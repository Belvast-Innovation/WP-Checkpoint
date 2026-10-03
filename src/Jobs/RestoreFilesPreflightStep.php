<?php
/**
 * A restore's preflight for files: where they are staged, and whether this site can take them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\IndexLineError;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Files\Links;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Restore\CannotStage;
use WPCheckpoint\Restore\DirectoryProbe;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Restore\LoaderProbe;
use WPCheckpoint\Restore\NameClashes;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Restore\StagingSpace;
use WPCheckpoint\Restore\TargetNames;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\Utf8;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with paths; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the job's work directory and the site's directories.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put a path into the error log; failures are reported.

/**
 * Nothing of the backup is written here; the restore stops before any file
 * is staged when a check fails (CannotStage: this site as it is set up;
 * WorkLost: the work directory changed). Six phases:
 *
 * 1. layout: the site's directories, resolved (ScanRoots::site_directories());
 *    the groups the backup holds are staged, each in the parent of its live
 *    directory (StagingLayout). Refused: a parent that is the root of a
 *    file system, a group directory on, inside or around another, a
 *    group's parent inside the content directory but not the content
 *    directory itself, and a storage directory inside a directory the swap
 *    replaces whole. The layout, with the staging roots' random part, goes
 *    to RestoreFiles::STAGING and never changes.
 * 2. probe: one staging parent per unit (DirectoryProbe): create, write,
 *    rename, remove; how names compare there (TargetNames) and which file
 *    system it is on. A group directory on another file system than its
 *    parent cannot be swapped by rename and is refused.
 * 3. loader: a file is put into the must-use plugins directory the way the
 *    loader will be, and removed (LoaderProbe).
 * 4. index: the files index the check extracted and hashed (RestoreVerifyStep::
 *    index_path()), PAGE_LINES lines or PAGE_BYTES bytes per unit: every path
 *    is mapped (a path that belongs to no group is listed in UNMAPPED and not
 *    restored), its staged path must fit StagingLayout::MAX_PATH_BYTES, its
 *    bytes count for its parent's file system, a top-level entry of the
 *    content directory must be on the content directory's file system and
 *    must not hold the storage directory, and its name keys go to the KEYS
 *    buckets (NameClashes). Buckets and UNMAPPED are appended under lengths
 *    committed in the cursor after the bytes are written.
 * 5. clashes: one bucket per unit; two paths this site's file system would
 *    put on one file refuse the restore, named.
 * 6. space: each file system must have StagingSpace::required() free; when
 *    free space cannot be read, the user is asked (free_space_unknown).
 *
 * The probes create and remove their own entries within one unit; a unit
 * that dies in between is replayed with new names, and what it left is a
 * probe the reaper removes.
 *
 * Known limits: a file system is identified by stat()'s device number, which
 * on Windows is the drive letter's, so a volume mounted into an NTFS folder
 * counts as its drive (the swap's rename would then fail on it, before
 * anything live is changed); TargetNames folds case as mb_strtolower() does,
 * which differs from NTFS and APFS in a few letters (see there).
 */
final class RestoreFilesPreflightStep implements Step {

	const ID = 'restore_files_preflight';

	/**
	 * Index lines per unit ...
	 */
	const PAGE_LINES = 2000;

	/**
	 * ... or index bytes per unit, whichever comes first (a line is at most IndexLine::MAX_LINE_BYTES).
	 */
	const PAGE_BYTES = 1048576;

	/**
	 * Unmapped paths named in the log.
	 */
	const MAX_LISTED = 10;

	/**
	 * Files of this plugin counted for the copy staged into plugins; more is not this plugin.
	 */
	const MAX_PLUGIN_ENTRIES = 20000;

	/**
	 * Injected parts (tests): "directories" function(): array<string, string> (group => live directory, resolved
	 * here like ScanRoots's), "free" function( string $dir ): ?int, "random" function(): string, "names"
	 * function( string $parent, TargetNames $probed ): TargetNames, "dev" function( string $path ): ?int (the
	 * file system of a live directory, null when it is not there), "page_lines" int, "at" function( string
	 * $point ): void ("appended": the index unit's bytes are written, its cursor is not), "plugin_dir" string,
	 * "dir_mode" int. Not a test part: "trusted_root" function(): string, the trusted deployment root ('' when none;
	 * RestoreJob passes it from the storage directories' state).
	 *
	 * @var array<string, mixed>
	 */
	private $parts;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $parts See $parts.
	 */
	public function __construct( array $parts = array() ) {
		$this->parts = $parts;
	}

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Run the phases.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws CannotStage When this site cannot take the staged files.
	 * @throws WorkLost When the work directory changed.
	 * @throws TransientFailure When a work file cannot be written.
	 */
	public function run( JobContext $context ): StepResult {
		$cursor = array_merge( array( 'phase' => 'layout' ), $context->cursor() );
		$work   = $context->work_path();
		$first  = true;
		if ( 'layout' === $cursor['phase'] ) {
			$cursor = $this->layout( $context );
			if ( $cursor instanceof StepResult ) {
				return $cursor; // The question about directories that are links to directories outside this site.
			}
			$context->checkpoint( $cursor, 62, __( 'Checking where the files go', 'wp-checkpoint' ) );
			$first = false; // The layout was this tick's first unit.
		}
		$staging = self::staging( $work );
		$layout  = self::layout_of( $staging, $context->job() );
		while ( 'probe' === $cursor['phase'] ) {
			if ( ! $first && $context->should_stop() ) {
				return StepResult::progress( $cursor, 63, __( 'Checking where the files go', 'wp-checkpoint' ) );
			}
			$first  = false;
			$cursor = $this->probe( $context, $cursor, $staging, $layout );
		}
		if ( 'loader' === $cursor['phase'] ) {
			if ( ! $first && $context->should_stop() ) {
				return StepResult::progress( $cursor, 64, __( 'Checking where the files go', 'wp-checkpoint' ) );
			}
			$first = false;
			LoaderProbe::run( $staging['groups']['mu-plugins'], $layout->probe_name( '.php' ), array( $context, 'confirm_lease' ), (int) ( $this->parts['dir_mode'] ?? ( defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 ) ) );
			$cursor = array_merge(
				$cursor,
				array(
					'phase'    => 'index',
					'offset'   => 0,
					'prev'     => -1,
					'lengths'  => array_fill( 0, NameClashes::BUCKETS, 0 ),
					'unmapped' => 0,
					'count'    => 0,
					'bytes'    => array_fill( 0, count( $staging['parents'] ), 0.0 ),
				)
			);
			$context->checkpoint( $cursor, 65, __( 'Reading the backup\'s files', 'wp-checkpoint' ) );
		}
		if ( 'index' === $cursor['phase'] ) {
			$result = $this->index( $context, $cursor, $staging, $layout, $first );
			if ( null !== $result ) {
				return $result;
			}
			$first = false;
		}
		while ( 'clashes' === $cursor['phase'] ) {
			if ( ! $first && $context->should_stop() ) {
				return StepResult::progress( $cursor, 68, __( 'Checking the backup\'s file names', 'wp-checkpoint' ) );
			}
			$first  = false;
			$cursor = $this->clashes( $context, $cursor, $staging );
		}
		if ( ! $first && $context->should_stop() ) {
			return StepResult::progress( $cursor, 69, __( 'Checking the free space', 'wp-checkpoint' ) );
		}
		return $this->space( $context, $cursor, $staging );
	}

	/**
	 * The layout phase: the cursor of the probe phase.
	 *
	 * @param JobContext $context Context.
	 * @return array<string, mixed>|StepResult The cursor of the probe phase, or the question about linked directories.
	 * @throws CannotStage When the layout cannot be staged and swapped.
	 */
	private function layout( JobContext $context ) {
		$manifest = RestorePreflightStep::manifest( $context->work_path() );
		$given    = isset( $this->parts['directories'] ) ? array_map( 'strval', (array) call_user_func( $this->parts['directories'] ) ) : ScanRoots::site_directories_as_given();
		$groups   = array_map( array( ScanRoots::class, 'resolved' ), $given );
		$staged   = array_values( array_intersect( StagingLayout::GROUPS, (array) $manifest->to_array()['contents']['files'] ) );
		$left_out = array();
		$linked   = $this->linked( $given, $groups, $staged );
		if ( array() !== $linked ) {
			$choice = $this->linked_choice( $context, $linked );
			if ( $choice instanceof StepResult ) {
				return $choice;
			}
			if ( LinkedTargets::EXCLUDE === $choice ) {
				$left_out = array_keys( $linked );
				$staged   = array_values( array_diff( $staged, $left_out ) );
				foreach ( $linked as $group => $target ) {
					$context->logger()->warning(
						'A content group of the backup is not restored: its directory is a link to a directory outside this site, and the restore was told to leave such groups out; its directory stays as it is',
						array(
							'group'  => $group,
							'target' => $target,
						)
					);
				}
			}
		}
		// The storage directory as the job names it and where it is: a swap that moves either breaks the job's
		// way to its own files (a link inside a replaced directory, or a directory reached through a link).
		$real    = realpath( $context->storage_path() );
		$storage = array_values( array_unique( array_filter( array( rtrim( Paths::normalize( $context->storage_path() ), '/' ), false === $real ? '' : rtrim( Paths::normalize( $real ), '/' ) ) ) ) );
		$content = (string) $groups[ StagingLayout::OTHER ];
		$named   = array_diff( StagingLayout::GROUPS, array( StagingLayout::OTHER ) );
		foreach ( $staged as $group ) {
			$live   = (string) $groups[ $group ];
			$parent = StagingLayout::OTHER === $group ? $live : dirname( $live );
			if ( self::is_root( $parent ) ) {
				throw new CannotStage( sprintf( 'The directory %s is at the top of its disk, so the restore has no directory to stage its copy next to. Move it into a directory, then try again.', $live ) );
			}
			if ( StagingLayout::OTHER === $group ) {
				continue;
			}
			if ( self::within( $live, $content ) ) {
				throw new CannotStage( sprintf( 'The directory %1$s is the content directory %2$s or holds it. The restore replaces %1$s whole, which would replace everything else in %2$s with it. Give it a directory of its own, then try again.', $live, $content ) );
			}
			if ( self::within( $content, $parent ) && ! self::within( $parent, $content ) ) {
				throw new CannotStage( sprintf( 'The directory %1$s is inside another directory of %2$s. The restore replaces the entries of %2$s one by one and could not keep that directory apart from the one around it. Move it directly into %2$s (or outside it), then try again.', $live, $content ) );
			}
			foreach ( $named as $other ) {
				if ( $other !== $group && ( self::within( (string) $groups[ $other ], $live ) || self::within( $live, (string) $groups[ $other ] ) ) ) {
					throw new CannotStage( sprintf( 'The directories %1$s and %2$s are one inside the other. The restore replaces each of them whole and cannot do that for nested directories. Move one of them elsewhere, then try again.', $live, (string) $groups[ $other ] ) );
				}
			}
			if ( self::holds( $live, $storage ) ) {
				throw new CannotStage( sprintf( 'The storage directory of WP Checkpoint is inside %s, which the restore replaces whole: the restore\'s own files would be swapped out with it. Set WPCHECKPOINT_STORAGE_DIR to a directory outside it, then start the restore again.', $live ) );
			}
		}
		$random  = isset( $this->parts['random'] ) ? (string) call_user_func( $this->parts['random'] ) : StagingLayout::new_random();
		$layout  = new StagingLayout( $groups, $context->job()->storage_token, $context->job()->id, $random, $storage );
		$parents = array();
		foreach ( $staged as $group ) {
			$parents[ $layout->parent( $group ) ] = true;
		}
		ExportPlan::write(
			$context->work_path(),
			RestoreFiles::STAGING,
			array(
				'groups'   => $groups,
				'staged'   => $staged,
				'parents'  => array_keys( $parents ),
				'storage'  => $storage,
				'random'   => $random,
				'left_out' => $left_out,
			)
		);
		return array(
			'phase'  => 'probe',
			'parent' => 0,
			'fs'     => array(),
		);
	}

	/**
	 * One parent of the probe phase: the next cursor.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $staging Staging file.
	 * @param StagingLayout        $layout  Layout.
	 * @return array<string, mixed>
	 * @throws CannotStage When the parent cannot be used.
	 */
	private function probe( JobContext $context, array $cursor, array $staging, StagingLayout $layout ): array {
		$i = (int) $cursor['parent'];
		if ( $i >= count( $staging['parents'] ) ) {
			$cursor['phase'] = 'loader';
			return $cursor;
		}
		$parent = (string) $staging['parents'][ $i ];
		$probed = DirectoryProbe::run( $parent, $layout->probe_name(), array( $context, 'confirm_lease' ) );
		$names  = isset( $this->parts['names'] ) ? call_user_func( $this->parts['names'], $parent, $probed['names'] ) : $probed['names'];
		if ( ! $names instanceof TargetNames ) {
			throw new \LogicException( 'The names part must return TargetNames.' );
		}
		if ( $probed['left'] ) {
			$context->logger()->info( 'A probe directory stayed behind because something else put an entry in it; it is removed later', array( 'directory' => $parent ) );
		}
		if ( $names->approximate() ) {
			$context->logger()->warning( 'The file system of a staging directory treats Unicode forms of a name as one name, and this server has no intl extension to tell them apart: two paths of the backup that differ only in their Unicode form would not be noticed before one overwrites the other', array( 'directory' => $parent ) );
		}
		foreach ( (array) $staging['staged'] as $group ) {
			if ( StagingLayout::OTHER === $group || $layout->parent( (string) $group ) !== $parent ) {
				continue;
			}
			$live = $layout->live_dir( (string) $group );
			$dev  = $this->dev( $live );
			if ( null !== $dev && $dev !== $probed['dev'] ) {
				throw new CannotStage( self::other_disk( $live, $parent ) );
			}
		}
		$cursor['fs'][ $i ] = array(
			'dev'   => $probed['dev'],
			'names' => $names->to_array(),
		);
		$cursor['parent']   = $i + 1;
		return $cursor;
	}

	/**
	 * The index phase: a result to return, or null when it is over (the cursor then holds the clashes phase).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @param array<string, mixed> $staging Staging file.
	 * @param StagingLayout        $layout  Layout.
	 * @param bool                 $first   Whether no unit ran in this tick yet.
	 * @return StepResult|null
	 * @throws CannotStage When a path cannot be staged here.
	 * @throws WorkLost When the index or a work file is not what was recorded.
	 * @throws TransientFailure When a work file cannot be written.
	 */
	private function index( JobContext $context, array &$cursor, array $staging, StagingLayout $layout, bool $first ) {
		$work     = $context->work_path();
		$manifest = RestorePreflightStep::manifest( $work );
		$spec     = $manifest->files_index();
		$path     = RestoreVerifyStep::index_path( $work, $spec );
		clearstatcache( true, $path );
		if ( 0 === (int) $spec['bytes'] && ! file_exists( $path ) ) {
			$path = 'php://memory'; // An empty index: nothing to read.
		} elseif ( ! is_file( $path ) || (int) filesize( $path ) !== (int) $spec['bytes'] ) {
			throw new WorkLost( 'The files index the check extracted is gone or changed in the work directory.' );
		}
		$keys = RestoreFiles::path( $work, RestoreFiles::KEYS );
		if ( ! is_dir( $keys ) && ! @mkdir( $keys, 0700 ) && ! is_dir( $keys ) ) {
			throw new TransientFailure( 'A work directory of the restore could not be created.' );
		}
		for ( $b = 0; $b < NameClashes::BUCKETS; $b++ ) {
			self::truncate_to( self::bucket( $keys, $b ), (int) $cursor['lengths'][ $b ] );
		}
		$unmapped = RestoreFiles::path( $work, RestoreFiles::UNMAPPED );
		self::truncate_to( $unmapped, (int) $cursor['unmapped'] );
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new WorkLost( 'The files index the check extracted cannot be read from the work directory.' );
		}
		try {
			$names  = array();
			$parent = array_flip( (array) $staging['parents'] );
			foreach ( (array) $cursor['fs'] as $i => $fs ) {
				$names[ $i ] = TargetNames::from_array( (array) $fs['names'] );
			}
			// The previous line, re-read: its path is where the key records left off, and its top-level entry
			// was checked already. Paths are not kept in the cursor.
			$prev = array(
				'path'   => '',
				'parent' => -1,
				'top'    => '',
				'raw'    => '',
			);
			if ( (int) $cursor['prev'] >= 0 ) {
				$prev = self::position( $layout, $parent, self::line_at( $handle, (int) $cursor['prev'], $manifest ) );
			}
			$since = 0;
			if ( 0 !== fseek( $handle, (int) $cursor['offset'] ) ) {
				throw new WorkLost( 'The files index the check extracted is shorter than recorded.' );
			}
			while ( true ) {
				if ( ! $first && $context->should_stop() ) {
					return StepResult::progress( $cursor, 66, __( 'Reading the backup\'s files', 'wp-checkpoint' ) );
				}
				$first   = false;
				$records = array();
				$lost    = '';
				$lines   = 0;
				$read    = 0;
				$page    = (int) ( $this->parts['page_lines'] ?? self::PAGE_LINES );
				while ( $lines < $page && $read < self::PAGE_BYTES ) {
					$at   = (int) $cursor['offset'] + $read;
					$text = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
					if ( false === $text ) {
						break;
					}
					$read += strlen( $text );
					++$lines;
					$line = self::parse( $text, $manifest );
					$raw  = self::top_entry( $line['p'] );
					if ( '' !== $raw && $raw !== $prev['raw'] ) {
						$this->check_storage_entry( $layout, $raw, array_map( 'strval', (array) $staging['storage'] ) );
					}
					$prev['raw'] = $raw;
					$map         = $layout->map( $line['p'] );
					$index       = null === $map ? null : ( $parent[ $layout->parent( $map['group'] ) ] ?? null );
					if ( null !== $map && in_array( $map['group'], (array) ( $staging['left_out'] ?? array() ), true ) ) {
						continue; // A group left out of the restore (the layout logged it): not a path of no group.
					}
					if ( null === $map || null === $index || ! in_array( $map['group'], (array) $staging['staged'], true ) ) {
						$lost .= self::json( array( 'p' => $line['p'] ) ) . "\n";
						++$cursor['count'];
						continue;
					}
					$bad = $names[ $index ]->unstorable( $map['relative'] );
					if ( null !== $bad ) {
						throw new CannotStage( sprintf( 'The backup holds %1$s, and the file system of this site cannot store the name %2$s (a name ending in a dot or a space, the characters < > : " | ? *, or a name like CON, NUL, COM1 or LPT1, depending on the server). Restore onto a server whose file system allows it, or rename it on the original site and make a new backup.', $line['p'], $bad ) );
					}
					if ( strlen( $map['staged'] ) > StagingLayout::MAX_PATH_BYTES ) {
						throw new CannotStage( sprintf( 'The path %1$s of the backup would be %2$d bytes long where the restore stages it, more than the %3$d bytes this server allows in one path. Restore onto a site whose directories are at a shorter path.', $line['p'], strlen( $map['staged'] ), StagingLayout::MAX_PATH_BYTES ) );
					}
					// Floats: the sum passes PHP_INT_MAX on 32-bit PHP (StagingSpace).
					$cursor['bytes'][ $index ] = (float) $cursor['bytes'][ $index ] + (float) $line['b'];
					if ( StagingLayout::OTHER === $map['group'] ) {
						$top = explode( '/', $map['relative'] )[0];
						if ( $top !== $prev['top'] ) {
							$this->check_top( $layout, $top, (int) $cursor['fs'][ $index ]['dev'] );
						}
						$prev['top'] = $top;
					}
					$under = $map['group'] . '/' . $map['relative'];
					foreach ( NameClashes::records( $index, $under, $names[ $index ], $at, $prev['parent'] === $index ? $prev['path'] : '' ) as $b => $text_records ) {
						$records[ $b ] = ( $records[ $b ] ?? '' ) . $text_records;
					}
					$prev['path']   = $under;
					$prev['parent'] = $index;
					$cursor['prev'] = $at;
				}
				// The bytes first, then the lengths that commit them (in the cursor this unit returns).
				foreach ( $records as $b => $text_records ) {
					self::append( self::bucket( $keys, $b ), $text_records );
					$cursor['lengths'][ $b ] = (int) $cursor['lengths'][ $b ] + strlen( $text_records );
				}
				if ( '' !== $lost ) {
					self::append( $unmapped, $lost );
					$cursor['unmapped'] = (int) $cursor['unmapped'] + strlen( $lost );
				}
				if ( isset( $this->parts['at'] ) ) {
					call_user_func( $this->parts['at'], 'appended' );
				}
				$cursor['offset'] = (int) $cursor['offset'] + $read;
				if ( 0 === $lines ) {
					break;
				}
				$since += $read;
				if ( $context->should_checkpoint( $since ) ) {
					$context->checkpoint( $cursor, 66, __( 'Reading the backup\'s files', 'wp-checkpoint' ) );
					$since = 0;
				}
			}
		} finally {
			fclose( $handle );
		}
		if ( (int) $cursor['offset'] !== (int) $spec['bytes'] ) {
			throw new WorkLost( 'The files index the check extracted ended before its recorded size.' );
		}
		if ( (int) $cursor['count'] > 0 ) {
			$context->logger()->warning(
				'Paths of the backup that belong to no content group are not restored',
				array(
					'count'   => (int) $cursor['count'],
					'example' => self::listed( $unmapped ),
				)
			);
		}
		$cursor = array(
			'phase'   => 'clashes',
			'bucket'  => 0,
			'lengths' => $cursor['lengths'],
			'fs'      => $cursor['fs'],
			'bytes'   => $cursor['bytes'],
		);
		$context->checkpoint( $cursor, 67, __( 'Checking the backup\'s file names', 'wp-checkpoint' ) );
		return null;
	}

	/**
	 * A top-level entry of the content directory that the backup restores: it is swapped by rename there, so
	 * it must be on the content directory's file system.
	 *
	 * @param StagingLayout $layout Layout.
	 * @param string        $top    Entry name.
	 * @param int           $dev    The content directory's file system.
	 * @return void
	 * @throws CannotStage When it cannot be swapped.
	 */
	private function check_top( StagingLayout $layout, string $top, int $dev ): void {
		$content = $layout->live_dir( StagingLayout::OTHER );
		$live    = $content . '/' . $top;
		$here    = $this->dev( $live );
		if ( null !== $here && $here !== $dev ) {
			throw new CannotStage( self::other_disk( $live, $content ) );
		}
	}

	/**
	 * A top-level entry of the content directory the backup holds must not hold the storage directory (as the
	 * job names it, or where that is): the layout would leave such an entry out (StagingLayout's reserved
	 * directories), silently restoring less than the backup holds, so it is refused with the reason instead.
	 * Entries named like this plugin's own are never restored and are not checked.
	 *
	 * @param StagingLayout $layout  Layout.
	 * @param string        $top     Entry name (top_entry()).
	 * @param string[]      $storage The storage directory as named and as resolved.
	 * @return void
	 * @throws CannotStage When it holds the storage directory.
	 */
	private function check_storage_entry( StagingLayout $layout, string $top, array $storage ): void {
		$live = $layout->live_dir( StagingLayout::OTHER ) . '/' . $top;
		$real = realpath( $live );
		if ( self::holds( $live, $storage ) || ( false !== $real && self::holds( Paths::normalize( $real ), $storage ) ) ) {
			throw new CannotStage( sprintf( 'The storage directory of WP Checkpoint is inside %s, which the restore replaces whole: the restore\'s own files would be swapped out with it. Set WPCHECKPOINT_STORAGE_DIR to a directory outside it, then start the restore again.', $live ) );
		}
	}

	/**
	 * The top-level entry of the content directory a backup path is in, when it is not a group directory or
	 * named like this plugin's own; '' otherwise.
	 *
	 * @param string $path Backup path.
	 * @return string
	 */
	private static function top_entry( string $path ): string {
		$parts = explode( '/', $path );
		if ( count( $parts ) < 3 || StagingLayout::CONTENT !== $parts[0] || in_array( $parts[1], array( 'plugins', 'themes', 'uploads', 'mu-plugins' ), true ) || 0 === stripos( $parts[1], 'wp-checkpoint-' ) ) {
			return '';
		}
		return $parts[1];
	}

	/**
	 * One bucket of the clashes phase: the next cursor.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $staging Staging file.
	 * @return array<string, mixed>
	 * @throws CannotStage When two paths would land on one file, or a bucket is too large to check.
	 * @throws WorkLost When a bucket is not what was recorded.
	 */
	private function clashes( JobContext $context, array $cursor, array $staging ): array {
		$b = (int) $cursor['bucket'];
		if ( $b >= NameClashes::BUCKETS ) {
			$cursor = array(
				'phase' => 'space',
				'fs'    => $cursor['fs'],
				'bytes' => $cursor['bytes'],
			);
			$context->checkpoint( $cursor, 69, __( 'Checking the free space', 'wp-checkpoint' ) );
			return $cursor;
		}
		$work   = $context->work_path();
		$file   = self::bucket( RestoreFiles::path( $work, RestoreFiles::KEYS ), $b );
		$length = (int) $cursor['lengths'][ $b ];
		if ( $length > NameClashes::MAX_BUCKET_BYTES ) {
			throw new CannotStage( sprintf( 'The backup lists too many files to check their names for clashes in one pass (%d bytes of name keys in one of %d parts, at most %d).', $length, NameClashes::BUCKETS, NameClashes::MAX_BUCKET_BYTES ) );
		}
		self::truncate_to( $file, $length );
		$records = 0 === $length ? '' : @file_get_contents( $file, false, null, 0, $length );
		if ( ! is_string( $records ) || strlen( $records ) !== $length ) {
			throw new WorkLost( 'A work file of the restore cannot be read back.' );
		}
		try {
			$clash = NameClashes::first_clash( $records );
		} catch ( \UnexpectedValueException $e ) {
			throw new WorkLost( 'A work file of the restore is damaged: ' . $e->getMessage() );
		}
		if ( null !== $clash ) {
			$manifest = RestorePreflightStep::manifest( $work );
			$handle   = @fopen( RestoreVerifyStep::index_path( $work, $manifest->files_index() ), 'rb' );
			if ( false === $handle ) {
				throw new WorkLost( 'The files index the check extracted cannot be read from the work directory.' );
			}
			try {
				$a = self::line_at( $handle, $clash[0], $manifest )['p'];
				$c = self::line_at( $handle, $clash[1], $manifest )['p'];
			} finally {
				fclose( $handle );
			}
			throw new CannotStage( sprintf( 'The backup holds %1$s and %2$s, which the file system of this site treats as one name: restoring both would put one over the other. This happens when a backup of a server that tells upper and lower case (or Unicode forms) apart is restored onto one that does not. Restore onto a server whose file system tells them apart, or rename one of them on the original site and make a new backup.', $a, $c ) );
		}
		$cursor['bucket'] = $b + 1;
		return $cursor;
	}

	/**
	 * The space phase.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $staging Staging file.
	 * @return StepResult
	 * @throws CannotStage When a file system is too full.
	 * @throws Stopped When the user chose to stop.
	 */
	private function space( JobContext $context, array $cursor, array $staging ): StepResult {
		$staged = array();
		$where  = array();
		foreach ( (array) $staging['parents'] as $i => $parent ) {
			$fs            = (string) $cursor['fs'][ $i ]['dev'];
			$staged[ $fs ] = ( $staged[ $fs ] ?? 0.0 ) + (float) $cursor['bytes'][ $i ];
			$where[ $fs ]  = $where[ $fs ] ?? (string) $parent;
		}
		if ( in_array( 'plugins', (array) $staging['staged'], true ) ) {
			$index = array_search( dirname( (string) $staging['groups']['plugins'] ), (array) $staging['parents'], true );
			if ( false !== $index ) {
				$fs            = (string) $cursor['fs'][ $index ]['dev'];
				$staged[ $fs ] = $staged[ $fs ] + $this->plugin_bytes();
			}
		}
		$free = array();
		foreach ( $where as $fs => $dir ) {
			$free[ $fs ] = $this->free( $dir );
		}
		$check = StagingSpace::check( $staged, $free );
		foreach ( $check['short'] as $fs => $short ) {
			throw new CannotStage( sprintf( 'There is not enough free space on the disk of %1$s: the restore stages %2$s MB of files there and needs %3$s MB free with a margin, and %4$s MB are free. Free some space (or remove older backups), then try again.', $where[ $fs ], self::mb( ceil( $staged[ $fs ] / 1048576 ) ), self::mb( ceil( $short['need'] / 1048576 ) ), self::mb( floor( $short['free'] / 1048576 ) ) ) );
		}
		if ( array() !== $check['unknown'] ) {
			$answers = isset( $context->options()['answers'] ) && is_array( $context->options()['answers'] ) ? $context->options()['answers'] : array();
			$answer  = $answers['free_space'] ?? null;
			if ( 'stop' === $answer ) {
				throw new Stopped( 'The restore was stopped because the free space for its staged files could not be confirmed.', array( 'free_space' ) );
			}
			if ( 'continue' !== $answer ) {
				return StepResult::ask(
					$cursor,
					array(
						array(
							'id'      => 'free_space',
							'kind'    => 'free_space_unknown',
							'bytes'   => self::question_bytes( max( $check['unknown'] ) ),
							'choices' => array( 'continue', 'stop' ),
						),
					),
					__( 'Waiting for your decision on 1 question', 'wp-checkpoint' )
				);
			}
			$context->logger()->warning( 'The free space for the staged files could not be read; the restore continues as you chose', array( 'need' => $check['unknown'] ) );
		}
		return StepResult::done( __( 'The backup\'s files can be staged here', 'wp-checkpoint' ) );
	}

	/**
	 * The staged groups whose directory is a link (or cannot be told not to be one) to a directory outside this site:
	 * neither in its WordPress directory nor in the trusted deployment root (LinkedTargets).
	 *
	 * @param array<string, string> $given  Group => directory as WordPress names it.
	 * @param array<string, string> $groups Group => directory, resolved.
	 * @param string[]              $staged The staged groups.
	 * @return array<string, string> Group => its target, resolved.
	 */
	private function linked( array $given, array $groups, array $staged ): array {
		$abspath = Paths::real( rtrim( ABSPATH, '/\\' ) );
		$trusted = isset( $this->parts['trusted_root'] ) ? (string) call_user_func( $this->parts['trusted_root'] ) : '';
		clearstatcache( true );
		$out = array();
		foreach ( $staged as $group ) {
			$path  = rtrim( (string) ( $given[ $group ] ?? '' ), '/\\' );
			$state = '' === $path ? Links::UNKNOWN : Links::state( $path );
			if ( Links::PLAIN === $state || ( Links::UNKNOWN === $state && Paths::normalize( $path ) === (string) $groups[ $group ] ) ) {
				continue; // Not a link, or resolved to itself: no link anywhere on its path.
			}
			if ( LinkedTargets::outside( (string) $groups[ $group ], false === $abspath ? '' : (string) $abspath, $trusted ) ) {
				$out[ $group ] = (string) $groups[ $group ];
			}
		}
		return $out;
	}

	/**
	 * What to do with the groups whose directory is a link to a directory outside this site: the answer to the
	 * question about these very groups and targets, or the policy; otherwise the question (no default).
	 *
	 * @param JobContext            $context Context.
	 * @param array<string, string> $linked  Group => target, resolved.
	 * @return string|StepResult LinkedTargets::SWAP or EXCLUDE, or the question.
	 * @throws TransientFailure When the question's file cannot be written.
	 */
	private function linked_choice( JobContext $context, array $linked ) {
		$abspath = Paths::real( rtrim( ABSPATH, '/\\' ) );
		$id      = LinkedTargets::id( $linked );
		$answers = isset( $context->options()['answers'] ) && is_array( $context->options()['answers'] ) ? $context->options()['answers'] : array();
		$policy  = RestoreJob::options( $context->options() )['policy'][ LinkedTargets::POLICY_KEY ];
		foreach ( array_keys( $answers ) as $other ) {
			if ( 0 === strpos( (string) $other, LinkedTargets::POLICY_KEY . '_' ) && $other !== $id ) {
				// Given for other groups or targets (a link pointed elsewhere since): it holds for none of these.
				$context->logger()->warning( 'An answer given for other linked directories than the restore would now ask about is not used', array( 'question' => (string) $other ) );
			}
		}
		$answer = $answers[ $id ] ?? null;
		if ( in_array( $answer, LinkedTargets::CHOICES, true ) ) {
			$choice = (string) $answer;
			$from   = 'answer';
		} elseif ( in_array( $policy, LinkedTargets::CHOICES, true ) ) {
			$choice = (string) $policy;
			$from   = 'policy';
		} else {
			$entries = array();
			$hex     = array();
			foreach ( $linked as $group => $target ) {
				$entries[]     = array(
					'group'    => (string) $group,
					'target'   => Utf8::scrub( $target ),
					'relation' => LinkedTargets::relation( $target, false === $abspath ? '' : (string) $abspath ),
				);
				$hex[ $group ] = bin2hex( $target );
			}
			try {
				ExportPlan::write(
					$context->work_path(),
					RestoreFiles::LINKED,
					array(
						'entries' => $entries,
						'hex'     => $hex, // The targets' bytes, for the id: the question lists them only for its own id.
					)
				);
			} catch ( \RuntimeException $e ) {
				throw new TransientFailure( 'A work file of the restore could not be written: ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
			}
			$context->logger()->info( 'Content directories that are links to directories outside this site; asked what to do with them', array( 'targets' => $linked ) );
			return StepResult::ask(
				array( 'phase' => 'layout' ),
				array(
					array(
						'id'      => $id,
						'kind'    => LinkedTargets::KIND,
						'count'   => count( $linked ),
						'file'    => RestoreFiles::LINKED,
						'choices' => LinkedTargets::CHOICES,
					),
				),
				__( 'Waiting for your decision on 1 question', 'wp-checkpoint' )
			);
		}
		$context->logger()->info(
			'Content directories that are links to directories outside this site',
			array(
				'targets' => $linked,
				'choice'  => $choice,
				'from'    => $from,
			)
		);
		return $choice;
	}

	/**
	 * The file system of an entry (not followed when it is a link: a link is renamed where it is), or null
	 * when it is not there.
	 *
	 * @param string $path Path.
	 * @return int|null
	 */
	private function dev( string $path ) {
		if ( isset( $this->parts['dev'] ) ) {
			$dev = call_user_func( $this->parts['dev'], $path );
			return null === $dev ? null : (int) $dev;
		}
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		return false === $stat ? null : (int) $stat['dev'];
	}

	/**
	 * Free bytes on the file system of a directory, or null when this server does not say.
	 *
	 * @param string $dir Directory.
	 * @return float|null
	 */
	private function free( string $dir ) {
		if ( isset( $this->parts['free'] ) ) {
			$free = call_user_func( $this->parts['free'], $dir );
			return null === $free ? null : (float) $free;
		}
		$free = HostFunctions::disk_free_space( $dir );
		return false === $free ? null : $free;
	}

	/**
	 * A byte count for a question (an integer, JobRepository::validate_questions()): capped at PHP_INT_MAX, never
	 * cast past it ((float) PHP_INT_MAX is 2^63 on 64-bit PHP, which an integer cast turns negative).
	 *
	 * @param float $bytes Bytes.
	 * @return int
	 */
	public static function question_bytes( float $bytes ): int {
		return $bytes >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) max( 0.0, $bytes );
	}

	/**
	 * Whole megabytes for a message, without an integer cast that overflows on 32-bit PHP.
	 *
	 * @param float $mb Megabytes, whole.
	 * @return string
	 */
	private static function mb( float $mb ): string {
		return number_format( $mb, 0, '.', '' );
	}

	/**
	 * Bytes of this plugin's files (the copy the restore stages into plugins).
	 *
	 * @return float
	 * @throws CannotStage When the plugin directory holds more than MAX_PLUGIN_ENTRIES entries.
	 */
	private function plugin_bytes(): float {
		$root  = rtrim( (string) ( $this->parts['plugin_dir'] ?? WPCHECKPOINT_DIR ), '/\\' );
		$bytes = 0.0;
		$seen  = 0;
		$dirs  = array( $root );
		while ( array() !== $dirs ) {
			$dir     = array_pop( $dirs );
			$entries = @scandir( $dir );
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ++$seen > self::MAX_PLUGIN_ENTRIES ) {
					throw new CannotStage( sprintf( 'The directory of WP Checkpoint holds more than %d entries; it is not this plugin as released. Reinstall the plugin, then try again.', self::MAX_PLUGIN_ENTRIES ) );
				}
				$path = $dir . '/' . $entry;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$dirs[] = $path;
				} else {
					$bytes += (float) @filesize( $path );
				}
			}
		}
		return $bytes;
	}

	/**
	 * The staging file.
	 *
	 * @param string $work Work directory.
	 * @return array<string, mixed>
	 * @throws WorkLost When it is gone or not the staging file.
	 */
	public static function staging( string $work ): array {
		try {
			$data = ExportPlan::read( $work, RestoreFiles::STAGING );
		} catch ( \RuntimeException $e ) {
			throw new WorkLost( 'The restore\'s staging layout is gone from the work directory.' );
		}
		foreach ( array( 'groups', 'staged', 'parents' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				throw new WorkLost( 'The restore\'s staging layout in the work directory is damaged.' );
			}
		}
		if ( ! isset( $data['random'], $data['storage'] ) || ! is_string( $data['random'] ) || ! is_array( $data['storage'] ) ) {
			throw new WorkLost( 'The restore\'s staging layout in the work directory is damaged.' );
		}
		return $data;
	}

	/**
	 * The layout the staging file describes.
	 *
	 * @param array<string, mixed> $staging Staging file.
	 * @param Job                  $job     Job.
	 * @return StagingLayout
	 * @throws WorkLost When it does not describe a layout.
	 */
	public static function layout_of( array $staging, Job $job ): StagingLayout {
		try {
			return new StagingLayout( $staging['groups'], $job->storage_token, $job->id, (string) $staging['random'], array_map( 'strval', $staging['storage'] ) );
		} catch ( \InvalidArgumentException $e ) {
			throw new WorkLost( 'The restore\'s staging layout in the work directory is damaged.' );
		}
	}

	/**
	 * Where a line leaves the key records: its path under the staging root, its parent, its top-level entry.
	 *
	 * @param StagingLayout      $layout Layout.
	 * @param array<string, int> $parents Parent => index.
	 * @param array{p: string}   $line    Parsed line.
	 * @return array{path: string, parent: int, top: string, raw: string}
	 */
	private static function position( StagingLayout $layout, array $parents, array $line ): array {
		$map = $layout->map( $line['p'] );
		if ( null === $map || ! isset( $parents[ $layout->parent( $map['group'] ) ] ) ) {
			return array(
				'path'   => '',
				'parent' => -1,
				'top'    => '',
				'raw'    => self::top_entry( $line['p'] ),
			);
		}
		return array(
			'path'   => $map['group'] . '/' . $map['relative'],
			'parent' => $parents[ $layout->parent( $map['group'] ) ],
			'top'    => StagingLayout::OTHER === $map['group'] ? explode( '/', $map['relative'] )[0] : '',
			'raw'    => self::top_entry( $line['p'] ),
		);
	}

	/**
	 * The line of the index at an offset.
	 *
	 * @param resource $handle   Index.
	 * @param int      $offset   Offset.
	 * @param Manifest $manifest Manifest.
	 * @return array{p: string, b: int}
	 * @throws WorkLost When there is no line there.
	 */
	private static function line_at( $handle, int $offset, Manifest $manifest ): array {
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new WorkLost( 'The files index the check extracted is shorter than recorded.' );
		}
		$text = fgets( $handle, IndexLine::MAX_LINE_BYTES + 2 );
		if ( false === $text ) {
			throw new WorkLost( 'The files index the check extracted is shorter than recorded.' );
		}
		return self::parse( $text, $manifest );
	}

	/**
	 * Parse an index line (the check parsed each already: a failure here is a changed work directory).
	 *
	 * @param string   $text     Line.
	 * @param Manifest $manifest Manifest.
	 * @return array{p: string, b: int}
	 * @throws WorkLost When it is not an index line.
	 */
	private static function parse( string $text, Manifest $manifest ): array {
		try {
			$line = IndexLine::files( rtrim( $text, "\n" ), $manifest->chunk_bytes() );
		} catch ( IndexLineError $e ) {
			throw new WorkLost( 'The files index the check extracted changed in the work directory.' );
		}
		return array(
			'p' => $line['p'],
			'b' => $line['b'],
		);
	}

	/**
	 * The first unmapped paths, for the log.
	 *
	 * @param string $file UNMAPPED.
	 * @return string[]
	 */
	private static function listed( string $file ): array {
		$out    = array();
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return $out;
		}
		for ( $read = 0; $read < self::MAX_LISTED; ) {
			$text = fgets( $handle );
			if ( false === $text ) {
				break;
			}
			++$read;
			$data = json_decode( $text, true );
			if ( is_array( $data ) && isset( $data['p'] ) && is_string( $data['p'] ) ) {
				$out[] = $data['p'];
			}
		}
		fclose( $handle );
		return $out;
	}

	/**
	 * JSON of a work record; a failure throws (the record is read back).
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string
	 * @throws TransientFailure When it cannot be encoded.
	 */
	private static function json( array $data ): string {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			throw new TransientFailure( 'A work record of the restore could not be encoded.' );
		}
		return $json;
	}

	/**
	 * A bucket file.
	 *
	 * @param string $keys KEYS directory.
	 * @param int    $b    Bucket.
	 * @return string
	 */
	private static function bucket( string $keys, int $b ): string {
		return $keys . DIRECTORY_SEPARATOR . sprintf( '%03d', $b );
	}

	/**
	 * Append to a work file.
	 *
	 * @param string $file File.
	 * @param string $text Text.
	 * @return void
	 * @throws TransientFailure When it cannot be written in full.
	 */
	private static function append( string $file, string $text ): void {
		$handle = @fopen( $file, 'ab' );
		if ( false === $handle ) {
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
		$written = @fwrite( $handle, $text );
		$closed  = @fclose( $handle );
		if ( strlen( $text ) !== $written || ! $closed ) {
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
	}

	/**
	 * Cut an appended work file back to its committed length; fail when it is shorter. A file never
	 * written is length 0.
	 *
	 * @param string $path   File.
	 * @param int    $length Committed length.
	 * @return void
	 * @throws WorkLost When the file is shorter than committed.
	 * @throws TransientFailure When it cannot be cut.
	 */
	private static function truncate_to( string $path, int $length ): void {
		clearstatcache( true, $path );
		$size = is_file( $path ) ? (int) filesize( $path ) : ( 0 === $length ? 0 : -1 );
		if ( $size < $length ) {
			throw new WorkLost( 'A work file of the restore is shorter than recorded; the work directory was changed.' );
		}
		if ( $size > $length ) {
			$handle = @fopen( $path, 'r+b' );
			if ( false === $handle || ! ftruncate( $handle, $length ) ) {
				throw new TransientFailure( 'A work file of the restore could not be cut back to its committed length.' );
			}
			fclose( $handle );
		}
	}

	/**
	 * The message for a directory on another file system than the one it is swapped within.
	 *
	 * @param string $live  Directory.
	 * @param string $where Its parent.
	 * @return string
	 */
	private static function other_disk( string $live, string $where ): string {
		return sprintf( 'The directory %1$s is on another disk than %2$s, where the restore stages its replacement. The restore swaps directories by renaming, which cannot cross disks, so it could not put the old one back if something went wrong. Move %1$s onto the same disk as %2$s, then try again. If it has to stay on its own disk, make your own copy of %1$s and restore by hand instead: the backup is a standard zip archive whose files and database (as SQL) can be restored without WP Checkpoint.', $live, $where );
	}

	/**
	 * Whether a path is the root of a file system.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public static function is_root( string $dir ): bool {
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
		return '' === $dir || 1 === preg_match( '#\A[A-Za-z]:\z#', $dir ) || 1 === preg_match( '#\A//[^/]+/[^/]+\z#', $dir );
	}

	/**
	 * Whether any of the paths is $dir or inside it.
	 *
	 * @param string   $dir   Directory.
	 * @param string[] $paths Paths.
	 * @return bool
	 */
	private static function holds( string $dir, array $paths ): bool {
		foreach ( $paths as $path ) {
			if ( self::within( $dir, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether $path is $dir or inside it (resolved paths, compared without case: refusing is the safe answer).
	 *
	 * @param string $dir  Directory.
	 * @param string $path Path.
	 * @return bool
	 */
	public static function within( string $dir, string $path ): bool {
		$dir  = strtolower( rtrim( str_replace( '\\', '/', $dir ), '/' ) );
		$path = strtolower( rtrim( str_replace( '\\', '/', $path ), '/' ) );
		return $dir === $path || 0 === strpos( $path . '/', $dir . '/' );
	}

	/**
	 * Nothing to do: the probes remove what they create, and everything else is in the work directory,
	 * which the engine removes.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}

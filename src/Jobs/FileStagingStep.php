<?php
/**
 * A restore's files, written next to the site: the staged copy the swap will put in place.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\EntryPath;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Files\PathKey;
use WPCheckpoint\Restore\BackupUnusable;
use WPCheckpoint\Restore\CannotStage;
use WPCheckpoint\Restore\ChunkHashes;
use WPCheckpoint\Restore\ChunkWalk;
use WPCheckpoint\Restore\PluginIdentity;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagedWriter;
use WPCheckpoint\Restore\StageModes;
use WPCheckpoint\Restore\StagingChanged;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Protection;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with paths; the presenter cleans them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- files in the job's work directory, the staging roots and this plugin's own directory.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put a path into the error log; failures are reported.

/**
 * Nothing the site uses is touched: every byte goes under the restore's
 * staging roots (StagingLayout), which the files preflight placed and
 * checked. Five phases:
 *
 * 1. roots: each staging root is created (the job's lease confirmed right
 *    before), protected from web access (index.php, .htaccess; on nginx the
 *    128-bit random name is the protection, which is why no message names
 *    it; a backup's own .htaccess staged below the root can loosen the
 *    root's on Apache where overrides are allowed) and given one directory
 *    per group staged in it.
 * 2. identify: the files index in lockstep with the volumes (ChunkWalk), a
 *    page per unit (index lines, index bytes and the bytes the heads cost
 *    together bound it), reading only the heads of the top-level PHP files
 *    of the backup's plugin directories: a directory is this plugin
 *    (PluginIdentity) by its header or its constant, whatever its name. A
 *    directory named like the running plugin's (compared as a file system
 *    that folds case would) that is not this plugin is left out too (the
 *    running copy goes there). Both are reported.
 * 3. files: the files in lockstep again, one unit per file or per content
 *    chunk of a larger stored file (every unit, that is every file or
 *    chunk, is bounded by the manifest's chunk size, or by the size a
 *    deflated entry is read in one piece). The bytes are hashed as they are
 *    read from the backup, not read back: each chunk against its hash in
 *    the index, a file of one chunk against its hash; a mismatch removes
 *    the staged file and ends the restore with its cause, judged after the
 *    range is read once more (the backup file changed since the check; it
 *    read differently from the full check or from the second read, which
 *    is this server's storage; or it read wrong twice and was never read in
 *    full: damaged). A symbolic link, FIFO, device or socket
 *    entry (by its Unix mode, when the creator is Unix) is reported, never
 *    created. Every directory level is checked before a write
 *    (StagedWriter); files and directories get this site's modes
 *    (StageModes); a file's modification time is set from the index once
 *    it is complete.
 * 4. plugin_list, plugin: when the plugins are staged, the running copy of
 *    this plugin is listed, then copied file by file (per chunk for a large
 *    one) into the staged plugins under its own directory name.
 * 5. The report is summarised in the job's log.
 *
 * Position and committed lengths are in the cursor, written only through
 * the engine's checkpoint (fenced on the job's lease). A staged file is cut
 * back to its committed length when it is resumed, and the report file
 * too. The lease is confirmed before each staging root is created and
 * before a staged file is removed, and renewed between units; a run that
 * lost it writes on until its next checkpoint (the engine's pace: at most
 * 2 seconds or 16 MB of units) fails, and cannot move the cursor (the
 * holder rewrites those units from its own position). Nothing checks a
 * staged tree as a whole yet: that is the swap's final check, a later part
 * of T042.
 */
final class FileStagingStep implements Step {

	const ID     = 'restore_stage_files';
	const MARGIN = 1.5;

	/**
	 * Index lines per unit of the identify phase ...
	 */
	const PAGE_LINES = 2000;

	/**
	 * ... or index bytes, whichever comes first.
	 */
	const PAGE_BYTES = 1048576;

	/**
	 * Most plugin directories of one backup that are this plugin.
	 */
	const MAX_SELF = 64;

	/**
	 * Report lines named in the log.
	 */
	const MAX_LISTED = 10;

	/**
	 * Injected parts (tests): "plugin_dir" string (the running copy), "plugin_main" string (its main file),
	 * "at" function( string $point ): void (a crash seam: roots, identified, dir, piece, complete, touched,
	 * report, plugin_list, plugin_piece), "read" function( string $piece ): string (what reading the backup yields),
	 * "write" function( resource $handle, string $bytes ): int|false (in place of fwrite()), "free"
	 * function( string $dir ): float|false (in place of disk_free_space()).
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
	 * @throws WorkLost When the staging directory or the work directory changed.
	 * @throws BackupUnusable When a file of the backup does not match its hash, and retrying cannot change that.
	 * @throws EnvironmentFailure When this server cannot read the backup or write the staged files.
	 * @throws CannotStage When the backup holds this plugin too many times.
	 */
	public function run( JobContext $context ): StepResult {
		$work    = $context->work_path();
		$plan    = RestorePreflightStep::load_plan( $work );
		$staging = RestoreFilesPreflightStep::staging( $work );
		$layout  = RestoreFilesPreflightStep::layout_of( $staging, $context->job() );
		$walk    = new ChunkWalk( RestoreVerifyStep::index_path( $work, RestorePreflightStep::manifest( $work )->files_index() ), $plan['volumes'], $plan['chunk_bytes'], ChunkWalk::FILES );
		$cursor  = array_merge( array( 'phase' => 'roots' ), $context->cursor() );
		$first   = true;
		try {
			if ( 'roots' === $cursor['phase'] ) {
				$cursor = $this->roots( $context, $staging, $layout, $walk );
				$context->checkpoint( $cursor, 72, __( 'Staging the files', 'wp-checkpoint' ) );
				$first = false;
			}
			self::truncate_to( RestoreFiles::path( $work, RestoreFiles::REPORT ), (int) $cursor['report'] );
			while ( 'identify' === $cursor['phase'] ) {
				if ( ! $first && $context->should_stop() ) {
					return StepResult::progress( $cursor, 73, __( 'Staging the files', 'wp-checkpoint' ) );
				}
				$first  = false;
				$cursor = $this->identify( $context, $cursor, $staging, $layout, $walk );
			}
			$result = null;
			if ( 'files' === $cursor['phase'] ) {
				$result = $this->files( $context, $cursor, $staging, $layout, $walk, $plan, $first );
				$first  = false;
			}
			if ( null !== $result ) {
				return $result;
			}
			if ( 'plugin_list' === $cursor['phase'] ) {
				if ( ! $first && $context->should_stop() ) {
					return StepResult::progress( $cursor, 90, __( 'Staging this plugin', 'wp-checkpoint' ) );
				}
				$first  = false;
				$cursor = $this->plugin_list( $context, $cursor );
			}
			if ( 'plugin' === $cursor['phase'] ) {
				$result = $this->plugin( $context, $cursor, $layout, $plan, $first );
				if ( null !== $result ) {
					return $result;
				}
			}
		} catch ( StagingChanged $e ) {
			throw new WorkLost( $e->getMessage() );
		}
		$this->summarise( $context );
		return StepResult::done( __( 'The files are staged next to the site', 'wp-checkpoint' ) );
	}

	/**
	 * The roots phase: the staging roots and their group directories; the cursor of the identify phase.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $staging Staging file.
	 * @param StagingLayout        $layout  Layout.
	 * @param ChunkWalk            $walk    The files walk.
	 * @return array<string, mixed>
	 * @throws EnvironmentFailure When a root cannot be created or protected.
	 * @throws StagingChanged When a root is there but not a directory of its own.
	 */
	private function roots( JobContext $context, array $staging, StagingLayout $layout, ChunkWalk $walk ): array {
		$dir_mode = StageModes::dir();
		foreach ( (array) $staging['staged'] as $group ) {
			$root = $layout->root( (string) $group );
			clearstatcache( true, $root );
			if ( false === @lstat( $root ) ) {
				$context->confirm_lease();
				if ( ! @mkdir( $root, $dir_mode ) ) {
					throw new EnvironmentFailure( sprintf( 'The staging directory cannot be created in %s.', dirname( $root ) ) );
				}
				@chmod( $root, $dir_mode );
				$this->at( 'roots' );
			}
			StagedWriter::assert_directory( $root );
			if ( ! Protection::write( $root ) ) {
				throw new EnvironmentFailure( sprintf( 'The staging directory in %s cannot be protected from web access.', dirname( $root ) ) );
			}
			StagedWriter::directories( $root, (string) $group, $dir_mode );
		}
		return array(
			'phase'  => 'identify',
			'start'  => $walk->files_start( self::database_chunks( $context->work_path() ) ),
			'at'     => null,
			'self'   => array(),
			'names'  => array(),
			'report' => 0,
		);
	}

	/**
	 * One unit of the identify phase: the next cursor.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param array<string, mixed> $staging Staging file.
	 * @param StagingLayout        $layout  Layout.
	 * @param ChunkWalk            $walk    The files walk.
	 * @return array<string, mixed>
	 * @throws CannotStage When the backup holds this plugin more than MAX_SELF times.
	 */
	private function identify( JobContext $context, array $cursor, array $staging, StagingLayout $layout, ChunkWalk $walk ): array {
		$identity = PluginIdentity::running( (string) ( $this->parts['plugin_main'] ?? WPCHECKPOINT_FILE ) );
		$running  = $this->running_name();
		$at       = null === $cursor['at'] ? $cursor['start'] : $cursor['at'];
		$read     = 0;
		for ( $lines = 0; $lines < self::PAGE_LINES && $read < self::PAGE_BYTES; $lines++ ) {
			$chunk = $walk->at( $at );
			if ( null === $chunk ) {
				return $this->identified( $context, $cursor, $running );
			}
			$read += (int) $chunk['next']['offset'] - (int) $at['offset'];
			$at    = $chunk['next'];
			$map   = $layout->map( (string) $chunk['line']['p'] );
			if ( null === $map || 'plugins' !== $map['group'] || ! in_array( 'plugins', (array) $staging['staged'], true ) ) {
				continue;
			}
			$dir = explode( '/', $map['relative'] )[0];
			if ( self::fold( $dir ) === self::fold( $running ) && ! in_array( $dir, (array) $cursor['names'], true ) ) {
				// The running copy's name, as spelt in the backup (a file system that folds case puts both in one place).
				$cursor['names'][] = $dir;
				if ( count( $cursor['names'] ) > self::MAX_SELF ) {
					throw new CannotStage( sprintf( 'The backup holds more than %d plugin directories named like WP Checkpoint\'s; it is not a backup this plugin wrote.', self::MAX_SELF ) );
				}
			}
			if ( isset( $cursor['self'][ $dir ] ) || 1 !== preg_match( '#\A[^/]+/[^/]+\.php\z#i', $map['relative'] ) || '' !== self::special( $chunk['entry'] ) ) {
				continue;
			}
			// The bytes a head costs count toward the unit: a deflated entry is inflated whole to read it.
			$read += ZipFormat::METHOD_STORE === (int) $chunk['entry']['method'] ? (int) min( PluginIdentity::HEAD_BYTES, (int) $chunk['entry']['usize'] ) : (int) $chunk['entry']['usize'];
			$head  = $this->head( $chunk['reader'], $chunk['entry'] );
			$basis = $identity->basis( $head );
			if ( '' === $basis ) {
				continue;
			}
			$cursor['self'][ $dir ] = $basis;
			if ( count( $cursor['self'] ) > self::MAX_SELF ) {
				throw new CannotStage( sprintf( 'The backup holds more than %d plugin directories that are WP Checkpoint; it is not a backup this plugin wrote.', self::MAX_SELF ) );
			}
		}
		$cursor['at'] = $at;
		return $cursor;
	}

	/**
	 * The end of the identify phase: the plan and its report lines; the cursor of the files phase.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param string               $running The running plugin's directory name.
	 * @return array<string, mixed>
	 */
	private function identified( JobContext $context, array $cursor, string $running ): array {
		$work  = $context->work_path();
		$self  = (array) $cursor['self'];
		$taken = array_values(
			array_filter(
				(array) $cursor['names'],
				static function ( $dir ) use ( $self ): bool {
					return ! isset( $self[ $dir ] );
				}
			)
		);
		ExportPlan::write(
			$work,
			RestoreFiles::STAGE_PLAN,
			array(
				'self'    => $self,
				'taken'   => $taken,
				'running' => $running,
			)
		);
		$lines = '';
		foreach ( $self as $dir => $basis ) {
			$lines .= self::json(
				array(
					'kind'  => 'plugin_self',
					'p'     => StagingLayout::CONTENT . '/plugins/' . $dir,
					'basis' => $basis,
					'why'   => 'This is WP Checkpoint; the running copy is staged instead.',
				)
			) . "\n";
		}
		foreach ( $taken as $dir ) {
			$lines .= self::json(
				array(
					'kind' => 'plugin_name_taken',
					'p'    => StagingLayout::CONTENT . '/plugins/' . $dir,
					'why'  => 'This directory has the name of the running WP Checkpoint\'s directory (compared without case, as many file systems compare names), where the running copy is staged, but it is not WP Checkpoint: it is left out.',
				)
			) . "\n";
		}
		$report = (int) $cursor['report'];
		if ( '' !== $lines ) {
			self::append( RestoreFiles::path( $work, RestoreFiles::REPORT ), $lines );
			$report += strlen( $lines );
		}
		$this->at( 'identified' );
		return array(
			'phase'  => 'files',
			'at'     => $cursor['start'],
			'done'   => 0,
			'crc'    => 0,
			'report' => $report,
		);
	}

	/**
	 * The files phase: a result to return, or null when it is over (the cursor then holds plugin_list).
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @param array<string, mixed> $staging Staging file.
	 * @param StagingLayout        $layout  Layout.
	 * @param ChunkWalk            $walk    The files walk.
	 * @param array<string, mixed> $plan    The restore's plan.
	 * @param bool                 $first   Whether no unit ran in this tick yet.
	 * @return StepResult|null
	 * @throws BackupUnusable When a file does not match its hash and retrying cannot change it.
	 * @throws EnvironmentFailure When the backup cannot be read or a staged file cannot be written.
	 * @throws StagingChanged When the staging directory was changed.
	 */
	private function files( JobContext $context, array &$cursor, array $staging, StagingLayout $layout, ChunkWalk $walk, array $plan, bool $first ) {
		$work        = $context->work_path();
		$chunk_bytes = (int) $plan['chunk_bytes'];
		$stage_plan  = ExportPlan::read( $work, RestoreFiles::STAGE_PLAN );
		$skip        = array_keys( (array) ( $stage_plan['self'] ?? array() ) );
		$running     = self::fold( (string) ( $stage_plan['running'] ?? '' ) );
		$dir_mode    = StageModes::dir();
		$file_mode   = StageModes::file();
		$slowest     = 0.0;
		$budget      = (float) $context->budget()->seconds;
		$since       = 0;
		while ( true ) {
			if ( ! $first && ( $context->should_stop() || $context->remaining_seconds() < $slowest * self::MARGIN ) ) {
				return StepResult::progress( $cursor, 80, __( 'Staging the files', 'wp-checkpoint' ) );
			}
			$first = false;
			$chunk = $walk->at( $cursor['at'] );
			if ( null === $chunk ) {
				// The running copy goes where plugins are staged; without them the live plugins, it included, stay.
				$cursor = array(
					'phase'  => in_array( 'plugins', (array) $staging['staged'], true ) ? 'plugin_list' : 'end',
					'report' => $cursor['report'],
				);
				$context->checkpoint( $cursor, 90, __( 'Staging this plugin', 'wp-checkpoint' ) );
				return null;
			}
			$line = $chunk['line'];
			$map  = $layout->map( (string) $line['p'] );
			$top  = null === $map ? '' : explode( '/', $map['relative'] )[0];
			if ( null === $map || ! in_array( $map['group'], (array) $staging['staged'], true ) || ( 'plugins' === $map['group'] && ( in_array( $top, $skip, true ) || self::fold( $top ) === $running ) ) ) {
				$cursor['at'] = $chunk['next']; // Not restored (listed by the files preflight), or this plugin's place.
				continue;
			}
			$special = self::special( $chunk['entry'] );
			if ( '' !== $special ) {
				$text = self::json(
					array(
						'kind' => 'special',
						'type' => $special,
						'p'    => (string) $line['p'],
						'why'  => 'Not created: a restore writes regular files and directories only.',
					)
				) . "\n";
				self::append( RestoreFiles::path( $work, RestoreFiles::REPORT ), $text );
				$this->at( 'report' );
				$cursor['report'] = (int) $cursor['report'] + strlen( $text );
				$cursor['at']     = $chunk['next'];
				continue;
			}
			$started = $context->elapsed();
			$written = $this->write_unit( $context, $cursor, $chunk, $layout, $map['group'], $map['group'] . '/' . $map['relative'], $chunk_bytes, $dir_mode, $file_mode );
			$cost    = $context->elapsed() - $started;
			$slowest = max( $slowest, $cost );
			if ( $cost > $budget ) {
				throw new EnvironmentFailure( sprintf( 'Writing the staged files is too slow on this server: one piece of %1$d MB took %2$d seconds, more than the %3$d-second time budget of a single run.', (int) ceil( $written / 1048576 ), (int) ceil( $cost ), (int) $budget ) );
			}
			$since += $written;
			if ( $context->should_checkpoint( $since ) ) {
				$context->checkpoint( $cursor, 80, __( 'Staging the files', 'wp-checkpoint' ) );
				$since = 0;
			}
		}
	}

	/**
	 * One unit of the files phase: the next piece of the current file (all of it when it is one chunk or
	 * deflated), hashed as it is read; the cursor moves past it once it is checked.
	 *
	 * @param JobContext           $context     Context.
	 * @param array<string, mixed> $cursor      Cursor (updated).
	 * @param array<string, mixed> $chunk       The walk's entry.
	 * @param StagingLayout        $layout      Layout.
	 * @param string               $group       The file's group.
	 * @param string               $relative    Path under the staging root ("{group}/…").
	 * @param int                  $chunk_bytes The manifest's chunk size.
	 * @param int                  $dir_mode    Directory mode.
	 * @param int                  $file_mode   File mode.
	 * @return int Bytes written.
	 * @throws BackupUnusable When the bytes do not match and retrying cannot change it.
	 * @throws EnvironmentFailure When the backup cannot be read or the file cannot be written.
	 * @throws StagingChanged When the staging directory was changed.
	 */
	private function write_unit( JobContext $context, array &$cursor, array $chunk, StagingLayout $layout, string $group, string $relative, int $chunk_bytes, int $dir_mode, int $file_mode ): int {
		$root   = $layout->root( $group ); // Checked itself on every write, and every level under it.
		$where  = $layout->parent( $group );
		$line   = $chunk['line'];
		$entry  = $chunk['entry'];
		$size   = (int) $line['b'];
		$done   = (int) $cursor['done'];
		$stored = ZipFormat::METHOD_STORE === (int) $entry['method'];
		$length = $stored ? (int) min( $chunk_bytes, $size - $done ) : $size;
		if ( ! $stored && 0 !== $done ) {
			throw new WorkLost( 'The restore\'s position is inside a compressed file, which is written in one piece; the work directory was changed.' );
		}
		$path    = $root . '/' . $relative;
		$handle  = StagedWriter::open(
			$root,
			$relative,
			$done,
			$dir_mode,
			$file_mode,
			function (): void {
				$this->at( 'dir' );
			}
		);
		$hashes  = new ChunkHashes( $done, $chunk_bytes, $size );
		$filter  = $this->parts['read'] ?? null;
		$failure = null;
		try {
			$crc = $chunk['reader']->stream_range(
				$entry,
				$done,
				$length,
				(int) $cursor['crc'],
				function ( string $piece ) use ( $handle, $hashes, $filter, $where ): void {
					if ( null !== $filter ) {
						$piece = (string) call_user_func( $filter, $piece );
					}
					$hashes->update( $piece );
					if ( strlen( $piece ) !== $this->write( $handle, $piece ) ) {
						throw $this->write_failure( $where, strlen( $piece ) );
					}
				}
			);
		} catch ( EnvironmentFailure $e ) {
			fclose( $handle );
			throw $e;
		} catch ( \RuntimeException $e ) {
			// A CRC mismatch or a truncated entry: the bytes are not the backup's, as a hash mismatch would say.
			$failure = $e->getMessage();
			$crc     = 0;
		}
		if ( ! @fflush( $handle ) ) {
			fclose( $handle );
			throw $this->write_failure( $where, 0 );
		}
		fclose( $handle );
		$this->at( 'piece' );
		if ( null === $failure ) {
			$failure = $hashes->mismatch( $line );
		}
		if ( null !== $failure ) {
			$again = $this->reread( $chunk, $done, $length, (int) $cursor['crc'], $chunk_bytes, $size );
			$context->confirm_lease(); // Removing a staged file: a run that lost the job must not remove the holder's.
			@unlink( $path );
			throw $this->unreadable( $context, (int) $chunk['next']['volume'], (string) $line['p'], $failure, null === $again );
		}
		if ( $done + $length < $size ) {
			$cursor['done'] = $done + $length;
			$cursor['crc']  = $crc;
			return $length;
		}
		$this->at( 'complete' );
		if ( ! @touch( $path, (int) $line['m'] ) ) {
			throw new EnvironmentFailure( sprintf( 'The modification time of the staged file %s cannot be set.', $relative ) );
		}
		$this->at( 'touched' );
		$cursor['at']   = $chunk['next'];
		$cursor['done'] = 0;
		$cursor['crc']  = 0;
		return $length;
	}

	/**
	 * The failure for bytes of the backup that do not match what the manifest says, by what the check saw:
	 * the volume changed since (its size or modification time), it read differently from the full check that
	 * read it before (this server's storage), or it was never read in full (damaged).
	 *
	 * @param JobContext $context Context.
	 * @param int        $volume  Volume number (0-based).
	 * @param string     $path    The backup path.
	 * @param string     $detail  What did not match.
	 * @param bool       $settled Whether reading the same range again gave the right bytes (the first read was wrong).
	 * @return \RuntimeException
	 * @throws WorkLost When the record of the check is gone.
	 */
	private function unreadable( JobContext $context, int $volume, string $path, string $detail, bool $settled ): \RuntimeException {
		$work    = $context->work_path();
		$sources = ExportPlan::read( $work, RestoreFiles::SOURCES );
		$plan    = RestorePreflightStep::load_plan( $work );
		$file    = (string) ( $plan['volumes'][ $volume ] ?? '' );
		$checked = $sources['volumes'][ $volume ] ?? null;
		if ( ! is_array( $checked ) || '' === $file ) {
			throw new WorkLost( 'The record of what the check read is gone from the work directory.' );
		}
		clearstatcache( true, $file );
		$now = @stat( $file );
		if ( false === $now || (int) $now['size'] !== (int) $checked['size'] || (int) $now['mtime'] !== (int) $checked['mtime'] ) {
			return new BackupUnusable( sprintf( 'The backup file %1$s was changed while it was being restored (it is not the file the restore checked), so %2$s cannot be restored from it. Start the restore again, and leave the backup alone while it runs.', basename( $file ), $path ) );
		}
		// Read right the second time, or right before (the full check): not the backup, the reading of it.
		if ( $settled ) {
			return new EnvironmentFailure( sprintf( 'The backup file %1$s could not be read the same way twice: %2$s read wrong once (%3$s) and right when it was read again. This points at the storage of this server; try again, and have the disk checked if it happens again.', basename( $file ), $path, $detail ), EnvironmentFailure::ACCESS );
		}
		if ( ArchiveVerifier::DEPTH_FULL === ( $sources['depth'] ?? '' ) ) {
			return new EnvironmentFailure( sprintf( 'The backup file %1$s could not be read the same way twice: the restore checked every byte of it, and now %2$s reads differently (%3$s). This points at the storage of this server; try again, and have the disk checked if it happens again.', basename( $file ), $path, $detail ), EnvironmentFailure::ACCESS );
		}
		return new BackupUnusable( sprintf( 'The backup is damaged: %1$s in %2$s does not match its recorded contents (%3$s). Restore from another backup.', $path, basename( $file ), $detail ) );
	}

	/**
	 * Read a unit's range of the backup again, only to compare: what does not match this time, or null when it
	 * does (the first read was wrong, not the backup).
	 *
	 * @param array<string, mixed> $chunk       The walk's entry.
	 * @param int                  $done        Start of the range.
	 * @param int                  $length      Its length.
	 * @param int                  $crc         CRC before it.
	 * @param int                  $chunk_bytes Chunk size.
	 * @param int                  $size        The file's size.
	 * @return string|null
	 * @throws EnvironmentFailure When the volume cannot be read.
	 */
	private function reread( array $chunk, int $done, int $length, int $crc, int $chunk_bytes, int $size ) {
		$hashes = new ChunkHashes( $done, $chunk_bytes, $size );
		$filter = $this->parts['read'] ?? null;
		try {
			$chunk['reader']->stream_range(
				$chunk['entry'],
				$done,
				$length,
				$crc,
				static function ( string $piece ) use ( $hashes, $filter ): void {
					$hashes->update( null === $filter ? $piece : (string) call_user_func( $filter, $piece ) );
				}
			);
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return $hashes->mismatch( $chunk['line'] );
	}

	/**
	 * The plugin_list phase: the running copy's files, listed in a work file; the cursor of the plugin phase.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @return array<string, mixed>
	 * @throws CannotStage When the plugin directory holds more than RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES entries.
	 * @throws TransientFailure When the list cannot be written.
	 */
	private function plugin_list( JobContext $context, array $cursor ): array {
		$root  = $this->plugin_dir();
		$lines = '';
		$seen  = 0;
		$dirs  = array( '' );
		while ( array() !== $dirs ) {
			$dir     = (string) array_shift( $dirs );
			$entries = @scandir( '' === $dir ? $root : $root . '/' . $dir );
			$entries = is_array( $entries ) ? $entries : array();
			sort( $entries, SORT_STRING );
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ++$seen > RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES ) {
					throw new CannotStage( sprintf( 'The directory of WP Checkpoint holds more than %d entries; it is not this plugin as released. Reinstall the plugin, then try again.', RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES ) );
				}
				$relative = '' === $dir ? $entry : $dir . '/' . $entry;
				$path     = $root . '/' . $relative;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$dirs[] = $relative;
				} elseif ( is_file( $path ) ) {
					$lines .= self::json(
						array(
							'r' => $relative,
							'b' => (int) @filesize( $path ),
						)
					) . "\n";
				}
			}
		}
		$list = RestoreFiles::path( $context->work_path(), RestoreFiles::PLUGIN_LIST );
		$temp = $list . '.' . bin2hex( random_bytes( 4 ) ) . '.part';
		if ( false === @file_put_contents( $temp, $lines ) || ! @rename( $temp, $list ) ) {
			@unlink( $temp );
			throw new TransientFailure( 'A work file of the restore could not be written.' );
		}
		$this->at( 'plugin_list' );
		return array(
			'phase'  => 'plugin',
			'line'   => 0,
			'done'   => 0,
			'report' => $cursor['report'],
		);
	}

	/**
	 * The plugin phase: a result to return, or null when every file is copied.
	 *
	 * @param JobContext           $context Context.
	 * @param array<string, mixed> $cursor  Cursor (updated).
	 * @param StagingLayout        $layout  Layout.
	 * @param array<string, mixed> $plan    The restore's plan.
	 * @param bool                 $first   Whether no unit ran in this tick yet.
	 * @return StepResult|null
	 * @throws EnvironmentFailure When a file cannot be read or written.
	 * @throws StagingChanged When the staging directory was changed.
	 * @throws WorkLost When the list is gone.
	 */
	private function plugin( JobContext $context, array &$cursor, StagingLayout $layout, array $plan, bool $first ) {
		$list = @fopen( RestoreFiles::path( $context->work_path(), RestoreFiles::PLUGIN_LIST ), 'rb' );
		if ( false === $list ) {
			throw new WorkLost( 'The list of this plugin\'s files is gone from the work directory.' );
		}
		$root        = $layout->root( 'plugins' ); // Checked itself on every write, and every level under it.
		$where       = $layout->parent( 'plugins' );
		$running     = $this->running_name();
		$source      = $this->plugin_dir();
		$source_real = realpath( $source );
		$chunk_bytes = (int) $plan['chunk_bytes'];
		$slowest     = 0.0;
		$budget      = (float) $context->budget()->seconds;
		$since       = 0;
		try {
			while ( true ) {
				if ( ! $first && ( $context->should_stop() || $context->remaining_seconds() < $slowest * self::MARGIN ) ) {
					return StepResult::progress( $cursor, 92, __( 'Staging this plugin', 'wp-checkpoint' ) );
				}
				$first = false;
				if ( 0 !== fseek( $list, (int) $cursor['line'] ) ) {
					throw new WorkLost( 'The list of this plugin\'s files is shorter than recorded.' );
				}
				$text = fgets( $list );
				if ( false === $text ) {
					return null;
				}
				$item = json_decode( $text, true );
				if ( ! is_array( $item ) || ! isset( $item['r'], $item['b'] ) || ! is_string( $item['r'] ) || null !== EntryPath::problem( $item['r'] ) ) {
					throw new WorkLost( 'The list of this plugin\'s files is damaged.' );
				}
				$started = $context->elapsed();
				$from    = $source . '/' . $item['r'];
				$size    = (int) $item['b'];
				$done    = (int) $cursor['done'];
				$length  = (int) min( $chunk_bytes, $size - $done );
				$target  = 'plugins/' . $running . '/' . $item['r'];
				// The file as it was listed: a regular file (a link put there since is not followed), and the one opened.
				clearstatcache( true, $from );
				$looked = @lstat( $from );
				$in     = false === $looked || 0100000 !== ( (int) $looked['mode'] & 0170000 ) ? false : @fopen( $from, 'rb' );
				$opened = false === $in ? false : fstat( $in );
				// Still under the plugin's directory (a directory of the list swapped for a link is not followed), and
				// still the size it was listed with (a file changed since would be copied cut short).
				$real   = false === $in ? false : realpath( dirname( $from ) );
				$inside = false !== $real && false !== $source_real && ( $real === $source_real || 0 === strpos( $real . '/', rtrim( $source_real, '/' ) . '/' ) );
				if ( false === $in || ! $inside || ! is_array( $opened ) || (int) $opened['dev'] !== (int) $looked['dev'] || (int) $opened['ino'] !== (int) $looked['ino'] || (int) $opened['size'] !== $size || 0 !== fseek( $in, $done ) ) {
					if ( false !== $in ) {
						fclose( $in );
					}
					throw new EnvironmentFailure( sprintf( 'A file of the running WP Checkpoint cannot be read as it was listed (%s); the plugin\'s directory changed during the restore.', $item['r'] ) );
				}
				$out = StagedWriter::open( $root, $target, $done, StageModes::dir(), StageModes::file() );
				try {
					for ( $left = $length; $left > 0; ) {
						$piece = fread( $in, (int) min( 1048576, $left ) );
						if ( false === $piece || '' === $piece ) {
							throw new EnvironmentFailure( sprintf( 'A file of the running WP Checkpoint changed while it was copied (%s).', $item['r'] ) );
						}
						if ( strlen( $piece ) !== $this->write( $out, $piece ) ) {
							throw $this->write_failure( $where, strlen( $piece ) );
						}
						$left -= strlen( $piece );
					}
				} finally {
					fclose( $in );
					fclose( $out );
				}
				$this->at( 'plugin_piece' );
				if ( $done + $length < $size ) {
					$cursor['done'] = $done + $length;
				} else {
					if ( ! @touch( $root . '/' . $target, (int) $looked['mtime'] ) ) {
						throw new EnvironmentFailure( sprintf( 'The modification time of the staged file %s cannot be set.', $target ) );
					}
					$cursor['line'] = (int) $cursor['line'] + strlen( $text );
					$cursor['done'] = 0;
				}
				$cost    = $context->elapsed() - $started;
				$slowest = max( $slowest, $cost );
				if ( $cost > $budget ) {
					throw new EnvironmentFailure( sprintf( 'Writing the staged files is too slow on this server: one piece of %1$d MB took %2$d seconds, more than the %3$d-second time budget of a single run.', (int) ceil( $length / 1048576 ), (int) ceil( $cost ), (int) $budget ) );
				}
				$since += $length;
				if ( $context->should_checkpoint( $since ) ) {
					$context->checkpoint( $cursor, 92, __( 'Staging this plugin', 'wp-checkpoint' ) );
					$since = 0;
				}
			}
		} finally {
			fclose( $list );
		}
	}

	/**
	 * Log what staging did not write: counts by kind and the first lines.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	private function summarise( JobContext $context ): void {
		$handle = @fopen( RestoreFiles::path( $context->work_path(), RestoreFiles::REPORT ), 'rb' );
		if ( false === $handle ) {
			return;
		}
		$counts = array();
		$listed = array();
		for ( $text = fgets( $handle ); false !== $text; $text = fgets( $handle ) ) {
			$item = json_decode( $text, true );
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind            = (string) ( $item['type'] ?? $item['kind'] ?? '' );
			$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + 1;
			if ( count( $listed ) < self::MAX_LISTED ) {
				$listed[] = $item;
			}
		}
		fclose( $handle );
		if ( array() !== $counts ) {
			$context->logger()->warning(
				'Entries of the backup that were not staged, and why',
				array(
					'counts'  => $counts,
					'example' => $listed,
				)
			);
		}
	}

	/**
	 * The head of an entry (PluginIdentity::HEAD_BYTES), as read from the backup.
	 *
	 * @param \WPCheckpoint\Archive\ZipReader $reader Volume.
	 * @param array<string, mixed>            $entry  Entry.
	 * @return string
	 * @throws EnvironmentFailure When the volume cannot be read.
	 */
	private function head( $reader, array $entry ): string {
		$head   = '';
		$stored = ZipFormat::METHOD_STORE === (int) $entry['method'];
		try {
			$reader->stream_range(
				$entry,
				0,
				$stored ? (int) min( PluginIdentity::HEAD_BYTES, (int) $entry['usize'] ) : (int) $entry['usize'],
				0,
				static function ( string $piece ) use ( &$head ): void {
					if ( strlen( $head ) < PluginIdentity::HEAD_BYTES ) {
						$head .= substr( $piece, 0, PluginIdentity::HEAD_BYTES - strlen( $head ) );
					}
				}
			);
		} catch ( EnvironmentFailure $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			return ''; // Not readable as a plugin header: the files phase finds out what is wrong with it.
		}
		return $head;
	}

	/**
	 * What kind of special file an entry is ('' for a regular file).
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string
	 */
	private static function special( array $entry ): string {
		return ZipFormat::special_type( (int) $entry['made_by'], (int) $entry['external'] );
	}

	/**
	 * The database chunks of the backup: its database index's lines.
	 *
	 * @param string $work Work directory.
	 * @return int
	 * @throws WorkLost When the index is gone.
	 */
	private static function database_chunks( string $work ): int {
		$handle = @fopen( RestoreFiles::path( $work, RestoreFiles::INDEX ), 'rb' );
		if ( false === $handle ) {
			throw new WorkLost( 'The database index of the restore is gone from the work directory.' );
		}
		$lines = 0;
		while ( ! feof( $handle ) ) {
			$block  = (string) fread( $handle, 1048576 );
			$lines += substr_count( $block, "\n" );
		}
		fclose( $handle );
		return $lines;
	}

	/**
	 * A write that failed: the disk is full when too little is free for the piece, an unwritable file otherwise.
	 *
	 * @param string $where The directory the staging root is in (named in the message; the root's name is not).
	 * @param int    $bytes Bytes that were to be written.
	 * @return EnvironmentFailure
	 */
	private function write_failure( string $where, int $bytes ): EnvironmentFailure {
		$free = isset( $this->parts['free'] ) ? call_user_func( $this->parts['free'], $where ) : HostFunctions::disk_free_space( $where );
		if ( false !== $free && $free < max( 1048576, $bytes ) ) {
			return new EnvironmentFailure( sprintf( 'The disk of %s is full: the restore could not write its staged files there. Free some space (the preflight checked that enough was free; something else has used it since), then try again.', $where ), EnvironmentFailure::ACCESS );
		}
		return new EnvironmentFailure( sprintf( 'A staged file in %s could not be written.', $where ), EnvironmentFailure::ACCESS );
	}

	/**
	 * A directory name as a file system that folds case and Unicode forms compares it (the safe side: two
	 * names that may be one are taken as one).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function fold( string $name ): string {
		return function_exists( 'mb_check_encoding' ) && mb_check_encoding( $name, 'UTF-8' ) ? PathKey::of( $name ) : strtolower( $name );
	}

	/**
	 * Write bytes to a staged file.
	 *
	 * @param resource $handle Handle.
	 * @param string   $bytes  Bytes.
	 * @return int|false
	 */
	private function write( $handle, string $bytes ) {
		if ( isset( $this->parts['write'] ) ) {
			return call_user_func( $this->parts['write'], $handle, $bytes );
		}
		return @fwrite( $handle, $bytes );
	}

	/**
	 * The running copy of this plugin.
	 *
	 * @return string
	 */
	private function plugin_dir(): string {
		return rtrim( (string) ( $this->parts['plugin_dir'] ?? WPCHECKPOINT_DIR ), '/\\' );
	}

	/**
	 * The running copy's directory name.
	 *
	 * @return string
	 */
	private function running_name(): string {
		return basename( $this->plugin_dir() );
	}

	/**
	 * A crash seam (tests).
	 *
	 * @param string $point Point.
	 * @return void
	 */
	private function at( string $point ): void {
		if ( isset( $this->parts['at'] ) ) {
			call_user_func( $this->parts['at'], $point );
		}
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
	 * Cut an appended work file back to its committed length; fail when it is shorter.
	 *
	 * @param string $path   File.
	 * @param int    $length Committed length.
	 * @return void
	 * @throws WorkLost When it is shorter than committed.
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
	 * Nothing to do: the staging roots are the engine's to reclaim (Residue: stage_dir), with the work directory.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}

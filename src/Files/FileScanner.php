<?php
/**
 * Walks the directories a backup covers and lists every file, one bounded unit at a time.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Files;

use WPCheckpoint\Archive\EntryPath;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Support\Utf8;

/**
 * Pure PHP: the roots (absolute directory, the archive path prefix its
 * files get, subtrees to skip) and the exclusions are injected. One
 * scan_unit() call handles at most UNIT_ENTRIES directory entries and
 * returns a state that fully describes where to continue: the current
 * root, and for every open directory its path and the last entry name
 * already handled. Continuing lists that directory again and goes on
 * after that name, which works because every listing is sorted the same
 * way (see sorted_names()).
 *
 * The walk is iterative with an explicit stack, so a deeply nested tree
 * costs stack entries, not call frames. Links are never followed and are
 * counted as skipped; special files (fifo, socket, device) are skipped;
 * an unreadable file or directory is recorded (the pre-flight asks the
 * user to confirm before backing up without it) and the walk goes on.
 * Directories are not listed in the index: the archive holds files, and
 * an empty directory is not part of a backup.
 *
 * Every file line carries the archive path "p", the size "b" and the
 * modification time "m"; no hash. Hashing happens while the packer reads
 * the file anyway, and the manifest step refuses an index whose lines
 * lack hashes (see T032), so a scan is never mistaken for a hashed index.
 *
 * Sibling names that collide under PathKey (case, Unicode form) are
 * reported once per directory: on Windows and macOS they extract onto one
 * file. The check is per directory because that is the only place such
 * collisions can occur; a whole-site set would not fit in memory.
 */
final class FileScanner {

	const UNIT_ENTRIES = 1000;
	const MAX_LISTED   = 50;

	/**
	 * Directory names whose subtree is summed up (lists.heavy) so the
	 * pre-flight can offer to leave them out: build and version-control
	 * trees that are never needed to restore a site. Nested ones count
	 * towards the outermost. Nothing is excluded by this list; the user
	 * decides.
	 */
	const HEAVY_NAMES = array( 'node_modules', '.git', '.svn', '.hg' );

	/**
	 * Roots: group, path (absolute), prefix (archive path, no trailing slash), skip (absolute paths not to enter).
	 *
	 * @var array<int, array{group: string, path: string, prefix: string, skip: string[]}>
	 */
	private $roots;

	/**
	 * Exclusions.
	 *
	 * @var Exclusions
	 */
	private $exclusions;

	/**
	 * Largest file a backup can hold here and which limit applies (Packer::max_file_bytes()).
	 *
	 * @var array{bytes: int, limited_by: string}
	 */
	private $max_file;

	/**
	 * Constructor.
	 *
	 * @param array<int, array{group: string, path: string, prefix: string, skip?: string[]}> $roots      Roots in scan order.
	 * @param Exclusions                                                                      $exclusions Exclusions.
	 * @param int                                                                             $int_size   PHP_INT_SIZE of the platform.
	 * @param int                                                                             $chunk_bytes Content chunk size (bounds the largest indexable file).
	 */
	public function __construct( array $roots, Exclusions $exclusions, int $int_size = PHP_INT_SIZE, int $chunk_bytes = Manifest::DEFAULT_CHUNK ) {
		$this->roots = array();
		foreach ( $roots as $root ) {
			$this->roots[] = array(
				'group'  => (string) $root['group'],
				'path'   => rtrim( (string) $root['path'], '/\\' ),
				'prefix' => trim( (string) $root['prefix'], '/' ),
				'skip'   => array_map(
					static function ( $path ): string {
						return rtrim( (string) $path, '/\\' );
					},
					isset( $root['skip'] ) ? (array) $root['skip'] : array()
				),
			);
		}
		$this->exclusions = $exclusions;
		$this->max_file   = Packer::max_file_bytes( $chunk_bytes, $int_size );
	}

	/**
	 * The state a scan starts from.
	 *
	 * @return array<string, mixed>
	 */
	public static function initial_state(): array {
		return array(
			'root'     => 0,
			'stack'    => array(),
			'done'     => false,
			'counts'   => array(
				'files'            => 0,
				'bytes'            => 0,
				'directories'      => 0,
				'excluded'         => 0,
				'links'            => 0,
				'special'          => 0,
				'unreadable'       => 0,
				'bad_names'        => 0,
				'collisions'       => 0,
				'too_large'        => 0,
				'over_volume'      => 0,
				'invalid_patterns' => 0,
				'heavy'            => 0,
			),
			'lists'    => array(
				'unreadable'  => array(),
				'too_large'   => array(),
				'over_volume' => array(),
				'heavy'       => array(),
			),
			'warnings' => array(),
			'limits'   => array(),
		);
	}

	/**
	 * Handle up to UNIT_ENTRIES entries and return where to continue.
	 *
	 * @param array<string, mixed> $state State from initial_state() or a previous call.
	 * @param callable             $emit  function( array{p: string, b: int, m: int} $line ): void.
	 * @return array<string, mixed>
	 */
	public function scan_unit( array $state, callable $emit ): array {
		// The threshold this scanner judges with, for the summary: the review reads it from there, never recomputes it.
		$state['limits'] = array(
			'max_file_bytes' => $this->max_file['bytes'],
			'max_file_limit' => $this->max_file['limited_by'],
		);
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		if ( array() === $state['stack'] && 0 === (int) $state['root'] && 0 === (int) $state['counts']['directories'] ) {
			foreach ( $this->exclusions->invalid() as $glob ) {
				$this->warn( $state, 'invalid_patterns', 'An exclusion pattern could not be used: ' . $glob );
			}
		}
		$handled = 0;
		while ( $handled < self::UNIT_ENTRIES ) {
			if ( array() === $state['stack'] ) {
				if ( (int) $state['root'] >= count( $this->roots ) ) {
					$state['done'] = true;
					return $state;
				}
				$root = $this->roots[ (int) $state['root'] ];
				if ( ! is_dir( $root['path'] ) || is_link( $root['path'] ) ) {
					$this->warn( $state, 'unreadable', 'A content directory is missing or is a link and was not scanned: ' . $root['prefix'] );
					++$state['root'];
					continue;
				}
				$state['stack'] = array(
					array(
						'dir'   => '',
						'after' => '',
					),
				);
			}
			$frame = $state['stack'][ count( $state['stack'] ) - 1 ];
			$root  = $this->roots[ (int) $state['root'] ];
			$abs   = '' === $frame['dir'] ? $root['path'] : $root['path'] . '/' . $frame['dir'];
			$names = $this->sorted_names( $abs );
			if ( null === $names ) {
				$this->record( $state, 'unreadable', $this->archive_path( $root, $frame['dir'] ) );
				array_pop( $state['stack'] );
				$this->leave( $state );
				continue;
			}
			if ( '' === $frame['after'] ) {
				++$state['counts']['directories'];
				foreach ( PathKey::collisions( $names ) as $group ) {
					++$state['counts']['collisions'];
					$this->warn( $state, 'collisions', 'These names differ only in case or Unicode form and would overwrite each other when restored on Windows or macOS: ' . $this->archive_path( $root, $frame['dir'] ) . ' [' . implode( ', ', $group ) . ']' );
				}
			}
			$entered = false;
			foreach ( $names as $name ) {
				if ( '' !== $frame['after'] && strcmp( $name, $frame['after'] ) <= 0 ) {
					continue;
				}
				++$handled;
				$frame['after']                                 = $name;
				$state['stack'][ count( $state['stack'] ) - 1 ] = $frame;
				$rel = '' === $frame['dir'] ? $name : $frame['dir'] . '/' . $name;
				$sub = $this->enter( $state, $root, $rel, $abs . '/' . $name, $emit, isset( $frame['heavy'] ) ? (string) $frame['heavy'] : '' );
				if ( null !== $sub ) {
					$state['stack'][] = $sub;
					$entered          = true;
					break;
				}
				if ( $handled >= self::UNIT_ENTRIES ) {
					return $state;
				}
			}
			if ( ! $entered ) {
				array_pop( $state['stack'] );
				$this->leave( $state );
			}
		}
		return $state;
	}

	/**
	 * Handle one entry. Returns a new stack frame when it is a directory to enter.
	 *
	 * @param array<string, mixed>                                               $state State (updated).
	 * @param array{group: string, path: string, prefix: string, skip: string[]} $root  Root.
	 * @param string                                                             $rel   Path relative to the root.
	 * @param string                                                             $abs   Absolute path.
	 * @param callable                                                           $emit  Line sink.
	 * @param string                                                             $heavy The heavy directory this entry is under ('' when none).
	 * @return array{dir: string, after: string, heavy?: string}|null
	 */
	private function enter( array &$state, array $root, string $rel, string $abs, callable $emit, string $heavy = '' ) {
		$p = $root['prefix'] . '/' . $rel;
		if ( Utf8::scrub( $p ) !== $p || null !== EntryPath::problem( $p ) ) {
			++$state['counts']['bad_names'];
			$this->warn( $state, 'bad_names', 'A file or directory was skipped because its name cannot be stored in an archive: ' . $root['prefix'] . '/' . Utf8::scrub( $rel ) );
			return null;
		}
		if ( is_link( $abs ) ) {
			++$state['counts']['links'];
			return null;
		}
		if ( in_array( $abs, $root['skip'], true ) || $this->exclusions->excludes( $p ) ) {
			++$state['counts']['excluded'];
			return null;
		}
		if ( is_dir( $abs ) ) {
			$frame = array(
				'dir'   => $rel,
				'after' => '',
			);
			if ( '' !== $heavy ) {
				$frame['heavy'] = $heavy;
			} elseif ( in_array( basename( $rel ), self::HEAVY_NAMES, true ) ) {
				++$state['counts']['heavy'];
				if ( count( $state['lists']['heavy'] ) < self::MAX_LISTED ) {
					$state['lists']['heavy'][ $p ] = 0;
					$frame['heavy']                = $p;
				}
			}
			return $frame;
		}
		$type = @filetype( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished entry is handled below.
		if ( 'file' !== $type ) {
			if ( false !== $type ) {
				++$state['counts']['special'];
			}
			return null;
		}
		if ( ! is_readable( $abs ) ) {
			$this->record( $state, 'unreadable', $p );
			return null;
		}
		$stat = @stat( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $stat ) {
			$this->record( $state, 'unreadable', $p );
			return null;
		}
		$size = (int) $stat['size'];
		if ( $size > $this->max_file['bytes'] ) {
			$this->record( $state, 'too_large', $p );
		} elseif ( $size > Packer::VOLUME_BYTES ) {
			$this->record( $state, 'over_volume', $p );
		}
		$emit(
			array(
				'p' => $p,
				'b' => $size,
				'm' => (int) $stat['mtime'],
			)
		);
		++$state['counts']['files'];
		$state['counts']['bytes'] += $size;
		if ( '' !== $heavy && isset( $state['lists']['heavy'][ $heavy ] ) ) {
			$state['lists']['heavy'][ $heavy ] += $size;
		}
		return null;
	}

	/**
	 * After a directory is popped: an empty stack moves to the next root.
	 *
	 * @param array<string, mixed> $state State (updated).
	 * @return void
	 */
	private function leave( array &$state ): void {
		if ( array() === $state['stack'] ) {
			++$state['root'];
		}
	}

	/**
	 * Entry names of a directory in a stable order, or null when it cannot be listed.
	 *
	 * The order must be identical on every listing, on every PHP version and
	 * locale, because a resumed scan relies on "everything after the last
	 * handled name". sort() without a flag would compare numeric-looking
	 * names as numbers; SORT_STRING compares bytes.
	 *
	 * @param string $dir Directory.
	 * @return string[]|null
	 */
	private function sorted_names( string $dir ) {
		$entries = @scandir( $dir, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory is recorded by the caller.
		if ( false === $entries ) {
			return null;
		}
		$names = array();
		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$names[] = $entry;
			}
		}
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Archive path of a directory relative to a root.
	 *
	 * @param array{group: string, path: string, prefix: string, skip: string[]} $root Root.
	 * @param string                                                             $rel  Relative directory ('' for the root).
	 * @return string
	 */
	private function archive_path( array $root, string $rel ): string {
		return '' === $rel ? $root['prefix'] : $root['prefix'] . '/' . $rel;
	}

	/**
	 * Count a listed finding and keep the first MAX_LISTED paths.
	 *
	 * @param array<string, mixed> $state State (updated).
	 * @param string               $kind  'unreadable', 'too_large' or 'over_volume'.
	 * @param string               $p     Archive path.
	 * @return void
	 */
	private function record( array &$state, string $kind, string $p ): void {
		++$state['counts'][ $kind ];
		if ( count( $state['lists'][ $kind ] ) < self::MAX_LISTED ) {
			$state['lists'][ $kind ][] = $p;
		}
	}

	/**
	 * Count a warning and keep the first MAX_LISTED texts.
	 *
	 * @param array<string, mixed> $state   State (updated).
	 * @param string               $counter Counter to increment (already incremented by some callers: see collisions).
	 * @param string               $text    Warning text (relative paths only).
	 * @return void
	 */
	private function warn( array &$state, string $counter, string $text ): void {
		if ( 'invalid_patterns' === $counter || 'unreadable' === $counter ) {
			++$state['counts'][ $counter ];
		}
		if ( count( $state['warnings'] ) < self::MAX_LISTED ) {
			$state['warnings'][] = $text;
		}
	}
}

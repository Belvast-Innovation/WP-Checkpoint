<?php

namespace WPCheckpoint\Tests\Fixtures\Files;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\FileScanner;
use WPCheckpoint\Files\Links;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\PackStep;

/**
 * Generated site layouts for the content-root rules (ScanRoots, FileScanner, PackStep), and the invariants every
 * layout must keep:
 *
 * - I1: no archive path is listed twice, and no file (by real path) is listed twice;
 * - I2: every file a chosen group holds (its directory, a link at the group's place followed once, never a link
 *   below it; the deepest group directory holding a file owns it) is listed, or its absence is explained by a
 *   finding the pre-flight asks about: a root reported as not scanned that holds it, or a directory left out for
 *   its archive path and listed as unreadable;
 * - I3: nothing of a group not chosen and nothing of the plugin's storage is listed;
 * - I4: the pack step's choice of root (PackStep::judged() and line_root()) reads every listed line from the file
 *   the scan read;
 * - I5: the scan ends, and asks about nothing needlessly: every entry listed as unreadable is a directory left out
 *   for its archive path (the layouts have no unreadable files and no names an archive cannot hold), and a root
 *   refused for sharing an archive path shares it with a root that is scanned.
 *
 * Layouts come from a seed (mt_rand is the same generator on every PHP version since 7.1), so a failing layout
 * is reproduced by its seed. POSIX only: they are made of symbolic links.
 */
final class Layouts {

	const GROUPS = array( 'plugins', 'themes', 'uploads', 'mu-plugins' );

	const KINDS = array(
		'default',
		'default',
		'missing',
		'outside',
		'outside-stale',
		'link-out',
		'link-into',
		'link-equal',
		'link-content',
		'link-site',
		'link-above',
		'link-storage',
		'nested',
		'same',
		'is-content',
		'is-site',
		'in-storage',
		'link-below',
		'refused-at-next',
		'junction-first',
		'junction-broken',
	);

	/**
	 * Names of the links that stand for Windows junctions in the layouts without readlink(): is_link() does not
	 * report a junction there, so the simulated test for links (as Deleter::reparse_state() works then) does not
	 * either. They sort first, where Deleter::first_plain_child() looks.
	 */
	const JUNCTION = '0jn-';

	/**
	 * Sandbox root of this run.
	 *
	 * @var string
	 */
	private $sandbox;

	/**
	 * Constructor: a fresh sandbox under the temporary directory.
	 */
	public function __construct() {
		$this->sandbox = sys_get_temp_dir() . '/wpcheckpoint-layouts-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->sandbox, 0700 );
	}

	/**
	 * Remove the sandbox: links as links, never followed; nothing outside it.
	 *
	 * @return void
	 */
	public function remove(): void {
		self::remove_tree( $this->sandbox, $this->sandbox );
	}

	/**
	 * Build the layout of a seed in a directory of its own.
	 *
	 * @param int $seed Seed.
	 * @return array<string, mixed> abspath, content, storage, dirs (for ScanRoots::resolve_dirs()), kinds, readlink, base.
	 */
	public function build( int $seed ): array {
		mt_srand( $seed );
		$base = $this->sandbox . '/s' . $seed;
		mkdir( $base, 0700 );
		$mode = array( 'inside', 'inside', 'link', 'bedrock' )[ mt_rand( 0, 3 ) ];
		if ( 'bedrock' === $mode ) {
			$abspath = $base . '/wp';
			$content = $base . '/app';
			self::files( $abspath . '/wp-includes' );
			self::files( $content );
			if ( 0 === mt_rand( 0, 1 ) ) {
				self::files( $abspath . '/wp-content/uploads' ); // A stale directory where WordPress used to be.
			}
		} else {
			$abspath = $base . '/site';
			$content = $abspath . '/wp-content';
			self::files( $abspath . '/wp-includes' );
			if ( 'link' === $mode ) {
				self::files( $base . '/real-content' );
				symlink( $base . '/real-content', $content );
			} else {
				self::files( $content );
			}
		}
		$storage = 0 === mt_rand( 0, 1 ) ? $content . '/wpcheckpoint-store' : $base . '/store';
		self::files( $storage . '/backups' );
		self::files( $base . '/outside' );
		symlink( $base . '/outside', $content . '/stray-link' );
		mkdir( $content . '/empty', 0700 );

		$dirs  = array(
			'abspath'       => $abspath,
			'content'       => $content,
			'other-content' => $content,
		);
		$kinds = array();
		foreach ( self::GROUPS as $i => $group ) {
			$kinds[ $group ] = self::KINDS[ mt_rand( 0, count( self::KINDS ) - 1 ) ];
		}
		// A link refused for where it leads, placed at the archive path of the next group, which is kept outside
		// both directories (and so archived under that path too): the two share a path, the refused one first.
		foreach ( self::GROUPS as $i => $group ) {
			if ( 'refused-at-next' === $kinds[ $group ] ) {
				if ( isset( self::GROUPS[ $i + 1 ] ) && 'refused-at-next' !== $kinds[ self::GROUPS[ $i + 1 ] ] ) {
					$kinds[ self::GROUPS[ $i + 1 ] ] = 'outside';
				} else {
					$kinds[ $group ] = 'link-site';
				}
			}
		}
		$real = array(); // Groups whose directory exists, for the kinds that refer to another group.
		foreach ( self::GROUPS as $i => $group ) {
			$kind  = $kinds[ $group ];
			$other = array() === $real ? '' : array_keys( $real )[ mt_rand( 0, count( $real ) - 1 ) ];
			if ( '' === $other && in_array( $kind, array( 'link-into', 'link-equal', 'nested', 'same' ), true ) ) {
				$kind = 'default';
			}
			$place = $content . '/' . $group;
			switch ( $kind ) {
				case 'missing':
					$dir = $place;
					break;
				case 'outside':
				case 'outside-stale':
					$dir = $base . '/ext/' . $group;
					self::files( $dir );
					if ( 'outside-stale' === $kind ) {
						self::files( $place );
					}
					break;
				case 'link-out':
					self::files( $base . '/shared/' . $group );
					$dir = self::link( $base . '/shared/' . $group, $place );
					break;
				case 'link-into':
					self::files( $real[ $other ] . '/into-' . $group );
					$dir = self::link( $real[ $other ] . '/into-' . $group, $place );
					break;
				case 'link-equal':
					$dir = self::link( $real[ $other ], $place );
					break;
				case 'link-content':
					$dir = self::link( $content, $place );
					break;
				case 'link-site':
					$dir = self::link( $abspath, $place );
					break;
				case 'refused-at-next':
					$dir = self::link( $abspath, $content . '/' . self::GROUPS[ $i + 1 ] );
					break;
				case 'link-above':
					$dir = self::link( $base, $place );
					break;
				case 'link-storage':
					$dir = self::link( $storage . '/backups', $place );
					break;
				case 'nested':
					$dir = $real[ $other ] . '/nest-' . $group;
					self::files( $dir );
					break;
				case 'same':
					$dir = $dirs[ $other ];
					break;
				case 'is-content':
					$dir = $content;
					break;
				case 'is-site':
					$dir = $abspath;
					break;
				case 'in-storage':
					$dir = $storage . '/' . $group;
					self::files( $dir );
					break;
				case 'junction-first':
					// A plain directory, and one below it, whose first entry is a working junction: judged a link
					// through it, though they are where they are listed.
					self::files( $place . '/deep' );
					symlink( $base . '/outside', $place . '/' . self::JUNCTION . 'out' );
					symlink( $base . '/outside', $place . '/deep/' . self::JUNCTION . 'out' );
					$dir = $place;
					break;
				case 'junction-broken':
					// A plain directory, and one below it, whose first entry is a broken junction: nothing to probe
					// through, so undecidable, though they hold files. And a junction to such a directory outside
					// the root: undecidable as well, and it resolves elsewhere.
					self::files( $place . '/deep' );
					symlink( $base . '/nowhere', $place . '/' . self::JUNCTION . 'gone' );
					symlink( $base . '/nowhere', $place . '/deep/' . self::JUNCTION . 'gone' );
					self::files( $base . '/undecided-' . $group );
					symlink( $base . '/nowhere', $base . '/undecided-' . $group . '/' . self::JUNCTION . 'gone' );
					symlink( $base . '/undecided-' . $group, $place . '/' . self::JUNCTION . 'away' );
					$dir = $place;
					break;
				case 'link-below':
					self::files( $place );
					symlink( $base . '/outside', $place . '/away' );
					mkdir( $place . '/empty', 0700 );
					// A cycle of directories that hold nothing but links: without readlink() nothing tells them apart
					// from directories, and only the containment check keeps the scan out of the loop.
					mkdir( $base . '/cycle-' . $group, 0700 );
					symlink( $base . '/cycle-' . $group, $place . '/cycle' );
					symlink( $place . '/cycle', $base . '/cycle-' . $group . '/back' );
					$dir = $place;
					break;
				default:
					self::files( $place );
					$dir = $place;
			}
			$dirs[ $group ]  = $dir;
			$kinds[ $group ] = $kind;
			if ( is_dir( $dir ) && false !== realpath( $dir ) ) {
				$real[ $group ] = (string) realpath( $dir );
			}
		}
		clearstatcache( true );
		$readlink = 0 !== mt_rand( 0, 3 );
		mt_srand(); // The generator is global: later tests get random numbers again.
		return array(
			'base'     => $base,
			'abspath'  => $abspath,
			'content'  => $content,
			'storage'  => $storage,
			'dirs'     => $dirs,
			'kinds'    => $kinds,
			'mode'     => $mode,
			'readlink' => $readlink,
		);
	}

	/**
	 * The chosen-group sets a layout is checked with: all, all but the content directory, only it, and random ones.
	 *
	 * @param int $seed Seed.
	 * @return array<int, string[]>
	 */
	public static function chosen_sets( int $seed ): array {
		mt_srand( $seed * 7919 );
		$all  = array_merge( self::GROUPS, array( 'other-content' ) );
		$sets = array( $all, self::GROUPS, array( 'other-content' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$set = array();
			foreach ( $all as $group ) {
				if ( 0 === mt_rand( 0, 1 ) ) {
					$set[] = $group;
				}
			}
			$sets[] = array() === $set ? array( $all[ mt_rand( 0, 4 ) ] ) : $set;
		}
		mt_srand();
		return $sets;
	}

	/**
	 * Resolve, scan and check one chosen set of a layout.
	 *
	 * @param array<string, mixed> $layout From build().
	 * @param string[]             $chosen Chosen groups.
	 * @param callable|null        $tamper function( array $roots, array $layout ): array, applied to the resolved roots
	 *                                     (the checks of the checker itself inject known violations this way).
	 * @param callable|null        $pick   function( array $roots, array $root_ids, string $p ): ?string, the pack step's
	 *                                     source for a line; the real one (PackStep) when null.
	 * @return string[] Violations, each "I<n>: text".
	 */
	public static function check( array $layout, array $chosen, $tamper = null, $pick = null ): array {
		$resolved = ScanRoots::resolve_dirs( $layout['dirs'], $chosen, (string) $layout['storage'] );
		$roots    = null === $tamper ? $resolved['roots'] : $tamper( $resolved['roots'], $layout );
		list( $lines, $state ) = self::scan( $roots, $layout );
		$out = array();

		// I1: once by archive path, once by file.
		$seen = array();
		$real = array();
		foreach ( $lines as $line ) {
			if ( isset( $seen[ $line['p'] ] ) ) {
				$out[] = 'I1: archive path listed twice: ' . $line['p'];
			}
			$seen[ $line['p'] ] = true;
			$file               = (string) realpath( $line['abs'] );
			if ( isset( $real[ $file ] ) ) {
				$out[] = 'I1: file listed twice: ' . $line['p'] . ' and ' . $real[ $file ];
			}
			$real[ $file ] = $line['p'];
		}

		// Group directories by real path, and who owns a file: the deepest group directory holding it (on a tie, a
		// chosen one). The content directory is the other-content group's.
		$groups = array();
		foreach ( array_merge( self::GROUPS, array( 'other-content' ) ) as $group ) {
			$dir = (string) $layout['dirs'][ $group ];
			if ( is_dir( $dir ) && false !== realpath( $dir ) ) {
				$groups[ $group ] = array(
					'real'   => (string) realpath( $dir ),
					'chosen' => in_array( $group, $chosen, true ),
				);
			}
		}
		$storage = (string) realpath( (string) $layout['storage'] );
		$owner   = static function ( string $file ) use ( $groups ): array {
			$best = array(
				'group'  => '',
				'len'    => -1,
				'chosen' => false,
			);
			foreach ( $groups as $group => $g ) {
				if ( 0 === strpos( $file, $g['real'] . '/' ) ) {
					$len = strlen( $g['real'] );
					if ( $len > $best['len'] || ( $len === $best['len'] && $g['chosen'] && ! $best['chosen'] ) ) {
						$best = array(
							'group'  => $group,
							'len'    => $len,
							'chosen' => $g['chosen'],
						);
					}
				}
			}
			return $best;
		};

		// I3: nothing of a group not chosen, nothing of the storage.
		foreach ( $real as $file => $p ) {
			if ( '' !== $storage && 0 === strpos( $file, $storage . '/' ) ) {
				$out[] = 'I3: storage listed: ' . $p;
			} elseif ( ! $owner( $file )['chosen'] ) {
				$out[] = 'I3: file of a group not chosen listed: ' . $p;
			}
		}

		// I2: every file a chosen group holds is listed, or its absence is a finding the pre-flight asks about.
		// A group reported as not scanned explains the absence of the files it owns, and of nothing else: a refused
		// root that leads over other groups (the content directory, the WordPress directory) excuses none of theirs.
		$reported = array();
		foreach ( $roots as $root ) {
			foreach ( (array) $state['warnings'] as $warning ) {
				if ( false !== strpos( $warning, 'not scanned' ) && false !== strpos( $warning, '"' . $root['group'] . '"' ) && false !== strpos( $warning, '(' . $root['prefix'] . ').' ) ) {
					$reported[ $root['group'] ] = true;
				}
			}
		}
		// Groups with the same real directory own the same files: reported for one, reported for all.
		foreach ( $groups as $group => $g ) {
			foreach ( $groups as $other => $o ) {
				if ( isset( $reported[ $other ] ) && $o['real'] === $g['real'] ) {
					$reported[ $group ] = true;
				}
			}
		}
		$collided = array();
		foreach ( $roots as $root ) {
			foreach ( isset( $root['collide'] ) ? (array) $root['collide'] : array() as $dir ) {
				$p = $root['prefix'] . '/' . substr( (string) $dir, strlen( (string) $root['path'] ) + 1 );
				if ( in_array( $p, (array) $state['lists']['unreadable'], true ) && false !== realpath( (string) $dir ) ) {
					$collided[] = (string) realpath( (string) $dir );
				}
			}
		}
		foreach ( $chosen as $group ) {
			$dir = 'other-content' === $group ? (string) $layout['content'] : (string) $layout['dirs'][ $group ];
			foreach ( self::reachable( $dir ) as $file ) {
				if ( isset( $real[ $file ] ) || ( '' !== $storage && 0 === strpos( $file, $storage . '/' ) ) || ! $owner( $file )['chosen'] ) {
					continue;
				}
				$explained = isset( $reported[ $owner( $file )['group'] ] );
				foreach ( $collided as $held ) {
					if ( '' !== $held && 0 === strpos( $file, $held . '/' ) ) {
						$explained = true;
					}
				}
				if ( ! $explained ) {
					$out[] = 'I2: not listed and nothing asked: ' . substr( $file, strlen( (string) $layout['base'] ) ) . ' (group ' . $group . ')';
				}
			}
		}

		// I5: the scan ended, and every unreadable entry is a directory left out for its archive path.
		if ( empty( $state['done'] ) ) {
			$out[] = 'I5: the scan did not end';
		}
		$collide_paths = array();
		foreach ( $roots as $root ) {
			foreach ( isset( $root['collide'] ) ? (array) $root['collide'] : array() as $dir ) {
				$collide_paths[] = $root['prefix'] . '/' . substr( (string) $dir, strlen( (string) $root['path'] ) + 1 );
			}
		}
		foreach ( (array) $state['lists']['unreadable'] as $p ) {
			if ( ! in_array( $p, $collide_paths, true ) ) {
				$out[] = 'I5: asked about needlessly: ' . $p;
			}
		}
		// Each directory at most once: never more directories entered than the chosen groups really hold.
		$dirs = array();
		foreach ( $chosen as $group ) {
			foreach ( self::reachable( 'other-content' === $group ? (string) $layout['content'] : (string) $layout['dirs'][ $group ], true ) as $dir ) {
				$dirs[ $dir ] = true;
			}
		}
		if ( $state['counts']['directories'] > count( $dirs ) ) {
			$out[] = 'I5: more directories entered (' . $state['counts']['directories'] . ') than there are (' . count( $dirs ) . ')';
		}
		if ( $state['counts']['bad_names'] > 0 ) {
			$out[] = 'I5: names an archive cannot hold (a path grown by a loop?): ' . $state['counts']['bad_names'];
		}
		foreach ( $roots as $root ) {
			if ( '' === (string) ( $root['refuse'] ?? '' ) ) {
				continue;
			}
			$shared = false;
			foreach ( $roots as $other ) {
				$scanned = true;
				foreach ( (array) $state['warnings'] as $warning ) {
					if ( false !== strpos( $warning, 'not scanned' ) && false !== strpos( $warning, '"' . $other['group'] . '"' ) ) {
						$scanned = false;
					}
				}
				if ( $other['group'] !== $root['group'] && $other['prefix'] === $root['prefix'] && $scanned ) {
					$shared = true;
				}
			}
			if ( ! $shared ) {
				$out[] = 'I5: refused for a path no scanned root has: ' . $root['group'] . ' (' . $root['prefix'] . ')';
			}
		}

		// I4: the pack step reads every listed line from the file the scan read.
		$pick = null === $pick ? array( self::class, 'pack_source' ) : $pick;
		$ids  = isset( $state['root_ids'] ) ? (array) $state['root_ids'] : array();
		foreach ( $lines as $line ) {
			$source = $pick( $roots, $ids, $line['p'], (string) $layout['abspath'] );
			if ( null === $source || realpath( $source ) !== realpath( $line['abs'] ) ) {
				$out[] = 'I4: packed from another file: ' . $line['p'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Where the pack step reads a line from: its own judgement of the roots and its choice of root.
	 *
	 * @param array<int, array<string, mixed>> $roots   Roots.
	 * @param array<string, string>            $ids     The scan's root_ids.
	 * @param string                           $p       Archive path.
	 * @param string                           $abspath The WordPress directory.
	 * @return string|null
	 */
	public static function pack_source( array $roots, array $ids, string $p, string $abspath ) {
		static $r = null;
		if ( null === $r ) {
			$r = array(
				'judged'    => new \ReflectionMethod( PackStep::class, 'judged' ),
				'line_root' => new \ReflectionMethod( PackStep::class, 'line_root' ),
				'source_of' => new \ReflectionMethod( PackStep::class, 'source_of' ),
				'scanned'   => new \ReflectionProperty( PackStep::class, 'scanned_prefixes' ),
			);
			foreach ( $r as $member ) {
				$member->setAccessible( true );
			}
		}
		$step = ( new \ReflectionClass( PackStep::class ) )->newInstanceWithoutConstructor();
		$r['scanned']->setValue( $step, array_map( 'strval', array_keys( $ids ) ) );
		$picked = $r['line_root']->invoke( $step, $r['judged']->invoke( null, $roots, $ids, $abspath ), $p );
		if ( $picked['gone'] || null === $picked['root'] || ! empty( $picked['root']['refused'] ) ) {
			return null;
		}
		return $r['source_of']->invoke( null, array( $picked['root'] ), $p );
	}

	/**
	 * Scan the roots to the end; the lines with the file each was read from.
	 *
	 * @param array<int, array<string, mixed>> $roots  Roots.
	 * @param array<string, mixed>             $layout Layout.
	 * @return array{0: array<int, array{p: string, abs: string}>, 1: array<string, mixed>}
	 */
	private static function scan( array $roots, array $layout ): array {
		$last  = '';
		$state = static function ( string $path ) use ( &$last, $layout ): string {
			$last = $path;
			if ( $layout['readlink'] ) {
				return Links::state( $path );
			}
			return self::without_readlink( $path );
		};
		$scanner = new FileScanner(
			$roots,
			new Exclusions( array(), array() ),
			PHP_INT_SIZE,
			Manifest::DEFAULT_CHUNK,
			array(
				'abspath'    => (string) $layout['abspath'],
				'link_state' => $state,
			)
		);
		$lines = array();
		$scan  = FileScanner::initial_state();
		for ( $units = 0; empty( $scan['done'] ) && $units < 10000; $units++ ) {
			$scan = $scanner->scan_unit(
				$scan,
				static function ( array $line ) use ( &$lines, &$last ): void {
					$lines[] = array(
						'p'   => (string) $line['p'],
						'abs' => $last,
					);
				}
			);
		}
		return array( $lines, $scan );
	}

	/**
	 * The test for links as Deleter::reparse_state() answers on Windows without readlink(): is_link() (which does
	 * not report a junction), else a directory is told apart only through its first entry that is_link() does not
	 * report: resolved through the directory, where it should be (plain) or elsewhere (a link); no such entry, or
	 * one that does not resolve: unknown.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function without_readlink( string $path ): string {
		$is_link = static function ( string $p ): bool {
			return is_link( $p ) && 0 !== strpos( basename( $p ), self::JUNCTION );
		};
		if ( $is_link( $path ) ) {
			return Links::LINK;
		}
		if ( ! is_dir( $path ) ) {
			return Links::PLAIN;
		}
		$parent = realpath( dirname( $path ) );
		if ( false === $parent ) {
			return Links::UNKNOWN;
		}
		$child = '';
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name && ! $is_link( $path . '/' . $name ) ) {
				$child = (string) $name;
				break;
			}
		}
		if ( '' === $child ) {
			return Links::UNKNOWN;
		}
		$resolved = realpath( $path . '/' . $child );
		if ( false === $resolved ) {
			return Links::UNKNOWN;
		}
		return $parent . '/' . basename( $path ) . '/' . $child === $resolved ? Links::PLAIN : Links::LINK;
	}

	/**
	 * Every regular file under a group directory, by real path: the directory itself followed if it is a link,
	 * nothing below it that is a link.
	 *
	 * @param string $dir  Group directory.
	 * @param bool   $dirs The directories instead of the files (the group directory included).
	 * @return string[]
	 */
	private static function reachable( string $dir, bool $dirs = false ): array {
		if ( ! is_dir( $dir ) || false === realpath( $dir ) ) {
			return array();
		}
		$out   = array();
		$stack = array( (string) realpath( $dir ) );
		while ( array() !== $stack ) {
			$current = array_pop( $stack );
			if ( $dirs ) {
				$out[] = $current;
			}
			foreach ( (array) scandir( $current ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $current . '/' . $name;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$stack[] = $path;
				} elseif ( ! $dirs && is_file( $path ) ) {
					$out[] = $path;
				}
			}
		}
		return $out;
	}

	/**
	 * A directory with two files and a subdirectory with one.
	 *
	 * @param string $dir Directory (created with its parents).
	 * @return void
	 */
	private static function files( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		file_put_contents( $dir . '/a.txt', $dir );
		file_put_contents( $dir . '/b.txt', 'b' );
		if ( ! is_dir( $dir . '/sub' ) ) {
			mkdir( $dir . '/sub', 0700 );
		}
		file_put_contents( $dir . '/sub/c.txt', 'c' );
	}

	/**
	 * A symbolic link, its parent created; returns the link.
	 *
	 * @param string $target Target.
	 * @param string $link   Link.
	 * @return string
	 */
	private static function link( string $target, string $link ): string {
		if ( ! is_dir( dirname( $link ) ) ) {
			mkdir( dirname( $link ), 0700, true );
		}
		if ( ! file_exists( $link ) && ! is_link( $link ) ) {
			symlink( $target, $link );
		}
		return $link;
	}

	/**
	 * Remove a tree without following links, refusing anything outside the sandbox.
	 *
	 * @param string $dir     Directory.
	 * @param string $sandbox Sandbox root.
	 * @return void
	 */
	public static function remove_tree( string $dir, string $sandbox ): void {
		if ( '' === $sandbox || 0 !== strpos( $sandbox, sys_get_temp_dir() . '/wpcheckpoint-layouts-' ) || ( $dir !== $sandbox && 0 !== strpos( $dir, $sandbox . '/' ) ) ) {
			throw new \LogicException( 'Not in the layout sandbox: ' . $dir );
		}
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				unlink( $path );
			} else {
				self::remove_tree( $path, $sandbox );
			}
		}
		rmdir( $dir );
	}
}

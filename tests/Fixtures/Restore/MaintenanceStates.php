<?php
/**
 * Every state of the two files a restore holds a site with, built in a sandbox, with what is true of each state by
 * construction and what the fixed codes would answer.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\Restore;

use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;

// phpcs:disable -- a test fixture.

/**
 * The matrix of MaintenanceMatrixTest (the file-level users) and MaintenanceMatrixDbTest (the reaper, the uninstall, the
 * deactivation): .maintenance (M) times the data file (D), each in every state a user of them must tell apart: not
 * there, this plugin's, someone else's, and the ones that cannot be told (a file that cannot be read now, a link the
 * including code reads through). What is true of a state is known here by construction (truth()), never asked of the
 * code under test; what the fixed codes would answer once whatever keeps a file from being read is over is read the
 * way they read (answers(): file_get_contents() through links, with the patterns taken from the codes themselves).
 *
 * The invariants (ARCHITECTURE, "three states"):
 * a. every judgment of either file is one of three (it is, it positively is not, it cannot be told), and what cannot
 *    be told goes the way of nothing deleted, held, the loader needed, waiting;
 * b. nothing is deleted that a held answer could follow from once the file can be read again, while a job may stand
 *    behind it;
 * c. once a release returns, nothing answers as held for this restore; where that cannot be reached it waits;
 * d. the held times are one set, the loader's and the plugin's.
 */
final class MaintenanceStates {

	const M = array( 'none', 'code', 'code_unreadable', 'other', 'other_unreadable', 'link_code', 'dir', 'long', 'legacy' );

	const D = array( 'none', 'held64', 'held32', 'lapsed', 'foreign_held', 'form', 'unreadable_held', 'link_held', 'long', 'dir' );

	const WEB = array( Maintenance::RUNTIME, Maintenance::VISITOR, Maintenance::LOG );

	const RUNTIME_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * Whether files can be made unreadable here (not as root, not on Windows): without it the "cannot be read" states
	 * are not what they say, and a test must skip.
	 *
	 * @return bool
	 */
	public static function can_hide(): bool {
		return '\\' !== DIRECTORY_SEPARATOR && function_exists( 'posix_geteuid' ) && 0 !== posix_geteuid();
	}

	/**
	 * Every state: array( M, D ).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::M as $m ) {
			foreach ( self::D as $d ) {
				$out[] = array( $m, $d );
			}
		}
		return $out;
	}

	/**
	 * A data file's contents.
	 *
	 * @param string $mark Mark.
	 * @param string $time Its time, as written.
	 * @return string
	 */
	public static function data( string $mark, string $time ): string {
		return "<?php exit; ?>\n" . $mark . "\n" . $time . "\n-\n" . self::RUNTIME_HASH . "\n";
	}

	/**
	 * Build a state under a root (made empty by the caller): site/ (the WordPress directory: the two files and the
	 * files of a run without WordPress, naming the mark), mu/ (the loader), aside/ (what links point to). Every file
	 * is as old as $age seconds.
	 *
	 * @param string $root Root directory.
	 * @param string $m    One of M.
	 * @param string $d    One of D.
	 * @param string $mark This restore's mark.
	 * @param int    $age  Seconds.
	 * @return array{site: string, mu: string}
	 */
	public static function build( string $root, string $m, string $d, string $mark, int $age ): array {
		$site  = $root . '/site';
		$mu    = $root . '/mu';
		$aside = $root . '/aside';
		foreach ( array( $site, $mu, $aside ) as $dir ) {
			if ( ! mkdir( $dir, 0700, true ) ) {
				throw new \RuntimeException( 'could not make ' . $dir );
			}
		}
		$then = time() - $age;
		$put  = static function ( string $path, string $contents ) use ( $then ): void {
			if ( strlen( $contents ) !== file_put_contents( $path, $contents ) || ! touch( $path, $then ) ) {
				throw new \RuntimeException( 'could not write ' . $path );
			}
		};
		foreach ( self::WEB as $name ) {
			$put( $site . '/' . $name, "<?php exit; ?>\n# " . $mark . "\n" );
		}
		$put( Loader::path( $mu ), Loader::CODE );
		$other  = '<?php $upgrading = time();';
		$long   = '<?php // ' . str_repeat( 'x', Maintenance::MAX_READ + 10 );
		$file   = $site . '/' . Maintenance::FILE;
		$data   = $site . '/' . Maintenance::DATA;
		$own    = self::data( $mark, Maintenance::HELD_VALUES[0] );
		$hidden = array();
		switch ( $m ) {
			case 'none':
				break;
			case 'code':
			case 'code_unreadable':
				$put( $file, Maintenance::CODE );
				break;
			case 'other':
			case 'other_unreadable':
				$put( $file, $other );
				break;
			case 'link_code':
				$put( $aside . '/code', Maintenance::CODE );
				if ( ! symlink( $aside . '/code', $file ) ) {
					throw new \RuntimeException( 'no link' );
				}
				break;
			case 'dir':
				mkdir( $file );
				break;
			case 'long':
				$put( $file, $long );
				break;
			case 'legacy':
				$put( $file, "<?php\n\$upgrading = " . Maintenance::HELD_VALUES[0] . '; // ' . $mark . "\n" );
				break;
			default:
				throw new \InvalidArgumentException( $m );
		}
		if ( in_array( $m, array( 'code_unreadable', 'other_unreadable' ), true ) ) {
			$hidden[] = $file;
		}
		switch ( $d ) {
			case 'none':
				break;
			case 'held64':
			case 'unreadable_held':
				$put( $data, $own );
				break;
			case 'held32':
				$put( $data, self::data( $mark, Maintenance::HELD_VALUES[1] ) );
				break;
			case 'lapsed':
				$put( $data, self::data( $mark, (string) ( time() - 3600 ) ) );
				break;
			case 'foreign_held':
				$put( $data, self::data( 'WP Checkpoint restore ' . str_repeat( 'f', 32 ), Maintenance::HELD_VALUES[0] ) );
				break;
			case 'form':
				$put( $data, "<?php exit; ?>\nnot a data file\n" );
				break;
			case 'link_held':
				$put( $aside . '/data', $own );
				if ( ! symlink( $aside . '/data', $data ) ) {
					throw new \RuntimeException( 'no link' );
				}
				break;
			case 'long':
				$put( $data, $long );
				break;
			case 'dir':
				mkdir( $data );
				break;
			default:
				throw new \InvalidArgumentException( $d );
		}
		if ( 'unreadable_held' === $d ) {
			$hidden[] = $data;
		}
		foreach ( $hidden as $path ) {
			if ( ! chmod( $path, 0 ) || false !== @file_get_contents( $path ) ) {
				throw new \RuntimeException( 'could not make a file unreadable: ' . $path );
			}
		}
		clearstatcache();
		return array(
			'site' => $site,
			'mu'   => $mu,
		);
	}

	/**
	 * End whatever keeps a file of a state from being read (the files made unreadable can be read again).
	 *
	 * @param string $site The WordPress directory of a state.
	 * @return void
	 */
	public static function reveal( string $site ): void {
		foreach ( array( Maintenance::FILE, Maintenance::DATA ) as $name ) {
			$path = $site . '/' . $name;
			clearstatcache( true, $path );
			if ( is_file( $path ) && ! is_link( $path ) ) {
				chmod( $path, 0600 );
			}
		}
		clearstatcache();
	}

	/**
	 * What is true of a state, by construction.
	 *
	 * - m_code: the code WordPress would run as .maintenance is this plugin's (whether or not it can be read now).
	 * - m_untellable: whether it is cannot be told now (it cannot be read, or it is a link).
	 * - m_not_code: it is positively not this plugin's code (not there, or something else that can be told).
	 * - m_ours_regular: a regular file that can be read and is this restore's (the code, or the single file).
	 * - d_untellable: what the data file says cannot be told now.
	 * - d_names: it positively names a restore (a regular file of the form, read).
	 * - d_not: it positively names none.
	 * - d_own: the restore it names (or would, once read) is this one.
	 * - d_held: its time (as it would be read) is a held one.
	 * - d_regular: a regular file is under its name.
	 *
	 * @param string $m One of M.
	 * @param string $d One of D.
	 * @return array<string, bool>
	 */
	public static function truth( string $m, string $d ): array {
		$m_untellable = in_array( $m, array( 'code_unreadable', 'other_unreadable', 'link_code' ), true );
		$d_untellable = in_array( $d, array( 'unreadable_held', 'link_held' ), true );
		$d_names      = in_array( $d, array( 'held64', 'held32', 'lapsed', 'foreign_held' ), true );
		return array(
			'm_code'         => in_array( $m, array( 'code', 'code_unreadable', 'link_code' ), true ),
			'm_untellable'   => $m_untellable,
			'm_not_code'     => in_array( $m, array( 'none', 'other', 'dir', 'long', 'legacy' ), true ),
			'm_none'         => 'none' === $m,
			'm_ours_regular' => in_array( $m, array( 'code', 'legacy' ), true ),
			'd_untellable'   => $d_untellable,
			'd_names'        => $d_names,
			'd_not'          => ! $d_names && ! $d_untellable,
			'd_own'          => in_array( $d, array( 'held64', 'held32', 'lapsed', 'unreadable_held', 'link_held' ), true ),
			'd_held'         => in_array( $d, array( 'held64', 'held32', 'foreign_held', 'unreadable_held', 'link_held' ), true ),
			'd_regular'      => ! in_array( $d, array( 'none', 'link_held', 'dir' ), true ),
		);
	}

	/**
	 * The pattern a fixed code reads the data file with, taken from the code itself.
	 *
	 * @param string $code Maintenance::CODE or Loader::CODE.
	 * @return string
	 */
	public static function pattern_of( string $code ): string {
		if ( 1 !== preg_match( '~preg_match\( \'(/\\\\A<\\\\\?php exit; [^\n]*?\\\\z/)\', \$(?:wpcheckpoint_)?data,~', $code, $found ) ) {
			throw new \RuntimeException( 'the code\'s pattern for the data file was not found' );
		}
		return $found[1];
	}

	/**
	 * What the fixed codes would answer in a directory as it is now, read the way they read (through links; a file
	 * that cannot be read is no file to them): call reveal() first for what they would answer once it can be read.
	 *
	 * - code: .maintenance is a code of this plugin's and answers with the maintenance page for good (a held data
	 *   file, or none it can read: "bare"); code_mark: the mark of the held data file it reads ('' for bare).
	 * - legacy: the single file with a held time; legacy_mark its mark.
	 * - loader: the loader would answer for a deleted or lapsed .maintenance (its code under its name, the runtime
	 *   next to the data file, a held data file naming a runtime); loader_mark: that data file's mark.
	 *
	 * @param string $site The WordPress directory.
	 * @param string $mu   The must-use plugins directory.
	 * @return array{code: bool, code_mark: string, legacy: bool, legacy_mark: string, loader: bool, loader_mark: string}
	 */
	public static function answers( string $site, string $mu ): array {
		clearstatcache();
		$out  = array(
			'code'        => false,
			'code_mark'   => '',
			'legacy'      => false,
			'legacy_mark' => '',
			'loader'      => false,
			'loader_mark' => '',
		);
		$file = @file_get_contents( $site . '/' . Maintenance::FILE );
		$data = @file_get_contents( $site . '/' . Maintenance::DATA );
		if ( is_string( $file ) && in_array( $file, Maintenance::KNOWN_CODES, true ) ) {
			if ( is_string( $data ) && 1 === preg_match( self::pattern_of( Maintenance::CODE ), $data, $found ) ) {
				// Held when the time is not within WordPress's ten minutes of now, for good when it is a held one.
				if ( in_array( $found[2], Maintenance::HELD_VALUES, true ) ) {
					$out['code']      = true;
					$out['code_mark'] = $found[1];
				}
			} else {
				$out['code'] = true; // Bare: every request blocked, no restore named.
			}
		} elseif ( is_string( $file ) && 1 === preg_match( Maintenance::LEGACY_FORM, $file, $legacy ) && in_array( $legacy[1], Maintenance::HELD_VALUES, true ) ) {
			$out['legacy']      = true;
			$out['legacy_mark'] = $legacy[2];
		}
		$loader = @file_get_contents( Loader::path( $mu ) );
		if ( is_string( $loader ) && in_array( $loader, Loader::KNOWN, true ) && @file_exists( $site . '/' . Maintenance::RUNTIME ) && is_string( $data ) && 1 === preg_match( self::pattern_of( Loader::CODE ), $data, $found ) ) {
			$out['loader']      = true;
			$out['loader_mark'] = $found[1];
		}
		return $out;
	}

	/**
	 * What is there of a state's files: name => whether it is there (lstat), for .maintenance, the data file, the
	 * files of a run without WordPress and the loader ("loader").
	 *
	 * @param string $site The WordPress directory.
	 * @param string $mu   The must-use plugins directory.
	 * @return array<string, bool>
	 */
	public static function there( string $site, string $mu ): array {
		clearstatcache();
		$out = array();
		foreach ( array_merge( array( Maintenance::FILE, Maintenance::DATA ), self::WEB ) as $name ) {
			$out[ $name ] = false !== @lstat( $site . '/' . $name );
		}
		$out['loader'] = false !== @lstat( Loader::path( $mu ) );
		return $out;
	}

	/**
	 * A digest of .maintenance as it is (its kind and, of a regular file or a link, its contents or target), to tell
	 * that it was not changed.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function digest( string $path ): string {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( false === $stat ) {
			return 'none';
		}
		if ( is_link( $path ) ) {
			return 'link:' . readlink( $path );
		}
		if ( is_dir( $path ) ) {
			return 'dir';
		}
		$mode = $stat['mode'] & 0777;
		if ( 0 === $mode ) {
			return 'hidden:' . $stat['size'];
		}
		return 'file:' . hash( 'sha256', (string) file_get_contents( $path ) );
	}
}

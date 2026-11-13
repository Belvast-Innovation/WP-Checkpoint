<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Support\Directories;

/**
 * What the integration tests leave in the test site's wp-content directory, beside what other checks look at: its
 * top-level entries (Leftovers "content:") but the ones registered here by name, each with why (WordPress's own, the
 * directories that are checked on their own, the probe directory a run keeps through it), and the top-level entries
 * of its must-use plugins directory (Leftovers "mu-plugins:"; a restore's preflight places its loader probe there,
 * Restore\LoaderProbe, and removes it). Before a run (leftovers-precheck.php) a loader probe still in the must-use
 * plugins directory stops it: a run stopped part way left it, and with it there the test site loads it as a must-use
 * plugin. The registered names are checked to be there (an integration test): a registration that names nothing
 * there is out of date, but for those a test site may not have yet (MAY_BE_ABSENT, each with why).
 */
final class ContentLeftovers {

	/**
	 * The test site's wp-content entries that are not looked at, by exact name, with why.
	 */
	const OWN = array(
		'index.php'  => 'WordPress\'s',
		'plugins'    => 'WordPress\'s (this plugin among them)',
		'themes'     => 'WordPress\'s',
		'upgrade'    => 'WordPress\'s',
		'uploads'    => 'checked on its own (UploadsLeftovers)',
		'mu-plugins' => 'checked on its own (mu_entries())',
	);

	/**
	 * Of OWN, the entries a test site may not have, with why (the check that every registered name is there leaves them
	 * out: whether they are out of date cannot be told from a site that never made them).
	 */
	const MAY_BE_ABSENT = array(
		'upgrade'    => 'WordPress makes it the first time it downloads a package: a new test site (CI\'s) has none',
		'mu-plugins' => 'made by the first test that needs it (a restore\'s preflight, the loader\'s tests): a new test site (CI\'s) has none, and in a random order this check may come before any of them',
	);

	/**
	 * The test site's wp-content entries that are not looked at, by the start of their name, with why.
	 */
	const OWN_PREFIXES = array(
		Directories::DIR_PREFIX => 'storage directories, checked on their own (StorageDirs)',
		ProbeDir::PREFIX        => 'the probe directory a run keeps through it, removed as the next one starts (bin/test-integration.sh)',
	);

	/**
	 * The test site's wp-content directory.
	 *
	 * @return string
	 */
	public static function dir(): string {
		$content = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : rtrim( (string) ABSPATH, '/\\' ) . '/wp-content';
		return rtrim( str_replace( '\\', '/', $content ), '/' );
	}

	/**
	 * The test site's must-use plugins directory.
	 *
	 * @return string
	 */
	public static function mu_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' ) ? rtrim( str_replace( '\\', '/', (string) WPMU_PLUGIN_DIR ), '/' ) : self::dir() . '/mu-plugins';
	}

	/**
	 * Whether a wp-content entry's name is registered (OWN, OWN_PREFIXES).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_own( string $name ): bool {
		if ( isset( self::OWN[ $name ] ) ) {
			return true;
		}
		foreach ( array_keys( self::OWN_PREFIXES ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A directory's top-level entries (paths with forward slashes, sorted), or those $keep says yes to.
	 *
	 * @param string        $dir  Directory.
	 * @param callable|null $keep function( string $name ): bool.
	 * @return string[]
	 */
	private static function top( string $dir, $keep = null ): array {
		$out  = array();
		$base = rtrim( str_replace( '\\', '/', $dir ), '/' );
		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $entry ) {
			$entry = (string) $entry;
			if ( '.' === $entry || '..' === $entry || ( null !== $keep && ! call_user_func( $keep, $entry ) ) ) {
				continue;
			}
			$out[] = $base . '/' . $entry;
		}
		sort( $out );
		return $out;
	}

	/**
	 * The wp-content entries looked at: every top-level one but the registered.
	 *
	 * @param string $dir The wp-content directory.
	 * @return string[]
	 */
	public static function entries( string $dir ): array {
		return self::top(
			$dir,
			static function ( string $name ): bool {
				return ! self::is_own( $name );
			}
		);
	}

	/**
	 * The must-use plugins directory's top-level entries.
	 *
	 * @param string $dir The must-use plugins directory.
	 * @return string[]
	 */
	public static function mu_entries( string $dir ): array {
		return self::top( $dir );
	}

	/**
	 * The loader probes there (named as StagingLayout::probe_name() names them, read by StagingLayout::parse()).
	 *
	 * @param string $dir The must-use plugins directory.
	 * @return string[]
	 */
	public static function probes( string $dir ): array {
		return self::top( $dir, array( self::class, 'is_probe' ) );
	}

	/**
	 * Whether a name is a restore's probe's.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_probe( string $name ): bool {
		$parsed = StagingLayout::parse( $name );
		return null !== $parsed && 'probe' === $parsed['kind'];
	}

	/**
	 * Why a run does not start ('' when it may): the loader probes a run left in the must-use plugins directory, and
	 * any file there under the name of a restore's loader or a temporary name of it, whatever it holds (RealMuPlugins).
	 *
	 * @param string $dir The must-use plugins directory.
	 * @return string
	 */
	public static function refusal( string $dir ): string {
		$probes  = self::probes( $dir );
		$loaders = RealMuPlugins::listing( $dir );
		$out     = '';
		if ( array() !== $probes ) {
			$out .= "The test site's must-use plugins directory holds a restore's loader probe an earlier run left; the run does not start. Remove them first:\n  " . implode( "\n  ", $probes ) . "\n";
		}
		if ( array() !== $loaders ) {
			$lines = array_map(
				static function ( string $path ): string {
					return $path . ( RealMuPlugins::ours( $path ) ? ' (this plugin\'s)' : ' (not this plugin\'s)' );
				},
				$loaders
			);
			$out  .= "The test site's must-use plugins directory holds a file under the name of a restore's must-use plugin; the run does not start, and nothing was removed. Remove them first (quote the name in an interactive shell: it starts with !):\n  " . implode( "\n  ", $lines ) . "\n";
		}
		return $out;
	}

	/**
	 * Remove what a test left: in wp-content, an entry under one of the tests' temporary prefixes (Leftovers::TEMP_PREFIXES,
	 * "wpc-"); in the must-use plugins directory, a loader probe, and a restore's loader by its contents
	 * (RealMuPlugins::clear()); anything else is said and left alone (Deleter, registered for that deletion only).
	 *
	 * @param string $path The entry.
	 * @param bool   $mu   Whether it is the must-use plugins directory's.
	 * @return void
	 */
	public static function remove( string $path, bool $mu ): void {
		$dir  = $mu ? self::mu_dir() : self::dir();
		$name = basename( $path );
		if ( ! $mu && RealAdvancedCache::is_name( $name ) ) {
			RealAdvancedCache::clear( $path ); // The tests' own page cache drop-in, by its contents; anything else is said.
			return;
		}
		if ( $mu && RealMuPlugins::is_loader( $name ) ) {
			RealMuPlugins::clear( $path ); // A restore's loader, by its contents; anything else under its name is said.
			return;
		}
		$ours = $mu ? self::is_probe( $name ) : ( 0 === strpos( $name, 'wpc-' ) || 0 === strpos( $name, 'wpcheckpoint-' ) );
		if ( dirname( $path ) !== $dir || ! $ours ) {
			fwrite( STDERR, "\nNot an entry the tests make there, left alone: {$path}\n" );
			return;
		}
		$roots = Deleter::replace_roots( array() );
		Deleter::replace_roots( $roots );
		try {
			Deleter::allow( $path );
			Deleter::delete_tree( $dir, $path );
		} catch ( DeletionRefused $e ) {
			fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
		} finally {
			Deleter::replace_roots( $roots );
		}
	}
}

<?php
/**
 * The one place that calls PHP functions a host may take away.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

// No ABSPATH guard: pure PHP, loaded by the archive classes, which run outside WordPress too.

/**
 * Shared hosts list functions in disable_functions, and extensions such as
 * zlib or posix may be missing. On PHP 8 a disabled function is not defined:
 * calling it throws an Error that "@" does not silence, and a string callback
 * naming it fails as a TypeError. Every such function is called here and only
 * here, behind available(), and answers "unknown" (false, '' or null) when it
 * is not there; callers treat unknown the documented way (free space unknown
 * does not block, and so on). A PHPStan rule
 * (tests/PHPStan/HostFunctionsRule.php) rejects a direct call, or a string
 * callback, anywhere else in src/.
 *
 * Not governed: ini_get() (WordPress itself cannot run without it) and the
 * filesystem and hashing functions every backup needs (fopen, realpath,
 * hash_file, ...), whose absence is not something a host does.
 */
final class HostFunctions {

	/**
	 * Functions governed by name.
	 */
	const GOVERNED = array(
		'disk_free_space',
		'disk_total_space',
		'set_time_limit',
		'ignore_user_abort',
		'ini_set',
		'readlink',
		'symlink',
		'link',
		'apache_setenv',
		'stream_isatty',
		'gzdeflate',
		'gzinflate',
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'popen',
		'putenv',
	);

	/**
	 * Disabled functions the environment page reports as relevant.
	 */
	const REPORTED = array( 'set_time_limit', 'ini_set', 'disk_free_space', 'disk_total_space', 'proc_open', 'exec', 'shell_exec', 'popen', 'symlink', 'readlink' );

	/**
	 * Functions governed by prefix.
	 */
	const GOVERNED_PREFIXES = array( 'proc_', 'pcntl_', 'posix_' );

	/**
	 * Whether a name is governed.
	 *
	 * @param string $name Function name (any case, with or without a leading backslash).
	 * @return bool
	 */
	public static function governs( string $name ): bool {
		$name = strtolower( ltrim( $name, '\\' ) );
		if ( in_array( $name, self::GOVERNED, true ) ) {
			return true;
		}
		foreach ( self::GOVERNED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a function exists and is not listed in disable_functions (PHP
	 * 7.4 keeps disabled functions defined; PHP 8 removes them).
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public static function available( string $name ): bool {
		if ( ! function_exists( $name ) ) {
			return false;
		}
		$disabled = array_map( 'strtolower', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
		return ! in_array( strtolower( $name ), $disabled, true );
	}

	/**
	 * Free bytes on the filesystem of a directory; false when unknown.
	 *
	 * @param string $dir Directory.
	 * @return float|false
	 */
	public static function disk_free_space( string $dir ) {
		if ( ! self::available( 'disk_free_space' ) ) {
			return false;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fails on some mounts; unknown is handled by the caller.
		return is_float( $free ) ? $free : false;
	}

	/**
	 * Total bytes of the filesystem of a directory; false when unknown.
	 *
	 * @param string $dir Directory.
	 * @return float|false
	 */
	public static function disk_total_space( string $dir ) {
		if ( ! self::available( 'disk_total_space' ) ) {
			return false;
		}
		$total = @disk_total_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see disk_free_space().
		return is_float( $total ) ? $total : false;
	}

	/**
	 * Calls set_time_limit(); false when it is not available or refused.
	 *
	 * @param int $seconds Seconds (0: no limit).
	 * @return bool
	 */
	public static function set_time_limit( int $seconds ): bool {
		if ( ! self::available( 'set_time_limit' ) ) {
			return false;
		}
		return (bool) @set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- refused on some hosts.
	}

	/**
	 * Keep running when the client goes away; false when not available.
	 *
	 * @return bool
	 */
	public static function ignore_user_abort(): bool {
		if ( ! self::available( 'ignore_user_abort' ) ) {
			return false;
		}
		ignore_user_abort( true );
		return true;
	}

	/**
	 * Calls ini_set(); false when it is not available or refused.
	 *
	 * @param string $option Option.
	 * @param string $value  Value.
	 * @return bool
	 */
	public static function ini_set( string $option, string $value ): bool {
		if ( ! self::available( 'ini_set' ) ) {
			return false;
		}
		return false !== @ini_set( $option, $value ); // phpcs:ignore WordPress.PHP.IniSet.Risky,WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- only runtime-safe options are passed.
	}

	/**
	 * Calls readlink(); false when it is not available or cannot resolve.
	 *
	 * @param string $path Path.
	 * @return string|false
	 */
	public static function readlink( string $path ) {
		if ( ! self::available( 'readlink' ) ) {
			return false;
		}
		$target = @readlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- warns when it cannot resolve; handled by the caller.
		return is_string( $target ) ? $target : false;
	}

	/**
	 * Calls apache_setenv(); false when not running under Apache or not available.
	 *
	 * @param string $name  Variable.
	 * @param string $value Value.
	 * @return bool
	 */
	public static function apache_setenv( string $name, string $value ): bool {
		if ( ! self::available( 'apache_setenv' ) ) {
			return false;
		}
		return (bool) @apache_setenv( $name, $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv,WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
	}

	/**
	 * Home directory of the process user from the password database; '' when unknown.
	 *
	 * @return string
	 */
	public static function process_user_home(): string {
		if ( ! self::available( 'posix_getpwuid' ) || ! self::available( 'posix_geteuid' ) ) {
			return '';
		}
		$info = posix_getpwuid( posix_geteuid() );
		return is_array( $info ) ? (string) $info['dir'] : '';
	}

	/**
	 * Whether a stream is a terminal; false when unknown.
	 *
	 * @param resource $stream Stream.
	 * @return bool
	 */
	public static function stream_isatty( $stream ): bool {
		return self::available( 'stream_isatty' ) && is_resource( $stream ) && stream_isatty( $stream );
	}

	/**
	 * Whether zlib's raw deflate is available (compressing and reading compressed entries).
	 *
	 * @return bool
	 */
	public static function can_deflate(): bool {
		return self::available( 'gzdeflate' ) && self::available( 'gzinflate' );
	}

	/**
	 * Calls gzdeflate(); false when zlib is not available.
	 *
	 * @param string $data  Data.
	 * @param int    $level Level.
	 * @return string|false
	 */
	public static function gzdeflate( string $data, int $level ) {
		if ( ! self::available( 'gzdeflate' ) ) {
			return false;
		}
		return gzdeflate( $data, $level );
	}

	/**
	 * Calls gzinflate() with a length limit (never 0: PHP reads 0 as unlimited);
	 * false when zlib is not available or the data is not a deflate stream.
	 *
	 * @param string $data       Compressed data.
	 * @param int    $max_length Largest output accepted (at least 1).
	 * @return string|false
	 */
	public static function gzinflate( string $data, int $max_length ) {
		if ( ! self::available( 'gzinflate' ) ) {
			return false;
		}
		$out = @gzinflate( $data, max( 1, $max_length ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data is reported by the caller.
		return is_string( $out ) ? $out : false;
	}
}

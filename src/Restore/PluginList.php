<?php
/**
 * The lists of active plugins as WordPress stores them, read without unserialize().
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The option active_plugins is a serialized list of plugin files
 * (a:2:{i:0;s:19:"akismet/akismet.php";...}); on multisite,
 * the site option active_sitewide_plugins maps plugin files to the time they were
 * activated (a:1:{s:31:"wp-checkpoint/wp-checkpoint.php";i:1700000000;}).
 * read() accepts exactly that shape, a flat array whose keys and values
 * are integers or strings, each string's length the exact byte count, and
 * nothing after the closing brace; anything else is null. There is no
 * guessing: a value that is not this shape means the backup's options are
 * not what WordPress wrote, and the restore stops rather than decide which
 * plugins the restored site runs.
 */
final class PluginList {

	/**
	 * Most entries read (a site with more active plugins than this is not one WordPress runs).
	 */
	const MAX_ENTRIES = 100000;

	/**
	 * The array, or null when the value is not a flat serialized array of integers and strings.
	 *
	 * @param string $data Stored value.
	 * @return array<int|string, int|string>|null
	 */
	public static function read( string $data ) {
		if ( 1 !== preg_match( '/\Aa:(\d{1,6}):\{/', $data, $head ) ) {
			return null;
		}
		$count = (int) $head[1];
		if ( $count > self::MAX_ENTRIES ) {
			return null;
		}
		$at  = strlen( $head[0] );
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$key = self::scalar( $data, $at );
			if ( null === $key ) {
				return null;
			}
			$value = self::scalar( $data, $at );
			if ( null === $value ) {
				return null;
			}
			$out[ $key ] = $value;
		}
		return ( '}' === substr( $data, $at ) && count( $out ) === $count ) ? $out : null;
	}

	/**
	 * The value WordPress stores for an array.
	 *
	 * @param array<int|string, int|string> $entries Entries.
	 * @return string
	 */
	public static function write( array $entries ): string {
		return serialize( $entries ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the option's own format.
	}

	/**
	 * Read i:<n>; or s:<len>:"<bytes>"; at $at.
	 *
	 * @param string $data Data.
	 * @param int    $at   Position (advanced).
	 * @return int|string|null
	 */
	private static function scalar( string $data, int &$at ) {
		if ( 1 === preg_match( '/\Gi:(-?[1-9]\d{0,18}|0);/', $data, $m, 0, $at ) ) {
			$at += strlen( $m[0] );
			return (int) $m[1];
		}
		if ( 1 !== preg_match( '/\Gs:(\d{1,9}):"/', $data, $m, 0, $at ) ) {
			return null;
		}
		$length = (int) $m[1];
		$start  = $at + strlen( $m[0] );
		if ( $start + $length + 2 > strlen( $data ) || '";' !== substr( $data, $start + $length, 2 ) ) {
			return null;
		}
		$at = $start + $length + 2;
		return substr( $data, $start, $length );
	}
}

<?php
/**
 * Where the entries of the content directory are on this site, as a restore saw them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put a path into the error log; what cannot be told is a state of its own.
// phpcs:disable WordPress.WP.AlternativeFunctions -- a file in the job's work directory.

/**
 * The restore swaps the backup's other content in entry by entry: each
 * top-level entry of the content directory the backup holds replaces the
 * live one of that name. StagingLayout keeps entries apart from the group
 * directories by their spelling, which a link does not have to share with
 * where it leads: an entry that is a link to the must-use plugins directory
 * would be replaced as other content while the group it leads to is
 * replaced as a group. So an entry is also judged by where it is:
 *
 * - ABSENT: nothing of that name is there (by positive evidence).
 * - PLAIN: it is there, and where it is is how it is spelled.
 * - "at:" and the place in hex: it is there, somewhere else than spelled
 *   (a link, or a file system that spells the name another way).
 * - UNKNOWN: whether it is there, or where it is, cannot be told.
 *
 * The files preflight records every entry that is not absent or plain
 * (line()), once, and leaves out of the staged other content (off()) the
 * ones that cannot be told and the ones that are one of the site's group
 * directories or reserved directories, or hold one, or are inside one. The
 * later steps read the record (read()) and do not judge again; the final
 * check and the swap compare what is there then with the record
 * (changed()), so a link put there, taken away or pointed elsewhere since
 * refuses the swap.
 *
 * The caller clears the realpath cache first (clearstatcache( true )): a
 * web server's worker keeps what it resolved before.
 */
final class OtherEntries {

	const ABSENT  = 'absent';
	const PLAIN   = 'plain';
	const UNKNOWN = 'unknown';
	const AT      = 'at:';

	/**
	 * Most entries recorded: the record is read whole by every later step. Each is an entry of the backup that on
	 * this site is a link (or cannot be told), which a content directory has a handful of.
	 */
	const MAX_RECORDS = 1000;

	/**
	 * Where an entry of the content directory is now.
	 *
	 * @param string $content The content directory (resolved, no trailing separator).
	 * @param string $name    The entry's name.
	 * @return string ABSENT, PLAIN, UNKNOWN, or AT and the place in hex.
	 */
	public static function state( string $content, string $name ): string {
		$path = $content . '/' . $name;
		if ( false === @lstat( $path ) ) {
			return Paths::positively_gone( $path ) ? self::ABSENT : self::UNKNOWN;
		}
		$real = Paths::real( $path );
		if ( false === $real ) {
			return self::UNKNOWN;
		}
		$real = rtrim( Paths::normalize( (string) $real ), '/' );
		if ( '' === $real ) {
			return self::UNKNOWN;
		}
		return Paths::same( $real, $path, Paths::is_windows() ) ? self::PLAIN : self::AT . bin2hex( $real );
	}

	/**
	 * Whether a state is one the preflight records (anything but absent and plain).
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function recorded( string $state ): bool {
		return self::ABSENT !== $state && self::PLAIN !== $state;
	}

	/**
	 * Whether an entry in this state is left out of the staged other content: it cannot be told where it is, or
	 * it is one of the directories, holds one, or is inside one (compared without case: leaving out is the safe
	 * answer).
	 *
	 * @param string   $state State.
	 * @param string[] $dirs  The site's group directories and reserved directories (resolved).
	 * @return bool
	 */
	public static function off( string $state, array $dirs ): bool {
		if ( self::ABSENT === $state || self::PLAIN === $state ) {
			return false;
		}
		$real = self::place( $state );
		if ( null === $real ) {
			return true;
		}
		$real = strtolower( $real );
		foreach ( $dirs as $dir ) {
			$dir = strtolower( rtrim( str_replace( '\\', '/', (string) $dir ), '/' ) );
			if ( '' !== $dir && ( $dir === $real || 0 === strpos( $dir . '/', $real . '/' ) || 0 === strpos( $real . '/', $dir . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The place of an "at:" state, or null for any other.
	 *
	 * @param string $state State.
	 * @return string|null
	 */
	public static function place( string $state ) {
		if ( 0 !== strpos( $state, self::AT ) ) {
			return null;
		}
		$hex = (string) substr( $state, strlen( self::AT ) );
		if ( '' === $hex || 1 !== preg_match( '/\A(?:[a-f0-9]{2})+\z/', $hex ) ) {
			return null;
		}
		return (string) hex2bin( $hex );
	}

	/**
	 * A line of the record (the name in hex: an entry's name is any bytes).
	 *
	 * @param string $name  The entry's name.
	 * @param string $state Its state (recorded()).
	 * @param bool   $off   Whether it is left out.
	 * @return string
	 */
	public static function line( string $name, string $state, bool $off ): string {
		return '{"n":"' . bin2hex( $name ) . '","s":"' . $state . '","o":' . ( $off ? '1' : '0' ) . "}\n";
	}

	/**
	 * The record: name => its state and whether it is left out. A file that is not there is an empty record (no
	 * entry was recorded).
	 *
	 * @param string $file The record's file.
	 * @return array<string, array{state: string, off: bool}>
	 * @throws \UnexpectedValueException When the file cannot be read, holds a line that is not a record's, or more than MAX_RECORDS.
	 */
	public static function read( string $file ): array {
		clearstatcache( true, $file );
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			throw new \UnexpectedValueException( 'The record of the content directory\'s entries cannot be read.' );
		}
		$out = array();
		try {
			while ( true ) {
				$text = fgets( $handle, 16384 );
				if ( false === $text ) {
					break;
				}
				if ( 1 !== preg_match( '/\A\{"n":"((?:[a-f0-9]{2})+)","s":"(unknown|at:(?:[a-f0-9]{2})+)","o":([01])\}\n\z/', $text, $m ) ) {
					throw new \UnexpectedValueException( 'The record of the content directory\'s entries is damaged.' );
				}
				$out[ (string) hex2bin( $m[1] ) ] = array(
					'state' => $m[2],
					'off'   => '1' === $m[3],
				);
				if ( count( $out ) > self::MAX_RECORDS ) {
					throw new \UnexpectedValueException( 'The record of the content directory\'s entries holds more entries than a restore records.' );
				}
			}
		} finally {
			fclose( $handle );
		}
		return $out;
	}

	/**
	 * The first entry that is not now as the preflight saw it: a recorded entry in another state than recorded, or
	 * an entry without a record (it was absent or plain) that is neither now. Null when all are as they were.
	 *
	 * @param string                                         $content The content directory.
	 * @param array<string, array{state: string, off: bool}> $records The record (read()).
	 * @param string[]                                       $names   The entries to look at besides the recorded ones (the staged ones).
	 * @return string|null The entry's name.
	 */
	public static function changed( string $content, array $records, array $names ) {
		foreach ( $records as $name => $record ) {
			if ( self::state( $content, (string) $name ) !== $record['state'] ) {
				return (string) $name;
			}
		}
		foreach ( $names as $name ) {
			if ( ! isset( $records[ $name ] ) && self::recorded( self::state( $content, (string) $name ) ) ) {
				return (string) $name;
			}
		}
		return null;
	}
}

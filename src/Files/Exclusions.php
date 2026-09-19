<?php
/**
 * Which paths a backup leaves out.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Files;

/**
 * A backup takes everything by default; leaving something out is the
 * user's choice. The only built-in exclusions are the three kinds whose
 * absence cannot change the restored site: caches (regenerated, and
 * wp-content/cache can be gigabytes), this plugin's own storage directory
 * (otherwise a backup contains the previous backups), and the directories
 * of other backup plugins (the easiest way to blow up the size). Anything
 * else that is merely large (node_modules, .git) is reported by the
 * pre-flight for the user to decide, never dropped silently.
 *
 * Patterns are globs matched against the archive path "p": "*" and "?"
 * stay within one segment, "**" crosses segments. A pattern that matches
 * a directory excludes everything below it. A pattern that cannot be
 * compiled is dropped and reported through invalid(), never treated as
 * "matches nothing" silently.
 */
final class Exclusions {

	/**
	 * Built-in exclusions (archive paths, globs).
	 *
	 * The other-backup-plugin entries are the directory names those plugins
	 * are known to use; extend the list as more are found.
	 */
	const DEFAULTS = array(
		'wp-content/cache',
		'wp-content/wp-checkpoint-*',
		'wp-content/updraft',
		'wp-content/ai1wm-backups',
		'wp-content/wpvividbackups',
		'wp-content/backups-dup-lite',
		'wp-content/backups-dup-pro',
		'wp-content/uploads/backwpup-*',
		'wp-content/uploads/backupbuddy_backups',
		'wp-content/uploads/backupbuddy_temp',
		'wp-content/uploads/wp-migrate-db',
		'wp-content/uploads/snapshots',
		'wp-content/uploads/wp-staging',
	);

	const MAX_PATTERN_BYTES = 1024;

	/**
	 * Compiled patterns: regex => glob.
	 *
	 * @var array<string, string>
	 */
	private $patterns = array();

	/**
	 * Globs that could not be compiled.
	 *
	 * @var string[]
	 */
	private $invalid = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $user_globs Extra patterns from the user.
	 * @param string[] $defaults   Built-in patterns (tests replace them).
	 */
	public function __construct( array $user_globs = array(), array $defaults = self::DEFAULTS ) {
		foreach ( array_merge( $defaults, $user_globs ) as $glob ) {
			$glob  = is_string( $glob ) ? trim( $glob, "/ \t\r\n" ) : '';
			$regex = self::compile( $glob );
			if ( null === $regex ) {
				$this->invalid[] = (string) $glob;
				continue;
			}
			$this->patterns[ $regex ] = $glob;
		}
	}

	/**
	 * Whether an archive path is excluded (itself, or below an excluded directory).
	 *
	 * @param string $p Archive path.
	 * @return bool
	 */
	public function excludes( string $p ): bool {
		foreach ( $this->patterns as $regex => $glob ) {
			$hit = preg_match( $regex, $p );
			if ( 1 === $hit ) {
				return true;
			}
			if ( false === $hit ) {
				// A pattern that fails at match time (backtracking limit) is reported, not ignored.
				$this->invalid[] = $glob;
				unset( $this->patterns[ $regex ] );
			}
		}
		return false;
	}

	/**
	 * The globs in effect.
	 *
	 * @return string[]
	 */
	public function globs(): array {
		return array_values( $this->patterns );
	}

	/**
	 * Patterns that were dropped because they could not be compiled or matched.
	 *
	 * @return string[]
	 */
	public function invalid(): array {
		return array_values( array_unique( $this->invalid ) );
	}

	/**
	 * Glob to an anchored regex that also matches everything below a match.
	 *
	 * @param string $glob Glob.
	 * @return string|null Null when the glob is unusable.
	 */
	public static function compile( string $glob ) {
		if ( '' === $glob || strlen( $glob ) > self::MAX_PATTERN_BYTES || false !== strpos( $glob, "\0" ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $glob ) ) {
			return null;
		}
		$regex = '';
		$len   = strlen( $glob );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $glob[ $i ];
			if ( '*' === $c ) {
				if ( $i + 1 < $len && '*' === $glob[ $i + 1 ] ) {
					$regex .= '.*';
					++$i;
				} else {
					$regex .= '[^/]*';
				}
			} elseif ( '?' === $c ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $c, '#' );
			}
		}
		$regex = '#\A(?:' . $regex . ')(?:/.*)?\z#u';
		if ( false === @preg_match( $regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an uncompilable pattern is reported by the caller.
			return null;
		}
		return $regex;
	}
}

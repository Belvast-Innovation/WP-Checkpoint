<?php
/**
 * Where a restore stages the backup's files, and under which names.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Files\PathKey;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The backup's paths are in the canonical layout (relative to
 * ABSPATH, the content directory always "wp-content/"): a path in one of
 * the four group directories goes to that group's directory on this site
 * (each where this site keeps it), any other entry of the content
 * directory is other-content. A path belongs to no group (it is not
 * restored; the caller reports it) when it is outside "wp-content/", or
 * when its entry of the content directory is a file named like a group
 * directory, an entry named like this plugin's own ("wp-checkpoint-*",
 * without case), a name another name reaches on some file system, or an
 * entry on, above or inside one of this site's group directories or a
 * reserved directory (uploads kept in the content directory under another
 * name, the storage directory).
 *
 * Each group is staged in the parent directory of its live directory
 * (other-content in the content directory itself, where its entries live),
 * so the swap is a rename within one parent. One staging root per parent,
 * with one sub-directory per group:
 * "{parent}/wp-checkpoint-stage-{token}-{job id}-{random}/{group}". The
 * random part is 128 bits, fixed once per restore. Probes of the preflight
 * are named "wp-checkpoint-probe-{token}-{job id}-{16 hex}". Both names
 * start with "wp-checkpoint-", which the export already leaves out of the
 * content directory; a storage directory's name has a 12-hex token right
 * after that prefix, which these never do.
 */
final class StagingLayout {

	const GROUPS         = array( 'plugins', 'themes', 'uploads', 'mu-plugins', 'other-content' );
	const OTHER          = 'other-content';
	const CONTENT        = 'wp-content';
	const STAGE_PREFIX   = 'wp-checkpoint-stage-';
	const PROBE_PREFIX   = 'wp-checkpoint-probe-';
	const RANDOM_PATTERN = '/\A[a-f0-9]{32}\z/';

	/**
	 * Longest staged path: what PHP opens in one call (it refuses a path of MAXPATHLEN - 1 bytes or more, in its
	 * virtual working directory layer, before the file system sees it). The staged path is longer than the live
	 * one by the staging root's name, so a path that fits live may not fit staged.
	 */
	const MAX_PATH_BYTES = PHP_MAXPATHLEN - 2;

	/**
	 * Group => this site's live directory (normalised, absolute, no trailing separator).
	 *
	 * @var array<string, string>
	 */
	private $groups;

	/**
	 * Storage token (the installation's identity in the names).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Job id.
	 *
	 * @var int
	 */
	private $job_id;

	/**
	 * The restore's random part.
	 *
	 * @var string
	 */
	private $random;

	/**
	 * Directories an entry of other-content must not land on, above or in (the storage directory).
	 *
	 * @var string[]
	 */
	private $reserved = array();

	/**
	 * Constructor.
	 *
	 * The directories are compared as given: the caller passes them resolved (realpath()), so that two
	 * spellings of one directory are one.
	 *
	 * @param array<string, string> $groups   Every group => this site's live directory (other-content: the content directory).
	 * @param string                $token    Storage token (12 lowercase hex).
	 * @param int                   $job_id   Job id.
	 * @param string                $random   32 lowercase hex (new_random()).
	 * @param string[]              $reserved Directories other-content must not land on, above or in (the storage directory).
	 * @throws \InvalidArgumentException When a part is not of its shape.
	 */
	public function __construct( array $groups, string $token, int $job_id, string $random, array $reserved = array() ) {
		foreach ( self::GROUPS as $group ) {
			if ( ! isset( $groups[ $group ] ) || '' === rtrim( (string) $groups[ $group ], '/' ) ) {
				throw new \InvalidArgumentException( 'Every content group needs its directory.' );
			}
			$this->groups[ $group ] = rtrim( str_replace( '\\', '/', (string) $groups[ $group ] ), '/' );
		}
		// Job ids of up to 18 digits: what parse() reads back from a name.
		if ( 1 !== preg_match( '/\A[a-f0-9]{12}\z/', $token ) || $job_id <= 0 || $job_id > 999999999999999999 || 1 !== preg_match( self::RANDOM_PATTERN, $random ) ) {
			throw new \InvalidArgumentException( 'Not a staging identity.' );
		}
		$this->token  = $token;
		$this->job_id = $job_id;
		$this->random = $random;
		foreach ( $reserved as $dir ) {
			$dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
			if ( '' !== $dir ) {
				$this->reserved[] = $dir;
			}
		}
	}

	/**
	 * A new random part: 128 bits.
	 *
	 * @return string
	 */
	public static function new_random(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Where a backup path goes: its group, the path inside the group, the live target and the staged file.
	 * Null when it belongs to no group (see the class description; other_content_entry()), or has no name
	 * inside its group.
	 *
	 * @param string $path Backup path (the files index's "p", already validated as an entry path).
	 * @return array{group: string, relative: string, target: string, staged: string}|null
	 */
	public function map( string $path ) {
		$parts = explode( '/', $path );
		if ( count( $parts ) < 2 || self::CONTENT !== $parts[0] || '' === $parts[ count( $parts ) - 1 ] ) {
			return null;
		}
		$named = in_array( $parts[1], array( 'plugins', 'themes', 'uploads', 'mu-plugins' ), true );
		if ( $named && count( $parts ) >= 3 ) {
			$group    = $parts[1];
			$relative = implode( '/', array_slice( $parts, 2 ) );
		} else {
			if ( $named || ! $this->other_content_entry( $parts[1] ) ) {
				return null;
			}
			$group    = self::OTHER;
			$relative = implode( '/', array_slice( $parts, 1 ) );
		}
		return array(
			'group'    => $group,
			'relative' => $relative,
			'target'   => $this->groups[ $group ] . '/' . $relative,
			'staged'   => $this->stage_dir( $group ) . '/' . $relative,
		);
	}

	/**
	 * Whether a name may be an entry of other-content: not one another name reaches on some file system (a
	 * trailing dot or space, which Windows drops; ":" of an NTFS stream; "~" of an 8.3 short name), not named
	 * like this plugin's own directories (without case), and not on, above or inside one of this site's group
	 * directories or a reserved directory (compared as PathKey does: without case, in one Unicode form).
	 *
	 * @param string $name The entry's name in the content directory.
	 * @return bool
	 */
	private function other_content_entry( string $name ): bool {
		if ( 1 === preg_match( '/[.\x20]\z|[:~]/', $name ) || 0 === strpos( strtolower( $name ), 'wp-checkpoint-' ) ) {
			return false;
		}
		$top = self::key( $this->groups[ self::OTHER ] . '/' . $name );
		$off = $this->reserved;
		foreach ( array( 'plugins', 'themes', 'uploads', 'mu-plugins' ) as $group ) {
			$off[] = $this->groups[ $group ];
		}
		foreach ( $off as $dir ) {
			$live = self::key( $dir );
			if ( $live === $top || 0 === strpos( $live . '/', $top . '/' ) || 0 === strpos( $top . '/', $live . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The key under which two paths name one directory on a file system that folds case or Unicode forms.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function key( string $path ): string {
		return function_exists( 'mb_check_encoding' ) && mb_check_encoding( $path, 'UTF-8' ) ? PathKey::of( $path ) : strtolower( $path );
	}

	/**
	 * The live directory of a group.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	public function live_dir( string $group ): string {
		return $this->groups[ $group ];
	}

	/**
	 * The directory the group is staged in and swapped within: the live directory's parent, or for
	 * other-content (whose entries are swapped one by one) the content directory itself.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	public function parent( string $group ): string {
		return self::OTHER === $group ? $this->groups[ $group ] : dirname( $this->groups[ $group ] );
	}

	/**
	 * The distinct parents, in group order.
	 *
	 * @return string[]
	 */
	public function parents(): array {
		$parents = array();
		foreach ( self::GROUPS as $group ) {
			$parents[ $this->parent( $group ) ] = true;
		}
		return array_keys( $parents );
	}

	/**
	 * The staging root in a group's parent.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	public function root( string $group ): string {
		return $this->parent( $group ) . '/' . $this->root_name();
	}

	/**
	 * The directory a group is staged in.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	public function stage_dir( string $group ): string {
		return $this->root( $group ) . '/' . $group;
	}

	/**
	 * The name of every staging root of this restore.
	 *
	 * @return string
	 */
	public function root_name(): string {
		return self::STAGE_PREFIX . $this->token . '-' . $this->job_id . '-' . $this->random;
	}

	/**
	 * A new probe name for this restore (not created): a directory, or a file with $suffix.
	 *
	 * @param string $suffix Suffix: "" for a directory, "-r" for its renamed form, ".php" for the loader probe
	 *                       (the ones parse() recognises).
	 * @throws \InvalidArgumentException When parse() would not recognise the name.
	 * @return string
	 */
	public function probe_name( string $suffix = '' ): string {
		if ( ! in_array( $suffix, array( '', '-r', '.php' ), true ) ) {
			throw new \InvalidArgumentException( 'Not a probe suffix.' );
		}
		return self::PROBE_PREFIX . $this->token . '-' . $this->job_id . '-' . bin2hex( random_bytes( 8 ) ) . $suffix;
	}

	/**
	 * What a directory entry's name says, when it is a staging root or a probe: its kind, the token and the
	 * job id. Null for any other name.
	 *
	 * @param string $name Entry name (not a path).
	 * @return array{kind: string, token: string, job_id: int}|null
	 */
	public static function parse( string $name ) {
		if ( 1 === preg_match( '/\A' . preg_quote( self::STAGE_PREFIX, '/' ) . '([a-f0-9]{12})-([1-9][0-9]{0,17})-[a-f0-9]{32}\z/', $name, $m ) ) {
			return array(
				'kind'   => 'stage',
				'token'  => $m[1],
				'job_id' => (int) $m[2],
			);
		}
		if ( 1 === preg_match( '/\A' . preg_quote( self::PROBE_PREFIX, '/' ) . '([a-f0-9]{12})-([1-9][0-9]{0,17})-[a-f0-9]{16}(-r|\.php)?(\.[a-f0-9]{16}\.tmp)?\z/', $name, $m ) ) {
			return array(
				'kind'   => 'probe',
				'token'  => $m[1],
				'job_id' => (int) $m[2],
			);
		}
		return null;
	}
}

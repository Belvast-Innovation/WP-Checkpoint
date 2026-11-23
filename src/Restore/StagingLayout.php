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
 * name, the storage directory), or an entry left out for where it is on
 * this site (leave_out()). A backup made where the content directory is
 * inside the WordPress directory under another name has its paths under
 * that name; they are read as "wp-content/" ones (content_prefix(),
 * canonical()).
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
	 * What the backup calls the content directory when that is not "wp-content" ('' when it is): a path that
	 * starts with it is read as one in "wp-content/" (canonical()).
	 *
	 * @var string
	 */
	private $prefix = '';

	/**
	 * Entries of the content directory that are not staged as other-content because of where they are on this
	 * site (name => true; leave_out()).
	 *
	 * @var array<string, bool>
	 */
	private $off = array();

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
	 * @param string                $prefix   What the backup calls the content directory when not "wp-content" (content_prefix()), or ''.
	 * @param string[]              $off      Entries of the content directory left out for where they are on this site.
	 * @throws \InvalidArgumentException When a part is not of its shape.
	 */
	public function __construct( array $groups, string $token, int $job_id, string $random, array $reserved = array(), string $prefix = '', array $off = array() ) {
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
		if ( '' !== $prefix && ( self::CONTENT === $prefix || 0 === strpos( $prefix, self::CONTENT . '/' ) || '/' === substr( $prefix, -1 ) || null === self::segments( '/' . $prefix ) ) ) {
			throw new \InvalidArgumentException( 'Not a content directory prefix.' );
		}
		$this->prefix = $prefix;
		foreach ( $off as $name ) {
			$this->leave_out( (string) $name );
		}
	}

	/**
	 * What a backup calls its content directory, from the two paths its manifest records of the site it was made
	 * on, when that is not "wp-content": the content directory's path below the WordPress directory ("app",
	 * "site/content"). '' when it is "wp-content", when the content directory is not inside the WordPress
	 * directory (the export then wrote "wp-content/" itself), and when either value is not a canonical absolute
	 * path: the manifest is not trusted, so a value with a backslash, a control character, an empty segment or a
	 * "." or ".." segment is not used for anything (one trailing "/" is allowed).
	 *
	 * @param string $abspath The manifest's site.abspath.
	 * @param string $content The manifest's site.content_dir.
	 * @return string
	 */
	public static function content_prefix( string $abspath, string $content ): string {
		$base = self::segments( $abspath );
		$dir  = self::segments( $content );
		if ( null === $base || null === $dir || count( $dir ) <= count( $base ) || array_slice( $dir, 0, count( $base ) ) !== $base ) {
			return '';
		}
		$under = array_slice( $dir, count( $base ) );
		// A name that starts with "wp-content" reads nothing differently: a path under it is one under "wp-content"
		// as it is written, and reading it again would put it in another group than it is spelled in.
		return self::CONTENT === $under[0] ? '' : implode( '/', $under );
	}

	/**
	 * The entry of "wp-content" that a backup's manifest places its content directory in or at, when it places it
	 * under the WordPress directory's "wp-content" (as "wp-content/app": "app"), or ''. content_prefix() reads no
	 * path differently for such a name, and as written the backup's paths would all be other content under that
	 * entry, its groups among them: the caller leaves the entry out (the constructor's $off), so its paths belong
	 * to no group and are asked about.
	 *
	 * @param string $abspath The manifest's site.abspath.
	 * @param string $content The manifest's site.content_dir.
	 * @return string
	 */
	public static function content_apart( string $abspath, string $content ): string {
		$base = self::segments( $abspath );
		$dir  = self::segments( $content );
		if ( null === $base || null === $dir || count( $dir ) < count( $base ) + 2 || array_slice( $dir, 0, count( $base ) ) !== $base ) {
			return '';
		}
		return self::CONTENT === $dir[ count( $base ) ] ? $dir[ count( $base ) + 1 ] : '';
	}

	/**
	 * The segments of a canonical absolute path (the drive of a Windows path first, as "c:"), or null when it is
	 * not one (content_prefix()).
	 *
	 * @param string $path Path.
	 * @return string[]|null
	 */
	private static function segments( string $path ) {
		if ( '' === $path || false !== strpos( $path, '\\' ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return null;
		}
		$drive = array();
		if ( 1 === preg_match( '#\A([A-Za-z]:)(/.*)\z#s', $path, $m ) ) {
			$drive = array( $m[1] );
			$path  = $m[2];
		}
		if ( '/' !== $path[0] ) {
			return null;
		}
		$path = '/' === substr( $path, -1 ) ? (string) substr( $path, 0, -1 ) : $path;
		if ( '' === $path ) {
			return $drive;
		}
		$parts = explode( '/', (string) substr( $path, 1 ) );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return null;
			}
		}
		return array_merge( $drive, $parts );
	}

	/**
	 * A backup path as the layout reads it: one that starts with the backup's own name of the content directory
	 * (the constructor's $prefix) starts with "wp-content" instead. Applied once to a path of the backup, never to
	 * its result.
	 *
	 * @param string $path Backup path.
	 * @return string
	 */
	public function canonical( string $path ): string {
		if ( '' !== $this->prefix && 0 === strncmp( $path, $this->prefix . '/', strlen( $this->prefix ) + 1 ) ) {
			return self::CONTENT . substr( $path, strlen( $this->prefix ) );
		}
		return $path;
	}

	/**
	 * Whether canonical() reads a backup path differently than it is written.
	 *
	 * @param string $path Backup path.
	 * @return bool
	 */
	public function reread( string $path ): bool {
		return $this->canonical( $path ) !== $path;
	}

	/**
	 * Leave an entry of the content directory out of other-content from here on: map() gives no place to a path
	 * in it. For an entry that, on this site, is somewhere the swap must not replace (the files preflight judges
	 * that, by where the entry is, and records it).
	 *
	 * @param string $name The entry's name in the content directory.
	 * @return void
	 */
	public function leave_out( string $name ): void {
		$this->off[ $name ] = true;
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
		$parts = explode( '/', $this->canonical( $path ) );
		if ( count( $parts ) < 2 || self::CONTENT !== $parts[0] || '' === $parts[ count( $parts ) - 1 ] ) {
			return null;
		}
		$named = in_array( $parts[1], array( 'plugins', 'themes', 'uploads', 'mu-plugins' ), true );
		if ( $named && count( $parts ) >= 3 ) {
			$group    = $parts[1];
			$relative = implode( '/', array_slice( $parts, 2 ) );
		} else {
			if ( $named || isset( $this->off[ $parts[1] ] ) || ! $this->other_content_entry( $parts[1] ) ) {
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

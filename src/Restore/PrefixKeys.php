<?php
/**
 * The rows WordPress finds by the table prefix, and their names under this site's prefix.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. WordPress names some rows after the table prefix (get_blog_prefix(),
 * update_user_option() for a site): the roles in each options table and a
 * user's capabilities, level and settings in usermeta. A backup made with
 * prefix P restored where the prefix is Q keeps them under P, where
 * WordPress no longer looks: every user would lose their role. These are
 * the exact names, by site: the main site (blog 1, or a single site) has
 * "{P}user_roles" and "{P}{k}"; another site of a network "{P}{id}_user_roles"
 * and "{P}{id}_{k}", with id from the network's blogs table. Nothing is
 * matched by "starts with P": with P empty that would be every name.
 *
 * Every rename goes through an intermediate name only this restore uses
 * (the marker, then the site and the key's position), because one site's
 * new name can be another site's old one (P "wp_", Q "wp_2_": the main
 * site's "wp_2_capabilities" is site 2's old name): old to intermediate,
 * then intermediate to new, each step moving rows out of what it matches.
 *
 * None of these is a name this plugin's own state is stored under
 * (StateCarry carries the exact names of Support\StoredNames, none of which
 * ends in "user_roles"; StoredNamesTest), so carrying the state before the
 * swap never meets them, whatever the table prefixes.
 */
final class PrefixKeys {

	/**
	 * Keys of usermeta WordPress prefixes per site (and the old names WP_User still moves from).
	 */
	const USER_KEYS = array( 'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id', 'media_library_mode', 'persisted_preferences', 'usersettings', 'usersettingstime' );

	/**
	 * The option WordPress prefixes per site.
	 */
	const ROLES = 'user_roles';

	/**
	 * Backup's prefix.
	 *
	 * @var string
	 */
	private $from;

	/**
	 * This site's prefix.
	 *
	 * @var string
	 */
	private $to;

	/**
	 * The intermediate names' start.
	 *
	 * @var string
	 */
	private $marker;

	/**
	 * Constructor.
	 *
	 * @param string $from   Backup's prefix.
	 * @param string $to     This site's prefix.
	 * @param string $marker Start of intermediate names (unique to the restore; not a prefix of any real name).
	 */
	public function __construct( string $from, string $to, string $marker ) {
		$this->from   = $from;
		$this->to     = $to;
		$this->marker = $marker;
	}

	/**
	 * The prefix a site's rows carry under a table prefix: the prefix itself for the main site (blog 1), the
	 * prefix, the id and "_" for another.
	 *
	 * @param string $prefix Table prefix.
	 * @param int    $site   Site id (1 for the main site or a single site).
	 * @return string
	 */
	public static function site( string $prefix, int $site ): string {
		return $site <= 1 ? $prefix : $prefix . $site . '_';
	}

	/**
	 * The usermeta renames of some sites: old => [intermediate, new].
	 *
	 * @param int[] $sites Site ids.
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function user_keys( array $sites ): array {
		$out = array();
		foreach ( $sites as $site ) {
			foreach ( self::USER_KEYS as $i => $key ) {
				$out[ self::site( $this->from, $site ) . $key ] = array( $this->marker . $site . ':' . $i, self::site( $this->to, $site ) . $key );
			}
		}
		return $out;
	}

	/**
	 * A site's roles option: [old, intermediate, new].
	 *
	 * @param int $site Site id.
	 * @return array{0: string, 1: string, 2: string}
	 */
	public function roles( int $site ): array {
		return array( self::site( $this->from, $site ) . self::ROLES, $this->marker . $site . ':r', self::site( $this->to, $site ) . self::ROLES );
	}

	/**
	 * Whether a usermeta key is one of the renamed ones, given which sites exist.
	 *
	 * @param string         $key   Key.
	 * @param callable|int[] $sites Site ids of the network (or a function( int $id ): bool).
	 * @return bool
	 */
	public function known( string $key, $sites ): bool {
		if ( 0 !== strncmp( $key, $this->from, strlen( $this->from ) ) ) {
			return false;
		}
		$rest = (string) substr( $key, strlen( $this->from ) );
		if ( in_array( $rest, self::USER_KEYS, true ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/\A([1-9][0-9]{0,18})_(.+)\z/s', $rest, $m ) || ! in_array( $m[2], self::USER_KEYS, true ) || (int) $m[1] <= 1 ) {
			return false;
		}
		return is_callable( $sites ) ? (bool) call_user_func( $sites, (int) $m[1] ) : in_array( (int) $m[1], $sites, true );
	}

	/**
	 * The copy's name of a key that starts with the backup's prefix and is not a known one.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function copy_of( string $key ): string {
		return $this->to . substr( $key, strlen( $this->from ) );
	}
}

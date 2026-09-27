<?php
/**
 * Calls StoredNamesRule must report (marked "reported") and must let pass
 * (unmarked). Never loaded: RulesTest analyses this file with the rules.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\PHPStan;

use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\StoredNames;

/**
 * One call per line.
 *
 * @param string $hash A SHA-256.
 * @param int    $job  A job id.
 * @return void
 */
function stored_names( string $hash, int $job ): void {
	update_option( 'wpcheckpoint_storage', 1 );
	update_option( StoredNames::STORAGE, 1 );
	update_option( 'wpcheckpoint_unlisted', 1 ); // reported
	get_option( 'wpcheckpoint_unlisted' ); // reported
	get_option( 'date_format' );
	update_option( 'blogname', 'x' ); // reported
	set_site_transient( 'wpcheckpoint_loopback_' . $hash, 1 ); // reported
	set_site_transient( StoredNames::loopback_token( $hash ), 1 );
	delete_site_transient( StoredNames::loopback_job( $job ) );
	update_blog_option( 1, 'wpcheckpoint_unlisted', 1 ); // reported
	update_blog_option( 1, StoredNames::DELETE_DATA, 1 );
	update_network_option( null, 'wpcheckpoint_unlisted', 1 ); // reported
	Options::set( 'wpcheckpoint_unlisted', 1 ); // reported
	Options::delete( StoredNames::ESTIMATE );
	Options::get( StoredNames::STORAGE );
	$name = 'wpcheckpoint_storage';
	delete_option( $name );
	$other = 'wpcheckpoint_' . $hash;
	delete_option( $other ); // reported
	foreach ( array( StoredNames::ESTIMATE, 'wpcheckpoint_unlisted' ) as $each ) {
		Options::delete( $each ); // reported
	}
	update_option( StoredNames::probe( $hash ) . 'x', 1 ); // reported
	delete_site_transient( StoredNames::is_sha256( $hash ) ? 'wpcheckpoint_storage' : 'x' ); // reported
	set_site_transient( StoredNames::is_sha256( $hash ), 1 ); // reported
	call_user_func( 'update_option', 'wpcheckpoint_unlisted', 1 ); // reported
	array_map( 'delete_option', array( 'wpcheckpoint_unlisted' ) ); // reported
	global $wpdb;
	$wpdb->insert( $wpdb->options, array( 'option_name' => 'wpcheckpoint_unlisted' ) ); // reported
	$wpdb->query( "DELETE FROM {$wpdb->sitemeta}" ); // reported
	$wpdb->query( "DELETE FROM {$wpdb->posts}" );
}

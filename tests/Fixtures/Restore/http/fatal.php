<?php
/**
 * A page of the test site that answers with WordPress's fatal error page (wp_die(), HTTP 500): loaded through the web
 * server as any page is, behind the maintenance file like any request (the restore's checks pass it with their key).
 * SiteCheckHttpTest points the check of the home page here.
 *
 * @package WPCheckpoint
 */

// phpcs:disable -- a test fixture served by the test site's web server.

require rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/' ) . '/wp-load.php';
wp_die( 'A fatal error, stood in for by the test.', '', array( 'response' => 500 ) );

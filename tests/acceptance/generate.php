<?php
/**
 * Acceptance data: files under wp-content/uploads/acceptance and tables
 * {prefix}acc_* plus posts, to a given size. Run inside the site:
 *
 *   wp eval-file tests/acceptance/generate.php <files_mb> <db_mb>
 *
 * Content is random (incompressible) or repetitive text (compressible) in
 * about equal parts; names include non-ASCII characters, spaces and an empty
 * file; one file is larger than a volume. Tables cover an integer key, a
 * composite string key, no key at all, NULLs, binary columns and 4-byte
 * UTF-8. Re-running replaces what an earlier run generated.
 *
 * @package WPCheckpoint
 */

// phpcs:ignoreFile -- development tooling, not part of the plugin.

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$files_mb = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 4600;
$db_mb    = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 500;
$root     = wp_upload_dir()['basedir'] . '/acceptance';
mt_srand( 20260923 );

/**
 * Write $bytes bytes to $path in 1 MiB blocks: random, or repeated text.
 */
function wpcacc_write( string $path, int $bytes, bool $text ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	$h     = fopen( $path, 'wb' );
	$block = 1048576;
	$line  = '';
	while ( strlen( $line ) < $block ) {
		$line .= sprintf( "%08d lorem ipsum dolor sit amet, consectetur adipiscing elit %s\n", mt_rand(), str_repeat( chr( 97 + mt_rand( 0, 25 ) ), mt_rand( 1, 40 ) ) );
	}
	for ( $left = $bytes; $left > 0; $left -= $block ) {
		$n = min( $block, $left );
		fwrite( $h, $text ? substr( $line, 0, $n ) : random_bytes( $n ) );
		if ( $text ) {
			$line = substr( $line, 97 ) . substr( $line, 0, 97 ); // Not one repeated megabyte.
		}
	}
	fclose( $h );
}

// Files.
if ( is_dir( $root ) ) {
	passthru( 'rm -rf ' . escapeshellarg( $root ) );
}
$written = 0;
$budget  = $files_mb * 1048576;
$big     = 0;
$large   = array(
	'large/video one.mp4'    => array( 1300 * 1048576, false ), // Larger than a volume.
	'large/archive-2.bin'    => array( 600 * 1048576, false ),
	'large/export-log-3.txt' => array( 400 * 1048576, true ),
);
foreach ( $large as $name => $spec ) {
	if ( $written + $spec[0] <= $budget ) {
		wpcacc_write( $root . '/' . $name, $spec[0], $spec[1] );
		$written += $spec[0];
		++$big;
	}
}
$small = 0;
for ( $i = 0; $i < 20000 && $written < $budget * 0.85; $i++ ) {
	$bytes = mt_rand( 1024, 65536 );
	wpcacc_write( sprintf( '%s/small/d%03d/f%05d.%s', $root, $i % 200, $i, 0 === $i % 2 ? 'txt' : 'bin' ), $bytes, 0 === $i % 2 );
	$written += $bytes;
	++$small;
}
$medium = 0;
while ( $written < $budget ) {
	$bytes = min( $budget - $written, mt_rand( 1, 16 ) * 1048576 );
	wpcacc_write( sprintf( '%s/medium/m%04d.bin', $root, $medium ), $bytes, 0 === $medium % 3 );
	$written += $bytes;
	++$medium;
}
foreach ( array( 'names/café-ü.txt', 'names/日本語のファイル.txt', 'names/with space and (parens).txt', 'names/emoji-😀.txt' ) as $name ) {
	wpcacc_write( $root . '/' . $name, 5000, true );
}
touch( $root . '/names/empty.txt' );
WP_CLI::log( sprintf( 'Files: %d MB (%d small, %d medium, %d large).', (int) ( $written / 1048576 ), $small, $medium, $big ) );

// Tables.
global $wpdb;
$p = $wpdb->prefix;
foreach ( array( 'acc_events', 'acc_meta', 'acc_nopk' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$p}{$t}`" );
}
$wpdb->query( "CREATE TABLE `{$p}acc_events` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `created` datetime NOT NULL, `kind` varchar(32) NOT NULL, `payload` longtext, `digest` varbinary(32) DEFAULT NULL, `note` varchar(191) DEFAULT NULL, PRIMARY KEY (`id`), KEY `kind` (`kind`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
$wpdb->query( "CREATE TABLE `{$p}acc_meta` (`object_id` bigint unsigned NOT NULL, `meta_key` varchar(64) NOT NULL, `meta_value` text, PRIMARY KEY (`object_id`, `meta_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
$wpdb->query( "CREATE TABLE `{$p}acc_nopk` (`line` int NOT NULL, `message` text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

$target = $db_mb * 1048576;
$bytes  = 0;
$id     = 0;
$kinds  = array( 'order', 'login', 'mail', 'cron', 'import', 'webhook' );
while ( $bytes < $target * 0.8 ) {
	$rows = array();
	for ( $i = 0; $i < 2000; $i++ ) {
		++$id;
		$payload = json_encode(
			array(
				'id'    => $id,
				'text'  => str_repeat( 'Grüße 😀 "quoted" \\ back\\slash ', mt_rand( 2, 12 ) ),
				'items' => range( 1, mt_rand( 1, 20 ) ),
			),
			JSON_UNESCAPED_UNICODE
		);
		$note    = 0 === $id % 7 ? 'NULL' : "'" . esc_sql( "note {$id}\nsecond line\t'tab'" ) . "'";
		$digest  = 0 === $id % 11 ? 'NULL' : "UNHEX('" . hash( 'sha256', (string) $id ) . "')";
		$rows[]  = sprintf( "('%s', '%s', '%s', %s, %s)", gmdate( 'Y-m-d H:i:s', 1700000000 + $id ), $kinds[ $id % 6 ], esc_sql( $payload ), $digest, $note );
		$bytes  += strlen( $payload ) + 80;
	}
	$wpdb->query( "INSERT INTO `{$p}acc_events` (`created`, `kind`, `payload`, `digest`, `note`) VALUES " . implode( ',', $rows ) );
}
for ( $o = 1; $o <= 20000; $o += 500 ) {
	$rows = array();
	for ( $j = $o; $j < $o + 500; $j++ ) {
		foreach ( array( '_price', '_sku', '_stock', 'ключ', '_thumbnail_id' ) as $k ) {
			$rows[] = sprintf( "(%d, '%s', '%s')", $j, esc_sql( $k ), esc_sql( str_repeat( "v{$j} ", mt_rand( 1, 30 ) ) ) );
		}
	}
	$wpdb->query( "INSERT INTO `{$p}acc_meta` (`object_id`, `meta_key`, `meta_value`) VALUES " . implode( ',', $rows ) );
}
for ( $o = 1; $o <= 20000; $o += 1000 ) {
	$rows = array();
	for ( $j = $o; $j < $o + 1000; $j++ ) {
		$rows[] = sprintf( "(%d, '%s')", $j % 5000, esc_sql( "duplicate-prone line {$j}" ) ); // Repeated values: no key to tell rows apart.
	}
	$wpdb->query( "INSERT INTO `{$p}acc_nopk` (`line`, `message`) VALUES " . implode( ',', $rows ) );
}
$wpdb->query( "DELETE FROM `{$wpdb->posts}` WHERE `post_type` = 'acc_page'" );
$posts = (int) ( ( $target - $bytes ) / 9000 );
for ( $o = 0; $o < $posts; $o += 200 ) {
	$rows = array();
	for ( $j = $o; $j < min( $posts, $o + 200 ); $j++ ) {
		$content = str_repeat( "<p>Paragraph {$j} with <strong>markup</strong> and a serialized-looking a:1:{s:3:\"key\";s:5:\"value\";}</p>\n", 80 );
		$rows[]  = $wpdb->prepare( '(1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', '2024-01-01 00:00:00', '2024-01-01 00:00:00', $content, "Acceptance page {$j}", '', 'publish', "acceptance-page-{$j}", '2024-01-01 00:00:00', '2024-01-01 00:00:00', 'acc_page', '', '', '' );
	}
	$wpdb->query( "INSERT INTO `{$wpdb->posts}` (`post_author`, `post_date`, `post_date_gmt`, `post_content`, `post_title`, `post_excerpt`, `post_status`, `post_name`, `post_modified`, `post_modified_gmt`, `post_type`, `to_ping`, `pinged`, `post_content_filtered`) VALUES " . implode( ',', $rows ) );
}
$size = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $p ) . '%' ) );
WP_CLI::log( sprintf( 'Database: %d event rows, 100000 meta rows, 20000 unkeyed rows, %d posts; tables take %d MB on disk.', $id, $posts, (int) ( $size / 1048576 ) ) );

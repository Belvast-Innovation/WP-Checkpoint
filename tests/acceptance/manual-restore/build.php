<?php
/**
 * Manual restore acceptance, part 1: a real archive of a test site, and the site's fingerprint.
 *
 * Run inside the test environment, from the plugin's directory:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-checkpoint wp eval-file tests/acceptance/manual-restore/build.php
 *
 * It adds test content to the site (files in the uploads, rows and a table in the database), exports the site with the
 * plugin's own export steps, and writes into build/manual-restore/:
 *   archive/      the volumes and the standalone manifest, as the plugin stores them;
 *   source.json   the fingerprint of the original: every exported table and every uploads file;
 *   tables.json   the names of the exported tables (restore.sh fingerprints the same ones).
 *
 * The seal threshold and the volume hash block are lowered (24 MiB and 16 MiB) so that a small site gives several
 * volumes, one of them hashed in blocks; the manifest records both values, and restore.sh reads them from there.
 * Everything else is the export as a user runs it.
 *
 * It deletes nothing. When a previous run left its output or its test content, it stops and says what to remove.
 */

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\ManifestStep;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require_once __DIR__ . '/fingerprint.php';

const WPC_MANUAL_VOLUME_BYTES = 25165824; // 24 MiB seal threshold.
const WPC_MANUAL_VOLUME_CHUNK = 16777216; // 16 MiB volume hash blocks (a multiple of the 16 MiB content chunk).

/**
 * Stop with a message.
 */
function wpc_manual_fail( string $message ): void {
	fwrite( STDERR, "manual-restore build: {$message}\n" );
	exit( 1 );
}

global $wpdb;
$out     = dirname( __DIR__, 3 ) . '/build/manual-restore';
$uploads = wp_upload_dir( null, false )['basedir'];
$content = $uploads . '/wpc-manual-restore';
$table   = $wpdb->prefix . 'wpc_manual';

if ( file_exists( $out ) ) {
	wpc_manual_fail( "{$out} exists from an earlier run. Remove it first (rm -r build/manual-restore)." );
}
if ( file_exists( $content ) || $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
	wpc_manual_fail( "the test content of an earlier run is still in the site. Remove {$content} and the table {$table} first." );
}

// 1. Test content. Files: two larger than one 16 MiB content chunk (hashed in chunks), one stored whole, small ones,
// names with non-ASCII characters and a nested directory.
wp_mkdir_p( $content . '/nested/deeper' );
foreach ( array(
	'big-a.bin'           => 20 * 1048576 + 1234,
	'big-b.bin'           => 18 * 1048576,
	'medium.bin'          => 6 * 1048576,
	'nested/deeper/x.bin' => 70000,
) as $name => $bytes ) {
	$handle = fopen( $content . '/' . $name, 'wb' );
	for ( $left = $bytes; $left > 0; $left -= 1048576 ) {
		fwrite( $handle, random_bytes( min( $left, 1048576 ) ) );
	}
	fclose( $handle );
}
file_put_contents( $content . '/café-ü.txt', "Grüße\n" );
file_put_contents( $content . '/nested/readme.txt', str_repeat( "line\n", 200 ) );

// Database: an option holding serialized data with the site address, a post, and a table with a 1.5 MiB value (one
// statement larger than 1 MiB), four-byte UTF-8 text and no primary key on a second table.
update_option( 'wpc_manual_serialized', array( 'home' => home_url( '/' ), 'list' => array( 1, 2, 3 ), 'emoji' => "\u{1F600}" ) );
wp_insert_post(
	array(
		'post_title'   => 'Manual restore',
		'post_content' => 'See ' . home_url( '/sample/' ),
		'post_status'  => 'publish',
	)
);
$wpdb->query( "CREATE TABLE `{$table}` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `label` varchar(191) NOT NULL, `payload` longblob, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (`label`, `payload`) VALUES (%s, %s), (%s, %s)", "caf\u{00E9} \u{1F680}", random_bytes( 1572864 ), 'zero bytes', "\x00\x01\x02" ) );
$wpdb->query( "CREATE TABLE `{$table}_nokey` (`a` int NOT NULL, `b` text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "INSERT INTO `{$table}_nokey` (`a`, `b`) VALUES (1, 'one'), (1, 'one'), (2, NULL)" );
if ( '' !== $wpdb->last_error ) {
	wpc_manual_fail( 'the test content could not be written: ' . $wpdb->last_error );
}

// 2. Export with the plugin's own steps; only the packer's two thresholds differ.
$plugin = Plugin::instance();
$export = $plugin->job_types()->get( ExportJob::ID );
$steps  = $export->steps();
$facts  = new ReflectionMethod( ExportJob::class, 'site_facts' );
$facts->setAccessible( true );
$gen = new ReflectionMethod( ExportJob::class, 'generator' );
$gen->setAccessible( true );
$packer = array(
	'volume_bytes'       => WPC_MANUAL_VOLUME_BYTES,
	'volume_chunk_bytes' => WPC_MANUAL_VOLUME_CHUNK,
);
foreach ( $steps as $i => $step ) {
	if ( $step instanceof PackStep ) {
		$steps[ $i ] = new PackStep( null, $packer );
	} elseif ( $step instanceof ManifestStep ) {
		$steps[ $i ] = new ManifestStep( $facts->invoke( null ), $gen->invoke( null ), $packer, Manifest::DEFAULT_CHUNK );
	}
}
$types = new JobTypes();
$types->add( new FixtureJobType( 'manual-restore-export', $steps ) );
$runner = new Runner(
	$plugin->jobs(),
	$types,
	new Redactor( Redactor::installation_secrets() ),
	array(
		'budget'       => new Budget( 20, 32 * 1048576, false ),
		'memory_limit' => -1,
	)
);
$job = $plugin->jobs()->create(
	'manual-restore-export',
	0,
	array(),
	array(
		'contents' => array( 'files' => array( 'uploads' ) ),
		'policy'   => array( 'unreadable' => 'fail', 'oversize' => 'fail', 'large_dirs' => 'include' ),
	)
);
for ( $i = 0; $i < 5000; $i++ ) {
	$result = $runner->tick( $job->id );
	if ( TickResult::MORE !== $result->status ) {
		break;
	}
}
$done = $plugin->jobs()->find( $job->id );
if ( null === $done || 'completed' !== $done->status ) {
	wpc_manual_fail( 'the export did not complete: ' . ( null === $done ? 'no job' : $done->status . ' ' . $done->last_error ) );
}

// 3. The stored archive: the newest standalone manifest and its volumes.
$backups   = $plugin->directories()->backups();
$manifests = glob( $backups . '/*.manifest.json' ) ?: array();
usort(
	$manifests,
	static function ( string $a, string $b ): int {
		return filemtime( $b ) <=> filemtime( $a );
	}
);
if ( array() === $manifests ) {
	wpc_manual_fail( 'no archive in the backups directory' );
}
$manifest = json_decode( (string) file_get_contents( $manifests[0] ), true );
wp_mkdir_p( $out . '/archive' );
copy( $manifests[0], $out . '/archive/' . basename( $manifests[0] ) );
foreach ( $manifest['volumes'] as $volume ) {
	copy( $backups . '/' . $volume['path'], $out . '/archive/' . $volume['path'] );
}
$chunked = array_filter(
	$manifest['volumes'],
	static function ( array $volume ): bool {
		return isset( $volume['chunks'] );
	}
);
if ( count( $manifest['volumes'] ) < 2 || array() === $chunked ) {
	wpc_manual_fail( 'the archive should have several volumes, one of them hashed in blocks; it has ' . count( $manifest['volumes'] ) . ' volume(s), ' . count( $chunked ) . ' in blocks' );
}

// 4. The original's fingerprint: the exported tables and every file in the uploads.
$tables = array_map(
	static function ( array $t ): string {
		return $t['name'];
	},
	$manifest['database']['tables']
);
$db = mysqli_init();
$db->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$db->set_charset( 'utf8mb4' );
$files = wpc_manual_files( $uploads );
file_put_contents( $out . '/tables.json', json_encode( $tables, JSON_PRETTY_PRINT ) . "\n" );
file_put_contents(
	$out . '/source.json',
	json_encode(
		array(
			'table_prefix' => $wpdb->prefix,
			'tables'       => wpc_manual_tables( $db, $wpdb->prefix, $tables ),
			'files'        => $files,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n"
);
printf( "manual-restore build: %d volumes, %d tables, %d files in the uploads, written to %s\n", count( $manifest['volumes'] ), count( $tables ), count( $files ), $out );

<?php
/**
 * Build an archive of many small entries in a process of its own, so the
 * test that verifies it measures the verifier's memory and not the
 * fixture's (PHP 7.4 cannot reset the peak). Prints the manifest path and
 * a fresh work directory as JSON; the caller removes the root.
 *
 * Usage: php build-many-entries.php <files>
 *
 * @package WPCheckpoint
 */

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;

$files   = isset( $argv[1] ) ? (int) $argv[1] : 0;
$builder = new ArchiveBuilder( array( 'volume_bytes' => 1073741824 ) ); // One volume at the entry limit, as the writer would make it.
for ( $i = 0; $i < $files; $i++ ) {
	$builder->file( sprintf( 'wp-content/uploads/%02d/image-%06d.jpg', $i % 12, $i ), 'x' );
}
$builder->table( 'wp_options', array( ArchiveBuilder::noise( 100 ) ) );
$builder->build();
echo json_encode(
	array(
		'manifest' => $builder->manifest_path,
		'work'     => $builder->work_dir(),
	)
);

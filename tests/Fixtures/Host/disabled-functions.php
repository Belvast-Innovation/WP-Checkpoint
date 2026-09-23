<?php
/**
 * Run under php -d disable_functions=... (see HostFunctionsTest): the
 * packer with its default options, the pack step without a reader, and
 * every HostFunctions wrapper must work or answer "unknown" when the host
 * has taken the functions away. Prints one JSON object; any Error or
 * TypeError ends the process with a non-zero exit code.
 *
 * @package WPCheckpoint
 */

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\ZipReader;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;

$out = array(
	'disk_free_space'   => HostFunctions::disk_free_space( sys_get_temp_dir() ),
	'disk_total_space'  => HostFunctions::disk_total_space( sys_get_temp_dir() ),
	'set_time_limit'    => HostFunctions::set_time_limit( 30 ),
	'ignore_user_abort' => HostFunctions::ignore_user_abort(),
	'ini_set'           => HostFunctions::ini_set( 'zlib.output_compression', '0' ),
	'readlink'          => HostFunctions::readlink( __FILE__ ),
	'apache_setenv'     => HostFunctions::apache_setenv( 'no-gzip', '1' ),
	'process_user_home' => HostFunctions::process_user_home(),
	'stream_isatty'     => HostFunctions::stream_isatty( STDIN ),
	'can_deflate'       => HostFunctions::can_deflate(),
	'gzdeflate'         => HostFunctions::gzdeflate( 'x', 6 ),
	'gzinflate'         => HostFunctions::gzinflate( 'x', 1 ),
);

// The packer with its default options: the free-space reader and the deflate switch are its own.
$ctx    = new WorkContext( 'wpcheckpoint-host-' );
$dir    = $ctx->work() . '/packer';
mkdir( $dir, 0700, true );
$packer = Packer::open( $dir, 'site-20260923-120000-ab12', array(), array() );
$packer->open_volume();
$packer->add_string_entry( 'files/a.txt', str_repeat( 'hello ', 100 ), 1758196800 );
$sealed = $packer->seal_volume();
$reader = ZipReader::open( $dir . '/' . $sealed['path'] );
$out['packer_entries'] = $reader->count();

// The pack step without a reader in its options: its space check before the first volume.
ExportPlan::write( $ctx->work(), ExportPlan::PLAN, array( 'base' => 'site-20260923-120000-cd34', 'tables' => array(), 'groups' => array(), 'exclusions' => array() ) );
ExportPlan::write( $ctx->work(), ExportPlan::REVIEW, array( 'findings' => array(), 'decisions' => array( 'exclude_tables' => array(), 'exclude_oversize' => array(), 'exclude_paths' => array(), 'notes' => array() ) ) );
file_put_contents( $ctx->work() . '/database.index.jsonl', '' );
file_put_contents( $ctx->work() . '/files.index.jsonl', '' );
$step   = new PackStep( array() );
$cursor = array();
for ( $i = 0; $i < 20; $i++ ) {
	$result = $step->run( $ctx->context( $cursor, 20 ) );
	if ( StepResult::DONE === $result->kind ) {
		break;
	}
	$cursor = $result->cursor;
}
$out['pack_step'] = $result->kind;
$out['root']      = $ctx->root; // Removed by the caller: this process has no exec().

echo json_encode( $out );

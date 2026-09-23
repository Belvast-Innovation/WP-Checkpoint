<?php
/**
 * Run under php -d disable_functions=gzinflate,gzdeflate (see
 * VerifierEnvironmentTest): every reader method that inflates, and the
 * verifier at both depths and from the embedded copy, on an archive that
 * was written with zlib. Prints JSON.
 *
 * Usage: php verify-without-zlib.php <manifest> <volume> <work root>
 *
 * @package WPCheckpoint
 */

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;

list( , $manifest, $volume, $work ) = $argv;

$reader   = ZipReader::open( $volume );
$deflated = null;
foreach ( $reader->entries() as $entry ) {
	if ( ZipFormat::METHOD_DEFLATE === (int) $entry['method'] && $entry['usize'] > 0 ) {
		$deflated = $entry;
		break;
	}
}
$out   = array( 'deflated_entry' => null !== $deflated );
$calls = array(
	'read'              => static function () use ( $reader, $deflated ) {
		return $reader->read( $deflated );
	},
	'extract'           => static function () use ( $reader, $deflated, $work ) {
		@mkdir( $work . '/x1', 0700, true );
		return $reader->extract( $deflated, $work . '/x1' );
	},
	'extract_piece'     => static function () use ( $reader, $deflated, $work ) {
		@mkdir( $work . '/x2', 0700, true );
		return $reader->extract_piece( $deflated, $work . '/x2', 0, (int) $deflated['usize'], 0 );
	},
	'hash_entry'        => static function () use ( $reader, $deflated ) {
		return $reader->hash_entry( $deflated );
	},
	'hash_entry_chunks' => static function () use ( $reader, $deflated ) {
		return $reader->hash_entry_chunks( $deflated, 65536 );
	},
);
foreach ( $calls as $name => $call ) {
	try {
		$call();
		$out['reader'][ $name ] = 'no exception';
	} catch ( \Throwable $e ) {
		$out['reader'][ $name ] = get_class( $e ) . ( $e instanceof EnvironmentFailure ? ':' . $e->cause() : '' );
	}
}
foreach ( array(
	'structure' => array( $manifest, ArchiveVerifier::DEPTH_STRUCTURE ),
	'full'      => array( $manifest, ArchiveVerifier::DEPTH_FULL ),
	'embedded'  => array( $volume, ArchiveVerifier::DEPTH_FULL ),
) as $name => list( $path, $depth ) ) {
	$dir = $work . '/v-' . $name;
	@mkdir( $dir, 0700, true );
	$verifier = ArchiveVerifier::open( $path, $dir, $depth );
	while ( $verifier->step() ) {
		continue;
	}
	$result                   = $verifier->result();
	$data                     = $result->to_array( 'strval' );
	$out['verifier'][ $name ] = array(
		'outcome' => $data['outcome'],
		'cause'   => $data['unreadable_cause'],
		'text'    => $result->to_text( 'strval' ),
	);
}
echo json_encode( $out );

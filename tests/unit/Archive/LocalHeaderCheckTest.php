<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\LocalHeaderCheck;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Local headers against central records: every writer path of the packer
 * produces headers whose compared fields equal the central record's, so the
 * verifier's check cannot fail a sound archive of ours; a new writer path
 * that introduces a difference beyond the two uncompared ones fails here.
 */
final class LocalHeaderCheckTest extends TestCase {

	/** @var string */
	private $root;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-lhc-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/out', 0700, true );
		mkdir( $this->root . '/src', 0700 );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	private function source( string $name, int $bytes, bool $text = false ): string {
		$path = $this->root . '/src/' . $name;
		file_put_contents( $path, $text ? str_repeat( "line of text\n", intdiv( $bytes, 13 ) + 1 ) : ( $bytes > 0 ? random_bytes( $bytes ) : '' ) );
		touch( $path, 1700000000 );
		return $path;
	}

	/**
	 * Pack with the given options, driving seals and new volumes the way a step does.
	 *
	 * @return string[] Sealed volume paths.
	 */
	private function pack( array $options, callable $body ): array {
		$dir     = $this->root . '/out/' . bin2hex( random_bytes( 3 ) );
		mkdir( $dir );
		$options = array_merge(
			array(
				'volume_bytes'       => 10485760,
				'volume_chunk_bytes' => 1048576,
				'deflate_max_bytes'  => 65536,
				'disk_free'          => static function (): int {
					return PHP_INT_MAX;
				},
			),
			$options
		);
		$packer  = Packer::open( $dir, 'site', array(), $options );
		$add     = static function ( string $path, string $name ) use ( $packer ): void {
			if ( ! $packer->has_open_volume() ) {
				$packer->open_volume();
			} elseif ( ! $packer->has_room( (int) filesize( $path ) ) ) {
				$packer->seal_volume();
				$packer->open_volume();
			}
			$packer->add_entry( $path, $name, 1700000000 );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		};
		$body( $packer, $add );
		$packer->prepare_finish( 8192 );
		if ( ! $packer->has_open_volume() ) {
			$packer->open_volume();
		}
		$packer->finish( array(), '{"embedded":true}', 1700000000 );
		$packer->close();
		return $packer->sealed_paths();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function writer_paths(): array {
		return array(
			'deflate, store, empty, UTF-8 name, manifest string entry, single-volume rename' => array( array() ),
			'several volumes'                                                              => array( array( 'volume_bytes' => 200000 ) ),
			'zip64 local headers (low threshold)'                                          => array( array( 'zip64_threshold' => 1000 ) ),
			'no zlib: everything stored'                                                   => array( array( 'can_deflate' => false ) ),
			'32-bit volume bound'                                                          => array( array( 'max_volume_bytes' => 400000 ) ),
		);
	}

	/**
	 * @dataProvider writer_paths
	 */
	public function test_every_writer_path_writes_local_headers_equal_to_the_central_records( array $options ): void {
		$volumes = $this->pack(
			$options,
			function ( Packer $packer, callable $add ): void {
				$add( $this->source( 'small.txt', 3000, true ), 'files/small.txt' );
				$add( $this->source( 'empty.txt', 0 ), 'files/empty.txt' );
				$add( $this->source( 'big.bin', 300000 ), 'files/big.bin' );
				$add( $this->source( 'name.txt', 500, true ), "files/\u{00FC}mlaut-\u{540D}\u{524D}.txt" );
			}
		);
		$this->assert_all_equal( $volumes, 5 );
	}

	public function test_an_aborted_and_re_added_entry_has_equal_headers(): void {
		$volumes = $this->pack(
			array(),
			function ( Packer $packer, callable $add ): void {
				$b = $this->source( 'b.bin', 300000 );
				$packer->open_volume();
				$packer->add_entry( $b, 'files/b.bin', 1700000000 );
				$packer->write_piece( 65536 );
				$packer->abort_entry();
				$add( $b, 'files/b.bin' );
			}
		);
		$this->assert_all_equal( $volumes, 2 );
	}

	public function test_the_comparison_names_the_fields_and_relaxes_only_for_data_descriptors(): void {
		$central = array( 'name' => 'files/a', 'flags' => ZipFormat::FLAG_UTF8, 'method' => 0, 'time' => 1, 'date' => 2, 'crc' => 3, 'csize' => 4, 'usize' => 4 );
		$this->assertSame( array( 'data_descriptor' => false, 'fields' => array() ), LocalHeaderCheck::compare( $central, $central ) );
		$placeholder = array_merge( $central, array( 'crc' => 0, 'csize' => 0, 'usize' => 0 ) );
		$this->assertSame( array( 'crc', 'csize', 'usize' ), LocalHeaderCheck::compare( $central, $placeholder )['fields'] );
		$this->assertSame( array( 'name' ), LocalHeaderCheck::compare( $central, array_merge( $central, array( 'name' => 'files/b' ) ) )['fields'] );
		$descriptor = array_merge( $placeholder, array( 'flags' => ZipFormat::FLAG_UTF8 | ZipFormat::FLAG_DATA_DESCRIPTOR ) );
		$both       = array_merge( $central, array( 'flags' => $descriptor['flags'] ) );
		$this->assertSame( array( 'data_descriptor' => true, 'fields' => array() ), LocalHeaderCheck::compare( $both, $descriptor ), 'zero CRC and sizes are allowed with a data descriptor' );
		$this->assertSame( array( 'flags' ), LocalHeaderCheck::compare( $central, $descriptor )['fields'], 'the flag on one side only is itself a difference' );
	}

	/**
	 * @param string[] $volumes Volume paths.
	 */
	private function assert_all_equal( array $volumes, int $entries ): void {
		$seen = 0;
		foreach ( $volumes as $volume ) {
			$reader = ZipReader::open( $volume );
			foreach ( $reader->entries() as $entry ) {
				++$seen;
				$check = LocalHeaderCheck::compare( $entry, $reader->local_header( $entry ) );
				$this->assertSame( array( 'data_descriptor' => false, 'fields' => array() ), $check, basename( $volume ) . ' ' . $entry['name'] );
			}
		}
		$this->assertSame( $entries, $seen );
	}
}

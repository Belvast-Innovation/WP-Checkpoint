<?php
/**
 * Builds small but complete archives (volumes, sidecar indexes, embedded and
 * standalone manifests) with the real Packer, for verifier tests.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\Archive;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Archive\ZipReader;

/**
 * Chunk size is 1 MiB (the minimum) so files above it exercise the chunk
 * lists; volumes seal at 3 MiB with 1 MiB container blocks; entries up to
 * 2 MiB are deflated so a chunked file exists in both methods.
 */
final class ArchiveBuilder {

	const CHUNK_BYTES  = 1048576;
	const VOLUME_BYTES = 3145728;
	const DEFLATE_MAX  = 2097152;
	const BASE         = 'example-20260918-100000-a1b2';
	const MTIME        = 1758196800;

	/**
	 * Root of everything built.
	 *
	 * @var string
	 */
	public $root;

	/**
	 * Directory holding the volumes and the standalone manifest.
	 *
	 * @var string
	 */
	public $dir;

	/**
	 * Standalone manifest path.
	 *
	 * @var string
	 */
	public $manifest_path;

	/**
	 * Volume paths in order.
	 *
	 * @var string[]
	 */
	public $volumes = array();

	/**
	 * Tables as name => list of chunk contents.
	 *
	 * @var array<string, string[]>
	 */
	private $tables = array();

	/**
	 * Files as path => array{content, hashed}.
	 *
	 * @var array<string, array{0: string, 1: bool}>
	 */
	private $files = array();

	/**
	 * Options.
	 *
	 * @var array<string, mixed>
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $options files_first (bool), database_lines (callable), files_lines (callable), manifest (callable), deflate_max_bytes (int).
	 */
	public function __construct( array $options = array() ) {
		$this->root    = sys_get_temp_dir() . '/wpcheckpoint-verify-' . bin2hex( random_bytes( 4 ) );
		$this->dir     = $this->root . '/out';
		$this->options = $options;
		mkdir( $this->root . '/src/database', 0700, true );
		mkdir( $this->root . '/src/files', 0700, true );
		mkdir( $this->root . '/work', 0700, true );
		mkdir( $this->dir, 0700, true );
	}

	/**
	 * A fresh work directory for a verifier.
	 *
	 * @return string
	 */
	public function work_dir(): string {
		$dir = $this->root . '/work/' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0700 );
		return $dir;
	}

	/**
	 * Deterministic incompressible bytes.
	 *
	 * @param int $bytes Size.
	 * @param int $seed  Seed.
	 * @return string
	 */
	public static function noise( int $bytes, int $seed = 1 ): string {
		$out = '';
		$i   = $seed * 1000000;
		while ( strlen( $out ) < $bytes ) {
			$out .= hash( 'sha256', (string) $i++, true );
		}
		return substr( $out, 0, $bytes );
	}

	/**
	 * Add a table with the given chunk contents (an empty list is a table with no chunks).
	 *
	 * @param string   $name   Table name.
	 * @param string[] $chunks Chunk contents.
	 * @return ArchiveBuilder
	 */
	public function table( string $name, array $chunks ): ArchiveBuilder {
		$this->tables[ $name ] = $chunks;
		return $this;
	}

	/**
	 * Add a file.
	 *
	 * @param string $path    Path relative to ABSPATH (as in the index).
	 * @param string $content Content.
	 * @param bool   $hashed  Whether the index line carries a hash.
	 * @return ArchiveBuilder
	 */
	public function file( string $path, string $content, bool $hashed = true ): ArchiveBuilder {
		$this->files[ $path ] = array( $content, $hashed );
		return $this;
	}

	/**
	 * A typical small archive: three tables (one with two chunks, one
	 * empty), a small text file, an incompressible image, a file without a
	 * hash, an empty file, a stored chunked file and a deflated chunked file.
	 *
	 * @return ArchiveBuilder
	 */
	public function typical(): ArchiveBuilder {
		return $this
			->table( 'wp_posts', array( self::noise( 300000, 10 ), self::noise( 200000, 11 ) ) )
			->table( 'wp_empty', array() )
			->table( 'wp_options', array( str_repeat( "INSERT INTO `wp_options` VALUES (1, 'siteurl', 'https://example.com');\n", 1500 ) ) )
			->file( 'wp-content/plugins/a/a.txt', str_repeat( "hello\n", 200 ) )
			->file( 'wp-content/uploads/b.jpg', self::noise( 700000, 20 ) )
			->file( 'wp-content/uploads/nohash.bin', self::noise( 50000, 21 ), false )
			->file( 'wp-content/uploads/empty.txt', '' )
			->file( 'wp-content/uploads/big-store.bin', self::noise( 2621440, 22 ) )
			->file( 'wp-content/uploads/big-deflate.bin', str_repeat( 'deflate me ', 143166 ) );
	}

	/**
	 * Pack everything and write the manifests.
	 *
	 * @return ArchiveBuilder
	 */
	public function build(): ArchiveBuilder {
		$src      = $this->root . '/src';
		$entries  = array();
		$db_lines = array();
		$tables   = array();
		foreach ( $this->tables as $name => $chunks ) {
			$hashes = array();
			$bytes  = 0;
			foreach ( $chunks as $i => $content ) {
				$entry = IndexLine::database_path( $name, $i + 1 );
				$path  = $src . '/' . $entry;
				file_put_contents( $path, $content );
				$hash       = hash( 'sha256', $content );
				$hashes[]   = $hash;
				$bytes     += strlen( $content );
				$entries[]  = array( $path, $entry );
				$db_lines[] = array(
					't' => $name,
					'c' => $i + 1,
					'p' => $entry,
					'b' => strlen( $content ),
					'h' => $hash,
				);
			}
			$tables[] = array(
				'name'   => $name,
				'rows'   => count( $chunks ) * 100,
				'bytes'  => $bytes,
				'chunks' => count( $chunks ),
				'sha256' => ChunkHasher::list_hash( $hashes ),
			);
		}
		$file_entries = array();
		$file_lines   = array();
		$files_bytes  = 0;
		foreach ( $this->files as $rel => list( $content, $hashed ) ) {
			$entry = ArchiveVerifier::FILES_PREFIX . $rel;
			$path  = $src . '/' . $entry;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0700, true );
			}
			file_put_contents( $path, $content );
			$file_entries[] = array( $path, $entry );
			$line           = array(
				'p' => $rel,
				'b' => strlen( $content ),
				'm' => self::MTIME,
			);
			if ( $hashed ) {
				$hash      = ChunkHasher::content_hash( $path, self::CHUNK_BYTES );
				$line['h'] = $hash['sha256'];
				if ( isset( $hash['chunks'] ) ) {
					$line['hc'] = $hash['chunks'];
				}
			}
			$file_lines[] = $line;
			$files_bytes += strlen( $content );
		}
		$entries     = ! empty( $this->options['files_first'] ) ? array_merge( $file_entries, $entries ) : array_merge( $entries, $file_entries );
		$files_count = count( $file_lines );

		// The summaries describe the archive as built; a mutated index is meant to disagree with them.
		if ( isset( $this->options['database_lines'] ) ) {
			$db_lines = call_user_func( $this->options['database_lines'], $db_lines );
		}
		if ( isset( $this->options['files_lines'] ) ) {
			$file_lines = call_user_func( $this->options['files_lines'], $file_lines );
		}
		$db_index    = $this->root . '/' . Manifest::DATABASE_INDEX;
		$files_index = $this->root . '/' . Manifest::FILES_INDEX;
		file_put_contents( $db_index, self::jsonl( $db_lines ) );
		file_put_contents( $files_index, self::jsonl( $file_lines ) );

		$options = array(
			'volume_bytes'       => self::VOLUME_BYTES,
			'volume_chunk_bytes' => self::CHUNK_BYTES,
			'deflate_max_bytes'  => $this->options['deflate_max_bytes'] ?? self::DEFLATE_MAX,
			'disk_free'          => static function (): int {
				return PHP_INT_MAX;
			},
		);
		$packer  = Packer::open( $this->dir, self::BASE, array(), $options );
		foreach ( $entries as list( $path, $entry ) ) {
			$packer->add_entry( $path, $entry, self::MTIME );
			while ( $packer->write_piece() > 0 ) {
				continue;
			}
		}
		// The embedded copy lists every volume sealed before the one that holds it: predict finish()'s seal.
		$summary_bytes = filesize( $db_index ) + filesize( $files_index ) + 4096;
		$state         = $packer->state();
		if ( $packer->has_open_volume() && $state['volume']['entries'] > 0 && $state['volume']['bytes'] + $summary_bytes > self::VOLUME_BYTES ) {
			$packer->seal_volume();
		}
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$manifest = array(
			'tables'         => $tables,
			'database_index' => self::content_entry( Manifest::DATABASE_INDEX, $db_index ),
			'files_index'    => self::content_entry( Manifest::FILES_INDEX, $files_index ),
			'files_count'    => $files_count,
			'files_bytes'    => $files_bytes,
		);
		$embedded = $this->manifest_json( $manifest, $packer->volume_entries(), true );
		$packer->finish(
			array(
				Manifest::DATABASE_INDEX => $db_index,
				Manifest::FILES_INDEX    => $files_index,
			),
			$embedded,
			self::MTIME
		);
		while ( $packer->hash_next_block() ) {
			continue;
		}
		$packer->close();
		$this->volumes       = $packer->sealed_paths();
		$this->manifest_path = $this->dir . '/' . self::BASE . '.manifest.json';
		file_put_contents( $this->manifest_path, $this->manifest_json( $manifest, $packer->volume_entries(), false ) );
		return $this;
	}

	/**
	 * Lines to JSONL.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines.
	 * @return string
	 */
	private static function jsonl( array $lines ): string {
		$out = '';
		foreach ( $lines as $line ) {
			$out .= ( is_string( $line ) ? $line : json_encode( $line, JSON_UNESCAPED_SLASHES ) ) . "\n";
		}
		return $out;
	}

	/**
	 * A manifest content entry for a file.
	 *
	 * @param string $name Entry path.
	 * @param string $path File.
	 * @return array<string, mixed>
	 */
	private static function content_entry( string $name, string $path ): array {
		$hash  = ChunkHasher::content_hash( $path, self::CHUNK_BYTES );
		$entry = array(
			'path'  => $name,
			'bytes' => filesize( $path ),
		);
		if ( isset( $hash['chunks'] ) ) {
			$entry['chunks'] = $hash['chunks'];
		}
		$entry['sha256'] = $hash['sha256'];
		return $entry;
	}

	/**
	 * Manifest JSON from the base fixture.
	 *
	 * @param array<string, mixed>              $summary  Built summaries.
	 * @param array<int, array<string, mixed>>  $volumes  Volume entries.
	 * @param bool                              $embedded Embedded copy.
	 * @return string
	 */
	private function manifest_json( array $summary, array $volumes, bool $embedded ): string {
		$base                                  = json_decode( (string) file_get_contents( __DIR__ . '/../Manifest/valid/base.json' ), true );
		$base['hashing']['chunk_bytes']        = self::CHUNK_BYTES;
		$base['hashing']['volume_chunk_bytes'] = self::CHUNK_BYTES;
		$base['database']                      = array(
			'index'  => $summary['database_index'],
			'tables' => $summary['tables'],
		);
		$base['files']                         = array(
			'count' => $summary['files_count'],
			'bytes' => $summary['files_bytes'],
			'index' => $summary['files_index'],
		);
		$base['volumes']                       = $volumes;
		if ( $embedded ) {
			$base['embedded'] = true;
		}
		if ( isset( $this->options['manifest'] ) ) {
			$base = call_user_func( $this->options['manifest'], $base, $embedded );
		}
		return (string) json_encode( $base, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Find an entry in a volume and return its data offset and sizes.
	 *
	 * @param string $volume Volume path.
	 * @param string $name   Entry name.
	 * @return array{offset: int, csize: int, usize: int, method: int}
	 */
	public static function locate( string $volume, string $name ): array {
		$reader = ZipReader::open( $volume );
		$entry  = $reader->find( $name );
		if ( null === $entry ) {
			throw new \RuntimeException( 'No entry ' . $name );
		}
		$h = fopen( $volume, 'rb' );
		fseek( $h, (int) $entry['offset'] );
		$len = ZipFormat::local_header_length( (string) fread( $h, 30 ) );
		fclose( $h );
		return array(
			'offset' => (int) $entry['offset'] + (int) $len,
			'csize'  => (int) $entry['csize'],
			'usize'  => (int) $entry['usize'],
			'method' => (int) $entry['method'],
		);
	}

	/**
	 * Flip one byte of a file in place.
	 *
	 * @param string $path   File.
	 * @param int    $offset Offset.
	 * @return void
	 */
	public static function flip( string $path, int $offset ): void {
		$h = fopen( $path, 'r+b' );
		fseek( $h, $offset );
		$byte = fread( $h, 1 );
		fseek( $h, $offset );
		fwrite( $h, chr( ord( $byte ) ^ 0x55 ) );
		fclose( $h );
	}

	/**
	 * Remove everything built.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		self::rm( $this->root );
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function rm( string $dir ): void {
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rm( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}

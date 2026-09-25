<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use WPCheckpoint\Tests\Fixtures\Permissions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A server that cannot read what a check needs (no zlib, a volume it may
 * not open) gets "could not be checked on this server", with advice that
 * fits the cause; never "damaged", which makes people delete good backups.
 */
final class VerifierEnvironmentTest extends TestCase {

	/** @var ArchiveBuilder|null */
	private $builder;

	protected function tear_down(): void {
		if ( null !== $this->builder ) {
			foreach ( glob( dirname( $this->builder->manifest_path ) . '/*' ) ?: array() as $file ) {
				@chmod( $file, 0600 );
			}
			$this->builder->cleanup();
		}
	}

	private function build(): ArchiveBuilder {
		$this->builder = ( new ArchiveBuilder() )
			->file( 'wp-content/uploads/a.txt', str_repeat( 'compressible text ', 200 ) )
			->table( 'wp_options', array( ArchiveBuilder::noise( 100 ) ) )
			->build();
		return $this->builder;
	}

	public function test_without_zlib_every_read_path_says_this_server_and_never_damaged(): void {
		$builder = $this->build();
		$volumes = glob( dirname( $builder->manifest_path ) . '/*.wpcheckpoint.zip' );
		$this->assertCount( 1, $volumes );
		$work = $builder->work_dir() . '-nozlib';
		mkdir( $work, 0700, true );
		$proc = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=gzinflate,gzdeflate', dirname( __DIR__, 2 ) . '/Fixtures/Archive/verify-without-zlib.php', $builder->manifest_path, $volumes[0], $work ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'WPCHECKPOINT_TEST_SUITE' => 'unit',
				'PATH'                    => (string) getenv( 'PATH' ),
			)
		);
		$out  = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		$code = proc_close( $proc );
		$this->assertSame( 0, $code, $out );
		$result = json_decode( $out, true );
		$this->assertIsArray( $result, $out );
		$this->assertTrue( $result['deflated_entry'], 'the archive has compressed entries' );
		foreach ( $result['reader'] as $method => $thrown ) {
			$this->assertSame( EnvironmentFailure::class . ':' . EnvironmentFailure::ZLIB, $thrown, $method . ': every inflating read path' );
		}
		foreach ( $result['verifier'] as $how => $verdict ) {
			$this->assertSame( VerificationResult::UNREADABLE, $verdict['outcome'], $how . ': ' . $verdict['text'] );
			$this->assertSame( EnvironmentFailure::ZLIB, $verdict['cause'], $how );
			$this->assertStringStartsWith( 'Archive could not be checked on this server.', $verdict['text'] );
			$this->assertStringContainsString( 'The archive itself is not in question', $verdict['text'] );
			$this->assertStringContainsString( 'on a server whose PHP has the zlib extension', $verdict['text'] );
			$this->assertStringNotContainsString( 'damaged', $verdict['text'] );
		}
	}

	public function test_a_volume_this_server_may_not_open_is_its_problem_not_the_archives(): void {
		Permissions::require_enforced();
		$builder = $this->build();
		$volume  = glob( dirname( $builder->manifest_path ) . '/*.wpcheckpoint.zip' )[0];
		chmod( $volume, 0000 );
		foreach ( array( ArchiveVerifier::DEPTH_STRUCTURE, ArchiveVerifier::DEPTH_FULL ) as $depth ) {
			$dir = $builder->work_dir() . '-' . $depth;
			mkdir( $dir, 0700, true );
			$verifier = ArchiveVerifier::open( $builder->manifest_path, $dir, $depth );
			while ( $verifier->step() ) {
				continue;
			}
			$data = $verifier->result()->to_array( 'strval' );
			$this->assertSame( VerificationResult::UNREADABLE, $data['outcome'], $depth . ': ' . $verifier->result()->to_text( 'strval' ) );
			$this->assertSame( EnvironmentFailure::ACCESS, $data['unreadable_cause'] );
			$this->assertStringContainsString( 'Check the permissions of the backup files', $verifier->result()->to_text( 'strval' ) );
		}
	}

	/**
	 * Every catch of \RuntimeException (or wider) on the read paths of the verifier and of the restore
	 * must let EnvironmentFailure through first: the next catch site added is where "damaged" would come back.
	 */
	public function test_every_catch_of_a_read_error_passes_environment_failures_through(): void {
		foreach ( array( 'Archive/ArchiveVerifier.php', 'Archive/ChunkHasher.php', 'Restore/ChunkWalk.php', 'Jobs/RestorePreflightStep.php', 'Jobs/DatabaseImportStep.php' ) as $file ) {
			$groups = self::catch_groups( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/' . $file ) );
			$this->assertNotEmpty( $groups, $file );
			foreach ( $groups as $line => $types ) {
				foreach ( $types as $i => $type ) {
					if ( in_array( ltrim( $type, '\\' ), array( 'RuntimeException', 'Exception', 'Throwable' ), true ) ) {
						$this->assertContains( 'EnvironmentFailure', array_slice( $types, 0, $i ), sprintf( '%s:%d catches %s without passing EnvironmentFailure through first', $file, $line, $type ) );
					}
				}
			}
		}
	}

	/**
	 * The catch clauses of every try block, by the try's line.
	 *
	 * @return array<int, string[]>
	 */
	private static function catch_groups( string $code ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static function ( $t ) {
					return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$groups = array();
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_TRY !== $tokens[ $i ][0] ) {
				continue;
			}
			$line = $tokens[ $i ][2];
			$j    = self::skip_block( $tokens, $i + 1 );
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_CATCH === $tokens[ $j ][0] ) {
				$types = array();
				for ( $k = $j + 2; $k < $count && '$' !== ( is_array( $tokens[ $k ] ) ? $tokens[ $k ][1][0] : '' ) && ')' !== $tokens[ $k ]; $k++ ) {
					if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], self::name_tokens(), true ) ) {
						$types[] = substr( (string) strrchr( '\\' . $tokens[ $k ][1], '\\' ), 1 );
					}
				}
				$groups[ $line ] = array_merge( $groups[ $line ] ?? array(), $types );
				while ( '{' !== $tokens[ $k ] ) {
					++$k;
				}
				$j = self::skip_block( $tokens, $k );
			}
		}
		return $groups;
	}

	/**
	 * Token ids of class names: T_STRING, and on PHP 8 the qualified-name tokens (PHP 7.4 splits
	 * \\RuntimeException into a separator and a T_STRING).
	 *
	 * @return int[]
	 */
	private static function name_tokens(): array {
		$ids = array( T_STRING );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ) as $name ) {
			if ( defined( $name ) ) {
				$ids[] = (int) constant( $name );
			}
		}
		return $ids;
	}

	/**
	 * Index after the brace block that starts at or after $i.
	 */
	private static function skip_block( array $tokens, int $i ): int {
		while ( '{' !== $tokens[ $i ] ) {
			++$i;
		}
		$depth = 0;
		for ( $count = count( $tokens ); $i < $count; $i++ ) {
			$t = is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			if ( '{' === $t || ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $t ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i + 1;
				}
			}
		}
		return $i;
	}
}

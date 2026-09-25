<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Archive\Finding;
use WPCheckpoint\Archive\Limits;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\VerificationResult;
use WPCheckpoint\Archive\ZipFormat;
use WPCheckpoint\Cli\VerifyCommand;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ArchiveVerifierTest extends TestCase {

	/** @var ArchiveBuilder[] */
	private $builders = array();

	protected function tear_down(): void {
		foreach ( $this->builders as $builder ) {
			$builder->cleanup();
		}
		$this->builders = array();
	}

	private function typical( array $options = array() ): ArchiveBuilder {
		$builder          = ( new ArchiveBuilder( $options ) )->typical()->build();
		$this->builders[] = $builder;
		return $builder;
	}

	private static function identity(): callable {
		return static function ( string $text ): string {
			return $text;
		};
	}

	private function verify( ArchiveBuilder $builder, string $path, string $depth = ArchiveVerifier::DEPTH_FULL ): VerificationResult {
		return ArchiveVerifier::open( $path, $builder->work_dir(), $depth )->run();
	}

	/**
	 * Findings as plain arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function findings( VerificationResult $result ): array {
		return $result->to_array( self::identity() )['findings'];
	}

	/**
	 * The first finding matching every given field.
	 */
	private static function find( VerificationResult $result, array $match ): ?array {
		foreach ( self::findings( $result ) as $finding ) {
			foreach ( $match as $key => $value ) {
				if ( ! array_key_exists( $key, $finding ) || $finding[ $key ] !== $value ) {
					continue 2;
				}
			}
			return $finding;
		}
		return null;
	}

	public function test_an_intact_archive_passes_in_full_and_only_partially_at_structure_depth(): void {
		$builder = $this->typical();
		$this->assertCount( 2, $builder->volumes, 'The fixture is meant to span two volumes.' );

		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::PASSED, $result->outcome(), $result->to_text( self::identity() ) );
		$this->assertTrue( $result->is_complete_pass() );
		$this->assertFalse( $result->restore_refused() );
		$this->assertSame( array(), $result->partial_reasons() );
		$this->assertSame( 'Archive is intact.', strtok( $result->to_text( self::identity() ), "\n" ) );
		$counts = $result->counts();
		$this->assertSame( 2, $counts['volumes_declared'] );
		$this->assertSame( 2, $counts['volumes_present'] );
		$this->assertSame( 2, $counts['volumes_verified'] );
		$this->assertSame( 5, $counts['blocks_verified'], 'The first volume is just under 4 MiB: four 1 MiB blocks; the second is one.' );
		$this->assertSame( 3, $counts['tables_declared'] );
		$this->assertSame( 3, $counts['tables_indexed'] );
		$this->assertSame( 3, $counts['chunks_verified'] );
		$this->assertSame( 6, $counts['files_declared'] );
		$this->assertSame( 5, $counts['files_verified'] );
		$this->assertSame( 1, $counts['files_size_only'] );
		$this->assertSame( 0, $counts['entries_missing'] );
		$this->assertSame( 0, $result->findings_total() );
		$this->assertSame( VerifyCommand::EXIT_PASSED, VerifyCommand::exit_code( $result->outcome() ) );

		$structure = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertSame( VerificationResult::PASSED_PARTIAL, $structure->outcome() );
		$this->assertFalse( $structure->is_complete_pass() );
		$this->assertFalse( $structure->restore_refused() );
		$this->assertSame( array( VerificationResult::REASON_STRUCTURE ), $structure->partial_reasons() );
		$this->assertSame( 0, $structure->counts()['chunks_verified'] );
		$this->assertSame( 3, $structure->counts()['tables_indexed'] );
		$this->assertSame( 6, $structure->counts()['files_indexed'] );
		$this->assertSame( VerifyCommand::EXIT_PASSED_PARTIAL, VerifyCommand::exit_code( $structure->outcome() ) );
	}

	public function test_the_embedded_copy_can_never_be_a_complete_pass(): void {
		$builder = $this->typical();
		$result  = $this->verify( $builder, end( $builder->volumes ) );
		$this->assertSame( VerificationResult::PASSED_PARTIAL, $result->outcome(), $result->to_text( self::identity() ) );
		$this->assertFalse( $result->is_complete_pass() );
		$this->assertFalse( $result->restore_refused() );
		$this->assertSame( array( VerificationResult::REASON_EMBEDDED ), $result->partial_reasons() );
		$this->assertTrue( $result->to_array( self::identity() )['embedded'] );
		$counts = $result->counts();
		$this->assertSame( 2, $counts['volumes_declared'], 'The given volume is counted although the copy does not list it.' );
		$this->assertSame( 1, $counts['volumes_verified'], 'The volume holding the copy has no container hash to check.' );
		$this->assertSame( 3, $counts['chunks_verified'], 'Its contents are still checked entry by entry.' );
		$this->assertSame( 5, $counts['files_verified'] );
		$this->assertStringContainsString( 'embedded', $result->to_text( self::identity() ) );

		// A standalone manifest that claims to be embedded, and a copy inside a volume that does not, are both refused.
		$claims = $this->typical(
			array(
				'manifest' => static function ( array $manifest, bool $embedded ): array {
					$manifest['embedded'] = ! $embedded;
					return $manifest;
				},
			)
		);
		$this->assertSame( VerificationResult::INVALID, $this->verify( $claims, $claims->manifest_path )->outcome() );
		$this->assertSame( VerificationResult::INVALID, $this->verify( $claims, end( $claims->volumes ) )->outcome() );
	}

	public function test_a_flipped_byte_names_the_volume_block_and_the_table_chunk(): void {
		$builder = $this->typical();
		$volume  = $builder->volumes[0];
		$chunk   = ArchiveBuilder::locate( $volume, 'database/wp_posts.0002.sql' );
		ArchiveBuilder::flip( $volume, $chunk['offset'] + 1000 );

		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertTrue( $result->restore_refused() );
		$this->assertSame( 'Archive is damaged.', strtok( $result->to_text( self::identity() ), "\n" ) );
		$block = intdiv( $chunk['offset'] + 1000, ArchiveBuilder::CHUNK_BYTES );
		$this->assertNotNull(
			self::find(
				$result,
				array(
					'phase'  => ArchiveVerifier::PHASE_CONTAINERS,
					'kind'   => Finding::CORRUPT,
					'volume' => 1,
					'block'  => $block,
				)
			),
			'The container check names the volume ordinal and the block.'
		);
		$this->assertNotNull(
			self::find(
				$result,
				array(
					'phase' => ArchiveVerifier::PHASE_CONTENTS,
					'kind'  => Finding::CORRUPT,
					'table' => 'wp_posts',
					'chunk' => 2,
				)
			),
			'The content check names the table and its chunk.'
		);
		$this->assertSame( 2, $result->findings_total(), 'Every other block and entry is intact.' );
		$this->assertSame( 2, $result->counts()['chunks_verified'] );
		$this->assertSame( 5, $result->counts()['files_verified'] );
		$this->assertSame( VerifyCommand::EXIT_FAILED, VerifyCommand::exit_code( $result->outcome() ) );
	}

	public function test_large_stored_files_are_checked_one_chunk_per_unit_and_the_damaged_chunk_is_named(): void {
		$builder = $this->typical();
		$volume  = $builder->volumes[0];
		$big     = ArchiveBuilder::locate( $volume, 'files/wp-content/uploads/big-store.bin' );
		$this->assertSame( 0, $big['method'], 'The fixture stores its 2.5 MiB file.' );
		ArchiveBuilder::flip( $volume, $big['offset'] + ArchiveBuilder::CHUNK_BYTES + 7 );

		$verifier = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL );
		$blocks   = array();
		while ( $verifier->step() ) {
			$state = $verifier->state();
			if ( ArchiveVerifier::PHASE_CONTENTS === $state['phase'] && $state['entry_block'] > 0 ) {
				$blocks[] = $state['entry_block'];
			}
		}
		$this->assertSame( array( 1 ), $blocks, 'Chunk 0 passed and left the entry on chunk 1; the mismatch there ended the entry.' );
		$result  = $verifier->result();
		$finding = self::find(
			$result,
			array(
				'phase' => ArchiveVerifier::PHASE_CONTENTS,
				'kind'  => Finding::CORRUPT,
				'entry' => 'files/wp-content/uploads/big-store.bin',
			)
		);
		$this->assertNotNull( $finding );
		$this->assertSame( 1, $finding['block'] );
		$this->assertSame( 4, $result->counts()['files_verified'] );

		// Intact: the same entry takes three units, one per chunk.
		$intact   = $this->typical();
		$verifier = ArchiveVerifier::open( $intact->manifest_path, $intact->work_dir(), ArchiveVerifier::DEPTH_FULL );
		$blocks   = array();
		while ( $verifier->step() ) {
			$state = $verifier->state();
			if ( ArchiveVerifier::PHASE_CONTENTS === $state['phase'] && $state['entry_block'] > 0 ) {
				$blocks[] = $state['entry_block'];
			}
		}
		$this->assertSame( array( 1, 2 ), $blocks );
		$this->assertTrue( $verifier->result()->is_complete_pass() );
	}

	public function test_a_truncated_volume_is_reported_by_ordinal_and_its_contents_as_missing(): void {
		$builder = $this->typical();
		$volume  = $builder->volumes[0];
		$h       = fopen( $volume, 'r+b' );
		ftruncate( $h, intdiv( (int) filesize( $volume ), 2 ) );
		fclose( $h );

		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertNotNull( self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_VOLUMES, 'kind' => Finding::CORRUPT, 'volume' => 1 ) ), 'Size differs.' );
		$this->assertNotNull( self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_CONTENTS, 'kind' => Finding::CORRUPT, 'volume' => 1 ) ), 'Cannot be opened.' );
		$this->assertSame( 1, $result->counts()['volumes_verified'], 'Only the second volume\'s container is checked.' );
		$this->assertSame( 1, $result->counts()['blocks_verified'] );
		$this->assertSame( 8, $result->counts()['entries_missing'], 'Three table chunks and five files lived in the first volume.' );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::MISSING, 'table' => 'wp_posts', 'chunk' => 1 ) ) );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::MISSING, 'entry' => 'files/wp-content/uploads/big-store.bin' ) ) );
		$this->assertSame( 1, $result->counts()['files_verified'], 'The second volume\'s file is still verified.' );
	}

	public function test_a_missing_volume_before_the_last_is_damage_but_the_rest_is_still_verified(): void {
		$builder = $this->typical();
		unlink( $builder->volumes[0] );

		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertTrue( $result->restore_refused() );
		$this->assertSame( 'Archive is damaged.', strtok( $result->to_text( self::identity() ), "\n" ) );
		$this->assertNotNull( self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_VOLUMES, 'kind' => Finding::MISSING, 'volume' => 1 ) ) );
		$this->assertNull( $result->to_array( self::identity() )['stopped_at'], 'The run went on to the end.' );
		$counts = $result->counts();
		$this->assertSame( 1, $counts['volumes_present'] );
		$this->assertSame( 1, $counts['volumes_verified'] );
		$this->assertSame( 8, $counts['entries_missing'] );
		$this->assertSame( 1, $counts['files_verified'] );
		$this->assertSame( 0, $counts['chunks_verified'] );
	}

	public function test_a_missing_last_volume_stops_the_run(): void {
		$builder = $this->typical();
		unlink( $builder->volumes[1] );

		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertSame( ArchiveVerifier::PHASE_VOLUMES, $result->to_array( self::identity() )['stopped_at'] );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::MISSING, 'volume' => 2 ) ) );
		$this->assertSame( 1, $result->findings_total() );
	}

	public function test_a_damaged_sidecar_index_refuses_restore_with_no_skip(): void {
		foreach ( array( Manifest::DATABASE_INDEX, Manifest::FILES_INDEX ) as $name ) {
			$builder = $this->typical();
			$last    = end( $builder->volumes );
			$index   = ArchiveBuilder::locate( $last, $name );
			ArchiveBuilder::flip( $last, $index['offset'] + 3 );

			foreach ( array( $builder->manifest_path, $last ) as $path ) {
				$result = $this->verify( $builder, $path, ArchiveVerifier::DEPTH_STRUCTURE );
				$this->assertSame( VerificationResult::FAILED, $result->outcome(), $name );
				$this->assertTrue( $result->restore_refused() );
				$this->assertSame( ArchiveVerifier::PHASE_INDEXES, $result->to_array( self::identity() )['stopped_at'] );
				$finding = self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_INDEXES, 'kind' => Finding::CORRUPT ) );
				$this->assertNotNull( $finding );
				$this->assertSame( $name, $finding['entry'] );
				$this->assertSame( 2, $finding['volume'] );
			}
		}
	}

	/**
	 * @dataProvider disagreeing_indexes
	 */
	public function test_index_lines_that_disagree_with_the_manifest_are_damage( array $options, string $kind, string $message, array $where ): void {
		$builder = $this->typical( $options );
		$result  = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$text    = $result->to_text( self::identity() );
		$this->assertSame( VerificationResult::FAILED, $result->outcome(), $text );
		$this->assertTrue( $result->restore_refused() );
		$this->assertSame( ArchiveVerifier::PHASE_INDEX_LINES, $result->to_array( self::identity() )['stopped_at'] );
		$this->assertSame( 1, $result->findings_total(), $text );
		$finding = self::findings( $result )[0];
		$this->assertSame( $kind, $finding['kind'] );
		$this->assertStringContainsString( $message, $finding['message'] );
		foreach ( $where as $key => $value ) {
			$this->assertSame( $value, $finding[ $key ], $key );
		}
	}

	public function disagreeing_indexes(): array {
		$h = 'a7e850497d2e33cb3465a56de6b29759bf85c550d47c11b1c4672fb2d1556a7a';
		return array(
			'a chunk line dropped'       => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						unset( $lines[1] );
						return array_values( $lines );
					},
				),
				Finding::MALFORMED,
				'fewer chunks',
				array( 'line' => 2, 'table' => 'wp_options' ),
			),
			'tables out of order'        => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						return array( $lines[2], $lines[0], $lines[1] );
					},
				),
				Finding::MALFORMED,
				'Table order',
				array( 'line' => 1, 'table' => 'wp_options' ),
			),
			'chunk number skipped'       => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						$lines[1]['c'] = 3;
						$lines[1]['p'] = 'database/wp_posts.0003.sql';
						return $lines;
					},
				),
				Finding::MALFORMED,
				'not sequential',
				array( 'line' => 2, 'table' => 'wp_posts', 'chunk' => 3 ),
			),
			'extra chunk'                => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						$extra      = $lines[1];
						$extra['c'] = 3;
						$extra['p'] = 'database/wp_posts.0003.sql';
						array_splice( $lines, 2, 0, array( $extra ) );
						return $lines;
					},
				),
				Finding::MALFORMED,
				'more chunks',
				array( 'line' => 3, 'table' => 'wp_posts' ),
			),
			'unknown table'              => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						$lines[] = array( 't' => 'wp_stranger', 'c' => 1, 'p' => 'database/wp_stranger.0001.sql', 'b' => 1, 'h' => str_repeat( 'a', 64 ) );
						return $lines;
					},
				),
				Finding::MALFORMED,
				'not in the manifest',
				array( 'line' => 4, 'table' => 'wp_stranger' ),
			),
			'chunk hash changed'         => array(
				array(
					'database_lines' => static function ( array $lines ) use ( $h ): array {
						$lines[0]['h'] = $h;
						return $lines;
					},
				),
				Finding::MALFORMED,
				'chunk hashes',
				array( 'line' => 2, 'table' => 'wp_posts' ),
			),
			'chunk size changed'         => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						$lines[2]['b'] = 5;
						return $lines;
					},
				),
				Finding::MALFORMED,
				'table size',
				array( 'line' => 3, 'table' => 'wp_options' ),
			),
			'a table missing entirely'   => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						return array( $lines[0], $lines[1] );
					},
				),
				Finding::MISSING,
				'no lines',
				array( 'table' => 'wp_options' ),
			),
			'an unparsable line'         => array(
				array(
					'database_lines' => static function ( array $lines ): array {
						$lines[1] = '{"t":"wp_posts","c":2,"p":"database/wp_posts.0002.sql","b":"200000","h":"' . str_repeat( 'b', 64 ) . '"}';
						return $lines;
					},
				),
				Finding::MALFORMED,
				'could not be parsed',
				array( 'line' => 2, 'field' => 'b', 'entry' => Manifest::DATABASE_INDEX ),
			),
			'a file line dropped'        => array(
				array(
					'files_lines' => static function ( array $lines ): array {
						array_pop( $lines );
						return $lines;
					},
				),
				Finding::MISSING,
				'fewer files',
				array( 'entry' => Manifest::FILES_INDEX ),
			),
			'a file line duplicated'     => array(
				array(
					'files_lines' => static function ( array $lines ): array {
						$lines[] = $lines[0];
						return $lines;
					},
				),
				Finding::MALFORMED,
				'more files',
				array( 'line' => 7 ),
			),
			'a file size changed'        => array(
				array(
					'files_lines' => static function ( array $lines ): array {
						$lines[0]['b'] = $lines[0]['b'] + 1;
						return $lines;
					},
				),
				Finding::MALFORMED,
				'total file size',
				array( 'entry' => Manifest::FILES_INDEX ),
			),
			'a bad chunk list on a file' => array(
				array(
					'files_lines' => static function ( array $lines ) use ( $h ): array {
						foreach ( $lines as $i => $line ) {
							if ( isset( $line['hc'] ) ) {
								$lines[ $i ]['hc'][0] = $h;
								break;
							}
						}
						return $lines;
					},
				),
				Finding::MALFORMED,
				'could not be parsed',
				array( 'field' => 'h', 'entry' => Manifest::FILES_INDEX ),
			),
		);
	}

	public function test_reordered_entries_are_an_unsupported_layout_not_damage(): void {
		$builder = $this->typical( array( 'files_first' => true ) );
		$result  = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::UNSUPPORTED_LAYOUT, $result->outcome() );
		$this->assertTrue( $result->restore_refused() );
		$this->assertFalse( $result->is_complete_pass() );
		$text = $result->to_text( self::identity() );
		$this->assertSame( 'Archive could not be verified.', strtok( $text, "\n" ) );
		$this->assertStringContainsString( 'repacked by another tool, not that data is damaged', $text );
		$this->assertStringContainsString( VerificationResult::REPACKED_ADVICE, $text );
		$this->assertStringNotContainsString( 'damaged.', strtok( $text, "\n" ) );
		$this->assertSame( 1, $result->findings_total() );
		$this->assertSame( Finding::UNSUPPORTED, self::findings( $result )[0]['kind'] );
		$this->assertSame( ArchiveVerifier::PHASE_CONTENTS, $result->to_array( self::identity() )['stopped_at'] );
		$this->assertSame( 2, $result->counts()['volumes_verified'], 'Containers were fully checked before the walk stopped.' );
		$this->assertSame( VerifyCommand::EXIT_UNSUPPORTED_LAYOUT, VerifyCommand::exit_code( $result->outcome() ) );

		// Structure depth walks the entries too (without hashing them) and reaches the same conclusion.
		$structure = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertSame( VerificationResult::UNSUPPORTED_LAYOUT, $structure->outcome() );
		$this->assertStringContainsString( 'repacked by another tool, not that data is damaged', $structure->to_text( self::identity() ) );
	}

	public function test_a_manifest_that_cannot_be_read_is_invalid(): void {
		$builder = $this->typical();
		file_put_contents( $builder->manifest_path, '{"format":"wpcheckpoint-archive","format_version":2}' );
		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::INVALID, $result->outcome() );
		$this->assertTrue( $result->restore_refused() );
		$this->assertSame( 'Archive could not be read.', strtok( $result->to_text( self::identity() ), "\n" ) );
		$this->assertSame( 'format_version', self::findings( $result )[0]['field'] );
		$this->assertSame( VerifyCommand::EXIT_INVALID, VerifyCommand::exit_code( $result->outcome() ) );

		file_put_contents( $builder->manifest_path, 'not json' );
		$this->assertSame( VerificationResult::INVALID, $this->verify( $builder, $builder->manifest_path )->outcome() );

		file_put_contents( $builder->manifest_path, str_repeat( ' ', Manifest::MAX_JSON_BYTES + 1 ) );
		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::INVALID, $result->outcome() );
		$this->assertStringContainsString( 'larger than allowed', self::findings( $result )[0]['message'] );

		unlink( $builder->manifest_path );
		$this->assertSame( VerificationResult::INVALID, $this->verify( $builder, $builder->manifest_path )->outcome() );

		// A volume without a manifest entry.
		file_put_contents( $builder->dir . '/stray.wpcheckpoint.zip', file_get_contents( $builder->volumes[0] ) );
		$result = $this->verify( $builder, $builder->dir . '/stray.wpcheckpoint.zip' );
		$this->assertSame( VerificationResult::INVALID, $result->outcome() );
		$this->assertStringContainsString( 'no manifest.json', self::findings( $result )[0]['message'] );
	}

	public function test_state_survives_serialisation_between_steps_and_holds_no_site_identity(): void {
		$builder = $this->typical();
		ArchiveBuilder::flip( $builder->volumes[0], ArchiveBuilder::locate( $builder->volumes[0], 'files/wp-content/uploads/b.jpg' )['offset'] + 10 );
		$one_shot = $this->verify( $builder, $builder->manifest_path )->to_array( self::identity() );

		$state  = array();
		$steps  = 0;
		$shared = $builder->work_dir();
		do {
			$verifier = ArchiveVerifier::open( $builder->manifest_path, $shared, ArchiveVerifier::DEPTH_FULL, $state );
			$more     = $verifier->step();
			$json     = json_encode( $verifier->state() );
			$this->assertIsString( $json );
			$this->assertStringNotContainsString( 'example.com', $json );
			$this->assertStringNotContainsString( '/var/www', $json );
			$this->assertStringNotContainsString( $builder->root, $json );
			$this->assertStringNotContainsString( ArchiveBuilder::BASE, $json, 'Volume file names carry the site slug and stay out of the cursor.' );
			$state = json_decode( $json, true );
			++$steps;
		} while ( $more );
		$this->assertGreaterThan( 15, $steps );
		$this->assertSame( $one_shot, VerificationResult::from_state( $state )->to_array( self::identity() ) );
		$this->assertSame( VerificationResult::FAILED, $one_shot['outcome'] );
	}

	public function test_reports_name_volumes_by_ordinal_and_pass_every_text_through_clean(): void {
		$builder = $this->typical();
		unlink( $builder->volumes[0] );
		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertGreaterThan( 5, $result->findings_total() );

		foreach ( array( $result->to_text( self::identity() ), json_encode( $result->to_array( self::identity() ) ) ) as $text ) {
			$this->assertStringNotContainsString( ArchiveBuilder::BASE, $text );
			$this->assertStringNotContainsString( 'example.com', $text );
			$this->assertStringNotContainsString( '/var/www', $text );
			$this->assertStringNotContainsString( $builder->root, $text );
		}
		$this->assertStringContainsString( '(volume 1)', $result->to_text( self::identity() ) );
		$this->assertSame( 1, self::findings( $result )[0]['volume'] );
		$upper = static function ( string $text ): string {
			return strtoupper( $text );
		};
		$this->assertStringContainsString( 'WP_POSTS', $result->to_text( $upper ) );
		$cleaned = $result->to_array( $upper );
		$this->assertSame( 'WP_POSTS', $cleaned['findings'][1]['table'] );
		$this->assertSame( 'volumes', $cleaned['findings'][0]['phase'], 'Phase and kind are fixed vocabulary, not text.' );
	}

	public function test_findings_beyond_the_stored_limit_are_only_counted(): void {
		$builder = $this->typical(
			array(
				'files_lines' => static function ( array $lines ): array {
					for ( $i = 0; $i < 30; $i++ ) {
						$lines[] = array( 'p' => "wp-content/uploads/ghost{$i}.txt", 'b' => 0, 'm' => 1, 'h' => hash( 'sha256', '' ) );
					}
					return $lines;
				},
				'manifest'    => static function ( array $manifest ): array {
					$manifest['files']['count'] += 30;
					return $manifest;
				},
			)
		);
		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertSame( 30, $result->counts()['entries_missing'] );
		$this->assertSame( 30, $result->findings_total() );
		$this->assertCount( ArchiveVerifier::MAX_STORED_FINDINGS, $result->findings() );
		$this->assertStringContainsString( '10 more finding(s)', $result->to_text( self::identity() ) );
	}

	public function test_a_sidecar_index_larger_than_a_chunk_is_checked_in_chunks(): void {
		$builder = new ArchiveBuilder( array( 'deflate_max_bytes' => 65536 ) );
		$builder->table( 'wp_options', array( 'x' ) );
		for ( $i = 0; $i < 9000; $i++ ) {
			$builder->file( sprintf( 'wp-content/uploads/%04d/%s.txt', $i % 100, bin2hex( random_bytes( 8 ) ) ), (string) $i );
		}
		$this->builders[] = $builder->build();
		$manifest         = Manifest::from_json( (string) file_get_contents( $builder->manifest_path ) );
		$this->assertArrayHasKey( 'chunks', $manifest->files_index(), 'The fixture must produce a chunked files index.' );

		$this->assertCount( 2, $manifest->files_index()['chunks'] );
		$verifier = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL );
		$blocks   = array();
		while ( $verifier->step() ) {
			$state = $verifier->state();
			if ( ArchiveVerifier::PHASE_INDEXES === $state['phase'] && 'hash' === $state['sub'] && 'files' === $state['index'] ) {
				$blocks[] = $state['block'];
			}
		}
		$this->assertSame( array( 0, 1 ), $blocks, 'One unit per index chunk.' );
		$result = $verifier->result();
		$this->assertTrue( $result->is_complete_pass(), $result->to_text( self::identity() ) );
		$this->assertSame( 9000, $result->counts()['files_verified'] );

		// A flipped byte in the stored index is caught on extraction (CRC) before the chunk hashes; restore is refused.
		$last  = end( $builder->volumes );
		$index = ArchiveBuilder::locate( $last, Manifest::FILES_INDEX );
		$this->assertSame( 0, $index['method'] );
		ArchiveBuilder::flip( $last, $index['offset'] + ArchiveBuilder::CHUNK_BYTES + 50 );
		$result  = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$finding = self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_INDEXES, 'kind' => Finding::CORRUPT ) );
		$this->assertNotNull( $finding );
		$this->assertSame( Manifest::FILES_INDEX, $finding['entry'] );
		$this->assertTrue( $result->restore_refused() );
	}

	public function test_a_volume_that_changes_during_the_run_is_inconclusive_not_damaged(): void {
		// Grown after the volumes phase (a transfer still writing): the containers phase notices before hashing.
		$builder  = $this->typical();
		$verifier = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL );
		while ( $verifier->step() && ArchiveVerifier::PHASE_CONTAINERS !== $verifier->state()['phase'] ) {
			continue;
		}
		file_put_contents( $builder->volumes[0], 'more', FILE_APPEND );
		$result = $verifier->run();
		$this->assertSame( VerificationResult::CHANGED, $result->outcome() );
		$this->assertTrue( $result->restore_refused() );
		$this->assertFalse( $result->is_complete_pass() );
		$text = $result->to_text( self::identity() );
		$this->assertSame( 'Archive changed while it was being verified.', strtok( $text, "\n" ) );
		$this->assertStringContainsString( 'Verify again once writing has finished', $text );
		$this->assertStringNotContainsString( 'damaged', $text );
		$this->assertSame( 1, $result->findings_total() );
		$finding = self::findings( $result )[0];
		$this->assertSame( Finding::CHANGED, $finding['kind'] );
		$this->assertSame( ArchiveVerifier::PHASE_CONTAINERS, $finding['phase'] );
		$this->assertSame( 1, $finding['volume'] );
		$this->assertSame( 0, $result->counts()['blocks_verified'], 'Not a single block was hashed against a moving file.' );
		$this->assertSame( VerifyCommand::EXIT_CHANGED, VerifyCommand::exit_code( $result->outcome() ) );

		// Changed in the middle of the contents walk: the unit boundary catches it, and earlier findings do not turn it into "damaged".
		$builder  = $this->typical();
		ArchiveBuilder::flip( $builder->volumes[0], ArchiveBuilder::locate( $builder->volumes[0], 'database/wp_posts.0001.sql' )['offset'] + 10 );
		$verifier = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL );
		while ( $verifier->step() ) {
			$state = $verifier->state();
			if ( ArchiveVerifier::PHASE_CONTENTS === $state['phase'] && 1 === $state['volume'] ) {
				file_put_contents( $builder->volumes[1], 'x', FILE_APPEND );
				break;
			}
		}
		$result = $verifier->run();
		$this->assertSame( VerificationResult::CHANGED, $result->outcome() );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::CORRUPT, 'table' => 'wp_posts', 'chunk' => 1 ) ), 'What was found before the change is still listed.' );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::CHANGED, 'volume' => 2 ) ) );
		$this->assertSame( ArchiveVerifier::PHASE_CONTENTS, $result->to_array( self::identity() )['stopped_at'] );

		// A volume that appears after the volumes phase saw it missing is a change too, as is the index volume growing before extraction.
		$builder = $this->typical();
		$copy    = $builder->volumes[0] . '.bak';
		rename( $builder->volumes[0], $copy );
		$verifier = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL );
		while ( $verifier->step() && ArchiveVerifier::PHASE_INDEXES !== $verifier->state()['phase'] ) {
			continue;
		}
		rename( $copy, $builder->volumes[0] );
		file_put_contents( $builder->volumes[1], 'x', FILE_APPEND );
		$result = $verifier->run();
		$this->assertSame( VerificationResult::CHANGED, $result->outcome() );
		$this->assertSame( ArchiveVerifier::PHASE_INDEXES, $result->to_array( self::identity() )['stopped_at'] );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::CHANGED, 'volume' => 2 ) ) );
	}

	/**
	 * A compressed entry larger than Limits::INFLATE_BYTES (another tool may write one; this plugin's export does
	 * not) is unsupported, not damage, at either depth: its sizes are in the central directory. One of exactly
	 * the limit passes.
	 */
	public function test_a_compressed_entry_larger_than_the_inflate_limit_is_unsupported_not_damage_at_both_depths(): void {
		foreach ( array( Limits::INFLATE_BYTES => true, Limits::INFLATE_BYTES + 1 => false ) as $bytes => $passes ) {
			$builder          = ( new ArchiveBuilder( array( 'deflate_max_bytes' => 2 * Limits::INFLATE_BYTES ) ) )->typical()->file( 'wp-content/uploads/large.txt', str_repeat( 'z', $bytes ) )->build();
			$this->builders[] = $builder;
			$this->assertSame( ZipFormat::METHOD_DEFLATE, ArchiveBuilder::locate( $builder->volumes[ count( $builder->volumes ) - 1 ], ArchiveVerifier::FILES_PREFIX . 'wp-content/uploads/large.txt' )['method'], $bytes . ': compressed' );
			foreach ( array( ArchiveVerifier::DEPTH_FULL, ArchiveVerifier::DEPTH_STRUCTURE ) as $depth ) {
				$result = $this->verify( $builder, $builder->manifest_path, $depth );
				$text   = $result->to_text( self::identity() );
				if ( $passes ) {
					$this->assertFalse( $result->restore_refused(), $depth . ': ' . $text );
					continue;
				}
				$this->assertSame( VerificationResult::UNSUPPORTED_LAYOUT, $result->outcome(), $depth . ': ' . $text );
				$finding = self::find( $result, array( 'kind' => Finding::UNSUPPORTED ) );
				$this->assertNotNull( $finding, $depth );
				$this->assertStringContainsString( 'is stored compressed and is 8388609 bytes large; this plugin decompresses a compressed entry in one piece and reads at most 8388608 bytes (8 MiB)', $finding['message'] );
				$this->assertNull( self::find( $result, array( 'kind' => Finding::CORRUPT ) ), $depth . ': not damage' );
			}
		}
	}

	public function test_chunks_larger_than_the_verifier_can_check_in_one_unit_are_unsupported_not_damage(): void {
		// A consistent manifest with chunk sizes above the verifier's bounds: no volume needs a chunk list any more.
		$builder  = $this->typical();
		$manifest = json_decode( (string) file_get_contents( $builder->manifest_path ), true );
		$manifest['hashing']['chunk_bytes']        = Limits::CONTENT_CHUNK_BYTES * 2;
		$manifest['hashing']['volume_chunk_bytes'] = ArchiveVerifier::MAX_CONTAINER_CHUNK * 2;
		foreach ( $manifest['volumes'] as $i => $volume ) {
			unset( $manifest['volumes'][ $i ]['chunks'] );
			$manifest['volumes'][ $i ]['sha256'] = hash_file( 'sha256', $builder->dir . '/' . $volume['path'] );
		}
		file_put_contents( $builder->manifest_path, json_encode( $manifest ) );
		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::UNSUPPORTED_LAYOUT, $result->outcome() );
		$this->assertSame( ArchiveVerifier::PHASE_MANIFEST, $result->to_array( self::identity() )['stopped_at'] );
		$finding = self::findings( $result )[0];
		$this->assertSame( Finding::UNSUPPORTED, $finding['kind'] );
		$this->assertStringContainsString( 'larger than this verifier checks in one step', $finding['message'] );
		$this->assertStringContainsString( (string) ( ArchiveVerifier::MAX_CONTAINER_CHUNK * 2 ), $finding['message'] );
		$this->assertStringNotContainsString( 'damaged', $result->to_text( self::identity() ) );
	}

	public function test_a_malformed_central_directory_is_a_finding_and_the_other_volume_is_still_checked(): void {
		$builder = $this->typical();
		$volume  = $builder->volumes[0];
		$reader  = \WPCheckpoint\Archive\ZipReader::open( $volume );
		$second  = $reader->entries()[1];
		ArchiveBuilder::flip( $volume, (int) $second['cd_offset'] ); // The signature of the second central header.

		$result = $this->verify( $builder, $builder->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertNull( $result->to_array( self::identity() )['stopped_at'], 'The run went on to the end.' );
		$finding = self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_CONTENTS, 'volume' => 1, 'kind' => Finding::CORRUPT ) );
		$this->assertNotNull( $finding );
		$this->assertStringContainsString( 'central directory', $finding['message'] );
		$this->assertSame( 1, $result->counts()['files_verified'], 'The second volume was walked.' );
		$this->assertGreaterThan( 0, $result->counts()['entries_missing'], 'The first volume\'s lines were skipped as missing.' );

		// The same damage in the last volume ends the run at the indexes phase.
		$builder = $this->typical();
		$volume  = $builder->volumes[1];
		$reader  = \WPCheckpoint\Archive\ZipReader::open( $volume );
		ArchiveBuilder::flip( $volume, (int) $reader->entries()[1]['cd_offset'] );
		$result = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertSame( ArchiveVerifier::PHASE_INDEXES, $result->to_array( self::identity() )['stopped_at'] );
		$this->assertStringContainsString( 'central directory', self::findings( $result )[0]['message'] );
	}

	public function test_server_side_failures_are_unreadable_not_damaged(): void {
		// The work directory disappears between units.
		$builder  = $this->typical();
		$work     = $builder->work_dir();
		$verifier = ArchiveVerifier::open( $builder->manifest_path, $work, ArchiveVerifier::DEPTH_FULL );
		while ( $verifier->step() && ArchiveVerifier::PHASE_INDEX_LINES !== $verifier->state()['phase'] ) {
			continue;
		}
		foreach ( glob( $work . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $work );
		$result = $verifier->run();
		$this->assertSame( VerificationResult::UNREADABLE, $result->outcome() );
		$this->assertTrue( $result->restore_refused() );
		$text = $result->to_text( self::identity() );
		$this->assertSame( 'Archive could not be checked on this server.', strtok( $text, "\n" ) );
		$this->assertStringContainsString( 'disk space', $text );
		$this->assertStringNotContainsString( 'damaged', $text );
		$this->assertSame( Finding::ENVIRONMENT, self::findings( $result )[0]['kind'] );
		$this->assertSame( VerifyCommand::EXIT_UNREADABLE, VerifyCommand::exit_code( $result->outcome() ) );

		// A work directory that cannot be written.
		if ( 'Windows' === PHP_OS_FAMILY || 0 === (int) getmyuid() ) {
			return; // Permissions do not bite root or Windows; the case above covers the outcome.
		}
		$builder = $this->typical();
		$work    = $builder->work_dir();
		chmod( $work, 0500 );
		try {
			$result = ArchiveVerifier::open( $builder->manifest_path, $work, ArchiveVerifier::DEPTH_STRUCTURE )->run();
		} finally {
			chmod( $work, 0700 );
		}
		$this->assertSame( VerificationResult::UNREADABLE, $result->outcome() );
		$this->assertSame( ArchiveVerifier::PHASE_INDEXES, $result->to_array( self::identity() )['stopped_at'] );
	}

	public function test_leftover_index_lines_are_reported_a_bounded_batch_per_unit(): void {
		$builder = new ArchiveBuilder( array( 'deflate_max_bytes' => 65536 ) );
		$builder->table( 'wp_options', array( 'x' ) );
		for ( $i = 0; $i < 6000; $i++ ) {
			$builder->file( sprintf( 'wp-content/uploads/%03d/%s.txt', $i % 100, bin2hex( random_bytes( 8 ) ) ), (string) $i );
		}
		$builder->file( 'wp-content/uploads/last.bin', ArchiveBuilder::noise( 3200000, 30 ) ); // Seals the first volume; the second holds only the summaries.
		$this->builders[] = $builder->build();
		$this->assertCount( 2, $builder->volumes );
		unlink( $builder->volumes[0] );

		$verifier = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL );
		$units    = 0;
		while ( $verifier->step() ) {
			$state = $verifier->state();
			if ( ArchiveVerifier::PHASE_CONTENTS === $state['phase'] && $state['volume'] >= 2 ) {
				++$units;
			}
		}
		$result = $verifier->result();
		$this->assertSame( 6002, $result->counts()['entries_missing'] );
		$this->assertGreaterThanOrEqual( 2, $units, 'Six thousand leftover lines take more than one unit of ' . ArchiveVerifier::LINES_PER_UNIT . '.' );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
	}

	public function test_open_refuses_bad_arguments(): void {
		$builder = $this->typical();
		try {
			ArchiveVerifier::open( $builder->manifest_path, $builder->root . '/nope', ArchiveVerifier::DEPTH_FULL );
			$this->fail();
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'work directory', $e->getMessage() );
		}
		try {
			ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), 'deep' );
			$this->fail();
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'depth', $e->getMessage() );
		}
		try {
			ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_FULL, array( 'x' => 1 ) );
			$this->fail();
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'not a verifier state', $e->getMessage() );
		}
		$this->expectException( \LogicException::class );
		ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir() )->result();
	}

	public function test_an_embedded_copy_that_leaves_a_volume_out_is_a_finding_at_structure_depth(): void {
		$builder = $this->typical(
			array(
				'manifest' => static function ( array $manifest, bool $embedded ): array {
					if ( $embedded ) {
						array_pop( $manifest['volumes'] ); // The copy forgets the last data volume.
					}
					return $manifest;
				},
			)
		);
		$structure = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$finding   = self::find( $structure, array( 'phase' => ArchiveVerifier::PHASE_VOLUMES, 'kind' => Finding::MALFORMED ) );
		$this->assertNotNull( $finding, $structure->to_text( self::identity() ) );
		$this->assertStringContainsString( 'does not list the volumes before the last one', $finding['message'] );
		$this->assertSame( 2, $finding['volume'] );
		$this->assertTrue( $structure->restore_refused() );
		$this->assertSame( VerificationResult::FAILED, $structure->outcome() );

		// A copy with the right list but not marked as embedded is a finding too; the intact fixture has none.
		$unmarked = $this->typical(
			array(
				'manifest' => static function ( array $manifest, bool $embedded ): array {
					if ( $embedded ) {
						unset( $manifest['embedded'] );
					}
					return $manifest;
				},
			)
		);
		$result   = $this->verify( $unmarked, $unmarked->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::MALFORMED ) ), $result->to_text( self::identity() ) );
		$intact = $this->typical();
		$this->assertSame( 0, $this->verify( $intact, $intact->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE )->findings_total() );
	}

	/**
	 * Malformed input is a finding, never an exception out of run(): the second time an exception escaped
	 * the verifier (a damaged central directory was the first), so the three shapes are pinned together.
	 */
	public function test_malformed_input_never_escapes_the_verifier_as_an_exception(): void {
		// 1. A malformed embedded copy (not a valid manifest).
		$copy = $this->typical(
			array(
				'manifest' => static function ( array $manifest, bool $embedded ): array {
					if ( $embedded ) {
						$manifest['format'] = 'bogus';
					}
					return $manifest;
				},
			)
		);
		$result = $this->verify( $copy, $copy->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$finding = self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_VOLUMES, 'kind' => Finding::MALFORMED ) );
		$this->assertNotNull( $finding, $result->to_text( self::identity() ) );
		$this->assertStringContainsString( 'not a valid manifest', $finding['message'] );
		$this->assertSame( 2, $finding['volume'] );
		$this->assertTrue( $result->restore_refused() );
		// 2. A malformed central directory.
		$broken = $this->typical();
		$reader = \WPCheckpoint\Archive\ZipReader::open( $broken->volumes[0] );
		ArchiveBuilder::flip( $broken->volumes[0], (int) $reader->entries()[1]['cd_offset'] );
		$result = $this->verify( $broken, $broken->manifest_path );
		$this->assertSame( VerificationResult::FAILED, $result->outcome() );
		$this->assertNotEmpty( preg_grep( '/central directory/', array_column( self::findings( $result ), 'message' ) ) );
		// 3. A malformed index line.
		$torn = $this->typical(
			array(
				'files_lines' => static function ( array $lines ): array {
					$lines[0] = array( 'p' => $lines[0]['p'], 'b' => 'not a number', 'm' => 1 );
					return $lines;
				},
			)
		);
		$result = $this->verify( $torn, $torn->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertTrue( $result->restore_refused() );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::MALFORMED ) ), $result->to_text( self::identity() ) );
	}

	/**
	 * Overwrite bytes of a volume in place.
	 */
	private static function poke( string $path, int $offset, string $bytes ): void {
		$h = fopen( $path, 'r+b' );
		fseek( $h, $offset );
		fwrite( $h, $bytes );
		fclose( $h );
	}

	/**
	 * The first data entry of a volume (not a summary entry).
	 */
	private static function first_data_entry( string $volume ): array {
		foreach ( \WPCheckpoint\Archive\ZipReader::open( $volume )->entries() as $entry ) {
			if ( ! in_array( $entry['name'], \WPCheckpoint\Archive\Packer::SUMMARY_ENTRIES, true ) ) {
				return $entry;
			}
		}
		throw new \RuntimeException( 'no data entry' );
	}

	public function test_a_local_header_that_disagrees_with_the_central_directory_is_damage_at_both_depths(): void {
		// What a run that outlived its lease leaves: the placeholder header (CRC and sizes zero) written over a
		// header another run had already completed. The central directory and every length are unchanged.
		$builder = $this->typical();
		$entry   = self::first_data_entry( $builder->volumes[0] );
		self::poke( $builder->volumes[0], (int) $entry['offset'] + 14, str_repeat( chr( 0 ), 12 ) );
		foreach ( array( ArchiveVerifier::DEPTH_STRUCTURE, ArchiveVerifier::DEPTH_FULL ) as $depth ) {
			$result  = $this->verify( $builder, $builder->manifest_path, $depth );
			$finding = self::find( $result, array( 'phase' => ArchiveVerifier::PHASE_CONTENTS, 'kind' => Finding::CORRUPT, 'entry' => $entry['name'] ) );
			$this->assertNotNull( $finding, $depth . ': ' . $result->to_text( self::identity() ) );
			$this->assertSame( 'The local header of the entry disagrees with the central directory (crc, csize, usize).', $finding['message'] );
			$this->assertSame( 1, $finding['volume'] );
			$this->assertSame( VerificationResult::FAILED, $result->outcome(), $depth );
			$this->assertTrue( $result->restore_refused() );
			$text = $result->to_text( self::identity() );
			$this->assertStringContainsString( VerificationResult::INCONSISTENT_ADVICE, $text, 'a new backup, not a search for other files' );
			$this->assertStringNotContainsString( 'Use the original volume files', $text );
		}
		// A summary entry's header (the embedded manifest in the last volume) is checked too.
		$builder = $this->typical();
		$last    = end( $builder->volumes );
		$summary = \WPCheckpoint\Archive\ZipReader::open( $last )->find( 'manifest.json' );
		self::poke( $last, (int) $summary['offset'] + 14, str_repeat( chr( 0 ), 4 ) );
		$result = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertNotNull( self::find( $result, array( 'kind' => Finding::CORRUPT, 'entry' => 'manifest.json' ) ), $result->to_text( self::identity() ) );
	}

	public function test_an_entry_with_data_descriptors_says_the_archive_was_written_or_repacked_by_another_tool(): void {
		$builder = $this->typical();
		$volume  = $builder->volumes[0];
		$reader  = \WPCheckpoint\Archive\ZipReader::open( $volume );
		$entries = array_values( array_filter( $reader->entries(), static function ( array $e ): bool {
			return ! in_array( $e['name'], \WPCheckpoint\Archive\Packer::SUMMARY_ENTRIES, true );
		} ) );
		foreach ( array_slice( $entries, 0, 2 ) as $entry ) {
			$flags = pack( 'v', \WPCheckpoint\Archive\ZipFormat::FLAG_UTF8 | \WPCheckpoint\Archive\ZipFormat::FLAG_DATA_DESCRIPTOR );
			self::poke( $volume, (int) $entry['offset'] + 6, $flags );
			self::poke( $volume, (int) $entry['cd_offset'] + 8, $flags );
			self::poke( $volume, (int) $entry['offset'] + 14, str_repeat( chr( 0 ), 12 ) ); // Allowed with a data descriptor.
		}
		$result = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$this->assertSame( VerificationResult::UNSUPPORTED_LAYOUT, $result->outcome(), $result->to_text( self::identity() ) );
		$this->assertSame( 1, $result->findings_total(), 'said once, however many entries carry it; the zeroed CRC and sizes are not damage then' );
		$this->assertSame( ArchiveVerifier::FOREIGN_MESSAGE, self::findings( $result )[0]['message'] );
		$this->assertStringContainsString( 'not written by WP Checkpoint, or it was repacked by another tool', $result->to_text( self::identity() ) );
		$this->assertStringContainsString( VerificationResult::REPACKED_ADVICE, $result->to_text( self::identity() ) );
		$this->assertStringNotContainsString( VerificationResult::INCONSISTENT_ADVICE, $result->to_text( self::identity() ), 'zeros allowed by a data descriptor are not an inconsistency' );
	}

	public function test_a_local_header_that_cannot_be_read_is_a_finding_not_an_exception(): void {
		$builder = $this->typical();
		$entry   = self::first_data_entry( $builder->volumes[0] );
		self::poke( $builder->volumes[0], (int) $entry['offset'], 'XXXX' ); // The signature.
		$result  = $this->verify( $builder, $builder->manifest_path, ArchiveVerifier::DEPTH_STRUCTURE );
		$finding = self::find( $result, array( 'kind' => Finding::CORRUPT, 'entry' => $entry['name'] ) );
		$this->assertNotNull( $finding );
		$this->assertStringContainsString( 'The local header of the entry cannot be read: The local header is malformed.', $finding['message'] );
	}

	public function test_the_entry_walk_reports_its_progress_and_says_early_when_it_will_be_slow(): void {
		$builder = new ArchiveBuilder();
		for ( $i = 0; $i < 1200; $i++ ) {
			$builder->file( sprintf( 'wp-content/uploads/f%04d.txt', $i ), 'x' . $i );
		}
		$builder->table( 'wp_options', array( ArchiveBuilder::noise( 300 ) ) );
		$builder->build();
		$this->builders[] = $builder;
		$verifier         = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_STRUCTURE );
		$now              = 0.0;
		$verifier->set_clock( static function () use ( &$now ): float {
			$now += 50.0; // Two readings per unit: 50 seconds per unit of 1000 entries, 0.05 s per entry.
			return $now;
		} );
		$this->assertSame( array( 'phase' => ArchiveVerifier::PHASE_MANIFEST, 'done' => 0, 'total' => 0, 'seconds_left' => null, 'slow' => false ), $verifier->progress() );
		$seen    = array();
		$resumed = null;
		while ( $verifier->step() ) {
			$seen[] = $verifier->progress();
			if ( null === $resumed && ArchiveVerifier::PHASE_CONTENTS === $verifier->progress()['phase'] && $verifier->progress()['done'] >= 1000 ) {
				$resumed = $verifier->state();
			}
		}
		$walk = array_values( array_filter( $seen, static function ( array $p ): bool {
			return ArchiveVerifier::PHASE_CONTENTS === $p['phase'] && $p['done'] > 0;
		} ) );
		$this->assertSame( 1201, $walk[0]['total'], 'every index line: 1200 files and one table chunk' );
		$this->assertSame( 1000, $walk[0]['done'], 'one unit is 1000 entries' );
		$this->assertSame( 11, $walk[0]['seconds_left'], '201 entries left at 0.05 s each, rounded up' );
		$this->assertTrue( $walk[0]['slow'], '1201 entries at 0.05 s is over a minute: said after the first 1000' );
		$last = $verifier->progress();
		$this->assertSame( 1201, $last['done'] );
		$this->assertSame( 0, $last['seconds_left'] );
		$this->assertSame( VerificationResult::PASSED_PARTIAL, $verifier->result()->outcome() );
		$this->assertSame( 1201, $verifier->result()->counts()['headers_checked'] );

		// The measurement is not state: a stored cursor carries positions and counts, not seconds.
		$this->assertNotNull( $resumed );
		$this->assertArrayNotHasKey( 'walk', $resumed );
		$this->assertStringNotContainsString( 'seconds', (string) json_encode( $resumed ) );
		// A verifier resumed from a stored state has no estimate until it has measured a unit of its own.
		$again = ArchiveVerifier::open( $builder->manifest_path, $builder->work_dir(), ArchiveVerifier::DEPTH_STRUCTURE, $resumed );
		$again->set_clock( static function () use ( &$now ): float {
			$now += 50.0;
			return $now;
		} );
		$this->assertSame( 1000, $again->progress()['done'], 'the count is state and carries over' );
		$this->assertNull( $again->progress()['seconds_left'] );
		$this->assertFalse( $again->progress()['slow'] );
		$again->step(); // The remaining 201 entries: fewer than a unit's worth of measurement.
		$this->assertNull( $again->progress()['seconds_left'] );
	}

	public function test_the_two_advices_are_worded_for_what_the_user_can_do(): void {
		$this->assertSame( 'Restore is refused because the contents could not be checked: the entries are not in the order this plugin writes them, or they were written by another tool. Your data is most likely fine. Use the original volume files the plugin produced, exactly as they are: do not repack them with another archive tool, and do not unzip them and zip them again. If those files are gone, follow the steps in the "Restoring a backup by hand" section of the documentation (extract every volume and import the SQL files).', VerificationResult::REPACKED_ADVICE );
		$this->assertSame( 'The archive is inconsistent inside: an entry\'s header disagrees with the archive\'s directory. This can happen when writing was interrupted or when two processes wrote the same backup at once. Do not rely on this backup; make a new one.', VerificationResult::INCONSISTENT_ADVICE );
		$this->assertSame( VerificationResult::REPACKED_ADVICE, VerificationResult::next_step( VerificationResult::UNSUPPORTED_LAYOUT ) );
	}
}

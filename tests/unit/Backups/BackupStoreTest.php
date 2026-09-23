<?php

namespace WPCheckpoint\Tests\Unit\Backups;

use WPCheckpoint\Archive\ArchiveVerifier;
use WPCheckpoint\Backups\BackupStore;
use WPCheckpoint\Backups\VerifyRecord;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Tests\Fixtures\Archive\ArchiveBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The backups directory as the Backups screen sees it: which files are a
 * backup's own, what a summary says, what deleting touches and whether a
 * record of a check still belongs to the manifest.
 */
final class BackupStoreTest extends TestCase {

	const BASE  = ArchiveBuilder::BASE; // example-20260918-100000-a1b2
	const OLDER = 'example-20260101-000000-0000';
	const NEWER = 'another-20261231-235959-ffff';

	/** @var ArchiveBuilder|null */
	private $builder;

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->builder = ( new ArchiveBuilder() )->typical()->build();
		$this->dir     = $this->builder->dir;
	}

	protected function tear_down(): void {
		$this->builder->cleanup();
		// PHPUnit keeps finished test objects: release the fixture's contents.
		$this->builder = null;
	}

	private function store(): BackupStore {
		return new BackupStore( $this->dir );
	}

	private function put( string $name, string $content = 'x' ): void {
		file_put_contents( $this->dir . '/' . $name, $content );
	}

	private function names(): array {
		$names = array_values( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}

	private function job( string $type, string $base, int $id = 5 ): Job {
		$job          = new Job();
		$job->id      = $id;
		$job->type    = $type;
		$job->status  = Job::RUNNING;
		$job->options = array( 'base' => $base );
		return $job;
	}

	private function write_record( string $base, string $manifest_sha256 ): void {
		$result = ArchiveVerifier::open( $this->builder->manifest_path, $this->builder->work_dir(), ArchiveVerifier::DEPTH_STRUCTURE )->run();
		$this->put( VerifyRecord::file_name( $base ), VerifyRecord::to_json( VerifyRecord::from_result( $base, $manifest_sha256, 1758600000, ArchiveVerifier::DEPTH_STRUCTURE, $result, 3 ) ) );
	}

	public function test_own_files_are_the_manifest_the_record_and_the_volumes_of_that_name(): void {
		foreach ( array( '.manifest.json', '.verify.json', '.wpcheckpoint.zip', '.wpcheckpoint.tar', '.part001.wpcheckpoint.zip', '.part1000.wpcheckpoint.tar' ) as $suffix ) {
			$this->assertTrue( BackupStore::is_own_file( self::BASE, self::BASE . $suffix ), $suffix );
		}
		foreach ( array(
			self::BASE . '.manifest.json.bak',
			self::BASE . '.part01.wpcheckpoint.zip',
			self::BASE . '.partABC.wpcheckpoint.zip',
			self::BASE . '.notes.wpcheckpoint.zip',
			self::BASE . '.log',
			self::BASE . '.wpcheckpoint.zip/x',
			self::BASE . 'x.manifest.json',
			'x' . self::BASE . '.manifest.json',
			self::OLDER . '.manifest.json',
			'.htaccess',
			'index.php',
		) as $name ) {
			$this->assertFalse( BackupStore::is_own_file( self::BASE, $name ), $name );
		}
	}

	public function test_backups_are_listed_newest_export_first_and_other_files_are_not_backups(): void {
		copy( $this->builder->manifest_path, $this->dir . '/' . self::OLDER . '.manifest.json' );
		copy( $this->builder->manifest_path, $this->dir . '/' . self::NEWER . '.manifest.json' );
		$this->put( 'notes.manifest.json' );
		$this->put( 'Example-20260918-100000-a1b2.manifest.json' );
		mkdir( $this->dir . '/dir-20260918-100000-a1b2.manifest.json' );
		$this->assertSame( array( self::NEWER, self::BASE, self::OLDER ), $this->store()->bases() );
		$page = $this->store()->page( 2, 2, array() );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( array( self::OLDER ), array_column( $page['items'], 'base' ) );
	}

	public function test_a_storage_path_with_glob_characters_still_lists_and_deletes(): void {
		$odd = dirname( $this->dir ) . '/store[ab]'; // A character class: glob() would look for storea or storeb.
		mkdir( $odd );
		copy( $this->builder->manifest_path, $odd . '/' . self::BASE . '.manifest.json' );
		file_put_contents( $odd . '/' . self::BASE . '.verify.json', '{}' );
		$store = new BackupStore( $odd );
		$this->assertSame( array( self::BASE ), $store->bases() );
		$this->assertSame( 2, $store->delete( self::BASE, array() ) );
		$this->assertSame( array( '.', '..' ), scandir( $odd ) );
	}

	public function test_a_summary_counts_the_volumes_and_says_whether_all_are_here(): void {
		$summary = $this->store()->page( 1, 10, array() )['items'][0];
		$this->assertTrue( $summary['valid'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertSame( count( $this->builder->volumes ), $summary['volumes'] );
		$this->assertSame( array_sum( array_map( 'filesize', $this->builder->volumes ) ), $summary['bytes'] );
		$this->assertSame( '', $summary['in_use'] );
		$this->assertSame( 'none', $summary['verification']['state'] );

		$handle = fopen( $this->builder->volumes[0], 'r+' );
		ftruncate( $handle, 10 );
		fclose( $handle );
		$details = $this->store()->details( self::BASE, array() );
		$this->assertFalse( $details['complete'] );
		$this->assertFalse( $details['volume_files'][0]['size_ok'] );
		$this->assertTrue( $details['volume_files'][0]['present'] );
		$this->assertNull( $details['volume_files'][0]['sha256'], 'the first volume is block-hashed: its manifest hash is not a file hash' );
		$last = count( $details['volume_files'] ) - 1;
		$this->assertSame( hash_file( 'sha256', $this->builder->volumes[ $last ] ), $details['volume_files'][ $last ]['sha256'] );
		$this->assertSame( hash_file( 'sha256', $this->builder->manifest_path ), $details['manifest_file']['sha256'] );
		$this->assertNull( $this->store()->details( self::OLDER, array() ) );
	}

	public function test_details_say_nothing_that_identifies_the_site_or_the_server(): void {
		$details = $this->store()->details( self::BASE, array() );
		$this->assertSame( array( 'charset', 'collate', 'locale', 'multisite', 'php_version', 'table_prefix', 'wp_version' ), self::sorted_keys( $details['site'] ) );
		$json = (string) json_encode( $details );
		$this->assertStringNotContainsString( 'example.com', $json );
		$this->assertStringNotContainsString( '/var/www/html', $json );
		$this->assertStringNotContainsString( 'MariaDB', $json );
	}

	private static function sorted_keys( array $data ): array {
		$keys = array_keys( $data );
		sort( $keys );
		return $keys;
	}

	public function test_a_file_larger_than_any_manifest_is_not_valid_and_no_record_applies_to_it(): void {
		$this->write_record( self::BASE, hash_file( 'sha256', $this->builder->manifest_path ) );
		$handle = fopen( $this->builder->manifest_path, 'r+' );
		ftruncate( $handle, \WPCheckpoint\Archive\Manifest::MAX_JSON_BYTES + 1 );
		fclose( $handle );
		$this->assertSame( 'manifest_changed', $this->store()->verification( self::BASE )['state'] );
		$this->assertFalse( $this->store()->page( 1, 10, array() )['items'][0]['valid'] );
	}

	public function test_an_unreadable_manifest_is_listed_as_not_valid(): void {
		$this->put( self::OLDER . '.manifest.json', '{"format":' );
		$items = $this->store()->page( 1, 10, array() )['items'];
		$this->assertSame( self::OLDER, $items[1]['base'] );
		$this->assertFalse( $items[1]['valid'] );
	}

	public function test_delete_removes_exactly_the_backups_own_files(): void {
		// A backup whose manifest lists the volumes of another: deleting it never follows that list.
		copy( $this->builder->manifest_path, $this->dir . '/' . self::OLDER . '.manifest.json' );
		$this->put( self::OLDER . '.part001.wpcheckpoint.zip' );
		$this->put( self::OLDER . '.verify.json' );
		$this->put( self::OLDER . '.manifest.json.bak' );
		$this->put( self::OLDER . '.log' );
		$before = $this->names();
		$this->assertSame( 3, $this->store()->delete( self::OLDER, array() ) );
		$this->assertSame(
			array_values( array_diff( $before, array( self::OLDER . '.manifest.json', self::OLDER . '.part001.wpcheckpoint.zip', self::OLDER . '.verify.json' ) ) ),
			$this->names()
		);
		foreach ( $this->builder->volumes as $volume ) {
			$this->assertFileExists( $volume );
		}
	}

	public function test_a_backup_in_use_is_neither_deleted_nor_touched(): void {
		$before = $this->names();
		foreach ( array( 'verify', 'restore' ) as $type ) {
			$active = array( $this->job( $type, self::BASE ) );
			$this->assertNotSame( '', $this->store()->page( 1, 10, $active )['items'][0]['in_use'] );
			try {
				$this->store()->delete( self::BASE, $active );
				$this->fail( 'a backup in use must not be deleted' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( sprintf( 'This backup is being %s (job 5).', 'verify' === $type ? 'verified' : 'restored' ), $e->getMessage() );
			}
			$this->assertSame( $before, $this->names() );
		}
		// A job about another backup does not hold this one.
		$this->assertSame( '', $this->store()->page( 1, 10, array( $this->job( 'restore', self::OLDER ) ) )['items'][0]['in_use'] );
	}

	public function test_a_record_applies_only_to_the_manifest_it_was_made_for(): void {
		$this->write_record( self::BASE, hash_file( 'sha256', $this->builder->manifest_path ) );
		$verification = $this->store()->verification( self::BASE );
		$this->assertSame( 'current', $verification['state'] );
		$this->assertSame( 'passed_partial', $verification['record']['outcome'] );

		// The manifest replaced (uploaded again, edited by hand): the record no longer applies.
		file_put_contents( $this->builder->manifest_path, "\n", FILE_APPEND );
		$this->assertSame( 'manifest_changed', $this->store()->verification( self::BASE )['state'] );

		// A damaged record, or one about another backup, is no record.
		$this->put( VerifyRecord::file_name( self::BASE ), '{"format":"wpcheckpoint-verify"' );
		$this->assertSame( array( 'state' => 'none', 'record' => null ), $this->store()->verification( self::BASE ) );
		$this->write_record( self::OLDER, hash_file( 'sha256', $this->builder->manifest_path ) );
		rename( $this->dir . '/' . VerifyRecord::file_name( self::OLDER ), $this->dir . '/' . VerifyRecord::file_name( self::BASE ) );
		$this->assertSame( 'none', $this->store()->verification( self::BASE )['state'] );
	}
}

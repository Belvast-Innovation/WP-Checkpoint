<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\ExportOptions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ExportOptionsTest extends TestCase {

	public function test_defaults_include_everything_and_ask_every_question(): void {
		$options = ExportOptions::normalize( array() );
		$this->assertTrue( $options['contents']['database'] );
		$this->assertSame( ScanRoots::GROUPS, $options['contents']['files'] );
		$this->assertSame( array(), $options['exclusions'] );
		$this->assertSame( array(), $options['exclude_tables'] );
		$this->assertSame( array( 'unreadable' => 'ask', 'oversize' => 'ask', 'large_dirs' => 'ask' ), $options['policy'] );
	}

	public function test_values_are_validated_and_normalised(): void {
		$options = ExportOptions::normalize( array(
			'contents'       => array( 'database' => false, 'files' => array( 'uploads', 'plugins', 'uploads' ) ),
			'exclusions'     => array( ' wp-content/uploads/cache ', 'wp-content/uploads/*.log' ),
			'exclude_tables' => array( 'wp_actionscheduler_logs', 'wp_actionscheduler_logs' ),
			'policy'         => array( 'oversize' => 'exclude' ),
		) );
		$this->assertFalse( $options['contents']['database'] );
		$this->assertSame( array( 'uploads', 'plugins' ), $options['contents']['files'], 'deduplicated, order kept' );
		$this->assertSame( array( 'wp-content/uploads/cache', 'wp-content/uploads/*.log' ), $options['exclusions'] );
		$this->assertSame( array( 'wp_actionscheduler_logs' ), $options['exclude_tables'] );
		$this->assertSame( array( 'unreadable' => 'ask', 'oversize' => 'exclude', 'large_dirs' => 'ask' ), $options['policy'] );
		$this->assertSame( array( 'unreadable' => 'continue', 'oversize' => 'fail', 'large_dirs' => 'include' ), ExportOptions::UNATTENDED );
		foreach ( ExportOptions::UNATTENDED as $key => $value ) {
			$this->assertContains( $value, ExportOptions::POLICIES[ $key ], 'the unattended policy only uses allowed values' );
		}
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_rejected( array $options, string $message ): void {
		try {
			ExportOptions::normalize( $options );
			$this->fail( $message );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function rejected(): array {
		return array(
			'unknown key'         => array( array( 'exclusion' => array() ), 'Unknown export option "exclusion"' ),
			'answers at creation' => array( array( 'answers' => array( 'unreadable' => 'continue' ) ), 'Unknown export option "answers"' ),
			'unknown group'       => array( array( 'contents' => array( 'files' => array( 'media' ) ) ), 'Unknown content group "media"' ),
			'nothing selected'    => array( array( 'contents' => array( 'database' => false, 'files' => array() ) ), 'Nothing to back up' ),
			'database not bool'   => array( array( 'contents' => array( 'database' => 'yes' ) ), '"contents.database"' ),
			'unknown content key' => array( array( 'contents' => array( 'themes' => true ) ), 'Unknown key "themes"' ),
			'empty exclusion'     => array( array( 'exclusions' => array( '  ' ) ), 'non-empty pattern' ),
			'bad exclusion'       => array( array( 'exclusions' => array( "a\x01b" ) ), 'cannot be used' ),
			'bad table name'      => array( array( 'exclude_tables' => array( 'wp posts' ) ), 'must be a table name' ),
			'unknown policy'      => array( array( 'policy' => array( 'links' => 'follow' ) ), 'Unknown policy "links"' ),
			'bad policy value'    => array( array( 'policy' => array( 'large_dirs' => 'exclude' ) ), 'Policy "large_dirs" must be one of: ask, include' ),
			'policy not object'   => array( array( 'policy' => 'ask' ), '"policy" must be an object' ),
		);
	}
}

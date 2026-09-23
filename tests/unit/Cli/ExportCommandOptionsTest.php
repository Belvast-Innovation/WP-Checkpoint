<?php

namespace WPCheckpoint\Tests\Unit\Cli;

use WPCheckpoint\Cli\ExportCommand;
use WPCheckpoint\Jobs\ExportOptions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ExportCommandOptionsTest extends TestCase {

	public function test_no_flags_leave_every_default_and_ask(): void {
		$this->assertSame( array(), ExportCommand::options( array() ) );
		$this->assertSame( ExportOptions::normalize( array() ), ExportOptions::normalize( ExportCommand::options( array() ) ) );
	}

	public function test_flags_map_to_options(): void {
		$options = ExportCommand::options(
			array(
				'database-only' => true,
				'exclude'       => 'cache/*, *.log,,',
				'exclude-table' => 'wp_big',
				'include-table' => ' wp_old_extra ,wp_old_more',
				'yes'           => true,
			)
		);
		$this->assertSame(
			array(
				'contents'       => array( 'files' => array() ),
				'exclusions'     => array( 'cache/*', '*.log' ),
				'exclude_tables' => array( 'wp_big' ),
				'include_tables' => array( 'wp_old_extra', 'wp_old_more' ),
				'policy'         => ExportOptions::UNATTENDED,
			),
			$options
		);
		$this->assertSame( array( 'wp_old_extra', 'wp_old_more' ), ExportOptions::normalize( $options )['include_tables'] );
		$this->assertSame( array( 'contents' => array( 'database' => false ) ), ExportCommand::options( array( 'files-only' => true ) ) );
	}

	public function test_database_only_and_files_only_together_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Choose either --database-only or --files-only, not both.' );
		ExportCommand::options( array( 'database-only' => true, 'files-only' => true ) );
	}
}

<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\ExportPlan;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Small work files that another run may read are replaced whole: the new
 * content is written to another file and renamed over the target, so a
 * reader sees the old content or the new, never a part (a run that outlived
 * its lease writing the file again while the next run reads it). The
 * property is asserted on the writer instead of reproducing the race: a hard
 * link to the old file keeps the old content when the target is replaced by
 * a rename, and shows the new bytes when the target is written in place.
 */
final class AtomicWriteTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-atomic-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	/**
	 * The step's private put().
	 *
	 * @param string $path   Path.
	 * @param string $text   Text.
	 * @param bool   $append Append.
	 * @return void
	 */
	private static function put( string $path, string $text, bool $append = false ): void {
		$put = new \ReflectionMethod( DatabaseExportStep::class, 'put' );
		$put->setAccessible( true );
		$put->invoke( ( new \ReflectionClass( DatabaseExportStep::class ) )->newInstanceWithoutConstructor(), $path, $text, $append );
	}

	/**
	 * A target with old content and a hard link to it.
	 *
	 * @param string $name File name.
	 * @return array{0: string, 1: string} Target and link.
	 */
	private function linked( string $name ): array {
		$target = $this->dir . '/' . $name;
		$alias  = $this->dir . '/alias-' . $name;
		file_put_contents( $target, 'old' );
		if ( ! @link( $target, $alias ) ) {
			$this->markTestSkipped( 'Hard links are not available here.' );
		}
		return array( $target, $alias );
	}

	public function test_the_database_step_replaces_its_small_files_by_rename(): void {
		// The control: an append writes in place, and the link shows it.
		list( $log, $log_alias ) = $this->linked( 'database.index.jsonl' );
		self::put( $log, "+line\n", true );
		$this->assertSame( "old+line\n", file_get_contents( $log_alias ), 'the link observes a write in place' );

		list( $target, $alias ) = $this->linked( 'database.tables.json' );
		self::put( $target, '{"tables":["new"]}' );
		$this->assert_replaced( $target, $alias, '{"tables":["new"]}' );
	}

	public function test_the_plan_files_are_replaced_by_rename(): void {
		// The control, in this test too: a write in place is seen through the link.
		list( $plain, $plain_alias ) = $this->linked( 'control.json' );
		file_put_contents( $plain, 'new' );
		$this->assertSame( 'new', file_get_contents( $plain_alias ), 'the link observes a write in place' );

		list( $target, $alias ) = $this->linked( ExportPlan::PLAN );
		ExportPlan::write( $this->dir, ExportPlan::PLAN, array( 'tables' => array( 'wp_posts' ) ) );
		$this->assert_replaced( $target, $alias, '{"tables":["wp_posts"]}' );
	}

	/**
	 * The target holds the new content in another file than before, the old
	 * file kept its content, and no temporary file is left. A rename and an
	 * unlink-and-recreate both pass the link check; the recreate would leave
	 * the target missing for a moment, which only a rename avoids, so the
	 * temporary file's absence is part of what says it was renamed.
	 *
	 * @param string $target  Target.
	 * @param string $alias   Link to the old file.
	 * @param string $content New content.
	 * @return void
	 */
	private function assert_replaced( string $target, string $alias, string $content ): void {
		clearstatcache();
		$this->assertSame( $content, file_get_contents( $target ) );
		$this->assertSame( 'old', file_get_contents( $alias ), 'the old file was never written: the target was replaced' );
		if ( 0 !== fileinode( $alias ) ) {
			$this->assertNotSame( fileinode( $alias ), fileinode( $target ), 'another file now has the name' );
		}
		$this->assertFileDoesNotExist( $target . '.tmp', 'the temporary file became the target' );
	}
}

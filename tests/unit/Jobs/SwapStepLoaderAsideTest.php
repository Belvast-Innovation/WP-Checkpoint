<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\SwapHost;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Tests\Fixtures\Jobs\WorkContext;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The restore's must-use plugin is taken out of where a swap moved the must-use plugins directory
 * (SwapStep::loader_aside()) when that directory is not a group of its own in the plan but an entry of another
 * group, told by its live directory: the plan has the directory resolved, the process has it as WordPress spells it
 * (WPMU_PLUGIN_DIR, which carries the dot segments of WP-CLI's --path and any link on the way), and the two are the
 * same directory however each is spelled.
 */
final class SwapStepLoaderAsideTest extends TestCase {

	/** @var string */
	private $root = '';

	protected function set_up(): void {
		parent::set_up();
		$this->root = Sandbox::make( 'loader-aside' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->root ) {
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	/**
	 * A plan with the must-use plugins directory as an entry of another group and one more entry, the loader in the
	 * moved-aside and staged place of each; then loader_aside() in a process that spells the directory $spelled.
	 *
	 * @param string $spelled How the process spells the must-use plugins directory, from the sandbox.
	 * @return array{mu: bool[], other: bool[]} Whether the loader is still in the moved-aside and staged place of each.
	 */
	private function aside( string $spelled ): array {
		$dirs = array();
		foreach ( array( 'mu-plugins', 'languages' ) as $name ) {
			$entry = array(
				'live'  => $this->root . '/site/content/' . $name,
				'stage' => $this->root . '/staging/other-content/' . $name,
				'old'   => $this->root . '/staging/old/other-content/' . $name,
			);
			foreach ( $entry as $dir ) {
				mkdir( $dir, 0700, true );
			}
			$this->assertSame( '', Loader::put( $entry['stage'] ) );
			$this->assertSame( '', Loader::put( $entry['old'] ) );
			$dirs[] = $entry;
		}
		$method = new \ReflectionMethod( SwapStep::class, 'loader_aside' );
		$method->setAccessible( true );
		$work = new WorkContext( 'wpc-loader-aside-' );
		try {
			$step = new SwapStep( null, array( 'mu_dir' => $this->root . $spelled ), SwapHost::without_wordpress( 'wp_', false, 0, 'wp-checkpoint/wp-checkpoint.php' ) );
			$method->invoke( $step, $work->context(), $dirs );
		} finally {
			$work->remove();
		}
		clearstatcache();
		return array(
			'mu'    => array( file_exists( Loader::path( $dirs[0]['old'] ) ), file_exists( Loader::path( $dirs[0]['stage'] ) ) ),
			'other' => array( file_exists( Loader::path( $dirs[1]['old'] ) ), file_exists( Loader::path( $dirs[1]['stage'] ) ) ),
		);
	}

	public function test_the_directory_spelled_as_the_plan_has_it(): void {
		$left = $this->aside( '/site/content/mu-plugins' );
		$this->assertSame( array( false, false ), $left['mu'], 'taken out of both places' );
		$this->assertSame( array( true, true ), $left['other'], 'the control: another entry is left alone' );
	}

	public function test_the_directory_spelled_with_dot_segments(): void {
		$left = $this->aside( '/site/./content/languages/../mu-plugins/' );
		$this->assertSame( array( false, false ), $left['mu'] );
		$this->assertSame( array( true, true ), $left['other'], 'the control: another entry is left alone' );
	}

	public function test_the_directory_reached_through_a_link(): void {
		mkdir( $this->root . '/site', 0700 );
		mkdir( $this->root . '/site/content', 0700 );
		if ( ! @symlink( $this->root . '/site/content', $this->root . '/site/wp-content' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- probing.
			$this->markTestSkipped( 'Symbolic links cannot be created here.' );
		}
		$left = $this->aside( '/site/wp-content/mu-plugins' );
		$this->assertSame( array( false, false ), $left['mu'] );
		$this->assertSame( array( true, true ), $left['other'], 'the control: another entry is left alone' );
	}

	public function test_a_directory_that_is_another_one_is_not_taken_for_it(): void {
		mkdir( $this->root . '/elsewhere/mu-plugins', 0700, true );
		$left = $this->aside( '/elsewhere/./mu-plugins' );
		$this->assertSame( array( true, true ), $left['mu'], 'not this process\'s must-use plugins directory' );
		$this->assertSame( array( true, true ), $left['other'] );
	}
}

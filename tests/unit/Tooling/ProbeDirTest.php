<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\ProbeDir;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The probe directory of an integration run, and its removal when the next run takes over the lock of a killed one.
 * A sandbox stands in for wp-content.
 */
final class ProbeDirTest extends TestCase {

	/** @var string */
	private $content = '';

	protected function set_up(): void {
		parent::set_up();
		$this->content = Sandbox::make( 'probe-dir' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->content ) {
			Sandbox::remove( $this->content );
		}
		parent::tear_down();
	}

	private function make( string $name ): string {
		$dir = $this->content . '/' . $name;
		mkdir( $dir );
		file_put_contents( $dir . '/probe.key', str_repeat( 'k', 48 ) );
		return $dir;
	}

	public function test_only_a_run_id_of_its_form_names_a_directory(): void {
		$this->assertTrue( ProbeDir::is_run_id( '0123456789abcdef' ), 'the control: the form' );
		foreach ( array( '0123456789ABCDEF', '0123456789abcde', '0123456789abcdef0', "0123456789abcdef\n", '../456789abcdef', '', null, 1234567890123456 ) as $bad ) {
			$this->assertFalse( ProbeDir::is_run_id( $bad ), var_export( $bad, true ) );
		}
		$this->expectException( \InvalidArgumentException::class );
		ProbeDir::path( $this->content, '../x' );
	}

	public function test_the_next_run_removes_the_probe_directory_a_killed_run_left(): void {
		$stale = $this->make( ProbeDir::PREFIX . '0123456789abcdef' );
		$other = $this->make( ProbeDir::PREFIX . 'fedcba9876543210' ); // Another run's: not named, so not touched.
		$said  = ProbeDir::remove_stale( $this->content, '0123456789abcdef' );
		$this->assertStringContainsString( 'Removed the probe directory ' . $stale, $said );
		$this->assertDirectoryDoesNotExist( $stale );
		$this->assertFileExists( $other . '/probe.key', 'the control: only the named run\'s goes' );
		$this->assertSame( '', ProbeDir::remove_stale( $this->content, '0123456789abcdef' ), 'gone already: nothing to say' );
		$this->assertSame( '', ProbeDir::remove_stale( $this->content, '' ), 'no run named' );
	}

	public function test_a_run_id_not_of_its_form_removes_nothing(): void {
		$upper = $this->make( ProbeDir::PREFIX . '0123456789ABCDEF' );
		$said  = ProbeDir::remove_stale( $this->content, '0123456789ABCDEF' );
		$this->assertStringContainsString( 'does not have its form', $said );
		$this->assertFileExists( $upper . '/probe.key' );
		$this->assertStringContainsString( 'does not have its form', ProbeDir::remove_stale( $this->content, '../' . basename( $this->content ) ) );
		$this->assertDirectoryExists( $this->content, 'nothing built from it' );
	}

	public function test_a_stale_probe_directory_that_is_a_link_goes_without_what_it_leads_to(): void {
		$target = $this->make( 'elsewhere' );
		if ( ! @symlink( $target, $this->content . '/' . ProbeDir::PREFIX . '0123456789abcdef' ) ) {
			$this->markTestSkipped( 'Links cannot be made here.' );
		}
		$this->assertStringContainsString( 'Removed the probe directory', ProbeDir::remove_stale( $this->content, '0123456789abcdef' ) );
		$this->assertFalse( is_link( $this->content . '/' . ProbeDir::PREFIX . '0123456789abcdef' ), 'the link goes' );
		$this->assertFileExists( $target . '/probe.key', 'what it led to stays' );
	}

	public function test_the_integration_bootstrap_removes_the_killed_runs_probe_directory_and_says_so(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 2 ) . '/bootstrap.php' );
		$this->assertStringContainsString( 'wpcheckpoint_bootstrap_integration', $bootstrap, 'the control: the integration part is read' );
		$this->assertSame( 1, preg_match( "/\\\$stale = \\\\WPCheckpoint\\\\Tests\\\\Fixtures\\\\ProbeDir::remove_stale\\( WP_CONTENT_DIR, \\(string\\) getenv\\( 'WPCHECKPOINT_TEST_STALE_RUN_ID' \\) \\);\\s*if \\( '' !== \\\$stale \\) \\{\\s*fwrite\\( STDERR, \\\$stale/", $bootstrap ), 'the run named by the script as it took the lock over loses its probe directory, and the run says so' );
	}
}

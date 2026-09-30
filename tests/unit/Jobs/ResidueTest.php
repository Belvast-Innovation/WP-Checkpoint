<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ResidueTest extends TestCase {

	/** @var string */
	private $base;

	protected function set_up(): void {
		parent::set_up();
		$this->base = sys_get_temp_dir() . '/wpcheckpoint-residue-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base . '/tmp', 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	public function test_work_directory_names_round_trip_and_reject_anything_else(): void {
		$this->assertSame( ExpectedPath::native( $this->base, 'tmp', 'job-42' ), Residue::work_dir( $this->base, 42 ) );
		$this->assertSame( 42, Residue::work_dir_id( 'job-42' ) );
		foreach ( array( 'job-0', 'job-', 'job-42.lock', 'job-4a', 'job--1', 'jobs-42', 'job-042', '' ) as $bad ) {
			$this->assertSame( 0, Residue::work_dir_id( $bad ), $bad );
		}
		$this->assertMatchesRegularExpression( '#/tmp/verify-[0-9a-f]{16}$#', str_replace( '\\', '/', Residue::new_verify_dir( $this->base . '/tmp' ) ) );
	}

	public function test_scan_classifies_everything_under_tmp_and_ignores_the_rest(): void {
		$tmp = $this->base . '/tmp';
		mkdir( $tmp . '/job-7' );
		touch( $tmp . '/job-7/site.part001.wpcheckpoint.zip.partial' );
		mkdir( $tmp . '/job-9' );
		mkdir( $tmp . '/verify-0123456789abcdef' );
		touch( $tmp . '/stray.partial' );
		touch( $tmp . '/stray.cdr' );
		touch( $tmp . '/job-3.lock' );
		touch( $tmp . '/index.php' );
		touch( $tmp . '/.htaccess' );
		touch( $tmp . '/job-11' ); // A file, not a directory.
		mkdir( $tmp . '/other-dir' );
		touch( $tmp . '/.partial' );

		$seen = array();
		foreach ( Residue::scan( $this->base ) as $entry ) {
			$seen[] = $entry['kind'] . ':' . basename( $entry['path'] ) . ':' . $entry['id'];
			$this->assertGreaterThan( 0, $entry['mtime'] );
		}
		sort( $seen );
		$this->assertSame(
			array( 'stray:stray.cdr:0', 'stray:stray.partial:0', 'verify_dir:verify-0123456789abcdef:0', 'work_dir:job-7:7', 'work_dir:job-9:9' ),
			$seen
		);
		$this->assertSame( array(), Residue::scan( $this->base . '/nope' ) );
		$this->assertSame( array(), Residue::scan( '' ) );
	}

	public function test_site_directories_follow_each_groups_own_parent_in_a_split_layout(): void {
		$dirs = Residue::site_dirs(
			array(
				'plugins'       => '/srv/app/extensions/',
				'themes'        => '/srv/site/wp-content/themes',
				'uploads'       => '/srv/media/files',
				'mu-plugins'    => '/srv/app/must-use',
				'other-content' => '/srv/site/wp-content',
			)
		);
		sort( $dirs );
		// Each group's parent (where its staging root goes), the content directory (other-content's entries, and
		// the themes' parent here), and mu-plugins itself (the loader probe): each once.
		$this->assertSame( array( '/srv/app', '/srv/app/must-use', '/srv/media', '/srv/site/wp-content' ), $dirs );
		$windows = Residue::site_dirs( array( 'plugins' => 'C:\\site\\wp-content\\plugins', 'other-content' => 'C:\\site\\wp-content' ) );
		$this->assertSame( array( 'C:/site/wp-content' ), $windows, 'backslashes: one directory, not two spellings' );
	}

	public function test_scan_site_lists_only_the_given_tokens_names_in_every_directory(): void {
		$content = $this->base . '/content';
		$apps    = $this->base . '/apps';
		mkdir( $content );
		mkdir( $apps );
		$random = str_repeat( 'ab', 16 );
		mkdir( $content . '/wp-checkpoint-stage-aaaaaaaaaaaa-7-' . $random );
		mkdir( $apps . '/wp-checkpoint-stage-bbbbbbbbbbbb-8-' . $random );
		touch( $apps . '/wp-checkpoint-probe-aaaaaaaaaaaa-9-0123456789abcdef.php' );
		mkdir( $content . '/wp-checkpoint-stage-ffffffffffff-7-' . $random ); // Another installation's.
		mkdir( $content . '/wp-checkpoint-0123456789ab' ); // A storage directory.
		mkdir( $content . '/plugins' );

		$seen = array();
		foreach ( Residue::scan_site( array( $content, $apps, $content, $this->base . '/missing' ), array( 'aaaaaaaaaaaa', 'bbbbbbbbbbbb', '' ) ) as $entry ) {
			$seen[] = $entry['kind'] . ':' . $entry['token'] . ':' . $entry['id'] . ':' . basename( $entry['parent'] );
			$this->assertSame( ExpectedPath::native( $entry['parent'], basename( $entry['path'] ) ), $entry['path'] );
		}
		sort( $seen );
		$this->assertSame( array( 'probe:aaaaaaaaaaaa:9:apps', 'stage_dir:aaaaaaaaaaaa:7:content', 'stage_dir:bbbbbbbbbbbb:8:apps' ), $seen, 'each once, only the given tokens' );
		$this->assertSame( array(), Residue::scan_site( array( $content, $apps ), array( '' ) ), 'no token: nothing, not everything' );
	}

	/**
	 * @requires OS Linux|Darwin
	 */
	public function test_a_staging_root_that_is_a_link_is_listed_and_removed_as_a_link(): void {
		$content = $this->base . '/content';
		$outside = $this->base . '/outside';
		mkdir( $content );
		mkdir( $outside );
		file_put_contents( $outside . '/keep.txt', 'keep' );
		$link = $content . '/wp-checkpoint-stage-aaaaaaaaaaaa-7-' . str_repeat( 'cd', 16 );
		symlink( $outside, $link );
		// The content directory reached through a link: the same entries.
		symlink( $content, $this->base . '/content-link' );

		$found = Residue::scan_site( array( $this->base . '/content-link' ), array( 'aaaaaaaaaaaa' ) );
		$this->assertCount( 1, $found );
		$result = \WPCheckpoint\Support\Deleter::delete_tree( $found[0]['parent'], $found[0]['path'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertFalse( is_link( $link ) || file_exists( $link ), 'the link is gone' );
		$this->assertFileExists( $outside . '/keep.txt', 'what it pointed to is not followed' );
	}

	public function test_unowned_entries_expire_by_kind(): void {
		$now    = 1000000000;
		$verify = array( 'kind' => Residue::VERIFY_DIR, 'path' => 'x', 'id' => 0, 'mtime' => $now - Residue::VERIFY_TTL + 1 );
		$this->assertFalse( Residue::is_expired( $verify, $now ) );
		$verify['mtime'] = $now - Residue::VERIFY_TTL;
		$this->assertTrue( Residue::is_expired( $verify, $now ) );
		$stray = array( 'kind' => Residue::STRAY, 'path' => 'x', 'id' => 0, 'mtime' => $now - Residue::VERIFY_TTL );
		$this->assertFalse( Residue::is_expired( $stray, $now ), 'stray files wait seven days, not one' );
		$stray['mtime'] = $now - Residue::STRAY_TTL;
		$this->assertTrue( Residue::is_expired( $stray, $now ) );
		$work = array( 'kind' => Residue::WORK_DIR, 'path' => 'x', 'id' => 5, 'mtime' => 0 );
		$this->assertFalse( Residue::is_expired( $work, $now ), 'work directories follow their job, never the clock' );
	}
}

<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The first real step under the engine: bounded units, checkpoints, a
 * crash between checkpoint and tick, cancellation, and the roots as
 * WordPress resolves them.
 */
final class FileScanStepTest extends JobTestCase {

	/** @var float */
	private $now;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $root;

	/** @var string */
	private $site;

	public function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-scanstep-' . bin2hex( random_bytes( 4 ) );
		$this->site = $this->root . '/site';
		mkdir( $this->site . '/wp-content/uploads/2024', 0755, true );
		mkdir( $this->site . '/wp-includes', 0755, true );
		for ( $i = 0; $i < 1500; $i++ ) {
			file_put_contents( sprintf( '%s/wp-content/uploads/2024/f%04d.txt', $this->site, $i ), (string) $i );
		}
		file_put_contents( $this->site . '/wp-content/uploads/top.txt', 'top' );
		$this->dirs  = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->site . '/' ) );
		$this->now   = 1_800_000_000.0;
		$this->repo  = new JobRepository( $this->dirs, null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->types = new JobTypes();
		Schema::ensure();
	}

	public function tear_down(): void {
		Deleter::empty_directory( $this->root );
		@rmdir( $this->root );
		parent::tear_down();
	}

	/**
	 * A runner whose clock advances one second per reading: with a small budget a tick ends after a few units.
	 */
	private function runner( int $seconds ): Runner {
		return new Runner(
			$this->repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'        => function (): float {
					$this->now += 1.0;
					return $this->now;
				},
				'memory'       => static function (): int {
					return 10 * 1048576;
				},
				'budget'       => new Budget( $seconds, 32 * 1048576, false ),
				'memory_limit' => -1,
				'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
			)
		);
	}

	private function step(): FileScanStep {
		$roots = array( array( 'group' => 'uploads', 'path' => $this->site . '/wp-content/uploads', 'prefix' => 'wp-content/uploads' ) );
		return new FileScanStep( $roots, new Exclusions() );
	}

	private function index_lines( Job $job ): array {
		$path = Residue::work_dir( $job->storage_path, $job->id ) . '/files.index.jsonl';
		$this->assertFileExists( $path );
		return array_values( array_filter( explode( "\n", (string) file_get_contents( $path ) ) ) );
	}

	public function test_the_scan_spans_ticks_survives_a_crash_after_the_last_checkpoint_and_ends_with_a_summary(): void {
		$this->types->add( new FixtureJobType( 'scan-only', array( $this->step() ) ) );
		$job = $this->repo->create( 'scan-only' );

		$result = $this->runner( 1 )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::MORE, $result->status, 'a one-second budget on a clock that advances per reading ends the tick after the first unit' );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::RUNNING, $stored->status );
		$this->assertArrayHasKey( 'scan', $stored->cursor );
		$this->assertArrayHasKey( 'bytes', $stored->cursor );
		$this->assertGreaterThan( 0, $stored->cursor['bytes'] );
		$lines = $this->index_lines( $stored );
		$this->assertSame( (int) $stored->cursor['scan']['counts']['files'], count( $lines ), 'the index holds exactly what the cursor says' );
		$this->assertStringNotContainsString( $this->site, wp_json_encode( $stored->cursor ), 'the cursor carries relative paths only' );

		// A unit that ran after the last checkpoint and died: lines beyond the recorded length.
		$path = Residue::work_dir( $stored->storage_path, $stored->id ) . '/files.index.jsonl';
		file_put_contents( $path, "{\"p\":\"wp-content/uploads/ghost.txt\",\"b\":1,\"m\":1}\n", FILE_APPEND );

		$ticks = 1;
		while ( TickResult::MORE === $result->status && $ticks < 200 ) {
			$result = $this->runner( 3 )->tick( $job->id, $this->now );
			++$ticks;
		}
		$this->assertSame( TickResult::COMPLETED, $result->status );
		$this->assertGreaterThanOrEqual( 2, $ticks, 'the scan needed more than one tick' );
		$stored = $this->repo->find( $job->id );
		$lines  = $this->index_lines( $stored );
		$this->assertCount( 1501, $lines, 'every file once; the ghost line was cut off on resume' );
		$this->assertStringNotContainsString( 'ghost', implode( "\n", $lines ) );
		$this->assertSame( count( $lines ), count( array_unique( $lines ) ) );
		$this->assertSame( 'wp-content/uploads/2024/f0000.txt', json_decode( $lines[0], true )['p'] );
		$this->assertSame( 'wp-content/uploads/top.txt', json_decode( $lines[1500], true )['p'] );

		$summary = json_decode( (string) file_get_contents( Residue::work_dir( $stored->storage_path, $stored->id ) . '/' . FileScanStep::SUMMARY ), true );
		$this->assertSame( 1501, $summary['counts']['files'] );
		$this->assertSame( 2, $summary['counts']['directories'] );
		$this->assertSame( array(), $summary['lists']['unreadable'] );
		$this->assertSame( Exclusions::DEFAULTS, $summary['exclusions'] );
		$this->assertArrayHasKey( 'normalization_available', $summary );
		$log = (string) file_get_contents( $this->dirs->base() . '/' . $stored->log_path );
		$this->assertStringContainsString( 'Scan finished', $log );
		$this->assertStringNotContainsString( $this->site, $log );
	}

	public function test_a_content_root_that_is_a_link_is_followed_and_its_target_reaches_the_summary_only_masked(): void {
		// A deployment layout: uploads is a link to a shared directory beside the site.
		$shared = $this->root . '/shared/uploads';
		mkdir( $shared, 0755, true );
		file_put_contents( $shared . '/photo.jpg', 'photo' );
		$link = $this->root . '/linked-uploads';
		$this->assertTrue( symlink( $shared, $link ) );
		$this->assertStringContainsString( $this->root, (string) realpath( $link ), 'the control: where it leads names the server path' );
		$step = new FileScanStep(
			array( array( 'group' => 'uploads', 'path' => $link, 'prefix' => 'wp-content/uploads' ) ),
			new Exclusions(),
			array(),
			\WPCheckpoint\Archive\Manifest::DEFAULT_CHUNK,
			static function ( string $text ): string {
				return \WPCheckpoint\Plugin::instance()->job_presenter()->clean( $text );
			}
		);
		$this->types->add( new FixtureJobType( 'scan-link', array( $step ) ) );
		$job = $this->repo->create( 'scan-link' );
		for ( $i = 0; $i < 20 && TickResult::MORE === $this->runner( 20 )->tick( $job->id, $this->now )->status; $i++ ) {
			$this->now += 1.0;
		}
		$this->assertSame( Job::COMPLETED, $this->repo->find( $job->id )->status );
		$this->assertSame( array( '{"p":"wp-content/uploads/photo.jpg"' ), array_map( static function ( string $line ): string {
			return substr( $line, 0, strpos( $line, ',' ) );
		}, $this->index_lines( $job ) ), 'the files behind the link are listed under the canonical prefix' );
		$summary = json_decode( (string) file_get_contents( Residue::work_dir( $job->storage_path, $job->id ) . '/' . FileScanStep::SUMMARY ), true );
		$warning = implode( "\n", $summary['warnings'] );
		$this->assertStringContainsString( 'The "uploads" content directory is a link; the directory it leads to was backed up (wp-content/uploads -> {tmp}/', $warning, 'the report says so, with the target masked' );
		$this->assertStringEndsWith( '/shared/uploads).', $warning );
		$this->assertStringNotContainsString( $this->root, $warning );
		$this->assertSame( array( 'wp-content/uploads' => \WPCheckpoint\Files\Links::fingerprint( $shared ) ), $summary['root_ids'], 'the summary tells the pack step where the root led' );
		$log = (string) file_get_contents( $this->dirs->base() . '/' . $this->repo->find( $job->id )->log_path );
		$this->assertStringContainsString( 'content directory is a link', $log, 'the control: the job log has the warning' );
		$this->assertStringNotContainsString( $this->root, $log );
	}

	public function test_a_group_that_is_a_link_into_another_group_keeps_its_own_path_and_is_backed_up_once(): void {
		$content = $this->site . '/wp-content';
		mkdir( $content . '/plugins/media/sub', 0755, true );
		mkdir( $content . '/themes/t', 0755, true );
		file_put_contents( $content . '/plugins/p.php', 'p' );
		file_put_contents( $content . '/plugins/media/m.jpg', 'm' );
		file_put_contents( $content . '/plugins/media/sub/s.jpg', 's' );
		file_put_contents( $content . '/themes/t/style.css', 't' );
		// uploads leads into the plugins directory, mu-plugins into a directory inside uploads' target.
		$this->assertTrue( symlink( $content . '/plugins/media', $this->root . '/uploads-link' ) );
		$this->assertTrue( symlink( $content . '/plugins/media/sub', $this->root . '/mu-link' ) );
		$overrides = array(
			'abspath'    => $this->site,
			'content'    => $content,
			'plugins'    => $content . '/plugins',
			'themes'     => $content . '/themes',
			'uploads'    => $this->root . '/uploads-link',
			'mu-plugins' => $this->root . '/mu-link',
		);
		$resolved = ScanRoots::resolve( array( 'plugins', 'themes', 'uploads', 'mu-plugins' ), '', $overrides );
		$this->assertSame( array( 'plugins', 'themes', 'uploads', 'mu-plugins' ), array_column( $resolved['roots'], 'group' ), 'a group that is a link stays a root of its own' );
		$this->assertSame( array(), $resolved['warnings'] );
		list( $lines, $state ) = $this->scan_roots( $resolved['roots'] );
		$this->assertSame(
			array( 'wp-content/plugins/p.php', 'wp-content/themes/t/style.css', 'wp-content/uploads/m.jpg', 'wp-content/mu-plugins/s.jpg' ),
			$lines,
			'each file once, under the path of the group whose link leads to it; neither link is refused for leading where the other leads'
		);
		$this->assertSame( 0, $state['counts']['unreadable'] );

		// The control: without the links, the groups are plain directories and each is its own root with nothing added.
		$plain = ScanRoots::resolve( array( 'plugins', 'themes' ), '', $overrides );
		$this->assertSame( array( array(), array() ), array_column( $plain['roots'], 'also_skip' ) );
	}

	/**
	 * Scan the roots to the end; returns the archive paths and the final state.
	 *
	 * @return array{0: string[], 1: array<string, mixed>}
	 */
	private function scan_roots( array $roots ): array {
		$scanner = new \WPCheckpoint\Files\FileScanner( $roots, new Exclusions(), PHP_INT_SIZE, \WPCheckpoint\Archive\Manifest::DEFAULT_CHUNK, array( 'abspath' => $this->site ) );
		$state   = \WPCheckpoint\Files\FileScanner::initial_state();
		$lines   = array();
		while ( empty( $state['done'] ) ) {
			$state = $scanner->scan_unit(
				$state,
				static function ( array $line ) use ( &$lines ): void {
					$lines[] = $line['p'];
				}
			);
		}
		return array( $lines, $state );
	}

	public function test_nothing_is_merged_into_a_group_that_is_a_link_and_a_link_may_not_lead_over_other_groups(): void {
		$content = $this->site . '/wp-content';
		mkdir( $content . '/plugins/p', 0755, true );
		mkdir( $content . '/themes/t', 0755, true );
		file_put_contents( $content . '/plugins/p/p.php', 'p' );
		file_put_contents( $content . '/themes/t/style.css', 't' );
		file_put_contents( $content . '/index.php', 'i' );
		$all   = array( 'plugins', 'themes', 'uploads', 'other-content' );
		$cases = array(
			// Where uploads leads, which groups are chosen, why it is refused, a file that must be backed up, and the
			// same file a second time through the link.
			'the content directory' => array( $content, $all, 'or to the content directory or a directory that holds it', 'wp-content/plugins/p/p.php', 'wp-content/uploads/plugins/p/p.php' ),
			'the site'              => array( $this->site, $all, 'it leads to the WordPress directory', 'wp-content/themes/t/style.css', 'wp-content/uploads/wp-content/themes/t/style.css' ),
			'a group directory'     => array( $content . '/plugins', $all, 'it leads to the directory of another content group', 'wp-content/plugins/p/p.php', 'wp-content/uploads/p/p.php' ),
			'the content directory, no other group chosen' => array( $content, array( 'uploads', 'other-content' ), 'or to the content directory or a directory that holds it', 'wp-content/index.php', 'wp-content/uploads/index.php' ),
		);
		foreach ( $cases as $case => list( $target, $groups, $reason, $kept, $twice ) ) {
			@unlink( $this->root . '/uploads-link' );
			$this->assertTrue( symlink( $target, $this->root . '/uploads-link' ) );
			$overrides = array(
				'abspath'       => $this->site,
				'content'       => $content,
				'plugins'       => $content . '/plugins',
				'themes'        => $content . '/themes',
				'uploads'       => $this->root . '/uploads-link',
				'other-content' => $content,
			);
			$resolved = ScanRoots::resolve( $groups, '', $overrides );
			$this->assertSame( $groups, array_column( $resolved['roots'], 'group' ), $case . ': nothing is merged into the link' );
			list( $lines, $state ) = $this->scan_roots( $resolved['roots'] );
			$this->assertContains( $kept, $lines, $case . ': the control, the file is backed up under its own path' );
			$this->assertNotContains( $twice, $lines, $case . ': not a second time under the link\'s path' );
			$this->assertSame( array_values( array_unique( $lines ) ), $lines, $case . ': no archive path twice' );
			$this->assertSame( 1, $state['counts']['unreadable'], $case . ': the refused link is a finding the pre-flight asks about' );
			$this->assertStringContainsString( $reason, implode( "\n", $state['warnings'] ), $case );
		}
	}

	public function test_a_group_spelled_inside_a_group_that_is_a_link_is_backed_up_once_with_everything_else(): void {
		// uploads is a link to network storage; mu-plugins is configured inside it (a plain directory there).
		$content = $this->site . '/wp-content';
		$nfs     = $this->root . '/nfs';
		mkdir( $nfs . '/mu', 0755, true );
		file_put_contents( $nfs . '/photo.jpg', 'p' );
		file_put_contents( $nfs . '/mu/m.php', 'm' );
		Deleter::empty_directory( $content . '/uploads' );
		rmdir( $content . '/uploads' );
		$this->assertTrue( symlink( $nfs, $content . '/uploads' ) );
		$overrides = array(
			'abspath'    => $this->site,
			'content'    => $content,
			'uploads'    => $content . '/uploads',
			'mu-plugins' => $content . '/uploads/mu',
		);
		$resolved = ScanRoots::resolve( array( 'uploads', 'mu-plugins' ), '', $overrides );
		$this->assertSame( array( 'wp-content/uploads', 'wp-content/uploads/mu' ), array_column( $resolved['roots'], 'prefix' ) );
		list( $lines, $state ) = $this->scan_roots( $resolved['roots'] );
		sort( $lines );
		$this->assertSame( array( 'wp-content/uploads/mu/m.php', 'wp-content/uploads/photo.jpg' ), $lines, 'each file once, nothing refused' );
		$this->assertSame( 0, $state['counts']['unreadable'] );
	}

	public function test_groups_in_one_directory_are_backed_up_once(): void {
		// uploads is the content directory itself (upload_path set to it), and mu-plugins is the plugins directory.
		$content = $this->site . '/wp-content';
		mkdir( $content . '/plugins/p', 0755, true );
		file_put_contents( $content . '/plugins/p/p.php', 'p' );
		file_put_contents( $content . '/index.php', 'i' );
		$overrides = array(
			'abspath'       => $this->site,
			'content'       => $content,
			'plugins'       => $content . '/plugins',
			'mu-plugins'    => $content . '/plugins',
			'uploads'       => $content,
			'other-content' => $content,
		);
		$resolved = ScanRoots::resolve( array( 'plugins', 'uploads', 'mu-plugins', 'other-content' ), '', $overrides );
		$this->assertSame( array( 'other-content' ), array_column( $resolved['roots'], 'group' ), 'uploads is the content directory, the plugins lie inside it, mu-plugins is the plugins directory' );
		list( $lines ) = $this->scan_roots( $resolved['roots'] );
		$this->assertSame( array_values( array_unique( $lines ) ), $lines, 'no archive path twice' );
		$this->assertContains( 'wp-content/plugins/p/p.php', $lines, 'the control: the plugins are backed up' );
		$this->assertContains( 'wp-content/index.php', $lines );
		$this->assertContains( 'wp-content/uploads/top.txt', $lines );
	}

	public function test_a_directory_at_the_archive_path_of_a_group_kept_elsewhere_is_left_out_with_a_warning(): void {
		// uploads is configured outside the site ("wp-content/uploads" in the archive); an old wp-content/uploads
		// directory is still there and would give the same archive paths.
		$content = $this->site . '/wp-content';
		$elsewhere = $this->root . '/srv-uploads';
		mkdir( $elsewhere . '/2026', 0755, true );
		file_put_contents( $elsewhere . '/2026/new.jpg', 'new' );
		file_put_contents( $content . '/index.php', 'i' );
		$overrides = array(
			'abspath'       => $this->site,
			'content'       => $content,
			'uploads'       => $elsewhere,
			'other-content' => $content,
		);
		$this->assertFileExists( $content . '/uploads/top.txt', 'the control: the old directory has files at the same archive paths' );
		$resolved = ScanRoots::resolve( array( 'uploads', 'other-content' ), '', $overrides );
		$this->assertSame( array( 'wp-content/uploads', 'wp-content' ), array_column( $resolved['roots'], 'prefix' ) );
		$this->assertContains( 'The directory wp-content/uploads in the content directory was not backed up: the "uploads" group, backed up under that path, is in another place on this site.', $resolved['warnings'] );
		list( $lines, $state ) = $this->scan_roots( $resolved['roots'] );
		$this->assertSame( array( 'wp-content/uploads/2026/new.jpg', 'wp-content/index.php' ), $lines, 'each archive path once, from the directory the site uses' );
		$this->assertSame( array( 'wp-content/uploads' ), $state['lists']['unreadable'], 'the old directory is a finding the pre-flight asks about' );

		// The control: with uploads where WordPress keeps it by default, nothing is left out and nothing is said.
		$plain = ScanRoots::resolve( array( 'uploads', 'other-content' ), '', array_merge( $overrides, array( 'uploads' => $content . '/uploads' ) ) );
		$this->assertSame( array(), $plain['warnings'] );
	}

	public function test_a_cancelled_scan_leaves_no_work_directory(): void {
		$this->types->add( new FixtureJobType( 'scan-cancel', array( $this->step() ) ) );
		$job = $this->repo->create( 'scan-cancel' );
		$this->assertSame( TickResult::MORE, $this->runner( 1 )->tick( $job->id, $this->now )->status );
		$this->assertDirectoryExists( Residue::work_dir( $job->storage_path, $job->id ) );
		$stored = $this->repo->find( $job->id );
		$this->repo->transition( $stored, Job::CANCELLED );
		$this->runner( 3 )->cleanup( $this->repo->find( $job->id ) );
		$this->assertDirectoryDoesNotExist( Residue::work_dir( $job->storage_path, $job->id ), 'the engine removes the work directory; the step itself has nothing to clean' );
	}

	public function test_roots_resolve_to_canonical_prefixes_and_skip_the_storage_directory(): void {
		$resolved = ScanRoots::resolve( ScanRoots::GROUPS, $this->dirs->base() );
		$groups   = array_column( $resolved['roots'], 'group' );
		$this->assertContains( 'plugins', $groups );
		$this->assertContains( 'other-content', $groups );
		foreach ( $resolved['roots'] as $root ) {
			$this->assertStringStartsWith( 'wp-content', $root['prefix'], $root['group'] );
			$this->assertStringNotContainsString( '\\', $root['prefix'] );
			$this->assertContains( rtrim( $this->dirs->base(), '/' ), $root['skip'], 'the plugin never backs up its own storage' );
			if ( 'other-content' === $root['group'] ) {
				$this->assertContains( rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ), $root['skip'], 'the other groups are not scanned twice' );
			}
		}
		// A Bedrock-like layout: the content directory outside ABSPATH is presented as wp-content/.
		$this->assertSame( 'wp-content/plugins', ScanRoots::prefix( 'plugins', '/srv/web/app/plugins', '/srv/web/wp', '/srv/web/app' ) );
		$this->assertSame( 'wp-content', ScanRoots::prefix( 'other-content', '/srv/web/app', '/srv/web/wp', '/srv/web/app' ) );
		$this->assertSame( 'wp-content/uploads', ScanRoots::prefix( 'uploads', '/mnt/media', '/srv/web/wp', '/srv/web/app' ), 'outside both: the group name' );
		$this->assertSame( 'wp-content/themes', ScanRoots::prefix( 'themes', '/srv/web/wp/wp-content/themes', '/srv/web/wp', '/srv/web/wp/wp-content' ), 'the standard layout is relative to ABSPATH' );
	}
}

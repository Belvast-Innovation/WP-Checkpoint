<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Support\Report;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use WPCheckpoint\Support\Paths;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The question about content directories that are links to directories outside this site: which targets are
 * outside, the id an answer is bound to, and the lines that show the targets masked yet told apart.
 */
final class LinkedTargetsTest extends TestCase {

	/** @var string */
	private $dir = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'linked-targets' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	public function test_the_id_is_bound_to_the_groups_and_their_targets(): void {
		$one = LinkedTargets::id(
			array(
				'uploads' => '/srv/shared/uploads',
				'themes'  => '/srv/shared/themes',
			)
		);
		$this->assertStringStartsWith( 'linked_targets_', $one );
		$this->assertMatchesRegularExpression( '/\A[a-z0-9_-]{1,64}\z/', $one, 'a question id the job accepts' );
		$this->assertSame(
			$one,
			LinkedTargets::id(
				array(
					'themes'  => '/srv/shared/themes',
					'uploads' => '/srv/shared/uploads',
				)
			),
			'in any order'
		);
		$this->assertNotSame( $one, LinkedTargets::id( array( 'uploads' => '/srv/shared/uploads' ) ), 'a group less' );
		$this->assertNotSame(
			$one,
			LinkedTargets::id(
				array(
					'uploads' => '/srv/other/uploads',
					'themes'  => '/srv/shared/themes',
				)
			),
			'a link pointed elsewhere'
		);
		$this->assertNotSame(
			$one,
			LinkedTargets::id(
				array(
					'plugins' => '/srv/shared/uploads',
					'themes'  => '/srv/shared/themes',
				)
			),
			'another group'
		);
	}

	public function test_a_directory_is_this_sites_only_inside_a_zone_and_below_no_other_installation(): void {
		$site = $this->dir . '/site';
		foreach ( array( '/site/wp-content/uploads', '/site/staging/wp-content/uploads', '/shared/uploads', '/elsewhere', '/site-copy/uploads' ) as $dir ) {
			mkdir( $this->dir . $dir, 0755, true );
		}
		file_put_contents( $site . '/wp-load.php', '<?php' );
		file_put_contents( $this->dir . '/site/staging/wp-config.php', '<?php' ); // A staging installation inside the site.
		$zones = array( $site );
		$this->assertSame( LinkedTargets::SITE, LinkedTargets::judge( $site . '/wp-content/uploads', $zones )['verdict'], 'in the WordPress directory (its own root files are the boundary\'s)' );
		$this->assertSame( LinkedTargets::SITE, LinkedTargets::judge( $site . '/wp-content/missing', $zones )['verdict'], 'not there yet: judged by where it would be' );
		$staging = LinkedTargets::judge( $site . '/staging/wp-content/uploads', $zones );
		$this->assertSame( LinkedTargets::INSTALLATION, $staging['verdict'], 'inside another installation inside the site' );
		$this->assertSame( ExpectedPath::slashed( $site, 'staging' ), $staging['at'] );
		$this->assertSame( LinkedTargets::OUTSIDE, LinkedTargets::judge( $this->dir . '/shared/uploads', $zones )['verdict'], 'outside, whether a link leads there or not' );
		$this->assertSame( LinkedTargets::SITE, LinkedTargets::judge( $this->dir . '/shared/uploads', array( $site, $this->dir . '/shared' ) )['verdict'], 'in the trusted root' );
		$this->assertSame( LinkedTargets::OUTSIDE, LinkedTargets::judge( $this->dir . '/site-copy/uploads', $zones )['verdict'], 'a name that only begins like it' );
		$this->assertSame( LinkedTargets::OUTSIDE, LinkedTargets::judge( $site . '/wp-content/uploads', array( '' ) )['verdict'], 'no zone known: nothing is positively this site\'s' );
		$unknown = LinkedTargets::judge( $this->dir . '/no-such/deeper/uploads', $zones );
		$this->assertSame( LinkedTargets::OUTSIDE === $unknown['verdict'] ? LinkedTargets::OUTSIDE : LinkedTargets::UNKNOWN, $unknown['verdict'], 'not there, its parent not either: asked either way' );
		$this->assertNotSame( LinkedTargets::SITE, $unknown['verdict'] );
	}

	public function test_the_directory_of_wp_config_is_a_zone_unless_it_is_a_file_systems_root_or_a_home_directory(): void {
		$this->assertSame( '/srv/site/web', LinkedTargets::config_zone( '/srv/site/web' ) );
		$this->assertSame( '/home/alice/site', LinkedTargets::config_zone( '/home/alice/site' ) );
		$this->assertSame( '', LinkedTargets::config_zone( '/home/alice' ), 'a home directory' );
		$this->assertSame( '', LinkedTargets::config_zone( '/var/www/vhosts/example.com' ), 'a Plesk home directory' );
		$this->assertSame( '', LinkedTargets::config_zone( '/' ), 'a file system\'s root' );
		$this->assertSame( '', LinkedTargets::config_zone( 'C:/' ) );
		$this->assertSame( '', LinkedTargets::config_zone( '//server/share' ) );
		$this->assertSame( '', LinkedTargets::config_zone( '' ) );
		$this->assertSame(
			'',
			LinkedTargets::config_zone(
				'/tmp/x/home/u',
				static function ( string $dir ): bool {
					return '/tmp/x/home/u' === $dir;
				}
			),
			'a home directory as a test names it'
		);
	}

	public function test_the_wp_config_wordpress_loads_is_found_by_its_own_rule(): void {
		mkdir( $this->dir . '/a/wp', 0755, true );
		file_put_contents( $this->dir . '/a/wp/wp-config.php', '<?php' );
		$this->assertSame( ExpectedPath::slashed( $this->dir, 'a/wp/wp-config.php' ), LinkedTargets::config_file( $this->dir . '/a/wp' ), 'in the WordPress directory' );
		mkdir( $this->dir . '/b/web/wp', 0755, true );
		file_put_contents( $this->dir . '/b/web/wp-config.php', '<?php' );
		$this->assertSame( ExpectedPath::slashed( $this->dir, 'b/web/wp-config.php' ), LinkedTargets::config_file( $this->dir . '/b/web/wp' ), 'one level above (Bedrock)' );
		mkdir( $this->dir . '/c/wp', 0755, true );
		file_put_contents( $this->dir . '/c/wp-config.php', '<?php' );
		file_put_contents( $this->dir . '/c/wp-settings.php', '<?php' );
		$this->assertSame( '', LinkedTargets::config_file( $this->dir . '/c/wp' ), 'not that of another WordPress directory above' );
		$this->assertSame( '', LinkedTargets::config_file( $this->dir . '/nowhere' ) );
	}

	public function test_a_home_directory_by_any_of_its_names_or_one_holding_homes_is_no_zone(): void {
		foreach ( array( '/home2/u', '/home/u', '/home', '/home3', '/Users', '/var/www/vhosts' ) as $dir ) {
			$this->assertSame( '', LinkedTargets::config_zone( $dir ), $dir );
		}
		$this->assertSame( '', LinkedTargets::config_zone( '/data/u', null, '/home/u' ), 'a home reached through a link: by its name as found' );
		$this->assertSame( '/data/u/site', LinkedTargets::config_zone( '/data/u/site', null, '/home/u/site' ), 'the control: a directory in a home is a zone' );
		// The home of the user PHP runs as, and the directories holding it.
		mkdir( $this->dir . '/h/site', 0755, true );
		$home = getenv( 'HOME' );
		putenv( 'HOME=' . $this->dir . '/h' );
		try {
			$this->assertSame( '', LinkedTargets::config_zone( rtrim( Paths::normalize( (string) realpath( $this->dir . '/h' ) ), '/' ) ) );
			$this->assertSame( '', LinkedTargets::config_zone( rtrim( Paths::normalize( (string) realpath( $this->dir ) ), '/' ) ), 'one holding it' );
			$site = rtrim( Paths::normalize( (string) realpath( $this->dir . '/h/site' ) ), '/' );
			$this->assertSame( $site, LinkedTargets::config_zone( $site ), 'the control: a directory in it is a zone' );
		} finally {
			putenv( false === $home ? 'HOME' : 'HOME=' . $home );
		}
	}

	public function test_a_path_that_is_not_absolute_is_not_to_be_told(): void {
		// One that resolves against this process's working directory, into a zone: it would be taken for this site's.
		$cwd = rtrim( Paths::normalize( (string) realpath( (string) getcwd() ) ), '/' );
		$this->assertDirectoryExists( 'src', 'the control: it resolves from here' );
		$this->assertSame( LinkedTargets::SITE, LinkedTargets::judge( $cwd . '/src', array( $cwd ) )['verdict'], 'the control: as an absolute path, this site\'s' );
		$this->assertSame( LinkedTargets::UNKNOWN, LinkedTargets::judge( 'src', array( $cwd ) )['verdict'] );
		$this->assertSame( '', LinkedTargets::resolve( '../uploads' ) );
	}

	public function test_a_directory_on_the_way_that_cannot_be_listed_is_not_to_be_told_and_named(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs a directory this user cannot list (not on Windows, not as root).' );
		}
		mkdir( $this->dir . '/site/wp-content/locked/uploads', 0755, true );
		$locked = $this->dir . '/site/wp-content/locked';
		chmod( $locked, 0311 ); // Searchable, not listable.
		try {
			$this->assertFalse( @scandir( $locked ), 'the control: it cannot be listed' );
			$why = LinkedTargets::judge( $locked . '/uploads', array( rtrim( Paths::normalize( (string) realpath( $this->dir . '/site' ) ), '/' ) ) );
			$this->assertSame( LinkedTargets::UNKNOWN, $why['verdict'] );
			$this->assertSame( rtrim( Paths::normalize( (string) realpath( $locked ) ), '/' ), $why['at'], 'the path that could not be read' );
		} finally {
			chmod( $locked, 0755 );
		}
	}

	public function test_a_directory_with_a_great_many_entries_is_read_in_bounded_memory(): void {
		$flat = $this->dir . '/site/wp-content/uploads';
		mkdir( $flat, 0755, true );
		for ( $i = 0; $i < 30000; $i++ ) {
			touch( $flat . '/f' . $i . '-' . str_repeat( 'x', 40 ) );
		}
		$zone = rtrim( Paths::normalize( (string) realpath( $this->dir . '/site' ) ), '/' );
		$why  = MemoryBudget::within(
			1048576,
			static function () use ( $flat, $zone ): array {
				return LinkedTargets::judge( $flat, array( $zone ) );
			}
		);
		$this->assertSame( LinkedTargets::SITE, $why['verdict'], '30000 entries read within 1 MB' );
	}

	public function test_whether_a_target_is_in_this_sites_home_directory(): void {
		$this->assertSame( '/home/alice', LinkedTargets::home( '/home/alice/public_html' ) );
		$this->assertSame( 'same', LinkedTargets::relation( '/home/alice/shared/uploads', '/home/alice/public_html' ) );
		$this->assertSame( 'other', LinkedTargets::relation( '/home/bob/public_html/wp-content/uploads', '/home/alice/public_html' ) );
		$this->assertSame( 'other', LinkedTargets::relation( '/mnt/data/uploads', '/home/alice/public_html' ), 'in no home directory' );
		$this->assertSame( 'unknown', LinkedTargets::relation( '/home/bob/uploads', '/var/www/html' ), 'this site is in none: nothing to say' );
		$this->assertSame( 'same', LinkedTargets::relation( 'C:\\Users\\Alice\\shared', 'C:/Users/alice/site' ), 'Windows, in any case' );
	}

	public function test_targets_that_read_the_same_once_masked_are_told_apart_without_whose_home_they_are_in(): void {
		$entries = array(
			array(
				'group'    => 'uploads',
				'target'   => '/home/alice/shared/uploads',
				'relation' => LinkedTargets::relation( '/home/alice/shared/uploads', '/home/carol/site' ),
			),
			array(
				'group'    => 'themes',
				'target'   => '/home/bob/shared/uploads',
				'relation' => LinkedTargets::relation( '/home/bob/shared/uploads', '/home/carol/site' ),
			),
			array(
				'group'    => 'plugins',
				'target'   => '/home/carol/shared/plugins',
				'relation' => LinkedTargets::relation( '/home/carol/shared/plugins', '/home/carol/site' ),
			),
		);
		// The control: the targets as found name both accounts.
		$raw = implode( "\n", array_column( $entries, 'target' ) );
		$this->assertStringContainsString( 'alice', $raw );
		$this->assertStringContainsString( 'bob', $raw );
		$clean = static function ( string $text ): string {
			return (string) Report::mask_paths( $text, array() );
		};
		$lines = LinkedTargets::lines( $entries, $clean );
		$this->assertSame(
			array(
				'uploads: /home/***/shared/uploads (target 1) (outside this site\'s directories) (not in this site\'s home directory)',
				'themes: /home/***/shared/uploads (target 2) (outside this site\'s directories) (not in this site\'s home directory)',
				'plugins: /home/***/shared/plugins (outside this site\'s directories) (in this site\'s home directory)',
			),
			$lines
		);
		// Why, for the other two verdicts: the directory on the way that is another installation's, or the path that
		// could not be read; masked too.
		$why = LinkedTargets::lines(
			array(
				array(
					'group'    => 'uploads',
					'target'   => '/home/alice/staging/wp-content/uploads',
					'relation' => 'other',
					'verdict'  => LinkedTargets::INSTALLATION,
					'at'       => '/home/alice/staging',
				),
				array(
					'group'    => 'themes',
					'target'   => '/home/bob/themes',
					'relation' => 'other',
					'verdict'  => LinkedTargets::UNKNOWN,
					'at'       => '/home/bob/themes',
				),
			),
			$clean
		);
		$this->assertSame( 'uploads: /home/***/staging/wp-content/uploads (inside another WordPress installation, at /home/***/staging) (not in this site\'s home directory)', $why[0] );
		$this->assertSame( 'themes: /home/***/themes (whether it is this site\'s could not be told: /home/***/themes could not be read) (not in this site\'s home directory)', $why[1] );
		$text = implode( "\n", $lines );
		$this->assertStringNotContainsString( 'alice', $text );
		$this->assertStringNotContainsString( 'bob', $text );
		$this->assertStringNotContainsString( 'carol', $text );
	}
}

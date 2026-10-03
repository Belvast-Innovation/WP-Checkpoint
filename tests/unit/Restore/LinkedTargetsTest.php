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

	/**
	 * @return array{target: string, verdict: string, at: string}
	 */
	private static function why( string $target, string $verdict = LinkedTargets::OUTSIDE, string $at = '' ): array {
		return array(
			'target'  => $target,
			'verdict' => $verdict,
			'at'      => $at,
		);
	}

	public function test_the_id_is_bound_to_the_groups_their_directories_and_why(): void {
		$one = LinkedTargets::id(
			array(
				'uploads' => self::why( '/srv/shared/uploads' ),
				'themes'  => self::why( '/srv/shared/themes' ),
			)
		);
		$this->assertStringStartsWith( 'linked_targets_', $one );
		$this->assertMatchesRegularExpression( '/\A[a-z0-9_-]{1,64}\z/', $one, 'a question id the job accepts' );
		$this->assertSame(
			$one,
			LinkedTargets::id(
				array(
					'themes'  => self::why( '/srv/shared/themes' ),
					'uploads' => self::why( '/srv/shared/uploads' ),
				)
			),
			'in any order'
		);
		$this->assertNotSame( $one, LinkedTargets::id( array( 'uploads' => self::why( '/srv/shared/uploads' ) ) ), 'a group less' );
		$this->assertNotSame( $one, LinkedTargets::id( array( 'uploads' => self::why( '/srv/other/uploads' ), 'themes' => self::why( '/srv/shared/themes' ) ) ), 'another directory' );
		$this->assertNotSame( $one, LinkedTargets::id( array( 'plugins' => self::why( '/srv/shared/uploads' ), 'themes' => self::why( '/srv/shared/themes' ) ) ), 'another group' );
		// Another reason is another question; for another installation, so is another root of it.
		$in_a = LinkedTargets::id( array( 'uploads' => self::why( '/srv/shared/uploads', LinkedTargets::INSTALLATION, '/srv/shared' ), 'themes' => self::why( '/srv/shared/themes' ) ) );
		$this->assertNotSame( $one, $in_a, 'outside before, inside another installation now' );
		$this->assertNotSame( $in_a, LinkedTargets::id( array( 'uploads' => self::why( '/srv/shared/uploads', LinkedTargets::INSTALLATION, '/srv' ), 'themes' => self::why( '/srv/shared/themes' ) ) ), 'another installation\'s root' );
		$this->assertNotSame( $one, LinkedTargets::id( array( 'uploads' => self::why( '/srv/shared/uploads', LinkedTargets::UNKNOWN, '/srv/shared/uploads' ), 'themes' => self::why( '/srv/shared/themes' ) ) ), 'not to be told now' );
	}

	public function test_an_installations_root_by_its_files_or_by_its_front_controller_and_core_beside_it(): void {
		$d = $this->dir;
		mkdir( "{$d}/plain/uploads", 0755, true );
		mkdir( "{$d}/loads", 0755, true );
		file_put_contents( "{$d}/loads/wp-load.php", '<?php' );
		mkdir( "{$d}/own/wp", 0755, true ); // WordPress in its own directory.
		file_put_contents( "{$d}/own/index.php", "<?php\ndefine( 'WP_USE_THEMES', true );\nrequire __DIR__ . '/wp/wp-blog-header.php';\n" );
		file_put_contents( "{$d}/own/wp/wp-load.php", '<?php' );
		mkdir( "{$d}/content/staging", 0755, true ); // A wp-content holding a staging site: not a root itself.
		file_put_contents( "{$d}/content/index.php", "<?php\n// Silence is golden.\n" );
		file_put_contents( "{$d}/content/staging/wp-load.php", '<?php' );
		mkdir( "{$d}/front-alone", 0755, true );
		file_put_contents( "{$d}/front-alone/index.php", "<?php require __DIR__ . '/wp-blog-header.php';" );
		$this->assertFalse( LinkedTargets::root_state( "{$d}/plain" ) );
		$this->assertTrue( LinkedTargets::root_state( "{$d}/loads" ) );
		$this->assertTrue( LinkedTargets::root_state( "{$d}/own" ), 'a front controller and the core beside it' );
		$this->assertFalse( LinkedTargets::root_state( "{$d}/content" ), 'an index.php that is no front controller' );
		$this->assertTrue( LinkedTargets::root_state( "{$d}/content/staging" ), 'the control: the staging site is one' );
		$this->assertFalse( LinkedTargets::root_state( "{$d}/front-alone" ), 'a front controller without a core beside it' );
		// More subdirectories beside a front controller than are looked into: cannot be told.
		mkdir( "{$d}/crowded", 0755, true );
		file_put_contents( "{$d}/crowded/index.php", "<?php require __DIR__ . '/wp/wp-blog-header.php';" );
		for ( $i = 0; $i <= LinkedTargets::CHILDREN_LIMIT; $i++ ) {
			mkdir( "{$d}/crowded/d{$i}" );
		}
		$this->assertNull( LinkedTargets::root_state( "{$d}/crowded" ) );
		$this->assertNull( LinkedTargets::root_state( "{$d}/no-such" ), 'not there to be listed' );
	}

	public function test_the_zone_of_wp_config_stands_only_without_another_installation_beside_this_site(): void {
		$d = $this->dir;
		// Bedrock: web holds wp-config.php, the WordPress directory (this site's branch) and the content directory.
		mkdir( "{$d}/web/wp", 0755, true );
		mkdir( "{$d}/web/app/uploads", 0755, true );
		file_put_contents( "{$d}/web/wp-config.php", '<?php' );
		file_put_contents( "{$d}/web/index.php", "<?php require __DIR__ . '/wp/wp-blog-header.php';" );
		file_put_contents( "{$d}/web/wp/wp-load.php", '<?php' );
		$this->assertTrue( LinkedTargets::zone_stands( "{$d}/web", "{$d}/web/wp" ) );
		// Hardened: wp-config.php one level above the WordPress directory, beside other sites and a shared directory.
		mkdir( "{$d}/www/html", 0755, true );
		mkdir( "{$d}/www/shared-media/uploads", 0755, true );
		file_put_contents( "{$d}/www/wp-config.php", '<?php' );
		file_put_contents( "{$d}/www/html/wp-load.php", '<?php' );
		$this->assertTrue( LinkedTargets::zone_stands( "{$d}/www", "{$d}/www/html" ), 'the control: only a shared directory beside it' );
		mkdir( "{$d}/www/other", 0755, true );
		file_put_contents( "{$d}/www/other/wp-load.php", '<?php' );
		$this->assertFalse( LinkedTargets::zone_stands( "{$d}/www", "{$d}/www/html" ), 'another site beside this one' );
		// Too many entries to read: it does not stand.
		mkdir( "{$d}/wide/html", 0755, true );
		for ( $i = 0; $i <= LinkedTargets::CHILDREN_LIMIT; $i++ ) {
			touch( "{$d}/wide/f{$i}" );
		}
		$this->assertFalse( LinkedTargets::zone_stands( "{$d}/wide", "{$d}/wide/html" ) );
		$this->assertFalse( LinkedTargets::zone_stands( "{$d}/no-such", "{$d}/no-such/html" ), 'not to be listed' );
	}

	public function test_broad_directories_that_hold_sites_are_no_zone(): void {
		foreach ( array( '/var/www', '/srv/www', '/srv', '/data', '/opt', '/usr/local/www', 'C:/inetpub' ) as $dir ) {
			$this->assertSame( '', LinkedTargets::config_zone( $dir ), $dir );
		}
		$this->assertSame( '/var/www/example', LinkedTargets::config_zone( '/var/www/example' ), 'the control: one site\'s directory is' );
		$this->assertSame( '/srv/bedrock/web', LinkedTargets::config_zone( '/srv/bedrock/web' ), 'and Bedrock\'s web' );
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

	public function test_a_directory_that_cannot_be_looked_into_is_not_to_be_told_and_named_one_that_cannot_be_listed_is_judged_by_name(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs directories this user cannot search or list (not on Windows, not as root).' );
		}
		mkdir( $this->dir . '/site/wp-content/unlisted/uploads', 0755, true );
		mkdir( $this->dir . '/site/wp-content/closed', 0755, true );
		$site     = rtrim( Paths::normalize( (string) realpath( $this->dir . '/site' ) ), '/' );
		$unlisted = $this->dir . '/site/wp-content/unlisted';
		$closed   = $this->dir . '/site/wp-content/closed';
		chmod( $unlisted, 0311 ); // Searchable, not listable: its root files are looked up by name.
		chmod( $closed, 0600 );   // Listable, not searchable: nothing in it can be looked at.
		try {
			$this->assertFalse( @scandir( $unlisted ), 'the control: it cannot be listed' );
			$this->assertSame( LinkedTargets::SITE, LinkedTargets::judge( $unlisted . '/uploads', array( $site ) )['verdict'], 'no root file in it, by name' );
			$why = LinkedTargets::judge( $closed, array( $site ) );
			$this->assertSame( LinkedTargets::UNKNOWN, $why['verdict'] );
			$this->assertSame( rtrim( Paths::normalize( (string) realpath( $closed ) ), '/' ), $why['at'], 'the path that could not be read' );
		} finally {
			chmod( $unlisted, 0755 );
			chmod( $closed, 0755 );
		}
	}

	public function test_an_index_php_that_cannot_be_read_beside_a_core_is_not_to_be_told(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs a file this user cannot read (not on Windows, not as root).' );
		}
		mkdir( $this->dir . '/own/wp', 0755, true );
		file_put_contents( $this->dir . '/own/wp/wp-load.php', '<?php' );
		file_put_contents( $this->dir . '/own/index.php', "<?php require __DIR__ . '/wp/wp-blog-header.php';" );
		$this->assertTrue( LinkedTargets::root_state( $this->dir . '/own' ), 'the control: readable, a root' );
		chmod( $this->dir . '/own/index.php', 0000 );
		try {
			$this->assertNull( LinkedTargets::root_state( $this->dir . '/own' ) );
		} finally {
			chmod( $this->dir . '/own/index.php', 0644 );
		}
		mkdir( $this->dir . '/odd/index.php', 0755, true ); // A directory by that name.
		mkdir( $this->dir . '/odd/wp', 0755, true );
		file_put_contents( $this->dir . '/odd/wp/wp-load.php', '<?php' );
		$this->assertFalse( LinkedTargets::root_state( $this->dir . '/odd' ), 'a directory named index.php is no front controller' );
	}

	public function test_a_link_in_the_zone_to_another_installation_does_not_undo_the_zone(): void {
		$d = $this->dir;
		mkdir( "{$d}/web/wp", 0755, true );
		file_put_contents( "{$d}/web/wp-config.php", '<?php' );
		mkdir( "{$d}/elsewhere/other", 0755, true );
		file_put_contents( "{$d}/elsewhere/other/wp-load.php", '<?php' );
		symlink( "{$d}/elsewhere/other", "{$d}/web/linked" ); // What is reached through it is judged where it is.
		$this->assertTrue( LinkedTargets::zone_stands( "{$d}/web", "{$d}/web/wp" ) );
		mkdir( "{$d}/web/other", 0755, true );
		file_put_contents( "{$d}/web/other/wp-load.php", '<?php' );
		$this->assertFalse( LinkedTargets::zone_stands( "{$d}/web", "{$d}/web/wp" ), 'the control: the same installation as a directory of the zone' );
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

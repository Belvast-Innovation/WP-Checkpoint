<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Where a restore stages each backup path, and the names of its staging
 * roots and probes.
 */
final class StagingLayoutTest extends TestCase {

	const TOKEN  = 'a1b2c3d4e5f6';
	const RANDOM = '0123456789abcdef0123456789abcdef';

	private static function standard(): StagingLayout {
		return new StagingLayout(
			array(
				'plugins'       => '/srv/wp/wp-content/plugins',
				'themes'        => '/srv/wp/wp-content/themes',
				'uploads'       => '/srv/wp/wp-content/uploads',
				'mu-plugins'    => '/srv/wp/wp-content/mu-plugins',
				'other-content' => '/srv/wp/wp-content',
			),
			self::TOKEN,
			7,
			self::RANDOM
		);
	}

	public function test_backup_paths_go_to_this_sites_group_directories(): void {
		$layout = self::standard();
		$root   = '/srv/wp/wp-content/wp-checkpoint-stage-' . self::TOKEN . '-7-' . self::RANDOM;
		$this->assertSame(
			array(
				'group'    => 'plugins',
				'relative' => 'akismet/akismet.php',
				'target'   => '/srv/wp/wp-content/plugins/akismet/akismet.php',
				'staged'   => ExpectedPath::slashed( $root, 'plugins/akismet/akismet.php' ),
			),
			$layout->map( 'wp-content/plugins/akismet/akismet.php' )
		);
		$this->assertSame( 'uploads', $layout->map( 'wp-content/uploads/2026/09/a.jpg' )['group'] );
		$this->assertSame( 'mu-plugins', $layout->map( 'wp-content/mu-plugins/x.php' )['group'] );
		$this->assertSame(
			array(
				'group'    => 'other-content',
				'relative' => 'languages/de_DE.mo',
				'target'   => '/srv/wp/wp-content/languages/de_DE.mo',
				'staged'   => ExpectedPath::slashed( $root, 'other-content/languages/de_DE.mo' ),
			),
			$layout->map( 'wp-content/languages/de_DE.mo' )
		);
		$this->assertSame( 'other-content', $layout->map( 'wp-content/object-cache.php' )['group'], 'a file directly in the content directory' );
	}

	public function test_a_path_that_belongs_to_no_group_maps_to_nothing(): void {
		$layout = self::standard();
		foreach ( array(
			'wp-config.php'                            => 'outside the content directory',
			'files/a.txt'                              => 'a group kept outside it, under another name',
			'wp-content'                               => 'the content directory itself',
			'wp-content/plugins'                       => 'a file named like a group directory',
			'wp-content/wp-checkpoint-stage-x/a.txt'   => 'named like this plugin\'s own directories',
			'wp-content/wp-checkpoint-0123456789ab/x'  => 'a storage directory',
		) as $path => $why ) {
			$this->assertNull( $layout->map( $path ), $why );
		}
	}

	public function test_an_entry_on_above_or_inside_a_group_directory_is_not_other_content(): void {
		// This site keeps its uploads in the content directory under another name, and themes one level down.
		$layout = new StagingLayout(
			array(
				'plugins'       => '/srv/wp/wp-content/plugins',
				'themes'        => '/srv/wp/wp-content/site/themes',
				'uploads'       => '/srv/wp/wp-content/files',
				'mu-plugins'    => '/srv/wp/wp-content/mu-plugins',
				'other-content' => '/srv/wp/wp-content',
			),
			self::TOKEN,
			7,
			self::RANDOM
		);
		$this->assertNull( $layout->map( 'wp-content/files/a.txt' ), 'on the uploads directory' );
		$this->assertNull( $layout->map( 'wp-content/Files/a.txt' ), 'the same without case' );
		$this->assertNull( $layout->map( 'wp-content/site/other.txt' ), 'above the themes directory' );
		$this->assertSame( 'other-content', $layout->map( 'wp-content/languages/a.mo' )['group'], 'the control: another entry' );
		$this->assertSame( '/srv/wp/wp-content/files/2026/a.jpg', $layout->map( 'wp-content/uploads/2026/a.jpg' )['target'], 'the uploads group goes to this site\'s directory' );
	}

	public function test_a_name_another_name_reaches_on_some_file_system_is_not_other_content(): void {
		$layout = new StagingLayout(
			array(
				'plugins'       => '/srv/wp/wp-content/plugins',
				'themes'        => '/srv/wp/wp-content/themes',
				'uploads'       => '/srv/wp/wp-content/uploads',
				'mu-plugins'    => '/srv/wp/wp-content/mu-plugins',
				'other-content' => '/srv/wp/wp-content',
			),
			self::TOKEN,
			7,
			self::RANDOM,
			array( '/srv/wp/wp-content/backups-store\\' )
		);
		foreach ( array(
			'wp-content/plugins./a.php'                     => 'a trailing dot: Windows opens plugins',
			'wp-content/plugins /a.php'                     => 'a trailing space: the same',
			'wp-content/uploads::$DATA/a'                   => 'an NTFS stream of uploads',
			'wp-content/PLUGIN~1/a.php'                     => 'an 8.3 short name',
			'wp-content/WP-CHECKPOINT-stage-x/a.txt'        => 'this plugin\'s prefix, in capitals',
			'wp-content/backups-store/a.zip'                => 'the reserved (storage) directory',
			'wp-content/Backups-Store/a.zip'                => 'the same without case',
			'wp-content/mu-plugins/'                        => 'no name inside the group',
		) as $path => $why ) {
			$this->assertNull( $layout->map( $path ), $why );
		}
		foreach ( array( 'wp-content/languages/a.mo', 'wp-content/backups-store-old/a.zip', 'wp-content/.htaccess', 'wp-content/cache.d/a' ) as $path ) {
			$this->assertSame( 'other-content', $layout->map( $path )['group'], 'the control: ' . $path );
		}
	}

	/**
	 * The standard layout, reading a backup that calls its content directory $prefix, with $off left out.
	 *
	 * @param string   $prefix Prefix.
	 * @param string[] $off    Entries left out.
	 */
	private static function reading( string $prefix, array $off = array() ): StagingLayout {
		return new StagingLayout(
			array(
				'plugins'       => '/srv/wp/wp-content/plugins',
				'themes'        => '/srv/wp/wp-content/themes',
				'uploads'       => '/srv/wp/wp-content/uploads',
				'mu-plugins'    => '/srv/wp/wp-content/mu-plugins',
				'other-content' => '/srv/wp/wp-content',
			),
			self::TOKEN,
			7,
			self::RANDOM,
			array(),
			$prefix,
			$off
		);
	}

	public function test_the_backups_own_name_of_the_content_directory_is_read_from_two_canonical_paths_only(): void {
		// The control: what a site with its content directory in the WordPress directory under another name records.
		$this->assertSame( 'app', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/app' ) );
		$this->assertSame( 'app', StagingLayout::content_prefix( '/var/www/html', '/var/www/html/app/' ), 'one trailing slash on either' );
		$this->assertSame( 'site/content', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/site/content' ) );
		$this->assertSame( 'app', StagingLayout::content_prefix( 'C:/inetpub/wwwroot/', 'C:/inetpub/wwwroot/app' ) );
		$this->assertSame( 'app', StagingLayout::content_prefix( '/', '/app' ), 'the WordPress directory at the top of the disk' );
		// Nothing to read differently.
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/wp-content' ), 'the usual name' );
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/wp-content/uploads' ), 'a name that starts with the usual one: a path under it would be read into another group than it is spelled in' );
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/wp-content/a/b' ) );
		$this->assertSame( 'wp-content-2', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/wp-content-2' ), 'the control: a name that only starts alike' );
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/', '/srv/content' ), 'outside the WordPress directory: the export wrote wp-content/ itself' );
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html' ), 'the WordPress directory itself' );
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/app', '/var/www/html/' ), 'the other way round' );
		$this->assertSame( '', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html-other/app' ), 'a name that only starts alike' );
		// Not canonical: not used, whatever it would resolve to.
		$bad = array(
			'a dot-dot segment in the WordPress directory'    => array( '/var/www/other/../html/', '/var/www/html/app' ),
			'a dot-dot segment in the content directory'      => array( '/var/www/html/', '/var/www/html/x/../app' ),
			'a dot-dot segment that leaves the directory'     => array( '/var/www/html/', '/var/www/html/../../etc' ),
			'a dot segment'                                   => array( '/var/www/html/', '/var/www/html/./app' ),
			'a trailing dot-dot'                              => array( '/var/www/html/', '/var/www/html/app/..' ),
			'an empty segment'                                => array( '/var/www/html/', '/var/www/html//app' ),
			'two trailing slashes'                            => array( '/var/www/html//', '/var/www/html/app' ),
			'a backslash'                                     => array( 'C:\\inetpub\\wwwroot\\', 'C:\\inetpub\\wwwroot\\app' ),
			'a backslash in a segment'                        => array( '/var/www/html/', '/var/www/html/a\\b' ),
			'a relative WordPress directory'                  => array( 'var/www/html/', 'var/www/html/app' ),
			'a relative content directory'                    => array( '/var/www/html/', 'app' ),
			'a drive without a path'                          => array( 'C:', 'C:/app' ),
			'another drive'                                   => array( 'C:/www/', 'D:/www/app' ),
			'a network share'                                 => array( '//server/share/', '//server/share/app' ),
			'a line break'                                    => array( "/var/www/html/", "/var/www/html/ap\np" ),
			'a NUL byte'                                      => array( "/var/www/html/", "/var/www/html/app\0" ),
			'an empty WordPress directory'                    => array( '', '/var/www/html/app' ),
			'an empty content directory'                      => array( '/var/www/html/', '' ),
		);
		foreach ( $bad as $what => $pair ) {
			$this->assertSame( '', StagingLayout::content_prefix( $pair[0], $pair[1] ), $what );
		}
	}

	public function test_the_entry_a_manifest_places_the_content_directory_under_is_named(): void {
		$this->assertSame( 'app', StagingLayout::content_apart( '/var/www/html/', '/var/www/html/wp-content/app' ) );
		$this->assertSame( 'app', StagingLayout::content_apart( '/var/www/html', '/var/www/html/wp-content/app/content/' ), 'the entry of wp-content, however deep the directory is' );
		$this->assertSame( 'uploads', StagingLayout::content_apart( 'C:/www/', 'C:/www/wp-content/uploads' ) );
		$this->assertSame( '', StagingLayout::content_apart( '/var/www/html/', '/var/www/html/wp-content' ), 'the usual place' );
		$this->assertSame( '', StagingLayout::content_apart( '/var/www/html/', '/var/www/html/wp-content/' ) );
		$this->assertSame( '', StagingLayout::content_apart( '/var/www/html/', '/var/www/html/app' ), 'a name of its own: content_prefix() reads it' );
		$this->assertSame( '', StagingLayout::content_apart( '/var/www/html/', '/srv/wp-content/app' ), 'not in the WordPress directory' );
		$this->assertSame( '', StagingLayout::content_apart( '/var/www/html/', '/var/www/html/wp-content/../app' ), 'not canonical' );
		$this->assertSame( '', StagingLayout::content_apart( '/var/www/html/', '/var/www/html/x/wp-content/app' ), 'wp-content further in is a name like any' );
		$this->assertSame( 'x/wp-content/app', StagingLayout::content_prefix( '/var/www/html/', '/var/www/html/x/wp-content/app' ), 'the control: that one is read as a name of its own' );
		// With the entry left out, a path in it has no group; the others are as they were.
		$layout = self::reading( '', array( 'app' ) );
		$this->assertNull( $layout->map( 'wp-content/app/plugins/demo/demo.php' ) );
		$this->assertSame( 'plugins', $layout->map( 'wp-content/plugins/demo/demo.php' )['group'] );
		$this->assertSame( 'other-content', self::reading( '' )->map( 'wp-content/app/plugins/demo/demo.php' )['group'], 'the control: not left out, it is other content' );
	}

	public function test_a_backup_path_under_its_own_name_of_the_content_directory_is_read_once(): void {
		$layout = self::reading( 'app' );
		$this->assertSame( 'wp-content/plugins/demo/demo.php', $layout->canonical( 'app/plugins/demo/demo.php' ) );
		$this->assertSame( 'plugins', $layout->map( 'app/plugins/demo/demo.php' )['group'] );
		$this->assertSame( '/srv/wp/wp-content/plugins/demo/demo.php', $layout->map( 'app/plugins/demo/demo.php' )['target'] );
		$this->assertSame( 'other-content', $layout->map( 'app/languages/de_DE.mo' )['group'] );
		$this->assertSame( 'other-content', $layout->map( 'app/object-cache.php' )['group'] );
		$this->assertNull( $layout->map( 'application/plugins/demo.php' ), 'a name that only starts alike is outside the content directory' );
		$this->assertNull( $layout->map( 'app' ), 'the name alone is no path in it' );
		$this->assertSame( 'plugins', $layout->map( 'wp-content/plugins/demo/demo.php' )['group'], 'a path already under wp-content/ is read as it is' );
		// Once: only the start of the path is the name; the same name further in is the path's own.
		$this->assertSame( 'wp-content/app/x.php', $layout->canonical( 'app/app/x.php' ) );
		$this->assertSame( 'other-content', $layout->map( 'app/app/x.php' )['group'] );
		$this->assertSame( 'app/x.php', $layout->map( 'app/app/x.php' )['relative'], 'read once, not twice' );
		// Which paths were read differently than written.
		$this->assertTrue( $layout->reread( 'app/plugins/demo/demo.php' ) );
		$this->assertFalse( $layout->reread( 'wp-content/plugins/demo/demo.php' ) );
		$this->assertFalse( $layout->reread( 'application/plugins/demo.php' ) );
		$this->assertFalse( self::reading( '' )->reread( 'app/plugins/demo/demo.php' ) );
		// Without a name of its own nothing is read differently.
		$this->assertNull( self::reading( '' )->map( 'app/plugins/demo/demo.php' ) );
		$this->assertSame( 'app/plugins/demo/demo.php', self::reading( '' )->canonical( 'app/plugins/demo/demo.php' ) );
	}

	public function test_a_prefix_that_is_not_a_relative_path_is_refused(): void {
		foreach ( array( 'wp-content', 'wp-content/app', 'wp-content/a/b', '../x', 'a/../b', 'a//b', '/a', 'a/', 'a\\b', './a' ) as $prefix ) {
			try {
				self::reading( $prefix );
				$this->fail( 'accepted ' . $prefix );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Not a content directory prefix.', $e->getMessage(), $prefix );
			}
		}
		$this->assertSame( 'wp-content/x', self::reading( 'a/b' )->canonical( 'a/b/x' ), 'the control: a relative path of two segments' );
		$this->assertSame( 'wp-content/x', self::reading( 'wp-content-2' )->canonical( 'wp-content-2/x' ), 'the control: a name that only starts like wp-content' );
	}

	public function test_an_entry_left_out_for_where_it_is_maps_to_nothing(): void {
		$layout = self::reading( '', array( 'mu-link' ) );
		$this->assertNull( $layout->map( 'wp-content/mu-link/a.php' ) );
		$this->assertNull( $layout->map( 'wp-content/mu-link' ), 'the entry itself, as a file' );
		$this->assertSame( 'other-content', $layout->map( 'wp-content/mu-link-2/a.php' )['group'], 'the control: another entry' );
		$this->assertSame( 'other-content', $layout->map( 'wp-content/languages/mu-link/a.php' )['group'], 'the name deeper down is not the entry' );
		$this->assertSame( 'other-content', self::standard()->map( 'wp-content/mu-link/a.php' )['group'], 'the control: not left out, it is other content' );
		$layout->leave_out( 'languages' );
		$this->assertNull( $layout->map( 'wp-content/languages/de_DE.mo' ), 'left out from here on' );
		$this->assertSame( 'plugins', $layout->map( 'wp-content/plugins/languages/x.php' )['group'], 'a group directory is not an entry of other content' );
	}

	public function test_each_parent_holds_one_staging_root(): void {
		$layout = new StagingLayout(
			array(
				'plugins'       => '/srv/wp/wp-content/plugins',
				'themes'        => '/srv/wp/wp-content/themes',
				'uploads'       => '/mnt/media/uploads',
				'mu-plugins'    => '/srv/wp/wp-content/mu-plugins',
				'other-content' => '/srv/wp/wp-content',
			),
			self::TOKEN,
			7,
			self::RANDOM
		);
		$this->assertSame( array( '/srv/wp/wp-content', '/mnt/media' ), $layout->parents() );
		$this->assertSame( ExpectedPath::slashed( '/mnt/media/wp-checkpoint-stage-' . self::TOKEN . '-7-' . self::RANDOM, 'uploads' ), $layout->stage_dir( 'uploads' ) );
		$this->assertSame( $layout->root( 'plugins' ), $layout->root( 'other-content' ), 'other-content is staged in the content directory, its entries\' parent' );
		$this->assertSame( '/srv/wp/wp-content', $layout->parent( 'other-content' ) );
	}

	public function test_names_carry_the_installation_the_job_and_128_random_bits(): void {
		$random = StagingLayout::new_random();
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/', $random, '128 bits' );
		$this->assertNotSame( $random, StagingLayout::new_random() );
		$layout = self::standard();
		$this->assertSame(
			array(
				'kind'   => 'stage',
				'token'  => self::TOKEN,
				'job_id' => 7,
			),
			StagingLayout::parse( $layout->root_name() )
		);
		// A directory, its renamed form, the loader probe, and the loader probe's temporary file (AtomicFile).
		foreach ( array( '', '-r', '.php', '.php.0123456789abcdef.tmp' ) as $suffix ) {
			$name = $layout->probe_name() . $suffix;
			$this->assertSame(
				array(
					'kind'   => 'probe',
					'token'  => self::TOKEN,
					'job_id' => 7,
				),
				StagingLayout::parse( $name ),
				$name
			);
		}
		$this->assertNotSame( $layout->probe_name(), $layout->probe_name(), 'a new probe name each time' );
		try {
			$layout->probe_name( '.tmp' );
			$this->fail( 'a suffix parse() does not know' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'Not a probe suffix.', $e->getMessage() );
		}
		$largest = new StagingLayout( array_fill_keys( StagingLayout::GROUPS, '/srv/x' ), self::TOKEN, 999999999999999999, self::RANDOM );
		$this->assertSame( 999999999999999999, StagingLayout::parse( $largest->root_name() )['job_id'], 'the largest id reads back' );
		foreach ( array(
			'wp-checkpoint-' . self::TOKEN,
			'wp-checkpoint-stage-' . self::TOKEN . '-7-' . substr( self::RANDOM, 0, 31 ),
			'wp-checkpoint-stage-' . self::TOKEN . '-0-' . self::RANDOM,
			'wp-checkpoint-probe-' . self::TOKEN . '-7-0123456789abcdef.txt',
			'wp-checkpoint-stage-' . self::TOKEN . '-1234567890123456789-' . self::RANDOM, // More digits than any id.
			'WP-CHECKPOINT-STAGE-' . self::TOKEN . '-7-' . self::RANDOM,
			'plugins',
		) as $name ) {
			$this->assertNull( StagingLayout::parse( $name ), $name );
		}
	}

	public function test_an_identity_of_the_wrong_shape_is_refused(): void {
		foreach ( array(
			array( 'token', 7, self::RANDOM ),
			array( self::TOKEN, 0, self::RANDOM ),
			array( self::TOKEN, 7, 'short' ),
			array( self::TOKEN, 7, strtoupper( self::RANDOM ) ),
			array( self::TOKEN, PHP_INT_MAX, self::RANDOM ), // 19 digits: parse() would not read the name back.
		) as list( $token, $id, $random ) ) {
			try {
				new StagingLayout( array_fill_keys( StagingLayout::GROUPS, '/srv' ), $token, $id, $random );
				$this->fail( 'accepted' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Not a staging identity.', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		new StagingLayout( array( 'plugins' => '/srv' ), self::TOKEN, 7, self::RANDOM );
	}
}

<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\StagingLayout;
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
				'staged'   => $root . '/plugins/akismet/akismet.php',
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
				'staged'   => $root . '/other-content/languages/de_DE.mo',
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
		$this->assertSame( '/mnt/media/wp-checkpoint-stage-' . self::TOKEN . '-7-' . self::RANDOM . '/uploads', $layout->stage_dir( 'uploads' ) );
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

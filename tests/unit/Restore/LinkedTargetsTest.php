<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Support\Report;
use WPCheckpoint\Tests\Fixtures\Sandbox;
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

	public function test_a_target_is_outside_unless_in_the_wordpress_directory_or_the_trusted_root(): void {
		mkdir( $this->dir . '/site/wp-content/uploads', 0755, true );
		mkdir( $this->dir . '/shared/uploads', 0755, true );
		mkdir( $this->dir . '/elsewhere/uploads', 0755, true );
		$site = $this->dir . '/site';
		$this->assertFalse( LinkedTargets::outside( $site . '/wp-content/uploads', $site, '' ), 'in the WordPress directory' );
		$this->assertTrue( LinkedTargets::outside( $this->dir . '/shared/uploads', $site, '' ), 'outside, without a trusted root' );
		$this->assertFalse( LinkedTargets::outside( $this->dir . '/shared/uploads', $site, $this->dir . '/shared' ), 'in the trusted root' );
		$this->assertTrue( LinkedTargets::outside( $this->dir . '/elsewhere/uploads', $site, $this->dir . '/shared' ), 'in neither' );
		$this->assertTrue( LinkedTargets::outside( $site . '/wp-content/uploads', '', '' ), 'a WordPress directory that could not be resolved is no evidence of inside' );
		$this->assertTrue( LinkedTargets::outside( $this->dir . '/site-copy/uploads', $site, '' ), 'a name that only begins like it' );
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
				'uploads: /home/***/shared/uploads (target 1) (not in this site\'s home directory)',
				'themes: /home/***/shared/uploads (target 2) (not in this site\'s home directory)',
				'plugins: /home/***/shared/plugins (in this site\'s home directory)',
			),
			$lines
		);
		$text = implode( "\n", $lines );
		$this->assertStringNotContainsString( 'alice', $text );
		$this->assertStringNotContainsString( 'bob', $text );
		$this->assertStringNotContainsString( 'carol', $text );
	}
}

<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A content group whose directory is a link (a deployment's shared/uploads) is restored to the end: the swap
 * replaces the directory the link points to, where it is, and the link itself stays as it was.
 */
final class SwapLinkRootTest extends SwapTestCase {

	public function test_a_group_whose_directory_is_a_link_is_swapped_where_its_target_is_and_the_link_stays(): void {
		$shared = $this->sandbox . '/shared';
		mkdir( $shared );
		$link = $this->dirs['uploads'];
		rename( $link, $shared . '/uploads' );
		symlink( $shared . '/uploads', $link );
		$this->assertTrue( is_link( $link ), 'the control: the uploads directory is a link' );
		$target = (string) readlink( $link );
		$this->assertSame( 'live upload', file_get_contents( $shared . '/uploads/live.txt' ), 'the control: the live uploads are in the target' );
		// The target is outside the WordPress directory: the restore is told to swap it as usual.
		$job  = $this->at_swap( array( 'policy' => array( LinkedTargets::POLICY_KEY => LinkedTargets::SWAP ) ) );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error );
		$this->assertContains( 'entered', $this->seams );
		// The link is where it was and points where it pointed.
		$this->assertTrue( is_link( $link ), 'the link stays a link' );
		$this->assertSame( $target, readlink( $link ), 'pointing where it did' );
		// Its target holds the backup's uploads; the live ones went next to it, into the staging root.
		$this->assertSame( 'restored upload', file_get_contents( $shared . '/uploads/2026/10/restored.txt' ) );
		$this->assertFileDoesNotExist( $shared . '/uploads/live.txt' );
		$roots = array_values(
			array_filter(
				(array) scandir( $shared ),
				static function ( $name ): bool {
					return null !== StagingLayout::parse( (string) $name );
				}
			)
		);
		$this->assertCount( 1, $roots, 'staged next to the target, not next to the link' );
		$this->assertSame( 'live upload', file_get_contents( $shared . '/' . $roots[0] . '/old/uploads/live.txt' ), 'the live uploads kept where the swap put them aside' );
		// The other groups, not links, swapped as usual.
		$this->assertSame( "<?php\n/* Plugin Name: Demo */\n", file_get_contents( $this->dirs['plugins'] . '/demo/demo.php' ) );
	}
}

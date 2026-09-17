<?php

namespace WPCheckpoint\Tests\Unit\Admin;

use WPCheckpoint\Admin\Tab;
use WPCheckpoint\Admin\Tabs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TabsTest extends TestCase {

	private function tab( string $slug ): Tab {
		return new class( $slug ) implements Tab {
			/** @var string */
			private $slug;

			public function __construct( string $slug ) {
				$this->slug = $slug;
			}

			public function slug(): string {
				return $this->slug;
			}

			public function label(): string {
				return ucfirst( $this->slug );
			}

			public function render(): void {
				echo $this->slug;
			}
		};
	}

	public function test_keeps_insertion_order(): void {
		$tabs = new Tabs();
		$tabs->add( $this->tab( 'backups' ) );
		$tabs->add( $this->tab( 'tools' ) );
		$tabs->add( $this->tab( 'settings' ) );

		$this->assertSame( array( 'backups', 'tools', 'settings' ), array_map( static function ( Tab $t ) { return $t->slug(); }, $tabs->all() ) );
		$this->assertSame( 'backups', $tabs->default_slug() );
	}

	public function test_same_slug_replaces_in_place(): void {
		$tabs  = new Tabs();
		$first = $this->tab( 'tools' );
		$tabs->add( $this->tab( 'backups' ) );
		$tabs->add( $first );
		$tabs->add( $this->tab( 'settings' ) );
		$replacement = $this->tab( 'tools' );
		$tabs->add( $replacement );

		$this->assertCount( 3, $tabs->all() );
		$this->assertSame( $replacement, $tabs->all()[1] );
	}

	public function test_resolve_falls_back_to_first_tab(): void {
		$tabs = new Tabs();
		$tabs->add( $this->tab( 'backups' ) );
		$tabs->add( $this->tab( 'settings' ) );

		$this->assertSame( 'settings', $tabs->resolve( 'settings' )->slug() );
		$this->assertSame( 'backups', $tabs->resolve( 'unknown' )->slug() );
		$this->assertSame( 'backups', $tabs->resolve( '' )->slug() );
		$this->assertTrue( $tabs->has( 'settings' ) );
		$this->assertFalse( $tabs->has( 'nope' ) );
	}

	public function test_empty_registry(): void {
		$tabs = new Tabs();
		$this->assertSame( '', $tabs->default_slug() );
		$this->assertNull( $tabs->resolve( 'anything' ) );
		$this->assertSame( array(), $tabs->all() );
	}
}

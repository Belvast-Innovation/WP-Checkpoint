<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Unit\Jobs\WebPathScanTest;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The controls of WebPathScanner: for each kind of edge, a WordPress call in a method reached only through that edge
 * is found (and one in a method of the same class reached by none is not, the scan being method by method); what
 * cannot be read fails the scan rather than counting as no edge.
 */
final class WebPathScannerTest extends TestCase {

	/**
	 * What a scan from Ctl\Root::go() over these sources finds.
	 *
	 * @param array<string, string> $sources Class => source.
	 * @param array<string, mixed>  $more    Registries.
	 * @return string[]
	 */
	private static function problems( array $sources, array $more = array() ): array {
		$reach = WebPathScanTest::scanner(
			$sources,
			array_merge(
				array(
					'roots'     => array( 'Ctl\Root::go' ),
					'bindings'  => array(),
					'receivers' => array(),
					'dynamic'   => array(),
					'allowed'   => array(),
					'cuts'      => array(),
				),
				$more
			)
		)->run();
		return $reach['problems'];
	}

	/**
	 * A target class: hit() calls WordPress, and so does unreached(), which nothing calls.
	 *
	 * @param string $name Class name in Ctl.
	 * @param string $more More of its body.
	 * @return string
	 */
	private static function target( string $name = 'Target', string $more = '' ): string {
		return "<?php\nnamespace Ctl;\nclass $name {\n$more\n\tpublic function hit() { wp_die(); }\n\tpublic function unreached() { wp_die(); }\n}\n";
	}

	/**
	 * Assert hit() of the class is found and unreached() is not.
	 *
	 * @param string[] $problems Problems.
	 * @param string   $file     The class (or trait) whose methods they are.
	 * @param string   $hit      The reached method.
	 */
	private function assert_reached( array $problems, string $file = 'Ctl\Target', string $hit = 'hit' ): void {
		$this->assertContains( "$file:$hit(): calls wp_die()", $problems, implode( "\n", $problems ) );
		$this->assertNotContains( "$file:unreached(): calls wp_die()", $problems );
	}

	public function test_a_static_call(): void {
		$this->assert_reached(
			self::problems(
				array(
					'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go() { Target::hit(); } }\n",
					'Ctl\Target' => self::target(),
				)
			)
		);
	}

	public function test_new(): void {
		$problems = self::problems(
			array(
				'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go() { return new Target(); } }\n",
				'Ctl\Target' => self::target( 'Target', "\tpublic function __construct() { wp_die(); }" ),
			)
		);
		$this->assert_reached( $problems, 'Ctl\Target', '__construct' );
		$this->assertNotContains( 'Ctl\Target:hit(): calls wp_die()', $problems );
	}

	public function test_a_receiver_typed_by_its_property(): void {
		$this->assert_reached(
			self::problems(
				array(
					'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root {\n\t/**\n\t * @var Target\n\t */\n\tprivate \$t;\n\tpublic function go() { \$this->t->hit(); }\n}\n",
					'Ctl\Target' => self::target(),
				)
			)
		);
	}

	public function test_a_receiver_typed_by_its_parameter(): void {
		$this->assert_reached(
			self::problems(
				array(
					'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go( Target \$t ) { \$t->hit(); } }\n",
					'Ctl\Target' => self::target(),
				)
			)
		);
	}

	public function test_an_implementation_reached_through_the_binding_of_its_interface(): void {
		$sources  = array(
			'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go( Port \$p ) { \$p->hit(); } }\n",
			'Ctl\Port'   => "<?php\nnamespace Ctl;\ninterface Port { public function hit(); }\n",
			'Ctl\Target' => self::target( 'Target implements Port' ),
			'Ctl\Other'  => self::target( 'Other implements Port' ),
		);
		$problems = self::problems( $sources, array( 'bindings' => array( 'Ctl\Port' => array( 'Ctl\Target' ) ) ) );
		$this->assert_reached( $problems );
		// Bound to Target only: Other, another implementation, is not followed.
		$this->assertNotContains( 'Ctl\Other:hit(): calls wp_die()', $problems );
		// Without a binding the call has no target: the scan fails.
		$this->assertContains( 'Ctl\Root:go(): none of Ctl\Port has hit()', self::problems( $sources ) );
	}

	public function test_a_closure_defined_in_a_reached_method(): void {
		$problems = self::problems(
			array(
				'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go() { return array_map( static function ( \$x ) { Target::hit(); return \$x; }, array( 1 ) ); } }\n",
				'Ctl\Target' => self::target(),
			)
		);
		$this->assert_reached( $problems );
		// A closure called through a variable is a call whose target cannot be read until it is registered.
		$sources = array(
			'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go() { \$f = static function () { Target::hit(); }; \$f(); } }\n",
			'Ctl\Target' => self::target(),
		);
		$this->assertContains( 'Ctl\Root:go(): a call whose target cannot be read', self::problems( $sources ) );
		$this->assert_reached( self::problems( $sources, array( 'dynamic' => array( 'Ctl\Root:go' => array() ) ) ) );
	}

	public function test_a_callback_given_by_name(): void {
		$this->assert_reached(
			self::problems(
				array(
					'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root { public function go() { return array( 'run' => array( Target::class, 'hit' ) ); } }\n",
					'Ctl\Target' => self::target(),
				)
			)
		);
	}

	public function test_a_trait(): void {
		$this->assert_reached(
			self::problems(
				array(
					'Ctl\Root' => "<?php\nnamespace Ctl;\nclass Root { use Part; public function go() { \$this->hit(); } }\n",
					'Ctl\Part' => "<?php\nnamespace Ctl;\ntrait Part {\n\tpublic function hit() { wp_die(); }\n\tpublic function unreached() { wp_die(); }\n}\n",
				)
			),
			'Ctl\Part'
		);
	}

	public function test_a_trait_method_calling_this_reaches_the_using_class(): void {
		$this->assert_reached(
			self::problems(
				array(
					'Ctl\Root' => "<?php\nnamespace Ctl;\nfinal class Root { use Part; public function go() { \$this->run(); }\n\tpublic function hit() { wp_die(); }\n\tpublic function unreached() { wp_die(); }\n}\n",
					'Ctl\Part' => "<?php\nnamespace Ctl;\ntrait Part { public function run() { \$this->hit(); } }\n",
				)
			),
			'Ctl\Root'
		);
	}

	public function test_a_call_on_this_in_a_class_that_is_not_final_is_found(): void {
		$sources = static function ( string $modifier, string $visibility ): array {
			return array(
				'Ctl\Root'   => "<?php\nnamespace Ctl;\nfinal class Root { public function go( Target \$t ) { \$t->run(); } }\n",
				'Ctl\Target' => "<?php\nnamespace Ctl;\n{$modifier}class Target {\n\tpublic function run() { \$this->hit(); static::hit(); }\n\t$visibility function hit() { wp_die(); }\n}\n",
			);
		};
		$found = 'Ctl\Target:run(): calls hit() on $this or static:: in Ctl\Target, which is not final (a subclass may override it)';
		$open  = self::problems( $sources( '', 'public' ) );
		$this->assertContains( $found, $open );
		$this->assertContains( 'Ctl\Target:hit(): calls wp_die()', $open, 'and followed all the same' );
		$this->assertNotContains( $found, self::problems( $sources( 'final ', 'public' ) ) );
		$this->assertNotContains( $found, self::problems( $sources( '', 'private' ) ) );
	}

	public function test_parent_self_and_static(): void {
		foreach ( array( 'parent', 'self', 'static' ) as $how ) {
			$this->assert_reached(
				self::problems(
					array(
						'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root extends Target { public function go() { $how::hit(); } }\n",
						'Ctl\Target' => self::target(),
					)
				)
			);
		}
	}

	public function test_a_receiver_whose_type_cannot_be_read_fails_the_scan_until_registered(): void {
		$cases = array(
			'no type'         => "public function go( \$t ) { \$t->hit(); }",
			'mixed'           => "/**\n\t * @param mixed \$t T.\n\t */\n\tpublic function go( \$t ) { \$t->hit(); }",
			'object'          => "public function go( object \$t ) { \$t->hit(); }",
			'array element'   => "public function go( array \$all ) { \$all['t']->hit(); }",
			'chain of a call' => "public function go() { \$this->make()->hit(); }\n\tprivate function make() { return null; }",
		);
		foreach ( $cases as $what => $body ) {
			$sources  = array(
				'Ctl\Root'   => "<?php\nnamespace Ctl;\nclass Root {\n\t$body\n}\n",
				'Ctl\Target' => self::target(),
			);
			$problems = self::problems( $sources );
			$this->assertNotEmpty( preg_grep( '/^Ctl\\\\Root:go\(\): the type of .* \(calling hit\(\)\) cannot be read$/', $problems ), $what . ': ' . implode( "\n", $problems ) );
			$this->assertNotContains( 'Ctl\Target:hit(): calls wp_die()', $problems, $what );
			// Registered with its class, it is followed.
			preg_match( '/the type of (.*) \(calling/', (string) current( preg_grep( '/cannot be read$/', $problems ) ), $m );
			$this->assert_reached( self::problems( $sources, array( 'receivers' => array( 'Ctl\Root:go:' . $m[1] => array( 'Ctl\Target' ) ) ) ) );
		}
	}

	public function test_a_constant_not_php_own_is_found(): void {
		$problems = self::problems(
			array(
				'Ctl\Root' => "<?php\nnamespace Ctl;\nclass Root { public function go() { return array( WP_CONTENT_DIR, PHP_EOL, \\JSON_THROW_ON_ERROR, self::X, Root::class ); }\n\tconst X = 1;\n}\n",
			)
		);
		$this->assertContains( 'Ctl\Root:go(): reads the constant WP_CONTENT_DIR', $problems );
		$this->assertSame( array( 'Ctl\Root:go(): reads the constant WP_CONTENT_DIR' ), preg_grep( '/constant/', $problems ), 'PHP\'s own and class constants are not' );
	}

	public function test_a_wide_catch_without_not_here_passed_through_is_found(): void {
		$wide = "<?php\nnamespace Ctl;\nclass Root { public function go() { try { return 1; } catch ( \\RuntimeException \$e ) { return 0; } } }\n";
		$this->assertContains( 'Ctl\Root:go(): a wide catch that may take a NotHere (\RuntimeException)', self::problems( array( 'Ctl\Root' => $wide ) ) );
		$passed = "<?php\nnamespace Ctl;\nuse WPCheckpoint\\Jobs\\NotHere;\nclass Root { public function go() { try { return 1; } catch ( NotHere \$e ) { throw \$e; } catch ( \\RuntimeException \$e ) { return 0; } } }\n";
		$this->assertSame( array(), preg_grep( '/wide catch/', self::problems( array( 'Ctl\Root' => $passed ) ) ) );
	}

	public function test_code_outside_the_class_is_found(): void {
		$this->assertContains( 'Ctl\Root: code outside the class', self::problems( array( 'Ctl\Root' => "<?php\nnamespace Ctl;\nrequire 'x.php';\nclass Root { public function go() {} }\n" ) ) );
		$this->assertSame( array(), self::problems( array( 'Ctl\Root' => "<?php\nnamespace Ctl;\ndefined( 'ABSPATH' ) || exit;\nfinal class Root { public function go() {} }\n" ) ) );
	}
}

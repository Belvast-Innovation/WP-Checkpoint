<?php

namespace WPCheckpoint\Tests\Unit\Replace;

use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Replace\Engine;
use WPCheckpoint\Replace\Needles;
use WPCheckpoint\Replace\Result;
use WPCheckpoint\Replace\Serialized;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A class whose deserialization would be observed: the engine must never
 * create one. Tests unserialize() copies of it to prove the counter works.
 */
final class WakeCounter {

	/** @var int */
	public static $woken = 0;

	/** @var string */
	public $url = '';

	/** @var string */
	private $secret = '';

	/** @var string */
	protected $kept = '';

	public function __construct( string $url = '', string $secret = '', string $kept = '' ) {
		$this->url    = $url;
		$this->secret = $secret;
		$this->kept   = $kept;
	}

	public function __wakeup() {
		++self::$woken;
	}

	public function secret(): string {
		return $this->secret;
	}

	public function kept(): string {
		return $this->kept;
	}
}

/**
 * Replacing in stored values: serialized data stays readable, with only
 * the replaced strings and their lengths changed; damaged data is left as it
 * is; nothing is ever deserialized. unserialize() is called here, in the
 * tests only, to cross-check what the engine wrote.
 */
final class EngineTest extends TestCase {

	const OLD = 'https://old.example';
	const NEW = 'https://new.example';

	private static function engine(): Engine {
		return new Engine( new Needles( array( array( self::OLD, self::NEW ) ) ) );
	}

	/**
	 * Deserialize for a check (tests only; objects allowed where the test says so).
	 *
	 * @param string $data    Serialized.
	 * @param bool   $objects Allow WakeCounter.
	 * @return mixed
	 */
	private static function read( string $data, bool $objects = false ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- cross-check in a test.
		return unserialize( $data, array( 'allowed_classes' => $objects ? array( WakeCounter::class, \stdClass::class ) : false ) );
	}

	public function test_strings_in_a_serialization_are_replaced_and_their_byte_lengths_written_anew(): void {
		$value  = array(
			'home'  => self::OLD . '/',
			'logo'  => self::OLD . '/wp-content/uploads/ä-logo.png',
			'title' => 'Grüße, 日本',
			'count' => 3,
		);
		$result = self::engine()->value( serialize( $value ) );
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( 2, $result->replaced() );
		$this->assertSame(
			array(
				'home'  => self::NEW . '/',
				'logo'  => self::NEW . '/wp-content/uploads/ä-logo.png',
				'title' => 'Grüße, 日本',
				'count' => 3,
			),
			self::read( $result->value() )
		);
		$this->assertStringContainsString( 's:' . strlen( self::NEW . '/wp-content/uploads/ä-logo.png' ) . ':"', $result->value(), 'lengths are bytes, not characters' );
	}

	public function test_a_serialization_inside_a_string_is_rewritten_first_and_the_outer_length_follows(): void {
		$inner  = serialize( array( 'url' => self::OLD . '/a', 'deeper' => serialize( array( self::OLD . '/b' ) ) ) );
		$value  = serialize( array( 'widget' => $inner, 'json' => '{"src":"https:\/\/old.example\/c.jpg"}' ) );
		$result = self::engine()->value( $value );
		$this->assertSame( 3, $result->replaced() );
		$outer = self::read( $result->value() );
		$this->assertIsArray( $outer );
		$middle = self::read( $outer['widget'] );
		$this->assertSame( self::NEW . '/a', $middle['url'] );
		$this->assertSame( array( self::NEW . '/b' ), self::read( $middle['deeper'] ) );
		$this->assertSame( self::NEW . '/c.jpg', json_decode( $outer['json'], true )['src'], 'JSON inside a serialization stays JSON' );
	}

	public function test_objects_are_rewritten_without_being_created(): void {
		$data = serialize( array( 'o' => new WakeCounter( self::OLD . '/x', self::OLD . '/private', self::OLD . '/protected' ) ) );
		// The control: deserializing a copy is observed.
		WakeCounter::$woken = 0;
		self::read( $data, true );
		$this->assertSame( 1, WakeCounter::$woken );

		WakeCounter::$woken = 0;
		$result             = self::engine()->value( $data );
		$this->assertSame( 0, WakeCounter::$woken, 'the engine created no object' );
		$this->assertSame( 3, $result->replaced(), 'public, private and protected properties' );
		$copy = self::read( $result->value(), true )['o'];
		$this->assertSame( self::NEW . '/x', $copy->url );
		$this->assertSame( self::NEW . '/private', $copy->secret() );
		$this->assertSame( self::NEW . '/protected', $copy->kept() );
	}

	public function test_references_still_point_where_they_did(): void {
		$shared        = new \stdClass();
		$shared->url   = self::OLD . '/shared';
		$value         = array( 'a' => self::OLD . '/a', 'first' => $shared, 'second' => $shared );
		$value['b']    = &$value['a'];
		$data          = serialize( $value );
		$this->assertMatchesRegularExpression( '/R:\d+;/', $data, 'the fixture holds a reference to a value' );
		$this->assertMatchesRegularExpression( '/r:\d+;/', $data, 'and one to an object' );
		$result = self::engine()->value( $data );
		$this->assertSame( 2, $result->replaced(), 'the referenced values once each' );
		$copy = self::read( $result->value(), true );
		$this->assertSame( self::NEW . '/a', $copy['a'] );
		$copy['b'] = 'changed';
		$this->assertSame( 'changed', $copy['a'], 'b is still a reference to a' );
		$this->assertSame( $copy['first'], $copy['second'], 'first and second are still one object' );
		$this->assertSame( self::NEW . '/shared', $copy['first']->url );
	}

	public function test_every_byte_not_replaced_is_kept(): void {
		$data   = 'a:7:{s:1:"f";d:0.1;s:1:"g";d:1.0E+25;s:1:"h";d:-INF;s:1:"i";i:-42;s:1:"b";b:1;s:1:"n";N;s:1:"u";s:19:"' . self::OLD . '";}';
		$result = self::engine()->value( $data );
		$this->assertSame( str_replace( 's:19:"' . self::OLD . '"', 's:19:"' . self::NEW . '"', $data ), $result->value() );
		// Round trip: A to B and back is the original, byte for byte.
		$back = new Engine( new Needles( array( array( self::NEW, self::OLD ) ) ) );
		$this->assertSame( $data, $back->value( $result->value() )->value() );
	}

	public function test_keys_and_opaque_parts_are_left_and_counted(): void {
		$data   = serialize( array( self::OLD . '/key' => self::OLD . '/value' ) );
		$result = self::engine()->value( $data );
		$this->assertSame( array( self::OLD . '/key' => self::NEW . '/value' ), self::read( $result->value() ) );
		$this->assertSame( 1, $result->keys() );
		$this->assertSame( 1, $result->replaced(), 'the value next to it is replaced' );

		$custom = 'a:1:{i:0;C:11:"ArrayObject":' . strlen( 'x:i:0;s:19:"' . self::OLD . '";' ) . ':{x:i:0;s:19:"' . self::OLD . '";}}';
		$result = self::engine()->value( $custom );
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( $custom, $result->value(), 'a custom serialization is copied as it is' );
		$this->assertSame( 1, $result->opaque() );
	}

	public function test_a_damaged_serialization_is_left_as_it_is_and_counted(): void {
		$good = serialize( array( 'u' => self::OLD . '/path' ) );
		// The control: the same value intact is rewritten.
		$this->assertSame( Result::SERIALIZED, self::engine()->value( $good )->kind() );
		$damaged = array(
			'wrong length' => str_replace( 's:24:', 's:25:', $good ),
			'wrong count'  => str_replace( 'a:1:', 'a:2:', $good ),
			'cut short'    => substr( $good, 0, -6 ) . '";}',
		);
		foreach ( $damaged as $label => $data ) {
			$this->assertTrue( Serialized::looks_serialized( $data ), $label . ': WordPress would try to unserialize it' );
			$this->assertFalse( @self::read( $data ), $label . ': and fail' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the notice is the point.
			$result = self::engine()->value( $data );
			$this->assertSame( Result::DAMAGED, $result->kind(), $label );
			$this->assertSame( $data, $result->value(), $label . ': left unchanged' );
			$this->assertSame( 1, $result->damaged(), $label );
		}
		$this->assertSame( 0, self::engine()->value( str_replace( 's:19:', 's:20:', serialize( array( 'u' => 'unrelated' ) ) ) )->damaged(), 'damage without a search text is none of our business' );
	}

	public function test_bytes_after_a_complete_value_are_kept_and_the_value_replaced_as_wordpress_reads_it(): void {
		$data = serialize( array( 'u' => self::OLD ) ) . 'x;';
		// The control: PHP reads the value and ignores what follows, so WordPress reads it.
		$this->assertTrue( Serialized::looks_serialized( $data ) );
		$this->assertSame( array( 'u' => self::OLD ), @self::read( $data ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- PHP 8.3 warns about the extra bytes.
		$result = self::engine()->value( $data );
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( 'x;', substr( $result->value(), -2 ), 'what follows is kept' );
		$this->assertSame( array( 'u' => self::NEW ), @self::read( $result->value() ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		// Not taken for a serialization by WordPress (it does not end in ";" or "}"): text.
		$prose = 's:3:"abc"; see ' . self::OLD . ' now';
		$this->assertFalse( Serialized::looks_serialized( $prose ) );
		$this->assertSame( 's:3:"abc"; see ' . self::NEW . ' now', self::engine()->value( $prose )->value() );
	}

	public function test_a_damaged_serialization_inside_a_good_one_is_left_and_the_rest_replaced(): void {
		$inner  = str_replace( 'a:1:', 'a:5:', serialize( array( self::OLD . '/inner' ) ) );
		$data   = serialize( array( 'broken' => $inner, 'fine' => self::OLD . '/fine' ) );
		$result = self::engine()->value( $data );
		$copy   = self::read( $result->value() );
		$this->assertSame( $inner, $copy['broken'] );
		$this->assertSame( self::NEW . '/fine', $copy['fine'] );
		$this->assertSame( 1, $result->damaged() );
		$this->assertSame( 1, $result->replaced() );
	}

	public function test_plain_text_and_json_are_replaced_as_text(): void {
		$elementor = '[{"id":"a1","elType":"widget","settings":{"image":{"url":"https:\/\/old.example\/wp-content\/uploads\/hero.jpg","id":7},"link":{"url":"https:\/\/old.example\/shop\/"}}}]';
		$result    = self::engine()->value( $elementor );
		$this->assertSame( Result::TEXT, $result->kind() );
		$this->assertSame( 2, $result->replaced() );
		$data = json_decode( $result->value(), true );
		$this->assertSame( self::NEW . '/wp-content/uploads/hero.jpg', $data[0]['settings']['image']['url'] );
		$this->assertSame( str_replace( 'old.example', 'new.example', $elementor ), $result->value(), 'nothing else changed, escaping included' );

		$post = '<p>Visit <a href="https://old.example/about">https://old.example/about</a> or https://old.example.</p>';
		$this->assertSame( str_replace( 'old.example', 'new.example', $post ), self::engine()->value( $post )->value() );
	}

	public function test_plugin_shaped_samples(): void {
		$engine = new Engine( Needles::for_move( 'http://old.example', 'https://new.example', '/var/www/old', '/srv/new' ) );
		// ACF: a link field and an image field.
		$acf    = serialize( array( 'title' => 'Shop', 'url' => 'http://old.example/shop/', 'target' => '' ) );
		$this->assertSame( array( 'title' => 'Shop', 'url' => 'https://new.example/shop/', 'target' => '' ), self::read( $engine->value( $acf )->value() ) );
		// WooCommerce settings: nested arrays, paths, a URL-encoded return address.
		$woo    = serialize(
			array(
				'enabled'  => 'yes',
				'pages'    => array( 'cart' => 'http://old.example/cart/', 'checkout' => '//old.example/checkout/' ),
				'log_dir'  => '/var/www/old/wp-content/uploads/wc-logs/',
				'return'   => 'http%3A%2F%2Fold.example%2Fmy-account%2F',
				'currency' => 'EUR',
			)
		);
		$copy   = self::read( $engine->value( $woo )->value() );
		$this->assertSame( 'https://new.example/cart/', $copy['pages']['cart'] );
		$this->assertSame( '//new.example/checkout/', $copy['pages']['checkout'] );
		$this->assertSame( '/srv/new/wp-content/uploads/wc-logs/', $copy['log_dir'] );
		$this->assertSame( 'https%3A%2F%2Fnew.example%2Fmy-account%2F', $copy['return'] );
		$this->assertSame( 'EUR', $copy['currency'] );
	}

	public function test_whitespace_around_a_serialization_is_kept(): void {
		$data   = " \n" . serialize( array( self::OLD ) ) . "\n ";
		$result = self::engine()->value( $data );
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( " \n", substr( $result->value(), 0, 2 ) );
		$this->assertSame( "\n ", substr( $result->value(), -2 ) );
		$this->assertSame( array( self::NEW ), self::read( trim( $result->value() ) ) );
	}

	public function test_the_deepest_nesting_allowed_is_read_and_one_more_is_damaged(): void {
		$build = static function ( int $levels ): string {
			$data = 's:19:"' . self::OLD . '";';
			for ( $i = 0; $i < $levels; $i++ ) {
				$data = 'a:1:{i:0;' . $data . '}';
			}
			return $data;
		};
		// n containers put the string at depth n: MAX_DEPTH is the deepest read, one more is refused.
		$deepest = $build( Serialized::MAX_DEPTH );
		$result  = self::engine()->value( $deepest );
		$this->assertSame( Result::SERIALIZED, $result->kind(), 'reachable' );
		$this->assertSame( 1, $result->replaced() );
		$beyond = self::engine()->value( $build( Serialized::MAX_DEPTH + 1 ) );
		$this->assertSame( Result::TOO_DEEP, $beyond->kind(), 'beyond the limit: left, and not called damaged (PHP reads deeper)' );
		$this->assertSame( 1, $beyond->too_deep() );
		$this->assertSame( 0, $beyond->damaged() );
		$this->assertNotFalse( self::read( $build( Serialized::MAX_DEPTH + 1 ) ), 'PHP does read it' );
	}

	public function test_a_value_at_the_row_limit_is_replaced_within_the_step_budget(): void {
		// Built as a string (no array of 4 MiB first), so the peak before the measurement stays small.
		$piece = serialize( array( 'u' => self::OLD . '/wp-content/uploads/image.jpg', 't' => str_repeat( 'x', 200 ) ) );
		$item  = 's:' . strlen( $piece ) . ':"' . $piece . '";';
		$body  = '';
		$count = 0;
		while ( strlen( $body ) + strlen( $item ) + 32 < TableExporter::MAX_ROW_BYTES - 32 ) {
			$body .= 'i:' . $count . ';' . $item;
			++$count;
		}
		$data = 'a:' . $count . ':{' . $body . '}';
		unset( $body );
		$this->assertGreaterThan( TableExporter::MAX_ROW_BYTES - 65536, strlen( $data ) );
		$this->assertLessThanOrEqual( TableExporter::MAX_ROW_BYTES, strlen( $data ), 'at most the export row limit' );
		$start  = microtime( true );
		$result = MemoryBudget::within(
			32 * 1048576, // The per-step memory budget.
			static function () use ( $data ): Result {
				return self::engine()->value( $data );
			}
		);
		$this->assertLessThan( 20, microtime( true ) - $start, 'within one step' );
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( substr_count( $data, self::OLD ), $result->replaced() );
	}

	public function test_nested_damage_is_read_once_per_level(): void {
		// Every level declares two members and holds one: each is damaged, and would be read again and again
		// if a failed read were retried on its inner strings.
		$value = 's:19:"' . self::OLD . '";';
		for ( $i = 0; $i < 30; $i++ ) {
			$value = 'a:2:{i:0;s:' . strlen( $value ) . ':"' . $value . '";}';
		}
		$start  = microtime( true );
		$result = self::engine()->value( $value );
		$this->assertLessThan( 1.0, microtime( true ) - $start, 'linear in the depth' );
		$this->assertSame( Result::DAMAGED, $result->kind() );
		$this->assertSame( $value, $result->value() );
	}

	public function test_lengths_too_large_to_fit_are_damage_not_an_error(): void {
		foreach ( array(
			'a:2:{i:0;s:19:"' . self::OLD . '";i:1;O:9223372036854775807:"x":0:{}}',
			'a:2:{i:0;s:19:"' . self::OLD . '";i:1;C:1:"X":9223372036854775807:{}}',
			'a:2:{i:0;s:19:"' . self::OLD . '";i:1;s:99999999999999999999:"x";}',
			'a:9223372036854775807:{i:0;s:19:"' . self::OLD . '";}',
		) as $data ) {
			$result = self::engine()->value( $data );
			$this->assertSame( Result::DAMAGED, $result->kind(), $data );
			$this->assertSame( $data, $result->value() );
		}
	}

	public function test_serializations_inside_strings_are_followed_to_the_limit_within_the_memory_budget(): void {
		// The innermost value is as large as a stored value may be; each level wraps it in another string.
		$payload = str_repeat( 'see ' . self::OLD . '/a ', (int) floor( ( TableExporter::MAX_ROW_BYTES - 1024 ) / ( strlen( self::OLD ) + 8 ) ) );
		$nest    = static function ( int $levels ) use ( $payload ): string {
			$data = serialize( array( $payload ) );
			for ( $i = 0; $i < $levels; $i++ ) {
				$data = serialize( array( $data ) );
			}
			return $data;
		};
		$deepest = $nest( Engine::MAX_NESTED );
		$this->assertLessThan( TableExporter::MAX_ROW_BYTES, strlen( $deepest ) );
		$result = MemoryBudget::within(
			32 * 1048576, // Reachable within the per-step budget.
			static function () use ( $deepest ): Result {
				return self::engine()->value( $deepest );
			}
		);
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( substr_count( $payload, self::OLD ), $result->replaced() );
		unset( $result, $deepest );
		$beyond = self::engine()->value( $nest( Engine::MAX_NESTED + 1 ) );
		$this->assertSame( 1, $beyond->too_deep(), 'one level more: left and counted' );
		$this->assertSame( 0, $beyond->replaced() );
	}

	public function test_unchanged_strings_keep_their_length_as_written(): void {
		$data   = 'a:2:{i:0;s:05:"hello";i:1;s:19:"' . self::OLD . '";}';
		$result = self::engine()->value( $data );
		$this->assertSame( 'a:2:{i:0;s:05:"hello";i:1;s:19:"' . self::NEW . '";}', $result->value() );
		$key = 'a:1:{s:019:"' . self::OLD . '";i:1;}';
		$this->assertSame( $key, self::engine()->value( $key )->value(), 'a needle in a key only: not a byte changed' );
		$this->assertFalse( self::engine()->value( $key )->changed() );
	}

	public function test_the_reader_accepts_what_php_accepts_and_nothing_else(): void {
		$cases = array(
			'd:-0;', 'd:.5;', 'd:5.;', 'd:1e5;', 'd:+1.5E-3;', 'd:-INF;', 'd:INF;', 'd:NAN;', 'd:0.1;', 'd:1.0E+25;',
			'd:--;', 'd:NANI;', 'd:1-2;', 'd:E;', 'd:.;', 'd:INFINF;', 'd:-NAN;', 'd:1e;', 'd:;',
			'i:+5;', 'i:-5;', 'i:;', 'i:5a;', 'b:2;', 'N;',
			'R:1;', 'r:1;',
			'a:2:{i:0;s:1:"x";i:1;R:2;}', 'a:2:{i:0;s:1:"x";i:1;R:3;}', 'a:1:{i:0;R:1;}',
			'a:3:{i:0;s:1:"x";i:1;R:2;i:2;R:3;}',
			'a:2:{i:0;s:1:"x";i:1;r:2;}', 'a:2:{i:0;a:0:{}i:1;r:2;}',
			'a:3:{i:0;O:8:"stdClass":0:{}i:1;r:2;i:2;r:3;}',
			'a:2:{i:0;O:8:"stdClass":1:{s:1:"a";s:1:"b";}i:1;r:3;}',
			'a:2:{i:0;a:1:{i:0;s:1:"x";}i:1;R:3;}',
			's:019:"https://old.example";', 's:2:"x";', 'a:1:{i:0;s:1:"x";', 'a:0:{}',
		);
		$both = array( 0, 0 );
		foreach ( $cases as $data ) {
			$php  = false !== @self::read( $data, true ) || 'b:0;' === $data; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- rejected input warns.
			$ours = null !== Serialized::rewrite(
				$data,
				static function ( string $s ): string {
					return $s;
				},
				static function (): void {
				}
			);
			$this->assertSame( $php, $ours, $data . ( $php ? ' is read by PHP' : ' is refused by PHP' ) );
			++$both[ $php ? 0 : 1 ];
		}
		$this->assertGreaterThan( 10, $both[0], 'the control: many of the cases are valid' );
		$this->assertGreaterThan( 10, $both[1], 'and many are not' );
	}

	public function test_random_structures_come_out_as_serializing_the_expected_structure(): void {
		mt_srand( 20260924 );
		for ( $round = 0; $round < 400; $round++ ) {
			list( $value, $expected ) = self::random_pair( 0, 0 );
			$result                   = self::engine()->value( serialize( $value ) );
			$this->assertSame( serialize( $expected ), $result->value(), 'round ' . $round );
			$this->assertTrue( 'b:0;' === $result->value() || false !== self::read( $result->value(), true ), 'round ' . $round . ' is readable' );
		}
	}

	/**
	 * A random value and, built by hand without the engine or Needles, what
	 * it must become: addresses on a boundary replaced (as written, JSON-
	 * escaped, URL-encoded), others and keys left, nesting inside strings
	 * within the engine's limit.
	 *
	 * @param int $depth   Nesting of arrays and objects.
	 * @param int $nesting Serializations inside strings above it.
	 * @return array{0: mixed, 1: mixed}
	 */
	private static function random_pair( int $depth, int $nesting ): array {
		$pick = mt_rand( 0, $depth > 3 ? 8 : 11 );
		switch ( $pick ) {
			case 0:
				$int = mt_rand( -1000, 1000 );
				return array( $int, $int );
			case 1:
				$float = mt_rand( 0, 1 ) ? mt_rand( -100000, 100000 ) / 7 : ( mt_rand( 0, 1 ) ? INF : -0.0 );
				return array( $float, $float );
			case 2:
				$bool = (bool) mt_rand( 0, 1 );
				return array( $bool, $bool );
			case 3:
				return array( null, null );
			case 4:
				$n = mt_rand( 0, 9 );
				return array( self::OLD . '/p' . $n, self::NEW . '/p' . $n );
			case 5:
				return array( 'see ' . self::OLD . '. Or ü', 'see ' . self::NEW . '. Or ü' );
			case 6:
				return array( self::OLD . '.au and ' . self::OLD . '-x', self::OLD . '.au and ' . self::OLD . '-x' );
			case 7:
				$n = mt_rand( 0, 9 );
				return array( wp_json_encode_for_test( array( 'u' => self::OLD . '/j' . $n ) ), wp_json_encode_for_test( array( 'u' => self::NEW . '/j' . $n ) ) );
			case 8:
				return array( 'to=' . rawurlencode( self::OLD . '/x' ), 'to=' . rawurlencode( self::NEW . '/x' ) );
			case 9:
				if ( $nesting >= Engine::MAX_NESTED ) {
					return array( 'text', 'text' );
				}
				list( $inner, $inner_expected ) = self::random_pair( $depth + 1, $nesting + 1 );
				return array( serialize( $inner ), serialize( $inner_expected ) );
			case 10:
				$object   = new \stdClass();
				$expected = new \stdClass();
				$count    = mt_rand( 0, 3 );
				for ( $i = 0; $i < $count; $i++ ) {
					list( $object->{'p' . $i}, $expected->{'p' . $i} ) = self::random_pair( $depth + 1, $nesting );
				}
				return array( $object, $expected );
			default:
				$array    = array();
				$expected = array();
				$count    = mt_rand( 0, 4 );
				for ( $i = 0; $i < $count; $i++ ) {
					$key                                     = mt_rand( 0, 2 );
					$key                                     = 0 === $key ? $i : ( 1 === $key ? 'k' . $i : self::OLD . '/k' . $i ); // A key keeps its address.
					list( $array[ $key ], $expected[ $key ] ) = self::random_pair( $depth + 1, $nesting );
				}
				return array( $array, $expected );
		}
	}
}

/**
 * JSON as the block editor and page builders store it: slashes escaped.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_for_test( $value ): string {
	return (string) json_encode( $value );
}

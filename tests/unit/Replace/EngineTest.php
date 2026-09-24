<?php

namespace WPCheckpoint\Tests\Unit\Replace;

use WPCheckpoint\Replace\Engine;
use WPCheckpoint\Replace\Needles;
use WPCheckpoint\Replace\Result;
use WPCheckpoint\Replace\Serialized;
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
		$this->assertSame( Result::DAMAGED, self::engine()->value( $build( Serialized::MAX_DEPTH + 1 ) )->kind(), 'beyond the limit' );
	}

	public function test_a_value_at_the_row_limit_is_replaced_within_the_step_budget(): void {
		// Built as a string (no array of 4 MiB first), so the peak before the measurement stays small.
		$piece = serialize( array( 'u' => self::OLD . '/wp-content/uploads/image.jpg', 't' => str_repeat( 'x', 200 ) ) );
		$item  = 's:' . strlen( $piece ) . ':"' . $piece . '";';
		$body  = '';
		$count = 0;
		while ( strlen( $body ) + strlen( $item ) + 32 < 4 * 1048576 - 32 ) {
			$body .= 'i:' . $count . ';' . $item;
			++$count;
		}
		$data = 'a:' . $count . ':{' . $body . '}';
		unset( $body );
		$this->assertGreaterThan( 4 * 1048576 - 65536, strlen( $data ) );
		$this->assertLessThanOrEqual( 4 * 1048576, strlen( $data ), 'at most the export row limit' );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage(); // PHP 8.2+: measure this value alone.
		}
		$before = memory_get_usage();
		$start  = microtime( true );
		$result = self::engine()->value( $data );
		$this->assertLessThan( 32 * 1048576, memory_get_peak_usage() - $before, 'well within the per-step memory budget' );
		$this->assertLessThan( 20, microtime( true ) - $start, 'within one step' );
		$this->assertSame( Result::SERIALIZED, $result->kind() );
		$this->assertSame( substr_count( $data, self::OLD ), $result->replaced() );
	}

	public function test_random_structures_stay_readable_with_the_same_shape(): void {
		mt_srand( 20260924 );
		for ( $round = 0; $round < 300; $round++ ) {
			$value  = self::random_value( 0 );
			$data   = serialize( $value );
			$result = self::engine()->value( $data );
			$copy   = self::read( $result->value() );
			$this->assertNotFalse( $copy, 'round ' . $round . ' is readable: ' . $result->value() );
			$this->assertSame( self::shape( $value ), self::shape( $copy ), 'round ' . $round . ' keeps its shape' );
			$this->assertSame( serialize( self::expected( $value ) ), $result->value(), 'round ' . $round . ' equals serializing the replaced structure' );
		}
	}

	/**
	 * A random nested value with addresses in it.
	 *
	 * @param int $depth Depth.
	 * @return mixed
	 */
	private static function random_value( int $depth ) {
		$pick = mt_rand( 0, $depth > 3 ? 3 : 6 );
		switch ( $pick ) {
			case 0:
				return mt_rand( -1000, 1000 );
			case 1:
				return mt_rand( 0, 1 ) ? self::OLD . '/p' . mt_rand( 0, 9 ) : 'text ' . mt_rand( 0, 9 ) . ' ü';
			case 2:
				return mt_rand( 0, 1 ) ? 'see ' . self::OLD . '.' : self::OLD . '.au';
			case 3:
				return null;
			case 4:
				return serialize( self::random_value( $depth + 1 ) );
			default:
				$array = array();
				$count = mt_rand( 0, 4 );
				for ( $i = 0; $i < $count; $i++ ) {
					$array[ mt_rand( 0, 1 ) ? 'k' . $i : $i ] = self::random_value( $depth + 1 );
				}
				return $array;
		}
	}

	/**
	 * What the engine should produce, computed on the structure.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function expected( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'expected' ), $value );
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$inner = @self::read( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- most strings are not serializations.
		if ( false !== $inner || 'b:0;' === $value ) {
			return serialize( self::expected( $inner ) );
		}
		return ( new Needles( array( array( self::OLD, self::NEW ) ) ) )->replace( $value )[0];
	}

	/**
	 * Types, keys and nesting, without the strings.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function shape( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'shape' ), $value );
		}
		return gettype( $value );
	}
}

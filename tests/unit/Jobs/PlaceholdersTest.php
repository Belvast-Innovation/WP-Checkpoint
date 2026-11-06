<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Placeholders;
use WPCheckpoint\Jobs\UpdateSql;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A template in wpdb::prepare()'s form, for a connection that binds "?" (Placeholders), with wpdb's meaning; and the
 * UPDATE wpdb::update() prepares (UpdateSql).
 */
final class PlaceholdersTest extends TestCase {

	public function test_it_reads_a_template_as_wpdb_prepare_does(): void {
		$this->assertSame(
			array( "UPDATE t SET a = ?, b = 12, c = ? WHERE d LIKE ? AND e = '100%'", array( 'x', 'y', 'z%' ) ),
			Placeholders::convert( "UPDATE t SET a = %s, b = %d, c = '%s' WHERE d LIKE \"%s\" AND e = '100%%'", array( 'x', '12', 'y', 'z%' ) )
		);
	}

	public function test_a_d_placeholder_is_the_value_as_an_integer_written_unquoted(): void {
		foreach ( array(
			array( 7, '7' ),
			array( '12abc', '12' ),
			array( 3.9, '3' ),
			array( '-5', '-5' ),
			array( null, '0' ),
			array( true, '1' ),
			array( '1e3', '1000' ),
		) as $case ) {
			list( $value, $written ) = $case;
			$this->assertSame( array( 'LIMIT ' . $written, array() ), Placeholders::convert( 'LIMIT %d', array( $value ) ), var_export( $value, true ) );
			$this->assertSame( sprintf( '%d', $value ), $written, 'the same as vsprintf(), which wpdb::prepare() runs: ' . var_export( $value, true ) );
		}
	}

	public function test_an_s_placeholder_is_bound_as_a_string(): void {
		$this->assertSame( array( 'a = ? AND b = ?', array( '', '1' ) ), Placeholders::convert( 'a = %s AND b = %s', array( null, true ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>}>
	 */
	public function refused(): array {
		return array(
			'%f'                 => array( 'a = %f', array( 1.5 ) ),
			'%F'                 => array( 'a = %F', array( 1.5 ) ),
			'%i'                 => array( 'SELECT * FROM %i', array( 't' ) ),
			'numbered %1$s'      => array( 'a = %1$s', array( 'x' ) ),
			'padded %5s'         => array( 'a = %5s', array( 'x' ) ),
			'a percent at the end' => array( 'a = 5%', array() ),
			'%x'                 => array( 'a = %x', array( 'x' ) ),
			'a ? in the text'    => array( 'a = %s OR b = ?', array( 'x' ) ),
			'fewer values'       => array( 'a = %s AND b = %d', array( 'x' ) ),
			'more values'        => array( 'a = %s', array( 'x', 'y' ) ),
			'an array value'     => array( 'a = %s', array( array( 'x' ) ) ),
			'an object for %d'   => array( 'a = %d', array( new \stdClass() ) ),
		);
	}

	/**
	 * @dataProvider refused
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 */
	public function test_any_other_form_is_refused( string $template, array $params ): void {
		$this->expectException( \InvalidArgumentException::class );
		Placeholders::convert( $template, $params );
	}

	public function test_an_update_is_in_the_form_wpdb_update_prepares(): void {
		$this->assertSame(
			array( 'UPDATE `wp_jobs` SET `a` = %s, `b` = NULL, `c` = %d WHERE `id` = %d AND `d` IS NULL AND `t` = %s', array( 'x', 3, 9, 'k' ) ),
			UpdateSql::template(
				'wp_jobs',
				array(
					'a' => 'x',
					'b' => null,
					'c' => 3,
				),
				array( '%s', '%s', '%d' ),
				array(
					'id' => 9,
					'd'  => null,
					't'  => 'k',
				),
				array( '%d', '%s', '%s' )
			)
		);
	}

	public function test_an_update_refuses_what_wpdb_would_not_mean(): void {
		foreach ( array(
			array( 'wp_jobs', array(), array(), array( 'id' => 1 ), array( '%d' ) ),
			array( 'wp_jobs', array( 'a' => 1 ), array( '%d' ), array(), array() ),
			array( 'wp jobs', array( 'a' => 1 ), array( '%d' ), array( 'id' => 1 ), array( '%d' ) ),
			array( 'wp_jobs', array( 'a`' => 1 ), array( '%d' ), array( 'id' => 1 ), array( '%d' ) ),
			array( 'wp_jobs', array( 'a' => 1.5 ), array( '%f' ), array( 'id' => 1 ), array( '%d' ) ),
		) as $i => $case ) {
			try {
				UpdateSql::template( $case[0], $case[1], $case[2], $case[3], $case[4] );
				$this->fail( 'refused: case ' . $i );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}

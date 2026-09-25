<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\ConstraintNames;
use WPCheckpoint\Restore\CreateTable;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\SqlLexer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * CREATE TABLE as the supported servers write it (real SHOW CREATE TABLE
 * output in tests/Fixtures/Restore/show-create/), read and rewritten for the
 * temporary table; and what is refused.
 */
final class CreateTableTest extends TestCase {

	const TEMP_PARENT = 'wcptmpabcdef_7_1a2b_parent';
	const TEMP_CHILD  = 'wcptmpabcdef_7_1a2b_child_x_1c2d3e4';

	/**
	 * A reference callback from a map (names not in it unchanged).
	 *
	 * @param array<string, string> $map Map.
	 */
	private static function map( array $map ): callable {
		return static function ( string $table ) use ( $map ): string {
			return $map[ $table ] ?? $table;
		};
	}

	private static function read( string $sql, string $table ): CreateTable {
		$buffer = $sql . ';';
		return CreateTable::read( $buffer, SqlLexer::tokens( $buffer, 0, true ), $table );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function servers(): array {
		$out = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/Fixtures/Restore/show-create/*.json' ) as $file ) {
			$out[ basename( $file, '.json' ) ] = array( $file );
		}
		return $out;
	}

	/**
	 * @dataProvider servers
	 */
	public function test_what_each_server_writes_is_read( string $file ): void {
		$shown = json_decode( (string) file_get_contents( $file ), true );
		$child = self::read( $shown['wp_child;x'], 'wp_child;x' );
		$this->assertSame( array( 'id', 'parent_id', 'parent_code', 'qty', 'total', 'total2', 'meta', 'title', 'pt', 'created' ), $child->columns() );
		$this->assertSame( array( 'id', 'parent_id', 'parent_code', 'qty', 'meta', 'title', 'pt', 'created' ), $child->stored_columns(), 'generated columns are not inserted' );
		$this->assertSame( array( 'id' ), $child->primary_key() );
		$this->assertSame( 'InnoDB', $child->engine() );
		$keys = array();
		foreach ( $child->foreign_keys() as $key ) {
			$keys[ $key['name'] ] = array( $key['columns'], $key['references'], $key['referenced_columns'] );
		}
		ksort( $keys );
		$generated = 0 === strpos( basename( $file ), 'mariadb-lts' ) ? '1' : 'wp_child;x_ibfk_1';
		$expected  = array(
			'fk_child_parent' => array( array( 'parent_id' ), 'wp_parent', array( 'id' ) ),
			$generated        => array( array( 'parent_code' ), 'wp_parent', array( 'code' ) ),
		);
		ksort( $expected );
		$this->assertSame( $expected, $keys );

		$part = self::read( $shown['wp_part'], 'wp_part' );
		$this->assertSame( array( 'id', 'd' ), $part->primary_key(), 'partitioned, in a versioned comment on MySQL' );
		if ( isset( $shown['wp_inv'] ) ) {
			$this->assertSame( array( 'id', 'secret' ), self::read( $shown['wp_inv'], 'wp_inv' )->stored_columns(), 'an invisible column is stored and listed' );
		}
	}

	/**
	 * @dataProvider servers
	 */
	public function test_the_rewrite_changes_names_and_keeps_every_other_byte( string $file ): void {
		$shown   = json_decode( (string) file_get_contents( $file ), true );
		$child   = self::read( $shown['wp_child;x'], 'wp_child;x' );
		$rewrite = $child->rewrite(
			self::TEMP_CHILD,
			'wp_child;x',
			2,
			new ConstraintNames( '1a2b' ),
			self::map(
				array(
					'wp_parent'  => self::TEMP_PARENT,
					'wp_child;x' => self::TEMP_CHILD,
				)
			)
		);
		$sql     = $rewrite['sql'];
		$this->assertStringStartsWith( 'CREATE TABLE `' . self::TEMP_CHILD . '` (', $sql );
		$this->assertStringContainsString( 'FOREIGN KEY (`parent_id`) REFERENCES `' . self::TEMP_PARENT . '` (`id`) ON DELETE CASCADE ON UPDATE SET NULL', $sql );
		$this->assertStringContainsString( 'CONSTRAINT `wcp1a2b_2_fk_child_parent` FOREIGN KEY', $sql, 'a name of its own: the marker in front, the name whole' );
		$this->assertStringContainsString( "COMMENT 'a; b ''c'' REFERENCES `wp_parent`'", $sql, 'text in a string is not a reference' );
		$this->assertStringNotContainsString( 'REFERENCES `wp_parent` (', $sql );

		$by_name = array_column( $rewrite['constraints'], null, 'intended' );
		if ( 0 === strpos( basename( $file ), 'mariadb-lts' ) ) {
			$this->assertSame( 'wcp1a2b_2_1', $by_name['1']['name'], 'MariaDB 12 names keys "1": marker' );
		} else {
			$this->assertSame( self::TEMP_CHILD . '_ibfk_1', $by_name['wp_child;x_ibfk_1']['name'], 'the generated form follows the table' );
		}
		// Every byte outside the replaced names is the original's.
		$names = array_merge( array( self::TEMP_CHILD, self::TEMP_PARENT, 'wp_child;x', 'wp_parent' ), array_column( $rewrite['constraints'], 'name' ), array_column( $rewrite['constraints'], 'intended' ) );
		$strip = static function ( string $text ) use ( $names ): string {
			return preg_replace( '/`(?:[^`]|``)*`/', '``', $text );
		};
		$this->assertSame( $strip( $shown['wp_child;x'] ), $strip( $sql ) );
		$this->assertNotEmpty( $names );
	}

	public function test_a_reference_to_a_table_outside_the_restore_keeps_its_name(): void {
		$create = self::read( 'CREATE TABLE `wp_a` (`id` int NOT NULL, `u` bigint, PRIMARY KEY (`id`), CONSTRAINT `fk_u` FOREIGN KEY (`u`) REFERENCES `wp_users` (`ID`), CONSTRAINT `fk_self` FOREIGN KEY (`id`) REFERENCES `wp_a` (`id`)) ENGINE=InnoDB', 'wp_a' );
		$sql    = $create->rewrite( 'wcptmp_a', 'wp_a', 0, new ConstraintNames( '0000' ), self::map( array( 'wp_a' => 'wcptmp_a' ) ) )['sql'];
		$this->assertStringContainsString( 'REFERENCES `wp_users` (`ID`)', $sql, 'not restored: the live table' );
		$moved = $create->rewrite(
			'wcptmp_a',
			'b_a',
			0,
			new ConstraintNames( '0000' ),
			self::map(
				array(
					'wp_a'     => 'wcptmp_a',
					'wp_users' => 'b_users',
				)
			)
		)['sql'];
		$this->assertStringContainsString( 'REFERENCES `b_users` (`ID`)', $moved, 'not restored, on a site with another prefix: its name here' );
		$this->assertStringContainsString( 'REFERENCES `wcptmp_a` (`id`)', $sql, 'itself: the temporary name' );
	}

	public function test_the_auto_increment_option_is_read_as_its_digits(): void {
		$columns = '(`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))';
		$this->assertSame( '5000', self::read( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=MyISAM AUTO_INCREMENT=5000 DEFAULT CHARSET=utf8mb4', 'wp_a' )->auto_increment() );
		$this->assertSame( '18446744073709551615', self::read( 'CREATE TABLE `wp_a` ' . $columns . ' auto_increment = 18446744073709551615', 'wp_a' )->auto_increment(), 'any case, spaces, beyond PHP_INT_MAX' );
		$this->assertSame( '', self::read( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=MyISAM', 'wp_a' )->auto_increment(), 'the column attribute is not the table option' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function refused(): array {
		$columns = '(`id` int NOT NULL, PRIMARY KEY (`id`))';
		return array(
			'another table'                             => array( 'CREATE TABLE `wp_b` ' . $columns, 'names another table' ),
			'temporary'                                 => array( 'CREATE TEMPORARY TABLE `wp_a` ' . $columns, 'not CREATE TABLE' ),
			'if not exists'                             => array( 'CREATE TABLE IF NOT EXISTS `wp_a` ' . $columns, 'names another table' ),
			'a copy of a query'                         => array( 'CREATE TABLE `wp_a` ' . $columns . ' SELECT * FROM `wp_users`', 'SELECT' ),
			'a copy of a query (as)'                    => array( 'CREATE TABLE `wp_a` ' . $columns . ' AS SELECT 1', 'AS' ),
			'like'                                      => array( 'CREATE TABLE `wp_a` LIKE `wp_users`', 'column list' ),
			'federated'                                 => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=FEDERATED CONNECTION=\'mysql://x@h/db/t\'', 'engine' ),
			'connection alone'                          => array( 'CREATE TABLE `wp_a` ' . $columns . ' CONNECTION=\'mysql://x@h/db/t\'', 'CONNECTION' ),
			'merge'                                     => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=MRG_MyISAM', 'engine' ),
			'connect engine'                            => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=CONNECT', 'engine' ),
			'archive engine'                            => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=ARCHIVE', 'ARCHIVE engine: its rows cannot be removed' ),
			'archive engine of a partition'             => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=InnoDB /*!50100 PARTITION BY HASH (`id`) (PARTITION p0 ENGINE = ARCHIVE) */', 'Leave the table out of the restore' ),
			'data directory'                            => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=InnoDB DATA DIRECTORY=\'/tmp\'', 'DIRECTORY' ),
			'directory in a comment'                    => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=InnoDB /*!50100 PARTITION BY HASH (`id`) (PARTITION p0 DATA DIRECTORY = \'/tmp\') */', 'DIRECTORY' ),
			'inline references'                         => array( 'CREATE TABLE `wp_a` (`id` int NOT NULL REFERENCES `wp_users` (`ID`), PRIMARY KEY (`id`))', 'inline REFERENCES' ),
			'another database'                          => array( 'CREATE TABLE `wp_a` (`id` int NOT NULL, PRIMARY KEY (`id`), CONSTRAINT `f` FOREIGN KEY (`id`) REFERENCES `other`.`t` (`id`))', 'another database' ),
			'an unnamed foreign key'                    => array( 'CREATE TABLE `wp_a` (`id` int NOT NULL, PRIMARY KEY (`id`), FOREIGN KEY (`id`) REFERENCES `wp_b` (`id`))', 'without a name' ),
			'system versioning items'                   => array( 'CREATE TABLE `wp_a` (`id` int NOT NULL, PERIOD FOR SYSTEM_TIME (`s`, `e`))', 'does not create (PERIOD)' ),
			'two primary keys'                          => array( 'CREATE TABLE `wp_a` (`id` int NOT NULL, PRIMARY KEY (`id`), PRIMARY KEY (`id`))', 'two primary keys' ),
			'a column twice'                            => array( 'CREATE TABLE `wp_a` (`id` int, `id` int)', 'twice' ),
			'a semicolon hidden in a versioned comment' => array( 'CREATE TABLE `wp_a` ' . $columns . ' /*!50100 ; DROP TABLE `wp_users` */', 'semicolon inside a versioned comment' ),
			'an auto_increment that is not a number'    => array( 'CREATE TABLE `wp_a` ' . $columns . ' ENGINE=MyISAM AUTO_INCREMENT=DEFAULT', 'AUTO_INCREMENT option without a number' ),
		);
	}

	/**
	 * @dataProvider refused
	 */
	public function test_definitions_the_restore_does_not_create_are_refused( string $sql, string $reason ): void {
		$this->expectException( Refused::class );
		$this->expectExceptionMessage( $reason );
		self::read( $sql, 'wp_a' );
	}

	public function test_constraint_names(): void {
		$names = new ConstraintNames( '1a2b' );
		$this->assertSame(
			array(
				'name'      => 'wcptmp_x_ibfk_3',
				'intended'  => 'wp2_x_ibfk_3',
				'shortened' => false,
			),
			$names->choose( 'wp_x_ibfk_3', 'ibfk', 'wp_x', 'wcptmp_x', 'wp2_x', 0 ),
			'the generated form of the backup\'s table: the temporary table\'s; meant to end as the final table\'s'
		);
		$this->assertSame( 'wcp1a2b_0_wp_y_ibfk_3', $names->choose( 'wp_y_ibfk_3', 'ibfk', 'wp_x', 'wcptmp_x', 'wp_x', 0 )['name'], 'another table\'s generated form is just a name' );
		$this->assertSame( 'wcp1a2b_0_wp_x_ibfk_03', $names->choose( 'wp_x_ibfk_03', 'ibfk', 'wp_x', 'wcptmp_x', 'wp_x', 0 )['name'], 'not the generated form' );
		$this->assertSame( 'wcptmp_x_chk_1', $names->choose( 'wp_x_chk_1', 'chk', 'wp_x', 'wcptmp_x', 'wp_x', 0 )['name'] );
		$this->assertSame( 'wcp1a2b_0_wp_x_chk_1', $names->choose( 'wp_x_chk_1', 'ibfk', 'wp_x', 'wcptmp_x', 'wp_x', 0 )['name'], 'the suffix of the other kind' );

		$long_final = str_repeat( 'f', 58 );
		$this->assertSame( 'wcp1a2b_0_wp_x_ibfk_1', $names->choose( 'wp_x_ibfk_1', 'ibfk', 'wp_x', 'wcptmp_x', $long_final, 0 )['name'], 'the final generated name would not fit 64 bytes: a marker instead' );
		$this->assertSame( 'wcp1a2b_z_1', $names->choose( '1', 'ibfk', 'wp_x', 'wcptmp_x', 'wp_x', 35 )['name'], 'the table number keeps equal names of two tables apart' );

		$woo = 'fk_wp_wc_download_log_permission_id';
		$this->assertStringEndsWith( $woo, $names->choose( $woo, 'ibfk', 'wp_wc_download_log', 'wcptmp_log', 'wp_wc_download_log', 12 )['name'], 'the whole name is still there for strpos()' );

		$long  = str_repeat( 'n', 60 );
		$short = $names->choose( $long, 'ibfk', 'wp_x', 'wcptmp_x', 'wp_x', 0 );
		$this->assertTrue( $short['shortened'] );
		$this->assertSame( 64, strlen( $short['name'] ) );
		$this->assertSame( $long, $short['intended'] );
		$utf8 = $names->choose( str_repeat( 'é', 30 ), 'ibfk', 'wp_x', 'wcptmp_x', 'wp_x', 0 )['name'];
		$this->assertTrue( 1 === preg_match( '//u', $utf8 ), 'no character cut in two' );
		$this->assertLessThanOrEqual( 64, strlen( $utf8 ) );
	}
}

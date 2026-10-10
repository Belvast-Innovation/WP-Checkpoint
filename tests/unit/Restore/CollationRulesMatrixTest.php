<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\CollationRules;
use WPCheckpoint\Tests\Fixtures\Restore\CollationSamples;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The rule table against what the servers measured (collation-matrix.expected.php, written from
 * collation-matrix.php on every supported server): for every row, on every server that creates the candidate, the
 * candidate makes no two sample strings equal that the source keeps apart; the equalities it loses are exactly the
 * recorded ones; and it keeps the source's sensitivity (the case pairs and the accent pairs compare as under the
 * source). The source's own behaviour is read where the name is the collation itself, not an alias. A row whose source
 * or candidate no server was measured with is refused too: a name nobody can create is a dead row, and one measured
 * nowhere natively has nothing to be judged against.
 */
final class CollationRulesMatrixTest extends TestCase {

	/**
	 * @return array{native: array<string, string[]>, lost: array<string, array<string, string[]>>, servers: array<string, array<string, string>>}
	 */
	private static function expected(): array {
		return require dirname( __DIR__, 2 ) . '/Fixtures/Restore/collation-matrix.expected.php';
	}

	/**
	 * The servers that create tables with $name.
	 *
	 * @param array<string, array<string, string>> $servers Group => observations.
	 * @return string[]
	 */
	private static function creating( array $servers, string $name ): array {
		$groups = array();
		foreach ( $servers as $group => $observed ) {
			if ( 'ok' === ( $observed[ 'name.' . $name . '.created' ] ?? '' ) ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * The equality vector of $name on $group: pair id => '1' | '0'; null when not measured there.
	 *
	 * @param array<string, string> $observed Observations of one group.
	 * @return array<string, string>|null
	 */
	private static function vector( array $observed, string $name ) {
		$vector = array();
		foreach ( array_keys( CollationSamples::pairs() ) as $id ) {
			$value = $observed[ 'eq.' . $name . '.' . $id ] ?? null;
			if ( '1' !== $value && '0' !== $value ) {
				return null;
			}
			$vector[ $id ] = $value;
		}
		return $vector;
	}

	/**
	 * The servers where $name is the collation itself (the expectations' "native" entries), among those creating it.
	 *
	 * @param array<string, string[]> $native Pattern => groups.
	 * @param string[]                $creating Groups creating the name.
	 * @return string[]
	 */
	private static function native( array $native, string $name, array $creating ): array {
		foreach ( $native as $pattern => $groups ) {
			if ( false !== strpos( $name, $pattern ) ) {
				return array_values( array_intersect( $groups, $creating ) );
			}
		}
		return $creating;
	}

	/**
	 * Every way $rules fall short of the measured servers, as messages; none when they hold.
	 *
	 * @param array<string, string[]>                                                                                                      $rules    Source => candidates.
	 * @param array{native: array<string, string[]>, lost: array<string, array<string, string[]>>, servers: array<string, array<string, string>>} $expected The expectations.
	 * @return string[]
	 */
	public static function problems( array $rules, array $expected ): array {
		$problems = array();
		$servers  = $expected['servers'];
		$pairs    = CollationSamples::pairs();
		foreach ( $rules as $source => $candidates ) {
			$natives = self::native( $expected['native'], $source, self::creating( $servers, $source ) );
			if ( array() === $natives ) {
				$problems[] = "$source: measured natively on no server, so there is nothing to judge its candidates against";
				continue;
			}
			$reference = null;
			foreach ( $natives as $group ) {
				$vector = self::vector( $servers[ $group ], $source );
				if ( null === $vector ) {
					$problems[] = "$source: no equality vector on $group";
					continue 2;
				}
				if ( null === $reference ) {
					$reference = $vector;
				} elseif ( $reference !== $vector ) {
					$problems[] = "$source: the servers it is native on disagree ($group differs from {$natives[0]})";
					continue 2;
				}
			}
			// The control: the sample shows the sensitivity the source's name promises.
			foreach ( CollationSamples::promised( $source ) as $class => $promised ) {
				foreach ( $pairs as $id => $pair ) {
					if ( null !== $promised && $class === $pair[2] && $promised !== $reference[ $id ] ) {
						$problems[] = "$source: the sample pair $id does not show what the name promises for its $class pairs";
					}
				}
			}
			foreach ( $candidates as $candidate ) {
				if ( $candidate === $source ) {
					$problems[] = "$source: is its own candidate";
					continue;
				}
				$creating = self::creating( $servers, $candidate );
				if ( array() === $creating ) {
					$problems[] = "$source -> $candidate: no measured server creates tables with the candidate";
					continue;
				}
				$known = $expected['lost'][ $source ][ $candidate ] ?? array();
				sort( $known );
				foreach ( $creating as $group ) {
					$vector = self::vector( $servers[ $group ], $candidate );
					if ( null === $vector ) {
						$problems[] = "$source -> $candidate: no equality vector on $group";
						continue;
					}
					$added = array();
					$lost  = array();
					$class = array();
					foreach ( $pairs as $id => $pair ) {
						if ( '0' === $reference[ $id ] && '1' === $vector[ $id ] ) {
							$added[] = $id;
						} elseif ( '1' === $reference[ $id ] && '0' === $vector[ $id ] ) {
							$lost[] = $id;
						}
						if ( ( CollationSamples::CASE === $pair[2] || CollationSamples::ACCENT === $pair[2] ) && $reference[ $id ] !== $vector[ $id ] ) {
							$class[] = $id;
						}
					}
					if ( array() !== $added ) {
						$problems[] = "$source -> $candidate on $group: makes equal what the source keeps apart: " . implode( ', ', $added );
					}
					sort( $lost );
					if ( $lost !== $known ) {
						$problems[] = "$source -> $candidate on $group: loses [" . implode( ', ', $lost ) . '], recorded as [' . implode( ', ', $known ) . ']';
					}
					if ( array() !== $class ) {
						$problems[] = "$source -> $candidate on $group: changes the source's sensitivity on: " . implode( ', ', $class );
					}
				}
			}
		}
		foreach ( $expected['lost'] as $source => $candidates ) {
			foreach ( $candidates as $candidate => $ids ) {
				if ( ! in_array( $candidate, $rules[ $source ] ?? array(), true ) ) {
					$problems[] = "$source -> $candidate: a recorded loss for a row the table does not have";
				}
			}
		}
		return $problems;
	}

	public function test_every_row_holds_on_every_measured_server(): void {
		$expected = self::expected();
		$this->assertSame( array(), self::problems( CollationRules::CANDIDATES, $expected ) );
		$this->assertGreaterThanOrEqual( 6, count( $expected['servers'] ), 'the control: the matrix covers the server groups' );
		$this->assertGreaterThanOrEqual( 10, count( CollationRules::CANDIDATES ), 'the control: the table is not empty' );
	}

	public function test_every_name_of_the_table_was_measured_on_every_server(): void {
		$expected = self::expected();
		foreach ( CollationSamples::names() as $name ) {
			foreach ( $expected['servers'] as $group => $observed ) {
				$this->assertArrayHasKey( 'name.' . $name . '.created', $observed, "$name on $group: measure the matrix again (a new row names it)" );
			}
		}
		$this->assertContains( 'utf8mb4_unicode_520_ci', CollationSamples::names(), 'the control: a candidate of the reverse direction is among the names' );
	}

	/**
	 * @return array<string, array{0: array<string, string[]>, 1: callable|null, 2: string}>
	 */
	public static function tampered(): array {
		$pad_space_candidate = array( 'utf8mb4_0900_ai_ci' => array( 'utf8mb4_unicode_520_ci' ) );
		$binary_candidate    = array( 'utf8mb4_0900_ai_ci' => array( 'utf8mb4_bin' ) );
		$general_candidate   = array( 'utf8mb4_0900_as_cs' => array( 'utf8mb4_general_ci' ) );
		$unrecorded_loss     = array( 'utf8mb4_uca1400_as_ci' => array( 'utf8mb4_0900_as_ci' ) );
		$language_specific   = array( 'utf8mb4_ja_0900_as_cs' => array( 'utf8mb4_uca1400_nopad_as_cs' ) );
		$dead_candidate      = array( 'utf8mb4_0900_ai_ci' => array( 'utf8mb4_uca1400_nopad_ai_cs' ) );
		$good_row            = array( 'utf8mb4_0900_ai_ci' => array( 'utf8mb4_uca1400_nopad_ai_ci' ) );
		return array(
			'a PAD SPACE candidate makes trailing-space strings equal'  => array( $pad_space_candidate, null, 'makes equal what the source keeps apart: pad_one, pad_two' ),
			'a binary candidate loses case-insensitivity'               => array( $binary_candidate, null, "changes the source's sensitivity on: case_ascii" ),
			'a general candidate for a sensitive source adds equalities' => array( $general_candidate, null, 'makes equal what the source keeps apart: case_ascii' ),
			'a loss not recorded'                                        => array(
				$unrecorded_loss,
				static function ( array $expected ): array {
					$expected['lost'] = array();
					return $expected;
				},
				'loses [pad_one, pad_two], recorded as []',
			),
			'a loss recorded that does not happen'                       => array(
				$good_row,
				static function ( array $expected ): array {
					$expected['lost'] = array( 'utf8mb4_0900_ai_ci' => array( 'utf8mb4_uca1400_nopad_ai_ci' => array( 'pad_one' ) ) );
					return $expected;
				},
				'loses [], recorded as [pad_one]',
			),
			'a recorded loss for a row the table does not have'         => array(
				$good_row,
				static function ( array $expected ): array {
					$expected['lost'] = array( 'utf8mb4_0900_bin' => array( 'utf8mb4_nopad_bin' => array( 'pad_one' ) ) );
					return $expected;
				},
				'utf8mb4_0900_bin -> utf8mb4_nopad_bin: a recorded loss for a row the table does not have',
			),
			'a language-specific source differs on other pairs'         => array( $language_specific, null, 'utf8mb4_ja_0900_as_cs -> utf8mb4_uca1400_nopad_as_cs on mariadb-10.11: loses [digits, kana_kind, kana_width], recorded as []' ),
			'a candidate no server creates (a typo)'                     => array( $dead_candidate, null, 'no measured server creates tables with the candidate' ),
			'a source measured natively nowhere'                         => array(
				$good_row,
				static function ( array $expected ): array {
					$expected['native']['_0900_'] = array( 'mysql-5.7' );
					return $expected;
				},
				'measured natively on no server',
			),
			'a source whose natives disagree'                            => array(
				array( 'utf8mb4_uca1400_ai_ci' => array( 'utf8mb4_unicode_520_ci' ) ),
				static function ( array $expected ): array {
					$expected['servers']['mariadb-12']['eq.utf8mb4_uca1400_ai_ci.eszett'] = '0' === $expected['servers']['mariadb-12']['eq.utf8mb4_uca1400_ai_ci.eszett'] ? '1' : '0';
					return $expected;
				},
				'the servers it is native on disagree',
			),
			'a source the sample does not show the sensitivity of'      => array(
				array( 'utf8mb4_0900_as_cs' => array( 'utf8mb4_uca1400_nopad_as_cs' ) ),
				static function ( array $expected ): array {
					$expected['servers']['mysql-8']['eq.utf8mb4_0900_as_cs.case_word'] = '1';
					return $expected;
				},
				'the sample pair case_word does not show what the name promises for its ci pairs',
			),
		);
	}

	/**
	 * The reverse check: each way a row can fall short is found.
	 *
	 * @dataProvider tampered
	 *
	 * @param array<string, string[]> $rules   Rules.
	 * @param callable|null           $tamper  What to change in the expectations.
	 * @param string                  $problem What the check must say.
	 */
	public function test_a_row_that_falls_short_is_found( array $rules, $tamper, string $problem ): void {
		$expected = self::expected();
		if ( null !== $tamper ) {
			$expected = $tamper( $expected );
		}
		$this->assertStringContainsString( $problem, implode( "\n", self::problems( $rules, $expected ) ) );
	}
}

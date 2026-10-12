<?php
/**
 * The names and the sample pairs the collation matrix measures (collation-matrix.php) and the rule table is judged on
 * (tests/unit/Restore/CollationRulesMatrixTest.php): one place, so the two cannot drift.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Tests\Fixtures\Restore;

use WPCheckpoint\Restore\CollationRules;

/**
 * Pure PHP.
 */
final class CollationSamples {

	const CASE            = 'ci';
	const ACCENT          = 'ai';
	const PAD             = 'pad';
	const UNICODE_VERSION = 'unicode_version';
	const OTHER           = 'other';

	/**
	 * The classes a candidate may lose equalities of (when recorded in the expectations): a difference between UCA
	 * versions on single characters, and the trailing-space equality when PAD SPACE is written as NO PAD. A loss of
	 * any other class refuses the row, recorded or not.
	 */
	const LOSS_CLASSES = array( self::UNICODE_VERSION, self::PAD );

	/**
	 * The names of interest: the names the restore's design measured, and every source and candidate of the rule
	 * table (a row whose name is not measured has no expectations, and the matrix test says so).
	 *
	 * @return string[]
	 */
	public static function names(): array {
		$names = array(
			'utf8mb4_general_ci',
			'utf8mb4_unicode_ci',
			'utf8mb4_unicode_520_ci',
			'utf8mb4_unicode_520_nopad_ci',
			'utf8mb4_bin',
			'utf8mb4_nopad_bin',
			'utf8mb4_0900_ai_ci',
			'utf8mb4_0900_as_ci',
			'utf8mb4_0900_as_cs',
			'utf8mb4_0900_bin',
			'utf8mb4_de_pb_0900_ai_ci',
			'utf8mb4_ja_0900_as_cs',
			'utf8mb4_uca1400_ai_ci',
			'utf8mb4_uca1400_as_ci',
			'utf8mb4_uca1400_as_cs',
			'utf8mb4_uca1400_nopad_ai_ci',
			'utf8mb4_uca1400_nopad_as_ci',
			'utf8mb4_uca1400_nopad_as_cs',
		);
		foreach ( CollationRules::CANDIDATES as $source => $candidates ) {
			$names[] = $source;
			foreach ( $candidates as $candidate ) {
				$names[] = $candidate;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The sample pairs: id => [left, right, class]. The class says what the pair tells: CASE differs by case only,
	 * ACCENT by accent only, PAD by trailing spaces only, UNICODE_VERSION two characters that UCA versions weigh
	 * differently (two supplementary-plane emoji: equal under UCA 4.0.0's utf8mb4_unicode_ci, which has no weights for
	 * them, apart from UCA 5.2.0 on), OTHER anything else (recorded; judged as new or lost equalities only). A pair of
	 * a class in LOSS_CLASSES must be witnessed (witnesses()), or the label is only a claim.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function pairs(): array {
		return array(
			'case_ascii'       => array( 'a', 'A', self::CASE ),
			'case_word'        => array( 'WordPress', 'wordpress', self::CASE ),
			'case_accented'    => array( 'é', 'É', self::CASE ),
			'accent_a'         => array( 'a', 'á', self::ACCENT ),
			'accent_e'         => array( 'e', 'é', self::ACCENT ),
			'accent_u_umlaut'  => array( 'u', 'ü', self::ACCENT ),
			'accent_n_tilde'   => array( 'n', 'ñ', self::ACCENT ),
			'accent_c_cedilla' => array( 'c', 'ç', self::ACCENT ),
			'pad_one'          => array( 'a', 'a ', self::PAD ),
			'pad_two'          => array( 'ab', 'ab  ', self::PAD ),
			'nfc_nfd'          => array( "\u{E9}", "e\u{301}", self::OTHER ),
			'eszett'           => array( 'ß', 'ss', self::OTHER ),
			'ae_ligature'      => array( 'æ', 'ae', self::OTHER ),
			'oe_slash'         => array( 'ø', 'o', self::OTHER ),
			'turkish_dotted'   => array( 'i', 'İ', self::OTHER ),
			'turkish_dotless'  => array( 'ı', 'I', self::OTHER ),
			'greek_sigma'      => array( 'σ', 'ς', self::OTHER ),
			'kana_width'       => array( 'ｱ', 'ア', self::OTHER ),
			'kana_kind'        => array( 'あ', 'ア', self::OTHER ),
			'cjk_two'          => array( '中', '國', self::OTHER ),
			'emoji_two'        => array( '😀', '😁', self::UNICODE_VERSION ),
			'emoji_skin'       => array( '👍', '👍🏽', self::OTHER ),
			'digits'           => array( '1', '１', self::OTHER ),
			'control'          => array( 'a', 'b', self::OTHER ),
		);
	}

	/**
	 * The collation pairs that differ only in what $class names, which a sample pair of that class must be told apart
	 * by: for UNICODE_VERSION, two UCA versions with the same pad mode; for PAD, the PAD SPACE and NO PAD variants of
	 * one version.
	 *
	 * @param string $class A class of LOSS_CLASSES.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function witnesses( string $class ): array {
		if ( self::UNICODE_VERSION === $class ) {
			return array(
				array( 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci' ),
				array( 'utf8mb4_unicode_520_ci', 'utf8mb4_uca1400_ai_ci' ),
				array( 'utf8mb4_unicode_520_nopad_ci', 'utf8mb4_uca1400_nopad_ai_ci' ),
				array( 'utf8mb4_unicode_520_nopad_ci', 'utf8mb4_0900_ai_ci' ),
				array( 'utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_nopad_ai_ci' ),
			);
		}
		if ( self::PAD === $class ) {
			return array(
				array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_nopad_ci' ),
				array( 'utf8mb4_uca1400_ai_ci', 'utf8mb4_uca1400_nopad_ai_ci' ),
				array( 'utf8mb4_uca1400_as_ci', 'utf8mb4_uca1400_nopad_as_ci' ),
				array( 'utf8mb4_uca1400_as_cs', 'utf8mb4_uca1400_nopad_as_cs' ),
				array( 'utf8mb4_bin', 'utf8mb4_nopad_bin' ),
			);
		}
		return array();
	}

	/**
	 * The sample pairs of a loss class that no server witnesses, as messages: on every server (group => observations,
	 * eq.<collation>.<pair> => '1' | '0') that creates both collations of at least one witness pair of the class, at
	 * least one witness pair must compare the sample pair differently; and at least one server must have been able
	 * to. A server with no witness pair created is not asked. A label without a witness is only a claim: a wrongly
	 * labelled pair, or a measurement that could not see the difference (emoji sent over a connection whose
	 * character set turns them into question marks), is found here.
	 *
	 * @param array<string, array<string, string>>           $servers Group => observations.
	 * @param array<string, array{0: string, 1: string, 2: string}> $pairs   The sample pairs (pairs() unless a test says otherwise).
	 * @return string[]
	 */
	public static function unwitnessed( array $servers, array $pairs = null ): array {
		$problems = array();
		foreach ( null === $pairs ? self::pairs() : $pairs as $id => $pair ) {
			$class = $pair[2];
			if ( ! in_array( $class, self::LOSS_CLASSES, true ) ) {
				continue;
			}
			$able = array();
			foreach ( $servers as $group => $observed ) {
				$seen = false;
				foreach ( self::witnesses( $class ) as $witness ) {
					$a = $observed[ 'eq.' . $witness[0] . '.' . $id ] ?? null;
					$b = $observed[ 'eq.' . $witness[1] . '.' . $id ] ?? null;
					if ( null === $a || null === $b ) {
						continue;
					}
					$seen = true;
					if ( $a !== $b ) {
						continue 2;
					}
				}
				if ( $seen ) {
					$able[] = $group;
				}
			}
			if ( array() !== $able ) {
				$problems[] = "$id ($class): no witness pair tells it apart on " . implode( ', ', $able );
			} elseif ( array() === array_filter(
				$servers,
				static function ( array $observed ) use ( $class, $id ): bool {
					foreach ( self::witnesses( $class ) as $witness ) {
						if ( isset( $observed[ 'eq.' . $witness[0] . '.' . $id ], $observed[ 'eq.' . $witness[1] . '.' . $id ] ) ) {
							return true;
						}
					}
					return false;
				}
			) ) {
				$problems[] = "$id ($class): no server creates a witness pair for it";
			}
		}
		return $problems;
	}

	/**
	 * What a collation's name promises on the sample: for the CASE and ACCENT classes, whether the pairs compare
	 * equal ('1') or not ('0') under it; null for a class the name says nothing about.
	 *
	 * @param string $name Collation.
	 * @return array<string, string|null> Class => '1' | '0' | null.
	 */
	public static function promised( string $name ): array {
		$name = strtolower( $name );
		if ( '_bin' === substr( $name, -4 ) ) {
			return array(
				self::CASE   => '0',
				self::ACCENT => '0',
			);
		}
		$case = null;
		if ( '_ci' === substr( $name, -3 ) ) {
			$case = '1';
		} elseif ( '_cs' === substr( $name, -3 ) ) {
			$case = '0';
		}
		$accent = null;
		if ( false !== strpos( $name, '_ai_' ) ) {
			$accent = '1';
		} elseif ( false !== strpos( $name, '_as_' ) ) {
			$accent = '0';
		}
		return array(
			self::CASE   => $case,
			self::ACCENT => $accent,
		);
	}
}

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

	const CASE   = 'ci';
	const ACCENT = 'ai';
	const PAD    = 'pad';
	const OTHER  = 'other';

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
	 * ACCENT by accent only, PAD by trailing spaces only, OTHER anything else (recorded; judged as new or lost
	 * equalities only).
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
			'emoji_two'        => array( '😀', '😁', self::OTHER ),
			'emoji_skin'       => array( '👍', '👍🏽', self::OTHER ),
			'digits'           => array( '1', '１', self::OTHER ),
			'control'          => array( 'a', 'b', self::OTHER ),
		);
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

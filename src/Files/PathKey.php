<?php
/**
 * The key under which two file names count as the same file.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Files;

/**
 * Windows and the default macOS file system fold case, and macOS also
 * normalises Unicode, so "Foo.txt" and "foo.txt", or a name in NFC and the
 * same name in NFD, land on one file when an archive is extracted there.
 * The scanner warns about such siblings and the importer refuses them;
 * both use this key so they agree. Normalisation needs the intl
 * extension; without it the key folds case only, and callers say so to
 * the user (see normalization_available()).
 */
final class PathKey {

	/**
	 * The comparison key of one path segment or path.
	 *
	 * @param string $name File or path name (valid UTF-8).
	 * @return string
	 */
	public static function of( string $name ): string {
		if ( self::normalization_available() ) {
			$normalized = \Normalizer::normalize( $name, \Normalizer::FORM_C );
			if ( is_string( $normalized ) ) {
				$name = $normalized;
			}
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $name, 'UTF-8' );
		}
		return strtolower( $name );
	}

	/**
	 * Whether Unicode normalisation is available (intl extension).
	 *
	 * @return bool
	 */
	public static function normalization_available(): bool {
		return class_exists( '\Normalizer' );
	}

	/**
	 * Groups of names that share a key, among a list of sibling names.
	 *
	 * @param string[] $names Sibling names.
	 * @return array<int, string[]> Each group lists two or more names.
	 */
	public static function collisions( array $names ): array {
		$by_key = array();
		foreach ( $names as $name ) {
			$by_key[ self::of( $name ) ][] = $name;
		}
		$out = array();
		foreach ( $by_key as $group ) {
			if ( count( $group ) > 1 ) {
				$out[] = $group;
			}
		}
		return $out;
	}
}

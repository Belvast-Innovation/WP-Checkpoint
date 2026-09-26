<?php
/**
 * How a file system compares names, as a probe found it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. Two paths of a backup land on one file only when the file
 * system they are staged on says so: ext4 keeps "Foo" and "foo" apart,
 * NTFS and the default macOS file system fold case, macOS also treats a
 * name in NFC and in NFD as one, and Windows drops a trailing dot or space
 * of every segment. DirectoryProbe finds out which of these the staging
 * parent does; key() is the name under which two paths count as one file
 * there, and nothing more is folded than the probe saw folded.
 *
 * Without the intl extension NFC and NFD cannot be told apart here: on a
 * file system that normalises, such pairs go unnoticed (approximate()
 * says so, and the preflight logs it).
 */
final class TargetNames {

	/**
	 * "Aé" found under "aé": ASCII letters fold.
	 *
	 * @var bool
	 */
	private $fold_ascii;

	/**
	 * "aÉ" found under "aé": other letters fold too.
	 *
	 * @var bool
	 */
	private $fold_unicode;

	/**
	 * "ae" + combining acute found under "aé" (NFC): forms are one name.
	 *
	 * @var bool
	 */
	private $normalize;

	/**
	 * "b" found under "b.": a trailing dot or space of a segment is dropped.
	 *
	 * @var bool
	 */
	private $trim_trailing;

	/**
	 * Constructor.
	 *
	 * @param bool $fold_ascii    ASCII case folds.
	 * @param bool $fold_unicode  Other letters' case folds.
	 * @param bool $normalize     Unicode forms are one name.
	 * @param bool $trim_trailing Trailing dots and spaces of a segment are dropped.
	 */
	public function __construct( bool $fold_ascii, bool $fold_unicode, bool $normalize, bool $trim_trailing ) {
		$this->fold_ascii    = $fold_ascii;
		$this->fold_unicode  = $fold_unicode;
		$this->normalize     = $normalize;
		$this->trim_trailing = $trim_trailing;
	}

	/**
	 * The behaviour as a list of flags (for a cursor).
	 *
	 * @return array{fold_ascii: bool, fold_unicode: bool, normalize: bool, trim_trailing: bool}
	 */
	public function to_array(): array {
		return array(
			'fold_ascii'    => $this->fold_ascii,
			'fold_unicode'  => $this->fold_unicode,
			'normalize'     => $this->normalize,
			'trim_trailing' => $this->trim_trailing,
		);
	}

	/**
	 * From to_array().
	 *
	 * @param array<string, mixed> $flags Flags.
	 * @return TargetNames
	 */
	public static function from_array( array $flags ): TargetNames {
		return new self( ! empty( $flags['fold_ascii'] ), ! empty( $flags['fold_unicode'] ), ! empty( $flags['normalize'] ), ! empty( $flags['trim_trailing'] ) );
	}

	/**
	 * Whether key() may miss pairs this file system treats as one (it normalises, and intl is missing).
	 *
	 * @return bool
	 */
	public function approximate(): bool {
		return $this->normalize && ! class_exists( '\Normalizer' );
	}

	/**
	 * The name under which a relative path counts as one file on this file system.
	 *
	 * @param string $path Relative path, "/"-separated.
	 * @return string
	 */
	public function key( string $path ): string {
		$utf8 = function_exists( 'mb_check_encoding' ) && mb_check_encoding( $path, 'UTF-8' );
		if ( $this->normalize && $utf8 && class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $path, \Normalizer::FORM_C );
			if ( is_string( $normalized ) ) {
				$path = $normalized;
			}
		}
		if ( $this->trim_trailing ) {
			$path = implode(
				'/',
				array_map(
					static function ( string $segment ): string {
						return rtrim( $segment, '. ' );
					},
					explode( '/', $path )
				)
			);
		}
		if ( $this->fold_unicode && $utf8 && function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $path, 'UTF-8' );
		}
		// ASCII letters only, whatever the locale (strtolower() follows it before PHP 8.2).
		return $this->fold_ascii ? strtr( $path, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' ) : $path;
	}
}

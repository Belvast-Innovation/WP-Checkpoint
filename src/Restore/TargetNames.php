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
 * says so, and the preflight logs it). Unicode case is folded as
 * mb_strtolower() folds it, which is close to but not the same as NTFS's
 * and APFS's tables (final sigma, sharp s, the Kelvin sign): a few pairs
 * are missed or refused wrongly.
 *
 * A file system that refuses "<" in a name (the Win32 namespace: NTFS,
 * FAT, SMB shares served by Windows) also refuses the characters
 * < > : " | ? * and the device names CON, PRN, AUX, NUL, COM1-9, LPT1-9
 * (with anything after a first dot; newer Windows allows some of these,
 * refusing them is the safe side); unstorable() finds such a segment.
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
	 * "c<d" cannot be created: the Win32 namespace.
	 *
	 * @var bool
	 */
	private $win32;

	/**
	 * Constructor.
	 *
	 * @param bool $fold_ascii    ASCII case folds.
	 * @param bool $fold_unicode  Other letters' case folds.
	 * @param bool $normalize     Unicode forms are one name.
	 * @param bool $trim_trailing Trailing dots and spaces of a segment are dropped.
	 * @param bool $win32         Names follow the Win32 rules ("<" refused).
	 */
	public function __construct( bool $fold_ascii, bool $fold_unicode, bool $normalize, bool $trim_trailing, bool $win32 = false ) {
		$this->fold_ascii    = $fold_ascii;
		$this->fold_unicode  = $fold_unicode;
		$this->normalize     = $normalize;
		$this->trim_trailing = $trim_trailing;
		$this->win32         = $win32;
	}

	/**
	 * The behaviour as a list of flags (for a cursor).
	 *
	 * @return array{fold_ascii: bool, fold_unicode: bool, normalize: bool, trim_trailing: bool, win32: bool}
	 */
	public function to_array(): array {
		return array(
			'fold_ascii'    => $this->fold_ascii,
			'fold_unicode'  => $this->fold_unicode,
			'normalize'     => $this->normalize,
			'trim_trailing' => $this->trim_trailing,
			'win32'         => $this->win32,
		);
	}

	/**
	 * From to_array().
	 *
	 * @param array<string, mixed> $flags Flags.
	 * @return TargetNames
	 */
	public static function from_array( array $flags ): TargetNames {
		return new self( ! empty( $flags['fold_ascii'] ), ! empty( $flags['fold_unicode'] ), ! empty( $flags['normalize'] ), ! empty( $flags['trim_trailing'] ), ! empty( $flags['win32'] ) );
	}

	/**
	 * The first segment of a relative path this file system cannot store, or null.
	 *
	 * @param string $path Relative path, "/"-separated.
	 * @return string|null
	 */
	public function unstorable( string $path ) {
		if ( ! $this->win32 ) {
			return null;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			// Win32 reserves a device name whatever follows its first dot; "¹²³" count as digits there.
			if ( false !== strpbrk( $segment, '<>:"|?*' ) || 1 === preg_match( '/\A(?:CON|PRN|AUX|NUL|(?:COM|LPT)(?:[1-9]|\xC2[\xB9\xB2\xB3]))(?:\..*)?\z/is', rtrim( $segment, '. ' ) ) ) {
				return $segment;
			}
		}
		return null;
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

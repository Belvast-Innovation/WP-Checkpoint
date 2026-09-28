<?php
/**
 * Where a custom storage directory may be.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Whether a custom storage directory (WPCHECKPOINT_STORAGE_DIR) would sit in one of WordPress's own directories.
 *
 * The plugin writes index.php, .htaccess and its sub-directories into its storage directory, and denies access to
 * all of it: in a directory WordPress serves from (the uploads, a theme, wp-admin) that changes what WordPress
 * serves, even when the directory is empty today (the uploads of a new site). So a custom storage directory may not
 * be one of WordPress's own directories, lie inside one, or hold one (an .htaccess applies to what is below it).
 * WordPress's content directory itself is refused too, but a directory inside it that belongs to none of the others
 * may be used.
 *
 * Paths are compared as written and where they lead (realpath(); for a directory that does not exist yet, its
 * nearest existing ancestor's real path and the rest as written), so a link or a second spelling does not get
 * around the rule. The caller refuses relative paths and paths with . or .. segments first
 * (Deleter::storage_refusal()).
 */
final class StorageLocation {

	/**
	 * Which of WordPress's directories a storage directory would be, lie in or hold: its label, or ''.
	 *
	 * @param string                $dir    The directory.
	 * @param array<string, string> $within directory => label: refused when $dir is it, lies inside it or holds it.
	 * @param array<string, string> $itself directory => label: refused when $dir is it or holds it (inside may be used).
	 * @return string
	 */
	public static function refusal( string $dir, array $within, array $itself ): string {
		$windows = Paths::is_windows();
		$forms   = self::forms( $dir );
		foreach ( array( false, true ) as $inside_refused ) { // The content directory first: it holds most of the others.
			foreach ( ( $inside_refused ? $within : $itself ) as $wordpress => $label ) {
				foreach ( self::forms( (string) $wordpress ) as $theirs ) {
					foreach ( $forms as $mine ) {
						if ( Paths::same( $mine, $theirs, $windows ) || Paths::is_prefix( $mine, $theirs, $windows ) || ( $inside_refused && Paths::is_prefix( $theirs, $mine, $windows ) ) ) {
							return (string) $label;
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * A path as written and where it leads (realpath(), or the nearest existing ancestor's real path followed by the
	 * rest as written), without trailing separators; the empty ones left out.
	 *
	 * @param string $path Path.
	 * @return string[]
	 */
	private static function forms( string $path ): array {
		$forms = array( rtrim( Paths::normalize( $path ), '/' ) );
		$tail  = '';
		$at    = rtrim( $path, '/\\' );
		for ( $i = 0; $i < 64 && '' !== $at; $i++ ) {
			$real = @realpath( $at ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			if ( false !== $real ) {
				$forms[] = rtrim( Paths::normalize( $real ), '/' ) . $tail;
				break;
			}
			$up = dirname( $at );
			if ( $up === $at ) {
				break;
			}
			$tail = '/' . basename( $at ) . $tail;
			$at   = $up;
		}
		return array_values(
			array_unique(
				array_filter(
					$forms,
					function ( string $form ): bool {
						return '' !== $form;
					}
				)
			)
		);
	}
}

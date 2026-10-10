<?php
/**
 * The one pipeline text goes through before it leaves the plugin or is written to a log.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Scrub (a cut multibyte character would make json_encode fail), neutralize terminal controls, redact (credentials,
 * e-mail addresses, database names in remote errors), then path and host placeholders (site identity), then backup
 * names. A masking failure yields a fixed text, never the text it could not mask. JobPresenter::clean() uses it for
 * every text that leaves the job engine, and Logger for every line it writes, so a log holds no raw host or path to
 * begin with: the masking where a log is shown or downloaded stays as a second line.
 */
final class TextMask {

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Placeholder => absolute path.
	 *
	 * @var array<string, string>
	 */
	private $paths;

	/**
	 * Site host names.
	 *
	 * @var string[]
	 */
	private $hosts;

	/**
	 * Site path prefixes (see Environment::report_site_paths()).
	 *
	 * @var array{paths: string[], coarse: bool, network_root: string}
	 */
	private $site_paths;

	/**
	 * Constructor.
	 *
	 * @param Redactor                                                   $redactor   Redactor.
	 * @param array<string, string>                                      $paths      Placeholder => path.
	 * @param string[]                                                   $hosts      Site hosts.
	 * @param array{paths: string[], coarse: bool, network_root: string} $site_paths Site paths.
	 */
	public function __construct( Redactor $redactor, array $paths, array $hosts, array $site_paths ) {
		$this->redactor   = $redactor;
		$this->paths      = $paths;
		$this->hosts      = $hosts;
		$this->site_paths = $site_paths;
	}

	/**
	 * The installation's mask: its paths (installation_paths()), with $extra first (placeholder => path, such as the
	 * storage directory), its hosts and its site paths.
	 *
	 * @param Redactor              $redactor Redactor.
	 * @param array<string, string> $extra    More placeholder => path.
	 * @return TextMask
	 */
	public static function for_installation( Redactor $redactor, array $extra = array() ): TextMask {
		return new self( $redactor, array_merge( $extra, self::installation_paths() ), Environment::report_hosts(), Environment::report_site_paths() );
	}

	/**
	 * A mask that only scrubs, neutralizes and redacts: no paths or hosts are known (tests that build a Logger
	 * outside WordPress). Not for the plugin's own loggers, which mask the installation (for_installation()).
	 *
	 * @param Redactor $redactor Redactor.
	 * @return TextMask
	 */
	public static function redact_only( Redactor $redactor ): TextMask {
		return new self(
			$redactor,
			array(),
			array(),
			array(
				'paths'        => array(),
				'coarse'       => false,
				'network_root' => '',
			)
		);
	}

	/**
	 * Paths masked in every text.
	 *
	 * @return array<string, string>
	 */
	public static function installation_paths(): array {
		$abspath = rtrim( ABSPATH, '/\\' );
		$paths   = array(
			'{abspath}'        => $abspath,
			'{abspath-parent}' => dirname( $abspath ),
			'{wp-content}'     => WP_CONTENT_DIR,
			'{tmp}'            => sys_get_temp_dir(),
		);
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
			$paths['{document-root}'] = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}
		return $paths;
	}

	/**
	 * The pipeline: scrub, neutralize terminal controls, redact, mask paths, mask hosts, mask backup names. Fails
	 * closed. Untrusted input reaches it (archive entry names, manifest strings), so it covers what a terminal, a log
	 * viewer or a ticket would interpret: escape sequences and bidirectional overrides are replaced, not passed on.
	 *
	 * @param string                $text  Text.
	 * @param array<string, string> $extra Extra placeholder => path, masked before the mask's own.
	 * @return string
	 */
	public function clean( string $text, array $extra = array() ): string {
		if ( '' === $text ) {
			return '';
		}
		$text   = $this->redactor->redact( Utf8::neutralize_controls( Utf8::scrub( $text ) ) );
		$masked = Report::mask_paths( $text, array_merge( $extra, $this->paths ) );
		if ( ! is_string( $masked ) ) {
			return Report::failure_text();
		}
		$masked = Report::mask_hosts( $masked, $this->hosts, $this->site_paths['paths'], $this->site_paths['coarse'], $this->site_paths['network_root'] );
		if ( ! is_string( $masked ) ) {
			return Report::failure_text();
		}
		// A backup's base name ({slug}-{date}-{time}-{hex}) carries the site's slug: the second line of
		// defence behind messages that refer to files by number. Fail-closed like the other masks. Masked
		// bare or with the suffixes the plugin gives it (volume, single archive, manifest, and what follows
		// those); a user's file that merely looks alike and goes on with another extension is left alone.
		$masked = preg_replace( '/[a-z0-9][a-z0-9-]*-\d{8}-\d{6}-[0-9a-f]{4}(?![a-z0-9-])(?!\.(?!(?:part\d{3,}\.)?wpcheckpoint\.|manifest\.json)[A-Za-z0-9])/', '[backup]', $masked );
		return is_string( $masked ) ? $masked : Report::failure_text();
	}
}

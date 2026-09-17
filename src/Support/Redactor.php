<?php
/**
 * Removes secrets from log text.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Pure text redaction. Every line the Logger writes passes through redact().
 *
 * Two layers: known secret values (database password, salts, stored
 * credentials) are replaced wherever they appear, in raw, JSON-escaped and
 * URL-encoded form; then generic patterns catch secrets by key name, URL
 * credentials, HTTP authorization headers, AWS access keys and e-mail
 * addresses.
 */
final class Redactor {

	/**
	 * Replacement text.
	 */
	const MASK = '[redacted]';

	/**
	 * Shortest secret value that is replaced; shorter values would mask
	 * ordinary text.
	 */
	const MIN_SECRET_LENGTH = 4;

	/**
	 * Key names whose values are secrets, as a regex alternation.
	 */
	const KEY_PATTERN = '(?:pass(?:word|wd|phrase)?|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|authorization|credentials?|auth)';

	/**
	 * Literal forms to search for, longest first.
	 *
	 * @var array<string, true>
	 */
	private $needles = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $secrets Secret values known at construction.
	 */
	public function __construct( array $secrets = array() ) {
		$this->add_secrets( $secrets );
	}

	/**
	 * Register additional secret values. Values can only be added, never removed.
	 *
	 * Internal API for the plugin's own components (storage destinations keep
	 * credentials that must never reach a log).
	 *
	 * @internal
	 * @param string[] $secrets Secret values.
	 * @return void
	 */
	public function add_secrets( array $secrets ): void {
		foreach ( $secrets as $secret ) {
			if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_SECRET_LENGTH ) {
				continue;
			}
			foreach ( self::forms( $secret ) as $form ) {
				if ( strlen( $form ) >= self::MIN_SECRET_LENGTH ) {
					$this->needles[ $form ] = true;
				}
			}
		}
		$needles = array_keys( $this->needles );
		usort(
			$needles,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->needles = array_fill_keys( $needles, true );
	}

	/**
	 * Encodings a secret may appear in.
	 *
	 * @param string $secret Raw value.
	 * @return string[]
	 */
	public static function forms( string $secret ): array {
		$forms = array( $secret );
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class; every escaping style is needed as a needle.
		$encodings = array(
			json_encode( $secret ),
			json_encode( $secret, JSON_UNESCAPED_UNICODE ),
			json_encode( $secret, JSON_UNESCAPED_SLASHES ),
			json_encode( $secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
		// phpcs:enable
		foreach ( $encodings as $encoded ) {
			if ( is_string( $encoded ) && strlen( $encoded ) >= 2 ) {
				$forms[] = substr( $encoded, 1, -1 );
			}
		}
		$forms[] = rawurlencode( $secret );
		$forms[] = urlencode( $secret ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- legacy form-encoding is exactly what must be matched.
		$forms[] = htmlspecialchars( $secret, ENT_QUOTES, 'UTF-8' );
		return array_values( array_unique( $forms ) );
	}

	/**
	 * Redact a piece of text.
	 *
	 * @param string $text Text that may contain secrets.
	 * @return string
	 */
	public function redact( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		if ( array() !== $this->needles ) {
			$text = str_replace( array_keys( $this->needles ), self::MASK, $text );
		}

		$patterns = array(
			// user:password@host in URLs.
			'#(\b[a-z][a-z0-9+.-]*://[^/\s:@]+:)[^@\s/]+(@)#i' => '$1' . self::MASK . '$2',
			// Authorization headers and bare bearer/basic tokens.
			'#\b(bearer|basic)\s+[A-Za-z0-9\-._~+/=]+#i' => '$1 ' . self::MASK,
			// JSON: "key": "value" / "key": value.
			'#("[^"]*' . self::KEY_PATTERN . '[^"]*"\s*:\s*)("(?:\\\\.|[^"\\\\])*"|[^,}\s\]]+)#i' => '$1"' . self::MASK . '"',
			// key=value, key: value, key => value (query strings, ini, logs).
			'#(\b[A-Za-z0-9_.\-]*' . self::KEY_PATTERN . '[A-Za-z0-9_.\-]*\s*(?:=>|=|:)\s*)(?!\[redacted\])(?:"[^"]*"|\'[^\']*\'|[^\s&,;"\'\]\}]+)#i' => '$1' . self::MASK,
			// AWS access key IDs.
			'#\b(?:AKIA|ASIA)[0-9A-Z]{16}\b#'            => self::MASK,
			// E-mail: keep first character and domain.
			'#\b([A-Za-z0-9])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b#' => '$1***@$2',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		return $text;
	}
}

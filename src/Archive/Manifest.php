<?php
/**
 * The archive manifest (manifest.json), validated by hand.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ManifestError messages carry field paths and are never printed as HTML; anything shown to a user goes through JobPresenter::clean() and esc_html().

/**
 * Reads and writes manifest.json of format version 1.
 *
 * The from_json() method validates by hand, field by field, and fails closed: no
 * JSON Schema library at runtime (the distribution has no dependencies and
 * an uploaded archive is untrusted input). The checks a schema cannot
 * express are here too: relative paths without traversal, the chunk list
 * rule (present exactly when the content is larger than chunk_bytes, with
 * the right number of entries), the size limits, and required_features
 * (an unknown value rejects the whole manifest; every other unknown key is
 * ignored for forward compatibility within a format version). The schema
 * file schema/manifest-v1.json is the specification text and the test
 * fixtures keep both in agreement.
 *
 * A valid manifest proves nothing about the archive beyond its own
 * structure: checksums detect corruption, not tampering, and every path,
 * size and table name in it stays untrusted input when the archive is
 * unpacked.
 */
final class Manifest {

	const FORMAT             = 'wpcheckpoint-archive';
	const VERSIONS           = array( 1 );
	const KINDS              = array( 'backup', 'checkpoint' );
	const TRIGGERS           = array( 'manual', 'scheduled', 'pre_update', 'pre_replace', 'pre_rollback', 'pre_restore' );
	const ALGORITHM          = 'sha256';
	const DEFAULT_CHUNK      = 16777216;
	const MIN_CHUNK          = 1048576;
	const MAX_CHUNK          = 1073741824;
	const MAX_JSON_BYTES     = 16777216;
	const MAX_DEPTH          = 32;
	const MAX_STRING         = 4096;
	const MAX_BYTES          = 9007199254740991; // 2^53 - 1
	const MAX_TABLES         = 10000;
	const MAX_TABLE_CHUNKS   = 100000;
	const MAX_VOLUMES        = 10000;
	const MAX_VOLUME_CHUNKS  = 65536;
	const MAX_WARNINGS       = 1000;
	const MAX_EXCLUSIONS     = 10000;
	const MAX_CONTENT_GROUPS = 100;
	const MAX_FEATURES       = 100;
	const MAX_TABLE_NAME     = 64;

	/**
	 * Validated data in canonical key order.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Use from_json() or from_array().
	 *
	 * @param array<string, mixed> $data Validated data.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Optional features this plugin version understands. A manifest whose
	 * required_features names anything else is rejected as a whole. Empty in
	 * format version 1; every entry added here needs a reader that honours it.
	 *
	 * @return string[]
	 */
	public static function known_features(): array {
		return array();
	}

	/**
	 * Parse and validate a manifest document.
	 *
	 * @param string $json JSON text.
	 * @return Manifest
	 * @throws ManifestError When the document is not an acceptable manifest.
	 */
	public static function from_json( string $json ): Manifest {
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new ManifestError( '', 'The manifest is larger than the maximum of 16 MB.' );
		}
		// Decoded as objects first: "[]" and "{}" both become an empty PHP array otherwise.
		$object = json_decode( $json, false, self::MAX_DEPTH );
		if ( ! is_object( $object ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new ManifestError( '', 'The manifest is not a JSON object.' );
		}
		$decoded = json_decode( $json, true, self::MAX_DEPTH );
		return self::from_array( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Validate decoded data.
	 *
	 * @param array<string, mixed> $data Decoded JSON object.
	 * @return Manifest
	 * @throws ManifestError When the data is not an acceptable manifest.
	 */
	public static function from_array( array $data ): Manifest {
		$out = array();

		$out['format'] = self::string_field( $data, 'format', '' );
		if ( self::FORMAT !== $out['format'] ) {
			throw new ManifestError( 'format', 'Unknown format.' );
		}
		$out['format_version'] = self::int_field( $data, 'format_version', '', 0, PHP_INT_MAX );
		if ( ! in_array( $out['format_version'], self::VERSIONS, true ) ) {
			throw new ManifestError( 'format_version', sprintf( 'Unsupported format version %d.', $out['format_version'] ) );
		}

		$features = self::string_list( $data, 'required_features', '', self::MAX_FEATURES );
		foreach ( $features as $i => $feature ) {
			if ( 1 !== preg_match( '/\A[a-z0-9_.-]{1,64}\z/', $feature ) ) {
				throw new ManifestError( "required_features[{$i}]", 'Invalid feature name.' );
			}
			if ( ! in_array( $feature, self::known_features(), true ) ) {
				throw new ManifestError( "required_features[{$i}]", sprintf( 'This plugin version does not understand the required feature "%s".', $feature ) );
			}
		}
		$out['required_features'] = array_values( $features );

		$out['kind']       = self::enum_field( $data, 'kind', '', self::KINDS );
		$out['trigger']    = self::enum_field( $data, 'trigger', '', self::TRIGGERS );
		$out['created_at'] = self::string_field( $data, 'created_at', '' );
		if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $out['created_at'] ) ) {
			throw new ManifestError( 'created_at', 'Not an ISO 8601 timestamp.' );
		}

		$generator        = self::object_field( $data, 'generator', '' );
		$out['generator'] = array(
			'name'    => self::string_field( $generator, 'name', 'generator' ),
			'version' => self::string_field( $generator, 'version', 'generator' ),
		);

		$site        = self::object_field( $data, 'site', '' );
		$out['site'] = array();
		foreach ( array( 'home_url', 'site_url', 'abspath', 'content_dir', 'table_prefix', 'wp_version', 'php_version', 'db_server', 'locale', 'charset', 'collate' ) as $key ) {
			$out['site'][ $key ] = self::string_field( $site, $key, 'site' );
		}
		$out['site']['multisite'] = self::bool_field( $site, 'multisite', 'site' );

		$contents          = self::object_field( $data, 'contents', '' );
		$out['contents']   = array(
			'database' => self::bool_field( $contents, 'database', 'contents' ),
			'files'    => self::string_list( $contents, 'files', 'contents', self::MAX_CONTENT_GROUPS ),
		);
		$out['exclusions'] = self::string_list( $data, 'exclusions', '', self::MAX_EXCLUSIONS );

		$hashing = self::object_field( $data, 'hashing', '' );
		if ( self::ALGORITHM !== self::string_field( $hashing, 'algorithm', 'hashing' ) ) {
			throw new ManifestError( 'hashing.algorithm', 'Unsupported hash algorithm.' );
		}
		$chunk_bytes    = self::int_field( $hashing, 'chunk_bytes', 'hashing', self::MIN_CHUNK, self::MAX_CHUNK );
		$out['hashing'] = array(
			'algorithm'   => self::ALGORITHM,
			'chunk_bytes' => $chunk_bytes,
		);

		$database        = self::object_field( $data, 'database', '' );
		$tables          = self::list_field( $database, 'tables', 'database', self::MAX_TABLES );
		$out['database'] = array( 'tables' => array() );
		$seen_tables     = array();
		foreach ( $tables as $i => $table ) {
			$field = "database.tables[{$i}]";
			if ( ! is_array( $table ) ) {
				throw new ManifestError( $field, 'Not an object.' );
			}
			$name = self::string_field( $table, 'name', $field, self::MAX_TABLE_NAME );
			if ( '' === $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
				throw new ManifestError( "{$field}.name", 'Invalid table name.' );
			}
			if ( isset( $seen_tables[ $name ] ) ) {
				throw new ManifestError( "{$field}.name", 'Duplicate table.' );
			}
			$seen_tables[ $name ] = true;
			$entry                = array(
				'name'   => $name,
				'rows'   => self::int_field( $table, 'rows', $field, 0, self::MAX_BYTES ),
				'chunks' => array(),
			);
			foreach ( self::list_field( $table, 'chunks', $field, self::MAX_TABLE_CHUNKS ) as $j => $chunk ) {
				$cfield = "{$field}.chunks[{$j}]";
				if ( ! is_array( $chunk ) ) {
					throw new ManifestError( $cfield, 'Not an object.' );
				}
				$path = self::relative_path( $chunk, 'path', $cfield );
				if ( 0 !== strpos( $path, 'database/' ) ) {
					throw new ManifestError( "{$cfield}.path", 'Database chunks live under database/.' );
				}
				$bytes = self::int_field( $chunk, 'bytes', $cfield, 0, self::MAX_BYTES );
				if ( $bytes > $chunk_bytes ) {
					throw new ManifestError( "{$cfield}.bytes", 'A database chunk file is at most chunk_bytes.' );
				}
				if ( array_key_exists( 'chunks', $chunk ) ) {
					throw new ManifestError( "{$cfield}.chunks", 'A database chunk file has no chunk list.' );
				}
				$entry['chunks'][] = array(
					'path'   => $path,
					'bytes'  => $bytes,
					'sha256' => self::hash_field( $chunk, 'sha256', $cfield ),
				);
			}
			$out['database']['tables'][] = $entry;
		}

		$files        = self::object_field( $data, 'files', '' );
		$index        = self::relative_path( $files, 'index', 'files' );
		$out['files'] = array(
			'count' => self::int_field( $files, 'count', 'files', 0, self::MAX_BYTES ),
			'bytes' => self::int_field( $files, 'bytes', 'files', 0, self::MAX_BYTES ),
			'index' => $index,
		);

		$out['volumes'] = array();
		$seen_volumes   = array();
		foreach ( self::list_field( $data, 'volumes', '', self::MAX_VOLUMES ) as $i => $volume ) {
			$field = "volumes[{$i}]";
			if ( ! is_array( $volume ) ) {
				throw new ManifestError( $field, 'Not an object.' );
			}
			$path = self::relative_path( $volume, 'path', $field );
			if ( false !== strpos( $path, '/' ) ) {
				throw new ManifestError( "{$field}.path", 'A volume path is a file name without directories.' );
			}
			if ( isset( $seen_volumes[ $path ] ) ) {
				throw new ManifestError( "{$field}.path", 'Duplicate volume.' );
			}
			$seen_volumes[ $path ] = true;
			$bytes                 = self::int_field( $volume, 'bytes', $field, 0, self::MAX_BYTES );
			$entry                 = array(
				'path'  => $path,
				'bytes' => $bytes,
			);
			$expected_chunks       = $bytes > $chunk_bytes ? ChunkHasher::chunk_count( $bytes, $chunk_bytes ) : 0;
			if ( $expected_chunks > 0 ) {
				if ( ! array_key_exists( 'chunks', $volume ) ) {
					throw new ManifestError( "{$field}.chunks", 'Content larger than chunk_bytes must carry its chunk hashes.' );
				}
				$chunks = self::list_field( $volume, 'chunks', $field, self::MAX_VOLUME_CHUNKS );
				if ( count( $chunks ) !== $expected_chunks ) {
					throw new ManifestError( "{$field}.chunks", sprintf( 'Expected %d chunk hashes for %d bytes, found %d.', $expected_chunks, $bytes, count( $chunks ) ) );
				}
				$entry['chunks'] = array();
				foreach ( $chunks as $j => $hash ) {
					$entry['chunks'][] = self::hash_value( $hash, "{$field}.chunks[{$j}]" );
				}
			} elseif ( array_key_exists( 'chunks', $volume ) ) {
				throw new ManifestError( "{$field}.chunks", 'Content of at most chunk_bytes has no chunk list.' );
			}
			$entry['sha256']  = self::hash_field( $volume, 'sha256', $field );
			$out['volumes'][] = $entry;
		}

		if ( array_key_exists( 'encryption', $data ) && null !== $data['encryption'] ) {
			throw new ManifestError( 'encryption', 'Encryption is not supported by this format version.' );
		}
		$out['encryption'] = null;
		$out['warnings']   = self::string_list( $data, 'warnings', '', self::MAX_WARNINGS );

		return new self( $out );
	}

	/**
	 * Canonical JSON (fixed key order, readable, slashes and unicode unescaped).
	 *
	 * @return string
	 */
	public function to_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class, also used where WordPress is not loaded.
		$json = json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json . "\n" : '';
	}

	/**
	 * Validated data.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Format version.
	 *
	 * @return int
	 */
	public function format_version(): int {
		return (int) $this->data['format_version'];
	}

	/**
	 * Hash chunk size.
	 *
	 * @return int
	 */
	public function chunk_bytes(): int {
		return (int) $this->data['hashing']['chunk_bytes'];
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return (string) $this->data['kind'];
	}

	/**
	 * Trigger.
	 *
	 * @return string
	 */
	public function trigger(): string {
		return (string) $this->data['trigger'];
	}

	/**
	 * Tables with their chunk files.
	 *
	 * @return array<int, array{name: string, rows: int, chunks: array<int, array{path: string, bytes: int, sha256: string}>}>
	 */
	public function tables(): array {
		return $this->data['database']['tables'];
	}

	/**
	 * Volumes.
	 *
	 * @return array<int, array{path: string, bytes: int, chunks?: string[], sha256: string}>
	 */
	public function volumes(): array {
		return $this->data['volumes'];
	}

	/**
	 * Site facts as recorded.
	 *
	 * @return array<string, mixed>
	 */
	public function site(): array {
		return $this->data['site'];
	}

	/**
	 * Warnings.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->data['warnings'];
	}

	/**
	 * A required string of bounded length.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Field path of the object.
	 * @param int                  $max    Maximum bytes.
	 * @return string
	 * @throws ManifestError When missing or not a string.
	 */
	private static function string_field( array $data, string $key, string $prefix, int $max = self::MAX_STRING ): string {
		$field = self::path( $prefix, $key );
		if ( ! array_key_exists( $key, $data ) ) {
			throw new ManifestError( $field, 'Missing.' );
		}
		if ( ! is_string( $data[ $key ] ) ) {
			throw new ManifestError( $field, 'Not a string.' );
		}
		if ( strlen( $data[ $key ] ) > $max ) {
			throw new ManifestError( $field, 'Too long.' );
		}
		if ( false !== strpos( $data[ $key ], "\0" ) ) {
			throw new ManifestError( $field, 'Contains a NUL byte.' );
		}
		return $data[ $key ];
	}

	/**
	 * A required integer within a range (JSON floats are not accepted).
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @param int                  $min    Minimum.
	 * @param int                  $max    Maximum.
	 * @return int
	 * @throws ManifestError When missing, not an integer or out of range.
	 */
	private static function int_field( array $data, string $key, string $prefix, int $min, int $max ): int {
		$field = self::path( $prefix, $key );
		if ( ! array_key_exists( $key, $data ) ) {
			throw new ManifestError( $field, 'Missing.' );
		}
		if ( ! is_int( $data[ $key ] ) ) {
			throw new ManifestError( $field, 'Not an integer.' );
		}
		if ( $data[ $key ] < $min || $data[ $key ] > $max ) {
			throw new ManifestError( $field, sprintf( 'Out of range (%d to %d).', $min, $max ) );
		}
		return $data[ $key ];
	}

	/**
	 * A required boolean.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @return bool
	 * @throws ManifestError When missing or not a boolean.
	 */
	private static function bool_field( array $data, string $key, string $prefix ): bool {
		$field = self::path( $prefix, $key );
		if ( ! array_key_exists( $key, $data ) ) {
			throw new ManifestError( $field, 'Missing.' );
		}
		if ( ! is_bool( $data[ $key ] ) ) {
			throw new ManifestError( $field, 'Not a boolean.' );
		}
		return $data[ $key ];
	}

	/**
	 * A required string from a fixed set.
	 *
	 * @param array<string, mixed> $data    Object.
	 * @param string               $key     Key.
	 * @param string               $prefix  Parent path.
	 * @param string[]             $allowed Allowed values.
	 * @return string
	 * @throws ManifestError When not one of the allowed values.
	 */
	private static function enum_field( array $data, string $key, string $prefix, array $allowed ): string {
		$value = self::string_field( $data, $key, $prefix );
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new ManifestError( self::path( $prefix, $key ), 'Not one of ' . implode( ', ', $allowed ) . '.' );
		}
		return $value;
	}

	/**
	 * A required JSON object.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @return array<string, mixed>
	 * @throws ManifestError When missing or not an object.
	 */
	private static function object_field( array $data, string $key, string $prefix ): array {
		$field = self::path( $prefix, $key );
		if ( ! array_key_exists( $key, $data ) ) {
			throw new ManifestError( $field, 'Missing.' );
		}
		if ( ! is_array( $data[ $key ] ) || ( array() !== $data[ $key ] && array_keys( $data[ $key ] ) === range( 0, count( $data[ $key ] ) - 1 ) ) ) {
			throw new ManifestError( $field, 'Not an object.' );
		}
		return $data[ $key ];
	}

	/**
	 * A required JSON array of bounded length.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @param int                  $max    Maximum entries.
	 * @return array<int, mixed>
	 * @throws ManifestError When missing, not a list or too long.
	 */
	private static function list_field( array $data, string $key, string $prefix, int $max ): array {
		$field = self::path( $prefix, $key );
		if ( ! array_key_exists( $key, $data ) ) {
			throw new ManifestError( $field, 'Missing.' );
		}
		$value = $data[ $key ];
		if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
			throw new ManifestError( $field, 'Not a list.' );
		}
		if ( count( $value ) > $max ) {
			throw new ManifestError( $field, sprintf( 'More than %d entries.', $max ) );
		}
		return $value;
	}

	/**
	 * A required list of bounded strings.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @param int                  $max    Maximum entries.
	 * @return string[]
	 * @throws ManifestError When an entry is not a string.
	 */
	private static function string_list( array $data, string $key, string $prefix, int $max ): array {
		$field = self::path( $prefix, $key );
		$out   = array();
		foreach ( self::list_field( $data, $key, $prefix, $max ) as $i => $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > self::MAX_STRING || false !== strpos( $value, "\0" ) ) {
				throw new ManifestError( "{$field}[{$i}]", 'Not a string of at most 4096 bytes.' );
			}
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * A required lowercase hex SHA-256.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @return string
	 * @throws ManifestError When not a SHA-256 hex string.
	 */
	private static function hash_field( array $data, string $key, string $prefix ): string {
		$field = self::path( $prefix, $key );
		if ( ! array_key_exists( $key, $data ) ) {
			throw new ManifestError( $field, 'Missing.' );
		}
		return self::hash_value( $data[ $key ], $field );
	}

	/**
	 * Validate one hash value.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field path.
	 * @return string
	 * @throws ManifestError When not a SHA-256 hex string.
	 */
	private static function hash_value( $value, string $field ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $value ) ) {
			throw new ManifestError( $field, 'Not a lowercase hex SHA-256.' );
		}
		return $value;
	}

	/**
	 * A required relative path: forward slashes only, no empty, "." or ".."
	 * segments, no leading slash or drive letter, no NUL.
	 *
	 * @param array<string, mixed> $data   Object.
	 * @param string               $key    Key.
	 * @param string               $prefix Parent path.
	 * @return string
	 * @throws ManifestError When the value could escape the archive.
	 */
	private static function relative_path( array $data, string $key, string $prefix ): string {
		$field = self::path( $prefix, $key );
		$value = self::string_field( $data, $key, $prefix );
		if ( '' === $value || false !== strpos( $value, '\\' ) || '/' === $value[0] || 1 === preg_match( '/\A[A-Za-z]:/', $value ) ) {
			throw new ManifestError( $field, 'Not a relative path with forward slashes.' );
		}
		foreach ( explode( '/', $value ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new ManifestError( $field, 'Path contains an empty, "." or ".." segment.' );
			}
		}
		return $value;
	}

	/**
	 * Field path of a key under a parent.
	 *
	 * @param string $prefix Field path of the enclosing object.
	 * @param string $key    Key.
	 * @return string
	 */
	private static function path( string $prefix, string $key ): string {
		return '' === $prefix ? $key : $prefix . '.' . $key;
	}
}

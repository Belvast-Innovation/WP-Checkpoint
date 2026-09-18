<?php
/**
 * The archive manifest (manifest.json), validated by hand.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- ManifestError messages carry field paths, never HTML. There is no caller yet; the tasks that show them to a user are responsible for routing them through JobPresenter::clean() and esc_html().

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
 * The manifest holds one summary line per table; the per-chunk records
 * live in the database.index.jsonl sidecar (described by an entry with its
 * own hash), so the document grows with the number of tables only and a
 * 4 MB limit is enough for any site while json_decode() stays within the
 * memory of a shared host. The document is decoded once, as objects, so a
 * JSON object and a JSON array stay distinguishable.
 *
 * A valid manifest proves nothing about the archive beyond its own
 * structure: checksums detect corruption, not tampering, and every path,
 * size and table name in it stays untrusted input when the archive is
 * unpacked.
 */
final class Manifest {

	const FORMAT               = 'wpcheckpoint-archive';
	const VERSIONS             = array( 1 );
	const KINDS                = array( 'backup', 'checkpoint' );
	const TRIGGERS             = array( 'manual', 'scheduled', 'pre_update', 'pre_replace', 'pre_rollback', 'pre_restore' );
	const ALGORITHM            = 'sha256';
	const DEFAULT_CHUNK        = 16777216;
	const DEFAULT_VOLUME_CHUNK = 268435456;
	const MAX_VOLUME_CHUNK     = 4294967296;
	const MIN_CHUNK            = 1048576;
	const MAX_CHUNK            = 1073741824;
	const MAX_JSON_BYTES       = 4194304;
	const MAX_DEPTH            = 32;
	const MAX_STRING           = 4096;
	/**
	 * Largest count or byte size accepted: 2^53 - 1 (exact in JSON) on 64-bit
	 * PHP, PHP_INT_MAX on 32-bit PHP where that literal would be a float.
	 */
	const MAX_BYTES          = PHP_INT_SIZE >= 8 ? 9007199254740991 : PHP_INT_MAX;
	const MAX_TABLES         = 10000;
	const MAX_TABLE_CHUNKS   = 100000;
	const DATABASE_INDEX     = 'database.index.jsonl';
	const FILES_INDEX        = 'files.index.jsonl';
	const MAX_VOLUMES        = 2000; // 2 TB of 1 GiB volumes; keeps a maximal manifest under MAX_JSON_BYTES (tested).
	const MAX_VOLUME_CHUNKS  = 65536;
	const MAX_WARNINGS       = 1000;
	const MAX_EXCLUSIONS     = 10000;
	const MAX_CONTENT_GROUPS = 100;
	const MAX_FEATURES       = 100;
	const MAX_TABLE_NAME     = 64;

	const INTEGER                        = 'integer';
	const INTEGER_TOO_LARGE_FOR_PLATFORM = 'too_large_for_platform';
	const NOT_AN_INTEGER                 = 'not_an_integer';

	/**
	 * Validated data in canonical key order.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Use from_json() or from_object().
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
			throw new ManifestError( '', 'The manifest is larger than the maximum of 4 MB.' );
		}
		// Decoded once, as objects: a JSON object is a stdClass and a JSON array a PHP array, so
		// the two stay distinguishable and no second tree is built.
		$object = json_decode( $json, false, self::MAX_DEPTH );
		if ( ! $object instanceof \stdClass || JSON_ERROR_NONE !== json_last_error() ) {
			throw new ManifestError( '', 'The manifest is not a JSON object.' );
		}
		return self::from_object( $object );
	}

	/**
	 * Validate a decoded document.
	 *
	 * @param \stdClass $document Decoded JSON object (nested objects are stdClass, arrays are PHP arrays).
	 * @return Manifest
	 * @throws ManifestError When the data is not an acceptable manifest.
	 */
	public static function from_object( \stdClass $document ): Manifest {
		$data  = (array) $document;
		$out   = array();
		$paths = array();

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
		$chunk_bytes = self::int_field( $hashing, 'chunk_bytes', 'hashing', self::MIN_CHUNK, self::MAX_CHUNK );
		// Volumes are hashed in coarser chunks: the manifest must not grow with the data (a 1 TB site
		// has 1000 volumes), and a damaged volume is re-transferred whole, so fine location is useless.
		$volume_chunk_bytes = self::int_field( $hashing, 'volume_chunk_bytes', 'hashing', $chunk_bytes, self::MAX_VOLUME_CHUNK );
		if ( 0 !== $volume_chunk_bytes % $chunk_bytes ) {
			throw new ManifestError( 'hashing.volume_chunk_bytes', 'Must be a multiple of chunk_bytes.' );
		}
		$out['hashing'] = array(
			'algorithm'          => self::ALGORITHM,
			'chunk_bytes'        => $chunk_bytes,
			'volume_chunk_bytes' => $volume_chunk_bytes,
		);

		$database        = self::object_field( $data, 'database', '' );
		$out['database'] = array(
			'index'  => self::content_entry( self::object_field( $database, 'index', 'database' ), 'database.index', $chunk_bytes, $paths, self::DATABASE_INDEX ),
			'tables' => array(),
		);
		$seen_tables     = array();
		foreach ( self::list_field( $database, 'tables', 'database', self::MAX_TABLES ) as $i => $table ) {
			$field = "database.tables[{$i}]";
			$table = self::object_item( $table, $field );
			$name  = self::string_field( $table, 'name', $field, self::MAX_TABLE_NAME );
			if ( '' === $name || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
				throw new ManifestError( "{$field}.name", 'Invalid table name.' );
			}
			if ( isset( $seen_tables[ $name ] ) ) {
				throw new ManifestError( "{$field}.name", 'Duplicate table.' );
			}
			$seen_tables[ $name ]        = true;
			$out['database']['tables'][] = array(
				'name'   => $name,
				'rows'   => self::int_field( $table, 'rows', $field, 0, self::MAX_BYTES ),
				'bytes'  => self::int_field( $table, 'bytes', $field, 0, self::MAX_BYTES ),
				'chunks' => self::int_field( $table, 'chunks', $field, 0, self::MAX_TABLE_CHUNKS ),
				'sha256' => self::hash_field( $table, 'sha256', $field ),
			);
		}

		$files        = self::object_field( $data, 'files', '' );
		$out['files'] = array(
			'count' => self::int_field( $files, 'count', 'files', 0, self::MAX_BYTES ),
			'bytes' => self::int_field( $files, 'bytes', 'files', 0, self::MAX_BYTES ),
			'index' => self::content_entry( self::object_field( $files, 'index', 'files' ), 'files.index', $chunk_bytes, $paths, self::FILES_INDEX ),
		);

		$out['volumes'] = array();
		foreach ( self::list_field( $data, 'volumes', '', self::MAX_VOLUMES ) as $i => $volume ) {
			$field = "volumes[{$i}]";
			$entry = self::content_entry( self::object_item( $volume, $field ), $field, $volume_chunk_bytes, $paths );
			if ( false !== strpos( $entry['path'], '/' ) ) {
				throw new ManifestError( "{$field}.path", 'A volume path is a file name without directories.' );
			}
			if ( 1 !== preg_match( '/\.wpcheckpoint\.(zip|tar)\z/', $entry['path'] ) ) {
				throw new ManifestError( "{$field}.path", 'A volume is named *.wpcheckpoint.zip or *.wpcheckpoint.tar.' );
			}
			$out['volumes'][] = $entry;
		}

		// The copy inside the last volume cannot describe that volume: readers never derive a full pass from it.
		$out['embedded'] = false;
		if ( array_key_exists( 'embedded', $data ) ) {
			$out['embedded'] = self::bool_field( $data, 'embedded', '' );
		}

		if ( array_key_exists( 'encryption', $data ) && null !== $data['encryption'] ) {
			throw new ManifestError( 'encryption', 'Encryption is not supported by this format version.' );
		}
		$out['encryption'] = null;
		$out['warnings']   = self::string_list( $data, 'warnings', '', self::MAX_WARNINGS );

		return new self( $out );
	}

	/**
	 * Canonical JSON: fixed key order, compact (no indentation), slashes and
	 * unicode unescaped, one trailing newline. Compact on purpose: the largest
	 * legal manifest is about 3.1 MB this way and 4.5 MB with four-space
	 * indentation, which readers must refuse (MAX_JSON_BYTES). Use jq to
	 * read one by eye.
	 *
	 * @return string
	 */
	public function to_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure PHP class, also used where WordPress is not loaded.
		$json = json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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
	 * Whether this is the copy embedded in the last volume (its volume list
	 * leaves out the volume holding it; a verifier never reports a full pass
	 * from it).
	 *
	 * @return bool
	 */
	public function embedded(): bool {
		return (bool) $this->data['embedded'];
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
	 * Hash chunk size of volumes (a multiple of chunk_bytes).
	 *
	 * @return int
	 */
	public function volume_chunk_bytes(): int {
		return (int) $this->data['hashing']['volume_chunk_bytes'];
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
	 * Table summaries (the per-chunk records are in the database index sidecar).
	 *
	 * @return array<int, array{name: string, rows: int, bytes: int, chunks: int, sha256: string}>
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
	 * The database index sidecar entry.
	 *
	 * @return array{path: string, bytes: int, chunks?: string[], sha256: string}
	 */
	public function database_index(): array {
		return $this->data['database']['index'];
	}

	/**
	 * The files index sidecar entry.
	 *
	 * @return array{path: string, bytes: int, chunks?: string[], sha256: string}
	 */
	public function files_index(): array {
		return $this->data['files']['index'];
	}

	/**
	 * Declared file count and total bytes.
	 *
	 * @return array{count: int, bytes: int}
	 */
	public function files_summary(): array {
		return array(
			'count' => (int) $this->data['files']['count'],
			'bytes' => (int) $this->data['files']['bytes'],
		);
	}

	/**
	 * A content entry (a volume or an index file): path, bytes, sha256 and,
	 * exactly when bytes > the entry's chunk size, the chunk hash list
	 * (chunk_bytes for index files, volume_chunk_bytes for volumes). Paths are
	 * unique across the whole manifest.
	 *
	 * @param array<string, mixed> $entry       Decoded entry.
	 * @param string               $field       Field path of the entry.
	 * @param int                  $chunk_bytes Chunk size.
	 * @param array<string, bool>  $paths       Paths seen so far (updated).
	 * @param string|null          $fixed_name  When set, the path must be exactly this name.
	 * @return array{path: string, bytes: int, chunks?: string[], sha256: string}
	 * @throws ManifestError When the entry is invalid.
	 */
	private static function content_entry( array $entry, string $field, int $chunk_bytes, array &$paths, $fixed_name = null ): array {
		$path = self::relative_path( $entry, 'path', $field );
		if ( null !== $fixed_name && $path !== $fixed_name ) {
			throw new ManifestError( "{$field}.path", sprintf( 'Expected "%s".', $fixed_name ) );
		}
		if ( isset( $paths[ $path ] ) ) {
			throw new ManifestError( "{$field}.path", 'Duplicate path.' );
		}
		$paths[ $path ] = true;
		$bytes          = self::int_field( $entry, 'bytes', $field, 0, self::MAX_BYTES );
		$out            = array(
			'path'  => $path,
			'bytes' => $bytes,
		);
		$expected       = $bytes > $chunk_bytes ? ChunkHasher::chunk_count( $bytes, $chunk_bytes ) : 0;
		if ( $expected > 0 ) {
			if ( ! array_key_exists( 'chunks', $entry ) ) {
				throw new ManifestError( "{$field}.chunks", 'Content larger than its chunk size must carry its chunk hashes.' );
			}
			$chunks = self::list_field( $entry, 'chunks', $field, self::MAX_VOLUME_CHUNKS );
			if ( count( $chunks ) !== $expected ) {
				throw new ManifestError( "{$field}.chunks", sprintf( 'Expected %d chunk hashes for %d bytes, found %d.', $expected, $bytes, count( $chunks ) ) );
			}
			$out['chunks'] = array();
			foreach ( $chunks as $j => $hash ) {
				$out['chunks'][] = self::hash_value( $hash, "{$field}.chunks[{$j}]" );
			}
		} elseif ( array_key_exists( 'chunks', $entry ) ) {
			throw new ManifestError( "{$field}.chunks", 'Content of at most its chunk size has no chunk list.' );
		}
		$out['sha256'] = self::hash_field( $entry, 'sha256', $field );
		return $out;
	}

	/**
	 * A list item that must be a JSON object.
	 *
	 * @param mixed  $item  Decoded item.
	 * @param string $field Field path of the item.
	 * @return array<string, mixed>
	 * @throws ManifestError When the item is not an object.
	 */
	private static function object_item( $item, string $field ): array {
		if ( ! $item instanceof \stdClass ) {
			throw new ManifestError( $field, 'Not an object.' );
		}
		return (array) $item;
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
		$kind = self::classify_integer( $data[ $key ] );
		if ( self::INTEGER_TOO_LARGE_FOR_PLATFORM === $kind ) {
			throw new ManifestError( $field, 'This value is larger than the 32-bit PHP on this server can handle; a 64-bit PHP is needed for an archive of this size.' );
		}
		if ( self::INTEGER !== $kind ) {
			throw new ManifestError( $field, 'Not an integer.' );
		}
		if ( $data[ $key ] < $min || $data[ $key ] > $max ) {
			throw new ManifestError( $field, sprintf( 'Out of range (%d to %d).', $min, $max ) );
		}
		return $data[ $key ];
	}

	/**
	 * What a decoded JSON number is on this platform. json_decode() turns an
	 * integer above PHP_INT_MAX into a float, so on 32-bit PHP a legitimate
	 * manifest from a 64-bit site (a 3 GB archive) arrives with float sizes;
	 * that is a platform limit, not a malformed manifest, and is reported as
	 * such.
	 *
	 * @param mixed $value    Decoded value.
	 * @param int   $int_size PHP_INT_SIZE of the platform (tests inject 4).
	 * @return string INTEGER, INTEGER_TOO_LARGE_FOR_PLATFORM or NOT_AN_INTEGER.
	 */
	public static function classify_integer( $value, int $int_size = PHP_INT_SIZE ): string {
		if ( is_int( $value ) ) {
			return self::INTEGER;
		}
		if ( is_float( $value ) && $int_size < 8 && floor( $value ) === $value && abs( $value ) > 2147483647.0 ) {
			return self::INTEGER_TOO_LARGE_FOR_PLATFORM;
		}
		return self::NOT_AN_INTEGER;
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
		if ( ! $data[ $key ] instanceof \stdClass ) {
			throw new ManifestError( $field, 'Not an object.' );
		}
		return (array) $data[ $key ];
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
		if ( ! is_array( $value ) ) {
			// Decoded as objects, only a JSON array is a PHP array here; a JSON object is a stdClass.
			throw new ManifestError( $field, 'Not a list.' );
		}
		if ( count( $value ) > $max ) {
			throw new ManifestError( $field, sprintf( 'More than %d entries.', $max ) );
		}
		return array_values( $value );
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
		$field   = self::path( $prefix, $key );
		$value   = self::string_field( $data, $key, $prefix );
		$problem = EntryPath::problem( $value );
		if ( null !== $problem ) {
			throw new ManifestError( $field, $problem );
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

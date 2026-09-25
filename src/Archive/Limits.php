<?php
/**
 * The sizes a writer, the verifier and the reader of an archive must agree on.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Each limit is defined here once, and each side takes it from here: the
 * export writes nothing larger (Manifest::DEFAULT_CHUNK, the deflate cap in
 * PackStep::packer_options_for()), the verifier reports anything larger as
 * unsupported (ArchiveVerifier), and the reader refuses it (ZipReader).
 * An archive another tool wrote may go beyond them; it is then refused by
 * the verifier with the reason, before a restore creates anything.
 */
final class Limits {

	/**
	 * Largest content hash chunk (the manifest's chunk_bytes): one SHA-256 over a chunk cannot be split across
	 * ticks, and a database chunk, at most this long, is extracted and hashed in one unit.
	 */
	const CONTENT_CHUNK_BYTES = 16777216;

	/**
	 * Largest compressed (deflated) entry, either size: a deflate stream has no addressable ranges and is
	 * inflated in one piece, which peaks at about three times this, inside the 32 MB step increment (tested).
	 */
	const INFLATE_BYTES = 8388608;
}

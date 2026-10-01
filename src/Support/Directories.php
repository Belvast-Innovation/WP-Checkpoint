<?php
/**
 * Locates, creates and validates the plugin's storage directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Decides where backups, temporary files and logs live.
 *
 * Preference: a sibling of the WordPress directory outside the document
 * root; otherwise wp-content/wp-checkpoint-{token}/ with deny rules. The
 * choice, a random install ID and the verification result are stored in an
 * installation option; the directory carries an owner marker so a cloned
 * site never writes into the original site's directory.
 */
final class Directories {

	const OPTION     = StoredNames::STORAGE;
	const DIR_PREFIX = 'wp-checkpoint-';

	/**
	 * Past storage tokens kept (own_tokens()).
	 */
	const PAST_TOKENS = 10;

	/**
	 * How many answers, lost tokens and moves the state keeps (the oldest go first).
	 */
	const KEPT_RECORDS = 20;

	/*
	 * The administrator's answers to identity_question().
	 */
	const ANSWER_COPY = 'copy';
	const ANSWER_SAME = 'same';

	const SOURCE_OUTSIDE = 'outside';
	const SOURCE_CONTENT = 'content';
	const SOURCE_CUSTOM  = 'custom';

	/*
	 * What a directory's owner marker says (marker()): none there; this installation's; nothing but the start of
	 * this installation's in an otherwise empty directory (a request died writing it); another installation's; or
	 * there but not readable (permissions, open_basedir), which says nothing about whose it is.
	 */
	const MARKER_NONE       = 'none';
	const MARKER_OWN        = 'own';
	const MARKER_UNFINISHED = 'unfinished';
	const MARKER_OTHER      = 'other';
	const MARKER_UNREADABLE = 'unreadable';

	/**
	 * Sub-directories created inside the base directory.
	 *
	 * @var string[]
	 */
	const SUBDIRS = array( 'backups', 'tmp', 'logs' );

	/**
	 * Environment (injectable for tests).
	 *
	 * @var array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string, wordpress_dirs: array{within: array<string, string>, itself: array<string, string>}|null, after_marker: callable|null, read_marker: callable|null, before_mark: callable|null}
	 */
	private $context;

	/**
	 * Persisted state.
	 *
	 * @var array<string, mixed>
	 */
	private $state;

	/**
	 * Resolved base directory, empty string when unusable, null before resolve().
	 *
	 * @var string|null
	 */
	private $base = null;

	/**
	 * Why the storage is unusable, if it is.
	 *
	 * @var string
	 */
	private $error = '';

	/**
	 * The path hash of each owner marker found this installation's in this request, or written by it, by directory.
	 *
	 * @var array<string, string>
	 */
	private $marker_hashes = array();

	/**
	 * The tokens this request recorded as copied (note_clone()): those a move detected in it sets aside.
	 *
	 * @var string[]
	 */
	private $copied_now = array();

	/**
	 * Whether this request found the state written at another ABSPATH (note_clone()).
	 *
	 * @var bool
	 */
	private $elsewhere = false;

	/**
	 * What the administrator recorded for this request's pair of paths, when they cannot be told apart otherwise:
	 * true for "the same site", false for "a copy or a move", null when nothing was recorded (identity_answer()).
	 *
	 * @var bool|null
	 */
	private $decided = null;

	/**
	 * The question this request could not answer itself (identity_question()), or null.
	 *
	 * @var array{kind: string, id: string, recorded: string, here: string}|null
	 */
	private $question = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context Overrides for default_context().
	 */
	public function __construct( array $context = array() ) {
		$this->context = array_merge( self::default_context(), $context );
		$this->state   = self::load_state();
	}

	/**
	 * Environment as seen in the current request.
	 *
	 * The wordpress_dirs entry is null here: WordPress's own directories (wordpress_dirs()) are looked up only when a custom
	 * directory is checked. Tests pass stand-ins, an after_marker callable (called in prepare() right after the owner
	 * marker is written: a request that dies there), a before_mark callable (called in prepare() right before the marker
	 * is written, once the directory was found usable: another request writing one meanwhile), and a read_marker
	 * callable (function( string $path ): string|false, reading the owner marker in place of file_get_contents(): a
	 * marker that cannot be read).
	 *
	 * @return array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string, wordpress_dirs: array{within: array<string, string>, itself: array<string, string>}|null, after_marker: callable|null, read_marker: callable|null, before_mark: callable|null}
	 */
	public static function default_context(): array {
		$document_root = '';
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$document_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}
		return array(
			'abspath'        => ABSPATH,
			'content_dir'    => WP_CONTENT_DIR,
			'document_root'  => $document_root,
			'is_web_request' => 'cli' !== PHP_SAPI && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ),
			'custom_dir'     => defined( 'WPCHECKPOINT_STORAGE_DIR' ) ? (string) WPCHECKPOINT_STORAGE_DIR : '',
			'wordpress_dirs' => null,
			'after_marker'   => null,
			'read_marker'    => null,
			'before_mark'    => null,
		);
	}

	/**
	 * WordPress's own directories, which a custom storage directory may not be, lie in or hold (StorageLocation):
	 * wp-admin, wp-includes and the content groups (plugins, must-use plugins, themes, uploads, languages, upgrade,
	 * the update backups, fonts), where WordPress resolves them now (UPLOADS, WPMU_PLUGIN_DIR and the like moved);
	 * and the content directory itself, which may hold one (inside it, a directory of none of these may be used).
	 * On a network, the main site's uploads and blogs.dir too. Not covered: an uploads directory moved for a single
	 * site of a network other than the main one.
	 *
	 * @return array{within: array<string, string>, itself: array<string, string>} directory => label.
	 */
	public static function wordpress_dirs(): array {
		$within = array();
		$add    = function ( string $dir, string $label ) use ( &$within ): void {
			if ( '' !== $dir && ! isset( $within[ $dir ] ) ) {
				$within[ $dir ] = $label;
			}
		};
		$add( ABSPATH . 'wp-admin', __( 'the wp-admin directory', 'wp-checkpoint' ) );
		$add( ABSPATH . 'wp-includes', __( 'the wp-includes directory', 'wp-checkpoint' ) ); // WPINC, which core never changes.
		$add( WP_PLUGIN_DIR, __( 'the plugins directory', 'wp-checkpoint' ) );
		$add( WPMU_PLUGIN_DIR, __( 'the must-use plugins directory', 'wp-checkpoint' ) );
		$add( (string) get_theme_root(), __( 'a themes directory', 'wp-checkpoint' ) );
		foreach ( (array) ( $GLOBALS['wp_theme_directories'] ?? array() ) as $themes ) {
			$add( (string) $themes, __( 'a themes directory', 'wp-checkpoint' ) );
		}
		$add( (string) wp_upload_dir( null, false )['basedir'], __( 'the uploads directory', 'wp-checkpoint' ) );
		if ( is_multisite() ) {
			if ( ! is_main_site() ) {
				switch_to_blog( get_main_site_id() );
				$add( (string) wp_upload_dir( null, false )['basedir'], __( 'the uploads directory', 'wp-checkpoint' ) );
				restore_current_blog();
			}
			$add( WP_CONTENT_DIR . '/blogs.dir', __( 'the uploads directory', 'wp-checkpoint' ) );
		}
		$add( WP_LANG_DIR, __( 'the languages directory', 'wp-checkpoint' ) );
		$add( WP_CONTENT_DIR . '/upgrade', __( 'the upgrade directory', 'wp-checkpoint' ) );
		$add( WP_CONTENT_DIR . '/upgrade-temp-backup', __( 'the directory WordPress keeps backups in during updates', 'wp-checkpoint' ) );
		if ( function_exists( 'wp_get_font_dir' ) ) {
			$add( (string) wp_get_font_dir()['basedir'], __( 'the fonts directory', 'wp-checkpoint' ) );
		}
		return array(
			'within' => $within,
			'itself' => array( WP_CONTENT_DIR => __( 'the content directory (wp-content)', 'wp-checkpoint' ) ),
		);
	}

	/**
	 * Stored state with defaults applied.
	 *
	 * @return array<string, mixed>
	 */
	public static function load_state(): array {
		$stored = Options::get( self::OPTION, array() );
		return array_merge(
			array(
				'install_id'            => '',
				'token'                 => '',
				'path'                  => '',
				'source'                => '',
				'provisional'           => false,
				'verification'          => array(),
				'clone_detected'        => false,
				'copied_tokens'         => array(), // The tokens held when a clone was detected: the original's (note_clone()).
				'previous_path'         => '',
				'abspath'               => '',
				'previous_abspath'      => '',
				'abspath_real'          => '', // The directory ABSPATH resolved to then ('' when it did not resolve).
				'previous_abspath_real' => '', // The same, as it was when a clone was detected (StorageReclaim).
				'marker_hash'           => '', // The path hash in the marker of the directory last adopted (adopt()).
				'previous_marker_hash'  => '', // The same, as it was when a clone was detected (StorageReclaim).
				'reclaim_tokens'        => array(), // Set aside by the detection "continue with the original directory" undoes.
				'reclaim_marker_hash'   => '', // The hash a "continue" was about to write into the marker (StorageReclaim).
				'detected_real'         => '', // Where ABSPATH resolved to when the clone was detected (note_detection()).
				'identity_answers'      => array(), // The administrator's answers, by pair of paths (answer_identity()).
				'lost_tokens'           => array(), // Set aside by a detection a newer one replaced, by token: when (note_detection()).
				'moves'                 => array(), // The latest detections, from where to where ABSPATH resolved (note_detection()).
				'trusted_deploy_root'   => '',
				'auto_reclaimed'        => array(),
				'past_tokens'           => array(),
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Whether a token has the expected shape.
	 *
	 * @param mixed $token Candidate.
	 * @return bool
	 */
	public static function is_valid_token( $token ): bool {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{12}$/', $token );
	}

	/**
	 * Base directory, created on first use. Empty string when unusable.
	 *
	 * @return string
	 */
	public function base(): string {
		if ( null === $this->base ) {
			$this->resolve();
		}
		return (string) $this->base;
	}

	/**
	 * Backups directory or empty string.
	 *
	 * @return string
	 */
	public function backups(): string {
		return $this->subdir( 'backups' );
	}

	/**
	 * Temporary files directory or empty string.
	 *
	 * @return string
	 */
	public function tmp(): string {
		return $this->subdir( 'tmp' );
	}

	/**
	 * Logs directory or empty string.
	 *
	 * @return string
	 */
	public function logs(): string {
		return $this->subdir( 'logs' );
	}

	/**
	 * Current state (after resolving).
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$this->base();
		return $this->state;
	}

	/**
	 * Reason the storage is unusable, empty when fine.
	 *
	 * @return string
	 */
	public function last_error(): string {
		$this->base();
		return $this->error;
	}

	/**
	 * Run the loopback verification and store the result.
	 *
	 * @return array{status: string, code: int|null, message: string}
	 */
	public function verify_protection(): array {
		$base = $this->base();
		if ( '' === $base ) {
			$result = array(
				'status'  => Protection::STATUS_UNVERIFIED,
				'code'    => null,
				'message' => $this->error,
			);
		} else {
			$result = Protection::verify( $base );
		}
		$this->state['verification'] = array_merge( $result, array( 'checked_at' => time() ) );
		$this->save_state();
		return $result;
	}

	/**
	 * Acknowledge a detected clone so the notice stops.
	 *
	 * @return void
	 */
	public function acknowledge_clone(): void {
		$this->base();
		$this->state['clone_detected']        = false;
		$this->state['previous_path']         = '';
		$this->state['previous_abspath']      = '';
		$this->state['previous_abspath_real'] = '';
		$this->state['previous_marker_hash']  = '';
		$this->state['reclaim_tokens']        = array(); // Another installation's, as the administrator says: they stay copied.
		$this->state['reclaim_marker_hash']   = '';
		$this->state['detected_real']         = '';
		$this->save_state();
	}

	/**
	 * Record, as a clone is detected, the tokens held then: the current one and the earlier ones, copied with the
	 * database from the installation that last wrote the state (not when that is this one). None of them is ever this installation's again (is_copied()): it is
	 * never adopted, taken back or kept as an earlier one, so no job or staging carrying one is claimed here. Only
	 * "continue with the original directory" (finish_reclaim(), and a trusted deployment's automatic reclaim) says
	 * this installation is the original, and clears them: they are its own again, the current one or earlier ones.
	 * Kept across detections and acknowledgements.
	 *
	 * @return bool False when it cannot be told (same_place()): nothing was recorded, and nothing else may change.
	 */
	private function note_clone(): bool {
		// The tokens are another installation's when the state was last taken up under another ABSPATH (the
		// original's, or a copy's of which this is a copy again): another place, not another spelling of this one
		// (WP-CLI's --path through a link, where the web server resolves it). State this installation wrote holds its
		// own, and state that never took a directory up says nothing. Not seen: a copy at the very same ABSPATH on
		// another host, whose directories look like the original's in every way.
		$written = (string) $this->state['abspath'];
		if ( '' === $written ) {
			return true;
		}
		$same = self::same_place( $written, (string) $this->state['abspath_real'], (string) $this->context['abspath'] );
		if ( null === $same ) {
			$same = $this->decided; // What the administrator recorded for this pair of paths, if anything.
		}
		if ( null === $same ) {
			return false;
		}
		if ( ! $same ) {
			$this->elsewhere              = true;
			$held                         = array_filter( array_map( 'strval', array_merge( array( (string) $this->state['token'] ), (array) $this->state['past_tokens'] ) ), array( self::class, 'is_valid_token' ) );
			$this->copied_now             = array_values( array_unique( array_merge( $this->copied_now, array_diff( $held, (array) $this->state['copied_tokens'] ) ) ) );
			$this->state['copied_tokens'] = array_values( array_unique( array_merge( array_map( 'strval', (array) $this->state['copied_tokens'] ), $held ) ) );
		}
		return true;
	}

	/**
	 * Record, as a clone or move is detected, what "continue with the original directory" needs: the ABSPATH and the
	 * marker as they were, and the tokens this request set aside (none that were set aside before: an earlier clone's,
	 * which the administrator may have said are another installation's).
	 *
	 * @param string $dir The directory the state named.
	 * @return void
	 */
	private function note_detection( string $dir ): void {
		// The same detection again (a custom directory refused on every request) keeps what it set aside; a new one
		// sets aside only what this request did: an earlier detection's tokens, never acknowledged, stay copied. The
		// same means the same directory, detected from the same place: a copy of the database asking from elsewhere
		// starts its own, without the tokens the original's detection set aside.
		$here  = OwnerMarker::real( (string) $this->context['abspath'] );
		$here  = '' === $here ? rtrim( Paths::normalize( (string) $this->context['abspath'] ), '/' ) : rtrim( Paths::normalize( $here ), '/' );
		$again = ! empty( $this->state['clone_detected'] ) && Paths::same( (string) $this->state['previous_path'], $dir, Paths::is_windows() ) && Paths::same( (string) $this->state['detected_real'], $here, Paths::is_windows() );
		if ( ! $again ) {
			// A newer detection replaces one the administrator never answered: what that one set aside is not given
			// back by any "continue" any more. Its jobs are failed with that reason (JobRepository::settle_storage()).
			$lost = ! empty( $this->state['clone_detected'] ) ? array_diff( array_map( 'strval', (array) $this->state['reclaim_tokens'] ), $this->copied_now, array( (string) $this->state['token'] ) ) : array();
			foreach ( $lost as $token ) {
				$this->state['lost_tokens'][ $token ] = time();
			}
			$this->state['lost_tokens'] = array_slice( (array) $this->state['lost_tokens'], -self::KEPT_RECORDS, null, true );
			$from                       = '' !== (string) $this->state['abspath_real'] ? (string) $this->state['abspath_real'] : (string) $this->state['abspath'];
			$this->state['moves'][]     = array( rtrim( Paths::normalize( $from ), '/' ), $here );
			$this->state['moves']       = array_slice( (array) $this->state['moves'], -self::KEPT_RECORDS );
		}
		$this->state['reclaim_tokens']        = array_values( array_unique( array_merge( $again ? array_map( 'strval', (array) $this->state['reclaim_tokens'] ) : array(), $this->copied_now ) ) );
		$this->state['detected_real']         = $here;
		$this->state['clone_detected']        = true;
		$this->state['previous_path']         = $dir;
		$this->state['previous_abspath']      = (string) $this->state['abspath'];
		$this->state['previous_abspath_real'] = (string) $this->state['abspath_real'];
		$this->state['previous_marker_hash']  = (string) $this->state['marker_hash'];
	}

	/**
	 * Whether two ABSPATHs are one directory: spelled alike, or resolving to one. The directory the recorded one
	 * resolved to when it was recorded is compared as it is (it is not resolved again: it may lie where this request
	 * cannot look, open_basedir); state written before that was recorded has its ABSPATH resolved here. Another
	 * directory only on evidence: two resolved directories; or the recorded ABSPATH positively gone here (a copy's host
	 * has no such directory). Null when neither can be shown: a path cannot be resolved (a directory on the way cannot
	 * be searched, open_basedir) and is not shown to be gone.
	 *
	 * @param string $written      ABSPATH recorded in the state.
	 * @param string $written_real The directory it resolved to when recorded ('' when not recorded).
	 * @param string $here         ABSPATH of this request.
	 * @return bool|null
	 */
	private static function same_place( string $written, string $written_real, string $here ) {
		if ( Paths::same( $written, $here, Paths::is_windows() ) ) {
			return true;
		}
		$real_here = OwnerMarker::real( $here );
		if ( '' !== $written_real && '' !== $real_here ) {
			return Paths::same( $written_real, $real_here, Paths::is_windows() );
		}
		$real_written = OwnerMarker::real( $written );
		if ( '' !== $real_written && '' !== $real_here ) {
			return Paths::same( $real_written, $real_here, Paths::is_windows() );
		}
		if ( Paths::positively_gone( $written ) ) {
			return false; // Nothing there: not where this request runs.
		}
		return null;
	}

	/**
	 * Why the state cannot be taken up in this request: whether it was written at this ABSPATH cannot be told.
	 *
	 * @return string
	 */
	private function undecidable_abspath(): string {
		return sprintf(
			/* translators: 1: WordPress directory recorded when the storage directory was chosen, 2: WordPress directory of this request */
			__( 'Whether this is the site that chose the storage directory cannot be told: WordPress\'s directory was %1$s then and is %2$s in this request, and one of them cannot be resolved from here (file permissions, or the host\'s open_basedir setting). Nothing was changed. If both name the same directory, make them resolvable by PHP (or use the one the web server uses, for WP-CLI\'s --path as well) and reload.', 'wp-checkpoint' ),
			(string) $this->state['abspath'],
			(string) $this->context['abspath']
		);
	}

	/**
	 * The question for this request's pair of paths when they cannot be told apart: the ABSPATH the state was written
	 * under and this request's, each as spelled and as resolved ('' when it does not resolve).
	 *
	 * @return array{kind: string, id: string, recorded: string, here: string}
	 */
	private function paths_question(): array {
		$recorded = (string) $this->state['abspath'];
		$here     = (string) $this->context['abspath'];
		return array(
			'kind'     => 'paths',
			'id'       => hash( 'sha256', implode( "\0", array( 'paths', $recorded, (string) $this->state['abspath_real'], $here, OwnerMarker::real( $here ) ) ) ),
			'recorded' => $recorded,
			'here'     => $here,
		);
	}

	/**
	 * The question when continuing with the original directory cannot be decided: the take-over a request of this
	 * installation started (its hash recorded before it rewrote the marker) died, and ABSPATH moved again before any
	 * request finished it. The marker then holds a hash of neither this ABSPATH nor the one recorded: as a copy of the
	 * database, made in between, would see it. Null when that is not the case.
	 *
	 * @return array{kind: string, id: string, recorded: string, here: string}|null
	 */
	private function claimed_question() {
		$previous = (string) $this->state['previous_path'];
		$recorded = (string) $this->state['reclaim_marker_hash'];
		if ( empty( $this->state['clone_detected'] ) || '' === $previous || '' === $recorded ) {
			return null;
		}
		$lines = StorageReclaim::read_marker( $previous );
		if ( null === $lines || ! hash_equals( (string) $this->state['install_id'], $lines[0] ) || ! hash_equals( $recorded, $lines[1] ) ) {
			return null;
		}
		$here = (string) $this->context['abspath'];
		if ( OwnerMarker::is_hash_of( $lines[1], $here ) || hash_equals( (string) $this->state['previous_marker_hash'], $lines[1] ) || OwnerMarker::is_hash_of( $lines[1], (string) $this->state['previous_abspath'] ) ) {
			return null; // The marker reads as recorded, or as this ABSPATH's: nothing to ask.
		}
		return array(
			'kind'     => 'claimed',
			'id'       => hash( 'sha256', implode( "\0", array( 'claimed', $recorded, $here, OwnerMarker::real( $here ) ) ) ),
			'recorded' => (string) $this->state['previous_abspath'],
			'here'     => $here,
		);
	}

	/**
	 * What the administrator answered for a question: true for "the same site", false for "a copy or a move", null
	 * when not answered.
	 *
	 * @param string $id The question's id.
	 * @return bool|null
	 */
	private function identity_answer( string $id ) {
		$answers = (array) $this->state['identity_answers'];
		if ( ! isset( $answers[ $id ] ) || ! is_array( $answers[ $id ] ) ) {
			return null;
		}
		$answer = $answers[ $id ]['answer'] ?? '';
		return self::ANSWER_SAME === $answer ? true : ( self::ANSWER_COPY === $answer ? false : null );
	}

	/**
	 * The question this installation cannot answer by itself in this request, for the administrator: whether this is
	 * the site that chose the storage directory (kind "paths": the two ABSPATHs cannot be compared), or whether to
	 * continue with the original directory after a take-over died and the site moved again (kind "claimed"). Null
	 * when there is none, or it was answered for this pair of paths.
	 *
	 * @return array{kind: string, id: string, recorded: string, here: string}|null
	 */
	public function identity_question() {
		$this->base();
		if ( null !== $this->question ) {
			return $this->question;
		}
		$claimed = $this->claimed_question();
		return null !== $claimed && null === $this->identity_answer( $claimed['id'] ) ? $claimed : null;
	}

	/**
	 * Record the administrator's answer to the current question (identity_question()), with the time, and act on it.
	 * Kind "paths": "copy" sets the tokens held aside as another installation's and takes a new token and directory;
	 * "same" keeps them this installation's. Kind "claimed": "same" finishes the take-over, "copy" keeps the new
	 * directory. The same pair of paths is not asked about again.
	 *
	 * @param string $answer      ANSWER_COPY or ANSWER_SAME.
	 * @param string $question_id The id of the question the answer is for (identity_question()): an answer to a
	 *                            question this request would not ask is refused.
	 * @return array{ok: bool, message: string}
	 */
	public function answer_identity( string $answer, string $question_id ): array {
		if ( self::ANSWER_COPY !== $answer && self::ANSWER_SAME !== $answer ) {
			return array(
				'ok'      => false,
				'message' => __( 'The answer must be "copy" or "same".', 'wp-checkpoint' ),
			);
		}
		$question = $this->identity_question();
		if ( null === $question ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no question about this site\'s identity to answer.', 'wp-checkpoint' ),
			);
		}
		if ( ! hash_equals( $question['id'], $question_id ) ) {
			// The answer is for the question that was shown, not for whatever this request would ask.
			return array(
				'ok'      => false,
				'message' => __( 'The question changed since it was shown; reload it and answer again.', 'wp-checkpoint' ),
			);
		}

		if ( 'claimed' === $question['kind'] && self::ANSWER_SAME === $answer ) {
			// Recorded only once the take-over is done: "answered, not taken over" would leave the question gone
			// and a "continue" that refuses the marker.
			$result = $this->reclaimer()->reclaim( false, (string) $this->state['reclaim_marker_hash'] );
			if ( ! $result['ok'] ) {
				return array(
					'ok'      => false,
					'message' => $result['message'],
				);
			}
			$this->finish_reclaim( $result['trusted_root'] );
			$this->record_answer( $question, $answer );
			$this->log_answer( $question, $answer );
			return array(
				'ok'      => true,
				'message' => $result['message'],
			);
		}
		$this->record_answer( $question, $answer );
		if ( 'claimed' === $question['kind'] ) {
			$this->acknowledge_clone();
			$this->log_answer( $question, $answer );
			return array(
				'ok'      => true,
				'message' => __( 'The new storage directory is kept; the original one is left to the other copy of this site.', 'wp-checkpoint' ),
			);
		}
		$this->base     = null;
		$this->question = null;
		$this->decided  = null;
		$this->base();
		$this->log_answer( $question, $answer );
		return array(
			'ok'      => true,
			'message' => self::ANSWER_SAME === $answer
				? __( 'Recorded: this is the same site. Its jobs go on with its storage token.', 'wp-checkpoint' )
				: __( 'Recorded: this site is a copy or was moved here. It uses a storage token and directory of its own; the jobs started before are not run here.', 'wp-checkpoint' ),
		);
	}

	/**
	 * Record an answer, with the time, in one write (the oldest go first).
	 *
	 * @param array{kind: string, id: string, recorded: string, here: string} $question The question.
	 * @param string                                                          $answer   The answer.
	 * @return void
	 */
	private function record_answer( array $question, string $answer ): void {
		$this->state['identity_answers'][ $question['id'] ] = array(
			'answer'   => $answer,
			'at'       => time(),
			'kind'     => $question['kind'],
			'recorded' => $question['recorded'],
			'here'     => $question['here'],
		);
		$this->state['identity_answers']                    = array_slice( (array) $this->state['identity_answers'], -self::KEPT_RECORDS, null, true );
		$this->save_state();
	}

	/**
	 * Write an answer into the storage log, once the storage directory it decided about is known (nothing is written
	 * while there is none).
	 *
	 * @param array{kind: string, id: string, recorded: string, here: string} $question The question.
	 * @param string                                                          $answer   The answer.
	 * @return void
	 */
	private function log_answer( array $question, string $answer ): void {
		$this->log_event( sprintf( 'The administrator answered "%1$s" to whether this is the site that chose the storage directory (WordPress directory %2$s then, %3$s now).', $answer, $question['recorded'], $question['here'] ) );
	}


	/**
	 * Whether the latest detected move went back to where an earlier one came from (A to B, then B to A): workers of
	 * two releases taking turns, which a trusted deployment root would take over without asking. A hint only.
	 *
	 * @return bool
	 */
	public function moved_back(): bool {
		$moves = array_values( (array) $this->state['moves'] );
		$count = count( $moves );
		if ( $count < 2 || ! is_array( $moves[ $count - 1 ] ) ) {
			return false;
		}
		list( $from, $to ) = array_pad( $moves[ $count - 1 ], 2, '' );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( is_array( $moves[ $i ] ) && Paths::same( (string) ( $moves[ $i ][0] ?? '' ), (string) $to, Paths::is_windows() ) && Paths::same( (string) ( $moves[ $i ][1] ?? '' ), (string) $from, Paths::is_windows() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a token is one of the original installation's, copied with the database (note_clone()).
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private function is_copied( string $token ): bool {
		return in_array( $token, (array) $this->state['copied_tokens'], true );
	}

	/**
	 * Environment of this instance.
	 *
	 * @return array{abspath: string, content_dir: string, document_root: string, is_web_request: bool, custom_dir: string, wordpress_dirs: array{within: array<string, string>, itself: array<string, string>}|null, after_marker: callable|null}
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Reclaim helper bound to the current state.
	 *
	 * @return StorageReclaim
	 */
	public function reclaim(): StorageReclaim {
		$this->base();
		return $this->reclaimer();
	}

	/**
	 * A take-over of the recorded directory that saves, before the marker is rewritten, the hash it is about to hold:
	 * a request that dies between the rewrite and finish_reclaim() leaves a marker the next attempt recognises.
	 *
	 * @return StorageReclaim
	 */
	private function reclaimer(): StorageReclaim {
		$reclaim = new StorageReclaim( $this->state, $this->context );
		$reclaim->on_rewrite(
			function ( string $hash ): void {
				$this->state['reclaim_marker_hash'] = $hash;
				Options::set( self::OPTION, array_merge( self::load_state(), array( 'reclaim_marker_hash' => $hash ) ) );
			}
		);
		return $reclaim;
	}

	/**
	 * Switch back to a reclaimed directory after its marker was rewritten.
	 *
	 * @param string $trusted_root Deployment root to trust from now on ('' keeps the current setting).
	 * @return void
	 */
	public function finish_reclaim( string $trusted_root = '' ): void {
		$this->base();
		$dir = (string) $this->state['previous_path'];
		if ( '' === $dir ) {
			return;
		}
		$abandoned = (string) $this->state['path'];
		if ( '' !== $trusted_root ) {
			$this->state['trusted_deploy_root'] = $trusted_root;
		}
		// This is the original: its tokens are its own, the earlier ones among them (dropped as the move was detected).
		$token                        = self::SOURCE_CUSTOM === $this->state['source'] ? (string) $this->state['token'] : substr( basename( $dir ), strlen( self::DIR_PREFIX ) );
		$given                        = array_map( 'strval', (array) $this->state['reclaim_tokens'] );
		$this->state['past_tokens']   = array_slice( array_values( array_diff( array_unique( array_merge( (array) $this->state['past_tokens'], $given ) ), array( $token ) ) ), 0, self::PAST_TOKENS );
		$this->state['copied_tokens'] = array_values( array_diff( (array) $this->state['copied_tokens'], $given, array( $token ) ) );
		foreach ( array_merge( $given, array( $token ) ) as $own ) {
			unset( $this->state['lost_tokens'][ $own ] ); // This installation's again.
		}
		$this->state['reclaim_tokens']        = array();
		$this->state['reclaim_marker_hash']   = '';
		$this->state['detected_real']         = '';
		$this->state['clone_detected']        = false;
		$this->state['previous_path']         = '';
		$this->state['previous_abspath']      = '';
		$this->state['previous_abspath_real'] = '';
		$this->state['previous_marker_hash']  = '';
		$this->state['token']                 = $token;
		$this->base                           = null;
		$this->adopt( $dir, $this->source_for( $dir ), false );
		$this->save_state();

		if ( '' !== $abandoned && $abandoned !== $dir && is_dir( $abandoned ) && $this->holds_only_plugin_files( $abandoned ) ) {
			$this->empty_directory( $abandoned );
			@rmdir( $abandoned ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the empty replacement directory.
		}
	}

	/**
	 * Forget the trusted deployment root.
	 *
	 * @return void
	 */
	public function untrust_deploy_root(): void {
		$this->base();
		$this->state['trusted_deploy_root'] = '';
		$this->save_state();
	}

	/**
	 * Clear the automatic take-over notice.
	 *
	 * @return void
	 */
	public function clear_auto_reclaimed(): void {
		$this->base();
		$this->state['auto_reclaimed'] = array();
		$this->save_state();
	}

	/**
	 * Source constant for a directory by its location.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private function source_for( string $dir ): string {
		if ( '' !== $this->context['custom_dir'] && Paths::same( $this->context['custom_dir'], $dir, Paths::is_windows() ) ) {
			return self::SOURCE_CUSTOM;
		}
		return Paths::is_same_or_inside( $this->context['content_dir'], $dir ) ? self::SOURCE_CONTENT : self::SOURCE_OUTSIDE;
	}

	/**
	 * A sub-directory path or empty string.
	 *
	 * @param string $name Sub-directory name.
	 * @return string
	 */
	private function subdir( string $name ): string {
		$base = $this->base();
		return '' === $base ? '' : $base . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * Decide the base directory.
	 *
	 * @return void
	 */
	private function resolve(): void {
		$this->base = '';

		if ( '' === $this->state['install_id'] ) {
			$this->state['install_id'] = OwnerMarker::generate_id();
			$this->save_state();
		}

		// Links are judged as they are now: a web server worker keeps PHP's realpath cache across requests, and a
		// deployment may have pointed "current" elsewhere since.
		clearstatcache( true );

		// The directory the state names carrying this installation's marker for this ABSPATH shows the state to be
		// this installation's: a directory is adopted only then, and a copy elsewhere matches neither form of the
		// marker. Otherwise, state written under another ABSPATH is a copy's (or a move's), however the directories
		// look: its tokens are recorded as copied before any is chosen, whether or not a marker shows the clone (a
		// copy whose custom directory names another place, or was emptied, never sees the original's marker). When
		// that cannot be told either, nothing is chosen, recorded or saved.
		// A "continue with the original directory" that rewrote the marker and died before saving the state: the
		// previous directory now carries this installation's marker for this ABSPATH with the very hash the take-over
		// recorded before rewriting it. It is finished here (the replay rule of that step), unless this request's
		// WPCHECKPOINT_STORAGE_DIR names another directory: that one is checked as usual.
		$previous  = (string) $this->state['previous_path'];
		$recorded  = (string) $this->state['reclaim_marker_hash'];
		$other_dir = '' !== (string) $this->context['custom_dir'] && ! Paths::same_location( rtrim( (string) $this->context['custom_dir'], '/\\' ), $previous );
		if ( ! empty( $this->state['clone_detected'] ) && '' !== $previous && '' !== $recorded && ! $other_dir && self::MARKER_OWN === $this->marker( $previous ) && hash_equals( $recorded, (string) ( $this->marker_hashes[ rtrim( $previous, '/\\' ) ] ?? '' ) ) ) {
			$this->finish_reclaim();
			$this->log_event( sprintf( 'Continuing with the storage directory %s, which a request had taken over but died before recording it.', $previous ) );
			return;
		}

		$marked = '' !== (string) $this->state['path'] && self::MARKER_OWN === $this->marker( (string) $this->state['path'] );
		if ( ! $marked && ! $this->note_clone() ) {
			// The paths cannot tell; the administrator may have (answer_identity()), for this very pair of paths.
			$question      = $this->paths_question();
			$this->decided = $this->identity_answer( $question['id'] );
			if ( null === $this->decided || ! $this->note_clone() ) {
				$this->question = $question;
				$this->error    = $this->undecidable_abspath();
				return;
			}
		}
		if ( $marked && $this->is_copied( (string) $this->state['token'] ) ) {
			// The token of that directory is this installation's, whatever a detection from another ABSPATH (a
			// release after a deployment, sharing a custom directory) set aside meanwhile.
			$this->state['copied_tokens'] = array_values( array_diff( (array) $this->state['copied_tokens'], array( (string) $this->state['token'] ) ) );
			$this->save_state();
		}

		if ( '' !== $this->context['custom_dir'] ) {
			$this->resolve_custom();
			return;
		}

		if ( '' !== $this->state['path'] && self::is_valid_token( $this->state['token'] ) ) {
			$existing = $this->state['path'];
			if ( is_dir( $existing ) ) {
				$found = $this->marker( $existing );
				if ( self::MARKER_OWN === $found ) {
					$this->adopt( $existing, $this->state['source'], (bool) $this->state['provisional'] );
					$this->maybe_migrate();
					return;
				}
				if ( self::MARKER_UNREADABLE === $found ) {
					// Not evidence of a clone: nothing is recorded, saved or chosen instead.
					$this->error = $this->unreadable_marker( $existing );
					return;
				}
				// Same options, different ABSPATH: a clone, a move, or a new release of a deployment.
				$this->note_clone();
				$this->note_detection( $existing );
				if ( $this->auto_reclaim( $existing ) ) {
					return;
				}
				$this->state['token']        = '';
				$this->state['past_tokens']  = array_values( array_diff( (array) $this->state['past_tokens'], (array) $this->state['copied_tokens'] ) ); // Only the original's go (note_clone()).
				$this->state['verification'] = array();
			} elseif ( $this->restore_keeps( $existing ) && ! Paths::positively_gone( $existing ) ) {
				// Not reachable from this request (open_basedir, permissions), yet not shown to be gone, and a
				// restore keeps its files there (or that cannot be read): no other directory is chosen meanwhile.
				$this->error = __( 'The storage directory cannot be reached from this request, and a restore may be keeping its files there, so no other directory is chosen. Jobs continue from a request that can reach it.', 'wp-checkpoint' );
				return;
			}
		}

		$token = self::is_valid_token( $this->state['token'] ) && ! $this->is_copied( (string) $this->state['token'] ) ? $this->state['token'] : bin2hex( random_bytes( 6 ) );
		$this->select( $token );
	}

	/**
	 * Take a sibling release over without asking when the deployment root is trusted.
	 *
	 * @param string $dir The directory recorded in the state.
	 * @return bool
	 */
	private function auto_reclaim( string $dir ): bool {
		$reclaim = $this->reclaimer();
		if ( ! $reclaim->auto() ) {
			return false;
		}
		$from                          = (string) $this->state['previous_abspath'];
		$this->state['auto_reclaimed'] = array(
			'at'   => time(),
			'from' => $from,
			'to'   => (string) $this->context['abspath'],
		);
		$this->finish_reclaim();
		$this->log_event( sprintf( 'Storage directory %s reclaimed automatically after a deployment: ABSPATH changed from %s to %s.', $dir, $from, (string) $this->context['abspath'] ) );
		return true;
	}

	/**
	 * Deleter::empty_directory(), with a refused path logged: nothing was deleted, and resolving the storage goes on.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function empty_directory( string $dir ): void {
		try {
			Deleter::empty_directory( $dir );
		} catch ( DeletionRefused $e ) {
			$this->log_event( $e->getMessage() );
		}
	}

	/**
	 * Append a line to logs/storage.log in the base directory.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function log_event( string $message ): void {
		$logs = $this->logs();
		if ( '' === $logs || ! is_dir( $logs ) ) {
			return;
		}
		$redactor = new Redactor( Redactor::installation_secrets( array( (string) $this->state['token'] ) ) );
		( new Logger( $logs . DIRECTORY_SEPARATOR . 'storage.log', $redactor ) )->info( $message );
	}

	/**
	 * Use the directory from WPCHECKPOINT_STORAGE_DIR.
	 *
	 * The storage token is the identity of the current directory choice
	 * (job ownership, the storage gate, temporary table prefixes, notice
	 * dismissals). A default directory derives it from its own name; a
	 * custom directory has no such name, so one is generated here and kept
	 * in the same state, and a new one is generated whenever the custom
	 * path changes, exactly as choosing a new default directory would. It
	 * is distinct from install_id (the owner marker): that one stays the
	 * same across directory choices, the token does not.
	 *
	 * @return void
	 */
	private function resolve_custom(): void {
		$refused = Deleter::storage_refusal( $this->context['custom_dir'] );
		if ( '' !== $refused ) {
			// Nothing in it could ever be deleted (reclaim, purge, uninstall): refused before anything is written there.
			// The constant as set, before any trimming (a drive root keeps its separator).
			$this->error = Deleter::NOT_A_FULL_PATH === $refused
				? __( 'WPCHECKPOINT_STORAGE_DIR must be an absolute path, without . or .. segments unless the directory exists. Set it to the full path of a directory of its own.', 'wp-checkpoint' )
				: __( 'WPCHECKPOINT_STORAGE_DIR names the root of the file system, a WordPress directory or a directory that holds one. Set it to a directory of its own, for example a new directory next to the WordPress directory. Backups already stored there stay in its backups sub-directory; move them to the new directory by hand.', 'wp-checkpoint' );
			return;
		}
		$wordpress = null === $this->context['wordpress_dirs'] ? self::wordpress_dirs() : $this->context['wordpress_dirs'];
		$label     = StorageLocation::refusal( $this->context['custom_dir'], $wordpress['within'], $wordpress['itself'] );
		if ( '' !== $label ) {
			// What the plugin writes there (index.php, .htaccess denying access) would change what WordPress serves.
			$this->error = in_array( $label, $wordpress['itself'], true )
				? sprintf( /* translators: %s: which directory, e.g. "the content directory (wp-content)" */ __( 'WPCHECKPOINT_STORAGE_DIR names %s or a directory that holds it. The files WP Checkpoint writes into its storage directory would change what WordPress serves from there. Set it to a new directory of its own, for example next to the WordPress directory or inside wp-content. If it was the storage directory before, its backups stay in its backups sub-directory; move them to the new one by hand.', 'wp-checkpoint' ), $label )
				: sprintf( /* translators: %s: which directory, e.g. "the uploads directory" */ __( 'WPCHECKPOINT_STORAGE_DIR names %s, a directory inside it or one that holds it. The files WP Checkpoint writes into its storage directory would change what WordPress serves from there. Set it to a new directory of its own, for example next to the WordPress directory or inside wp-content. If it was the storage directory before, its backups stay in its backups sub-directory; move them to the new one by hand.', 'wp-checkpoint' ), $label );
			return;
		}
		$dir = rtrim( $this->context['custom_dir'], '/\\' );
		if ( '' === $dir ) {
			$this->error = __( 'WPCHECKPOINT_STORAGE_DIR is empty.', 'wp-checkpoint' );
			return;
		}
		// Another installation's marker, read: a clone. One that cannot be read is no evidence of that (past_tokens
		// are kept, nothing is saved): prepare() refuses the directory with the reason (unowned()).
		if ( self::MARKER_OTHER === $this->marker( $dir ) ) {
			$this->note_clone();
			$this->note_detection( $dir );
			$this->state['past_tokens'] = array_values( array_diff( (array) $this->state['past_tokens'], (array) $this->state['copied_tokens'] ) ); // Only the original's go (note_clone()).
			$this->save_state();
			$this->error = __( 'WPCHECKPOINT_STORAGE_DIR belongs to another installation.', 'wp-checkpoint' );
			return;
		}
		$moved = '' !== (string) $this->state['path'] && ! Paths::same_location( $dir, (string) $this->state['path'] );
		$token = '';
		if ( $moved ) {
			// A restore keeps its files in the directory it was started with (its row's storage_path): another
			// directory here (the constant set differently for WP-CLI and for the web server) must not become
			// the choice while that directory is there. Nothing is created, re-tokened or saved.
			$restores = $this->restores();
			if ( null === $restores ) {
				$this->error = __( 'Whether a restore is in progress cannot be read from the database, so this request does not switch to the storage directory WPCHECKPOINT_STORAGE_DIR names. Try again once the database answers.', 'wp-checkpoint' );
				return;
			}
			$listings = array();
			foreach ( $restores as $restore ) {
				if ( Paths::same_location( $dir, $restore['path'] ) && ! $this->is_copied( (string) $restore['token'] ) ) {
					$token = $restore['token']; // Its own directory: taken back with its own token (never a copied one).
					break;
				}
			}
			if ( '' === $token ) {
				foreach ( $restores as $restore ) {
					if ( ! Paths::positively_gone( $restore['path'], $listings ) ) {
						$this->error = __( 'A restore is in progress in a storage directory that this request cannot confirm to be the one WPCHECKPOINT_STORAGE_DIR names (for example, the constant is set differently for WP-CLI and for the web server). Set it to the directory the restore was started with, or wait until the restore has ended.', 'wp-checkpoint' );
						return;
					}
				}
			}
		}
		if ( ! $this->prepare( $dir ) ) {
			return;
		}
		$before = (string) $this->state['token'];
		if ( self::is_valid_token( $token ) ) {
			$this->state['token'] = $token;
		} elseif ( ! self::is_valid_token( $this->state['token'] ) || $moved || $this->is_copied( (string) $this->state['token'] ) ) {
			// A copy holding the original's token (a clone detected in this directory, which it may now use: emptied)
			// takes one of its own before it writes anything under a token.
			$this->state['token'] = bin2hex( random_bytes( 6 ) );
		}
		// The token and the path are saved together (one write): never a restore's token on another path.
		$this->adopt( $dir, self::SOURCE_CUSTOM, false, $before !== $this->state['token'] );
	}

	/**
	 * Pick a new location for a token.
	 *
	 * @param string $token Directory token.
	 * @return void
	 */
	private function select( string $token ): void {
		$formal = $this->context['is_web_request'] && '' !== $this->context['document_root'];

		if ( $formal ) {
			$outside = $this->candidate_outside( $token );
			if ( '' !== $outside && $this->prepare( $outside ) ) {
				$this->state['token'] = $token;
				$this->adopt( $outside, self::SOURCE_OUTSIDE, false );
				return;
			}
		}

		$content = rtrim( $this->context['content_dir'], '/\\' ) . DIRECTORY_SEPARATOR . self::DIR_PREFIX . $token;
		if ( $this->prepare( $content ) ) {
			$this->state['token'] = $token;
			$this->adopt( $content, self::SOURCE_CONTENT, ! $formal );
			return;
		}

		$this->save_state();
	}

	/**
	 * Candidate directory next to the WordPress directory, if that is outside
	 * the document root.
	 *
	 * @param string $token Directory token.
	 * @return string Empty when not eligible.
	 */
	private function candidate_outside( string $token ): string {
		$abspath = realpath( $this->context['abspath'] );
		if ( false === $abspath ) {
			return '';
		}
		$parent = dirname( $abspath );
		if ( $parent === $abspath ) {
			return '';
		}
		if ( Paths::is_same_or_inside( $this->context['document_root'], $parent ) ) {
			return '';
		}
		return $parent . DIRECTORY_SEPARATOR . self::DIR_PREFIX . $token;
	}

	/**
	 * Create the directory tree, protection files and owner marker, and prove
	 * it is writable.
	 *
	 * Only a directory that does not exist yet, one that is empty, or one
	 * that carries this installation's owner marker is written into
	 * (unowned()); anything else is refused before the first write. The
	 * marker is the first thing written, with exclusive creation, so a
	 * directory the plugin created is marked as its own before it holds
	 * anything else: a request that dies after creating it leaves an empty
	 * directory, and one that dies while writing the marker leaves nothing
	 * but a marker holding the start of this installation's (both taken up
	 * again by the next request).
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function prepare( string $dir ): bool {
		$unowned = $this->unowned( $dir );
		if ( '' !== $unowned ) {
			$this->error = $unowned;
			return false;
		}
		if ( ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot create or write to %s.', 'wp-checkpoint' ), $dir );
			return false;
		}
		if ( null !== $this->context['before_mark'] ) {
			call_user_func( $this->context['before_mark'], $dir );
		}
		if ( ! $this->mark( $dir ) ) {
			return false;
		}
		if ( null !== $this->context['after_marker'] ) {
			call_user_func( $this->context['after_marker'], $dir );
		}

		$probe = $dir . DIRECTORY_SEPARATOR . '.probe-' . bin2hex( random_bytes( 4 ) );
		$nonce = bin2hex( random_bytes( 8 ) );
		try {
			if ( false === file_put_contents( $probe, $nonce, LOCK_EX ) || file_get_contents( $probe ) !== $nonce ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- probing the plugin's own directory.
				$this->error = sprintf( /* translators: %s: directory path */ __( 'Files written to %s cannot be read back.', 'wp-checkpoint' ), $dir );
				return false;
			}
		} finally {
			if ( is_file( $probe ) ) {
				wp_delete_file( $probe );
			}
		}

		foreach ( self::SUBDIRS as $name ) {
			$sub = $dir . DIRECTORY_SEPARATOR . $name;
			if ( ! wp_mkdir_p( $sub ) || ! Protection::write( $sub ) ) {
				$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot create %s.', 'wp-checkpoint' ), $sub );
				return false;
			}
		}
		if ( ! Protection::write( $dir ) ) {
			$this->error = sprintf( /* translators: %s: directory path */ __( 'Cannot write protection files to %s.', 'wp-checkpoint' ), $dir );
			return false;
		}

		$this->error = '';
		return true;
	}

	/**
	 * Why nothing may be written into $dir, or '' when it may: it does not exist yet (or is not a directory, which
	 * creating it will report), it is empty, it carries this installation's owner marker, or it holds nothing but an
	 * unfinished one (marker_unfinished()). A directory whose contents cannot be listed is refused: it cannot be shown
	 * to be empty.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private function unowned( string $dir ): string {
		clearstatcache();
		if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			return '';
		}
		$found = $this->marker( $dir );
		if ( self::MARKER_OWN === $found || self::MARKER_UNFINISHED === $found ) {
			return '';
		}
		if ( self::MARKER_UNREADABLE === $found ) {
			return $this->unreadable_marker( $dir );
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( false === $entries ) {
			return sprintf( /* translators: %s: directory path */ __( 'What %s holds cannot be listed, so it cannot be shown to be empty or to be WP Checkpoint\'s own; nothing is written there. Make it readable, or use a directory that does not exist yet (WP Checkpoint creates it).', 'wp-checkpoint' ), $dir );
		}
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		if ( array() === $entries ) {
			return '';
		}
		if ( in_array( OwnerMarker::FILENAME, $entries, true ) ) {
			return __( 'The directory belongs to another installation.', 'wp-checkpoint' );
		}
		return sprintf( /* translators: %s: directory path */ __( '%s already holds files and does not carry WP Checkpoint\'s owner marker, so nothing is written there. Use a directory that does not exist yet (WP Checkpoint creates it) or an empty one.', 'wp-checkpoint' ), $dir );
	}

	/**
	 * Whether $dir holds nothing but an owner marker whose contents are the start of this installation's (empty
	 * included): what a request that died while writing the marker leaves behind. The marker is written in one call
	 * right after the directory is created, before anything else.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private function marker_unfinished( string $dir ): bool {
		return self::MARKER_UNFINISHED === $this->marker( $dir );
	}

	/**
	 * What the owner marker in $dir says (MARKER_*). It reads the file system on every call (another request may have
	 * written the marker meanwhile). Each answer rests on what was seen: "another installation's" on contents read,
	 * "none" on a listing of the directory without a marker. What cannot be seen is MARKER_UNREADABLE, never someone
	 * else's and never no one's: a marker that cannot be read, a directory that cannot be searched or listed. Nothing
	 * it reads warns: a warning would name the path in the error log.
	 *
	 * @phpstan-impure
	 *
	 * @param string $dir Base directory.
	 * @return string
	 */
	private function marker( string $dir ): string {
		$dir    = rtrim( $dir, '/\\' );
		$marker = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		clearstatcache();
		if ( ! @is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			return self::MARKER_NONE; // Nothing there to be anyone's.
		}
		if ( ! @is_file( $marker ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			// No marker only when the directory is listed without one: a directory that cannot be searched says
			// neither (its marker cannot be looked at, listed or not).
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			return false === $entries || in_array( OwnerMarker::FILENAME, $entries, true ) ? self::MARKER_UNREADABLE : self::MARKER_NONE;
		}
		$contents = null === $this->context['read_marker']
			? @file_get_contents( $marker ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- as above; a tiny local file.
			: call_user_func( $this->context['read_marker'], $marker );
		if ( ! is_string( $contents ) ) {
			return self::MARKER_UNREADABLE;
		}
		$install_id = (string) $this->state['install_id'];
		if ( OwnerMarker::matches( $contents, $install_id, $this->context['abspath'] ) ) {
			$lines                       = OwnerMarker::lines( $contents );
			$this->marker_hashes[ $dir ] = null === $lines ? '' : $lines[1];
			return self::MARKER_OWN;
		}
		if ( OwnerMarker::is_unfinished( $contents, $install_id, $this->context['abspath'] ) ) {
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			if ( false === $entries ) {
				return self::MARKER_UNREADABLE; // Whether anything else is there cannot be seen.
			}
			if ( array( OwnerMarker::FILENAME ) === array_values( array_diff( $entries, array( '.', '..' ) ) ) ) {
				return self::MARKER_UNFINISHED;
			}
		}
		if ( $this->undecidable_marker( $contents ) ) {
			return self::MARKER_UNREADABLE;
		}
		return self::MARKER_OTHER;
	}

	/**
	 * Whether a marker of this installation's ID written at another ABSPATH, as spelled, could still be for this one:
	 * this request's ABSPATH cannot be resolved, so a resolved form of it cannot be compared (the marker may carry
	 * one). Not evidence of another installation, unless the state was found written at another ABSPATH.
	 *
	 * @param string $contents Marker contents.
	 * @return bool
	 */
	private function undecidable_marker( string $contents ): bool {
		$lines = OwnerMarker::lines( $contents );
		return ! $this->elsewhere && null !== $lines && '' !== (string) $this->state['install_id'] && hash_equals( (string) $this->state['install_id'], $lines[0] ) && '' === OwnerMarker::real( (string) $this->context['abspath'] );
	}

	/**
	 * Why a directory whose owner marker cannot be seen (MARKER_UNREADABLE) is not used: something that is not a file
	 * where the marker belongs (a directory, a link to nothing), or the directory or the marker cannot be read.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private function unreadable_marker( string $dir ): string {
		$marker = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		$read   = null === $this->context['read_marker'] ? @file_get_contents( $marker ) : call_user_func( $this->context['read_marker'], $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a warning would name the path; a tiny local file.
		if ( is_string( $read ) && @is_file( $marker ) && $this->undecidable_marker( $read ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			return sprintf(
				/* translators: 1: owner marker file name, 2: directory path, 3: WordPress directory of this request */
				__( 'Whether %2$s is this site\'s own storage directory cannot be told: its owner marker %1$s names this installation at a WordPress directory spelled differently from %3$s, and %3$s cannot be resolved from this request to compare them (file permissions, or the host\'s open_basedir setting). Nothing was changed. Make it resolvable by PHP and reload.', 'wp-checkpoint' ),
				OwnerMarker::FILENAME,
				$dir,
				(string) $this->context['abspath']
			);
		}
		if ( ( @file_exists( $marker ) || @is_link( $marker ) ) && ! @is_file( $marker ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			return sprintf(
				/* translators: 1: owner marker file name, 2: directory path */
				__( 'Whether %2$s is this site\'s own storage directory cannot be told: what is named %1$s there is not a file. Nothing was changed. Remove or rename it if it is not needed, and reload.', 'wp-checkpoint' ),
				OwnerMarker::FILENAME,
				$dir
			);
		}
		return sprintf(
			/* translators: 1: owner marker file name, 2: directory path */
			__( 'Whether %2$s is this site\'s own storage directory cannot be told: the directory or its owner marker %1$s cannot be read (file permissions, or the host\'s open_basedir setting). Nothing was changed. Make both readable by PHP and reload.', 'wp-checkpoint' ),
			OwnerMarker::FILENAME,
			$dir
		);
	}

	/**
	 * Write this installation's owner marker into $dir unless it is there (exclusive creation); an unfinished one is
	 * replaced first.
	 *
	 * @param string $dir Directory.
	 * @return bool Whether the directory carries this installation's marker now.
	 */
	private function mark( string $dir ): bool {
		$marker = $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( $this->owns( $dir ) ) {
			return true; // The usual case: a directory prepared before.
		}
		if ( $this->marker_unfinished( $dir ) ) {
			wp_delete_file( $marker );
		}
		if ( OwnerMarker::create( $marker, OwnerMarker::build( (string) $this->state['install_id'], $this->context['abspath'] ) ) ) {
			$this->marker_hashes[ rtrim( $dir, '/\\' ) ] = OwnerMarker::hash_path( $this->context['abspath'] );
			return true;
		}
		$found = $this->marker( $dir );
		if ( self::MARKER_OWN === $found ) {
			return true; // Written meanwhile by another request of this installation.
		}
		// A marker still there, read, and not the start of this installation's was written by someone else.
		if ( self::MARKER_UNREADABLE === $found ) {
			$this->error = $this->unreadable_marker( $dir );
		} else {
			$this->error = self::MARKER_OTHER === $found ? __( 'The directory belongs to another installation.', 'wp-checkpoint' ) : __( 'Cannot write the owner marker.', 'wp-checkpoint' );
		}
		return false;
	}

	/**
	 * Whether the owner marker in $dir belongs to this installation. It reads the file system on every call (another
	 * request may have written the marker meanwhile).
	 *
	 * @phpstan-impure
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function owns( string $dir ): bool {
		return self::MARKER_OWN === $this->marker( $dir );
	}

	/**
	 * Record a usable directory.
	 *
	 * @param string $dir         Base directory.
	 * @param string $source      Source constant.
	 * @param bool   $provisional Chosen without a proper web request.
	 * @param bool   $save        Whether the state changed before (the token), so it is saved with the path.
	 * @return void
	 */
	private function adopt( string $dir, string $source, bool $provisional, bool $save = false ): void {
		$this->base  = $dir;
		$this->error = '';
		$abspath     = rtrim( Paths::normalize( (string) $this->context['abspath'] ), '/' ) . '/';
		// The marker as this installation found or wrote it: what "continue with the original directory" requires it
		// to still read (StorageReclaim), however ABSPATH is spelled or resolves by then.
		$key = rtrim( $dir, '/\\' );
		if ( ! isset( $this->marker_hashes[ $key ] ) ) {
			$this->marker( $dir );
		}
		$marker_hash = isset( $this->marker_hashes[ $key ] ) ? $this->marker_hashes[ $key ] : (string) $this->state['marker_hash'];
		$real        = OwnerMarker::real( (string) $this->context['abspath'] );
		$real        = '' === $real ? '' : rtrim( Paths::normalize( $real ), '/' ) . '/';
		if ( '' !== (string) $this->state['path'] && $dir !== $this->state['path'] && Paths::same_location( $dir, (string) $this->state['path'] ) ) {
			$dir = (string) $this->state['path']; // The same directory spelled another way: the stored spelling stays.
		}
		$changed = $save || $dir !== $this->state['path'] || $source !== $this->state['source'] || $provisional !== (bool) $this->state['provisional'] || $abspath !== $this->state['abspath'] || $marker_hash !== $this->state['marker_hash'] || $real !== $this->state['abspath_real'];
		if ( $changed ) {
			if ( $dir !== $this->state['path'] ) {
				$this->state['verification'] = array();
			}
			$this->state['path']         = $dir;
			$this->state['source']       = $source;
			$this->state['provisional']  = $provisional;
			$this->state['abspath']      = $abspath;
			$this->state['marker_hash']  = $marker_hash;
			$this->state['abspath_real'] = $real;
			$this->save_state();
		}
	}

	/**
	 * Re-evaluate a provisional (CLI/cron) choice during a proper web request:
	 * move to the outside candidate while the directory holds no user files
	 * and no job is unfinished.
	 *
	 * @return void
	 */
	private function maybe_migrate(): void {
		if ( ! $this->state['provisional'] || self::SOURCE_CONTENT !== $this->state['source'] ) {
			return;
		}
		if ( ! $this->context['is_web_request'] || '' === $this->context['document_root'] ) {
			return;
		}
		if ( false !== $this->jobs_unfinished() ) {
			// A job keeps its files here until it ends (a restore above all), and every driver goes on using this
			// directory: the choice stays provisional and is made once no job is left (also when the jobs table
			// cannot be read).
			return;
		}

		$current = (string) $this->base;
		if ( ! $this->holds_only_plugin_files( $current ) ) {
			// User data present: keep the directory, but the choice is final now.
			$this->adopt( $current, self::SOURCE_CONTENT, false );
			return;
		}

		$outside = $this->candidate_outside( (string) $this->state['token'] );
		if ( '' === $outside || ! $this->prepare( $outside ) ) {
			$this->adopt( $current, self::SOURCE_CONTENT, false );
			return;
		}

		$this->empty_directory( $current );
		@rmdir( $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort cleanup of the empty provisional directory.
		$this->adopt( $outside, self::SOURCE_OUTSIDE, false );
	}

	/**
	 * The directory and token of each unfinished restore, or null when that cannot be read (JobRepository, or
	 * the answer a test gives in the context: key "restores", a callable; internal, for tests only).
	 *
	 * @return array<int, array{path: string, token: string}>|null
	 */
	private function restores() {
		if ( isset( $this->context['restores'] ) && is_callable( $this->context['restores'] ) ) {
			return call_user_func( $this->context['restores'] );
		}
		return \WPCheckpoint\Jobs\JobRepository::unfinished_restores();
	}

	/**
	 * Whether an unfinished restore keeps its files in $dir, or that cannot be read.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private function restore_keeps( string $dir ): bool {
		$restores = $this->restores();
		if ( null === $restores ) {
			return true;
		}
		foreach ( $restores as $restore ) {
			// The same location, or a restore whose directory this request cannot resolve either (or that names
			// none, or a relative one): that could be $dir spelled another way, and nothing shows it is not.
			$path     = $restore['path'];
			$relative = '' === $path || ( '/' !== Paths::normalize( $path )[0] && 1 !== preg_match( '#^[A-Za-z]:/#', Paths::normalize( $path ) ) );
			if ( $relative || Paths::same_location( $path, $dir ) || ( false === @realpath( $path ) && ! Paths::positively_gone( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log.
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any job is unfinished: true, false, or null when that cannot be read.
	 *
	 * @return bool|null
	 */
	private function jobs_unfinished() {
		return $this->unfinished( null );
	}

	/**
	 * JobRepository::has_unfinished(), or the answer a test gives in the context (key "unfinished":
	 * function( ?string $type ): ?bool; internal, for tests only).
	 *
	 * @param string|null $type Job type, or null for any.
	 * @return bool|null
	 */
	private function unfinished( $type ) {
		if ( isset( $this->context['unfinished'] ) && is_callable( $this->context['unfinished'] ) ) {
			return call_user_func( $this->context['unfinished'], $type );
		}
		return \WPCheckpoint\Jobs\JobRepository::has_unfinished( $type );
	}

	/**
	 * Whether a base directory contains nothing but the files this class writes.
	 *
	 * @param string $dir Base directory.
	 * @return bool
	 */
	private function holds_only_plugin_files( string $dir ): bool {
		$own = array( 'index.php', '.htaccess', OwnerMarker::FILENAME );
		foreach ( self::SUBDIRS as $sub ) {
			$entries = @scandir( $dir . DIRECTORY_SEPARATOR . $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing sub-directory counts as empty.
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				if ( ! in_array( $entry, array( '.', '..', 'index.php', '.htaccess' ), true ) ) {
					return false;
				}
			}
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			if ( ! in_array( $entry, array_merge( array( '.', '..' ), $own, self::SUBDIRS ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Persist the state.
	 *
	 * @return void
	 */
	private function save_state(): void {
		// A token this installation used before (its custom directory moved, a restore's token taken back) still
		// names its staging roots and probes next to the site (Residue::scan_site()): it is kept, a few of them.
		// One copied from the original installation may be kept here: whatever reads these leaves copied ones out
		// (own_tokens(), JobRepository::held_tokens()).
		$saved = self::load_state()['token'];
		if ( self::is_valid_token( $saved ) && $saved !== $this->state['token'] ) {
			$past = array_values( array_diff( (array) $this->state['past_tokens'], array( $saved, (string) $this->state['token'] ) ) );
			array_unshift( $past, $saved );
			$this->state['past_tokens'] = array_slice( $past, 0, self::PAST_TOKENS );
		}
		Options::set( self::OPTION, $this->state );
	}

	/**
	 * This installation's storage tokens, the current one first: the ones its staging roots and probes may carry.
	 * None while a clone is detected: the tokens in the copied state may be the original installation's (a custom
	 * directory keeps the original's current token while refusing it), so nothing is claimed until the notice is
	 * resolved; the earlier ones are dropped when the clone is detected.
	 *
	 * @param array<string, mixed>|null $state Stored state (load_state()), or null to load it.
	 * @return string[]
	 */
	public static function own_tokens( $state = null ): array {
		$state = is_array( $state ) ? $state : self::load_state();
		if ( ! empty( $state['clone_detected'] ) ) {
			return array();
		}
		$tokens = array_merge( array( (string) $state['token'] ), (array) $state['past_tokens'] );
		$copied = (array) ( $state['copied_tokens'] ?? array() );
		return array_values(
			array_filter(
				array_unique( array_map( 'strval', $tokens ) ),
				static function ( string $token ) use ( $copied ): bool {
					return self::is_valid_token( $token ) && ! in_array( $token, $copied, true );
				}
			)
		);
	}
}

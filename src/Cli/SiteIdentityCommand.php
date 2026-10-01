<?php
/**
 * The wp wpcheckpoint site-identity command.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WP_CLI;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * Shows or answers the question whether this is the site that chose the storage directory, as the notice in the
 * admin does (Directories::identity_question()), for this WP-CLI request's own WordPress directory. Only loaded
 * under WP-CLI.
 */
final class SiteIdentityCommand {

	/**
	 * Presenter (its clean() pipeline).
	 *
	 * @var JobPresenter
	 */
	private $presenter;

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Constructor.
	 *
	 * @param JobPresenter $presenter   Presenter.
	 * @param Directories  $directories Directories.
	 */
	public function __construct( JobPresenter $presenter, Directories $directories ) {
		$this->presenter   = $presenter;
		$this->directories = $directories;
	}

	/**
	 * Show the question whether this is the site that chose the storage directory, or answer it.
	 *
	 * Asked only when it cannot be told otherwise. There is no default answer. The answer is recorded with the time,
	 * and the same pair of WordPress directories is not asked about again.
	 *
	 * ## OPTIONS
	 *
	 * [--question=<id>]
	 * : The id of the question being answered, as shown without --answer: an answer to another question than this
	 * request would ask is refused.
	 *
	 * [--answer=<answer>]
	 * : copy: this site is a copy, or was moved here (it takes a storage token and directory of its own; the jobs
	 * started before are not run here). same: this is the same site (it keeps the original storage token; if it is in
	 * fact a copy, the original site's jobs may run here).
	 * ---
	 * options:
	 *   - copy
	 *   - same
	 * ---
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$answer   = isset( $assoc_args['answer'] ) ? (string) $assoc_args['answer'] : null;
		$question = isset( $assoc_args['question'] ) ? (string) $assoc_args['question'] : '';
		$result   = $this->run( $answer, $question );
		foreach ( $result['lines'] as $line ) {
			WP_CLI::line( $line );
		}
		if ( 0 !== $result['code'] ) {
			WP_CLI::halt( $result['code'] );
		}
	}

	/**
	 * What the command prints and its exit status. Separated for tests.
	 *
	 * @param string|null $answer   The answer, or null to show the question.
	 * @param string      $question The id of the question being answered.
	 * @return array{code: int, lines: string[]}
	 */
	public function run( $answer, string $question = '' ): array {
		if ( null !== $answer ) {
			$result = $this->directories->answer_identity( $answer, $question );
			if ( $result['ok'] ) {
				( new JobRepository( $this->directories ) )->settle_storage();
			}
			return array(
				'code'  => $result['ok'] ? 0 : 1,
				'lines' => array( $this->presenter->clean( $result['message'] ) ),
			);
		}
		$question = $this->directories->identity_question();
		if ( null === $question ) {
			return array(
				'code'  => 0,
				'lines' => array( $this->presenter->clean( __( 'There is no question about this site\'s identity to answer.', 'wp-checkpoint' ) ) ),
			);
		}
		$lines = array(
			'claimed' === $question['kind']
				? __( 'Whether to continue with the original storage directory cannot be told: a request of this site took it over and stopped before recording that, and the WordPress directory has moved again since; a copy of this site made meanwhile would look the same.', 'wp-checkpoint' )
				: __( 'Whether this is the site that chose the storage directory cannot be told from the paths: one of the two WordPress directories cannot be looked at from here.', 'wp-checkpoint' ),
			/* translators: %s: WordPress directory recorded before */
			sprintf( __( 'WordPress directory then: %s', 'wp-checkpoint' ), $question['recorded'] ),
			/* translators: %s: WordPress directory of this request */
			sprintf( __( 'WordPress directory now: %s', 'wp-checkpoint' ), $question['here'] ),
			__( 'Answer with one of (there is no default):', 'wp-checkpoint' ),
			'  wp wpcheckpoint site-identity --question=' . $question['id'] . ' --answer=copy  ' . ( 'claimed' === $question['kind']
				? __( 'Keeps the new storage directory; the original one, and the jobs started with it, are left to the other copy.', 'wp-checkpoint' )
				: __( 'This site takes a storage token and directory of its own; the jobs started before are not run here.', 'wp-checkpoint' ) ),
			'  wp wpcheckpoint site-identity --question=' . $question['id'] . ' --answer=same  ' . ( 'claimed' === $question['kind']
				? __( 'Continues with the original storage directory and its jobs. If this is in fact a copy, it takes the directory away from the original site, and the original\'s jobs may run here.', 'wp-checkpoint' )
				: __( 'Keeps using the original storage token. If this is in fact a copy, the original site\'s jobs may run here.', 'wp-checkpoint' ) ),
		);
		return array(
			'code'  => 2,
			'lines' => array_map( array( $this->presenter, 'clean' ), $lines ),
		);
	}
}

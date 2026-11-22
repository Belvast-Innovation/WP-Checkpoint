<?php
/**
 * The one way a restore asks its questions.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\LinkedTargets;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors; the presenter cleans them.

/**
 * A restore nobody attends (RestoreJob::options()'s "unattended") is never
 * answered, so a question would leave it paused until the engine gives it
 * up days later, without a reason anybody reads. Every question of the
 * restore's steps therefore goes through ask(), and every kind of question
 * is registered in KINDS as one of:
 *
 * - POLICY: the options carry a policy for it, which an unattended restore
 *   must give before it starts (RestoreJob::options() refuses it otherwise),
 *   so the step does not come to ask. Should it (a policy that is not
 *   there), the restore fails here and names the policy.
 * - FAILS: there is no policy, and nothing answers in a person's place: an
 *   unattended restore fails, with what it would have asked.
 * - ASKS: asked of an unattended restore too; the entry says why.
 *
 * A kind that is not registered is refused (a programming error), attended
 * or not. RestoreAskUsageTest holds the steps to this: StepResult::ask() is
 * called here and nowhere else in a restore.
 */
final class RestoreAsk {

	const POLICY = 'policy';
	const FAILS  = 'fails';
	const ASKS   = 'asks';

	/**
	 * Kind of question => how an unattended restore meets it, and the policy's key (POLICY), what the question is
	 * about (FAILS), or why it is asked all the same (ASKS).
	 */
	const KINDS = array(
		'tables_of_either_installation'           => array( self::POLICY, 'uncertain_tables' ),
		'tables_shared_with_another_installation' => array( self::POLICY, 'shared_tables' ),
		LinkedTargets::KIND                       => array( self::POLICY, LinkedTargets::POLICY_KEY ),
		SwapStep::UNVERIFIED_KIND                 => array( self::POLICY, RestoreJob::UNVERIFIED ),
		'free_space_unknown'                      => array( self::ASKS, 'As before this entry existed: the free space for the staged files could not be read, and the restore waits for someone to say whether to go on.' ),
		SwapStep::CARRY_KIND                      => array( self::ASKS, 'Asked by an undo, which a person starts for a restore that has completed; the site is open as it is while it waits, and failing here would end the undo halfway.' ),
		SwapStep::REDO_CARRY_KIND                 => array( self::ASKS, 'Asked by a redo, which a person starts after an undo; the site is open as the undo left it while it waits.' ),
		SwapStep::AFTER_UNDO_KIND                 => array( self::ASKS, 'Asked at the end of an undo, which a person starts; the site the undo put back is open while it waits, and either answer is a decision about a site already changed.' ),
	);

	/**
	 * Ask: the step's result, or the failure of a restore nobody attends.
	 *
	 * @param JobContext                       $context   Context.
	 * @param array<string, mixed>             $cursor    Cursor to keep.
	 * @param array<int, array<string, mixed>> $questions Questions (JobRepository::validate_questions()), each with a registered kind.
	 * @param string                           $message   Progress text.
	 * @return StepResult
	 * @throws \LogicException When a question's kind is not registered.
	 * @throws Stopped When the restore is unattended and a question is not one asked of it.
	 */
	public static function ask( JobContext $context, array $cursor, array $questions, string $message ): StepResult {
		$unattended = true === ( $context->options()['unattended'] ?? false );
		$refused    = array();
		$ids        = array();
		foreach ( $questions as $question ) {
			$kind = (string) ( $question['kind'] ?? '' );
			if ( ! isset( self::KINDS[ $kind ] ) ) {
				throw new \LogicException( sprintf( 'The restore asks a question of a kind that is not registered (%s): register it in RestoreAsk::KINDS.', $kind ) );
			}
			if ( ! $unattended || self::ASKS === self::KINDS[ $kind ][0] ) {
				continue;
			}
			$ids[]     = (string) ( $question['id'] ?? '' );
			$refused[] = self::refusal( $kind, self::KINDS[ $kind ][0], self::KINDS[ $kind ][1] );
		}
		if ( array() !== $refused ) {
			throw new Stopped( sprintf( 'This restore runs unattended, and it came to a decision only a person can make: %s. Nothing answers such a question in a person\'s place, so the restore stopped. Start the restore attended to decide it.', implode( '; ', $refused ) ), $ids );
		}
		return StepResult::ask( $cursor, $questions, $message );
	}

	/**
	 * What an unattended restore is told it would have been asked.
	 *
	 * @param string $kind  The question's kind.
	 * @param string $mode  POLICY or FAILS.
	 * @param string $about The policy's key (POLICY), or what the question is about (FAILS).
	 * @return string
	 */
	private static function refusal( string $kind, string $mode, string $about ): string {
		if ( self::POLICY !== $mode ) {
			return $about;
		}
		return sprintf( '%1$s (set the restore policy "%2$s")', RestoreJob::POLICY_SUBJECTS[ $about ] ?? $kind, $about );
	}
}

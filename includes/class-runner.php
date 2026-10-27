<?php
/**
 * The two kinds of job.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Hands a job to its runner: a restore, or an export. One job exists at a time.
 */
final class Runner {

	/**
	 * @param Job $job Job.
	 * @return Restore|Export
	 */
	public static function of( Job $job ) {
		return 'export' === $job->get( 'type' ) ? new Export( $job ) : new Restore( $job );
	}

	/**
	 * Clears the job left on the page before a new one starts: a finished one is dismissed, a
	 * stopped one cancelled. One still running blocks the new job.
	 *
	 * @throws \RuntimeException When a job is still running.
	 */
	public static function clear_previous() {
		$job = Job::current();
		if ( ! $job ) {
			return;
		}
		$runner = self::of( $job );
		if ( 'done' === $job->get( 'status' ) ) {
			$runner->dismiss();
		} elseif ( in_array( $job->get( 'status' ), array( 'ready', 'failed' ), true ) ) {
			$runner->cancel();
		} else {
			throw new \RuntimeException( 'export' === $job->get( 'type' ) ? 'A backup is being created: let it finish or cancel it first.' : 'A restore is already under way: let it finish or cancel it first.' );
		}
	}

	/** @return float Seconds a step may work: 10, or a third of PHP's time limit if that is shorter. */
	public static function budget() {
		$budget = (float) apply_filters( 'wpress_restore_step_seconds', 10 );
		$limit  = function_exists( 'ini_get' ) ? (int) ini_get( 'max_execution_time' ) : 0;
		return $limit > 0 ? min( $budget, max( 2, $limit / 3 ) ) : $budget;
	}

	/**
	 * Writes all of $data.
	 *
	 * @param resource $handle File.
	 * @param string   $data   Bytes.
	 * @throws \RuntimeException When the disk takes less.
	 */
	public static function write( $handle, $data ) {
		if ( '' !== $data && fwrite( $handle, $data ) !== strlen( $data ) ) {
			throw new \RuntimeException( 'Writing failed: the disk is probably full. Free some space, then retry.' );
		}
	}
}

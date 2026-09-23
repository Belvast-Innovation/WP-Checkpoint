<?php
/**
 * Plugin Name: WP Checkpoint acceptance probe
 * Description: Records every web request's duration, peak memory and, for job ticks, the job's step and position before and after. For the acceptance run in a development environment only; never install it on a real site.
 *
 * @package WPCheckpoint
 */

// phpcs:ignoreFile -- development tooling, not part of the plugin.

if ( ! defined( 'ABSPATH' ) || 'cli' === PHP_SAPI ) {
	return;
}

/**
 * Where the probe writes: requests.jsonl (one line per request) and
 * inflight/<pid>.json while a tick runs (the kill step picks one of these).
 */
function wpcheckpoint_acceptance_dir(): string {
	return WP_CONTENT_DIR . '/wpcheckpoint-acceptance';
}

/**
 * The job row, reduced to what locates the work: step, status, progress and
 * the cursor's scalar fields (two levels deep).
 */
function wpcheckpoint_acceptance_position( int $job_id ): ?array {
	global $wpdb;
	$table = $wpdb->base_prefix . 'wpcheckpoint_jobs';
	$row   = $job_id > 0
		? $wpdb->get_row( $wpdb->prepare( "SELECT id, status, step, progress, cursor_json FROM {$table} WHERE id = %d", $job_id ), ARRAY_A )
		: $wpdb->get_row( "SELECT id, status, step, progress, cursor_json FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT 1", ARRAY_A );
	if ( ! is_array( $row ) ) {
		return null;
	}
	$cursor = json_decode( (string) $row['cursor_json'], true );
	$unit   = array();
	foreach ( is_array( $cursor ) ? $cursor : array() as $key => $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			$unit[ $key ] = is_string( $value ) ? substr( $value, 0, 200 ) : $value;
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $inner => $v ) {
				if ( is_scalar( $v ) && ! is_int( $inner ) ) {
					$unit[ $key . '.' . $inner ] = is_string( $v ) ? substr( $v, 0, 200 ) : $v;
				}
			}
		}
	}
	return array(
		'job'      => (int) $row['id'],
		'status'   => (string) $row['status'],
		'step'     => (string) $row['step'],
		'progress' => (int) $row['progress'],
		'unit'     => $unit,
	);
}

$wpcheckpoint_acceptance = array(
	'start' => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ),
	'pid'   => getmypid(),
	'uri'   => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
	'kind'  => 'other',
	'job'   => 0,
);
if ( 1 === preg_match( '#/wp-checkpoint/v1/jobs/(\d+)/(tick|loopback)#', $wpcheckpoint_acceptance['uri'], $m ) ) {
	$wpcheckpoint_acceptance['kind'] = $m[2];
	$wpcheckpoint_acceptance['job']  = (int) $m[1];
} elseif ( false !== strpos( $wpcheckpoint_acceptance['uri'], 'wp-cron.php' ) ) {
	$wpcheckpoint_acceptance['kind'] = 'cron';
} elseif ( 1 === preg_match( '#^/(\?.*)?$#', $wpcheckpoint_acceptance['uri'] ) ) {
	$wpcheckpoint_acceptance['kind'] = 'front';
}

if ( in_array( $wpcheckpoint_acceptance['kind'], array( 'tick', 'loopback', 'cron' ), true ) ) {
	$wpcheckpoint_acceptance['before'] = wpcheckpoint_acceptance_position( $wpcheckpoint_acceptance['job'] );
	$dir                               = wpcheckpoint_acceptance_dir() . '/inflight';
	if ( is_dir( $dir ) || @mkdir( $dir, 0777, true ) ) {
		@file_put_contents( $dir . '/' . $wpcheckpoint_acceptance['pid'] . '.json', json_encode( array( 'pid' => $wpcheckpoint_acceptance['pid'], 'start' => $wpcheckpoint_acceptance['start'], 'kind' => $wpcheckpoint_acceptance['kind'] ) ) );
	}
}

register_shutdown_function(
	static function () use ( &$wpcheckpoint_acceptance ): void {
		$probe = $wpcheckpoint_acceptance;
		$line  = array(
			'kind'        => $probe['kind'],
			'start'       => round( $probe['start'], 4 ),
			'seconds'     => round( microtime( true ) - $probe['start'], 4 ),
			'pid'         => $probe['pid'],
			'peak_real'   => memory_get_peak_usage( true ),
			'peak'        => memory_get_peak_usage( false ),
			'limit'       => ini_get( 'memory_limit' ),
			'max_seconds' => (int) ini_get( 'max_execution_time' ),
			'status'      => http_response_code(),
		);
		if ( isset( $probe['before'] ) ) {
			$line['before'] = $probe['before'];
			$line['after']  = wpcheckpoint_acceptance_position( $probe['job'] > 0 ? $probe['job'] : ( is_array( $probe['before'] ) ? $probe['before']['job'] : 0 ) );
			@unlink( wpcheckpoint_acceptance_dir() . '/inflight/' . $probe['pid'] . '.json' );
		}
		$error = error_get_last();
		if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			$line['fatal'] = substr( (string) $error['message'], 0, 300 );
		}
		$dir = wpcheckpoint_acceptance_dir();
		if ( is_dir( $dir ) || @mkdir( $dir, 0777, true ) ) {
			@file_put_contents( $dir . '/requests.jsonl', json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
		}
	}
);

<?php
/**
 * Plugin Name: WP Checkpoint acceptance probe
 * Description: Records every web request's duration, peak memory and, for job ticks, the job's step and position before and after; kills a tick's own web process at planned points (kills.json). For the acceptance run in a development environment only; never install it on a real site.
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
	$cols  = 'id, status, step, progress, cursor_json, takeovers, takeover_mark, lock_token, locked_until';
	$row   = $job_id > 0
		? $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE id = %d", $job_id ), ARRAY_A )
		: $wpdb->get_row( "SELECT {$cols} FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT 1", ARRAY_A );
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
		'takeovers'     => (int) $row['takeovers'],
		'takeover_mark' => (string) $row['takeover_mark'],
		// Held by someone whose lease has run out: this tick takes the job over.
		'lease_expired' => '' !== (string) $row['lock_token'] && (int) $row['locked_until'] <= time(),
	);
}

/**
 * Planned kills: rules in kills.json, each fired once (state in kills-state.json). A rule matches a job-table
 * UPDATE by its call stack: event "checkpoint" (JobContext::checkpoint) or "confirm" (JobContext::confirm_lease,
 * the lease check right before an irreversible transition), the step class in the stack, optionally a function
 * in the stack ("in"), text in the cursor being written ("contains"), and that a confirm inside a given function
 * happened earlier in this request ("after_confirm_in"). It fires on its nth match, before the query runs: the
 * process kills itself with SIGKILL, like a web server killing a worker.
 *
 * @param string $query SQL.
 * @return string
 */
function wpcheckpoint_acceptance_query( $query ) {
	static $confirms = array();
	global $wpdb;
	if ( 0 !== strpos( ltrim( (string) $query ), 'UPDATE' ) || false === strpos( (string) $query, $wpdb->base_prefix . 'wpcheckpoint_jobs' ) ) {
		return $query;
	}
	$dir   = wpcheckpoint_acceptance_dir();
	$rules = json_decode( (string) @file_get_contents( $dir . '/kills.json' ), true );
	if ( ! is_array( $rules ) ) {
		return $query;
	}
	$frames = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 40 );
	$stack  = array();
	$event  = '';
	$cursor = null;
	foreach ( $frames as $frame ) {
		$class   = isset( $frame['class'] ) ? substr( (string) strrchr( '\\' . $frame['class'], '\\' ), 1 ) : '';
		$stack[] = ( '' !== $class ? $class . '::' : '' ) . $frame['function'];
		if ( 'JobContext' === $class && 'checkpoint' === $frame['function'] ) {
			$event  = 'checkpoint';
			$cursor = $frame['args'][0] ?? null;
		} elseif ( 'JobContext' === $class && 'confirm_lease' === $frame['function'] && '' === $event ) {
			$event = 'confirm';
		}
	}
	if ( '' === $event ) {
		return $query;
	}
	$in_stack = static function ( string $name ) use ( $stack ): bool {
		foreach ( $stack as $entry ) {
			// A class ("PackStep"), a function ("seal_volume") or both ("Packer::seal_volume").
			if ( $entry === $name || 0 === strpos( $entry, $name . '::' ) || substr( $entry, -strlen( '::' . $name ) ) === '::' . $name ) {
				return true;
			}
		}
		return false;
	};
	$json  = is_array( $cursor ) ? (string) json_encode( $cursor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
	$state = json_decode( (string) @file_get_contents( $dir . '/kills-state.json' ), true );
	$state = is_array( $state ) ? $state : array();
	foreach ( $rules as $rule ) {
		$id = (string) $rule['id'];
		if ( ! empty( $state[ $id ]['fired'] ) || $rule['event'] !== $event || ! $in_stack( (string) $rule['step'] ) ) {
			continue;
		}
		if ( isset( $rule['in'] ) && ! $in_stack( (string) $rule['in'] ) ) {
			continue;
		}
		if ( isset( $rule['contains'] ) && false === strpos( $json, (string) $rule['contains'] ) ) {
			continue;
		}
		if ( isset( $rule['after_confirm_in'] ) && empty( $confirms[ (string) $rule['after_confirm_in'] ] ) ) {
			continue;
		}
		$state[ $id ]['count'] = (int) ( $state[ $id ]['count'] ?? 0 ) + 1;
		if ( $state[ $id ]['count'] < (int) ( $rule['nth'] ?? 1 ) ) {
			continue;
		}
		$state[ $id ]['fired'] = microtime( true );
		file_put_contents( $dir . '/kills-state.json', json_encode( $state ), LOCK_EX );
		file_put_contents(
			$dir . '/kills.jsonl',
			json_encode( array( 'rule' => $id, 'at' => microtime( true ), 'pid' => getmypid(), 'event' => $event, 'cursor' => substr( $json, 0, 2000 ), 'stack' => array_slice( $stack, 0, 14 ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n",
			FILE_APPEND | LOCK_EX
		);
		posix_kill( getmypid(), 9 );
	}
	file_put_contents( $dir . '/kills-state.json', json_encode( $state ), LOCK_EX );
	if ( 'confirm' === $event ) {
		foreach ( $stack as $entry ) {
			$confirms[ substr( (string) strrchr( '::' . $entry, ':' ), 1 ) ] = true;
		}
	}
	return $query;
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
	// Every driver: REST ticks, and WP-Cron and loopback ticks, which take a job over just as well.
	if ( is_file( wpcheckpoint_acceptance_dir() . '/kills.json' ) ) {
		add_filter( 'query', 'wpcheckpoint_acceptance_query', PHP_INT_MAX );
	}
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

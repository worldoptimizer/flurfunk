<?php
/**
 * AI assistants: routines – standing work on a schedule, as in Claude Tag. "Every Friday at 3pm,
 * post a summary of what is still open in this channel": the assistant sets it up (ability
 * flurfunk/routine) and does it at those times, with the rights of the person who set it up within
 * its limits. Results go into the conversation it was set up in, or as a new thread into the channel
 * each time; with nothing worth reporting it stays quiet. Anyone on the team in the channel can list,
 * pause or remove routines by asking; administrators also remove them in the settings.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Ai;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const ROUTINES     = 'flurfunk_ai_routines'; // id => { name, task, days, time, post, thread, channel, agent, owner, paused, created, last, next }.
const ROUTINE_HOOK = 'flurfunk_ai_routine';
const ROUTINES_MAX = 10; // Per channel (or private conversation).
const DAYS         = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_routines' );
add_action( ROUTINE_HOOK, __NAMESPACE__ . '\\run_routine' );

/**
 * All routines, or those of one place (a channel, or a private conversation by its thread).
 *
 * @param string $channel Channel slug ('' = all, or a private conversation with $thread).
 * @param int    $thread  Private conversation.
 * @return array<string, array>
 */
function routines( string $channel = '', int $thread = 0 ): array {
	$all = (array) get_option( ROUTINES, array() );
	if ( '' === $channel && ! $thread ) {
		return $all;
	}
	return array_filter( $all, static fn ( $r ) => '' !== $channel ? $channel === (string) $r['channel'] : ( '' === (string) $r['channel'] && $thread === (int) $r['thread'] ) );
}

/**
 * The next time a routine is due, after a moment.
 *
 * @param array $r     Routine.
 * @param int   $after Timestamp.
 * @return int Timestamp (0 if it has no days).
 */
function next_time( array $r, int $after ): int {
	$tz = wp_timezone();
	[ $h, $m ] = array_map( 'intval', explode( ':', (string) $r['time'] ) + array( 0, 0 ) );
	$day = ( new \DateTimeImmutable( '@' . $after ) )->setTimezone( $tz )->setTime( $h, $m );
	for ( $i = 0; $i < 8; $i++ ) {
		$at = $day->modify( '+' . $i . ' day' );
		if ( $at->getTimestamp() > $after && in_array( strtolower( $at->format( 'D' ) ), (array) $r['days'], true ) ) {
			return $at->getTimestamp();
		}
	}
	return 0;
}

/**
 * Schedule a routine's next run.
 *
 * @param string $id Routine.
 */
function schedule_routine( string $id ): void {
	$all = (array) get_option( ROUTINES, array() );
	if ( ! isset( $all[ $id ] ) ) {
		return;
	}
	wp_clear_scheduled_hook( ROUTINE_HOOK, array( $id ) );
	$next              = empty( $all[ $id ]['paused'] ) ? next_time( $all[ $id ], time() + 30 ) : 0;
	$all[ $id ]['next'] = $next;
	update_option( ROUTINES, $all, false );
	if ( $next ) {
		wp_schedule_single_event( $next, ROUTINE_HOOK, array( $id ) );
	}
}

/**
 * How a routine runs, for people ("Fri 15:00").
 *
 * @param array $r Routine.
 */
function routine_when( array $r ): string {
	$names = array(
		'mon' => __( 'Mon', 'flurfunk' ),
		'tue' => __( 'Tue', 'flurfunk' ),
		'wed' => __( 'Wed', 'flurfunk' ),
		'thu' => __( 'Thu', 'flurfunk' ),
		'fri' => __( 'Fri', 'flurfunk' ),
		'sat' => __( 'Sat', 'flurfunk' ),
		'sun' => __( 'Sun', 'flurfunk' ),
	);
	$days = (array) $r['days'];
	$text = 7 === count( $days ) ? __( 'daily', 'flurfunk' ) : ( array( 'mon', 'tue', 'wed', 'thu', 'fri' ) === array_values( $days ) ? __( 'weekdays', 'flurfunk' ) : implode( ', ', array_map( static fn ( $d ) => $names[ $d ] ?? $d, $days ) ) );
	return $text . ' ' . (string) $r['time'];
}

/**
 * Permission of flurfunk/routine: an assistant at work (or an assistant account) for a team member
 * who may see the conversation.
 *
 * @param array|null $input Ability input.
 */
function may_routine( $input = null ): bool {
	$t     = Store\thread_row( (int) ( ( (array) $input )['thread'] ?? 0 ) );
	$agent = working() ?: ( Access\is_bot( get_current_user_id() ) ? get_current_user_id() : 0 );
	$user  = get_current_user_id();
	return $agent && $t && is_user_logged_in() && Access\can_see( $t, $user ) && Access\can_see( $t, $agent ) && ( 'team' === Access\kind( $user ) || Access\is_bot( $user ) );
}

/**
 * Add, list, pause, resume or remove routines of a conversation's channel (or of a private
 * conversation).
 *
 * @param array $input { thread, action, name?, task?, days?, time?, post?, id? }.
 * @return array|\WP_Error
 */
function routine( array $input ) {
	$t       = Store\thread_row( (int) ( $input['thread'] ?? 0 ) );
	$channel = $t ? channel_slug( $t ) : '';
	$here    = routines( $channel, '' === $channel && $t ? (int) $t->id : 0 );
	$action  = (string) ( $input['action'] ?? 'list' );
	$list    = static fn ( array $rs ) => array_values(
		array_map(
			static fn ( $id, $r ) => array(
				'id'     => $id,
				'name'   => (string) $r['name'],
				'when'   => routine_when( $r ),
				'task'   => (string) $r['task'],
				'post'   => (string) $r['post'],
				'paused' => ! empty( $r['paused'] ),
				'next'   => ! empty( $r['next'] ) ? wp_date( 'Y-m-d H:i', (int) $r['next'] ) : '',
			),
			array_keys( $rs ),
			$rs
		)
	);
	if ( 'list' === $action ) {
		return array( 'routines' => $list( $here ) );
	}
	fresh_options( true ); // Called during a run: schedule into the current list of events.
	$all = (array) get_option( ROUTINES, array() );
	if ( 'add' !== $action ) {
		$id = (string) ( $input['id'] ?? '' );
		if ( ! isset( $here[ $id ] ) ) {
			return new \WP_Error( 'flurfunk_routine', __( 'There is no such routine here.', 'flurfunk' ), array( 'status' => 404 ) );
		}
		if ( 'remove' === $action ) {
			wp_clear_scheduled_hook( ROUTINE_HOOK, array( $id ) );
			unset( $all[ $id ] );
			update_option( ROUTINES, $all, false );
		} else {
			$all[ $id ]['paused'] = 'pause' === $action;
			update_option( ROUTINES, $all, false );
			schedule_routine( $id );
		}
		return array( 'routines' => $list( routines( $channel, '' === $channel ? (int) $t->id : 0 ) ) );
	}
	$days = array_values( array_intersect( DAYS, array_map( 'strtolower', array_map( 'strval', (array) ( $input['days'] ?? array() ) ) ) ) );
	$time = (string) ( $input['time'] ?? '' );
	$task = trim( mb_substr( sanitize_textarea_field( (string) ( $input['task'] ?? '' ) ), 0, 500 ) );
	if ( ! $days || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) || '' === $task ) {
		return new \WP_Error( 'flurfunk_routine', __( 'A routine needs days, a time (HH:MM) and what to do.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	if ( count( $here ) >= ROUTINES_MAX ) {
		return new \WP_Error( 'flurfunk_routine', __( 'There are already enough routines here. Remove one first.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$id         = wp_generate_password( 10, false, false );
	$all[ $id ] = array(
		'name'    => mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 80 ) ?: mb_substr( $task, 0, 60 ),
		'task'    => $task,
		'days'    => $days,
		'time'    => $time,
		'post'    => 'channel' === ( $input['post'] ?? '' ) && '' !== $channel ? 'channel' : 'thread',
		'thread'  => (int) $t->id,
		'channel' => $channel,
		'agent'   => working() ?: get_current_user_id(),
		'owner'   => get_current_user_id(),
		'paused'  => false,
		'created' => time(),
		'last'    => 0,
		'next'    => 0,
	);
	update_option( ROUTINES, $all, false );
	schedule_routine( $id );
	return array( 'routines' => $list( array( $id => ( (array) get_option( ROUTINES ) )[ $id ] ) ) );
}

/**
 * A routine is due: the assistant does it and posts the result (or stays quiet with nothing to say).
 *
 * @param string $id Routine.
 */
function run_routine( string $id ): void {
	$r = ( (array) get_option( ROUTINES, array() ) )[ $id ] ?? null;
	if ( ! $r || ! empty( $r['paused'] ) ) {
		return;
	}
	schedule_routine( $id ); // The next one first, so one failure does not end the routine.
	$agent  = (int) $r['agent'];
	$owner  = (int) $r['owner'];
	$thread = Store\thread_row( (int) $r['thread'] );
	if ( ! $thread || ! \Flurfunk\Modules\enabled( MODULE ) || ! profile( $agent )['on'] ) {
		return;
	}
	if ( ! Access\can_see( $thread, $owner ) ) {
		// The person it works for cannot see the conversation any more: it pauses.
		$all                  = (array) get_option( ROUTINES, array() );
		$all[ $id ]['paused'] = true;
		update_option( ROUTINES, $all, false );
		schedule_routine( $id );
		return;
	}
	$slug = channel_slug( $thread );
	$p    = profile( $agent );
	if ( ( $p['budget'] && usage( $agent )['tokens'] >= $p['budget'] ) || channel_spent( $slug ) ) {
		return;
	}
	$task = sprintf(
		'It is %1$s. This is your routine "%2$s", set up by %3$s in this conversation: %4$s. Do it now – with your tools where needed – and write the result for the team: concise and easy to scan, no greeting, no mention that it is a routine. If there is nothing worth reporting, reply with only a single dash: -',
		wp_date( 'l, j F Y, H:i' ),
		(string) $r['name'],
		Store\person( $owner )['name'],
		(string) $r['task']
	);
	answering( $agent, $owner );
	$lang = conversation_locale( (string) $r['task'], $owner );
	language( $agent, $lang );
	try {
		$context = with_limit( $agent, $owner, static fn () => flurfunk_as( $agent, static fn () => context( $thread, 0, $agent, $task, $owner ) ), $slug );
		$answer  = is_wp_error( $context ) ? $context : with_limit( $agent, $owner, static fn () => flurfunk_as( $agent, static fn () => answer( $thread, 0, $agent, $context ) ), $slug );
	} finally {
		answering( $agent, 0 );
	}
	fresh_options( true ); // What others wrote while it was working.
	$all = (array) get_option( ROUTINES, array() );
	if ( isset( $all[ $id ] ) ) {
		$all[ $id ]['last'] = time();
		update_option( ROUTINES, $all, false );
	}
	log_run(
		array(
			'agent'   => $agent,
			'asker'   => $owner,
			'thread'  => (int) $thread->id,
			'channel' => $slug,
			'model'   => is_wp_error( $answer ) ? '' : (string) ( $answer['model'] ?? '' ),
			'tokens'  => is_wp_error( $answer ) ? 0 : (int) $answer['tokens'],
			'error'   => is_wp_error( $answer ) ? $answer->get_error_message() : '',
			'kind'    => 'routine',
		)
	);
	if ( is_wp_error( $answer ) ) {
		language( $agent, '' );
		return;
	}
	add_usage( $agent, (int) $answer['tokens'] );
	add_channel_usage( $slug, (int) $answer['tokens'] );
	$text = trim( (string) $answer['text'] );
	if ( '' === $text || preg_match( '/^[-–—]$/u', $text ) ) {
		language( $agent, '' );
		return; // Nothing worth reporting.
	}
	$text = names_to_mentions( $text, $thread );
	in_locale(
		$lang,
		static function () use ( $r, $thread, $agent, $text, $slug ) {
			if ( 'channel' === $r['post'] && '' !== $slug ) {
				$free = static fn ( $max, $user_id ) => (int) $user_id === $agent ? 0 : $max;
				add_filter( 'flurfunk_rate_limit', $free, 99, 2 );
				try {
					flurfunk_post(
						array(
							'channel' => $slug,
							/* translators: date format (see https://www.php.net/manual/datetime.format.php) for the title of a routine's new thread, after its name */
							'title'   => (string) $r['name'] . ' – ' . wp_date( _x( 'M j', 'routine date', 'flurfunk' ) ),
							'body'    => $text,
							'author'  => $agent,
						)
					);
				} finally {
					remove_filter( 'flurfunk_rate_limit', $free, 99 );
				}
			} else {
				reply( (int) $thread->id, $agent, $text );
			}
		}
	);
	language( $agent, '' );
}

/**
 * The ability (offered to every assistant).
 */
function register_routines(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability(
		'flurfunk/routine',
		array(
			'label'               => __( 'Routines', 'flurfunk' ),
			'description'         => __( 'Standing work for an AI assistant on a schedule (e.g. every Friday at 15:00 a summary of what is open in this channel): add, list, pause, resume or remove the routines of a conversation\'s channel.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'thread' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'action' => array(
						'type' => 'string',
						'enum' => array( 'add', 'list', 'pause', 'resume', 'remove' ),
					),
					'name'   => array(
						'type'        => 'string',
						'maxLength'   => 80,
						'description' => __( 'A short name, e.g. "Friday round-up".', 'flurfunk' ),
					),
					'task'   => array(
						'type'        => 'string',
						'maxLength'   => 500,
						'description' => __( 'What to do each time, in a sentence or two.', 'flurfunk' ),
					),
					'days'   => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => DAYS,
						),
					),
					'time'   => array(
						'type'        => 'string',
						'description' => __( 'HH:MM in the site\'s time zone.', 'flurfunk' ),
					),
					'post'   => array(
						'type'        => 'string',
						'enum'        => array( 'thread', 'channel' ),
						'description' => __( 'thread: report in this conversation; channel: start a new thread in the channel each time.', 'flurfunk' ),
					),
					'id'     => array(
						'type'        => 'string',
						'description' => __( 'The routine to pause, resume or remove (from list).', 'flurfunk' ),
					),
				),
				'required'             => array( 'thread', 'action' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => routine( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_routine',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'public'      => false,
			),
		)
	);
}

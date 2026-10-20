<?php
/**
 * AI assistants: check-ins. "Remind us on Friday whether the photo rights are settled" – the
 * assistant sets itself a check-in (ability flurfunk/follow-up) and, when it is due, looks at the
 * thread again and posts a short follow-up there: asks whether it is done and mentions who should
 * answer, or says it is settled. Only when someone asks for it; at most a few per thread, at most
 * 60 days ahead. It works with the rights of the person who asked, within its limits.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Ai;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const FOLLOWUPS      = 'flurfunk_ai_followups'; // id => { thread, agent, asker, at, note, created }.
const FOLLOWUP_HOOK  = 'flurfunk_ai_check_in';
const FOLLOWUPS_MAX  = 5;  // Pending check-ins per thread.
const FOLLOWUPS_DAYS = 60; // How far ahead.

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_followups' );
add_action( FOLLOWUP_HOOK, __NAMESPACE__ . '\\check_in' );

/**
 * The assistant working right now (set around its tools), for abilities that belong to it.
 *
 * @param int|null $set Assistant (0 = none).
 */
function working( ?int $set = null ): int {
	static $agent = 0;
	if ( null !== $set ) {
		$agent = $set;
	}
	return $agent;
}

/**
 * Pending check-ins, optionally of one thread.
 *
 * @param int $thread_id Thread (0 = all).
 * @return array<string, array>
 */
function followups( int $thread_id = 0 ): array {
	$all = (array) get_option( FOLLOWUPS, array() );
	return $thread_id ? array_filter( $all, static fn ( $f ) => (int) ( $f['thread'] ?? 0 ) === $thread_id ) : $all;
}

/**
 * Permission of flurfunk/follow-up: an assistant at work (or an assistant account itself) for a
 * person who may see the conversation.
 *
 * @param array|null $input Ability input.
 */
function may_follow_up( $input = null ): bool {
	$t     = Store\thread_row( (int) ( ( (array) $input )['thread'] ?? 0 ) );
	$agent = working() ?: ( Access\is_bot( get_current_user_id() ) ? get_current_user_id() : 0 );
	return $agent && $t && is_user_logged_in() && Access\can_see( $t, get_current_user_id() ) && Access\can_see( $t, $agent );
}

/**
 * Set (or cancel) a check-in.
 *
 * @param array $input { thread, when, note, cancel? }.
 * @return array|\WP_Error
 */
function follow_up( array $input ) {
	$tid   = (int) ( $input['thread'] ?? 0 );
	$agent = working() ?: get_current_user_id();
	fresh_options( true ); // Called during a run: schedule into the current list of events.
	if ( ! empty( $input['cancel'] ) ) {
		$all = (array) get_option( FOLLOWUPS, array() );
		$n   = 0;
		foreach ( $all as $id => $f ) {
			if ( (int) $f['thread'] === $tid && (int) $f['agent'] === $agent ) {
				wp_unschedule_event( (int) $f['at'], FOLLOWUP_HOOK, array( (string) $id ) );
				unset( $all[ $id ] );
				++$n;
			}
		}
		update_option( FOLLOWUPS, $all, false );
		return array( 'cancelled' => $n );
	}
	try {
		$when = new \DateTimeImmutable( (string) ( $input['when'] ?? '' ), wp_timezone() );
	} catch ( \Exception $e ) {
		return new \WP_Error( 'flurfunk_follow_up', __( 'Give the time as YYYY-MM-DD HH:MM.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$at = $when->getTimestamp();
	if ( $at < time() + 60 || $at > time() + FOLLOWUPS_DAYS * DAY_IN_SECONDS ) {
		/* translators: %d: days */
		return new \WP_Error( 'flurfunk_follow_up', sprintf( __( 'A check-in has to be in the future, at most %d days ahead.', 'flurfunk' ), FOLLOWUPS_DAYS ), array( 'status' => 400 ) );
	}
	if ( count( followups( $tid ) ) >= FOLLOWUPS_MAX ) {
		return new \WP_Error( 'flurfunk_follow_up', __( 'There are already enough check-ins in this conversation.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$note        = trim( mb_substr( sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ), 0, 300 ) );
	$id          = wp_generate_password( 10, false, false );
	$all         = (array) get_option( FOLLOWUPS, array() );
	$all[ $id ]  = array(
		'thread'  => $tid,
		'agent'   => $agent,
		'asker'   => get_current_user_id(),
		'at'      => $at,
		'note'    => $note,
		'created' => time(),
	);
	update_option( FOLLOWUPS, $all, false );
	wp_schedule_single_event( $at, FOLLOWUP_HOOK, array( $id ) );
	return array(
		'id'   => $id,
		'when' => wp_date( 'Y-m-d H:i', $at ),
	);
}

/**
 * "@Full Name" – or "@First" where only one person has that first name, as in the app – as
 * mentions of people who can see the conversation (longest names first).
 *
 * @param string     $text   Text from the AI.
 * @param object     $thread Thread row.
 * @param int[]|null $only   Only these people (e.g. assistants).
 */
function names_to_mentions( string $text, object $thread, ?array $only = null ): string {
	if ( ! str_contains( $text, '@' ) ) {
		return $text;
	}
	$names = array();
	$first = array();
	foreach ( Store\people() as $p ) {
		$name = trim( (string) ( $p['name'] ?? '' ) );
		if ( '' === $name || ( null !== $only && ! in_array( (int) $p['id'], $only, true ) ) || ! Access\can_see( $thread, (int) $p['id'] ) ) {
			continue;
		}
		$names[ $name ] = (int) $p['id'];
		$f              = mb_strtolower( (string) strtok( $name, ' ' ) );
		$first[ $f ]    = isset( $first[ $f ] ) ? -1 : (int) $p['id'];
	}
	uksort( $names, static fn ( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
	foreach ( $names as $name => $id ) {
		$text = (string) preg_replace( '/@' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/u', '<@' . $id . '>', $text );
	}
	return (string) preg_replace_callback(
		'/@([\p{L}\p{N}_-]+)(?![\p{L}\p{N}])/u',
		static fn ( $m ) => ( $first[ mb_strtolower( $m[1] ) ] ?? -1 ) > 0 ? '<@' . $first[ mb_strtolower( $m[1] ) ] . '>' : $m[0],
		$text
	);
}

/**
 * A check-in is due: the assistant looks at the thread again and follows up there.
 *
 * @param string $id Check-in.
 */
function check_in( string $id ): void {
	$all = (array) get_option( FOLLOWUPS, array() );
	$f   = $all[ $id ] ?? null;
	if ( ! $f ) {
		return;
	}
	unset( $all[ $id ] );
	update_option( FOLLOWUPS, $all, false );
	$agent  = (int) $f['agent'];
	$asker  = (int) $f['asker'];
	$thread = Store\thread_row( (int) $f['thread'] );
	if ( ! $thread || ! \Flurfunk\Modules\enabled( MODULE ) || ! profile( $agent )['on'] || ! Access\can_see( $thread, $asker ) ) {
		return;
	}
	$p    = profile( $agent );
	$slug = channel_slug( $thread );
	if ( ( $p['budget'] && usage( $agent )['tokens'] >= $p['budget'] ) || channel_spent( $slug ) ) {
		return;
	}
	$task = sprintf(
		'%1$s asked you to check back now: "%2$s". Look at what happened in this thread since and write a short follow-up for the team: if it is not settled yet, ask whether it is done and mention who should answer as @Name (their full name as shown); if it is settled, say so in one sentence. At most two sentences, no greeting.',
		Store\person( $asker )['name'],
		(string) $f['note']
	);
	answering( $agent, $asker );
	$lang = conversation_locale( (string) $f['note'], $asker );
	language( $agent, $lang );
	try {
		$context = with_limit( $agent, $asker, static fn () => flurfunk_as( $agent, static fn () => context( $thread, 0, $agent, $task, $asker ) ), $slug );
		$answer  = is_wp_error( $context ) ? $context : with_limit( $agent, $asker, static fn () => flurfunk_as( $agent, static fn () => answer( $thread, 0, $agent, $context ) ), $slug );
	} finally {
		answering( $agent, 0 ); // The follow-up notifies like any message: the people it mentions.
	}
	fresh_options( true ); // What others wrote while it was working.
	log_run(
		array(
			'agent'   => $agent,
			'asker'   => $asker,
			'thread'  => (int) $thread->id,
			'channel' => $slug,
			'model'   => is_wp_error( $answer ) ? '' : (string) ( $answer['model'] ?? '' ),
			'tokens'  => is_wp_error( $answer ) ? 0 : (int) $answer['tokens'],
			'error'   => is_wp_error( $answer ) ? $answer->get_error_message() : '',
			'kind'    => 'check-in',
		)
	);
	if ( is_wp_error( $answer ) || '' === trim( (string) $answer['text'] ) ) {
		language( $agent, '' );
		return;
	}
	add_usage( $agent, (int) $answer['tokens'] );
	add_channel_usage( $slug, (int) $answer['tokens'] );
	speaks_for( $agent, $asker ); // An assistant it mentions takes it on for the person who asked.
	try {
		$mid = in_locale( $lang, static fn () => reply( (int) $thread->id, $agent, names_to_mentions( trim( (string) $answer['text'] ), $thread ) ) );
	} finally {
		speaks_for( $agent, 0 );
	}
	if ( is_int( $mid ) ) {
		remember_meta(
			$mid,
			array(
				'asker' => $asker,
				'model' => (string) ( $answer['model'] ?? '' ),
			)
		);
	}
	language( $agent, '' );
}

/**
 * The ability (offered to every assistant).
 */
function register_followups(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability(
		'flurfunk/follow-up',
		array(
			'label'               => __( 'Check back later', 'flurfunk' ),
			'description'         => __( 'An AI assistant sets itself a check-in in a conversation: at that time it looks at the conversation again and follows up there (asks whether something is done, mentions who should answer). Or cancels its check-ins in that conversation.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'thread' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'when'   => array(
						'type'        => 'string',
						'description' => __( 'Date and time in the site\'s time zone: YYYY-MM-DD HH:MM.', 'flurfunk' ),
					),
					'note'   => array(
						'type'        => 'string',
						'maxLength'   => 300,
						'description' => __( 'What to check, in a few words.', 'flurfunk' ),
					),
					'cancel' => array(
						'type'        => 'boolean',
						'description' => __( 'Cancel the check-ins in this conversation instead.', 'flurfunk' ),
					),
				),
				'required'             => array( 'thread' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => follow_up( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_follow_up',
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

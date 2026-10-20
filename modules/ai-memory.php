<?php
/**
 * AI assistants: notes per channel. When someone asks an assistant to remember something lasting
 * ("remember: teasers at most 200 characters"), it keeps a short note for that channel; every
 * assistant there reads the channel's notes before answering. Only on request – nothing is learned
 * on the quiet – and only within the channel: notes never reach other channels, private
 * conversations have none. Administrators see and remove them per channel in the settings.
 * Off until the site switches it on (Settings → Flurfunk → AI assistants).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Ai;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const MEMORY     = 'flurfunk_ai_memory'; // slug => [ { text, at, thread } ]; SITE_NOTES for all channels.
const MEMORY_MAX = 30;                   // Notes per channel (and for all channels).
const SITE_NOTES = '*';                  // Notes for all channels: house rules (as Claude Tag's workspace notes).

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_memory' );

/**
 * Does the site keep notes per channel?
 */
function memory_on(): bool {
	return \Flurfunk\Modules\enabled( MODULE ) && options()['memory'];
}

/**
 * A channel's notes, oldest first.
 *
 * @param string $slug Channel.
 * @return array<int, array{text:string, at:int, thread:int}>
 */
function notes( string $slug ): array {
	$all = (array) get_option( MEMORY, array() );
	return array_values( array_filter( (array) ( $all[ $slug ] ?? array() ), static fn ( $n ) => is_array( $n ) && '' !== (string) ( $n['text'] ?? '' ) ) );
}

/**
 * Store a channel's notes.
 *
 * @param string $slug  Channel.
 * @param array  $notes Notes.
 */
function save_notes( string $slug, array $notes ): void {
	$all = (array) get_option( MEMORY, array() );
	if ( $notes ) {
		$all[ $slug ] = array_values( array_slice( $notes, -MEMORY_MAX ) );
	} else {
		unset( $all[ $slug ] );
	}
	update_option( MEMORY, $all, false );
}

/**
 * The notes as a block for the assistant's instructions ('' if none).
 *
 * @param string $slug Channel.
 */
function notes_text( string $slug ): string {
	$notes = '' !== $slug && memory_on() ? notes( $slug ) : array();
	$lines = array();
	foreach ( $notes as $i => $n ) {
		$lines[] = ( $i + 1 ) . '. ' . $n['text'];
	}
	return $lines ? "Notes for this channel (things the team asked you to remember; they may be outdated – if someone says otherwise, ask whether to change the note):\n" . implode( "\n", $lines ) : '';
}

/**
 * Can guests read this conversation (a channel guests see, or a guest's private conversation)? There
 * an assistant uses no notes for all channels, and the channel's own only where guests see all of
 * it (as Claude Tag, which uses no memory while a guest is present).
 *
 * @param object $thread Thread row.
 */
function guests_read( object $thread ): bool {
	if ( ! empty( $thread->private_to ) ) {
		return 'team' !== Access\kind( (int) $thread->private_to );
	}
	$c = Store\channel( (int) $thread->channel_id );
	if ( ! $c || 'none' === (string) $c->access_guests || \Flurfunk\Caps\TEAM === Access\required_cap( $c ) ) {
		return false;
	}
	// "Own posts": only the threads about a guest's post.
	return 'all' === (string) $c->access_guests || array() !== Store\audience( $thread );
}

/**
 * The notes an assistant reads in a conversation: those for all channels, then the channel's own.
 *
 * @param object $thread Thread row.
 */
function notes_for( object $thread ): string {
	if ( ! memory_on() ) {
		return '';
	}
	$guests  = guests_read( $thread );
	$slug    = channel_slug( $thread );
	$c       = '' !== $slug ? Store\channel( (int) $thread->channel_id ) : null;
	$blocks  = array();
	$site    = $guests ? array() : notes( SITE_NOTES );
	$channel = '' === $slug || ( $guests && ( ! $c || 'all' !== (string) $c->access_guests ) ) ? array() : notes( $slug );
	if ( $site ) {
		$lines = array();
		foreach ( $site as $i => $n ) {
			$lines[] = ( $i + 1 ) . '. ' . $n['text'];
		}
		$blocks[] = "Notes for all channels (house rules the team asked you to remember; to remove one use scope site and its number):\n" . implode( "\n", $lines );
	}
	if ( $channel ) {
		$blocks[] = notes_text( $slug );
	}
	return implode( "\n\n", $blocks );
}

/**
 * Permission of flurfunk/remember: the person may see the conversation, it is in a channel (not
 * private) and the site keeps notes.
 *
 * @param array|null $input Ability input.
 */
function may_remember( $input = null ): bool {
	$t = Store\thread_row( (int) ( ( (array) $input )['thread'] ?? 0 ) );
	if ( ! memory_on() || ! is_user_logged_in() || ! $t || ! empty( $t->private_to ) || ! Access\can_see( $t, get_current_user_id() ) || '' === channel_slug( $t ) ) {
		return false;
	}
	// Notes for all channels only from a channel without guests, by the team.
	return 'site' !== ( ( (array) $input )['scope'] ?? '' ) || ( ! guests_read( $t ) && 'team' === Access\kind() );
}

/**
 * Add, replace or remove a note of the conversation's channel.
 *
 * @param array $input { thread, note?, remove? }.
 * @return array|\WP_Error The channel's notes now.
 */
function remember( array $input ) {
	$t    = Store\thread_row( (int) ( $input['thread'] ?? 0 ) );
	$slug = $t ? channel_slug( $t ) : '';
	if ( '' === $slug ) {
		return new \WP_Error( 'flurfunk_memory', __( 'Notes are kept per channel; this conversation has none.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$here = $slug;
	if ( 'site' === ( $input['scope'] ?? '' ) ) {
		$slug = SITE_NOTES;
	}
	$notes  = notes( $slug );
	$remove = (int) ( $input['remove'] ?? 0 );
	if ( $remove ) {
		if ( ! isset( $notes[ $remove - 1 ] ) ) {
			/* translators: %d: number */
			return new \WP_Error( 'flurfunk_memory', sprintf( __( 'There is no note %d.', 'flurfunk' ), $remove ), array( 'status' => 404 ) );
		}
		array_splice( $notes, $remove - 1, 1 );
	}
	$text = trim( mb_substr( sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ), 0, 300 ) );
	if ( '' !== $text ) {
		$notes[] = array(
			'text'   => (string) preg_replace( '/\s+/', ' ', $text ),
			'at'     => time(),
			'thread' => (int) $t->id,
		);
	}
	save_notes( $slug, $notes );
	Store\bump();
	return array(
		'channel' => SITE_NOTES === $slug ? 'all channels' : $here,
		'notes'   => array_map( static fn ( $n ) => $n['text'], notes( $slug ) ),
	);
}

/**
 * The ability (offered to assistants when the site keeps notes).
 */
function register_memory(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability(
		'flurfunk/remember',
		array(
			'label'               => __( 'Remember for a channel', 'flurfunk' ),
			'description'         => __( 'Keeps a short note (a rule, a fact the team wants kept) for the channel of a conversation – or, with scope site, for all channels – or removes one by its number. Every AI assistant there reads the notes.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'thread' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'note'   => array(
						'type'      => 'string',
						'maxLength' => 300,
					),
					'remove' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Number of a note to remove (to correct one: remove it and add the new one).', 'flurfunk' ),
					),
					'scope'  => array(
						'type'        => 'string',
						'enum'        => array( 'channel', 'site' ),
						'default'     => 'channel',
						'description' => __( 'channel: for this channel; site: a house rule for all channels (only from a channel without guests).', 'flurfunk' ),
					),
				),
				'required'             => array( 'thread' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => remember( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_remember',
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

<?php
/**
 * AI assistants: proposals. An assistant may suggest actions that change something (abilities that
 * are not read-only, chosen per assistant) – but never run them. Each suggestion becomes a card in
 * its answer with "Run" and "Dismiss"; only a person can run it, with their own rights, within the
 * assistant's limit (ai-limits.php). What the person asking may not do is not proposed at all.
 *
 * Proposals are kept in the option flurfunk_ai_proposals (id => ability, input, agent, thread,
 * state, by, at, result), the newest 500.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Ai;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const PROPOSALS     = 'flurfunk_ai_proposals';
const PROPOSALS_MAX = 500;
const PROPOSAL_CARD = 'flurfunk/ai-proposal';

add_action( 'init', __NAMESPACE__ . '\\register_proposals', 20 );

/**
 * Abilities an assistant may propose: registered ones that are not marked read-only (the draft
 * abilities come with the pack "Drafts" and run directly instead).
 *
 * @return array<string, \WP_Ability>
 */
function writable_abilities(): array {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return array();
	}
	$out = array();
	foreach ( wp_get_abilities() as $a ) {
		if ( empty( $a->get_meta()['annotations']['readonly'] ) && ! in_array( $a->get_name(), array_merge( DRAFT_TOOLS, array( 'flurfunk/remember', 'flurfunk/follow-up' ) ), true ) ) {
			$out[ $a->get_name() ] = $a;
		}
	}
	ksort( $out );
	return $out;
}

/**
 * The abilities an assistant may propose (from its profile, still registered).
 *
 * @param int $agent Assistant.
 * @return \WP_Ability[]
 */
function proposal_abilities( int $agent ): array {
	$all = writable_abilities();
	return array_values( array_filter( array_map( static fn ( string $n ) => $all[ $n ] ?? null, profile( $agent )['proposals'] ) ) );
}

/**
 * May the current person (an assistant's asker, within its limit) run this ability with this input?
 * Asked before a card is made, so an assistant never proposes what nobody here could run.
 *
 * @param string $ability Ability name.
 * @param array  $input   Its input, as the AI gave it.
 * @return true|\WP_Error
 */
function may_propose( string $ability, array $input ) {
	$a = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability ) : null;
	if ( ! $a ) {
		return new \WP_Error( 'flurfunk_ai_gone', 'No such ability.' );
	}
	$ok = $a->check_permissions( $input );
	if ( true !== $ok ) {
		return is_wp_error( $ok ) ? $ok : new \WP_Error( 'flurfunk_forbidden', 'Not allowed.' );
	}
	// Changes to posts: every post exists, every text to correct is found – else the AI hears why.
	return \Flurfunk\Changes\NAME === $ability ? \Flurfunk\Changes\validate( $input ) : true;
}

/**
 * Store a proposal.
 *
 * @param string $ability Ability name.
 * @param array  $input   Its input, as the AI gave it.
 * @param int    $agent   Assistant.
 * @param int    $thread  Thread.
 * @return string Proposal ID.
 */
function add_proposal( string $ability, array $input, int $agent, int $thread ): string {
	$preview = array();
	if ( \Flurfunk\Changes\NAME === $ability ) {
		// Remember each post's state now: if someone changes it before the click, it is skipped.
		// And what the card shows as before and after, as it was when proposed.
		foreach ( (array) ( $input['posts'] ?? array() ) as $i => $e ) {
			$post = get_post( (int) ( ( (array) $e )['id'] ?? 0 ) );
			if ( $post ) {
				$input['posts'][ $i ]                    = (array) $e;
				$input['posts'][ $i ]['unchanged_since'] = (string) $post->post_modified_gmt;
				$plan                                    = \Flurfunk\Changes\plan( (array) $e );
				$preview[ $post->ID ]                    = is_wp_error( $plan ) ? array() : $plan;
			}
		}
	}
	$all        = (array) get_option( PROPOSALS, array() );
	$id         = wp_generate_password( 12, false, false );
	$all[ $id ] = array(
		'ability' => $ability,
		'input'   => $input,
		'agent'   => $agent,
		'thread'  => $thread,
		'state'   => 'open',
		'locale'  => language( $agent ),
		'preview' => $preview,
		'by'      => 0,
		'at'      => 0,
		'result'  => '',
	);
	update_option( PROPOSALS, array_slice( $all, -PROPOSALS_MAX, null, true ), false );
	return $id;
}

/**
 * A proposal (or null).
 *
 * @param string $id Proposal ID.
 */
function proposal( string $id ): ?array {
	$p = ( (array) get_option( PROPOSALS, array() ) )[ $id ] ?? null;
	return is_array( $p ) ? $p : null;
}

/**
 * Change a proposal.
 *
 * @param string $id      Proposal ID.
 * @param array  $changes Keys to set.
 */
function update_proposal( string $id, array $changes ): void {
	$all = (array) get_option( PROPOSALS, array() );
	if ( isset( $all[ $id ] ) ) {
		$all[ $id ] = array_merge( $all[ $id ], $changes );
		update_option( PROPOSALS, $all, false );
	}
}

/**
 * The card provider.
 */
function register_proposals(): void {
	flurfunk_register_provider(
		PROPOSAL_CARD,
		array(
			'command'  => 'ai-proposal',
			'label'    => __( 'Proposal of an AI assistant', 'flurfunk' ),
			'hidden'   => true,
			'snapshot' => __NAMESPACE__ . '\\proposal_snapshot',
			'actions'  => array(
				'run'     => array(
					'label'    => __( 'Run', 'flurfunk' ),
					/* translators: %s: person */
					'done'     => __( '%s ran the proposal', 'flurfunk' ),
					'style'    => 'primary',
					'confirm'  => __( 'Run this now, with your rights?', 'flurfunk' ),
					'callback' => __NAMESPACE__ . '\\run_proposal',
				),
				'undo'    => array(
					'label'    => __( 'Undo', 'flurfunk' ),
					/* translators: %s: person */
					'done'     => __( '%s undid the changes', 'flurfunk' ),
					'style'    => 'secondary',
					'confirm'  => __( 'Undo these changes, with your rights?', 'flurfunk' ),
					'callback' => __NAMESPACE__ . '\\undo_proposal',
				),
				'dismiss' => array(
					'label'    => __( 'Dismiss', 'flurfunk' ),
					/* translators: %s: person */
					'done'     => __( '%s dismissed the proposal', 'flurfunk' ),
					'style'    => 'secondary',
					'callback' => static function ( array $ref, array $card, int $user_id ) {
						$p = proposal( (string) ( $ref['id'] ?? '' ) );
						if ( ! $p || 'open' !== $p['state'] ) {
							return null;
						}
						update_proposal(
							(string) $ref['id'],
							array(
								'state' => 'dismissed',
								'by'    => $user_id,
								'at'    => time(),
							)
						);
						return null;
					},
				),
			),
		)
	);
}

/**
 * Card of a proposal.
 *
 * @param array $ref     { id }.
 * @param int   $user_id Who looks.
 * @return array|null
 */
function proposal_snapshot( array $ref, int $user_id = 0 ): ?array {
	$p = proposal( (string) ( $ref['id'] ?? '' ) );
	if ( ! $p ) {
		return null;
	}
	// In the language of the conversation it came from.
	return in_locale( (string) ( $p['locale'] ?? '' ), static fn () => build_snapshot( $p ) );
}

/**
 * The card's content (in the current language).
 *
 * @param array $p Proposal.
 */
function build_snapshot( array $p ): array {
	if ( \Flurfunk\Changes\NAME === $p['ability'] ) {
		return changes_snapshot( $p );
	}
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $p['ability'] ) : null;
	$fields  = array();
	$schema  = $ability ? (array) ( $ability->get_input_schema()['properties'] ?? array() ) : array();
	// Flurfunk's own abilities: their labels in this language (registered ones are in the language
	// of the request that registered them).
	$own = \Flurfunk\Abilities\texts()[ (string) $p['ability'] ] ?? null;
	foreach ( (array) $p['input'] as $key => $value ) {
		$fields[] = array(
			// The schema's title if it has one, else the key.
			'label' => (string) ( $own['fields'][ $key ] ?? $schema[ $key ]['title'] ?? ucfirst( str_replace( '_', ' ', (string) $key ) ) ),
			'value' => mb_substr( is_scalar( $value ) ? (string) ( is_bool( $value ) ? ( $value ? __( 'yes', 'flurfunk' ) : __( 'no', 'flurfunk' ) ) : $value ) : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ), 0, 300 ),
		);
	}
	if ( '' !== (string) $p['result'] ) {
		$result = json_decode( (string) $p['result'], true );
		// A new Flurfunk conversation: a link to it instead of its ID.
		if ( str_starts_with( (string) $p['ability'], 'flurfunk/' ) && is_array( $result ) && ! empty( $result['id'] ) ) {
			$fields[] = array(
				'label' => __( 'Result', 'flurfunk' ),
				'value' => __( 'Open the conversation', 'flurfunk' ),
				'url'   => \Flurfunk\Admin\url( 'thread:' . (int) $result['id'] ),
			);
		} else {
			$fields[] = array(
				'label' => __( 'Result', 'flurfunk' ),
				'value' => (string) $p['result'],
			);
		}
	}
	$who    = $p['by'] ? \Flurfunk\Store\person( (int) $p['by'] )['name'] : '';
	$status = array(
		'open'      => array(
			'label' => __( 'Waiting for a person', 'flurfunk' ),
			'tone'  => 'warning',
		),
		'done'      => array(
			/* translators: %s: person */
			'label' => sprintf( __( 'Run by %s', 'flurfunk' ), $who ),
			'tone'  => 'positive',
		),
		'dismissed' => array(
			/* translators: %s: person */
			'label' => sprintf( __( 'Dismissed by %s', 'flurfunk' ), $who ),
			'tone'  => 'neutral',
		),
	)[ $p['state'] ] ?? array(
		'label' => $p['state'],
		'tone'  => 'neutral',
	);
	// The card's own look (assets/app.js): one sentence for what would happen, a line with where,
	// a button named after the action. The generic keys above stay for other renderers.
	$in   = (array) $p['input'];
	$kind = 'action';
	$line = $own ? $own['label'] : ( $ability ? $ability->get_label() : (string) $p['ability'] );
	$sub  = $ability && ! $own ? (string) $ability->get_description() : '';
	$run  = __( 'Run', 'flurfunk' );
	if ( 'flurfunk/start-thread' === $p['ability'] ) {
		$task  = ! empty( $in['task'] );
		$kind  = $task ? 'task' : 'thread';
		$what  = trim( (string) ( $in['title'] ?? '' ) ) ?: mb_substr( wp_strip_all_tags( (string) ( $in['text'] ?? '' ) ), 0, 80 );
		/* translators: %s: title */
		$line  = sprintf( $task ? __( 'Task: %s', 'flurfunk' ) : __( 'New conversation: %s', 'flurfunk' ), $what );
		/* translators: %s: channel */
		$sub   = sprintf( __( 'in #%s', 'flurfunk' ), sanitize_key( (string) ( $in['channel'] ?? '' ) ) );
		$run   = $task ? __( 'Create task', 'flurfunk' ) : __( 'Start conversation', 'flurfunk' );
		$extra = trim( (string) ( $in['text'] ?? '' ) );
	} elseif ( 'flurfunk/reply' === $p['ability'] ) {
		$kind  = 'reply';
		$line  = __( 'Reply in a conversation', 'flurfunk' );
		$sub   = mb_substr( wp_strip_all_tags( (string) ( $in['text'] ?? '' ) ), 0, 140 );
		$run   = __( 'Send reply', 'flurfunk' );
		$extra = '';
	}
	$result_url = '';
	foreach ( $fields as $f ) {
		if ( ! empty( $f['url'] ) ) {
			$result_url = (string) $f['url'];
		}
	}
	return array(
		'title'    => $line, // What would happen ("Task: …"), also in the line of who ran it.
		'subtitle' => $own ? $own['description'] : ( $ability ? $ability->get_description() : '' ),
		'status'   => $status,
		'fields'   => $fields,
		'actions'  => 'open' === $p['state'] && $ability ? array( 'run', 'dismiss' ) : array(),
		'data'     => array(
			'bare'   => true,
			'kind'   => $kind,
			'line'   => $line,
			'sub'    => $sub,
			'text'   => isset( $extra ) ? mb_substr( (string) $extra, 0, 400 ) : '',
			'run'    => $run,
			'state'  => (string) $p['state'],
			'by'     => $who,
			'at'     => $p['at'] ? gmdate( 'c', (int) $p['at'] ) : '',
			'url'    => $result_url,
			'agent'  => (int) $p['agent'],
			'fields' => 'action' === $kind ? $fields : array(),
		),
	);
}

/**
 * The card of changes to posts: one row per post with before and after; once run, what happened
 * to each (changed or skipped, and why); "Undo" while it can be undone.
 *
 * @param array $p Proposal.
 */
function changes_snapshot( array $p ): array {
	$entries = \Flurfunk\Changes\entries( (array) $p['input'] );
	$name    = static fn ( int $u ): string => $u ? \Flurfunk\Store\person( $u )['name'] : '';
	$rows    = array();
	$fields  = array();
	foreach ( $entries as $e ) {
		$id   = (int) $e['id'];
		$post = get_post( $id );
		// Before and after as proposed; while open, checked again against the post as it is now.
		$plan = 'open' === $p['state'] || ! isset( $p['preview'][ $id ] ) ? \Flurfunk\Changes\plan( $e ) : (array) $p['preview'][ $id ];
		$row  = array(
			'id'      => $id,
			'title'   => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '#' . $id,
			'url'     => $post ? (string) get_edit_post_link( $id, 'raw' ) : '',
			'changes' => is_wp_error( $plan ) ? (array) ( $p['preview'][ $id ] ?? array() ) : $plan,
			'note'    => is_wp_error( $plan ) ? $plan->get_error_message() : '',
		);
		if ( 'open' !== $p['state'] && 'dismissed' !== $p['state'] ) {
			$row['status'] = 'left'; // Not ticked when it was applied (the outcome below says otherwise).
		}
		// Changed by someone since it was proposed: it would be skipped.
		if ( 'open' === $p['state'] && $post && ! empty( $e['unchanged_since'] ) && (string) $e['unchanged_since'] !== (string) $post->post_modified_gmt ) {
			$row['note'] = __( 'Someone changed this post in the meantime.', 'flurfunk' );
		}
		foreach ( $row['changes'] as $c ) {
			$fields[ $c['label'] ] = true;
		}
		// Short enough for a card (its data has a size limit): at most 8 changes, texts shortened.
		$cut            = static fn ( $v ) => mb_strlen( (string) $v ) > 160 ? mb_substr( (string) $v, 0, 159 ) . '…' : (string) $v;
		$row['more']    = max( 0, count( $row['changes'] ) - 8 );
		$row['changes'] = array_map( static fn ( $c ) => array_map( $cut, $c ), array_slice( $row['changes'], 0, 8 ) );
		$rows[ $id ]    = $row;
	}
	// After running (or undoing): what happened to each post.
	foreach ( array( 'outcome', 'undone' ) as $key ) {
		foreach ( (array) ( $p[ $key ] ?? array() ) as $r ) {
			$id = (int) ( $r['id'] ?? 0 );
			if ( isset( $rows[ $id ] ) ) {
				$rows[ $id ]['status'] = (string) ( $r['status'] ?? '' );
				$rows[ $id ]['note']   = (string) ( $r['message'] ?? '' );
			}
		}
	}
	$n      = count( $entries );
	/* translators: %d: number of posts */
	$line   = sprintf( _n( 'Change %d post', 'Change %d posts', $n, 'flurfunk' ), $n );
	$by     = $name( (int) $p['by'] );
	$undoer = $name( (int) ( $p['undo_by'] ?? 0 ) );
	$status = array(
		/* translators: %s: person */
		'done'      => array( 'label' => sprintf( __( 'Applied by %s', 'flurfunk' ), $by ), 'tone' => 'positive' ),
		/* translators: %s: person */
		'undone'    => array( 'label' => sprintf( __( 'Undone by %s', 'flurfunk' ), $undoer ), 'tone' => 'neutral' ),
		/* translators: %s: person */
		'dismissed' => array( 'label' => sprintf( __( 'Dismissed by %s', 'flurfunk' ), $by ), 'tone' => 'neutral' ),
	)[ $p['state'] ] ?? array( 'label' => __( 'Waiting for a person', 'flurfunk' ), 'tone' => 'warning' );
	return array(
		'title'    => $line,
		'subtitle' => implode( ', ', array_keys( $fields ) ),
		'status'   => $status,
		'fields'   => array_map( static fn ( $r ) => array( 'label' => $r['title'], 'value' => implode( ', ', array_map( static fn ( $c ) => $c['label'], $r['changes'] ) ) ), array_values( $rows ) ),
		'actions'  => 'open' === $p['state'] ? array( 'run', 'dismiss' ) : ( 'done' === $p['state'] && undoable( $p ) ? array( 'undo' ) : array() ),
		'data'     => array(
			'bare'    => true,
			'kind'    => 'changes',
			'line'    => $line,
			'sub'     => implode( ', ', array_keys( $fields ) ),
			'run'     => __( 'Apply', 'flurfunk' ),
			'state'   => (string) $p['state'],
			'by'      => $by,
			'at'      => $p['at'] ? gmdate( 'c', (int) $p['at'] ) : '',
			'undo_by' => $undoer,
			'undo_at' => ! empty( $p['undo_at'] ) ? gmdate( 'c', (int) $p['undo_at'] ) : '',
			'undo'    => 'done' === $p['state'] && undoable( $p ),
			'rows'    => array_values( $rows ),
			'agent'   => (int) $p['agent'],
		),
	);
}

/**
 * "Run": the ability runs as the person who clicked, with its own permission check, within the
 * limit of the assistant that proposed it.
 *
 * @param array $ref     { id }.
 * @param array $card    Card.
 * @param int   $user_id Who clicked.
 * @return array|\WP_Error|null
 */
function run_proposal( array $ref, array $card, int $user_id, array $choice = array() ) {
	$id = (string) ( $ref['id'] ?? '' );
	$p  = proposal( $id );
	if ( ! $p || 'open' !== $p['state'] ) {
		return null;
	}
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $p['ability'] ) : null;
	if ( ! $ability ) {
		return new \WP_Error( 'flurfunk_ai_gone', __( 'This action is no longer available on the site.', 'flurfunk' ) );
	}
	$input   = (array) $p['input'];
	$changes = \Flurfunk\Changes\NAME === $p['ability'];
	if ( $changes && isset( $choice['rows'] ) ) {
		// Only the rows the person left ticked.
		$rows           = array_map( 'intval', (array) $choice['rows'] );
		$input['posts'] = array_values( array_filter( (array) ( $input['posts'] ?? array() ), static fn ( $e ) => in_array( (int) ( ( (array) $e )['id'] ?? 0 ), $rows, true ) ) );
		if ( ! $input['posts'] ) {
			return new \WP_Error( 'flurfunk_change_empty', __( 'Tick at least one post.', 'flurfunk' ), array( 'status' => 400 ) );
		}
	}
	$me = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		// With the clicker's rights, within the limit of the assistant that proposed it.
		$result = with_limit( (int) $p['agent'], $user_id, static fn () => $ability->execute( $input ), proposal_channel( $p ) );
	} finally {
		wp_set_current_user( $me );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	update_proposal(
		$id,
		array(
			'state'   => 'done',
			'by'      => $user_id,
			'at'      => time(),
			'result'  => $changes ? '' : mb_substr( is_scalar( $result ) ? (string) $result : (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 300 ),
			'outcome' => $changes ? (array) ( $result['posts'] ?? array() ) : null,
		)
	);
	return null;
}

/**
 * "Undo" of changes to posts: back to the state before, post by post, with the clicker's rights
 * within the assistant's limit – unless someone changed a post again since.
 *
 * @param array $ref     { id }.
 * @param array $card    Card.
 * @param int   $user_id Who clicked.
 * @return \WP_Error|null
 */
function undo_proposal( array $ref, array $card, int $user_id ) {
	$id = (string) ( $ref['id'] ?? '' );
	$p  = proposal( $id );
	if ( ! $p || 'done' !== $p['state'] || \Flurfunk\Changes\NAME !== $p['ability'] || ! undoable( $p ) ) {
		return new \WP_Error( 'flurfunk_action_gone', __( 'This is no longer possible. The card shows the current state.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	$me = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		$result = with_limit( (int) $p['agent'], $user_id, static fn () => \Flurfunk\Changes\undo( (array) $p['outcome'] ), proposal_channel( $p ) );
	} finally {
		wp_set_current_user( $me );
	}
	update_proposal(
		$id,
		array(
			'state'   => 'undone',
			'undone'  => (array) $result['posts'],
			'undo_by' => $user_id,
			'undo_at' => time(),
		)
	);
	return null;
}

/**
 * The channel a proposal was made in (for the channel's limit; '' if private or gone).
 *
 * @param array $p Proposal.
 */
function proposal_channel( array $p ): string {
	$t = \Flurfunk\Store\thread_row( (int) ( $p['thread'] ?? 0 ) );
	return $t ? channel_slug( $t ) : '';
}

/**
 * Can the changes of a run proposal still be undone (at least one post changed)?
 *
 * @param array $p Proposal.
 */
function undoable( array $p ): bool {
	return (bool) array_filter( (array) ( $p['outcome'] ?? array() ), static fn ( $r ) => 'changed' === ( $r['status'] ?? '' ) );
}

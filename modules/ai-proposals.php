<?php
/**
 * AI assistants: proposals. An assistant may suggest actions that change something (abilities that
 * are not read-only, chosen per assistant) – but never run them. Each suggestion becomes a card in
 * its answer with "Run" and "Dismiss"; only a person can run it, with their own rights.
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
 * Abilities an assistant may propose: registered ones that are not marked read-only.
 *
 * @return array<string, \WP_Ability>
 */
function writable_abilities(): array {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return array();
	}
	$out = array();
	foreach ( wp_get_abilities() as $a ) {
		if ( empty( $a->get_meta()['annotations']['readonly'] ) ) {
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
 * Store a proposal.
 *
 * @param string $ability Ability name.
 * @param array  $input   Its input, as the AI gave it.
 * @param int    $agent   Assistant.
 * @param int    $thread  Thread.
 * @return string Proposal ID.
 */
function add_proposal( string $ability, array $input, int $agent, int $thread ): string {
	$all        = (array) get_option( PROPOSALS, array() );
	$id         = wp_generate_password( 12, false, false );
	$all[ $id ] = array(
		'ability' => $ability,
		'input'   => $input,
		'agent'   => $agent,
		'thread'  => $thread,
		'state'   => 'open',
		'locale'  => language( $agent ),
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
	return array(
		'title'    => $own ? $own['label'] : ( $ability ? $ability->get_label() : (string) $p['ability'] ),
		'subtitle' => $own ? $own['description'] : ( $ability ? $ability->get_description() : '' ),
		'status'   => $status,
		'fields'   => $fields,
		'actions'  => 'open' === $p['state'] && $ability ? array( 'run', 'dismiss' ) : array(),
	);
}

/**
 * "Run": the ability runs as the person who clicked, with its own permission check.
 *
 * @param array $ref     { id }.
 * @param array $card    Card.
 * @param int   $user_id Who clicked.
 * @return array|\WP_Error|null
 */
function run_proposal( array $ref, array $card, int $user_id ) {
	$id = (string) ( $ref['id'] ?? '' );
	$p  = proposal( $id );
	if ( ! $p || 'open' !== $p['state'] ) {
		return null;
	}
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) $p['ability'] ) : null;
	if ( ! $ability ) {
		return new \WP_Error( 'flurfunk_ai_gone', __( 'This action is no longer available on the site.', 'flurfunk' ) );
	}
	$me = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		$result = $ability->execute( (array) $p['input'] );
	} finally {
		wp_set_current_user( $me );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	update_proposal(
		$id,
		array(
			'state'  => 'done',
			'by'     => $user_id,
			'at'     => time(),
			'result' => mb_substr( is_scalar( $result ) ? (string) $result : (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 300 ),
		)
	);
	return null;
}

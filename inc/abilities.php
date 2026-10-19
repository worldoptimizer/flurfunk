<?php
/**
 * Flurfunk as WordPress abilities (Abilities API, WordPress 6.9+): other plugins, the AI client's
 * tool use and external agents (through the MCP adapter) can read conversations and, where the
 * person may, write in them. Every ability runs as the current user with Flurfunk's own checks.
 *
 * Reading abilities are public (REST/MCP) by default, writing ones are not: the filters
 * flurfunk_abilities_public and flurfunk_abilities_public_write change that.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Abilities;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * The category.
 */
function category(): void {
	wp_register_ability_category(
		'flurfunk',
		array(
			'label'       => __( 'Flurfunk', 'flurfunk' ),
			'description' => __( 'Team conversations, tasks and channels.', 'flurfunk' ),
		)
	);
}

/**
 * May the current user use Flurfunk at all?
 */
function allowed(): bool {
	return is_user_logged_in() && Access\can_use();
}

/**
 * May this channel be used here? Other code can narrow it (the AI module does while an assistant
 * looks things up: only channels the assistant takes part in).
 *
 * @param string $slug Channel.
 */
function channel_ok( string $slug ): bool {
	return (bool) apply_filters( 'flurfunk_ability_channel', true, $slug );
}

/**
 * A thread in short: for lists.
 *
 * @param array $t Thread as the interface gets it.
 */
function brief( array $t ): array {
	return array(
		'id'       => (int) $t['id'],
		'channel'  => (string) $t['channel'],
		'title'    => (string) ( $t['title'] ?? '' ),
		'text'     => mb_substr( (string) preg_replace( '/<@(\d+)>/', '@$1', (string) $t['body'] ), 0, 300 ),
		'author'   => Store\person( (int) $t['author'] )['name'],
		'status'   => (string) ( $t['status'] ?? '' ),
		'replies'  => (int) ( $t['replies'] ?? 0 ),
		'activity' => (string) ( $t['activity'] ?? $t['created'] ?? '' ),
		'link'     => \Flurfunk\Admin\url( 'thread:' . (int) $t['id'] ),
	);
}

/**
 * Mentions in readable form.
 *
 * @param string $text Text.
 */
function names( string $text ): string {
	return (string) preg_replace_callback( '/<@(\d+)>/', static fn ( $m ) => '@' . Store\person( (int) $m[1] )['name'], $text );
}

/**
 * Register the abilities.
 */
function register(): void {
	$read  = (bool) apply_filters( 'flurfunk_abilities_public', true );
	$write = (bool) apply_filters( 'flurfunk_abilities_public_write', false );
	wp_register_ability(
		'flurfunk/list-channels',
		array(
			'label'               => __( 'List channels', 'flurfunk' ),
			'description'         => __( 'The Flurfunk channels the current user can read: short name, name and topic.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'execute_callback'    => static function (): array {
				$uid = get_current_user_id();
				$out = array();
				foreach ( Store\channels() as $c ) {
					if ( empty( $c->archived ) && 'none' !== Access\level( $c, $uid ) && channel_ok( (string) $c->slug ) ) {
						$out[] = array(
							'channel' => (string) $c->slug,
							'name'    => (string) $c->name,
							'topic'   => (string) $c->topic,
						);
					}
				}
				return $out;
			},
			'permission_callback' => __NAMESPACE__ . '\\allowed',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'      => $read,
			),
		)
	);
	wp_register_ability(
		'flurfunk/list-threads',
		array(
			'label'               => __( 'List conversations', 'flurfunk' ),
			'description'         => __( 'Conversations in Flurfunk the current user can read, newest activity first. Filter by channel (leave it out to search all channels), by view (all, mine = my tasks, tasks = open tasks, mentions) or by search words.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'channel' => array(
						'type'        => 'string',
						'description' => __( 'Channel short name, e.g. general.', 'flurfunk' ),
					),
					'view'    => array(
						'type' => 'string',
						'enum' => array( 'all', 'mine', 'tasks', 'mentions' ),
					),
					'search'  => array( 'type' => 'string' ),
					'limit'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'execute_callback'    => static function ( $input = array() ): array {
				$input = (array) $input;
				$rows  = Store\list_threads(
					get_current_user_id(),
					array(
						'channel' => sanitize_key( (string) ( $input['channel'] ?? '' ) ),
						'view'    => in_array( $input['view'] ?? '', array( 'mine', 'tasks', 'mentions' ), true ) ? $input['view'] : 'all',
						'q'       => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
						'limit'   => max( 1, min( 50, (int) ( $input['limit'] ?? 20 ) ) ),
					)
				);
				return array_values( array_map( __NAMESPACE__ . '\\brief', array_filter( $rows, static fn ( $t ) => channel_ok( (string) $t['channel'] ) ) ) );
			},
			'permission_callback' => __NAMESPACE__ . '\\allowed',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'      => $read,
			),
		)
	);
	wp_register_ability(
		'flurfunk/get-thread',
		array(
			'label'               => __( 'Read a conversation', 'flurfunk' ),
			'description'         => __( 'One conversation with its messages (newest 100), if the current user may read it.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static function ( $input = array() ) {
				$full = Store\full_thread( (int) ( (array) $input )['id'], get_current_user_id() );
				if ( is_wp_error( $full ) ) {
					return $full;
				}
				if ( ! channel_ok( (string) $full['channel'] ) ) {
					return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
				}
				$messages = array();
				foreach ( array_slice( $full['messages'], -100 ) as $m ) {
					if ( 'message' === $m['type'] ) {
						$messages[] = array(
							'author' => Store\person( (int) $m['author'] )['name'],
							'time'   => (string) $m['created'],
							'text'   => names( (string) $m['text'] ),
						);
					}
				}
				$target = null;
				if ( ! empty( $full['target'] ) ) {
					$info   = \Flurfunk\Targets\info( (string) $full['target']['type'], (int) $full['target']['id'] );
					$target = $info ? array(
						'title' => $info['title'],
						'url'   => $info['url'],
					) : null;
				}
				return array_merge(
					brief( $full ),
					array(
						'text'     => names( (string) $full['body'] ),
						'about'    => $target,
						'messages' => $messages,
					)
				);
			},
			'permission_callback' => __NAMESPACE__ . '\\allowed',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'      => $read,
			),
		)
	);
	wp_register_ability(
		'flurfunk/reply',
		array(
			'label'               => __( 'Reply in a conversation', 'flurfunk' ),
			'description'         => __( 'Adds a reply to a conversation as the current user, if they may write there. Mentions as <@user ID>.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'text' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'             => array( 'id', 'text' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static function ( $input = array() ) {
				$input = (array) $input;
				$id    = Store\add_message( (int) $input['id'], array( 'body' => (string) $input['text'] ) );
				return is_wp_error( $id ) ? $id : array( 'message_id' => (int) $id );
			},
			'permission_callback' => __NAMESPACE__ . '\\allowed',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'public'      => $write,
			),
		)
	);
	wp_register_ability(
		'flurfunk/start-thread',
		array(
			'label'               => __( 'Start a conversation', 'flurfunk' ),
			'description'         => __( 'Starts a conversation in a channel as the current user, optionally as a task.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'channel' => array( 'type' => 'string' ),
					'title'   => array( 'type' => 'string' ),
					'text'    => array( 'type' => 'string' ),
					'task'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'channel', 'text' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static function ( $input = array() ) {
				$input = (array) $input;
				$id    = Store\create_thread(
					array(
						'channel' => sanitize_key( (string) $input['channel'] ),
						'title'   => (string) ( $input['title'] ?? '' ),
						'body'    => (string) $input['text'],
						'task'    => ! empty( $input['task'] ),
					)
				);
				return is_wp_error( $id ) ? $id : array( 'id' => (int) $id );
			},
			'permission_callback' => __NAMESPACE__ . '\\allowed',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'public'      => $write,
			),
		)
	);
}

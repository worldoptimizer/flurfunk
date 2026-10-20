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
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return; // WordPress before 6.9: no abilities.
	}
	wp_register_ability_category(
		'flurfunk',
		array(
			'label'       => __( 'Flurfunk', 'flurfunk' ),
			'description' => __( 'Team conversations, tasks and channels.', 'flurfunk' ),
		)
	);
}

/**
 * Labels of Flurfunk's abilities that change something, translated when asked (so a proposal card
 * can be shown in the language of the conversation, not only the one the request started in).
 *
 * @return array<string, array{label:string, description:string, fields:array<string,string>}>
 */
function texts(): array {
	return array(
		'flurfunk/reply'        => array(
			'label'       => __( 'Reply in a conversation', 'flurfunk' ),
			'description' => __( 'Adds a reply to a conversation as the current user, if they may write there. Mentions as <@user ID>.', 'flurfunk' ),
			'fields'      => array(
				'id'   => __( 'Conversation', 'flurfunk' ),
				'text' => __( 'Text', 'flurfunk' ),
			),
		),
		'flurfunk/start-thread' => array(
			'label'       => __( 'Start a conversation', 'flurfunk' ),
			'description' => __( 'Starts a conversation in a channel as the current user, optionally as a task.', 'flurfunk' ),
			'fields'      => array(
				'channel' => __( 'Channel', 'flurfunk' ),
				'title'   => __( 'Title', 'flurfunk' ),
				'text'    => __( 'Text', 'flurfunk' ),
				'task'    => __( 'As a task', 'flurfunk' ),
			),
		),
	);
}

/**
 * May the current user use Flurfunk at all?
 */
function allowed(): bool {
	return is_user_logged_in() && Access\can_use();
}

/**
 * May the current person start a conversation in this channel? For the permission check of
 * flurfunk/start-thread, so a refusal comes before running (an AI assistant then does not even
 * propose it); the store checks again when it runs.
 *
 * @param array|null $input Ability input.
 */
function may_start( $input = null ): bool {
	$ch = Store\channel_by_slug( sanitize_key( (string) ( ( (array) $input )['channel'] ?? '' ) ) );
	return allowed() && $ch && Access\can_start( $ch, '', 0, get_current_user_id() );
}

/**
 * May the current person reply in this conversation? For the permission check of flurfunk/reply.
 *
 * @param array|null $input Ability input.
 */
function may_reply( $input = null ): bool {
	$t = Store\thread_row( (int) ( ( (array) $input )['id'] ?? 0 ) );
	return allowed() && $t && Access\can_see( $t, get_current_user_id() );
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
 * A post as plain text: without block markup and shortcodes, paragraphs as line breaks, without
 * invisible characters (they can hide instructions).
 *
 * @param \WP_Post $p Post.
 */
function plain_text( \WP_Post $p ): string {
	$html = (string) preg_replace( '/<!--.*?-->/s', '', strip_shortcodes( (string) $p->post_content ) );
	$html = (string) preg_replace( '#</(p|h[1-6]|li|blockquote|figure|figcaption|tr|div)>|<br\s*/?>#i', "\n", $html );
	$text = trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) preg_replace( '/[ \t]+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) ) );
	return (string) preg_replace( '/\p{Cf}+/u', '', $text );
}

/**
 * Register the abilities.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return; // WordPress before 6.9: no abilities.
	}
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
						'title'       => __( 'Channel', 'flurfunk' ),
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
		'flurfunk/find-posts',
		array(
			'label'               => __( 'Find posts', 'flurfunk' ),
			'description'         => __( 'Posts of this site the current user may see (drafts and scheduled ones only if they may edit them), newest first: the latest ones, or those matching search words. Returns ID, title, status, dates, author, the address of published ones and the first words; read one with flurfunk/get-post.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Words in the title or text; leave it out for the latest posts.', 'flurfunk' ),
					),
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'any', 'publish', 'draft', 'pending', 'future' ),
						'default' => 'any',
					),
					'order'  => array(
						'type'        => 'string',
						'enum'        => array( 'date', 'modified' ),
						'default'     => 'date',
						'description' => __( 'date: newest by publication date; modified: last edited first.', 'flurfunk' ),
					),
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 20,
						'default' => 10,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'execute_callback'    => static function ( $input = array() ): array {
				$input  = (array) $input;
				$uid    = get_current_user_id();
				$status = (string) ( $input['status'] ?? 'any' );
				$limit  = max( 1, min( 20, (int) ( $input['limit'] ?? 10 ) ) );
				$args   = array(
					'post_type'           => \Flurfunk\Settings\get()['post_types'],
					'post_status'         => in_array( $status, array( 'publish', 'draft', 'pending', 'future' ), true ) ? array( $status ) : array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'posts_per_page'      => $limit * 3, // Some may not be visible to this person.
					'orderby'             => 'modified' === ( $input['order'] ?? '' ) ? 'modified' : 'date',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
					's'                   => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				);
				if ( 'team' === Access\kind( $uid ) ) {
					$posts = get_posts( $args );
				} else {
					// Guests: what anyone may read on the site, and their own posts.
					$own   = get_posts( array( 'post__in' => Access\owned_post_ids( $uid ) ?: array( 0 ) ) + $args );
					$pub   = array_intersect( $args['post_status'], array( 'publish' ) ) ? get_posts( array( 'post_status' => array( 'publish' ) ) + $args ) : array();
					$posts = array();
					foreach ( array_merge( $own, $pub ) as $p ) {
						$posts[ $p->ID ] = $p;
					}
					$key = 'modified' === $args['orderby'] ? 'post_modified' : 'post_date';
					uasort( $posts, static fn ( $a, $b ) => strcmp( $b->$key, $a->$key ) );
				}
				$out = array();
				foreach ( $posts as $p ) {
					if ( count( $out ) < $limit && \Flurfunk\Providers\visible_post( $p, $uid ) ) {
						$out[] = array(
							'id'       => $p->ID,
							'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
							'status'   => $p->post_status,
							'date'     => (string) $p->post_date,
							'modified' => (string) $p->post_modified,
							'author'   => (string) get_the_author_meta( 'display_name', (int) $p->post_author ),
							'url'      => 'publish' === $p->post_status ? (string) get_permalink( $p ) : '',
							'words'    => str_word_count( plain_text( $p ), 0, 'äöüÄÖÜß' ),
							'start'    => mb_substr( plain_text( $p ), 0, 160 ),
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
		'flurfunk/get-post',
		array(
			'label'               => __( 'Read a post', 'flurfunk' ),
			'description'         => __( 'The text of one post (as plain text, without markup), if the current user may see it – drafts only for those who may edit them.', 'flurfunk' ),
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
				$uid = get_current_user_id();
				$p   = get_post( (int) ( (array) $input )['id'] );
				$ok  = $p && in_array( $p->post_type, \Flurfunk\Settings\get()['post_types'], true ) && \Flurfunk\Providers\visible_post( $p, $uid ) && user_can( $uid, 'read_post', $p->ID )
					&& ( 'team' === Access\kind( $uid ) || 'publish' === $p->post_status || in_array( $p->ID, Access\owned_post_ids( $uid ), true ) );
				if ( ! $ok ) {
					return new \WP_Error( 'flurfunk_forbidden', __( 'This post does not exist or you may not see it.', 'flurfunk' ), array( 'status' => 404 ) );
				}
				$text = plain_text( $p );
				$max  = 20000;
				return array(
					'id'       => $p->ID,
					'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
					'status'   => $p->post_status,
					'date'     => (string) $p->post_date,
					'modified' => (string) $p->post_modified,
					'author'   => (string) get_the_author_meta( 'display_name', (int) $p->post_author ),
					'excerpt'  => html_entity_decode( wp_strip_all_tags( (string) $p->post_excerpt ), ENT_QUOTES, 'UTF-8' ),
					'url'      => 'publish' === $p->post_status ? (string) get_permalink( $p ) : '',
					'text'     => mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . ' […]' : $text,
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
						'title'     => __( 'Text', 'flurfunk' ),
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
			'permission_callback' => __NAMESPACE__ . '\\may_reply',
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
					'channel' => array(
						'type'  => 'string',
						'title' => __( 'Channel', 'flurfunk' ),
					),
					'title'   => array(
						'type'  => 'string',
						'title' => __( 'Title', 'flurfunk' ),
					),
					'text'    => array(
						'type'  => 'string',
						'title' => __( 'Text', 'flurfunk' ),
					),
					'task'    => array(
						'type'    => 'boolean',
						'default' => false,
						'title'   => __( 'As a task', 'flurfunk' ),
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
			'permission_callback' => __NAMESPACE__ . '\\may_start',
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

<?php
/**
 * Reading and writing. To the outside (interface, export) there is only the format "flurfunk/1":
 *
 *   thread  { id, channel, ref_key, target: {type,id}|null, title|null, body, author, created,
 *             status: 'open'|'doing'|'waiting'|'done'|null, resolved: {by,at}|null, mentions[], attachments[] }
 *   message { id, type: 'message'|'status'|'event'|'deleted', author, created, text, mentions[],
 *             files[], attachments[], status?, edited } – events carry event: due|assign|card_action
 *             and its data
 *   Text: plain text, mentions as <@ID>. Times in UTC (ISO 8601).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Store;

use Flurfunk\Access;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

// Flurfunk's own tables have no WordPress API to go through. Every query below is prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Task states: open → doing (in progress) → waiting (needs an answer) → done.
const STATUSES = array( 'open', 'doing', 'waiting', 'done' );
const ACTIVE   = array( 'open', 'doing', 'waiting' );

const MAX_TEXT = 20000;

/**
 * Now (UTC, for the database).
 */
function now(): string {
	return gmdate( 'Y-m-d H:i:s' );
}

/**
 * Database time (UTC) as ISO 8601.
 *
 * @param string|null $mysql Time.
 */
function iso( ?string $mysql ): ?string {
	return $mysql ? gmdate( 'c', (int) strtotime( $mysql . ' UTC' ) ) : null;
}

/**
 * ISO 8601 (import) as database time (UTC).
 *
 * @param string|null $iso Time.
 */
function mysql( ?string $iso ): ?string {
	$t = $iso ? strtotime( $iso ) : false;
	return false !== $t ? gmdate( 'Y-m-d H:i:s', $t ) : null;
}

/**
 * Clean text: plain text only (always escaped on output), no control characters.
 *
 * @param mixed $text Input.
 */
function clean_text( $text ): string {
	$text = wp_check_invalid_utf8( (string) $text );
	$text = (string) preg_replace( '/[^\P{C}\n\t]/u', '', str_replace( "\r\n", "\n", $text ) );
	return mb_substr( trim( $text ), 0, MAX_TEXT );
}

/**
 * People mentioned (<@ID>) in a text, limited to those who may see the thread.
 *
 * @param string $text   Text.
 * @param object $thread Thread row.
 * @return int[]
 */
function mentions_in( string $text, object $thread ): array {
	preg_match_all( '/<@(\d+)>/', $text, $m );
	$ids = array_unique( array_map( 'intval', $m[1] ) );
	return array_values( array_filter( $ids, static fn ( $id ) => Access\can_use( $id ) && Access\can_see( $thread, $id ) ) );
}

/* -------------------------------------------------------------------------
 * Channels
 * ---------------------------------------------------------------------- */

/**
 * All channels (cached per request).
 *
 * @param bool $fresh Reload.
 * @return object[]
 */
function channels( bool $fresh = false ): array {
	global $wpdb;
	static $rows = null;
	if ( null === $rows || $fresh ) {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY position, name', table( 'channels' ) ) );
	}
	return $rows;
}

/**
 * Channel by ID.
 *
 * @param int $id ID.
 */
function channel( int $id ): ?object {
	foreach ( channels() as $c ) {
		if ( (int) $c->id === $id ) {
			return $c;
		}
	}
	return null;
}

/**
 * Channel by short name.
 *
 * @param string $slug Short name.
 */
function channel_by_slug( string $slug ): ?object {
	foreach ( channels() as $c ) {
		if ( $c->slug === $slug ) {
			return $c;
		}
	}
	return null;
}

/**
 * Create a channel or change its details.
 *
 * @param string $slug Short name.
 * @param array  $args name, topic, access_team, access_guests, position, archived.
 * @param bool   $update Overwrite an existing channel with the details.
 * @return int ID.
 */
function ensure_channel( string $slug, array $args = array(), bool $update = false ): int {
	global $wpdb;
	$slug = sanitize_key( $slug );
	$row  = channel_by_slug( $slug );
	$data = array();
	foreach ( array( 'name', 'topic' ) as $k ) {
		if ( isset( $args[ $k ] ) ) {
			$data[ $k ] = sanitize_text_field( (string) $args[ $k ] );
		}
	}
	foreach ( array( 'access_team', 'access_guests' ) as $k ) {
		if ( isset( $args[ $k ] ) && in_array( $args[ $k ], Access\LEVELS, true ) ) {
			$data[ $k ] = $args[ $k ];
		}
	}
	foreach ( array( 'position', 'archived' ) as $k ) {
		if ( isset( $args[ $k ] ) ) {
			$data[ $k ] = (int) $args[ $k ];
		}
	}
	if ( $row ) {
		if ( $update && $data ) {
			$wpdb->update( table( 'channels' ), $data, array( 'id' => $row->id ) );
			channels( true );
			bump();
		}
		return (int) $row->id;
	}
	$wpdb->insert(
		table( 'channels' ),
		$data + array(
			'slug'    => $slug,
			'name'    => $data['name'] ?? $slug,
			'topic'   => $data['topic'] ?? '',
			'created' => now(),
		)
	);
	$id = (int) $wpdb->insert_id;
	channels( true );
	bump();
	return $id;
}

/**
 * Channel in the data format.
 *
 * @param object $c Channel row.
 */
function format_channel( object $c ): array {
	return array(
		'id'     => $c->slug,
		'name'   => $c->name,
		'topic'  => $c->topic,
		'access' => array(
			'team'   => $c->access_team,
			'guests' => $c->access_guests,
		),
	);
}

/* -------------------------------------------------------------------------
 * People
 * ---------------------------------------------------------------------- */

/**
 * Person for the interface (name, role, kind).
 *
 * @param int $id User.
 */
function person( int $id ): array {
	static $cache = array();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$u = get_userdata( $id );
	if ( ! $u ) {
		return $cache[ $id ] = array(
			'id'   => $id,
			'name' => __( 'Deleted user', 'flurfunk' ),
			'role' => '',
			'kind' => '',
		);
	}
	$roles = wp_roles()->role_names;
	$role  = $u->roles ? translate_user_role( $roles[ $u->roles[0] ] ?? $u->roles[0] ) : '';
	return $cache[ $id ] = array(
		'id'     => $id,
		'name'   => html_entity_decode( $u->display_name, ENT_QUOTES, 'UTF-8' ),
		'role'   => (string) apply_filters( 'flurfunk_user_role_label', $role, $u ),
		'kind'   => Access\kind( $id ),
		'bot'    => Access\is_bot( $id ),
		'avatar' => avatar( $id ),
	);
}

/**
 * Profile picture URL, or '' for initials. Uses WordPress's avatar (uploaded picture via
 * plugins, otherwise Gravatar) unless the site has avatars switched off or the person chose
 * initials. "blank" as fallback: without a picture the initials underneath stay visible.
 *
 * @param int $id User.
 */
function avatar( int $id ): string {
	if ( ! get_option( 'show_avatars' ) || 'initials' === \Flurfunk\Notify\prefs( $id )['avatar'] ) {
		return '';
	}
	return (string) get_avatar_url(
		$id,
		array(
			'size'    => 96,
			'default' => 'blank',
		)
	);
}

/**
 * Everyone with access (for mentions).
 *
 * @return array[]
 */
function people(): array {
	$users = array_unique(
		array_merge(
			get_users(
				(array) apply_filters(
					'flurfunk_people_query',
					array(
						// Everyone who can be team or guest by the settings; kind() decides below.
						'capability__in' => array_values( array_filter( array_unique( array( 'edit_posts', \Flurfunk\Settings\get()['team_cap'], \Flurfunk\Settings\get()['guest_cap'] ) ) ) ),
						'fields'         => 'ID',
						'orderby'        => 'display_name',
					)
				)
			),
			get_users(
				array(
					'meta_key' => 'flurfunk_bot', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only a handful of assistant accounts carry this key.
					'fields'   => 'ID',
				)
			)
		)
	);
	return array_values( array_filter( array_map( static fn ( $id ) => person( (int) $id ), $users ), static fn ( $p ) => '' !== $p['kind'] ) );
}

/* -------------------------------------------------------------------------
 * Threads
 * ---------------------------------------------------------------------- */

/**
 * Thread row.
 *
 * @param int $id ID.
 */
function thread_row( int $id ): ?object {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table( 'threads' ), $id ) );
	return $row ?: null;
}

/**
 * Thread by external key (e.g. "checklist:billing"), set by plugins that embed threads.
 *
 * @param string $ref_key Key.
 */
function thread_by_ref( string $ref_key ): ?object {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref_key = %s ORDER BY id LIMIT 1', table( 'threads' ), $ref_key ) );
	return $row ?: null;
}

/**
 * Brake for runaway scripts: at most N new threads and messages per person and minute
 * (filter flurfunk_rate_limit, default 30; 0 = off).
 *
 * @param int $user_id User.
 * @return true|\WP_Error
 */
function rate_limit( int $user_id ) {
	// Site managers are exempt (imports, tests); runaway scripts run as assistants or editors.
	$max = (int) apply_filters( 'flurfunk_rate_limit', Access\can_manage( $user_id ) ? 0 : 30, $user_id );
	if ( $max <= 0 ) {
		return true;
	}
	$key  = 'flurfunk_rate_' . $user_id . '_' . gmdate( 'YmdHi' );
	$used = (int) get_transient( $key );
	if ( $used >= $max ) {
		return new \WP_Error( 'flurfunk_rate', __( 'That was a lot at once. Please wait a minute.', 'flurfunk' ), array( 'status' => 429 ) );
	}
	set_transient( $key, $used + 1, 2 * MINUTE_IN_SECONDS );
	return true;
}

/**
 * Create a thread.
 *
 * @param array $args channel (short name), target {type,id}, title, body, task, attachments[], ref_key,
 *                    author (import/CLI only), created (import only).
 * @param bool  $check Check permissions (off for imports).
 * @return int|\WP_Error ID.
 */
function create_thread( array $args, bool $check = true ) {
	global $wpdb;
	$author  = (int) ( $args['author'] ?? get_current_user_id() );
	$channel = channel_by_slug( (string) ( $args['channel'] ?? '' ) );
	if ( ! $channel ) {
		return new \WP_Error( 'flurfunk_channel', __( 'This channel does not exist.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$type = (string) ( $args['target']['type'] ?? '' );
	$tid  = (int) ( $args['target']['id'] ?? 0 );
	if ( '' === $type || ! $tid ) {
		$type = '';
		$tid  = 0;
	}
	if ( $check && ! Access\can_start( $channel, $type, $tid, $author ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot start a conversation here.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$rate = $check ? rate_limit( $author ) : true;
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}
	$body  = clean_text( $args['body'] ?? '' );
	$title = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
	$files = files( (array) ( $args['files'] ?? array() ), $author, $check );
	if ( '' === $body && '' === $title && empty( $args['attachments'] ) && ! $files ) {
		return new \WP_Error( 'flurfunk_empty', __( 'The conversation is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	// Two people ticking the same embedded checklist item at once must not create two threads.
	$ref = sanitize_text_field( (string) ( $args['ref_key'] ?? '' ) );
	if ( '' !== $ref ) {
		$lock = 'flurfunk_ref_' . md5( $ref );
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		$existing = thread_by_ref( $ref );
		if ( $existing ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return (int) $existing->id;
		}
	}
	$created = mysql( $args['created'] ?? null ) ?? now();
	$status  = $args['status'] ?? ( empty( $args['task'] ) ? '' : 'open' );
	$wpdb->insert(
		table( 'threads' ),
		array(
			'channel_id'    => (int) $channel->id,
			'ref_key'       => $ref,
			'target_type'   => $type,
			'target_id'     => $tid,
			'title'         => $title,
			'body'          => $body,
			'files'         => (string) wp_json_encode( $files ),
			'author_id'     => $author,
			'status'        => in_array( $status, STATUSES, true ) ? $status : '',
			'resolved_by'   => (int) ( $args['resolved']['by'] ?? 0 ),
			'resolved_at'   => mysql( $args['resolved']['at'] ?? null ),
			'created'       => $created,
			'last_activity' => $created,
		)
	);
	$id = (int) $wpdb->insert_id;
	if ( '' !== $ref ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'flurfunk_ref_' . md5( $ref ) ) );
	}
	if ( ! $id ) {
		return new \WP_Error( 'flurfunk_db', __( 'Could not save the conversation.', 'flurfunk' ), array( 'status' => 500 ) );
	}
	bump();
	$row = thread_row( $id );
	save_mentions( $row, 0, $check ? mentions_in( $body, $row ) : array_map( 'intval', (array) ( $args['mentions'] ?? array() ) ) );
	save_attachments( $row, 0, (array) ( $args['attachments'] ?? array() ), $author, $check );
	if ( ! empty( $args['due'] ) || ! empty( $args['assignees'] ) ) {
		// Silent on creation: the thread itself announces the task, no separate events.
		$task = array();
		if ( ! empty( $args['due'] ) ) {
			$task['due'] = $args['due'];
		}
		if ( ! empty( $args['assignees'] ) ) {
			$task['assignees'] = $args['assignees'];
		}
		set_task( $id, $task, $check );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE thread_id = %d AND type = 'event'", table( 'messages' ), $id ) );
	}
	if ( $check ) {
		mark_read( $id, $author );
		do_action( 'flurfunk_thread_created', thread_row( $id ) );
	}
	return $id;
}

/**
 * Add a message.
 *
 * @param int   $thread_id Thread.
 * @param array $args body, files (media library IDs), attachments[], author/created/type/status (import).
 * @param bool  $check Check permissions (off for imports).
 * @return int|\WP_Error ID.
 */
function add_message( int $thread_id, array $args, bool $check = true ) {
	global $wpdb;
	$thread = thread_row( $thread_id );
	$author = (int) ( $args['author'] ?? get_current_user_id() );
	if ( ! $thread || ( $check && ! Access\can_see( $thread, $author ) ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	// Task history ("event") only comes in through the import; it is written by set_task otherwise.
	$type  = in_array( $args['type'] ?? '', $check ? array( 'status', 'deleted' ) : array( 'status', 'deleted', 'event' ), true ) ? $args['type'] : 'message';
	$body  = 'message' === $type ? clean_text( $args['body'] ?? '' ) : ( 'event' === $type ? (string) wp_json_encode( array_intersect_key( (array) ( $args['event'] ?? array() ), array_flip( array( 'event', 'due', 'added', 'removed', 'card', 'provider', 'action', 'label', 'summary', 'title' ) ) ) ) : '' );
	$rate  = $check && 'message' === $type ? rate_limit( $author ) : true;
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}
	$files = files( (array) ( $args['files'] ?? array() ), $author, $check );
	if ( 'message' === $type && '' === $body && ! $files && empty( $args['attachments'] ) ) {
		return new \WP_Error( 'flurfunk_empty', __( 'The message is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$created = mysql( $args['created'] ?? null ) ?? now();
	$wpdb->insert(
		table( 'messages' ),
		array(
			'thread_id' => $thread_id,
			'type'      => $type,
			'author_id' => $author,
			'body'      => $body,
			'status'    => 'status' === $type ? (string) ( $args['status'] ?? '' ) : '',
			'files'     => (string) wp_json_encode( $files ),
			'created'   => $created,
			'edited'    => mysql( $args['edited'] ?? null ),
		)
	);
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		return new \WP_Error( 'flurfunk_db', __( 'Could not save the message.', 'flurfunk' ), array( 'status' => 500 ) );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_activity = GREATEST(last_activity, %s) WHERE id = %d', table( 'threads' ), $created, $thread_id ) );
	bump();
	save_mentions( $thread, $id, $check ? mentions_in( $body, $thread ) : array_map( 'intval', (array) ( $args['mentions'] ?? array() ) ) );
	save_attachments( $thread, $id, (array) ( $args['attachments'] ?? array() ), $author, $check );
	if ( $check ) {
		mark_read( $thread_id, $author, $id );
		do_action( 'flurfunk_message_added', $thread, $id );
	}
	return $id;
}

/**
 * Set the status (open/done) and log it as an event in the thread.
 *
 * @param int    $thread_id Thread.
 * @param string $status    open|done ('' = no longer a task).
 * @return true|\WP_Error
 */
function set_status( int $thread_id, string $status ) {
	global $wpdb;
	$thread = thread_row( $thread_id );
	$user   = get_current_user_id();
	if ( ! $thread || ! Access\can_see( $thread, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( ! in_array( $status, array_merge( STATUSES, array( '' ) ), true ) || ! apply_filters( 'flurfunk_can_resolve', true, $thread, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( $thread->status === $status ) {
		return true;
	}
	$wpdb->update(
		table( 'threads' ),
		array(
			'status'      => $status,
			'resolved_by' => 'done' === $status ? $user : 0,
			'resolved_at' => 'done' === $status ? now() : null,
		),
		array( 'id' => $thread_id )
	);
	$id = add_message(
		$thread_id,
		array(
			'type'   => 'status',
			'status' => '' === $status ? 'none' : $status,
		)
	);
	if ( ! is_wp_error( $id ) ) {
		do_action( 'flurfunk_status_changed', thread_row( $thread_id ), $status, $user );
	}
	return is_wp_error( $id ) ? $id : true;
}

/**
 * May the person change or remove this message / thread? Authors may, and site managers.
 *
 * @param int $author  Author of the item.
 * @param int $user_id User.
 */
function can_edit( int $author, int $user_id ): bool {
	return (bool) apply_filters( 'flurfunk_can_edit', $author === $user_id || Access\can_manage( $user_id ), $author, $user_id );
}

/**
 * Change the text of a message (mentions follow the new text).
 *
 * @param int    $message_id Message.
 * @param string $body       New text.
 * @return int|\WP_Error Thread ID.
 */
function edit_message( int $message_id, string $body ) {
	global $wpdb;
	$user = get_current_user_id();
	$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table( 'messages' ), $message_id ) );
	$t    = $m ? thread_row( (int) $m->thread_id ) : null;
	if ( ! $m || ! $t || 'message' !== $m->type || ! Access\can_see( $t, $user ) || ! can_edit( (int) $m->author_id, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$body = clean_text( $body );
	if ( '' === $body && '[]' === $m->files && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE message_id = %d', table( 'attachments' ), $message_id ) ) ) {
		return new \WP_Error( 'flurfunk_empty', __( 'The message is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$wpdb->update( table( 'messages' ), array( 'body' => $body, 'edited' => now() ), array( 'id' => $message_id ) );
	bump();
	$wpdb->delete( table( 'mentions' ), array( 'thread_id' => (int) $t->id, 'message_id' => $message_id ) );
	save_mentions( $t, $message_id, mentions_in( $body, $t ) );
	do_action( 'flurfunk_message_edited', $t, $message_id );
	return (int) $t->id;
}

/**
 * Remove a message. A short note stays in its place so replies keep their context.
 *
 * @param int $message_id Message.
 * @return int|\WP_Error Thread ID.
 */
function delete_message( int $message_id ) {
	global $wpdb;
	$user = get_current_user_id();
	$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table( 'messages' ), $message_id ) );
	$t    = $m ? thread_row( (int) $m->thread_id ) : null;
	if ( ! $m || ! $t || 'message' !== $m->type || ! Access\can_see( $t, $user ) || ! can_edit( (int) $m->author_id, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$wpdb->update( table( 'messages' ), array( 'type' => 'deleted', 'body' => '', 'files' => '[]', 'edited' => now() ), array( 'id' => $message_id ) );
	bump();
	$wpdb->delete( table( 'mentions' ), array( 'thread_id' => (int) $t->id, 'message_id' => $message_id ) );
	$wpdb->delete( table( 'attachments' ), array( 'thread_id' => (int) $t->id, 'message_id' => $message_id ) );
	do_action( 'flurfunk_message_deleted', $t, $message_id );
	return (int) $t->id;
}

/**
 * Change title and text of a thread.
 *
 * @param int   $thread_id Thread.
 * @param array $args      title, body.
 * @return true|\WP_Error
 */
function edit_thread( int $thread_id, array $args ) {
	global $wpdb;
	$user = get_current_user_id();
	$t    = thread_row( $thread_id );
	if ( ! $t || ! Access\can_see( $t, $user ) || ! can_edit( (int) $t->author_id, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$title = array_key_exists( 'title', $args ) ? sanitize_text_field( (string) $args['title'] ) : $t->title;
	$body  = array_key_exists( 'body', $args ) ? clean_text( $args['body'] ) : $t->body;
	if ( '' === $title && '' === $body ) {
		return new \WP_Error( 'flurfunk_empty', __( 'The conversation is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$wpdb->update( table( 'threads' ), array( 'title' => $title, 'body' => $body ), array( 'id' => $thread_id ) );
	bump();
	$wpdb->delete( table( 'mentions' ), array( 'thread_id' => $thread_id, 'message_id' => 0 ) );
	save_mentions( $t, 0, mentions_in( $body, $t ) );
	return true;
}

/**
 * Follow (1), mute (-1) or reset (0) a thread for a person. Followers hear about every reply;
 * muted threads stay quiet except for mentions and tasks.
 *
 * @param int $thread_id Thread.
 * @param int $user_id   User.
 * @param int $mode      1, -1 or 0.
 */
function set_follow( int $thread_id, int $user_id, int $mode ): void {
	global $wpdb;
	$mode = max( -1, min( 1, $mode ) );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id, thread_id, last_message_id, seen, follow) VALUES (%d, %d, 0, %s, %d) ON DUPLICATE KEY UPDATE follow = VALUES(follow)', table( 'reads' ), $user_id, $thread_id, now(), $mode ) );
}

/**
 * When should a person be reminded of a thread?
 *
 * @param string $when    hour | tomorrow | monday | none | ISO 8601 time.
 * @param int    $user_id User (their hour for "tomorrow" and "monday").
 * @return string|null|false Database time, null = no reminder, false = invalid.
 */
function remind_time( string $when, int $user_id ) {
	if ( 'none' === $when || '' === $when ) {
		return null;
	}
	$tz   = wp_timezone();
	$hour = \Flurfunk\Notify\prefs( $user_id )['hour'];
	$at   = new \DateTimeImmutable( 'now', $tz );
	if ( 'hour' === $when ) {
		$at = $at->modify( '+1 hour' );
	} elseif ( 'tomorrow' === $when ) {
		$at = $at->modify( 'tomorrow' )->setTime( $hour, 0 );
	} elseif ( 'monday' === $when ) {
		$at = $at->modify( 'next monday' )->setTime( $hour, 0 );
	} else {
		$t = strtotime( $when );
		if ( false === $t || $t < time() - 60 || $t > time() + 366 * DAY_IN_SECONDS ) {
			return false;
		}
		return gmdate( 'Y-m-d H:i:s', $t );
	}
	return gmdate( 'Y-m-d H:i:s', $at->getTimestamp() );
}

/**
 * Set or clear a person's reminder for a thread.
 *
 * @param int         $thread_id Thread.
 * @param int         $user_id   User.
 * @param string|null $at        Database time or null.
 */
function set_reminder( int $thread_id, int $user_id, ?string $at ): void {
	global $wpdb;
	if ( null === $at ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET remind_at = NULL WHERE user_id = %d AND thread_id = %d', table( 'reads' ), $user_id, $thread_id ) );
		return;
	}
	$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id, thread_id, last_message_id, seen, remind_at) VALUES (%d, %d, 0, %s, %s) ON DUPLICATE KEY UPDATE remind_at = VALUES(remind_at)', table( 'reads' ), $user_id, $thread_id, now(), $at ) );
}

/**
 * A person's reminder for a thread (database time) or null.
 *
 * @param int $thread_id Thread.
 * @param int $user_id   User.
 */
function reminder( int $thread_id, int $user_id ): ?string {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT remind_at FROM %i WHERE user_id = %d AND thread_id = %d', table( 'reads' ), $user_id, $thread_id ) ) ?: null;
}

/**
 * Follow modes of a thread: user => 1 (follow) or -1 (mute).
 *
 * @param int $thread_id Thread.
 * @return array<int,int>
 */
function follows( int $thread_id ): array {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, follow FROM %i WHERE thread_id = %d AND follow <> 0', table( 'reads' ), $thread_id ) ) as $r ) {
		$out[ (int) $r->user_id ] = (int) $r->follow;
	}
	return $out;
}

/**
 * Mark everything a person can see as read, optionally only in one channel.
 *
 * @param int    $user_id User.
 * @param string $channel Channel short name ('' = all).
 * @return int Threads marked.
 */
function mark_all_read( int $user_id, string $channel = '' ): int {
	global $wpdb;
	$where = Access\where( $user_id );
	if ( '' !== $channel ) {
		$c      = channel_by_slug( $channel );
		$where .= $wpdb->prepare( ' AND t.channel_id = %d', $c ? (int) $c->id : 0 );
	}
	// One statement for all threads (thousands of threads stay fast).
	$n = (int) $wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is made of $wpdb->prepare() pieces (Access\where()).
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is made of $wpdb->prepare() pieces (Access\where()).
			"INSERT INTO %i (user_id, thread_id, last_message_id, seen) SELECT %d, t.id, COALESCE( MAX(m.id), 0 ), %s FROM %i t LEFT JOIN %i m ON m.thread_id = t.id WHERE {$where} GROUP BY t.id
			ON DUPLICATE KEY UPDATE last_message_id = GREATEST(%i.last_message_id, VALUES(last_message_id)), seen = VALUES(seen)",
			table( 'reads' ),
			$user_id,
			now(),
			table( 'threads' ),
			table( 'messages' ),
			table( 'reads' )
		)
	);
	update_user_meta( $user_id, 'flurfunk_rev', (int) get_user_meta( $user_id, 'flurfunk_rev', true ) + 1 );
	return $n;
}

/**
 * Revision counter: changes whenever something changes, so cached numbers expire at once.
 * Reading only affects the reader, so it bumps that person's own counter.
 *
 * @param int $user_id User.
 */
function revision( int $user_id ): string {
	return (int) get_option( 'flurfunk_rev', 0 ) . '-' . (int) get_user_meta( $user_id, 'flurfunk_rev', true );
}

/**
 * Something changed for everyone.
 */
function bump(): void {
	update_option( 'flurfunk_rev', (int) get_option( 'flurfunk_rev', 0 ) + 1, true );
}

/**
 * Mark as read up to …
 *
 * @param int      $thread_id  Thread.
 * @param int      $user_id    User.
 * @param int|null $message_id Message (null = latest).
 */
function mark_read( int $thread_id, int $user_id, ?int $message_id = null ): void {
	global $wpdb;
	if ( null === $message_id ) {
		$message_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE thread_id = %d', table( 'messages' ), $thread_id ) );
	}
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO %i (user_id, thread_id, last_message_id, seen) VALUES (%d, %d, %d, %s)
			ON DUPLICATE KEY UPDATE last_message_id = GREATEST(last_message_id, VALUES(last_message_id)), seen = VALUES(seen)',
			table( 'reads' ),
			$user_id,
			$thread_id,
			$message_id,
			now()
		)
	);
	update_user_meta( $user_id, 'flurfunk_rev', (int) get_user_meta( $user_id, 'flurfunk_rev', true ) + 1 );
}

/**
 * Store mentions.
 *
 * @param object $thread     Thread.
 * @param int    $message_id Message (0 = the thread itself).
 * @param int[]  $users      People.
 */
function save_mentions( object $thread, int $message_id, array $users ): void {
	global $wpdb;
	foreach ( array_unique( $users ) as $u ) {
		$wpdb->replace(
			table( 'mentions' ),
			array(
				'thread_id'  => (int) $thread->id,
				'message_id' => $message_id,
				'user_id'    => (int) $u,
			)
		);
	}
}

/**
 * Store cards: the provider checks permissions and supplies the snapshot.
 *
 * @param object $thread     Thread.
 * @param int    $message_id Message (0 = the thread itself).
 * @param array  $list       [{provider, ref, snapshot?}].
 * @param int    $user_id    Who attaches.
 * @param bool   $check      Check permissions and fetch a fresh snapshot (off for imports).
 */
function save_attachments( object $thread, int $message_id, array $list, int $user_id, bool $check ): void {
	global $wpdb;
	foreach ( array_slice( $list, 0, 10 ) as $a ) {
		$provider = (string) ( $a['provider'] ?? '' );
		$ref      = is_array( $a['ref'] ?? null ) ? $a['ref'] : array();
		if ( $check ) {
			$snapshot = \Flurfunk\Providers\snapshot( $provider, $ref, $user_id );
			if ( null === $snapshot ) {
				continue;
			}
		} else {
			$snapshot = is_array( $a['snapshot'] ?? null ) ? $a['snapshot'] : array();
		}
		$wpdb->insert(
			table( 'attachments' ),
			array(
				'thread_id'  => (int) $thread->id,
				'message_id' => $message_id,
				'provider'   => sanitize_text_field( $provider ),
				'ref_key'    => \Flurfunk\Providers\ref_key( $ref ),
				'ref'        => (string) wp_json_encode( $ref ),
				'snapshot'   => (string) wp_json_encode( $snapshot ),
				'created'    => now(),
			)
		);
	}
}

/**
 * Fetch a fresh snapshot for a card (same provider, same reference). The provider checks again
 * whether the person may see it.
 *
 * @param int $attachment_id Card row.
 * @return int|\WP_Error Thread ID.
 */
function refresh_card( int $attachment_id ) {
	global $wpdb;
	$user = get_current_user_id();
	$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table( 'attachments' ), $attachment_id ) );
	$t    = $row ? thread_row( (int) $row->thread_id ) : null;
	if ( ! $row || ! $t || ! Access\can_see( $t, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$snap = \Flurfunk\Providers\snapshot( $row->provider, (array) json_decode( $row->ref, true ), $user );
	if ( null === $snap ) {
		return new \WP_Error( 'flurfunk_card', __( 'This card cannot be updated (any more).', 'flurfunk' ), array( 'status' => 409 ) );
	}
	$wpdb->update( table( 'attachments' ), array( 'snapshot' => (string) wp_json_encode( $snap ) ), array( 'id' => $attachment_id ) );
	bump();
	return (int) $t->id;
}

/**
 * Run a card action (a button on a card, see inc/providers.php): the provider's callback
 * returns the new snapshot, which replaces the stored one, and an event line in the thread
 * records who did what. The same idempotency key from the same person within ten minutes runs
 * the action only once; an action the card no longer offers is refused (409).
 *
 * @param int    $attachment_id Card row.
 * @param string $action        Action ID.
 * @param string $key           Idempotency key from the client ('' = none).
 * @return int|\WP_Error Thread ID.
 */
function card_action( int $attachment_id, string $action, string $key = '' ) {
	global $wpdb;
	$user   = get_current_user_id();
	$action = sanitize_key( $action );
	$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table( 'attachments' ), $attachment_id ) );
	$t      = $row ? thread_row( (int) $row->thread_id ) : null;
	if ( ! $row || ! $t || ! Access\can_see( $t, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$info = \Flurfunk\Providers\action_info( (string) $row->provider, $action );
	if ( ! $info ) {
		return new \WP_Error( 'flurfunk_action', __( 'Unknown action.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$once = '' !== $key ? 'flurfunk_act_' . md5( $user . ':' . $attachment_id . ':' . $action . ':' . substr( $key, 0, 64 ) ) : '';
	if ( $once && get_transient( $once ) ) {
		return (int) $t->id; // A retry of a request that went through: same answer, nothing runs twice.
	}
	$rate = rate_limit( $user );
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}
	// One action per card at a time: two people clicking at once run one after the other, and
	// the second sees the state the first left behind.
	$lock = 'flurfunk_card_' . $attachment_id;
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
	try {
		$snapshot = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot FROM %i WHERE id = %d', table( 'attachments' ), $attachment_id ) );
		$card     = json_decode( $snapshot, true ) ?: array();
		$snap     = \Flurfunk\Providers\run_action( (string) $row->provider, $action, (array) json_decode( $row->ref, true ), $card, $user );
		if ( is_wp_error( $snap ) ) {
			return $snap;
		}
		$wpdb->update( table( 'attachments' ), array( 'snapshot' => (string) wp_json_encode( $snap ) ), array( 'id' => $attachment_id ) );
		$who     = person( $user )['name'];
		$summary = '' !== $info['done'] && str_contains( $info['done'], '%s' )
			? str_replace( '%s', $who, $info['done'] )
			/* translators: 1: person, 2: button label */
			: sprintf( __( '%1$s chose "%2$s"', 'flurfunk' ), $who, $info['label'] );
		$wpdb->insert(
			table( 'messages' ),
			array(
				'thread_id' => (int) $t->id,
				'type'      => 'event',
				'author_id' => $user,
				'body'      => (string) wp_json_encode(
					array(
						'event'    => 'card_action',
						'card'     => $attachment_id,
						'provider' => (string) $row->provider,
						'action'   => $action,
						'label'    => $info['label'],
						'summary'  => $summary,
						'title'    => (string) ( $snap['title'] ?? $card['title'] ?? '' ),
					)
				),
				'status'    => '',
				'files'     => '[]',
				'created'   => now(),
			)
		);
		$mid = (int) $wpdb->insert_id;
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_activity = %s WHERE id = %d', table( 'threads' ), now(), (int) $t->id ) );
	bump();
	if ( $once ) {
		set_transient( $once, (int) $t->id, 10 * MINUTE_IN_SECONDS );
	}
	if ( $mid ) {
		mark_read( (int) $t->id, $user, $mid );
		do_action( 'flurfunk_card_action', thread_row( (int) $t->id ), $mid, $action, $user, $snap );
	}
	return (int) $t->id;
}

/**
 * May this person refer to a media library file in a message? Their own files and files they
 * can edit; assistants (which usually have no role) any file in the library.
 *
 * @param \WP_Post $post    Attachment.
 * @param int      $user_id User.
 */
function can_attach( \WP_Post $post, int $user_id ): bool {
	$ok = (int) $post->post_author === $user_id || user_can( $user_id, 'edit_post', $post->ID ) || ( \Flurfunk\Access\is_bot( $user_id ) && 'private' !== get_post_status( $post ) );
	return (bool) apply_filters( 'flurfunk_can_attach', $ok, $post, $user_id );
}

/**
 * Validate and describe files from the media library.
 *
 * @param array $ids     Media library IDs (or ready descriptions on import).
 * @param int   $user_id Who attaches.
 * @param bool  $check   Check permissions.
 * @return array[]
 */
function files( array $ids, int $user_id, bool $check ): array {
	$out = array();
	foreach ( array_slice( $ids, 0, (int) apply_filters( 'flurfunk_max_files', 50 ) ) as $f ) {
		if ( ! $check && is_array( $f ) ) {
			$out[] = $f;
			continue;
		}
		$id   = (int) ( is_array( $f ) ? ( $f['id'] ?? 0 ) : $f );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ( $check && ! can_attach( $post, $user_id ) ) ) {
			continue;
		}
		$path  = get_attached_file( $id );
		$out[] = array(
			'id'   => $id,
			'name' => wp_basename( (string) $path ),
			'size' => $path && file_exists( $path ) ? (int) filesize( $path ) : 0,
			'type' => (string) $post->post_mime_type,
		);
	}
	return $out;
}

/**
 * File for display: adds the current address and, for images, a preview. Stored data stays
 * the plain description (id, name, size, type), so exports remain portable; a file deleted
 * from the media library is marked "missing".
 *
 * @param array $f Stored description.
 */
function file_view( array $f ): array {
	$id = (int) ( $f['id'] ?? 0 );
	if ( ! $id && ! empty( $f['url'] ) ) {
		return $f; // Imported from another site: link to where it lives.
	}
	$url = $id ? wp_get_attachment_url( $id ) : false;
	if ( ! $url ) {
		return $f + array( 'missing' => true );
	}
	$f['url']     = $url;
	$f['title']   = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	$f['library'] = ! \Flurfunk\Files\is_hidden( $id );
	if ( str_starts_with( (string) ( $f['type'] ?? '' ), 'image/' ) ) {
		$img = wp_get_attachment_image_src( $id, 'medium_large' );
		if ( $img ) {
			$f['preview'] = $img[0];
			$f['width']   = (int) $img[1];
			$f['height']  = (int) $img[2];
		}
		$thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
		if ( $thumb ) {
			$f['thumb'] = $thumb[0];
		}
	}
	return $f;
}

/* -------------------------------------------------------------------------
 * Output in the data format
 * ---------------------------------------------------------------------- */

/**
 * Cards of threads (grouped by message).
 *
 * @param int[] $thread_ids Threads.
 * @return array<int, array<int, array[]>> [thread][message] => cards.
 */
function attachments_for( array $thread_ids ): array {
	global $wpdb;
	if ( ! $thread_ids ) {
		return array();
	}
	$ids  = array_map( 'intval', $thread_ids );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE thread_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id', array_merge( array( table( 'attachments' ) ), $ids ) ) );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r->thread_id ][ (int) $r->message_id ][] = array(
			'id'       => (int) $r->id,
			'provider' => $r->provider,
			'ref'      => json_decode( $r->ref, true ) ?: array(),
			'snapshot' => json_decode( $r->snapshot, true ) ?: array(),
		);
	}
	return $out;
}

/**
 * Mentions (grouped by message).
 *
 * @param int[] $thread_ids Threads.
 * @return array<int, array<int, int[]>>
 */
function mentions_for( array $thread_ids ): array {
	global $wpdb;
	if ( ! $thread_ids ) {
		return array();
	}
	$ids  = array_map( 'intval', $thread_ids );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE thread_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( table( 'mentions' ) ), $ids ) ) );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r->thread_id ][ (int) $r->message_id ][] = (int) $r->user_id;
	}
	return $out;
}

/**
 * Thread in the data format (without messages).
 *
 * @param object $t     Thread row.
 * @param array  $atts  Cards from attachments_for().
 * @param array  $ments Mentions from mentions_for().
 */
function format_thread( object $t, array $atts = array(), array $ments = array() ): array {
	$channel = channel( (int) $t->channel_id );
	return array(
		'id'          => (int) $t->id,
		'channel'     => $channel ? $channel->slug : '',
		'ref_key'     => '' !== $t->ref_key ? $t->ref_key : null,
		'target'      => '' !== $t->target_type ? array(
			'type' => $t->target_type,
			'id'   => (int) $t->target_id,
		) : null,
		'title'       => '' !== $t->title ? $t->title : null,
		'body'        => $t->body,
		'files'       => array_map( __NAMESPACE__ . '\\file_view', json_decode( (string) ( $t->files ?? '' ), true ) ?: array() ),
		'author'      => (int) $t->author_id,
		'created'     => iso( $t->created ),
		'status'      => '' !== $t->status ? $t->status : null,
		'resolved'    => 'done' === $t->status && $t->resolved_by ? array(
			'by' => (int) $t->resolved_by,
			'at' => iso( $t->resolved_at ),
		) : null,
		'mentions'    => $ments[ (int) $t->id ][0] ?? array(),
		'attachments' => $atts[ (int) $t->id ][0] ?? array(),
		'due'         => ! empty( $t->due_date ) ? (string) $t->due_date : null,
		'assignees'   => assignees( (int) $t->id ),
	);
}

/**
 * People responsible for a task (cached per request; prime with assignees_for()).
 *
 * @param int $thread_id Thread.
 * @return int[]
 */
function assignees( int $thread_id ): array {
	$map = assignees_for( array( $thread_id ) );
	return $map[ $thread_id ] ?? array();
}

/**
 * Load assignees of several threads at once.
 *
 * @param int[] $thread_ids Threads.
 * @param bool  $fresh      Ignore the cache.
 * @return array<int,int[]>
 */
function assignees_for( array $thread_ids, bool $fresh = false ): array {
	global $wpdb;
	static $cache = array();
	if ( $fresh ) {
		$cache = array();
	}
	$missing = array_values( array_diff( array_map( 'intval', $thread_ids ), array_keys( $cache ) ) );
	if ( $missing ) {
		foreach ( $missing as $id ) {
			$cache[ $id ] = array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT thread_id, user_id FROM %i WHERE thread_id IN (' . implode( ',', array_fill( 0, count( $missing ), '%d' ) ) . ') ORDER BY created', array_merge( array( table( 'assignees' ) ), $missing ) ) );
		foreach ( $rows as $r ) {
			$cache[ (int) $r->thread_id ][] = (int) $r->user_id;
		}
	}
	return array_intersect_key( $cache, array_flip( array_map( 'intval', $thread_ids ) ) );
}

/**
 * Change due date and/or assignees of a task. Changes are logged as events in the thread;
 * new assignees are notified.
 *
 * @param int   $thread_id Thread.
 * @param array $args      due ('Y-m-d' or '' to clear), assignees (user IDs, replaces the list).
 * @param bool  $check     Check permissions (off for imports).
 * @return true|\WP_Error
 */
function set_task( int $thread_id, array $args, bool $check = true ) {
	global $wpdb;
	$user = get_current_user_id();
	$t    = thread_row( $thread_id );
	if ( ! $t || ( $check && ! Access\can_see( $t, $user ) ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( $check && ! apply_filters( 'flurfunk_can_resolve', true, $t, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( '' === $t->status ) {
		$wpdb->update( table( 'threads' ), array( 'status' => 'open' ), array( 'id' => $thread_id ) );
	}
	$events = array();
	if ( array_key_exists( 'due', $args ) ) {
		$due = (string) $args['due'];
		$due = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) && checkdate( (int) substr( $due, 5, 2 ), (int) substr( $due, 8, 2 ), (int) substr( $due, 0, 4 ) ) ? $due : null;
		if ( (string) $t->due_date !== (string) $due ) {
			$wpdb->update( table( 'threads' ), array( 'due_date' => $due ), array( 'id' => $thread_id ) );
			$events[] = array( 'event' => 'due', 'due' => $due );
		}
	}
	if ( array_key_exists( 'assignees', $args ) ) {
		$before = assignees( $thread_id );
		$want   = array();
		foreach ( array_unique( array_map( 'intval', (array) $args['assignees'] ) ) as $uid ) {
			if ( $uid && Access\can_use( $uid ) && Access\can_see( $t, $uid ) ) {
				$want[] = $uid;
			}
		}
		$want    = array_slice( $want, 0, 20 );
		$added   = array_values( array_diff( $want, $before ) );
		$removed = array_values( array_diff( $before, $want ) );
		foreach ( $added as $uid ) {
			$wpdb->replace( table( 'assignees' ), array( 'thread_id' => $thread_id, 'user_id' => $uid, 'assigned_by' => $user, 'created' => now() ) );
		}
		foreach ( $removed as $uid ) {
			$wpdb->delete( table( 'assignees' ), array( 'thread_id' => $thread_id, 'user_id' => $uid ) );
		}
		assignees_for( array(), true );
		if ( $added || $removed ) {
			$events[] = array( 'event' => 'assign', 'added' => $added, 'removed' => $removed );
		}
		if ( $added && $check ) {
			do_action( 'flurfunk_assigned', thread_row( $thread_id ), $added, $user );
		}
	}
	foreach ( $events as $e ) {
		$wpdb->insert(
			table( 'messages' ),
			array(
				'thread_id' => $thread_id,
				'type'      => 'event',
				'author_id' => $user,
				'body'      => (string) wp_json_encode( $e ),
				'status'    => '',
				'files'     => '[]',
				'created'   => now(),
			)
		);
	}
	if ( $events ) {
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_activity = %s WHERE id = %d', table( 'threads' ), now(), $thread_id ) );
		bump();
	}
	return true;
}

/**
 * Messages of a thread in the data format.
 *
 * @param object $t     Thread row.
 * @param array  $atts  Cards.
 * @param array  $ments Mentions.
 * @return array[]
 */
function format_messages( object $t, array $atts, array $ments, int $limit = 0 ): array {
	global $wpdb;
	// Very long threads: the newest $limit messages (oldest first); 0 = all (export).
	$rows = $limit
		? array_reverse( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE thread_id = %d ORDER BY id DESC LIMIT %d', table( 'messages' ), $t->id, $limit ) ) )
		: (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE thread_id = %d ORDER BY id', table( 'messages' ), $t->id ) );
	$out  = array();
	foreach ( $rows as $m ) {
		$mid = (int) $m->id;
		if ( 'event' === $m->type ) {
			$out[] = array(
				'id'      => $mid,
				'type'    => 'event',
				'author'  => (int) $m->author_id,
				'created' => iso( $m->created ),
			) + (array) json_decode( $m->body, true );
			continue;
		}
		if ( 'deleted' === $m->type ) {
			$out[] = array(
				'id'      => $mid,
				'type'    => 'deleted',
				'author'  => (int) $m->author_id,
				'created' => iso( $m->created ),
			);
			continue;
		}
		if ( 'status' === $m->type ) {
			$out[] = array(
				'id'      => $mid,
				'type'    => 'status',
				'author'  => (int) $m->author_id,
				'created' => iso( $m->created ),
				'status'  => $m->status,
			);
			continue;
		}
		$out[] = array(
			'id'          => $mid,
			'type'        => 'message',
			'author'      => (int) $m->author_id,
			'created'     => iso( $m->created ),
			'text'        => $m->body,
			'mentions'    => $ments[ (int) $t->id ][ $mid ] ?? array(),
			'files'       => array_map( __NAMESPACE__ . '\\file_view', json_decode( $m->files, true ) ?: array() ),
			'attachments' => $atts[ (int) $t->id ][ $mid ] ?? array(),
			'edited'      => iso( $m->edited ),
		);
	}
	return $out;
}

/**
 * Full thread for a person (with messages and read state).
 *
 * @param int $id      Thread.
 * @param int $user_id User.
 * @return array|\WP_Error
 */
function full_thread( int $id, int $user_id ) {
	$t = thread_row( $id );
	if ( ! $t || ! Access\can_see( $t, $user_id ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$atts  = attachments_for( array( $id ) );
	$ments = mentions_for( array( $id ) );
	$limit    = (int) apply_filters( 'flurfunk_thread_limit', 300 );
	$messages = format_messages( $t, $atts, $ments, $limit );
	global $wpdb;
	$total = count( $messages ) < $limit ? count( $messages ) : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE thread_id = %d', table( 'messages' ), $id ) );
	return format_thread( $t, $atts, $ments ) + array(
		'messages' => $messages,
		'older'    => max( 0, $total - count( $messages ) ),
		'read'     => read_state( array( $id ), $user_id )[ $id ] ?? 0,
		'follow'   => follows( $id )[ $user_id ] ?? 0,
		'remind'   => iso( reminder( $id, $user_id ) ),
		'audience' => audience( $t ),
	);
}

/**
 * Read state of a person.
 *
 * @param int[] $thread_ids Threads.
 * @param int   $user_id    User.
 * @return array<int,int> Thread → last message read (-1 = never opened).
 */
function read_state( array $thread_ids, int $user_id ): array {
	global $wpdb;
	$out = array_fill_keys( $thread_ids, -1 );
	if ( $thread_ids ) {
		$ids  = array_map( 'intval', $thread_ids );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT thread_id, last_message_id FROM %i WHERE user_id = %d AND thread_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( table( 'reads' ), $user_id ), $ids ) ) );
		foreach ( $rows as $r ) {
			$out[ (int) $r->thread_id ] = (int) $r->last_message_id;
		}
	}
	return $out;
}

/**
 * Guests who can read a thread (shown to the team as a hint).
 *
 * @param object $t Thread row.
 * @return string[] Names.
 */
function audience( object $t ): array {
	$channel = channel( (int) $t->channel_id );
	if ( ! $channel || 'none' === $channel->access_guests || 'post' !== $t->target_type ) {
		return array();
	}
	if ( 'all' === $channel->access_guests ) {
		return array( __( 'Guests', 'flurfunk' ) );
	}
	$names = array();
	$post  = get_post( (int) $t->target_id );
	$ids   = $post ? (array) apply_filters( 'flurfunk_post_owners', array( (int) $post->post_author ), $post ) : array();
	foreach ( $ids as $uid ) {
		if ( 'guest' === Access\kind( (int) $uid ) ) {
			$names[] = person( (int) $uid )['name'];
		}
	}
	return $names;
}

/**
 * Threads for the overview: summary with reply count, participants and unread count.
 *
 * @param int   $user_id User.
 * @param array $args    view (all|mentions|tasks), channel (short name), target ('post:ID'), ref_prefix, q (search),
 *                       before (ISO, for paging), limit, order ('ref' = oldest first).
 * @return array[]
 */
function list_threads( int $user_id, array $args = array() ): array {
	global $wpdb;
	$where = array( Access\where( $user_id ) );
	$view  = (string) ( $args['view'] ?? 'all' );
	if ( ! empty( $args['channel'] ) ) {
		$c       = channel_by_slug( (string) $args['channel'] );
		$where[] = $wpdb->prepare( 't.channel_id = %d', $c ? (int) $c->id : 0 );
	}
	if ( ! empty( $args['target'] ) && preg_match( '/^([a-z0-9_-]+):(\d+)$/', (string) $args['target'], $m ) ) {
		$where[] = $wpdb->prepare( 't.target_type = %s AND t.target_id = %d', $m[1], (int) $m[2] );
	}
	if ( ! empty( $args['ref_prefix'] ) ) {
		$where[] = $wpdb->prepare( 't.ref_key LIKE %s', $wpdb->esc_like( (string) $args['ref_prefix'] ) . '%' );
	}
	if ( 'tasks' === $view ) {
		$where[] = "t.status IN ( 'open', 'doing', 'waiting' )";
	}
	if ( 'mine' === $view ) {
		$where[] = $wpdb->prepare( "t.status IN ( 'open', 'doing', 'waiting' ) AND t.id IN ( SELECT thread_id FROM %i WHERE user_id = %d )", table( 'assignees' ), $user_id );
	}
	$q = trim( (string) ( $args['q'] ?? '' ) );
	if ( '' !== $q ) {
		$like    = '%' . $wpdb->esc_like( $q ) . '%';
		$where[] = $wpdb->prepare( "( t.title LIKE %s OR t.body LIKE %s OR t.id IN ( SELECT thread_id FROM %i WHERE type = 'message' AND body LIKE %s ) )", $like, $like, table( 'messages' ), $like );
	}
	if ( 'mentions' === $view ) {
		$where[] = $wpdb->prepare( 't.id IN (SELECT thread_id FROM %i WHERE user_id = %d)', table( 'mentions' ), $user_id );
	}
	if ( 'board' === $view ) {
		// Every task, ticked-off ones only from the last two weeks.
		$where[] = $wpdb->prepare( "( t.status IN ( 'open', 'doing', 'waiting' ) OR ( t.status = 'done' AND t.resolved_at >= %s ) )", gmdate( 'Y-m-d H:i:s', time() - (int) apply_filters( 'flurfunk_board_done_days', 14 ) * DAY_IN_SECONDS ) );
	}
	if ( ! empty( $args['person'] ) ) {
		$where[] = $wpdb->prepare( 't.id IN ( SELECT thread_id FROM %i WHERE user_id = %d )', table( 'assignees' ), (int) $args['person'] );
	}
	if ( ! empty( $args['after'] ) ) {
		$where[] = $wpdb->prepare( 't.id > %d', (int) $args['after'] ); // Paging through order=ref lists.
	}
	if ( ! empty( $args['before'] ) && mysql( (string) $args['before'] ) ) {
		$where[] = $wpdb->prepare( 't.last_activity < %s', mysql( (string) $args['before'] ) );
	}
	$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 40 ) ) );
	// One of three fixed sort orders.
	$order = 'ref' === ( $args['order'] ?? '' ) ? 't.id ASC' : ( in_array( $view, array( 'mine', 'tasks', 'board' ), true ) ? 't.due_date IS NULL, t.due_date ASC, t.last_activity DESC' : 't.last_activity DESC' );
	$where = implode( ' AND ', $where );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is made of $wpdb->prepare() pieces, $order is one of the fixed orders above.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.* FROM %i t WHERE {$where} ORDER BY {$order} LIMIT %d", table( 'threads' ), $limit ) );
	return summarize( $rows, $user_id );
}

/**
 * Summaries for thread rows.
 *
 * @param object[] $rows    Threads.
 * @param int      $user_id User.
 * @return array[]
 */
function summarize( array $rows, int $user_id ): array {
	global $wpdb;
	if ( ! $rows ) {
		return array();
	}
	$ids   = array_map( static fn ( $r ) => (int) $r->id, $rows );
	assignees_for( $ids );
	$m     = table( 'messages' );
	$read  = read_state( $ids, $user_id );
	$atts  = attachments_for( $ids );
	$ments = mentions_for( $ids );
	$stats = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT thread_id, COUNT(*) n, MAX(id) last_id FROM %i WHERE thread_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND type = 'message' GROUP BY thread_id", array_merge( array( $m ), $ids ) ) ) as $r ) {
		$stats[ (int) $r->thread_id ] = $r;
	}
	$last = array();
	$ppl  = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT thread_id, author_id, created, id FROM %i WHERE thread_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND type = 'message' ORDER BY id", array_merge( array( $m ), $ids ) ) ) as $r ) {
		$tid          = (int) $r->thread_id;
		$last[ $tid ] = $r;
		$ppl[ $tid ][ (int) $r->author_id ] = true;
	}
	// Text of each thread's latest reply, for a one-line preview.
	$texts    = array();
	$last_ids = array_filter( array_map( static fn ( $r ) => (int) $r->last_id, $stats ) );
	if ( $last_ids ) {
		$last_ids = array_values( $last_ids );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, body FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $last_ids ), '%d' ) ) . ')', array_merge( array( $m ), $last_ids ) ) ) as $r ) {
			$texts[ (int) $r->id ] = mb_substr( \Flurfunk\Notify\readable( (string) $r->body ), 0, 160 );
		}
	}
	$unread = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT m.thread_id, COUNT(*) n FROM %i m LEFT JOIN %i r ON r.thread_id = m.thread_id AND r.user_id = %d WHERE m.thread_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND m.type = 'message' AND m.author_id <> %d AND m.id > COALESCE(r.last_message_id, 0) GROUP BY m.thread_id", array_merge( array( $m, table( 'reads' ), $user_id ), $ids, array( $user_id ) ) ) ) as $r ) {
		$unread[ (int) $r->thread_id ] = (int) $r->n;
	}
	$out = array();
	foreach ( $rows as $r ) {
		$tid   = (int) $r->id;
		$l     = $last[ $tid ] ?? null;
		$out[] = format_thread( $r, $atts, $ments ) + array(
			'replies'      => (int) ( $stats[ $tid ]->n ?? 0 ),
			'participants' => array_keys( $ppl[ $tid ] ?? array() ),
			'last'         => $l ? array(
				'author'  => (int) $l->author_id,
				'created' => iso( $l->created ),
				'text'    => $texts[ (int) $l->id ] ?? '',
			) : null,
			'activity'     => iso( $r->last_activity ),
			'unread'       => ( $unread[ $tid ] ?? 0 ) + ( -1 === $read[ $tid ] && (int) $r->author_id !== $user_id ? 1 : 0 ),
			'audience'     => audience( $r ),
		);
	}
	return $out;
}

/**
 * Numbers for sidebar, dock and menu: unread per channel and target, mentions, open tasks.
 *
 * @param int $user_id User.
 */
function counts( int $user_id ): array {
	global $wpdb;
	// Asked on every admin page (menu badge): cache per person until anything changes.
	$key    = 'flurfunk_counts_' . $user_id . '_' . revision( $user_id ) . '_' . wp_date( 'Ymd' );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$where = Access\where( $user_id );
	$rows  = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is made of $wpdb->prepare() pieces (Access\where()).
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is made of $wpdb->prepare() pieces (Access\where()).
			"SELECT t.id, t.channel_id, t.target_type, t.target_id, t.status, ( SELECT COUNT(*) FROM %i m WHERE m.thread_id = t.id AND m.type = 'message' AND m.author_id <> %d AND m.id > COALESCE(r.last_message_id, 0) ) + ( r.user_id IS NULL AND t.author_id <> %d ) AS unread, EXISTS ( SELECT 1 FROM %i x WHERE x.thread_id = t.id AND x.user_id = %d ) AS mentioned, EXISTS ( SELECT 1 FROM %i a WHERE a.thread_id = t.id AND a.user_id = %d ) AS mine, t.due_date FROM %i t LEFT JOIN %i r ON r.thread_id = t.id AND r.user_id = %d WHERE {$where}",
			table( 'messages' ),
			$user_id,
			$user_id,
			table( 'mentions' ),
			$user_id,
			table( 'assignees' ),
			$user_id,
			table( 'threads' ),
			table( 'reads' ),
			$user_id
		)
	);
	$today = wp_date( 'Y-m-d' );
	$out = array(
		'total'    => 0,
		'mentions' => 0,
		'tasks'    => 0,
		'mine'     => 0,
		'overdue'  => 0,
		'channels' => array(),
		'targets'  => array(),
	);
	foreach ( $rows as $row ) {
		$n       = (int) $row->unread;
		$channel = channel( (int) $row->channel_id );
		$slug    = $channel ? $channel->slug : '';
		$out['channels'][ $slug ] = ( $out['channels'][ $slug ] ?? 0 ) + $n;
		if ( '' !== $row->target_type ) {
			$key                    = $row->target_type . ':' . $row->target_id;
			$out['targets'][ $key ] = ( $out['targets'][ $key ] ?? 0 ) + $n;
		}
		$out['total']    += $n;
		$out['mentions'] += $row->mentioned && $n ? 1 : 0;
		$active           = in_array( $row->status, ACTIVE, true );
		$out['tasks']    += $active ? 1 : 0;
		if ( $active && $row->mine ) {
			++$out['mine'];
			$out['overdue'] += $row->due_date && $row->due_date < $today ? 1 : 0;
		}
	}
	$out['notifications'] = \Flurfunk\Notify\unread( $user_id );
	set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * Targets with recent conversations the person may see (for the sidebar).
 *
 * @param int $user_id User.
 * @param int $limit   How many.
 * @return array[] { type, id }
 */
function recent_targets( int $user_id, int $limit = 15 ): array {
	global $wpdb;
	$where = Access\where( $user_id );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is made of $wpdb->prepare() pieces (Access\where()).
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.target_type, t.target_id, MAX(t.last_activity) a FROM %i t WHERE t.target_type <> '' AND {$where} GROUP BY t.target_type, t.target_id ORDER BY a DESC LIMIT %d", table( 'threads' ), $limit ) );
	return array_map(
		static fn ( $r ) => array(
			'type' => $r->target_type,
			'id'   => (int) $r->target_id,
		),
		$rows
	);
}

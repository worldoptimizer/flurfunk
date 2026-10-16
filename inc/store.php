<?php
/**
 * Reading and writing. To the outside (interface, export) there is only the format "flurfunk/1":
 *
 *   thread  { id, channel, ref_key, target: {type,id}|null, title|null, body, author, created,
 *             status: 'open'|'done'|null, resolved: {by,at}|null, mentions[], attachments[] }
 *   message { id, type: 'message'|'status', author, created, text, mentions[], files[],
 *             attachments[], status?, edited }
 *   Text: plain text, mentions as <@ID>. Times in UTC (ISO 8601).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Store;

use Flurfunk\Access;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

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
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . table( 'channels' ) . ' ORDER BY position, name' ); // phpcs:ignore WordPress.DB
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
			$wpdb->update( table( 'channels' ), $data, array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB
			channels( true );
			bump();
		}
		return (int) $row->id;
	}
	$wpdb->insert( // phpcs:ignore WordPress.DB
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
	$users = get_users(
		array(
			'capability' => 'edit_posts',
			'fields'     => 'ID',
			'orderby'    => 'display_name',
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
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'threads' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	return $row ?: null;
}

/**
 * Thread by external key (e.g. "checklist:billing"), set by plugins that embed threads.
 *
 * @param string $ref_key Key.
 */
function thread_by_ref( string $ref_key ): ?object {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'threads' ) . ' WHERE ref_key = %s ORDER BY id LIMIT 1', $ref_key ) ); // phpcs:ignore WordPress.DB
	return $row ?: null;
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
	$body  = clean_text( $args['body'] ?? '' );
	$title = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
	if ( '' === $body && '' === $title && empty( $args['attachments'] ) ) {
		return new \WP_Error( 'flurfunk_empty', __( 'The conversation is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	// Two people ticking the same embedded checklist item at once must not create two threads.
	$ref = sanitize_text_field( (string) ( $args['ref_key'] ?? '' ) );
	if ( '' !== $ref ) {
		$lock = 'flurfunk_ref_' . md5( $ref );
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // phpcs:ignore WordPress.DB
		$existing = thread_by_ref( $ref );
		if ( $existing ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB
			return (int) $existing->id;
		}
	}
	$created = mysql( $args['created'] ?? null ) ?? now();
	$status  = $args['status'] ?? ( empty( $args['task'] ) ? '' : 'open' );
	$wpdb->insert( // phpcs:ignore WordPress.DB
		table( 'threads' ),
		array(
			'channel_id'    => (int) $channel->id,
			'ref_key'       => $ref,
			'target_type'   => $type,
			'target_id'     => $tid,
			'title'         => $title,
			'body'          => $body,
			'author_id'     => $author,
			'status'        => in_array( $status, array( 'open', 'done' ), true ) ? $status : '',
			'resolved_by'   => (int) ( $args['resolved']['by'] ?? 0 ),
			'resolved_at'   => mysql( $args['resolved']['at'] ?? null ),
			'created'       => $created,
			'last_activity' => $created,
		)
	);
	$id = (int) $wpdb->insert_id;
	if ( '' !== $ref ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'flurfunk_ref_' . md5( $ref ) ) ); // phpcs:ignore WordPress.DB
	}
	if ( ! $id ) {
		return new \WP_Error( 'flurfunk_db', __( 'Could not save the conversation.', 'flurfunk' ), array( 'status' => 500 ) );
	}
	bump();
	$row = thread_row( $id );
	save_mentions( $row, 0, $check ? mentions_in( $body, $row ) : array_map( 'intval', (array) ( $args['mentions'] ?? array() ) ) );
	save_attachments( $row, 0, (array) ( $args['attachments'] ?? array() ), $author, $check );
	if ( $check ) {
		mark_read( $id, $author );
		do_action( 'flurfunk_thread_created', $row );
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
	$type  = 'status' === ( $args['type'] ?? '' ) ? 'status' : 'message';
	$body  = 'message' === $type ? clean_text( $args['body'] ?? '' ) : '';
	$files = files( (array) ( $args['files'] ?? array() ), $author, $check );
	if ( 'message' === $type && '' === $body && ! $files && empty( $args['attachments'] ) ) {
		return new \WP_Error( 'flurfunk_empty', __( 'The message is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$created = mysql( $args['created'] ?? null ) ?? now();
	$wpdb->insert( // phpcs:ignore WordPress.DB
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
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'threads' ) . ' SET last_activity = GREATEST(last_activity, %s) WHERE id = %d', $created, $thread_id ) ); // phpcs:ignore WordPress.DB
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
	if ( ! in_array( $status, array( 'open', 'done', '' ), true ) || ! apply_filters( 'flurfunk_can_resolve', true, $thread, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( $thread->status === $status ) {
		return true;
	}
	$wpdb->update( // phpcs:ignore WordPress.DB
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
	$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'messages' ) . ' WHERE id = %d', $message_id ) ); // phpcs:ignore WordPress.DB
	$t    = $m ? thread_row( (int) $m->thread_id ) : null;
	if ( ! $m || ! $t || 'message' !== $m->type || ! Access\can_see( $t, $user ) || ! can_edit( (int) $m->author_id, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$body = clean_text( $body );
	if ( '' === $body && '[]' === $m->files && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . table( 'attachments' ) . ' WHERE message_id = %d', $message_id ) ) ) { // phpcs:ignore WordPress.DB
		return new \WP_Error( 'flurfunk_empty', __( 'The message is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$wpdb->update( table( 'messages' ), array( 'body' => $body, 'edited' => now() ), array( 'id' => $message_id ) ); // phpcs:ignore WordPress.DB
	bump();
	$wpdb->delete( table( 'mentions' ), array( 'thread_id' => (int) $t->id, 'message_id' => $message_id ) ); // phpcs:ignore WordPress.DB
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
	$m    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'messages' ) . ' WHERE id = %d', $message_id ) ); // phpcs:ignore WordPress.DB
	$t    = $m ? thread_row( (int) $m->thread_id ) : null;
	if ( ! $m || ! $t || 'message' !== $m->type || ! Access\can_see( $t, $user ) || ! can_edit( (int) $m->author_id, $user ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$wpdb->update( table( 'messages' ), array( 'type' => 'deleted', 'body' => '', 'files' => '[]', 'edited' => now() ), array( 'id' => $message_id ) ); // phpcs:ignore WordPress.DB
	bump();
	$wpdb->delete( table( 'mentions' ), array( 'thread_id' => (int) $t->id, 'message_id' => $message_id ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( table( 'attachments' ), array( 'thread_id' => (int) $t->id, 'message_id' => $message_id ) ); // phpcs:ignore WordPress.DB
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
	$wpdb->update( table( 'threads' ), array( 'title' => $title, 'body' => $body ), array( 'id' => $thread_id ) ); // phpcs:ignore WordPress.DB
	bump();
	$wpdb->delete( table( 'mentions' ), array( 'thread_id' => $thread_id, 'message_id' => 0 ) ); // phpcs:ignore WordPress.DB
	save_mentions( $t, 0, mentions_in( $body, $t ) );
	return true;
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
		$message_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . table( 'messages' ) . ' WHERE thread_id = %d', $thread_id ) ); // phpcs:ignore WordPress.DB
	}
	$wpdb->query( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'INSERT INTO ' . table( 'reads' ) . ' (user_id, thread_id, last_message_id, seen) VALUES (%d, %d, %d, %s)
			ON DUPLICATE KEY UPDATE last_message_id = GREATEST(last_message_id, VALUES(last_message_id)), seen = VALUES(seen)',
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
		$wpdb->replace( // phpcs:ignore WordPress.DB
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
		$wpdb->insert( // phpcs:ignore WordPress.DB
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
 * Validate and describe files from the media library.
 *
 * @param array $ids     Media library IDs (or ready descriptions on import).
 * @param int   $user_id Who attaches.
 * @param bool  $check   Check permissions.
 * @return array[]
 */
function files( array $ids, int $user_id, bool $check ): array {
	$out = array();
	foreach ( array_slice( $ids, 0, 10 ) as $f ) {
		if ( ! $check && is_array( $f ) ) {
			$out[] = $f;
			continue;
		}
		$id   = (int) ( is_array( $f ) ? ( $f['id'] ?? 0 ) : $f );
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ( $check && ! user_can( $user_id, 'edit_post', $id ) && (int) $post->post_author !== $user_id ) ) {
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
	$f['url']   = $url;
	$f['title'] = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	if ( str_starts_with( (string) ( $f['type'] ?? '' ), 'image/' ) ) {
		$img = wp_get_attachment_image_src( $id, 'medium_large' );
		if ( $img ) {
			$f['preview'] = $img[0];
			$f['width']   = (int) $img[1];
			$f['height']  = (int) $img[2];
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
	$rows = $wpdb->get_results( 'SELECT * FROM ' . table( 'attachments' ) . ' WHERE thread_id IN (' . implode( ',', array_map( 'intval', $thread_ids ) ) . ') ORDER BY id' ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r->thread_id ][ (int) $r->message_id ][] = array(
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
	$rows = $wpdb->get_results( 'SELECT * FROM ' . table( 'mentions' ) . ' WHERE thread_id IN (' . implode( ',', array_map( 'intval', $thread_ids ) ) . ')' ); // phpcs:ignore WordPress.DB
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
		'author'      => (int) $t->author_id,
		'created'     => iso( $t->created ),
		'status'      => '' !== $t->status ? $t->status : null,
		'resolved'    => 'done' === $t->status && $t->resolved_by ? array(
			'by' => (int) $t->resolved_by,
			'at' => iso( $t->resolved_at ),
		) : null,
		'mentions'    => $ments[ (int) $t->id ][0] ?? array(),
		'attachments' => $atts[ (int) $t->id ][0] ?? array(),
	);
}

/**
 * Messages of a thread in the data format.
 *
 * @param object $t     Thread row.
 * @param array  $atts  Cards.
 * @param array  $ments Mentions.
 * @return array[]
 */
function format_messages( object $t, array $atts, array $ments ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . table( 'messages' ) . ' WHERE thread_id = %d ORDER BY id', $t->id ) ); // phpcs:ignore WordPress.DB
	$out  = array();
	foreach ( $rows as $m ) {
		$mid = (int) $m->id;
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
	return format_thread( $t, $atts, $ments ) + array(
		'messages' => format_messages( $t, $atts, $ments ),
		'read'     => read_state( array( $id ), $user_id )[ $id ] ?? 0,
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT thread_id, last_message_id FROM ' . table( 'reads' ) . ' WHERE user_id = %d AND thread_id IN (' . implode( ',', array_map( 'intval', $thread_ids ) ) . ')', $user_id ) ); // phpcs:ignore WordPress.DB
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
 * @param array $args    view (all|mentions|tasks), channel (short name), target ('post:ID'), ref_prefix,
 *                       before (ISO, for paging), limit, order ('ref' = oldest first).
 * @return array[]
 */
function list_threads( int $user_id, array $args = array() ): array {
	global $wpdb;
	$t     = table( 'threads' );
	$where = array( Access\where( $user_id ) );
	$view  = (string) ( $args['view'] ?? 'all' );
	if ( ! empty( $args['channel'] ) ) {
		$c       = channel_by_slug( (string) $args['channel'] );
		$where[] = 't.channel_id = ' . ( $c ? (int) $c->id : 0 );
	}
	if ( ! empty( $args['target'] ) && preg_match( '/^([a-z_]+):(\d+)$/', (string) $args['target'], $m ) ) {
		$where[] = $wpdb->prepare( 't.target_type = %s AND t.target_id = %d', $m[1], (int) $m[2] );
	}
	if ( ! empty( $args['ref_prefix'] ) ) {
		$where[] = $wpdb->prepare( 't.ref_key LIKE %s', $wpdb->esc_like( (string) $args['ref_prefix'] ) . '%' );
	}
	if ( 'tasks' === $view ) {
		$where[] = "t.status = 'open'";
	}
	if ( 'mentions' === $view ) {
		$where[] = $wpdb->prepare( 't.id IN (SELECT thread_id FROM ' . table( 'mentions' ) . ' WHERE user_id = %d)', $user_id );
	}
	if ( ! empty( $args['before'] ) && mysql( (string) $args['before'] ) ) {
		$where[] = $wpdb->prepare( 't.last_activity < %s', mysql( (string) $args['before'] ) );
	}
	$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 40 ) ) );
	$order = 'ref' === ( $args['order'] ?? '' ) ? 't.id ASC' : 't.last_activity DESC';
	$rows  = $wpdb->get_results( "SELECT t.* FROM $t t WHERE " . implode( ' AND ', $where ) . " ORDER BY $order LIMIT $limit" ); // phpcs:ignore WordPress.DB
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
	$in    = implode( ',', $ids );
	$m     = table( 'messages' );
	$read  = read_state( $ids, $user_id );
	$atts  = attachments_for( $ids );
	$ments = mentions_for( $ids );
	$stats = array();
	foreach ( $wpdb->get_results( "SELECT thread_id, COUNT(*) n, MAX(id) last_id FROM $m WHERE thread_id IN ($in) AND type = 'message' GROUP BY thread_id" ) as $r ) { // phpcs:ignore WordPress.DB
		$stats[ (int) $r->thread_id ] = $r;
	}
	$last = array();
	$ppl  = array();
	foreach ( $wpdb->get_results( "SELECT thread_id, author_id, created, id FROM $m WHERE thread_id IN ($in) AND type = 'message' ORDER BY id" ) as $r ) { // phpcs:ignore WordPress.DB
		$tid          = (int) $r->thread_id;
		$last[ $tid ] = $r;
		$ppl[ $tid ][ (int) $r->author_id ] = true;
	}
	$unread = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT m.thread_id, COUNT(*) n FROM $m m LEFT JOIN " . table( 'reads' ) . " r ON r.thread_id = m.thread_id AND r.user_id = %d WHERE m.thread_id IN ($in) AND m.type = 'message' AND m.author_id <> %d AND m.id > COALESCE(r.last_message_id, 0) GROUP BY m.thread_id", $user_id, $user_id ) ) as $r ) { // phpcs:ignore WordPress.DB
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
	$key    = 'flurfunk_counts_' . $user_id . '_' . revision( $user_id );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$t   = table( 'threads' );
	$m   = table( 'messages' );
	$r   = table( 'reads' );
	$mt  = table( 'mentions' );
	$sql = $wpdb->prepare(
		"SELECT t.id, t.channel_id, t.target_type, t.target_id, t.status,
			( SELECT COUNT(*) FROM $m m WHERE m.thread_id = t.id AND m.type = 'message' AND m.author_id <> %d AND m.id > COALESCE(r.last_message_id, 0) )
			+ ( r.user_id IS NULL AND t.author_id <> %d ) AS unread,
			EXISTS ( SELECT 1 FROM $mt x WHERE x.thread_id = t.id AND x.user_id = %d ) AS mentioned
		FROM $t t LEFT JOIN $r r ON r.thread_id = t.id AND r.user_id = %d
		WHERE " . Access\where( $user_id ),
		$user_id,
		$user_id,
		$user_id,
		$user_id
	);
	$out = array(
		'total'    => 0,
		'mentions' => 0,
		'tasks'    => 0,
		'channels' => array(),
		'targets'  => array(),
	);
	foreach ( $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB
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
		$out['tasks']    += 'open' === $row->status ? 1 : 0;
	}
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
	$rows = $wpdb->get_results( 'SELECT t.target_type, t.target_id, MAX(t.last_activity) a FROM ' . table( 'threads' ) . " t WHERE t.target_type <> '' AND " . Access\where( $user_id ) . ' GROUP BY t.target_type, t.target_id ORDER BY a DESC LIMIT ' . (int) $limit ); // phpcs:ignore WordPress.DB
	return array_map(
		static fn ( $r ) => array(
			'type' => $r->target_type,
			'id'   => (int) $r->target_id,
		),
		$rows
	);
}

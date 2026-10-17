<?php
/**
 * Notifications: one dispatcher, several channels.
 *
 * Events and their kind per person:
 *   mention   someone wrote <@you>
 *   assigned  you were made responsible for a task
 *   due       a task of yours is due today or overdue (daily, once)
 *   reply     new message in a thread you started, wrote in or are responsible for
 *   done      a task you started or are responsible for was ticked off by someone else
 *   message   anything else you can see (only for people who chose "everything")
 *
 * Every notification is stored for the in-app list (bell). Delivery per channel follows the
 * person's preferences (user meta "flurfunk_prefs"):
 *   mail   mentions (personal kinds) | all | digest (one mail a day) | none
 *   push   mentions | all | none   – only for browsers that subscribed (inc/push.php)
 *   quiet  "22:00-07:00": no push in that window (in the site's time zone)
 *   hour   0–23: when the daily digest and due reminders arrive (default 7)
 * Per thread: follow (every reply) or mute (only mentions and tasks).
 *
 * Filters: flurfunk_notify_recipients (user => kind, per event), flurfunk_mail (false = skip),
 * flurfunk_push_payload.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Notify;

use Flurfunk\Access;
use Flurfunk\Store;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

const PREFS    = 'flurfunk_prefs';
const PERSONAL = array( 'mention', 'assigned', 'due', 'remind', 'reply', 'done', 'channel' );
const PRIORITY = array( 'mention' => 6, 'assigned' => 5, 'due' => 4, 'remind' => 4, 'done' => 3, 'reply' => 2, 'channel' => 2, 'message' => 1 );
const KEYS     = array( 'theme', 'avatar', 'mail', 'push', 'quiet', 'badge', 'hour', 'channels' );
const DIGEST   = 'flurfunk_digest';
const DUE      = 'flurfunk_due';
const REMIND   = 'flurfunk_remind';

/**
 * Preferences of a person.
 *
 * @param int $user_id User.
 */
function prefs( int $user_id ): array {
	// Each preference is its own meta entry (flurfunk_pref_<key>), so two settings changed at the
	// same moment can't overwrite each other; the older combined entry still counts as fallback.
	$p = (array) get_user_meta( $user_id, PREFS, true );
	foreach ( KEYS as $k ) {
		$v = get_user_meta( $user_id, PREFS . '_' . $k, true );
		if ( '' !== $v || metadata_exists( 'user', $user_id, PREFS . '_' . $k ) ) {
			$p[ $k ] = 'badge' === $k ? (bool) $v : $v;
		}
	}
	$quiet = (string) ( $p['quiet'] ?? '' );
	return array(
		'theme'  => in_array( $p['theme'] ?? '', array( 'system', 'light', 'dark' ), true ) ? $p['theme'] : 'light',
		'avatar' => in_array( $p['avatar'] ?? '', array( 'photo', 'initials' ), true ) ? $p['avatar'] : 'photo',
		'mail'   => in_array( $p['mail'] ?? '', array( 'mentions', 'all', 'digest', 'none' ), true ) ? $p['mail'] : 'mentions',
		'push'   => in_array( $p['push'] ?? '', array( 'mentions', 'all', 'none' ), true ) ? $p['push'] : 'mentions',
		'quiet'  => preg_match( '/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/', $quiet ) ? $quiet : '',
		'badge'  => isset( $p['badge'] ) ? (bool) $p['badge'] : true,
		'hour'   => isset( $p['hour'] ) && '' !== $p['hour'] && (int) $p['hour'] >= 0 && (int) $p['hour'] <= 23 ? (int) $p['hour'] : 7,
		// Per channel (slug): all = every new thread and reply, mentions = only what concerns me.
		'channels' => array_filter( is_array( $p['channels'] ?? null ) ? $p['channels'] : array(), static fn ( $v, $k ) => is_string( $k ) && in_array( $v, array( 'all', 'mentions' ), true ), ARRAY_FILTER_USE_BOTH ),
	);
}

/**
 * Store preferences.
 *
 * @param int   $user_id User.
 * @param array $in      Changes.
 */
function save_prefs( int $user_id, array $in ): array {
	if ( isset( $in['channel'] ) && is_array( $in['channel'] ) ) {
		// { slug, level: all|mentions|default } changes one channel.
		$all  = prefs( $user_id )['channels'];
		$slug = sanitize_key( (string) ( $in['channel']['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$level = (string) ( $in['channel']['level'] ?? '' );
			if ( in_array( $level, array( 'all', 'mentions' ), true ) ) {
				$all[ $slug ] = $level;
			} else {
				unset( $all[ $slug ] );
			}
			update_user_meta( $user_id, PREFS . '_channels', $all );
		}
	}
	unset( $in['channels'], $in['channel'] );
	foreach ( array_intersect_key( $in, array_flip( KEYS ) ) as $k => $v ) {
		update_user_meta( $user_id, PREFS . '_' . $k, 'badge' === $k ? ( $v ? '1' : '0' ) : sanitize_text_field( (string) $v ) );
	}
	return prefs( $user_id );
}

/**
 * Is it quiet time for this person right now?
 *
 * @param int $user_id User.
 */
function is_quiet( int $user_id ): bool {
	$q = prefs( $user_id )['quiet'];
	if ( '' === $q ) {
		return false;
	}
	list( $from, $to ) = explode( '-', $q );
	$now               = wp_date( 'H:i' );
	return $from <= $to ? ( $now >= $from && $now < $to ) : ( $now >= $from || $now < $to );
}

/**
 * Should a channel deliver this kind, given the person's level?
 *
 * @param string $level mentions|all|digest|none.
 * @param string $kind  Kind.
 */
function wants( string $level, string $kind ): bool {
	return 'all' === $level || ( 'mentions' === $level && in_array( $kind, PERSONAL, true ) );
}

/*
 * Events.
 */
add_action( 'flurfunk_thread_created', static fn ( object $t ) => event( $t, 0 ) );
add_action( 'flurfunk_message_added', static fn ( object $t, int $mid ) => event( $t, $mid ), 10, 2 );
add_action(
	'flurfunk_assigned',
	static function ( object $t, array $added, int $actor ): void {
		dispatch( $t, 0, array_fill_keys( array_diff( $added, array( $actor ) ), 'assigned' ), $actor );
	},
	10,
	3
);
add_action(
	'flurfunk_status_changed',
	static function ( object $t, string $status, int $actor ): void {
		if ( 'done' !== $status ) {
			return;
		}
		$to = array_fill_keys( array_merge( array( (int) $t->author_id ), Store\assignees( (int) $t->id ) ), 'done' );
		unset( $to[ $actor ] );
		dispatch( $t, 0, $to, $actor );
	},
	10,
	3
);

/**
 * A new thread (message 0) or message: work out who gets what.
 *
 * @param object $thread     Thread row.
 * @param int    $message_id Message (0 = the thread itself).
 */
function event( object $thread, int $message_id ): void {
	global $wpdb;
	$m      = table( 'messages' );
	$sender = get_current_user_id();
	if ( $message_id ) {
		$type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM $m WHERE id = %d", $message_id ) ); // phpcs:ignore WordPress.DB
		if ( 'message' !== $type ) {
			return;
		}
	}
	$mentioned    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . table( 'mentions' ) . ' WHERE thread_id = %d AND message_id = %d', $thread->id, $message_id ) ) ); // phpcs:ignore WordPress.DB
	$participants = $message_id ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT author_id FROM $m WHERE thread_id = %d AND type = 'message'", $thread->id ) ) ) : array(); // phpcs:ignore WordPress.DB
	if ( $message_id ) {
		$participants[] = (int) $thread->author_id;
		$participants   = array_merge( $participants, Store\assignees( (int) $thread->id ) );
	}
	$follow = Store\follows( (int) $thread->id );
	$ch     = Store\channel( (int) $thread->channel_id );
	$slug   = $ch ? (string) $ch->slug : '';
	$to     = array();
	foreach ( Store\people() as $p ) {
		$uid = (int) $p['id'];
		if ( $uid === $sender || ! Access\can_see( $thread, $uid ) ) {
			continue;
		}
		if ( in_array( $uid, $mentioned, true ) ) {
			$to[ $uid ] = 'mention';
		} elseif ( -1 === ( $follow[ $uid ] ?? 0 ) ) {
			continue; // Muted: only mentions (and tasks, see flurfunk_assigned) get through.
		} elseif ( in_array( $uid, $participants, true ) || 1 === ( $follow[ $uid ] ?? 0 ) ) {
			$to[ $uid ] = 'reply';
		} else {
			$pr    = prefs( $uid );
			$level = $pr['channels'][ $slug ] ?? '';
			if ( 'all' === $level ) {
				$to[ $uid ] = 'channel';
			} elseif ( 'mentions' !== $level && ( 'all' === $pr['mail'] || 'all' === $pr['push'] ) ) {
				$to[ $uid ] = 'message';
			}
		}
	}
	dispatch( $thread, $message_id, (array) apply_filters( 'flurfunk_notify_recipients', $to, $thread, $message_id ), $sender );
}

/**
 * Record notifications and queue their delivery.
 *
 * @param object $thread     Thread row.
 * @param int    $message_id Message.
 * @param array  $to         user => kind.
 * @param int    $actor      Who caused it.
 */
function dispatch( object $thread, int $message_id, array $to, int $actor ): void {
	global $wpdb;
	foreach ( $to as $uid => $kind ) {
		$uid = (int) $uid;
		if ( ! $uid || ! isset( PRIORITY[ $kind ] ) ) {
			continue;
		}
		// One notification per person and event: keep the most important kind.
		$q = &queue();
		$k = $uid . ':' . $thread->id . ':' . $message_id;
		if ( isset( $q[ $k ] ) && PRIORITY[ $q[ $k ]['kind'] ] >= PRIORITY[ $kind ] ) {
			continue;
		}
		$q[ $k ] = compact( 'uid', 'kind', 'thread', 'message_id', 'actor' );
	}
	if ( queue() && ! has_action( 'shutdown', __NAMESPACE__ . '\\deliver' ) ) {
		add_action( 'shutdown', __NAMESPACE__ . '\\deliver' );
	}
	unset( $wpdb );
}

/**
 * Pending notifications of this request.
 *
 * @return array[]
 */
function &queue(): array {
	static $q = array();
	return $q;
}

/**
 * Store and deliver everything queued in this request.
 */
function deliver(): void {
	global $wpdb;
	$q = &queue();
	foreach ( $q as $key => $n ) {
		unset( $q[ $key ] );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			table( 'notifications' ),
			array(
				'user_id'    => $n['uid'],
				'thread_id'  => (int) $n['thread']->id,
				'message_id' => $n['message_id'],
				'kind'       => $n['kind'],
				'actor_id'   => $n['actor'],
				'created'    => Store\now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		update_user_meta( $n['uid'], 'flurfunk_rev', (int) get_user_meta( $n['uid'], 'flurfunk_rev', true ) + 1 );
		$pr   = prefs( $n['uid'] );
		$sent = array();
		if ( wants( $pr['mail'], $n['kind'] ) && mail_one( $n ) ) {
			$sent[] = 'mail';
		}
		if ( wants( $pr['push'], $n['kind'] ) && ! is_quiet( $n['uid'] ) && \Flurfunk\Push\send( $n['uid'], payload( $n ) ) ) {
			$sent[] = 'push';
		}
		if ( $sent && $id ) {
			$wpdb->update( table( 'notifications' ), array( 'sent' => implode( ',', $sent ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		}
	}
}

/**
 * Mentions <@ID> as @Name.
 *
 * @param string $text Text.
 */
function readable( string $text ): string {
	return (string) preg_replace_callback( '/<@(\d+)>/', static fn ( $m ) => '@' . Store\person( (int) $m[1] )['name'], $text );
}

/**
 * Words for a notification: headline and text.
 *
 * @param array $n Queued notification (uid, kind, thread, message_id, actor).
 * @return array{headline:string, text:string, topic:string}
 */
function words( array $n ): array {
	global $wpdb;
	$t     = $n['thread'];
	$who   = $n['actor'] ? Store\person( (int) $n['actor'] )['name'] : \Flurfunk\Settings\name();
	$topic = '' !== $t->title ? $t->title : wp_trim_words( readable( $t->body ), 8, '…' );
	$text  = $n['message_id']
		? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . table( 'messages' ) . ' WHERE id = %d', $n['message_id'] ) ) // phpcs:ignore WordPress.DB
		: trim( $t->title . "\n\n" . $t->body );
	$due   = ! empty( $t->due_date ) ? wp_date( get_option( 'date_format' ), (int) strtotime( $t->due_date . ' 12:00' ) ) : '';
	switch ( $n['kind'] ) {
		case 'mention':
			/* translators: %s: person */
			$head = sprintf( __( '%s mentioned you', 'flurfunk' ), $who );
			break;
		case 'assigned':
			/* translators: %s: person */
			$head = sprintf( __( '%s gave you a task', 'flurfunk' ), $who ) . ( $due ? ' · ' . sprintf( /* translators: %s: date */ __( 'due %s', 'flurfunk' ), $due ) : '' );
			$text = trim( $t->title . "\n\n" . $t->body );
			break;
		case 'due':
			$head = $t->due_date < wp_date( 'Y-m-d' ) ? __( 'Overdue task', 'flurfunk' ) : __( 'Task due today', 'flurfunk' );
			$text = trim( $t->title . "\n\n" . $t->body );
			break;
		case 'remind':
			$head = __( 'Reminder', 'flurfunk' );
			$text = $topic;
			break;
		case 'done':
			/* translators: %s: person */
			$head = sprintf( __( '%s ticked off a task', 'flurfunk' ), $who );
			$text = $topic;
			break;
		default:
			/* translators: %s: person */
			$head = sprintf( __( '%s wrote', 'flurfunk' ), $who );
	}
	return array(
		'headline' => $head,
		'text'     => readable( (string) $text ),
		'topic'    => $topic,
	);
}

/**
 * One mail for one notification.
 *
 * @param array $n Queued notification.
 */
function mail_one( array $n ): bool {
	$user = get_userdata( $n['uid'] );
	if ( ! $user || ! $user->user_email || str_ends_with( $user->user_email, '.invalid' ) ) {
		return false;
	}
	$w       = words( $n );
	$channel = Store\channel( (int) $n['thread']->channel_id );
	/* translators: 1: site/app name, 2: headline, 3: channel, 4: thread topic */
	$subject = sprintf( __( '[%1$s] %2$s in #%3$s: %4$s', 'flurfunk' ), \Flurfunk\Settings\name(), $w['headline'], $channel ? $channel->slug : '', $w['topic'] );
	$body    = $w['headline'] . ":\n\n" . $w['text'] . "\n\n"
		/* translators: %s: link */
		. sprintf( __( 'Reply in Flurfunk: %s', 'flurfunk' ), \Flurfunk\Admin\url( 'thread:' . $n['thread']->id ) ) . "\n\n"
		. __( 'You can change which mails you get under your name at the bottom of the Flurfunk sidebar.', 'flurfunk' );
	$mail = apply_filters( 'flurfunk_mail', array( 'subject' => str_replace( array( "\r", "\n" ), ' ', $subject ), 'body' => $body ), $user, $n['thread'], $n['message_id'] );
	return $mail ? (bool) wp_mail( $user->user_email, $mail['subject'], $mail['body'] ) : false;
}

/**
 * Push payload for a notification.
 *
 * @param array $n Queued notification.
 */
function payload( array $n ): array {
	$w = words( $n );
	return (array) apply_filters(
		'flurfunk_push_payload',
		array(
			'title' => mb_substr( $w['headline'], 0, 120 ),
			'body'  => mb_substr( $w['topic'] . ( $w['text'] && $w['text'] !== $w['topic'] ? ': ' . $w['text'] : '' ), 0, 180 ),
			'url'   => \Flurfunk\Admin\url( 'thread:' . $n['thread']->id ),
			'tag'   => 'flurfunk-' . $n['thread']->id,
			'icon'  => \Flurfunk\Settings\logo(),
		),
		$n
	);
}

/*
 * Due reminders and digests: an hourly job, each person at their own hour (default 7:00).
 */
add_action(
	'init',
	static function (): void {
		foreach ( array( DUE, DIGEST ) as $hook ) {
			if ( 'hourly' !== wp_get_schedule( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
				$next = new \DateTime( 'now', wp_timezone() );
				$next->setTime( (int) $next->format( 'H' ) + 1, 0, 30 );
				wp_schedule_event( $next->getTimestamp(), 'hourly', $hook );
			}
		}
	}
);

/**
 * Is it this person's hour for daily mails and reminders?
 *
 * @param int $user_id User.
 */
function is_their_hour( int $user_id ): bool {
	return (int) wp_date( 'G' ) === prefs( $user_id )['hour'];
}
add_action( DUE, __NAMESPACE__ . '\\remind_due' );
add_action( DIGEST, __NAMESPACE__ . '\\send_digests' );

/**
 * Remind assignees of open tasks that are due today or overdue (once per day and task).
 *
 * @return int Reminders created.
 */
function remind_due( bool $force = false ): int {
	global $wpdb;
	$today = wp_date( 'Y-m-d' );
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, a.user_id assignee FROM ' . table( 'threads' ) . ' t JOIN ' . table( 'assignees' ) . " a ON a.thread_id = t.id WHERE t.status = 'open' AND t.due_date IS NOT NULL AND t.due_date <= %s", $today ) ); // phpcs:ignore WordPress.DB
	$n     = 0;
	foreach ( $rows as $r ) {
		$done = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . table( 'notifications' ) . " WHERE kind = 'due' AND thread_id = %d AND user_id = %d AND created >= %s", $r->id, $r->assignee, get_gmt_from_date( $today . ' 00:00:00' ) ) ); // phpcs:ignore WordPress.DB
		if ( ! $done && ( $force || is_their_hour( (int) $r->assignee ) ) && Access\can_see( $r, (int) $r->assignee ) ) {
			dispatch( $r, 0, array( (int) $r->assignee => 'due' ), 0 );
			++$n;
		}
	}
	deliver();
	return $n;
}

/**
 * Daily digest mail for people who chose it: unread notifications since the last digest.
 *
 * @return int Mails sent.
 */
function send_digests( bool $force = false ): int {
	global $wpdb;
	$sent = 0;
	foreach ( Store\people() as $p ) {
		$uid = (int) $p['id'];
		if ( 'digest' !== prefs( $uid )['mail'] || ( ! $force && ! is_their_hour( $uid ) ) ) {
			continue;
		}
		$since = (string) get_user_meta( $uid, 'flurfunk_digest_last', true ) ?: gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT n.*, t.title, t.body, t.channel_id FROM ' . table( 'notifications' ) . ' n JOIN ' . table( 'threads' ) . ' t ON t.id = n.thread_id WHERE n.user_id = %d AND n.read_at IS NULL AND n.created > %s ORDER BY n.id', $uid, $since ) ); // phpcs:ignore WordPress.DB
		update_user_meta( $uid, 'flurfunk_digest_last', Store\now() );
		if ( ! $rows ) {
			continue;
		}
		$user  = get_userdata( $uid );
		$lines = array();
		foreach ( $rows as $r ) {
			$w       = words(
				array(
					'uid'        => $uid,
					'kind'       => $r->kind,
					'thread'     => Store\thread_row( (int) $r->thread_id ),
					'message_id' => (int) $r->message_id,
					'actor'      => (int) $r->actor_id,
				)
			);
			$lines[] = '• ' . $w['headline'] . ' – ' . $w['topic'] . "\n  " . \Flurfunk\Admin\url( 'thread:' . $r->thread_id );
		}
		/* translators: 1: site/app name, 2: number */
		$subject = sprintf( _n( '[%1$s] %2$d new notification', '[%1$s] %2$d new notifications', count( $rows ), 'flurfunk' ), \Flurfunk\Settings\name(), count( $rows ) );
		$body    = implode( "\n\n", $lines ) . "\n\n" . __( 'You can change which mails you get under your name at the bottom of the Flurfunk sidebar.', 'flurfunk' );
		$mail    = apply_filters( 'flurfunk_mail', compact( 'subject', 'body' ), $user, null, 0 );
		if ( $user && $mail && wp_mail( $user->user_email, $mail['subject'], $mail['body'] ) ) {
			++$sent;
		}
	}
	return $sent;
}

/*
 * The in-app list.
 */

/**
 * Notifications of a person, newest first.
 *
 * @param int $user_id User.
 * @param int $before  Only older than this ID (paging).
 * @param int $limit   How many.
 */
function list_for( int $user_id, int $before = 0, int $limit = 30 ): array {
	global $wpdb;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'SELECT n.* FROM ' . table( 'notifications' ) . ' n WHERE n.user_id = %d' . ( $before ? ' AND n.id < ' . (int) $before : '' ) . ' ORDER BY n.id DESC LIMIT %d',
			$user_id,
			max( 1, min( 100, $limit ) )
		)
	);
	$out = array();
	foreach ( $rows as $r ) {
		$t = Store\thread_row( (int) $r->thread_id );
		if ( ! $t || ! Access\can_see( $t, $user_id ) ) {
			continue;
		}
		$w     = words(
			array(
				'uid'        => $user_id,
				'kind'       => $r->kind,
				'thread'     => $t,
				'message_id' => (int) $r->message_id,
				'actor'      => (int) $r->actor_id,
			)
		);
		$out[] = array(
			'id'       => (int) $r->id,
			'kind'     => $r->kind,
			'thread'   => (int) $r->thread_id,
			'message'  => (int) $r->message_id,
			'actor'    => (int) $r->actor_id,
			'created'  => Store\iso( $r->created ),
			'read'     => null !== $r->read_at,
			'headline' => $w['headline'],
			'topic'    => $w['topic'],
			'text'     => mb_substr( $w['text'], 0, 240 ),
			'channel'  => ( Store\channel( (int) $t->channel_id ) )->slug ?? '',
		);
	}
	return $out;
}

/**
 * Mark notifications as read.
 *
 * @param int        $user_id   User.
 * @param int[]|null $ids       Notification IDs (null = all).
 * @param int        $thread_id Or all of one thread.
 */
function mark_read( int $user_id, ?array $ids = null, int $thread_id = 0 ): void {
	global $wpdb;
	$where = $wpdb->prepare( 'user_id = %d AND read_at IS NULL', $user_id );
	if ( $thread_id ) {
		$where .= $wpdb->prepare( ' AND thread_id = %d', $thread_id );
	} elseif ( null !== $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$where .= ' AND id IN (' . implode( ',', $ids ) . ')';
	}
	$n = $wpdb->query( 'UPDATE ' . table( 'notifications' ) . " SET read_at = '" . esc_sql( Store\now() ) . "' WHERE $where" ); // phpcs:ignore WordPress.DB
	if ( $n ) {
		update_user_meta( $user_id, 'flurfunk_rev', (int) get_user_meta( $user_id, 'flurfunk_rev', true ) + 1 );
	}
}

/**
 * Unread notifications of a person.
 *
 * @param int $user_id User.
 */
function unread( int $user_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . table( 'notifications' ) . ' WHERE user_id = %d AND read_at IS NULL', $user_id ) ); // phpcs:ignore WordPress.DB
}

/**
 * Keep the notification list from growing forever: read ones go after 90 days, unread after 180
 * (filter flurfunk_notification_days). Runs with the daily cleanup.
 *
 * @return int Removed rows.
 */
function prune(): int {
	global $wpdb;
	$days = (array) apply_filters( 'flurfunk_notification_days', array( 'read' => 90, 'unread' => 180 ) );
	$n    = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . table( 'notifications' ) . ' WHERE read_at IS NOT NULL AND created < %s', gmdate( 'Y-m-d H:i:s', time() - (int) $days['read'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	$n   += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . table( 'notifications' ) . ' WHERE read_at IS NULL AND created < %s', gmdate( 'Y-m-d H:i:s', time() - (int) $days['unread'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	return $n;
}
add_action( 'flurfunk_cleanup_files', __NAMESPACE__ . '\\prune' );

/*
 * Reminders a person set on a thread ("remind me tomorrow"): checked every five minutes.
 */
add_filter(
	'cron_schedules',
	static function ( array $s ): array {
		$s['flurfunk_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Flurfunk: every 5 minutes',
		);
		return $s;
	}
);
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( REMIND ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'flurfunk_5min', REMIND );
		}
	}
);
add_action( REMIND, __NAMESPACE__ . '\\send_reminders' );

/**
 * Send the reminders that are due.
 *
 * @return int Sent reminders.
 */
function send_reminders(): int {
	global $wpdb;
	$r    = table( 'reads' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.*, r.user_id reminded, r.remind_at FROM $r r JOIN " . table( 'threads' ) . ' t ON t.id = r.thread_id WHERE r.remind_at IS NOT NULL AND r.remind_at <= %s ORDER BY r.remind_at LIMIT 200', Store\now() ) ); // phpcs:ignore WordPress.DB
	$n    = 0;
	foreach ( $rows as $row ) {
		// Claim it first, so two overlapping runs never send the same reminder twice.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $r SET remind_at = NULL WHERE user_id = %d AND thread_id = %d AND remind_at = %s", $row->reminded, $row->id, $row->remind_at ) ); // phpcs:ignore WordPress.DB
		if ( $claimed && Access\can_see( $row, (int) $row->reminded ) ) {
			dispatch( $row, 0, array( (int) $row->reminded => 'remind' ), 0 );
			++$n;
		}
	}
	deliver();
	return $n;
}

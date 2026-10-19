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

// Flurfunk's own tables have no WordPress API to go through. Every query below is prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

const PREFS    = 'flurfunk_prefs';
const PERSONAL = array( 'mention', 'assigned', 'due', 'remind', 'waiting', 'reply', 'done', 'channel' );
const PRIORITY = array( 'mention' => 6, 'assigned' => 5, 'due' => 4, 'remind' => 4, 'waiting' => 3, 'done' => 3, 'reply' => 2, 'channel' => 2, 'message' => 1 );
const KEYS     = array( 'theme', 'avatar', 'mail', 'push', 'quiet', 'badge', 'hour', 'channels', 'pause' );
const DIGEST   = 'flurfunk_digest';
const DUE      = 'flurfunk_due';
const REMIND   = 'flurfunk_remind';

/**
 * Preferences of a person.
 *
 * @param int $user_id User.
 */
function prefs( int $user_id ): array {
	// Each preference is its own meta entry (flurfunk_prefs_<key>), so two settings changed at the
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
		// Push paused until (ISO time), '' = not paused.
		'pause'  => ! empty( $p['pause'] ) && strtotime( $p['pause'] . ' UTC' ) > time() ? Store\iso( $p['pause'] ) : '',
		// Per channel (slug): all = every new thread and reply, mentions = only what concerns me.
		'channels' => array_filter( is_array( $p['channels'] ?? null ) ? $p['channels'] : array(), static fn ( $v, $k ) => ( is_string( $k ) || is_int( $k ) ) && in_array( $v, array( 'all', 'mentions' ), true ), ARRAY_FILTER_USE_BOTH ),
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
	if ( isset( $in['pause'] ) ) {
		// 1h | 3h | tomorrow | none.
		$map         = array( '1h' => 'hour', 'tomorrow' => 'tomorrow' );
		$in['pause'] = is_string( $in['pause'] ) ? $in['pause'] : '';
		$in['pause'] = '3h' === $in['pause'] ? gmdate( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ) : ( isset( $map[ $in['pause'] ] ) ? (string) Store\remind_time( $map[ $in['pause'] ], $user_id ) : '' );
	}
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
	$p = prefs( $user_id );
	if ( '' !== $p['pause'] ) {
		return true;
	}
	$q = $p['quiet'];
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
// A card action ("Ana approved: …") reaches the same people as a reply would.
add_action( 'flurfunk_card_action', static fn ( object $t, int $mid ) => event( $t, $mid ), 10, 2 );
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
		if ( 'done' !== $status && 'waiting' !== $status ) {
			return;
		}
		// Ticked off, or someone needs an answer: tell whoever started it and the responsible people.
		$to = array_fill_keys( array_merge( array( (int) $t->author_id ), Store\assignees( (int) $t->id ) ), $status );
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
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT type, body FROM %i WHERE id = %d', $m, $message_id ) );
		if ( ! $row || ( 'message' !== $row->type && ! is_card_action( $row ) ) ) {
			return;
		}
	}
	$mentioned    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE thread_id = %d AND message_id = %d', table( 'mentions' ), $thread->id, $message_id ) ) );
	$participants = $message_id ? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT author_id FROM %i WHERE thread_id = %d AND type = 'message'", $m, $thread->id ) ) ) : array();
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
	$to = (array) apply_filters( 'flurfunk_notify_recipients', $to, $thread, $message_id );
	// Whoever a filter adds must still be allowed to read the thread.
	$to = array_filter( $to, static fn ( $kind, $uid ) => Access\can_see( $thread, (int) $uid ), ARRAY_FILTER_USE_BOTH );
	dispatch( $thread, $message_id, $to, $sender );
}

/**
 * Is a message row the event line of a card action?
 *
 * @param object|null $row Message row (type, body).
 */
function is_card_action( $row ): bool {
	return $row && 'event' === $row->type && 'card_action' === ( json_decode( (string) $row->body, true )['event'] ?? '' );
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
		$wpdb->insert(
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
		// In the recipient's language, not the language of whoever caused it.
		$lang = get_user_locale( $n['uid'] );
		if ( wants( $pr['mail'], $n['kind'] ) && in_locale( $lang, static fn () => mail_one( $n ) ) ) {
			$sent[] = 'mail';
		}
		if ( wants( $pr['push'], $n['kind'] ) && ! is_quiet( $n['uid'] ) && \Flurfunk\Push\send( $n['uid'], in_locale( $lang, static fn () => payload( $n ) ) ) ) {
			$sent[] = 'push';
		}
		if ( $sent && $id ) {
			$wpdb->update( table( 'notifications' ), array( 'sent' => implode( ',', $sent ) ), array( 'id' => $id ) );
		}
	}
}

/**
 * Run something in a language: mails and pushes in the recipient's, an assistant's cards in the
 * conversation's.
 *
 * @param string   $locale Locale ('' = as it is).
 * @param callable $fn     Work.
 * @return mixed What $fn returns.
 */
function in_locale( string $locale, callable $fn ) {
	static $own = array(); // Locales loaded below, innermost last.
	$now = $own ? end( $own ) : determine_locale();
	if ( '' === $locale || $now === $locale ) {
		return $fn();
	}
	if ( ! $own && switch_to_locale( $locale ) ) {
		try {
			return $fn();
		} finally {
			restore_previous_locale();
		}
	}
	// The site does not have that language installed: Flurfunk brings its own translation, so at
	// least Flurfunk's texts follow.
	$load = static function ( string $l ): void {
		unload_textdomain( 'flurfunk', true );
		if ( 'en_US' !== $l ) {
			load_textdomain( 'flurfunk', FLURFUNK_DIR . 'languages/flurfunk-' . $l . '.mo', $l );
		}
	};
	if ( 'en_US' !== $locale && ! is_readable( FLURFUNK_DIR . 'languages/flurfunk-' . $locale . '.l10n.php' ) ) {
		return $fn();
	}
	$load( $locale );
	$own[] = $locale;
	try {
		return $fn();
	} finally {
		array_pop( $own );
		if ( $own ) {
			$load( end( $own ) );
		} else {
			unload_textdomain( 'flurfunk', true ); // WordPress loads the right one again when it is next needed.
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
	$row   = $n['message_id'] ? $wpdb->get_row( $wpdb->prepare( 'SELECT type, body FROM %i WHERE id = %d', table( 'messages' ), $n['message_id'] ) ) : null;
	$text  = $n['message_id'] ? (string) ( $row->body ?? '' ) : trim( $t->title . "\n\n" . $t->body );
	if ( is_card_action( $row ) ) {
		// "Ana approved" as the headline, the card's title as the text.
		$e = (array) json_decode( (string) $row->body, true );
		return array(
			'headline' => (string) ( $e['summary'] ?? $who ),
			'text'     => (string) ( $e['title'] ?? '' ),
			'topic'    => $topic,
		);
	}
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
		case 'waiting':
			/* translators: %s: person */
			$head = sprintf( __( '%s has a question about a task', 'flurfunk' ), $who );
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
	$subject = empty( $n['thread']->private_to )
		/* translators: 1: site/app name, 2: headline, 3: channel, 4: thread topic */
		? sprintf( __( '[%1$s] %2$s in #%3$s: %4$s', 'flurfunk' ), \Flurfunk\Settings\name(), $w['headline'], $channel ? $channel->slug : '', $w['topic'] )
		/* translators: 1: site/app name, 2: headline, 3: thread topic */
		: sprintf( __( '[%1$s] %2$s (private): %3$s', 'flurfunk' ), \Flurfunk\Settings\name(), $w['headline'], $w['topic'] );
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
 * Has this person's hour for daily mails and reminders come today?
 *
 * @param int $user_id User.
 */
function is_past_their_hour( int $user_id ): bool {
	return (int) wp_date( 'G' ) >= prefs( $user_id )['hour'];
}

/**
 * Is it exactly this person's hour?
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
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT t.*, a.user_id assignee FROM %i t JOIN %i a ON a.thread_id = t.id WHERE t.status IN ( 'open', 'doing', 'waiting' ) AND t.due_date IS NOT NULL AND t.due_date <= %s", table( 'threads' ), table( 'assignees' ), $today ) );
	$n     = 0;
	foreach ( $rows as $r ) {
		$done = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE kind = 'due' AND thread_id = %d AND user_id = %d AND created >= %s", table( 'notifications' ), $r->id, $r->assignee, get_gmt_from_date( $today . ' 00:00:00' ) ) );
		if ( ! $done && ( $force || is_past_their_hour( (int) $r->assignee ) ) && Access\can_see( $r, (int) $r->assignee ) ) {
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
		// Once a day from their hour on, so a run WP-Cron missed is caught up by the next one.
		if ( 'digest' !== prefs( $uid )['mail'] || ( ! $force && ( ! is_past_their_hour( $uid ) || wp_date( 'Y-m-d' ) === get_user_meta( $uid, 'flurfunk_digest_day', true ) ) ) ) {
			continue;
		}
		update_user_meta( $uid, 'flurfunk_digest_day', wp_date( 'Y-m-d' ) );
		$since = (string) get_user_meta( $uid, 'flurfunk_digest_last', true ) ?: gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// Only threads they can still read (a channel may have gained a capability since).
		$where = Access\where( $uid );
		$rows  = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is made of $wpdb->prepare() pieces (Access\where()).
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is made of $wpdb->prepare() pieces (Access\where()).
				"SELECT n.*, t.title, t.body, t.channel_id FROM %i n JOIN %i t ON t.id = n.thread_id WHERE n.user_id = %d AND n.read_at IS NULL AND n.created > %s AND {$where} ORDER BY n.id",
				table( 'notifications' ),
				table( 'threads' ),
				$uid,
				$since
			)
		);
		update_user_meta( $uid, 'flurfunk_digest_last', Store\now() );
		if ( ! $rows ) {
			continue;
		}
		$user = get_userdata( $uid );
		list( $subject, $body ) = in_locale(
			get_user_locale( $uid ),
			static function () use ( $rows, $uid ): array {
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
				return array( $subject, $body );
			}
		);
		$mail = apply_filters( 'flurfunk_mail', compact( 'subject', 'body' ), $user, null, 0 );
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
	$limit = max( 1, min( 100, $limit ) );
	$rows  = $before
		? $wpdb->get_results( $wpdb->prepare( 'SELECT n.* FROM %i n WHERE n.user_id = %d AND n.id < %d ORDER BY n.id DESC LIMIT %d', table( 'notifications' ), $user_id, $before, $limit ) )
		: $wpdb->get_results( $wpdb->prepare( 'SELECT n.* FROM %i n WHERE n.user_id = %d ORDER BY n.id DESC LIMIT %d', table( 'notifications' ), $user_id, $limit ) );
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
			// The title already shows as topic; the text continues from there, shortened with an ellipsis.
			'text'     => mb_strimwidth( '' !== $w['topic'] && str_starts_with( $w['text'], $w['topic'] ) ? ltrim( substr( $w['text'], strlen( $w['topic'] ) ) ) : $w['text'], 0, 240, '…' ),
			'channel'  => empty( $t->private_to ) ? ( ( Store\channel( (int) $t->channel_id ) )->slug ?? '' ) : '',
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
	$table = table( 'notifications' );
	if ( $thread_id ) {
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND thread_id = %d', $table, Store\now(), $user_id, $thread_id ) );
	} elseif ( null !== $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $table, Store\now(), $user_id ), $ids ) ) );
	} else {
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL', $table, Store\now(), $user_id ) );
	}
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
	// Like the list: only notifications about threads they can (still) read.
	$where = Access\where( $user_id );
	return (int) $wpdb->get_var( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is made of $wpdb->prepare() pieces (Access\where()).
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is made of $wpdb->prepare() pieces (Access\where()).
			"SELECT COUNT(*) FROM %i n JOIN %i t ON t.id = n.thread_id WHERE n.user_id = %d AND n.read_at IS NULL AND {$where}",
			table( 'notifications' ),
			table( 'threads' ),
			$user_id
		)
	);
}

/**
 * Keep the notification list from growing forever: read ones go after 90 days, unread after 180
 * (filter flurfunk_notification_days). Runs with the daily cleanup.
 *
 * @return int Removed rows.
 */
function prune(): int {
	global $wpdb;
	$days = wp_parse_args( (array) apply_filters( 'flurfunk_notification_days', array( 'read' => 90, 'unread' => 180 ) ), array( 'read' => 90, 'unread' => 180 ) );
	$days = array_map( static fn ( $d ) => max( 1, (int) $d ), $days );
	$n    = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE read_at IS NOT NULL AND created < %s', table( 'notifications' ), gmdate( 'Y-m-d H:i:s', time() - (int) $days['read'] * DAY_IN_SECONDS ) ) );
	$n   += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE read_at IS NULL AND created < %s', table( 'notifications' ), gmdate( 'Y-m-d H:i:s', time() - (int) $days['unread'] * DAY_IN_SECONDS ) ) );
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
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, r.user_id reminded, r.remind_at FROM %i r JOIN %i t ON t.id = r.thread_id WHERE r.remind_at IS NOT NULL AND r.remind_at <= %s ORDER BY r.remind_at LIMIT 200', $r, table( 'threads' ), Store\now() ) );
	$n    = 0;
	foreach ( $rows as $row ) {
		if ( is_quiet( (int) $row->reminded ) ) {
			continue; // Quiet hours or paused: it fires once they end.
		}
		// Claim it first, so two overlapping runs never send the same reminder twice.
		$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET remind_at = NULL WHERE user_id = %d AND thread_id = %d AND remind_at = %s', $r, $row->reminded, $row->id, $row->remind_at ) );
		if ( $claimed && Access\can_see( $row, (int) $row->reminded ) ) {
			dispatch( $row, 0, array( (int) $row->reminded => 'remind' ), 0 );
			++$n;
		}
	}
	deliver();
	return $n;
}

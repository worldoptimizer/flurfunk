<?php
/**
 * Personal preferences and email notifications.
 *
 * Preferences (one user meta entry per person, "flurfunk_prefs"):
 *   theme  light (default) | dark | system
 *   avatar photo (profile picture or Gravatar, default) | initials
 *   mail   mentions (mentions and replies in my threads) | all (everything I may see) | none
 *   badge  show the unread number on the menu/dock icon
 *
 * Mails go out at the end of the request. Adjust with the filters flurfunk_notify_recipients
 * and flurfunk_mail (return false to skip a mail).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Notify;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const PREFS = 'flurfunk_prefs';

/**
 * Preferences of a person.
 *
 * @param int $user_id User.
 */
function prefs( int $user_id ): array {
	$p = (array) get_user_meta( $user_id, PREFS, true );
	return array(
		'theme'  => in_array( $p['theme'] ?? '', array( 'system', 'light', 'dark' ), true ) ? $p['theme'] : 'light',
		'avatar' => in_array( $p['avatar'] ?? '', array( 'photo', 'initials' ), true ) ? $p['avatar'] : 'photo',
		'mail'   => in_array( $p['mail'] ?? '', array( 'mentions', 'all', 'none' ), true ) ? $p['mail'] : 'mentions',
		'badge'  => isset( $p['badge'] ) ? (bool) $p['badge'] : true,
	);
}

/**
 * Store preferences.
 *
 * @param int   $user_id User.
 * @param array $in      Changes.
 */
function save_prefs( int $user_id, array $in ): array {
	$p = array_merge( prefs( $user_id ), array_intersect_key( $in, array_flip( array( 'theme', 'mail', 'badge', 'avatar' ) ) ) );
	update_user_meta( $user_id, PREFS, $p );
	return prefs( $user_id );
}

/**
 * Queue of mails for this request.
 *
 * @return array[]
 */
function &queue(): array {
	static $q = array();
	return $q;
}

add_action(
	'flurfunk_thread_created',
	static function ( object $thread ): void {
		collect( $thread, 0 );
	}
);
add_action(
	'flurfunk_message_added',
	static function ( object $thread, int $message_id ): void {
		collect( $thread, $message_id );
	},
	10,
	2
);

/**
 * Work out who gets a mail about a new thread or message.
 *
 * @param object $thread     Thread row.
 * @param int    $message_id Message (0 = the thread itself).
 */
function collect( object $thread, int $message_id ): void {
	global $wpdb;
	$m      = \Flurfunk\Schema\table( 'messages' );
	$sender = get_current_user_id();
	if ( $message_id ) {
		$msg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $m WHERE id = %d", $message_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $msg || 'message' !== $msg->type ) {
			return;
		}
		$text = $msg->body;
	} else {
		$text = trim( $thread->title . "\n\n" . $thread->body );
	}
	$mentioned    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . \Flurfunk\Schema\table( 'mentions' ) . ' WHERE thread_id = %d AND message_id = %d', $thread->id, $message_id ) ) ); // phpcs:ignore WordPress.DB
	$participants = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT author_id FROM $m WHERE thread_id = %d AND type = 'message'", $thread->id ) ) ); // phpcs:ignore WordPress.DB
	$participants[] = (int) $thread->author_id;

	$to = array();
	foreach ( Store\people() as $p ) {
		$uid = (int) $p['id'];
		if ( $uid === $sender || ! Access\can_see( $thread, $uid ) ) {
			continue;
		}
		$mode = prefs( $uid )['mail'];
		if ( 'all' === $mode || ( 'mentions' === $mode && ( in_array( $uid, $mentioned, true ) || in_array( $uid, $participants, true ) ) ) ) {
			$to[ $uid ] = in_array( $uid, $mentioned, true ) ? 'mention' : 'reply';
		}
	}
	$to = (array) apply_filters( 'flurfunk_notify_recipients', $to, $thread, $message_id );
	if ( ! $to ) {
		return;
	}
	$q   = &queue();
	$q[] = array( $thread, $message_id, $sender, $text, $to );
	if ( 1 === count( $q ) ) {
		add_action( 'shutdown', __NAMESPACE__ . '\\send' );
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
 * Send the queued mails.
 */
function send(): void {
	$site = \Flurfunk\Settings\name();
	foreach ( queue() as list( $thread, $message_id, $sender, $text, $to ) ) {
		$channel = Store\channel( (int) $thread->channel_id );
		$from    = Store\person( (int) $sender )['name'];
		$topic   = '' !== $thread->title ? $thread->title : wp_trim_words( readable( $thread->body ), 8, '…' );
		$link    = \Flurfunk\Admin\url( 'thread:' . $thread->id );
		foreach ( $to as $uid => $why ) {
			$user = get_userdata( (int) $uid );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}
			/* translators: 1: site/app name, 2: sender, 3: channel, 4: thread topic */
			$subject = sprintf( __( '[%1$s] %2$s in #%3$s: %4$s', 'flurfunk' ), $site, $from, $channel ? $channel->slug : '', $topic );
			$body    = ( 'mention' === $why
				/* translators: %s: sender */
				? sprintf( __( '%s mentioned you:', 'flurfunk' ), $from )
				/* translators: %s: sender */
				: sprintf( __( '%s wrote:', 'flurfunk' ), $from ) )
				. "\n\n" . readable( $text ) . "\n\n"
				/* translators: %s: link */
				. sprintf( __( 'Reply in Flurfunk: %s', 'flurfunk' ), $link ) . "\n\n"
				. __( 'You can change which mails you get under your name at the bottom of the Flurfunk sidebar.', 'flurfunk' );
			$mail = apply_filters( 'flurfunk_mail', compact( 'subject', 'body' ), $user, $thread, $message_id );
			if ( $mail ) {
				wp_mail( $user->user_email, $mail['subject'], $mail['body'] );
			}
		}
	}
}

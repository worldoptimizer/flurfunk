<?php
/**
 * Personal data: WordPress's export and erase tools (Tools → Export/Erase Personal Data) include
 * Flurfunk, and deleting a user cleans up what only concerned them.
 *
 * Erasing keeps conversations readable for everyone else: the person's messages are replaced by
 * a "deleted" note, their threads keep the title but lose the text, and they no longer appear as
 * author.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Privacy;

use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

// Flurfunk's own tables have no WordPress API to go through. Every query below is prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

const PAGE = 100;

add_filter(
	'wp_privacy_personal_data_exporters',
	static function ( array $exporters ): array {
		$exporters['flurfunk'] = array(
			'exporter_friendly_name' => __( 'Flurfunk conversations', 'flurfunk' ),
			'callback'               => __NAMESPACE__ . '\\export',
		);
		return $exporters;
	}
);
add_filter(
	'wp_privacy_personal_data_erasers',
	static function ( array $erasers ): array {
		$erasers['flurfunk'] = array(
			'eraser_friendly_name' => __( 'Flurfunk conversations', 'flurfunk' ),
			'callback'             => __NAMESPACE__ . '\\erase',
		);
		return $erasers;
	}
);

/**
 * Export a person's threads and messages.
 *
 * @param string $email Email address.
 * @param int    $page  Page (1-based).
 */
function export( string $email, int $page = 1 ): array {
	global $wpdb;
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return array( 'data' => array(), 'done' => true );
	}
	$offset = ( $page - 1 ) * PAGE;
	$rows   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT 'thread' kind, t.id, t.id thread_id, t.title, t.body, t.created FROM %i t WHERE t.author_id = %d
			UNION ALL
			SELECT 'message' kind, m.id, m.thread_id, '' title, m.body, m.created FROM %i m WHERE m.author_id = %d AND m.type = 'message'
			UNION ALL
			SELECT 'reaction' kind, x.message_id id, x.thread_id, '' title, x.emoji body, x.created FROM %i x WHERE x.user_id = %d
			ORDER BY created LIMIT %d OFFSET %d",
			table( 'threads' ),
			$user->ID,
			table( 'messages' ),
			$user->ID,
			table( 'reactions' ),
			$user->ID,
			PAGE,
			$offset
		)
	);
	$data = array();
	foreach ( $rows as $r ) {
		$data[] = array(
			'group_id'    => 'flurfunk',
			'group_label' => __( 'Flurfunk conversations', 'flurfunk' ),
			'item_id'     => 'flurfunk-' . $r->kind . '-' . $r->id . ( 'reaction' === $r->kind ? '-' . $r->thread_id . '-' . $r->body : '' ),
			'data'        => array_values(
				array_filter(
					array(
						array( 'name' => __( 'Type', 'flurfunk' ), 'value' => 'thread' === $r->kind ? __( 'Thread', 'flurfunk' ) : ( 'reaction' === $r->kind ? __( 'Reaction', 'flurfunk' ) : __( 'Message', 'flurfunk' ) ) ),
						array( 'name' => __( 'Thread', 'flurfunk' ), 'value' => (string) $r->thread_id ),
						'' !== $r->title ? array( 'name' => __( 'Title', 'flurfunk' ), 'value' => $r->title ) : null,
						array( 'name' => __( 'Text', 'flurfunk' ), 'value' => 'reaction' === $r->kind ? \Flurfunk\Reactions\char( (string) $r->body ) : \Flurfunk\Notify\readable( (string) $r->body ) ),
						array( 'name' => __( 'Date', 'flurfunk' ), 'value' => (string) \Flurfunk\Store\iso( $r->created ) ),
					)
				)
			),
		);
	}
	return array(
		'data' => $data,
		'done' => count( $rows ) < PAGE,
	);
}

/**
 * Erase (anonymise) a person's contributions.
 *
 * @param string $email Email address.
 */
function erase( string $email ): array {
	global $wpdb;
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
	$uid = (int) $user->ID;
	$m   = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET type = 'deleted', body = '', files = '[]', author_id = 0 WHERE author_id = %d", table( 'messages' ), $uid ) );
	$t   = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET body = %s, author_id = 0 WHERE author_id = %d', table( 'threads' ), __( '(removed on request)', 'flurfunk' ), $uid ) );
	forget( $uid );
	\Flurfunk\Store\bump();
	return array(
		'items_removed'  => $m + $t > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/**
 * Remove what only concerned this person: read state, mentions, preferences.
 *
 * @param int $uid User.
 */
function forget( int $uid ): void {
	global $wpdb;
	$wpdb->delete( table( 'reads' ), array( 'user_id' => $uid ) );
	$wpdb->delete( table( 'mentions' ), array( 'user_id' => $uid ) );
	$wpdb->delete( table( 'assignees' ), array( 'user_id' => $uid ) );
	$wpdb->delete( table( 'notifications' ), array( 'user_id' => $uid ) );
	$wpdb->delete( table( 'push' ), array( 'user_id' => $uid ) );
	$wpdb->delete( table( 'reactions' ), array( 'user_id' => $uid ) );
	delete_user_meta( $uid, \Flurfunk\Notify\PREFS );
	foreach ( \Flurfunk\Notify\KEYS as $k ) {
		delete_user_meta( $uid, \Flurfunk\Notify\PREFS . '_' . $k );
	}
}

// Deleting a user: hand their contributions to the person who receives their posts, if any.
add_action(
	'deleted_user',
	static function ( int $id, $reassign ): void {
		global $wpdb;
		if ( $reassign ) {
			$wpdb->update( table( 'threads' ), array( 'author_id' => (int) $reassign ), array( 'author_id' => $id ) );
			$wpdb->update( table( 'messages' ), array( 'author_id' => (int) $reassign ), array( 'author_id' => $id ) );
		}
		forget( $id );
		\Flurfunk\Store\bump();
	},
	10,
	2
);

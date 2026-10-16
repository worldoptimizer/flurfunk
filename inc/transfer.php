<?php
/**
 * Export and import as JSON ("flurfunk/1") – for backups, moving sites, or moving to another
 * chat tool later. People are matched by email address on import.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Transfer;

use Flurfunk\Store;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

/**
 * Everything as one array.
 */
function export(): array {
	global $wpdb;
	$people = array();
	$add    = static function ( int $id ) use ( &$people ): void {
		if ( $id && ! isset( $people[ $id ] ) ) {
			$u             = get_userdata( $id );
			$people[ $id ] = array(
				'id'    => $id,
				'name'  => $u ? html_entity_decode( $u->display_name, ENT_QUOTES, 'UTF-8' ) : '',
				'email' => $u ? $u->user_email : '',
				'kind'  => \Flurfunk\Access\kind( $id ),
			);
		}
	};
	$rows  = (array) $wpdb->get_results( 'SELECT * FROM ' . table( 'threads' ) . ' ORDER BY id' ); // phpcs:ignore WordPress.DB
	$ids   = array_map( static fn ( $r ) => (int) $r->id, $rows );
	$atts  = Store\attachments_for( $ids );
	$ments = Store\mentions_for( $ids );
	$reads = array();
	foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . table( 'reads' ) ) as $r ) { // phpcs:ignore WordPress.DB
		$reads[ (int) $r->thread_id ][ (int) $r->user_id ] = (int) $r->last_message_id;
	}
	$threads = array();
	foreach ( $rows as $r ) {
		$t             = Store\format_thread( $r, $atts, $ments );
		$t['messages'] = Store\format_messages( $r, $atts, $ments );
		$t['read']     = (object) ( $reads[ (int) $r->id ] ?? array() );
		$add( (int) $r->author_id );
		$add( (int) $r->resolved_by );
		foreach ( $t['messages'] as $m ) {
			$add( (int) $m['author'] );
		}
		$threads[] = $t;
	}
	return array(
		'format'   => 'flurfunk/1',
		'exported' => gmdate( 'c' ),
		'site'     => home_url( '/' ),
		'channels' => array_map( static fn ( $c ) => Store\format_channel( $c ) + array( 'position' => (int) $c->position, 'archived' => (bool) $c->archived ), Store\channels( true ) ),
		'people'   => array_values( $people ),
		'threads'  => $threads,
	);
}

/**
 * Import. Threads already imported (same source site and ID) are skipped.
 *
 * @param array $data Export.
 * @return array{threads:int, messages:int, skipped:int}
 */
function import( array $data ): array {
	if ( 'flurfunk/1' !== ( $data['format'] ?? '' ) ) {
		return array( 'threads' => 0, 'messages' => 0, 'skipped' => 0, 'error' => 'format' );
	}
	$map = array();
	foreach ( (array) ( $data['people'] ?? array() ) as $p ) {
		$u                         = ! empty( $p['email'] ) ? get_user_by( 'email', $p['email'] ) : false;
		$map[ (int) ( $p['id'] ?? 0 ) ] = $u ? (int) $u->ID : 0;
	}
	$user = static fn ( $id ): int => $map[ (int) $id ] ?? 0;
	$text = static fn ( string $s ): string => (string) preg_replace_callback( '/<@(\d+)>/', static fn ( $m ) => $user( $m[1] ) ? '<@' . $user( $m[1] ) . '>' : '@?', $s );
	foreach ( (array) ( $data['channels'] ?? array() ) as $c ) {
		Store\ensure_channel(
			(string) $c['id'],
			array(
				'name'          => $c['name'] ?? $c['id'],
				'topic'         => $c['topic'] ?? '',
				'access_team'   => $c['access']['team'] ?? 'all',
				'access_guests' => $c['access']['guests'] ?? 'none',
				'position'      => $c['position'] ?? 100,
				'archived'      => ! empty( $c['archived'] ) ? 1 : 0,
			)
		);
	}
	$source = md5( (string) ( $data['site'] ?? '' ) );
	$count  = array( 'threads' => 0, 'messages' => 0, 'skipped' => 0 );
	foreach ( (array) ( $data['threads'] ?? array() ) as $t ) {
		$ref = (string) ( $t['ref_key'] ?? '' ) ?: 'import:' . substr( $source, 0, 8 ) . ':' . (int) $t['id'];
		if ( Store\thread_by_ref( $ref ) ) {
			++$count['skipped'];
			continue;
		}
		$id = Store\create_thread(
			array(
				'channel'     => (string) $t['channel'],
				'target'      => $t['target'] ?? null,
				'title'       => (string) ( $t['title'] ?? '' ),
				'body'        => $text( (string) ( $t['body'] ?? '' ) ),
				'status'      => (string) ( $t['status'] ?? '' ),
				'resolved'    => isset( $t['resolved']['by'] ) ? array( 'by' => $user( $t['resolved']['by'] ), 'at' => $t['resolved']['at'] ) : null,
				'author'      => $user( $t['author'] ?? 0 ),
				'created'     => $t['created'] ?? null,
				'mentions'    => array_filter( array_map( $user, (array) ( $t['mentions'] ?? array() ) ) ),
				'attachments' => (array) ( $t['attachments'] ?? array() ),
				'ref_key'     => $ref,
			),
			false
		);
		if ( is_wp_error( $id ) ) {
			++$count['skipped'];
			continue;
		}
		++$count['threads'];
		foreach ( (array) ( $t['messages'] ?? array() ) as $m ) {
			Store\add_message(
				$id,
				array(
					'type'        => $m['type'] ?? 'message',
					'status'      => $m['status'] ?? '',
					'body'        => $text( (string) ( $m['text'] ?? '' ) ),
					// Media IDs belong to the source site: keep name, type and a link to the original.
					'files'       => array_map(
						static fn ( $f ) => array(
							'id'   => 0,
							'name' => (string) ( $f['name'] ?? '' ),
							'size' => (int) ( $f['size'] ?? 0 ),
							'type' => (string) ( $f['type'] ?? '' ),
							'url'  => esc_url_raw( (string) ( $f['url'] ?? '' ) ),
						),
						(array) ( $m['files'] ?? array() )
					),
					'attachments' => (array) ( $m['attachments'] ?? array() ),
					'mentions'    => array_filter( array_map( $user, (array) ( $m['mentions'] ?? array() ) ) ),
					'author'      => $user( $m['author'] ?? 0 ),
					'created'     => $m['created'] ?? null,
					'edited'      => $m['edited'] ?? null,
				),
				false
			);
			++$count['messages'];
		}
		// Everything imported counts as read, so nobody gets a wall of "new".
		foreach ( array_filter( $map ) as $uid ) {
			Store\mark_read( $id, $uid );
		}
	}
	return $count;
}

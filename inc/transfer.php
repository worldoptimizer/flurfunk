<?php
/**
 * Export and import as JSON ("flurfunk/1") – for backups, moving sites, or moving to another
 * chat tool later. People are matched by email address on import.
 *
 * With a bundle folder, files travel along: the export copies them to <folder>/files/ and notes
 * the path; the import stores them again as Flurfunk files on the new site (and adds them to the
 * media library if they were there before). Without a folder, files stay links to the old site.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Transfer;

use Flurfunk\Store;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

/**
 * Everything as one array.
 *
 * @param string $bundle Folder to copy files into ('' = links only).
 */
function export( string $bundle = '' ): array {
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
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own tables, read once for the export.
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', table( 'threads' ) ) );
	$ids   = array_map( static fn ( $r ) => (int) $r->id, $rows );
	$atts  = Store\attachments_for( $ids );
	$ments = Store\mentions_for( $ids );
	$reads = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', table( 'reads' ) ) ) as $r ) {
		$reads[ (int) $r->thread_id ][ (int) $r->user_id ] = (int) $r->last_message_id;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$threads = array();
	foreach ( $rows as $r ) {
		$t             = Store\format_thread( $r, $atts, $ments );
		$t['messages'] = Store\format_messages( $r, $atts, $ments );
		if ( '' !== $bundle ) {
			$t['messages'][] = array( 'files' => $t['files'] ); // The thread's own files, copied along below.
			foreach ( $t['messages'] as &$msg ) {
				foreach ( $msg['files'] ?? array() as $i => $f ) {
					$src = ! empty( $f['id'] ) ? get_attached_file( (int) $f['id'] ) : '';
					if ( $src && is_readable( $src ) ) {
						$rel = 'files/' . (int) $f['id'] . '-' . wp_basename( $src );
						wp_mkdir_p( $bundle . '/files' );
						if ( ! file_exists( $bundle . '/' . $rel ) ) {
							copy( $src, $bundle . '/' . $rel );
						}
						$msg['files'][ $i ]['bundle'] = $rel;
					}
				}
			}
			unset( $msg );
			$t['files'] = array_pop( $t['messages'] )['files'];
		}
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
 * @param array  $data   Export.
 * @param string $bundle Folder the export's files were copied to ('' = keep links).
 * @return array{threads:int, messages:int, skipped:int, files:int}
 */
function import( array $data, string $bundle = '' ): array {
	$stored = array();
	$file   = static function ( array $f ) use ( $bundle, &$stored ): array {
		$plain = array(
			'id'   => 0,
			'name' => (string) ( $f['name'] ?? '' ),
			'size' => (int) ( $f['size'] ?? 0 ),
			'type' => (string) ( $f['type'] ?? '' ),
			'url'  => esc_url_raw( (string) ( $f['url'] ?? '' ) ),
		);
		$rel = (string) ( $f['bundle'] ?? '' );
		if ( '' === $bundle || '' === $rel || str_contains( $rel, '..' ) || ! is_readable( $bundle . '/' . $rel ) ) {
			return $plain;
		}
		if ( ! isset( $stored[ $rel ] ) ) {
			$id = \Flurfunk\Files\store( $plain['name'] ?: wp_basename( $rel ), (string) file_get_contents( $bundle . '/' . $rel ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file from the unpacked export.
			if ( is_wp_error( $id ) ) {
				return $plain;
			}
			if ( ! empty( $f['title'] ) ) {
				wp_update_post( array( 'ID' => $id, 'post_title' => (string) $f['title'] ) );
			}
			if ( ! empty( $f['library'] ) ) {
				delete_post_meta( $id, \Flurfunk\Files\META );
			}
			$stored[ $rel ] = $id;
		}
		return Store\files( array( $stored[ $rel ] ), get_current_user_id(), false )[0] ?? $plain;
	};
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
				'capability'    => (string) ( $c['capability'] ?? '' ),
				'position'      => $c['position'] ?? 100,
				'archived'      => ! empty( $c['archived'] ) ? 1 : 0,
			)
		);
	}
	$source = md5( (string) ( $data['site'] ?? '' ) );
	$count  = array( 'threads' => 0, 'messages' => 0, 'skipped' => 0, 'files' => 0 );
	foreach ( (array) ( $data['threads'] ?? array() ) as $t ) {
		$ref = (string) ( $t['ref_key'] ?? '' ) ?: 'import:' . substr( $source, 0, 8 ) . ':' . (int) $t['id'];
		// The same site importing its own export (a restore): threads that still exist stay as they are.
		$same = $source === md5( home_url( '/' ) ) ? Store\thread_row( (int) $t['id'] ) : null;
		if ( Store\thread_by_ref( $ref ) || ( $same && Store\iso( $same->created ) === ( $t['created'] ?? '' ) ) ) {
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
				'files'       => array_map( $file, (array) ( $t['files'] ?? array() ) ),
				'due'         => (string) ( $t['due'] ?? '' ),
				'assignees'   => array_values( array_filter( array_map( $user, (array) ( $t['assignees'] ?? array() ) ) ) ),
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
					'event'       => 'event' === ( $m['type'] ?? '' ) ? array(
						'event'   => $m['event'] ?? '',
						'due'     => $m['due'] ?? null,
						'added'   => array_values( array_filter( array_map( $user, (array) ( $m['added'] ?? array() ) ) ) ),
						'removed' => array_values( array_filter( array_map( $user, (array) ( $m['removed'] ?? array() ) ) ) ),
					) + ( 'card_action' === ( $m['event'] ?? '' ) ? array_map( 'sanitize_text_field', array_intersect_key( $m, array_flip( array( 'provider', 'action', 'label', 'summary', 'title' ) ) ) ) : array() ) : null,
					'body'        => $text( (string) ( $m['text'] ?? '' ) ),
					// Media IDs belong to the source site: files from a bundle are stored again,
					// otherwise name, type and a link to the original remain.
					'files'       => array_map( $file, (array) ( $m['files'] ?? array() ) ),
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
	$count['files'] = count( $stored );
	return $count;
}

<?php
/**
 * Flurfunk files: uploads that belong to a conversation, not (yet) to the media library.
 *
 * They are ordinary WordPress attachments (thumbnails, type checks, players keep working), stored
 * under uploads/flurfunk/<year>/<month>/<random>/ and marked with the meta key below. The media
 * library hides marked attachments. "Add to media library" removes the mark – the file stays
 * where it is and keeps its address, so every message, card or post using it keeps working.
 * Marked files nothing refers to any more are removed after 30 days.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Files;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const META     = '_flurfunk_file';
const DIR      = 'flurfunk';
const KEEP     = 30 * DAY_IN_SECONDS;
const CRON     = 'flurfunk_cleanup_files';

/**
 * Is this attachment a Flurfunk file (hidden from the media library)?
 *
 * @param int $id Attachment.
 */
function is_hidden( int $id ): bool {
	return (bool) get_post_meta( $id, META, true );
}

/**
 * Store an upload as a Flurfunk file.
 *
 * @param string $name Original file name.
 * @param string $data File contents.
 * @return int|\WP_Error Attachment ID.
 */
function store( string $name, string $data ) {
	if ( ! current_user_can( 'upload_files' ) && ! Access\is_bot( get_current_user_id() ) ) {
		return new \WP_Error( 'flurfunk_upload', __( 'You cannot upload files.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( '' === $data ) {
		return new \WP_Error( 'flurfunk_upload', __( 'The file is empty.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	if ( strlen( $data ) > wp_max_upload_size() ) {
		/* translators: %s: size */
		return new \WP_Error( 'flurfunk_upload', sprintf( __( 'The file is larger than %s.', 'flurfunk' ), size_format( wp_max_upload_size() ) ), array( 'status' => 413 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$name = sanitize_file_name( wp_basename( $name ) ) ?: 'datei';
	$tmp  = wp_tempnam( $name );
	file_put_contents( $tmp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$dir = static function ( array $u ): array {
		$sub         = '/' . DIR . '/' . gmdate( 'Y/m' ) . '/' . strtolower( wp_generate_password( 12, false ) );
		$u['subdir'] = $sub;
		$u['path']   = $u['basedir'] . $sub;
		$u['url']    = $u['baseurl'] . $sub;
		return $u;
	};
	add_filter( 'upload_dir', $dir );
	$id = media_handle_sideload(
		array(
			'name'     => $name,
			'tmp_name' => $tmp,
		),
		0
	);
	remove_filter( 'upload_dir', $dir );
	if ( is_file( $tmp ) ) {
		wp_delete_file( $tmp );
	}
	if ( is_wp_error( $id ) ) {
		$id->add_data( array( 'status' => 400 ) );
		return $id;
	}
	update_post_meta( $id, META, time() );
	return (int) $id;
}

/**
 * Add a Flurfunk file to the media library (only removes the mark).
 *
 * @param int $id Attachment.
 * @return true|\WP_Error
 */
function adopt( int $id ) {
	$post = get_post( $id );
	if ( ! $post || 'attachment' !== $post->post_type || ! is_hidden( $id ) ) {
		return new \WP_Error( 'flurfunk_file', __( 'This file is not a Flurfunk file.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'upload_files' ) || ( 'team' !== Access\kind() && (int) $post->post_author !== get_current_user_id() ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot change this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	delete_post_meta( $id, META );
	\Flurfunk\Store\bump();
	return true;
}

/*
 * Keep marked files out of the media library: grid (Ajax), list mode and REST.
 */
add_filter(
	'ajax_query_attachments_args',
	static function ( array $q ): array {
		$q['meta_query']   = (array) ( $q['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$q['meta_query'][] = array(
			'key'     => META,
			'compare' => 'NOT EXISTS',
		);
		return $q;
	}
);
add_action(
	'pre_get_posts',
	static function ( \WP_Query $q ): void {
		global $pagenow;
		if ( is_admin() && $q->is_main_query() && 'upload.php' === $pagenow ) {
			$mq   = (array) $q->get( 'meta_query' );
			$mq[] = array(
				'key'     => META,
				'compare' => 'NOT EXISTS',
			);
			$q->set( 'meta_query', $mq );
		}
	}
);
add_filter(
	'rest_attachment_query',
	static function ( array $args ): array {
		$args['meta_query']   = (array) ( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_query'][] = array(
			'key'     => META,
			'compare' => 'NOT EXISTS',
		);
		return $args;
	}
);

/*
 * Clean up: marked files older than 30 days that no message refers to.
 */
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CRON );
		}
	}
);
add_action( CRON, __NAMESPACE__ . '\\cleanup' );

/**
 * Remove orphaned Flurfunk files.
 *
 * @param int $older Seconds a file must be old (default 30 days).
 * @return int Removed files.
 */
function cleanup( int $older = KEEP ): int {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- daily cron job; a numeric comparison on the meta value, and our own tables.
	$ids     = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d', $wpdb->postmeta, META, time() - $older ) );
	$removed = 0;
	foreach ( array_map( 'intval', $ids ) as $id ) {
		$like = array( '%"id":' . $id . ',%', '%"id":' . $id . '}%' );
		$used = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'message' AND ( files LIKE %s OR files LIKE %s )", \Flurfunk\Schema\table( 'messages' ), $like[0], $like[1] ) )
			|| $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE files LIKE %s OR files LIKE %s', \Flurfunk\Schema\table( 'threads' ), $like[0], $like[1] ) );
		if ( ! $used && wp_delete_attachment( $id, true ) ) {
			++$removed;
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $removed;
}

<?php
/**
 * Module "Comment cards": /comment inserts a comment as a card with its state and buttons to
 * approve it, mark it as spam or move it to the trash. Only WordPress core underneath.
 *
 * Who: the team (flurfunk_team) who may moderate comments (moderate_comments); each action
 * checks edit_comment for that comment again. The card shows the commenter's public name, the
 * post and an excerpt, never the email or IP address.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Comments;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const PROVIDER = 'flurfunk/comment';

add_action( 'init', __NAMESPACE__ . '\\register', 20 );

/**
 * Register the provider.
 */
function register(): void {
	flurfunk_register_provider(
		PROVIDER,
		array(
			'command'  => _x( 'comment', 'slash command', 'flurfunk' ),
			'label'    => __( 'Comment', 'flurfunk' ),
			'hint'     => __( 'A comment, with buttons to moderate it', 'flurfunk' ),
			'can'      => __NAMESPACE__ . '\\can',
			'search'   => __NAMESPACE__ . '\\search',
			'snapshot' => __NAMESPACE__ . '\\snapshot',
			'actions'  => array(
				'approve' => array(
					'label'      => __( 'Approve', 'flurfunk' ),
					/* translators: %s: person */
					'done'       => __( '%s approved the comment', 'flurfunk' ),
					'style'      => 'primary',
					'capability' => 'moderate_comments',
					'callback'   => static fn ( array $ref, array $card, int $user_id ) => moderate( $ref, $user_id, 'approve' ),
				),
				'spam'    => array(
					'label'      => __( 'Spam', 'flurfunk' ),
					/* translators: %s: person */
					'done'       => __( '%s marked the comment as spam', 'flurfunk' ),
					'style'      => 'secondary',
					'confirm'    => __( 'Mark this comment as spam?', 'flurfunk' ),
					'capability' => 'moderate_comments',
					'callback'   => static fn ( array $ref, array $card, int $user_id ) => moderate( $ref, $user_id, 'spam' ),
				),
				'trash'   => array(
					'label'      => __( 'Trash', 'flurfunk' ),
					/* translators: %s: person */
					'done'       => __( '%s moved the comment to the trash', 'flurfunk' ),
					'style'      => 'danger',
					'confirm'    => __( 'Move this comment to the trash?', 'flurfunk' ),
					'capability' => 'moderate_comments',
					'callback'   => static fn ( array $ref, array $card, int $user_id ) => moderate( $ref, $user_id, 'trash' ),
				),
			),
		)
	);
}

/**
 * May this person use /comment?
 *
 * @param int $user_id User.
 */
function can( int $user_id ): bool {
	return user_can( $user_id, 'moderate_comments' ); // On top of the provider's team capability.
}

/**
 * State of a comment: approve | hold | spam | trash.
 *
 * @param \WP_Comment $c Comment.
 */
function state( \WP_Comment $c ): string {
	$s = (string) $c->comment_approved;
	return '1' === $s ? 'approve' : ( '0' === $s ? 'hold' : ( in_array( $s, array( 'spam', 'trash' ), true ) ? $s : 'hold' ) );
}

/**
 * Status pill for a state.
 *
 * @param string $state approve|hold|spam|trash.
 */
function status( string $state ): array {
	$map = array(
		'hold'    => array( __( 'Pending', 'flurfunk' ), 'warning' ),
		'approve' => array( __( 'Approved', 'flurfunk' ), 'positive' ),
		'spam'    => array( __( 'Spam', 'flurfunk' ), 'critical' ),
		'trash'   => array( __( 'In the trash', 'flurfunk' ), 'neutral' ),
	);
	return array(
		'label' => $map[ $state ][0],
		'tone'  => $map[ $state ][1],
	);
}

/**
 * Comments matching a search: waiting ones first, then the newest others.
 *
 * @param string $q       Search text.
 * @param int    $user_id User.
 */
function search( string $q, int $user_id ): array {
	$args  = array(
		'number'  => 12,
		'search'  => $q,
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
		'type'    => 'comment',
	);
	$found = get_comments( $args + array( 'status' => 'hold' ) );
	if ( count( $found ) < 12 ) {
		$found = array_merge( $found, get_comments( array( 'number' => 12 - count( $found ), 'status' => 'approve' ) + $args ) );
	}
	$out = array();
	foreach ( $found as $c ) {
		if ( ! user_can( $user_id, 'edit_comment', (int) $c->comment_ID ) ) {
			continue;
		}
		$out[] = array(
			'ref'      => array( 'id' => (int) $c->comment_ID ),
			'title'    => wp_trim_words( wp_strip_all_tags( $c->comment_content ), 10, '…' ),
			/* translators: 1: commenter's name, 2: post title, 3: state */
			'subtitle' => sprintf( __( '%1$s on %2$s · %3$s', 'flurfunk' ), author( $c ), post_title( (int) $c->comment_post_ID ), status( state( $c ) )['label'] ),
		);
	}
	return $out;
}

/**
 * The commenter's public name.
 *
 * @param \WP_Comment $c Comment.
 */
function author( \WP_Comment $c ): string {
	return '' !== trim( (string) $c->comment_author ) ? (string) $c->comment_author : __( 'Anonymous', 'flurfunk' );
}

/**
 * Title of the post a comment belongs to.
 *
 * @param int $post_id Post.
 */
function post_title( int $post_id ): string {
	$t = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
	return '' !== $t ? $t : __( '(no title)', 'flurfunk' );
}

/**
 * Card for a comment.
 *
 * @param array $ref     { id }.
 * @param int   $user_id User.
 */
function snapshot( array $ref, int $user_id ): ?array {
	$c = get_comment( (int) ( $ref['id'] ?? 0 ) );
	if ( ! $c || ! user_can( $user_id, 'edit_comment', (int) $c->comment_ID ) ) {
		return null;
	}
	$state   = state( $c );
	$post_id = (int) $c->comment_post_ID;
	$link    = get_permalink( $post_id );
	$fields  = array(
		array(
			'label' => __( 'Post', 'flurfunk' ),
			'value' => post_title( $post_id ),
			'url'   => $link ? $link : '',
		),
		array(
			'label' => __( 'Written', 'flurfunk' ),
			'value' => wp_date( date_and_time_format(), strtotime( $c->comment_date_gmt . ' UTC' ) ),
		),
	);
	if ( $c->comment_parent ) {
		$parent   = get_comment( (int) $c->comment_parent );
		$fields[] = array(
			'label' => __( 'In reply to', 'flurfunk' ),
			'value' => $parent ? author( $parent ) : '–',
		);
	}
	$actions = array(
		'hold'    => array( 'approve', 'spam', 'trash' ),
		'approve' => array( 'spam', 'trash' ),
		'spam'    => array( 'approve', 'trash' ),
		'trash'   => array(),
	)[ $state ];
	$snap    = array(
		/* translators: %s: commenter's name */
		'title'   => sprintf( __( 'Comment by %s', 'flurfunk' ), author( $c ) ),
		'url'     => admin_url( 'comment.php?action=editcomment&c=' . (int) $c->comment_ID ),
		'status'  => status( $state ),
		'quote'   => wp_trim_words( wp_strip_all_tags( $c->comment_content ), 60, '…' ),
		'fields'  => $fields,
		'actions' => $actions,
	);
	if ( Access\can_target( 'post', $post_id, $user_id ) ) {
		$snap['target'] = array(
			'type' => 'post',
			'id'   => $post_id,
		);
	}
	return $snap;
}

/**
 * Approve, spam or trash a comment for a person and return the new card.
 *
 * @param array  $ref     { id }.
 * @param int    $user_id User.
 * @param string $to      approve|spam|trash.
 * @return array|\WP_Error
 */
function moderate( array $ref, int $user_id, string $to ) {
	$c = get_comment( (int) ( $ref['id'] ?? 0 ) );
	if ( ! $c ) {
		return new \WP_Error( 'flurfunk_comment', __( 'This comment no longer exists.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( ! user_can( $user_id, 'edit_comment', (int) $c->comment_ID ) ) {
		return new \WP_Error( 'flurfunk_comment', __( 'You cannot moderate this comment.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( state( $c ) !== $to && ! wp_set_comment_status( $c, $to ) ) {
		return new \WP_Error( 'flurfunk_comment', __( 'The comment could not be changed.', 'flurfunk' ) );
	}
	clean_comment_cache( (int) $c->comment_ID );
	return snapshot( $ref, $user_id ) ?? new \WP_Error( 'flurfunk_comment', __( 'This comment no longer exists.', 'flurfunk' ), array( 'status' => 404 ) );
}

/**
 * The site's date format, plus its time format unless the date format shows the time already.
 */
function date_and_time_format(): string {
	$date = (string) get_option( 'date_format' );
	return preg_match( '/(?<!\\\\)[aAgGhHi]/', $date ) ? $date : $date . ', ' . get_option( 'time_format' );
}


<?php
/**
 * Module "Review requests": when a post is submitted for review (status "pending"), a task
 * thread about it asks the people who may publish it to take a look. Submitting the same post
 * again reopens that thread; publishing it ticks the task off, moving it back to draft marks
 * it as "question". Works for the post types chosen under Settings → Flurfunk.
 *
 * Filters: flurfunk_review_channel (slug, default "general", per post),
 * flurfunk_review_assignees (user IDs, per post).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Review;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

add_action( 'transition_post_status', __NAMESPACE__ . '\\transition', 10, 3 );

/**
 * Thread reference for a post.
 *
 * @param int $post_id Post.
 */
function ref( int $post_id ): string {
	return 'flurfunk-review:' . $post_id;
}

/**
 * People who may publish this post (Flurfunk users, no assistants, not the author).
 *
 * @param \WP_Post $post Post.
 * @return int[]
 */
function reviewers( \WP_Post $post ): array {
	$type = get_post_type_object( $post->post_type );
	$cap  = $type ? (string) $type->cap->publish_posts : 'publish_posts';
	$ids  = array();
	foreach ( get_users( array( 'capability' => $cap, 'fields' => 'ID', 'number' => 100 ) ) as $uid ) {
		$uid = (int) $uid;
		if ( $uid !== (int) $post->post_author && ! Access\is_bot( $uid ) && Access\can_use( $uid ) && user_can( $uid, 'edit_post', $post->ID ) ) {
			$ids[] = $uid;
		}
	}
	return array_slice( array_map( 'intval', (array) apply_filters( 'flurfunk_review_assignees', $ids, $post ) ), 0, 20 );
}

/**
 * React to status changes.
 *
 * @param string   $new_status New status.
 * @param string   $old_status Old status.
 * @param \WP_Post $post       Post.
 */
function transition( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( $new_status === $old_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! in_array( $post->post_type, (array) \Flurfunk\Settings\get()['post_types'], true ) ) {
		return;
	}
	$ref   = ref( $post->ID );
	$found = Store\thread_by_ref( $ref );
	$who   = get_current_user_id() ? Store\person( get_current_user_id() )['name'] : __( 'Someone', 'flurfunk' );
	if ( 'pending' === $new_status ) {
		if ( $found ) {
			/* translators: %s: person */
			flurfunk_reply( $ref, sprintf( __( '%s submitted it for review again.', 'flurfunk' ), $who ) );
			flurfunk_set_status( $ref, 'open' );
			return;
		}
		$author = get_userdata( (int) $post->post_author );
		flurfunk_post(
			array(
				'channel'     => sanitize_key( (string) apply_filters( 'flurfunk_review_channel', 'general', $post ) ),
				/* translators: %s: post title */
				'title'       => sprintf( __( 'Review: %s', 'flurfunk' ), html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ?: __( '(no title)', 'flurfunk' ) ),
				/* translators: %s: person */
				'body'        => sprintf( __( '%s submitted this for review. Please take a look.', 'flurfunk' ), $author ? $author->display_name : $who ),
				'target'      => array(
					'type' => 'post',
					'id'   => $post->ID,
				),
				'task'        => true,
				'assignees'   => reviewers( $post ),
				'attachments' => array(
					array(
						'provider' => 'flurfunk/post',
						'ref'      => array( 'id' => $post->ID ),
					),
				),
				'ref_key'     => $ref,
			)
		);
		return;
	}
	if ( ! $found || ( 'pending' !== $old_status && 'publish' !== $new_status ) ) {
		return;
	}
	if ( 'publish' === $new_status || 'future' === $new_status ) {
		if ( 'done' !== $found->status ) {
			/* translators: %s: person */
			flurfunk_reply( $ref, sprintf( 'future' === $new_status ? __( '%s scheduled it.', 'flurfunk' ) : __( '%s published it.', 'flurfunk' ), $who ) );
			flurfunk_set_status( $ref, 'done' );
		}
	} elseif ( 'draft' === $new_status ) {
		/* translators: %s: person */
		flurfunk_reply( $ref, sprintf( __( '%s moved it back to draft.', 'flurfunk' ), $who ) );
		flurfunk_set_status( $ref, 'waiting' );
	}
}

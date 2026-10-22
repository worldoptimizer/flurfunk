<?php
/**
 * Module "Daily moderation thread": once a day it looks whether comments wait for approval. The
 * first time one thread lists them, with a card that always shows the current queue (author,
 * post, since when) and a link to the comments screen. Later only new comments come as a reply in
 * that thread – the same comments never post again; once none waits, the thread is ticked off.
 * If they wait for days, a reminder in the thread (setting "remind", default 7 days, 0 = never).
 *
 * The channel is the module's setting (Settings → Flurfunk → Modules), by default its own channel
 * #comments that only people with moderate_comments see (created when the module is switched on,
 * or on the first run). Filters: flurfunk_comments_channel (slug; overrides the setting),
 * flurfunk_comments_hour (0–23, site time, default 9).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\CommentsDaily;

use Flurfunk\Access;
use Flurfunk\Watch;

defined( 'ABSPATH' ) || exit;

const MODULE   = 'flurfunk/comments-daily';
const PROVIDER = 'flurfunk/comment-queue';
const HOOK     = 'flurfunk_comments_daily';
const STATE    = 'flurfunk_comments_watch'; // The open thread and the comments it knows.

add_action( 'init', __NAMESPACE__ . '\\register', 20 );
add_action( HOOK, __NAMESPACE__ . '\\run' );
add_action(
	'flurfunk_module_off',
	static function ( string $id ): void {
		if ( MODULE === $id ) {
			wp_clear_scheduled_hook( HOOK );
		}
	}
);

/**
 * Register the (hidden) provider for the summary card and schedule the daily run.
 */
function register(): void {
	flurfunk_register_provider(
		PROVIDER,
		array(
			'command'  => 'comment-queue',
			'label'    => __( 'Comments awaiting moderation', 'flurfunk' ),
			'hidden'   => true,
			// The assistant posts the card; people who may moderate can update it.
			'can'      => static fn ( int $user_id ) => Access\is_bot( $user_id ) || ( Access\can_use( $user_id ) && user_can( $user_id, 'moderate_comments' ) ),
			'snapshot' => __NAMESPACE__ . '\\snapshot',
		)
	);
	if ( ! wp_next_scheduled( HOOK ) ) {
		$hour = max( 0, min( 23, (int) apply_filters( 'flurfunk_comments_hour', 9 ) ) );
		$next = new \DateTime( 'today ' . $hour . ':00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', HOOK );
	}
}

/**
 * The summary card: number waiting, the oldest, and a table of up to ten.
 */
function snapshot(): array {
	$count = (int) get_comments(
		array(
			'status' => 'hold',
			'type'   => 'comment',
			'count'  => true,
		)
	);
	$url   = admin_url( 'edit-comments.php?comment_status=moderated' );
	if ( ! $count ) {
		return array(
			'title'  => __( 'Comments awaiting moderation', 'flurfunk' ),
			'url'    => $url,
			'status' => array(
				'label' => __( 'All done', 'flurfunk' ),
				'tone'  => 'positive',
			),
		);
	}
	$list = get_comments(
		array(
			'status'  => 'hold',
			'type'    => 'comment',
			'number'  => 10,
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		)
	);
	$date = static fn ( $c ) => wp_date( get_option( 'date_format' ), strtotime( $c->comment_date_gmt . ' UTC' ) );
	return array(
		/* translators: %s: number */
		'title'  => sprintf( _n( '%s comment awaiting moderation', '%s comments awaiting moderation', $count, 'flurfunk' ), number_format_i18n( $count ) ),
		'url'    => $url,
		'status' => array(
			'label' => __( 'Waiting', 'flurfunk' ),
			'tone'  => 'warning',
		),
		'fields' => array(
			array(
				'label' => __( 'Waiting', 'flurfunk' ),
				'value' => number_format_i18n( $count ),
			),
			array(
				'label' => __( 'Oldest from', 'flurfunk' ),
				'value' => $list ? $date( $list[0] ) : '–',
			),
		),
		'table'  => array(
			'columns' => array( __( 'From', 'flurfunk' ), __( 'Post', 'flurfunk' ), __( 'Since', 'flurfunk' ) ),
			'rows'    => array_map(
				static fn ( $c ) => array(
					'' !== trim( (string) $c->comment_author ) ? (string) $c->comment_author : __( 'Anonymous', 'flurfunk' ),
					html_entity_decode( get_the_title( (int) $c->comment_post_ID ), ENT_QUOTES, 'UTF-8' ),
					$date( $c ),
				),
				$list
			),
		),
		'action' => array(
			'label' => __( 'Moderate comments', 'flurfunk' ),
			'url'   => $url,
		),
	);
}

/**
 * Look at the queue: a thread the first time, a reply for new comments, ticked off when none
 * waits. Returns the thread ID (0 = nothing waiting).
 */
function run(): int {
	$items = array();
	foreach ( get_comments(
		array(
			'status'  => 'hold',
			'type'    => 'comment',
			'number'  => 200,
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		)
	) as $c ) {
		/* translators: 1: comment author, 2: post title */
		$items[ 'c' . $c->comment_ID ] = sprintf( __( '%1$s on "%2$s"', 'flurfunk' ), '' !== trim( (string) $c->comment_author ) ? (string) $c->comment_author : __( 'Anonymous', 'flurfunk' ), html_entity_decode( get_the_title( (int) $c->comment_post_ID ), ENT_QUOTES, 'UTF-8' ) );
	}
	Watch\adopt( STATE, 'flurfunk-comments:', array_keys( $items ) ); // One thread per day before 0.7.
	$channel = channel();
	if ( $items && \Flurfunk\Modules\COMMENTS_CHANNEL === $channel ) {
		\Flurfunk\Modules\comments_channel(); // Also when the module was switched on in code.
	}
	return Watch\run(
		STATE,
		$items,
		array(
			'channel' => $channel,
			'title'   => __( 'Comments awaiting moderation', 'flurfunk' ),
			/* translators: %s: number */
			'body'    => static fn ( array $all ) => sprintf( _n( '%s comment is waiting for approval.', '%s comments are waiting for approval.', count( $all ), 'flurfunk' ), number_format_i18n( count( $all ) ) ),
			/* translators: 1: number, 2: who on which post */
			'new'     => static fn ( array $new ) => sprintf( _n( '%1$s new comment is waiting: %2$s', '%1$s new comments are waiting: %2$s', count( $new ), 'flurfunk' ), number_format_i18n( count( $new ) ), implode( '; ', $new ) ),
			/* translators: %s: number of days */
			'remind'  => static fn ( int $days ) => sprintf( _n( 'These comments have been waiting for %s day.', 'These comments have been waiting for %s days.', $days, 'flurfunk' ), number_format_i18n( $days ) ),
			'days'    => (int) flurfunk_module_setting( MODULE, 'remind' ),
			'card'    => array( PROVIDER, array( 'id' => 'queue' ) ),
		)
	);
}

/**
 * Channel of the daily thread: the module setting, unless the filter says otherwise.
 */
function channel(): string {
	$slug = (string) ( flurfunk_module_setting( MODULE, 'channel' ) ?? \Flurfunk\Modules\COMMENTS_CHANNEL );
	return sanitize_key( (string) apply_filters( 'flurfunk_comments_channel', $slug ) );
}

<?php
/**
 * Module "Daily moderation thread": once a day, if comments are waiting for approval, one
 * thread lists them (author, post, since when) with their number and a link to the comments
 * screen. Nothing is posted on days without waiting comments; running it again on the same
 * day updates that day's card instead of posting again.
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
use Flurfunk\Store;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

const MODULE   = 'flurfunk/comments-daily';
const PROVIDER = 'flurfunk/comment-queue';
const HOOK     = 'flurfunk_comments_daily';

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
 * Post today's thread, or update today's card. Returns the thread ID (0 = nothing waiting).
 *
 * @return int|\WP_Error
 */
function run() {
	$count = (int) get_comments(
		array(
			'status' => 'hold',
			'type'   => 'comment',
			'count'  => true,
		)
	);
	$ref   = 'flurfunk-comments:' . wp_date( 'Y-m-d' );
	$found = Store\thread_by_ref( $ref );
	if ( $found ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own table.
		$card = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE thread_id = %d AND provider = %s ORDER BY id LIMIT 1', table( 'attachments' ), $found->id, PROVIDER ) );
		if ( $card ) {
			flurfunk_as( 0, static fn () => Store\refresh_card( $card ) );
		}
		return (int) $found->id;
	}
	if ( ! $count ) {
		return 0;
	}
	$channel = channel();
	if ( \Flurfunk\Modules\COMMENTS_CHANNEL === $channel ) {
		\Flurfunk\Modules\comments_channel(); // Also when the module was switched on in code.
	}
	return flurfunk_post(
		array(
			'channel'     => $channel,
			'title'       => __( 'Comments awaiting moderation', 'flurfunk' ),
			/* translators: %s: number */
			'body'        => sprintf( _n( '%s comment is waiting for approval.', '%s comments are waiting for approval.', $count, 'flurfunk' ), number_format_i18n( $count ) ),
			'attachments' => array(
				array(
					'provider' => PROVIDER,
					'ref'      => array( 'id' => 'queue' ),
				),
			),
			'ref_key'     => $ref,
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

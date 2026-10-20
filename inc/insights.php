<?php
/**
 * Insights: how the site's content stands and how its numbers develop – for a person (or an AI
 * assistant working for them) who runs the site.
 *
 * - flurfunk/site-check: a content and SEO check of the published posts and pages (missing excerpts
 *   or meta descriptions, long titles, images without alt text, thin texts, duplicate titles, no
 *   internal links, long untouched) – works on any site; SEO plugins' descriptions count.
 * - flurfunk/metrics: the numbers right now – content and comments from WordPress itself, visits
 *   and the like from statistics plugins that add them through the filter flurfunk_metrics.
 * - flurfunk/snapshot: keeps the numbers with a date and a note per channel ("excerpts added") and
 *   compares them later – whether a measure helped. Only stored in Flurfunk; changes nothing else.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Insights;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const SNAPSHOTS = 'flurfunk_snapshots'; // slug => [ { at, by, note, metrics: { key => { label, value, unit, source } } } ].
const KEEP      = 60;                   // Snapshots per channel.

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * Meta keys in which SEO plugins keep a post's description.
 */
function description_keys(): array {
	return (array) apply_filters( 'flurfunk_description_keys', array( '_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc', '_genesis_description', '_aioseo_description' ) );
}

/**
 * The content and SEO check.
 *
 * @param array $input { type: post|page|any, examples }.
 */
function site_check( array $input ): array {
	$types = 'page' === ( $input['type'] ?? '' ) ? array( 'page' ) : ( 'post' === ( $input['type'] ?? '' ) ? array( 'post' ) : array( 'post', 'page' ) );
	$max   = max( 1, min( 10, (int) ( $input['examples'] ?? 5 ) ) );
	$posts = get_posts(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	$host   = wp_parse_url( home_url(), PHP_URL_HOST );
	$issues = array();
	$titles = array();
	$add    = static function ( string $key, \WP_Post $p, string $detail = '' ) use ( &$issues, $max ): void {
		$issues[ $key ]['count'] = ( $issues[ $key ]['count'] ?? 0 ) + 1;
		if ( count( $issues[ $key ]['examples'] ?? array() ) < $max ) {
			$issues[ $key ]['examples'][] = array_filter(
				array(
					'id'     => $p->ID,
					'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
					'detail' => $detail,
				)
			);
		}
	};
	foreach ( $posts as $p ) {
		$title = html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' );
		$text  = wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ) );
		$words = str_word_count( $text, 0, 'äöüÄÖÜßéèàçñ' );
		$desc  = '' !== trim( (string) $p->post_excerpt );
		foreach ( description_keys() as $k ) {
			$desc = $desc || '' !== trim( (string) get_post_meta( $p->ID, $k, true ) );
		}
		if ( ! $desc ) {
			$add( 'no_description', $p );
		}
		if ( mb_strlen( $title ) > 60 ) {
			$add( 'long_title', $p, mb_strlen( $title ) . ' chars' );
		} elseif ( mb_strlen( $title ) < 15 ) {
			$add( 'short_title', $p, mb_strlen( $title ) . ' chars' );
		}
		if ( 'post' === $p->post_type && ! has_post_thumbnail( $p ) ) {
			$add( 'no_featured_image', $p );
		}
		$noalt = preg_match_all( '/<img(?![^>]*\balt="[^"]+")[^>]*>/i', (string) $p->post_content );
		if ( $noalt ) {
			$add( 'images_without_alt', $p, $noalt . ' images' );
		}
		if ( $words < 300 ) {
			$add( 'thin', $p, $words . ' words' );
		}
		if ( ! preg_match( '#href="(/|https?://' . preg_quote( (string) $host, '#' ) . ')#i', (string) $p->post_content ) ) {
			$add( 'no_internal_links', $p );
		}
		if ( strtotime( $p->post_modified_gmt . ' UTC' ) < time() - 2 * YEAR_IN_SECONDS ) {
			$add( 'not_updated_two_years', $p );
		}
		$titles[ mb_strtolower( $title ) ][] = $p;
	}
	foreach ( $titles as $same ) {
		if ( count( $same ) > 1 ) {
			foreach ( $same as $p ) {
				$add( 'duplicate_title', $p );
			}
		}
	}
	// What each finding means, so it is not misread (a missing description is a missing excerpt too).
	$about = array(
		'no_description'        => 'Neither an excerpt nor a meta description of an SEO plugin: the excerpt field is empty as well.',
		'long_title'            => 'Title longer than 60 characters: search results cut it.',
		'short_title'           => 'Title shorter than 15 characters.',
		'no_featured_image'     => 'Post without a featured image.',
		'images_without_alt'    => 'Images without alt text in the content.',
		'thin'                  => 'Under 300 words.',
		'no_internal_links'     => 'No link to another page of this site.',
		'not_updated_two_years' => 'Not changed in two years.',
		'duplicate_title'       => 'The same title as another post or page.',
	);
	return array(
		'checked' => count( $posts ),
		'issues'  => $issues,
		'about'   => array_intersect_key( $about, $issues ),
	);
}

/**
 * The numbers right now: WordPress's own, and those statistics plugins add.
 *
 * @return array<string, array{label:string, value:float|int, unit:string, source:string}>
 */
function metrics(): array {
	$count = static function ( string $type, int $days ): int {
		$q = new \WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'after' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ),
			)
		);
		return (int) $q->found_posts;
	};
	$check = site_check( array() );
	$c     = wp_count_comments();
	$out   = array(
		'posts_published'  => array( 'label' => 'Posts published', 'value' => (int) wp_count_posts()->publish, 'unit' => '', 'source' => 'WordPress' ),
		'posts_30d'        => array( 'label' => 'New posts in the last 30 days', 'value' => $count( 'post', 30 ), 'unit' => '', 'source' => 'WordPress' ),
		'comments_pending' => array( 'label' => 'Comments waiting for approval', 'value' => (int) $c->moderated, 'unit' => '', 'source' => 'WordPress' ),
		'comments'         => array( 'label' => 'Approved comments', 'value' => (int) $c->approved, 'unit' => '', 'source' => 'WordPress' ),
	);
	foreach ( array( 'no_description' => 'Posts and pages without a description', 'images_without_alt' => 'Posts with images without alt text', 'thin' => 'Posts and pages under 300 words' ) as $key => $label ) {
		$out[ 'check_' . $key ] = array( 'label' => $label, 'value' => (int) ( $check['issues'][ $key ]['count'] ?? 0 ), 'unit' => '', 'source' => 'Flurfunk site check' );
	}
	/**
	 * Numbers of statistics plugins: add entries key => { label, value, unit, source }, e.g. visits
	 * of the last 7 days or the most read posts (value a number; put lists into the label).
	 *
	 * @param array $metrics The numbers so far.
	 */
	$more = (array) apply_filters( 'flurfunk_metrics', array() );
	foreach ( $more as $key => $m ) {
		if ( is_array( $m ) && isset( $m['value'] ) && is_numeric( $m['value'] ) ) {
			$out[ sanitize_key( (string) $key ) ] = array(
				'label'  => mb_substr( (string) ( $m['label'] ?? $key ), 0, 120 ),
				'value'  => 0 + $m['value'],
				'unit'   => mb_substr( (string) ( $m['unit'] ?? '' ), 0, 20 ),
				'source' => mb_substr( (string) ( $m['source'] ?? '' ), 0, 60 ),
			);
		}
	}
	return $out;
}

/**
 * A channel's snapshots, oldest first.
 *
 * @param string $slug Channel.
 */
function snapshots( string $slug ): array {
	return array_values( (array) ( ( (array) get_option( SNAPSHOTS, array() ) )[ $slug ] ?? array() ) );
}

/**
 * Keep the numbers of now (or given ones, e.g. in tests) for a channel.
 *
 * @param string     $slug    Channel.
 * @param string     $note    What happened ("excerpts added").
 * @param array|null $numbers Numbers (default: metrics()).
 * @param int        $at      When (default: now).
 */
function take( string $slug, string $note = '', ?array $numbers = null, int $at = 0 ): array {
	$all   = (array) get_option( SNAPSHOTS, array() );
	$list  = (array) ( $all[ $slug ] ?? array() );
	$snap  = array(
		'at'      => $at ?: time(),
		'by'      => get_current_user_id(),
		'note'    => mb_substr( sanitize_text_field( $note ), 0, 200 ),
		'metrics' => $numbers ?? metrics(),
	);
	$list[] = $snap;
	usort( $list, static fn ( $a, $b ) => (int) $a['at'] <=> (int) $b['at'] );
	$all[ $slug ] = array_slice( $list, -KEEP );
	update_option( SNAPSHOTS, $all, false );
	return $snap;
}

/**
 * Compare two sets of numbers: what changed, by how much.
 *
 * @param array $then Older numbers.
 * @param array $now  Newer numbers.
 */
function compare( array $then, array $now ): array {
	$out = array();
	foreach ( $now as $key => $m ) {
		if ( ! isset( $then[ $key ] ) ) {
			continue;
		}
		$a = 0 + $then[ $key ]['value'];
		$b = 0 + $m['value'];
		if ( $a === $b ) {
			continue;
		}
		$out[ $key ] = array(
			'label'   => $m['label'],
			'then'    => $a,
			'now'     => $b,
			'change'  => $b - $a,
			'percent' => $a ? round( ( $b - $a ) / abs( $a ) * 100, 1 ) : null,
			'unit'    => $m['unit'],
		);
	}
	return $out;
}

/**
 * The ability flurfunk/snapshot: take, list or compare.
 *
 * @param array $input { thread, action, note, since }.
 * @return array|\WP_Error
 */
function snapshot( array $input ) {
	$t    = Store\thread_row( (int) ( $input['thread'] ?? 0 ) );
	$slug = $t && empty( $t->private_to ) ? (string) ( Store\channel( (int) $t->channel_id )->slug ?? '' ) : '';
	if ( '' === $slug ) {
		return new \WP_Error( 'flurfunk_snapshot', __( 'Snapshots are kept per channel; this conversation has none.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$action = (string) ( $input['action'] ?? 'take' );
	$list   = snapshots( $slug );
	$brief  = static fn ( array $s, int $i ) => array(
		'number' => $i + 1,
		'at'     => wp_date( 'Y-m-d H:i', (int) $s['at'] ),
		'note'   => (string) $s['note'],
	);
	if ( 'list' === $action ) {
		return array( 'snapshots' => array_map( $brief, $list, array_keys( $list ) ) );
	}
	if ( 'compare' === $action ) {
		if ( ! $list ) {
			return new \WP_Error( 'flurfunk_snapshot', __( 'There is no snapshot in this channel yet to compare with.', 'flurfunk' ), array( 'status' => 404 ) );
		}
		$since = (int) ( $input['since'] ?? 0 );
		$then  = $list[ $since >= 1 && $since <= count( $list ) ? $since - 1 : count( $list ) - 1 ];
		return array(
			'since'   => $brief( $then, array_search( $then, $list, true ) ),
			'changes' => compare( (array) $then['metrics'], metrics() ),
		);
	}
	$snap = take( $slug, (string) ( $input['note'] ?? '' ) );
	Store\bump();
	return array(
		'taken'   => $brief( $snap, count( $list ) ),
		'metrics' => $snap['metrics'],
		'changes' => $list ? compare( (array) end( $list )['metrics'], $snap['metrics'] ) : array(),
	);
}

/**
 * May the current person read the site's content numbers?
 */
function may( $input = null ): bool {
	return is_user_logged_in() && Access\can_use() && current_user_can( 'edit_posts' );
}

/**
 * May the current person keep snapshots in this conversation's channel (the team, who can see it)?
 *
 * @param array|null $input Ability input.
 */
function may_snapshot( $input = null ): bool {
	$t = Store\thread_row( (int) ( ( (array) $input )['thread'] ?? 0 ) );
	return may() && $t && empty( $t->private_to ) && 'team' === Access\kind() && Access\can_see( $t, get_current_user_id() );
}

/**
 * Register the abilities.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$read = array(
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
		'public'      => false,
	);
	wp_register_ability(
		'flurfunk/site-check',
		array(
			'label'               => __( 'Check the site\'s content', 'flurfunk' ),
			'description'         => __( 'A content and SEO check of the published posts and pages: missing excerpts or meta descriptions (SEO plugins count), titles too long or short, no featured image, images without alt text, under 300 words, duplicate titles, no internal links, not updated for two years – with counts and examples.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'     => array(
						'type'    => 'string',
						'enum'    => array( 'any', 'post', 'page' ),
						'default' => 'any',
					),
					'examples' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 10,
						'default' => 5,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => site_check( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => $read,
		)
	);
	wp_register_ability(
		'flurfunk/metrics',
		array(
			'label'               => __( 'The site\'s numbers', 'flurfunk' ),
			'description'         => __( 'The numbers right now: posts and comments from WordPress, the results of the site check, and visits and the like from statistics plugins that provide them.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Only these numbers (keys); leave it out for all.', 'flurfunk' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => empty( ( (array) $input )['keys'] ) ? metrics() : array_intersect_key( metrics(), array_flip( array_map( 'strval', (array) $input['keys'] ) ) ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => $read,
		)
	);
	wp_register_ability(
		'flurfunk/snapshot',
		array(
			'label'               => __( 'Keep and compare the numbers', 'flurfunk' ),
			'description'         => __( 'Keeps the site\'s numbers of now with a note for the channel of a conversation (take), lists the kept ones (list), or compares the numbers of now with one of them (compare, by default the latest) – to see whether a measure helped.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'thread' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'action' => array(
						'type'    => 'string',
						'enum'    => array( 'take', 'list', 'compare' ),
						'default' => 'take',
					),
					'note'   => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => __( 'What happened or is planned, e.g. "excerpts added to all posts".', 'flurfunk' ),
					),
					'since'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Number of the snapshot to compare with (from list).', 'flurfunk' ),
					),
				),
				'required'             => array( 'thread' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => snapshot( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_snapshot',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'public'      => false,
			),
		)
	);
}

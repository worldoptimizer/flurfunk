<?php
/**
 * Visits from statistics plugins that keep their numbers on the site itself – Koko Analytics,
 * WP Statistics and Independent Analytics – as numbers for the assistants and snapshots (filter
 * flurfunk_metrics): visitors and page views of the last 7 and 30 days, visits from search engines
 * (where the plugin offers them), and the most read posts of the week. Read through the plugins' own
 * classes and functions, never their tables; nothing is sent anywhere.
 * Other plugins add their numbers through the same filter.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Stats;

defined( 'ABSPATH' ) || exit;

const TOP = 5; // Most read posts of the week.

add_filter( 'flurfunk_metrics', __NAMESPACE__ . '\\metrics', 5 );

/**
 * The days from $days ago until today, in the site's time zone.
 *
 * @param int $days Number of days, today included.
 * @return array{0:string, 1:string} From, to (Y-m-d).
 */
function days( int $days ): array {
	$today = new \DateTimeImmutable( 'now', wp_timezone() );
	return array( $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ), $today->format( 'Y-m-d' ) );
}

/**
 * Is this a search engine's address?
 *
 * @param string $url Referrer.
 */
function search_engine( string $url ): bool {
	$host = (string) wp_parse_url( ( str_contains( $url, '//' ) ? '' : 'https://' ) . $url, PHP_URL_HOST );
	return (bool) preg_match( '/(^|\.)(google|bing|duckduckgo|yahoo|ecosia|qwant|startpage|yandex|baidu|ask|search\.brave)\.[a-z.]+$/i', $host );
}

/**
 * One number.
 *
 * @param string    $label  What it counts.
 * @param int|float $value  The number.
 * @param string    $source The plugin.
 */
function entry( string $label, $value, string $source ): array {
	return array(
		'label'  => $label,
		'value'  => (int) $value,
		'unit'   => '',
		'source' => $source,
	);
}

/**
 * The numbers of the active statistics plugin, added to the others (a number someone else already
 * added stays).
 *
 * @param array $m The numbers so far.
 */
function metrics( array $m ): array {
	try {
		if ( class_exists( '\\KokoAnalytics\\Stats' ) ) {
			$add = koko();
		} elseif ( class_exists( '\\WP_Statistics\\Models\\VisitorsModel' ) ) {
			$add = wp_statistics();
		} elseif ( function_exists( '\\IAWPSCOPED\\iawp_analytics' ) ) {
			$add = independent_analytics();
		} else {
			$add = array();
		}
	} catch ( \Throwable $e ) {
		$add = array(); // A plugin that changed its classes must not break the other numbers.
	}
	return $m + $add;
}

/**
 * Koko Analytics.
 */
function koko(): array {
	$stats = new \KokoAnalytics\Stats();
	$src   = 'Koko Analytics';
	$out   = array();
	foreach ( array( 7, 30 ) as $days ) {
		list( $from, $to )           = days( $days );
		$t                           = $stats->get_totals( $from, $to );
		$out[ "visitors_{$days}d" ]  = entry( "Visitors in the last {$days} days", $t->visitors ?? 0, $src );
		$out[ "pageviews_{$days}d" ] = entry( "Page views in the last {$days} days", $t->pageviews ?? 0, $src );
	}
	list( $from, $to ) = days( 7 );
	if ( method_exists( $stats, 'get_referrers' ) ) {
		$search = 0;
		foreach ( (array) $stats->get_referrers( $from, $to, 0, 200 ) as $r ) {
			$search += search_engine( (string) ( $r->url ?? '' ) ) ? (int) ( $r->visitors ?? 0 ) : 0;
		}
		$out['search_7d'] = entry( 'Visits from search engines in the last 7 days', $search, $src );
	}
	foreach ( (array) $stats->get_posts( $from, $to, 0, TOP ) as $p ) {
		if ( (int) ( $p->post_id ?? 0 ) > 0 ) {
			$out[ 'views_7d_' . (int) $p->post_id ] = entry( top_label( (int) $p->post_id ), $p->pageviews ?? 0, $src );
		}
	}
	return $out;
}

/**
 * WP Statistics.
 */
function wp_statistics(): array {
	$visitors = new \WP_Statistics\Models\VisitorsModel();
	$src      = 'WP Statistics';
	$out      = array();
	/*
	 * WP Statistics compares its date columns with "<from> 00:00:00" – on SQLite (WordPress Studio,
	 * Playground) the first day then drops out. The second before it counts the same on both.
	 */
	$range = static function ( int $days ): array {
		list( $from, $to ) = days( $days );
		return array(
			'from' => ( new \DateTimeImmutable( $from ) )->modify( '-1 day' )->format( 'Y-m-d' ) . ' 23:59:59',
			'to'   => $to,
		);
	};
	foreach ( array( 7, 30 ) as $days ) {
		$date                        = $range( $days );
		$out[ "visitors_{$days}d" ]  = entry( "Visitors in the last {$days} days", $visitors->countVisitors( array( 'date' => $date ) ), $src );
		$out[ "pageviews_{$days}d" ] = entry( "Page views in the last {$days} days", $visitors->countHits( array( 'date' => $date ) ), $src );
	}
	$date = $range( 7 );
	if ( method_exists( $visitors, 'countDailyVisitors' ) ) {
		$search = 0;
		foreach ( (array) $visitors->countDailyVisitors( array( 'date' => $date, 'source_channel' => array( 'search' ) ) ) as $day ) {
			$search += (int) ( is_object( $day ) ? ( $day->visitors ?? 0 ) : ( $day['visitors'] ?? 0 ) );
		}
		$out['search_7d'] = entry( 'Visits from search engines in the last 7 days', $search, $src );
	}
	if ( class_exists( '\\WP_Statistics\\Models\\PostsModel' ) ) {
		foreach ( (array) ( new \WP_Statistics\Models\PostsModel() )->getPostsViewsData( array( 'date' => $date, 'per_page' => TOP ) ) as $p ) {
			$id = (int) ( is_object( $p ) ? ( $p->ID ?? 0 ) : ( $p['ID'] ?? 0 ) );
			if ( $id > 0 ) {
				$out[ 'views_7d_' . $id ] = entry( top_label( $id ), is_object( $p ) ? ( $p->views ?? 0 ) : ( $p['views'] ?? 0 ), $src );
			}
		}
	}
	return $out;
}

/**
 * Independent Analytics (also Pro), through its public functions: views and visitors; the most read
 * posts and pages. It has no public number for visits from search engines.
 */
function independent_analytics(): array {
	$src  = 'Independent Analytics';
	$out  = array();
	$date = static fn ( string $day ) => new \DateTime( $day, wp_timezone() ); // Whole days, in the site's time zone.
	foreach ( array( 7, 30 ) as $days ) {
		list( $from, $to )           = days( $days );
		$a                           = \IAWPSCOPED\iawp_analytics( $date( $from ), $date( $to ) );
		$out[ "visitors_{$days}d" ]  = entry( "Visitors in the last {$days} days", $a->visitors ?? 0, $src );
		$out[ "pageviews_{$days}d" ] = entry( "Page views in the last {$days} days", $a->views ?? 0, $src );
	}
	if ( function_exists( '\\IAWPSCOPED\\iawp_top_posts' ) ) {
		list( $from, $to ) = days( 7 );
		$top               = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			foreach ( (array) \IAWPSCOPED\iawp_top_posts( array( 'post_type' => $type, 'limit' => TOP, 'from' => $date( $from ), 'to' => $date( $to ) ) ) as $p ) {
				$p = (object) $p;
				if ( (int) ( $p->id ?? 0 ) > 0 ) {
					$top[ (int) $p->id ] = (int) ( $p->views ?? 0 );
				}
			}
		}
		arsort( $top );
		foreach ( array_slice( $top, 0, TOP, true ) as $id => $views ) {
			$out[ 'views_7d_' . $id ] = entry( top_label( $id ), $views, $src );
		}
	}
	return $out;
}

/**
 * The label of a most read post: its title and ID, so a snapshot later still names it.
 *
 * @param int $id Post.
 */
function top_label( int $id ): string {
	return mb_substr( sprintf( 'Page views of "%s" (ID %d) in the last 7 days', html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), $id ), 0, 120 );
}

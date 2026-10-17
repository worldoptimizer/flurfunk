<?php
/**
 * Load test: 2000 threads, 20000 messages in a temporary channel; times the main queries and
 * removes everything again. Run: wp eval-file tests/load.php (uses users 156, 41, 2, 159 – adapt).
 *
 * @package Flurfunk
 */

// phpcs:disable
global $wpdb;
$P = $wpdb->prefix . 'flurfunk_';
$ch = Flurfunk\Store\ensure_channel( 'ff-load', array( 'name' => 'Last', 'access_guests' => 'own-posts' ), true );
$people = array( 156, 41, 2, 159 );
$t0 = microtime( true );
$now = time();
// 2000 threads in batches
for ( $b = 0; $b < 20; $b++ ) {
	$vals = array();
	for ( $i = 0; $i < 100; $i++ ) {
		$n = $b * 100 + $i;
		$ts = gmdate( 'Y-m-d H:i:s', $now - $n * 300 );
		$vals[] = $wpdb->prepare( '(%d,%s,%s,%d,%s,%s,%d,%s,%s,%s)', $ch, 'ff-load:' . $n, '', 0, 'Last ' . $n, 'Inhalt Nummer ' . $n . ' mit etwas Text', $people[ $n % 4 ], $n % 3 ? '' : 'open', $ts, $ts );
	}
	$wpdb->query( "INSERT INTO {$P}threads (channel_id, ref_key, target_type, target_id, title, body, author_id, status, created, last_activity) VALUES " . implode( ',', $vals ) );
}
$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$P}threads WHERE channel_id = %d", $ch ) );
// 10 messages each
foreach ( array_chunk( $ids, 100 ) as $chunk ) {
	$vals = array();
	foreach ( $chunk as $tid ) {
		for ( $k = 0; $k < 10; $k++ ) {
			$vals[] = $wpdb->prepare( '(%d,%s,%d,%s,%s,%s,%s)', $tid, 'message', $people[ $k % 4 ], 'Antwort ' . $k . ' zu ' . $tid, '', '[]', gmdate( 'Y-m-d H:i:s', $now - $k * 60 ) );
		}
	}
	$wpdb->query( "INSERT INTO {$P}messages (thread_id, type, author_id, body, status, files, created) VALUES " . implode( ',', $vals ) );
}
// some notifications for 41
$vals = array();
foreach ( array_slice( $ids, 0, 1000 ) as $tid ) {
	$vals[] = $wpdb->prepare( '(%d,%d,%d,%s,%d,%s)', 41, $tid, 0, 'reply', 156, gmdate( 'Y-m-d H:i:s' ) );
}
$wpdb->query( "INSERT INTO {$P}notifications (user_id, thread_id, message_id, kind, actor_id, created) VALUES " . implode( ',', $vals ) );
printf( "seeded %d threads, %d messages in %.1fs\n", count( $ids ), $wpdb->get_var( "SELECT COUNT(*) FROM {$P}messages m JOIN {$P}threads t ON t.id=m.thread_id WHERE t.channel_id=$ch" ), microtime( true ) - $t0 );
Flurfunk\Store\bump();
wp_set_current_user( 41 );
$time = function ( $label, $fn ) {
	$t = microtime( true );
	$r = $fn();
	printf( "%-38s %6.0f ms\n", $label, ( microtime( true ) - $t ) * 1000 );
	return $r;
};
$req = function ( $m, $path, $params = array() ) {
	$r = new WP_REST_Request( $m, '/flurfunk/v1/' . $path );
	foreach ( $params as $k => $v ) $r->set_param( $k, $v );
	return rest_do_request( $r )->get_data();
};
$time( 'counts (cold)', fn () => Flurfunk\Store\counts( 41 ) );
$time( 'counts (cached)', fn () => Flurfunk\Store\counts( 41 ) );
$time( 'bootstrap', fn () => $req( 'GET', 'bootstrap' ) );
$time( 'list all threads (40)', fn () => $req( 'GET', 'threads', array( 'view' => 'all', 'limit' => 40 ) ) );
$time( 'list channel (40)', fn () => $req( 'GET', 'threads', array( 'channel' => 'ff-load', 'limit' => 40 ) ) );
$time( 'list channel page 10', fn () => $req( 'GET', 'threads', array( 'channel' => 'ff-load', 'limit' => 40, 'before' => gmdate( 'c', $now - 400 * 300 ) ) ) );
$time( 'open tasks', fn () => $req( 'GET', 'threads', array( 'view' => 'tasks', 'limit' => 40 ) ) );
$time( 'search in replies', fn () => $req( 'GET', 'threads', array( 'q' => 'Antwort 7 zu ' . $ids[1500], 'limit' => 40 ) ) );
$time( 'open a thread', fn () => $req( 'GET', 'threads/' . $ids[100] ) );
$time( 'notifications (30)', fn () => $req( 'GET', 'notifications', array( 'limit' => 30 ) ) );
$time( 'mark channel read', fn () => Flurfunk\Store\mark_all_read( 41, 'ff-load' ) );
$time( 'export everything', fn () => count( Flurfunk\Transfer\export()['threads'] ) );
// clean up
$in = implode( ',', array_map( 'intval', $ids ) );
foreach ( array( 'messages', 'mentions', 'reads', 'attachments', 'assignees', 'notifications' ) as $tb ) {
	$wpdb->query( "DELETE FROM {$P}$tb WHERE thread_id IN ($in)" );
}
$wpdb->query( "DELETE FROM {$P}threads WHERE id IN ($in)" );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$P}channels WHERE id = %d", $ch ) );
Flurfunk\Store\bump();
echo "cleaned up\n";

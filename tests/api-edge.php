<?php
/**
 * Edge-case tests for the REST API and the store. Runs inside WordPress:
 *
 *   wp eval-file tests/api-edge.php <team-user-id> <second-team-user-id> <guest-user-id>
 *
 * The guest needs at least one post of their own. Everything happens in a temporary channel
 * "ff-test" (plus one archived channel) that is removed at the end, so it is safe on staging.
 *
 * @package Flurfunk
 */

// phpcs:disable

use Flurfunk\Store;

$args  = $args ?? array();
$team  = (int) ( $args[0] ?? 0 );
$team2 = (int) ( $args[1] ?? 0 );
$guest = (int) ( $args[2] ?? 0 );
if ( ! $team || ! $team2 || ! $guest ) {
	WP_CLI::error( 'Usage: wp eval-file tests/api-edge.php <team> <team2> <guest>' );
}

$fails = 0;
$runs  = 0;
$check = function ( string $name, bool $ok, $detail = '' ) use ( &$fails, &$runs ) {
	++$runs;
	if ( ! $ok ) {
		++$fails;
	}
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $name . ( $ok || '' === $detail ? '' : '  → ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) ) );
};
$req = function ( string $method, string $path, $body = null, array $params = array() ) {
	$r = new WP_REST_Request( $method, '/flurfunk/v1/' . $path );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};
$as = function ( int $uid ) {
	wp_set_current_user( $uid );
};

// No mails during tests.
add_filter( 'flurfunk_mail', '__return_false' );
$mails = array();
add_filter(
	'flurfunk_notify_recipients',
	function ( $to ) use ( &$mails ) {
		$mails[] = $to;
		return $to;
	}
);

Store\ensure_channel( 'ff-test', array( 'name' => 'Test', 'access_guests' => 'own-posts' ), true );
Store\ensure_channel( 'ff-test-archived', array( 'name' => 'Archiv', 'archived' => 1 ), true );
$guest_post = (int) ( get_posts( array( 'author' => $guest, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ) )[0] ?? 0 );
$other_post = (int) ( get_posts( array( 'author__not_in' => array( $guest ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1 ) )[0] ?? 0 );
if ( ! $guest_post || ! $other_post ) {
	WP_CLI::error( 'Need a post by the guest and one by someone else.' );
}

WP_CLI::log( 'Input' );
$as( $team );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => "  \n\t " ) );
$check( 'empty body is rejected', 400 === $s, $s );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'nope', 'body' => 'x' ) );
$check( 'unknown channel is rejected', 400 === $s, $s );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test-archived', 'body' => 'x' ) );
$check( 'archived channel takes no threads', 403 === $s, $s );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => 'x', 'target' => array( 'type' => 'planet', 'id' => 1 ) ) );
$check( 'unknown target type is rejected', 403 === $s, $s );
list( $s, $t ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => str_repeat( 'ä', 25000 ) ) );
$check( 'very long text is cut to 20000 characters', 200 === $s && 20000 === mb_strlen( $t['body'] ), mb_strlen( $t['body'] ?? '' ) );
$evil = "<script>alert(1)</script><img src=x onerror=alert(2)> \x07bell\x00null 🎉 Ümlaut";
list( $s, $t ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'title' => '<b>Titel</b>', 'body' => $evil ) );
$check( 'markup is kept as plain text (escaped on output)', 200 === $s && str_contains( $t['body'], '<script>' ), $t['body'] ?? $s );
$check( 'control characters are removed, emoji and umlauts kept', ! str_contains( $t['body'], "\x07" ) && ! str_contains( $t['body'], "\x00" ) && str_contains( $t['body'], '🎉 Ümlaut' ) );
$check( 'title loses markup', 'Titel' === $t['title'], $t['title'] );

WP_CLI::log( 'Mentions' );
list( $s, $t ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => "Hallo <@$team2> und <@$guest> und <@999999>" ) );
$check( 'mentions keep only people who can see the thread', array( $team2 ) === $t['mentions'], $t['mentions'] );
list( $s, $t2 ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'target' => array( 'type' => 'post', 'id' => $guest_post ), 'body' => "Frage an <@$guest>" ) );
$check( 'guest is mentionable in a thread about their own post', array( $guest ) === $t2['mentions'], $t2['mentions'] );
$check( 'team sees who else can read it', in_array( get_userdata( $guest )->display_name, $t2['audience'], true ), $t2['audience'] );

WP_CLI::log( 'Guest rights' );
$internal = $t['id'];
$as( $guest );
list( $s ) = $req( 'GET', 'threads/' . $internal );
$check( 'guest cannot open an internal thread', 404 === $s, $s );
list( $s ) = $req( 'POST', 'threads/' . $internal . '/messages', array( 'body' => 'hi' ) );
$check( 'guest cannot reply to it', 404 === $s, $s );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => 'x' ) );
$check( 'guest cannot start a thread without their post', 403 === $s, $s );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => 'x', 'target' => array( 'type' => 'post', 'id' => $other_post ) ) );
$check( "guest cannot attach a thread to someone else's post", 403 === $s, $s );
list( $s, $g ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'Mach ich', 'attachments' => array( array( 'provider' => 'flurfunk/post', 'ref' => array( 'id' => $other_post ) ), array( 'provider' => 'does/not-exist', 'ref' => array( 'id' => 1 ) ) ) ) );
$last = end( $g['messages'] );
$check( 'guest reply works, foreign and unknown cards are dropped', 200 === $s && array() === $last['attachments'], $last['attachments'] ?? $s );
$foreign_file = (int) ( get_posts( array( 'post_type' => 'attachment', 'author__not_in' => array( $guest ), 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1 ) )[0] ?? 0 );
if ( $foreign_file ) {
	list( $s, $g ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'Datei', 'files' => array( $foreign_file ) ) );
	$last = end( $g['messages'] );
	$check( "guest cannot attach someone else's media file", array() === $last['files'], $last['files'] );
}
list( $s ) = $req( 'POST', 'threads/' . $t2['id'] . '/status', array( 'status' => 'bogus' ) );
$check( 'unknown status is rejected', 403 === $s, $s );
list( $s, $l ) = $req( 'GET', 'threads', null, array( 'view' => 'all' ) );
$check( 'guest list holds only threads about their posts', ! array_filter( $l, fn ( $x ) => ! $x['target'] || (int) $x['target']['id'] !== $guest_post ), wp_list_pluck( $l, 'id' ) );
list( $s, $items ) = $req( 'GET', 'providers/flurfunk/post/search', null, array( 'q' => '' ) );
$check( 'guest search offers only own posts', ! array_filter( $items, fn ( $i ) => ! in_array( (int) $i['ref']['id'], Flurfunk\Access\owned_post_ids( $guest ), true ) ), wp_list_pluck( $items, 'title' ) );

WP_CLI::log( 'Tasks, read state, idempotence' );
$as( $team );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/status', array( 'status' => 'open' ) );
$check( 'a conversation can become a task', 'open' === $x['status'] );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/status', array( 'status' => 'open' ) );
$events = count( array_filter( $x['messages'], fn ( $m ) => 'status' === $m['type'] ) );
$check( 'setting the same status twice logs it once', 1 === $events, $events );
$as( $team2 );
$before = Store\counts( $team2 )['total'];
list( $s ) = $req( 'GET', 'threads/' . $t2['id'] );
$after = Store\counts( $team2 )['total'];
$check( 'opening a thread marks it read', $after < $before, "$before → $after" );
list( $s ) = $req( 'GET', 'threads/' . $t2['id'], null, array( 'peek' => 1 ) );
$check( 'peek does not change the read state', Store\counts( $team2 )['total'] === $after );
Store\mark_read( $t2['id'], $team2, 1 );
$check( 'read state never goes backwards', Store\counts( $team2 )['total'] === $after );
list( $s, $a ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'ref_key' => 'test:same', 'title' => 'Eins', 'body' => 'x' ) );
list( $s, $b ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'ref_key' => 'test:same', 'title' => 'Zwei', 'body' => 'y' ) );
$check( 'same ref_key returns the same thread', $a['id'] === $b['id'] && 'Eins' === $b['title'] );

WP_CLI::log( 'Lists and paging' );
for ( $i = 0; $i < 5; $i++ ) {
	Store\create_thread( array( 'channel' => 'ff-test', 'body' => "Seite $i", 'created' => gmdate( 'c', time() - 3600 * ( $i + 1 ) ) ) );
}
list( $s, $p1 ) = $req( 'GET', 'threads', null, array( 'channel' => 'ff-test', 'limit' => 3 ) );
list( $s, $p2 ) = $req( 'GET', 'threads', null, array( 'channel' => 'ff-test', 'limit' => 3, 'before' => end( $p1 )['activity'] ) );
$check( 'paging returns the next, older threads', 3 === count( $p1 ) && $p2 && ! array_intersect( wp_list_pluck( $p1, 'id' ), wp_list_pluck( $p2, 'id' ) ) );
list( $s, $l ) = $req( 'GET', 'threads', null, array( 'limit' => 100000 ) );
$check( 'limit is capped', 200 === $s && count( $l ) <= 200 );

WP_CLI::log( 'Export and import' );
$data  = Flurfunk\Transfer\export();
$mine  = array_values( array_filter( $data['threads'], fn ( $x ) => 'ff-test' === $x['channel'] ) );
$check( 'export contains the test threads with messages', count( $mine ) >= 8 && isset( $mine[0]['messages'] ) );
$data['site']     = 'https://elsewhere.example/';
$data['threads']  = $mine;
$data['channels'] = array_values( array_filter( $data['channels'], fn ( $c ) => 'ff-test' === $c['id'] ) );
$r1 = Flurfunk\Transfer\import( $data );
$r2 = Flurfunk\Transfer\import( $data );
$check( 'import adds threads once, a second run skips them', $r1['threads'] > 0 && 0 === $r2['threads'] && $r2['skipped'] >= $r1['threads'], array( $r1, $r2 ) );
$check( 'import never sends mails', ! array_filter( $mails, fn ( $m ) => false === $m ) );

WP_CLI::log( 'Edit and delete' );
$as( $team );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'Tippfehler' ) );
$mid = end( $x['messages'] )['id'];
list( $s, $x ) = $req( 'POST', 'messages/' . $mid, array( 'body' => "Korrigiert für <@$team2>" ) );
$m = current( array_filter( $x['messages'], fn ( $y ) => $y['id'] === $mid ) );
$check( 'author can edit; edited time and new mentions are stored', 200 === $s && 'Korrigiert für <@' . $team2 . '>' === $m['text'] && $m['edited'] && array( $team2 ) === $m['mentions'], $m );
list( $s ) = $req( 'POST', 'messages/' . $mid, array( 'body' => '   ' ) );
$check( 'a message cannot be edited to empty', 400 === $s, $s );
$as( $guest );
list( $s ) = $req( 'POST', 'messages/' . $mid, array( 'body' => 'gekapert' ) );
$check( "nobody else can edit someone's message", 403 === $s, $s );
list( $s ) = $req( 'DELETE', 'messages/' . $mid );
$check( "nobody else can delete someone's message", 403 === $s, $s );
list( $s ) = $req( 'POST', 'threads/' . $t2['id'] . '/edit', array( 'body' => 'gekapert' ) );
$check( "nobody else can edit someone's thread", 403 === $s, $s );
$as( $team );
list( $s, $x ) = $req( 'DELETE', 'messages/' . $mid );
$m = current( array_filter( $x['messages'], fn ( $y ) => $y['id'] === $mid ) );
$check( 'deleting leaves a note without text', 200 === $s && 'deleted' === $m['type'] && ! isset( $m['text'] ), $m );
list( $s ) = $req( 'POST', 'messages/' . $mid, array( 'body' => 'wieder da' ) );
$check( 'a deleted message cannot be edited', 403 === $s, $s );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/edit', array( 'body' => "Neue Frage an <@$guest>" ) );
$check( 'thread author can edit the thread text', 200 === $s && str_starts_with( $x['body'], 'Neue Frage' ) && array( $guest ) === $x['mentions'], $x['mentions'] ?? $s );

WP_CLI::log( 'Targets' );
flurfunk_register_target(
	'ff-test-thing',
	array(
		'label'  => 'Testding',
		'search' => fn ( $q ) => array( array( 'id' => 7, 'title' => 'Ding sieben' ), array( 'id' => 8, 'title' => 'Ding acht' ) ),
		'info'   => fn ( $id ) => 7 === $id || 8 === $id ? array( 'title' => 'Ding ' . $id ) : null,
	)
);
list( $s, $r ) = $req( 'GET', 'targets', null, array( 'q' => '' ) );
$check( 'team finds posts and plugin targets', in_array( 'post', wp_list_pluck( $r, 'type' ), true ) && in_array( 'ff-test-thing', wp_list_pluck( $r, 'type' ), true ), array_unique( wp_list_pluck( $r, 'type' ) ) );
list( $s, $x ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => 'am Ding', 'target' => array( 'type' => 'ff-test-thing', 'id' => 7 ) ) );
$check( 'a thread can hang on a plugin target, with its title', 200 === $s && 'Ding 7' === $x['target']['title'], $x['target'] ?? $s );
list( $s ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'body' => 'x', 'target' => array( 'type' => 'ff-test-thing', 'id' => 99 ) ) );
$check( 'a target that does not exist is refused', 403 === $s, $s );
$as( $guest );
list( $s, $r ) = $req( 'GET', 'targets', null, array( 'q' => '' ) );
$check( 'guest finds only own posts, no plugin targets', $r && ! array_filter( $r, fn ( $i ) => 'post' !== $i['type'] || ! in_array( $i['id'], Flurfunk\Access\owned_post_ids( $guest ), true ) ), $r );
$as( $team );

WP_CLI::log( 'Mail preferences' );
$prefs_before = array( Flurfunk\Notify\prefs( $team2 ), Flurfunk\Notify\prefs( $guest ) );
$as( $team );
$recipients = function ( callable $fn ) use ( &$mails ) {
	$mails = array();
	$fn();
	return array_reduce( $mails, fn ( $all, $to ) => $all + $to, array() );
};
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'none' ) );
$to = $recipients( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => "An <@$team2>" ) ) );
$check( '"never" gets no mail even when mentioned', ! isset( $to[ $team2 ] ), $to );
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'all' ) );
$to = $recipients( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Für alle' ) ) );
$check( '"everything" gets mail without mention', isset( $to[ $team2 ] ), $to );
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'mentions' ) );
$to = $recipients( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Ohne Erwähnung' ) ) );
$check( '"mentions" gets no mail for unrelated threads', ! isset( $to[ $team2 ] ), $to );
$to = $recipients( fn () => Store\add_message( $t2['id'], array( 'body' => 'Weiter geht es' ) ) );
$check( 'guest gets mail for replies in a thread about their post', isset( $to[ $guest ] ) && 'reply' === $to[ $guest ], $to );
$check( 'nobody gets mail about their own message', ! isset( $to[ $team ] ), $to );
$to = $recipients( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => "Intern <@$guest>" ) ) );
$check( 'guest gets no mail from threads they cannot see', ! isset( $to[ $guest ] ), $to );
Flurfunk\Notify\save_prefs( $team2, $prefs_before[0] );
Flurfunk\Notify\save_prefs( $guest, $prefs_before[1] );

WP_CLI::log( 'Deleted people' );
$tmp = wp_insert_user( array( 'user_login' => 'ff-e2e-' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$tid = Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'von bald gelöscht', 'author' => $tmp ), false );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $tmp );
$as( $team );
list( $s, $x ) = $req( 'GET', 'threads/' . $tid );
$check( 'threads of deleted people stay readable', 200 === $s && 'von bald gelöscht' === $x['body'], $s );

WP_CLI::log( 'Clean up' );
global $wpdb;
$ids = array_map( 'intval', $wpdb->get_col( "SELECT t.id FROM {$wpdb->prefix}flurfunk_threads t JOIN {$wpdb->prefix}flurfunk_channels c ON c.id = t.channel_id WHERE c.slug IN ('ff-test','ff-test-archived')" ) );
if ( $ids ) {
	$in = implode( ',', $ids );
	foreach ( array( 'messages', 'mentions', 'reads', 'attachments' ) as $tb ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}flurfunk_$tb WHERE thread_id IN ($in)" );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}flurfunk_threads WHERE id IN ($in)" );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}flurfunk_channels WHERE slug IN ('ff-test','ff-test-archived')" );
WP_CLI::log( "  removed " . count( $ids ) . ' test threads' );

WP_CLI::log( '' );
$fails ? WP_CLI::error( "$fails of $runs checks failed." ) : WP_CLI::success( "All $runs checks passed." );

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

// No mails or pushes leave during tests; record who would have got a mail.
$mailed = array();
add_filter(
	'flurfunk_mail',
	function ( $mail, $user ) use ( &$mailed ) {
		$mailed[] = (int) $user->ID;
		return false;
	},
	10,
	2
);
add_filter( 'pre_http_request', fn ( $pre, $args, $url ) => str_contains( (string) $url, 'push.invalid' ) ? new WP_Error( 'blocked', 'test' ) : $pre, 10, 3 );
$mails = array(); // Kept for the import check below.

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

WP_CLI::log( 'Cache and files' );
$as( $team2 );
$c1 = Store\counts( $team2 )['total'];
$as( $team );
Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'neu für den Zähler' ) );
$c2 = Store\counts( $team2 )['total'];
$check( 'cached unread numbers update right after a new thread', $c2 === $c1 + 1, "$c1 → $c2" );
$png = wp_tempnam( 'ff-test.png' );
file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$att = media_handle_sideload( array( 'name' => 'ff-test-punkt.png', 'tmp_name' => $png ), 0, 'Testbild Punkt' );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'mit Bild', 'files' => array( $att ) ) );
$f = end( $x['messages'] )['files'][0] ?? array();
$check( 'image files come with address, preview and title', ! empty( $f['url'] ) && ! empty( $f['preview'] ) && 'Testbild Punkt' === ( $f['title'] ?? '' ), $f );
wp_delete_attachment( $att, true );
list( $s, $x ) = $req( 'GET', 'threads/' . $t2['id'] );
$f = end( $x['messages'] )['files'][0] ?? array();
$check( 'a file deleted from the media library is marked missing', ! empty( $f['missing'] ) && empty( $f['url'] ), $f );
$check( 'files from another site keep their link', 'https://elsewhere.example/a.pdf' === ( Store\file_view( array( 'id' => 0, 'name' => 'a.pdf', 'url' => 'https://elsewhere.example/a.pdf' ) )['url'] ?? '' ) );

WP_CLI::log( 'Flurfunk files' );
$as( $team );
$fid = Flurfunk\Files\store( 'ff-test-notiz.txt', "Notiz\n" );
$check( 'uploads go into the Flurfunk folder', ! is_wp_error( $fid ) && str_contains( (string) get_attached_file( $fid ), '/flurfunk/' ), is_wp_error( $fid ) ? $fid->get_error_message() : get_attached_file( $fid ) );
$lib = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => array( $fid ), 'fields' => 'ids', 'meta_query' => array( array( 'key' => Flurfunk\Files\META, 'compare' => 'NOT EXISTS' ) ) ) );
$check( 'they are hidden from the media library', 0 === $lib->post_count );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'Notiz anbei', 'files' => array( $fid ) ) );
$f = end( $x['messages'] )['files'][0] ?? array();
$check( 'a message shows them as not yet in the library', false === ( $f['library'] ?? null ), $f );
$path = get_attached_file( $fid );
list( $s, $f2 ) = $req( 'POST', 'files/' . $fid . '/adopt', array() );
$check( 'adopting keeps the same file and address', 200 === $s && true === $f2['library'] && get_attached_file( $fid ) === $path && $f2['url'] === $f['url'], $f2 );
$old = Flurfunk\Files\store( 'ff-test-alt.txt', "alt\n" );
update_post_meta( $old, Flurfunk\Files\META, time() - 40 * DAY_IN_SECONDS );
$keep = Flurfunk\Files\store( 'ff-test-genutzt.txt', "genutzt\n" );
update_post_meta( $keep, Flurfunk\Files\META, time() - 40 * DAY_IN_SECONDS );
$req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'genutzt', 'files' => array( $keep ) ) );
Flurfunk\Files\cleanup();
$check( 'cleanup removes old unused Flurfunk files only', ! get_post( $old ) && get_post( $keep ) && get_post( $fid ) );
wp_delete_attachment( $keep, true );
wp_delete_attachment( $fid, true );

WP_CLI::log( 'Assistant' );
$bot = wp_insert_user( array( 'user_login' => 'ff-test-bot-' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'display_name' => 'Testassistent' ) );
update_user_meta( $bot, 'flurfunk_bot', 1 );
$check( 'an assistant counts as team without editing rights', 'team' === Flurfunk\Access\kind( $bot ) && ! user_can( $bot, 'edit_posts' ) );
$as( $bot );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'automatisch' ) );
$check( 'an assistant can reply and is marked as such', 200 === $s && Store\person( $bot )['bot'], $s );
$bfile = Flurfunk\Files\store( 'ff-test-bot.txt', "bot\n" );
$check( 'an assistant can attach files', ! is_wp_error( $bfile ) );
if ( ! is_wp_error( $bfile ) ) {
	wp_delete_attachment( $bfile, true );
}

WP_CLI::log( 'Brake and privacy' );
$as( $team2 );
add_filter( 'flurfunk_rate_limit', fn () => 3 );
delete_transient( 'flurfunk_rate_' . $team2 . '_' . gmdate( 'YmdHi' ) );
$codes = array();
for ( $i = 0; $i < 5; $i++ ) {
	list( $s ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'schnell ' . $i ) );
	$codes[] = $s;
}
remove_all_filters( 'flurfunk_rate_limit' );
$check( 'too many messages in a minute are refused with 429', in_array( 429, $codes, true ) && 200 === $codes[0], $codes );
$as( $team );
$tmpu = wp_insert_user( array( 'user_login' => 'ff-test-priv-' . wp_generate_password( 4, false ), 'user_email' => 'ff-priv-' . wp_generate_password( 6, false ) . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$as( $tmpu );
$ptid = Store\create_thread( array( 'channel' => 'ff-test', 'title' => 'Privat', 'body' => 'meine Daten' ) );
Store\add_message( $ptid, array( 'body' => 'noch mehr Daten' ) );
$email = get_userdata( $tmpu )->user_email;
$ex    = Flurfunk\Privacy\export( $email );
$check( 'personal data export lists threads and messages', 2 === count( $ex['data'] ), count( $ex['data'] ) );
Flurfunk\Privacy\erase( $email );
$as( $team );
list( $s, $x ) = $req( 'GET', 'threads/' . $ptid );
$check( 'erasing keeps the thread but removes text and author', 200 === $s && 0 === $x['author'] && ! str_contains( wp_json_encode( $x ), 'Daten' ), $x['body'] ?? $s );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $tmpu );
wp_delete_user( $bot );

WP_CLI::log( 'Card refresh' );
$as( $team );
$cpost = wp_insert_post( array( 'post_title' => 'ff-test Karte alt', 'post_status' => 'draft', 'post_type' => 'post' ) );
list( $s, $x ) = $req( 'POST', 'threads/' . $t2['id'] . '/messages', array( 'body' => 'Karte', 'attachments' => array( array( 'provider' => 'flurfunk/post', 'ref' => array( 'id' => $cpost ) ) ) ) );
$card = end( $x['messages'] )['attachments'][0];
wp_update_post( array( 'ID' => $cpost, 'post_title' => 'ff-test Karte neu' ) );
list( $s, $x ) = $req( 'POST', 'cards/' . $card['id'] . '/refresh', array() );
$check( 'a card can be refreshed and shows the new state', 200 === $s && 'ff-test Karte neu' === end( $x['messages'] )['attachments'][0]['snapshot']['title'], $s );
wp_delete_post( $cpost, true );
list( $s ) = $req( 'POST', 'cards/' . $card['id'] . '/refresh', array() );
$check( 'refreshing a card whose source is gone keeps the old snapshot', 409 === $s, $s );
$as( $guest );
list( $s ) = $req( 'POST', 'cards/' . $card['id'] . '/refresh', array() );
$check( "nobody can refresh cards in threads they can't see", 404 === $s || 409 === $s, $s );
$as( $team );

WP_CLI::log( 'Bundle with files' );
$as( $team );
$bf = Flurfunk\Files\store( 'ff-test-bundle.txt', "Inhalt fürs Bündel\n" );
$bt = Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Bündel' ) );
Store\add_message( $bt, array( 'body' => 'mit Datei', 'files' => array( $bf ) ) );
$dir  = get_temp_dir() . 'ff-test-bundle-' . wp_generate_password( 6, false );
$data = Flurfunk\Transfer\export( $dir );
$mine = array_values( array_filter( $data['threads'], fn ( $x ) => (int) $x['id'] === (int) $bt ) );
$rel  = $mine[0]['messages'][0]['files'][0]['bundle'] ?? '';
$check( 'export with a folder copies the files and notes their path', '' !== $rel && is_readable( $dir . '/' . $rel ), $rel );
$data['site']     = 'https://bundle.example/';
$data['threads']  = $mine;
$data['channels'] = array_values( array_filter( $data['channels'], fn ( $c ) => 'ff-test' === $c['id'] ) );
$r    = Flurfunk\Transfer\import( $data, $dir );
$new  = Store\thread_by_ref( 'import:' . substr( md5( 'https://bundle.example/' ), 0, 8 ) . ':' . $bt );
$nf   = $new ? ( Store\full_thread( (int) $new->id, $team )['messages'][0]['files'][0] ?? array() ) : array();
$check( 'import stores bundled files again as Flurfunk files', 1 === $r['files'] && ! empty( $nf['id'] ) && $nf['id'] !== $bf && false === $nf['library'] && file_get_contents( get_attached_file( $nf['id'] ) ) === "Inhalt fürs Bündel\n", $nf );
wp_delete_attachment( $bf, true );
if ( ! empty( $nf['id'] ) ) {
	wp_delete_attachment( $nf['id'], true );
}
array_map( 'wp_delete_file', glob( $dir . '/files/*' ) ?: array() );
@rmdir( $dir . '/files' ); // phpcs:ignore
@rmdir( $dir ); // phpcs:ignore

WP_CLI::log( 'Search and read all' );
$as( $team );
$needle = 'Zebrastreifen' . wp_generate_password( 4, false, false );
$st     = Store\create_thread( array( 'channel' => 'ff-test', 'title' => 'Suchtest', 'body' => 'nichts' ) );
Store\add_message( $st, array( 'body' => "in einer Antwort: $needle" ) );
list( $s, $l ) = $req( 'GET', 'threads', null, array( 'q' => $needle ) );
$check( 'search finds a thread by a word in a reply', 1 === count( $l ) && (int) $l[0]['id'] === (int) $st, wp_list_pluck( $l, 'id' ) );
list( $s, $l ) = $req( 'GET', 'threads', null, array( 'q' => '%' ) );
$check( 'search treats % literally', 200 === $s );
$as( $guest );
list( $s, $l ) = $req( 'GET', 'threads', null, array( 'q' => $needle ) );
$check( 'search never shows what the person may not see', array() === $l );
$as( $team2 );
list( $s, $r ) = $req( 'POST', 'read', array( 'channel' => 'ff-test' ) );
$check( 'marking a channel as read clears its count', 200 === $s && 0 === (int) ( $r['channels']['ff-test'] ?? 0 ), $r['channels'] ?? $s );
$as( $team );
list( $s, $x ) = $req( 'POST', 'threads/' . $st . '/edit', array( 'title' => 'Neuer Titel', 'body' => 'nichts' ) );
$check( 'the thread title can be edited', 200 === $s && 'Neuer Titel' === $x['title'], $x['title'] ?? $s );

WP_CLI::log( 'Notifications and mail preferences' );
$prefs_before = array( Flurfunk\Notify\prefs( $team2 ), Flurfunk\Notify\prefs( $guest ) );
$as( $team );
$flush = function ( callable $fn ) use ( &$mailed ) {
	global $wpdb;
	$mailed = array();
	$max    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}flurfunk_notifications" );
	$fn();
	Flurfunk\Notify\deliver();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, kind FROM {$wpdb->prefix}flurfunk_notifications WHERE id > %d", $max ) );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (int) $r->user_id ] = $r->kind;
	}
	return array( $out, $mailed );
};
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'none', 'push' => 'none' ) );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => "An <@$team2>" ) ) );
$check( 'a mention always lands in the notification list', 'mention' === ( $notes[ $team2 ] ?? '' ), $notes );
$check( '"never" gets no mail even when mentioned', ! in_array( $team2, $m, true ), $m );
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'all' ) );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Für alle' ) ) );
$check( '"everything" gets mail without mention', in_array( $team2, $m, true ) && 'message' === ( $notes[ $team2 ] ?? '' ), array( $notes, $m ) );
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'mentions' ) );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Ohne Erwähnung' ) ) );
$check( '"mentions" gets nothing for unrelated threads', ! isset( $notes[ $team2 ] ) && ! in_array( $team2, $m, true ), array( $notes, $m ) );
Flurfunk\Notify\save_prefs( $team2, array( 'channel' => array( 'slug' => 'ff-test', 'level' => 'all' ) ) );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Kanal folgen' ) ) );
$check( 'a channel set to "every post" notifies and mails even with "mentions"', 'channel' === ( $notes[ $team2 ] ?? '' ) && in_array( $team2, $m, true ), array( $notes, $m ) );
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'all', 'channel' => array( 'slug' => 'ff-test', 'level' => 'mentions' ) ) );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Kanal leise' ) ) );
$check( 'a channel set to "only mentions" stays quiet even with "everything"', ! isset( $notes[ $team2 ] ) && ! in_array( $team2, $m, true ), array( $notes, $m ) );
list( $notes ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => "Leise, aber <@$team2>" ) ) );
$check( '… but mentions still get through', 'mention' === ( $notes[ $team2 ] ?? '' ), $notes );
$check( 'channel levels are validated', ! isset( Flurfunk\Notify\save_prefs( $team2, array( 'channel' => array( 'slug' => 'x', 'level' => 'loud' ) ) )['channels']['x'] ) );
Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'mentions', 'channel' => array( 'slug' => 'ff-test', 'level' => 'default' ) ) );
Flurfunk\Notify\save_prefs( $team2, array( 'channel' => array( 'slug' => '2025', 'level' => 'all' ) ) );
$check( 'channels with a numeric name keep their level', 'all' === ( Flurfunk\Notify\prefs( $team2 )['channels'][2025] ?? '' ) );
Flurfunk\Notify\save_prefs( $team2, array( 'channel' => array( 'slug' => '2025', 'level' => 'default' ) ) );
$check( 'an array as pause does not break', '' === Flurfunk\Notify\save_prefs( $team2, array( 'pause' => array( 1 ) ) )['pause'] );
$check( '"default" removes the channel setting', ! isset( Flurfunk\Notify\prefs( $team2 )['channels']['ff-test'] ) );
list( $notes, $m ) = $flush( fn () => Store\add_message( $t2['id'], array( 'body' => 'Weiter geht es' ) ) );
$check( 'guest gets a reply notification in a thread about their post', 'reply' === ( $notes[ $guest ] ?? '' ), $notes );
$check( 'nobody is notified about their own message', ! isset( $notes[ $team ] ) && ! in_array( $team, $m, true ) );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => "Intern <@$guest>" ) ) );
$check( 'guest gets nothing from threads they cannot see', ! isset( $notes[ $guest ] ) && ! in_array( $guest, $m, true ), $notes );

WP_CLI::log( 'Tasks' );
$as( $team );
$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
list( $notes ) = $flush(
	function () use ( $req, $team2, $guest, &$task ) {
		list( , $task ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'title' => 'Aufgabe', 'body' => 'machen', 'due' => '2030-02-30', 'assignees' => array( $team2, $guest ) ) );
	}
);
$check( 'a new task drops impossible dates and people who cannot see it', 'open' === $task['status'] && null === $task['due'] && array( $team2 ) === $task['assignees'], array( $task['due'], $task['assignees'] ) );
$check( 'the assignee is notified', 'assigned' === ( $notes[ $team2 ] ?? '' ), $notes );
list( $s, $x ) = $req( 'POST', 'threads/' . $task['id'] . '/task', array( 'due' => $yesterday, 'assignees' => array( $team2, $team ) ) );
$ev = array_values( array_filter( $x['messages'], fn ( $mm ) => 'event' === $mm['type'] ) );
$check( 'changes are logged as events', 2 === count( $ev ) && 'due' === $ev[0]['event'] && $yesterday === $ev[0]['due'] && array( $team ) === $ev[1]['added'], $ev );
$as( $team2 );
list( $s, $l ) = $req( 'GET', 'threads', null, array( 'view' => 'mine' ) );
$check( '"my tasks" lists it', in_array( $task['id'], wp_list_pluck( $l, 'id' ), true ) );
$c = Store\counts( $team2 );
$check( 'counts show my tasks and the overdue one', $c['mine'] >= 1 && $c['overdue'] >= 1, array( $c['mine'], $c['overdue'] ) );
list( $notes ) = $flush( fn () => Flurfunk\Notify\remind_due( true ) );
$check( 'due reminders go to assignees of overdue tasks', 'due' === ( $notes[ $team2 ] ?? '' ), $notes );
list( $notes ) = $flush( fn () => Flurfunk\Notify\remind_due( true ) );
$check( '… only once a day', ! isset( $notes[ $team2 ] ), $notes );
list( $notes ) = $flush( fn () => $req( 'POST', 'threads/' . $task['id'] . '/status', array( 'status' => 'done' ) ) );
$check( 'ticking off notifies author and the other assignees, not oneself', 'done' === ( $notes[ $team ] ?? '' ) && ! isset( $notes[ $team2 ] ), $notes );
$as( $team );
list( $s, $x ) = $req( 'POST', 'threads/' . $task['id'] . '/task', array( 'due' => '' ) );
$check( 'the due date can be removed', null === $x['due'] );

WP_CLI::log( 'Follow and mute' );
$as( $team );
$ft = Store\create_thread( array( 'channel' => 'ff-test', 'body' => 'Folgen-Test' ) );
Store\set_follow( $ft, $team2, 1 );
list( $notes ) = $flush( fn () => Store\add_message( $ft, array( 'body' => 'neu' ) ) );
$check( 'followers hear about replies they took no part in', 'reply' === ( $notes[ $team2 ] ?? '' ), $notes );
Store\set_follow( $ft, $team2, -1 );
$as( $team2 );
Store\add_message( $ft, array( 'body' => 'ich schreibe auch' ) );
$as( $team );
list( $notes ) = $flush( fn () => Store\add_message( $ft, array( 'body' => 'noch was' ) ) );
$check( 'muted threads stay quiet even for participants', ! isset( $notes[ $team2 ] ), $notes );
list( $notes ) = $flush( fn () => Store\add_message( $ft, array( 'body' => "<@$team2> wichtig" ) ) );
$check( '… but mentions still get through', 'mention' === ( $notes[ $team2 ] ?? '' ), $notes );
$as( $team2 );
list( $s, $r ) = $req( 'POST', 'threads/' . $ft . '/follow', array( 'mode' => 'follow' ) );
list( $s2, $x ) = $req( 'GET', 'threads/' . $ft );
$check( 'follow can be set through the API and shows in the thread', 1 === $r['follow'] && 1 === $x['follow'], array( $r, $x['follow'] ?? null ) );
Flurfunk\Notify\save_prefs( $team2, array( 'hour' => ( (int) wp_date( 'G' ) + 3 ) % 24 ) );
$check( 'the digest hour is respected', ! Flurfunk\Notify\is_their_hour( $team2 ) );
Flurfunk\Notify\save_prefs( $team2, array( 'hour' => (int) wp_date( 'G' ) ) );
$check( '… and it is their hour now', Flurfunk\Notify\is_their_hour( $team2 ) );

WP_CLI::log( 'Notification list' );
$as( $team2 );
list( $s, $list ) = $req( 'GET', 'notifications', null, array( 'limit' => 50 ) );
$check( 'the list holds my notifications with words', $list && ! empty( $list[0]['headline'] ) && ! empty( $list[0]['topic'] ), $list[0] ?? $s );
$as( $guest );
list( $s, $gl ) = $req( 'GET', 'notifications', null, array( 'limit' => 50 ) );
$check( 'the list never shows threads one cannot see', ! array_filter( $gl, fn ( $n ) => ! Flurfunk\Access\can_see( Store\thread_row( $n['thread'] ), $guest ) ) );
$as( $team2 );
$unread = Flurfunk\Notify\unread( $team2 );
$req( 'GET', 'threads/' . $task['id'] );
$check( 'opening a thread marks its notifications read', Flurfunk\Notify\unread( $team2 ) < $unread, array( $unread, Flurfunk\Notify\unread( $team2 ) ) );
list( $s, $c ) = $req( 'POST', 'notifications', array( 'all' => true ) );
$check( 'mark all read', 0 === (int) $c['notifications'], $c['notifications'] ?? $s );

global $wpdb;
$wpdb->insert( $wpdb->prefix . 'flurfunk_notifications', array( 'user_id' => $team2, 'thread_id' => $t2['id'], 'kind' => 'reply', 'created' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ), 'read_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$old = (int) $wpdb->insert_id;
$wpdb->insert( $wpdb->prefix . 'flurfunk_notifications', array( 'user_id' => $team2, 'thread_id' => $t2['id'], 'kind' => 'reply', 'created' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ) );
$young = (int) $wpdb->insert_id;
Flurfunk\Notify\prune();
$check( 'old read notifications are pruned, unread ones kept longer', ! $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}flurfunk_notifications WHERE id = $old" ) && $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}flurfunk_notifications WHERE id = $young" ) );

WP_CLI::log( 'Media library references' );
$lib = wp_insert_attachment( array( 'post_title' => 'ff-test-bibliothek', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $team ), false );
$check( 'a guest cannot refer to someone else\'s library file', array() === Store\files( array( $lib ), $guest, true ) );
$check( 'the team can', 1 === count( Store\files( array( $lib ), $team2, true ) ) );
$bot_id = (int) ( get_users( array( 'meta_key' => 'flurfunk_bot', 'fields' => 'ID', 'number' => 1 ) )[0] ?? 0 );
if ( $bot_id ) {
	$check( 'an assistant can', 1 === count( Store\files( array( $lib ), $bot_id, true ) ) );
}
$check( 'up to 50 files per message', 50 === count( Store\files( array_fill( 0, 60, $lib ), $team2, true ) ) );
wp_delete_attachment( $lib, true );

WP_CLI::log( 'Board states' );
$as( $team );
list( $s, $bt ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'title' => 'Board', 'body' => 'x', 'task' => true, 'assignees' => array( $team2 ) ) );
list( $s ) = $req( 'POST', 'threads/' . $bt['id'] . '/status', array( 'status' => 'doing' ) );
$check( '"in progress" is a state', 200 === $s && 'doing' === Store\thread_row( $bt['id'] )->status );
$ids = wp_list_pluck( Store\list_threads( $team, array( 'view' => 'tasks', 'limit' => 200 ) ), 'id' );
$check( 'tasks in progress still count as open tasks', in_array( (int) $bt['id'], array_map( 'intval', $ids ), true ) );
Flurfunk\Notify\deliver(); // What the requests above queued.
list( $notes ) = $flush( fn () => Store\set_status( $bt['id'], 'waiting' ) );
$check( '"question" notifies the responsible people', 'waiting' === ( $notes[ $team2 ] ?? '' ), $notes );
list( $s ) = $req( 'POST', 'threads/' . $bt['id'] . '/status', array( 'status' => 'party' ) );
$check( 'unknown states are refused', 403 === $s || 400 === $s, $s );
Store\set_status( $bt['id'], 'done' );
Flurfunk\Notify\deliver();
$board = wp_list_pluck( Store\list_threads( $team, array( 'view' => 'board', 'limit' => 200 ) ), 'id' );
$check( 'the board shows recently ticked-off tasks', in_array( (int) $bt['id'], array_map( 'intval', $board ), true ) );
$mine = wp_list_pluck( Store\list_threads( $team, array( 'view' => 'board', 'person' => $team2, 'limit' => 200 ) ), 'id' );
$check( 'the board filters by person', in_array( (int) $bt['id'], array_map( 'intval', $mine ), true ) && ! in_array( (int) $t2['id'], array_map( 'intval', $mine ), true ) );

WP_CLI::log( 'Files on the first post' );
$as( $team );
$tf = wp_insert_attachment( array( 'post_title' => 'ff-test-start', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit', 'post_author' => $team ), false );
list( $s, $ft ) = $req( 'POST', 'threads', array( 'channel' => 'ff-test', 'files' => array( $tf ) ) );
$check( 'a thread can start with only a file', 200 === $s && 1 === count( $ft['files'] ?? array() ), $ft['files'] ?? $s );
wp_delete_attachment( $tf, true );
$check( 'the people list follows the team and guest capabilities', in_array( $guest, array_map( static fn ( $p ) => (int) $p['id'], Store\people() ), true ) );

WP_CLI::log( 'Reminders' );
$as( $team2 );
list( $s, $r ) = $req( 'POST', 'threads/' . $t2['id'] . '/remind', array( 'when' => 'tomorrow' ) );
$check( 'a reminder for tomorrow is set at the person\'s hour', 200 === $s && $r['remind'] && wp_date( 'Y-m-d G', strtotime( $r['remind'] ) ) === wp_date( 'Y-m-d', strtotime( '+1 day' ) ) . ' ' . Flurfunk\Notify\prefs( $team2 )['hour'], $r );
list( $s, $x ) = $req( 'GET', 'threads/' . $t2['id'], null, array( 'peek' => 1 ) );
$check( 'the thread shows my reminder', 200 === $s && $x['remind'] === $r['remind'], $x['remind'] ?? $s );
list( $s ) = $req( 'POST', 'threads/' . $t2['id'] . '/remind', array( 'when' => '2001-01-01T10:00:00Z' ) );
$check( 'a reminder in the past is refused', 400 === $s, $s );
list( $s ) = $req( 'POST', 'threads/' . $t2['id'] . '/remind', array( 'when' => 'gestern irgendwann' ) );
$check( 'nonsense is refused', 400 === $s, $s );
$check( '"monday" lies in the future on a Monday', strtotime( Store\remind_time( 'monday', $team2 ) . ' UTC' ) > time() + DAY_IN_SECONDS );
Store\set_reminder( $t2['id'], $team2, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
$as( $team );
$hq = (int) wp_date( 'G' );
Flurfunk\Notify\save_prefs( $team2, array( 'quiet' => sprintf( '%02d:00-%02d:59', $hq, $hq ) ) );
list( $notes ) = $flush( fn () => Flurfunk\Notify\send_reminders() );
$check( 'reminders wait during quiet hours', ! isset( $notes[ $team2 ] ) && null !== Store\reminder( $t2['id'], $team2 ), $notes );
Flurfunk\Notify\save_prefs( $team2, array( 'quiet' => '' ) );
list( $notes ) = $flush( fn () => Flurfunk\Notify\send_reminders() );
$check( 'a due reminder becomes a notification', 'remind' === ( $notes[ $team2 ] ?? '' ), $notes );
list( $notes ) = $flush( fn () => Flurfunk\Notify\send_reminders() );
$check( '… exactly once', ! isset( $notes[ $team2 ] ) && null === Store\reminder( $t2['id'], $team2 ), $notes );
Store\set_reminder( $t2['id'], $guest, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
Store\set_reminder( $internal, $guest, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
list( $notes ) = $flush( fn () => Flurfunk\Notify\send_reminders() );
$check( 'reminders for threads someone can no longer see are dropped', null === Store\reminder( $internal, $guest ) );
$as( $team2 );
list( $s, $r ) = $req( 'POST', 'threads/' . $t2['id'] . '/remind', array( 'when' => 'none' ) );
$check( 'a reminder can be removed', 200 === $s && null === $r['remind'], $r );

WP_CLI::log( 'Digest, quiet hours, push' );
$fake = static fn ( $n ) => array( 'endpoint' => 'https://push.example.test/' . $n, 'keys' => array( 'p256dh' => Flurfunk\Push\b64( "\x04" . random_bytes( 64 ) ), 'auth' => Flurfunk\Push\b64( random_bytes( 16 ) ) ) );
Flurfunk\Push\subscribe( $team2, $fake( 'renew-old' ), 'Test' );
$check( 'renewal with an unknown endpoint is refused', ! Flurfunk\Push\renew( 'https://push.example.test/nobody', $fake( 'x' ) ) );
Flurfunk\Push\subscribe( $team2, $fake( 'renew-old' ), 'Test' );
$moved = $fake( 'x' );
$moved['endpoint'] = 'https://evil.example.test/x';
$check( 'renewal cannot move pushes to another host', ! Flurfunk\Push\renew( 'https://push.example.test/renew-old', $moved ) );
$check( 'renewal replaces the old subscription', Flurfunk\Push\renew( 'https://push.example.test/renew-old', $fake( 'renew-new' ) ) && 1 === count( array_filter( Flurfunk\Push\devices( $team2 ), static fn ( $d ) => str_contains( $d->endpoint, 'renew-' ) ) ) && array_filter( Flurfunk\Push\devices( $team2 ), static fn ( $d ) => str_ends_with( $d->endpoint, 'renew-new' ) && 'Test' === $d->label ) );
Flurfunk\Push\unsubscribe( $team2, 'https://push.example.test/renew-new' );

Flurfunk\Notify\save_prefs( $team2, array( 'mail' => 'digest' ) );
delete_user_meta( $team2, 'flurfunk_digest_last' );
$as( $team );
list( $notes, $m ) = $flush( fn () => Store\create_thread( array( 'channel' => 'ff-test', 'body' => "Für die Zusammenfassung <@$team2>" ) ) );
$check( 'digest people get no immediate mail', ! in_array( $team2, $m, true ) && 'mention' === ( $notes[ $team2 ] ?? '' ) );
$mailed = array();
Flurfunk\Notify\send_digests( true );
$check( 'the daily digest mails them once', 1 === count( array_keys( $mailed, $team2, true ) ), $mailed );
$mailed = array();
Flurfunk\Notify\send_digests( true );
$check( '… and not again for the same notifications', ! in_array( $team2, $mailed, true ) );
$now = wp_date( 'H:i' );
$h   = (int) substr( $now, 0, 2 );
Flurfunk\Notify\save_prefs( $team2, array( 'quiet' => sprintf( '%02d:00-%02d:59', $h, $h ) ) );
$check( 'quiet hours are recognised', Flurfunk\Notify\is_quiet( $team2 ) );
Flurfunk\Notify\save_prefs( $team2, array( 'quiet' => sprintf( '%02d:00-%02d:00', ( $h + 2 ) % 24, ( $h + 3 ) % 24 ) ) );
$check( '… and outside them push is allowed', ! Flurfunk\Notify\is_quiet( $team2 ) );
$p = Flurfunk\Notify\save_prefs( $team2, array( 'pause' => '3h' ) );
$check( 'pausing push for three hours', $p['pause'] && abs( strtotime( $p['pause'] ) - time() - 3 * HOUR_IN_SECONDS ) < 120 && Flurfunk\Notify\is_quiet( $team2 ), $p['pause'] );
$p = Flurfunk\Notify\save_prefs( $team2, array( 'pause' => 'none' ) );
$check( '… and resuming', '' === $p['pause'] && ! Flurfunk\Notify\is_quiet( $team2 ), $p['pause'] );
update_user_meta( $team2, 'flurfunk_prefs_pause', gmdate( 'Y-m-d H:i:s', time() - 60 ) );
$check( 'a pause that ran out no longer counts', '' === Flurfunk\Notify\prefs( $team2 )['pause'] );
$check( 'nonsense does not pause', '' === Flurfunk\Notify\save_prefs( $team2, array( 'pause' => 'forever' ) )['pause'] );
$as( $team2 );
list( $s ) = $req( 'POST', 'me/push', array( 'subscription' => array( 'endpoint' => 'https://push.invalid/x', 'keys' => array( 'p256dh' => 'abc', 'auth' => 'def' ) ) ) );
$check( 'invalid push subscriptions are refused', 400 === $s, $s );
if ( Flurfunk\Push\available() ) {
	$k = Flurfunk\Push\new_key();
	list( $s, $r ) = $req( 'POST', 'me/push', array( 'subscription' => array( 'endpoint' => 'https://push.invalid/ff-test', 'keys' => array( 'p256dh' => Flurfunk\Push\b64( Flurfunk\Push\raw_public( $k ) ), 'auth' => Flurfunk\Push\b64( random_bytes( 16 ) ) ) ) ) );
	$check( 'a valid subscription is stored', 200 === $s && $r['devices'] >= 1, $r );
	for ( $i = 0; $i < 6; $i++ ) {
		Flurfunk\Push\send( $team2, array( 'title' => 'x' ) );
	}
	$left = array_filter( Flurfunk\Push\devices( $team2 ), fn ( $d ) => str_contains( $d->endpoint, 'push.invalid' ) );
	$check( 'a subscription that keeps failing is dropped', ! $left, count( $left ) );
	$check( 'VAPID keys exist and stay the same', Flurfunk\Push\vapid()['public'] === Flurfunk\Push\vapid()['public'] && 87 === strlen( Flurfunk\Push\vapid()['public'] ) );
}
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
	foreach ( array( 'messages', 'mentions', 'reads', 'attachments', 'assignees', 'notifications' ) as $tb ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}flurfunk_$tb WHERE thread_id IN ($in)" );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}flurfunk_threads WHERE id IN ($in)" );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}flurfunk_channels WHERE slug IN ('ff-test','ff-test-archived')" );
WP_CLI::log( "  removed " . count( $ids ) . ' test threads' );

WP_CLI::log( '' );
$fails ? WP_CLI::error( "$fails of $runs checks failed." ) : WP_CLI::success( "All $runs checks passed." );

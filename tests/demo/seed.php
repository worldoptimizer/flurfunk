<?php
/**
 * Demo content for screenshots and trying Flurfunk out: a small fictional editorial team
 * ("Northline Magazine") with channels, posts, conversations, tasks, files, a card and
 * notifications. Safe to run more than once: people, posts and threads that exist are kept.
 *
 *   wp eval-file wp-content/plugins/flurfunk/tests/demo/seed.php [reset-passwords]
 *
 * Or in the browser, logged in as an administrator: open this file's address (inside the
 * plugin, or copied into the WordPress root) and confirm. Without a login, define
 * FLURFUNK_DEMO_SECRET in wp-config.php and add ?secret=… – each secret works once.
 *
 * Demo accounts get random passwords, printed once when they are created (or again with
 * reset-passwords). Mail and push are off for them while the content is created. For the
 * profile pictures and job titles, copy tests/demo/demo-avatars.php into wp-content/mu-plugins.
 *
 * Only for test and demo sites. Times are relative to now, so the content always looks recent.
 *
 * @package Flurfunk
 */

// phpcs:disable WordPress.DB, WordPress.Security.EscapeOutput

use Flurfunk\Store;
use function Flurfunk\Schema\table;

if ( ! defined( 'ABSPATH' ) ) {
	// Called in the browser: find WordPress above this file.
	$flurfunk_demo_dir = __DIR__;
	for ( $i = 0; $i < 8 && ! is_file( $flurfunk_demo_dir . '/wp-load.php' ); $i++ ) {
		$flurfunk_demo_dir = dirname( $flurfunk_demo_dir );
	}
	if ( ! is_file( $flurfunk_demo_dir . '/wp-load.php' ) ) {
		http_response_code( 500 );
		exit( 'WordPress not found.' );
	}
	require $flurfunk_demo_dir . '/wp-load.php';
	flurfunk_demo_gate();
}

/**
 * Browser only: an administrator who confirms the form, or a one-time secret.
 */
function flurfunk_demo_gate(): void {
	nocache_headers();
	$secret = (string) ( $_GET['secret'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' !== $secret ) {
		$want = defined( 'FLURFUNK_DEMO_SECRET' ) ? (string) FLURFUNK_DEMO_SECRET : '';
		$used = (string) get_option( 'flurfunk_demo_secret_used', '' );
		if ( strlen( $want ) < 16 || ! hash_equals( $want, $secret ) || hash_equals( $used, wp_hash( $want ) ) ) {
			http_response_code( 403 );
			exit( 'Wrong or used secret (FLURFUNK_DEMO_SECRET, at least 16 characters, works once).' );
		}
		update_option( 'flurfunk_demo_secret_used', wp_hash( $want ), false );
		header( 'Content-Type: text/plain; charset=utf-8' );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		auth_redirect();
		http_response_code( 403 );
		exit( 'Administrators only.' );
	}
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'flurfunk_demo' ) ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><meta charset="utf-8"><title>Flurfunk demo content</title><body style="font:16px/1.5 system-ui;max-width:36em;margin:3em auto">'
			. '<h1>Flurfunk demo content</h1><p>Creates the fictional Northline Magazine team: five people and an assistant, channels, four posts, reader comments and about a dozen conversations. Only for test and demo sites.</p>'
			. '<form method="post">' . wp_nonce_field( 'flurfunk_demo', '_wpnonce', true, false )
			. '<p><label><input type="checkbox" name="reset-passwords" value="1"> New passwords for existing demo accounts</label></p>'
			. '<p><button type="submit" id="flurfunk-demo-run">Create demo content</button></p></form>';
		exit;
	}
	header( 'Content-Type: text/plain; charset=utf-8' );
}

/**
 * Output a line (WP-CLI or browser).
 *
 * @param string $line Text.
 */
function flurfunk_demo_say( string $line ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $line );
	} else {
		echo $line, "\n";
	}
}

if ( ! function_exists( 'Flurfunk\Store\create_thread' ) ) {
	flurfunk_demo_say( 'Activate Flurfunk first.' );
	return;
}

$flurfunk_demo_reset = in_array( 'reset-passwords', (array) ( $args ?? array() ), true ) || ! empty( $_REQUEST['reset-passwords'] ); // phpcs:ignore WordPress.Security.NonceVerification

( static function ( bool $reset ): void {
	global $wpdb;

	$base = is_dir( __DIR__ . '/images' ) ? __DIR__ : FLURFUNK_DIR . 'tests/demo';
	$now  = time();
	$was  = get_current_user_id();
	// Hours ago, a few minutes earlier depending on $salt, so not every time ends in the same minute.
	$ago  = static fn ( float $hours, string $salt = '' ): string => gmdate( 'c', (int) ( $now - $hours * HOUR_IN_SECONDS - ( '' === $salt ? 0 : crc32( $salt ) % 23 * MINUTE_IN_SECONDS ) ) );
	$day  = static fn ( int $offset ): string => wp_date( 'Y-m-d', $now + $offset * DAY_IN_SECONDS );
	$nice = static fn ( int $offset ): string => wp_date( 'l, j F', $now + $offset * DAY_IN_SECONDS );

	add_filter( 'flurfunk_rate_limit', '__return_zero' );

	/* People ------------------------------------------------------------ */

	$people = array(
		'ana'   => array( 'ana.klein', 'Ana', 'Klein', 'editor', 'Editor-in-chief' ),
		'tom'   => array( 'tom.berger', 'Tom', 'Berger', 'editor', 'Photo editor' ),
		'mia'   => array( 'mia.park', 'Mia', 'Park', 'author', 'Writer' ),
		'jonas' => array( 'jonas.weber', 'Jonas', 'Weber', 'editor', 'Developer' ),
		'lea'   => array( 'lea.okafor', 'Lea', 'Okafor', 'author', 'Social media' ),
	);
	$u = array();
	foreach ( $people as $key => [ $login, $first, $last, $role, $job ] ) {
		$user = get_user_by( 'login', $login );
		$pass = '';
		if ( ! $user ) {
			$pass = wp_generate_password( 20, false );
			$id   = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => $pass,
					'user_email'   => $login . '@northline.example',
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => $first . ' ' . $last,
					'nickname'     => $first,
					'description'  => $job . ' at Northline Magazine.',
					'role'         => $role,
				)
			);
			if ( is_wp_error( $id ) ) {
				flurfunk_demo_say( 'Could not create ' . $login . ': ' . $id->get_error_message() );
				return;
			}
			$user = get_userdata( $id );
		} elseif ( $reset ) {
			$pass = wp_generate_password( 20, false );
			wp_set_password( $pass, $user->ID );
		}
		$u[ $key ] = (int) $user->ID;
		update_user_meta( $user->ID, 'flurfunk_demo_avatar', $key . '.jpg' );
		update_user_meta( $user->ID, 'flurfunk_demo_role', $job );
		// Nothing leaves the site while the content is created; Ana's choices follow at the end.
		\Flurfunk\Notify\save_prefs( $user->ID, array( 'mail' => 'none', 'push' => 'none' ) );
		if ( '' !== $pass ) {
			flurfunk_demo_say( sprintf( 'User %-12s password %s', $login, $pass ) );
		}
	}
	$bot = \Flurfunk\Access\create_bot( 'Assistant', 'northline-assistant' );
	if ( is_wp_error( $bot ) ) {
		flurfunk_demo_say( 'Could not create the assistant: ' . $bot->get_error_message() );
		return;
	}
	$u['bot'] = (int) $bot;
	$m        = static fn ( string $who ): string => '<@' . $u[ $who ] . '>';

	/* Settings and channels ------------------------------------------------ */

	$settings = (array) get_option( \Flurfunk\Settings\OPTION, array() );
	if ( '' === (string) ( $settings['name'] ?? '' ) ) {
		$settings['name'] = 'Northline';
		update_option( \Flurfunk\Settings\OPTION, $settings, true );
		\Flurfunk\Settings\get( true );
	}
	$channels = array(
		'general'   => array( 'General', 'Everyone at Northline, guest writers included', 'all', 0 ),
		'editorial' => array( 'Editorial', 'Planning, copy and the issue schedule', 'none', 1 ),
		'photo'     => array( 'Photo', 'Shoots, picture selection and rights', 'none', 2 ),
		'tech'      => array( 'Tech', 'Website, newsletter and the CMS', 'none', 3 ),
		'authors'   => array( 'Authors', 'With our writers, about their own pieces', 'own-posts', 4 ),
	);
	foreach ( $channels as $slug => [ $name, $topic, $guests, $pos ] ) {
		Store\ensure_channel(
			$slug,
			array(
				'name'          => $name,
				'topic'         => $topic,
				'access_team'   => 'all',
				'access_guests' => $guests,
				'position'      => $pos,
			),
			true
		);
	}
	Store\channels( true );

	/* Posts ---------------------------------------------------------------- */

	$image = static function ( string $file, string $title, int $post_id ) use ( $base ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( $file );
		copy( $base . '/images/' . $file, $tmp );
		$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), $post_id, $title );
		return is_wp_error( $id ) ? 0 : (int) $id;
	};
	$p = array();
	foreach (
		array(
			'cover'      => array( 'ana', 'Autumn issue: cover story', 'draft', 9, 'autumn-market.jpg', 'Lanterns, cider and the last warm evenings: a night at the harbour market, where the town says goodbye to summer.' ),
			'interview'  => array( 'mia', 'Interview with a lighthouse keeper', 'pending', 4, '', 'Forty years on the rock: he kept the light burning through storms, fog and one very long winter.' ),
			'newsletter' => array( 'lea', 'Newsletter: November', 'draft', 1, '', 'What is coming in the autumn issue, plus the stories you read most in October.' ),
			'walks'      => array( 'mia', 'Ten walks for a foggy weekend', 'publish', 12, 'autumn-forest.jpg', 'From the beech forest above the bay to the old tow path: ten routes that look their best in the mist.' ),
		) as $key => [ $who, $title, $status, $days, $img, $lead ]
	) {
		$found = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_key'    => '_flurfunk_demo', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		if ( $found ) {
			$p[ $key ] = (int) $found[0];
			continue;
		}
		wp_set_current_user( $u[ $who ] );
		$date = wp_date( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$id   = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => $status,
				'post_author'   => $u[ $who ],
				'post_title'    => $title,
				'post_excerpt'  => $lead,
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'post_content'  => "<!-- wp:paragraph -->\n<p>" . esc_html( $lead ) . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Draft text follows. This post belongs to the Flurfunk demo content.</p>\n<!-- /wp:paragraph -->",
				'meta_input'    => array( '_flurfunk_demo' => $key ),
			)
		);
		$p[ $key ] = (int) $id;
		if ( $img && $id ) {
			$att = $image( $img, $title, (int) $id );
			if ( $att ) {
				set_post_thumbnail( (int) $id, $att );
			}
		}
	}

	/* Reader comments on the published post, one still waiting for approval -- */

	$c = array();
	foreach (
		array(
			'clara' => array( 'Clara Jansen', 'We did walk number four last Sunday. The fog lifted just as we reached the top – thank you for the tip!', 1, 110, '' ),
			'ben'   => array( 'Ben Achterberg', 'Is the old tow path fine with a pram?', 1, 70, '' ),
			'mia'   => array( 'mia', 'Yes, the first two kilometres from the lock are paved, after that it gets bumpy.', 1, 66, 'ben' ),
			'oskar' => array( 'Oskar Lind', 'Careful with walk seven: the path between the mill and the bridge has been closed since the September storm. Maybe add a note?', 0, 5, '' ),
		) as $key => [ $who, $text, $approved, $hours, $parent ]
	) {
		$found = get_comments(
			array(
				'post_id'    => $p['walks'],
				'meta_key'   => '_flurfunk_demo', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'status'     => 'all',
				'fields'     => 'ids',
				'number'     => 1,
			)
		);
		if ( $found ) {
			$c[ $key ] = (int) $found[0];
			continue;
		}
		$user = isset( $u[ $who ] ) ? get_userdata( $u[ $who ] ) : null;
		$when = wp_date( 'Y-m-d H:i:s', (int) ( $now - $hours * HOUR_IN_SECONDS ) );
		$id   = wp_insert_comment(
			array(
				'comment_post_ID'      => $p['walks'],
				'comment_author'       => $user ? $user->display_name : $who,
				'comment_author_email' => $user ? $user->user_email : strtolower( strtok( $who, ' ' ) ) . '@example.com',
				'comment_content'      => $text,
				'comment_approved'     => $approved,
				'comment_parent'       => $parent ? (int) ( $c[ $parent ] ?? 0 ) : 0,
				'user_id'              => $user ? $user->ID : 0,
				'comment_date'         => $when,
				'comment_date_gmt'     => get_gmt_from_date( $when ),
				'comment_meta'         => array( '_flurfunk_demo' => $key ),
			)
		);
		$c[ $key ] = (int) $id;
	}

	/* Conversations -------------------------------------------------------- */

	$top   = static fn (): array => array(
		'm' => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . table( 'messages' ) ),
		'n' => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . table( 'notifications' ) ),
	);
	// Run one action as one person at one moment: whatever it wrote gets that time.
	$at = static function ( string $who, string $when, callable $fn ) use ( $u, $top, $wpdb ) {
		wp_set_current_user( $u[ $who ] );
		$before = $top();
		$result = $fn();
		\Flurfunk\Notify\deliver();
		$time = Store\mysql( $when );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'messages' ) . " SET created = %s WHERE id > %d AND type <> 'message'", $time, $before['m'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'notifications' ) . ' SET created = %s WHERE id > %d', $time, $before['n'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'reads' ) . ' SET seen = %s WHERE user_id = %d AND seen > %s', $time, $u[ $who ], $time ) );
		if ( is_wp_error( $result ) ) {
			flurfunk_demo_say( 'Skipped a step: ' . $result->get_error_message() );
		}
		return $result;
	};
	$photo = static function ( string $file ) use ( $base ) {
		return \Flurfunk\Files\store( $file, (string) file_get_contents( $base . '/images/' . $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	};

	$transcript = "Northline Magazine – interview transcript (excerpt)\nRecorded at the lighthouse, 3 October\n\n"
		. "MP: How long have you looked after the light?\nHA: Forty-one years. I came for one summer and never left.\n\n"
		. "MP: What was the hardest night?\nHA: The storm of 1962, before my time here – my predecessor told me about it so often that I feel I was there.\n\n"
		. "MP: And now that the light runs on its own?\nHA: I still climb up every evening. Someone has to say good night to it.\n";

	$threads = array(
		'schedule'          => array(
			'channel' => 'editorial',
			'by'      => 'ana',
			'at'      => 98,
			'title'   => 'Autumn issue: schedule',
			'body'    => "Here is the plan for the autumn issue:\n- copy deadline: " . $nice( 6 ) . "\n- layout: " . $nice( 9 ) . ' to ' . $nice( 11 ) . "\n- print: " . $nice( 16 ) . "\n\nShout if anything clashes with your plans.",
			'steps'   => array(
				array( 'tom', 96, 'Works for me, as long as I get the archive pictures for the lighthouse piece by ' . $nice( 3 ) . '.' ),
				array( 'jonas', 94, "I'll prepare the landing page, so the issue goes live on the site the day it goes to print." ),
				array( 'ana', 93, 'Perfect, thanks both. The dates are in the shared calendar now.' ),
			),
		),
		'winter-shoot'      => array(
			'channel' => 'photo',
			'by'      => 'tom',
			'at'      => 140,
			'title'   => 'Shoot list for the winter issue',
			'body'    => "First ideas for the winter shoots:\n- the harbour at blue hour\n- the bakery on Mill Street at 4 am\n- portraits for the reader stories\n\nAnything missing?",
			'steps'   => array(
				array( 'ana', 138, 'Add the ice swimmers at the lake – Mia is pitching a piece about them.' ),
				array( 'tom', 137, '*Good one*, added. I will ask them about a Sunday morning.' ),
			),
		),
		'archive-rights'    => array(
			'channel' => 'photo',
			'by'      => 'tom',
			'at'      => 120,
			'target'  => 'interview',
			'title'   => 'Rights for the archive pictures',
			'body'    => "For the lighthouse interview we'd like three pictures from the maritime museum's archive. " . $m( 'ana' ) . ' you have the contact there – could you ask them for written permission?',
			'task'    => array( 'due' => -2, 'assignees' => array( 'ana' ) ),
			'steps'   => array(
				array( 'ana', 100, "I've written to them and I'm waiting for an answer." ),
				array( 'tom', 26, 'Any news? We need them by ' . $nice( 3 ) . ' at the latest, otherwise I will plan a new shoot.' ),
			),
		),
		'interview-draft'   => array(
			'channel' => 'authors',
			'by'      => 'mia',
			'at'      => 75,
			'target'  => 'interview',
			'title'   => 'Lighthouse interview: first draft',
			'body'    => "The first draft is in the editor. I've attached the full transcript in case you want to check a quote.",
			'file'    => array( 'lighthouse-interview-transcript.txt', $transcript ),
			'steps'   => array(
				array( 'ana', 72, 'Great read, ' . $m( 'mia' ) . '! Two small things: the intro is a bit long, and we need his full name and age for the caption.' ),
				array( 'mia', 70, "Thanks! He's Henrik Aalto, 71. I'll add both to the caption and shorten the intro." ),
			),
		),
		'php-update'        => array(
			'channel' => 'tech',
			'by'      => 'ana',
			'at'      => 80,
			'title'   => 'Hosting: PHP update before November',
			'body'    => 'Our host asked us to move to PHP 8.3 before November. ' . $m( 'jonas' ) . ' can you take care of it?',
			'task'    => array( 'due' => 1, 'assignees' => array( 'jonas' ) ),
			'steps'   => array(
				array( 'jonas', 79, 'status:doing' ),
				array( 'jonas', 52, 'Tested on staging, all plugins are fine. Switching tonight.' ),
				array( 'jonas', 28, 'Done – the site runs on PHP 8.3, and pages load a little faster, too.' ),
				array( 'jonas', 27.9, 'status:done' ),
			),
		),
		'prints'            => array(
			'channel' => 'photo',
			'by'      => 'tom',
			'at'      => 70,
			'title'   => 'Prints for the window display',
			'body'    => "The window display needs A2 prints of the three summer covers. I'll order them from the lab.",
			'task'    => array( 'due' => 0, 'assignees' => array( 'tom' ) ),
			'steps'   => array(
				array( 'tom', 54, 'Ordered, they arrive on ' . $nice( 2 ) . '.' ),
				array( 'tom', 53.9, 'status:done' ),
			),
		),
		'interview-length'  => array(
			'channel' => 'authors',
			'by'      => 'ana',
			'at'      => 50,
			'target'  => 'interview',
			'title'   => 'Interview: length and pictures',
			'body'    => $m( 'mia' ) . ' the layout has two pages for the interview: about 9,000 characters plus two pictures. Could you cut the draft to that by today?',
			'task'    => array( 'due' => 0, 'assignees' => array( 'mia' ) ),
			'steps'   => array(
				array( 'mia', 30, "On it. One question before I cut: can the part about the storm of 1962 stay? It's the best story he told me, but it's long." ),
				array( 'mia', 29.9, 'status:waiting' ),
			),
		),
		'gallery-sizes'     => array(
			'channel' => 'tech',
			'by'      => 'jonas',
			'at'      => 48,
			'title'   => 'Image sizes for the new gallery',
			'body'    => "The new gallery layout loads full-size images on phones. I'm adding a 1200 px size and lazy loading.",
			'task'    => array( 'due' => 3, 'assignees' => array( 'jonas' ) ),
			'steps'   => array(
				array( 'jonas', 47.5, 'status:doing' ),
				array( 'tom', 46, 'Can we keep the originals for the lightbox? Readers zoom in on the market pictures.' ),
				array( 'jonas', 45, 'Yes, the lightbox keeps the large version, only the grid gets the smaller one.' ),
			),
		),
		'cover-photos'      => array(
			'channel' => 'editorial',
			'by'      => 'ana',
			'at'      => 30,
			'target'  => 'cover',
			'title'   => 'Cover photos: first selection',
			'body'    => 'We need the opener for the cover story by ' . $nice( 2 ) . '. ' . $m( 'tom' ) . ' could you share a first selection from the market shoot? An upright option would help with the masthead.',
			'task'    => array( 'due' => 2, 'assignees' => array( 'tom' ) ),
			'steps'   => array(
				array( 'tom', 27, 'status:doing' ),
				array( 'tom', 22, "First selection: the cider stall at the harbour market, the forest walk for the second spread, and one from our newsroom for the editor's letter. The market shot is my favourite for the cover.", array( 'autumn-market.jpg', 'autumn-forest.jpg', 'newsroom.jpg' ) ),
				array( 'ana', 20, 'The market one it is. Could you crop it a little tighter on the stall, so the masthead fits above the lanterns?' ),
				array( 'tom', 18, 'Sure. The upright crop comes tomorrow morning, with a second version in case the headline runs long.' ),
				array( 'ana', 3, $m( 'jonas' ) . " once Tom's crop is in, please swap it into the cover preview on the site." ),
				array( 'jonas', 2, "Will do. The preview picks up the featured image, so it's a one-minute job." ),
			),
		),
		'team-lunch'        => array(
			'channel' => 'general',
			'by'      => 'lea',
			'at'      => 26,
			'title'   => 'Team lunch after the copy deadline?',
			'body'    => "Pizza at Vito's on " . $nice( 6 ) . ", after the copy deadline? I'll book a table for six.",
			'steps'   => array(
				array( 'tom', 25, "I'm in." ),
				array( 'mia', 24, 'Yes please!' ),
				array( 'jonas', 6, "Count me in, I'll be fifteen minutes late though." ),
			),
		),
		'weekly-report'     => array(
			'channel' => 'general',
			'by'      => 'bot',
			'at'      => 8,
			'title'   => 'Weekly site report',
			'body'    => "*Last 7 days on the website*\n- 12,480 visits (+8% on the week before)\n- most read: Ten walks for a foggy weekend\n- 214 new newsletter subscribers\n- 3 broken links fixed",
			'cards'   => array( 'walks' ),
			'steps'   => array(
				array( 'lea', 5, 'Nice! The walks story did great on Instagram, too.' ),
			),
		),
		'newsletter-topics' => array(
			'channel' => 'authors',
			'by'      => 'lea',
			'at'      => 6.5,
			'target'  => 'newsletter',
			'title'   => 'Newsletter: November topics',
			'body'    => "Topics for the November newsletter:\n1. Autumn issue: cover story\n2. A teaser for the lighthouse interview\n3. Ten walks for a foggy weekend (our most-read story in October)\n\n" . $m( 'ana' ) . ' could you approve them by ' . $nice( 5 ) . '?',
			'task'    => array( 'due' => 5, 'assignees' => array( 'ana' ) ),
		),
		'reader-tip'        => array(
			'channel' => 'editorial',
			'by'      => 'jonas',
			'at'      => 2.5,
			'title'   => 'Reader tip about walk seven',
			'body'    => 'A reader says walk seven has been closed since the storm. ' . $m( 'ana' ) . ' shall we add a note to the article before we approve the comment?',
			'comments' => array( 'oskar' ),
		),
		'cover-draft'       => array(
			'channel' => 'editorial',
			'by'      => 'ana',
			'at'      => 7,
			'title'   => 'Cover story draft is in',
			'body'    => 'The draft of the cover story is ready for a first read. ' . $m( 'tom' ) . ' ' . $m( 'jonas' ) . ' comments welcome, especially on the second half.',
			'cards'   => array( 'cover' ),
			'steps'   => array(
				array( 'jonas', 5, 'Read it on the train – really strong. The part about the cider press could lose a paragraph.' ),
				array( 'tom', 4, "Agreed. I'll write the captions tonight, so they're in before the copy edit." ),
			),
		),
	);

	$ids     = array();
	$created = 0;
	foreach ( $threads as $key => $t ) {
		$ref      = 'northline-demo:' . $key;
		$existing = Store\thread_by_ref( $ref );
		if ( $existing ) {
			$ids[ $key ] = (int) $existing->id;
			continue;
		}
		$args = array(
			'ref_key' => $ref,
			'channel' => $t['channel'],
			'title'   => $t['title'],
			'body'    => $t['body'],
			'created' => $ago( $t['at'], $key ),
		);
		if ( ! empty( $t['target'] ) ) {
			$args['target'] = array( 'type' => 'post', 'id' => $p[ $t['target'] ] );
		}
		if ( ! empty( $t['task'] ) ) {
			$args['task']      = true;
			$args['due']       = $day( $t['task']['due'] );
			$args['assignees'] = array_map( static fn ( $w ) => $u[ $w ], $t['task']['assignees'] );
		}
		if ( ! empty( $t['cards'] ) ) {
			$args['attachments'] = array_map( static fn ( $k ) => array( 'provider' => 'flurfunk/post', 'ref' => array( 'id' => $p[ $k ] ) ), $t['cards'] );
		}
		foreach ( $t['comments'] ?? array() as $k ) {
			if ( ! empty( $c[ $k ] ) ) {
				$args['attachments'][] = array( 'provider' => 'flurfunk/comment', 'ref' => array( 'id' => $c[ $k ] ) );
			}
		}
		$id = $at(
			$t['by'],
			$args['created'],
			static function () use ( $t, $args ) {
				if ( ! empty( $t['file'] ) ) {
					$file = \Flurfunk\Files\store( $t['file'][0], $t['file'][1] );
					if ( ! is_wp_error( $file ) ) {
						$args['files'] = array( $file );
					}
				}
				return Store\create_thread( $args );
			}
		);
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		$ids[ $key ] = (int) $id;
		++$created;
		$prev = $args['created'];
		foreach ( $t['steps'] ?? array() as $s ) {
			[ $who, $hours, $text ] = $s;
			$status                 = str_starts_with( $text, 'status:' );
			$when                   = $ago( $hours, $status ? '' : $key . $hours );
			if ( strtotime( $when ) <= strtotime( $prev ) ) {
				$when = gmdate( 'c', strtotime( $prev ) + 2 * MINUTE_IN_SECONDS );
			}
			$prev = $when;
			if ( $status ) {
				$at( $who, $when, static fn () => Store\set_status( (int) $id, substr( $text, 7 ) ) );
				if ( 'status:done' === $text ) {
					$wpdb->update( table( 'threads' ), array( 'resolved_at' => Store\mysql( $when ) ), array( 'id' => $id ) );
				}
				continue;
			}
			$at(
				$who,
				$when,
				static function () use ( $id, $text, $when, $s, $photo ) {
					$files = array();
					foreach ( $s[3] ?? array() as $f ) {
						$fid = $photo( $f );
						if ( ! is_wp_error( $fid ) ) {
							$files[] = $fid;
						}
					}
					return Store\add_message(
						(int) $id,
						array(
							'body'    => $text,
							'files'   => $files,
							'created' => $when,
						)
					);
				}
			);
		}
	}

	if ( $created ) {
		// Activity follows the newest message; the task steps above wrote "now".
		foreach ( $ids as $id ) {
			$row  = Store\thread_row( $id );
			$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created) FROM ' . table( 'messages' ) . ' WHERE thread_id = %d', $id ) );
			$wpdb->update( table( 'threads' ), array( 'last_activity' => max( (string) $row->created, $last ) ), array( 'id' => $id ) );
		}

		// Ana was last here three hours ago; four conversations still wait for her.
		$seen   = gmdate( 'Y-m-d H:i:s', $now - 3 * HOUR_IN_SECONDS );
		$unread = array_filter( array( $ids['newsletter-topics'] ?? 0, $ids['interview-length'] ?? 0, $ids['team-lunch'] ?? 0, $ids['reader-tip'] ?? 0 ) );
		foreach ( $ids as $key => $id ) {
			if ( in_array( $id, $unread, true ) ) {
				continue;
			}
			$mid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . table( 'messages' ) . ' WHERE thread_id = %d AND created <= %s', $id, $seen ) );
			Store\mark_read( $id, $u['ana'], $mid );
		}
		if ( isset( $ids['team-lunch'] ) ) {
			$mid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . table( 'messages' ) . " WHERE thread_id = %d AND author_id = %d AND type = 'message'", $ids['team-lunch'], $u['mia'] ) );
			Store\mark_read( $ids['team-lunch'], $u['ana'], $mid );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'reads' ) . ' SET seen = %s WHERE user_id = %d AND seen > %s', $seen, $u['ana'], $seen ) );

		// The morning reminder for Ana's overdue task.
		if ( isset( $ids['archive-rights'] ) ) {
			wp_set_current_user( 0 );
			\Flurfunk\Notify\dispatch( Store\thread_row( $ids['archive-rights'] ), 0, array( $u['ana'] => 'due' ), 0 );
			$before = $top();
			\Flurfunk\Notify\deliver();
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'notifications' ) . ' SET created = %s WHERE id > %d', gmdate( 'Y-m-d H:i:s', min( $now - 60, (int) strtotime( get_gmt_from_date( wp_date( 'Y-m-d', $now ) . ' 07:00:00' ) . ' UTC' ) ) ), $before['n'] ) );
		}

		// Notifications Ana has seen: older ones, outside the conversations still waiting for her.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . table( 'notifications' ) . ' SET read_at = created WHERE user_id = %d AND created <= %s' . ( $unread ? ' AND thread_id NOT IN (' . implode( ',', array_map( 'intval', $unread ) ) . ')' : '' ),
				$u['ana'],
				$seen
			)
		);
		// Everyone else is up to date with their notifications.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . table( 'notifications' ) . ' SET read_at = created WHERE user_id <> %d', $u['ana'] ) );
		Store\bump();
		foreach ( $u as $id ) {
			update_user_meta( $id, 'flurfunk_rev', (int) get_user_meta( $id, 'flurfunk_rev', true ) + 1 );
		}
	}

	// Ana's settings, as she would have chosen them.
	\Flurfunk\Notify\save_prefs(
		$u['ana'],
		array(
			'theme'  => 'light',
			'avatar' => 'photo',
			'mail'   => 'mentions',
			'push'   => 'mentions',
			'quiet'  => '22:00-07:00',
			'hour'   => 8,
		)
	);

	wp_set_current_user( $was );
	flurfunk_demo_say( sprintf( 'Northline demo: %d people, %d posts, %d new conversations (%d in total).', count( $u ), count( $p ), $created, count( $ids ) ) );
	flurfunk_demo_say( 'Log in as ana.klein to see it from the editor-in-chief\'s side.' );
} )( $flurfunk_demo_reset );

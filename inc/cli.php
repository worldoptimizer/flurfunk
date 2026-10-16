<?php
/**
 * WP-CLI: wp flurfunk …
 *
 * @package Flurfunk
 */

namespace Flurfunk\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Flurfunk on the command line.
 */
class Command {

	/**
	 * Export everything as JSON (format flurfunk/1).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Write to this file instead of the screen.
	 *
	 * @param array $args Arguments.
	 */
	public function export( array $args ): void {
		$json = (string) wp_json_encode( \Flurfunk\Transfer\export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $args[0] ) ) {
			file_put_contents( $args[0], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			WP_CLI::success( 'Exported to ' . $args[0] );
			return;
		}
		WP_CLI::line( $json );
	}

	/**
	 * Import a JSON export. Threads imported before are skipped.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Export file.
	 *
	 * @param array $args Arguments.
	 */
	public function import( array $args ): void {
		$data = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Not a JSON file.' );
		}
		$r = \Flurfunk\Transfer\import( $data );
		if ( ! empty( $r['error'] ) ) {
			WP_CLI::error( 'Unknown format.' );
		}
		WP_CLI::success( sprintf( '%d threads, %d messages imported, %d skipped.', $r['threads'], $r['messages'], $r['skipped'] ) );
	}

	/**
	 * Channels and counts.
	 */
	public function status(): void {
		global $wpdb;
		$rows = array();
		foreach ( \Flurfunk\Store\channels( true ) as $c ) {
			$rows[] = array(
				'channel' => $c->slug,
				'name'    => $c->name,
				'team'    => $c->access_team,
				'guests'  => $c->access_guests,
				'active'  => $c->archived ? 'no' : 'yes',
				'threads' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Flurfunk\Schema\table( 'threads' ) . ' WHERE channel_id = %d', $c->id ) ), // phpcs:ignore WordPress.DB
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'name', 'team', 'guests', 'active', 'threads' ) );
	}

	/**
	 * Create or change a channel.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Short name.
	 *
	 * [--name=<name>]
	 * [--topic=<topic>]
	 * [--team=<level>]
	 * : all, own-posts or none.
	 * [--guests=<level>]
	 * : all, own-posts or none.
	 * [--position=<n>]
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function channel( array $args, array $assoc_args ): void {
		$map = array( 'name' => 'name', 'topic' => 'topic', 'team' => 'access_team', 'guests' => 'access_guests', 'position' => 'position' );
		$in  = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $assoc_args[ $from ] ) ) {
				$in[ $to ] = $assoc_args[ $from ];
			}
		}
		$id = \Flurfunk\Store\ensure_channel( $args[0], $in, true );
		WP_CLI::success( 'Channel #' . $args[0] . ' (' . $id . ').' );
	}

	/**
	 * Create an assistant account for agents and scripts (shown as "Assistant").
	 *
	 * The account gets no editing rights in WordPress; it only takes part in Flurfunk.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Display name. Default: Assistent.
	 *
	 * [--login=<login>]
	 * : Login name. Default: flurfunk-assistent.
	 *
	 * [--default]
	 * : Use it for `wp flurfunk post` when no --as is given.
	 *
	 * @subcommand bot-create
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function bot_create( array $args, array $assoc_args ): void {
		$login = sanitize_user( $assoc_args['login'] ?? 'flurfunk-assistent' );
		$user  = get_user_by( 'login', $login );
		$id    = $user ? $user->ID : wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 40 ),
				'user_email'   => $login . '@invalid.invalid',
				'display_name' => $assoc_args['name'] ?? 'Assistent',
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id );
		}
		update_user_meta( $id, 'flurfunk_bot', 1 );
		\Flurfunk\Notify\save_prefs( (int) $id, array( 'mail' => 'none', 'badge' => false ) );
		if ( ! empty( $assoc_args['default'] ) || ! get_option( 'flurfunk_bot_default' ) ) {
			update_option( 'flurfunk_bot_default', (int) $id, false );
		}
		WP_CLI::success( sprintf( 'Assistant %s (%d).', get_userdata( $id )->display_name, $id ) );
	}

	/**
	 * Create an application password for an assistant (shown once). Agents use it with the REST API.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID of the assistant.
	 *
	 * [--name=<name>]
	 * : Label of the password. Default: Agent.
	 *
	 * @subcommand bot-password
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function bot_password( array $args, array $assoc_args ): void {
		$id = (int) $args[0];
		if ( ! \Flurfunk\Access\is_bot( $id ) ) {
			WP_CLI::error( 'Not an assistant account.' );
		}
		$r = \WP_Application_Passwords::create_new_application_password( $id, array( 'name' => $assoc_args['name'] ?? 'Agent' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r );
		}
		WP_CLI::log( 'User:     ' . get_userdata( $id )->user_login );
		WP_CLI::log( 'Password: ' . \WP_Application_Passwords::chunk_password( $r[0] ) );
		WP_CLI::log( 'API:      ' . rest_url( 'flurfunk/v1/' ) );
	}

	/**
	 * Post as a person or assistant: a new thread or a reply.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<slug>]
	 * : Channel for a new thread.
	 *
	 * [--thread=<id>]
	 * : Reply in this thread.
	 *
	 * [--ref=<key>]
	 * : Reply in the thread with this external key (or create it with --channel).
	 *
	 * [--title=<title>]
	 * [--body=<text>]
	 * : Text; "-" reads from standard input. Mentions as <@ID>.
	 *
	 * [--task]
	 * : New thread is a task.
	 *
	 * [--target=<target>]
	 * : Attach a new thread to e.g. post:12.
	 *
	 * [--file=<files>]
	 * : Files to attach, comma-separated. They become Flurfunk files.
	 *
	 * [--card=<card>]
	 * : A card, e.g. 'flurfunk/post:{"id":12}'.
	 *
	 * [--as=<user>]
	 * : Author. Default: the default assistant.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function post( array $args, array $assoc_args ): void {
		$as = (int) ( $assoc_args['as'] ?? get_option( 'flurfunk_bot_default' ) );
		if ( ! $as || ! get_userdata( $as ) ) {
			WP_CLI::error( 'No author: pass --as or create one with `wp flurfunk bot-create`.' );
		}
		wp_set_current_user( $as );
		$body = (string) ( $assoc_args['body'] ?? '' );
		if ( '-' === $body ) {
			$body = (string) stream_get_contents( STDIN );
		}
		$files = array();
		foreach ( array_filter( explode( ',', (string) ( $assoc_args['file'] ?? '' ) ) ) as $path ) {
			$path = trim( $path );
			if ( ! is_readable( $path ) ) {
				WP_CLI::error( "Cannot read $path" );
			}
			$id = \Flurfunk\Files\store( wp_basename( $path ), (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $id );
			}
			$files[] = $id;
		}
		$cards = array();
		if ( ! empty( $assoc_args['card'] ) && preg_match( '/^([^:]+\/[^:]+):(.*)$/s', $assoc_args['card'], $m ) ) {
			$cards[] = array( 'provider' => $m[1], 'ref' => (array) json_decode( $m[2], true ) );
		}
		$thread = (int) ( $assoc_args['thread'] ?? 0 );
		if ( ! $thread && ! empty( $assoc_args['ref'] ) ) {
			$row    = \Flurfunk\Store\thread_by_ref( (string) $assoc_args['ref'] );
			$thread = $row ? (int) $row->id : 0;
		}
		if ( $thread ) {
			$r = \Flurfunk\Store\add_message( $thread, array( 'body' => $body, 'files' => $files, 'attachments' => $cards ) );
		} else {
			$target = null;
			if ( ! empty( $assoc_args['target'] ) && preg_match( '/^([a-z0-9_-]+):(\d+)$/', $assoc_args['target'], $m ) ) {
				$target = array( 'type' => $m[1], 'id' => (int) $m[2] );
			}
			$r = \Flurfunk\Store\create_thread(
				array(
					'channel'     => (string) ( $assoc_args['channel'] ?? '' ),
					'ref_key'     => (string) ( $assoc_args['ref'] ?? '' ),
					'title'       => (string) ( $assoc_args['title'] ?? '' ),
					'body'        => $body,
					'task'        => ! empty( $assoc_args['task'] ),
					'target'      => $target,
					'attachments' => $cards,
				)
			);
			if ( ! is_wp_error( $r ) && $files ) {
				$r2 = \Flurfunk\Store\add_message( (int) $r, array( 'body' => '', 'files' => $files ) );
				if ( is_wp_error( $r2 ) ) {
					WP_CLI::warning( $r2->get_error_message() );
				}
			}
		}
		if ( is_wp_error( $r ) ) {
			// Don't leave uploaded files behind when the post itself failed.
			foreach ( $files as $f ) {
				wp_delete_attachment( $f, true );
			}
			WP_CLI::error( $r );
		}
		WP_CLI::success( $thread ? "Reply $r in thread $thread." : "Thread $r." );
	}
}

WP_CLI::add_command( 'flurfunk', __NAMESPACE__ . '\\Command' );

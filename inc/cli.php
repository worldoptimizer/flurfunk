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
	 * : Write to this file instead of the screen. With --files: a folder.
	 *
	 * [--files]
	 * : Copy all files along (folder with flurfunk.json and files/), for moving to another site.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( array $args, array $assoc_args = array() ): void {
		if ( ! empty( $assoc_args['files'] ) ) {
			$dir = rtrim( (string) ( $args[0] ?? '' ), '/' );
			if ( '' === $dir ) {
				WP_CLI::error( 'Name a folder for the bundle.' );
			}
			wp_mkdir_p( $dir );
			$data = \Flurfunk\Transfer\export( $dir );
			file_put_contents( $dir . '/flurfunk.json', (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes to the path the user named.
			WP_CLI::success( sprintf( 'Exported %d threads with files to %s', count( $data['threads'] ), $dir ) );
			return;
		}
		$json = (string) wp_json_encode( \Flurfunk\Transfer\export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $args[0] ) ) {
			file_put_contents( $args[0], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes to the path the user named.
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
	 * : Export file, or a bundle folder from `export --files`.
	 *
	 * @param array $args Arguments.
	 */
	public function import( array $args ): void {
		$bundle = is_dir( $args[0] ) ? rtrim( $args[0], '/' ) : '';
		if ( ! get_current_user_id() ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			wp_set_current_user( (int) ( $admins[0] ?? 0 ) ); // Files are stored in someone's name.
		}
		$data = json_decode( (string) file_get_contents( $bundle ? $bundle . '/flurfunk.json' : $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the user named.
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Not a JSON file.' );
		}
		$r = \Flurfunk\Transfer\import( $data, $bundle );
		if ( ! empty( $r['error'] ) ) {
			WP_CLI::error( 'Unknown format.' );
		}
		WP_CLI::success( sprintf( '%d threads, %d messages, %d files imported, %d skipped.', $r['threads'], $r['messages'], $r['files'], $r['skipped'] ) );
	}

	/**
	 * List modules or switch one on or off.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list (default), on or off.
	 *
	 * [<id>]
	 * : Module ID for on/off.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flurfunk module
	 *     wp flurfunk module on flurfunk/review
	 *
	 * @param array $args Arguments.
	 */
	public function module( array $args ): void {
		$action = $args[0] ?? 'list';
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( \Flurfunk\Modules\listing() as $group => $list ) {
				foreach ( $list as $m ) {
					$rows[] = array(
						'id'        => $m['id'],
						'group'     => $group,
						'enabled'   => $m['enabled'] ? 'on' : 'off',
						'available' => $m['available'] ? 'yes' : 'no',
						'loaded'    => $m['loaded'] ? 'yes' : 'no',
					);
				}
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'group', 'enabled', 'available', 'loaded' ) );
			return;
		}
		$id = (string) ( $args[1] ?? '' );
		if ( ! in_array( $action, array( 'on', 'off' ), true ) || ! isset( \Flurfunk\Modules\registry()[ $id ] ) ) {
			WP_CLI::error( 'Usage: wp flurfunk module on|off <id> (see wp flurfunk module).' );
		}
		\Flurfunk\Modules\set( array( $id => 'on' === $action ) );
		WP_CLI::success( sprintf( '%s is %s.', $id, $action ) );
	}

	/**
	 * Channels and counts.
	 */
	public function status(): void {
		global $wpdb;
		$rows = array();
		foreach ( \Flurfunk\Store\real_channels( true ) as $c ) {
			$rows[] = array(
				'channel'  => $c->slug,
				'name'     => $c->name,
				'team'     => $c->access_team,
				'guests'   => $c->access_guests,
				'requires' => (string) ( $c->capability ?? '' ),
				'active'   => $c->archived ? 'no' : 'yes',
				'threads'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE channel_id = %d', \Flurfunk\Schema\table( 'threads' ), $c->id ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, a one-off command.
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'name', 'team', 'guests', 'requires', 'active', 'threads' ) );
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
	 * [--capability=<cap>]
	 * : Capability people also need to see the channel; empty for none.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function channel( array $args, array $assoc_args ): void {
		$map = array( 'name' => 'name', 'topic' => 'topic', 'team' => 'access_team', 'guests' => 'access_guests', 'position' => 'position', 'capability' => 'capability' );
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
	 * : Display name. Default: Assistant (translated).
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
		$id = \Flurfunk\Access\create_bot( $assoc_args['name'] ?? __( 'Assistant', 'flurfunk' ), $assoc_args['login'] ?? 'flurfunk-assistent' );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id );
		}
		if ( ! empty( $assoc_args['default'] ) ) {
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
			$id = \Flurfunk\Files\store( wp_basename( $path ), (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the user named.
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

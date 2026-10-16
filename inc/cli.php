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
}

WP_CLI::add_command( 'flurfunk', __NAMESPACE__ . '\\Command' );

<?php
/**
 * Everything an administrator needs without WP-CLI: export and import under Settings → Flurfunk,
 * and a short welcome after the first activation (a notice in wp-admin and a thread in #general).
 *
 * Export: one JSON file (flurfunk/1), or a ZIP with the JSON and every file, for moving to another
 * site. Import: the JSON or that ZIP. Both are the same as `wp flurfunk export|import`.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Data;

use Flurfunk\Transfer;

defined( 'ABSPATH' ) || exit;

const WELCOME        = 'flurfunk_welcome';
const WELCOME_THREAD = 'flurfunk_welcome_thread'; // { by: user } until written, then 'done'.
const RESULT         = 'flurfunk_import_result_';

/**
 * Can this server build and read ZIP bundles?
 */
function zip_available(): bool {
	return class_exists( 'ZipArchive' );
}

/**
 * A fresh temporary folder.
 */
function temp_dir(): string {
	$dir = trailingslashit( get_temp_dir() ) . 'flurfunk-' . strtolower( wp_generate_password( 12, false ) );
	wp_mkdir_p( $dir );
	return $dir;
}

/**
 * Remove a temporary folder.
 *
 * @param string $dir Folder.
 */
function remove_dir( string $dir ): void {
	if ( '' === $dir || ! is_dir( $dir ) || ! str_contains( $dir, 'flurfunk-' ) ) {
		return;
	}
	$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own temporary folder.
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own temporary folder.
}

/**
 * Stop unless an administrator is asking (the nonce is checked right after, in each handler).
 */
function check(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You cannot change this.', 'flurfunk' ), 403 );
	}
}

/*
 * Export: download JSON or a ZIP bundle.
 */
add_action(
	'admin_post_flurfunk_export',
	static function (): void {
		check();
		check_admin_referer( 'flurfunk_export' );
		$name  = 'flurfunk-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' );
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( empty( $_POST['files'] ) || ! zip_available() ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.json"' );
			echo wp_json_encode( Transfer\export(), $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not HTML.
			exit;
		}
		$dir = temp_dir();
		file_put_contents( $dir . '/flurfunk.json', (string) wp_json_encode( Transfer\export( $dir ), $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own temporary folder.
		$zip_file = $dir . '.zip';
		$zip      = new \ZipArchive();
		$zip->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$zip->addFile( $item->getPathname(), ltrim( substr( $item->getPathname(), strlen( $dir ) ), '/' ) );
		}
		$zip->close();
		remove_dir( $dir );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_file ) );
		readfile( $zip_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the ZIP we just built.
		wp_delete_file( $zip_file );
		exit;
	}
);

/*
 * Import: a JSON export or a ZIP bundle.
 */
add_action(
	'admin_post_flurfunk_import',
	static function (): void {
		check();
		check_admin_referer( 'flurfunk_import' );
		$back = admin_url( 'options-general.php?page=flurfunk-settings#flurfunk-transfer' );
		$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an upload: only its temporary path and name are used, the content is parsed as JSON.
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			set_transient( RESULT . get_current_user_id(), array( 'error' => __( 'Please choose an export file.', 'flurfunk' ) ), 300 );
			wp_safe_redirect( $back );
			exit;
		}
		$is_zip = str_ends_with( strtolower( (string) $file['name'] ), '.zip' );
		$dir    = '';
		$json   = '';
		if ( $is_zip ) {
			if ( ! zip_available() ) {
				set_transient( RESULT . get_current_user_id(), array( 'error' => __( 'This server cannot open ZIP files. Import the JSON file instead.', 'flurfunk' ) ), 300 );
				wp_safe_redirect( $back );
				exit;
			}
			$dir = temp_dir();
			$zip = new \ZipArchive();
			$ok  = true === $zip->open( $file['tmp_name'] );
			// Only plain paths: nothing may land outside the temporary folder.
			for ( $i = 0; $ok && $i < $zip->numFiles; $i++ ) {
				$entry = (string) $zip->getNameIndex( $i );
				$ok    = ! str_contains( $entry, '..' ) && ! str_starts_with( $entry, '/' );
			}
			if ( $ok ) {
				$zip->extractTo( $dir );
				$zip->close();
				$json = is_readable( $dir . '/flurfunk.json' ) ? (string) file_get_contents( $dir . '/flurfunk.json' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in our temporary folder.
			}
		} else {
			$json = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded file.
		}
		$data   = json_decode( $json, true );
		$result = is_array( $data ) ? Transfer\import( $data, $dir ) : array( 'error' => 'format' );
		remove_dir( $dir );
		if ( ! empty( $result['error'] ) ) {
			$result = array( 'error' => __( 'This is not a Flurfunk export (format flurfunk/1).', 'flurfunk' ) );
		}
		set_transient( RESULT . get_current_user_id(), $result, 300 );
		wp_safe_redirect( $back );
		exit;
	}
);

/**
 * The export and import section of the settings page (its own forms, below the settings form).
 */
function render_section(): void {
	$r = get_transient( RESULT . get_current_user_id() );
	if ( $r ) {
		delete_transient( RESULT . get_current_user_id() );
		$text = ! empty( $r['error'] )
			? $r['error']
			/* translators: 1: threads, 2: messages, 3: files, 4: skipped threads */
			: sprintf( __( 'Imported: %1$d threads, %2$d messages, %3$d files. %4$d threads were already there and were skipped.', 'flurfunk' ), $r['threads'], $r['messages'], $r['files'], $r['skipped'] );
		printf( '<div class="notice notice-%s"><p>%s</p></div>', ! empty( $r['error'] ) ? 'error' : 'success', esc_html( $text ) );
	}
	?>
	<h2 id="flurfunk-transfer"><?php esc_html_e( 'Export and import', 'flurfunk' ); ?></h2>
	<p><?php esc_html_e( 'All channels, threads, messages, tasks and cards in one file, for a backup or for moving to another site. People are matched by their email address.', 'flurfunk' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="flurfunk_export">
		<?php wp_nonce_field( 'flurfunk_export' ); ?>
		<?php if ( zip_available() ) : ?>
			<p><label><input type="checkbox" name="files" value="1"> <?php esc_html_e( 'Include all files (a ZIP; can be large)', 'flurfunk' ); ?></label></p>
		<?php endif; ?>
		<?php submit_button( __( 'Download export', 'flurfunk' ), 'secondary', 'submit', false ); ?>
	</form>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
		<input type="hidden" name="action" value="flurfunk_import">
		<?php wp_nonce_field( 'flurfunk_import' ); ?>
		<p><label for="flurfunk-import-file"><?php esc_html_e( 'Import an export file (.json or .zip):', 'flurfunk' ); ?></label><br>
			<input type="file" id="flurfunk-import-file" name="file" accept=".json,.zip,application/json,application/zip" required></p>
		<p class="description"><?php esc_html_e( 'Threads that were imported before are skipped, so importing twice does no harm.', 'flurfunk' ); ?></p>
		<?php submit_button( __( 'Import', 'flurfunk' ), 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

/*
 * Welcome after the first activation: where things are, until someone opened Flurfunk.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! get_option( WELCOME ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && ( str_contains( (string) $screen->id, 'flurfunk' ) ) ) {
			delete_option( WELCOME ); // They found it.
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=flurfunk_welcome_done' ), 'flurfunk_welcome_done' );
		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a> <a href="%7$s">%8$s</a></p></div>',
			esc_html__( 'Flurfunk is ready.', 'flurfunk' ),
			esc_html__( 'A #general channel is waiting. Set the name, logo and colours, decide who counts as team and who as guest, and add channels in the settings.', 'flurfunk' ),
			esc_url( \Flurfunk\Admin\url() ),
			esc_html__( 'Open Flurfunk', 'flurfunk' ),
			esc_url( admin_url( 'options-general.php?page=flurfunk-settings' ) ),
			esc_html__( 'Settings', 'flurfunk' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'flurfunk' )
		);
	}
);
add_action(
	'admin_post_flurfunk_welcome_done',
	static function (): void {
		check();
		check_admin_referer( 'flurfunk_welcome_done' );
		delete_option( WELCOME );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
);

/**
 * The welcome thread in #general: what threads, replies, mentions, tasks, cards and
 * notifications are. Written once, on the first request after a fresh activation (then the
 * translations are loaded), in the name of the administrator who activated Flurfunk.
 */
function welcome_thread(): void {
	$w = get_option( WELCOME_THREAD );
	if ( ! is_array( $w ) ) {
		return;
	}
	update_option( WELCOME_THREAD, 'done', false );
	$by = (int) ( $w['by'] ?? 0 );
	if ( ! $by || ! get_userdata( $by ) ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$by     = (int) ( $admins[0] ?? 0 );
	}
	if ( ! $by ) {
		return;
	}
	$lines = array(
		__( 'This is a thread: a topic with its replies underneath, so a conversation stays in one place.', 'flurfunk' ),
		'',
		'- ' . __( 'Reply below to answer in this thread.', 'flurfunk' ),
		'- ' . __( 'Type @ and a name to mention someone. They get a notification.', 'flurfunk' ),
		'- ' . __( 'Any thread can be a task: tick "Task", set a due date and who is responsible. The Board shows all tasks by state, "My tasks" your own.', 'flurfunk' ),
		'- ' . __( 'Type / in a message to insert a card: a post, or whatever other plugins offer.', 'flurfunk' ),
		'- ' . __( 'Threads can belong to a post. They then show up in the editor\'s Conversations panel and in the posts list.', 'flurfunk' ),
		'- ' . __( 'The bell collects your notifications. Under your name at the bottom of the sidebar you choose mail, push and quiet hours.', 'flurfunk' ),
	);
	\Flurfunk\Store\create_thread(
		array(
			'channel' => 'general',
			'title'   => __( 'Welcome to Flurfunk', 'flurfunk' ),
			'body'    => implode( "\n", $lines ),
			'author'  => $by,
			'ref_key' => 'flurfunk:welcome',
		),
		false
	);
}
add_action( 'init', __NAMESPACE__ . '\\welcome_thread', 20 );

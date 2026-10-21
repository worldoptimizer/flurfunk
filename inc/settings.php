<?php
/**
 * Settings: branding (name, logo, colours), which post types conversations can attach to,
 * channels, assistants; the permissions matrix comes from inc/caps.php. Everything a site wants
 * to adjust lives here or in a provider – nothing site-specific belongs in the plugin code.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Settings;

use Flurfunk\Access;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const OPTION = 'flurfunk_settings';
const PAGE   = 'flurfunk-settings';

/**
 * Defaults.
 */
function defaults(): array {
	return array(
		'name'          => '',
		'logo_id'       => 0,
		'accent'        => '#8b5a1b',
		'sidebar'       => '#231b12',
		'post_types'    => array( 'post' ),
		'editor_panel'  => true,
		'posts_column'  => true,
		'keep_data'     => true,
	);
}

/**
 * Current settings.
 *
 * @param bool $fresh Reload.
 */
function get( bool $fresh = false ): array {
	static $s = null;
	if ( null === $s || $fresh ) {
		$s = array_merge( defaults(), (array) get_option( OPTION, array() ) );
		$s = (array) apply_filters( 'flurfunk_settings', $s );
	}
	return $s;
}

/**
 * Name shown in the app (falls back to the site title).
 */
function name(): string {
	$name = trim( (string) get()['name'] );
	return '' !== $name ? $name : html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Logo URL ('' = none).
 */
function logo(): string {
	$id = (int) get()['logo_id'];
	return $id ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
}

/**
 * Sanitize a colour.
 *
 * @param mixed  $value    Input.
 * @param string $fallback Default.
 */
function colour( $value, string $fallback ): string {
	return sanitize_hex_color( (string) $value ) ?: $fallback;
}

/**
 * Sanitize and store settings from the form.
 *
 * @param array $in Raw input.
 */
function save( array $in ): void {
	$d   = defaults();
	$out = array(
		'name'         => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
		'logo_id'      => absint( $in['logo_id'] ?? 0 ),
		'accent'       => colour( $in['accent'] ?? '', $d['accent'] ),
		'sidebar'      => colour( $in['sidebar'] ?? '', $d['sidebar'] ),
		'post_types'   => array_values( array_intersect( (array) ( $in['post_types'] ?? array() ), array_keys( post_types() ) ) ) ?: $d['post_types'],
		'editor_panel' => ! empty( $in['editor_panel'] ),
		'posts_column' => ! empty( $in['posts_column'] ),
		'keep_data'    => ! empty( $in['keep_data'] ),
	);
	update_option( OPTION, $out, true );
	get( true );
	\Flurfunk\Store\bump();
}

/**
 * Post types to choose from.
 *
 * @return array<string,string>
 */
function post_types(): array {
	$out = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
		if ( 'attachment' !== $pt->name && 'wp_block' !== $pt->name ) {
			$out[ $pt->name ] = $pt->labels->name;
		}
	}
	return $out;
}

/**
 * Capabilities a channel can require, grouped: Flurfunk's registry (its own and declared ones)
 * and a few common WordPress ones. A capability set in code that is not listed is kept as an
 * extra option, so saving the form never drops it.
 *
 * @param string[] $also Capabilities to list as well (e.g. the ones channels use now).
 * @return array<string, array<string, string>> Group => cap => label.
 */
function channel_caps( array $also = array() ): array {
	$flurfunk = array();
	foreach ( \Flurfunk\Caps\all() as $cap => $def ) {
		$flurfunk[ $cap ] = $def['label'] . ' (' . $cap . ')';
	}
	$wp     = array();
	$common = array(
		'moderate_comments' => __( 'Moderate comments', 'flurfunk' ),
		'edit_others_posts' => __( 'Edit other people\'s posts', 'flurfunk' ),
		'publish_posts'     => __( 'Publish posts', 'flurfunk' ),
		'manage_options'    => __( 'Manage options', 'flurfunk' ),
	);
	foreach ( $common as $cap => $label ) {
		$wp[ $cap ] = $label . ' (' . $cap . ')';
	}
	$other = array();
	foreach ( $also as $cap ) {
		$cap = sanitize_key( (string) $cap );
		if ( '' !== $cap && ! isset( $flurfunk[ $cap ] ) && ! isset( $wp[ $cap ] ) ) {
			$other[ $cap ] = $cap;
		}
	}
	$out = array(
		__( 'Flurfunk', 'flurfunk' )  => $flurfunk,
		__( 'WordPress', 'flurfunk' ) => $wp,
	);
	if ( $other ) {
		$out[ __( 'Other', 'flurfunk' ) ] = $other;
	}
	return (array) apply_filters( 'flurfunk_channel_capabilities', $out );
}

/**
 * Is this capability one of the choices (or '' for none)?
 *
 * @param string   $cap  Capability.
 * @param string[] $also Extra capabilities allowed (the channel's current one).
 */
function known_cap( string $cap, array $also = array() ): bool {
	if ( '' === $cap ) {
		return true;
	}
	foreach ( channel_caps( $also ) as $caps ) {
		if ( isset( $caps[ $cap ] ) ) {
			return true;
		}
	}
	return false;
}

add_action(
	'admin_menu',
	static function (): void {
		$hook = add_options_page( __( 'Flurfunk', 'flurfunk' ), __( 'Flurfunk', 'flurfunk' ), \Flurfunk\Caps\MANAGE, PAGE, __NAMESPACE__ . '\\render' );
		add_action( 'load-' . $hook, static fn () => add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue' ) );
	}
);

/**
 * Scripts of the settings page only: the media library for the logo picker.
 */
function enqueue(): void {
	wp_enqueue_media();
	wp_enqueue_script( 'flurfunk-settings', FLURFUNK_URL . 'assets/settings.js', array( 'media-editor' ), (string) filemtime( FLURFUNK_DIR . 'assets/settings.js' ), true );
	wp_enqueue_style( 'flurfunk-settings', FLURFUNK_URL . 'assets/settings.css', array(), (string) filemtime( FLURFUNK_DIR . 'assets/settings.css' ) );
}

// "Settings" next to Deactivate in the plugins list.
add_filter(
	'plugin_action_links_' . plugin_basename( FLURFUNK_FILE ),
	static function ( array $links ): array {
		if ( Access\can_manage() ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . PAGE ) ) . '">' . esc_html__( 'Settings', 'flurfunk' ) . '</a>' );
		}
		return $links;
	}
);

add_action(
	'admin_post_flurfunk_settings',
	static function (): void {
		if ( ! Access\can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'flurfunk' ), 403 );
		}
		check_admin_referer( 'flurfunk_settings' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in save() and below.
		save( (array) ( $in['flurfunk'] ?? array() ) );
		foreach ( (array) ( $in['channels'] ?? array() ) as $slug => $c ) {
			$c   = (array) $c;
			$now = Store\channel_by_slug( sanitize_key( (string) $slug ) );
			if ( isset( $c['capability'] ) && ! known_cap( sanitize_key( (string) $c['capability'] ), $now ? array( (string) ( $now->capability ?? '' ) ) : array() ) ) {
				unset( $c['capability'] ); // Not one of the choices: leave it as it is.
			}
			Store\ensure_channel( (string) $slug, $c + array( 'archived' => empty( $c['active'] ) ? 1 : 0 ), true );
		}
		// Assistants are user accounts: only for people who may create users (a manager need not).
		if ( ! empty( $in['new_bot']['name'] ) && current_user_can( 'create_users' ) ) {
			Access\create_bot( (string) $in['new_bot']['name'], 'flurfunk-' . sanitize_title( (string) $in['new_bot']['name'] ) );
		}
		$new = (array) ( $in['new_channel'] ?? array() );
		if ( '' !== trim( (string) ( $new['name'] ?? '' ) ) ) {
			$slug = sanitize_key( ( $new['slug'] ?? '' ) ?: sanitize_title( (string) $new['name'] ) );
			if ( isset( $new['capability'] ) && ! known_cap( sanitize_key( (string) $new['capability'] ) ) ) {
				unset( $new['capability'] );
			}
			Store\ensure_channel( $slug, $new );
		}
		wp_safe_redirect( add_query_arg( 'flurfunk-saved', '1', admin_url( 'options-general.php?page=' . PAGE ) ) );
		exit;
	}
);

/**
 * Settings page.
 */
function render(): void {
	if ( ! Access\can_manage() ) {
		return;
	}
	$s      = get();
	$levels = array(
		'all'       => __( 'Everything', 'flurfunk' ),
		'own-posts' => __( 'Only threads about their own posts', 'flurfunk' ),
		'none'      => __( 'Nothing', 'flurfunk' ),
	);
	$select = static function ( string $name, array $options, string $current ): void {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $current, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
	};
	$channels = Store\real_channels( true );
	$caps     = channel_caps( array_map( static fn ( $c ) => (string) ( $c->capability ?? '' ), $channels ) );
	$cap_pick = static function ( string $name, string $current ) use ( $caps ): void {
		echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr__( 'Also requires', 'flurfunk' ) . '">';
		echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html__( 'Nothing else', 'flurfunk' ) . '</option>';
		foreach ( $caps as $group => $list ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $list as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $current, $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select>';
	};
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Flurfunk', 'flurfunk' ); ?></h1>
		<?php if ( isset( $_GET['flurfunk-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- own parameter: WordPress shows its own notice for "updated". ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'flurfunk' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="flurfunk_settings">
			<?php wp_nonce_field( 'flurfunk_settings' ); ?>

			<h2><?php esc_html_e( 'Look', 'flurfunk' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ff-name"><?php esc_html_e( 'Name', 'flurfunk' ); ?></label></th>
					<td><input id="ff-name" class="regular-text" name="flurfunk[name]" value="<?php echo esc_attr( $s['name'] ); ?>" placeholder="<?php echo esc_attr( name() ); ?>">
						<p class="description"><?php esc_html_e( 'Shown at the top of the sidebar. Empty: the site title.', 'flurfunk' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logo', 'flurfunk' ); ?></th>
					<td>
						<input type="hidden" name="flurfunk[logo_id]" id="ff-logo" value="<?php echo (int) $s['logo_id']; ?>">
						<img id="ff-logo-preview" src="<?php echo esc_url( logo() ); ?>" alt="" style="max-height:48px;vertical-align:middle;<?php echo logo() ? '' : 'display:none'; ?>">
						<button type="button" class="button" id="ff-logo-pick"><?php esc_html_e( 'Choose logo', 'flurfunk' ); ?></button>
						<button type="button" class="button-link" id="ff-logo-clear"><?php esc_html_e( 'Remove', 'flurfunk' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ff-accent"><?php esc_html_e( 'Accent colour', 'flurfunk' ); ?></label></th>
					<td><input id="ff-accent" type="color" name="flurfunk[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>">
						<p class="description"><?php esc_html_e( 'Mentions, unread counts, links.', 'flurfunk' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ff-sidebar"><?php esc_html_e( 'Sidebar colour', 'flurfunk' ); ?></label></th>
					<td><input id="ff-sidebar" type="color" name="flurfunk[sidebar]" value="<?php echo esc_attr( $s['sidebar'] ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Channels', 'flurfunk' ); ?></h2>
			<p class="description" style="max-width:46rem"><?php echo wp_kses( sprintf( /* translators: %s: link to the permissions section */ __( 'Who counts as team and who as guest is set under %s.', 'flurfunk' ), '<a href="#flurfunk-permissions">' . esc_html__( 'Permissions', 'flurfunk' ) . '</a>' ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
			<p class="description" style="max-width:46rem"><?php esc_html_e( '"Also requires": only people with this capability see the channel at all (its threads, numbers, notifications and search results), whatever team and guests may see. Assistants count only for Flurfunk\'s Team and Guest; for anything else only when their account has the capability, but the site\'s own modules can always post there.', 'flurfunk' ); ?></p>
			<table class="widefat striped" style="max-width:72rem">
				<thead><tr><th><?php esc_html_e( 'Active', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Name', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Topic', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Team sees', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Guests see', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Also requires', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Order', 'flurfunk' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $channels as $c ) : ?>
					<tr>
						<td><input type="checkbox" name="channels[<?php echo esc_attr( $c->slug ); ?>][active]" value="1" <?php checked( ! $c->archived ); ?> aria-label="<?php esc_attr_e( 'Active', 'flurfunk' ); ?>"></td>
						<td><input name="channels[<?php echo esc_attr( $c->slug ); ?>][name]" value="<?php echo esc_attr( $c->name ); ?>"><br><code>#<?php echo esc_html( $c->slug ); ?></code></td>
						<td><input class="regular-text" name="channels[<?php echo esc_attr( $c->slug ); ?>][topic]" value="<?php echo esc_attr( $c->topic ); ?>"></td>
						<td><?php $select( 'channels[' . $c->slug . '][access_team]', $levels, $c->access_team ); ?></td>
						<td><?php $select( 'channels[' . $c->slug . '][access_guests]', $levels, $c->access_guests ); ?></td>
						<td><?php $cap_pick( 'channels[' . $c->slug . '][capability]', (string) ( $c->capability ?? '' ) ); ?></td>
						<td><input type="number" style="width:5em" name="channels[<?php echo esc_attr( $c->slug ); ?>][position]" value="<?php echo (int) $c->position; ?>"></td>
					</tr>
				<?php endforeach; ?>
					<tr>
						<td><?php esc_html_e( 'New', 'flurfunk' ); ?></td>
						<td><input name="new_channel[name]" placeholder="<?php esc_attr_e( 'Name', 'flurfunk' ); ?>"></td>
						<td><input class="regular-text" name="new_channel[topic]" placeholder="<?php esc_attr_e( 'Topic', 'flurfunk' ); ?>"></td>
						<td><?php $select( 'new_channel[access_team]', $levels, 'all' ); ?></td>
						<td><?php $select( 'new_channel[access_guests]', $levels, 'none' ); ?></td>
						<td><?php $cap_pick( 'new_channel[capability]', '' ); ?></td>
						<td><input type="number" style="width:5em" name="new_channel[position]" value="100"></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Assistants', 'flurfunk' ); ?></h2>
			<p class="description" style="max-width:46rem"><?php esc_html_e( 'Accounts for agents and scripts. They take part like the team, have no editing rights in WordPress and are marked "Assistant" in conversations. Agents connect with an application password from the account\'s profile page.', 'flurfunk' ); ?></p>
			<table class="widefat striped" style="max-width:46rem">
				<tbody>
				<?php foreach ( get_users( array( 'meta_key' => 'flurfunk_bot' ) ) as $bot ) : // phpcs:ignore WordPress.DB.SlowDBQuery ?>
					<tr>
						<td><strong><?php echo esc_html( $bot->display_name ); ?></strong><br><code><?php echo esc_html( $bot->user_login ); ?></code> · ID <?php echo (int) $bot->ID; ?><?php echo (int) get_option( 'flurfunk_bot_default' ) === $bot->ID ? ' · ' . esc_html__( 'default for wp flurfunk post', 'flurfunk' ) : ''; ?></td>
						<td><?php if ( current_user_can( 'edit_user', $bot->ID ) ) : ?><a href="<?php echo esc_url( get_edit_user_link( $bot->ID ) . '#application-passwords-section' ); ?>"><?php esc_html_e( 'Application passwords', 'flurfunk' ); ?></a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
					<?php if ( current_user_can( 'create_users' ) ) : ?>
					<tr>
						<td colspan="2"><label><?php esc_html_e( 'New assistant', 'flurfunk' ); ?> <input name="new_bot[name]" placeholder="<?php esc_attr_e( 'Name', 'flurfunk' ); ?>"></label></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php assistants_help(); ?>

			<h2><?php esc_html_e( 'Posts', 'flurfunk' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Conversations about', 'flurfunk' ); ?></th>
					<td><?php foreach ( post_types() as $pt => $label ) : ?>
						<label style="margin-right:1em"><input type="checkbox" name="flurfunk[post_types][]" value="<?php echo esc_attr( $pt ); ?>" <?php checked( in_array( $pt, $s['post_types'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show', 'flurfunk' ); ?></th>
					<td>
						<label><input type="checkbox" name="flurfunk[editor_panel]" value="1" <?php checked( $s['editor_panel'] ); ?>> <?php esc_html_e( 'Conversations panel in the editor', 'flurfunk' ); ?></label><br>
						<label><input type="checkbox" name="flurfunk[posts_column]" value="1" <?php checked( $s['posts_column'] ); ?>> <?php esc_html_e( 'Column in the posts list', 'flurfunk' ); ?></label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Data', 'flurfunk' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'flurfunk' ); ?></th>
					<td><label><input type="checkbox" name="flurfunk[keep_data]" value="1" <?php checked( $s['keep_data'] ); ?>> <?php esc_html_e( 'Keep conversations when the plugin is deleted', 'flurfunk' ); ?></label>
						<p class="description"><?php esc_html_e( 'Export and import are below; on the command line: wp flurfunk export / wp flurfunk import.', 'flurfunk' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php \Flurfunk\Caps\render_section(); ?>
		<?php \Flurfunk\Modules\render_section(); ?>
		<?php do_action( 'flurfunk_settings_sections' ); // Sections of modules (e.g. AI assistants). ?>
		<?php \Flurfunk\Data\render_section(); ?>
	</div>
	<?php
}

/**
 * How an agent or script posts as an assistant: application password, then one request.
 */
function assistants_help(): void {
	$bot  = (int) get_option( 'flurfunk_bot_default' );
	$user = $bot && get_userdata( $bot ) ? get_userdata( $bot )->user_login : 'flurfunk-assistent';
	$curl = "curl -X POST " . rest_url( 'flurfunk/v1/threads' ) . " \\\n  -u '" . $user . ":xxxx xxxx xxxx xxxx xxxx xxxx' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"channel\":\"general\",\"title\":\"Nightly report\",\"body\":\"All checks passed. <@1> please have a look.\"}'";
	?>
	<details style="max-width:46rem;margin-top:12px">
		<summary><?php esc_html_e( 'How an agent posts as an assistant', 'flurfunk' ); ?></summary>
		<ol>
			<li><?php esc_html_e( 'Create an assistant above, then open "Application passwords" next to it and add one. WordPress offers application passwords only on sites with HTTPS.', 'flurfunk' ); ?></li>
			<li><?php esc_html_e( 'The agent sends the login name and that password with every request to the REST API, for example to start a thread:', 'flurfunk' ); ?></li>
		</ol>
		<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px 12px"><code><?php echo esc_html( $curl ); ?></code></pre>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to the developer documentation */
				esc_html__( 'Fields: channel (short name, required), title, body (mentions as <@user ID>), task, due, assignees, target {type, id}, ref_key. Replies go to /threads/<thread ID>/messages with a body. More in the %s.', 'flurfunk' ),
				'<a href="https://worldoptimizer.github.io/flurfunk/developers.html#rest-api">' . esc_html__( 'developer documentation', 'flurfunk' ) . '</a>'
			);
			?>
		</p>
	</details>
	<?php
}

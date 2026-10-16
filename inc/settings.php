<?php
/**
 * Settings: branding (name, logo, colours), who counts as team or guest, which post types
 * conversations can attach to, channels. Everything a site wants to adjust lives here or in a
 * provider – nothing site-specific belongs in the plugin code.
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
		'accent'        => '#0f6e66',
		'sidebar'       => '#1f2a2e',
		'team_cap'      => 'edit_others_posts',
		'guest_cap'     => 'edit_posts',
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
	$d    = defaults();
	$caps = array_keys( capabilities() );
	$out  = array(
		'name'         => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
		'logo_id'      => absint( $in['logo_id'] ?? 0 ),
		'accent'       => colour( $in['accent'] ?? '', $d['accent'] ),
		'sidebar'      => colour( $in['sidebar'] ?? '', $d['sidebar'] ),
		'team_cap'     => in_array( $in['team_cap'] ?? '', $caps, true ) ? $in['team_cap'] : $d['team_cap'],
		'guest_cap'    => '' === ( $in['guest_cap'] ?? '' ) || in_array( $in['guest_cap'], $caps, true ) ? (string) ( $in['guest_cap'] ?? '' ) : $d['guest_cap'],
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
 * Capabilities to choose from.
 *
 * @return array<string,string>
 */
function capabilities(): array {
	return array(
		'manage_options'    => __( 'Administrators (manage_options)', 'flurfunk' ),
		'edit_others_posts' => __( 'Editors and up (edit_others_posts)', 'flurfunk' ),
		'publish_posts'     => __( 'Authors and up (publish_posts)', 'flurfunk' ),
		'edit_posts'        => __( 'Contributors and up (edit_posts)', 'flurfunk' ),
	);
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

add_action(
	'admin_menu',
	static function (): void {
		add_options_page( __( 'Flurfunk', 'flurfunk' ), __( 'Flurfunk', 'flurfunk' ), 'manage_options', PAGE, __NAMESPACE__ . '\\render' );
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
			Store\ensure_channel( (string) $slug, (array) $c + array( 'archived' => empty( $c['active'] ) ? 1 : 0 ), true );
		}
		$new = (array) ( $in['new_channel'] ?? array() );
		if ( '' !== trim( (string) ( $new['name'] ?? '' ) ) ) {
			$slug = sanitize_key( ( $new['slug'] ?? '' ) ?: sanitize_title( (string) $new['name'] ) );
			Store\ensure_channel( $slug, $new );
		}
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . PAGE ) ) );
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
	wp_enqueue_media();
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
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Flurfunk', 'flurfunk' ); ?></h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
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

			<h2><?php esc_html_e( 'People', 'flurfunk' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Team', 'flurfunk' ); ?></th>
					<td><?php $select( 'flurfunk[team_cap]', capabilities(), $s['team_cap'] ); ?>
						<p class="description"><?php esc_html_e( 'The team sees channels according to their team setting.', 'flurfunk' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Guests', 'flurfunk' ); ?></th>
					<td><?php $select( 'flurfunk[guest_cap]', array( '' => __( 'No guests', 'flurfunk' ) ) + capabilities(), $s['guest_cap'] ); ?>
						<p class="description"><?php esc_html_e( 'Everyone else with this capability is a guest, e.g. guest authors. Guests see channels according to their guest setting.', 'flurfunk' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Channels', 'flurfunk' ); ?></h2>
			<table class="widefat striped" style="max-width:60rem">
				<thead><tr><th><?php esc_html_e( 'Active', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Name', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Topic', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Team sees', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Guests see', 'flurfunk' ); ?></th><th><?php esc_html_e( 'Order', 'flurfunk' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Store\channels( true ) as $c ) : ?>
					<tr>
						<td><input type="checkbox" name="channels[<?php echo esc_attr( $c->slug ); ?>][active]" value="1" <?php checked( ! $c->archived ); ?> aria-label="<?php esc_attr_e( 'Active', 'flurfunk' ); ?>"></td>
						<td><input name="channels[<?php echo esc_attr( $c->slug ); ?>][name]" value="<?php echo esc_attr( $c->name ); ?>"><br><code>#<?php echo esc_html( $c->slug ); ?></code></td>
						<td><input class="regular-text" name="channels[<?php echo esc_attr( $c->slug ); ?>][topic]" value="<?php echo esc_attr( $c->topic ); ?>"></td>
						<td><?php $select( 'channels[' . $c->slug . '][access_team]', $levels, $c->access_team ); ?></td>
						<td><?php $select( 'channels[' . $c->slug . '][access_guests]', $levels, $c->access_guests ); ?></td>
						<td><input type="number" style="width:5em" name="channels[<?php echo esc_attr( $c->slug ); ?>][position]" value="<?php echo (int) $c->position; ?>"></td>
					</tr>
				<?php endforeach; ?>
					<tr>
						<td><?php esc_html_e( 'New', 'flurfunk' ); ?></td>
						<td><input name="new_channel[name]" placeholder="<?php esc_attr_e( 'Name', 'flurfunk' ); ?>"></td>
						<td><input class="regular-text" name="new_channel[topic]" placeholder="<?php esc_attr_e( 'Topic', 'flurfunk' ); ?>"></td>
						<td><?php $select( 'new_channel[access_team]', $levels, 'all' ); ?></td>
						<td><?php $select( 'new_channel[access_guests]', $levels, 'none' ); ?></td>
						<td><input type="number" style="width:5em" name="new_channel[position]" value="100"></td>
					</tr>
				</tbody>
			</table>

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
						<p class="description"><?php esc_html_e( 'Export and import as JSON: wp flurfunk export / wp flurfunk import.', 'flurfunk' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<script>
	( function () {
		var pick = document.getElementById( 'ff-logo-pick' ), clear = document.getElementById( 'ff-logo-clear' );
		var input = document.getElementById( 'ff-logo' ), img = document.getElementById( 'ff-logo-preview' ), frame;
		pick.addEventListener( 'click', function () {
			frame = frame || wp.media( { library: { type: 'image' }, multiple: false } );
			frame.off( 'select' ).on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				input.value = a.id;
				img.src = ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url );
				img.style.display = '';
			} );
			frame.open();
		} );
		clear.addEventListener( 'click', function () { input.value = 0; img.style.display = 'none'; } );
	} )();
	</script>
	<?php
}

<?php
/**
 * Capabilities: Flurfunk's own rights, granted to roles like any other WordPress capability.
 *
 *   flurfunk_team      takes part fully; sees channels according to their team level
 *   flurfunk_guest     takes part as a guest; sees channels according to their guest level
 *   flurfunk_moderate  edits and deletes other people's messages and threads
 *   flurfunk_manage    settings, channels, modules, assistants, export and import
 *
 * Flurfunk only ever checks these (plus WordPress's own upload_files for files), so role editors
 * (Members, User Role Editor, …) hand them out like any other capability, and the matrix under
 * Settings → Flurfunk → Permissions does the same.
 *
 * Modules, providers and other plugins declare more:
 *
 *   flurfunk_register_capability( 'acme_send', array(
 *       'label'       => 'Send newsletters',
 *       'description' => 'May send a campaign from a card.',
 *       'roles'       => array( 'administrator', 'editor' ),   // recommended
 *   ) );
 *
 * or with a `capabilities` argument to flurfunk_register_module() / flurfunk_register_provider().
 * The recommended roles get the capability once (for a module: once it is switched on), unless the
 * site already decided (some role has it, or it was saved in the matrix). After that the site
 * decides; Flurfunk never grants it again.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Caps;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const TEAM     = 'flurfunk_team';
const GUEST    = 'flurfunk_guest';
const MODERATE = 'flurfunk_moderate';
const MANAGE   = 'flurfunk_manage';
const VERSION  = 1;
const OPTION   = 'flurfunk_caps_version';
const SEEDED   = 'flurfunk_caps_seeded'; // cap => array( 'new' => bool ): recommended grants done.

/**
 * Flurfunk's own capabilities and the roles that get them on a fresh site.
 *
 * @return array<string, string[]>
 */
function core_roles(): array {
	return array(
		TEAM     => array( 'administrator', 'editor' ),
		GUEST    => array( 'author', 'contributor' ),
		MODERATE => array( 'administrator', 'editor' ),
		MANAGE   => array( 'administrator' ),
	);
}

/**
 * Flurfunk's own capabilities with labels.
 *
 * @return array<string, array>
 */
function core(): array {
	$group = __( 'Flurfunk', 'flurfunk' );
	$texts = array(
		TEAM     => array( __( 'Team', 'flurfunk' ), __( 'Takes part fully and sees channels according to their team setting.', 'flurfunk' ) ),
		GUEST    => array( __( 'Guest', 'flurfunk' ), __( 'Takes part as a guest and sees channels according to their guest setting, e.g. only threads about their own posts. Team wins if someone has both.', 'flurfunk' ) ),
		MODERATE => array( __( 'Moderate', 'flurfunk' ), __( 'Edits and deletes other people\'s messages and threads.', 'flurfunk' ) ),
		MANAGE   => array( __( 'Manage', 'flurfunk' ), __( 'Settings, channels, permissions, modules, assistants, export and import.', 'flurfunk' ) ),
	);
	$out = array();
	foreach ( core_roles() as $cap => $roles ) {
		$out[ $cap ] = array(
			'label'       => $texts[ $cap ][0],
			'description' => $texts[ $cap ][1],
			'roles'       => $roles,
			'group'       => $group,
			'module'      => '',
		);
	}
	return $out;
}

/**
 * Capabilities declared by modules, providers and plugins.
 *
 * @return array<string, array>
 */
function &registry(): array {
	static $caps = array();
	return $caps;
}

/**
 * Declare a capability.
 *
 * @param string $cap  Capability, e.g. "acme_send" (lower case, digits, _ and -).
 * @param array  $args label, description, roles (recommended role names), group, module (ID of
 *                     the module it belongs to: granted only once that module is on).
 * @return bool Registered.
 */
function register( string $cap, array $args = array() ): bool {
	if ( '' === $cap || sanitize_key( $cap ) !== $cap || isset( core_roles()[ $cap ] ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'A Flurfunk capability needs a name of lower-case letters, digits, _ and - that is not one of Flurfunk\'s own.', 'flurfunk' ), '0.2.0' );
		return false;
	}
	$caps         = &registry();
	$caps[ $cap ] = normalize( $args, $cap );
	return true;
}

/**
 * Fill in a capability definition.
 *
 * @param array  $args Definition.
 * @param string $cap  Capability.
 */
function normalize( array $args, string $cap ): array {
	return array(
		'label'       => (string) ( $args['label'] ?? $cap ),
		'description' => (string) ( $args['description'] ?? '' ),
		'roles'       => array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['roles'] ?? array() ) ) ) ),
		'group'       => (string) ( ( $args['group'] ?? '' ) ?: __( 'Other', 'flurfunk' ) ),
		'module'      => (string) ( $args['module'] ?? '' ),
	);
}

/**
 * Declare the capabilities from a module's or provider's `capabilities` argument.
 *
 * @param mixed  $list   cap => definition (or a list of cap names).
 * @param string $group  Shown as the group in the matrix.
 * @param string $module Module ID ('' = none).
 */
function register_list( $list, string $group, string $module = '' ): void {
	foreach ( (array) $list as $cap => $def ) {
		if ( is_int( $cap ) && is_string( $def ) ) {
			$cap = $def;
			$def = array();
		}
		register( (string) $cap, (array) $def + array( 'group' => $group, 'module' => $module ) );
	}
}

/**
 * All capabilities (Flurfunk's own first), adjustable with the flurfunk_capabilities filter.
 *
 * @return array<string, array>
 */
function all(): array {
	$caps = (array) apply_filters( 'flurfunk_capabilities', core() + registry() );
	$out  = array();
	foreach ( $caps as $cap => $def ) {
		if ( is_string( $cap ) && '' !== $cap && sanitize_key( $cap ) === $cap ) {
			$out[ $cap ] = normalize( (array) $def, $cap );
		}
	}
	return $out;
}

/**
 * Does the person have this capability, as Flurfunk counts it? flurfunk_team means "is team"
 * (assistants and the flurfunk_user_kind filter included), flurfunk_guest and '' mean "uses
 * Flurfunk" (team or guest); anything else is a normal capability check.
 *
 * @param int    $user_id User.
 * @param string $cap     Capability.
 */
function user_has( int $user_id, string $cap ): bool {
	if ( TEAM === $cap ) {
		return 'team' === Access\kind( $user_id );
	}
	if ( GUEST === $cap || '' === $cap ) {
		return Access\can_use( $user_id );
	}
	return user_can( $user_id, $cap );
}

/**
 * Roles that have a capability.
 *
 * @param string $cap Capability.
 * @return string[] Role names.
 */
function roles_with( string $cap ): array {
	$out = array();
	foreach ( wp_roles()->role_objects as $name => $role ) {
		if ( $role->has_cap( $cap ) ) {
			$out[] = (string) $name;
		}
	}
	return $out;
}

/**
 * Grant a capability to roles (those that exist).
 *
 * @param string   $cap   Capability.
 * @param string[] $roles Role names.
 */
function grant( string $cap, array $roles ): void {
	foreach ( $roles as $name ) {
		$role = get_role( $name );
		if ( $role && ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}

/**
 * Bring the roles up to date (activation, and the first request after an update). Runs once per
 * site: a site from 0.1 keeps who had access (team/guest capability settings become role grants),
 * a fresh site gets the defaults.
 */
function install(): void {
	if ( (int) get_option( OPTION, 0 ) >= VERSION ) {
		return;
	}
	$legacy = legacy();
	null === $legacy ? grant_defaults() : migrate( $legacy );
	ensure_manager();
	$seeded = (array) get_option( SEEDED, array() );
	foreach ( array_keys( core_roles() ) as $cap ) {
		$seeded[ $cap ] = array( 'new' => true );
	}
	update_option( SEEDED, $seeded, true );
	update_option( OPTION, VERSION, true );
}

/**
 * A fresh site: administrators manage, moderate and are team; editors moderate and are team;
 * authors and contributors are guests.
 */
function grant_defaults(): void {
	foreach ( core_roles() as $cap => $roles ) {
		grant( $cap, $roles );
	}
}

/**
 * The 0.1 access settings of an existing site, or null on a fresh one. 0.1 chose a WordPress
 * capability for the team and one for guests (Settings → Flurfunk → People), also through the
 * flurfunk_settings filter.
 *
 * @return array{team: string, guest: string}|null
 */
function legacy(): ?array {
	global $wpdb;
	$opt = get_option( 'flurfunk_settings' );
	$old = false !== $opt;
	if ( ! $old && get_option( 'flurfunk_db_version' ) ) {
		// Tables alone come from the first request after copying the plugin; channels only exist
		// once Flurfunk ran (activation creates #general).
		$table    = $wpdb->prefix . 'flurfunk_channels';
		$suppress = $wpdb->suppress_errors();
		$old      = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once per site, on upgrade.
		$wpdb->suppress_errors( $suppress );
	}
	if ( ! $old ) {
		return null;
	}
	$s = (array) apply_filters(
		'flurfunk_settings',
		array_merge(
			array(
				'team_cap'  => 'edit_others_posts',
				'guest_cap' => 'edit_posts',
			),
			is_array( $opt ) ? $opt : array()
		)
	);
	return array(
		'team'  => (string) ( $s['team_cap'] ?? 'edit_others_posts' ),
		'guest' => (string) ( $s['guest_cap'] ?? '' ),
	);
}

/**
 * Turn the 0.1 settings into role grants, then forget them: roles with the team capability get
 * flurfunk_team, the others with the guest capability flurfunk_guest, roles with manage_options
 * flurfunk_manage and flurfunk_moderate (in 0.1 only they could edit other people's messages).
 *
 * @param array $legacy team, guest (capabilities; guest '' = no guests).
 */
function migrate( array $legacy ): void {
	foreach ( wp_roles()->role_objects as $role ) {
		if ( '' !== $legacy['team'] && $role->has_cap( $legacy['team'] ) ) {
			$role->add_cap( TEAM );
		} elseif ( '' !== $legacy['guest'] && $role->has_cap( $legacy['guest'] ) ) {
			$role->add_cap( GUEST );
		}
		if ( $role->has_cap( 'manage_options' ) ) {
			$role->add_cap( MANAGE );
			$role->add_cap( MODERATE );
		}
	}
	$opt = get_option( 'flurfunk_settings' );
	if ( is_array( $opt ) && ( array_key_exists( 'team_cap', $opt ) || array_key_exists( 'guest_cap', $opt ) ) ) {
		unset( $opt['team_cap'], $opt['guest_cap'] );
		update_option( 'flurfunk_settings', $opt, true );
	}
}

/**
 * Lockout protection: if no role may manage Flurfunk any more (e.g. after a role editor removed
 * it everywhere), administrators get it back.
 */
function ensure_manager(): void {
	if ( ! roles_with( MANAGE ) ) {
		grant( MANAGE, array( 'administrator' ) );
	}
}

/**
 * Apply the recommended grants of declared capabilities once: those of plugins and providers
 * right away, those of modules once the module is on. Skipped where the site already decided.
 */
function seed_pending(): void {
	$seeded  = (array) get_option( SEEDED, array() );
	$changed = false;
	foreach ( all() as $cap => $def ) {
		if ( isset( $seeded[ $cap ] ) || isset( core_roles()[ $cap ] ) ) {
			continue;
		}
		if ( '' !== $def['module'] && ! \Flurfunk\Modules\active( $def['module'] ) ) {
			continue;
		}
		$had = (bool) roles_with( $cap );
		if ( ! $had ) {
			grant( $cap, $def['roles'] );
		}
		$seeded[ $cap ] = array( 'new' => ! $had );
		$changed        = true;
	}
	if ( $changed ) {
		update_option( SEEDED, $seeded, true );
	}
}

/**
 * Capabilities shown in the matrix: all except those of modules that are off and that no role has.
 *
 * @return array<string, array>
 */
function shown(): array {
	return array_filter(
		all(),
		static fn ( array $def, string $cap ): bool => '' === $def['module'] || \Flurfunk\Modules\active( $def['module'] ) || roles_with( $cap ),
		ARRAY_FILTER_USE_BOTH
	);
}

/**
 * Save the matrix: role => cap => on, for the capabilities shown. Administrators always keep
 * flurfunk_manage, and nobody takes it away from the last role that has it or from themselves.
 *
 * @param array $grid Role name => array of capability => truthy.
 * @return bool True if a manage change was held back to prevent a lockout.
 */
function save_matrix( array $grid ): bool {
	$caps  = array_keys( shown() );
	$roles = wp_roles()->role_objects;
	$want  = array();
	foreach ( $roles as $name => $role ) {
		foreach ( $caps as $cap ) {
			$want[ $name ][ $cap ] = ! empty( $grid[ $name ][ $cap ] ) || ( MANAGE === $cap && 'administrator' === $name );
		}
	}
	$held = false;
	if ( in_array( MANAGE, $caps, true ) ) {
		$managers = array_keys( array_filter( $want, static fn ( array $c ): bool => ! empty( $c[ MANAGE ] ) ) );
		$user     = wp_get_current_user();
		$mine     = array_intersect( (array) $user->roles, roles_with( MANAGE ) );
		$direct   = ! empty( $user->caps[ MANAGE ] ) || is_super_admin( $user->ID );
		if ( ! $managers ) {
			// Keep it where it is rather than leave nobody.
			foreach ( roles_with( MANAGE ) as $name ) {
				$want[ $name ][ MANAGE ] = true;
			}
			$held = true;
		} elseif ( $mine && ! $direct && ! array_intersect( $mine, $managers ) ) {
			// The person saving would lock themselves out.
			foreach ( $mine as $name ) {
				$want[ $name ][ MANAGE ] = true;
			}
			$held = true;
		}
	}
	foreach ( $want as $name => $list ) {
		foreach ( $list as $cap => $on ) {
			$has = $roles[ $name ]->has_cap( $cap );
			if ( $on && ! $has ) {
				$roles[ $name ]->add_cap( $cap );
			} elseif ( ! $on && $has ) {
				$roles[ $name ]->remove_cap( $cap );
			}
		}
	}
	// The site decided: no recommended grants for these any more.
	$seeded = (array) get_option( SEEDED, array() );
	foreach ( $caps as $cap ) {
		$seeded[ $cap ] = $seeded[ $cap ] ?? array( 'new' => true );
	}
	update_option( SEEDED, $seeded, true );
	\Flurfunk\Store\bump();
	return $held;
}

// After an update without re-activation (and on each site of a network): once, on init, when
// theme code (e.g. a flurfunk_settings filter) is loaded but before modules register.
add_action( 'init', __NAMESPACE__ . '\\install', -1 );
// Declared capabilities are known once everything has registered.
add_action( 'wp_loaded', __NAMESPACE__ . '\\seed_pending' );
add_action( 'admin_init', __NAMESPACE__ . '\\ensure_manager' );

/**
 * The permissions section of the settings page (its own form): roles × capabilities.
 */
function render_section(): void {
	$caps   = shown();
	$roles  = wp_roles()->role_names;
	$groups = array();
	foreach ( $caps as $cap => $def ) {
		$groups[ $def['group'] ][ $cap ] = $def;
	}
	?>
	<h2 id="flurfunk-permissions"><?php esc_html_e( 'Permissions', 'flurfunk' ); ?></h2>
	<?php
	$notice = isset( $_GET['permissions'] ) ? sanitize_key( wp_unslash( $_GET['permissions'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the notice after a redirect.
	if ( 'kept' === $notice ) :
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Permissions saved. "Manage" stayed where it was, so nobody locks themselves out.', 'flurfunk' ); ?></p></div>
	<?php elseif ( 'saved' === $notice ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Permissions saved.', 'flurfunk' ); ?></p></div>
	<?php endif; ?>
	<p style="max-width:46rem"><?php esc_html_e( 'Which roles may do what in Flurfunk. Team members see channels according to their team setting, guests according to their guest setting. Assistants always count as team. These are normal WordPress capabilities, so role editor plugins (e.g. Members or User Role Editor) can manage them as well, also for single users.', 'flurfunk' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="flurfunk_caps">
		<?php wp_nonce_field( 'flurfunk_caps' ); ?>
		<div style="overflow-x:auto;max-width:100%">
		<table class="widefat striped ff-caps" style="width:auto;min-width:min(46rem,100%)">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Capability', 'flurfunk' ); ?></th>
					<?php foreach ( $roles as $name => $label ) : ?>
						<th scope="col" style="text-align:center"><?php echo esc_html( translate_user_role( $label ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $groups as $group => $list ) : ?>
				<?php if ( count( $groups ) > 1 ) : ?>
					<tr><th scope="rowgroup" colspan="<?php echo count( $roles ) + 1; ?>"><strong><?php echo esc_html( $group ); ?></strong></th></tr>
				<?php endif; ?>
				<?php foreach ( $list as $cap => $def ) : ?>
					<tr>
						<th scope="row" style="max-width:24rem">
							<strong><?php echo esc_html( $def['label'] ); ?></strong> <code><?php echo esc_html( $cap ); ?></code>
							<?php if ( '' !== $def['description'] ) : ?>
								<br><span class="description"><?php echo esc_html( $def['description'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $def['module'] && ! \Flurfunk\Modules\active( $def['module'] ) ) : ?>
								<br><em><?php esc_html_e( '(module is off)', 'flurfunk' ); ?></em>
							<?php endif; ?>
						</th>
						<?php
						foreach ( $roles as $name => $label ) :
							$role  = get_role( $name );
							$fixed = MANAGE === $cap && 'administrator' === $name;
							/* translators: 1: capability label, 2: role name */
							$aria = sprintf( __( '%1$s for %2$s', 'flurfunk' ), $def['label'], translate_user_role( $label ) );
							?>
							<td style="text-align:center">
								<input type="checkbox" name="caps[<?php echo esc_attr( $name ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" aria-label="<?php echo esc_attr( $aria ); ?>" <?php checked( $fixed || ( $role && $role->has_cap( $cap ) ) ); ?> <?php disabled( $fixed ); ?><?php echo $fixed ? ' title="' . esc_attr__( 'Administrators always keep this.', 'flurfunk' ) . '"' : ''; ?>>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php submit_button( __( 'Save permissions', 'flurfunk' ), 'secondary', 'flurfunk-save-permissions' ); ?>
	</form>
	<?php
}

add_action(
	'admin_post_flurfunk_caps',
	static function (): void {
		if ( ! Access\can_manage() ) {
			wp_die( esc_html__( 'You cannot change this.', 'flurfunk' ), 403 );
		}
		check_admin_referer( 'flurfunk_caps' );
		$in   = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys are checked against the roles and the registry, values only tested for being set.
		$held = save_matrix( (array) $in );
		wp_safe_redirect( admin_url( 'options-general.php?page=flurfunk-settings&permissions=' . ( $held ? 'kept' : 'saved' ) . '#flurfunk-permissions' ) );
		exit;
	}
);

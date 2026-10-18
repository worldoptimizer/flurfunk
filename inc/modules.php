<?php
/**
 * Modules: optional parts of Flurfunk that a site switches on or off under Settings → Flurfunk.
 *
 * Flurfunk's own optional parts (modules/: comment cards, the daily moderation thread, review
 * requests) are modules, and any other plugin can add its own the same way, so everything
 * optional shows up in one place:
 *
 *   add_action( 'flurfunk_modules', function () {
 *       flurfunk_register_module( 'acme/review', array(
 *           'label'       => 'Review workflow',
 *           'description' => 'A task for every post that is submitted for review.',
 *           'group'       => 'Acme',
 *           'requires'    => fn () => post_type_exists( 'post' ),   // optional
 *           'default'     => true,                                   // on until switched off
 *           'load'        => fn () => require __DIR__ . '/review.php',
 *           'capabilities' => array(                                 // optional, see inc/caps.php
 *               'acme_review' => array( 'label' => 'Approve reviews', 'roles' => array( 'editor' ) ),
 *           ),
 *       ) );
 *   } );
 *
 * Registration happens on `flurfunk_modules` (init, priority 0, so labels can be translated);
 * enabled modules whose requirements are met load right after. A module can therefore hook into
 * init (priority 1 and later), admin_menu, rest_api_init and everything after. A module that is
 * switched off is never loaded, so it costs nothing.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules;

defined( 'ABSPATH' ) || exit;

const OPTION = 'flurfunk_modules';

/**
 * All registered modules.
 *
 * @return array<string, array>
 */
function &registry(): array {
	static $modules = array();
	return $modules;
}

/**
 * Register a module.
 *
 * @param string $id   Unique ID, ideally "vendor/name".
 * @param array  $args label, description, group, requires (callable|bool|string[] of
 *                     functions/classes/constants that must exist), default (bool), load
 *                     (callable), settings_url (string, optional link next to the switch),
 *                     capabilities (cap => label, description, roles; the roles get it once the
 *                     module is on, unless the site already decided).
 * @return bool Registered.
 */
function register( string $id, array $args ): bool {
	$id = strtolower( trim( $id ) );
	if ( ! preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $id ) || empty( $args['label'] ) || ! is_callable( $args['load'] ?? null ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'A Flurfunk module needs an ID like "vendor/name", a label and a load callback.', 'flurfunk' ), '0.2.0' );
		return false;
	}
	$modules        = &registry();
	$modules[ $id ] = array(
		'id'           => $id,
		'label'        => (string) $args['label'],
		'description'  => (string) ( $args['description'] ?? '' ),
		'group'        => (string) ( $args['group'] ?? __( 'Other', 'flurfunk' ) ),
		'requires'     => $args['requires'] ?? true,
		'default'      => (bool) ( $args['default'] ?? true ),
		'load'         => $args['load'],
		'settings_url' => (string) ( $args['settings_url'] ?? '' ),
		'loaded'       => false,
	);
	\Flurfunk\Caps\register_list( $args['capabilities'] ?? array(), $modules[ $id ]['label'], $id );
	return true;
}

/**
 * Are a module's requirements met (e.g. the plugin it builds on is active)?
 *
 * @param array $m Module.
 */
function available( array $m ): bool {
	$r = $m['requires'];
	if ( is_callable( $r ) ) {
		return (bool) call_user_func( $r );
	}
	if ( is_array( $r ) ) {
		foreach ( $r as $thing ) {
			if ( ! function_exists( $thing ) && ! class_exists( $thing ) && ! defined( $thing ) ) {
				return false;
			}
		}
		return true;
	}
	return (bool) $r;
}

/**
 * Is a module switched on (the site's choice, or its default)?
 *
 * @param string $id Module ID.
 */
function enabled( string $id ): bool {
	$m = registry()[ $id ] ?? null;
	if ( ! $m ) {
		return false;
	}
	$choices = (array) get_option( OPTION, array() );
	$on      = array_key_exists( $id, $choices ) ? (bool) $choices[ $id ] : $m['default'];
	return (bool) apply_filters( 'flurfunk_module_enabled', $on, $id );
}

/**
 * Is a module switched on and are its requirements met (so it loads)?
 *
 * @param string $id Module ID.
 */
function active( string $id ): bool {
	return enabled( $id ) && available( registry()[ $id ] );
}

/**
 * Switch modules on or off.
 *
 * @param array<string, bool> $changes Module ID => on.
 */
function set( array $changes ): void {
	$choices = (array) get_option( OPTION, array() );
	$changed = array();
	foreach ( $changes as $id => $on ) {
		if ( isset( registry()[ $id ] ) ) {
			if ( enabled( $id ) !== (bool) $on ) {
				$changed[ $id ] = (bool) $on;
			}
			$choices[ $id ] = (bool) $on;
		}
	}
	update_option( OPTION, $choices, true );
	// Only for modules that really changed (the form sends every switch), once the choice is saved.
	foreach ( $changed as $id => $on ) {
		if ( $on ) {
			do_action( 'flurfunk_module_on', $id );
		} else {
			do_action( 'flurfunk_module_off', $id );
		}
	}
	\Flurfunk\Caps\seed_pending(); // Recommended grants of modules that just came on.
}

/**
 * Modules for display, grouped and with their state.
 *
 * @return array<string, array[]> Group => modules.
 */
function listing(): array {
	$out = array();
	foreach ( registry() as $id => $m ) {
		$out[ $m['group'] ][] = array(
			'id'           => $id,
			'label'        => $m['label'],
			'description'  => $m['description'],
			'available'    => available( $m ),
			'enabled'      => enabled( $id ),
			'loaded'       => $m['loaded'],
			'settings_url' => $m['settings_url'],
		);
	}
	ksort( $out );
	return $out;
}

// Flurfunk's own modules. They only build on WordPress itself.
add_action(
	'flurfunk_modules',
	static function (): void {
		register(
			'flurfunk/comments',
			array(
				'label'       => __( 'Comment cards', 'flurfunk' ),
				'description' => __( '/comment inserts a comment as a card, with buttons to approve it, mark it as spam or move it to the trash. For people who may moderate comments.', 'flurfunk' ),
				'group'       => __( 'Core', 'flurfunk' ),
				'load'        => static fn () => require_once FLURFUNK_DIR . 'modules/comments.php',
			)
		);
		register(
			'flurfunk/comments-daily',
			array(
				'label'       => __( 'Daily moderation thread', 'flurfunk' ),
				'description' => __( 'Once a day, if comments are waiting for approval, a thread in #general lists them.', 'flurfunk' ),
				'group'       => __( 'Core', 'flurfunk' ),
				'default'     => false,
				'load'        => static fn () => require_once FLURFUNK_DIR . 'modules/comments-daily.php',
			)
		);
		register(
			'flurfunk/review',
			array(
				'label'       => __( 'Review requests', 'flurfunk' ),
				'description' => __( 'A post submitted for review becomes a task for the people who may publish it; publishing it ticks the task off.', 'flurfunk' ),
				'group'       => __( 'Core', 'flurfunk' ),
				'default'     => false,
				'load'        => static fn () => require_once FLURFUNK_DIR . 'modules/review.php',
			)
		);
	},
	5
);

// Registration, then loading what is switched on.
add_action(
	'init',
	static function (): void {
		do_action( 'flurfunk_modules' );
		$modules = &registry();
		foreach ( $modules as $id => &$m ) {
			if ( enabled( $id ) && available( $m ) ) {
				call_user_func( $m['load'] );
				$m['loaded'] = true;
			}
		}
		unset( $m );
		do_action( 'flurfunk_modules_loaded' );
	},
	0
);

/**
 * The modules section of the settings page (its own form).
 */
function render_section(): void {
	$groups = listing();
	?>
	<h2 id="flurfunk-modules"><?php esc_html_e( 'Modules', 'flurfunk' ); ?></h2>
	<?php if ( isset( $_GET['modules'] ) && 'saved' === $_GET['modules'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the notice after a redirect. ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Modules saved.', 'flurfunk' ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'Optional parts of Flurfunk and additions from other plugins. Modules that are off are not loaded at all.', 'flurfunk' ); ?></p>
	<?php if ( ! $groups ) : ?>
		<p class="description"><?php esc_html_e( 'No modules are installed.', 'flurfunk' ); ?></p>
		<?php
		return;
	endif;
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="flurfunk_modules">
		<?php wp_nonce_field( 'flurfunk_modules' ); ?>
		<table class="form-table" role="presentation">
			<?php foreach ( $groups as $group => $list ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $group ); ?></th>
					<td>
						<?php foreach ( $list as $m ) : ?>
							<p>
								<input type="hidden" name="modules[<?php echo esc_attr( $m['id'] ); ?>]" value="0">
								<label><input type="checkbox" name="modules[<?php echo esc_attr( $m['id'] ); ?>]" value="1" <?php checked( $m['enabled'] ); ?>> <strong><?php echo esc_html( $m['label'] ); ?></strong></label>
								<?php if ( ! $m['available'] ) : ?>
									<em>(<?php esc_html_e( 'needs a plugin that is not active', 'flurfunk' ); ?>)</em>
								<?php endif; ?>
								<?php if ( $m['settings_url'] ) : ?>
									· <a href="<?php echo esc_url( $m['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'flurfunk' ); ?></a>
								<?php endif; ?>
								<?php if ( $m['description'] ) : ?>
									<br><span class="description"><?php echo esc_html( $m['description'] ); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button( __( 'Save modules', 'flurfunk' ), 'secondary' ); ?>
	</form>
	<?php
}

add_action(
	'admin_post_flurfunk_modules',
	static function (): void {
		if ( ! \Flurfunk\Access\can_manage() ) {
			wp_die( esc_html__( 'You cannot change this.', 'flurfunk' ), 403 );
		}
		check_admin_referer( 'flurfunk_modules' );
		$in = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys checked against the registry, values cast to bool.
		$changes = array();
		foreach ( $in as $id => $on ) {
			$changes[ sanitize_text_field( (string) $id ) ] = '1' === (string) $on;
		}
		set( $changes );
		wp_safe_redirect( admin_url( 'options-general.php?page=flurfunk-settings&modules=saved#flurfunk-modules' ) );
		exit;
	}
);

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
 *           'settings'     => array(                                 // optional, shown under the switch
 *               array( 'key' => 'channel', 'type' => 'channel', 'label' => 'Channel', 'default' => 'general' ),
 *               array( 'key' => 'days', 'type' => 'number', 'label' => 'Days', 'default' => 7, 'min' => 1, 'max' => 90 ),
 *           ),
 *       ) );
 *   } );
 *
 *   flurfunk_module_setting( 'acme/review', 'channel' );   // the saved value, or the default
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

const OPTION   = 'flurfunk_modules';
const SETTINGS = 'flurfunk_module_settings'; // module ID => key => value.
const TYPES    = array( 'channel', 'checkbox', 'number', 'text', 'select', 'roles' );

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
 *                     module is on, unless the site already decided), settings (fields: key,
 *                     type channel|checkbox|number|text|select|roles, label, description,
 *                     default, options for select, min/max for number).
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
		'settings'     => fields( (array) ( $args['settings'] ?? array() ) ),
		'loaded'       => false,
	);
	\Flurfunk\Caps\register_list( $args['capabilities'] ?? array(), $modules[ $id ]['label'], $id );
	return true;
}

/**
 * Clean up a module's settings fields; fields without a key or with an unknown type are left out.
 *
 * @param array $list Fields as registered.
 * @return array<string, array> Key => field.
 */
function fields( array $list ): array {
	$out = array();
	foreach ( $list as $f ) {
		$f    = (array) $f;
		$key  = sanitize_key( (string) ( $f['key'] ?? '' ) );
		$type = (string) ( $f['type'] ?? 'text' );
		if ( '' === $key || ! in_array( $type, TYPES, true ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'A Flurfunk module setting needs a key and one of the types channel, checkbox, number, text, select or roles.', 'flurfunk' ), '0.2.0' );
			continue;
		}
		$field = array(
			'key'         => $key,
			'type'        => $type,
			'label'       => (string) ( $f['label'] ?? $key ),
			'description' => (string) ( $f['description'] ?? '' ),
			'options'     => array_map( 'strval', (array) ( $f['options'] ?? array() ) ),
			'min'         => isset( $f['min'] ) && is_numeric( $f['min'] ) ? 0 + $f['min'] : null,
			'max'         => isset( $f['max'] ) && is_numeric( $f['max'] ) ? 0 + $f['max'] : null,
		);
		$defaults         = array(
			'checkbox' => false,
			'number'   => 0,
			'roles'    => array(),
		);
		$field['default'] = $f['default'] ?? ( $defaults[ $type ] ?? '' );
		$out[ $key ]      = $field;
	}
	return $out;
}

/**
 * Sanitize one value for a settings field. Anything that does not fit becomes the default.
 *
 * @param array $f   Field.
 * @param mixed $raw Input.
 * @return mixed
 */
function sanitize_value( array $f, $raw ) {
	switch ( $f['type'] ) {
		case 'checkbox':
			return is_bool( $raw ) ? $raw : in_array( (string) ( is_scalar( $raw ) ? $raw : '' ), array( '1', 'on', 'yes', 'true' ), true );
		case 'number':
			if ( ! is_numeric( $raw ) ) {
				return $f['default'];
			}
			$n = str_contains( (string) $raw, '.' ) || str_contains( strtolower( (string) $raw ), 'e' ) ? (float) $raw : (int) $raw;
			if ( null !== $f['min'] && $n < $f['min'] ) {
				$n = $f['min'];
			}
			if ( null !== $f['max'] && $n > $f['max'] ) {
				$n = $f['max'];
			}
			return $n;
		case 'select':
			$v = is_scalar( $raw ) ? (string) $raw : '';
			return array_key_exists( $v, $f['options'] ) ? $v : $f['default'];
		case 'channel':
			$v = sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
			return '' !== $v && \Flurfunk\Store\channel_by_slug( $v ) ? $v : $f['default'];
		case 'roles':
			$roles = array_keys( wp_roles()->get_names() );
			return array_values( array_intersect( $roles, array_map( 'sanitize_key', array_filter( (array) $raw, 'is_scalar' ) ) ) );
		default:
			return sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
	}
}

/**
 * A module's setting: the saved value, or the field's default. Unknown module or key: null.
 *
 * @param string $id  Module ID.
 * @param string $key Setting key.
 * @return mixed
 */
function setting( string $id, string $key ) {
	$f = registry()[ $id ]['settings'][ $key ] ?? null;
	if ( ! $f ) {
		$value = null;
	} else {
		$all   = (array) get_option( SETTINGS, array() );
		$saved = (array) ( $all[ $id ] ?? array() );
		$value = array_key_exists( $key, $saved ) ? $saved[ $key ] : $f['default'];
	}
	return apply_filters( 'flurfunk_module_setting', $value, $id, $key );
}

/**
 * Save a module's settings from input. Keys that are not in $in keep their value, except
 * checkboxes and role lists, which a form leaves out when nothing is ticked: pass $complete.
 *
 * @param string $id       Module ID.
 * @param array  $in       Key => raw value.
 * @param bool   $complete $in is the whole form (missing checkboxes are off, missing roles none).
 * @return array The module's stored settings.
 */
function save_settings( string $id, array $in, bool $complete = false ): array {
	$fields = registry()[ $id ]['settings'] ?? array();
	$all    = (array) get_option( SETTINGS, array() );
	$mine   = (array) ( $all[ $id ] ?? array() );
	foreach ( $fields as $key => $f ) {
		if ( array_key_exists( $key, $in ) ) {
			$mine[ $key ] = sanitize_value( $f, $in[ $key ] );
		} elseif ( $complete && 'checkbox' === $f['type'] ) {
			$mine[ $key ] = false;
		} elseif ( $complete && 'roles' === $f['type'] ) {
			$mine[ $key ] = array();
		}
	}
	if ( $fields ) {
		$all[ $id ] = $mine;
		update_option( SETTINGS, $all, false );
	}
	return $mine;
}

/**
 * One settings field in the modules form.
 *
 * @param string $id Module ID.
 * @param array  $f  Field.
 */
function render_field( string $id, array $f ): void {
	$name  = 'module_settings[' . $id . '][' . $f['key'] . ']';
	$value = setting( $id, $f['key'] );
	$html  = 'ff-ms-' . preg_replace( '/[^a-z0-9_-]/', '-', $id . '-' . $f['key'] );
	echo '<span class="ff-module-setting" style="display:block;margin:.4em 0 .4em 1.8em">';
	switch ( $f['type'] ) {
		case 'checkbox':
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
			echo '<label><input type="checkbox" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $f['label'] ) . '</label>';
			break;
		case 'roles':
			echo '<span>' . esc_html( $f['label'] ) . ':</span> <input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
			foreach ( wp_roles()->get_names() as $role => $label ) {
				echo '<label style="margin-right:1em"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $role ) . '"' . checked( in_array( $role, (array) $value, true ), true, false ) . '> ' . esc_html( translate_user_role( $label ) ) . '</label>';
			}
			break;
		case 'select':
		case 'channel':
			$options = $f['options'];
			if ( 'channel' === $f['type'] ) {
				$options = array();
				foreach ( \Flurfunk\Store\channels() as $c ) {
					$options[ $c->slug ] = '#' . $c->slug . ( $c->name && $c->name !== $c->slug ? ' – ' . $c->name : '' ) . ( $c->archived ? ' ' . __( '(not active)', 'flurfunk' ) : '' );
				}
				if ( '' !== (string) $value && ! isset( $options[ (string) $value ] ) ) {
					/* translators: %s: channel short name */
					$options[ (string) $value ] = sprintf( __( '#%s (created when needed)', 'flurfunk' ), (string) $value );
				}
			}
			echo '<label for="' . esc_attr( $html ) . '">' . esc_html( $f['label'] ) . '</label> <select id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $options as $v => $l ) {
				echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $value, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select>';
			break;
		case 'number':
			echo '<label for="' . esc_attr( $html ) . '">' . esc_html( $f['label'] ) . '</label> <input type="number" style="width:6em" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( null !== $f['min'] ? ' min="' . esc_attr( (string) $f['min'] ) . '"' : '' ) . ( null !== $f['max'] ? ' max="' . esc_attr( (string) $f['max'] ) . '"' : '' ) . ( is_float( $value ) ? ' step="any"' : '' ) . '>';
			break;
		default:
			echo '<label for="' . esc_attr( $html ) . '">' . esc_html( $f['label'] ) . '</label> <input class="regular-text" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}
	if ( '' !== $f['description'] ) {
		echo '<br><span class="description">' . esc_html( $f['description'] ) . '</span>';
	}
	echo '</span>';
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
			'settings'     => $m['settings'],
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
								<?php
								foreach ( $m['settings'] as $f ) {
									render_field( $m['id'], $f );
								}
								?>
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
		// Settings fields under the switches. Keys are checked against the registry, values sanitized by type.
		$values = isset( $_POST['module_settings'] ) && is_array( $_POST['module_settings'] ) ? wp_unslash( $_POST['module_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field type in save_settings().
		foreach ( $values as $id => $fields ) {
			$id = sanitize_text_field( (string) $id );
			if ( isset( registry()[ $id ] ) && is_array( $fields ) ) {
				save_settings( $id, $fields, true );
			}
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=flurfunk-settings&modules=saved#flurfunk-modules' ) );
		exit;
	}
);

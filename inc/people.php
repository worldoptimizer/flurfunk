<?php
/**
 * Team or guest per person. Who takes part comes from the roles (Settings → Flurfunk →
 * Permissions); single people can differ from their role, as in Slack, where every account is a
 * member or a guest: a trainee who is a "contributor" can be team, a freelancer with the same role
 * a guest, someone else nothing at all. Stored as WordPress capabilities on the account itself
 * (granted or denied), so role editors show the same and nothing else is needed.
 *
 * @package Flurfunk
 */

namespace Flurfunk\People;

use Flurfunk\Access;
use Flurfunk\Caps;

defined( 'ABSPATH' ) || exit;

add_action( 'edit_user_profile', __NAMESPACE__ . '\\field' );
add_action( 'show_user_profile', __NAMESPACE__ . '\\field' );
add_action( 'edit_user_profile_update', __NAMESPACE__ . '\\save' );

/**
 * What the account itself says ('' = as its role, 'team', 'guest', 'none').
 *
 * @param int $user_id User.
 */
function choice( int $user_id ): string {
	$user = get_userdata( $user_id );
	$caps = $user ? (array) $user->caps : array();
	if ( ! array_key_exists( Caps\TEAM, $caps ) ) {
		return '';
	}
	if ( $caps[ Caps\TEAM ] ) {
		return 'team';
	}
	return empty( $caps[ Caps\GUEST ] ) ? 'none' : 'guest';
}

/**
 * Set it.
 *
 * @param int    $user_id User.
 * @param string $choice  '' (as its role), 'team', 'guest' or 'none'.
 */
function set_choice( int $user_id, string $choice ): void {
	$user = get_userdata( $user_id );
	if ( ! $user || Access\is_bot( $user_id ) ) {
		return;
	}
	$user->remove_cap( Caps\TEAM );
	$user->remove_cap( Caps\GUEST );
	if ( 'team' === $choice ) {
		$user->add_cap( Caps\TEAM, true );
	} elseif ( 'guest' === $choice ) {
		$user->add_cap( Caps\TEAM, false );
		$user->add_cap( Caps\GUEST, true );
	} elseif ( 'none' === $choice ) {
		$user->add_cap( Caps\TEAM, false );
		$user->add_cap( Caps\GUEST, false );
	}
	Access\kinds_changed( true );
	\Flurfunk\Store\bump();
}

/**
 * What the role alone would make of someone.
 *
 * @param \WP_User $user User.
 */
function by_role( \WP_User $user ): string {
	foreach ( $user->roles as $role ) {
		$r = get_role( $role );
		if ( $r && $r->has_cap( Caps\TEAM ) ) {
			return 'team';
		}
	}
	foreach ( $user->roles as $role ) {
		$r = get_role( $role );
		if ( $r && $r->has_cap( Caps\GUEST ) ) {
			return 'guest';
		}
	}
	return 'none';
}

/**
 * Labels.
 *
 * @return array<string, string>
 */
function labels(): array {
	return array(
		'team'  => __( 'Team', 'flurfunk' ),
		'guest' => __( 'Guest', 'flurfunk' ),
		'none'  => __( 'No access', 'flurfunk' ),
	);
}

/**
 * People whose account differs from their role.
 *
 * @return array<int, string> User => choice.
 */
function exceptions(): array {
	global $wpdb;
	$out = array();
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of accounts, on the settings page only.
	$ids = get_users(
		array(
			'fields'     => 'ID',
			'meta_query' => array(
				array(
					'key'     => $wpdb->get_blog_prefix() . 'capabilities',
					'value'   => 'flurfunk_',
					'compare' => 'LIKE',
				),
			),
		)
	);
	foreach ( array_map( 'intval', $ids ) as $id ) {
		$c = choice( $id );
		if ( '' !== $c && ! Access\is_bot( $id ) ) {
			$out[ $id ] = $c;
		}
	}
	return $out;
}

/**
 * The field on a profile (for people who manage Flurfunk; on their own profile only shown).
 *
 * @param \WP_User $user User.
 */
function field( \WP_User $user ): void {
	if ( ! Access\can_manage() || Access\is_bot( $user->ID ) ) {
		return;
	}
	$labels = labels();
	$now    = choice( $user->ID );
	$own    = get_current_user_id() === $user->ID || ! current_user_can( 'promote_users' );
	?>
	<h2><?php esc_html_e( 'Flurfunk', 'flurfunk' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="flurfunk-kind"><?php esc_html_e( 'Takes part as', 'flurfunk' ); ?></label></th>
			<td>
				<select name="flurfunk_kind" id="flurfunk-kind" <?php disabled( $own ); ?>>
					<?php /* translators: %s: Team, Guest or No access */ ?>
					<option value=""><?php echo esc_html( sprintf( __( 'As the role says (%s)', 'flurfunk' ), $labels[ by_role( $user ) ] ) ); ?></option>
					<?php foreach ( $labels as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $now, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php wp_nonce_field( 'flurfunk_kind_' . $user->ID, 'flurfunk_kind_nonce' ); ?>
				<p class="description"><?php echo esc_html( $own ? __( 'Only people who may change user roles can change this, and not for their own account.', 'flurfunk' ) : __( 'Team sees channels by their setting for the team, guests by the one for guests. For one person, e.g. a trainee in the team although their role is "Contributor", or a freelancer as a guest.', 'flurfunk' ) ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Save the field.
 *
 * @param int $user_id User.
 */
function save( int $user_id ): void {
	if ( ! isset( $_POST['flurfunk_kind'], $_POST['flurfunk_kind_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['flurfunk_kind_nonce'] ) ), 'flurfunk_kind_' . $user_id ) ) {
		return;
	}
	if ( ! Access\can_manage() || ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) || get_current_user_id() === $user_id ) {
		return;
	}
	$choice = sanitize_key( wp_unslash( $_POST['flurfunk_kind'] ) );
	set_choice( $user_id, in_array( $choice, array( 'team', 'guest', 'none' ), true ) ? $choice : '' );
}

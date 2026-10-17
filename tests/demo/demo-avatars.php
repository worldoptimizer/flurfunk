<?php
/**
 * Plugin Name: Flurfunk demo avatars
 * Description: Profile pictures and job titles for the Northline demo accounts from tests/demo/seed.php. Copy into wp-content/mu-plugins on a demo site only.
 *
 * @package Flurfunk
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_get_avatar_data',
	static function ( array $args, $id_or_email ): array {
		if ( ! defined( 'FLURFUNK_URL' ) || ! defined( 'FLURFUNK_DIR' ) ) {
			return $args;
		}
		$user = null;
		if ( $id_or_email instanceof WP_User ) {
			$user = $id_or_email;
		} elseif ( is_numeric( $id_or_email ) ) {
			$user = get_userdata( (int) $id_or_email );
		} elseif ( is_string( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
		} elseif ( $id_or_email instanceof WP_Post || $id_or_email instanceof WP_Comment ) {
			$user = get_userdata( (int) ( $id_or_email->post_author ?? $id_or_email->user_id ) );
		}
		$file = $user ? basename( (string) get_user_meta( $user->ID, 'flurfunk_demo_avatar', true ) ) : '';
		if ( '' !== $file && is_file( FLURFUNK_DIR . 'tests/demo/avatars/' . $file ) ) {
			$args['url']          = FLURFUNK_URL . 'tests/demo/avatars/' . $file;
			$args['found_avatar'] = true;
		}
		return $args;
	},
	10,
	2
);

// "Editor-in-chief" instead of the WordPress role under the names.
add_filter(
	'flurfunk_user_role_label',
	static function ( string $label, WP_User $user ): string {
		$job = (string) get_user_meta( $user->ID, 'flurfunk_demo_role', true );
		return '' !== $job ? $job : $label;
	},
	10,
	2
);

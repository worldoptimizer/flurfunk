<?php
/**
 * AI assistants: limits. An assistant can never do more than the person who asks it, and never
 * more than its limit – "may at most act as" a role of the site, or (the default) simply as the
 * person asking. That holds for reading as much as for proposals.
 *
 * While an assistant works for someone, a map_meta_cap filter turns every check of that person
 * into "do_not_allow" if it needs a capability the limit's role lacks, or one that administers the
 * site (users, plugins, themes, options, unfiltered HTML …). It only ever takes away, never grants,
 * and changes no account or role: the assistant's own account stays as it is. Because WordPress
 * checks everything through current_user_can()/user_can() – abilities, map_meta_cap for single
 * posts, WP_Query for private posts and others' drafts – the limit applies to the abilities of
 * other plugins too. map_meta_cap (not user_has_cap) also covers super admins on a multisite.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Ai;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

// Never while an assistant works, whatever the person asking or the limit may: administering the
// site, people, code and unfiltered markup. Role names count too (current_user_can( 'administrator' )).
const BLOCKED = array(
	'administrator',
	'activate_plugins',
	'add_users',
	'create_sites',
	'create_users',
	'customize',
	'delete_plugins',
	'delete_site',
	'delete_sites',
	'delete_themes',
	'delete_users',
	'edit_dashboard',
	'edit_files',
	'edit_plugins',
	'edit_theme_options',
	'edit_themes',
	'edit_users',
	'erase_others_personal_data',
	'export',
	'export_others_personal_data',
	'import',
	'install_languages',
	'install_plugins',
	'install_themes',
	'list_users',
	'manage_network',
	'manage_network_options',
	'manage_network_plugins',
	'manage_network_themes',
	'manage_network_users',
	'manage_options',
	'manage_privacy_options',
	'manage_sites',
	'promote_users',
	'remove_users',
	'resume_plugins',
	'resume_themes',
	'setup_network',
	'switch_themes',
	'unfiltered_html',
	'unfiltered_upload',
	'update_core',
	'update_languages',
	'update_plugins',
	'update_themes',
	'upgrade_network',
	\Flurfunk\Caps\MANAGE,
);

// Roles with any of these administer the site; they are not offered as a limit.
const ADMIN_CAPS = array( 'manage_options', 'promote_users', 'edit_users', 'activate_plugins', 'install_plugins', 'edit_plugins', 'update_core', 'manage_network' );

add_filter( 'map_meta_cap', __NAMESPACE__ . '\\limit_meta_cap', PHP_INT_MAX, 3 );

/**
 * Roles an assistant can be limited to: slug => name, without roles that administer the site.
 *
 * @return array<string, string>
 */
function limit_roles(): array {
	$out = array();
	foreach ( wp_roles()->roles as $slug => $role ) {
		$caps = array_keys( array_filter( (array) ( $role['capabilities'] ?? array() ) ) );
		if ( ! array_intersect( ADMIN_CAPS, $caps ) ) {
			$out[ (string) $slug ] = translate_user_role( (string) $role['name'] );
		}
	}
	return $out;
}

/**
 * The name of an assistant's limit, for people ("the person asking" or a role).
 *
 * @param int $agent Assistant.
 */
function limit_label( int $agent ): string {
	$slug = profile( $agent )['limit'];
	return '' === $slug ? __( 'the person asking', 'flurfunk' ) : ( limit_roles()[ $slug ] ?? $slug );
}

/**
 * The capabilities an assistant's limit allows, or null for "as the person asking" (then only the
 * blocked ones are taken away). A limit naming a role that is gone or administers the site allows
 * nothing but reading.
 *
 * @param int $agent Assistant.
 * @return array<string, bool>|null
 */
function limit_caps( int $agent ): ?array {
	$slug = profile( $agent )['limit'];
	if ( '' === $slug ) {
		return null;
	}
	$role = isset( limit_roles()[ $slug ] ) ? get_role( $slug ) : null;
	return $role ? array_map( 'boolval', (array) $role->capabilities ) : array( 'read' => true );
}

/**
 * The limits in force: a stack of { user, agent, caps }, innermost last.
 *
 * @param array|null $push A limit to add.
 * @param bool       $pop  Remove the innermost.
 * @return array
 */
function limits( ?array $push = null, bool $pop = false ): array {
	static $stack = array();
	if ( $push ) {
		$stack[] = $push;
	} elseif ( $pop ) {
		array_pop( $stack );
	}
	return $stack;
}

/**
 * Run something for a person, limited by an assistant: what both the person and the assistant's
 * limit allow, without the blocked capabilities.
 *
 * @param int      $agent   Assistant.
 * @param int      $user_id The person it works for.
 * @param callable $fn      What to run.
 * @return mixed What $fn returns.
 */
function with_limit( int $agent, int $user_id, callable $fn ) {
	$caps = limit_caps( $agent );
	limits(
		array(
			'user'  => $user_id,
			'agent' => $agent,
			'caps'  => $caps,
		)
	);
	// Access worked out within this limit is kept apart from any other (also the same assistant's
	// with another limit later in the request).
	$scope = Access\scope();
	Access\scope( $scope . '|' . $user_id . ':' . md5( (string) wp_json_encode( $caps ) ) );
	// Markup a limited person saves goes through kses (unfiltered_html is blocked).
	if ( get_current_user_id() === $user_id ) {
		kses_init();
	}
	try {
		return $fn();
	} finally {
		limits( null, true );
		Access\scope( $scope );
		if ( get_current_user_id() === $user_id ) {
			kses_init();
		}
	}
}

/**
 * The filter: a check of a limited person that needs a capability their assistant's limit lacks
 * (or a blocked one) becomes "do_not_allow". Only ever takes away.
 *
 * @param string[] $caps    Capabilities the check needs.
 * @param string   $cap     The capability checked.
 * @param int      $user_id Whose.
 * @return string[]
 */
function limit_meta_cap( $caps, $cap, $user_id ) {
	$stack = limits();
	if ( ! $stack || ! is_array( $caps ) ) {
		return $caps;
	}
	// Flurfunk's own "uses Flurfunk" rights stay as they are: the assistant's channels limit those.
	$free = array( 'exist', \Flurfunk\Caps\TEAM, \Flurfunk\Caps\GUEST, CAP );
	foreach ( $stack as $limit ) {
		if ( (int) $limit['user'] !== (int) $user_id ) {
			continue;
		}
		foreach ( $caps as $c ) {
			if ( 'do_not_allow' === $c || in_array( $c, $free, true ) ) {
				continue;
			}
			if ( in_array( $c, BLOCKED, true ) || ( null !== $limit['caps'] && empty( $limit['caps'][ $c ] ) ) ) {
				return array( 'do_not_allow' );
			}
		}
	}
	return $caps;
}

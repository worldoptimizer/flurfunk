<?php
/**
 * AI assistants: limits. An assistant can never do more than the person who asks it, and never
 * more than its limit – "may at most act as" a role of the site, or (the default) simply as the
 * person asking – nor more than the channel's limit, if the channel sets one (as in Claude Tag,
 * a channel decides what its assistant may). That holds for reading as much as for proposals.
 * A channel can also set a monthly token budget shared by all assistants there.
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

const CHANNEL_RULES = 'flurfunk_ai_channel_rules'; // slug => { limit, budget }.
const CHANNEL_USAGE = 'flurfunk_ai_channel_usage'; // slug => { month, tokens, requests, warned }.

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
 * The roles that limit an assistant here: its own limit and the channel's (each '' = none).
 *
 * @param int    $agent   Assistant.
 * @param string $channel Channel slug ('' = none, e.g. a private conversation).
 * @return string[] Role slugs, at most two.
 */
function limit_slugs( int $agent, string $channel = '' ): array {
	return array_values( array_unique( array_filter( array( profile( $agent )['limit'], '' !== $channel ? channel_rule( $channel )['limit'] : '' ) ) ) );
}

/**
 * The name of an assistant's limit, for people ("the person asking", a role, or two roles).
 *
 * @param int    $agent   Assistant.
 * @param string $channel Channel slug.
 */
function limit_label( int $agent, string $channel = '' ): string {
	$names = array_map( static fn ( string $s ): string => limit_roles()[ $s ] ?? $s, limit_slugs( $agent, $channel ) );
	/* translators: 1, 2: role names */
	return $names ? ( 2 === count( $names ) ? sprintf( __( '%1$s and %2$s', 'flurfunk' ), $names[0], $names[1] ) : $names[0] ) : __( 'the person asking', 'flurfunk' );
}

/**
 * The capabilities a role allows as a limit (a role that is gone or administers the site: reading).
 *
 * @param string $slug Role.
 * @return array<string, bool>
 */
function role_caps( string $slug ): array {
	$role = isset( limit_roles()[ $slug ] ) ? get_role( $slug ) : null;
	return $role ? array_map( 'boolval', (array) $role->capabilities ) : array( 'read' => true );
}

/**
 * The capabilities an assistant's limits allow here – its own and the channel's, both – or null
 * for "as the person asking" (then only the blocked ones are taken away).
 *
 * @param int    $agent   Assistant.
 * @param string $channel Channel slug ('' = none).
 * @return array<string, bool>|null
 */
function limit_caps( int $agent, string $channel = '' ): ?array {
	$caps = null;
	foreach ( limit_slugs( $agent, $channel ) as $slug ) {
		$role = array_filter( role_caps( $slug ) );
		$caps = null === $caps ? $role : array_intersect_key( $caps, $role );
	}
	return $caps;
}

/**
 * What a channel sets for AI assistants: a limit (a role, '' = none), a monthly token budget
 * shared by all assistants there (0 = none), and whether they answer new threads unasked when the
 * quick check finds one is for them (off by default: every new thread there then goes to the AI
 * provider for the check).
 *
 * @param string $slug Channel.
 * @return array{limit:string, budget:int, respond:bool}
 */
function channel_rule( string $slug ): array {
	$r = ( (array) get_option( CHANNEL_RULES, array() ) )[ $slug ] ?? array();
	$l = (string) ( $r['limit'] ?? '' );
	return array(
		'limit'   => isset( limit_roles()[ $l ] ) ? $l : '',
		'budget'  => max( 0, (int) ( $r['budget'] ?? 0 ) ),
		'respond' => ! empty( $r['respond'] ),
	);
}

/**
 * Tokens the assistants used in a channel this month.
 *
 * @param string $slug Channel.
 * @return array{month:string, tokens:int, requests:int, warned:bool}
 */
function channel_usage( string $slug ): array {
	$u     = ( (array) get_option( CHANNEL_USAGE, array() ) )[ $slug ] ?? array();
	$month = wp_date( 'Y-m' );
	$u     = is_array( $u ) && ( $u['month'] ?? '' ) === $month ? $u : array();
	return array(
		'month'    => $month,
		'tokens'   => (int) ( $u['tokens'] ?? 0 ),
		'requests' => (int) ( $u['requests'] ?? 0 ),
		'warned'   => ! empty( $u['warned'] ),
	);
}

/**
 * Count a run in a channel.
 *
 * @param string $slug   Channel.
 * @param int    $tokens Tokens.
 * @param bool   $warned The budget notice was posted.
 */
function add_channel_usage( string $slug, int $tokens, bool $warned = false ): void {
	if ( '' === $slug ) {
		return;
	}
	$all          = (array) get_option( CHANNEL_USAGE, array() );
	$u            = channel_usage( $slug );
	$all[ $slug ] = array(
		'month'    => $u['month'],
		'tokens'   => $u['tokens'] + max( 0, $tokens ),
		'requests' => $u['requests'] + ( $tokens > 0 ? 1 : 0 ),
		'warned'   => $u['warned'] || $warned,
	);
	update_option( CHANNEL_USAGE, $all, false );
}

/**
 * Is a channel's budget used up this month?
 *
 * @param string $slug Channel.
 */
function channel_spent( string $slug ): bool {
	$b = '' !== $slug ? channel_rule( $slug )['budget'] : 0;
	return $b > 0 && channel_usage( $slug )['tokens'] >= $b;
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
 * @param string   $channel Channel slug, for the channel's limit ('' = none).
 * @return mixed What $fn returns.
 */
function with_limit( int $agent, int $user_id, callable $fn, string $channel = '' ) {
	$caps = limit_caps( $agent, $channel );
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
 * Reading what only administrators may read – WordPress's own read-only abilities such as the
 * environment, the site's details or its settings – for an administrator who asks: inside such an
 * ability the rights that administer the site count as the asker's, because it only reads. Never
 * for anything that changes something; the limits (role, channel) still apply.
 *
 * @param bool|null $set Switch on or off; null reads.
 */
function admin_read( ?bool $set = null ): bool {
	static $on = false;
	if ( null !== $set ) {
		$on = $set;
	}
	return $on;
}

/**
 * May this ability read with the asker's administrator rights (see admin_read())? WordPress's own
 * read-only abilities (core/…); the filter flurfunk_ai_admin_read decides for others.
 *
 * @param string $ability Ability name.
 */
function reads_as_admin( string $ability ): bool {
	$a  = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability ) : null;
	$ok = $a && str_starts_with( $ability, 'core/' ) && ! empty( $a->get_meta()['annotations']['readonly'] );
	return (bool) apply_filters( 'flurfunk_ai_admin_read', $ok, $ability );
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
			if ( ( in_array( $c, BLOCKED, true ) && ! admin_read() ) || ( null !== $limit['caps'] && empty( $limit['caps'][ $c ] ) ) ) {
				return array( 'do_not_allow' );
			}
		}
	}
	return $caps;
}

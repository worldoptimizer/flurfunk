<?php
/**
 * Who sees what.
 *
 * A person is "team" (capability flurfunk_team, or an assistant) or "guest" (flurfunk_guest,
 * e.g. guest authors) – or has no access. Each channel sets a level for both sides:
 *   all        every thread in the channel
 *   own-posts  only threads attached to one of their own posts
 *   none       nothing
 *
 * A channel can also require a capability (its `capability`, '' = none): whoever lacks it gets
 * 'none' there, whatever their level – so the channel, its threads, counts, notifications, search
 * results and mention suggestions are gone for them, and they cannot start threads in it. All of
 * that goes through level(), so nothing else needs to know. Assistants pass if the capability is
 * flurfunk_team or flurfunk_guest, if their account has it, or if flurfunk_bot_can says so (default:
 * no). Code that posts through flurfunk_as() / flurfunk_post() as an assistant always passes: that
 * is the site's own PHP acting, not an agent reading.
 *
 * Adjust with the filters flurfunk_user_kind and flurfunk_owned_post_ids (e.g. to count
 * co-authors from another plugin). The capabilities themselves live in inc/caps.php.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const LEVELS = array( 'all', 'own-posts', 'none' );

/**
 * Kind of person.
 *
 * @param int $user_id User (0 = current).
 * @return string 'team', 'guest' or ''.
 */
function kind( int $user_id = 0 ): string {
	static $cache = array();
	$user_id = $user_id ?: get_current_user_id();
	$key     = $user_id . ':' . kinds_changed(); // Someone set to team or guest within the request counts at once.
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$user = get_userdata( $user_id );
	$kind = '';
	if ( $user ) {
		$kind = is_bot( $user_id ) || user_can( $user, \Flurfunk\Caps\TEAM ) ? 'team' : ( user_can( $user, \Flurfunk\Caps\GUEST ) ? 'guest' : '' );
		$kind = (string) apply_filters( 'flurfunk_user_kind', $kind, $user );
	}
	return $cache[ $key ] = $kind;
}

/**
 * A counter that changes when someone is set to team, guest or no access (for the caches of kind()
 * and level()).
 *
 * @param bool $bump Count up.
 */
function kinds_changed( bool $bump = false ): int {
	static $n = 0;
	return $bump ? ++$n : $n;
}

/**
 * Assistant accounts (agents, scripts): a normal user with a mark, shown as "Assistant" in the
 * interface. They take part like the team but need no editing rights of their own.
 *
 * @param int $user_id User.
 */
function is_bot( int $user_id ): bool {
	return (bool) get_user_meta( $user_id, 'flurfunk_bot', true );
}

/**
 * Create (or mark) an assistant account: a subscriber without editing rights.
 *
 * @param string $name  Display name (empty: "Assistant", translated).
 * @param string $login Login name.
 * @return int|\WP_Error User ID.
 */
function create_bot( string $name = '', string $login = 'flurfunk-assistent' ) {
	$login = sanitize_user( $login, true ) ?: 'flurfunk-assistent';
	$user  = get_user_by( 'login', $login );
	$id    = $user ? $user->ID : wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 40 ),
			'user_email'   => $login . '@invalid.invalid',
			'display_name' => sanitize_text_field( $name ) ?: __( 'Assistant', 'flurfunk' ),
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_user_meta( $id, 'flurfunk_bot', 1 );
	\Flurfunk\Notify\save_prefs( (int) $id, array( 'mail' => 'none', 'badge' => false ) );
	if ( ! get_option( 'flurfunk_bot_default' ) ) {
		update_option( 'flurfunk_bot_default', (int) $id, false );
	}
	\Flurfunk\Store\bump();
	return (int) $id;
}

/**
 * May the person use Flurfunk at all?
 *
 * @param int $user_id User (0 = current).
 */
function can_use( int $user_id = 0 ): bool {
	return '' !== kind( $user_id );
}

/**
 * May the person manage settings, channels, permissions, modules and assistants?
 *
 * @param int $user_id User (0 = current).
 */
function can_manage( int $user_id = 0 ): bool {
	return user_can( $user_id ?: get_current_user_id(), \Flurfunk\Caps\MANAGE );
}

/**
 * May the person edit and delete other people's messages and threads?
 *
 * @param int $user_id User (0 = current).
 */
function can_moderate( int $user_id = 0 ): bool {
	return user_can( $user_id ?: get_current_user_id(), \Flurfunk\Caps\MODERATE );
}

/**
 * Access level of a person in a channel.
 *
 * @param object $channel Channel row.
 * @param int    $user_id User.
 */
function level( object $channel, int $user_id ): string {
	static $cache = array();
	// The channel's settings are part of the key, so a change within the request counts at once;
	// so is the scope (an assistant working for this person may see less).
	$key = implode( ':', array( $channel->id, $user_id, $channel->access_team, $channel->access_guests, (int) $channel->archived, required_cap( $channel ), (int) acting( $user_id ), scope(), kinds_changed() ) );
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$kind  = kind( $user_id );
	if ( \Flurfunk\Store\PRIVATE_CHANNEL === $channel->slug ) {
		return $cache[ $key ] = 'none'; // Only a container for private conversations, never a channel.
	}
	$level = '' === $kind || ! empty( $channel->archived ) ? 'none' : ( 'team' === $kind ? (string) $channel->access_team : (string) $channel->access_guests );
	if ( 'none' !== $level && ! passes( $channel, $user_id ) ) {
		$level = 'none';
	}
	// Runs after the capability check, so it has the last word. E.g. a private channel for a few
	// people: return 'none' for everyone else.
	$level = (string) apply_filters( 'flurfunk_channel_level', $level, $channel, $user_id, $kind );
	return $cache[ $key ] = in_array( $level, LEVELS, true ) ? $level : 'none';
}

/**
 * The capability scope code runs in ('' = normal). Code that narrows what people may – an AI
 * assistant working for someone – names its scope, so access worked out outside it is not reused
 * inside it (and the other way round).
 *
 * @param string|null $set New scope.
 */
function scope( ?string $set = null ): string {
	static $scope = '';
	if ( null !== $set ) {
		$scope = $set;
	}
	return $scope;
}

/**
 * The capability a channel requires on top of its levels ('' = none).
 *
 * @param object $channel Channel row.
 */
function required_cap( object $channel ): string {
	return sanitize_key( (string) ( $channel->capability ?? '' ) );
}

/**
 * Does the person have what the channel requires? flurfunk_team and flurfunk_guest are counted
 * the Flurfunk way (Caps\user_has(): assistants are team). For any other capability an assistant
 * passes only if its account has it, while PHP code acts as it (flurfunk_as()), or if the filter
 * flurfunk_bot_can allows it – by default it does not, so an agent with an application password
 * cannot read a gated channel just because it is an assistant.
 *
 * @param object $channel Channel row.
 * @param int    $user_id User.
 */
function passes( object $channel, int $user_id ): bool {
	$cap = required_cap( $channel );
	if ( '' === $cap ) {
		return true;
	}
	if ( \Flurfunk\Caps\TEAM === $cap || \Flurfunk\Caps\GUEST === $cap ) {
		return \Flurfunk\Caps\user_has( $user_id, $cap );
	}
	if ( user_can( $user_id, $cap ) ) {
		return true;
	}
	if ( ! is_bot( $user_id ) ) {
		return false;
	}
	return acting( $user_id ) || (bool) apply_filters( 'flurfunk_bot_can', false, $cap, $channel, $user_id );
}

/**
 * Assistants that PHP code is acting as right now (flurfunk_as()), innermost last.
 *
 * @return int[]
 */
function &acting_stack(): array {
	static $ids = array();
	return $ids;
}

/**
 * Is PHP code acting as this assistant right now (flurfunk_as(), flurfunk_post(), …)?
 *
 * @param int $user_id User.
 */
function acting( int $user_id ): bool {
	return in_array( $user_id, acting_stack(), true );
}

/**
 * Run code as an assistant from PHP: it passes channel capabilities while $fn runs.
 *
 * @param int      $user_id Assistant.
 * @param callable $fn      Work.
 * @return mixed What $fn returns.
 */
function as_server( int $user_id, callable $fn ) {
	$stack   = &acting_stack();
	$stack[] = $user_id;
	try {
		return $fn();
	} finally {
		array_pop( $stack );
	}
}

/**
 * Posts that belong to a person (for "own-posts").
 *
 * @param int $user_id User.
 * @return int[]
 */
function owned_post_ids( int $user_id ): array {
	static $cache = array();
	if ( ! isset( $cache[ $user_id ] ) ) {
		// get_posts() leaves out filters by default, so other plugins can't hide posts here.
		$ids = get_posts(
			array(
				'author'         => $user_id,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$cache[ $user_id ] = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'flurfunk_owned_post_ids', $ids, $user_id ) ) ) );
	}
	return $cache[ $user_id ];
}

/**
 * May the person see this thread?
 *
 * @param object $thread  Thread row.
 * @param int    $user_id User (0 = current).
 */
function can_see( object $thread, int $user_id = 0 ): bool {
	$user_id = $user_id ?: get_current_user_id();
	// A private conversation with an assistant: the person and the assistant, nobody else – not
	// moderators, not the site's administrators, whatever the channel settings say.
	if ( ! empty( $thread->private_to ) ) {
		return $user_id && ( (int) $thread->private_to === $user_id || ( 'assistant' === $thread->target_type && (int) $thread->target_id === $user_id ) );
	}
	$channel = \Flurfunk\Store\channel( (int) $thread->channel_id );
	if ( ! $channel ) {
		return false;
	}
	$level = level( $channel, $user_id );
	if ( 'all' === $level ) {
		return true;
	}
	return 'own-posts' === $level && 'post' === $thread->target_type && in_array( (int) $thread->target_id, owned_post_ids( $user_id ), true );
}

/**
 * May the person start a thread in this channel (with this target)?
 *
 * @param object $channel     Channel row.
 * @param string $target_type Target type ('' = none).
 * @param int    $target_id   Target ID.
 * @param int    $user_id     User (0 = current).
 */
function can_start( object $channel, string $target_type, int $target_id, int $user_id = 0 ): bool {
	$user_id = $user_id ?: get_current_user_id();
	$level   = level( $channel, $user_id );
	if ( 'all' === $level ) {
		return '' === $target_type || can_target( $target_type, $target_id, $user_id );
	}
	return 'own-posts' === $level && 'post' === $target_type && in_array( $target_id, owned_post_ids( $user_id ), true );
}

/**
 * May the person attach a thread to this target?
 *
 * @param string $type    Target type (see inc/targets.php).
 * @param int    $id      Target ID.
 * @param int    $user_id User.
 */
function can_target( string $type, int $id, int $user_id ): bool {
	return (bool) apply_filters( 'flurfunk_can_target', \Flurfunk\Targets\can( $type, $id, $user_id ), $type, $id, $user_id );
}

/**
 * SQL condition for the threads a person may see (table alias t).
 *
 * @param int $user_id User.
 */
function where( int $user_id ): string {
	global $wpdb;
	$all = array();
	$own = array();
	foreach ( \Flurfunk\Store\channels() as $c ) {
		$level = level( $c, $user_id );
		if ( 'all' === $level ) {
			$all[] = (int) $c->id;
		} elseif ( 'own-posts' === $level ) {
			$own[] = (int) $c->id;
		}
	}
	$parts = array();
	// Each piece is prepared on its own; callers put the result into their own prepared query.
	if ( $all ) {
		$parts[] = $wpdb->prepare( 't.channel_id IN (' . implode( ',', array_fill( 0, count( $all ), '%d' ) ) . ')', $all );
	}
	$posts = $own ? owned_post_ids( $user_id ) : array();
	if ( $posts ) {
		$parts[] = $wpdb->prepare( '(t.channel_id IN (' . implode( ',', array_fill( 0, count( $own ), '%d' ) ) . ") AND t.target_type = 'post' AND t.target_id IN (" . implode( ',', array_fill( 0, count( $posts ), '%d' ) ) . '))', array_merge( $own, $posts ) );
	}
	// Private conversations never show up in lists, numbers or searches; they have their own view.
	return $parts ? '(' . implode( ' OR ', $parts ) . ') AND t.private_to = 0' : '0=1';
}

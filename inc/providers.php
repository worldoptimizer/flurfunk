<?php
/**
 * Providers: the extension API for slash commands and cards (reference below).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Providers;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

/*
 * Flurfunk itself only knows text, mentions and files. Everything else comes from providers
 * that other plugins register:
 *
 *   flurfunk_register_provider( 'myplugin/report', array(
 *       'command'  => 'report',                       // typed as /report
 *       'label'    => 'Report',
 *       'hint'     => 'Insert a report card',
 *       'search'   => fn ( string $q, int $user_id ) => array(   // suggestions while typing
 *           array( 'ref' => array( 'id' => 12 ), 'title' => 'Q3', 'subtitle' => 'Draft' ),
 *       ),
 *       'snapshot' => fn ( array $ref, int $user_id ) => array(  // stored with the message;
 *           'title' => 'Q3', 'subtitle' => 'Draft', 'url' => '…', // null = not allowed
 *       ),
 *       'can'      => fn ( int $user_id ) => true,   // offer the command to this person?
 *       'capability' => 'flurfunk_team',             // needed to insert or search (default);
 *                                                    // 'flurfunk_guest': guests too
 *       'capabilities' => array(                     // optional, declared like in inc/caps.php
 *           'myplugin_reports' => array( 'label' => 'Insert reports', 'roles' => array( 'editor' ) ),
 *       ),
 *   ) );
 *
 * A message stores the reference plus a small snapshot, so it stays readable after the source
 * is gone or the data moves elsewhere. Snapshots are data only, never HTML: Flurfunk draws them
 * from a fixed set of building blocks, cleaned by clean_snapshot() with strict limits:
 *
 *   title, subtitle, text, url, image, audio
 *   metric    { value, label }                   big number
 *   spark     [ numbers ]                        small bar chart
 *   meta      [ strings ]                        small facts
 *   fields    [ { label, value, url? } ]         label/value pairs
 *   checklist [ { label, done } ]                ticked and open items
 *   progress  { value, max, label? }             progress bar
 *   status    { label, tone }                    pill: neutral|positive|warning|critical|info
 *   people    [ user IDs ]                       avatar stack with names
 *   table     { columns: [ … ], rows: [ [ … ] ] } plain text table
 *   badges    [ strings ]                        small labels
 *   quote     string                             quoted plain text
 *   target    { type, id }                       "open conversations about this" link
 *   action    { label, url }                     link button
 *   actions   [ action IDs ]                     which registered actions are available now
 *
 * Card actions are buttons that run PHP on the site (see run_action() and the REST route
 * cards/<id>/action/<action>):
 *
 *   'actions' => array(
 *       'approve' => array(
 *           'label'      => 'Approve',
 *           'done'       => '%s approved',              // event line in the thread (optional)
 *           'style'      => 'primary',                  // primary | secondary | danger
 *           'confirm'    => 'Really approve?',          // optional, asked inline
 *           'capability' => 'flurfunk_team',            // default
 *           'callback'   => fn ( array $ref, array $card, int $user_id ) => array|WP_Error,
 *       ),
 *   ),
 *
 * A plugin that needs a special look registers a renderer in JavaScript:
 *   window.Flurfunk.registerCard( 'myplugin/report', ( snapshot, h ) => html ), built with the helpers in h.
 */

/**
 * Registry.
 *
 * @return array<string, array>
 */
function &registry(): array {
	static $providers = array();
	return $providers;
}

/**
 * Register a provider.
 *
 * @param string $id   Identifier ("vendor/name").
 * @param array  $args See file header.
 */
function register( string $id, array $args ): void {
	$command = sanitize_key( (string) ( $args['command'] ?? '' ) );
	if ( '' === $command || ! is_callable( $args['snapshot'] ?? null ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'A Flurfunk provider needs a command and a snapshot callback.', 'flurfunk' ), '0.1.0' );
		return;
	}
	$actions = array();
	foreach ( (array) ( $args['actions'] ?? array() ) as $key => $a ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key || ! is_array( $a ) || ! is_callable( $a['callback'] ?? null ) || count( $actions ) >= MAX_ACTIONS ) {
			continue;
		}
		$actions[ $key ] = array(
			'label'      => plain( $a['label'] ?? $key, 60 ) ?? $key,
			'done'       => plain( $a['done'] ?? '', 120 ) ?? '',
			'style'      => in_array( $a['style'] ?? '', array( 'primary', 'secondary', 'danger' ), true ) ? $a['style'] : 'secondary',
			'confirm'    => plain( $a['confirm'] ?? '', 300 ) ?? '',
			'capability' => sanitize_key( (string) ( $a['capability'] ?? \Flurfunk\Caps\TEAM ) ),
			'callback'   => $a['callback'],
		);
	}
	$registry        = &registry();
	$registry[ $id ] = array(
		'id'       => $id,
		'command'  => $command,
		'label'    => (string) ( $args['label'] ?? $command ),
		'hint'     => (string) ( $args['hint'] ?? '' ),
		'search'   => is_callable( $args['search'] ?? null ) ? $args['search'] : null,
		'snapshot' => $args['snapshot'],
		'can'      => is_callable( $args['can'] ?? null ) ? $args['can'] : null,
		'actions'  => $actions,
		// Hidden providers make cards (e.g. for threads posted from PHP) but are not in the / menu.
		'hidden'   => ! empty( $args['hidden'] ),
		// Capability needed to insert, search or refresh the card (see inc/caps.php).
		'cap'      => sanitize_key( (string) ( $args['capability'] ?? \Flurfunk\Caps\TEAM ) ),
	);
	\Flurfunk\Caps\register_list( $args['capabilities'] ?? array(), $registry[ $id ]['label'] );
}

/**
 * May the person use this provider (its capability, then its `can` callback)?
 *
 * @param array $p       Provider.
 * @param int   $user_id User.
 */
function allowed( array $p, int $user_id ): bool {
	return \Flurfunk\Caps\user_has( $user_id, $p['cap'] ) && ( ! $p['can'] || call_user_func( $p['can'], $user_id ) );
}

/**
 * Providers a person may use (for the interface), with the card actions they may run.
 *
 * @param int $user_id User.
 * @return array[] id, command, label, hint, hidden, actions { id: { label, done, style, confirm, can } }.
 */
function available( int $user_id ): array {
	$out = array();
	foreach ( registry() as $p ) {
		if ( allowed( $p, $user_id ) ) {
			$actions = array();
			foreach ( $p['actions'] as $key => $a ) {
				// All of them, so event lines can be worded; buttons only where "can" is true.
				$actions[ $key ] = array_intersect_key( $a, array_flip( array( 'label', 'done', 'style', 'confirm' ) ) ) + array( 'can' => \Flurfunk\Caps\user_has( $user_id, $a['capability'] ) );
			}
			$out[] = array(
				'id'      => $p['id'],
				'command' => $p['command'],
				'label'   => $p['label'],
				'hint'    => $p['hint'],
				'hidden'  => $p['hidden'],
				'actions' => (object) $actions,
			);
		}
	}
	return $out;
}

/**
 * Suggestions while typing.
 *
 * @param string $id      Provider.
 * @param string $query   Search text.
 * @param int    $user_id User.
 * @return array[]|null Null if the provider is unknown or not allowed.
 */
function search( string $id, string $query, int $user_id ): ?array {
	$p = registry()[ $id ] ?? null;
	if ( ! $p || ! allowed( $p, $user_id ) ) {
		return null;
	}
	if ( ! $p['search'] ) {
		return array();
	}
	$items = array();
	foreach ( (array) call_user_func( $p['search'], $query, $user_id ) as $item ) {
		if ( is_array( $item['ref'] ?? null ) ) {
			$items[] = array(
				'ref'      => $item['ref'],
				'title'    => plain( $item['title'] ?? '', 200 ) ?? '',
				'subtitle' => plain( $item['subtitle'] ?? '', 200 ) ?? '',
			);
		}
	}
	return array_slice( $items, 0, 20 );
}

/**
 * Snapshot for a reference (checks permissions via the provider).
 *
 * @param string $id      Provider.
 * @param array  $ref     Reference.
 * @param int    $user_id User.
 */
function snapshot( string $id, array $ref, int $user_id ): ?array {
	$p = registry()[ $id ] ?? null;
	if ( ! $p || ! allowed( $p, $user_id ) ) {
		return null;
	}
	$snap = call_user_func( $p['snapshot'], $ref, $user_id );
	return is_array( $snap ) ? clean_snapshot( $snap, $id ) : null;
}

/*
 * Limits of the building blocks. Anything beyond them is cut off, not rejected.
 */
const MAX_ACTIONS   = 6;   // Registered and offered actions per card.
const MAX_FIELDS    = 12;  // fields.
const MAX_CHECKLIST = 20;  // checklist items.
const MAX_PEOPLE    = 12;  // people.
const MAX_COLUMNS   = 6;   // table columns.
const MAX_ROWS      = 20;  // table rows.
const MAX_BADGES    = 8;   // badges.
const MAX_DATA      = 16384; // Bytes of JSON under "data".
const TONES         = array( 'neutral', 'positive', 'warning', 'critical', 'info' );

/**
 * A plain, single-line or multi-line string from untrusted input: tags removed, control
 * characters dropped, cut to a length. Arrays and objects are not strings (null).
 *
 * @param mixed $v   Value.
 * @param int   $max Characters.
 * @param bool  $multiline Keep line breaks.
 */
function plain( $v, int $max, bool $multiline = false ): ?string {
	if ( ! is_scalar( $v ) ) {
		return null;
	}
	if ( is_bool( $v ) ) {
		$v = $v ? '1' : '';
	}
	$v = wp_strip_all_tags( wp_check_invalid_utf8( (string) $v, true ), ! $multiline );
	$v = (string) preg_replace( $multiline ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $v );
	$v = $multiline ? trim( (string) preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $v ) ) ) : trim( $v );
	return mb_substr( $v, 0, $max );
}

/**
 * A web address (http/https, for links also mailto) or ''.
 *
 * @param mixed $v    Value.
 * @param bool  $link A link (may be mailto:), not a media source.
 */
function web_url( $v, bool $link = false ): string {
	return is_string( $v ) && '' !== $v ? esc_url_raw( $v, $link ? array( 'http', 'https', 'mailto' ) : array( 'http', 'https' ) ) : '';
}

/**
 * A finite number or null.
 *
 * @param mixed $v Value.
 */
function number( $v ): ?float {
	return is_numeric( $v ) && is_finite( (float) $v ) ? (float) $v : null;
}

/**
 * A list (array with any keys) cut to $max entries; anything else becomes an empty list.
 *
 * @param mixed $v   Value.
 * @param int   $max Entries.
 */
function items( $v, int $max ): array {
	return is_array( $v ) ? array_slice( array_values( $v ), 0, $max ) : array();
}

/**
 * Keep only the card building blocks, as plain values within their limits. Unknown keys,
 * wrong types and empty blocks are dropped.
 *
 * @param array  $s        Snapshot from a provider.
 * @param string $provider Provider ID (to keep only actions it registered).
 */
function clean_snapshot( array $s, string $provider = '' ): array {
	$out = array();
	foreach ( array( 'title' => 300, 'subtitle' => 300 ) as $k => $max ) {
		$v = plain( $s[ $k ] ?? null, $max );
		if ( null !== $v && '' !== $v ) {
			$out[ $k ] = $v;
		}
	}
	foreach ( array( 'text' => 2000, 'quote' => 2000 ) as $k => $max ) {
		$v = plain( $s[ $k ] ?? null, $max, true );
		if ( null !== $v && '' !== $v ) {
			$out[ $k ] = $v;
		}
	}
	foreach ( array( 'url', 'image', 'audio' ) as $k ) {
		$v = web_url( $s[ $k ] ?? '', 'url' === $k );
		if ( '' !== $v ) {
			$out[ $k ] = $v;
		}
	}
	if ( is_array( $s['metric'] ?? null ) && null !== plain( $s['metric']['value'] ?? null, 40 ) ) {
		$out['metric'] = array(
			'value' => plain( $s['metric']['value'], 40 ),
			'label' => plain( $s['metric']['label'] ?? '', 80 ) ?? '',
		);
	}
	$spark = array_values( array_filter( array_map( __NAMESPACE__ . '\\number', items( $s['spark'] ?? null, 60 ) ), static fn ( $n ) => null !== $n ) );
	if ( $spark ) {
		$out['spark'] = $spark;
	}
	$meta = array_values( array_filter( array_map( static fn ( $m ) => plain( $m, 120 ), items( $s['meta'] ?? null, 6 ) ), 'strlen' ) );
	if ( $meta ) {
		$out['meta'] = $meta;
	}
	$fields = array();
	foreach ( items( $s['fields'] ?? null, 10 * MAX_FIELDS ) as $f ) {
		if ( count( $fields ) >= MAX_FIELDS ) {
			break;
		}
		$label = is_array( $f ) ? plain( $f['label'] ?? '', 60 ) : null;
		$value = is_array( $f ) ? plain( $f['value'] ?? '', 200 ) : null;
		if ( null === $label || null === $value || ( '' === $label && '' === $value ) ) {
			continue;
		}
		$field = array(
			'label' => $label,
			'value' => $value,
		);
		$url   = web_url( $f['url'] ?? '', true );
		if ( '' !== $url ) {
			$field['url'] = $url;
		}
		$fields[] = $field;
	}
	if ( $fields ) {
		$out['fields'] = $fields;
	}
	$list = array();
	foreach ( items( $s['checklist'] ?? null, 10 * MAX_CHECKLIST ) as $c ) {
		if ( count( $list ) >= MAX_CHECKLIST ) {
			break;
		}
		$label = is_array( $c ) ? plain( $c['label'] ?? '', 200 ) : null;
		if ( null !== $label && '' !== $label ) {
			$list[] = array(
				'label' => $label,
				'done'  => ! empty( $c['done'] ) && true === filter_var( $c['done'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
			);
		}
	}
	if ( $list ) {
		$out['checklist'] = $list;
	}
	if ( is_array( $s['progress'] ?? null ) && null !== number( $s['progress']['value'] ?? null ) ) {
		$max             = number( $s['progress']['max'] ?? 100 ) ?? 100.0;
		$max             = $max > 0 ? $max : 100.0;
		$out['progress'] = array(
			'value' => max( 0.0, min( $max, number( $s['progress']['value'] ) ) ),
			'max'   => $max,
			'label' => plain( $s['progress']['label'] ?? '', 80 ) ?? '',
		);
	}
	if ( is_array( $s['status'] ?? null ) ) {
		$label = plain( $s['status']['label'] ?? '', 60 );
		if ( null !== $label && '' !== $label ) {
			$out['status'] = array(
				'label' => $label,
				'tone'  => in_array( $s['status']['tone'] ?? '', TONES, true ) ? $s['status']['tone'] : 'neutral',
			);
		}
	}
	$people = array_values( array_unique( array_filter( array_map( static fn ( $u ) => is_numeric( $u ) ? max( 0, (int) $u ) : 0, items( $s['people'] ?? null, MAX_PEOPLE ) ) ) ) );
	if ( $people ) {
		$out['people'] = $people;
	}
	if ( is_array( $s['table'] ?? null ) ) {
		$cols = array_map( static fn ( $c ) => plain( $c, 60 ) ?? '', items( $s['table']['columns'] ?? null, MAX_COLUMNS ) );
		$rows = array();
		foreach ( items( $s['table']['rows'] ?? null, 10 * MAX_ROWS ) as $r ) {
			if ( is_array( $r ) && count( $rows ) < MAX_ROWS ) {
				$rows[] = array_map( static fn ( $c ) => plain( $c, 200 ) ?? '', items( $r, MAX_COLUMNS ) );
			}
		}
		$width = max( count( $cols ), $rows ? max( array_map( 'count', $rows ) ) : 0 );
		if ( $rows && $width ) {
			$out['table'] = array(
				'columns' => $cols ? array_pad( $cols, $width, '' ) : array(),
				'rows'    => array_map( static fn ( $r ) => array_pad( $r, $width, '' ), $rows ),
			);
		}
	}
	$badges = array_values( array_filter( array_map( static fn ( $b ) => plain( $b, 40 ), items( $s['badges'] ?? null, MAX_BADGES ) ), 'strlen' ) );
	if ( $badges ) {
		$out['badges'] = $badges;
	}
	if ( is_array( $s['target'] ?? null ) && isset( $s['target']['type'], $s['target']['id'] ) && is_scalar( $s['target']['type'] ) && is_numeric( $s['target']['id'] ) ) {
		$out['target'] = array(
			'type' => sanitize_key( (string) $s['target']['type'] ),
			'id'   => (int) $s['target']['id'],
		);
	}
	if ( is_array( $s['action'] ?? null ) && '' !== web_url( $s['action']['url'] ?? '', true ) ) {
		$out['action'] = array(
			'label' => plain( $s['action']['label'] ?? '', 60 ) ?? '',
			'url'   => web_url( $s['action']['url'], true ),
		);
	}
	if ( is_array( $s['actions'] ?? null ) ) {
		$known   = '' !== $provider ? array_keys( registry()[ $provider ]['actions'] ?? array() ) : null;
		$actions = array();
		foreach ( $s['actions'] as $a ) {
			$a = is_string( $a ) ? sanitize_key( $a ) : '';
			if ( '' !== $a && ( null === $known || in_array( $a, $known, true ) ) && ! in_array( $a, $actions, true ) ) {
				$actions[] = $a;
			}
		}
		$out['actions'] = array_slice( $actions, 0, MAX_ACTIONS );
	}
	// Providers may keep extra data for their own renderer under "data" (plain JSON values).
	if ( isset( $s['data'] ) && is_array( $s['data'] ) ) {
		$json = (string) wp_json_encode( $s['data'] );
		if ( strlen( $json ) <= MAX_DATA ) {
			$out['data'] = json_decode( $json, true );
		}
	}
	return $out;
}

/**
 * Run a card action for a person: checks the provider, the action, whether the card offers it
 * right now and the action's capability. The thread itself is checked by the caller.
 *
 * @param string $provider Provider ID.
 * @param string $action   Action ID.
 * @param array  $ref      The card's reference.
 * @param array  $card     The card's stored snapshot.
 * @param int    $user_id  User.
 * @return array|\WP_Error The new snapshot (cleaned).
 */
function run_action( string $provider, string $action, array $ref, array $card, int $user_id ) {
	$p = registry()[ $provider ] ?? null;
	$a = $p['actions'][ $action ] ?? null;
	if ( ! $p || ! $a ) {
		return new \WP_Error( 'flurfunk_action', __( 'Unknown action.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( ! allowed( $p, $user_id ) || ! \Flurfunk\Caps\user_has( $user_id, $a['capability'] ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot do this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( ! in_array( $action, (array) ( $card['actions'] ?? array() ), true ) ) {
		return new \WP_Error( 'flurfunk_action_gone', __( 'This is no longer possible. The card shows the current state.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	try {
		$result = call_user_func( $a['callback'], $ref, $card, $user_id );
	} catch ( \Throwable $e ) {
		return new \WP_Error( 'flurfunk_action_failed', __( 'That did not work.', 'flurfunk' ), array( 'status' => 500 ) );
	}
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return new \WP_Error(
			'flurfunk_action_failed',
			plain( $result->get_error_message(), 300 ) ?: __( 'That did not work.', 'flurfunk' ),
			array( 'status' => is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] >= 400 && (int) $data['status'] < 500 ? (int) $data['status'] : 400 )
		);
	}
	if ( ! is_array( $result ) ) {
		// Nothing returned: ask the provider for the current state.
		$result = call_user_func( $p['snapshot'], $ref, $user_id );
	}
	return is_array( $result ) ? clean_snapshot( $result, $provider ) : $card;
}

/**
 * An action's definition (without the callback).
 *
 * @param string $provider Provider ID.
 * @param string $action   Action ID.
 */
function action_info( string $provider, string $action ): ?array {
	$a = registry()[ $provider ]['actions'][ $action ] ?? null;
	return $a ? array_intersect_key( $a, array_flip( array( 'label', 'done', 'style', 'confirm', 'capability' ) ) ) : null;
}

/**
 * Short key of a reference for lookups ("where was post 12 referenced?").
 *
 * @param array $ref Reference.
 */
function ref_key( array $ref ): string {
	if ( isset( $ref['id'] ) && is_scalar( $ref['id'] ) ) {
		return substr( (string) $ref['id'], 0, 191 );
	}
	return substr( md5( (string) wp_json_encode( $ref ) ), 0, 32 );
}

/*
 * Built-in provider: posts (any type the person may edit). Plugins can register more, or
 * unregister this one with remove_action( 'init', 'Flurfunk\Providers\register_builtin', 20 ).
 */
add_action( 'init', __NAMESPACE__ . '\\register_builtin', 20 );

/**
 * Register the built-in post provider.
 */
function register_builtin(): void {
	register(
		'flurfunk/post',
		array(
			'command'    => _x( 'post', 'slash command', 'flurfunk' ),
			'label'      => __( 'Post', 'flurfunk' ),
			'hint'       => __( 'Insert a post as a card', 'flurfunk' ),
			'search'     => __NAMESPACE__ . '\\search_posts',
			'snapshot'   => __NAMESPACE__ . '\\post_snapshot',
			'capability' => \Flurfunk\Caps\GUEST, // Guests get their own posts.
		)
	);
}

/**
 * Posts a person may reference.
 *
 * @param string $q       Search text.
 * @param int    $user_id User.
 */
function search_posts( string $q, int $user_id ): array {
	$args = array(
		'post_type'      => \Flurfunk\Settings\get()['post_types'],
		'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'posts_per_page' => 12,
		'orderby'        => 'modified',
		's'              => $q,
	);
	if ( 'team' !== Access\kind( $user_id ) ) {
		$args['post__in'] = Access\owned_post_ids( $user_id ) ?: array( 0 );
	}
	return array_map(
		static fn ( \WP_Post $p ) => array(
			'ref'      => array( 'id' => $p->ID ),
			'title'    => get_the_title( $p ) ?: __( '(no title)', 'flurfunk' ),
			'subtitle' => get_post_status_object( $p->post_status )->label ?? $p->post_status,
		),
		array_values( array_filter( get_posts( $args ), static fn ( \WP_Post $p ): bool => visible_post( $p, $user_id ) ) )
	);
}

/**
 * May this person see that a post exists (title, status)? Published ones yes; drafts, scheduled
 * and private posts only for those who may edit them.
 *
 * @param \WP_Post $p       Post.
 * @param int      $user_id User.
 */
function visible_post( \WP_Post $p, int $user_id ): bool {
	return 'publish' === $p->post_status || user_can( $user_id, 'edit_post', $p->ID );
}

/**
 * Card for a post.
 *
 * @param array $ref     { id }.
 * @param int   $user_id User.
 */
function post_snapshot( array $ref, int $user_id ): ?array {
	$p = get_post( (int) ( $ref['id'] ?? 0 ) );
	if ( ! $p || ! Access\can_target( 'post', $p->ID, $user_id ) || ! visible_post( $p, $user_id ) ) {
		return null;
	}
	$author = get_userdata( (int) $p->post_author );
	return array(
		'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		'subtitle' => implode( ' · ', array_filter( array( get_post_status_object( $p->post_status )->label ?? '', $author ? $author->display_name : '' ) ) ),
		'image'    => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
		'url'      => (string) get_edit_post_link( $p->ID, 'raw' ),
		'target'   => array(
			'type' => 'post',
			'id'   => $p->ID,
		),
	);
}

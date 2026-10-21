<?php
/**
 * Patterns: the site's own (the "My patterns" of the block editor – synced ones show the same
 * everywhere they are used, unsynced ones are a starting point copied into a page) and those the
 * theme and WordPress register. Read by people who may write posts; saved by them – an AI assistant
 * only proposes it, as a card the person applies and can undo.
 *
 * What an assistant writes is filtered (no scripts), whoever applies it.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Patterns;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const NAME = 'flurfunk/save-pattern';

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * Is it synced (the same everywhere it is used)?
 *
 * @param int $id Pattern.
 */
function synced( int $id ): bool {
	return 'unsynced' !== get_post_meta( $id, 'wp_pattern_sync_status', true );
}

/**
 * A pattern of the site's own (wp_block).
 *
 * @param int $id Pattern.
 */
function own( int $id ): ?\WP_Post {
	$p = get_post( $id );
	return $p && 'wp_block' === $p->post_type && 'trash' !== $p->post_status ? $p : null;
}

/**
 * The patterns, or the markup of one.
 *
 * @param array $input { id, name, search }.
 * @return array|\WP_Error
 */
function get( array $input ) {
	if ( ! empty( $input['id'] ) ) {
		$p = own( (int) $input['id'] );
		if ( ! $p || ! current_user_can( 'read_post', $p->ID ) ) {
			return new \WP_Error( 'flurfunk_patterns', __( 'This pattern does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
		}
		return array(
			'id'      => $p->ID,
			'title'   => $p->post_title,
			'synced'  => synced( $p->ID ),
			'content' => mb_substr( (string) $p->post_content, 0, 30000 ),
		);
	}
	$registry = class_exists( 'WP_Block_Patterns_Registry' ) ? \WP_Block_Patterns_Registry::get_instance() : null;
	if ( ! empty( $input['name'] ) ) {
		$r = $registry ? $registry->get_registered( (string) $input['name'] ) : null;
		if ( ! $r ) {
			return new \WP_Error( 'flurfunk_patterns', __( 'This pattern does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
		}
		return array(
			'name'    => (string) $r['name'],
			'title'   => (string) ( $r['title'] ?? '' ),
			'content' => mb_substr( (string) ( $r['content'] ?? '' ), 0, 30000 ),
		);
	}
	$q    = mb_strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
	$hit  = static fn ( string $text ) => '' === $q || str_contains( mb_strtolower( $text ), $q );
	$mine = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'wp_block',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		)
	) as $p ) {
		$cats = wp_get_object_terms( $p->ID, 'wp_pattern_category', array( 'fields' => 'names' ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;
		if ( $hit( $p->post_title . ' ' . implode( ' ', $cats ) ) ) {
			$mine[] = array_filter(
				array(
					'id'         => $p->ID,
					'title'      => $p->post_title,
					'synced'     => synced( $p->ID ),
					'categories' => $cats,
				),
				static fn ( $v ) => array() !== $v
			);
		}
	}
	$theirs = array();
	foreach ( $registry ? $registry->get_all_registered() : array() as $r ) {
		if ( false === ( $r['inserter'] ?? true ) || ! $hit( ( $r['title'] ?? '' ) . ' ' . implode( ' ', (array) ( $r['categories'] ?? array() ) ) ) ) {
			continue;
		}
		$theirs[] = array(
			'name'       => (string) $r['name'],
			'title'      => (string) ( $r['title'] ?? '' ),
			'categories' => array_values( (array) ( $r['categories'] ?? array() ) ),
		);
		if ( count( $theirs ) >= 80 ) {
			break;
		}
	}
	return array(
		'own'        => $mine,
		'registered' => $theirs,
	);
}

/**
 * The markup a change gives: new markup, or an exact replacement in the pattern's – filtered.
 *
 * @param array         $input { content, find, replace }.
 * @param \WP_Post|null $p     The pattern changed (null for a new one).
 * @return string|null|\WP_Error Null: the markup stays.
 */
function markup( array $input, ?\WP_Post $p ) {
	if ( isset( $input['content'] ) && '' !== trim( (string) $input['content'] ) ) {
		return trim( wp_kses_post( (string) $input['content'] ) );
	}
	if ( ! $p ) {
		return new \WP_Error( 'flurfunk_patterns', __( 'A new pattern needs a title and its block markup.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$find = (string) ( $input['find'] ?? '' );
	if ( '' === $find ) {
		return null;
	}
	if ( ! str_contains( (string) $p->post_content, $find ) ) {
		return new \WP_Error( 'flurfunk_patterns', __( 'The text to replace is not in this pattern (exactly as written).', 'flurfunk' ), array( 'status' => 400 ) );
	}
	return str_replace( $find, wp_kses_post( (string) ( $input['replace'] ?? '' ) ), (string) $p->post_content );
}

/**
 * Check a proposed pattern before it becomes a card.
 *
 * @param array $input Ability input.
 * @return true|\WP_Error
 */
function validate( array $input ) {
	$p = empty( $input['id'] ) ? null : own( (int) $input['id'] );
	if ( ! empty( $input['id'] ) && ! $p ) {
		return new \WP_Error( 'flurfunk_patterns', __( 'This pattern does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( ! $p && '' === trim( (string) ( $input['title'] ?? '' ) ) ) {
		return new \WP_Error( 'flurfunk_patterns', __( 'A new pattern needs a title and its block markup.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$new = markup( $input, $p );
	if ( is_wp_error( $new ) ) {
		return $new;
	}
	if ( $p && null === $new && ! isset( $input['title'] ) ) {
		return new \WP_Error( 'flurfunk_patterns', __( 'Nothing to change.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Save a pattern as the current person: a new one, or a change to one of the site's own.
 *
 * @param array $input { id, title, content, find, replace, synced, categories, why }.
 * @return array|\WP_Error What was saved, and what undo needs.
 */
function save( array $input ) {
	$ok = validate( $input );
	if ( true !== $ok ) {
		return $ok;
	}
	$p   = empty( $input['id'] ) ? null : own( (int) $input['id'] );
	$new = markup( $input, $p );
	if ( $p ) {
		$data = array( 'ID' => $p->ID );
		if ( null !== $new ) {
			$data['post_content'] = $new;
		}
		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$data['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		$done = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$after = get_post( $p->ID );
		return array(
			'id'     => $p->ID,
			'title'  => $after->post_title,
			'synced' => synced( $p->ID ),
			'undo'   => array(
				'id'     => $p->ID,
				'made'   => false,
				'before' => array( $p->post_title, $p->post_content ),
				'after'  => md5( $after->post_title . $after->post_content ),
			),
		);
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( (string) $input['title'] ),
				'post_content' => (string) $new,
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( empty( $input['synced'] ) ) {
		update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
	}
	$cats = array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $input['categories'] ?? array() ) ) ) );
	if ( $cats ) {
		wp_set_object_terms( $id, array_slice( $cats, 0, 5 ), 'wp_pattern_category' );
	}
	$after = get_post( $id );
	return array(
		'id'     => $id,
		'title'  => $after->post_title,
		'synced' => synced( $id ),
		'undo'   => array(
			'id'    => $id,
			'made'  => true,
			'after' => md5( $after->post_title . $after->post_content ),
		),
	);
}

/**
 * Put a pattern back as before the card (or remove one it made) – unless it was changed since.
 *
 * @param array $undo What save() returned under "undo".
 * @return true|\WP_Error
 */
function undo( array $undo ) {
	$p = own( (int) ( $undo['id'] ?? 0 ) );
	if ( ! $p ) {
		return true; // Gone already.
	}
	if ( ! current_user_can( 'edit_post', $p->ID ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot do this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( md5( $p->post_title . $p->post_content ) !== (string) ( $undo['after'] ?? '' ) ) {
		return new \WP_Error( 'flurfunk_patterns', __( 'The pattern was changed again since; nothing was undone.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	if ( ! empty( $undo['made'] ) ) {
		return wp_delete_post( $p->ID, true ) ? true : new \WP_Error( 'flurfunk_patterns', __( 'The pattern could not be removed.', 'flurfunk' ) );
	}
	$done = wp_update_post(
		wp_slash(
			array(
				'ID'           => $p->ID,
				'post_title'   => (string) ( $undo['before'][0] ?? $p->post_title ),
				'post_content' => (string) ( $undo['before'][1] ?? $p->post_content ),
			)
		),
		true
	);
	return is_wp_error( $done ) ? $done : true;
}

/**
 * May the current person read the patterns?
 */
function may_read(): bool {
	return is_user_logged_in() && Access\can_use() && current_user_can( 'edit_posts' );
}

/**
 * May the current person save this pattern (a new one, or change that one)?
 *
 * @param array|null $input Ability input.
 */
function may( $input = null ): bool {
	$input = (array) $input;
	if ( ! may_read() ) {
		return false;
	}
	$type = get_post_type_object( 'wp_block' );
	return empty( $input['id'] ) ? ( $type && current_user_can( $type->cap->create_posts ) ) : current_user_can( 'edit_post', (int) $input['id'] );
}

/**
 * Register the abilities.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability(
		'flurfunk/get-patterns',
		array(
			'label'               => __( 'Read the patterns', 'flurfunk' ),
			'description'         => __( 'Without id or name: the site\'s own patterns (id, title, whether synced, categories) and those the theme and WordPress register (name, title, categories), optionally matching search words. With id (own) or name (registered): its block markup – a starting point for a page or a template.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'name'   => array(
						'type'        => 'string',
						'description' => __( 'A registered pattern, e.g. "twentytwentyfive/banner-intro".', 'flurfunk' ),
					),
					'search' => array(
						'type' => 'string',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => get( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_read',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'      => false,
			),
		)
	);
	wp_register_ability(
		NAME,
		array(
			'label'               => __( 'Save a pattern', 'flurfunk' ),
			'description'         => __( 'Saves a pattern of the site\'s own: a new one (title, content, synced: the same everywhere it is used – else a starting point copied into pages, categories), or a change to one (id; an exact replacement find/replace, or new content; title). A change to a synced pattern shows wherever it is used. Can be undone. Scripts are filtered out.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'title'      => array(
						'type'      => 'string',
						'maxLength' => 100,
						'title'     => __( 'Title', 'flurfunk' ),
					),
					'content'    => array(
						'type'  => 'string',
						'title' => __( 'Block markup', 'flurfunk' ),
					),
					'find'       => array(
						'type'  => 'string',
						'title' => __( 'Find', 'flurfunk' ),
					),
					'replace'    => array(
						'type'  => 'string',
						'title' => __( 'Replace with', 'flurfunk' ),
					),
					'synced'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'categories' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'maxItems' => 5,
					),
					'why'        => array(
						'type'      => 'string',
						'maxLength' => 300,
						'title'     => __( 'Why', 'flurfunk' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => save( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'public'      => false,
			),
		)
	);
}

<?php
/**
 * The templates of a block theme – the page templates, the parts like header and footer, and the
 * navigation menus: read, and changed by a person who may edit the theme's options (an AI assistant
 * only proposes it, as a card the person applies and can undo).
 *
 * A change saves the site's own copy of the template, as the Site Editor does; "undo" puts back the
 * state before – the theme's original again, or the copy before – unless it was changed since. What
 * an assistant writes is always filtered (no scripts), whoever applies it.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Templates;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const NAME = 'flurfunk/update-template';
const ADD  = 'flurfunk/add-template';

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

const TYPES = array( 'wp_template_part', 'wp_template', 'wp_navigation' );

/**
 * The type asked for (a template part if none).
 *
 * @param mixed $type Input.
 */
function type( $type ): string {
	return in_array( $type, TYPES, true ) ? (string) $type : 'wp_template_part';
}

/**
 * REST base of a template type.
 *
 * @param string $type wp_template, wp_template_part or wp_navigation.
 */
function route( string $type ): string {
	return array(
		'wp_template'      => '/wp/v2/templates/',
		'wp_template_part' => '/wp/v2/template-parts/',
		'wp_navigation'    => '/wp/v2/navigation/',
	)[ type( $type ) ];
}

/**
 * One template – or a navigation menu, in the same shape.
 *
 * @param string $id   "theme//slug", or the menu's post ID.
 * @param string $type wp_template, wp_template_part or wp_navigation.
 */
function one( string $id, string $type ): ?\WP_Block_Template {
	if ( 'wp_navigation' === $type ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'wp_navigation' !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$t          = new \WP_Block_Template();
		$t->id      = (string) $post->ID;
		$t->type    = 'wp_navigation';
		$t->title   = '' !== $post->post_title ? $post->post_title : __( 'Navigation', 'flurfunk' );
		$t->content = $post->post_content;
		$t->source  = 'custom';
		return $t;
	}
	return function_exists( 'get_block_template' ) ? get_block_template( $id, type( $type ) ) : null;
}

/**
 * The templates and parts, or the markup of one.
 *
 * @param array $input { id, type }.
 * @return array|\WP_Error
 */
function get( array $input ) {
	if ( ! wp_is_block_theme() ) {
		return new \WP_Error( 'flurfunk_templates', __( 'This theme is not a block theme: its templates are files, which cannot be changed here.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$type = type( $input['type'] ?? '' );
	if ( ! empty( $input['id'] ) ) {
		$t = one( (string) $input['id'], $type );
		if ( ! $t ) {
			return new \WP_Error( 'flurfunk_templates', __( 'This template does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
		}
		return array(
			'id'      => $t->id,
			'type'    => $type,
			'title'   => (string) $t->title,
			'source'  => (string) $t->source,
			'content' => mb_substr( (string) $t->content, 0, 30000 ),
		);
	}
	$out = array();
	foreach ( array( 'wp_template_part', 'wp_template' ) as $kind ) {
		foreach ( get_block_templates( array(), $kind ) as $t ) {
			$out[] = array_filter(
				array(
					'id'     => $t->id,
					'type'   => $kind,
					'title'  => (string) $t->title,
					'area'   => 'wp_template_part' === $kind ? (string) $t->area : '',
					'source' => (string) $t->source,
				)
			);
		}
	}
	// The menus: a navigation block without "ref" shows the most recently published one.
	$menus = get_posts(
		array(
			'post_type'      => 'wp_navigation',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	foreach ( $menus as $i => $m ) {
		$out[] = array_filter(
			array(
				'id'     => (string) $m->ID,
				'type'   => 'wp_navigation',
				'title'  => $m->post_title,
				'area'   => 0 === $i ? 'navigation (shown where a navigation block has no ref)' : 'navigation',
				'source' => 'custom',
			)
		);
	}
	return $out;
}

/**
 * The new markup of a change: an exact replacement in the current markup, or new markup – filtered.
 *
 * @param array                   $input { find, replace, content }.
 * @param \WP_Block_Template|null $t     Template.
 * @return string|\WP_Error
 */
function changed( array $input, ?\WP_Block_Template $t ) {
	if ( ! $t ) {
		return new \WP_Error( 'flurfunk_templates', __( 'This template does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	// What the assistant wrote is filtered (no scripts); the rest of the template stays as it is.
	if ( isset( $input['content'] ) && '' !== trim( (string) $input['content'] ) ) {
		return trim( wp_kses_post( (string) $input['content'] ) );
	}
	$find = (string) ( $input['find'] ?? '' );
	if ( '' === $find || ! str_contains( (string) $t->content, $find ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'The text to replace is not in this template (exactly as written).', 'flurfunk' ), array( 'status' => 400 ) );
	}
	return str_replace( $find, wp_kses_post( (string) ( $input['replace'] ?? '' ) ), (string) $t->content );
}

/**
 * What a change does to the visible text, in one line for the card: what is added, removed or
 * becomes what – the markup around it is shown below the card.
 *
 * @param array $input { find, replace, content }.
 */
function summary( array $input ): string {
	if ( isset( $input['content'] ) && '' !== trim( (string) $input['content'] ) ) {
		return __( 'New markup for the whole template', 'flurfunk' );
	}
	$text = static fn ( $s ) => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
	$old  = $text( $input['find'] ?? '' );
	$new  = $text( $input['replace'] ?? '' );
	$cut  = static fn ( $s ) => mb_substr( $s, 0, 80 );
	if ( $old === $new ) {
		return __( 'Only the markup changes, not the text', 'flurfunk' );
	}
	if ( '' === $new ) {
		/* translators: %s: text that is removed */
		return sprintf( __( 'Removes "%s"', 'flurfunk' ), $cut( $old ) );
	}
	if ( '' === $old || str_contains( $new, $old ) ) {
		/* translators: %s: text that is added */
		return sprintf( __( 'Adds "%s"', 'flurfunk' ), $cut( trim( '' === $old ? $new : str_replace( $old, ' ', $new ) ) ) );
	}
	/* translators: 1: old text, 2: new text */
	return sprintf( __( '"%1$s" becomes "%2$s"', 'flurfunk' ), $cut( $old ), $cut( $new ) );
}

/**
 * Check a proposed change before it becomes a card.
 *
 * @param array $input Ability input.
 * @return true|\WP_Error
 */
function validate( array $input ) {
	if ( ! wp_is_block_theme() ) {
		return new \WP_Error( 'flurfunk_templates', __( 'This theme is not a block theme: its templates are files, which cannot be changed here.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$new = changed( $input, one( (string) ( $input['id'] ?? '' ), type( $input['type'] ?? '' ) ) );
	return is_wp_error( $new ) ? $new : true;
}

/**
 * Apply a change as the current person.
 *
 * @param array $input { id, type, find, replace, content, why }.
 * @return array|\WP_Error What changed, and what undo needs.
 */
function update( array $input ) {
	$type = type( $input['type'] ?? '' );
	$t    = one( (string) ( $input['id'] ?? '' ), $type );
	$new  = changed( $input, $t );
	if ( is_wp_error( $new ) ) {
		return $new;
	}
	$req = new \WP_REST_Request( 'POST', route( $type ) . $t->id );
	$req->set_body_params( array( 'content' => $new ) );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	$after = one( $t->id, $type );
	return array(
		'id'    => $t->id,
		'title' => (string) $t->title,
		'undo'  => array(
			'id'     => $t->id,
			'type'   => $type,
			'source' => (string) $t->source,
			'before' => (string) $t->content,
			'after'  => md5( (string) ( $after ? $after->content : '' ) ),
		),
	);
}

/**
 * Put a template back as it was before a change – unless it was changed again since.
 *
 * @param array $undo What update() returned under "undo".
 * @return true|\WP_Error
 */
function undo( array $undo ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot do this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$t = one( (string) ( $undo['id'] ?? '' ), type( $undo['type'] ?? '' ) );
	if ( ! $t || md5( (string) $t->content ) !== (string) ( $undo['after'] ?? '' ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'The template was changed again since; nothing was undone.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	if ( 'theme' === ( $undo['source'] ?? '' ) ) {
		// It was the theme's original: remove the site's copy (as "reset" in the Site Editor).
		$res = rest_do_request( new \WP_REST_Request( 'DELETE', route( type( $undo['type'] ) ) . $t->id ) );
	} else {
		$req = new \WP_REST_Request( 'POST', route( type( $undo['type'] ) ) . $t->id );
		$req->set_body_params( array( 'content' => (string) $undo['before'] ) );
		$res = rest_do_request( $req );
	}
	return $res->is_error() ? $res->as_error() : true;
}

/**
 * The pages a new page template is meant for, which the current person may change.
 *
 * @param array $input { assign }.
 * @return int[]|\WP_Error
 */
function pages( array $input ) {
	$ids = array();
	foreach ( array_slice( (array) ( $input['assign'] ?? array() ), 0, 20 ) as $id ) {
		$p = get_post( (int) $id );
		if ( ! $p || 'page' !== $p->post_type || ! current_user_can( 'edit_post', $p->ID ) ) {
			/* translators: %d: ID */
			return new \WP_Error( 'flurfunk_templates', sprintf( __( 'Page %d does not exist or you may not change it.', 'flurfunk' ), (int) $id ), array( 'status' => 400 ) );
		}
		$ids[] = $p->ID;
	}
	return $ids;
}

/**
 * Check a new template before it becomes a card: a block theme, a name of its own, some markup.
 *
 * @param array $input { type, slug, title, content, area, assign }.
 * @return true|\WP_Error
 */
function validate_new( array $input ) {
	if ( ! wp_is_block_theme() ) {
		return new \WP_Error( 'flurfunk_templates', __( 'This theme is not a block theme: its templates are files, which cannot be changed here.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$type = 'wp_template' === ( $input['type'] ?? 'wp_template' ) ? 'wp_template' : 'wp_template_part';
	$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
	if ( '' === $slug || '' === trim( (string) ( $input['content'] ?? '' ) ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'A new template needs a short name (slug) and its block markup.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	if ( one( get_stylesheet() . '//' . $slug, $type ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'A template with this name exists already: change it instead (flurfunk/update-template).', 'flurfunk' ), array( 'status' => 400 ) );
	}
	if ( 'wp_template_part' === $type && ! empty( $input['assign'] ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'Only a page template can be given to pages.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$pages = pages( $input );
	return is_wp_error( $pages ) ? $pages : true;
}

/**
 * Add a template or template part as the current person (the site's own, as in the Site Editor),
 * and give a page template to the pages named.
 *
 * @param array $input { type, slug, title, description, content, area, assign }.
 * @return array|\WP_Error What was made, and what undo needs.
 */
function create( array $input ) {
	$ok = validate_new( $input );
	if ( true !== $ok ) {
		return $ok;
	}
	$type  = 'wp_template' === ( $input['type'] ?? 'wp_template' ) ? 'wp_template' : 'wp_template_part';
	$slug  = sanitize_title( (string) $input['slug'] );
	$pages = pages( $input );
	$body  = array(
		'slug'        => $slug,
		'title'       => sanitize_text_field( (string) ( $input['title'] ?? $slug ) ),
		'description' => sanitize_text_field( (string) ( $input['description'] ?? '' ) ),
		'content'     => trim( wp_kses_post( (string) $input['content'] ) ), // What the assistant wrote is filtered.
		'status'      => 'publish',
	);
	if ( 'wp_template_part' === $type ) {
		$body['area'] = in_array( $input['area'] ?? '', array( 'header', 'footer', 'uncategorized' ), true ) ? (string) $input['area'] : 'uncategorized';
	}
	$req = new \WP_REST_Request( 'POST', rtrim( route( $type ), '/' ) );
	$req->set_body_params( $body );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	$id     = (string) ( $res->get_data()['id'] ?? get_stylesheet() . '//' . $slug );
	$before = array();
	foreach ( $pages as $page ) {
		$before[ $page ] = (string) get_post_meta( $page, '_wp_page_template', true );
		update_post_meta( $page, '_wp_page_template', $slug );
	}
	$made = one( $id, $type );
	return array(
		'id'    => $id,
		'title' => $made ? (string) $made->title : $body['title'],
		'pages' => $pages,
		'undo'  => array(
			'id'    => $id,
			'type'  => $type,
			'slug'  => $slug,
			'pages' => $before,
			'after' => md5( (string) ( $made ? $made->content : '' ) ),
		),
	);
}

/**
 * Remove a template a card added (and give its pages their template from before) – unless someone
 * changed it since.
 *
 * @param array $undo What create() returned under "undo".
 * @return true|\WP_Error
 */
function remove( array $undo ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot do this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$t = one( (string) ( $undo['id'] ?? '' ), type( $undo['type'] ?? '' ) );
	if ( $t && md5( (string) $t->content ) !== (string) ( $undo['after'] ?? '' ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'The template was changed again since; nothing was undone.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	if ( $t ) {
		$req = new \WP_REST_Request( 'DELETE', route( type( $undo['type'] ) ) . $t->id );
		$req->set_param( 'force', true );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return $res->as_error();
		}
	}
	foreach ( (array) ( $undo['pages'] ?? array() ) as $page => $was ) {
		if ( current_user_can( 'edit_post', (int) $page ) && (string) $undo['slug'] === (string) get_post_meta( (int) $page, '_wp_page_template', true ) ) {
			if ( '' === (string) $was ) {
				delete_post_meta( (int) $page, '_wp_page_template' );
			} else {
				update_post_meta( (int) $page, '_wp_page_template', (string) $was );
			}
		}
	}
	return true;
}

/**
 * May the current person read or change the templates?
 */
function may(): bool {
	return is_user_logged_in() && Access\can_use() && current_user_can( 'edit_theme_options' );
}

/**
 * Register the abilities.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$which = array(
		'id'   => array(
			'type'        => 'string',
			'description' => __( 'The template\'s ID, e.g. "twentytwentyfive//footer", or a navigation menu\'s post ID.', 'flurfunk' ),
		),
		'type' => array(
			'type'    => 'string',
			'enum'    => TYPES,
			'default' => 'wp_template_part',
		),
	);
	wp_register_ability(
		'flurfunk/get-templates',
		array(
			'label'               => __( 'Read the theme\'s templates', 'flurfunk' ),
			'description'         => __( 'Without an ID: the templates and template parts (header, footer, …) of the active block theme, with where each comes from (the theme, or the site\'s own copy), and the navigation menus (a navigation block without "ref" shows the newest; a page list in it adds every published page by itself). With an ID: its block markup.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $which,
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => array( 'object', 'array' ) ),
			'execute_callback'    => static fn ( $input = array() ) => get( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
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
			'label'               => __( 'Change a template', 'flurfunk' ),
			'description'         => __( 'Changes a template, template part or navigation menu of the active block theme: an exact replacement in its block markup (find, replace), or new markup (content). Saves the site\'s own copy, as the Site Editor does; can be undone. Scripts are filtered out.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $which + array(
					'find'    => array(
						'type'  => 'string',
						'title' => __( 'Find', 'flurfunk' ),
					),
					'replace' => array(
						'type'  => 'string',
						'title' => __( 'Replace with', 'flurfunk' ),
					),
					'content' => array(
						'type'  => 'string',
						'title' => __( 'New markup', 'flurfunk' ),
					),
					'why'     => array(
						'type'      => 'string',
						'maxLength' => 300,
						'title'     => __( 'Why', 'flurfunk' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => update( (array) $input ),
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
	wp_register_ability(
		ADD,
		array(
			'label'               => __( 'Add a template', 'flurfunk' ),
			'description'         => __( 'Adds a new template (e.g. a page template "landing page") or template part to the active block theme – the site\'s own, as in the Site Editor – and can give a page template to pages right away (assign). A template named "page-<page slug>" is used for that page by itself. Can be undone. Scripts are filtered out.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'        => array(
						'type'    => 'string',
						'enum'    => array( 'wp_template', 'wp_template_part' ),
						'default' => 'wp_template',
					),
					'slug'        => array(
						'type'        => 'string',
						'maxLength'   => 80,
						'description' => __( 'Short name, e.g. "landing-page".', 'flurfunk' ),
					),
					'title'       => array(
						'type'      => 'string',
						'maxLength' => 100,
						'title'     => __( 'Title', 'flurfunk' ),
					),
					'description' => array(
						'type'      => 'string',
						'maxLength' => 300,
					),
					'area'        => array(
						'type'        => 'string',
						'enum'        => array( 'header', 'footer', 'uncategorized' ),
						'description' => __( 'For a template part: where it belongs.', 'flurfunk' ),
					),
					'content'     => array(
						'type'  => 'string',
						'title' => __( 'Block markup', 'flurfunk' ),
					),
					'assign'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'maxItems'    => 20,
						'description' => __( 'IDs of pages that get the new page template.', 'flurfunk' ),
					),
					'why'         => array(
						'type'      => 'string',
						'maxLength' => 300,
						'title'     => __( 'Why', 'flurfunk' ),
					),
				),
				'required'             => array( 'slug', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => create( (array) $input ),
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

<?php
/**
 * The templates of a block theme – the page templates and the parts like header and footer: read,
 * and changed by a person who may edit the theme's options (an AI assistant only proposes it, as a
 * card the person applies and can undo).
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

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * REST base of a template type.
 *
 * @param string $type wp_template or wp_template_part.
 */
function route( string $type ): string {
	return 'wp_template' === $type ? '/wp/v2/templates/' : '/wp/v2/template-parts/';
}

/**
 * One template.
 *
 * @param string $id   "theme//slug".
 * @param string $type wp_template or wp_template_part.
 */
function one( string $id, string $type ): ?\WP_Block_Template {
	return function_exists( 'get_block_template' ) ? get_block_template( $id, 'wp_template' === $type ? 'wp_template' : 'wp_template_part' ) : null;
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
	$type = 'wp_template' === ( $input['type'] ?? '' ) ? 'wp_template' : 'wp_template_part';
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
	$new = changed( $input, one( (string) ( $input['id'] ?? '' ), (string) ( $input['type'] ?? '' ) ) );
	return is_wp_error( $new ) ? $new : true;
}

/**
 * Apply a change as the current person.
 *
 * @param array $input { id, type, find, replace, content, why }.
 * @return array|\WP_Error What changed, and what undo needs.
 */
function update( array $input ) {
	$type = 'wp_template' === ( $input['type'] ?? '' ) ? 'wp_template' : 'wp_template_part';
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
	$t = one( (string) ( $undo['id'] ?? '' ), (string) ( $undo['type'] ?? '' ) );
	if ( ! $t || md5( (string) $t->content ) !== (string) ( $undo['after'] ?? '' ) ) {
		return new \WP_Error( 'flurfunk_templates', __( 'The template was changed again since; nothing was undone.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	if ( 'theme' === ( $undo['source'] ?? '' ) ) {
		// It was the theme's original: remove the site's copy (as "reset" in the Site Editor).
		$res = rest_do_request( new \WP_REST_Request( 'DELETE', route( (string) $undo['type'] ) . $t->id ) );
	} else {
		$req = new \WP_REST_Request( 'POST', route( (string) $undo['type'] ) . $t->id );
		$req->set_body_params( array( 'content' => (string) $undo['before'] ) );
		$res = rest_do_request( $req );
	}
	return $res->is_error() ? $res->as_error() : true;
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
			'description' => __( 'The template\'s ID, e.g. "twentytwentyfive//footer".', 'flurfunk' ),
		),
		'type' => array(
			'type'    => 'string',
			'enum'    => array( 'wp_template_part', 'wp_template' ),
			'default' => 'wp_template_part',
		),
	);
	wp_register_ability(
		'flurfunk/get-templates',
		array(
			'label'               => __( 'Read the theme\'s templates', 'flurfunk' ),
			'description'         => __( 'Without an ID: the templates and template parts (header, footer, …) of the active block theme, with where each comes from (the theme, or the site\'s own copy). With an ID: its block markup.', 'flurfunk' ),
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
			'description'         => __( 'Changes a template or template part of the active block theme: an exact replacement in its block markup (find, replace), or new markup (content). Saves the site\'s own copy, as the Site Editor does; can be undone. Scripts are filtered out.', 'flurfunk' ),
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
}

<?php
/**
 * The site's look: colors, fonts and additional CSS of the active theme – read, and changed by a
 * person who may edit the theme's options (an AI assistant only proposes it, as a card, which the
 * person applies and can undo).
 *
 * Block themes keep a site's own choices in its global styles (colors, the palette, CSS); classic
 * themes have the "Additional CSS" of the Customizer. Both keep revisions, and "undo" puts back the
 * state before – unless someone changed the look again since.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Styles;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const NAME = 'flurfunk/update-styles';

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * The site's own global styles post of the active block theme (created if asked).
 *
 * @param bool $create Create it if there is none.
 */
function user_post( bool $create = false ): ?\WP_Post {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return null;
	}
	$row = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), $create );
	return ! empty( $row['ID'] ) ? get_post( (int) $row['ID'] ) : null;
}

/**
 * The look of now.
 */
function get(): array {
	$theme = wp_get_theme();
	$block = wp_is_block_theme();
	$out   = array(
		'theme'       => $theme->get( 'Name' ),
		'block_theme' => $block,
	);
	if ( $block && function_exists( 'wp_get_global_settings' ) ) {
		$styles  = wp_get_global_styles();
		$palette = (array) ( wp_get_global_settings( array( 'color', 'palette' ) ) ?? array() );
		$list    = array();
		foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $c ) {
				if ( isset( $c['slug'] ) && ! isset( $list[ $c['slug'] ] ) ) {
					$list[ $c['slug'] ] = array(
						'slug'  => (string) $c['slug'],
						'name'  => (string) ( $c['name'] ?? $c['slug'] ),
						'color' => (string) ( $c['color'] ?? '' ),
						'from'  => $origin,
					);
				}
			}
		}
		$fonts = array();
		foreach ( (array) ( wp_get_global_settings( array( 'typography', 'fontFamilies' ) ) ?? array() ) as $group ) {
			foreach ( (array) $group as $f ) {
				$fonts[] = (string) ( $f['name'] ?? $f['slug'] ?? '' );
			}
		}
		$out['colors']  = array(
			'background' => (string) ( $styles['color']['background'] ?? '' ),
			'text'       => (string) ( $styles['color']['text'] ?? '' ),
			'link'       => (string) ( $styles['elements']['link']['color']['text'] ?? '' ),
		);
		$out['palette'] = array_values( $list );
		$out['fonts']   = array_values( array_unique( array_filter( $fonts ) ) );
		$post           = user_post();
		$own            = $post ? json_decode( (string) $post->post_content, true ) : array();
		$out['css']     = mb_substr( (string) ( $own['styles']['css'] ?? '' ), 0, 6000 );
	} else {
		$out['css'] = mb_substr( (string) wp_get_custom_css(), 0, 6000 );
	}
	return $out;
}

/**
 * Is a value a color an assistant may set (hex, rgb/hsl, a palette variable)?
 *
 * @param string $c Value.
 */
function color( string $c ): bool {
	return (bool) preg_match( '/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)|var\(--wp--preset--color--[a-z0-9-]+\))$/i', trim( $c ) );
}

/**
 * CSS that may be saved: no markup, no imports or scripts.
 *
 * @param string $css CSS.
 * @return true|\WP_Error
 */
function css_ok( string $css ) {
	if ( mb_strlen( $css ) > 20000 || preg_match( '/<|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $css ) ) {
		return new \WP_Error( 'flurfunk_styles', __( 'This CSS cannot be saved: no markup, no @import and no scripts.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Check a proposed change before it becomes a card.
 *
 * @param array $input Ability input.
 * @return true|\WP_Error
 */
function validate( array $input ) {
	if ( isset( $input['css'] ) ) {
		$ok = css_ok( (string) $input['css'] );
		if ( true !== $ok ) {
			return $ok;
		}
	}
	foreach ( array( 'background', 'text', 'link' ) as $k ) {
		if ( isset( $input[ $k ] ) && ! color( (string) $input[ $k ] ) ) {
			/* translators: %s: value */
			return new \WP_Error( 'flurfunk_styles', sprintf( __( '"%s" is not a color.', 'flurfunk' ), (string) $input[ $k ] ), array( 'status' => 400 ) );
		}
	}
	foreach ( (array) ( $input['palette'] ?? array() ) as $c ) {
		if ( ! is_array( $c ) || empty( $c['slug'] ) || ! color( (string) ( $c['color'] ?? '' ) ) ) {
			return new \WP_Error( 'flurfunk_styles', __( 'Every palette color needs a short name (slug) and a color.', 'flurfunk' ), array( 'status' => 400 ) );
		}
	}
	if ( ! wp_is_block_theme() && ( isset( $input['background'] ) || isset( $input['text'] ) || isset( $input['link'] ) || ! empty( $input['palette'] ) ) ) {
		return new \WP_Error( 'flurfunk_styles', __( 'This theme is not a block theme: only additional CSS can be changed here.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Apply a change as the current person.
 *
 * @param array $input { css, css_mode, background, text, link, palette, why }.
 * @return array|\WP_Error What changed, and what undo needs.
 */
function update( array $input ) {
	$ok = validate( $input );
	if ( true !== $ok ) {
		return $ok;
	}
	$append = 'replace' !== ( $input['css_mode'] ?? 'append' );
	$did    = array();
	if ( wp_is_block_theme() ) {
		$post = user_post( true );
		if ( ! $post ) {
			return new \WP_Error( 'flurfunk_styles', __( 'The theme\'s styles could not be changed.', 'flurfunk' ) );
		}
		$before = (string) $post->post_content;
		$data   = json_decode( $before, true ) ?: array(
			'version'                     => 3,
			'isGlobalStylesUserThemeJSON' => true,
		);
		if ( isset( $input['css'] ) ) {
			$old = (string) ( $data['styles']['css'] ?? '' );

			$data['styles']['css'] = $append && '' !== trim( $old ) ? rtrim( $old ) . "\n\n" . trim( (string) $input['css'] ) : trim( (string) $input['css'] );

			$did[] = 'css';
		}
		foreach ( array( 'background', 'text' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$data['styles']['color'][ $k ] = trim( (string) $input[ $k ] );

				$did[] = $k;
			}
		}
		if ( isset( $input['link'] ) ) {
			$data['styles']['elements']['link']['color']['text'] = trim( (string) $input['link'] );

			$did[] = 'link';
		}
		if ( ! empty( $input['palette'] ) ) {
			$custom = array();
			foreach ( (array) ( $data['settings']['color']['palette']['custom'] ?? array() ) as $c ) {
				$custom[ (string) $c['slug'] ] = $c;
			}
			foreach ( (array) $input['palette'] as $c ) {
				$slug            = sanitize_title( (string) $c['slug'] );
				$custom[ $slug ] = array(
					'slug'  => $slug,
					'name'  => sanitize_text_field( (string) ( $c['name'] ?? $slug ) ),
					'color' => trim( (string) $c['color'] ),
				);
			}
			$data['settings']['color']['palette']['custom'] = array_values( $custom );

			$did[] = 'palette';
		}
		$after = (string) wp_json_encode( $data );
		$res   = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $after ),
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		return array(
			'theme'   => wp_get_theme()->get( 'Name' ),
			'changed' => $did,
			'undo'    => array(
				'kind'   => 'global',
				'post'   => (int) $post->ID,
				'before' => $before,
				'after'  => md5( (string) get_post( $post->ID )->post_content ),
			),
		);
	}
	$before = (string) wp_get_custom_css();
	$css    = $append && '' !== trim( $before ) ? rtrim( $before ) . "\n\n" . trim( (string) ( $input['css'] ?? '' ) ) : trim( (string) ( $input['css'] ?? '' ) );
	$res    = wp_update_custom_css_post( $css );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array(
		'theme'   => wp_get_theme()->get( 'Name' ),
		'changed' => array( 'css' ),
		'undo'    => array(
			'kind'   => 'custom_css',
			'before' => $before,
			'after'  => md5( (string) wp_get_custom_css() ),
		),
	);
}

/**
 * Put the look back as it was before a change – unless it was changed again since.
 *
 * @param array $undo What update() returned under "undo".
 * @return true|\WP_Error
 */
function undo( array $undo ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'You cannot do this.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	if ( 'global' === ( $undo['kind'] ?? '' ) ) {
		$post = get_post( (int) $undo['post'] );
		if ( ! $post || md5( (string) $post->post_content ) !== (string) $undo['after'] ) {
			return new \WP_Error( 'flurfunk_styles', __( 'The look was changed again since; nothing was undone.', 'flurfunk' ), array( 'status' => 409 ) );
		}
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( (string) $undo['before'] ),
			)
		);
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		return true;
	}
	if ( md5( (string) wp_get_custom_css() ) !== (string) ( $undo['after'] ?? '' ) ) {
		return new \WP_Error( 'flurfunk_styles', __( 'The look was changed again since; nothing was undone.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	$res = wp_update_custom_css_post( (string) $undo['before'] );
	return is_wp_error( $res ) ? $res : true;
}

/**
 * May the current person change (or read) the theme's look?
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
	wp_register_ability(
		'flurfunk/get-styles',
		array(
			'label'               => __( 'Read the site\'s look', 'flurfunk' ),
			'description'         => __( 'The active theme, whether it is a block theme, its base colors, color palette, fonts and the site\'s additional CSS.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn () => get(),
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
	$color = array(
		'type'        => 'string',
		'description' => __( 'A color: #hex, rgb(), hsl() or var(--wp--preset--color--slug).', 'flurfunk' ),
	);
	wp_register_ability(
		NAME,
		array(
			'label'               => __( 'Change the site\'s look', 'flurfunk' ),
			'description'         => __( 'Changes the active theme\'s look for the whole site: additional CSS (added, or replacing all of it), for block themes also the background, text and link colors and colors of the palette. Can be undone.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'css'        => array(
						'type'      => 'string',
						'maxLength' => 20000,
						'title'     => __( 'CSS', 'flurfunk' ),
					),
					'css_mode'   => array(
						'type'    => 'string',
						'enum'    => array( 'append', 'replace' ),
						'default' => 'append',
					),
					'background' => $color + array( 'title' => __( 'Background', 'flurfunk' ) ),
					'text'       => $color + array( 'title' => __( 'Text color', 'flurfunk' ) ),
					'link'       => $color + array( 'title' => __( 'Link color', 'flurfunk' ) ),
					'palette'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'  => array( 'type' => 'string' ),
								'name'  => array( 'type' => 'string' ),
								'color' => array( 'type' => 'string' ),
							),
						),
						'title' => __( 'Palette', 'flurfunk' ),
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

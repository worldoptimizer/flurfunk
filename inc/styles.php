<?php
/**
 * The site's look: colors, fonts, sizes, spacing and additional CSS of the active theme – read, and
 * changed by a person who may edit the theme's options (an AI assistant only proposes it, as a card,
 * which the person applies and can undo).
 *
 * Block themes keep a site's own choices in its global styles (colors, the palette, typography,
 * spacing, borders, content widths, CSS) – only known properties with plain values are set; classic
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
		// The presets, as the values to use: var(--wp--preset--font-family--<slug>) and the like.
		$presets = static function ( array $path, string $var, string $value ): array {
			$out = array();
			foreach ( (array) ( wp_get_global_settings( $path ) ?? array() ) as $group ) {
				foreach ( (array) $group as $x ) {
					if ( ! empty( $x['slug'] ) && ! isset( $out[ $x['slug'] ] ) ) {
						$out[ $x['slug'] ] = array(
							'name'  => (string) ( $x['name'] ?? $x['slug'] ),
							'value' => 'var(--wp--preset--' . $var . '--' . $x['slug'] . ')',
							'is'    => is_scalar( $x[ $value ] ?? null ) ? (string) $x[ $value ] : '',
						);
					}
				}
			}
			return array_values( $out );
		};
		$fonts  = $presets( array( 'typography', 'fontFamilies' ), 'font-family', 'fontFamily' );
		$layout = (array) ( wp_get_global_settings( array( 'layout' ) ) ?? array() );
		$out['colors']  = array(
			'background' => (string) ( $styles['color']['background'] ?? '' ),
			'text'       => (string) ( $styles['color']['text'] ?? '' ),
			'link'       => (string) ( $styles['elements']['link']['color']['text'] ?? '' ),
		);
		$out['palette'] = array_values( $list );
		$out['fonts']   = $fonts;
		$out['sizes']   = array(
			'font'    => $presets( array( 'typography', 'fontSizes' ), 'font-size', 'size' ),
			'spacing' => $presets( array( 'spacing', 'spacingSizes' ), 'spacing', 'size' ),
		);
		$out['layout']  = array_intersect_key( $layout, array_flip( array( 'contentSize', 'wideSize' ) ) );
		$out['styles']  = array_filter(
			array(
				'typography' => (array) ( $styles['typography'] ?? array() ),
				'spacing'    => (array) ( $styles['spacing'] ?? array() ),
				'elements'   => array_filter( array_map( static fn ( $e ) => array_intersect_key( (array) $e, array_flip( array( 'typography', 'spacing', 'border' ) ) ), array_intersect_key( (array) ( $styles['elements'] ?? array() ), array_flip( ELEMENTS ) ) ) ),
			)
		);
		$post           = user_post();
		$own            = $post ? json_decode( (string) $post->post_content, true ) : array();
		$out['css']     = mb_substr( (string) ( $own['styles']['css'] ?? '' ), 0, 6000 );
	} else {
		$out['css'] = mb_substr( (string) wp_get_custom_css(), 0, 6000 );
	}
	return $out;
}

/**
 * The style properties an assistant may set, per group (as in theme.json).
 */
const PROPS = array(
	'typography' => array( 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'textTransform', 'textDecoration' ),
	'spacing'    => array( 'blockGap', 'padding', 'margin' ),
	'color'      => array( 'background', 'text' ),
	'border'     => array( 'radius', 'width', 'style', 'color' ),
);

/**
 * The elements whose look can be set (headings, buttons, links, …).
 */
const ELEMENTS = array( 'link', 'heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'caption', 'cite' );

/**
 * Is a value a plain CSS value (a size, a number, a font stack, a preset variable)? No URLs, no
 * markup, nothing that ends a declaration.
 *
 * @param mixed $v Value.
 */
function value_ok( $v ): bool {
	if ( is_int( $v ) || is_float( $v ) ) {
		return true;
	}
	if ( ! is_string( $v ) || '' === trim( $v ) || strlen( $v ) > 200 ) {
		return false;
	}
	return (bool) preg_match( '/^[a-z0-9#%.,()\s\/+*\'"-]+$/i', $v ) && ! preg_match( '/url\s*\(|expression|javascript|behavior/i', $v );
}

/**
 * One node of styles (the site, an element or a block), checked: only known properties, plain
 * values.
 *
 * @param mixed  $node  Node.
 * @param string $where For the error.
 * @return array|\WP_Error
 */
function clean_node( $node, string $where ) {
	$out = array();
	foreach ( (array) $node as $group => $props ) {
		if ( ! isset( PROPS[ $group ] ) || ! is_array( $props ) ) {
			/* translators: 1: e.g. styles.elements.h1, 2: property */
			return new \WP_Error( 'flurfunk_styles', sprintf( __( '%1$s: "%2$s" cannot be set here (typography, spacing, color and border can).', 'flurfunk' ), $where, (string) $group ), array( 'status' => 400 ) );
		}
		foreach ( $props as $k => $v ) {
			$sides = in_array( $k, array( 'padding', 'margin' ), true ) && is_array( $v );
			$vals  = $sides ? $v : array( $v );
			$known = in_array( $k, PROPS[ $group ], true ) && ( ! $sides || ! array_diff( array_keys( $v ), array( 'top', 'right', 'bottom', 'left' ) ) );
			if ( ! $known || array_filter( $vals, static fn ( $x ) => ! value_ok( $x ) ) ) {
				/* translators: 1: e.g. styles.elements.h1, 2: property, e.g. typography.fontSize */
				return new \WP_Error( 'flurfunk_styles', sprintf( __( '%1$s: "%2$s" is not a property or value that can be set here.', 'flurfunk' ), $where, $group . '.' . $k ), array( 'status' => 400 ) );
			}
			$out[ $group ][ $k ] = $sides ? array_map( static fn ( $x ) => trim( (string) $x ), $v ) : ( is_string( $v ) ? trim( $v ) : $v );
		}
	}
	return $out;
}

/**
 * Styles for the whole site, its elements and blocks, checked.
 *
 * @param array $styles { typography, spacing, color, border, elements: { h1: … }, blocks: { core/…: … } }.
 * @return array|\WP_Error
 */
function clean_styles( array $styles ) {
	$out  = array();
	$base = array_diff_key( $styles, array_flip( array( 'elements', 'blocks' ) ) );
	$node = clean_node( $base, 'styles' );
	if ( is_wp_error( $node ) ) {
		return $node;
	}
	$out = $node;
	foreach ( (array) ( $styles['elements'] ?? array() ) as $el => $n ) {
		if ( ! in_array( $el, ELEMENTS, true ) ) {
			/* translators: %s: element */
			return new \WP_Error( 'flurfunk_styles', sprintf( __( 'There is no element "%s" (link, heading, h1–h6, button, caption, cite).', 'flurfunk' ), (string) $el ), array( 'status' => 400 ) );
		}
		$c = clean_node( $n, 'styles.elements.' . $el );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$out['elements'][ $el ] = $c;
	}
	$registry = \WP_Block_Type_Registry::get_instance();
	foreach ( (array) ( $styles['blocks'] ?? array() ) as $block => $n ) {
		if ( ! $registry->is_registered( (string) $block ) ) {
			/* translators: %s: block name */
			return new \WP_Error( 'flurfunk_styles', sprintf( __( 'There is no block "%s" (e.g. core/button).', 'flurfunk' ), (string) $block ), array( 'status' => 400 ) );
		}
		$c = clean_node( $n, 'styles.blocks.' . $block );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$out['blocks'][ $block ] = $c;
	}
	return $out;
}

/**
 * The widths of the content (contentSize, wideSize), checked.
 *
 * @param array $layout { contentSize, wideSize }.
 * @return array|\WP_Error
 */
function clean_layout( array $layout ) {
	$out = array();
	foreach ( $layout as $k => $v ) {
		if ( ! in_array( $k, array( 'contentSize', 'wideSize' ), true ) || ! value_ok( $v ) ) {
			/* translators: %s: setting */
			return new \WP_Error( 'flurfunk_styles', sprintf( __( 'layout.%s cannot be set (contentSize and wideSize can).', 'flurfunk' ), (string) $k ), array( 'status' => 400 ) );
		}
		$out[ $k ] = trim( (string) $v );
	}
	return $out;
}

/**
 * What a change does, in words for the card ("Headings: font size 2.5rem").
 *
 * @param array $input { styles, layout }.
 * @return string[]
 */
function describe( array $input ): array {
	$names = array(
		'fontFamily'     => __( 'font', 'flurfunk' ),
		'fontSize'       => __( 'font size', 'flurfunk' ),
		'fontWeight'     => __( 'font weight', 'flurfunk' ),
		'fontStyle'      => __( 'font style', 'flurfunk' ),
		'lineHeight'     => __( 'line height', 'flurfunk' ),
		'letterSpacing'  => __( 'letter spacing', 'flurfunk' ),
		'textTransform'  => __( 'capitals', 'flurfunk' ),
		'textDecoration' => __( 'underline', 'flurfunk' ),
		'blockGap'       => __( 'space between blocks', 'flurfunk' ),
		'padding'        => __( 'inner spacing', 'flurfunk' ),
		'margin'         => __( 'outer spacing', 'flurfunk' ),
		'background'     => __( 'background', 'flurfunk' ),
		'text'           => __( 'text color', 'flurfunk' ),
		'radius'         => __( 'rounded corners', 'flurfunk' ),
		'width'          => __( 'border width', 'flurfunk' ),
		'style'          => __( 'border style', 'flurfunk' ),
		'color'          => __( 'border color', 'flurfunk' ),
	);
	$where = array(
		'link'    => __( 'Links', 'flurfunk' ),
		'heading' => __( 'Headings', 'flurfunk' ),
		'button'  => __( 'Buttons', 'flurfunk' ),
		'caption' => __( 'Captions', 'flurfunk' ),
		'cite'    => __( 'Quotes', 'flurfunk' ),
	);
	$value = static function ( $v ): string {
		$v = is_array( $v ) ? implode( ' ', $v ) : (string) $v;
		return preg_replace( '/var\(--wp--preset--[a-z-]+--([a-z0-9-]+)\)/i', '$1', $v );
	};
	$line = static function ( string $who, array $node ) use ( $names, $value ): string {
		$bits = array();
		foreach ( $node as $props ) {
			foreach ( (array) $props as $k => $v ) {
				$bits[] = ( $names[ $k ] ?? $k ) . ' ' . $value( $v );
			}
		}
		return $who . ': ' . implode( ', ', $bits );
	};
	$styles = (array) ( $input['styles'] ?? array() );
	$out    = array();
	$base   = array_diff_key( $styles, array_flip( array( 'elements', 'blocks' ) ) );
	if ( $base ) {
		$out[] = $line( __( 'Whole site', 'flurfunk' ), $base );
	}
	foreach ( (array) ( $styles['elements'] ?? array() ) as $el => $n ) {
		/* translators: %s: heading level, e.g. h2 */
		$out[] = $line( $where[ $el ] ?? sprintf( __( 'Headings %s', 'flurfunk' ), strtoupper( (string) $el ) ), (array) $n );
	}
	foreach ( (array) ( $styles['blocks'] ?? array() ) as $block => $n ) {
		$type  = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block );
		$out[] = $line( $type && $type->title ? (string) $type->title : (string) $block, (array) $n );
	}
	foreach ( (array) ( $input['layout'] ?? array() ) as $k => $v ) {
		$out[] = ( 'wideSize' === $k ? __( 'Wide width', 'flurfunk' ) : __( 'Content width', 'flurfunk' ) ) . ' ' . $value( $v );
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
	foreach ( array( 'styles', 'layout' ) as $k ) {
		if ( ! empty( $input[ $k ] ) ) {
			if ( ! is_array( $input[ $k ] ) ) {
				/* translators: %s: styles or layout */
				return new \WP_Error( 'flurfunk_styles', sprintf( __( '%s must be an object.', 'flurfunk' ), $k ), array( 'status' => 400 ) );
			}
			$c = 'styles' === $k ? clean_styles( $input[ $k ] ) : clean_layout( $input[ $k ] );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
		}
	}
	if ( ! wp_is_block_theme() && ( isset( $input['background'] ) || isset( $input['text'] ) || isset( $input['link'] ) || ! empty( $input['palette'] ) || ! empty( $input['styles'] ) || ! empty( $input['layout'] ) ) ) {
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
		if ( ! empty( $input['styles'] ) ) {
			$data['styles'] = array_replace_recursive( (array) ( $data['styles'] ?? array() ), clean_styles( (array) $input['styles'] ) );

			$did[] = 'styles';
		}
		if ( ! empty( $input['layout'] ) ) {
			$data['settings']['layout'] = array_merge( (array) ( $data['settings']['layout'] ?? array() ), clean_layout( (array) $input['layout'] ) );

			$did[] = 'layout';
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
			'description'         => __( 'The active theme, whether it is a block theme, its base colors, color palette, fonts and font and spacing sizes (with the values to use), the content widths, the typography and spacing set for the site and its elements, and the site\'s additional CSS.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'css' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Include the additional CSS.', 'flurfunk' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => array_diff_key( get(), false === ( ( (array) $input )['css'] ?? true ) ? array( 'css' => 1 ) : array() ),
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
			'description'         => __( 'Changes the active theme\'s look for the whole site: additional CSS (added, or replacing all of it), for block themes also the background, text and link colors, colors of the palette, fonts, font sizes, spacing and borders (styles, as in theme.json – for the site, elements like headings and buttons, or blocks) and the content widths (layout). Can be undone.', 'flurfunk' ),
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
					'styles'     => array(
						'type'        => 'object',
						'title'       => __( 'Styles', 'flurfunk' ),
						'description' => __( 'Block themes, as in theme.json "styles": groups typography (fontFamily, fontSize, fontWeight, fontStyle, lineHeight, letterSpacing, textTransform, textDecoration), spacing (blockGap, padding, margin – a value or top/right/bottom/left), color (background, text), border (radius, width, style, color) – for the whole site, under elements (link, heading, h1–h6, button, caption, cite) or under blocks (e.g. core/button). Values: sizes, numbers or presets such as var(--wp--preset--font-family--inter). Only what changes.', 'flurfunk' ),
					),
					'layout'     => array(
						'type'        => 'object',
						'title'       => __( 'Content width', 'flurfunk' ),
						'description' => __( 'Block themes: contentSize and wideSize, e.g. "720px".', 'flurfunk' ),
						'properties'  => array(
							'contentSize' => array( 'type' => 'string' ),
							'wideSize'    => array( 'type' => 'string' ),
						),
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

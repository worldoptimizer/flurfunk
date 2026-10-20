<?php
/**
 * Drafts from a conversation, as WordPress abilities: flurfunk/create-draft makes a draft (never
 * published; the current user is its author), flurfunk/update-draft changes such a draft as long as
 * nobody else has saved it since. Images from Flurfunk conversations can go along: they move into
 * the media library (keeping their address, as "Add to media library" does).
 *
 * Both run as the current user with WordPress's own capabilities (create_posts of the post type,
 * edit_post, upload_files for images); an AI assistant runs them within its limit
 * (modules/ai-limits.php), so what it writes goes through kses like anyone's without unfiltered_html.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Drafts;

use Flurfunk\Access;
use Flurfunk\Files;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

const SAVED  = '_flurfunk_draft_saved'; // post_modified_gmt after Flurfunk last saved it.
const AGENT  = '_flurfunk_assistant';   // The assistant that wrote it (set by the AI module).
const THREAD = '_flurfunk_thread';      // The conversation it came from (set by the AI module).
const NAMES  = array( 'flurfunk/create-draft', 'flurfunk/update-draft' );

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * Post types drafts can be made of: Flurfunk's post types, and pages (to build the site with).
 *
 * @return string[]
 */
function types(): array {
	return array_values(
		array_unique(
			array_filter(
				array_merge( (array) \Flurfunk\Settings\get()['post_types'], array( 'page' ) ),
				static fn ( $t ) => post_type_exists( (string) $t ) && 'attachment' !== $t
			)
		)
	);
}

/**
 * The text of a draft as blocks: block markup as it is (WordPress filters it on saving, as for
 * anyone without "unfiltered_html" – which an assistant never has), plain text converted.
 *
 * @param string $text Text or block markup.
 */
function content( string $text ): string {
	return str_contains( $text, '<!-- wp:' ) ? trim( wp_kses_post( $text ) ) : blocks( $text );
}

/**
 * Text as blocks: paragraphs (blank line between), "- " lists, "## " headings, *bold*, _italic_,
 * links. Everything else is escaped.
 *
 * @param string $text Plain text.
 */
function blocks( string $text ): string {
	$out = array();
	foreach ( (array) preg_split( "/\n[ \t]*\n/", trim( str_replace( "\r", '', $text ) ) ) as $chunk ) {
		$chunk = trim( (string) $chunk );
		if ( '' === $chunk ) {
			continue;
		}
		$lines = explode( "\n", $chunk );
		if ( 1 === count( $lines ) && preg_match( '/^(#{2,4})\s+(.+)$/u', $chunk, $m ) ) {
			$level = strlen( $m[1] );
			$out[] = '<!-- wp:heading' . ( 2 === $level ? '' : ' {"level":' . $level . '}' ) . ' -->' . "\n" . '<h' . $level . ' class="wp-block-heading">' . inline( $m[2] ) . '</h' . $level . '>' . "\n" . '<!-- /wp:heading -->';
			continue;
		}
		if ( count( preg_grep( '/^\s*[-*•]\s+/u', $lines ) ) === count( $lines ) ) {
			$items = array_map( static fn ( $l ) => '<!-- wp:list-item -->' . "\n" . '<li>' . inline( (string) preg_replace( '/^\s*[-*•]\s+/u', '', $l ) ) . '</li>' . "\n" . '<!-- /wp:list-item -->', $lines );
			$out[] = '<!-- wp:list -->' . "\n" . '<ul class="wp-block-list">' . implode( "\n", $items ) . '</ul>' . "\n" . '<!-- /wp:list -->';
			continue;
		}
		$out[] = '<!-- wp:paragraph -->' . "\n" . '<p>' . implode( '<br>', array_map( __NAMESPACE__ . '\\inline', $lines ) ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
	}
	return implode( "\n\n", $out );
}

/**
 * One line: escaped, with bold (two or one asterisks), italic (underscores) and links.
 *
 * @param string $s Text.
 */
function inline( string $s ): string {
	$s = esc_html( trim( $s ) );
	$s = (string) preg_replace( '/\*\*(?!\s)(.+?)(?<!\s)\*\*/u', '<strong>$1</strong>', $s );
	$s = (string) preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<strong>$1</strong>', $s );
	$s = (string) preg_replace( '/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/u', '<em>$1</em>', $s );
	return make_clickable( $s );
}

/**
 * An image block.
 *
 * @param int $id Attachment.
 */
function image_block( int $id ): string {
	$url = wp_get_attachment_image_url( $id, 'large' ) ?: wp_get_attachment_url( $id );
	$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="' . esc_url( (string) $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/></figure>' . "\n" . '<!-- /wp:image -->';
}

/**
 * Is this Flurfunk file in a conversation the person can see?
 *
 * @param int $id      Attachment.
 * @param int $user_id Person.
 */
function in_visible_thread( int $id, int $user_id ): bool {
	global $wpdb;
	$like = array( '%' . $wpdb->esc_like( '"id":' . $id . ',' ) . '%', '%' . $wpdb->esc_like( '"id":' . $id . '}' ) . '%' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own tables.
	$threads = array_merge(
		(array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE files LIKE %s OR files LIKE %s LIMIT 20', table( 'threads' ), $like[0], $like[1] ) ),
		(array) $wpdb->get_col( $wpdb->prepare( "SELECT thread_id FROM %i WHERE type = 'message' AND ( files LIKE %s OR files LIKE %s ) LIMIT 20", table( 'messages' ), $like[0], $like[1] ) )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	foreach ( array_unique( array_map( 'intval', $threads ) ) as $tid ) {
		$t = \Flurfunk\Store\thread_row( $tid );
		if ( $t && Access\can_see( $t, $user_id ) ) {
			return true;
		}
	}
	return false;
}

/**
 * May the current person put this image into a post?
 *
 * @param int $id Attachment.
 * @return true|\WP_Error
 */
function check_image( int $id ) {
	$uid = get_current_user_id();
	if ( ! wp_attachment_is_image( $id ) ) {
		/* translators: %d: ID */
		return new \WP_Error( 'flurfunk_image', sprintf( __( 'File %d is not an image.', 'flurfunk' ), $id ), array( 'status' => 400 ) );
	}
	if ( ! current_user_can( 'upload_files' ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'Adding images to posts needs the right to upload files.', 'flurfunk' ), array( 'status' => 403 ) );
	}
	$ok = Files\is_hidden( $id ) ? in_visible_thread( $id, $uid ) : current_user_can( 'read_post', $id );
	/* translators: %d: ID */
	return $ok ? true : new \WP_Error( 'flurfunk_forbidden', sprintf( __( 'Image %d does not exist or you may not use it.', 'flurfunk' ), $id ), array( 'status' => 404 ) );
}

/**
 * The images of an input (featured first), checked; Flurfunk files move into the media library.
 *
 * @param array $input   Ability input.
 * @param int   $post_id The draft (0 = only check).
 * @return int[]|\WP_Error Images for the text.
 */
function images( array $input, int $post_id = 0 ) {
	$featured = (int) ( $input['featured_image'] ?? 0 );
	$inline   = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['images'] ?? array() ) ) ) ) );
	foreach ( array_filter( array_unique( array_merge( array( $featured ), $inline ) ) ) as $id ) {
		$ok = check_image( $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( $post_id ) {
			delete_post_meta( $id, Files\META ); // Into the media library; the address stays.
			if ( ! get_post( $id )->post_parent ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_parent' => $post_id,
					)
				);
			}
		}
	}
	if ( $post_id && $featured ) {
		set_post_thumbnail( $post_id, $featured );
	}
	return $inline;
}

/**
 * Permission: may the current person create a draft of this type (and add images, if any)?
 *
 * @param array|null $input Ability input.
 */
function may_create( $input = null ): bool {
	$input = (array) $input;
	$type  = get_post_type_object( (string) ( $input['type'] ?? 'post' ) );
	return is_user_logged_in() && Access\can_use()
		&& $type && in_array( $type->name, types(), true ) && current_user_can( $type->cap->create_posts )
		&& ( empty( $input['images'] ) && empty( $input['featured_image'] ) || current_user_can( 'upload_files' ) );
}

/**
 * Permission: may the current person edit this post (and add images, if any)?
 *
 * @param array|null $input Ability input.
 */
function may_update( $input = null ): bool {
	$input = (array) $input;
	$id    = (int) ( $input['id'] ?? 0 );
	return is_user_logged_in() && Access\can_use() && $id && current_user_can( 'edit_post', $id )
		&& ( empty( $input['images'] ) && empty( $input['featured_image'] ) || current_user_can( 'upload_files' ) );
}

/**
 * Remember that Flurfunk saved it now (so a later change by someone else shows).
 *
 * @param int $id Post.
 */
function stamp( int $id ): void {
	clean_post_cache( $id );
	update_post_meta( $id, SAVED, (string) get_post( $id )->post_modified_gmt );
}

/**
 * What a draft looks like in an ability's answer.
 *
 * @param int $id Post.
 */
function result( int $id ): array {
	$p = get_post( $id );
	return array(
		'id'       => $id,
		'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		'status'   => (string) $p->post_status,
		'type'     => (string) $p->post_type,
		'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
		'image'    => (int) get_post_thumbnail_id( $id ),
	);
}

/**
 * Create a draft.
 *
 * @param array $input { title, text, excerpt?, type?, featured_image?, images? }.
 * @return array|\WP_Error
 */
function create( array $input ) {
	$check = images( $input );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$text = content( (string) ( $input['text'] ?? '' ) );
	$id   = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => (string) ( $input['type'] ?? 'post' ),
				'post_status'  => 'draft', // Never more: publishing is for people.
				'post_author'  => get_current_user_id(),
				'post_title'   => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
				'post_content' => $text,
				'post_excerpt' => sanitize_textarea_field( (string) ( $input['excerpt'] ?? '' ) ),
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$inline = images( $input, (int) $id );
	if ( $inline && ! is_wp_error( $inline ) ) {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $text . "\n\n" . implode( "\n\n", array_map( __NAMESPACE__ . '\\image_block', $inline ) ),
				)
			)
		);
	}
	stamp( (int) $id );
	/**
	 * Flurfunk made a draft (e.g. for an AI assistant).
	 *
	 * @param int   $id    Post.
	 * @param array $input What it was made from.
	 */
	do_action( 'flurfunk_draft_saved', (int) $id, $input );
	return result( (int) $id );
}

/**
 * Change a draft Flurfunk made, if nobody else saved it since.
 *
 * @param array $input { id, title?, text?, excerpt?, featured_image?, images? }.
 * @return array|\WP_Error
 */
function update( array $input ) {
	$id = (int) ( $input['id'] ?? 0 );
	$p  = get_post( $id );
	if ( ! $p || ! in_array( $p->post_type, types(), true ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This post does not exist or you may not see it.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( ! in_array( $p->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
		return new \WP_Error( 'flurfunk_draft_published', __( 'This post is no longer a draft. Changes to it need a person.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	$saved = (string) get_post_meta( $id, SAVED, true );
	if ( '' === $saved || $saved !== (string) $p->post_modified_gmt ) {
		return new \WP_Error( 'flurfunk_draft_touched', __( 'Someone has worked on this draft since it was written here (or it was not written here). It is not changed on its own any more.', 'flurfunk' ), array( 'status' => 409 ) );
	}
	$inline = images( $input, $id );
	if ( is_wp_error( $inline ) ) {
		return $inline;
	}
	$data = array( 'ID' => $id );
	if ( isset( $input['title'] ) ) {
		$data['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['text'] ) ) {
		$data['post_content'] = content( (string) $input['text'] );
	}
	if ( isset( $input['excerpt'] ) ) {
		$data['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
	}
	if ( $inline ) {
		$data['post_content'] = ( $data['post_content'] ?? (string) $p->post_content ) . "\n\n" . implode( "\n\n", array_map( __NAMESPACE__ . '\\image_block', $inline ) );
	}
	$done = wp_update_post( wp_slash( $data ), true );
	if ( is_wp_error( $done ) ) {
		return $done;
	}
	stamp( $id );
	do_action( 'flurfunk_draft_saved', $id, $input );
	return result( $id );
}

/**
 * Register the abilities.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$public = (bool) apply_filters( 'flurfunk_abilities_public_write', false );
	$images = array(
		'featured_image' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of an image (e.g. a file from the conversation) to use as the featured image.', 'flurfunk' ),
		),
		'images'         => array(
			'type'        => 'array',
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'maxItems'    => 10,
			'description' => __( 'IDs of images (e.g. files from the conversation) to place at the end of the text.', 'flurfunk' ),
		),
	);
	$text   = array(
		'type'        => 'string',
		'description' => __( 'The text: paragraphs separated by a blank line; "- " for lists, "## " for headings, *bold*. Or, for a page with a layout, WordPress block markup of core blocks (group, columns, cover, buttons, heading, paragraph, image, separator); it is filtered like anyone\'s without "unfiltered_html".', 'flurfunk' ),
	);
	wp_register_ability(
		'flurfunk/create-draft',
		array(
			'label'               => __( 'Write a draft', 'flurfunk' ),
			'description'         => __( 'Creates a draft (never published) with the current user as its author. Images from Flurfunk conversations can go along.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 300,
					),
					'text'    => $text,
					'excerpt' => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'type'    => array(
						'type'    => 'string',
						'enum'    => types() ?: array( 'post' ),
						'default' => 'post',
					),
				) + $images,
				'required'             => array( 'title', 'text' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => create( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_create',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'public'      => $public,
			),
		)
	);
	wp_register_ability(
		'flurfunk/update-draft',
		array(
			'label'               => __( 'Change a draft written here', 'flurfunk' ),
			'description'         => __( 'Changes a draft made with flurfunk/create-draft, as long as nobody else has saved it since: title, text (replaces it), excerpt, images. Never publishes.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'title'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 300,
					),
					'text'    => $text,
					'excerpt' => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
				) + $images,
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => update( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may_update',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'      => $public,
			),
		)
	);
}

<?php
/**
 * Changes to existing posts, as one WordPress ability: flurfunk/update-posts corrects text (exact
 * replacements, only in text – never in markup), and sets titles, excerpts, categories, tags and the
 * featured image, for several posts at once. An AI assistant only proposes it: one card with before
 * and after per post, a person picks the rows and applies them with their own rights (within the
 * assistant's limit), and can undo it afterwards.
 *
 * Safe by design: a post someone changed since the proposal is skipped (unchanged_since), and a post
 * with markup that saving would lose for this person (e.g. an embed only someone with unfiltered
 * HTML may save) is left for a person to change by hand. Undo restores the revision from before and
 * the old terms and featured image – unless someone changed the post again in between.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Changes;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const NAME      = 'flurfunk/update-posts';
const MAX_POSTS = 20;

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * The changes of an input, one entry per post (at most MAX_POSTS).
 *
 * @param array $input Ability input.
 * @return array[]
 */
function entries( array $input ): array {
	$out = array();
	foreach ( array_slice( (array) ( $input['posts'] ?? array() ), 0, MAX_POSTS ) as $e ) {
		$e = (array) $e;
		if ( ! empty( $e['id'] ) ) {
			$out[] = $e;
		}
	}
	return $out;
}

/**
 * Text replacements in a post's content: only in text between tags, never in markup or block
 * comments; the new text is escaped. Every occurrence is replaced.
 *
 * @param string $content Post content.
 * @param array  $pairs   [ { find, with } ].
 * @return string|\WP_Error The new content, or which text was not found.
 */
function replace( string $content, array $pairs ) {
	$parts = (array) preg_split( '/(<!--.*?-->|<[^>]*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	foreach ( $pairs as $pair ) {
		$find = (string) ( $pair['find'] ?? '' );
		$with = htmlspecialchars( (string) ( $pair['with'] ?? '' ), ENT_NOQUOTES, 'UTF-8', false );
		if ( '' === trim( $find ) ) {
			continue;
		}
		$hit = false;
		foreach ( array_unique( array( $find, htmlspecialchars( $find, ENT_NOQUOTES, 'UTF-8', false ) ) ) as $needle ) {
			foreach ( $parts as $i => $part ) {
				if ( 1 === $i % 2 ) {
					continue; // Markup.
				}
				if ( str_contains( (string) $part, $needle ) ) {
					$parts[ $i ] = str_replace( $needle, $with, (string) $part );
					$hit         = true;
				}
			}
			if ( $hit ) {
				break;
			}
		}
		if ( ! $hit ) {
			/* translators: %s: text */
			return new \WP_Error( 'flurfunk_change_text', sprintf( __( 'The text "%s" is not in the post (quote it exactly as it is written, within one paragraph).', 'flurfunk' ), mb_substr( $find, 0, 80 ) ), array( 'status' => 400 ) );
		}
	}
	return implode( '', $parts );
}

/**
 * Term names as IDs (existing terms; new ones only for people who may manage the taxonomy).
 *
 * @param string   $taxonomy category or post_tag.
 * @param string[] $names    Names (or slugs).
 * @param bool     $create   Create missing ones.
 * @return int[]|\WP_Error
 */
function term_ids( string $taxonomy, array $names, bool $create ) {
	$ids = array();
	foreach ( array_filter( array_map( 'trim', array_map( 'strval', $names ) ) ) as $name ) {
		$term = get_term_by( 'name', $name, $taxonomy ) ?: get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		if ( ! $term && $create ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
				/* translators: %s: name */
				return new \WP_Error( 'flurfunk_change_term', sprintf( __( '"%s" does not exist yet, and you may not create it.', 'flurfunk' ), $name ), array( 'status' => 403 ) );
			}
			$made = wp_insert_term( sanitize_text_field( $name ), $taxonomy );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$ids[] = (int) $made['term_id'];
			continue;
		}
		if ( ! $term ) {
			/* translators: %s: name */
			return new \WP_Error( 'flurfunk_change_term', sprintf( __( '"%s" does not exist.', 'flurfunk' ), $name ), array( 'status' => 400 ) );
		}
		$ids[] = (int) $term->term_id;
	}
	return $ids;
}

/**
 * What one entry would change: rows of { field, label, before, after, find?, with?, context? }.
 * Checks the entry (post, texts, terms) without changing anything.
 *
 * @param array $e Entry.
 * @return array|\WP_Error
 */
function plan( array $e ) {
	$p = get_post( (int) $e['id'] );
	if ( ! $p || ! in_array( $p->post_type, (array) \Flurfunk\Settings\get()['post_types'], true ) ) {
		/* translators: %d: ID */
		return new \WP_Error( 'flurfunk_forbidden', sprintf( __( 'Post %d does not exist or you may not see it.', 'flurfunk' ), (int) $e['id'] ), array( 'status' => 404 ) );
	}
	$rows = array();
	if ( isset( $e['title'] ) && trim( (string) $e['title'] ) !== $p->post_title ) {
		$rows[] = array(
			'field'  => 'title',
			'label'  => __( 'Title', 'flurfunk' ),
			'before' => html_entity_decode( (string) $p->post_title, ENT_QUOTES, 'UTF-8' ),
			'after'  => sanitize_text_field( (string) $e['title'] ),
		);
	}
	if ( isset( $e['excerpt'] ) && trim( (string) $e['excerpt'] ) !== $p->post_excerpt ) {
		$rows[] = array(
			'field'  => 'excerpt',
			'label'  => __( 'Excerpt', 'flurfunk' ),
			'before' => (string) $p->post_excerpt,
			'after'  => sanitize_textarea_field( (string) $e['excerpt'] ),
		);
	}
	$pairs = array_values( array_filter( array_map( static fn ( $r ) => (array) $r, (array) ( $e['replace'] ?? array() ) ), static fn ( $r ) => '' !== trim( (string) ( $r['find'] ?? '' ) ) ) );
	if ( $pairs ) {
		$new = replace( (string) $p->post_content, $pairs );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$plain = \Flurfunk\Abilities\plain_text( $p );
		foreach ( $pairs as $pair ) {
			$find = (string) $pair['find'];
			$at   = mb_strpos( $plain, $find );
			$rows[] = array(
				'field'  => 'text',
				'label'  => __( 'Text', 'flurfunk' ),
				'before' => false === $at ? '' : ( $at > 40 ? '…' : '' ) . mb_substr( $plain, max( 0, $at - 40 ), min( 40, $at ) ),
				'find'   => $find,
				'with'   => (string) ( $pair['with'] ?? '' ),
				'after'  => false === $at ? '' : mb_substr( $plain, $at + mb_strlen( $find ), 40 ) . ( mb_strlen( $plain ) > $at + mb_strlen( $find ) + 40 ? '…' : '' ),
			);
		}
	}
	foreach ( array( 'category' => __( 'Categories', 'flurfunk' ), 'post_tag' => __( 'Tags', 'flurfunk' ) ) as $tax => $label ) {
		$key  = 'category' === $tax ? 'categories' : 'tags';
		$add  = (array) ( $e[ 'add_' . $key ] ?? array() );
		$drop = (array) ( $e[ 'remove_' . $key ] ?? array() );
		if ( ! $add && ! $drop ) {
			continue;
		}
		if ( ! is_object_in_taxonomy( $p->post_type, $tax ) ) {
			continue;
		}
		$now  = wp_get_post_terms( $p->ID, $tax, array( 'fields' => 'names' ) );
		$now  = is_wp_error( $now ) ? array() : $now;
		$plus = array_values( array_diff( array_map( 'trim', array_map( 'strval', $add ) ), $now ) );
		$out  = array_values( array_diff( $now, array_map( 'trim', array_map( 'strval', $drop ) ) ) );
		if ( $plus || count( $out ) !== count( $now ) ) {
			$rows[] = array(
				'field'  => $tax,
				'label'  => $label,
				'before' => implode( ', ', $now ),
				'after'  => implode( ', ', array_merge( $out, $plus ) ),
			);
		}
	}
	if ( ! empty( $e['featured_image'] ) && (int) $e['featured_image'] !== (int) get_post_thumbnail_id( $p ) ) {
		$old    = (int) get_post_thumbnail_id( $p );
		$rows[] = array(
			'field'  => 'image',
			'label'  => __( 'Featured image', 'flurfunk' ),
			'before' => $old ? html_entity_decode( get_the_title( $old ), ENT_QUOTES, 'UTF-8' ) : '–',
			'after'  => html_entity_decode( get_the_title( (int) $e['featured_image'] ), ENT_QUOTES, 'UTF-8' ),
		);
	}
	return $rows;
}

/**
 * Check an input before it is proposed: every post exists, every text is found, something changes.
 *
 * @param array $input Ability input.
 * @return true|\WP_Error
 */
function validate( array $input ) {
	$entries = entries( $input );
	if ( ! $entries ) {
		return new \WP_Error( 'flurfunk_change_empty', __( 'Nothing to change.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	foreach ( $entries as $e ) {
		$rows = plan( $e );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! $rows ) {
			/* translators: %d: ID */
			return new \WP_Error( 'flurfunk_change_empty', sprintf( __( 'Nothing would change in post %d.', 'flurfunk' ), (int) $e['id'] ), array( 'status' => 400 ) );
		}
	}
	return true;
}

/**
 * Permission: may the current person edit every post (and set images, if any)?
 *
 * @param array|null $input Ability input.
 */
function may( $input = null ): bool {
	$entries = entries( (array) $input );
	if ( ! $entries || ! is_user_logged_in() || ! Access\can_use() ) {
		return false;
	}
	foreach ( $entries as $e ) {
		if ( ! current_user_can( 'edit_post', (int) $e['id'] ) || ( ! empty( $e['featured_image'] ) && ! current_user_can( 'upload_files' ) ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Apply the changes, post by post. A post changed since unchanged_since, or one whose markup saving
 * would lose, is skipped and says why.
 *
 * @param array $input Ability input.
 * @return array { posts: [ { id, title, status: changed|skipped, message?, undo? } ] }
 */
function apply( array $input ): array {
	$out = array();
	foreach ( entries( $input ) as $e ) {
		$id   = (int) $e['id'];
		$p    = get_post( $id );
		$line = array(
			'id'    => $id,
			'title' => $p ? html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) : '',
		);
		if ( ! $p || ! current_user_can( 'edit_post', $id ) ) {
			$out[] = $line + array(
				'status'  => 'skipped',
				'message' => __( 'You may not edit this post.', 'flurfunk' ),
			);
			continue;
		}
		if ( ! empty( $e['unchanged_since'] ) && (string) $e['unchanged_since'] !== (string) $p->post_modified_gmt ) {
			$out[] = $line + array(
				'status'  => 'skipped',
				'message' => __( 'Someone changed this post in the meantime.', 'flurfunk' ),
			);
			continue;
		}
		$data = array( 'ID' => $id );
		if ( isset( $e['title'] ) ) {
			$data['post_title'] = sanitize_text_field( (string) $e['title'] );
		}
		if ( isset( $e['excerpt'] ) ) {
			$data['post_excerpt'] = sanitize_textarea_field( (string) $e['excerpt'] );
		}
		if ( ! empty( $e['replace'] ) ) {
			$new = replace( (string) $p->post_content, array_map( static fn ( $r ) => (array) $r, (array) $e['replace'] ) );
			if ( is_wp_error( $new ) ) {
				$out[] = $line + array(
					'status'  => 'skipped',
					'message' => $new->get_error_message(),
				);
				continue;
			}
			// Saving goes through kses for people without unfiltered HTML: if that would lose
			// markup of this post (an embed, a script), a person has to change it by hand.
			if ( ! current_user_can( 'unfiltered_html' ) && wp_unslash( wp_filter_post_kses( wp_slash( $new ) ) ) !== $new ) {
				$out[] = $line + array(
					'status'  => 'skipped',
					'message' => __( 'This post contains markup that would be lost when saving here. Please change it by hand.', 'flurfunk' ),
				);
				continue;
			}
			$data['post_content'] = $new;
		}
		$terms = array();
		foreach ( array( 'category' => 'categories', 'post_tag' => 'tags' ) as $tax => $key ) {
			$add  = (array) ( $e[ 'add_' . $key ] ?? array() );
			$drop = (array) ( $e[ 'remove_' . $key ] ?? array() );
			if ( ( ! $add && ! $drop ) || ! is_object_in_taxonomy( $p->post_type, $tax ) ) {
				continue;
			}
			$plus = term_ids( $tax, $add, true );
			$less = term_ids( $tax, $drop, false );
			if ( is_wp_error( $plus ) || is_wp_error( $less ) ) {
				$out[] = $line + array(
					'status'  => 'skipped',
					'message' => ( is_wp_error( $plus ) ? $plus : $less )->get_error_message(),
				);
				continue 2;
			}
			if ( ! current_user_can( get_taxonomy( $tax )->cap->assign_terms ) ) {
				$out[] = $line + array(
					'status'  => 'skipped',
					'message' => __( 'You may not change its categories or tags.', 'flurfunk' ),
				);
				continue 2;
			}
			$now            = wp_get_post_terms( $id, $tax, array( 'fields' => 'ids' ) );
			$now            = is_wp_error( $now ) ? array() : array_map( 'intval', $now );
			$terms[ $tax ]  = array(
				'before' => $now,
				'after'  => array_values( array_unique( array_merge( array_diff( $now, $less ), $plus ) ) ),
			);
		}
		$image = (int) ( $e['featured_image'] ?? 0 );
		if ( $image ) {
			$ok = \Flurfunk\Drafts\check_image( $image );
			if ( is_wp_error( $ok ) ) {
				$out[] = $line + array(
					'status'  => 'skipped',
					'message' => $ok->get_error_message(),
				);
				continue;
			}
		}
		// Undo: the revision of the state before (WordPress keeps it), the old terms and image.
		$rev = wp_save_post_revision( $id );
		if ( ! $rev ) {
			$revs = wp_get_post_revisions(
				$id,
				array(
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			$rev  = (int) ( $revs ? reset( $revs ) : 0 );
		}
		$undo = array(
			'revision' => (int) $rev,
			'terms'    => array_map( static fn ( $t ) => $t['before'], $terms ),
			'image'    => (int) get_post_thumbnail_id( $id ),
			'texts'    => wp_revisions_enabled( $p ) ? null : array( $p->post_title, $p->post_excerpt, $p->post_content ),
		);
		if ( count( $data ) > 1 ) {
			$done = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $done ) ) {
				$out[] = $line + array(
					'status'  => 'skipped',
					'message' => $done->get_error_message(),
				);
				continue;
			}
		}
		foreach ( $terms as $tax => $t ) {
			wp_set_post_terms( $id, $t['after'], $tax );
		}
		if ( $image ) {
			\Flurfunk\Drafts\images( array( 'featured_image' => $image ), $id );
		}
		clean_post_cache( $id );
		$undo['after'] = (string) get_post( $id )->post_modified_gmt;
		$out[]         = array(
			'id'     => $id,
			'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'status' => 'changed',
			'undo'   => $undo,
		);
	}
	return array( 'posts' => $out );
}

/**
 * Undo applied changes, post by post – unless someone changed a post again since.
 *
 * @param array $posts What apply() returned (its posts).
 * @return array { posts: [ { id, title, status: undone|skipped, message? } ] }
 */
function undo( array $posts ): array {
	$out = array();
	foreach ( $posts as $r ) {
		if ( 'changed' !== ( $r['status'] ?? '' ) || empty( $r['undo'] ) ) {
			continue;
		}
		$id   = (int) $r['id'];
		$p    = get_post( $id );
		$u    = (array) $r['undo'];
		$line = array(
			'id'    => $id,
			'title' => (string) ( $r['title'] ?? '' ),
		);
		if ( ! $p || ! current_user_can( 'edit_post', $id ) ) {
			$out[] = $line + array(
				'status'  => 'skipped',
				'message' => __( 'You may not edit this post.', 'flurfunk' ),
			);
			continue;
		}
		if ( (string) ( $u['after'] ?? '' ) !== (string) $p->post_modified_gmt ) {
			$out[] = $line + array(
				'status'  => 'skipped',
				'message' => __( 'Someone changed this post in the meantime.', 'flurfunk' ),
			);
			continue;
		}
		$rev = (int) ( $u['revision'] ?? 0 );
		if ( $rev && wp_get_post_revision( $rev ) ) {
			wp_restore_post_revision( $rev );
		} elseif ( is_array( $u['texts'] ?? null ) ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_title'   => (string) $u['texts'][0],
						'post_excerpt' => (string) $u['texts'][1],
						'post_content' => (string) $u['texts'][2],
					)
				)
			);
		}
		foreach ( (array) ( $u['terms'] ?? array() ) as $tax => $ids ) {
			wp_set_post_terms( $id, array_map( 'intval', (array) $ids ), (string) $tax );
		}
		if ( (int) get_post_thumbnail_id( $id ) !== (int) ( $u['image'] ?? 0 ) ) {
			empty( $u['image'] ) ? delete_post_thumbnail( $id ) : set_post_thumbnail( $id, (int) $u['image'] );
		}
		$out[] = $line + array( 'status' => 'undone' );
	}
	return array( 'posts' => $out );
}

/**
 * Register the ability.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$names = array(
		'type'  => 'array',
		'items' => array( 'type' => 'string' ),
	);
	wp_register_ability(
		NAME,
		array(
			'label'               => __( 'Change posts', 'flurfunk' ),
			'description'         => __( 'Changes existing posts, several at once: corrections in the text (exact replacements, only in text), title, excerpt, categories, tags, featured image. A post changed since unchanged_since is skipped, as is one whose markup saving would lose.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'posts' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => MAX_POSTS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'                => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'title'             => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 300,
								),
								'excerpt'           => array(
									'type'      => 'string',
									'maxLength' => 1000,
								),
								'replace'           => array(
									'type'        => 'array',
									'maxItems'    => 30,
									'description' => __( 'Corrections in the text: find (exactly as written in the post, within one paragraph) and with (the new text).', 'flurfunk' ),
									'items'       => array(
										'type'                 => 'object',
										'properties'           => array(
											'find' => array(
												'type'      => 'string',
												'minLength' => 1,
												'maxLength' => 300,
											),
											'with' => array(
												'type'      => 'string',
												'maxLength' => 600,
											),
										),
										'required'             => array( 'find', 'with' ),
										'additionalProperties' => false,
									),
								),
								'add_categories'    => $names,
								'remove_categories' => $names,
								'add_tags'          => $names,
								'remove_tags'       => $names,
								'featured_image'    => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'unchanged_since'   => array(
									'type'        => 'string',
									'description' => __( 'Skip the post if it was modified since this time (post_modified_gmt).', 'flurfunk' ),
								),
							),
							'required'             => array( 'id' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'posts' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => apply( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'      => (bool) apply_filters( 'flurfunk_abilities_public_write', false ),
			),
		)
	);
}

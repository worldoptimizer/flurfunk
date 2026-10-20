<?php
/**
 * Reading the code of plugins and themes – for an administrator who wants to know how something
 * works or how to extend a plugin, through abilities an AI assistant (or any client) can use:
 * list the plugins and themes or the files of one, search in them, read a file.
 *
 * Read-only, and only inside the plugins and themes folders (not must-use plugins). Files that
 * usually hold secrets are never read (wp-config, .env, keys, certificates, database dumps, version
 * control), and what looks like a password, token or key in a line is shown as [hidden]. Plugins
 * need "activate_plugins", themes "switch_themes" – administrators.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Code;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const TEXT      = array( 'php', 'js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'css', 'scss', 'sass', 'less', 'json', 'html', 'htm', 'twig', 'md', 'txt', 'xml', 'yml', 'yaml', 'svg', 'pot', 'po', 'ini', 'neon', 'dist' );
const MAX_FILES = 4000;   // Files looked at per search.
const MAX_BYTES = 400000; // Per file searched or read.

add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register' );

/**
 * The folder of a plugin or theme, if the current person may read it ('' if not).
 *
 * @param string $kind 'plugin' or 'theme'.
 * @param string $slug Folder name.
 */
function root( string $kind, string $slug ): string {
	$slug = sanitize_file_name( $slug );
	if ( '' === $slug ) {
		return '';
	}
	if ( 'theme' === $kind ) {
		$base = get_theme_root();
		$cap  = 'switch_themes';
	} else {
		$base = WP_PLUGIN_DIR;
		$cap  = 'activate_plugins';
	}
	$dir  = realpath( $base . '/' . $slug );
	$base = realpath( $base );
	if ( ! $dir || ! $base || ! is_dir( $dir ) || ! str_starts_with( $dir . '/', $base . '/' ) || ! current_user_can( $cap ) ) {
		return '';
	}
	return $dir;
}

/**
 * Is this a file that may be read (text, not a secret, inside the folder)?
 *
 * @param string $file Absolute path.
 * @param string $root Folder.
 */
function readable( string $file, string $root ): bool {
	$real = realpath( $file );
	if ( ! $real || ! is_file( $real ) || ! str_starts_with( $real, $root . '/' ) || filesize( $real ) > MAX_BYTES ) {
		return false;
	}
	$rel  = strtolower( substr( $real, strlen( $root ) + 1 ) );
	$name = basename( $rel );
	if ( preg_match( '#(^|/)(\.git|\.svn|\.hg)/#', $rel ) || preg_match( '/^(wp-config|\.env|\.htpasswd|auth\.json|id_rsa|id_dsa|id_ed25519)/', $name ) || preg_match( '/\.(pem|key|crt|p12|pfx|sql|sqlite|db|bak|log)$/', $name ) || preg_match( '/(secret|credential|password)/', $name ) ) {
		return false;
	}
	$ext = pathinfo( $name, PATHINFO_EXTENSION );
	return in_array( $ext, TEXT, true ) || in_array( $name, array( 'license', 'readme', 'changelog' ), true );
}

/**
 * A line with what looks like a secret value hidden.
 *
 * @param string $line Line.
 */
function hide( string $line ): string {
	return (string) preg_replace( '/((?:api[_-]?key|secret|password|passwd|token|auth[_-]?key|private[_-]?key|salt)[\'"]?\s*(?:=>|=|:|,)\s*[\'"])[^\'"\s]{6,}([\'"])/i', '$1[hidden]$2', $line );
}

/**
 * The files of a folder (relative paths), skipping vendor folders unless asked.
 *
 * @param string $root    Folder.
 * @param bool   $vendor  Include vendor/ and node_modules/.
 * @param string $sub     Subfolder to start in.
 * @return string[]
 */
function files( string $root, bool $vendor = false, string $sub = '' ): array {
	$start = realpath( $root . '/' . ltrim( $sub, '/' ) );
	if ( ! $start || ! str_starts_with( $start . '/', $root . '/' ) || ! is_dir( $start ) ) {
		return array();
	}
	$out = array();
	$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $start, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$rel = substr( (string) $f, strlen( $root ) + 1 );
		if ( ! $vendor && preg_match( '#(^|/)(vendor|node_modules)/#', $rel ) ) {
			continue;
		}
		if ( readable( (string) $f, $root ) ) {
			$out[] = $rel;
		}
		if ( count( $out ) >= MAX_FILES ) {
			break;
		}
	}
	sort( $out );
	return $out;
}

/**
 * Plugins and themes, or the files of one.
 *
 * @param array $input { kind, slug, folder, vendor }.
 * @return array|\WP_Error
 */
function list_code( array $input ) {
	$kind = 'theme' === ( $input['kind'] ?? '' ) ? 'theme' : 'plugin';
	if ( empty( $input['slug'] ) ) {
		$out = array();
		if ( current_user_can( 'activate_plugins' ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $p ) {
				$out[] = array(
					'kind'    => 'plugin',
					'slug'    => dirname( $file ) !== '.' ? dirname( $file ) : basename( $file, '.php' ),
					'name'    => $p['Name'],
					'version' => $p['Version'],
					'active'  => is_plugin_active( $file ),
				);
			}
		}
		if ( current_user_can( 'switch_themes' ) ) {
			foreach ( wp_get_themes() as $slug => $t ) {
				$out[] = array(
					'kind'    => 'theme',
					'slug'    => $slug,
					'name'    => $t->get( 'Name' ),
					'version' => $t->get( 'Version' ),
					'active'  => get_stylesheet() === $slug,
				);
			}
		}
		return $out;
	}
	$root = root( $kind, (string) $input['slug'] );
	if ( '' === $root ) {
		return new \WP_Error( 'flurfunk_code', __( 'This plugin or theme does not exist, or you may not read its code.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$list = files( $root, ! empty( $input['vendor'] ), (string) ( $input['folder'] ?? '' ) );
	return array(
		'files' => array_slice( $list, 0, 400 ),
		'more'  => max( 0, count( $list ) - 400 ),
	);
}

/**
 * Search in the code of a plugin or theme.
 *
 * @param array $input { kind, slug, query, regex, vendor }.
 * @return array|\WP_Error
 */
function search( array $input ) {
	$kind  = 'theme' === ( $input['kind'] ?? '' ) ? 'theme' : 'plugin';
	$root  = root( $kind, (string) ( $input['slug'] ?? '' ) );
	$query = (string) ( $input['query'] ?? '' );
	if ( '' === $root ) {
		return new \WP_Error( 'flurfunk_code', __( 'This plugin or theme does not exist, or you may not read its code.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	if ( '' === trim( $query ) ) {
		return new \WP_Error( 'flurfunk_code', __( 'Say what to look for.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$pattern = ! empty( $input['regex'] ) ? '~' . str_replace( '~', '\~', $query ) . '~i' : '~' . preg_quote( $query, '~' ) . '~i';
	if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- only checks the pattern.
		return new \WP_Error( 'flurfunk_code', __( 'That pattern does not work.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$hits = array();
	foreach ( files( $root, ! empty( $input['vendor'] ) ) as $rel ) {
		$lines = file( $root . '/' . $rel, FILE_IGNORE_NEW_LINES );
		foreach ( (array) $lines as $i => $line ) {
			if ( preg_match( $pattern, (string) $line ) ) {
				$hits[] = array(
					'file' => $rel,
					'line' => $i + 1,
					'text' => mb_substr( hide( trim( (string) $line ) ), 0, 240 ),
				);
				if ( count( $hits ) >= 60 ) {
					return array(
						'hits' => $hits,
						'more' => true,
					);
				}
			}
		}
	}
	return array(
		'hits' => $hits,
		'more' => false,
	);
}

/**
 * Read a file of a plugin or theme (some lines of it).
 *
 * @param array $input { kind, slug, file, from, to }.
 * @return array|\WP_Error
 */
function read( array $input ) {
	$kind = 'theme' === ( $input['kind'] ?? '' ) ? 'theme' : 'plugin';
	$root = root( $kind, (string) ( $input['slug'] ?? '' ) );
	$file = $root ? $root . '/' . ltrim( (string) ( $input['file'] ?? '' ), '/' ) : '';
	if ( '' === $root || ! readable( $file, $root ) ) {
		return new \WP_Error( 'flurfunk_code', __( 'This file does not exist, or it may not be read.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$lines = (array) file( realpath( $file ), FILE_IGNORE_NEW_LINES );
	$from  = max( 1, (int) ( $input['from'] ?? 1 ) );
	$to    = min( count( $lines ), max( $from, (int) ( $input['to'] ?? $from + 299 ) ), $from + 399 );
	$out   = array();
	for ( $i = $from; $i <= $to; $i++ ) {
		$out[] = $i . ': ' . hide( (string) $lines[ $i - 1 ] );
	}
	return array(
		'file'  => substr( realpath( $file ), strlen( $root ) + 1 ),
		'lines' => count( $lines ),
		'from'  => $from,
		'to'    => $to,
		'text'  => implode( "\n", $out ),
	);
}

/**
 * May the current person read code at all (plugins or themes)?
 */
function may(): bool {
	return is_user_logged_in() && Access\can_use() && ( current_user_can( 'activate_plugins' ) || current_user_can( 'switch_themes' ) );
}

/**
 * Register the abilities.
 */
function register(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$which = array(
		'kind' => array(
			'type'        => 'string',
			'enum'        => array( 'plugin', 'theme' ),
			'default'     => 'plugin',
			'description' => __( 'A plugin or a theme.', 'flurfunk' ),
		),
		'slug' => array(
			'type'        => 'string',
			'description' => __( 'Its folder name, e.g. "akismet".', 'flurfunk' ),
		),
	);
	$read = array(
		'annotations' => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
		'public'      => false,
	);
	wp_register_ability(
		'flurfunk/list-code',
		array(
			'label'               => __( 'List plugins, themes and their files', 'flurfunk' ),
			'description'         => __( 'Without a slug: the installed plugins and themes (slug, name, version, active). With one: the files of that plugin or theme (without vendor folders unless asked).', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $which + array(
					'folder' => array( 'type' => 'string' ),
					'vendor' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => array( 'object', 'array' ) ),
			'execute_callback'    => static fn ( $input = array() ) => list_code( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => $read,
		)
	);
	wp_register_ability(
		'flurfunk/search-code',
		array(
			'label'               => __( 'Search the code', 'flurfunk' ),
			'description'         => __( 'Searches the files of a plugin or theme for a word or a regular expression (e.g. a hook name, function or class) and returns file, line and the line itself – up to 60 hits.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $which + array(
					'query'  => array( 'type' => 'string' ),
					'regex'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'vendor' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'slug', 'query' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => search( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => $read,
		)
	);
	wp_register_ability(
		'flurfunk/read-code',
		array(
			'label'               => __( 'Read a code file', 'flurfunk' ),
			'description'         => __( 'Some lines of a file of a plugin or theme (up to 400 at a time), with line numbers. Secrets and files that usually hold them are never shown.', 'flurfunk' ),
			'category'            => 'flurfunk',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $which + array(
					'file' => array(
						'type'        => 'string',
						'description' => __( 'Path inside the plugin or theme, e.g. "includes/class-api.php".', 'flurfunk' ),
					),
					'from' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'to'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'slug', 'file' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static fn ( $input = array() ) => read( (array) $input ),
			'permission_callback' => __NAMESPACE__ . '\\may',
			'meta'                => $read,
		)
	);
}

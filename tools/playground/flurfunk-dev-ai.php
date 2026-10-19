<?php
/**
 * Plugin Name: Flurfunk dev: AI through a local proxy
 * Description: Local development only (WordPress Playground). Sends the OpenAI provider's
 * requests to an OpenAI-compatible proxy on this machine (FLURFUNK_DEV_AI_PROXY, e.g.
 * http://localhost:8317/v1), so the AI features can be tried without a paid key. Never use this on
 * a real site.
 *
 * @package Flurfunk
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FLURFUNK_DEV_AI_PROXY' ) || ! FLURFUNK_DEV_AI_PROXY ) {
	return;
}

// The connector counts as set up once a key exists; the proxy does not check it.
if ( ! defined( 'OPENAI_API_KEY' ) ) {
	define( 'OPENAI_API_KEY', 'local-proxy' );
}

add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) {
		$from = 'https://api.openai.com/v1';
		if ( false !== $pre || ! str_starts_with( $url, $from ) ) {
			return $pre;
		}
		$args['timeout']            = max( 120, (int) ( $args['timeout'] ?? 0 ) );
		$args['reject_unsafe_urls'] = false; // The AI client asks for "safe" URLs only; the proxy is on localhost on purpose.
		return wp_remote_request( rtrim( FLURFUNK_DEV_AI_PROXY, '/' ) . substr( $url, strlen( $from ) ), $args );
	},
	10,
	3
);

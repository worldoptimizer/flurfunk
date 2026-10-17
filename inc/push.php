<?php
/**
 * Web Push (RFC 8030/8291/8292) without external libraries: VAPID keys, aes128gcm encryption and
 * delivery to the browsers' push services. Browsers subscribe through the app (service worker in
 * assets/sw.js); notifications arrive even when no Flurfunk window is open.
 *
 * Needs PHP's OpenSSL extension with EC support (standard). Without it, push stays off and the
 * app says so.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Push;

use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

const KEYS = 'flurfunk_vapid';
const P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200'; // SubjectPublicKeyInfo prefix.

/**
 * Can this server send push messages?
 */
function available(): bool {
	return function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_pkey_derive' ) && in_array( 'aes-128-gcm', openssl_get_cipher_methods(), true );
}

/**
 * Base64url.
 *
 * @param string $bin Bytes.
 */
function b64( string $bin ): string {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/**
 * Base64url decode.
 *
 * @param string $s Text.
 */
function unb64( string $s ): string {
	return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/**
 * Raw uncompressed public key (65 bytes) of an EC key.
 *
 * @param \OpenSSLAsymmetricKey $key Key.
 */
function raw_public( $key ): string {
	$d = openssl_pkey_get_details( $key )['ec'];
	return "\x04" . str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['y'], 32, "\0", STR_PAD_LEFT );
}

/**
 * Public key from 65 raw bytes.
 *
 * @param string $raw Uncompressed point.
 * @return \OpenSSLAsymmetricKey|false
 */
function public_from_raw( string $raw ) {
	$der = hex2bin( P256 ) . $raw;
	$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	return openssl_pkey_get_public( $pem );
}

/**
 * New P-256 key pair.
 *
 * @return \OpenSSLAsymmetricKey|false
 */
function new_key() {
	return openssl_pkey_new(
		array(
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		)
	);
}

/**
 * The site's VAPID keys (created on first use).
 *
 * @return array{private:string, public:string}|null private = PEM, public = base64url raw.
 */
function vapid(): ?array {
	if ( ! available() ) {
		return null;
	}
	$k = get_option( KEYS );
	if ( is_array( $k ) && ! empty( $k['private'] ) && ! empty( $k['public'] ) ) {
		return $k;
	}
	$key = new_key();
	if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
		return null;
	}
	$k = array(
		'private' => $pem,
		'public'  => b64( raw_public( $key ) ),
	);
	add_option( KEYS, $k, '', false );
	return get_option( KEYS );
}

/**
 * ECDSA signature (DER) as raw r||s, 64 bytes, as JWT ES256 wants it.
 *
 * @param string $der Signature.
 */
function der_to_raw( string $der ): string {
	$pos = 2;
	if ( 0x81 === ord( $der[1] ) ) {
		$pos = 3;
	}
	$rlen = ord( $der[ $pos + 1 ] );
	$r    = substr( $der, $pos + 2, $rlen );
	$pos  = $pos + 2 + $rlen;
	$slen = ord( $der[ $pos + 1 ] );
	$s    = substr( $der, $pos + 2, $slen );
	$fix  = static fn ( $x ) => str_pad( ltrim( $x, "\0" ), 32, "\0", STR_PAD_LEFT );
	return $fix( $r ) . $fix( $s );
}

/**
 * VAPID Authorization header for a push service.
 *
 * @param string $endpoint Subscription endpoint.
 */
function vapid_header( string $endpoint ): ?string {
	$k = vapid();
	if ( ! $k ) {
		return null;
	}
	$p   = wp_parse_url( $endpoint );
	$jwt = b64( (string) wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) ) . '.' . b64(
		(string) wp_json_encode(
			array(
				'aud' => $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ),
				'exp' => time() + 12 * HOUR_IN_SECONDS,
				'sub' => 'mailto:' . get_option( 'admin_email' ),
			)
		)
	);
	if ( ! openssl_sign( $jwt, $sig, $k['private'], OPENSSL_ALGO_SHA256 ) ) {
		return null;
	}
	return 'vapid t=' . $jwt . '.' . b64( der_to_raw( $sig ) ) . ', k=' . $k['public'];
}

/**
 * HKDF (SHA-256) for the short outputs Web Push needs.
 *
 * @param string $salt Salt.
 * @param string $ikm  Input key material.
 * @param string $info Info.
 * @param int    $len  Length (≤ 32).
 */
function hkdf( string $salt, string $ikm, string $info, int $len ): string {
	$prk = hash_hmac( 'sha256', $ikm, $salt, true );
	return substr( hash_hmac( 'sha256', $info . "\x01", $prk, true ), 0, $len );
}

/**
 * Encrypt a payload for a subscription (aes128gcm, RFC 8291).
 *
 * @param string $plain  Payload.
 * @param string $p256dh Browser public key (base64url).
 * @param string $auth   Browser auth secret (base64url).
 * @return string|null Body to POST.
 */
function encrypt( string $plain, string $p256dh, string $auth ): ?string {
	$ua_public = unb64( $p256dh );
	$secret    = unb64( $auth );
	$peer      = 65 === strlen( $ua_public ) ? public_from_raw( $ua_public ) : false;
	$local     = new_key();
	if ( ! $peer || ! $local || 16 !== strlen( $secret ) ) {
		return null;
	}
	$shared = openssl_pkey_derive( $peer, $local );
	if ( false === $shared ) {
		return null;
	}
	$as_public = raw_public( $local );
	$ikm       = hkdf( $secret, $shared, "WebPush: info\0" . $ua_public . $as_public, 32 );
	$salt      = random_bytes( 16 );
	$cek       = hkdf( $salt, $ikm, "Content-Encoding: aes128gcm\0", 16 );
	$nonce     = hkdf( $salt, $ikm, "Content-Encoding: nonce\0", 12 );
	$cipher    = openssl_encrypt( $plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
	if ( false === $cipher ) {
		return null;
	}
	return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public . $cipher . $tag;
}

/**
 * Store a browser's subscription.
 *
 * @param int    $user_id User.
 * @param array  $sub     { endpoint, keys: { p256dh, auth } }.
 * @param string $label   Device label.
 * @return true|\WP_Error
 */
function subscribe( int $user_id, array $sub, string $label = '' ) {
	global $wpdb;
	$endpoint = esc_url_raw( (string) ( $sub['endpoint'] ?? '' ), array( 'https' ) );
	$p256dh   = (string) ( $sub['keys']['p256dh'] ?? '' );
	$auth     = (string) ( $sub['keys']['auth'] ?? '' );
	if ( '' === $endpoint || 65 !== strlen( unb64( $p256dh ) ) || 16 !== strlen( unb64( $auth ) ) ) {
		return new \WP_Error( 'flurfunk_push', __( 'This browser sent an invalid push subscription.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$hash = hash( 'sha256', $endpoint );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . table( 'push' ) . ' WHERE endpoint_hash = %s', $hash ) ); // phpcs:ignore WordPress.DB
	$wpdb->insert( // phpcs:ignore WordPress.DB
		table( 'push' ),
		array(
			'user_id'       => $user_id,
			'endpoint'      => $endpoint,
			'endpoint_hash' => $hash,
			'p256dh'        => $p256dh,
			'auth'          => $auth,
			'label'         => sanitize_text_field( mb_substr( $label, 0, 190 ) ),
			'created'       => \Flurfunk\Store\now(),
		)
	);
	return true;
}

/**
 * Remove a subscription (or all of a person's).
 *
 * @param int    $user_id  User.
 * @param string $endpoint Endpoint ('' = all).
 */
function unsubscribe( int $user_id, string $endpoint = '' ): void {
	global $wpdb;
	if ( '' === $endpoint ) {
		$wpdb->delete( table( 'push' ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB
		return;
	}
	$wpdb->delete( table( 'push' ), array( 'user_id' => $user_id, 'endpoint_hash' => hash( 'sha256', $endpoint ) ) ); // phpcs:ignore WordPress.DB
}

/**
 * A person's devices.
 *
 * @param int $user_id User.
 * @return object[]
 */
function devices( int $user_id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, endpoint, label, created, last_ok, failures FROM ' . table( 'push' ) . ' WHERE user_id = %d ORDER BY id', $user_id ) ); // phpcs:ignore WordPress.DB
}

/**
 * Send a notification to all of a person's subscribed browsers.
 *
 * @param int   $user_id User.
 * @param array $payload title, body, url, tag, icon.
 * @return bool At least one browser accepted it.
 */
function send( int $user_id, array $payload ): bool {
	global $wpdb;
	if ( ! available() ) {
		return false;
	}
	$subs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . table( 'push' ) . ' WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB
	$ok   = false;
	foreach ( $subs as $s ) {
		$body = encrypt( (string) wp_json_encode( $payload ), $s->p256dh, $s->auth );
		$auth = vapid_header( $s->endpoint );
		if ( null === $body || null === $auth ) {
			continue;
		}
		$res = wp_remote_post(
			$s->endpoint,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
					'TTL'              => (string) DAY_IN_SECONDS,
					'Urgency'          => 'normal',
					'Authorization'    => $auth,
				),
				'body'    => $body,
			)
		);
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			$ok = true;
			$wpdb->update( table( 'push' ), array( 'last_ok' => \Flurfunk\Store\now(), 'failures' => 0 ), array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB
		} elseif ( 404 === $code || 410 === $code || (int) $s->failures >= 4 ) {
			// The browser unsubscribed or keeps failing: forget it.
			$wpdb->delete( table( 'push' ), array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->update( table( 'push' ), array( 'failures' => (int) $s->failures + 1 ), array( 'id' => $s->id ) ); // phpcs:ignore WordPress.DB
		}
	}
	return $ok;
}

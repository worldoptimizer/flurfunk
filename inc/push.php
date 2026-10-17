<?php
/**
 * Web Push (RFC 8030/8291/8292) without external libraries: VAPID keys, aes128gcm encryption and
 * delivery to the browsers' push services. Browsers subscribe through the app (service worker in
 * assets/sw.js); notifications arrive even when no Flurfunk window is open.
 *
 * Needs PHP's OpenSSL extension with EC support (standard). Without it, push stays off and the
 * app says so.
 *
 * Subscriptions are only accepted for the browser vendors' push services (filter
 * flurfunk_push_hosts), so the site never posts to an address a visitor made up.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Push;

use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

// Flurfunk's own table has no WordPress API to go through. Every query below is prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Web Push keys and tokens are base64url (RFC 8292).
}

/**
 * Base64url decode.
 *
 * @param string $s Text.
 */
function unb64( string $s ): string {
	return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- browser keys arrive base64url-encoded (RFC 8291).
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
	$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM is base64 by definition.
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
 * Push services a subscription may point to. "*.example.com" stands for any subdomain.
 * Chrome, Opera, Samsung Internet and Edge on Android use Google's service, Firefox Mozilla's,
 * Safari Apple's and Edge on Windows Microsoft's (WNS).
 *
 * @return string[]
 */
function hosts(): array {
	return array_values(
		array_filter(
			array_map(
				static fn ( $h ) => strtolower( trim( (string) $h ) ),
				(array) apply_filters( 'flurfunk_push_hosts', array( 'fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', '*.push.apple.com', '*.notify.windows.com' ) )
			)
		)
	);
}

/**
 * Is this endpoint on one of the known push services (HTTPS, default port)?
 *
 * @param string $endpoint Subscription endpoint.
 */
function allowed_endpoint( string $endpoint ): bool {
	$p = wp_parse_url( $endpoint );
	if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) {
		return false;
	}
	$host = strtolower( (string) $p['host'] );
	foreach ( hosts() as $pattern ) {
		if ( str_starts_with( $pattern, '*.' ) ? str_ends_with( $host, substr( $pattern, 1 ) ) : $host === $pattern ) {
			return true;
		}
	}
	return false;
}

/**
 * A subscription as the browser sends it, reduced to what we use: an https endpoint and two
 * base64url keys. Anything else is dropped; invalid values become ''.
 *
 * @param mixed $sub { endpoint, keys: { p256dh, auth } }.
 * @return array{endpoint:string, keys:array{p256dh:string, auth:string}}
 */
function clean_subscription( $sub ): array {
	$sub  = is_array( $sub ) ? $sub : array();
	$keys = is_array( $sub['keys'] ?? null ) ? $sub['keys'] : array();
	$b64  = static fn ( $v ): string => is_string( $v ) && strlen( $v ) <= 255 && preg_match( '/^[A-Za-z0-9_-]+={0,2}$/', $v ) ? $v : '';
	$url  = is_string( $sub['endpoint'] ?? null ) && strlen( $sub['endpoint'] ) <= 2048 ? esc_url_raw( $sub['endpoint'], array( 'https' ) ) : '';
	return array(
		'endpoint' => $url,
		'keys'     => array(
			'p256dh' => $b64( $keys['p256dh'] ?? '' ),
			'auth'   => $b64( $keys['auth'] ?? '' ),
		),
	);
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
	$sub      = clean_subscription( $sub );
	$endpoint = $sub['endpoint'];
	$p256dh   = $sub['keys']['p256dh'];
	$auth     = $sub['keys']['auth'];
	if ( '' === $endpoint || 65 !== strlen( unb64( $p256dh ) ) || 16 !== strlen( unb64( $auth ) ) ) {
		return new \WP_Error( 'flurfunk_push', __( 'This browser sent an invalid push subscription.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	if ( ! allowed_endpoint( $endpoint ) ) {
		return new \WP_Error( 'flurfunk_push_host', __( 'This browser uses a push service Flurfunk does not know.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$hash = hash( 'sha256', $endpoint );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE endpoint_hash = %s', table( 'push' ), $hash ) );
	$wpdb->insert(
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
		$wpdb->delete( table( 'push' ), array( 'user_id' => $user_id ) );
		return;
	}
	$wpdb->delete( table( 'push' ), array( 'user_id' => $user_id, 'endpoint_hash' => hash( 'sha256', $endpoint ) ) );
}

/**
 * A person's devices.
 *
 * @param int $user_id User.
 * @return object[]
 */
function devices( int $user_id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, endpoint, label, created, last_ok, failures FROM %i WHERE user_id = %d ORDER BY id', table( 'push' ), $user_id ) );
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
	$subs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', table( 'push' ), $user_id ) );
	$ok   = false;
	foreach ( $subs as $s ) {
		// Stored before the host check existed, or the site narrowed the list: don't send there.
		if ( ! allowed_endpoint( (string) $s->endpoint ) ) {
			continue;
		}
		$body = encrypt( (string) wp_json_encode( $payload ), $s->p256dh, $s->auth );
		$auth = vapid_header( $s->endpoint );
		if ( null === $body || null === $auth ) {
			continue;
		}
		$res = wp_safe_remote_post(
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
			$wpdb->update( table( 'push' ), array( 'last_ok' => \Flurfunk\Store\now(), 'failures' => 0 ), array( 'id' => $s->id ) );
		} elseif ( 404 === $code || 410 === $code || (int) $s->failures >= 4 ) {
			// The browser unsubscribed or keeps failing: forget it.
			$wpdb->delete( table( 'push' ), array( 'id' => $s->id ) );
		} else {
			$wpdb->update( table( 'push' ), array( 'failures' => (int) $s->failures + 1 ), array( 'id' => $s->id ) );
		}
	}
	return $ok;
}

/**
 * Replace a subscription the browser renewed by itself (service worker, no login cookie). The old
 * endpoint is an unguessable address only that browser knew, so it identifies the person.
 *
 * @param string $old_endpoint Previous endpoint.
 * @param array  $sub          New subscription.
 * @return bool
 */
function renew( string $old_endpoint, array $sub ): bool {
	global $wpdb;
	$sub = clean_subscription( $sub );
	if ( ! allowed_endpoint( $sub['endpoint'] ) ) {
		return false;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, label FROM %i WHERE endpoint_hash = %s', table( 'push' ), hash( 'sha256', $old_endpoint ) ) );
	// The browser's push service stays the same; never let a renewal point pushes elsewhere.
	if ( ! $row || wp_parse_url( $sub['endpoint'], PHP_URL_HOST ) !== wp_parse_url( $old_endpoint, PHP_URL_HOST ) ) {
		return false;
	}
	if ( true !== subscribe( (int) $row->user_id, $sub, (string) $row->label ) ) {
		return false;
	}
	if ( $sub['endpoint'] !== $old_endpoint ) {
		unsubscribe( (int) $row->user_id, $old_endpoint );
	}
	return true;
}

/**
 * Brake for the logged-out renewal: at most N attempts per address and hour (filter
 * flurfunk_push_renew_limit, default 30). The address is only kept as a salted hash.
 */
function renew_allowed(): bool {
	$max = (int) apply_filters( 'flurfunk_push_renew_limit', 30 );
	if ( $max <= 0 ) {
		return true;
	}
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key  = 'flurfunk_renew_' . wp_hash( $ip ) . '_' . gmdate( 'YmdH' );
	$used = (int) get_transient( $key );
	if ( $used >= $max ) {
		return false;
	}
	set_transient( $key, $used + 1, HOUR_IN_SECONDS );
	return true;
}

$flurfunk_renew = static function (): void {
	if ( ! renew_allowed() ) {
		wp_send_json_error( null, 429 );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the service worker has no login cookie; the old endpoint, which only that browser knows, authenticates it.
	$old = isset( $_POST['old'] ) && is_string( $_POST['old'] ) ? esc_url_raw( wp_unslash( $_POST['old'] ), array( 'https' ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; decoded and reduced to an https URL and two base64url keys by clean_subscription().
	$raw = isset( $_POST['subscription'] ) && is_string( $_POST['subscription'] ) ? wp_unslash( $_POST['subscription'] ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$sub = strlen( $raw ) <= 4096 ? json_decode( $raw, true ) : null;
	if ( '' === $old || ! is_array( $sub ) || ! renew( $old, clean_subscription( $sub ) ) ) {
		wp_send_json_error( null, 400 );
	}
	wp_send_json_success();
};
add_action( 'wp_ajax_flurfunk_push_renew', $flurfunk_renew );
add_action( 'wp_ajax_nopriv_flurfunk_push_renew', $flurfunk_renew );
unset( $flurfunk_renew );

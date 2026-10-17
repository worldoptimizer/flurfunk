#!/usr/bin/env bash
# Checks the Web Push crypto in inc/push.php against Node's crypto, like a browser would:
# encrypt in PHP (aes128gcm, RFC 8291) → decrypt in Node; ES256 VAPID signature → verify in Node.
set -euo pipefail
cd "$(dirname "$0")"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
cp gen.js dec.js "$TMP"/ && cd "$TMP"
read -r P A < <(node gen.js)
cat > enc.php <<PHP
<?php
define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 );
function wp_json_encode( \$v ) { return json_encode( \$v ); }
function add_action() {}
require '$OLDPWD/../../inc/push.php';
file_put_contents( 'body.bin', Flurfunk\Push\encrypt( '{"title":"Hallo","body":"Prüfung ✓"}', '$P', '$A' ) );
\$key = Flurfunk\Push\new_key(); openssl_pkey_export( \$key, \$pem ); openssl_sign( 'abc', \$sig, \$pem, OPENSSL_ALGO_SHA256 );
file_put_contents( 'jwt.json', json_encode( array( 'pub' => Flurfunk\Push\b64( Flurfunk\Push\raw_public( \$key ) ), 'sig' => Flurfunk\Push\b64( Flurfunk\Push\der_to_raw( \$sig ) ) ) ) );
PHP
php enc.php
node dec.js | grep -q 'payload {"title":"Hallo","body":"Prüfung ✓"}' && echo "ok   aes128gcm payload decrypts" || { echo "FAIL decrypt"; exit 1; }
node -e "
const c=require('crypto'),j=JSON.parse(require('fs').readFileSync('jwt.json'));const p=Buffer.from(j.pub,'base64url');
const key=c.createPublicKey({key:{kty:'EC',crv:'P-256',x:p.subarray(1,33).toString('base64url'),y:p.subarray(33).toString('base64url')},format:'jwk'});
process.exit(c.verify('sha256',Buffer.from('abc'),{key,dsaEncoding:'ieee-p1363'},Buffer.from(j.sig,'base64url'))?0:1)" && echo "ok   VAPID ES256 signature verifies" || { echo "FAIL signature"; exit 1; }

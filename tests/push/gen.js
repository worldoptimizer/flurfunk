// Make a browser-like subscription key pair; print p256dh/auth and keep the private key for decryption.
const crypto = require( 'crypto' );
const ecdh = crypto.createECDH( 'prime256v1' ); ecdh.generateKeys();
const auth = crypto.randomBytes( 16 );
const b64 = ( b ) => b.toString( 'base64url' );
require( 'fs' ).writeFileSync( 'keys.json', JSON.stringify( { priv: b64( ecdh.getPrivateKey() ), pub: b64( ecdh.getPublicKey() ), auth: b64( auth ) } ) );
console.log( b64( ecdh.getPublicKey() ), b64( auth ) );

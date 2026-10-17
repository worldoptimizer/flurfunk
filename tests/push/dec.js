// Decrypt an aes128gcm body (RFC 8291) as a browser would.
const crypto = require( 'crypto' ); const fs = require( 'fs' );
const k = JSON.parse( fs.readFileSync( 'keys.json' ) ); const body = fs.readFileSync( 'body.bin' );
const salt = body.subarray( 0, 16 ); const rs = body.readUInt32BE( 16 ); const idlen = body[ 20 ]; const asPub = body.subarray( 21, 21 + idlen ); const ct = body.subarray( 21 + idlen );
const ecdh = crypto.createECDH( 'prime256v1' ); ecdh.setPrivateKey( Buffer.from( k.priv, 'base64url' ) );
const shared = ecdh.computeSecret( asPub ); const uaPub = Buffer.from( k.pub, 'base64url' ); const auth = Buffer.from( k.auth, 'base64url' );
const hk = ( salt, ikm, info, len ) => Buffer.from( crypto.hkdfSync( 'sha256', ikm, salt, info, len ) );
const ikm = hk( auth, shared, Buffer.concat( [ Buffer.from( 'WebPush: info\0' ), uaPub, asPub ] ), 32 );
const cek = hk( salt, ikm, Buffer.from( 'Content-Encoding: aes128gcm\0' ), 16 ); const nonce = hk( salt, ikm, Buffer.from( 'Content-Encoding: nonce\0' ), 12 );
const d = crypto.createDecipheriv( 'aes-128-gcm', cek, nonce ); d.setAuthTag( ct.subarray( ct.length - 16 ) );
const plain = Buffer.concat( [ d.update( ct.subarray( 0, ct.length - 16 ) ), d.final() ] );
console.log( 'rs', rs, 'delimiter', plain[ plain.length - 1 ], 'payload', plain.subarray( 0, plain.length - 1 ).toString() );

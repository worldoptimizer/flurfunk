/**
 * Flurfunk service worker: shows push notifications and opens the thread on click.
 * It controls no pages (its scope is this folder); it only receives pushes.
 */
/* eslint-env serviceworker */
self.addEventListener( 'install', () => self.skipWaiting() );
self.addEventListener( 'activate', ( e ) => e.waitUntil( self.clients.claim() ) );

self.addEventListener( 'push', ( e ) => {
	let d = {};
	try {
		d = e.data ? e.data.json() : {};
	} catch ( x ) {
		d = { body: e.data ? e.data.text() : '' };
	}
	e.waitUntil(
		self.registration.showNotification( d.title || 'Flurfunk', {
			body: d.body || '',
			tag: d.tag || undefined,
			renotify: !! d.tag,
			icon: d.icon || undefined,
			data: { url: d.url || '' },
		} )
	);
} );

// Full-screen app address (inc/standalone.php) and a note that it was opened as an installed app.
const APP = new URL( self.location.href ).searchParams.get( 'app' ) || '';
const INSTALLED = new URL( 'installed', self.location.href ).href;

self.addEventListener( 'message', ( e ) => {
	if ( e.data && 'installed' === e.data.flurfunk ) {
		e.waitUntil( caches.open( 'flurfunk' ).then( ( c ) => c.put( INSTALLED, new Response( '1' ) ) ) );
	}
} );

// The same view in the full-screen app: wp-admin's ?page=flurfunk&view=… becomes /flurfunk/?view=…
const inApp = ( url ) => {
	const view = new URL( url ).searchParams.get( 'view' );
	const to = new URL( APP );
	if ( view ) {
		to.searchParams.set( 'view', view );
	}
	return to.href;
};

self.addEventListener( 'notificationclick', ( e ) => {
	e.notification.close();
	const url = ( e.notification.data && e.notification.data.url ) || '';
	e.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( async ( list ) => {
			// Prefer an open Flurfunk window: focus it and let it open the thread.
			for ( const c of list ) {
				if ( ( c.url.includes( 'page=flurfunk' ) || ( APP && c.url.startsWith( APP ) ) ) && 'focus' in c ) {
					c.postMessage( { flurfunk: 'open', url } );
					return c.focus();
				}
			}
			if ( ! url ) {
				return undefined;
			}
			// On a device with the home screen app, open that (on iOS the only place with push).
			const installed = APP && url.includes( 'page=flurfunk' ) && ( await caches.match( INSTALLED ).catch( () => null ) );
			return self.clients.openWindow( installed ? inApp( url ) : url );
		} )
	);
} );

// The browser replaced the subscription (expired or rotated keys): subscribe again with the same
// key and hand the new one to the site. The old endpoint proves which subscription it replaces.
self.addEventListener( 'pushsubscriptionchange', ( e ) => {
	const ajax = new URL( self.location.href ).searchParams.get( 'ajax' );
	const old = e.oldSubscription;
	const key = old && old.options && old.options.applicationServerKey;
	if ( ! ajax || ! old || ! key ) {
		return; // The app subscribes again the next time it opens.
	}
	e.waitUntil(
		( e.newSubscription ? Promise.resolve( e.newSubscription ) : self.registration.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: key } ) )
			.then( ( sub ) => {
				const body = new URLSearchParams( { action: 'flurfunk_push_renew', old: old.endpoint, subscription: JSON.stringify( sub.toJSON() ) } );
				return fetch( ajax, { method: 'POST', body, credentials: 'omit' } );
			} )
			.catch( () => {} )
	);
} );

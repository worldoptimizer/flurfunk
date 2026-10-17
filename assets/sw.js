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

self.addEventListener( 'notificationclick', ( e ) => {
	e.notification.close();
	const url = ( e.notification.data && e.notification.data.url ) || '';
	e.waitUntil(
		self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( ( list ) => {
			// Prefer an open Flurfunk window: focus it and let it open the thread.
			for ( const c of list ) {
				if ( c.url.includes( 'page=flurfunk' ) && 'focus' in c ) {
					c.postMessage( { flurfunk: 'open', url } );
					return c.focus();
				}
			}
			return url ? self.clients.openWindow( url ) : undefined;
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

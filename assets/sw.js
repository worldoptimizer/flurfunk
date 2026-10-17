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

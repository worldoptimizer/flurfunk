import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const Q = process.env.FF_QUERY ? '&' + process.env.FF_QUERY : '';
const APP = '/wp-admin/admin.php?page=flurfunk' + Q;

async function login( page, user, pass ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await expect.poll( async () => ( await page.context().cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in' ) ), { timeout: 90_000 } ).toBe( true );
}
const api = ( page, path, body ) => page.evaluate( ( [ p, b ] ) => window.Flurfunk.api( p, b ? { method: 'POST', body: b } : {} ), [ path, body ] );
async function openApp( page ) {
	await page.goto( APP, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.ff-app .ff-me-tile' );
	await page.waitForFunction( () => ! document.querySelector( '.ff-feed .ff-empty' ) || ! /Lädt|Loading/.test( document.querySelector( '.ff-feed' ).textContent ) );
}

test.describe( 'team', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page, process.env.FF_TEAM, process.env.FF_TEAM_PASS );
		await openApp( page );
		await api( page, 'me/prefs', { theme: 'light', avatar: 'photo', mail: 'mentions', badge: true } );
	} );

	test( 'markup in messages is shown as text, never run', async ( { page } ) => {
		const evil = '<img src=x onerror="window.__ffxss=1"><script>window.__ffxss=2</script> e2e-xss';
		const th = await api( page, 'threads', { channel: 'general', title: '<b>e2e</b> Titel', body: evil } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await expect( page.locator( '.ff-root-msg .ff-text' ) ).toContainText( '<script>' );
		expect( await page.evaluate( () => window.__ffxss ) ).toBeUndefined();
		expect( await page.locator( '.ff-root-msg img[src="x"]' ).count() ).toBe( 0 );
	} );

	test( 'long words wrap instead of widening the page', async ( { page } ) => {
		const th = await api( page, 'threads', { channel: 'general', body: 'x'.repeat( 400 ) + ' https://example.org/' + 'y'.repeat( 300 ) } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		for ( const sel of [ '.ff-feed', '.ff-pane-body' ] ) {
			const over = await page.locator( sel ).evaluate( ( e ) => e.scrollWidth - e.clientWidth );
			expect( over, sel ).toBeLessThanOrEqual( 1 );
		}
	} );

	test( 'closing a thread restores the two-column layout', async ( { page } ) => {
		await page.locator( '.ff-post' ).first().click();
		await expect( page.locator( '.ff-pane' ) ).toBeVisible();
		await page.locator( '.ff-pane-head .ff-x' ).click();
		await expect( page.locator( '.ff-pane' ) ).toBeHidden();
		const cols = await page.locator( '.ff-app' ).evaluate( ( e ) => getComputedStyle( e ).gridTemplateColumns.split( ' ' ).length );
		expect( cols ).toBe( 2 );
	} );

	for ( const [ w, cols, nav ] of [ [ 1280, 3, true ], [ 1000, 3, true ], [ 650, 1, false ] ] ) {
		test( `layout with open thread at ${ w }px`, async ( { page } ) => {
			await page.setViewportSize( { width: w, height: 800 } );
			await openApp( page );
			await page.locator( '.ff-post' ).first().click();
			await expect( page.locator( '.ff-pane textarea' ) ).toBeVisible();
			const n = await page.locator( '.ff-app' ).evaluate( ( e ) => getComputedStyle( e ).gridTemplateColumns.split( ' ' ).length );
			expect( n ).toBe( cols );
			expect( await page.locator( '.ff-nav' ).isVisible() ).toBe( nav );
			expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) ).toBe( true );
			// The header tools stay inside the middle column, also with "Mark all as read" in it.
			const fits = await page.evaluate( () => {
				const main = document.querySelector( '.ff-main' ).getBoundingClientRect();
				return [ ...document.querySelectorAll( '.ff-head-tools > *' ) ].every( ( el ) => el.getBoundingClientRect().right <= main.right + 1 );
			} );
			expect( fits ).toBe( true );
		} );
	}

	test( 'mention picker inserts the full name and stores the mention', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		const other = boot.people.find( ( p ) => p.id !== boot.me.id && 'team' === p.kind );
		test.skip( ! other, 'needs a second team member' );
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( 'e2e Hallo @' + other.name.split( ' ' )[ 0 ].slice( 0, 3 ) );
		await page.locator( '.ff-new-thread .ff-menu button', { hasText: other.name } ).first().click();
		await expect( ta ).toHaveValue( new RegExp( '@' + other.name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + ' $' ) );
		await ta.press( 'Control+Enter' );
		await expect( page.locator( '.ff-pane .ff-mention', { hasText: other.name } ) ).toBeVisible();
		const th = await api( page, 'threads?view=all&limit=1' );
		expect( th[ 0 ].mentions ).toContain( other.id );
	} );

	test( 'slash menu: unknown command, keyboard, card insert and remove', async ( { page } ) => {
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( '/gibtsnicht' );
		await expect( page.locator( '.ff-new-thread .ff-menu' ) ).toHaveCount( 0 );
		await ta.fill( '' );
		await ta.pressSequentially( '/' );
		await expect( page.locator( '.ff-new-thread .ff-menu button' ).first() ).toBeVisible();
		await ta.press( 'Escape' );
		await expect( page.locator( '.ff-new-thread .ff-menu' ) ).toHaveCount( 0 );
		const boot = await api( page, 'bootstrap' );
		const post = boot.providers.find( ( p ) => 'flurfunk/post' === p.id );
		await ta.fill( '' );
		await ta.pressSequentially( '/' + post.command + ' ' );
		await page.locator( '.ff-new-thread .ff-menu button[data-ff="pick-item"]' ).first().waitFor();
		await ta.press( 'ArrowDown' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '.ff-new-thread .ff-pending .ff-card' ) ).toHaveCount( 1 );
		await page.locator( '.ff-new-thread .ff-card-x' ).click();
		await expect( page.locator( '.ff-new-thread .ff-pending .ff-card' ) ).toHaveCount( 0 );
	} );

	test( 'empty message is not sent; Ctrl+Enter sends', async ( { page } ) => {
		const posts = [];
		page.on( 'request', ( r ) => r.method() === 'POST' && r.url().includes( 'flurfunk/v1/threads' ) && posts.push( r.url() ) );
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.press( 'Control+Enter' );
		await page.waitForTimeout( 500 );
		expect( posts.length ).toBe( 0 );
		await ta.pressSequentially( 'e2e Strg+Enter' );
		await ta.press( 'Control+Enter' );
		await expect( page.locator( '.ff-pane .ff-root-msg' ) ).toContainText( 'e2e Strg+Enter' );
		await expect( ta ).toHaveValue( '' );
	} );

	test( 'settings: theme persists, outside click closes, initials switch', async ( { page } ) => {
		await page.locator( '.ff-me-tile' ).click();
		await page.locator( '[data-pref="theme"][data-val="dark"]' ).click();
		await expect( page.locator( '.ff-root' ).first() ).toHaveAttribute( 'data-ff-theme', 'dark' );
		await page.locator( '.ff-head' ).click();
		await expect( page.locator( '.ff-settings' ) ).toHaveCount( 0 );
		await openApp( page );
		await expect( page.locator( '.ff-root' ).first() ).toHaveAttribute( 'data-ff-theme', 'dark' );
		await page.locator( '.ff-me-tile' ).click();
		await page.locator( '[data-pref="avatar"][data-val="initials"]' ).click();
		await expect( page.locator( '.ff-me-tile .ff-sq img' ) ).toHaveCount( 0 );
		await page.locator( '[data-pref="theme"][data-val="light"]' ).click();
	} );

	test( 'keyboard: a thread opens with Enter', async ( { page } ) => {
		await page.locator( '.ff-post' ).first().focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '.ff-pane textarea' ) ).toBeFocused();
	} );

	for ( const theme of [ 'light', 'dark' ] ) {
		test( `no serious accessibility problems (${ theme })`, async ( { page } ) => {
			await api( page, 'me/prefs', { theme } );
			await openApp( page );
			await page.locator( '.ff-post' ).first().click();
			await page.locator( '.ff-pane textarea' ).waitFor();
			const r = await new AxeBuilder( { page } ).include( '.ff-app' ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa' ] ).analyze();
			const bad = r.violations.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) ).map( ( v ) => v.id + ': ' + v.nodes.slice( 0, 3 ).map( ( n ) => n.target.join( ' ' ) ).join( ' | ' ) );
			expect( bad ).toEqual( [] );
		} );
	}

	test( 'file upload shows progress and ends up in the message', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		test.skip( ! boot.me.upload, 'account cannot upload' );
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( 'e2e mit Datei' );
		const png = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64' );
		await page.locator( '.ff-new-thread input[type=file]' ).setInputFiles( { name: 'e2e-punkt.png', mimeType: 'image/png', buffer: png } );
		await expect( page.locator( '.ff-new-thread .ff-pending .ff-file' ) ).toContainText( 'e2e-punkt' );
		await expect( page.locator( '.ff-new-thread .ff-pending small' ) ).toHaveCount( 0, { timeout: 60_000 } );
		await ta.press( 'Control+Enter' );
		await expect( page.locator( '.ff-pane .ff-root-msg, .ff-pane .ff-msg' ).first() ).toBeVisible();
		const th = ( await api( page, 'threads?view=all&limit=1' ) )[ 0 ];
		const full = await api( page, 'threads/' + th.id );
		expect( full.body ).toContain( 'e2e mit Datei' );
	} );

	test( 'formatting and image previews show in the thread', async ( { page } ) => {
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.fill( 'e2e *fett* und _kursiv_\n- eins\n- zwei\n`code`' );
		const png = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64' );
		await page.locator( '.ff-new-thread input[type=file]' ).setInputFiles( { name: 'e2e-vorschau.png', mimeType: 'image/png', buffer: png } );
		await expect( page.locator( '.ff-new-thread .ff-pending small' ) ).toHaveCount( 0, { timeout: 60_000 } );
		await ta.press( 'Control+Enter' );
		const root = page.locator( '.ff-pane .ff-root-msg' );
		await expect( root.locator( 'strong' ) ).toHaveText( 'fett' );
		await expect( root.locator( 'em' ) ).toHaveText( 'kursiv' );
		await expect( root.locator( 'li' ) ).toHaveCount( 2 );
		await expect( root.locator( 'code' ) ).toHaveText( 'code' );
	} );

	test( 'a reply with an image shows a preview', async ( { page } ) => {
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Bild ' + Date.now() } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		const png = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64' );
		await page.locator( '.ff-pane input[type=file]' ).setInputFiles( { name: 'e2e-antwort.png', mimeType: 'image/png', buffer: png } );
		await expect( page.locator( '.ff-pane .ff-pending small' ) ).toHaveCount( 0, { timeout: 60_000 } );
		await page.locator( '.ff-pane .ff-send' ).click();
		await expect( page.locator( '.ff-pane .ff-msg .ff-image img' ) ).toBeVisible();
	} );

	test( 'several images form a gallery that opens in a lightbox', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		test.skip( ! boot.me.upload, 'account cannot upload' );
		const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
		const up = ( name, type, b64 ) => page.evaluate( async ( [ n, ty, data ] ) => {
			const r = await fetch( window.flurfunkConfig.fileEndpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.flurfunkConfig.nonce, 'Content-Disposition': 'attachment; filename="' + n + '"', 'Content-Type': ty }, body: Uint8Array.from( atob( data ), ( c ) => c.charCodeAt( 0 ) ) } );
			return ( await r.json() ).id;
		}, [ name, type, b64 ] );
		const ids = [];
		for ( let i = 1; i <= 3; i++ ) {
			ids.push( await up( 'e2e-galerie-' + i + '.png', 'image/png', png ) );
		}
		for ( let i = 1; i <= 10; i++ ) {
			ids.push( await up( 'e2e-liste-' + i + '.txt', 'text/plain', btoa( 'Zeile ' + i ) ) );
		}
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Galerie ' + Date.now() } );
		await api( page, 'threads/' + th.id + '/messages', { body: 'Anhänge', files: ids } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		const root = page.locator( '.ff-pane .ff-msg', { hasText: 'Anhänge' } );
		await expect( root.locator( '.ff-gallery .ff-image' ) ).toHaveCount( 3 );
		await expect( root.locator( '.ff-more-files summary' ) ).toContainText( '4' );
		await expect( root.locator( '.ff-more-files .ff-file' ).first() ).toBeHidden();
		await root.locator( '.ff-more-files summary' ).click();
		await expect( root.locator( '.ff-more-files .ff-file' ) ).toHaveCount( 4 );
		await root.locator( '.ff-gallery .ff-image' ).nth( 1 ).click();
		const box = page.locator( 'dialog.ff-lightbox' );
		await expect( box ).toBeVisible();
		await expect( box.locator( 'figcaption' ) ).toContainText( '2/3' );
		await page.keyboard.press( 'ArrowRight' );
		await expect( box.locator( 'figcaption' ) ).toContainText( '3/3' );
		await page.keyboard.press( 'Escape' );
		await expect( box ).toHaveCount( 0 );
		await expect( page.locator( '.ff-pane' ) ).toBeVisible(); // Escape closed only the lightbox.
	} );

	test( 'the media library dialog opens from the composer', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		test.skip( ! boot.me.upload, 'account cannot upload' );
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Mediathek ' + Date.now() } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-pane [data-ff="library"]' ).click();
		await expect( page.locator( '.media-modal' ).first() ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '.media-modal' ).first() ).toBeHidden();
		await expect( page.locator( '.ff-pane' ) ).toBeVisible();
	} );

	test( 'on a phone the back button returns to the list', async ( { page } ) => {
		await page.setViewportSize( { width: 400, height: 800 } );
		await openApp( page );
		await page.locator( '.ff-post' ).first().click();
		await expect( page.locator( '.ff-nav' ) ).toBeHidden();
		await page.locator( '.ff-back' ).click();
		await expect( page.locator( '.ff-feed' ) ).toBeVisible();
		await expect( page.locator( '.ff-pane' ) ).toBeHidden();
	} );

	test( 'older threads load on request', async ( { page } ) => {
		const list = await api( page, 'threads?channel=general&limit=41' );
		for ( let i = list.length; i < 41; i++ ) {
			await api( page, 'threads', { channel: 'general', body: 'e2e Füller ' + i } );
		}
		await page.goto( APP + '&view=channel:general', { waitUntil: 'domcontentloaded' } );
		await page.locator( '.ff-post' ).first().waitFor();
		await expect( page.locator( '.ff-post' ) ).toHaveCount( 40 );
		await page.locator( '.ff-more' ).click();
		await expect.poll( () => page.locator( '.ff-post' ).count() ).toBeGreaterThan( 40 );
	} );

	test( 'a reply from someone else shows up without reloading', async ( { page, browser } ) => {
		test.skip( ! process.env.FF_TEAM2, 'needs a second team account' );
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e live ' + Date.now() } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-head' ).click(); // focus out of the reply box, as when reading
		const other = await ( await browser.newContext() ).newPage();
		await login( other, process.env.FF_TEAM2, process.env.FF_TEAM2_PASS );
		await openApp( other );
		await api( other, 'threads/' + th.id + '/messages', { body: 'e2e Antwort von nebenan' } );
		await expect( page.locator( '.ff-pane .ff-msg', { hasText: 'e2e Antwort von nebenan' } ) ).toBeVisible( { timeout: 45_000 } );
		await other.context().close();
	} );

	test( 'an open thread follows edits and ticks by others; Escape closes it', async ( { page, browser } ) => {
		test.skip( ! process.env.FF_TEAM2, 'needs a second team account' );
		const other = await ( await browser.newContext() ).newPage();
		await login( other, process.env.FF_TEAM2, process.env.FF_TEAM2_PASS );
		await openApp( other );
		const th = await api( other, 'threads', { channel: 'general', body: 'e2e folgt ' + Date.now(), task: true } );
		const msg = await api( other, 'threads/' + th.id + '/messages', { body: 'vorher' } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await expect( page.locator( '.ff-pane .ff-msg', { hasText: 'vorher' } ) ).toBeVisible();
		await page.locator( '.ff-head' ).click();
		const mid = msg.messages[ msg.messages.length - 1 ].id;
		await other.evaluate( ( id ) => window.Flurfunk.api( 'messages/' + id, { method: 'POST', body: { body: 'nachher' } } ), mid );
		await api( other, 'threads/' + th.id + '/status', { status: 'done' } );
		await other.context().close();
		await expect( page.locator( '.ff-pane .ff-msg', { hasText: 'nachher' } ) ).toBeVisible( { timeout: 45_000 } );
		await expect( page.locator( '.ff-pane .ff-root-row input[data-ff=done]' ) ).toBeChecked();
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '.ff-pane' ) ).toBeHidden();
	} );

	test( 'unread number appears for others and goes away after reading', async ( { page, browser } ) => {
		test.skip( ! process.env.FF_TEAM2, 'needs a second team account' );
		const other = await ( await browser.newContext() ).newPage();
		await login( other, process.env.FF_TEAM2, process.env.FF_TEAM2_PASS );
		await openApp( other );
		const th = await api( other, 'threads', { channel: 'general', body: 'e2e ungelesen ' + Date.now() } );
		await other.context().close();
		await openApp( page );
		const badge = page.locator( '.ff-nav button[data-view="channel:general"] b' );
		await expect( badge ).toBeVisible();
		const before = +( await badge.textContent() );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-pane textarea' ).waitFor();
		await expect.poll( async () => ( await badge.count() ) ? +( await badge.textContent() ) : 0, { timeout: 45_000 } ).toBeLessThan( before );
	} );

	test( 'attached-to picker searches and attaches', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		const target = boot.targets[ 1 ] || boot.targets[ 0 ];
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( 'e2e am Artikel' );
		await page.locator( '.ff-new-thread .ff-pick' ).click();
		const q = page.locator( '.ff-new-thread .ff-pick-q' );
		await expect( q ).toBeFocused();
		await q.pressSequentially( target.title.split( ' ' )[ 0 ] );
		const opt = page.locator( '.ff-new-thread .ff-pick-list button', { hasText: target.title } ).first();
		await opt.waitFor();
		await opt.click();
		await expect( page.locator( '.ff-new-thread .ff-pick' ) ).toContainText( target.title.slice( 0, 20 ) );
		await ta.press( 'Control+Enter' );
		await expect( page.locator( '.ff-pane .ff-target' ) ).toContainText( target.title.slice( 0, 20 ) );
	} );

	test( 'own message can be edited and deleted', async ( { page } ) => {
		page.on( 'dialog', ( d ) => d.accept() );
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Bearbeiten ' + Date.now() } );
		await api( page, 'threads/' + th.id + '/messages', { body: 'Tippfeler' } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		const msg = page.locator( '.ff-pane .ff-msg' ).last();
		await msg.hover();
		await msg.locator( '[data-ff="msg-edit"]' ).click();
		const ed = page.locator( '.ff-pane .ff-edit textarea' );
		await ed.fill( 'Tippfehler korrigiert' );
		await page.locator( '.ff-pane .ff-edit .ff-send' ).click();
		await expect( page.locator( '.ff-pane .ff-msg' ).last() ).toContainText( 'Tippfehler korrigiert' );
		await expect( page.locator( '.ff-pane .ff-msg' ).last().locator( '.ff-edited' ) ).toBeVisible();
		await page.locator( '.ff-pane .ff-msg' ).last().hover();
		await page.locator( '.ff-pane .ff-msg' ).last().locator( '[data-ff="msg-del"]' ).click();
		await expect( page.locator( '.ff-pane .ff-event.is-deleted' ) ).toBeVisible();
	} );

	test( 'a draft survives a reload', async ( { page } ) => {
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( 'e2e Entwurf bleibt' );
		await page.waitForTimeout( 600 );
		await openApp( page );
		await expect( page.locator( '.ff-new-thread textarea' ) ).toHaveValue( 'e2e Entwurf bleibt' );
		await page.locator( '.ff-new-thread textarea' ).fill( '' );
		await page.waitForTimeout( 600 );
	} );

	test( 'an expired nonce is renewed silently', async ( { page } ) => {
		await page.evaluate( () => {
			window.flurfunkConfig.nonce = 'abgelaufen';
		} );
		const n = await page.evaluate( async () => ( await window.Flurfunk.api( 'counts' ) ).total );
		expect( typeof n ).toBe( 'number' );
	} );

	test( 'after logging out the app says so', async ( { page } ) => {
		await page.context().clearCookies();
		await page.locator( '.ff-post' ).first().click();
		await expect( page.locator( '.ff-session' ) ).toBeVisible();
	} );

	test( 'a channel as checklist: add an item, reply, tick it off', async ( { page } ) => {
		await page.evaluate( () => {
			const d = document.createElement( 'div' );
			d.id = 'ff-checklist-test';
			document.querySelector( '.ff-app' ).replaceWith( d );
			window.Flurfunk.mountChannel( d, { channel: 'general' } );
		} );
		const list = page.locator( '#ff-checklist-test' );
		await list.locator( '.ff-new-slot input[name=title]' ).waitFor();
		const title = 'e2e Punkt ' + Date.now();
		await list.locator( '.ff-new-slot input[name=title]' ).fill( title );
		await list.locator( '.ff-new-slot textarea' ).fill( 'Worum es geht' );
		await list.locator( '.ff-new-slot .ff-send' ).click();
		const item = list.locator( '.ff-item', { hasText: title } );
		await expect( item ).toBeVisible();
		await item.locator( '[data-ff="answer"]' ).click();
		await item.locator( 'textarea' ).fill( 'erste Antwort' );
		await item.locator( '.ff-send' ).click();
		await expect( list.locator( '.ff-item', { hasText: title } ).locator( '.ff-msg', { hasText: 'erste Antwort' } ) ).toBeVisible();
		await list.locator( '.ff-item', { hasText: title } ).locator( 'input[data-ff=done]' ).check();
		await expect( list.locator( '.ff-item.is-done', { hasText: title } ) ).toBeVisible();
		await expect( list.locator( '.ff-done-h' ) ).toBeVisible();
	} );

	test( 'a Flurfunk file can be added to the media library', async ( { page } ) => {
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Datei ' + Date.now() } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-pane input[type=file]' ).setInputFiles( { name: 'e2e-notiz.txt', mimeType: 'text/plain', buffer: Buffer.from( 'Notiz' ) } );
		await expect( page.locator( '.ff-pane .ff-pending small' ) ).toHaveCount( 0, { timeout: 60_000 } );
		await page.locator( '.ff-pane .ff-send' ).click();
		const adopt = page.locator( '.ff-pane .ff-msg [data-ff="adopt"]' ).last();
		await expect( adopt ).toBeVisible();
		await adopt.click();
		await expect( page.locator( '.ff-pane .ff-msg [data-ff="adopt"]' ) ).toHaveCount( 0 );
	} );

	test( 'a card can be updated in place', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Karte ' + Date.now(), attachments: [ { provider: 'flurfunk/post', ref: { id: boot.targets[ 0 ].id } } ] } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		const btn = page.locator( '.ff-pane .ff-root-msg [data-ff="card-refresh"]' );
		await expect( btn ).toBeVisible();
		await btn.click();
		await expect( page.locator( '.ff-pane .ff-root-msg .ff-card' ) ).toBeVisible();
	} );

	test( 'search finds a reply and mark-all-read clears numbers', async ( { page } ) => {
		const word = 'Suchwort' + Date.now();
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e Suche' } );
		await api( page, 'threads/' + th.id + '/messages', { body: 'darin steht ' + word } );
		await openApp( page );
		await page.locator( '.ff-search input' ).fill( word );
		await page.locator( '.ff-search input' ).press( 'Enter' );
		await expect( page.locator( '.ff-head h1' ) ).toContainText( word );
		await expect( page.locator( '.ff-post' ) ).toHaveCount( 1 );
		await page.locator( '.ff-nav button[data-view="all"]' ).click();
		const btn = page.locator( '[data-ff="read-all"]' );
		if ( await btn.count() ) {
			await btn.click();
			await expect( page.locator( '.ff-nav b' ) ).toHaveCount( 0 );
		}
	} );

	test( 'the sidebar can be dragged wider and keeps its width', async ( { page } ) => {
		const nav = page.locator( '.ff-nav' );
		const before = ( await nav.boundingBox() ).width;
		const h = await page.locator( '.ff-nav > .ff-resize' ).boundingBox();
		await page.mouse.move( h.x + h.width / 2, h.y + 200 );
		await page.mouse.down();
		await page.mouse.move( h.x + 80, h.y + 200, { steps: 8 } );
		await page.mouse.up();
		const after = ( await nav.boundingBox() ).width;
		expect( after ).toBeGreaterThan( before + 50 );
		await openApp( page );
		expect( Math.abs( ( await page.locator( '.ff-nav' ).boundingBox() ).width - after ) ).toBeLessThan( 3 );
		await page.locator( '.ff-nav > .ff-resize' ).dblclick();
		expect( Math.abs( ( await page.locator( '.ff-nav' ).boundingBox() ).width - before ) ).toBeLessThan( 3 );
	} );

	test( 'the list shows a short preview, lists keep their bullets', async ( { page } ) => {
		const th = await api( page, 'threads', { channel: 'general', title: 'e2e Liste ' + Date.now(), body: 'Absatz\n\n• eins\n• zwei\n• drei\n• vier' } );
		await openApp( page );
		const post = page.locator( `.ff-post[data-id="${ th.id }"]` );
		await expect( post.locator( '.ff-preview' ) ).toContainText( 'Absatz · eins · zwei' );
		expect( await post.locator( 'ul' ).count() ).toBe( 0 );
		await post.click();
		const li = page.locator( '.ff-pane .ff-root-msg li' ).first();
		expect( await li.evaluate( ( e ) => getComputedStyle( e.parentElement ).listStyleType ) ).toBe( 'disc' );
	} );

	test( 'a task with due date and responsible person, shown in "My tasks"', async ( { page, browser } ) => {
		test.skip( ! process.env.FF_TEAM2, 'needs a second team account' );
		const boot = await api( page, 'bootstrap' );
		const other = boot.people.find( ( p ) => p.id !== boot.me.id && 'team' === p.kind );
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		const title = 'e2e Aufgabe ' + Date.now();
		await ta.fill( title );
		await page.locator( '.ff-new-thread input[name=task]' ).check();
		await page.locator( '.ff-new-thread input[name=due]' ).fill( '2031-05-04' );
		await page.locator( '.ff-new-thread [data-ff="new-assign"]' ).click();
		await page.locator( '.ff-people-pop .ff-pick-q' ).fill( other.name.split( ' ' )[ 0 ] );
		await page.locator( '.ff-people-pop label', { hasText: other.name } ).first().locator( 'input' ).check();
		await page.locator( '.ff-people-pop [data-pp="done"]' ).click();
		await expect( page.locator( '.ff-new-thread [data-ff="new-assign"]' ) ).toContainText( other.name.split( ' ' )[ 0 ] );
		await ta.press( 'Control+Enter' );
		const task = page.locator( '.ff-pane .ff-task' );
		await expect( task ).toContainText( other.name );
		await expect( task.locator( 'input[type=date]' ) ).toHaveValue( '2031-05-04' );
		await task.locator( 'input[type=date]' ).fill( '2020-01-01' );
		await task.locator( 'input[type=date]' ).dispatchEvent( 'change' );
		await expect( page.locator( '.ff-pane .ff-due.is-over' ) ).toBeVisible();
		await expect( page.locator( '.ff-pane .ff-event', { hasText: '2020' } ).or( page.locator( '.ff-pane .ff-event' ).last() ) ).toBeVisible();
		const second = await ( await browser.newContext() ).newPage();
		await login( second, process.env.FF_TEAM2, process.env.FF_TEAM2_PASS );
		await second.goto( APP + '&view=mine', { waitUntil: 'domcontentloaded' } );
		await expect( second.locator( '.ff-post', { hasText: title } ) ).toBeVisible( { timeout: 60_000 } );
		await expect( second.locator( '.ff-nav button[data-view="mine"] b.is-over' ) ).toBeVisible();
		await expect( second.locator( '.ff-bell b' ) ).toBeVisible();
		await second.locator( '.ff-bell' ).click();
		const note = second.locator( '.ff-note-item', { hasText: title } ).first();
		await expect( note ).toBeVisible();
		await note.click();
		await expect( second.locator( '.ff-pane .ff-root-msg' ) ).toContainText( title );
		await second.context().close();
	} );

	test( 'settings: notifications section, quiet hours, browser notifications', async ( { page } ) => {
		await page.context().grantPermissions( [ 'notifications' ] );
		await openApp( page );
		await page.locator( '.ff-me-tile' ).click();
		const box = page.locator( '.ff-settings' );
		await expect( box ).toContainText( /Push/ );
		await box.locator( 'input[type=radio][value=digest]' ).check();
		await box.locator( '[data-ff="quiet-on"]' ).check();
		await expect( box.locator( '[data-part="from"]' ) ).toBeEnabled();
		await box.locator( '[data-ff="browser-notes"]' ).check();
		await expect.poll( async () => ( await api( page, 'bootstrap' ) ).me.prefs.quiet ).toMatch( /^\d\d:\d\d-\d\d:\d\d$/ );
		expect( ( await api( page, 'bootstrap' ) ).me.prefs.mail ).toBe( 'digest' );
		await box.locator( 'input[type=radio][value=mentions]' ).check();
		await box.locator( '[data-ff="quiet-on"]' ).uncheck();
		await expect.poll( async () => ( await api( page, 'bootstrap' ) ).me.prefs.quiet ).toBe( '' );
	} );

	test( 'losing the connection shows a notice that goes away again; Ctrl+K searches', async ( { page } ) => {
		await page.context().setOffline( true );
		await page.evaluate( () => window.dispatchEvent( new Event( 'offline' ) ) );
		await expect( page.locator( '.ff-offline' ) ).toBeVisible();
		await page.context().setOffline( false );
		await page.evaluate( () => window.dispatchEvent( new Event( 'online' ) ) );
		await expect( page.locator( '.ff-offline' ) ).toHaveCount( 0, { timeout: 30_000 } );
		await page.locator( '.ff-head h1' ).click();
		await page.keyboard.press( 'Control+k' );
		await expect( page.locator( '.ff-search input' ) ).toBeFocused();
	} );

	test( 'follow and mute from the thread header', async ( { page } ) => {
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e folgen ' + Date.now() } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-follow [data-ff="follow"]' ).first().click();
		await expect( page.locator( '.ff-follow [aria-pressed="true"]' ) ).toHaveCount( 1 );
		expect( ( await api( page, 'threads/' + th.id + '?peek=1' ) ).follow ).toBe( 1 );
		await page.locator( '.ff-follow [data-ff="follow"]' ).nth( 1 ).click();
		await expect.poll( async () => ( await api( page, 'threads/' + th.id + '?peek=1' ) ).follow ).toBe( -1 );
	} );

	test( 'remind me later from the thread header', async ( { page } ) => {
		const th = await api( page, 'threads', { channel: 'general', body: 'e2e erinnern ' + Date.now() } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-pane select[data-ff="remind"]' ).selectOption( 'tomorrow' );
		await expect( page.locator( '.ff-pane select.ff-remind.is-set' ) ).toBeVisible();
		expect( ( await api( page, 'threads/' + th.id + '?peek=1' ) ).remind ).toBeTruthy();
		await page.locator( '.ff-pane select[data-ff="remind"]' ).selectOption( 'none' );
		await expect( page.locator( '.ff-pane select.ff-remind.is-set' ) ).toHaveCount( 0 );
		expect( ( await api( page, 'threads/' + th.id + '?peek=1' ) ).remind ).toBeNull();
	} );

	test( 'notification level per channel', async ( { page } ) => {
		await page.locator( '.ff-nav [data-view="channel:general"]' ).click();
		const sel = page.locator( '.ff-head select[data-ff="channel-notify"]' );
		await sel.selectOption( 'mentions' );
		await expect.poll( async () => ( await api( page, 'bootstrap' ) ).me.prefs.channels.general ).toBe( 'mentions' );
		await page.reload();
		await page.locator( '.ff-nav [data-view="channel:general"]' ).click();
		await expect( sel ).toHaveValue( 'mentions' );
		await sel.selectOption( 'default' );
		await expect.poll( async () => ( await api( page, 'bootstrap' ) ).me.prefs.channels.general ).toBeUndefined();
	} );

	test( 'board: lanes by state, drag and drop, keyboard, state menu in the thread', async ( { page } ) => {
		const stamp = Date.now();
		const a = await api( page, 'threads', { channel: 'general', title: 'e2e Board A ' + stamp, body: 'x', task: true } );
		const b = await api( page, 'threads', { channel: 'general', title: 'e2e Board B ' + stamp, body: 'y', task: true } );
		await api( page, 'threads/' + b.id + '/status', { status: 'doing' } );
		await page.locator( '.ff-nav [data-view="board"]' ).click();
		const card = ( th ) => page.locator( `.ff-tcard[data-id="${ th.id }"]` );
		await expect( page.locator( '.ff-lane[data-status="open"]' ).locator( card( a ) ) ).toBeVisible();
		await expect( page.locator( '.ff-lane[data-status="doing"]' ).locator( card( b ) ) ).toBeVisible();
		await expect( page.locator( '.ff-compose-slot .ff-compose' ) ).toHaveCount( 0 );
		await card( a ).dragTo( page.locator( '.ff-lane[data-status="waiting"]' ) );
		await expect( page.locator( '.ff-lane[data-status="waiting"]' ).locator( card( a ) ) ).toBeVisible();
		await expect.poll( async () => ( await api( page, 'threads/' + a.id + '?peek=1' ) ).status ).toBe( 'waiting' );
		await card( b ).focus();
		await page.keyboard.press( 'Alt+ArrowRight' );
		await expect.poll( async () => ( await api( page, 'threads/' + b.id + '?peek=1' ) ).status ).toBe( 'waiting' );
		await expect( card( b ) ).toBeFocused();
		const wide = ( await page.locator( '.ff-board' ).boundingBox() ).width;
		await card( b ).click();
		await expect( page.locator( '.ff-pane' ) ).toBeVisible();
		expect( Math.abs( ( await page.locator( '.ff-board' ).boundingBox() ).width - wide ) ).toBeLessThan( 2 ); // A drawer, the board keeps its size.
		await expect( card( b ) ).toHaveClass( /is-current/ );
		await page.locator( '.ff-pane select[data-ff="status"]' ).selectOption( 'done' );
		await expect.poll( async () => ( await api( page, 'threads/' + b.id + '?peek=1' ) ).status ).toBe( 'done' );
		await expect( page.locator( '.ff-pane .ff-event', { hasText: /Rückfrage|question/ } ) ).toHaveCount( 1 );
		await expect( page.locator( '.ff-lane[data-status="done"]' ).locator( card( b ) ) ).toBeVisible();
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 ) ).toBe( true );
	} );

	test( 'export and import from the settings page, without WP-CLI', async ( { page } ) => {
		await page.goto( '/wp-admin/options-general.php?page=flurfunk-settings' + Q );
		const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.locator( 'form:has(input[name=action][value=flurfunk_export]) [type=submit]' ).click() ] );
		const path = await download.path();
		const fs = await import( 'node:fs' );
		const data = JSON.parse( fs.readFileSync( path, 'utf8' ) );
		expect( data.format ).toBe( 'flurfunk/1' );
		expect( data.threads.length ).toBeGreaterThan( 0 );
		await page.locator( '#flurfunk-import-file' ).setInputFiles( path );
		await page.locator( 'form:has(input[name=action][value=flurfunk_import]) [type=submit]' ).click();
		await expect( page.locator( '.notice-success', { hasText: /Imported|Importiert/ } ) ).toContainText( /: 0 /, { timeout: 60_000 } ); // Nothing new: all of it is here already.
	} );

	test( 'editor panel starts a conversation about the post', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		const post = boot.targets[ 0 ];
		await page.goto( '/wp-admin/post.php?action=edit&post=' + post.id + Q, { waitUntil: 'domcontentloaded' } );
		await page.locator( '.editor-header, .edit-post-header' ).first().waitFor( { timeout: 90_000 } );
		// The panel lives in the document sidebar; open it if this session has it closed.
		const settings = page.getByRole( 'button', { name: /^(Einstellungen|Settings)$/ } ).first();
		if ( 'false' === await settings.getAttribute( 'aria-pressed' ) ) {
			await settings.click();
		}
		const tab = page.getByRole( 'tab', { name: /^(Beitrag|Post)$/ } ).first();
		if ( await tab.count() ) {
			await tab.click();
		}
		const panel = page.locator( '.flurfunk-panel' );
		await panel.waitFor( { timeout: 60_000 } );
		const toggle = panel.locator( 'button.components-panel__body-toggle' );
		if ( 'false' === await toggle.getAttribute( 'aria-expanded' ) ) {
			await toggle.click();
		}
		const ta = panel.locator( '.ff-compose textarea' ).last();
		const text = 'e2e aus dem Editor ' + Date.now();
		await ta.fill( text );
		await panel.locator( '.ff-compose .ff-send' ).last().click();
		await expect( panel.locator( '.ff-box', { hasText: text } ) ).toBeVisible();
	} );
	/*
	 * Card building blocks and actions. Needs the test provider (tests/fixtures/flurfunk-test-provider.php,
	 * see the developer guide); skipped without it. FF_SHOTS=<dir> also saves screenshots.
	 */
	const openCardThread = async ( page, attachments, body ) => {
		const th = await api( page, 'threads', { channel: 'general', body, attachments } );
		await openApp( page );
		await page.locator( `.ff-post[data-id="${ th.id }"]` ).click();
		await page.locator( '.ff-pane .ff-card' ).first().waitFor();
		return th;
	};
	const testCard = async ( page ) => {
		const boot = await api( page, 'bootstrap' );
		test.skip( ! boot.providers.some( ( p ) => 'flurfunk-test/everything' === p.id ), 'test provider not loaded (FLURFUNK_TEST_PROVIDERS)' );
		return openCardThread( page, [ { provider: 'flurfunk-test/everything', ref: { id: 'e2e' + Date.now() } } ], 'e2e card blocks' );
	};

	for ( const theme of [ 'light', 'dark' ] ) {
		test( `card building blocks render accessibly (${ theme })`, async ( { page } ) => {
			await api( page, 'me/prefs', { theme } );
			await testCard( page );
			const c = page.locator( '.ff-pane .ff-card' ).first();
			for ( const sel of [ '.ff-card-status.is-warning', '.ff-card-fields dt', '.ff-card-check li.is-done', '.ff-card-progress [role="progressbar"]', '.ff-card-people .ff-ava', '.ff-card-table th[scope="col"]', '.ff-card-badges li', '.ff-card-quote', '.ff-card-actions .ff-card-btn.is-primary', '.ff-card-actions .ff-card-btn.is-danger' ] ) {
				await expect( c.locator( sel ).first(), sel ).toBeVisible();
			}
			expect( await c.locator( '[role="progressbar"]' ).getAttribute( 'aria-valuenow' ) ).toBe( '3' );
			// No coloured edge stripes: borders are the same on all four sides.
			const sides = await c.locator( '.ff-card-quote, .ff-card-status' ).evaluateAll( ( els ) => els.map( ( e ) => {
				const s = getComputedStyle( e );
				return new Set( [ s.borderTopColor, s.borderRightColor, s.borderBottomColor, s.borderLeftColor ] ).size;
			} ) );
			expect( sides.every( ( n ) => 1 === n ) ).toBe( true );
			const r = await new AxeBuilder( { page } ).include( '.ff-pane .ff-card' ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa' ] ).analyze();
			expect( r.violations.map( ( v ) => v.id + ': ' + v.nodes.slice( 0, 3 ).map( ( n ) => n.target.join( ' ' ) ).join( ' | ' ) ) ).toEqual( [] );
			if ( process.env.FF_SHOTS ) {
				await c.scrollIntoViewIfNeeded();
				await c.screenshot( { path: `${ process.env.FF_SHOTS }/cards-${ theme }.png` } );
				await page.setViewportSize( { width: 390, height: 844 } );
				await openApp( page );
				await page.locator( '.ff-post' ).first().click();
				await page.locator( '.ff-pane .ff-card' ).first().scrollIntoViewIfNeeded();
				await page.screenshot( { path: `${ process.env.FF_SHOTS }/cards-${ theme }-phone.png`, fullPage: false } );
			}
			await api( page, 'me/prefs', { theme: 'light' } );
		} );
	}

	test( 'card actions: inline confirm, keyboard, busy state, error, event line', async ( { page } ) => {
		await testCard( page );
		const c = page.locator( '.ff-pane .ff-card' ).first();
		const approve = c.locator( '.ff-card-actions button[data-act="approve"]' );
		await approve.focus();
		await page.keyboard.press( 'Enter' );
		const confirm = c.locator( '.ff-card-confirm' );
		await expect( confirm ).toBeVisible();
		await expect( confirm.locator( '[data-ff="card-act-go"]' ) ).toBeFocused();
		await expect( c.locator( '.ff-card-actions' ) ).toBeHidden();
		if ( process.env.FF_SHOTS ) {
			await c.screenshot( { path: `${ process.env.FF_SHOTS }/cards-confirm.png` } );
		}
		await page.keyboard.press( 'Escape' );
		await expect( confirm ).toHaveCount( 0 );
		await expect( approve ).toBeFocused();
		await expect( page.locator( '.ff-pane' ) ).toBeVisible(); // Escape did not close the thread.
		// A failing action shows its message inside the card and keeps the buttons usable.
		await c.locator( 'button[data-act="fail"]' ).click();
		await expect( c.locator( '.ff-card-error[role="alert"]' ) ).toContainText( 'The mail service did not answer.' );
		await expect( c.locator( 'button[data-act="fail"]' ) ).toBeEnabled();
		// Busy while the request runs.
		await page.route( ( u ) => decodeURIComponent( u.href ).includes( '/action/approve' ), async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 600 ) );
			await route.continue();
		} );
		await approve.click();
		await c.locator( '[data-ff="card-act-go"]' ).click();
		await expect( c.locator( '[data-ff="card-act-go"][aria-busy="true"]' ) ).toBeVisible();
		await expect( page.locator( '.ff-pane .ff-card .ff-card-status.is-positive' ) ).toBeVisible();
		await expect( page.locator( '.ff-pane .ff-card button[data-act="approve"]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.ff-pane .ff-card button[data-act="reopen"]' ) ).toBeVisible();
		const boot = await api( page, 'bootstrap' );
		await expect( page.locator( '.ff-pane .ff-event', { hasText: boot.me.name } ).last() ).toContainText( 'Spring newsletter' );
		if ( process.env.FF_SHOTS ) {
			await page.locator( '.ff-pane' ).screenshot( { path: `${ process.env.FF_SHOTS }/cards-after.png` } );
		}
	} );

	test( 'comment card: approve and trash from the thread', async ( { page } ) => {
		const boot = await api( page, 'bootstrap' );
		test.skip( ! boot.providers.some( ( p ) => 'flurfunk/comment' === p.id ), 'comment cards module is off' );
		const wp = ( path, method = 'GET', body ) => page.evaluate( async ( [ p, m, b ] ) => {
			const r = await fetch( '/wp-json/wp/v2/' + p, { method: m, headers: { 'X-WP-Nonce': window.flurfunkConfig.nonce, 'Content-Type': 'application/json' }, body: b ? JSON.stringify( b ) : undefined } );
			return r.json();
		}, [ path, method, body ] );
		const post = ( await wp( 'posts?per_page=1&status=publish' ) )[ 0 ];
		const comment = await wp( 'comments', 'POST', { post: post.id, content: 'e2e comment ' + Date.now(), author_name: 'E2E Eve', author_email: 'eve@example.test', status: 'hold' } );
		try {
			await openCardThread( page, [ { provider: 'flurfunk/comment', ref: { id: comment.id } } ], 'e2e comment card' );
			const c = page.locator( '.ff-pane .ff-card' ).first();
			await expect( c.locator( '.ff-card-status.is-warning' ) ).toBeVisible();
			await expect( c ).not.toContainText( 'eve@example.test' );
			await expect( c.locator( '.ff-card-quote' ) ).toContainText( 'e2e comment' );
			await c.locator( 'button[data-act="approve"]' ).click();
			await expect( page.locator( '.ff-pane .ff-card .ff-card-status.is-positive' ) ).toBeVisible();
			expect( ( await wp( 'comments/' + comment.id + '?context=edit' ) ).status ).toBe( 'approved' );
			await page.locator( '.ff-pane .ff-card button[data-act="trash"]' ).click();
			await page.locator( '.ff-pane .ff-card [data-ff="card-act-go"]' ).click();
			await expect( page.locator( '.ff-pane .ff-card .ff-card-status.is-neutral' ) ).toBeVisible();
			await expect( page.locator( '.ff-pane .ff-card .ff-card-btn' ) ).toHaveCount( 0 );
			expect( ( await wp( 'comments/' + comment.id + '?context=edit' ) ).status ).toBe( 'trash' );
			await expect( page.locator( '.ff-pane .ff-event' ).last() ).toContainText( boot.me.name );
		} finally {
			await wp( 'comments/' + comment.id + '?force=true', 'DELETE' );
		}
	} );

} );

test.describe( 'full screen', () => {
	test( 'logged out it asks for the login and comes back to the same view', async ( { page } ) => {
		await page.goto( '/?flurfunk_app=1&view=mentions' );
		await expect( page ).toHaveURL( /wp-login\.php\?.*redirect_to=/ );
		expect( decodeURIComponent( decodeURIComponent( page.url() ) ) ).toContain( 'view=mentions' );
	} );

	test( 'runs without wp-admin, installs as web app, fits a phone', async ( { page } ) => {
		await login( page, process.env.FF_TEAM, process.env.FF_TEAM_PASS );
		await openApp( page );
		const cfg = await page.evaluate( () => window.flurfunkConfig );
		expect( cfg.standalone ).toBe( false );
		await page.locator( '.ff-me-tile' ).click();
		const open = page.locator( '.ff-settings a', { hasText: /Vollbild|full screen/i } );
		expect( await open.getAttribute( 'href' ) ).toContain( cfg.fullUrl.split( '?' )[ 0 ] );
		await open.click();
		await page.waitForSelector( '.ff-app .ff-me-tile' );
		expect( page.url().startsWith( cfg.fullUrl.split( '?' )[ 0 ] ) ).toBe( true );
		expect( await page.locator( '#wpadminbar, #adminmenu, #wpfooter' ).count() ).toBe( 0 );
		expect( await page.evaluate( () => window.flurfunkConfig.standalone ) ).toBe( true );

		// Fills the window.
		const h = await page.locator( '.ff-app' ).evaluate( ( e ) => e.getBoundingClientRect().height );
		expect( Math.abs( h - page.viewportSize().height ) ).toBeLessThanOrEqual( 1 );

		// Web app: manifest (fetched without cookies, like browsers do) and home screen tags.
		const m = await page.evaluate( async () => ( await fetch( document.querySelector( 'link[rel="manifest"]' ).href, { credentials: 'omit' } ) ).json() );
		expect( m.start_url ).toBe( cfg.fullUrl );
		expect( m.display ).toBe( 'standalone' );
		expect( m.start_url.startsWith( m.scope ) ).toBe( true );
		for ( const size of [ '192x192', '512x512' ] ) {
			expect( m.icons.some( ( i ) => i.sizes === size ), size ).toBe( true );
		}
		await expect( page.locator( 'link[rel="apple-touch-icon"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'meta[name="viewport"]' ) ).toHaveAttribute( 'content', /viewport-fit=cover/ );

		// Works: a new thread.
		const text = 'e2e Vollbild ' + Date.now();
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( text );
		await ta.press( 'Control+Enter' );
		await expect( page.locator( '.ff-pane .ff-root-msg' ) ).toContainText( text );

		// Phone width: one column, no sideways scrolling.
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.reload();
		await page.waitForSelector( '.ff-app .ff-me-tile' );
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) ).toBe( true );
		await page.locator( '.ff-post' ).first().click();
		await expect( page.locator( '.ff-pane textarea' ) ).toBeVisible();
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) ).toBe( true );
		await page.locator( '.ff-back' ).click();

		// The way back to wp-admin.
		await page.locator( '.ff-me-tile' ).click();
		await expect( page.locator( '.ff-settings a', { hasText: /Vollbild|full screen/i } ) ).toHaveCount( 0 );
		await expect( page.locator( '.ff-settings a', { hasText: /Zurück zu WordPress|Back to WordPress/ } ) ).toHaveAttribute( 'href', cfg.appUrl );
	} );
} );

test.describe( 'guest', () => {
	test( 'sees only allowed channels and commands', async ( { page } ) => {
		test.skip( ! process.env.FF_GUEST, 'no guest account' );
		await login( page, process.env.FF_GUEST, process.env.FF_GUEST_PASS );
		await openApp( page );
		const boot = await api( page, 'bootstrap' );
		expect( boot.me.kind ).toBe( 'guest' );
		for ( const c of boot.channels ) {
			expect( c.level, c.id ).toBe( 'own-posts' );
		}
		const ta = page.locator( '.ff-new-thread textarea' );
		await ta.click();
		await ta.pressSequentially( '/' );
		const cmds = await page.locator( '.ff-new-thread .ff-menu button b' ).allTextContents();
		expect( cmds.join( ' ' ) ).not.toMatch( /newsletter|dossier/ );
		const opts = await page.locator( '.ff-new-thread select[name="target"] option' ).allTextContents();
		expect( opts.join( ' ' ) ).not.toMatch( /nichts|nothing/ );
	} );
} );

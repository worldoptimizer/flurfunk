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

// One person runs a website with AI assistants – Flurfunk as a hub, not as a team chat: a channel
// per area (#inhalte, #technik, #seo), each with its own assistant that answers every new thread
// there, and an organising assistant that keeps the overview. It shows how far that goes today:
// steps marked WARN are known limits (an assistant never administers the site; it can only use the
// abilities the site has).
//
// Like newsroom-day.mjs: real AI, a LOCAL test site only, not part of the test suite.
//
//   cd tests/e2e && FF_BASE=http://localhost:9601 node admin-hub.mjs
//
//   FF_BASE, FF_ADMIN, FF_ADMIN_PASS   site and administrator (default http://localhost:9601, admin / password)
//   FF_HUB_MODEL                       model of the content assistant (default claude-sonnet-5-5)
//   FF_HUB_REPORT                      report file (default test-results/admin-hub.md)
//   FF_HUB_ONLY                        only the steps whose title matches (a regular expression), e.g.
//                                      "footer|look" – to try one step again without the whole run
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.FF_BASE || 'http://localhost:9601';
const MODEL = process.env.FF_HUB_MODEL || 'claude-sonnet-5-5';
const REPORT = process.env.FF_HUB_REPORT || 'test-results/admin-hub.md';
const ONLY = process.env.FF_HUB_ONLY ? new RegExp( process.env.FF_HUB_ONLY, 'i' ) : null;
const started = Date.now();
const steps = [];
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
const clip = ( s, n = 300 ) => String( s || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, n );

const browser = await chromium.launch();
const max = await ( await browser.newContext( { baseURL: BASE, viewport: { width: 1280, height: 860 }, locale: 'de-DE' } ) ).newPage();
await max.goto( '/wp-login.php', { waitUntil: 'load' } );
await max.fill( '#user_login', process.env.FF_ADMIN || 'admin' );
await max.fill( '#user_pass', process.env.FF_ADMIN_PASS || 'password' );
await max.click( '#wp-submit' );
await max.waitForURL( /wp-admin/ );
const app = async ( view = '' ) => {
	await max.goto( '/wp-admin/admin.php?page=flurfunk' + ( view ? '&view=' + view : '' ), { waitUntil: 'domcontentloaded' } );
	await max.waitForSelector( '.ff-app .ff-me-tile', { timeout: 30000 } );
};
const settings = () => max.goto( '/wp-admin/options-general.php?page=flurfunk-settings', { waitUntil: 'domcontentloaded' } );
const api = ( route, body, method ) => max.evaluate( async ( [ r, b, m ] ) => {
	try {
		return await window.Flurfunk.api( r, b || m ? { method: m || 'POST', body: b } : {} );
	} catch ( e ) {
		return { error: String( ( e && ( e.message || e.code ) ) || e ) };
	}
}, [ route, body, method ] );
const wp = ( route, body, method ) => max.evaluate( async ( [ r, b, m ] ) => {
	const nonce = window.flurfunkConfig?.nonce || ( await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() );
	return ( await fetch( '/wp-json/wp/v2/' + r, { method: m || ( b ? 'POST' : 'GET' ), headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: b ? JSON.stringify( b ) : undefined } ) ).json();
}, [ route, body, method ] );
const tick = () => max.evaluate( () => fetch( '/wp-cron.php?doing_wp_cron=' + Date.now() / 1000 ).then( () => 1, () => 0 ) );
const lastId = async ( tid ) => Math.max( 0, ...( ( await api( 'threads/' + tid ) ).messages || [] ).map( ( m ) => m.id ) );
let AGENTS = [];
let NAMES = {};
async function watch( tid, after, { timeout = 150000, quiet = 0 } = {} ) {
	const t0 = Date.now();
	let view;
	while ( Date.now() - t0 < ( quiet || timeout ) ) {
		await tick();
		await sleep( 2500 );
		view = await api( 'threads/' + tid );
		const fresh = ( view.messages || [] ).filter( ( m ) => m.id > after && 'message' === m.type && AGENTS.includes( m.author ) );
		if ( fresh.length && ! quiet && ! ( view.thinking || [] ).length ) {
			return { msgs: fresh, view };
		}
	}
	return { msgs: ( ( view && view.messages ) || [] ).filter( ( m ) => m.id > after && 'message' === m.type && AGENTS.includes( m.author ) ), view };
}
const said = ( r ) => r.msgs.map( ( m ) => NAMES[ m.author ] + ': ' + clip( m.text, 400 ) ).join( ' | ' );
const cardOf = ( r ) => r.msgs.flatMap( ( m ) => m.attachments || [] ).find( ( c ) => /ai-proposal|draft/.test( c.provider ) );
// Apply the first card of an answer in the thread, look at the result, then undo it.
async function useCard( r, between ) {
	await app( 'thread:' + r.id );
	await max.waitForSelector( '.ff-pane .ff-card', { timeout: 20000 } );
	await max.locator( '.ff-pane .ff-card button', { hasText: /Übernehmen|Apply/ } ).first().click();
	await sleep( 1500 );
	const confirm = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Übernehmen|Apply|Ausführen|Run|Ja|Yes/ } ).last();
	if ( await confirm.count() ) {
		await confirm.click();
	}
	await sleep( 4000 );
	const applied = clip( await max.locator( '.ff-pane .ff-card' ).first().innerText(), 200 );
	const seen = between ? await between() : null;
	const undoBtn = max.locator( '.ff-pane .ff-card button', { hasText: /Rückgängig|Undo/ } ).first();
	let undone = '';
	if ( await undoBtn.count() ) {
		await undoBtn.click();
		await sleep( 1500 );
		const c2 = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Rückgängig|Undo|Ja|Yes/ } ).last();
		if ( await c2.count() ) {
			await c2.click();
		}
		await sleep( 4000 );
		undone = clip( await max.locator( '.ff-pane .ff-card' ).first().innerText(), 200 );
	}
	await app();
	return { applied, seen, undone };
}
const page = ( path ) => max.evaluate( async ( p ) => ( await fetch( p ) ).text(), path );
const aboutPage = async () => ( ( await wp( 'pages?status=publish&search=' + encodeURIComponent( 'Über uns' ) + '&orderby=date&order=desc&context=edit' ) ) || [] )[ 0 ];
async function step( title, fn ) {
	if ( ONLY && ! ONLY.test( title ) ) {
		return;
	}
	const t0 = Date.now();
	let res;
	try {
		res = await fn();
	} catch ( e ) {
		res = { ok: false, note: 'Error: ' + clip( e.message, 300 ) };
	}
	const mark = 'warn' === res.ok ? 'WARN' : res.ok ? 'PASS' : 'FAIL';
	steps.push( { title, mark, note: res.note || '', secs: Math.round( ( Date.now() - t0 ) / 1000 ) } );
	console.log( `${ mark }  ${ title }${ res.note ? '\n      ' + clip( res.note, 500 ) : '' }` );
}
// Ask in an area's channel: a new thread there (its assistant answers every new one), or a reply.
async function ask( channel, text, thread = 0, opts = {} ) {
	const after = thread ? await lastId( thread ) : 0;
	const t = thread ? { id: thread } : await api( 'threads', { channel, body: text } );
	if ( thread ) {
		await api( 'threads/' + thread + '/messages', { body: text } );
	}
	return { id: t.id, ...( await watch( t.id, after, opts ) ) };
}

/* ---------------------------------------------------------------- setup */
const findUser = async ( name ) => ( await wp( 'users?search=' + encodeURIComponent( name ) + '&context=edit' ) ).find( ( u ) => u.name === name )?.id || 0;
for ( const [ name, topic ] of [ [ 'Inhalte', 'Beiträge, Entwürfe, Korrekturen' ], [ 'Technik', 'Zustand der Website' ], [ 'SEO', 'Titel, Auszüge, Beschreibungen' ] ] ) {
	await settings();
	const exists = await max.evaluate( ( n ) => [ ...document.querySelectorAll( 'input[name^="channels["][name$="[name]"]' ) ].some( ( i ) => i.value === n ), name );
	if ( ! exists ) {
		await max.fill( 'input[name="new_channel[name]"]', name );
		await max.fill( 'input[name="new_channel[topic]"]', topic );
		await max.selectOption( 'select[name="new_channel[access_team]"]', 'all' );
		await max.selectOption( 'select[name="new_channel[access_guests]"]', 'none' );
		await max.locator( 'form:has([name="new_channel[name]"]) [type=submit]' ).first().click();
		await max.waitForLoadState( 'domcontentloaded' );
	}
	if ( ! ( await findUser( name + '-KI' ) ) ) {
		await settings();
		await max.fill( 'input[name="new_bot[name]"]', name + '-KI' );
		await max.locator( 'form:has([name="new_bot[name]"]) [type=submit]' ).first().click();
		await max.waitForLoadState( 'domcontentloaded' );
	}
}
const INHALTE = await findUser( 'Inhalte-KI' );
const TECHNIK = await findUser( 'Technik-KI' );
const SEO = await findUser( 'SEO-KI' );
const PLAN = await findUser( 'Assistenz' );
AGENTS = [ INHALTE, TECHNIK, SEO, PLAN ].filter( Boolean );
NAMES = { [ INHALTE ]: 'Inhalte-KI', [ TECHNIK ]: 'Technik-KI', [ SEO ]: 'SEO-KI', [ PLAN ]: 'Assistenz' };
await settings();
const before = await max.evaluate( ( ids ) => [ ...new FormData( document.querySelector( '#flurfunk-ai form' ) ).entries() ].filter( ( [ k ] ) => ids.some( ( id ) => k.startsWith( `ai[${ id }]` ) ) ), AGENTS );
const packsAvailable = await max.evaluate( () => [ ...new Set( [ ...document.querySelectorAll( 'input[name$="[packs][]"]' ) ].filter( ( i ) => ! i.disabled ).map( ( i ) => i.value ) ) ] );
await max.evaluate( ( [ I, T, S, P, model ] ) => {
	const q = ( n ) => document.querySelector( `[name="${ n }"]` );
	const tick = ( id, field, vals ) => document.querySelectorAll( `input[name="ai[${ id }][${ field }][]"]` ).forEach( ( i ) => {
		if ( ! i.disabled ) {
			i.checked = vals.includes( i.value );
		}
	} );
	const set = ( id, role, models, packs, channels, auto, instructions ) => {
		q( `ai[${ id }][on]` ).checked = true;
		q( `ai[${ id }][role]` ).value = role;
		q( `ai[${ id }][models]` ).value = models;
		q( `ai[${ id }][instructions]` ).value = instructions;
		tick( id, 'packs', packs );
		tick( id, 'channels', channels );
		tick( id, 'auto', auto );
	};
	set( I, 'Schreibt Entwürfe, baut Seiten, pflegt das Design', model, [ 'proofread', 'drafts', 'edits', 'lookup', 'design' ], [ 'inhalte' ], [ 'inhalte' ], 'Du betreust die Inhalte und das Aussehen dieser Website für ihre Betreiberin. Arbeite selbstständig und knapp.' );
	set( T, 'Technik, Code und Kommentare', model, [ 'tech', 'lookup', 'code', 'comments' ], [ 'technik' ], [ 'technik' ], 'Du kümmerst dich um die Technik dieser Website, ihre Plugins und die Kommentare. Sag klar, was du sehen kannst und was nicht.' );
	set( S, 'Titel, Auszüge, Beschreibungen und die Kennzahlen', 'claude-haiku-5-5', [ 'proofread', 'edits', 'seo', 'insights' ], [ 'seo', 'inhalte' ], [ 'seo' ], 'Du kümmerst dich um SEO und die Kennzahlen: Titel, Auszüge, Beschreibungen, ob Maßnahmen wirken. Schlag Änderungen als Karte vor.' );
	tick( P, 'channels', [ 'redaktion', 'inhalte', 'technik', 'seo' ] );
	tick( P, 'packs', [ 'lookup', 'tasks' ] );
}, [ INHALTE, TECHNIK, SEO, PLAN, MODEL ] );
await max.locator( '#flurfunk-ai form [type=submit]' ).last().click();
await max.waitForLoadState( 'domcontentloaded' );
// The module "Comment cards" (comments as cards with buttons) on.
await settings();
if ( ! ( await max.locator( 'input[type=checkbox][name="modules[flurfunk/comments]"]' ).isChecked() ) ) {
	await max.locator( 'input[type=checkbox][name="modules[flurfunk/comments]"]' ).check();
	await max.locator( 'form:has([name="modules[flurfunk/comments]"]) [type=submit]' ).first().click();
	await max.waitForLoadState( 'domcontentloaded' );
}
await app();
const created = [];
const devApi = ( route, body ) => max.evaluate( async ( [ r, b ] ) => ( await fetch( '/wp-json/flurfunk-dev/v1/' + r, { method: 'POST', headers: { 'X-WP-Nonce': window.flurfunkConfig.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify( b ) } ) ).json(), [ route, body ] );

/* ---------------------------------------------------------------- the hub */
await step( 'The hub: every area assistant can be asked in private too, the channels are there', async () => {
	const b = await api( 'bootstrap' );
	const priv = ( b.private || [] ).map( ( p ) => p.name );
	return { ok: [ 'Inhalte-KI', 'Technik-KI', 'SEO-KI' ].every( ( n ) => priv.includes( n ) ) && [ 'inhalte', 'technik', 'seo' ].every( ( c ) => b.channels.some( ( x ) => x.id === c ) ), note: 'private: ' + priv.join( ', ' ) + ' · packs available on this site: ' + packsAvailable.join( ', ' ) };
} );
let tech;
await step( 'Technik: "how is the site?" – a new thread in #technik, answered without @', async () => {
	tech = await ask( 'technik', 'Wie geht es der Website? Welche WordPress- und PHP-Version laufen, und ist irgendwas auffällig?' );
	return { ok: tech.msgs.length && /PHP[^.\n]{0,40}\d+\.\d+/.test( said( tech ) ), note: said( tech ) }; // A version number, not only the word.
} );
await step( 'Technik: how to extend a plugin – it searches the code and names files and lines', async () => {
	const r = await ask( 'technik', 'Ich möchte für Flurfunk einen eigenen Slash-Befehl bauen, der eine Karte mit unseren Vereinsterminen zeigt. Wie geht das, welche Funktion und welche Hooks brauche ich? Schau bitte in den Code.', 0, { timeout: 240000 } );
	return { ok: r.msgs.length && /flurfunk_register_provider/.test( said( r ) ) && /\.php/.test( said( r ) ), note: said( r ) };
} );
await step( 'Technik: plugin updates and security – it should say what it cannot see (a known limit)', async () => {
	const r = await ask( 'technik', 'Gibt es Updates für Plugins oder Sicherheitsprobleme?', tech.id );
	return { ok: r.msgs.length ? 'warn' : false, note: said( r ) };
} );
await step( 'Technik: "update all plugins" – it never administers the site (by design)', async () => {
	const r = await ask( 'technik', 'Dann aktualisiere bitte alle Plugins.', tech.id );
	return { ok: r.msgs.length ? 'warn' : false, note: said( r ) };
} );
await step( 'Inhalte: a draft from a few bullet points, as a card', async () => {
	const r = await ask( 'inhalte', 'Schreib mir bitte einen Entwurf: "Fünf Tipps für ein barrierefreies Vereinsfest" – Rampen, Toiletten, leichte Sprache auf Plakaten, Ruheraum, Ansprechperson. Mit Zwischenüberschriften.', 0, { timeout: 200000 } );
	const drafts = await wp( 'posts?status=draft&search=' + encodeURIComponent( 'barrierefrei' ) + '&context=edit' );
	for ( const d of drafts || [] ) {
		created.push( d.id );
	}
	return { ok: r.msgs.length && ( drafts || [] ).length, note: said( r ) + ' · drafts: ' + ( drafts || [] ).map( ( d ) => d.title.raw ).join( ', ' ) };
} );
await step( 'Inhalte: proofread the latest posts, corrections as one card the owner applies', async () => {
	// A fresh post with a few slips, so there is always something to correct (removed at the end).
	const typo = await wp( 'posts', { title: 'Neues vom Vorstand', status: 'publish', content: '<!-- wp:paragraph --><p>Der Vorstand hat sich am Dienstag getroffen und beschlossen, das das Sommerfest dieses Jahr im Stadtpark statt findet. Alle Mitgleider sind herzlich eingeladen.</p><!-- /wp:paragraph -->' } );
	if ( typo?.id ) {
		created.push( typo.id );
	}
	const r = await ask( 'inhalte', 'Lies bitte die drei neuesten veröffentlichten Beiträge gegen und schlag die Korrekturen direkt als Karte vor.', 0, { timeout: 200000 } );
	const c = cardOf( r );
	if ( ! c ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	await app( 'thread:' + r.id );
	await max.waitForSelector( '.ff-pane .ff-card', { timeout: 20000 } );
	await max.locator( '.ff-pane .ff-card button', { hasText: /Übernehmen|Apply/ } ).last().click();
	await sleep( 1500 );
	const confirm = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Übernehmen|Apply|Ja|Yes/ } ).last();
	if ( await confirm.count() ) {
		await confirm.click();
	}
	await sleep( 4000 );
	const state = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 160 );
	await app();
	return { ok: /Übernommen|Applied|geändert|Changed/i.test( state ), note: said( r ) + ' · card: ' + state };
} );
await step( 'SEO: posts without an excerpt – suggestions as a card', async () => {
	const r = await ask( 'seo', 'Welche veröffentlichten Beiträge haben noch keinen Auszug? Schlag für sie passende Auszüge vor.', 0, { timeout: 200000 } );
	const c = cardOf( r );
	return { ok: r.msgs.length && c ? true : r.msgs.length ? 'warn' : false, note: said( r ) + ( c ? ' · card: ' + clip( c.snapshot?.data?.line || c.snapshot?.title, 120 ) : ' · no card' ) };
} );
await step( 'Assistants hand work to each other: Inhalte writes, then hands the excerpt to SEO', async () => {
	const r = await ask( 'inhalte', 'Schreib bitte einen kurzen Entwurf "Neue Öffnungszeiten der Geschäftsstelle" (Mo–Do 9–12 Uhr) und lass die SEO-KI direkt einen Auszug dafür vorschlagen.', 0, { timeout: 200000 } );
	let all = r;
	if ( ! r.msgs.some( ( m ) => SEO === m.author ) ) {
		all = await watch( r.id, Math.max( ...r.msgs.map( ( m ) => m.id ), 0 ), { timeout: 150000 } );
	}
	const fromSeo = ( all.view?.messages || [] ).filter( ( m ) => SEO === m.author );
	const meta = fromSeo.length ? ( all.view.ai_meta || {} )[ fromSeo[ 0 ].id ] || {} : {};
	const drafts = await wp( 'posts?status=draft&search=' + encodeURIComponent( 'Öffnungszeiten' ) + '&context=edit' );
	for ( const d of drafts || [] ) {
		created.push( d.id );
	}
	const later = fromSeo.filter( ( m ) => ! r.msgs.some( ( x ) => x.id === m.id ) );
	return { ok: fromSeo.length && INHALTE === meta.from, note: said( r ) + ( later.length ? ' || ' + later.map( ( m ) => 'SEO-KI: ' + clip( m.text, 300 ) ).join( ' ' ) : '' ) + ' · handed over by: ' + ( NAMES[ meta.from ] || '–' ) };
} );
await step( 'A page with a layout: "about us" with two columns and a button, as a draft', async () => {
	const r = await ask( 'inhalte', 'Bau mir bitte eine Seite "Über uns": links zwei Absätze über den Verein (gegründet 1987, 240 Mitglieder, Stadtteilarbeit in der Südstadt), rechts Kontakt (Geschäftsstelle, Marktstraße 4), darunter ein Button "Mitglied werden". Als Entwurf.', 0, { timeout: 240000 } );
	const pages = await wp( 'pages?status=draft&search=' + encodeURIComponent( 'Über uns' ) + '&context=edit' );
	for ( const pg of pages || [] ) {
		created.push( pg.id );
	}
	const html = ( pages || [] ).map( ( pg ) => pg.content?.raw || '' ).join( '' );
	return { ok: ( pages || [] ).length && /wp:columns/.test( html ) && /wp:button/.test( html ), note: said( r ) + ' · page blocks: ' + [ ...new Set( ( html.match( /wp:[a-z-]+/g ) || [] ) ) ].join( ', ' ) };
} );
await step( 'The look: square buttons and dark green links as a card – applied, then undone', async () => {
	const r = await ask( 'inhalte', 'Mach bitte die Buttons auf der ganzen Seite eckig und die Links dunkelgrün.', 0, { timeout: 240000 } );
	const c = cardOf( r );
	if ( ! c ) {
		return { ok: false, note: said( r ) + ' · no card' };
	}
	await app( 'thread:' + r.id );
	await max.waitForSelector( '.ff-pane .ff-card', { timeout: 20000 } );
	await max.locator( '.ff-pane .ff-card button', { hasText: /Übernehmen|Apply/ } ).last().click();
	await sleep( 1500 );
	const confirm = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Übernehmen|Apply|Ausführen|Run|Ja|Yes/ } ).last();
	if ( await confirm.count() ) {
		await confirm.click();
	}
	await sleep( 4000 );
	const applied = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 200 );
	const undoBtn = max.locator( '.ff-pane .ff-card button', { hasText: /Rückgängig|Undo/ } ).last();
	let undone = '';
	if ( await undoBtn.count() ) {
		await undoBtn.click();
		await sleep( 1500 );
		const c2 = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Rückgängig|Undo|Ja|Yes/ } ).last();
		if ( await c2.count() ) {
			await c2.click();
		}
		await sleep( 4000 );
		undone = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 200 );
	}
	await app();
	return { ok: /border-radius|radius/i.test( JSON.stringify( c.snapshot ) ) && undone ? true : 'warn', note: said( r ) + ' · applied: ' + applied + ' · undone: ' + ( undone || 'no undo button' ) };
} );
await step( 'The theme: a line in the footer as a template card – applied, then undone', async () => {
	const r = await ask( 'inhalte', 'Füg bitte im Fußbereich der Website eine Zeile "© 2026 Verein Südstadt e.V." hinzu.', 0, { timeout: 240000 } );
	const c = cardOf( r );
	if ( ! c ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	await app( 'thread:' + r.id );
	await max.waitForSelector( '.ff-pane .ff-card', { timeout: 20000 } );
	await max.locator( '.ff-pane .ff-card button', { hasText: /Übernehmen|Apply/ } ).last().click();
	await sleep( 1500 );
	const confirm = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Übernehmen|Apply|Ausführen|Run|Ja|Yes/ } ).last();
	if ( await confirm.count() ) {
		await confirm.click();
	}
	await sleep( 4000 );
	const applied = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 200 );
	const home = await max.evaluate( async () => ( await fetch( '/' ) ).text() );
	const shown = /Verein Südstadt e\.V\./.test( home );
	const undoBtn = max.locator( '.ff-pane .ff-card button', { hasText: /Rückgängig|Undo/ } ).last();
	let undone = '';
	if ( await undoBtn.count() ) {
		await undoBtn.click();
		await sleep( 1500 );
		const c2 = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Rückgängig|Undo|Ja|Yes/ } ).last();
		if ( await c2.count() ) {
			await c2.click();
		}
		await sleep( 4000 );
		undone = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 200 );
	}
	await app();
	return { ok: shown && undone ? true : 'warn', note: said( r ) + ' · applied: ' + applied + ' · on the site: ' + ( shown ? 'yes' : 'no' ) + ' · undone: ' + ( undone || 'no undo button' ) };
} );
await step( 'The menu: only two chosen links instead of all pages, as a card – applied, then undone', async () => {
	const menu = ( ( await wp( 'navigation?context=edit&per_page=1&orderby=date&order=desc' ) ) || [] )[ 0 ];
	// Max has read the "about us" draft and published it (assistants only write drafts).
	const about = ( ( await wp( 'pages?status=draft&search=' + encodeURIComponent( 'Über uns' ) + '&orderby=date&order=desc&context=edit' ) ) || [] )[ 0 ];
	if ( about ) {
		await wp( 'pages/' + about.id, { status: 'publish' } );
	}
	const r = await ask( 'inhalte', 'Im Menü oben sollen nur noch "Startseite" und "Über uns" stehen, in dieser Reihenfolge – nicht mehr alle Seiten.', 0, { timeout: 240000 } );
	const c = cardOf( r );
	if ( ! c ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	await app( 'thread:' + r.id );
	await max.waitForSelector( '.ff-pane .ff-card', { timeout: 20000 } );
	await max.locator( '.ff-pane .ff-card button', { hasText: /Übernehmen|Apply/ } ).last().click();
	await sleep( 1500 );
	const confirm = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Übernehmen|Apply|Ausführen|Run|Ja|Yes/ } ).last();
	if ( await confirm.count() ) {
		await confirm.click();
	}
	await sleep( 4000 );
	const applied = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 200 );
	const after = menu ? ( await wp( 'navigation/' + menu.id + '?context=edit' ) )?.content?.raw || '' : '';
	const undoBtn = max.locator( '.ff-pane .ff-card button', { hasText: /Rückgängig|Undo/ } ).last();
	let undone = '';
	if ( await undoBtn.count() ) {
		await undoBtn.click();
		await sleep( 1500 );
		const c2 = max.locator( '.ff-pane .ff-card-confirm button', { hasText: /Rückgängig|Undo|Ja|Yes/ } ).last();
		if ( await c2.count() ) {
			await c2.click();
		}
		await sleep( 4000 );
		undone = clip( await max.locator( '.ff-pane .ff-card' ).last().innerText(), 200 );
	}
	const back = menu ? ( await wp( 'navigation/' + menu.id + '?context=edit' ) )?.content?.raw || '' : '';
	await app();
	const links = ( after.match( /"label":"[^"]+"/g ) || [] ).join( ', ' );
	return { ok: /wp:navigation-link|wp:home-link/.test( after ) && ! /wp:page-list/.test( after ) && back === menu?.content?.raw ? true : 'warn', note: said( r ) + ' · applied: ' + applied + ' · menu: ' + ( links || clip( after, 160 ) ) + ' · undone: ' + ( undone ? 'yes, back to: ' + clip( back, 80 ) : 'no undo button' ) };
} );
await step( 'A published page changed: a section with dates added at the end, as a card – applied, then undone', async () => {
	const about = await aboutPage();
	if ( ! about ) {
		return { ok: false, note: 'no published "about us" page (the page and menu steps make it)' };
	}
	const r = await ask( 'inhalte', 'Ergänze auf der Seite "Über uns" am Ende einen Abschnitt "Termine" mit einer kurzen Liste: Mitgliederversammlung am 12. März, Sommerfest am 21. Juni.', 0, { timeout: 240000 } );
	if ( ! cardOf( r ) ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	const u = await useCard( r, async () => ( await wp( 'pages/' + about.id + '?context=edit' ) )?.content?.raw || '' );
	const back = ( await wp( 'pages/' + about.id + '?context=edit' ) )?.content?.raw || '';
	const ok = /Termine/.test( u.seen ) && /21\. Juni/.test( u.seen ) && ! /Termine/.test( back );
	return { ok: ok ? true : 'warn', note: said( r ) + ' · applied: ' + u.applied + ' · on the page: ' + ( /Termine/.test( u.seen ) ? 'yes' : 'no' ) + ' · undone: ' + ( /Termine/.test( back ) ? 'no' : 'yes' ) };
} );
await step( 'A word on a published page: the button label, as a card – applied, then undone', async () => {
	const about = await aboutPage();
	if ( ! about ) {
		return { ok: false, note: 'no published "about us" page (the page and menu steps make it)' };
	}
	const r = await ask( 'inhalte', 'Ändere auf der Seite "Über uns" den Button-Text "Mitglied werden" in "Jetzt Mitglied werden".', 0, { timeout: 240000 } );
	if ( ! cardOf( r ) ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	const u = await useCard( r, async () => ( await wp( 'pages/' + about.id + '?context=edit' ) )?.content?.raw || '' );
	const back = ( await wp( 'pages/' + about.id + '?context=edit' ) )?.content?.raw || '';
	return { ok: /Jetzt Mitglied werden/.test( u.seen ) && ! /Jetzt Mitglied werden/.test( back ) ? true : 'warn', note: said( r ) + ' · applied: ' + u.applied + ' · on the page: ' + ( /Jetzt Mitglied werden/.test( u.seen ) ? 'yes' : 'no' ) + ' · undone: ' + ( /Jetzt Mitglied werden/.test( back ) ? 'no' : 'yes' ) };
} );
await step( 'The single post template: date and author under the title, as a card – applied, then undone', async () => {
	const single = async () => ( ( await wp( 'templates?context=edit' ) ) || [] ).find( ( t ) => 'single' === t.slug );
	const before = await single();
	const r = await ask( 'inhalte', 'In einzelnen Beiträgen sollen direkt unter dem Titel das Datum und der Autor stehen, klein und grau.', 0, { timeout: 240000 } );
	if ( ! cardOf( r ) ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	const u = await useCard( r, single );
	const back = await single();
	const has = ( t ) => /wp:post-date/.test( t?.content?.raw || '' ) && /wp:post-author/.test( t?.content?.raw || '' );
	return { ok: has( u.seen ) && 'custom' === u.seen?.source && ( back?.content?.raw || '' ) === ( before?.content?.raw || '' ) ? true : 'warn', note: said( r ) + ' · applied: ' + u.applied + ' · template: ' + ( u.seen?.source || '–' ) + ', date and author: ' + ( has( u.seen ) ? 'yes' : 'no' ) + ' · undone: ' + ( ( back?.content?.raw || '' ) === ( before?.content?.raw || '' ) ? 'yes (back to ' + ( back?.source || '–' ) + ')' : 'no' ) };
} );
await step( 'Typography: larger headings, more line height, a narrower content – as a card, applied, then undone', async () => {
	const r = await ask( 'inhalte', 'Mach bitte die Überschriften etwas größer, die Zeilenhöhe im Fließtext luftiger und den Inhaltsbereich schmaler, etwa 680px.', 0, { timeout: 240000 } );
	const c = cardOf( r );
	if ( ! c ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	const width = /--wp--style--global--content-size:\s*680px/;
	const u = await useCard( r, () => page( '/' ) );
	const back = await page( '/' );
	const input = JSON.stringify( c.snapshot || {} );
	return { ok: width.test( u.seen ) && ! width.test( back ) && /lineHeight|line height|Zeilenh/i.test( input + u.applied ) ? true : 'warn', note: said( r ) + ' · applied: ' + u.applied + ' · content 680px on the site: ' + ( width.test( u.seen ) ? 'yes' : 'no' ) + ' · undone: ' + ( width.test( back ) ? 'no' : 'yes' ) };
} );
await step( 'A new page template "landing page", given to the about page – as a card, applied, then undone', async () => {
	const about = await aboutPage();
	const r = await ask( 'inhalte', 'Leg bitte eine Seitenvorlage "Landingpage" an: oben nur der Seitentitel der Website ohne Menü, dann der Inhalt in voller Breite, unten der normale Fußbereich. Gib sie gleich der Seite "Über uns".', 0, { timeout: 240000 } );
	if ( ! cardOf( r ) ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	const u = await useCard( r, async () => ( {
		tpl: ( ( await wp( 'templates?context=edit' ) ) || [] ).find( ( t ) => /landing/i.test( t.slug ) ),
		given: about ? ( await wp( 'pages/' + about.id + '?context=edit' ) )?.template || '' : '',
	} ) );
	const gone = ! ( ( await wp( 'templates?context=edit' ) ) || [] ).some( ( t ) => /landing/i.test( t.slug ) );
	const given = about ? ( await wp( 'pages/' + about.id + '?context=edit' ) )?.template || '' : '';
	const ok = u.seen?.tpl && u.seen.given === u.seen.tpl.slug && gone && '' === given;
	return { ok: ok ? true : 'warn', note: said( r ) + ' · applied: ' + u.applied + ' · template: ' + ( u.seen?.tpl?.slug || '–' ) + ' (blocks: ' + [ ...new Set( ( ( u.seen?.tpl?.content?.raw || '' ).match( /wp:[a-z-]+/g ) || [] ) ) ].join( ', ' ) + ') · about page uses it: ' + ( u.seen?.given || '–' ) + ' · undone: ' + ( gone && '' === given ? 'yes' : 'no' ) };
} );
await step( 'A pattern to reuse: the contact box of the about page, synced – as a card, applied, then undone', async () => {
	const r = await ask( 'inhalte', 'Speicher bitte den Kontakt-Block von der Seite "Über uns" als Muster, das überall gleich aussieht, wo ich es wiederverwende.', 0, { timeout: 240000 } );
	if ( ! cardOf( r ) ) {
		return { ok: r.msgs.length ? 'warn' : false, note: said( r ) + ' · no card' };
	}
	const mine = async () => ( ( await wp( 'blocks?context=edit&per_page=50' ) ) || [] ).filter( ( b ) => /kontakt|contact/i.test( b.title?.raw || '' ) );
	const before = ( await mine() ).length;
	const u = await useCard( r, mine );
	const after = ( await mine() ).length;
	const made = ( u.seen || [] )[ 0 ];
	const during = ( u.seen || [] ).length;
	return { ok: during > before && after === before ? true : 'warn', note: said( r ) + ' · applied: ' + u.applied + ' · pattern: ' + ( made ? made.title.raw + ' (' + ( made.wp_pattern_sync_status || 'synced' ) + ')' : '–' ) + ' · undone: ' + ( during > before && after === before ? 'yes' : 'no' ) };
} );
await step( 'SEO: the site check, the numbers kept with a note', async () => {
	const r = await ask( 'seo', 'Mach bitte einen SEO-Check der Website und halte die aktuellen Zahlen fest – wir fangen jetzt an, fehlende Auszüge zu ergänzen.', 0, { timeout: 200000 } );
	return { ok: r.msgs.length && /Auszug|Beschreibung|description/i.test( said( r ) ), note: said( r ) };
} );
await step( 'Three weeks later (made-up numbers): did the measure help? It compares, with numbers', async () => {
	await devApi( 'snapshot', { channel: 'seo', note: 'Vor der Auszug-Aktion', days: 21, scale: 0.7, missing: 9 } );
	const r = await ask( 'seo', 'Hat die Auszug-Aktion etwas gebracht? Vergleich bitte mit dem Stand von vor drei Wochen ("Vor der Auszug-Aktion").', 0, { timeout: 200000 } );
	return { ok: r.msgs.length && /%/.test( said( r ) ), note: said( r ) };
} );
await step( 'Monitoring: a weekly routine keeps the numbers and says what changed (run once now, then removed)', async () => {
	const r = await ask( 'seo', 'Halte bitte jeden Montag um 7 Uhr die aktuellen Zahlen fest und vergleiche sie mit dem letzten Stand – schreib kurz, was sich verändert hat.', 0, { timeout: 200000 } );
	const setUp = Math.max( 0, ...r.msgs.map( ( m ) => m.id ) );
	// The week in between: two posts get an excerpt (the made-up visits follow excerpts), put back after.
	const week = ( await wp( 'posts?status=publish&per_page=2&context=edit' ) ) || [];
	for ( const p of week ) {
		await wp( 'posts/' + p.id, { excerpt: 'Kurz zusammengefasst: ' + p.title.raw } );
	}
	const run = await devApi( 'routine', { channel: 'seo' } ); // What would happen next Monday, now.
	for ( const p of week ) {
		await wp( 'posts/' + p.id, { excerpt: p.excerpt.raw } );
	}
	if ( ! run?.id ) {
		return { ok: false, note: said( r ) + ' · no routine: ' + clip( JSON.stringify( run ), 200 ) };
	}
	const where = 'channel' === run.post ? ( await api( 'threads?channel=seo&limit=1&order=new' ) )[ 0 ]?.id : run.thread;
	const report = ( ( await api( 'threads/' + where ) ).messages || [] ).filter( ( m ) => SEO === m.author && m.id > setUp ).pop();
	await devApi( 'routine', { channel: 'seo', remove: true } );
	const kept = run.snapshots_after > run.snapshots_before;
	return { ok: kept && report && /\d/.test( report.text ) ? true : report ? 'warn' : false, note: said( r ) + ' || routine run: ' + ( report ? 'SEO-KI: ' + clip( report.text, 400 ) : 'no report' ) + ' · numbers kept: ' + ( kept ? 'yes' : 'no' ) };
} );
await step( 'The overview: the organising assistant sums up what the area assistants did today', async () => {
	const t = await api( 'threads', { private_with: PLAN, body: 'Was haben die Assistenten heute in #inhalte, #technik und #seo erledigt, und was ist noch offen? Kurz, als Liste.' } );
	const r = await watch( t.id, 0, { timeout: 180000 } );
	return { ok: r.msgs.length && /technik|inhalt|seo/i.test( said( r ) ), note: said( r ) };
} );
await step( 'A weekly site report as a routine (set up, then removed again)', async () => {
	const r = await ask( 'technik', 'Schick mir bitte jeden Montag um 8 Uhr einen kurzen Bericht zum Zustand der Website, als neuen Thread hier.', tech.id );
	const id = await lastId( tech.id );
	await api( 'threads/' + tech.id + '/messages', { body: `<@${ TECHNIK }> danke, lösch die Routine bitte wieder, das war nur ein Test.` } );
	const r2 = await watch( tech.id, id, { timeout: 120000 } );
	return { ok: r.msgs.length && /Montag|8/.test( said( r ) ), note: said( r ) + ' || ' + said( r2 ) };
} );
await step( 'Comments waiting for approval: shown as cards with buttons, spam named', async () => {
	const posts = await wp( 'posts?per_page=1&status=publish' );
	for ( const [ author, content ] of [ [ 'Leserin Lea', 'Toller Artikel! Wann kommt der zweite Teil?' ], [ 'Cheap Pills', 'Best prices!!! https://spam.example/a https://spam.example/b https://spam.example/c' ] ] ) {
		await wp( 'comments', { post: posts[ 0 ].id, author_name: author, author_email: 'x@example.test', content, status: 'hold' } );
	}
	const r = await ask( 'technik', 'Gibt es Kommentare, die auf Freigabe warten?', tech.id );
	const cards = r.msgs.flatMap( ( m ) => m.attachments || [] ).filter( ( c ) => 'flurfunk/comment' === c.provider );
	return { ok: r.msgs.length && cards.length >= 2 && /spam/i.test( said( r ) ), note: said( r ) + ' · comment cards: ' + cards.length };
} );
await step( 'Site settings: "change the tagline" (by design an assistant never administers the site)', async () => {
	const r = await ask( 'technik', 'Ändere bitte den Untertitel der Website auf "Das Vereinsblatt für die Südstadt".', tech.id );
	return { ok: r.msgs.length ? 'warn' : false, note: said( r ) };
} );

/* ---------------------------------------------------------------- report and clean-up */
await settings();
const log = await max.evaluate( () => [ ...document.querySelectorAll( '#flurfunk-ai table.widefat tbody tr' ) ].map( ( r ) => [ ...r.cells ].map( ( c ) => c.textContent.replace( /\s+/g, ' ' ).trim() ) ) );
const today = log.filter( ( row ) => Date.parse( row[ 0 ] + ' UTC' ) >= started - 60000 );
const tokens = today.reduce( ( n, row ) => n + ( parseInt( ( row[ row.length - 1 ] || '0' ).replace( /\D/g, '' ), 10 ) || 0 ), 0 );
await max.evaluate( ( [ entries, ids ] ) => {
	const want = new Map();
	for ( const [ k, v ] of entries ) {
		want.set( k, [ ...( want.get( k ) || [] ), v ] );
	}
	document.querySelectorAll( '#flurfunk-ai form input, #flurfunk-ai form select, #flurfunk-ai form textarea' ).forEach( ( el ) => {
		if ( ! ids.some( ( id ) => el.name.startsWith( `ai[${ id }]` ) ) || 'hidden' === el.type || 'submit' === el.type ) {
			return;
		}
		const vals = want.get( el.name ) || [];
		if ( 'checkbox' === el.type ) {
			el.checked = vals.includes( el.value );
		} else if ( vals.length ) {
			el.value = vals[ 0 ];
		}
	} );
}, [ before, AGENTS ] );
await max.locator( '#flurfunk-ai form [type=submit]' ).last().click();
await max.waitForLoadState( 'domcontentloaded' );
for ( const id of created ) {
	const gone = await wp( 'posts/' + id + '?force=true', null, 'DELETE' );
	if ( gone?.code ) {
		await wp( 'pages/' + id + '?force=true', null, 'DELETE' ); // The "about us" page.
	}
}
const passed = steps.filter( ( s ) => 'PASS' === s.mark ).length;
const warned = steps.filter( ( s ) => 'WARN' === s.mark ).length;
const md = [
	`# Admin hub – ${ new Date().toISOString().slice( 0, 16 ).replace( 'T', ' ' ) } UTC`,
	'',
	`${ BASE } · content model ${ MODEL } · ${ Math.round( ( Date.now() - started ) / 60000 ) } minutes · ${ passed } of ${ steps.length } steps work, ${ warned } known limits · ${ today.length } AI runs and checks, ${ tokens.toLocaleString( 'en' ) } tokens`,
	'',
	...steps.map( ( s ) => `- **${ s.mark }** ${ s.title } (${ s.secs } s)${ s.note ? '\n  ' + s.note.replace( /\n/g, ' ' ) : '' }` ),
	'',
].join( '\n' );
fs.mkdirSync( path.dirname( REPORT ), { recursive: true } );
fs.writeFileSync( REPORT, md );
console.log( `\n${ passed } of ${ steps.length } steps work, ${ warned } known limits. Report: ${ REPORT }` );
await browser.close();

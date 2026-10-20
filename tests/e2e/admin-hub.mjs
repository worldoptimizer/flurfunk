// One person runs a website with AI assistants – Flurfunk as a hub, not as a team chat: a channel
// per area (#inhalte, #technik, #seo), each with its own assistant that answers every new thread
// there, and an organising assistant that keeps the overview. It shows how far that goes today:
// steps marked WARN are known limits (an assistant never administers the site; it can only use the
// abilities the site has; assistants do not hand work to each other).
//
// Like newsroom-day.mjs: real AI, a LOCAL test site only, not part of the test suite.
//
//   cd tests/e2e && FF_BASE=http://localhost:9601 node admin-hub.mjs
//
//   FF_BASE, FF_ADMIN, FF_ADMIN_PASS   site and administrator (default http://localhost:9601, admin / password)
//   FF_HUB_MODEL                       model of the content assistant (default claude-sonnet-5-5)
//   FF_HUB_REPORT                      report file (default test-results/admin-hub.md)
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.FF_BASE || 'http://localhost:9601';
const MODEL = process.env.FF_HUB_MODEL || 'claude-sonnet-5-5';
const REPORT = process.env.FF_HUB_REPORT || 'test-results/admin-hub.md';
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
async function step( title, fn ) {
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
	set( I, 'Schreibt Entwürfe und korrigiert Beiträge', model, [ 'proofread', 'drafts', 'edits', 'lookup' ], [ 'inhalte' ], [ 'inhalte' ], 'Du betreust die Inhalte dieser Website für ihre Betreiberin. Arbeite selbstständig und knapp.' );
	set( T, 'Hat den Zustand der Website im Blick', 'claude-haiku-5-5', [ 'tech', 'lookup' ], [ 'technik' ], [ 'technik' ], 'Du kümmerst dich um die Technik dieser Website. Sag klar, was du sehen kannst und was nicht.' );
	set( S, 'Titel, Auszüge und Beschreibungen für Suchmaschinen', 'claude-haiku-5-5', [ 'proofread', 'edits', 'seo' ], [ 'seo' ], [ 'seo' ], 'Du kümmerst dich um SEO: Titel, Auszüge, Beschreibungen. Schlag Änderungen als Karte vor.' );
	tick( P, 'channels', [ 'redaktion', 'inhalte', 'technik', 'seo' ] );
	tick( P, 'packs', [ 'lookup', 'tasks' ] );
}, [ INHALTE, TECHNIK, SEO, PLAN, MODEL ] );
await max.locator( '#flurfunk-ai form [type=submit]' ).last().click();
await max.waitForLoadState( 'domcontentloaded' );
await app();
const created = [];

/* ---------------------------------------------------------------- the hub */
await step( 'The hub: every area assistant can be asked in private too, the channels are there', async () => {
	const b = await api( 'bootstrap' );
	const priv = ( b.private || [] ).map( ( p ) => p.name );
	return { ok: [ 'Inhalte-KI', 'Technik-KI', 'SEO-KI' ].every( ( n ) => priv.includes( n ) ) && [ 'inhalte', 'technik', 'seo' ].every( ( c ) => b.channels.some( ( x ) => x.id === c ) ), note: 'private: ' + priv.join( ', ' ) + ' · packs available on this site: ' + packsAvailable.join( ', ' ) };
} );
let tech;
await step( 'Technik: "how is the site?" – a new thread in #technik, answered without @', async () => {
	tech = await ask( 'technik', 'Wie geht es der Website? Welche WordPress- und PHP-Version laufen, und ist irgendwas auffällig?' );
	return { ok: tech.msgs.length && /PHP[^.]{0,40}\d+\.\d+/.test( said( tech ) ), note: said( tech ) }; // A version number, not only the word.
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
await step( 'Assistants hand work to each other: Inhalte asks SEO (a known limit: assistants do not wake each other)', async () => {
	const r = await ask( 'inhalte', `<@${ INHALTE }> wenn der Entwurf zum Vereinsfest fertig ist, sag bitte <@${ SEO }> Bescheid, dass er einen Auszug dafür vorschlägt.`, 0, { timeout: 150000 } );
	const seo = r.msgs.some( ( m ) => SEO === m.author );
	const r2 = seo ? r : await watch( r.id, Math.max( ...r.msgs.map( ( m ) => m.id ), 0 ), { quiet: 30000 } );
	const seoLater = r2.msgs.some( ( m ) => SEO === m.author );
	return { ok: seo || seoLater ? true : 'warn', note: said( r ) + ( seo || seoLater ? '' : ' · SEO-KI did not react (assistants do not answer assistants)' ) };
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
await step( 'Comments waiting for approval (a known limit: no ability for comments yet)', async () => {
	const r = await ask( 'technik', 'Gibt es Kommentare, die auf Freigabe warten?', tech.id );
	return { ok: r.msgs.length ? 'warn' : false, note: said( r ) };
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
	await wp( 'posts/' + id + '?force=true', null, 'DELETE' );
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

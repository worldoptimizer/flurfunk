#!/usr/bin/env node
/*
 * Builds the card gallery of the website from the plugin's own code, so it always looks like
 * the app:
 *
 *   docs/_includes/card-gallery.html   every building block, drawn by assets/app.js (light and dark)
 *   docs/assets/cards.css              the card rules and colour tokens from assets/app.css,
 *                                      scoped to .ff-gallery so the site's styles stay apart
 *
 * Run after changing the cards' markup or CSS:  node tools/docs-cards.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const read = ( f ) => fs.readFileSync( path.join( root, f ), 'utf8' );

/* ---------------------------------------------------------------- the helpers from app.js */

const ctx = {
	window: { flurfunkConfig: { i18n: {} } },
	document: { addEventListener() {}, documentElement: { lang: 'en' } },
	navigator: {},
	location: { hash: '' },
	Intl,
	console,
	setTimeout,
	clearTimeout,
};
ctx.window.document = ctx.document;
vm.createContext( ctx );
vm.runInContext( read( 'assets/app.js' ), ctx );
const h = ctx.window.Flurfunk.cardHelpers;

// The frame app.js puts around every stored card (provider label, Update link, action row).
const frame = ( label, command, inner, actions = '' ) => '<div class="ff-card"><span class="ff-card-kind">' + h.esc( label ) + ( command ? ' <small>/' + h.esc( command ) + '</small>' : '' )
	+ ' <button type="button" class="ff-card-refresh" tabindex="-1">Update</button></span>' + inner + actions + '</div>';
// Adds markup at the end of a card body (before its closing tag).
const withBody = ( html, extra ) => html.slice( 0, html.lastIndexOf( '</div>' ) ) + extra + '</div>';
const actionRow = ( list ) => '<div class="ff-card-actions" role="group" aria-label="Actions">' + list.map( ( [ label, style ] ) => h.button( label, { style } ) ).join( '' ) + '</div>';

const people = [ { name: 'Ana Klein' }, { name: 'Jonas Berg' }, { name: 'Mira Okafor' }, { name: 'Tom Weber' }, { name: 'Lea Hoffmann' } ];
const all = {
	title: 'Spring newsletter',
	url: '#',
	subtitle: 'Campaign · goes out Friday, 9:00',
	status: { label: 'In review', tone: 'warning' },
	badges: [ 'Newsletter', 'A/B test' ],
	progress: { value: 3, max: 4, label: 'Checklist' },
	quote: 'Could we move the opening hours to the top? People keep asking.',
	fields: [ { label: 'Lists', value: 'Customers, Partners', url: '#' }, { label: 'Recipients', value: '1,284' } ],
	checklist: [ { label: 'Subject line', done: true }, { label: 'Links checked', done: true }, { label: 'Approved by the editor', done: false } ],
	table: { columns: [ 'Variant', 'Subject', 'Opened' ], rows: [ [ 'A', 'New opening hours from May', '43 %' ], [ 'B', 'We are open longer', '39 %' ] ] },
	meta: [ 'Updated 5 minutes ago' ],
};

// [ id, heading, description, html ]
const items = [
	[ 'title', 'title, subtitle, url, status', 'The head of every card. The title links to url; status is a pill next to it.', frame( 'Newsletter', 'newsletter', h.card( { title: all.title, url: '#', subtitle: all.subtitle, status: { label: 'Scheduled', tone: 'info' } } ) ) ],
	[ 'status', 'status tones', 'neutral, positive, warning, critical and info. The word carries the meaning; the colour only supports it.', frame( 'Status', '', '<div class="ff-card-body"><span class="ff-card-head">' + [ [ 'Draft', 'neutral' ], [ 'Approved', 'positive' ], [ 'Waiting', 'warning' ], [ 'Failed', 'critical' ], [ 'Scheduled', 'info' ] ].map( ( [ l, t ] ) => h.status( l, t ) ).join( ' ' ) + '</span></div>' ) ],
	[ 'fields', 'fields', 'Label and value pairs, optionally linked. They line up in columns and wrap on narrow screens.', frame( 'Order', 'order', h.card( { title: 'Order #1042', fields: [ { label: 'Customer', value: 'Northline GmbH', url: '#' }, { label: 'Total', value: '€ 1,280.00' }, { label: 'Items', value: '3' }, { label: 'Paid', value: 'Yesterday' } ] } ) ) ],
	[ 'checklist', 'checklist', 'Items with a done flag. Screen readers hear "done" or "open" after each item.', frame( 'Release', 'release', h.card( { title: 'Release 2.4', checklist: all.checklist } ) ) ],
	[ 'progress', 'progress', 'value of max (default 100) with an optional label, as an accessible progress bar.', frame( 'Upload', '', h.card( { title: 'Image import', progress: { value: 68, max: 100, label: '68 of 100 images' } } ) ) ],
	[ 'people', 'people', 'User IDs become an avatar stack with names (from the people Flurfunk knows).', frame( 'Shift', '', withBody( h.card( { title: 'Weekend desk' } ), h.people( people ) ) ) ],
	[ 'table', 'table', 'Columns and up to 20 rows of plain text. Wide tables scroll sideways inside the card.', frame( 'Report', 'report', h.card( { title: 'Top articles this week', table: { columns: [ 'Article', 'Views', 'Change' ], rows: [ [ 'Opening hours', '4,210', '+12 %' ], [ 'Summer festival', '2,980', '+3 %' ], [ 'New team', '1,104', '−8 %' ] ] } } ) ) ],
	[ 'badges', 'badges', 'Small labels, up to 8.', frame( 'Post', 'post', h.card( { title: 'Interview with the mayor', badges: [ 'Politics', 'Premium', 'Video' ] } ) ) ],
	[ 'quote', 'quote', 'A block of quoted plain text, e.g. a comment or a customer message.', frame( 'Comment', 'comment', h.card( { title: 'Comment by Eve', status: { label: 'Pending', tone: 'warning' }, quote: 'Great article! Is there a recording of the talk?\nThanks for the summary.' } ) ) ],
	[ 'metric', 'metric, spark, text, meta, action', 'The original fields: a big number, a small bar chart, a paragraph, small facts and a link button.', frame( 'Statistics', 'stats', h.card( { title: 'Visits', metric: { value: '12,480', label: 'this week' }, spark: [ 4, 6, 5, 9, 7, 11, 12 ], text: 'Up 14 % on last week.', meta: [ 'Source: own counter', 'Updated hourly' ], action: { label: 'Open statistics', url: '#' } } ) ) ],
	[ 'actions', 'actions', 'Buttons that run PHP on the site: primary, secondary and danger. Actions with a confirm text ask inline first; errors show inside the card.',
		frame( 'Comment', 'comment', h.card( { title: 'Comment by Eve', status: { label: 'Pending', tone: 'warning' }, quote: 'Great article!' } ), actionRow( [ [ 'Approve', 'primary' ], [ 'Spam', 'secondary' ], [ 'Trash', 'danger' ] ] ) )
		+ frame( 'Comment', 'comment', h.card( { title: 'Comment by Eve', status: { label: 'Pending', tone: 'warning' } } ), '<div class="ff-card-actions" hidden></div><div class="ff-card-confirm" role="group" aria-label="Move this comment to the trash?"><p>Move this comment to the trash?</p>' + h.button( 'Trash', { style: 'danger' } ) + h.button( 'Cancel', { style: 'secondary' } ) + '</div>' )
		+ frame( 'Newsletter', 'newsletter', h.card( { title: all.title, status: { label: 'In review', tone: 'warning' } } ), actionRow( [ [ 'Approve', 'primary' ], [ 'Send test', 'secondary' ] ] ) + '<p class="ff-card-error" role="alert">The mail service did not answer.</p>' )
		+ '<p class="ff-event">Ana Klein approved the comment: Comment by Eve · today, 09:12</p>' ],
	[ 'everything', 'All together', 'One card using most blocks, as a provider might send it.', frame( 'Newsletter', 'newsletter', withBody( h.card( all ), h.people( people.slice( 0, 3 ) ) ), actionRow( [ [ 'Approve', 'primary' ], [ 'Reject', 'danger' ] ] ) ) ],
];

const panel = ( theme, html ) => '<div class="ff-root ffg-panel" data-ff-theme="' + theme + '"><span class="ffg-theme">' + ( 'light' === theme ? 'Light' : 'Dark' ) + '</span><div class="ff-cards">' + html + '</div></div>';
const gallery = items.map( ( [ id, head, desc, html ] ) => '<section class="ffg-item" aria-labelledby="block-' + id + '"><h3 id="block-' + id + '"><code>' + head.split( ', ' ).join( '</code>, <code>' ).replace( '<code>All together</code>', 'All together' ) + '</code></h3><p>' + desc + '</p><div class="ffg-pair">' + panel( 'light', html ) + panel( 'dark', html ) + '</div></section>' ).join( '\n' );

// Wrapped in "-->" … "<!--": docs/cards.md includes this inside an HTML comment, so GitHub shows
// nothing there while the website shows the gallery. One element per line, no blank lines and
// no indentation, so kramdown leaves the HTML alone.
fs.writeFileSync( path.join( root, 'docs/_includes/card-gallery.html' ), '-->\n<!-- Generated by tools/docs-cards.mjs from assets/app.js; do not edit by hand. -->\n<div class="ff-gallery">\n<link rel="stylesheet" href="{{ \'/assets/cards.css\' | relative_url }}">\n' + gallery.replace( /\n\s*\n/g, '\n' ) + '\n</div>\n<!--\n' );

/* ---------------------------------------------------------------- the CSS from app.css */

// Top-level rules and at-rules of a stylesheet (enough for app.css: no strings with braces).
const blocks = ( css ) => {
	const out = [];
	let depth = 0;
	let start = 0;
	for ( let i = 0; i < css.length; i++ ) {
		if ( '/' === css[ i ] && '*' === css[ i + 1 ] ) {
			const end = css.indexOf( '*/', i + 2 );
			if ( 0 === depth ) {
				start = end + 2;
			}
			i = end + 1;
		} else if ( '{' === css[ i ] ) {
			depth++;
		} else if ( '}' === css[ i ] && 0 === --depth ) {
			out.push( css.slice( start, i + 1 ).trim() );
			start = i + 1;
		}
	}
	return out;
};
// Selector list → selectors (commas inside :is(), :where() … stay).
const split = ( list ) => {
	const out = [];
	let depth = 0;
	let cur = '';
	for ( const ch of list ) {
		depth += '(' === ch ? 1 : ')' === ch ? -1 : 0;
		if ( ',' === ch && 0 === depth ) {
			out.push( cur.trim() );
			cur = '';
		} else {
			cur += ch;
		}
	}
	return [ ...out, cur.trim() ].filter( Boolean );
};
const KEEP = /\.ff-root|\.ff-card|\.ff-ava|\.ff-stack|\.ff-event|screen-reader-text/;
const SKIP = /\.ff-root-|\.ff-(app|nav|msg|box|menu|pp|note|who|assignees|summary|pending|post|tcard|lane|me|compose|embed)\b|flurfunk-|\.os-|\.ff-root \.ff-(?!card)/;
const scope = ( rule ) => {
	const at = rule.match( /^@media[^{]+\{([\s\S]*)\}$/ );
	if ( at ) {
		const inner = blocks( at[ 1 ] ).map( scope ).filter( Boolean );
		return inner.length ? rule.slice( 0, rule.indexOf( '{' ) + 1 ) + '\n\t' + inner.join( '\n\t' ) + '\n}' : '';
	}
	if ( rule.startsWith( '@keyframes ff-spin' ) ) {
		return rule;
	}
	if ( rule.startsWith( '@' ) ) {
		return '';
	}
	const brace = rule.indexOf( '{' );
	const sels = split( rule.slice( 0, brace ) ).filter( ( s ) => KEEP.test( s ) && ! SKIP.test( s ) );
	return sels.length ? sels.map( ( s ) => '.ff-gallery ' + s ).join( ', ' ) + ' ' + rule.slice( brace ).replace( /\s+/g, ' ' ) : '';
};
const generic = [ '.ff-root *', '.ff-root *::before', '.ff-root *::after', '.ff-root :where(button, input, select, textarea)', '.ff-root :where(h1, h2, h3, h4, p)', '.ff-root :where(a)', '.ff-root :focus-visible' ];
const rules = blocks( read( 'assets/app.css' ) ).map( ( r ) => {
	const sel = r.slice( 0, r.indexOf( '{' ) ).trim();
	return generic.some( ( g ) => sel.startsWith( g ) ) || /^\.ff-root\s*\{/.test( r ) || /^\.ff-root\[data-ff-theme/.test( r ) ? '.ff-gallery ' + split( sel ).join( ', .ff-gallery ' ) + ' ' + r.slice( r.indexOf( '{' ) ).replace( /\s+/g, ' ' ) : scope( r );
} ).filter( Boolean );

fs.writeFileSync( path.join( root, 'docs/assets/cards.css' ), `/*
 * Card gallery of the website. Generated by tools/docs-cards.mjs from assets/app.css; do not
 * edit by hand. Every rule is scoped to .ff-gallery so the site's own styles stay apart.
 */

/* Gallery frame. */
.ff-gallery { display: grid; gap: 2.5rem; margin: 2rem 0; }
.ff-gallery .ffg-item > h3 { margin: 0 0 .35rem; }
.ff-gallery .ffg-item > p { margin: 0 0 .9rem; }
.ff-gallery .ffg-pair { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr)); gap: 12px; }
.ff-gallery .ffg-panel { position: relative; padding: 30px 14px 14px; border: 1px solid var(--ff-line); border-radius: 10px; background: var(--ff-paper); min-width: 0; }
.ff-gallery .ffg-theme { position: absolute; top: 8px; left: 14px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ff-muted); }
.ff-gallery .ff-cards { margin: 0; }
.ff-gallery .ff-card + .ff-card { margin-top: 0; }
.ff-gallery .ff-event { margin: 2px 0 0; }
.ff-gallery .ff-card-refresh { pointer-events: none; }

/* Keep the site's prose styles (tables, lists, quotes, paragraphs) out of the cards. */
.ff-gallery .ff-root :is(p, ul, ol, dl, dd, li, table, thead, tbody, tr, th, td, blockquote) { margin: 0; padding: 0; border: 0; background: none; font-size: inherit; line-height: inherit; }
.ff-gallery .ff-root li::marker { content: none; }
.ff-gallery .ff-root table { display: table; width: 100%; min-width: 0; overflow: visible; }
.ff-gallery .ff-root tbody tr:hover td { background: none; }

/* From assets/app.css. */
${ rules.join( '\n' ) }
` );
console.log( 'docs/_includes/card-gallery.html: ' + items.length + ' blocks; docs/assets/cards.css: ' + rules.length + ' rules' );

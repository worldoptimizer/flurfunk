/**
 * Flurfunk interface. No build step, no framework: plain DOM, drawn from REST data.
 *
 * window.Flurfunk.mountApp( el )                         the overview (sidebar, threads, thread pane)
 * window.Flurfunk.mountTarget( el, { type, id } )        conversations about one post (editor panel)
 * window.Flurfunk.mountRefs( root, { prefix, channel } ) threads embedded next to elements carrying
 *                                                        data-flurfunk-ref (e.g. a checklist)
 * window.Flurfunk.registerCard( providerId, ( snapshot, h ) => html )   custom card look
 */
( function () {
	'use strict';

	const cfg = window.flurfunkConfig || {};
	const i18n = cfg.i18n || {};

	/* ------------------------------------------------------------------ basics */

	const t = ( s, ...args ) => {
		let out = i18n[ s ] || s;
		args.forEach( ( v, i ) => {
			out = out.split( '%' + ( i + 1 ) + '$s' ).join( v );
		} );
		return args.length === 1 ? out.replace( '%s', args[ 0 ] ) : out;
	};
	const esc = ( s ) => String( s ?? '' ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );
	const $ = ( sel, el ) => ( el || document ).querySelector( sel );

	// Shown once when WordPress has logged the person out (the page itself still looks alive).
	let sessionShown = false;
	const sessionEnded = () => {
		if ( sessionShown ) {
			return;
		}
		sessionShown = true;
		const bar = document.createElement( 'div' );
		bar.className = 'ff-root ff-session';
		bar.setAttribute( 'role', 'alert' );
		bar.innerHTML = esc( t( 'Your session has ended. Please log in again.' ) ) + ( cfg.loginUrl ? ' <a href="' + esc( cfg.loginUrl ) + '" target="_top">' + esc( t( 'Log in' ) ) + '</a>' : '' );
		document.body.append( bar );
	};
	const api = async ( path, opts = {}, retried = false ) => {
		const res = await fetch( cfg.root + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: Object.assign( { 'X-WP-Nonce': cfg.nonce }, opts.body ? { 'Content-Type': 'application/json' } : {} ),
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
		} );
		const data = await res.json().catch( () => null );
		if ( ! res.ok ) {
			const code = data && data.code;
			// Windows stay open for days; WordPress nonces don't. Fetch a fresh one once and retry.
			if ( ! retried && 'rest_cookie_invalid_nonce' === code && cfg.ajaxUrl ) {
				const fresh = await fetch( cfg.ajaxUrl + '?action=rest-nonce', { credentials: 'same-origin' } ).then( ( r ) => r.text() ).catch( () => '0' );
				if ( fresh && '0' !== fresh.trim() ) {
					cfg.nonce = fresh.trim();
					return api( path, opts, true );
				}
			}
			if ( 401 === res.status || 'rest_cookie_invalid_nonce' === code || 'rest_not_logged_in' === code ) {
				sessionEnded();
				throw new Error( t( 'Your session has ended. Please log in again.' ) );
			}
			throw new Error( ( data && data.message ) || res.statusText );
		}
		return data;
	};
	const query = ( params ) => Object.entries( params ).filter( ( [ , v ] ) => v !== undefined && v !== '' && v !== null ).map( ( [ k, v ] ) => encodeURIComponent( k ) + '=' + encodeURIComponent( v ) ).join( '&' );

	let boot = null;
	let bootPromise = null;
	const loadBoot = ( fresh ) => {
		if ( ! bootPromise || fresh ) {
			bootPromise = api( 'bootstrap' ).then( ( b ) => {
				boot = b;
				loadDrafts();
				return b;
			} );
		}
		return bootPromise;
	};
	const person = ( id ) => ( boot && boot.people.find( ( p ) => p.id === id ) ) || ( boot && boot.me.id === id ? boot.me : { id, name: '?', role: '', kind: '' } );
	const channelOf = ( slug ) => boot.channels.find( ( c ) => c.id === slug );
	const chName = ( slug ) => ( channelOf( slug ) || { name: slug } ).name || slug;

	const locale = () => ( boot && boot.locale ) || document.documentElement.lang || 'en';
	const when = ( iso ) => {
		if ( ! iso ) {
			return '';
		}
		const d = new Date( iso );
		const now = new Date();
		const time = d.toLocaleTimeString( locale(), { hour: '2-digit', minute: '2-digit' } );
		const days = Math.round( ( new Date( now.toDateString() ) - new Date( d.toDateString() ) ) / 864e5 );
		if ( now - d < 60e3 ) {
			return t( 'just now' );
		}
		if ( days <= 1 ) {
			const rel = new Intl.RelativeTimeFormat( locale(), { numeric: 'auto' } ).format( -days, 'day' );
			return rel + ', ' + time;
		}
		return d.toLocaleDateString( locale(), { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' } ) + ', ' + time;
	};
	const size = ( b ) => ( b < 1048576 ? Math.max( 1, Math.round( b / 1024 ) ) + ' KB' : ( b / 1048576 ).toFixed( 1 ) + ' MB' );

	const icon = {
		clip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="m20 11-8.5 8.5a5 5 0 0 1-7-7L13 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L14 7"/></svg>',
		file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M6 3h8l4 4v14H6zM14 3v4h4"/></svg>',
		doc: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M6 3h8l4 4v14H6zM9 12h6M9 16h6"/></svg>',
		image: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4"/><circle cx="15.5" cy="9" r="1.5" fill="currentColor"/></svg>',
		bell: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 20.5a2 2 0 0 0 4 0"/></svg>',
		chev: '<svg class="ff-chev" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M3 1.5 7 5l-4 3.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
		gear: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/></svg>',
	};

	const initials = ( name ) => String( name || '?' ).trim().split( /\s+/ ).slice( 0, 2 ).map( ( w ) => w[ 0 ] ).join( '' ).toUpperCase();
	const photo = ( p ) => ( p.avatar ? '<img src="' + esc( p.avatar ) + '" alt="" loading="lazy" onerror="this.remove()">' : '' );
	const ava = ( id, cls ) => {
		const p = person( id );
		return '<span class="ff-ava' + ( 'guest' === p.kind ? ' is-guest' : '' ) + ( boot && id === boot.me.id ? ' is-me' : '' ) + ( cls ? ' ' + cls : '' ) + '" aria-hidden="true">' + esc( initials( p.name ) ) + photo( p ) + '</span>';
	};

	/*
	 * Text → HTML: escaped first, then a small, Slack-like formatting on top. Mentions, links
	 * and code are set aside as placeholders so formatting never reaches into them.
	 *   *bold*  _italic_  ~strike~  `code`  ```block```  "- item" / "1. item"  "> quote"
	 */
	const rich = ( text ) => {
		const keep = [];
		const hold = ( html ) => '\u0000' + ( keep.push( html ) - 1 ) + '\u0000';
		let s = String( text || '' );
		s = s.replace( /```([\s\S]*?)```/g, ( all, code ) => hold( '<pre class="ff-code">' + esc( code.replace( /^\n|\n$/g, '' ) ) + '</pre>' ) );
		s = s.replace( /`([^`\n]+)`/g, ( all, code ) => hold( '<code>' + esc( code ) + '</code>' ) );
		s = s.replace( /<@(\d+)>/g, ( all, id ) => hold( '<span class="ff-mention' + ( boot && +id === boot.me.id ? ' is-me' : '' ) + '">@' + esc( person( +id ).name ) + '</span>' ) );
		s = s.replace( /https?:\/\/[^\s<>"]+[^\s<>".,;:!?)\]]/g, ( url ) => hold( '<a href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer">' + esc( url ) + '</a>' ) );
		s = esc( s );
		const edge = '(^|[\\s(„"\'\\[])';
		const end = '(?=$|[\\s).,!?:;“"\'\\]])';
		s = s.replace( new RegExp( edge + '\\*([^*\\n]+?)\\*' + end, 'g' ), '$1<strong>$2</strong>' );
		s = s.replace( new RegExp( edge + '_([^_\\n]+?)_' + end, 'g' ), '$1<em>$2</em>' );
		s = s.replace( new RegExp( edge + '~([^~\\n]+?)~' + end, 'g' ), '$1<s>$2</s>' );
		// Lines: lists and quotes become blocks, everything else keeps its line breaks.
		const out = [];
		let block = null;
		const flush = () => {
			if ( block ) {
				out.push( '<' + block.tag + '>' + block.items.map( ( x ) => ( 'blockquote' === block.tag ? x : '<li>' + x + '</li>' ) ).join( 'blockquote' === block.tag ? '<br>' : '' ) + '</' + block.tag + '>' );
				block = null;
			}
		};
		s.split( '\n' ).forEach( ( line ) => {
			const m = line.match( /^\s*(?:([-•*])|(\d+)[.)]|(&gt;))\s+(.*)$/ );
			const tag = m ? ( m[ 1 ] ? 'ul' : m[ 2 ] ? 'ol' : 'blockquote' ) : null;
			if ( tag ) {
				if ( ! block || block.tag !== tag ) {
					flush();
					block = { tag, items: [] };
				}
				block.items.push( m[ 4 ] );
				return;
			}
			flush();
			out.push( line + '\n' );
		} );
		flush();
		s = out.join( '' ).replace( /\n+$/, '' ).replace( /\n/g, '<br>' ).replace( /(<\/(?:ul|ol|blockquote)>)<br>/g, '$1' ).replace( /(?:<br>)+(<(?:ul|ol|blockquote))/g, '$1' );
		return s.replace( /\u0000(\d+)\u0000/g, ( all, i ) => keep[ +i ] );
	};

	// "@Full Name" (or a unique first name) → <@ID>.
	const encode = ( text ) => {
		let out = text;
		const people = boot.people.slice().sort( ( a, b ) => b.name.length - a.name.length );
		people.forEach( ( p ) => {
			out = out.replace( new RegExp( '@' + p.name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '(?![\\p{L}\\p{N}])', 'giu' ), '<@' + p.id + '>' );
		} );
		const firsts = {};
		people.forEach( ( p ) => {
			const f = p.name.split( /\s+/ )[ 0 ].toLowerCase();
			firsts[ f ] = firsts[ f ] ? -1 : p.id;
		} );
		return out.replace( /@([\p{L}\p{N}_-]+)/gu, ( all, w ) => ( firsts[ w.toLowerCase() ] > 0 ? '<@' + firsts[ w.toLowerCase() ] + '>' : all ) );
	};

	/* ------------------------------------------------------------------ cards */

	const renderers = {};
	const cardHelpers = { esc, rich, when };
	const genericCard = ( s ) => {
		const max = s.spark ? Math.max( 1, ...s.spark ) : 1;
		return ( s.image ? '<img class="ff-card-img" src="' + esc( s.image ) + '" alt="">' : '' )
			+ '<div class="ff-card-body">'
			+ ( s.title ? '<b class="ff-card-title">' + ( s.url ? '<a href="' + esc( s.url ) + '" target="_top">' + esc( s.title ) + '</a>' : esc( s.title ) ) + '</b>' : '' )
			+ ( s.subtitle ? '<span class="ff-card-sub">' + esc( s.subtitle ) + '</span>' : '' )
			+ ( s.metric ? '<span class="ff-card-metric">' + esc( s.metric.value ) + ' <small>' + esc( s.metric.label ) + '</small></span>' : '' )
			+ ( s.spark ? '<span class="ff-card-spark" aria-hidden="true">' + s.spark.map( ( v ) => '<i style="height:' + Math.max( 2, Math.round( v / max * 28 ) ) + 'px"></i>' ).join( '' ) + '</span>' : '' )
			+ ( s.text ? '<span class="ff-card-text">' + esc( s.text ) + '</span>' : '' )
			+ ( s.audio ? '<audio controls preload="none" src="' + esc( s.audio ) + '"></audio>' : '' )
			+ ( s.meta ? '<span class="ff-card-meta">' + s.meta.map( esc ).join( ' · ' ) + '</span>' : '' )
			+ ( s.action ? '<a class="ff-card-action" href="' + esc( s.action.url ) + '" target="_top">' + esc( s.action.label ) + '</a>' : '' )
			+ '</div>';
	};
	const providerOf = ( id ) => ( boot && boot.providers.find( ( p ) => p.id === id ) ) || { id, label: id, command: '' };
	const card = ( a, remove ) => {
		const p = providerOf( a.provider );
		const s = a.snapshot || a.preview || {};
		let inner;
		try {
			inner = renderers[ a.provider ] && a.snapshot ? renderers[ a.provider ]( s, cardHelpers ) : genericCard( s );
		} catch ( e ) {
			inner = genericCard( s );
		}
		const canRefresh = a.id && remove === undefined && boot && boot.providers.some( ( x ) => x.id === a.provider );
		return '<div class="ff-card"><span class="ff-card-kind">' + esc( p.label ) + ( p.command ? ' <small>/' + esc( p.command ) + '</small>' : '' )
			+ ( canRefresh ? ' <button type="button" class="ff-card-refresh" data-ff="card-refresh" data-cid="' + a.id + '">' + esc( t( 'Update' ) ) + '</button>' : '' ) + '</span>' + inner
			+ ( remove !== undefined ? '<button type="button" class="ff-x ff-card-x" data-ff="card-x" data-i="' + remove + '" aria-label="' + esc( t( 'Remove card' ) ) + '">×</button>' : '' ) + '</div>';
	};
	const cards = ( list ) => ( list && list.length ? '<div class="ff-cards">' + list.map( ( a ) => card( a ) ).join( '' ) + '</div>' : '' );
	// Images as previews (several as a gallery, all open in the lightbox), audio and video as
	// players, everything else as a link; long lists of files fold away.
	const files = ( list ) => {
		const all = list || [];
		const adoptBtn = ( f ) => ( false === f.library && boot && boot.me.upload ? '<button type="button" class="ff-adopt" data-ff="adopt" data-fid="' + f.id + '" title="' + esc( t( 'Only in Flurfunk so far. Adding it keeps the file where it is.' ) ) + '">' + esc( t( 'Add to media library' ) ) + '</button>' : '' );
		// The media library title if someone set one, otherwise the file name.
		const nameOf = ( f ) => ( f.title && f.title !== String( f.name ).replace( /\.[^.]+$/, '' ) ? f.title : f.name );
		const isImage = ( f ) => ! f.missing && f.url && String( f.type || '' ).startsWith( 'image/' );
		const images = all.filter( isImage );
		const box = ( f, src, tile ) => '<a class="ff-media ff-image' + ( tile ? ' is-tile' : '' ) + '" href="' + esc( f.url ) + '" data-ff="lightbox" data-name="' + esc( nameOf( f ) ) + '" target="_blank" rel="noopener"'
			+ ( ! tile && f.width && f.height ? ' style="aspect-ratio:' + f.width + '/' + f.height + '"' : '' ) + '><img src="' + esc( src ) + '" alt="' + esc( nameOf( f ) ) + '" loading="lazy"></a>';
		let gallery = '';
		if ( 1 === images.length ) {
			gallery = '<span class="ff-media-wrap">' + box( images[ 0 ], images[ 0 ].preview || images[ 0 ].url ) + adoptBtn( images[ 0 ] ) + '</span>';
		} else if ( images.length ) {
			gallery = '<div class="ff-gallery">' + images.map( ( f ) => box( f, f.thumb || f.preview || f.url, true ) ).join( '' ) + '</div>';
		}
		const rest = all.filter( ( f ) => ! isImage( f ) ).map( ( f ) => {
			const type = String( f.type || '' );
			const label = esc( nameOf( f ) ) + ' <small>· ' + size( f.size ) + '</small>';
			if ( f.missing || ! f.url ) {
				return '<span class="ff-file is-missing">' + icon.file + esc( f.name ) + ' <small>· ' + esc( t( 'no longer available' ) ) + '</small></span>';
			}
			if ( type.startsWith( 'audio/' ) ) {
				return '<figure class="ff-media ff-audio"><figcaption>' + label + '</figcaption><audio controls preload="none" src="' + esc( f.url ) + '"></audio>' + adoptBtn( f ) + '</figure>';
			}
			if ( type.startsWith( 'video/' ) ) {
				return '<figure class="ff-media ff-video"><video controls preload="metadata" src="' + esc( f.url ) + '"></video><figcaption>' + label + '</figcaption>' + adoptBtn( f ) + '</figure>';
			}
			return '<span class="ff-media-wrap"><a class="ff-file" href="' + esc( f.url ) + '" download target="_blank" rel="noopener">' + icon.file + label + '</a>' + adoptBtn( f ) + '</span>';
		} );
		const SHOW = 6;
		const listed = rest.length > SHOW + 2
			? rest.slice( 0, SHOW ).join( '' ) + '<details class="ff-more-files"><summary>' + esc( t( '%s more files', rest.length - SHOW ) ) + '</summary><div class="ff-files">' + rest.slice( SHOW ).join( '' ) + '</div></details>'
			: rest.join( '' );
		return gallery || listed ? '<div class="ff-files">' + gallery + listed + '</div>' : '';
	};

	/* ------------------------------------------------------------------ pieces */

	const targetChip = ( th ) => ( th.target && ! th.target.title ? '<span class="ff-target is-gone">' + icon.doc + '<span>' + esc( t( 'deleted' ) ) + '</span></span>' : th.target && th.target.title
		? '<a class="ff-target" href="' + esc( th.target.url ) + '" target="_top" data-ff="target" data-type="' + esc( th.target.type ) + '" data-id="' + th.target.id + '" title="' + esc( t( 'Conversations about this post' ) ) + '">' + icon.doc + '<span>' + esc( th.target.title ) + '</span></a>'
		: '' );
	const statusPill = ( th ) => ( 'done' === th.status ? '<span class="ff-pill is-done">' + esc( t( 'done' ) ) + '</span>' : 'open' === th.status ? '<span class="ff-pill is-open">' + esc( t( 'open' ) ) + '</span>' : '' );
	const audiencePill = ( th ) => ( boot && 'team' === boot.me.kind && th.audience && th.audience.length ? '<span class="ff-pill is-seen">' + esc( t( '%s can read this', th.audience.join( ', ' ) ) ) + '</span>' : '' );
	const pad2 = ( n ) => String( n ).padStart( 2, '0' );
	const today = () => {
		const d = new Date();
		return d.getFullYear() + '-' + pad2( d.getMonth() + 1 ) + '-' + pad2( d.getDate() );
	};
	const fmtDate = ( ymd ) => new Date( ymd + 'T12:00:00' ).toLocaleDateString( locale(), { weekday: 'short', day: 'numeric', month: 'short' } );
	const dueChip = ( th ) => {
		if ( ! th.due || 'open' !== th.status ) {
			return '';
		}
		const d = today();
		const cls = th.due < d ? ' is-over' : th.due === d ? ' is-today' : '';
		const txt = th.due < d ? t( 'overdue since %s', fmtDate( th.due ) ) : th.due === d ? t( 'due today' ) : t( 'due %s', fmtDate( th.due ) );
		return '<span class="ff-due' + cls + '">' + esc( txt ) + '</span>';
	};
	const names = ( ids ) => ( ids || [] ).map( ( id ) => person( id ).name ).join( ', ' );
	const assigneeStack = ( th ) => ( th.assignees && th.assignees.length && 'open' === th.status ? '<span class="ff-stack ff-assignees" title="' + esc( t( 'Responsible: %s', names( th.assignees ) ) ) + '">' + th.assignees.slice( 0, 4 ).map( ( id ) => ava( id ) ).join( '' ) + '</span>' : '' );
	// Responsible people and due date of a task, editable in place.
	const taskControls = ( th ) => {
		if ( null === th.status || undefined === th.status ) {
			return '';
		}
		const who = th.assignees && th.assignees.length ? th.assignees.map( ( id ) => '<span class="ff-who-chip">' + ava( id ) + esc( person( id ).name ) + '</span>' ).join( '' ) : '<span class="ff-muted">' + esc( t( 'nobody yet' ) ) + '</span>';
		return '<div class="ff-task"><span class="ff-task-l">' + esc( t( 'Responsible' ) ) + '</span>' + who
			+ '<button type="button" class="ff-link" data-ff="assign" data-tid="' + th.id + '">' + esc( th.assignees && th.assignees.length ? t( 'Change' ) : t( 'Assign' ) ) + '</button>'
			+ '<label class="ff-task-l">' + esc( t( 'Due' ) ) + ' <input type="date" data-ff="due" data-tid="' + th.id + '" value="' + esc( th.due || '' ) + '"></label>'
			+ ( th.due ? '<button type="button" class="ff-x ff-due-x" data-ff="due-clear" data-tid="' + th.id + '" aria-label="' + esc( t( 'Remove due date' ) ) + '">×</button>' : '' )
			+ dueChip( th ) + '</div>';
	};
	/**
	 * Pick people (checkboxes with search) in a small popover next to the anchor.
	 *
	 * @param {Element}  anchor   Button that opened it.
	 * @param {number[]} selected Preselected user IDs.
	 * @param {Function} onDone   Called with the chosen IDs.
	 */
	const peoplePicker = ( anchor, selected, onDone ) => {
		document.querySelectorAll( '.ff-people-pop' ).forEach( ( x ) => x.remove() );
		const chosen = new Set( selected );
		const pop = document.createElement( 'div' );
		pop.className = 'ff-pop ff-people-pop';
		pop.setAttribute( 'role', 'dialog' );
		pop.setAttribute( 'aria-label', t( 'Responsible' ) );
		pop.innerHTML = '<input type="search" class="ff-pick-q" placeholder="' + esc( t( 'Search …' ) ) + '" aria-label="' + esc( t( 'Search' ) ) + '"><div class="ff-pick-list"></div><div class="ff-row"><button type="button" class="ff-send" data-pp="done">' + esc( t( 'Done' ) ) + '</button><button type="button" class="ff-link" data-pp="cancel">' + esc( t( 'Cancel' ) ) + '</button></div>';
		const list = $( '.ff-pick-list', pop );
		const q = $( '.ff-pick-q', pop );
		const draw = () => {
			const term = q.value.toLowerCase();
			list.innerHTML = boot.people.filter( ( p ) => ! term || p.name.toLowerCase().includes( term ) ).slice( 0, 40 ).map( ( p ) => '<label class="ff-pp"><input type="checkbox" value="' + p.id + '"' + ( chosen.has( p.id ) ? ' checked' : '' ) + '>' + ava( p.id ) + '<span><b>' + esc( p.name ) + '</b><small>' + esc( p.role ) + '</small></span></label>' ).join( '' );
		};
		const close = () => {
			pop.remove();
			document.removeEventListener( 'mousedown', outside );
			anchor.focus();
		};
		const outside = ( e ) => {
			if ( ! pop.contains( e.target ) && e.target !== anchor ) {
				close();
			}
		};
		q.addEventListener( 'input', draw );
		list.addEventListener( 'change', ( e ) => {
			const id = +e.target.value;
			if ( e.target.checked ) {
				chosen.add( id );
			} else {
				chosen.delete( id );
			}
		} );
		pop.addEventListener( 'click', ( e ) => {
			const b = e.target.closest( '[data-pp]' );
			if ( b ) {
				e.stopPropagation();
				const ids = [ ...chosen ];
				close();
				if ( 'done' === b.dataset.pp ) {
					onDone( ids );
				}
			}
		} );
		pop.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				e.stopPropagation();
				close();
			}
		} );
		draw();
		// Fixed to the viewport next to the button, below or above depending on the room left, so
		// no container can clip it.
		const root = anchor.closest( '.ff-root' ) || document.body;
		root.append( pop );
		const r = anchor.getBoundingClientRect();
		const h = Math.min( 340, pop.offsetHeight || 340 );
		pop.style.position = 'fixed';
		pop.style.left = Math.max( 8, Math.min( r.left, window.innerWidth - pop.offsetWidth - 8 ) ) + 'px';
		if ( window.innerHeight - r.bottom > h + 12 || r.top < h + 12 ) {
			pop.style.top = ( r.bottom + 6 ) + 'px';
			pop.style.bottom = 'auto';
		} else {
			pop.style.bottom = ( window.innerHeight - r.top + 6 ) + 'px';
			pop.style.top = 'auto';
		}
		document.addEventListener( 'mousedown', outside );
		q.focus();
	};
	const doneBox = ( th, extra ) => ( null === th.status || undefined === th.status ? '' : '<label class="ff-state"><input type="checkbox" data-ff="done"' + ( extra || '' ) + ( 'done' === th.status ? ' checked' : '' ) + '> ' + esc( t( 'Done' ) ) + '</label>' );

	const summary = ( th, expanded, act ) => {
		if ( ! th.replies ) {
			return '';
		}
		const last = th.last;
		return '<button type="button" class="ff-summary" data-ff="' + act + '" aria-expanded="' + !! expanded + '">' + icon.chev
			+ '<span class="ff-stack">' + ( th.participants || [] ).slice( 0, 5 ).map( ( id ) => ava( id ) ).join( '' ) + '</span>'
			+ '<strong>' + esc( 1 === th.replies ? t( '1 reply' ) : t( '%s replies', th.replies ) ) + '</strong>'
			+ ( th.unread ? '<span class="ff-new">' + esc( t( '%s new', th.unread ) ) + '</span>' : '' )
			+ ( last ? '<span>' + esc( t( 'last: %1$s, %2$s', person( last.author ).name, when( last.created ) ) ) + '</span>' : '' ) + '</button>';
	};

	// Name in bold; assistants (agents, scripts) are marked so nobody mistakes them for a person.
	const nameHtml = ( id ) => '<b>' + esc( person( id ).name ) + '</b>' + ( person( id ).bot ? ' <span class="ff-pill is-bot">' + esc( t( 'Assistant' ) ) + '</span>' : '' );
	// One-line preview for lists: mentions and links stay, formatting and line structure don't.
	const preview = ( text ) => rich( String( text || '' ).replace( /```[\s\S]*?```/g, ' … ' ).replace( /^\s*(?:[-•*]|\d+[.)]|>)\s+/gm, '' ).replace( /[*_~`]/g, '' ).replace( /\s*\n+\s*/g, ' · ' ) );
	const mine = ( author ) => !! boot && ( author === boot.me.id || boot.me.manage );
	const actions = ( m ) => ( mine( m.author ) ? '<span class="ff-actions"><button type="button" data-ff="msg-edit" data-mid="' + m.id + '">' + esc( t( 'Edit' ) ) + '</button><button type="button" data-ff="msg-del" data-mid="' + m.id + '">' + esc( t( 'Delete' ) ) + '</button></span>' : '' );
	const messagesHtml = ( th ) => {
		let html = '';
		let prev = null;
		if ( th.older ) {
			html += '<li class="ff-event">' + esc( t( '%s earlier messages are not shown here.', th.older ) ) + '</li>';
		}
		( th.messages || [] ).forEach( ( m ) => {
			if ( 'event' === m.type ) {
				const who = person( m.author ).name;
				const parts = [];
				if ( 'assign' === m.event ) {
					if ( m.added && m.added.length ) {
						parts.push( t( '%1$s made %2$s responsible', who, names( m.added ) ) );
					}
					if ( m.removed && m.removed.length ) {
						parts.push( t( '%1$s took %2$s off the task', who, names( m.removed ) ) );
					}
				} else if ( 'due' === m.event ) {
					parts.push( m.due ? t( '%1$s set the due date to %2$s', who, fmtDate( m.due ) ) : t( '%s removed the due date', who ) );
				}
				if ( parts.length ) {
					html += '<li class="ff-event">' + esc( parts.join( ' · ' ) ) + ' · ' + esc( when( m.created ) ) + '</li>';
				}
				prev = null;
				return;
			}
			if ( 'deleted' === m.type ) {
				html += '<li class="ff-event is-deleted">' + esc( t( '%s deleted a message', person( m.author ).name ) ) + '</li>';
				prev = null;
				return;
			}
			if ( 'status' === m.type ) {
				const name = esc( person( m.author ).name );
				const text = 'done' === m.status ? t( '%s marked this as done', name ) : 'open' === m.status ? t( '%s reopened this', name ) : t( '%s removed the task', name );
				html += '<li class="ff-event' + ( 'done' === m.status ? ' is-done' : '' ) + '">' + ( 'done' === m.status ? '✓ ' : '↺ ' ) + text + ' · ' + esc( when( m.created ) ) + '</li>';
				prev = null;
				return;
			}
			const cont = prev && prev.author === m.author && new Date( m.created ) - new Date( prev.created ) < 5 * 60e3;
			const unread = th.read >= 0 && m.id > th.read && m.author !== boot.me.id;
			html += '<li class="ff-msg' + ( cont ? ' is-cont' : '' ) + ( unread ? ' is-unread' : '' ) + '" id="ff-m-' + m.id + '">' + ava( m.author ) + '<div class="ff-msg-body">'
				+ ( cont ? '' : '<p class="ff-who">' + nameHtml( m.author ) + ' <span>' + esc( person( m.author ).role ) + ' · ' + esc( when( m.created ) ) + '</span></p>' )
				+ ( m.text ? '<div class="ff-text">' + rich( m.text ) + '</div>' : '' )
				+ ( m.edited ? '<small class="ff-edited">' + esc( t( '(edited)' ) ) + '</small>' : '' )
				+ cards( m.attachments ) + files( m.files )
				+ actions( m ) + '</div></li>';
			prev = m;
		} );
		return html ? '<ol class="ff-thread">' + html + '</ol>' : '';
	};

	/* ------------------------------------------------------------------ push and browser notifications */

	const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
	const keyBytes = ( b64 ) => {
		const s = atob( ( b64 + '='.repeat( ( 4 - b64.length % 4 ) % 4 ) ).replace( /-/g, '+' ).replace( /_/g, '/' ) );
		return Uint8Array.from( s, ( c ) => c.charCodeAt( 0 ) );
	};
	const sameBytes = ( a, b ) => a.length === b.length && a.every( ( v, i ) => v === b[ i ] );
	const paused = () => !! ( boot.me && boot.me.prefs.pause && new Date( boot.me.prefs.pause ) > new Date() );
	const pausedUntil = () => {
		const d = new Date( boot.me.prefs.pause );
		return d.toLocaleString( locale(), d.toDateString() === new Date().toDateString() ? { hour: '2-digit', minute: '2-digit' } : { weekday: 'short', hour: '2-digit', minute: '2-digit' } );
	};
	const swRegistration = async () => {
		if ( ! pushSupported() || ! cfg.swUrl ) {
			return null;
		}
		// register() is idempotent and picks up a changed worker address.
		const reg = await navigator.serviceWorker.register( cfg.swUrl );
		if ( ! reg.active ) {
			// The worker controls no page, so "ready" would never resolve: wait for it to activate.
			await new Promise( ( resolve ) => {
				const sw = reg.installing || reg.waiting;
				if ( ! sw ) {
					resolve();
					return;
				}
				sw.addEventListener( 'statechange', () => 'activated' === sw.state && resolve() );
			} );
		}
		return reg;
	};
	const pushState = async () => {
		if ( ! pushSupported() || ! boot.push || ! boot.push.available ) {
			return 'unsupported';
		}
		if ( 'denied' === Notification.permission ) {
			return 'blocked';
		}
		const reg = await navigator.serviceWorker.getRegistration( cfg.swUrl );
		const sub = reg && ( await reg.pushManager.getSubscription() );
		return sub ? 'on' : 'off';
	};
	const deviceLabel = () => {
		const ua = navigator.userAgent;
		const browser = /Edg\//.test( ua ) ? 'Edge' : /Firefox\//.test( ua ) ? 'Firefox' : /Chrome\//.test( ua ) ? 'Chrome' : /Safari\//.test( ua ) ? 'Safari' : t( 'Browser' );
		const os = /Mac OS X/.test( ua ) ? 'macOS' : /Windows/.test( ua ) ? 'Windows' : /Android/.test( ua ) ? 'Android' : /iPhone|iPad/.test( ua ) ? 'iOS' : /Linux/.test( ua ) ? 'Linux' : '';
		return browser + ( os ? ' · ' + os : '' );
	};
	const pushOn = async () => {
		if ( 'granted' !== ( await Notification.requestPermission() ) ) {
			throw new Error( t( 'Notifications are blocked in this browser. Allow them in the site settings of your browser.' ) );
		}
		const reg = await swRegistration();
		const sub = ( await reg.pushManager.getSubscription() ) || ( await reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: keyBytes( boot.push.key ) } ) );
		const r = await api( 'me/push', { method: 'POST', body: { subscription: sub.toJSON(), label: deviceLabel() } } );
		boot.push.devices = r.devices;
	};
	const pushOff = async () => {
		const reg = await navigator.serviceWorker.getRegistration( cfg.swUrl );
		const sub = reg && ( await reg.pushManager.getSubscription() );
		if ( sub ) {
			const r = await api( 'me/push', { method: 'DELETE', body: { endpoint: sub.endpoint } } );
			boot.push.devices = r.devices;
			await sub.unsubscribe();
		}
	};
	// Push subscriptions can rotate; tell the server the current one on every start.
	const pushResync = async () => {
		try {
			if ( 'on' === ( await pushState() ) ) {
				const reg = await swRegistration();
				let sub = await reg.pushManager.getSubscription();
				// The site's key changed (moved or restored site): the old subscription can no longer be used.
				const key = sub.options && sub.options.applicationServerKey;
				if ( key && boot.push.key && ! sameBytes( new Uint8Array( key ), keyBytes( boot.push.key ) ) ) {
					await api( 'me/push', { method: 'DELETE', body: { endpoint: sub.endpoint } } ).catch( () => {} );
					await sub.unsubscribe();
					sub = await reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: keyBytes( boot.push.key ) } );
				}
				await api( 'me/push', { method: 'POST', body: { subscription: sub.toJSON(), label: deviceLabel() } } );
			}
		} catch ( e ) {}
	};
	const browserNotesKey = () => 'flurfunk-browser-notes-' + boot.me.id;
	const browserNotesOn = () => {
		try {
			return '1' === window.localStorage.getItem( browserNotesKey() ) && 'Notification' in window && 'granted' === Notification.permission;
		} catch ( e ) {
			return false;
		}
	};

	/* ------------------------------------------------------------------ edit and delete */

	const rawText = ( text ) => String( text || '' ).replace( /<@(\d+)>/g, ( all, id ) => '@' + person( +id ).name );
	const startEdit = ( holder, text, onSave, title ) => {
		const form = document.createElement( 'form' );
		form.className = 'ff-edit';
		form.innerHTML = ( 'string' === typeof title ? '<input type="text" name="title" class="ff-title-input" aria-label="' + esc( t( 'Title' ) ) + '">' : '' ) + '<textarea rows="3" aria-label="' + esc( t( 'Edit' ) ) + '"></textarea><div class="ff-row"><button type="submit" class="ff-send">' + esc( t( 'Save' ) ) + '</button><button type="button" class="ff-link" data-ff="edit-cancel">' + esc( t( 'Cancel' ) ) + '</button></div><p class="ff-error" role="alert" hidden></p>';
		const ta = $( 'textarea', form );
		ta.value = rawText( text );
		if ( form.elements.title ) {
			form.elements.title.value = title;
		}
		holder.hidden = true;
		holder.after( form );
		ta.focus();
		const cancel = () => {
			form.remove();
			holder.hidden = false;
		};
		form.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '[data-ff="edit-cancel"]' ) ) {
				e.stopPropagation();
				cancel();
			}
		} );
		form.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				cancel();
			} else if ( 'Enter' === e.key && ( e.metaKey || e.ctrlKey ) ) {
				e.preventDefault();
				form.requestSubmit();
			}
		} );
		form.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			try {
				await onSave( encode( ta.value.trim() ), form.elements.title ? form.elements.title.value.trim() : undefined );
			} catch ( x ) {
				const er = $( '.ff-error', form );
				er.textContent = x.message;
				er.hidden = false;
			}
		} );
	};
	/**
	 * Edit/delete buttons inside any view. Returns true when the click was one of them.
	 *
	 * @param {Element}  el       Clicked element with data-ff.
	 * @param {Object[]} threads  Full threads shown in this view.
	 * @param {Function} onUpdate Called with the updated thread.
	 */
	const messageAction = async ( el, threads, onUpdate ) => {
		const act = el.dataset.ff;
		if ( 'msg-edit' === act || 'msg-del' === act ) {
			const mid = +el.dataset.mid;
			if ( 'msg-del' === act ) {
				if ( window.confirm( t( 'Delete this message?' ) ) ) {
					onUpdate( await api( 'messages/' + mid, { method: 'DELETE' } ) );
				}
				return true;
			}
			const m = threads.flatMap( ( x ) => x.messages || [] ).find( ( x ) => x.id === mid );
			const body = el.closest( '.ff-msg-body' );
			let holder = $( '.ff-text', body );
			if ( ! holder ) {
				holder = document.createElement( 'div' );
				holder.className = 'ff-text';
				$( '.ff-who', body ) ? $( '.ff-who', body ).after( holder ) : body.prepend( holder );
			}
			startEdit( holder, m ? m.text : '', async ( text ) => onUpdate( await api( 'messages/' + mid, { method: 'POST', body: { body: text } } ) ) );
			return true;
		}
		if ( 'assign' === act || 'due-clear' === act ) {
			const tid = +el.dataset.tid;
			const th = threads.find( ( x ) => x.id === tid ) || {};
			if ( 'due-clear' === act ) {
				onUpdate( await api( 'threads/' + tid + '/task', { method: 'POST', body: { due: '' } } ) );
			} else {
				peoplePicker( el, th.assignees || [], async ( ids ) => onUpdate( await api( 'threads/' + tid + '/task', { method: 'POST', body: { assignees: ids } } ) ) );
			}
			return true;
		}
		if ( 'card-refresh' === act ) {
			el.disabled = true;
			try {
				onUpdate( await api( 'cards/' + el.dataset.cid + '/refresh', { method: 'POST', body: {} } ) );
			} catch ( x ) {
				el.disabled = false;
				el.textContent = x.message;
			}
			return true;
		}
		if ( 'adopt' === act ) {
			const fid = +el.dataset.fid;
			await api( 'files/' + fid + '/adopt', { method: 'POST', body: {} } );
			const th = threads.find( ( x ) => ( x.messages || [] ).some( ( m ) => ( m.files || [] ).some( ( f ) => f.id === fid ) ) );
			if ( th ) {
				onUpdate( await api( 'threads/' + th.id + '?peek=1' ) );
			} else {
				el.replaceWith( Object.assign( document.createElement( 'small' ), { className: 'ff-adopted', textContent: t( 'In the media library' ) } ) );
			}
			return true;
		}
		if ( 'thread-edit' === act ) {
			const th = threads.find( ( x ) => x.id === +el.dataset.tid );
			const holder = $( '.ff-text', el.closest( '.ff-msg-body, .ff-box' ) );
			if ( th && holder ) {
				startEdit( holder, th.body, async ( text, title ) => onUpdate( await api( 'threads/' + th.id + '/edit', { method: 'POST', body: undefined === title ? { body: text } : { body: text, title } } ) ), th.title || undefined );
			}
			return true;
		}
		return false;
	};

	/**
	 * Due date changed in a task row (change event). Returns true when handled.
	 *
	 * @param {Element}  el       The date input.
	 * @param {Function} onUpdate Called with the updated thread.
	 */
	const dueChange = async ( el, onUpdate ) => {
		if ( 'due' !== el.dataset.ff ) {
			return false;
		}
		onUpdate( await api( 'threads/' + el.dataset.tid + '/task', { method: 'POST', body: { due: el.value } } ) );
		return true;
	};

	/* ------------------------------------------------------------------ target picker */

	/**
	 * "Attached to": a searchable picker instead of a long list. Shows the person's recent
	 * targets first and searches every target type as they type.
	 *
	 * @param {boolean} guest Guests must pick one of their own posts.
	 */
	const targetPicker = ( guest ) => {
		const wrap = document.createElement( 'div' );
		wrap.className = 'ff-picker';
		wrap.innerHTML = '<input type="hidden" name="target" value=""><button type="button" class="ff-pick" aria-haspopup="listbox" aria-expanded="false"></button>'
			+ '<div class="ff-pop" hidden><input type="search" class="ff-pick-q" placeholder="' + esc( t( 'Search …' ) ) + '" aria-label="' + esc( t( 'Attached to' ) ) + '"><div class="ff-pick-list" role="listbox" aria-label="' + esc( t( 'Attached to' ) ) + '"></div></div>';
		const hidden = $( 'input[type=hidden]', wrap );
		const btn = $( '.ff-pick', wrap );
		const pop = $( '.ff-pop', wrap );
		const q = $( '.ff-pick-q', wrap );
		const list = $( '.ff-pick-list', wrap );
		let items = [];
		const set = ( x ) => {
			hidden.value = x ? x.type + ':' + x.id : '';
			btn.innerHTML = icon.doc + '<span>' + esc( x ? x.title : t( 'Attached to: nothing' ) ) + '</span>' + icon.chev;
			btn.title = x ? ( x.label || '' ) + ': ' + x.title : '';
		};
		const option = ( x, i ) => '<button type="button" role="option" data-i="' + i + '"><span><b>' + esc( x ? x.title : t( 'Nothing' ) ) + '</b><small>' + esc( x ? [ x.label, x.subtitle || x.status ].filter( Boolean ).join( ' · ' ) : t( 'A thread without a target' ) ) + '</small></span></button>';
		const show = ( found ) => {
			items = ( guest ? [] : [ null ] ).concat( found );
			list.innerHTML = items.map( option ).join( '' ) + ( found.length ? '' : '<p class="ff-menu-h">' + esc( t( 'Nothing found.' ) ) + '</p>' );
		};
		const close = () => {
			pop.hidden = true;
			btn.setAttribute( 'aria-expanded', 'false' );
		};
		let timer = null;
		let seq = 0;
		q.addEventListener( 'input', () => {
			clearTimeout( timer );
			const mySeq = ++seq;
			timer = setTimeout( () => api( 'targets?' + query( { q: q.value } ) ).then( ( r ) => mySeq === seq && show( r ) ).catch( () => {} ), 200 );
		} );
		btn.addEventListener( 'click', () => {
			if ( ! pop.hidden ) {
				close();
				return;
			}
			pop.hidden = false;
			btn.setAttribute( 'aria-expanded', 'true' );
			q.value = '';
			show( boot.targets );
			q.focus();
		} );
		list.addEventListener( 'click', ( e ) => {
			const o = e.target.closest( '[data-i]' );
			if ( o ) {
				set( items[ +o.dataset.i ] );
				close();
				btn.focus();
			}
		} );
		wrap.addEventListener( 'keydown', ( e ) => {
			const opts = [ ...list.querySelectorAll( 'button' ) ];
			const i = opts.indexOf( document.activeElement );
			if ( 'Escape' === e.key && ! pop.hidden ) {
				e.preventDefault();
				e.stopPropagation();
				close();
				btn.focus();
			} else if ( 'ArrowDown' === e.key && ! pop.hidden ) {
				e.preventDefault();
				( opts[ Math.min( opts.length - 1, i + 1 ) ] || q ).focus();
			} else if ( 'ArrowUp' === e.key && ! pop.hidden ) {
				e.preventDefault();
				( i > 0 ? opts[ i - 1 ] : q ).focus();
			} else if ( 'Enter' === e.key && document.activeElement === q ) {
				e.preventDefault();
				if ( opts[ guest ? 0 : 1 ] || opts[ 0 ] ) {
					( opts[ guest ? 0 : 1 ] || opts[ 0 ] ).click();
				}
			}
		} );
		document.addEventListener( 'mousedown', ( e ) => {
			if ( ! pop.hidden && ! wrap.contains( e.target ) ) {
				close();
			}
		} );
		set( guest && boot.targets[ 0 ] ? boot.targets[ 0 ] : null );
		return wrap;
	};

	/* ------------------------------------------------------------------ composer */

	// Drafts survive a reload (kept per person in this browser only).
	let drafts = {};
	const draftsKey = () => 'flurfunk-drafts-' + ( boot ? boot.me.id : 0 );
	function loadDrafts() {
		try {
			drafts = JSON.parse( window.localStorage.getItem( draftsKey() ) || '{}' ) || {};
		} catch ( e ) {
			drafts = {};
		}
	}
	let draftTimer = null;
	const saveDrafts = () => {
		clearTimeout( draftTimer );
		draftTimer = setTimeout( () => {
			try {
				const keep = {};
				Object.keys( drafts ).forEach( ( k ) => {
					if ( drafts[ k ] ) {
						keep[ k ] = drafts[ k ];
					}
				} );
				window.localStorage.setItem( draftsKey(), JSON.stringify( keep ) );
			} catch ( e ) {}
		}, 300 );
	};
	const MENTION = /(?:^|\s)@([\p{L}\p{N}._-]*)$/u;
	const SLASH = /(?:^|\s)\/([a-z0-9_-]*)(?:[ \t]+([^\n]*))?$/i;

	/**
	 * Text box with @mentions, /commands, files and cards.
	 *
	 * @param {Object} o key, label, placeholder, submitLabel, extra (HTML for the row), onSubmit(payload, el).
	 */
	/* ------------------------------------------------------------------ lightbox */

	// Is a lightbox or WordPress's media dialog open? Then Escape belongs to it.
	const overlayOpen = () => !! document.querySelector( 'dialog.ff-lightbox[open]' ) || [ ...document.querySelectorAll( '.media-modal' ) ].some( ( m ) => null !== m.offsetParent );
	const lightbox = ( links, start ) => {
		const dlg = document.createElement( 'dialog' );
		dlg.className = 'ff-lightbox';
		dlg.setAttribute( 'aria-label', t( 'Image' ) );
		let i = start;
		const show = () => {
			const a = links[ i ];
			dlg.innerHTML = '<figure><img src="' + esc( a.href ) + '" alt="' + esc( a.dataset.name || '' ) + '"><figcaption><span>' + esc( a.dataset.name || '' ) + ( links.length > 1 ? ' · ' + ( i + 1 ) + '/' + links.length : '' ) + '</span>'
				+ '<a href="' + esc( a.href ) + '" download target="_blank" rel="noopener">' + esc( t( 'Download' ) ) + '</a></figcaption></figure>'
				+ ( links.length > 1 ? '<button type="button" class="ff-lb-prev" data-lb="-1" aria-label="' + esc( t( 'Previous image' ) ) + '">‹</button><button type="button" class="ff-lb-next" data-lb="1" aria-label="' + esc( t( 'Next image' ) ) + '">›</button>' : '' )
				+ '<button type="button" class="ff-lb-close" data-lb="close" aria-label="' + esc( t( 'Close' ) ) + '">×</button>';
		};
		const step = ( d ) => {
			i = ( i + d + links.length ) % links.length;
			show();
		};
		dlg.addEventListener( 'click', ( e ) => {
			const b = e.target.closest( '[data-lb]' );
			if ( b ) {
				return 'close' === b.dataset.lb ? dlg.close() : step( +b.dataset.lb );
			}
			if ( e.target === dlg || 'FIGURE' === e.target.tagName ) {
				dlg.close(); // A click next to the image.
			}
		} );
		dlg.addEventListener( 'keydown', ( e ) => {
			if ( 'ArrowRight' === e.key || 'ArrowLeft' === e.key ) {
				step( 'ArrowRight' === e.key ? 1 : -1 );
			} else if ( 'Escape' === e.key ) {
				e.preventDefault();
				dlg.close();
			}
		} );
		dlg.addEventListener( 'close', () => dlg.remove() );
		show();
		document.body.appendChild( dlg );
		dlg.showModal();
		$( '.ff-lb-close', dlg ).focus();
	};
	document.addEventListener( 'click', ( e ) => {
		const a = e.target.closest && e.target.closest( 'a[data-ff="lightbox"]' );
		if ( ! a || e.metaKey || e.ctrlKey || e.shiftKey || 0 !== e.button ) {
			return; // Modifier clicks still open the file in a new tab.
		}
		e.preventDefault();
		const group = [ ...( a.closest( '.ff-files' ) || a.parentNode ).querySelectorAll( 'a[data-ff="lightbox"]' ) ];
		lightbox( group, Math.max( 0, group.indexOf( a ) ) );
	} );

	const composer = ( o ) => {
		const el = document.createElement( 'form' );
		el.className = 'ff-compose' + ( o.className ? ' ' + o.className : '' );
		const state = { files: [], atts: [], busy: false };
		const id = 'ff-ta-' + o.key.replace( /[^a-z0-9_-]/gi, '-' );
		el.innerHTML = ( o.label ? '<label class="ff-label" for="' + id + '">' + esc( o.label ) + '</label>' : '' )
			+ ( o.titleField ? '<input type="text" name="title" class="ff-title-input" placeholder="' + esc( o.titleField ) + '" aria-label="' + esc( o.titleField ) + '">' : '' )
			+ '<textarea id="' + id + '" rows="' + ( o.autoGrow ? 1 : 2 ) + '" placeholder="' + esc( o.placeholder || t( 'Write … (@name, / for cards)' ) ) + '"' + ( o.label ? '' : ' aria-label="' + esc( o.placeholder || t( 'Message' ) ) + '"' ) + '></textarea>'
			+ '<div class="ff-menu-slot"></div><div class="ff-pending"></div>'
			+ '<div class="ff-row"><div class="ff-left">'
			+ ( boot.me.upload ? '<label class="ff-link ff-upload">' + icon.clip + '<span>' + esc( t( 'Attach file' ) ) + '</span><input type="file" multiple hidden></label>' : '' )
			+ ( boot.me.upload && window.wp && window.wp.media ? '<button type="button" class="ff-link ff-upload" data-ff="library">' + icon.image + '<span>' + esc( t( 'From media library' ) ) + '</span></button>' : '' )
			+ ( o.extra || '' ) + '</div>'
			+ '<span class="ff-hint">' + esc( t( 'Ctrl+Enter sends' ) ) + '</span>'
			+ '<button type="submit" class="ff-send">' + esc( o.submitLabel || t( 'Send' ) ) + '</button></div>'
			+ '<p class="ff-error" role="alert" hidden></p>';
		const ta = $( 'textarea', el );
		const slot = $( '.ff-menu-slot', el );
		const pend = $( '.ff-pending', el );
		const err = $( '.ff-error', el );
		ta.value = drafts[ o.key ] || '';
		el.classList.toggle( 'has-text', '' !== ta.value );

		const showPending = () => {
			pend.innerHTML = ( state.atts.length ? '<div class="ff-cards">' + state.atts.map( ( a, i ) => card( a, i ) ).join( '' ) + '</div>' : '' )
				+ state.files.map( ( f, i ) => '<span class="ff-file is-pending">' + ( f.thumb ? '<img class="ff-pend-thumb" src="' + esc( f.thumb ) + '" alt="">' : icon.file ) + esc( f.name ) + ( f.uploading ? ' <small>' + esc( t( 'uploading …' ) ) + '</small>' : '' ) + '<button type="button" class="ff-x" data-ff="file-x" data-i="' + i + '" aria-label="' + esc( t( 'Remove file' ) ) + '">×</button></span>' ).join( '' );
		};
		const before = () => ta.value.slice( 0, ta.selectionStart );
		const replaceBefore = ( re, text ) => {
			const b = before();
			const m = b.match( re );
			if ( ! m ) {
				return;
			}
			const start = b.length - m[ 0 ].length + ( /^\s/.test( m[ 0 ] ) ? 1 : 0 );
			ta.value = ta.value.slice( 0, start ) + text + ta.value.slice( ta.selectionStart );
			ta.selectionStart = ta.selectionEnd = start + text.length;
			drafts[ o.key ] = ta.value;
			saveDrafts();
		};
		let searchTimer = null;
		let searchSeq = 0;
		const menu = ( title, items ) => {
			slot.innerHTML = items.length || title ? '<div class="ff-menu" role="listbox" aria-label="' + esc( title ) + '">' + ( title ? '<p class="ff-menu-h">' + esc( title ) + '</p>' : '' ) + items.join( '' ) + '</div>' : '';
		};
		const updateMenu = () => {
			const b = before();
			const mm = b.match( MENTION );
			if ( mm ) {
				const q = mm[ 1 ].toLowerCase();
				const list = boot.people.filter( ( p ) => p.name.toLowerCase().split( /\s+/ ).some( ( w ) => w.startsWith( q ) ) || p.name.toLowerCase().startsWith( q ) ).slice( 0, 8 );
				return menu( list.length ? '' : '', list.map( ( p ) => '<button type="button" role="option" data-ff="pick-person" data-name="' + esc( p.name ) + '">' + ava( p.id ) + '<span><b>' + esc( p.name ) + '</b><small>' + esc( p.role ) + '</small></span></button>' ) );
			}
			const sm = b.match( SLASH );
			if ( sm ) {
				const prov = boot.providers.find( ( p ) => p.command === sm[ 1 ].toLowerCase() );
				if ( prov && undefined !== sm[ 2 ] ) {
					const seq = ++searchSeq;
					clearTimeout( searchTimer );
					searchTimer = setTimeout( () => {
						api( 'providers/' + prov.id + '/search?' + query( { q: sm[ 2 ] } ) ).then( ( items ) => {
							if ( seq !== searchSeq ) {
								return;
							}
							state.results = items;
							menu( '/' + prov.command + ' – ' + prov.hint, items.length
								? items.map( ( it, i ) => '<button type="button" role="option" data-ff="pick-item" data-p="' + esc( prov.id ) + '" data-i="' + i + '"><span><b>' + esc( it.title ) + '</b><small>' + esc( it.subtitle ) + '</small></span></button>' )
								: [ '<p class="ff-menu-h">' + esc( t( 'Nothing found.' ) ) + '</p>' ] );
						} ).catch( () => menu( '', [] ) );
					}, 180 );
					return;
				}
				const list = boot.providers.filter( ( p ) => p.command.startsWith( sm[ 1 ].toLowerCase() ) );
				return menu( list.length ? t( 'Insert a card' ) : '', list.map( ( p ) => '<button type="button" role="option" data-ff="pick-cmd" data-cmd="' + esc( p.command ) + '"><span><b>/' + esc( p.command ) + '</b><small>' + esc( p.hint ) + '</small></span></button>' ) );
			}
			menu( '', [] );
		};

		// Grow with the text (up to 40% of the window), never shrink below the start height.
		const grow = () => {
			if ( ! o.autoGrow ) {
				return;
			}
			ta.style.height = 'auto';
			ta.style.height = Math.min( ta.scrollHeight + 2, Math.round( window.innerHeight * 0.4 ) ) + 'px';
		};
		ta.addEventListener( 'input', () => {
			grow();
			drafts[ o.key ] = ta.value;
			saveDrafts();
			el.classList.toggle( 'has-text', '' !== ta.value );
			updateMenu();
		} );
		ta.addEventListener( 'click', updateMenu );
		el.addEventListener( 'keydown', ( e ) => {
			const opts = [ ...slot.querySelectorAll( 'button' ) ];
			if ( e.target === ta && 'Enter' === e.key && ( e.metaKey || e.ctrlKey ) ) {
				e.preventDefault();
				el.requestSubmit();
				return;
			}
			if ( ! opts.length ) {
				return;
			}
			const i = opts.indexOf( e.target );
			if ( 'ArrowDown' === e.key && ( e.target === ta || i >= 0 ) ) {
				e.preventDefault();
				opts[ Math.min( opts.length - 1, i + 1 ) ].focus();
			} else if ( 'ArrowUp' === e.key && i >= 0 ) {
				e.preventDefault();
				( i ? opts[ i - 1 ] : ta ).focus();
			} else if ( 'Escape' === e.key ) {
				e.preventDefault();
				menu( '', [] );
				ta.focus();
			}
		} );
		el.addEventListener( 'click', ( e ) => {
			const b = e.target.closest( '[data-ff]' );
			if ( ! b || ! el.contains( b ) ) {
				return;
			}
			const act = b.dataset.ff;
			if ( 'pick-person' === act ) {
				replaceBefore( MENTION, '@' + b.dataset.name + ' ' );
				menu( '', [] );
				ta.focus();
			} else if ( 'pick-cmd' === act ) {
				replaceBefore( /\/[a-z0-9_-]*$/i, '/' + b.dataset.cmd + ' ' );
				ta.focus();
				updateMenu();
			} else if ( 'pick-item' === act ) {
				const it = state.results[ +b.dataset.i ];
				state.atts.push( { provider: b.dataset.p, ref: it.ref, preview: { title: it.title, subtitle: it.subtitle } } );
				replaceBefore( SLASH, '' );
				menu( '', [] );
				showPending();
				ta.focus();
			} else if ( 'card-x' === act ) {
				state.atts.splice( +b.dataset.i, 1 );
				showPending();
			} else if ( 'file-x' === act ) {
				state.files.splice( +b.dataset.i, 1 );
				showPending();
			} else if ( 'library' === act ) {
				// WordPress's own media dialog; the file stays where it is, the message refers to it.
				const frame = window.wp.media( { title: t( 'Attach from the media library' ), multiple: 'add', button: { text: t( 'Attach' ) } } );
				frame.on( 'select', () => {
					frame.state().get( 'selection' ).toJSON().forEach( ( a ) => {
						if ( ! state.files.some( ( f ) => f.id === a.id ) ) {
							state.files.push( { id: a.id, name: a.filename, size: a.filesizeInBytes, thumb: 'image' === a.type ? ( ( a.sizes && a.sizes.thumbnail ) || a ).url : '' } );
						}
					} );
					showPending();
					ta.focus();
				} );
				frame.open();
			}
		} );
		const upload = $( 'input[type=file]', el );
		if ( upload ) {
			upload.addEventListener( 'change', () => {
				[ ...upload.files ].forEach( ( f ) => {
					const entry = { name: f.name, size: f.size, uploading: true, thumb: /^image\//.test( f.type ) ? URL.createObjectURL( f ) : '' };
					state.files.push( entry );
					showPending();
					fetch( cfg.fileEndpoint, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Disposition': 'attachment; filename="' + encodeURIComponent( f.name ) + '"', 'Content-Type': f.type || 'application/octet-stream' },
						body: f,
					} ).then( ( r ) => r.json().then( ( d ) => ( r.ok ? d : Promise.reject( new Error( d.message ) ) ) ) ).then( ( d ) => {
						entry.id = d.id;
						entry.uploading = false;
						showPending();
					} ).catch( ( x ) => {
						state.files.splice( state.files.indexOf( entry ), 1 );
						showPending();
						err.textContent = x.message;
						err.hidden = false;
					} );
				} );
				upload.value = '';
			} );
		}
		el.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			if ( state.busy ) {
				return;
			}
			const text = encode( ta.value.trim() );
			if ( state.files.some( ( f ) => f.uploading ) ) {
				err.textContent = t( 'Please wait until the upload is finished.' );
				err.hidden = false;
				return;
			}
			const title = el.elements.title ? el.elements.title.value.trim() : '';
			if ( ! text && ! title && ! state.files.length && ! state.atts.length && ! o.allowEmpty ) {
				ta.focus();
				return;
			}
			state.busy = true;
			err.hidden = true;
			el.classList.add( 'is-busy' );
			Promise.resolve( o.onSubmit( {
				...( el.elements.title ? { title } : {} ),
				body: text,
				files: state.files.map( ( f ) => f.id ),
				attachments: state.atts.map( ( a ) => ( { provider: a.provider, ref: a.ref } ) ),
			}, el ) ).then( () => {
				ta.value = '';
				if ( el.elements.title ) {
					el.elements.title.value = '';
				}
				drafts[ o.key ] = '';
				saveDrafts();
				el.classList.remove( 'has-text' );
				ta.style.height = '';
				state.files = [];
				state.atts = [];
				showPending();
			} ).catch( ( x ) => {
				err.textContent = x.message || t( 'Could not send.' );
				err.hidden = false;
			} ).finally( () => {
				state.busy = false;
				el.classList.remove( 'is-busy' );
			} );
		} );
		if ( ta.value ) {
			requestAnimationFrame( grow );
		}
		el.focusText = () => {
			ta.focus();
			ta.selectionStart = ta.selectionEnd = ta.value.length;
		};
		return el;
	};

	/* ------------------------------------------------------------------ look */

	// Embedded pieces (editor panel, lists) sit in light wp-admin screens and stay light;
	// the app follows the person's choice.
	const applyLook = ( root, embedded ) => {
		root.classList.add( 'ff-root' );
		root.style.setProperty( '--ff-accent', boot.look.accent );
		root.style.setProperty( '--ff-side', boot.look.sidebar );
		const theme = embedded ? 'light' : boot.me.prefs.theme;
		if ( 'system' === theme ) {
			root.removeAttribute( 'data-ff-theme' );
		} else {
			root.setAttribute( 'data-ff-theme', theme );
		}
	};

	/* ================================================================== app */

	const mountApp = async ( root ) => {
		root.innerHTML = '<p class="ff-loading">' + esc( t( 'Loading …' ) ) + '</p>';
		try {
			await loadBoot();
		} catch ( e ) {
			root.innerHTML = '<p class="ff-loading">' + esc( e.message ) + '</p>';
			return;
		}
		applyLook( root );
		const params = new URLSearchParams( location.search );
		const ui = { view: params.get( 'view' ) || 'all', thread: null, list: [], more: false, settings: false, loading: true };
		const start = ui.view.match( /^thread:(\d+)$/ );
		if ( start ) {
			ui.view = 'all';
			ui.thread = +start[ 1 ];
		}

		root.innerHTML = '<div class="ff-app">'
			+ '<nav class="ff-nav" aria-label="' + esc( t( 'Channels' ) ) + '"></nav>'
			+ '<section class="ff-main"><header class="ff-head"></header><div class="ff-feed" aria-live="polite"></div><div class="ff-compose-slot"></div></section>'
			+ '<section class="ff-pane" hidden></section></div>';
		const app = $( '.ff-app', root );
		const nav = $( '.ff-nav', root );
		const head = $( '.ff-head', root );
		const feed = $( '.ff-feed', root );
		const slot = $( '.ff-compose-slot', root );
		const pane = $( '.ff-pane', root );

		const viewInfo = () => {
			const [ kind, a, b ] = ui.view.split( ':' );
			if ( 'channel' === kind ) {
				const c = channelOf( a );
				return { title: '#' + chName( a ), sub: c ? c.topic : '', args: { channel: a }, channel: a };
			}
			if ( 'target' === kind ) {
				const info = boot.recent.concat( boot.targets ).find( ( x ) => x.type === a && x.id === +b );
				return { title: info ? info.title : t( 'Post' ), sub: t( 'All conversations about this post, from every channel' ), args: { target: a + ':' + b }, target: { type: a, id: +b }, url: info && info.url };
			}
			if ( 'mentions' === kind ) {
				return { title: t( 'Mentions' ), sub: t( 'Where someone mentioned you' ), args: { view: 'mentions' } };
			}
			if ( 'tasks' === kind ) {
				return { title: t( 'Open tasks' ), sub: t( 'Everything not ticked off yet' ), args: { view: 'tasks' } };
			}
			if ( 'mine' === kind ) {
				return { title: t( 'My tasks' ), sub: t( 'Assigned to you, by due date' ), args: { view: 'mine' } };
			}
			if ( 'notifications' === kind ) {
				return { title: t( 'Notifications' ), sub: t( 'What happened for you' ), args: {}, notifications: true };
			}
			if ( 'search' === kind ) {
				const q = ui.view.slice( 7 );
				return { title: t( 'Search: %s', q ), sub: t( 'Threads and replies that contain it' ), args: { view: 'all', q }, search: q };
			}
			return { title: t( 'All threads' ), sub: t( 'Latest activity first' ), args: { view: 'all' } };
		};

		const syncUrl = () => {
			const p = new URLSearchParams( location.search );
			p.set( 'view', ui.thread ? 'thread:' + ui.thread : ui.view );
			history.replaceState( null, '', location.pathname + '?' + p.toString() );
		};

		/* column widths: drag the edge, arrow keys, double-click resets */
		const colsKey = 'flurfunk-cols-' + boot.me.id;
		const cols = ( () => {
			try {
				return JSON.parse( window.localStorage.getItem( colsKey ) || '{}' ) || {};
			} catch ( e ) {
				return {};
			}
		} )();
		const applyCols = () => {
			[ 'nav', 'pane' ].forEach( ( c ) => ( cols[ c ] ? app.style.setProperty( '--ff-' + c + '-w', cols[ c ] + 'px' ) : app.style.removeProperty( '--ff-' + c + '-w' ) ) );
			try {
				window.localStorage.setItem( colsKey, JSON.stringify( cols ) );
			} catch ( e ) {}
		};
		const limits = ( c ) => {
			const w = app.getBoundingClientRect().width;
			return 'nav' === c ? [ 160, Math.max( 160, Math.min( 440, w * 0.4 ) ) ] : [ 280, Math.max( 280, w * 0.6 ) ];
		};
		const setCol = ( c, px ) => {
			const [ lo, hi ] = limits( c );
			cols[ c ] = Math.round( Math.min( hi, Math.max( lo, px ) ) );
			applyCols();
			const h = root.querySelector( '.ff-resize[data-col="' + c + '"]' );
			if ( h ) {
				h.setAttribute( 'aria-valuenow', cols[ c ] );
			}
		};
		const resizer = ( c, label ) => {
			const [ lo, hi ] = limits( c );
			const now = cols[ c ] || ( 'nav' === c ? 240 : 400 );
			return '<div class="ff-resize" data-col="' + c + '" role="separator" aria-orientation="vertical" tabindex="0" aria-valuemin="' + Math.round( lo ) + '" aria-valuemax="' + Math.round( hi ) + '" aria-valuenow="' + Math.round( Math.min( hi, Math.max( lo, now ) ) ) + '" aria-label="' + esc( label ) + '" title="' + esc( label ) + '"></div>';
		};
		applyCols();
		root.addEventListener( 'pointerdown', ( e ) => {
			const h = e.target.closest( '.ff-resize' );
			if ( ! h ) {
				return;
			}
			e.preventDefault();
			const c = h.dataset.col;
			const box = app.getBoundingClientRect();
			h.setPointerCapture( e.pointerId );
			h.classList.add( 'is-dragging' );
			app.classList.add( 'is-resizing' );
			const move = ( ev ) => setCol( c, 'nav' === c ? ev.clientX - box.left : box.right - ev.clientX );
			const up = () => {
				h.removeEventListener( 'pointermove', move );
				h.classList.remove( 'is-dragging' );
				app.classList.remove( 'is-resizing' );
			};
			h.addEventListener( 'pointermove', move );
			h.addEventListener( 'pointerup', up, { once: true } );
			h.addEventListener( 'pointercancel', up, { once: true } );
		} );
		root.addEventListener( 'dblclick', ( e ) => {
			const h = e.target.closest( '.ff-resize' );
			if ( h ) {
				delete cols[ h.dataset.col ];
				applyCols();
			}
		} );
		root.addEventListener( 'keydown', ( e ) => {
			const h = e.target.closest && e.target.closest( '.ff-resize' );
			if ( h && ( 'ArrowLeft' === e.key || 'ArrowRight' === e.key ) ) {
				e.preventDefault();
				const c = h.dataset.col;
				const now = ( 'nav' === c ? nav : pane ).getBoundingClientRect().width;
				const step = ( 'ArrowRight' === e.key ? 1 : -1 ) * ( 'nav' === c ? 1 : -1 ) * 16;
				setCol( c, now + step );
			}
		} );

		/* nav */
		const renderNav = () => {
			const c = boot.counts;
			const btn = ( view, glyph, label, n, title ) => '<button type="button" data-ff="nav" data-view="' + esc( view ) + '"' + ( ui.view === view && ! ui.threadOnly ? ' aria-current="true"' : '' ) + ( n ? ' class="is-unread"' : '' ) + ( title ? ' title="' + esc( title ) + '"' : '' ) + '><span class="ff-glyph" aria-hidden="true">' + glyph + '</span><span class="ff-lbl">' + esc( label ) + '</span>' + ( n ? '<b>' + n + '</b>' : '' ) + '</button>';
			const targets = boot.recent.filter( ( x ) => x.title );
			nav.innerHTML = resizer( 'nav', t( 'Drag to make the sidebar wider or narrower' ) ) + '<div class="ff-ws">' + ( boot.look.logo ? '<img src="' + esc( boot.look.logo ) + '" alt="">' : '' ) + '<span>' + esc( boot.look.name ) + '</span>'
				+ '<button type="button" class="ff-bell" data-ff="nav" data-view="notifications"' + ( 'notifications' === ui.view ? ' aria-current="true"' : '' ) + ' aria-label="' + esc( c.notifications ? t( 'Notifications (%s new)', c.notifications ) : t( 'Notifications' ) ) + '">' + icon.bell + ( c.notifications ? '<b>' + c.notifications + '</b>' : '' ) + '</button></div>'
				+ '<div class="ff-nav-list">'
				+ btn( 'all', '≡', t( 'All threads' ), 0 )
				+ btn( 'mentions', '@', t( 'Mentions' ), c.mentions )
				+ btn( 'mine', '✓', t( 'My tasks' ), c.mine || 0, c.overdue ? t( '%s overdue', c.overdue ) : '' ).replace( '<b>', c.overdue ? '<b class="is-over">' : '<b class="is-soft">' )
				+ btn( 'tasks', '☐', t( 'Open tasks' ), 0 )
				+ '<h2 class="ff-nav-h">' + esc( t( 'Channels' ) ) + '</h2>'
				+ boot.channels.map( ( ch ) => btn( 'channel:' + ch.id, '#', ch.name || ch.id, c.channels[ ch.id ] || 0, ch.topic ) ).join( '' )
				+ ( targets.length ? '<h2 class="ff-nav-h">' + esc( 'guest' === boot.me.kind ? t( 'My posts' ) : targets.every( ( x ) => 'post' === x.type ) ? t( 'Posts' ) : t( 'Linked' ) ) + '</h2>' + targets.map( ( x ) => btn( 'target:' + x.type + ':' + x.id, '▤', x.title, c.targets[ x.type + ':' + x.id ] || 0, x.title ) ).join( '' ) : '' )
				+ '</div>'
				+ '<div class="ff-me">' + ( ui.settings ? settingsHtml() : '' )
				+ '<button type="button" class="ff-me-tile" data-ff="settings" aria-expanded="' + ui.settings + '"><span class="ff-sq" aria-hidden="true">' + esc( initials( boot.me.name ) ) + photo( person( boot.me.id ) ) + '</span><span class="ff-me-name"><strong>' + esc( boot.me.name ) + '</strong><small>' + esc( paused() ? t( 'Paused until %s', pausedUntil() ) : boot.me.role ) + '</small></span>' + icon.gear + '<span class="screen-reader-text">' + esc( t( 'My settings' ) ) + '</span></button></div>';
		};
		const settingsHtml = () => {
			const p = boot.me.prefs;
			const seg = [ [ 'light', t( 'Light' ) ], [ 'dark', t( 'Dark' ) ], [ 'system', t( 'Automatic' ) ] ];
			const pic = [ [ 'photo', t( 'Photo' ) ], [ 'initials', t( 'Initials' ) ] ];
			const mail = [ [ 'mentions', t( 'Right away: mentions, tasks, replies in my threads' ) ], [ 'digest', t( 'Once a day: a summary at 7 am' ) ], [ 'all', t( 'Every new message I can see' ) ], [ 'none', t( 'Never – I check myself' ) ] ];
			const push = [ [ 'mentions', t( 'Mentions, tasks, replies' ) ], [ 'all', t( 'Everything' ) ], [ 'none', t( 'Nothing' ) ] ];
			const [ qFrom, qTo ] = p.quiet ? p.quiet.split( '-' ) : [ '22:00', '07:00' ];
			const ps = ui.pushState || 'unknown';
			const pushBox = 'unsupported' === ps
				? '<p class="ff-meta">' + esc( t( 'Push notifications are not available in this browser or on this server.' ) ) + '</p>'
				: 'blocked' === ps
					? '<p class="ff-meta">' + esc( t( 'Notifications are blocked in this browser. Allow them in the site settings of your browser.' ) ) + '</p>'
					: '<div class="ff-row ff-push-row"><span class="ff-push-state">' + esc( 'on' === ps ? t( 'On for this device' ) : t( 'Off for this device' ) ) + '</span>'
						+ '<button type="button" class="ff-link" data-ff="push-toggle">' + esc( 'on' === ps ? t( 'Turn off' ) : t( 'Turn on' ) ) + '</button>'
						+ ( 'on' === ps ? '<button type="button" class="ff-link" data-ff="push-test">' + esc( t( 'Send a test' ) ) + '</button>' : '' ) + '</div>'
						+ ( ui.pushError ? '<p class="ff-error">' + esc( ui.pushError ) + '</p>' : '' )
						+ ( boot.push && boot.push.devices ? '<p class="ff-meta">' + esc( t( 'Devices with push: %s', boot.push.devices ) ) + '</p>' : '' );
			return '<div class="ff-settings" role="group" aria-label="' + esc( t( 'My settings' ) ) + '">'
				+ '<h3>' + esc( t( 'Appearance' ) ) + '</h3><div class="ff-seg">' + seg.map( ( [ v, l ] ) => '<button type="button" data-ff="pref" data-pref="theme" data-val="' + v + '" aria-pressed="' + ( p.theme === v ) + '">' + esc( l ) + '</button>' ).join( '' ) + '</div>'
				+ '<h3>' + esc( t( 'Profile picture' ) ) + '</h3><div class="ff-seg">' + pic.map( ( [ v, l ] ) => '<button type="button" data-ff="pref" data-pref="avatar" data-val="' + v + '" aria-pressed="' + ( p.avatar === v ) + '">' + esc( l ) + '</button>' ).join( '' ) + '</div>'
				+ '<p class="ff-meta">' + esc( t( 'Your picture from your profile or Gravatar. Without one, your initials show.' ) ) + '</p>'
				+ '<h3>' + esc( t( 'Email me' ) ) + '</h3>' + mail.map( ( [ v, l ] ) => '<label><input type="radio" name="ff-mail" data-ff="pref" data-pref="mail" value="' + v + '"' + ( p.mail === v ? ' checked' : '' ) + '> ' + esc( l ) + '</label>' ).join( '' )
				+ '<label class="ff-hour">' + esc( t( 'Daily summary and task reminders at' ) ) + ' <select data-ff="pref" data-pref="hour">' + Array.from( { length: 24 }, ( _, h ) => '<option value="' + h + '"' + ( p.hour === h ? ' selected' : '' ) + '>' + pad2( h ) + ':00</option>' ).join( '' ) + '</select></label>'
				+ '<h3>' + esc( t( 'Push notifications' ) ) + '</h3>' + pushBox
				+ ( 'on' === ps ? '<div class="ff-seg">' + push.map( ( [ v, l ] ) => '<button type="button" data-ff="pref" data-pref="push" data-val="' + v + '" aria-pressed="' + ( p.push === v ) + '">' + esc( l ) + '</button>' ).join( '' ) + '</div>' : '' )
				+ ( 'on' === ps || browserNotesOn() ? pauseBox( p ) : '' )
				+ '<label><input type="checkbox" data-ff="quiet-on"' + ( p.quiet ? ' checked' : '' ) + '> ' + esc( t( 'Quiet hours (no push)' ) ) + '</label>'
				+ '<div class="ff-row ff-quiet"><input type="time" data-ff="quiet" data-part="from" value="' + esc( qFrom ) + '" aria-label="' + esc( t( 'From' ) ) + '"' + ( p.quiet ? '' : ' disabled' ) + '> – <input type="time" data-ff="quiet" data-part="to" value="' + esc( qTo ) + '" aria-label="' + esc( t( 'To' ) ) + '"' + ( p.quiet ? '' : ' disabled' ) + '></div>'
				+ ( 'Notification' in window ? '<label><input type="checkbox" data-ff="browser-notes"' + ( browserNotesOn() ? ' checked' : '' ) + '> ' + esc( t( 'Show notifications while Flurfunk is open in the background' ) ) + '</label>' : '' )
				+ '<h3>' + esc( t( 'Badge' ) ) + '</h3><label><input type="checkbox" data-ff="pref" data-pref="badge"' + ( p.badge ? ' checked' : '' ) + '> ' + esc( t( 'Show the unread number on the menu icon' ) ) + '</label>'
				+ ( boot.settings ? '<a class="ff-link" href="' + esc( boot.settings ) + '" target="_top">' + esc( t( 'Flurfunk settings for this site' ) ) + '</a>' : '' )
				+ '</div>';
		};

		const pauseBox = ( p ) => paused()
			? '<div class="ff-row ff-pause"><span>' + esc( t( 'Paused until %s', pausedUntil() ) ) + '</span><button type="button" class="ff-link" data-ff="pref" data-pref="pause" data-val="none">' + esc( t( 'Resume' ) ) + '</button></div>'
			: '<div class="ff-row ff-pause"><span>' + esc( t( 'Pause:' ) ) + '</span>' + [ [ '1h', t( '1 hour' ) ], [ '3h', t( '3 hours' ) ], [ 'tomorrow', t( 'until tomorrow' ) ] ].map( ( [ v, l ] ) => '<button type="button" class="ff-link" data-ff="pref" data-pref="pause" data-val="' + v + '">' + esc( l ) + '</button>' ).join( '' ) + '</div>';

		/* feed */
		const unreadHere = () => {
			const v = viewInfo();
			const c = boot.counts;
			return v.channel ? c.channels[ v.channel ] || 0 : v.target ? c.targets[ v.target.type + ':' + v.target.id ] || 0 : c.total;
		};
		const channelNotify = ( slug ) => {
			const cur = ( boot.me.prefs.channels || {} )[ slug ] || 'default';
			const opts = [ [ 'default', t( 'Notify: as usual' ) ], [ 'all', t( 'Notify: every new post' ) ], [ 'mentions', t( 'Notify: only mentions' ) ] ];
			return '<select class="ff-chan-notify" data-ff="channel-notify" data-slug="' + esc( slug ) + '" aria-label="' + esc( t( 'Notifications for this channel' ) ) + '">'
				+ opts.map( ( [ v, l ] ) => '<option value="' + v + '"' + ( cur === v ? ' selected' : '' ) + '>' + esc( l ) + '</option>' ).join( '' ) + '</select>';
		};
		const renderHead = () => {
			const v = viewInfo();
			head.innerHTML = '<div class="ff-head-text"><h1>' + esc( v.title ) + '</h1><p>' + esc( v.sub ) + ( v.url ? ' · <a href="' + esc( v.url ) + '" target="_top">' + esc( t( 'Open post' ) ) + '</a>' : '' ) + '</p></div>'
				+ '<div class="ff-head-tools">' + ( v.notifications ? ( boot.counts.notifications ? '<button type="button" class="ff-link" data-ff="notes-read">' + esc( t( 'Mark all as read' ) ) + '</button>' : '' ) : unreadHere() && ! v.target && ! v.search ? '<button type="button" class="ff-link" data-ff="read-all">' + esc( t( 'Mark all as read' ) ) + '</button>' : '' )
				+ ( v.channel ? channelNotify( v.channel ) : '' )
				+ '<form class="ff-search" role="search"><input type="search" name="q" value="' + esc( v.search || '' ) + '" placeholder="' + esc( t( 'Search …' ) ) + '" aria-label="' + esc( t( 'Search conversations' ) ) + '"></form></div>';
		};
		const postHtml = ( th ) => '<article class="ff-post' + ( ui.thread === th.id ? ' is-current' : '' ) + ( th.unread ? ' is-unread' : '' ) + '" data-ff="open" data-id="' + th.id + '" tabindex="0" aria-label="' + esc( ( th.title || th.body || '' ).slice( 0, 80 ) ) + '">'
			+ ava( th.author ) + '<div class="ff-post-body">'
			+ '<p class="ff-who">' + nameHtml( th.author ) + ' <span>' + esc( when( th.created ) ) + ( viewInfo().channel ? '' : ' · #' + esc( chName( th.channel ) ) ) + '</span> ' + statusPill( th ) + ' ' + audiencePill( th ) + '</p>'
			+ ( th.title ? '<h3 class="ff-title">' + esc( th.title ) + '</h3>' : '' )
			+ ( th.body ? '<p class="ff-preview">' + preview( th.body ) + '</p>' : '' )
			+ cards( th.attachments ) + ( viewInfo().target ? '' : targetChip( th ) )
			+ ( dueChip( th ) || assigneeStack( th ) ? '<p class="ff-task-line">' + dueChip( th ) + assigneeStack( th ) + '</p>' : '' )
			+ summary( th, false, 'open' ) + '</div></article>';
		const noteHtml = ( n ) => '<article class="ff-post ff-note-item' + ( n.read ? '' : ' is-unread' ) + '" data-ff="open-note" data-id="' + n.thread + '" data-nid="' + n.id + '" tabindex="0">'
			+ ( n.actor ? ava( n.actor ) : '<span class="ff-ava" aria-hidden="true">' + icon.bell + '</span>' ) + '<div class="ff-post-body">'
			+ '<p class="ff-who"><b>' + esc( n.headline ) + '</b> <span>' + esc( when( n.created ) ) + ( n.channel ? ' · #' + esc( chName( n.channel ) ) : '' ) + '</span></p>'
			+ '<p class="ff-title">' + esc( n.topic ) + '</p>'
			+ ( n.text && n.text !== n.topic ? '<p class="ff-preview">' + preview( n.text ) + '</p>' : '' ) + '</div></article>';
		const renderFeed = () => {
			if ( viewInfo().notifications ) {
				feed.innerHTML = ui.notes && ui.notes.length
					? ui.notes.map( noteHtml ).join( '' ) + ( ui.more ? '<button type="button" class="ff-more" data-ff="more">' + esc( t( 'Load older notifications' ) ) + '</button>' : '' )
					: '<p class="ff-empty">' + esc( ui.loading ? t( 'Loading …' ) : t( 'No notifications yet.' ) ) + '</p>';
				return;
			}
			feed.innerHTML = ui.list.length
				? ui.list.map( postHtml ).join( '' ) + ( ui.more ? '<button type="button" class="ff-more" data-ff="more">' + esc( t( 'Load older threads' ) ) + '</button>' : '' )
				: '<p class="ff-empty">' + esc( ui.loading ? t( 'Loading …' ) : t( 'Nothing here yet. Start a thread below.' ) ) + '</p>';
		};
		const loadList = async ( append ) => {
			const v = viewInfo();
			if ( v.notifications ) {
				const before = append && ui.notes && ui.notes.length ? ui.notes[ ui.notes.length - 1 ].id : 0;
				const items = await api( 'notifications?' + query( { limit: 40, before } ) );
				ui.notes = append ? ( ui.notes || [] ).concat( items ) : items;
				ui.more = items.length === 40;
				ui.loading = false;
				renderFeed();
				return;
			}
			const args = Object.assign( { limit: 40 }, v.args );
			if ( append && ui.list.length ) {
				args.before = ui.list[ ui.list.length - 1 ].activity;
			}
			const items = await api( 'threads?' + query( args ) );
			ui.list = append ? ui.list.concat( items ) : items;
			ui.more = items.length === 40;
			ui.loading = false;
			renderFeed();
		};

		/* composer for new threads */
		const renderComposer = () => {
			const v = viewInfo();
			if ( v.notifications ) {
				slot.replaceChildren();
				return;
			}
			const startable = boot.channels.filter( ( c ) => 'all' === c.level || ( 'own-posts' === c.level && v.target ) || 'own-posts' === c.level );
			const chSel = v.channel ? '' : '<select name="channel" aria-label="' + esc( t( 'Channel' ) ) + '">' + startable.map( ( c ) => '<option value="' + esc( c.id ) + '">#' + esc( c.name || c.id ) + '</option>' ).join( '' ) + '</select>';
			const guest = 'guest' === boot.me.kind;
			if ( ! startable.length || ( guest && ! boot.targets.length && ! v.target ) ) {
				slot.innerHTML = '<p class="ff-note">' + esc( guest ? t( 'You can start conversations about your own posts. As soon as you have one, you can start here.' ) : t( 'You cannot start threads here.' ) ) + '</p>';
				return;
			}
			const c = composer( {
				key: 'new:' + ui.view,
				className: 'ff-new-thread',
				autoGrow: true,
				placeholder: v.channel ? t( 'New thread in #%s … (@name, / for cards)', chName( v.channel ) ) : t( 'New thread … (@name, / for cards)' ),
				submitLabel: t( 'Start thread' ),
				extra: chSel + '<label class="ff-check"><input type="checkbox" name="task"> ' + esc( t( 'Task' ) ) + '</label>'
					+ '<span class="ff-task-new"><label class="ff-check">' + esc( t( 'Due' ) ) + ' <input type="date" name="due"></label><input type="hidden" name="assignees" value=""><button type="button" class="ff-link" data-ff="new-assign">' + esc( t( 'Assign' ) ) + '</button></span>',
				onSubmit: async ( payload, form ) => {
					const tg = v.target ? v.target : form.elements.target && form.elements.target.value ? { type: form.elements.target.value.split( ':' )[ 0 ], id: +form.elements.target.value.split( ':' )[ 1 ] } : null;
					const assignees = form.elements.assignees.value ? form.elements.assignees.value.split( ',' ).map( Number ) : [];
					const task = form.elements.task.checked;
					const th = await api( 'threads', { method: 'POST', body: Object.assign( payload, { channel: v.channel || form.elements.channel.value, target: tg, task, due: task ? form.elements.due.value : '', assignees: task ? assignees : [] } ) } );
					form.elements.task.checked = false;
					form.elements.due.value = '';
					form.elements.assignees.value = '';
					$( '[data-ff="new-assign"]', form ).textContent = t( 'Assign' );
					await refresh();
					openThread( th.id, th );
				},
			} );
			if ( ! v.target ) {
				$( '.ff-left', c ).insertBefore( targetPicker( guest ), $( '.ff-check', c ) );
			}
			$( '[data-ff="new-assign"]', c ).addEventListener( 'click', ( e ) => {
				const hidden = c.elements.assignees;
				const btn = e.currentTarget;
				peoplePicker( btn, hidden.value ? hidden.value.split( ',' ).map( Number ) : [], ( ids ) => {
					hidden.value = ids.join( ',' );
					btn.textContent = ids.length ? t( 'Responsible: %s', names( ids ) ) : t( 'Assign' );
					c.elements.task.checked = c.elements.task.checked || ids.length > 0;
				} );
			} );
			slot.replaceChildren( c );
		};

		/* thread pane */
		const remindSelect = ( th ) => {
			const h = String( boot.me.prefs.hour ?? 7 ) + ':00';
			const set = th.remind ? new Date( th.remind ) : null;
			const opts = ( set
				? [ [ '', t( 'Reminder: %s', set.toLocaleString( locale(), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } ) ) ], [ 'none', t( 'No reminder' ) ] ]
				: [ [ '', t( 'Remind me …' ) ] ] ).concat( [ [ 'hour', t( 'In an hour' ) ], [ 'tomorrow', t( 'Tomorrow, %s', h ) ], [ 'monday', t( 'Next Monday, %s', h ) ] ] );
			return '<select class="ff-remind' + ( set ? ' is-set' : '' ) + '" data-ff="remind" aria-label="' + esc( t( 'Remind me about this thread' ) ) + '">'
				+ opts.map( ( [ v, l ] ) => '<option value="' + v + '">' + esc( l ) + '</option>' ).join( '' ) + '</select>';
		};
		const renderPane = ( th ) => {
			pane.hidden = false;
			app.classList.add( 'has-pane' );
			const follow = th.follow || 0;
			pane.innerHTML = resizer( 'pane', t( 'Drag to make the thread wider or narrower' ) ) + '<header class="ff-pane-head"><button type="button" class="ff-back" data-ff="close">‹ ' + esc( t( 'Back' ) ) + '</button><h2>' + esc( t( 'Thread' ) ) + ' <small>#' + esc( chName( th.channel ) ) + '</small></h2>'
				+ '<span class="ff-follow"><button type="button" class="ff-link" data-ff="follow" data-mode="' + ( 1 === follow ? 'default' : 'follow' ) + '" aria-pressed="' + ( 1 === follow ) + '" title="' + esc( t( 'Get notified about every reply' ) ) + '">' + esc( 1 === follow ? t( 'Following' ) : t( 'Follow' ) ) + '</button>'
				+ '<button type="button" class="ff-link" data-ff="follow" data-mode="' + ( -1 === follow ? 'default' : 'mute' ) + '" aria-pressed="' + ( -1 === follow ) + '" title="' + esc( t( 'Only mentions and tasks' ) ) + '">' + esc( -1 === follow ? t( 'Muted' ) : t( 'Mute' ) ) + '</button>' + remindSelect( th ) + '</span>'
				+ '<button type="button" class="ff-x" data-ff="close" aria-label="' + esc( t( 'Close thread' ) ) + '">×</button></header>'
				+ '<div class="ff-pane-body"><div class="ff-root-msg">' + ava( th.author ) + '<div class="ff-msg-body">'
				+ '<p class="ff-who">' + nameHtml( th.author ) + ' <span>' + esc( when( th.created ) ) + '</span></p>'
				+ ( th.title ? '<h3 class="ff-title">' + esc( th.title ) + '</h3>' : '' ) + ( th.body ? '<div class="ff-text">' + rich( th.body ) + '</div>' : '' )
				+ cards( th.attachments ) + targetChip( th )
				+ taskControls( th )
				+ '<div class="ff-root-row">' + statusPill( th ) + audiencePill( th ) + doneBox( th ) + ( null === th.status ? '<button type="button" class="ff-link" data-ff="make-task">' + esc( t( 'Make it a task' ) ) + '</button>' : '' ) + ( mine( th.author ) && th.body ? '<button type="button" class="ff-link" data-ff="thread-edit" data-tid="' + th.id + '">' + esc( t( 'Edit' ) ) + '</button>' : '' ) + '</div>'
				+ '</div></div><div class="ff-msgs">' + messagesHtml( th ) + '</div><div class="ff-reply-slot"></div></div>';
			const c = composer( {
				key: 'thread:' + th.id,
				label: t( 'Reply' ),
				submitLabel: t( 'Reply' ),
				onSubmit: async ( payload ) => {
					const fresh = await api( 'threads/' + th.id + '/messages', { method: 'POST', body: payload } );
					ui.current = fresh;
					$( '.ff-msgs', pane ).innerHTML = messagesHtml( fresh );
					const body = $( '.ff-pane-body', pane );
					body.scrollTop = body.scrollHeight;
					refresh();
				},
			} );
			$( '.ff-reply-slot', pane ).replaceChildren( c );
			const body = $( '.ff-pane-body', pane );
			const first = $( '.ff-msg.is-unread', pane );
			body.scrollTop = first ? first.offsetTop - 80 : body.scrollHeight;
			return c;
		};
		const openThread = async ( id, preset ) => {
			ui.thread = id;
			syncUrl();
			feed.querySelectorAll( '.ff-post' ).forEach( ( p ) => p.classList.toggle( 'is-current', +p.dataset.id === id ) );
			try {
				const th = preset && preset.messages ? preset : await api( 'threads/' + id );
				ui.current = th;
				const c = renderPane( th );
				c.focusText();
				refresh();
			} catch ( e ) {
				pane.hidden = false;
				pane.innerHTML = '<p class="ff-empty">' + esc( e.message ) + '</p>';
			}
		};
		const closeThread = () => {
			ui.thread = null;
			ui.current = null;
			pane.hidden = true;
			app.classList.remove( 'has-pane' );
			syncUrl();
			renderFeed();
		};

		/* refresh: counts, list (and open thread if nobody is typing there) */
		const refresh = async () => {
			try {
				const before = boot.counts.notifications || 0;
				boot.counts = await api( 'counts' );
				if ( boot.counts.notifications > before && document.hidden && browserNotesOn() && 'on' !== ui.pushState ) {
					showBrowserNotes();
				}
				if ( ! ui.settings ) {
					renderNav(); // Not while the settings are open: that would steal the focus.
				}
				if ( ! head.contains( document.activeElement ) ) {
					renderHead();
				}
				if ( boot.counts.notifications > before ) {
					announce( t( 'Notifications (%s new)', boot.counts.notifications ) );
				}
				if ( ! document.hidden ) {
					await loadList();
				}
				updateBadge( boot.counts.total );
				offline( false );
			} catch ( e ) {
				// Network gone (not logged out – that has its own message): say so, keep trying.
				if ( ! /session|Anmeldung/i.test( e.message ) ) {
					offline( true );
				}
			}
		};
		// Screen readers hear about new notifications without moving the focus.
		const live = document.createElement( 'div' );
		live.className = 'screen-reader-text';
		live.setAttribute( 'aria-live', 'polite' );
		root.append( live );
		const announce = ( text ) => {
			live.textContent = '';
			setTimeout( () => {
				live.textContent = text;
			}, 50 );
		};
		const offline = ( on ) => {
			let bar = $( '.ff-offline', root );
			if ( on && ! bar ) {
				bar = document.createElement( 'div' );
				bar.className = 'ff-offline';
				bar.setAttribute( 'role', 'status' );
				bar.textContent = t( 'No connection – trying again …' );
				app.prepend( bar );
			} else if ( ! on && bar ) {
				bar.remove();
			}
		};
		window.addEventListener( 'online', () => refresh() );
		window.addEventListener( 'offline', () => offline( true ) );
		// New notifications as system notifications while the window is in the background (only when
		// this browser gets no push, which would show them already).
		let shownNote = 0;
		const showBrowserNotes = async () => {
			const items = await api( 'notifications?limit=5' );
			if ( paused() ) {
				shownNote = Math.max( shownNote, ...items.map( ( n ) => n.id ) );
				return;
			}
			items.filter( ( n ) => ! n.read && n.id > shownNote ).reverse().forEach( ( n ) => {
				const note = new Notification( n.headline, { body: n.topic, tag: 'flurfunk-' + n.thread, icon: boot.look.logo || undefined } );
				note.onclick = () => {
					window.focus();
					openThread( n.thread );
					note.close();
				};
			} );
			shownNote = Math.max( shownNote, ...items.map( ( n ) => n.id ) );
		};
		// What can change while a thread is open: new, edited or deleted messages, the status, the
		// thread text, refreshed cards.
		const signature = ( th ) => [ th.status, th.body, th.title, JSON.stringify( th.attachments ) ].concat( th.messages.map( ( m ) => m.id + ':' + m.type + ':' + ( m.edited || '' ) + ':' + JSON.stringify( m.attachments || [] ) ) ).join( '|' );
		const pollPane = async () => {
			const active = document.activeElement;
			const busy = pane.contains( active ) && ( ( 'TEXTAREA' === active.tagName && active.value ) || active.closest( '.ff-edit, .ff-pop' ) ) || $( '.ff-edit', pane );
			if ( ! ui.thread || busy ) {
				return;
			}
			const th = await api( 'threads/' + ui.thread );
			if ( ui.current && signature( th ) !== signature( ui.current ) ) {
				const body = $( '.ff-pane-body', pane );
				const atEnd = body && body.scrollHeight - body.scrollTop - body.clientHeight < 40;
				const top = body ? body.scrollTop : 0;
				ui.current = th;
				renderPane( th );
				const nb = $( '.ff-pane-body', pane );
				nb.scrollTop = atEnd ? nb.scrollHeight : top;
			}
		};
		const setView = ( view ) => {
			ui.view = view;
			ui.list = [];
			ui.loading = true;
			closeThread();
			renderNav();
			renderHead();
			renderFeed();
			renderComposer();
			loadList();
		};

		/* events */
		root.addEventListener( 'click', async ( e ) => {
			const el = e.target.closest( '[data-ff]' );
			if ( ! el || ! root.contains( el ) || el.closest( '.ff-compose' ) ) {
				return;
			}
			if ( await messageAction( el, ui.current ? [ ui.current ] : [], ( th ) => {
				ui.current = th;
				renderPane( th );
				refresh();
			} ) ) {
				return;
			}
			const act = el.dataset.ff;
			if ( 'nav' === act ) {
				setView( el.dataset.view );
			} else if ( 'open' === act ) {
				if ( e.target.closest( 'a' ) ) {
					return;
				}
				openThread( +el.closest( '[data-id]' ).dataset.id );
			} else if ( 'target' === act ) {
				e.preventDefault();
				e.stopPropagation();
				setView( 'target:' + el.dataset.type + ':' + el.dataset.id );
			} else if ( 'close' === act ) {
				closeThread();
			} else if ( 'more' === act ) {
				loadList( true );
			} else if ( 'push-toggle' === act ) {
				el.disabled = true;
				try {
					if ( 'on' === ui.pushState ) {
						await pushOff();
					} else {
						await pushOn();
					}
				} catch ( x ) {
					ui.pushError = x.message;
				}
				ui.pushState = await pushState();
				renderNav();
				ui.pushError = '';
			} else if ( 'push-test' === act ) {
				const r = await api( 'me/push/test', { method: 'POST', body: {} } );
				el.textContent = r.sent ? t( 'Sent – it should appear shortly' ) : t( 'Could not send' );
			} else if ( 'settings' === act ) {
				if ( ! ui.settings ) {
					ui.pushState = await pushState().catch( () => 'unsupported' );
				}
				ui.settings = ! ui.settings;
				renderNav();
				$( '.ff-me-tile', nav ).focus();
			} else if ( 'pref' === act && el.dataset.val ) {
				boot.me.prefs = await api( 'me/prefs', { method: 'POST', body: { [ el.dataset.pref ]: el.dataset.val } } );
				if ( 'avatar' === el.dataset.pref ) {
					// Everyone's list of people carries the picture; reload it for the new choice.
					await loadBoot( true );
					applyLook( root );
					renderNav();
					renderFeed();
					if ( ui.current ) {
						renderPane( ui.current );
					}
					return;
				}
				applyLook( root );
				renderNav();
			} else if ( 'follow' === act && ui.current ) {
				const r = await api( 'threads/' + ui.current.id + '/follow', { method: 'POST', body: { mode: el.dataset.mode } } );
				ui.current.follow = r.follow;
				renderPane( ui.current );
			} else if ( 'open-note' === act ) {
				const nid = +el.dataset.nid;
				api( 'notifications', { method: 'POST', body: { ids: [ nid ] } } ).then( ( c ) => {
					boot.counts = c;
					renderNav();
					updateBadge( c.total );
				} );
				const n = ( ui.notes || [] ).find( ( x ) => x.id === nid );
				if ( n ) {
					n.read = true;
				}
				openThread( +el.dataset.id );
				renderFeed();
			} else if ( 'notes-read' === act ) {
				boot.counts = await api( 'notifications', { method: 'POST', body: { all: true } } );
				renderNav();
				renderHead();
				await loadList();
			} else if ( 'read-all' === act ) {
				const v = viewInfo();
				const r = await api( 'read', { method: 'POST', body: v.channel ? { channel: v.channel } : {} } );
				boot.counts = r;
				renderNav();
				renderHead();
				await loadList();
				updateBadge( boot.counts.total );
			} else if ( 'make-task' === act ) {
				ui.current = await api( 'threads/' + ui.thread + '/status', { method: 'POST', body: { status: 'open' } } );
				renderPane( ui.current );
				refresh();
			}
		} );
		// Escape closes the open thread even when nothing inside the app has focus.
		document.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' !== e.key || ! ui.thread || e.defaultPrevented || ui.settings || overlayOpen() || root.contains( e.target ) || ( e.target.closest && e.target.closest( 'input, textarea, select, [contenteditable]' ) ) ) {
				return;
			}
			closeThread();
		} );
		head.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const q = e.target.elements.q.value.trim();
			setView( q ? 'search:' + q : 'all' );
			const box = $( '.ff-search input', head );
			if ( box ) {
				box.focus();
				box.setSelectionRange( box.value.length, box.value.length );
			}
		} );
		root.addEventListener( 'keydown', ( e ) => {
			const post = e.target.closest && e.target.closest( '.ff-post' );
			if ( post && e.target === post && ( 'Enter' === e.key || ' ' === e.key ) ) {
				e.preventDefault();
				post.click();
			}
			if ( 'Escape' === e.key && ui.settings ) {
				ui.settings = false;
				renderNav();
				$( '.ff-me-tile', nav ).focus();
			} else if ( 'Escape' === e.key && ui.thread && ! e.defaultPrevented && ! overlayOpen() && ! e.target.closest( '.ff-compose, .ff-edit, .ff-pop, .ff-menu' ) ) {
				const id = ui.thread;
				closeThread();
				const post = feed.querySelector( '.ff-post[data-id="' + id + '"]' );
				if ( post ) {
					post.focus();
				}
			}
		} );
		root.addEventListener( 'change', async ( e ) => {
			const el = e.target;
			if ( await dueChange( el, ( th ) => {
				ui.current = th;
				renderPane( th );
				refresh();
			} ) ) {
				return;
			}
			if ( 'done' === el.dataset.ff && ui.thread ) {
				ui.current = await api( 'threads/' + ui.thread + '/status', { method: 'POST', body: { status: el.checked ? 'done' : 'open' } } );
				renderPane( ui.current );
				refresh();
			} else if ( 'remind' === el.dataset.ff && ui.current ) {
				if ( el.value ) {
					const r = await api( 'threads/' + ui.current.id + '/remind', { method: 'POST', body: { when: el.value } } );
					ui.current.remind = r.remind;
					renderPane( ui.current );
					announce( r.remind ? t( 'Reminder set' ) : t( 'Reminder removed' ) );
				}
			} else if ( 'channel-notify' === el.dataset.ff ) {
				boot.me.prefs = await api( 'me/prefs', { method: 'POST', body: { channel: { slug: el.dataset.slug, level: el.value } } } );
				announce( t( 'Saved' ) );
			} else if ( 'pref' === el.dataset.ff ) {
				boot.me.prefs = await api( 'me/prefs', { method: 'POST', body: { [ el.dataset.pref ]: 'checkbox' === el.type ? el.checked : el.value } } );
				updateBadge( boot.counts.total );
			} else if ( 'quiet-on' === el.dataset.ff || 'quiet' === el.dataset.ff ) {
				const box = el.closest( '.ff-settings' );
				const on = $( '[data-ff="quiet-on"]', box ).checked;
				box.querySelectorAll( '[data-ff="quiet"]' ).forEach( ( i ) => {
					i.disabled = ! on;
				} );
				const value = on ? $( '[data-part="from"]', box ).value + '-' + $( '[data-part="to"]', box ).value : '';
				boot.me.prefs = await api( 'me/prefs', { method: 'POST', body: { quiet: value } } );
			} else if ( 'browser-notes' === el.dataset.ff ) {
				let on = el.checked;
				if ( on && 'granted' !== Notification.permission ) {
					on = 'granted' === ( await Notification.requestPermission() );
					el.checked = on;
				}
				try {
					window.localStorage.setItem( browserNotesKey(), on ? '1' : '0' );
				} catch ( x ) {}
			}
		} );
		document.addEventListener( 'click', ( e ) => {
			// Clicks inside the sidebar redraw it, so the clicked element may already be gone.
			if ( ui.settings && e.target.isConnected && ! e.target.closest( '.ff-me' ) ) {
				ui.settings = false;
				renderNav();
			}
		} );

		renderNav();
		renderHead();
		renderFeed();
		renderComposer();
		await loadList();
		updateBadge( boot.counts.total );
		pushState().then( ( st ) => {
			ui.pushState = st;
		} ).catch( () => {} );
		pushResync();
		if ( pushSupported() ) {
			// A click on a push notification focuses this window: open the thread.
			navigator.serviceWorker.addEventListener( 'message', ( e ) => {
				const m = e.data && 'open' === e.data.flurfunk && String( e.data.url || '' ).match( /thread(?::|%3A)(\d+)/ );
				if ( m ) {
					openThread( +m[ 1 ] );
				}
			} );
		}
		if ( ui.thread ) {
			openThread( ui.thread );
		}
		// Every 30 s while visible; in the background only for system notifications, every 60 s.
		let ticks = 0;
		setInterval( () => {
			ticks++;
			if ( 'visible' === document.visibilityState ) {
				refresh();
				pollPane().catch( () => {} );
			} else if ( browserNotesOn() && 'on' !== ui.pushState && 0 === ticks % 2 ) {
				refresh();
			}
		}, 30000 );
		// Back to the tab: show what happened right away.
		document.addEventListener( 'visibilitychange', () => {
			if ( 'visible' === document.visibilityState ) {
				refresh();
				pollPane().catch( () => {} );
			}
		} );
		// Ctrl/Cmd+K: search.
		document.addEventListener( 'keydown', ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && 'k' === e.key.toLowerCase() ) {
				const box = $( '.ff-search input', head );
				if ( box ) {
					e.preventDefault();
					box.focus();
					box.select();
				}
			}
		} );
	};

	/* Badge on the admin menu item (OpenStation's dock reads the same markup). */
	const baseTitle = String( document.title || '' ).replace( /^\(\d+\) /, '' );
	const updateBadge = ( n ) => {
		const show = boot && boot.me.prefs.badge && n > 0;
		// The tab or window title carries the number too, e.g. "(3) Flurfunk".
		if ( document.getElementById( 'flurfunk-app' ) ) {
			document.title = ( show ? '(' + n + ') ' : '' ) + baseTitle;
		}
		document.querySelectorAll( '#toplevel_page_flurfunk .wp-menu-name, #adminmenu a[href$="page=flurfunk"] .wp-menu-name' ).forEach( ( el ) => {
			let b = el.querySelector( '.awaiting-mod' );
			if ( ! show ) {
				if ( b ) {
					b.remove();
				}
				return;
			}
			if ( ! b ) {
				b = document.createElement( 'span' );
				el.append( ' ', b );
			}
			b.className = 'awaiting-mod count-' + n;
			b.innerHTML = '<span class="pending-count">' + n + '</span>';
		} );
	};

	/* ================================================================== conversations about one target */

	const mountTarget = async ( root, target ) => {
		await loadBoot();
		applyLook( root, true );
		root.classList.add( 'ff-embed' );
		const open = new Set();
		let list = [];
		let shown = [];
		const startable = () => boot.channels.filter( ( c ) => 'all' === c.level || 'own-posts' === c.level );
		const draw = async () => {
			list = await api( 'threads?' + query( { target: target.type + ':' + target.id, limit: 100 } ) );
			const full = {};
			await Promise.all( [ ...open ].map( ( id ) => api( 'threads/' + id ).then( ( th ) => {
				full[ id ] = th;
			} ).catch( () => {} ) ) );
			shown = Object.values( full ).concat( list.map( ( th ) => ( { id: th.id, body: th.body, assignees: th.assignees, messages: [] } ) ) );
			root.innerHTML = ( list.length ? '' : '<p class="ff-empty">' + esc( t( 'No conversations about this post yet.' ) ) + '</p>' )
				+ list.map( ( th ) => '<div class="ff-box' + ( 'done' === th.status ? ' is-done' : '' ) + '" data-id="' + th.id + '">'
					+ '<div class="ff-box-head"><h3>' + esc( th.title || person( th.author ).name ) + '</h3>' + doneBox( th, ' data-id="' + th.id + '"' ) + ( mine( th.author ) && th.body ? '<button type="button" class="ff-link" data-ff="thread-edit" data-tid="' + th.id + '">' + esc( t( 'Edit' ) ) + '</button>' : '' ) + '</div>'
					+ ( th.body ? '<div class="ff-text">' + rich( th.body ) + '</div>' : '' ) + cards( th.attachments )
					+ '<p class="ff-meta">' + esc( person( th.author ).name + ' · ' + when( th.created ) + ' · #' + chName( th.channel ) ) + ' ' + audiencePill( th ) + '</p>'
					+ taskControls( th )
					+ summary( th, open.has( th.id ), 'toggle' )
					+ ( full[ th.id ] ? messagesHtml( full[ th.id ] ) + '<div class="ff-reply-slot"></div>' : ( th.replies ? '' : '<div class="ff-reply-slot"></div>' ) )
					+ '</div>' ).join( '' )
				+ '<div class="ff-new-slot"></div>'
				+ ( cfg.appUrl ? '<p class="ff-meta"><a href="' + esc( cfg.appUrl + '&view=target:' + target.type + ':' + target.id ) + '" target="_top">' + esc( t( 'Open in Flurfunk' ) ) + '</a></p>' : '' );
			root.querySelectorAll( '.ff-box' ).forEach( ( box ) => {
				const id = +box.dataset.id;
				const s = $( '.ff-reply-slot', box );
				if ( s ) {
					s.replaceChildren( composer( {
						key: 'thread:' + id,
						placeholder: t( 'Reply … (@name, / for cards)' ),
						submitLabel: t( 'Reply' ),
						onSubmit: async ( payload ) => {
							await api( 'threads/' + id + '/messages', { method: 'POST', body: payload } );
							open.add( id );
							draw();
						},
					} ) );
				}
			} );
			const chans = startable();
			$( '.ff-new-slot', root ).replaceChildren( composer( {
				key: 'new:target:' + target.type + ':' + target.id,
				label: t( 'New conversation about this post' ),
				placeholder: t( 'What is it about? (@name, / for cards)' ),
				submitLabel: t( 'Start' ),
				extra: '<select name="channel" aria-label="' + esc( t( 'Channel' ) ) + '">' + chans.map( ( c ) => '<option value="' + esc( c.id ) + '">#' + esc( c.name || c.id ) + '</option>' ).join( '' ) + '</select><label class="ff-check"><input type="checkbox" name="task" checked> ' + esc( t( 'Task' ) ) + '</label>',
				onSubmit: async ( payload, form ) => {
					const th = await api( 'threads', { method: 'POST', body: Object.assign( payload, { channel: form.elements.channel.value, target, task: form.elements.task.checked } ) } );
					open.add( th.id );
					draw();
				},
			} ) );
		};
		root.addEventListener( 'click', async ( e ) => {
			const act = e.target.closest( '[data-ff]' );
			if ( act && ! act.closest( '.ff-compose' ) && await messageAction( act, shown, ( th ) => {
				open.add( th.id );
				draw();
			} ) ) {
				return;
			}
			const el = e.target.closest( '[data-ff="toggle"]' );
			if ( el && ! el.closest( '.ff-compose' ) ) {
				const id = +el.closest( '[data-id]' ).dataset.id;
				if ( open.has( id ) ) {
					open.delete( id );
				} else {
					open.add( id );
				}
				draw();
			}
			const chip = e.target.closest( '[data-ff="target"]' );
			if ( chip ) {
				e.preventDefault();
			}
		} );
		root.addEventListener( 'change', async ( e ) => {
			if ( await dueChange( e.target, () => draw() ) ) {
				return;
			}
			if ( 'done' === e.target.dataset.ff ) {
				await api( 'threads/' + e.target.dataset.id + '/status', { method: 'POST', body: { status: e.target.checked ? 'done' : 'open' } } );
				draw();
			}
		} );
		root.innerHTML = '<p class="ff-loading">' + esc( t( 'Loading …' ) ) + '</p>';
		await draw();
		return { refresh: draw };
	};

	/* ================================================================== threads next to existing elements */

	/**
	 * Every element with data-flurfunk-ref="key" (optional data-title, data-body, data-task) gets a
	 * thread underneath. The thread is created on the first reply or tick, so nothing needs seeding.
	 */
	const mountRefs = async ( container, opts ) => {
		await loadBoot();
		const els = [ ...container.querySelectorAll( '[data-flurfunk-ref]' ) ];
		if ( ! els.length ) {
			return;
		}
		const open = new Set();
		const byRef = {};
		const fulls = {};
		const load = async () => {
			const list = await api( 'threads?' + query( { ref_prefix: opts.prefix || '', limit: 200, order: 'ref' } ) );
			list.forEach( ( th ) => {
				byRef[ th.ref_key ] = th;
			} );
		};
		const ensure = async ( el ) => {
			const key = el.dataset.flurfunkRef;
			if ( byRef[ key ] ) {
				return byRef[ key ];
			}
			const th = await api( 'threads', { method: 'POST', body: { ref_key: key, channel: el.dataset.channel || opts.channel, title: el.dataset.title || '', body: el.dataset.body || '', task: '1' === el.dataset.task } } );
			byRef[ key ] = th;
			return th;
		};
		const draw = async ( el ) => {
			const key = el.dataset.flurfunkRef;
			let host = el.querySelector( ':scope > .ff-ref' );
			if ( ! host ) {
				host = document.createElement( 'div' );
				host.className = 'ff-ref ff-embed';
				applyLook( host, true );
				el.append( host );
			}
			const th = byRef[ key ];
			const isOpen = open.has( key ) || ! th || ! th.replies;
			const full = th && open.has( key ) ? await api( 'threads/' + th.id ) : null;
			if ( full ) {
				fulls[ key ] = full;
			}
			const task = th ? th.status : ( '1' === el.dataset.task ? 'open' : null );
			host.innerHTML = '<div class="ff-ref-row">' + ( th ? summary( th, isOpen, 'toggle' ) : '' )
				+ ( null !== task ? '<label class="ff-state"><input type="checkbox" data-ff="done"' + ( 'done' === task ? ' checked' : '' ) + '> ' + esc( t( 'Done' ) ) + '</label>' : '' ) + '</div>'
				+ ( full ? messagesHtml( full ) : '' ) + ( isOpen ? '<div class="ff-reply-slot"></div>' : '' );
			el.classList.toggle( 'ff-is-done', 'done' === task );
			const s = $( '.ff-reply-slot', host );
			if ( s ) {
				s.replaceChildren( composer( {
					key: 'ref:' + key,
					label: th && th.replies ? t( 'Reply' ) : t( 'No replies yet' ),
					placeholder: t( 'Reply … (@name, / for cards)' ),
					submitLabel: t( 'Reply' ),
					onSubmit: async ( payload ) => {
						const tth = await ensure( el );
						await api( 'threads/' + tth.id + '/messages', { method: 'POST', body: payload } );
						await load();
						open.add( key );
						draw( el );
					},
				} ) );
			}
		};
		container.addEventListener( 'click', async ( e ) => {
			const act = e.target.closest( '.ff-ref [data-ff]' );
			if ( act && ! act.closest( '.ff-compose' ) && await messageAction( act, Object.values( fulls ), () => {
				const el = act.closest( '[data-flurfunk-ref]' );
				load().then( () => draw( el ) );
			} ) ) {
				return;
			}
			const b = e.target.closest( '.ff-ref [data-ff="toggle"]' );
			if ( b ) {
				const el = b.closest( '[data-flurfunk-ref]' );
				const key = el.dataset.flurfunkRef;
				if ( open.has( key ) ) {
					open.delete( key );
				} else {
					open.add( key );
				}
				draw( el );
			}
		} );
		container.addEventListener( 'change', async ( e ) => {
			if ( 'done' !== e.target.dataset.ff || ! e.target.closest( '.ff-ref' ) ) {
				return;
			}
			const el = e.target.closest( '[data-flurfunk-ref]' );
			const th = await ensure( Object.assign( el, {} ) );
			await api( 'threads/' + th.id + '/status', { method: 'POST', body: { status: e.target.checked ? 'done' : 'open' } } );
			await load();
			draw( el );
			if ( opts.onChange ) {
				opts.onChange();
			}
		} );
		await load();
		await Promise.all( els.map( draw ) );
	};

	/* ================================================================== a channel as a checklist page */

	/**
	 * A channel shown as a numbered list: each thread with its full text, files and cards, a tick
	 * box for tasks and the conversation underneath. Open items first, done ones below. New items
	 * can be added at the end. Used e.g. for a page of open questions.
	 *
	 * @param {Element} root
	 * @param {Object}  opts channel, newLabel (heading of the form), titleLabel.
	 */
	const mountChannel = async ( root, opts ) => {
		await loadBoot();
		applyLook( root, true );
		root.classList.add( 'ff-embed', 'ff-checklist' );
		const open = new Set();
		const answering = new Set();
		let shown = [];
		const item = ( th, n, full ) => '<article class="ff-item' + ( 'done' === th.status ? ' is-done' : '' ) + '" data-id="' + th.id + '" id="ff-item-' + th.id + '">'
			+ '<span class="ff-num" aria-hidden="true">' + ( 'done' === th.status ? '✓' : n ) + '</span><div class="ff-item-body">'
			+ '<div class="ff-box-head"><h3>' + esc( th.title || person( th.author ).name ) + '</h3>' + doneBox( th, ' data-id="' + th.id + '"' )
			+ ( mine( th.author ) && th.body ? '<button type="button" class="ff-link" data-ff="thread-edit" data-tid="' + th.id + '">' + esc( t( 'Edit' ) ) + '</button>' : '' ) + '</div>'
			+ '<div class="ff-msg-body">' + ( th.body ? '<div class="ff-text">' + rich( th.body ) + '</div>' : '' ) + '</div>'
			+ cards( th.attachments ) + targetChip( th )
			+ ( 'done' === th.status ? '' : taskControls( th ) )
			+ '<p class="ff-meta">' + nameHtml( th.author ) + ' · ' + esc( when( th.created ) ) + ' ' + audiencePill( th ) + '</p>'
			+ ( th.replies ? summary( th, open.has( th.id ), 'toggle' ) : '<button type="button" class="ff-link ff-answer" data-ff="answer">' + esc( t( 'Reply' ) ) + '</button>' )
			+ ( full ? messagesHtml( full ) : '' )
			+ ( full || answering.has( th.id ) ? '<div class="ff-reply-slot"></div>' : '' )
			+ '</div></article>';
		const draw = async () => {
			const list = await api( 'threads?' + query( { channel: opts.channel, limit: 200, order: 'ref' } ) );
			const full = {};
			await Promise.all( [ ...open ].map( ( id ) => api( 'threads/' + id ).then( ( th ) => {
				full[ id ] = th;
			} ).catch( () => {} ) ) );
			shown = Object.values( full ).concat( list.map( ( th ) => ( { id: th.id, body: th.body, assignees: th.assignees, messages: [] } ) ) );
			const todo = list.filter( ( th ) => 'done' !== th.status );
			const done = list.filter( ( th ) => 'done' === th.status );
			root.innerHTML = ( list.length ? '' : '<p class="ff-empty">' + esc( t( 'Nothing here yet.' ) ) + '</p>' )
				+ todo.map( ( th, i ) => item( th, i + 1, full[ th.id ] ) ).join( '' )
				+ ( done.length ? '<h2 class="ff-done-h">' + esc( t( 'Done' ) ) + ' <span>' + done.length + '</span></h2>' + done.map( ( th ) => item( th, '', full[ th.id ] ) ).join( '' ) : '' )
				+ '<div class="ff-new-slot"></div>';
			root.querySelectorAll( '.ff-item' ).forEach( ( box ) => {
				const id = +box.dataset.id;
				const slot = $( '.ff-reply-slot', box );
				if ( slot ) {
					const c = composer( {
						key: 'thread:' + id,
						placeholder: t( 'Reply … (@name, / for cards)' ),
						submitLabel: t( 'Reply' ),
						onSubmit: async ( payload ) => {
							await api( 'threads/' + id + '/messages', { method: 'POST', body: payload } );
							open.add( id );
							answering.delete( id );
							await draw();
						},
					} );
					slot.replaceChildren( c );
					if ( answering.has( id ) ) {
						c.focusText();
					}
				}
			} );
			$( '.ff-new-slot', root ).replaceChildren( composer( {
				key: 'new:channel:' + opts.channel,
				label: opts.newLabel || t( 'New item' ),
				titleField: opts.titleLabel || t( 'Title' ),
				placeholder: t( 'What is it about? (@name, / for cards)' ),
				submitLabel: t( 'Add' ),
				extra: '<label class="ff-check"><input type="checkbox" name="task" checked> ' + esc( t( 'Task' ) ) + '</label>',
				onSubmit: async ( payload, form ) => {
					await api( 'threads', { method: 'POST', body: Object.assign( payload, { channel: opts.channel, task: form.elements.task.checked } ) } );
					await draw();
				},
			} ) );
		};
		root.addEventListener( 'click', async ( e ) => {
			const el = e.target.closest( '[data-ff]' );
			if ( ! el || el.closest( '.ff-compose' ) ) {
				return;
			}
			if ( await messageAction( el, shown, ( th ) => {
				open.add( th.id );
				draw();
			} ) ) {
				return;
			}
			const id = +( el.closest( '[data-id]' ) || {} ).dataset?.id;
			if ( 'toggle' === el.dataset.ff ) {
				if ( open.has( id ) ) {
					open.delete( id );
				} else {
					open.add( id );
				}
				draw();
			} else if ( 'answer' === el.dataset.ff ) {
				answering.add( id );
				draw();
			}
		} );
		root.addEventListener( 'change', async ( e ) => {
			if ( await dueChange( e.target, () => draw() ) ) {
				return;
			}
			if ( 'done' === e.target.dataset.ff ) {
				await api( 'threads/' + e.target.dataset.id + '/status', { method: 'POST', body: { status: e.target.checked ? 'done' : 'open' } } );
				draw();
			}
		} );
		root.innerHTML = '<p class="ff-loading">' + esc( t( 'Loading …' ) ) + '</p>';
		await draw();
		const hash = location.hash.match( /^#ff-item-(\d+)$/ );
		if ( hash && document.getElementById( 'ff-item-' + hash[ 1 ] ) ) {
			document.getElementById( 'ff-item-' + hash[ 1 ] ).scrollIntoView();
		}
		return { refresh: draw };
	};

	window.Flurfunk = {
		mountApp,
		mountChannel,
		mountTarget,
		mountRefs,
		registerCard: ( id, fn ) => {
			renderers[ id ] = fn;
		},
		api,
		t,
		format: rich,
	};

	document.addEventListener( 'DOMContentLoaded', () => {
		const app = document.getElementById( 'flurfunk-app' );
		if ( app ) {
			mountApp( app );
		} else if ( cfg.badge !== undefined ) {
			// Elsewhere in wp-admin the menu badge comes from PHP; nothing to do.
		}
	} );
}() );

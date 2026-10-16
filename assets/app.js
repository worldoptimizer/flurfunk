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
		chev: '<svg class="ff-chev" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M3 1.5 7 5l-4 3.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
		gear: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/></svg>',
	};

	const initials = ( name ) => String( name || '?' ).trim().split( /\s+/ ).slice( 0, 2 ).map( ( w ) => w[ 0 ] ).join( '' ).toUpperCase();
	const photo = ( p ) => ( p.avatar ? '<img src="' + esc( p.avatar ) + '" alt="" loading="lazy" onerror="this.remove()">' : '' );
	const ava = ( id, cls ) => {
		const p = person( id );
		return '<span class="ff-ava' + ( 'guest' === p.kind ? ' is-guest' : '' ) + ( boot && id === boot.me.id ? ' is-me' : '' ) + ( cls ? ' ' + cls : '' ) + '" aria-hidden="true">' + esc( initials( p.name ) ) + photo( p ) + '</span>';
	};

	const linkify = ( html ) => html.replace( /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>' );
	const rich = ( text ) => String( text || '' ).split( /(<@\d+>)/ ).map( ( part ) => {
		const m = part.match( /^<@(\d+)>$/ );
		if ( m ) {
			const p = person( +m[ 1 ] );
			return '<span class="ff-mention' + ( boot && +m[ 1 ] === boot.me.id ? ' is-me' : '' ) + '">@' + esc( p.name ) + '</span>';
		}
		return linkify( esc( part ) ).replace( /\n/g, '<br>' );
	} ).join( '' );

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
		return '<div class="ff-card"><span class="ff-card-kind">' + esc( p.label ) + ( p.command ? ' <small>/' + esc( p.command ) + '</small>' : '' ) + '</span>' + inner
			+ ( remove !== undefined ? '<button type="button" class="ff-x ff-card-x" data-ff="card-x" data-i="' + remove + '" aria-label="' + esc( t( 'Remove card' ) ) + '">×</button>' : '' ) + '</div>';
	};
	const cards = ( list ) => ( list && list.length ? '<div class="ff-cards">' + list.map( ( a ) => card( a ) ).join( '' ) + '</div>' : '' );
	const files = ( list ) => ( list || [] ).map( ( f ) => '<a class="ff-file" href="' + esc( cfg.mediaUrl + f.id ) + '" target="_top">' + icon.file + esc( f.name ) + ' <small>· ' + size( f.size ) + '</small></a>' ).join( '' );

	/* ------------------------------------------------------------------ pieces */

	const targetChip = ( th ) => ( th.target && th.target.title
		? '<a class="ff-target" href="' + esc( th.target.url ) + '" target="_top" data-ff="target" data-type="' + esc( th.target.type ) + '" data-id="' + th.target.id + '" title="' + esc( t( 'Conversations about this post' ) ) + '">' + icon.doc + '<span>' + esc( th.target.title ) + '</span></a>'
		: '' );
	const statusPill = ( th ) => ( 'done' === th.status ? '<span class="ff-pill is-done">' + esc( t( 'done' ) ) + '</span>' : 'open' === th.status ? '<span class="ff-pill is-open">' + esc( t( 'open' ) ) + '</span>' : '' );
	const audiencePill = ( th ) => ( boot && 'team' === boot.me.kind && th.audience && th.audience.length ? '<span class="ff-pill is-seen">' + esc( t( '%s can read this', th.audience.join( ', ' ) ) ) + '</span>' : '' );
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

	const mine = ( author ) => !! boot && ( author === boot.me.id || boot.me.manage );
	const actions = ( m ) => ( mine( m.author ) ? '<span class="ff-actions"><button type="button" data-ff="msg-edit" data-mid="' + m.id + '">' + esc( t( 'Edit' ) ) + '</button><button type="button" data-ff="msg-del" data-mid="' + m.id + '">' + esc( t( 'Delete' ) ) + '</button></span>' : '' );
	const messagesHtml = ( th ) => {
		let html = '';
		let prev = null;
		( th.messages || [] ).forEach( ( m ) => {
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
				+ ( cont ? '' : '<p class="ff-who"><b>' + esc( person( m.author ).name ) + '</b> <span>' + esc( person( m.author ).role ) + ' · ' + esc( when( m.created ) ) + '</span></p>' )
				+ ( m.text ? '<div class="ff-text">' + rich( m.text ) + '</div>' : '' )
				+ ( m.edited ? '<small class="ff-edited">' + esc( t( '(edited)' ) ) + '</small>' : '' )
				+ cards( m.attachments ) + files( m.files )
				+ actions( m ) + '</div></li>';
			prev = m;
		} );
		return html ? '<ol class="ff-thread">' + html + '</ol>' : '';
	};

	/* ------------------------------------------------------------------ edit and delete */

	const rawText = ( text ) => String( text || '' ).replace( /<@(\d+)>/g, ( all, id ) => '@' + person( +id ).name );
	const startEdit = ( holder, text, onSave ) => {
		const form = document.createElement( 'form' );
		form.className = 'ff-edit';
		form.innerHTML = '<textarea rows="3" aria-label="' + esc( t( 'Edit' ) ) + '"></textarea><div class="ff-row"><button type="submit" class="ff-send">' + esc( t( 'Save' ) ) + '</button><button type="button" class="ff-link" data-ff="edit-cancel">' + esc( t( 'Cancel' ) ) + '</button></div><p class="ff-error" role="alert" hidden></p>';
		const ta = $( 'textarea', form );
		ta.value = rawText( text );
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
				await onSave( encode( ta.value.trim() ) );
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
		if ( 'thread-edit' === act ) {
			const th = threads.find( ( x ) => x.id === +el.dataset.tid );
			const holder = $( '.ff-text', el.closest( '.ff-msg-body, .ff-box' ) );
			if ( th && holder ) {
				startEdit( holder, th.body, async ( text ) => onUpdate( await api( 'threads/' + th.id + '/edit', { method: 'POST', body: { body: text } } ) ) );
			}
			return true;
		}
		return false;
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
	const composer = ( o ) => {
		const el = document.createElement( 'form' );
		el.className = 'ff-compose' + ( o.className ? ' ' + o.className : '' );
		const state = { files: [], atts: [], busy: false };
		const id = 'ff-ta-' + o.key.replace( /[^a-z0-9_-]/gi, '-' );
		el.innerHTML = ( o.label ? '<label class="ff-label" for="' + id + '">' + esc( o.label ) + '</label>' : '' )
			+ '<textarea id="' + id + '" rows="' + ( o.autoGrow ? 1 : 2 ) + '" placeholder="' + esc( o.placeholder || t( 'Write … (@name, / for cards)' ) ) + '"' + ( o.label ? '' : ' aria-label="' + esc( o.placeholder || t( 'Message' ) ) + '"' ) + '></textarea>'
			+ '<div class="ff-menu-slot"></div><div class="ff-pending"></div>'
			+ '<div class="ff-row"><div class="ff-left">'
			+ ( boot.me.upload ? '<label class="ff-link ff-upload">' + icon.clip + '<span>' + esc( t( 'Attach file' ) ) + '</span><input type="file" multiple hidden></label>' : '' )
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
				+ state.files.map( ( f, i ) => '<span class="ff-file is-pending">' + icon.file + esc( f.name ) + ( f.uploading ? ' <small>' + esc( t( 'uploading …' ) ) + '</small>' : '' ) + '<button type="button" class="ff-x" data-ff="file-x" data-i="' + i + '" aria-label="' + esc( t( 'Remove file' ) ) + '">×</button></span>' ).join( '' );
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
			}
		} );
		const upload = $( 'input[type=file]', el );
		if ( upload ) {
			upload.addEventListener( 'change', () => {
				[ ...upload.files ].forEach( ( f ) => {
					const entry = { name: f.name, size: f.size, uploading: true };
					state.files.push( entry );
					showPending();
					fetch( cfg.mediaEndpoint, {
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
			if ( ! text && ! state.files.length && ! state.atts.length && ! o.allowEmpty ) {
				ta.focus();
				return;
			}
			state.busy = true;
			err.hidden = true;
			el.classList.add( 'is-busy' );
			Promise.resolve( o.onSubmit( {
				body: text,
				files: state.files.map( ( f ) => f.id ),
				attachments: state.atts.map( ( a ) => ( { provider: a.provider, ref: a.ref } ) ),
			}, el ) ).then( () => {
				ta.value = '';
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
			return { title: t( 'All threads' ), sub: t( 'Latest activity first' ), args: { view: 'all' } };
		};

		const syncUrl = () => {
			const p = new URLSearchParams( location.search );
			p.set( 'view', ui.thread ? 'thread:' + ui.thread : ui.view );
			history.replaceState( null, '', location.pathname + '?' + p.toString() );
		};

		/* nav */
		const renderNav = () => {
			const c = boot.counts;
			const btn = ( view, glyph, label, n, title ) => '<button type="button" data-ff="nav" data-view="' + esc( view ) + '"' + ( ui.view === view && ! ui.threadOnly ? ' aria-current="true"' : '' ) + ( n ? ' class="is-unread"' : '' ) + ( title ? ' title="' + esc( title ) + '"' : '' ) + '><span class="ff-glyph" aria-hidden="true">' + glyph + '</span><span class="ff-lbl">' + esc( label ) + '</span>' + ( n ? '<b>' + n + '</b>' : '' ) + '</button>';
			const targets = boot.recent.filter( ( x ) => x.title );
			nav.innerHTML = '<div class="ff-ws">' + ( boot.look.logo ? '<img src="' + esc( boot.look.logo ) + '" alt="">' : '' ) + '<span>' + esc( boot.look.name ) + '</span></div>'
				+ '<div class="ff-nav-list">'
				+ btn( 'all', '≡', t( 'All threads' ), 0 )
				+ btn( 'mentions', '@', t( 'Mentions' ), c.mentions )
				+ btn( 'tasks', '☐', t( 'Open tasks' ), 0 ) + ( c.tasks ? '' : '' )
				+ '<h2 class="ff-nav-h">' + esc( t( 'Channels' ) ) + '</h2>'
				+ boot.channels.map( ( ch ) => btn( 'channel:' + ch.id, '#', ch.name || ch.id, c.channels[ ch.id ] || 0, ch.topic ) ).join( '' )
				+ ( targets.length ? '<h2 class="ff-nav-h">' + esc( 'guest' === boot.me.kind ? t( 'My posts' ) : targets.every( ( x ) => 'post' === x.type ) ? t( 'Posts' ) : t( 'Linked' ) ) + '</h2>' + targets.map( ( x ) => btn( 'target:' + x.type + ':' + x.id, '▤', x.title, c.targets[ x.type + ':' + x.id ] || 0, x.title ) ).join( '' ) : '' )
				+ '</div>'
				+ '<div class="ff-me">' + ( ui.settings ? settingsHtml() : '' )
				+ '<button type="button" class="ff-me-tile" data-ff="settings" aria-expanded="' + ui.settings + '"><span class="ff-sq" aria-hidden="true">' + esc( initials( boot.me.name ) ) + photo( person( boot.me.id ) ) + '</span><span class="ff-me-name"><strong>' + esc( boot.me.name ) + '</strong><small>' + esc( boot.me.role ) + '</small></span>' + icon.gear + '<span class="screen-reader-text">' + esc( t( 'My settings' ) ) + '</span></button></div>';
		};
		const settingsHtml = () => {
			const p = boot.me.prefs;
			const seg = [ [ 'light', t( 'Light' ) ], [ 'dark', t( 'Dark' ) ], [ 'system', t( 'Automatic' ) ] ];
			const pic = [ [ 'photo', t( 'Photo' ) ], [ 'initials', t( 'Initials' ) ] ];
			const mail = [ [ 'mentions', t( 'Mentions and replies in my threads' ) ], [ 'all', t( 'Every new message I can see' ) ], [ 'none', t( 'Never – I check myself' ) ] ];
			return '<div class="ff-settings" role="group" aria-label="' + esc( t( 'My settings' ) ) + '">'
				+ '<h3>' + esc( t( 'Appearance' ) ) + '</h3><div class="ff-seg">' + seg.map( ( [ v, l ] ) => '<button type="button" data-ff="pref" data-pref="theme" data-val="' + v + '" aria-pressed="' + ( p.theme === v ) + '">' + esc( l ) + '</button>' ).join( '' ) + '</div>'
				+ '<h3>' + esc( t( 'Profile picture' ) ) + '</h3><div class="ff-seg">' + pic.map( ( [ v, l ] ) => '<button type="button" data-ff="pref" data-pref="avatar" data-val="' + v + '" aria-pressed="' + ( p.avatar === v ) + '">' + esc( l ) + '</button>' ).join( '' ) + '</div>'
				+ '<p class="ff-meta">' + esc( t( 'Your picture from your profile or Gravatar. Without one, your initials show.' ) ) + '</p>'
				+ '<h3>' + esc( t( 'Email me' ) ) + '</h3>' + mail.map( ( [ v, l ] ) => '<label><input type="radio" name="ff-mail" data-ff="pref" data-pref="mail" value="' + v + '"' + ( p.mail === v ? ' checked' : '' ) + '> ' + esc( l ) + '</label>' ).join( '' )
				+ '<h3>' + esc( t( 'Badge' ) ) + '</h3><label><input type="checkbox" data-ff="pref" data-pref="badge"' + ( p.badge ? ' checked' : '' ) + '> ' + esc( t( 'Show the unread number on the menu icon' ) ) + '</label>'
				+ ( boot.settings ? '<a class="ff-link" href="' + esc( boot.settings ) + '" target="_top">' + esc( t( 'Flurfunk settings for this site' ) ) + '</a>' : '' )
				+ '</div>';
		};

		/* feed */
		const renderHead = () => {
			const v = viewInfo();
			head.innerHTML = '<h1>' + esc( v.title ) + '</h1><p>' + esc( v.sub ) + ( v.url ? ' · <a href="' + esc( v.url ) + '" target="_top">' + esc( t( 'Open post' ) ) + '</a>' : '' ) + '</p>';
		};
		const postHtml = ( th ) => '<article class="ff-post' + ( ui.thread === th.id ? ' is-current' : '' ) + ( th.unread ? ' is-unread' : '' ) + '" data-ff="open" data-id="' + th.id + '" tabindex="0" aria-label="' + esc( ( th.title || th.body || '' ).slice( 0, 80 ) ) + '">'
			+ ava( th.author ) + '<div class="ff-post-body">'
			+ '<p class="ff-who"><b>' + esc( person( th.author ).name ) + '</b> <span>' + esc( when( th.created ) ) + ( viewInfo().channel ? '' : ' · #' + esc( chName( th.channel ) ) ) + '</span> ' + statusPill( th ) + ' ' + audiencePill( th ) + '</p>'
			+ ( th.title ? '<h3 class="ff-title">' + esc( th.title ) + '</h3>' : '' )
			+ ( th.body ? '<div class="ff-text ff-clamp">' + rich( th.body ) + '</div>' : '' )
			+ cards( th.attachments ) + ( viewInfo().target ? '' : targetChip( th ) )
			+ summary( th, false, 'open' ) + '</div></article>';
		const renderFeed = () => {
			feed.innerHTML = ui.list.length
				? ui.list.map( postHtml ).join( '' ) + ( ui.more ? '<button type="button" class="ff-more" data-ff="more">' + esc( t( 'Load older threads' ) ) + '</button>' : '' )
				: '<p class="ff-empty">' + esc( ui.loading ? t( 'Loading …' ) : t( 'Nothing here yet. Start a thread below.' ) ) + '</p>';
		};
		const loadList = async ( append ) => {
			const v = viewInfo();
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
				extra: chSel + '<label class="ff-check"><input type="checkbox" name="task"> ' + esc( t( 'Task' ) ) + '</label>',
				onSubmit: async ( payload, form ) => {
					const tg = v.target ? v.target : form.elements.target && form.elements.target.value ? { type: form.elements.target.value.split( ':' )[ 0 ], id: +form.elements.target.value.split( ':' )[ 1 ] } : null;
					const th = await api( 'threads', { method: 'POST', body: Object.assign( payload, { channel: v.channel || form.elements.channel.value, target: tg, task: form.elements.task.checked } ) } );
					form.elements.task.checked = false;
					await refresh();
					openThread( th.id, th );
				},
			} );
			if ( ! v.target ) {
				$( '.ff-left', c ).insertBefore( targetPicker( guest ), $( '.ff-check', c ) );
			}
			slot.replaceChildren( c );
		};

		/* thread pane */
		const renderPane = ( th ) => {
			pane.hidden = false;
			app.classList.add( 'has-pane' );
			pane.innerHTML = '<header class="ff-pane-head"><button type="button" class="ff-back" data-ff="close">‹ ' + esc( t( 'Back' ) ) + '</button><h2>' + esc( t( 'Thread' ) ) + ' <small>#' + esc( chName( th.channel ) ) + '</small></h2><button type="button" class="ff-x" data-ff="close" aria-label="' + esc( t( 'Close thread' ) ) + '">×</button></header>'
				+ '<div class="ff-pane-body"><div class="ff-root-msg">' + ava( th.author ) + '<div class="ff-msg-body">'
				+ '<p class="ff-who"><b>' + esc( person( th.author ).name ) + '</b> <span>' + esc( when( th.created ) ) + '</span></p>'
				+ ( th.title ? '<h3 class="ff-title">' + esc( th.title ) + '</h3>' : '' ) + ( th.body ? '<div class="ff-text">' + rich( th.body ) + '</div>' : '' )
				+ cards( th.attachments ) + targetChip( th )
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
				boot.counts = await api( 'counts' );
				if ( ! ui.settings ) {
					renderNav(); // Not while the settings are open: that would steal the focus.
				}
				await loadList();
				updateBadge( boot.counts.total );
			} catch ( e ) {}
		};
		const pollPane = async () => {
			if ( ! ui.thread || pane.contains( document.activeElement ) && document.activeElement.tagName === 'TEXTAREA' && document.activeElement.value ) {
				return;
			}
			const th = await api( 'threads/' + ui.thread );
			const last = ( a ) => ( a.messages.length ? a.messages[ a.messages.length - 1 ].id : 0 );
			if ( ui.current && last( th ) !== last( ui.current ) ) {
				ui.current = th;
				$( '.ff-msgs', pane ).innerHTML = messagesHtml( th );
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
			} else if ( 'settings' === act ) {
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
			} else if ( 'make-task' === act ) {
				ui.current = await api( 'threads/' + ui.thread + '/status', { method: 'POST', body: { status: 'open' } } );
				renderPane( ui.current );
				refresh();
			}
		} );
		root.addEventListener( 'keydown', ( e ) => {
			const post = e.target.closest && e.target.closest( '.ff-post' );
			if ( post && e.target === post && ( 'Enter' === e.key || ' ' === e.key ) ) {
				e.preventDefault();
				openThread( +post.dataset.id );
			}
			if ( 'Escape' === e.key && ui.settings ) {
				ui.settings = false;
				renderNav();
				$( '.ff-me-tile', nav ).focus();
			}
		} );
		root.addEventListener( 'change', async ( e ) => {
			const el = e.target;
			if ( 'done' === el.dataset.ff && ui.thread ) {
				ui.current = await api( 'threads/' + ui.thread + '/status', { method: 'POST', body: { status: el.checked ? 'done' : 'open' } } );
				renderPane( ui.current );
				refresh();
			} else if ( 'pref' === el.dataset.ff ) {
				boot.me.prefs = await api( 'me/prefs', { method: 'POST', body: { [ el.dataset.pref ]: 'checkbox' === el.type ? el.checked : el.value } } );
				updateBadge( boot.counts.total );
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
		if ( ui.thread ) {
			openThread( ui.thread );
		}
		setInterval( () => {
			if ( 'visible' === document.visibilityState ) {
				refresh();
				pollPane().catch( () => {} );
			}
		}, 30000 );
	};

	/* Badge on the admin menu item (OpenStation's dock reads the same markup). */
	const updateBadge = ( n ) => {
		const show = boot && boot.me.prefs.badge && n > 0;
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
			shown = Object.values( full ).concat( list.map( ( th ) => ( { id: th.id, body: th.body, messages: [] } ) ) );
			root.innerHTML = ( list.length ? '' : '<p class="ff-empty">' + esc( t( 'No conversations about this post yet.' ) ) + '</p>' )
				+ list.map( ( th ) => '<div class="ff-box' + ( 'done' === th.status ? ' is-done' : '' ) + '" data-id="' + th.id + '">'
					+ '<div class="ff-box-head"><h3>' + esc( th.title || person( th.author ).name ) + '</h3>' + doneBox( th, ' data-id="' + th.id + '"' ) + ( mine( th.author ) && th.body ? '<button type="button" class="ff-link" data-ff="thread-edit" data-tid="' + th.id + '">' + esc( t( 'Edit' ) ) + '</button>' : '' ) + '</div>'
					+ ( th.body ? '<div class="ff-text">' + rich( th.body ) + '</div>' : '' ) + cards( th.attachments )
					+ '<p class="ff-meta">' + esc( person( th.author ).name + ' · ' + when( th.created ) + ' · #' + chName( th.channel ) ) + ' ' + audiencePill( th ) + '</p>'
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

	window.Flurfunk = {
		mountApp,
		mountTarget,
		mountRefs,
		registerCard: ( id, fn ) => {
			renderers[ id ] = fn;
		},
		api,
		t,
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

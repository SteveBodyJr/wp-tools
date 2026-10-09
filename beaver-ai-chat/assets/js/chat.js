/**
 * Beaver AI Chat - widget behaviour.
 *
 * Talks only to this site's own REST endpoint. No API key ever reaches the
 * browser. Conversation state lives in sessionStorage so a page navigation does
 * not lose the thread, and is cleared when the visitor ends the chat.
 */
( function () {
	'use strict';

	/*
	 * WordPress prints footer scripts at wp_footer priority 20, while the widget
	 * markup is printed later in the same hook. This file therefore runs before
	 * #bac-root exists, so wait for the document to finish parsing before
	 * looking anything up. This also keeps the widget working if a caching or
	 * optimisation plugin defers, moves or bundles the script.
	 */
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	function boot() {

	var C = window.BAC_CONFIG || {};
	var root = document.getElementById( 'bac-root' );

	if ( ! root || ! C.rest ) {
		return;
	}

	var T = C.i18n || {};
	var inline = root.getAttribute( 'data-bac-inline' ) === '1';

	var panel = document.getElementById( 'bac-panel' );
	var launch = document.getElementById( 'bac-launch' );
	var log = root.querySelector( '[data-bac-log]' );
	var chips = root.querySelector( '[data-bac-chips]' );
	var form = root.querySelector( '[data-bac-form]' );
	var input = root.querySelector( '[data-bac-input]' );
	var sendBtn = form.querySelector( '.bac-send' );
	var nudge = root.querySelector( '.bac-nudge' );
	var cta = root.querySelector( '[data-bac-cta]' );
	var contactBtn = root.querySelector( '[data-bac-contact]' );
	var contactLbl = root.querySelector( '[data-bac-contact-label]' );
	var waBtn = root.querySelector( '[data-bac-wa]' );
	var menuBtn = root.querySelector( '[data-bac-menu]' );
	var menu = root.querySelector( '[data-bac-menu-list]' );

	var STORE_HISTORY = 'bac_history';
	var STORE_SID = 'bac_sid';
	var STORE_NUDGE = 'bac_nudge_seen';
	var STORE_NOTICE = 'bac_notice_seen';

	var history = [];
	var busy = false;
	var started = false;
	var ended = false;
	var requested = false;

	/* ------------------------------------------------------------- helpers */

	function store( key, value ) {
		try {
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {}
	}

	function read( key ) {
		try {
			return window.sessionStorage.getItem( key ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function uid() {
		try {
			if ( window.crypto && window.crypto.randomUUID ) {
				return window.crypto.randomUUID();
			}
		} catch ( e ) {}
		return 'bac-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	var sid = read( STORE_SID );
	if ( ! sid ) {
		sid = uid();
		store( STORE_SID, sid );
	}

	try {
		var saved = read( STORE_HISTORY );
		if ( saved ) {
			history = JSON.parse( saved ) || [];
		}
	} catch ( e ) {
		history = [];
	}

	function save() {
		try {
			store( STORE_HISTORY, JSON.stringify( history.slice( -20 ) ) );
		} catch ( e ) {}
	}

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/*
	 * A bare "/tanzania-weather/" is written relative to the domain, which
	 * breaks when WordPress lives in a sub folder. Prefix the site's own path
	 * unless the link already carries it.
	 */
	var home = ( C.home || '/' ).replace( /\/?$/, '/' );

	function sitePath( path ) {
		if ( home === '/' || path.indexOf( home ) === 0 ) {
			return path;
		}
		return home + path.replace( /^\//, '' );
	}

	/** "/tours/7-day-migration-safari/" reads as "7 Day Migration Safari". */
	function pageTitle( path ) {
		var slug = path.replace( /[?#].*$/, '' ).replace( /\/+$/, '' ).split( '/' ).pop() || '';
		slug = decodeURIComponent( slug ).replace( /[-_]+/g, ' ' ).trim();
		return slug ? slug.charAt( 0 ).toUpperCase() + slug.slice( 1 ) : path;
	}

	function isExternal( url ) {
		return /^https?:\/\//i.test( url ) && url.indexOf( window.location.host ) === -1;
	}

	function link( url, label ) {
		var external = isExternal( url );
		return '<a href="' + url + '"' + ( external ? ' target="_blank" rel="noopener"' : '' ) + '>' + label + '</a>';
	}

	/** Escape, then linkify and bold. Runs on escaped text only. */
	function inlineMarkup( s ) {
		var h = esc( s );

		// [label](url) first, so its URL is not linked a second time below.
		h = h.replace( /\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g, function ( m, label, url ) {
			return link( url.charAt( 0 ) === '/' ? sitePath( url ) : url, label );
		} );
		h = h.replace( /(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,!?;:])/g, function ( m, pre, url ) {
			return pre + link( url, isExternal( url ) ? url.replace( /^https?:\/\/(www\.)?/, '' ).replace( /\/$/, '' ) : pageTitle( url ) );
		} );
		h = h.replace( /(^|[\s(])(\/[a-z0-9\-/]+\/)/gi, function ( m, pre, path ) {
			return pre + link( sitePath( path ), pageTitle( path ) );
		} );
		h = h.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );

		return h;
	}

	/** Render prose: paragraphs, "- " bullet lists and "1. " numbered lists. */
	function format( text ) {
		var lines = String( text ).replace( /\r/g, '' ).split( '\n' );
		var parts = [];
		var list = [];
		var listTag = 'ul';
		var para = [];

		function flushList() {
			if ( list.length ) {
				parts.push( '<' + listTag + '>' + list.map( function ( x ) {
					return '<li>' + inlineMarkup( x ) + '</li>';
				} ).join( '' ) + '</' + listTag + '>' );
				list = [];
			}
		}

		function flushPara() {
			if ( para.length ) {
				parts.push( '<p>' + para.map( inlineMarkup ).join( '<br>' ) + '</p>' );
				para = [];
			}
		}

		lines.forEach( function ( line ) {
			var bullet = line.match( /^\s*[-•*]\s+(.*\S)\s*$/ );
			var number = line.match( /^\s*\d{1,2}[.)]\s+(.*\S)\s*$/ );
			var item = bullet || number;
			var tag = number ? 'ol' : 'ul';

			if ( item ) {
				flushPara();
				if ( list.length && tag !== listTag ) {
					flushList();
				}
				listTag = tag;
				list.push( item[ 1 ] );
			} else if ( line.trim() === '' ) {
				flushPara();
				flushList();
			} else {
				flushList();
				para.push( line.trim() );
			}
		} );

		flushPara();
		flushList();

		return parts.join( '' ) || inlineMarkup( String( text ) );
	}

	function paintAvatar( el ) {
		if ( C.avatar ) {
			el.style.backgroundImage = 'url("' + C.avatar.replace( /"/g, '' ) + '")';
			el.classList.add( 'bac-has-photo' );
			el.textContent = '';
		} else {
			el.textContent = C.initial || 'A';
		}
	}

	/*
	 * Keep the newest message in view. Waits a frame so it measures after any
	 * layout change made in the same tick: the action bar under the log appears
	 * after the first reply, shrinks the log, and would otherwise push the
	 * message just scrolled to back out of sight.
	 */
	function scrollToEnd( smooth ) {
		window.requestAnimationFrame( function () {
			if ( log.scrollTo ) {
				log.scrollTo( { top: log.scrollHeight, behavior: smooth ? 'smooth' : 'auto' } );
			} else {
				log.scrollTop = log.scrollHeight;
			}
		} );
	}

	/* -------------------------------------------------------------- chrome */

	function each( selector, fn ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), fn );
	}

	function setText( selector, value ) {
		var el = root.querySelector( selector );
		if ( el ) {
			el.textContent = value || '';
		}
	}

	each( '[data-bac-avatar]', paintAvatar );

	setText( '[data-bac-name]', C.assistant );
	setText( '[data-bac-tagline]', C.tagline );
	setText( '[data-bac-foot]', C.footerNote );
	setText( '[data-bac-nudge-text]', C.nudge );
	setText( '[data-bac-contact-label]', C.cta );
	setText( '[data-bac-wa-label]', T.whatsapp );
	setText( '[data-bac-end]', T.end );
	setText( '[data-bac-new]', T.restart );
	setText( '[data-bac-restart]', T.restart );
	setText( '[data-bac-launch-label]', C.launcherLabel );

	input.setAttribute( 'placeholder', C.placeholder || '' );

	/*
	 * The notice above the message box: the AI disclosure plus, when the site
	 * has a privacy policy, a line linking to it. Once dismissed it stays
	 * dismissed for this visitor, on every page and future visit.
	 */
	var notice = root.querySelector( '[data-bac-notice]' );
	var agree = root.querySelector( '[data-bac-agree]' );

	if ( agree && C.privacy ) {
		var parts = String( T.agree || 'By chatting, you agree to our %s.' ).split( '%s' );
		var policy = document.createElement( 'a' );
		policy.href = C.privacy;
		policy.target = '_blank';
		policy.rel = 'noopener';
		policy.textContent = T.policy || 'privacy policy';
		agree.appendChild( document.createTextNode( parts[ 0 ] || '' ) );
		agree.appendChild( policy );
		agree.appendChild( document.createTextNode( parts[ 1 ] || '' ) );
	}

	function noticeSeen() {
		try {
			return window.localStorage.getItem( STORE_NOTICE ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	if ( notice && ( C.footerNote || C.privacy ) && ! noticeSeen() ) {
		notice.hidden = false;
	}

	each( '[data-bac-notice-close]', function ( el ) {
		el.addEventListener( 'click', function () {
			notice.hidden = true;
			try {
				window.localStorage.setItem( STORE_NOTICE, '1' );
			} catch ( e ) {}
			input.focus();
		} );
	} );

	if ( waBtn && C.whatsapp ) {
		waBtn.href = 'https://wa.me/' + C.whatsapp + '?text=' + encodeURIComponent( C.waMessage || '' );
		waBtn.hidden = false;
	}

	if ( ! C.cta && contactBtn ) {
		contactBtn.hidden = true;
	}

	/* --------------------------------------------------------------- render */

	function bubble( who, text, extraClass ) {
		var row = document.createElement( 'div' );
		row.className = 'bac-row ' + ( who === 'me' ? 'me' : 'bot' ) + ( extraClass ? ' ' + extraClass : '' );

		var body = document.createElement( 'div' );
		body.className = 'bac-bubble';

		if ( text === '__typing__' ) {
			body.innerHTML = '<span class="bac-typing" role="status" aria-label="' + esc( T.typing || '' ) + '"><span></span><span></span><span></span></span>';
		} else {
			body.innerHTML = format( text );
		}

		row.appendChild( body );
		log.appendChild( row );
		scrollToEnd( true );

		return row;
	}

	/*
	 * Suggested questions sit just above the message box, right aligned like
	 * the visitor's own messages: they read as things you could say next.
	 * They go once the conversation has started.
	 */
	function renderChips() {
		chips.innerHTML = '';

		if ( history.length > 1 || ended ) {
			return;
		}

		( C.chips || [] ).forEach( function ( question ) {
			var chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'bac-chip';
			chip.textContent = question;
			chip.addEventListener( 'click', function () {
				input.value = question;
				submit();
			} );
			chips.appendChild( chip );
		} );
	}

	function updateCta() {
		if ( ! cta ) {
			return;
		}

		var hasUser = history.some( function ( m ) {
			return m.role === 'user';
		} );

		cta.hidden = ! ( started && hasUser && ! ended && ( C.cta || C.whatsapp ) );
	}

	function replay() {
		log.innerHTML = '';

		if ( ! history.length ) {
			history.push( { role: 'assistant', content: C.greeting || 'Hello! How can I help?' } );
		}

		history.forEach( function ( m ) {
			bubble( m.role === 'user' ? 'me' : 'bot', m.content );
		} );

		renderChips();
		updateCta();
		scrollToEnd( false );
	}

	/* ----------------------------------------------------------------- menu */

	function setMenu( show ) {
		if ( ! menu || ! menuBtn ) {
			return;
		}
		menu.hidden = ! show;
		menuBtn.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
		if ( show ) {
			var first = menu.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
		}
	}

	if ( menuBtn ) {
		menuBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			setMenu( menu.hidden );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( menu && ! menu.hidden && ! menu.contains( e.target ) ) {
			setMenu( false );
		}
	} );

	/* ----------------------------------------------------------- open/close */

	function hideNudge() {
		if ( nudge ) {
			nudge.hidden = true;
		}
		store( STORE_NUDGE, '1' );
	}

	// When the chat covers the whole phone screen, stop the page behind it from
	// scrolling under the conversation. The CSS only acts on small screens, so
	// the class is harmless on a desktop.
	var fullscreen = root.classList.contains( 'bac-fullscreen' );

	function lockPage( lock ) {
		if ( ! fullscreen ) {
			return;
		}
		document.documentElement.classList.toggle( 'bac-scroll-lock', lock );
	}

	function open() {
		root.classList.add( 'bac-open' );
		if ( panel ) {
			panel.hidden = false;
		}
		if ( launch ) {
			launch.setAttribute( 'aria-expanded', 'true' );
			launch.setAttribute( 'aria-label', T.close || 'Minimise chat' );
		}
		lockPage( true );
		hideNudge();

		if ( ! started ) {
			started = true;
			replay();
		} else {
			scrollToEnd( false );
		}

		window.setTimeout( function () {
			input.focus();
		}, 220 );
	}

	function close() {
		root.classList.remove( 'bac-open' );
		lockPage( false );
		setMenu( false );

		if ( launch ) {
			launch.setAttribute( 'aria-expanded', 'false' );
			launch.setAttribute( 'aria-label', T.open || 'Open chat' );
			launch.focus();
		}
	}

	if ( launch ) {
		launch.addEventListener( 'click', function () {
			if ( root.classList.contains( 'bac-open' ) ) {
				close();
			} else {
				open();
			}
		} );
	}

	each( '[data-bac-open]', function ( el ) {
		el.addEventListener( 'click', open );
	} );

	each( '[data-bac-nudge-close]', function ( el ) {
		el.addEventListener( 'click', hideNudge );
	} );

	each( '[data-bac-close]', function ( el ) {
		el.addEventListener( 'click', close );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' ) {
			return;
		}
		if ( menu && ! menu.hidden ) {
			setMenu( false );
			menuBtn.focus();
		} else if ( ! inline && root.classList.contains( 'bac-open' ) ) {
			close();
		}
	} );

	/* ------------------------------------------------------- end / restart */

	function newSession() {
		sid = uid();
		store( STORE_SID, sid );
	}

	function endChat() {
		setMenu( false );

		if ( ended ) {
			return;
		}

		ended = true;
		bubble( 'bot', T.farewell || 'Thanks for chatting.' );

		history = [];
		save();
		newSession();

		renderChips();
		root.classList.add( 'bac-ended' );
		updateCta();
		scrollToEnd( true );
	}

	function startNew() {
		setMenu( false );

		if ( ! ended && history.some( function ( m ) { return m.role === 'user'; } ) ) {
			newSession();
		}

		ended = false;
		requested = false;
		root.classList.remove( 'bac-ended' );

		if ( contactBtn ) {
			contactBtn.disabled = false;
		}
		if ( contactLbl ) {
			contactLbl.textContent = C.cta || '';
		}

		history = [];
		started = true;
		replay();
		save();

		window.setTimeout( function () {
			input.focus();
		}, 120 );
	}

	each( '[data-bac-end]', function ( el ) {
		el.addEventListener( 'click', endChat );
	} );

	each( '[data-bac-new], [data-bac-restart]', function ( el ) {
		el.addEventListener( 'click', startNew );
	} );

	/* -------------------------------------------------------- contact hand-off */

	function requestContact() {
		if ( requested || busy || ! C.contactRest ) {
			return;
		}

		contactBtn.disabled = true;
		if ( contactLbl ) {
			contactLbl.textContent = T.sending || 'Sending…';
		}

		window.fetch( C.contactRest, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { token: C.token, sid: sid } )
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				var reply = ( j && j.reply ) || 'Done.';
				bubble( 'bot', reply );
				history.push( { role: 'assistant', content: reply } );
				save();

				if ( j && ( j.ok || j.done ) ) {
					requested = true;
					contactBtn.disabled = true;
					if ( contactLbl ) {
						contactLbl.textContent = T.requested || 'Request sent';
					}
				} else {
					contactBtn.disabled = false;
					if ( contactLbl ) {
						contactLbl.textContent = C.cta || '';
					}
					if ( j && j.need_email ) {
						window.setTimeout( function () {
							input.focus();
						}, 120 );
					}
				}
			} )
			.catch( function () {
				contactBtn.disabled = false;
				if ( contactLbl ) {
					contactLbl.textContent = C.cta || '';
				}
				bubble( 'bot', T.offline || 'Could not reach the server.', 'bac-error' );
			} );
	}

	if ( contactBtn ) {
		contactBtn.addEventListener( 'click', requestContact );
	}

	/* ------------------------------------------------------------- sending */

	/*
	 * A failed turn is shown with a retry, and never stored: the visitor's
	 * message stays the last thing in the history, so retrying simply asks
	 * again, and the failure text is never sent back to the model as if the
	 * assistant had said it.
	 */
	function failed( text ) {
		var row = bubble( 'bot', text, 'bac-error' );
		var retry = document.createElement( 'button' );

		retry.type = 'button';
		retry.className = 'bac-retry';
		retry.textContent = T.retry || 'Try again';
		retry.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			row.parentNode.removeChild( row );
			ask();
		} );

		row.querySelector( '.bac-bubble' ).appendChild( retry );
	}

	function ask() {
		busy = true;
		sendBtn.disabled = true;
		var typing = bubble( 'bot', '__typing__', 'bac-pending' );

		window.fetch( C.rest, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				token: C.token,
				sid: sid,
				page: window.location.href,
				messages: history.slice( -1 * ( C.historyTurns || 14 ) )
			} )
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				removeNode( typing );

				if ( ! j || ! j.reply || j.error ) {
					failed( ( j && j.reply ) || T.failed );
					return;
				}

				bubble( 'bot', j.reply );
				history.push( { role: 'assistant', content: j.reply } );
				save();
				updateCta();
				scrollToEnd( true );
			} )
			.catch( function () {
				removeNode( typing );
				failed( T.offline || 'Could not reach the server.' );
			} )
			.then( function () {
				busy = false;
				sendBtn.disabled = false;
				input.focus();
			} );
	}

	function submit() {
		var text = ( input.value || '' ).trim();

		if ( ! text || busy || ended ) {
			return;
		}

		input.value = '';
		input.style.height = 'auto';

		bubble( 'me', text );
		history.push( { role: 'user', content: text } );
		save();
		renderChips();
		updateCta();
		scrollToEnd( true );

		ask();
	}

	function removeNode( node ) {
		if ( node && node.parentNode ) {
			node.parentNode.removeChild( node );
		}
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		submit();
	} );

	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			submit();
		}
	} );

	input.addEventListener( 'input', function () {
		input.style.height = 'auto';
		input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
	} );

	/* --------------------------------------------------------------- start */

	if ( inline ) {
		started = true;
		replay();
	} else if ( nudge && C.nudge && read( STORE_NUDGE ) !== '1' ) {
		window.setTimeout( function () {
			if ( ! root.classList.contains( 'bac-open' ) ) {
				nudge.hidden = false;
			}
		}, C.nudgeDelay || 1800 );
	}

	} // end boot()
} )();

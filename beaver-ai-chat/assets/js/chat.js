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

	var STORE_HISTORY = 'bac_history';
	var STORE_SID = 'bac_sid';
	var STORE_NUDGE = 'bac_nudge_seen';

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

	/** Escape, then linkify and bold. Runs on escaped text only. */
	function inlineMarkup( s ) {
		var h = esc( s );
		h = h.replace( /\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>' );
		h = h.replace( /(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>' );
		h = h.replace( /(^|[\s(])(\/[a-z0-9\-/]+\/)/gi, '$1<a href="$2">$2</a>' );
		h = h.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
		return h;
	}

	/** Render prose, turning "- " lines into a styled list. */
	function format( text ) {
		var lines = String( text ).replace( /\r/g, '' ).split( '\n' );
		var parts = [];
		var list = [];
		var para = [];

		function flushList() {
			if ( list.length ) {
				parts.push( '<ul>' + list.map( function ( x ) {
					return '<li>' + inlineMarkup( x ) + '</li>';
				} ).join( '' ) + '</ul>' );
				list = [];
			}
		}

		function flushPara() {
			if ( para.length ) {
				parts.push( para.map( inlineMarkup ).join( '<br>' ) );
				para = [];
			}
		}

		lines.forEach( function ( line ) {
			var bullet = line.match( /^\s*(?:[-•*])\s+(.*\S)\s*$/ );
			if ( bullet ) {
				flushPara();
				list.push( bullet[ 1 ] );
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
			el.textContent = '';
		} else {
			el.textContent = C.initial || 'A';
		}
	}

	/* -------------------------------------------------------------- chrome */

	Array.prototype.forEach.call( root.querySelectorAll( '[data-bac-avatar]' ), paintAvatar );

	setText( '[data-bac-name]', C.assistant );
	setText( '[data-bac-tagline]', C.tagline );
	setText( '[data-bac-foot]', C.footerNote );
	setText( '[data-bac-nudge-text]', C.nudge );
	setText( '[data-bac-contact-label]', C.cta );
	setText( '[data-bac-wa-label]', T.whatsapp );
	setText( '[data-bac-end]', T.end );
	setText( '[data-bac-restart]', T.restart );

	input.setAttribute( 'placeholder', C.placeholder || '' );

	function setText( selector, value ) {
		var el = root.querySelector( selector );
		if ( el ) {
			el.textContent = value || '';
		}
	}

	if ( waBtn && C.whatsapp ) {
		waBtn.href = 'https://wa.me/' + C.whatsapp + '?text=' + encodeURIComponent( C.waMessage || '' );
		waBtn.hidden = false;
	}

	if ( ! C.cta && contactBtn ) {
		contactBtn.hidden = true;
	}

	/* --------------------------------------------------------------- render */

	function bubble( who, text ) {
		var row = document.createElement( 'div' );
		row.className = 'bac-row ' + ( who === 'me' ? 'me' : 'bot' );

		if ( who !== 'me' ) {
			var mini = document.createElement( 'span' );
			mini.className = 'bac-mini';
			paintAvatar( mini );
			row.appendChild( mini );
		}

		var body = document.createElement( 'div' );
		body.className = 'bac-bubble';

		if ( text === '__typing__' ) {
			body.innerHTML = '<span class="bac-typing"><span></span><span></span><span></span></span>';
		} else {
			body.innerHTML = format( text );
		}

		row.appendChild( body );
		log.appendChild( row );
		log.scrollTop = log.scrollHeight;

		return row;
	}

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
	}

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
		}

		window.setTimeout( function () {
			input.focus();
		}, 280 );
	}

	function close() {
		root.classList.remove( 'bac-open' );
		lockPage( false );

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
		if ( e.key === 'Escape' && ! inline && root.classList.contains( 'bac-open' ) ) {
			close();
		}
	} );

	function each( selector, fn ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), fn );
	}

	/* ------------------------------------------------------- end / restart */

	function newSession() {
		sid = uid();
		store( STORE_SID, sid );
	}

	function endChat() {
		if ( ended ) {
			return;
		}

		ended = true;
		bubble( 'bot', T.farewell || 'Thanks for chatting.' );

		history = [];
		save();
		newSession();

		chips.innerHTML = '';
		root.classList.add( 'bac-ended' );
		updateCta();
		log.scrollTop = log.scrollHeight;
	}

	function startNew() {
		ended = false;
		requested = false;
		root.classList.remove( 'bac-ended' );

		if ( contactBtn ) {
			contactBtn.disabled = false;
		}
		if ( contactLbl ) {
			contactLbl.textContent = C.cta || '';
		}

		log.innerHTML = '';
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

	each( '[data-bac-restart]', function ( el ) {
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
				bubble( 'bot', T.offline || 'Could not reach the server.' );
			} );
	}

	if ( contactBtn ) {
		contactBtn.addEventListener( 'click', requestContact );
	}

	/* ------------------------------------------------------------- sending */

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

		busy = true;
		sendBtn.disabled = true;
		var typing = bubble( 'bot', '__typing__' );

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
				removeTyping( typing );
				var reply = ( j && ( j.reply || j.message ) ) || T.failed;
				bubble( 'bot', reply );
				history.push( { role: 'assistant', content: reply } );
				save();
			} )
			.catch( function () {
				removeTyping( typing );
				bubble( 'bot', T.offline || 'Could not reach the server.' );
			} )
			.then( function () {
				busy = false;
				sendBtn.disabled = false;
				input.focus();
			} );
	}

	function removeTyping( node ) {
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

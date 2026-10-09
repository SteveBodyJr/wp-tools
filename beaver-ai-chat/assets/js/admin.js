/**
 * Beaver AI Chat - settings screen behaviour: tabs, provider hints and the
 * connection tester.
 */
( function () {
	'use strict';

	var A = window.BAC_ADMIN || {};
	var T = A.i18n || {};
	var HINTS = A.providers || {};

	/* --------------------------------------------------------------- tabs */

	var tabs = document.querySelectorAll( '[data-bac-tab]' );
	var panels = document.querySelectorAll( '[data-bac-panel]' );

	function show( name ) {
		var found = false;

		Array.prototype.forEach.call( panels, function ( panel ) {
			var match = panel.getAttribute( 'data-bac-panel' ) === name;
			panel.classList.toggle( 'is-active', match );
			if ( match ) {
				found = true;
			}
		} );

		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.classList.toggle( 'nav-tab-active', tab.getAttribute( 'data-bac-tab' ) === name );
		} );

		// The Tools tab lives outside the settings form, so hide Save there.
		var save = document.querySelector( '.bac-form .submit' );
		if ( save ) {
			save.style.display = ( name === 'tools' ) ? 'none' : '';
		}

		if ( found ) {
			try {
				window.localStorage.setItem( 'bacTab', name );
			} catch ( e ) {}
		}

		return found;
	}

	Array.prototype.forEach.call( tabs, function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			show( tab.getAttribute( 'data-bac-tab' ) );
		} );
	} );

	var initial = ( window.location.hash || '' ).replace( '#', '' );

	if ( ! initial ) {
		try {
			initial = window.localStorage.getItem( 'bacTab' ) || '';
		} catch ( e ) {}
	}

	if ( ! initial || ! show( initial ) ) {
		show( 'connection' );
	}

	/* ------------------------------------------- validation across tabs */

	/*
	 * A field the browser rejects — a number outside its min/max, say — blocks
	 * the whole form from submitting. When that field is on a tab that is not
	 * showing, the browser cannot focus it to report why, so it cancels the
	 * submit and says nothing: Save appears to be broken.
	 *
	 * Opening the tab that holds the field puts it back on screen in time for
	 * the browser to focus it and show its message. The listener is on the
	 * capture phase because `invalid` does not bubble.
	 */
	var settingsForm = document.querySelector( '.bac-form' );

	if ( settingsForm ) {
		settingsForm.addEventListener( 'invalid', function ( e ) {
			var panel = e.target.closest ? e.target.closest( '[data-bac-panel]' ) : null;

			if ( panel && ! panel.classList.contains( 'is-active' ) ) {
				show( panel.getAttribute( 'data-bac-panel' ) );
			}
		}, true );
	}

	/* ---------------------------------------------------- provider hints */

	var provider = document.getElementById( 'bac-provider' );
	var providerHint = document.getElementById( 'bac-provider-hint' );
	var modelHint = document.getElementById( 'bac-model-hint' );

	function refreshProvider() {
		if ( ! provider ) {
			return;
		}

		var h = HINTS[ provider.value ] || {};

		if ( providerHint ) {
			providerHint.innerHTML = h.keysAt
				? 'Get a key from <strong>' + h.keysAt + '</strong>' + ( h.prefix ? ', it looks like <code>' + h.prefix + '</code>' : '' )
				: '';
		}

		if ( modelHint ) {
			modelHint.innerHTML = h.model
				? 'Leave blank to use <code>' + h.model + '</code>.' + ( h.alt ? ' Alternative: ' + h.alt + '.' : '' )
				: 'Enter the model name your endpoint expects.';
		}

		toggleRow( '.bac-row-custom', !! h.custom );
		toggleRow( '.bac-row-claude', !! h.claude );
		toggleRow( '.bac-row-temp', !! h.temp );
	}

	function toggleRow( selector, visible ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), function ( row ) {
			row.style.display = visible ? '' : 'none';
		} );
	}

	if ( provider ) {
		provider.addEventListener( 'change', refreshProvider );
		refreshProvider();
	}

	/* ------------------------------------------------------------ API keys */

	/*
	 * One slot per provider. Each key input is disabled until Add or Change is
	 * pressed, so a browser or password manager cannot autofill it and saving
	 * another setting cannot overwrite a working key. Enabling it is the only
	 * way it joins the form.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '[data-bac-keyslot]' ), function ( slot ) {
		var edit = slot.querySelector( '[data-bac-key-edit]' );
		var input = edit ? edit.querySelector( 'input' ) : null;
		var actions = slot.querySelector( '.bac-keyslot__actions' );
		var removeFlag = slot.querySelector( '[data-bac-key-remove-flag]' );
		var removing = slot.querySelector( '[data-bac-key-removing]' );

		function editing( on ) {
			if ( ! edit || ! input ) {
				return;
			}
			edit.hidden = ! on;
			input.disabled = ! on;
			actions.hidden = on;
			if ( on ) {
				input.value = '';
				input.focus();
				guardAutofill( input );
			}
		}

		function markRemoved( on ) {
			if ( ! removeFlag ) {
				return;
			}
			removeFlag.disabled = ! on;
			removing.hidden = ! on;
			actions.hidden = on;
			slot.classList.toggle( 'is-removing', on );
		}

		on( slot, '[data-bac-key-change]', function () { editing( true ); } );
		on( slot, '[data-bac-key-cancel]', function () { editing( false ); } );
		on( slot, '[data-bac-key-remove]', function () { markRemoved( true ); } );
		on( slot, '[data-bac-key-undo]', function () { markRemoved( false ); } );
		on( slot, '[data-bac-key-test]', function ( btn ) {
			runTest( btn, slot.querySelector( '[data-bac-key-test-out]' ), 'bac_test', A.nonce, T.testing || 'Testing…', T.ok || 'Connected.', {
				provider: slot.getAttribute( 'data-bac-keyslot' )
			} );
		} );
	} );

	function on( scope, selector, fn ) {
		var el = scope.querySelector( selector );
		if ( el ) {
			el.addEventListener( 'click', function () {
				fn( el );
			} );
		}
	}

	// A password manager can still fill a box the moment it is revealed.
	// Clear anything that appears without a keystroke, so nothing is saved
	// that the person did not actually type or paste.
	function guardAutofill( input ) {
		var typed = false;
		input.addEventListener( 'keydown', function () { typed = true; } );
		input.addEventListener( 'paste', function () { typed = true; } );

		window.setTimeout( function () {
			if ( ! typed && ! input.disabled && input.value !== '' ) {
				input.value = '';
			}
		}, 600 );
	}

	/*
	 * Mark the main provider's key slot, and take it out of the backup list:
	 * the main AI cannot be its own backup.
	 */
	function refreshMain() {
		var main = provider ? provider.value : '';

		Array.prototype.forEach.call( document.querySelectorAll( '[data-bac-keyslot]' ), function ( slot ) {
			var badge = slot.querySelector( '[data-bac-main-badge]' );
			var isMain = slot.getAttribute( 'data-bac-keyslot' ) === main;
			slot.classList.toggle( 'is-main', isMain );
			if ( badge ) {
				badge.hidden = ! isMain;
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-bac-fallback]' ), function ( label ) {
			var box = label.querySelector( 'input' );
			var isMain = label.getAttribute( 'data-bac-fallback' ) === main;
			box.disabled = isMain;
			label.classList.toggle( 'is-main', isMain );
		} );
	}

	if ( provider ) {
		provider.addEventListener( 'change', refreshMain );
		refreshMain();
	}

	/* ------------------------------------------------------------- testers */

	/**
	 * Both testers behave the same way: disable the button, report back in
	 * place, and never leave the button stuck if the request dies.
	 */
	function wireTest( buttonId, outId, action, nonce, busyText, okPrefix ) {
		var btn = document.getElementById( buttonId );
		var out = document.getElementById( outId );

		if ( ! btn || ! out ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			runTest( btn, out, action, nonce, busyText, okPrefix, {} );
		} );
	}

	function runTest( btn, out, action, nonce, busyText, okPrefix, extra ) {
		if ( ! btn || ! out ) {
			return;
		}

		out.className = 'bac-test-out is-busy';
		out.textContent = busyText;
		btn.disabled = true;

		var data = new window.FormData();
		data.append( 'action', action );
		data.append( '_ajax_nonce', nonce );
		Object.keys( extra || {} ).forEach( function ( k ) {
			data.append( k, extra[ k ] );
		} );

		window.fetch( A.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				btn.disabled = false;

				if ( j && j.success ) {
					out.className = 'bac-test-out is-ok';
					out.textContent = '✓ ' + ( okPrefix ? okPrefix + ' ' : '' ) + ( ( j.data && j.data.message ) || '' );
				} else {
					out.className = 'bac-test-out is-bad';
					out.textContent = '✗ ' + ( ( j && j.data && j.data.message ) || T.failed || 'Failed' );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				out.className = 'bac-test-out is-bad';
				out.textContent = '✗ ' + ( T.network || 'Network error' );
			} );
	}

	wireTest( 'bac-test', 'bac-test-out', 'bac_test', A.nonce, T.testing || 'Testing…', T.ok || 'Connected.' );
	wireTest( 'bac-test-email', 'bac-test-email-out', 'bac_test_email', A.emailNonce, T.sending || 'Sending…', '' );

	/* -------------------------------------------------------- alert timing */

	/*
	 * Only show the settings that apply to the chosen timing. A quiet window is
	 * meaningless for a roundup, and a roundup hour is meaningless for the rest.
	 */
	var timing = document.getElementById( 'bac-notify-timing' );
	var digestEvery = document.getElementById( 'bac-notify-digest-every' );
	var share = document.getElementById( 'bac-notify-share-links' );
	var kbMode = document.getElementById( 'bac-kb-mode' );
	var waMode = document.getElementById( 'bac-wa-api-mode' );

	function refreshAlerts() {
		if ( timing ) {
			toggleRow( '.bac-row-settled', timing.value === 'settled' );
			toggleRow( '.bac-row-digest', timing.value === 'digest' );
		}

		if ( digestEvery ) {
			toggleRow( '.bac-row-digest-daily', digestEvery.value === '24' );
		}

		if ( share ) {
			toggleRow( '.bac-row-share', share.checked );
		}

		if ( kbMode ) {
			toggleRow( '.bac-row-relevant', kbMode.value === 'relevant' );
		}

		if ( waMode ) {
			toggleRow( '.bac-row-wa-template', waMode.value === 'template' );
		}
	}

	[ timing, digestEvery, share, kbMode, waMode ].forEach( function ( el ) {
		if ( el ) {
			el.addEventListener( 'change', refreshAlerts );
		}
	} );

	refreshAlerts();

	/* ------------------------------------------------------ overview cards */

	// The figures across the top open the tab where they are set.
	Array.prototype.forEach.call( document.querySelectorAll( '[data-bac-goto]' ), function ( card ) {
		card.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			show( card.getAttribute( 'data-bac-goto' ) );
			var nav = document.querySelector( '.bac-tabs' );
			if ( nav && nav.scrollIntoView ) {
				nav.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		} );
	} );

	/* --------------------------------------------------------- live preview */

	/*
	 * Repaints the drawing on the Appearance tab from the fields as they
	 * change. The colour maths mirrors BAC_Widget: text on a fill is white or
	 * near black by WCAG contrast, and the assistant's bubble is a tint of the
	 * window background.
	 */
	var pv = document.querySelector( '[data-bac-pv]' );

	if ( pv ) {
		var pvRoot = pv.parentNode;
		var field = function ( key ) {
			return document.querySelector( '[name="' + ( A.option || 'beaver_ai_chat_settings' ) + '[' + key + ']"]' );
		};

		var rgb = function ( hex ) {
			hex = String( hex || '' ).replace( '#', '' );
			if ( hex.length === 3 ) {
				hex = hex.replace( /(.)/g, '$1$1' );
			}
			var n = parseInt( hex, 16 );
			return isNaN( n ) ? [ 0, 0, 0 ] : [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
		};

		var lum = function ( hex ) {
			var c = rgb( hex ).map( function ( v ) {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
			} );
			return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
		};

		var contrast = function ( a, b ) {
			var x = lum( a ), y = lum( b );
			return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
		};

		var onColour = function ( hex ) {
			return contrast( hex, '#ffffff' ) >= contrast( hex, '#1d1d1b' ) ? '#ffffff' : '#1d1d1b';
		};

		var shade = function ( hex, amount ) {
			return 'rgb(' + rgb( hex ).map( function ( v ) {
				return Math.round( amount < 0 ? v * ( 1 + amount ) : v + ( 255 - v ) * amount );
			} ).join( ',' ) + ')';
		};

		var paint = function () {
			var accent = ( field( 'accent' ) || {} ).value || '#1b7f5a';
			var second = ( field( 'secondary' ) || {} ).value || '#c9a24a';
			var surface = ( field( 'surface' ) || {} ).value || '#ffffff';
			var corner = ( A.corners || {} )[ ( field( 'corner_style' ) || {} ).value ] || { panel: '20px', control: '14px', pillish: '999px', bubble: '16px' };
			var bubbleKey = ( field( 'bubble_style' ) || {} ).value;
			var bubble = ( A.bubbles || {} )[ bubbleKey ] || { radius: '16px' };
			var name = ( field( 'assistant' ) || {} ).value || '';
			var label = ( ( field( 'launcher_label' ) || {} ).value || '' ).replace( /\{assistant\}/g, name );
			var avatar = ( field( 'avatar_url' ) || {} ).value || '';

			var vars = {
				'--pv-accent': accent,
				'--pv-on-accent': onColour( accent ),
				'--pv-second': second,
				'--pv-on-second': onColour( second ),
				'--pv-bg': surface,
				'--pv-bubble': shade( surface, -0.05 ),
				'--pv-line': shade( surface, -0.09 ),
				'--pv-field': lum( surface ) >= 0.97 ? surface : '#ffffff',
				'--pv-link': contrast( accent, '#ffffff' ) >= 4.5 ? accent : shade( accent, -0.5 ),
				'--pv-radius': corner.panel,
				'--pv-pill': corner.pillish,
				'--pv-bubble-radius': 'match' === bubbleKey ? corner.bubble : bubble.radius
			};

			Object.keys( vars ).forEach( function ( k ) {
				pvRoot.style.setProperty( k, vars[ k ] );
			} );

			var nameEl = pvRoot.querySelector( '[data-bac-pv-name]' );
			var av = pvRoot.querySelector( '[data-bac-pv-av]' );
			var labelEl = pvRoot.querySelector( '[data-bac-pv-label]' );
			var launcher = pvRoot.querySelector( '[data-bac-pv-launcher]' );

			if ( nameEl && name ) {
				nameEl.textContent = name;
			}
			if ( av ) {
				av.style.backgroundImage = avatar ? 'url("' + avatar.replace( /"/g, '' ) + '")' : '';
				av.classList.toggle( 'has-photo', !! avatar );
				av.textContent = avatar ? '' : ( name.charAt( 0 ) || 'A' ).toUpperCase();
			}
			if ( labelEl ) {
				labelEl.textContent = label;
			}
			if ( launcher ) {
				launcher.classList.toggle( 'has-label', '' !== label.trim() );
			}
		};

		[ 'accent', 'secondary', 'surface', 'corner_style', 'bubble_style', 'launcher_label', 'assistant', 'avatar_url' ].forEach( function ( key ) {
			var el = field( key );
			if ( el ) {
				el.addEventListener( 'input', paint );
				el.addEventListener( 'change', paint );
			}
		} );

		paint();
	}
} )();

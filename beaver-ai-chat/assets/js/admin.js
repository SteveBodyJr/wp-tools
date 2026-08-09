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

	/* ------------------------------------------------------------- API key */

	/*
	 * The key input is disabled until "Change key" is pressed, so a browser or
	 * password manager cannot autofill it and saving another setting cannot
	 * overwrite a working key. Enabling it is the only way it joins the form.
	 */
	var keyView = document.querySelector( '[data-bac-key-view]' );
	var keyEdit = document.querySelector( '[data-bac-key-edit]' );
	var keyInput = document.getElementById( 'bac-api-key' );

	function showKeyEditor( show ) {
		if ( ! keyView || ! keyEdit || ! keyInput ) {
			return;
		}

		keyView.hidden = show;
		keyEdit.hidden = ! show;
		keyInput.disabled = ! show;

		if ( show ) {
			keyInput.value = '';
			keyInput.focus();
		}
	}

	var keyChange = document.querySelector( '[data-bac-key-change]' );
	if ( keyChange ) {
		keyChange.addEventListener( 'click', function () {
			showKeyEditor( true );
		} );
	}

	var keyCancel = document.querySelector( '[data-bac-key-cancel]' );
	if ( keyCancel ) {
		keyCancel.addEventListener( 'click', function () {
			showKeyEditor( false );
		} );
	}

	// A password manager can still fill the box the moment it is revealed.
	// Clear anything that appears without a keystroke, so nothing is saved
	// that the person did not actually type or paste.
	if ( keyInput ) {
		var typed = false;
		keyInput.addEventListener( 'keydown', function () { typed = true; } );
		keyInput.addEventListener( 'paste', function () { typed = true; } );

		window.setTimeout( function () {
			if ( ! typed && ! keyInput.disabled && keyInput.value !== '' ) {
				keyInput.value = '';
			}
		}, 600 );
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
			out.className = 'bac-test-out is-busy';
			out.textContent = busyText;
			btn.disabled = true;

			var data = new window.FormData();
			data.append( 'action', action );
			data.append( '_ajax_nonce', nonce );

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
} )();

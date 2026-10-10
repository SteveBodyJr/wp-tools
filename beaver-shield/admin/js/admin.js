/**
 * Beaver Shield — admin.
 *
 * Confirmations for the three settings that can cost something, and a busy
 * state on the scan button so a slow loopback request does not read as a dead
 * page. Nothing else.
 */
( function () {
	'use strict';

	var cfg = window.beaverShieldAdmin || {};
	var i18n = cfg.i18n || {};

	var prompts = {
		login: i18n.confirmLogin,
		enforce: i18n.confirmEnforce,
		xmlrpc: i18n.confirmXmlrpc
	};

	/**
	 * Confirms before a switch that can break something is turned on.
	 *
	 * Only on the way on. Turning one of these back off never needs a warning,
	 * because that is the direction that fixes things.
	 */
	document.addEventListener( 'change', function ( event ) {
		var field = event.target;

		if ( ! field || ! field.dataset || ! field.dataset.confirm ) {
			return;
		}

		var message = prompts[ field.dataset.confirm ];

		if ( ! message ) {
			return;
		}

		if ( field.type === 'checkbox' && ! field.checked ) {
			return;
		}

		if ( window.confirm( message ) ) {
			return;
		}

		if ( field.type === 'checkbox' ) {
			field.checked = false;
		} else if ( field.type === 'radio' ) {
			field.checked = false;
		} else {
			field.value = '';
		}
	} );

	// The login path is a text field, so it is confirmed when the form is sent
	// rather than on every keystroke.
	var login = document.getElementById( 'bs-login' );

	if ( login ) {
		var original = login.value;

		login.form.addEventListener( 'submit', function ( event ) {
			if ( login.value === original || login.value === '' ) {
				return;
			}

			if ( ! window.confirm( prompts.login ) ) {
				event.preventDefault();
			}
		} );
	}

	var scan = document.getElementById( 'beaver-shield-scan' );

	if ( scan ) {
		scan.form.addEventListener( 'submit', function () {
			scan.disabled = true;
			scan.textContent = i18n.scanning || 'Scanning…';
		} );
	}
}() );

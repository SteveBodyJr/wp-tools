/**
 * Beaver Access admin behaviour.
 *
 * @package BeaverAccess
 */

( function () {
	'use strict';

	var settings = window.beaverAccess || {};

	var button = document.getElementById( 'beaver-access-copy' );
	var field = document.getElementById( 'beaver-access-url' );
	var status = document.getElementById( 'beaver-access-copied' );

	if ( button && field ) {
		button.addEventListener( 'click', function () {
			function done( message ) {
				if ( status ) {
					status.textContent = message;
				}
			}

			// The clipboard API needs a secure context, which a staging site on
			// plain HTTP is not. Selecting the text always works.
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard
					.writeText( field.value )
					.then( function () {
						done( settings.copied );
					} )
					.catch( function () {
						field.select();
						done( settings.failed );
					} );

				return;
			}

			field.select();

			try {
				done( document.execCommand( 'copy' ) ? settings.copied : settings.failed );
			} catch ( e ) {
				done( settings.failed );
			}
		} );
	}

	// Expiry: the two custom rows only exist for the choice that uses them.
	var expiry = document.getElementById( 'ba-minutes' );
	var byLength = document.getElementById( 'ba-custom-length' );
	var byTime = document.getElementById( 'ba-custom-until' );

	if ( expiry && byLength && byTime ) {
		var syncExpiry = function ( focus ) {
			var choice = expiry.value;

			byLength.hidden = 'custom' !== choice;
			byTime.hidden = 'until' !== choice;

			if ( ! focus ) {
				return;
			}

			var revealed = byLength.hidden ? ( byTime.hidden ? null : byTime ) : byLength;

			if ( revealed ) {
				var input = revealed.querySelector( 'input' );

				if ( input ) {
					input.focus();
				}
			}
		};

		expiry.addEventListener( 'change', function () {
			syncExpiry( true );
		} );

		// PHP renders the correct starting state; this only keeps the two in
		// step if the browser restores an earlier selection on a back button.
		syncExpiry( false );
	}

	// Revoking signs people out mid-session, so it always asks first.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest
			? event.target.closest( '.beaver-access-revoke, #beaver-access-revoke-all' )
			: null;

		if ( ! link ) {
			return;
		}

		var message = 'beaver-access-revoke-all' === link.id ? settings.all : settings.confirm;

		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} )();

/**
 * Beaver Debug admin behaviour.
 *
 * @package BeaverDebug
 */

( function () {
	'use strict';

	var settings = window.beaverDebug || {};
	var button = document.getElementById( 'beaver-debug-copy' );
	var field = document.getElementById( 'beaver-debug-report' );
	var status = document.getElementById( 'beaver-debug-copied' );

	if ( ! button || ! field ) {
		return;
	}

	button.addEventListener( 'click', function () {
		function done( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		// The clipboard API needs a secure context, which a local or
		// plain-HTTP staging site is not. Selecting the text always works.
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
} )();

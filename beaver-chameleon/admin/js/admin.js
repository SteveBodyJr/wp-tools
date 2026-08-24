/**
 * Beaver Chameleon — admin behaviour.
 *
 * One job: make sure "Reset statistics" is a deliberate click, not a stray
 * one, since it cannot be undone. Everything else on the screen is a plain
 * form or a plain link and needs nothing from here.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'beaver-chameleon-reset-form' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			var message = ( window.beaverChameleon && window.beaverChameleon.confirmReset )
				? window.beaverChameleon.confirmReset
				: 'Reset all statistics?';

			if ( ! window.confirm( message ) ) { // eslint-disable-line no-alert
				event.preventDefault();
			}
		} );
	} );
} )();

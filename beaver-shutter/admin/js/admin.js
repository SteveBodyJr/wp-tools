/**
 * Beaver Shutter admin behaviour.
 *
 * A confirmation on the two steps that are worth a second thought: taking the
 * whole site dark, and deleting the record.
 */
( function () {
	'use strict';

	var i18n = ( window.beaverShutter && window.beaverShutter.i18n ) || {};

	/**
	 * Warns the first time the "Dark" level is chosen.
	 */
	function confirmDark() {
		var radio = document.querySelector( '[data-confirm="dark"]' );

		if ( ! radio ) {
			return;
		}

		radio.addEventListener( 'change', function () {
			if ( radio.checked && i18n.confirmDark && ! window.confirm( i18n.confirmDark ) ) {
				radio.checked = false;
			}
		} );
	}

	/**
	 * Asks before clearing the log.
	 */
	function confirmClear() {
		var button = document.getElementById( 'beaver-shutter-clear-log' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function ( event ) {
			if ( i18n.clearLog && ! window.confirm( i18n.clearLog ) ) {
				event.preventDefault();
			}
		} );
	}

	confirmDark();
	confirmClear();
}() );

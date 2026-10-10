/**
 * Beaver Shield — front end.
 *
 * Deliberately small and deliberately limited. Two listeners, no polling, no
 * timers, no attempt to detect developer tools. Nothing here blocks a keyboard
 * shortcut it cannot actually block, and nothing changes what a crawler reads,
 * because a crawler never runs any of it.
 */
( function () {
	'use strict';

	var cfg = window.beaverShield || {};

	/**
	 * Whether a node is somewhere a visitor is entitled to select and copy.
	 *
	 * Form fields are the case that matters. Anything else is opt in through a
	 * beaver-shield-allow class, which is how a theme marks a code sample or a
	 * quotable pull quote as fair game.
	 *
	 * @param {Node} node Event target.
	 * @return {boolean} True when the node is exempt.
	 */
	function exempt( node ) {
		var el = node && node.nodeType === 3 ? node.parentNode : node;

		while ( el && el.nodeType === 1 ) {
			var tag = el.tagName;

			if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'CODE' || tag === 'PRE' ) {
				return true;
			}

			if ( el.isContentEditable ) {
				return true;
			}

			if ( el.classList && el.classList.contains( 'beaver-shield-allow' ) ) {
				return true;
			}

			el = el.parentNode;
		}

		return false;
	}

	if ( cfg.text ) {
		document.addEventListener( 'copy', function ( event ) {
			if ( exempt( event.target ) ) {
				return;
			}

			if ( cfg.mode !== 'attribute' ) {
				event.preventDefault();

				return;
			}

			// Let the copy through, carrying a line back to where it came from.
			// Someone quoting the page still can, and the quote arrives with a
			// source attached, which is worth more than the argument.
			var selection = window.getSelection ? String( window.getSelection() ) : '';

			if ( ! selection || ! event.clipboardData ) {
				return;
			}

			event.clipboardData.setData( 'text/plain', selection + '\n\n' + cfg.source );
			event.preventDefault();
		} );

		document.addEventListener( 'cut', function ( event ) {
			if ( ! exempt( event.target ) ) {
				event.preventDefault();
			}
		} );
	}

	if ( cfg.images ) {
		document.addEventListener( 'dragstart', function ( event ) {
			if ( event.target && event.target.tagName === 'IMG' ) {
				event.preventDefault();
			}
		} );

		// Only over an image. A page that swallows every right click takes away
		// back, reload, open in new tab and translate, which visitors notice and
		// competitors do not.
		document.addEventListener( 'contextmenu', function ( event ) {
			if ( event.target && event.target.tagName === 'IMG' ) {
				event.preventDefault();
			}
		} );
	}
}() );

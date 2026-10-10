/**
 * Beaver Translate admin script.
 *
 * Drives the Generate / Regenerate / Approve buttons in the Translations
 * panel. Each row swaps its own markup in from the server's response, so the
 * badge, the error message and the available actions always reflect what
 * actually happened rather than a guess made in the browser.
 */
( function () {
	'use strict';

	if ( typeof window.beaverTranslate === 'undefined' ) {
		return;
	}

	var settings = window.beaverTranslate;

	/**
	 * Posts to admin-ajax and returns the decoded payload.
	 *
	 * @param {string} action Admin AJAX action, without the plugin prefix.
	 * @param {Object} data   Extra body fields.
	 * @return {Promise<Object>} Resolved with the `data` member of the response.
	 */
	function request( action, data ) {
		var body = new FormData();

		body.append( 'action', 'beaver_translate_' + action );
		body.append( 'nonce', settings.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message ? payload.data.message : settings.i18n.failed;

					throw new Error( message );
				}

				return payload.data;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var generateButton = event.target.closest ? event.target.closest( '[data-beaver-translate-generate]' ) : null;
		var approveButton = event.target.closest ? event.target.closest( '[data-beaver-translate-approve]' ) : null;
		var button = generateButton || approveButton;

		if ( ! button ) {
			return;
		}

		var row = button.closest( '[data-beaver-translate-post]' );

		if ( ! row ) {
			return;
		}

		var postId = row.getAttribute( 'data-beaver-translate-post' );
		var language = row.getAttribute( 'data-beaver-translate-language' );
		var action = generateButton ? 'generate' : 'approve';

		if ( 'approve' === action && ! window.confirm( settings.i18n.confirmApprove ) ) {
			return;
		}

		button.disabled = true;
		var status = row.querySelector( '[data-beaver-translate-status]' );

		if ( status && 'generate' === action ) {
			status.textContent = settings.i18n.working;
		}

		request( action, { post_id: postId, language: language } )
			.then( function ( data ) {
				/*
				 * "Approve" can be clicked from two different places: a row
				 * inside the language list on the source post, or a single
				 * button with no list around it on the translated draft's
				 * own screen. The row markup the server sends back only
				 * makes sense in the first case — swapping it into the
				 * second would drop a stray <li> into a <p>. A full reload
				 * is correct either way and a big status change like this
				 * is worth showing plainly rather than patched in place.
				 */
				if ( 'generate' === action && data.html ) {
					row.outerHTML = data.html;

					return;
				}

				window.location.reload();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				window.alert( error.message );
			} );
	} );
} )();

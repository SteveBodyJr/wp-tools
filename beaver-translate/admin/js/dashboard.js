/**
 * Beaver Translate dashboard script.
 *
 * "Generate remaining" scans once for the untranslated post IDs in a
 * language, then translates them one at a time — never in parallel. Each
 * AI call already takes real seconds; firing several at once per language
 * would only make it more likely two land on the same post at once, which
 * is exactly the kind of race this plugin does not yet have a lock against.
 * A slow, sequential loop the browser can Stop at any point is the safe
 * default until it does.
 */
( function () {
	'use strict';

	if ( typeof window.beaverTranslateDashboard === 'undefined' ) {
		return;
	}

	var settings = window.beaverTranslateDashboard;
	var running = false;
	var stopRequested = false;

	var progress = document.getElementById( 'beaver-translate-dashboard-progress' );
	var progressFill = document.getElementById( 'beaver-translate-dashboard-progress-fill' );
	var progressText = document.getElementById( 'beaver-translate-dashboard-progress-text' );
	var stopButton = document.getElementById( 'beaver-translate-dashboard-stop' );
	var notice = document.getElementById( 'beaver-translate-dashboard-notice' );

	/**
	 * Posts to admin-ajax and returns the decoded payload.
	 *
	 * @param {string} action Admin AJAX action, without the plugin prefix.
	 * @param {Object} data   Extra body fields.
	 * @param {string} nonce  Nonce for this specific action.
	 * @return {Promise<Object>} Resolved with the `data` member of the response.
	 */
	function request( action, data, nonce ) {
		var body = new FormData();

		body.append( 'action', 'beaver_translate_' + action );
		body.append( 'nonce', nonce );

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

	/**
	 * Shows a message in the inline notice box.
	 *
	 * @param {string} message Text to display.
	 * @param {string} type    One of 'success', 'error' or 'info'.
	 */
	function notify( message, type ) {
		if ( ! notice ) {
			return;
		}

		notice.textContent = message;
		notice.className = 'beaver-translate-inline-notice' + ( type ? ' beaver-translate-inline-notice--' + type : '' );
		notice.hidden = false;
	}

	/**
	 * Updates the progress bar.
	 *
	 * @param {number} done  Items completed.
	 * @param {number} total Items in this run.
	 * @param {string} label Status line.
	 */
	function setProgress( done, total, label ) {
		if ( ! progress ) {
			return;
		}

		var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;

		progress.hidden = false;
		progressFill.style.width = percent + '%';
		progressText.textContent = label + ' ' + done + ' / ' + total + ' (' + percent + '%)';
	}

	/**
	 * Refreshes one language row's counts after the run touches it, without
	 * a full page reload.
	 *
	 * @param {HTMLElement} row Table row for the language just processed.
	 */
	function markRowDone( row ) {
		var actionCell = row.querySelector( '[data-beaver-translate-dashboard-action]' );

		if ( actionCell ) {
			actionCell.innerHTML = '';
		}
	}

	/**
	 * Runs the queue for one language, one post at a time.
	 *
	 * @param {string}        language Language code.
	 * @param {Array<number>} ids      Remaining post IDs.
	 * @param {number}        total    Total this run started with.
	 * @param {number}        done     Completed so far.
	 * @param {HTMLElement}   row      The language's table row.
	 */
	function runQueue( language, ids, total, done, row ) {
		if ( stopRequested || ! ids.length ) {
			running = false;
			stopButton.hidden = true;

			if ( stopRequested ) {
				setProgress( done, total, settings.i18n.complete );
				notify( settings.i18n.complete, 'info' );
			} else {
				setProgress( total, total, settings.i18n.complete );
				notify( settings.i18n.complete, 'success' );
				markRowDone( row );
			}

			return;
		}

		var postId = ids[ 0 ];
		var remaining = ids.slice( 1 );

		setProgress( done, total, settings.i18n.working );

		request( 'generate', { post_id: postId, language: language }, settings.generateNonce )
			.then( function () {
				runQueue( language, remaining, total, done + 1, row );
			} )
			.catch( function () {
				// One bad post should not stop a run of hundreds — it is
				// already recorded as failed and shows up on the next scan.
				runQueue( language, remaining, total, done + 1, row );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-beaver-translate-bulk-generate]' ) : null;

		if ( ! button || running ) {
			return;
		}

		var row = button.closest( '[data-beaver-translate-language]' );

		if ( ! row ) {
			return;
		}

		var language = row.getAttribute( 'data-beaver-translate-language' );

		running = true;
		stopRequested = false;
		button.disabled = true;
		setProgress( 0, 0, settings.i18n.scanning );

		request( 'dashboard_scan', { language: language }, settings.scanNonce )
			.then( function ( data ) {
				if ( ! data.total ) {
					running = false;
					button.disabled = false;
					notify( settings.i18n.none, 'info' );

					return;
				}

				stopButton.hidden = false;
				runQueue( language, data.ids, data.total, 0, row );
			} )
			.catch( function ( error ) {
				running = false;
				button.disabled = false;
				notify( error.message, 'error' );
			} );
	} );

	if ( stopButton ) {
		stopButton.addEventListener( 'click', function () {
			stopRequested = true;
			stopButton.hidden = true;
		} );
	}
} )();

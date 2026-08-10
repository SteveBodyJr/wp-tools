/**
 * Beaver Sync admin behaviour.
 *
 * Two jobs: hide the "which live site" fields when they do not apply, and walk
 * the download queue a batch at a time. The batching is the point. A library of
 * three thousand images cannot arrive inside one PHP request on shared hosting,
 * so the browser asks for a handful, waits, and asks again.
 */
( function () {
	'use strict';

	var config = window.beaverSync || {};
	var i18n = config.i18n || {};

	/**
	 * The live-site fields only mean anything when this site is the copy.
	 */
	function roleFields() {
		var block = document.querySelector( '.beaver-sync-copyonly' );
		var radios = document.querySelectorAll( 'input[name="role"]' );

		if ( ! block || ! radios.length ) {
			return;
		}

		function sync() {
			var picked = document.querySelector( 'input[name="role"]:checked' );

			block.hidden = ! picked || 'copy' !== picked.value;
		}

		Array.prototype.forEach.call( radios, function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );

		sync();
	}

	/**
	 * Walks the queue until the server says it is finished.
	 */
	function runner() {
		var box = document.querySelector( '[data-beaver-sync-run]' );

		if ( ! box ) {
			return;
		}

		var button = box.querySelector( '[data-beaver-sync-go]' );
		var status = box.querySelector( '[data-beaver-sync-status]' );
		var bar = box.querySelector( '[data-beaver-sync-bar]' );
		var failed = box.querySelector( '[data-beaver-sync-failed]' );
		var running = false;

		function report( data ) {
			if ( status && i18n.progress ) {
				status.textContent = i18n.progress
					.replace( '%1$d', data.done )
					.replace( '%2$d', data.total );
			}

			if ( bar && data.total ) {
				bar.style.width = Math.round( ( 100 * data.done ) / data.total ) + '%';
			}

			if ( ! failed || ! data.failed ) {
				return;
			}

			// Rebuilt rather than appended to, so a file that succeeds on a
			// later pass does not stay on the list looking like a failure.
			failed.textContent = '';

			Object.keys( data.failed ).forEach( function ( path ) {
				var li = document.createElement( 'li' );

				li.textContent = path + ': ' + data.failed[ path ];
				failed.appendChild( li );
			} );
		}

		function stop( message ) {
			running = false;
			button.disabled = false;
			button.textContent = message;
		}

		function step() {
			var body = new FormData();

			body.append( 'action', 'beaver_sync_batch' );
			body.append( '_ajax_nonce', config.nonce );

			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					stop( ( i18n.failed || '' ) + ( ( payload && payload.data && payload.data.message ) || '' ) );

					return;
				}

				report( payload.data );

				if ( payload.data.finished ) {
					stop( i18n.done || 'Finished' );
					// The screen carries the run's own summary, so reload to
					// show the finished state rather than faking it here.
					window.setTimeout( function () {
						window.location.reload();
					}, 800 );

					return;
				}

				step();
			} ).catch( function ( e ) {
				stop( ( i18n.failed || '' ) + e.message );
			} );
		}

		button.addEventListener( 'click', function () {
			if ( running ) {
				return;
			}

			running = true;
			button.disabled = true;
			button.textContent = i18n.working || 'Working';
			step();
		} );
	}

	roleFields();
	runner();
}() );

/**
 * Beaver Image Optimizer admin script.
 *
 * Drives bulk optimization as a sequence of small AJAX requests. Keeping the
 * loop in the browser means a shared host never has to hold one long PHP
 * request open, and the job can be stopped and resumed at any point.
 */
( function () {
	'use strict';

	if ( typeof window.beaverIO === 'undefined' ) {
		return;
	}

	var settings = window.beaverIO;
	var running = false;
	var stopRequested = false;

	/**
	 * Posts to admin-ajax and returns the decoded payload.
	 *
	 * @param {string} action Admin AJAX action, without the plugin prefix.
	 * @param {Object} data   Extra body fields.
	 * @return {Promise<Object>} Resolved with the `data` member of the response.
	 */
	function request( action, data ) {
		var body = new FormData();

		body.append( 'action', 'beaver_io_' + action );
		body.append( 'nonce', settings.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( 'HTTP ' + response.status );
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: settings.i18n.failed;

					throw new Error( message );
				}

				return payload.data;
			} );
	}

	/**
	 * Shows a short message inside an inline notice box.
	 *
	 * @param {string} id      Element ID.
	 * @param {string} message Text to display.
	 * @param {string} type    One of 'success', 'error' or 'info'.
	 */
	function notify( id, message, type ) {
		var box = document.getElementById( id );

		if ( ! box ) {
			return;
		}

		box.textContent = message;
		box.className = 'beaver-io-inline-notice' + ( type ? ' beaver-io-inline-notice--' + type : '' );
		box.hidden = false;
	}

	/* --------------------------------------------------------------------
	 * Bulk optimization
	 * ----------------------------------------------------------------- */

	var startButton = document.getElementById( 'beaver-io-start' );
	var forceButton = document.getElementById( 'beaver-io-force' );
	var stopButton = document.getElementById( 'beaver-io-stop' );
	var progress = document.getElementById( 'beaver-io-progress' );
	var progressFill = document.getElementById( 'beaver-io-progress-fill' );
	var progressText = document.getElementById( 'beaver-io-progress-text' );
	var log = document.getElementById( 'beaver-io-log' );

	/**
	 * Updates the progress bar.
	 *
	 * @param {number} done  Items completed.
	 * @param {number} total Items in the queue.
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
	 * Appends processed items to the on-screen log.
	 *
	 * @param {Array} items Log entries returned by the batch endpoint.
	 */
	function appendLog( items ) {
		if ( ! log || ! items || ! items.length ) {
			return;
		}

		log.hidden = false;

		items.forEach( function ( item ) {
			var row = document.createElement( 'div' );
			row.className = 'beaver-io-log__row beaver-io-log__row--' + item.status;

			var name = document.createElement( 'span' );
			name.className = 'beaver-io-log__name';
			name.textContent = item.title || '#' + item.id;
			name.title = item.message || '';

			var meta = document.createElement( 'span' );
			meta.className = 'beaver-io-log__meta';
			meta.textContent = item.saved > 0
				? formatBytes( item.saved ) + ' ' + settings.i18n.saved
				: item.message;

			row.appendChild( name );
			row.appendChild( meta );
			log.appendChild( row );
		} );

		log.scrollTop = log.scrollHeight;
	}

	/**
	 * Formats a byte count for display.
	 *
	 * @param {number} bytes Byte count.
	 * @return {string} Human readable size.
	 */
	function formatBytes( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var index = 0;
		var value = Number( bytes ) || 0;

		while ( value >= 1024 && index < units.length - 1 ) {
			value /= 1024;
			index++;
		}

		return ( index === 0 ? value : value.toFixed( 1 ) ) + ' ' + units[ index ];
	}

	/**
	 * Refreshes the dashboard statistic cards in place.
	 *
	 * @param {Object} stats Formatted statistics payload.
	 */
	function refreshStats( stats ) {
		if ( ! stats ) {
			return;
		}

		Object.keys( stats ).forEach( function ( key ) {
			var node = document.querySelector( '[data-stat="' + key + '"]' );

			if ( node ) {
				node.textContent = stats[ key ];
			}
		} );
	}

	/**
	 * Toggles the control buttons for the running state.
	 *
	 * @param {boolean} active Whether a job is in flight.
	 */
	function setRunning( active ) {
		running = active;

		if ( startButton ) {
			startButton.disabled = active;
		}

		if ( forceButton ) {
			forceButton.disabled = active;
		}

		if ( stopButton ) {
			stopButton.hidden = ! active;
		}
	}

	/**
	 * Runs batches until the queue is empty, an error occurs, or Stop is pressed.
	 *
	 * @param {number} total Queue length.
	 * @param {number} done  Items already completed.
	 */
	function runBatches( total, done, retries ) {
		if ( stopRequested ) {
			setRunning( false );
			setProgress( done, total, settings.i18n.cancelled );
			return;
		}

		retries = retries || 0;

		request( 'batch', {} )
			.then( function ( data ) {
				appendLog( data.items );
				refreshStats( data.stats );
				setProgress( data.done, data.total || total, settings.i18n.working );

				if ( data.complete ) {
					setRunning( false );
					setProgress( data.total, data.total, settings.i18n.complete );

					if ( startButton ) {
						startButton.dataset.resume = '0';
					}

					return;
				}

				runBatches( data.total || total, data.done, 0 );
			} )
			.catch( function ( error ) {
				/*
				 * A batch can still die outright — a memory limit hit hard
				 * enough leaves no room even for the shutdown handler. The
				 * server records which image was in flight and drops it from
				 * the queue, so retrying moves on to the next one instead of
				 * repeating the crash. Give up only if the retry also fails,
				 * which means the problem is not a single bad image.
				 */
				if ( retries < 1 ) {
					notify( 'beaver-io-tools-notice', settings.i18n.recovering, 'error' );
					runBatches( total, done, retries + 1 );
					return;
				}

				setRunning( false );
				notify( 'beaver-io-tools-notice', error.message, 'error' );
			} );
	}

	/**
	 * Scans the library and starts the batch loop.
	 *
	 * @param {boolean} force  Reconvert images that are already optimized.
	 * @param {boolean} resume Continue an existing queue when one is present.
	 */
	function start( force, resume ) {
		if ( running ) {
			return;
		}

		stopRequested = false;
		setRunning( true );
		setProgress( 0, 0, settings.i18n.scanning );

		if ( log ) {
			log.innerHTML = '';
			log.hidden = true;
		}

		request( 'scan', { force: force ? 1 : 0, resume: resume ? 1 : 0 } )
			.then( function ( data ) {
				if ( ! data.total || data.remaining === 0 ) {
					setRunning( false );
					setProgress( data.total || 0, data.total || 0, settings.i18n.complete );
					notify( 'beaver-io-tools-notice', settings.i18n.noImages, 'info' );
					return;
				}

				setProgress( data.done, data.total, settings.i18n.working );
				runBatches( data.total, data.done );
			} )
			.catch( function ( error ) {
				setRunning( false );
				notify( 'beaver-io-tools-notice', error.message, 'error' );
			} );
	}

	if ( startButton ) {
		startButton.addEventListener( 'click', function () {
			start( false, startButton.dataset.resume === '1' );
		} );
	}

	if ( forceButton ) {
		forceButton.addEventListener( 'click', function () {
			if ( window.confirm( settings.i18n.confirm ) ) {
				start( true, false );
			}
		} );
	}

	if ( stopButton ) {
		stopButton.addEventListener( 'click', function () {
			stopRequested = true;
			stopButton.hidden = true;
		} );
	}

	/* --------------------------------------------------------------------
	 * Maintenance and health actions
	 * ----------------------------------------------------------------- */

	var resetButton = document.getElementById( 'beaver-io-reset-stats' );

	if ( resetButton ) {
		resetButton.addEventListener( 'click', function () {
			resetButton.disabled = true;

			request( 'reset_stats', {} )
				.then( function ( data ) {
					refreshStats( data.stats );
					notify( 'beaver-io-tools-notice', settings.i18n.complete, 'success' );
				} )
				.catch( function ( error ) {
					notify( 'beaver-io-tools-notice', error.message, 'error' );
				} )
				.finally( function () {
					resetButton.disabled = false;
				} );
		} );
	}

	var testButton = document.getElementById( 'beaver-io-test-delivery' );

	if ( testButton ) {
		testButton.addEventListener( 'click', function () {
			testButton.disabled = true;
			notify( 'beaver-io-health-notice', settings.i18n.testing, 'info' );

			request( 'test_delivery', {} )
				.then( function ( data ) {
					notify(
						'beaver-io-health-notice',
						data.message,
						data.status === 'working' ? 'success' : 'error'
					);
				} )
				.catch( function ( error ) {
					notify( 'beaver-io-health-notice', error.message, 'error' );
				} )
				.finally( function () {
					testButton.disabled = false;
				} );
		} );
	}

	var rulesButton = document.getElementById( 'beaver-io-write-rules' );

	if ( rulesButton ) {
		rulesButton.addEventListener( 'click', function () {
			rulesButton.disabled = true;

			request( 'write_rules', {} )
				.then( function ( data ) {
					notify( 'beaver-io-health-notice', data.message, data.written ? 'success' : 'error' );
				} )
				.catch( function ( error ) {
					notify( 'beaver-io-health-notice', error.message, 'error' );
				} )
				.finally( function () {
					rulesButton.disabled = false;
				} );
		} );
	}

	/* --------------------------------------------------------------------
	 * Media library row actions
	 * ----------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.beaver-io-row-action' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var cell = button.closest( '.column-beaver_io' ) || button.parentNode;
		var original = button.textContent;

		button.disabled = true;
		button.textContent = settings.i18n.working;

		request( 'single', { id: button.dataset.id, mode: button.dataset.mode } )
			.then( function ( data ) {
				if ( cell ) {
					cell.innerHTML = data.html;
				}
			} )
			.catch( function ( error ) {
				button.disabled = false;
				button.textContent = original;
				window.alert( error.message );
			} );
	} );
} )();

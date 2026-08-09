/**
 * Beaver Alt Text admin behaviour.
 *
 * @package BeaverAltText
 */

( function () {
	'use strict';

	var settings = window.beaverAlt || {};

	if ( ! settings.ajaxUrl ) {
		return;
	}

	var running = false;
	var stopRequested = false;

	/**
	 * Posts to admin-ajax and unwraps the JSON envelope.
	 *
	 * @param {string} action Action suffix.
	 * @param {Object} data   Extra fields.
	 * @return {Promise<Object>} Resolved payload.
	 */
	function request( action, data ) {
		var body = new FormData();

		body.append( 'action', 'beaver_alt_' + action );
		body.append( 'nonce', settings.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( 'HTTP ' + response.status );
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message =
						payload && payload.data && payload.data.message
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
	 * @param {string} id      Element id.
	 * @param {string} message Text.
	 * @param {string} type    'error' or 'success'.
	 */
	function notify( id, message, type ) {
		var box = document.getElementById( id );

		if ( ! box ) {
			return;
		}

		box.hidden = false;
		box.textContent = message;
		box.className = 'beaver-alt-inline-notice is-' + ( type || 'info' );
	}

	/* --------------------------------------------------------------------
	 * Bulk run
	 * ----------------------------------------------------------------- */

	var startButton = document.getElementById( 'beaver-alt-start' );
	var forceButton = document.getElementById( 'beaver-alt-force' );
	var stopButton = document.getElementById( 'beaver-alt-stop' );
	var resetButton = document.getElementById( 'beaver-alt-reset' );
	var progress = document.getElementById( 'beaver-alt-progress' );
	var fill = document.getElementById( 'beaver-alt-progress-fill' );
	var label = document.getElementById( 'beaver-alt-progress-label' );
	var log = document.getElementById( 'beaver-alt-log' );

	/**
	 * Updates the progress bar.
	 *
	 * @param {number} done  Completed items.
	 * @param {number} total Queue length.
	 * @param {string} text  Status text.
	 */
	function setProgress( done, total, text ) {
		if ( ! progress ) {
			return;
		}

		var percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;

		progress.hidden = false;
		fill.style.width = percent + '%';
		label.textContent = text + ' ' + done + ' / ' + total + ' (' + percent + '%)';
	}

	/**
	 * Toggles the running state of the controls.
	 *
	 * @param {boolean} active Whether a run is in progress.
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
	 * Appends result rows to the log.
	 *
	 * @param {Array} items Batch items.
	 */
	function appendLog( items ) {
		if ( ! log || ! items || ! items.length ) {
			return;
		}

		log.hidden = false;

		items.forEach( function ( item ) {
			var row = document.createElement( 'div' );
			row.className = 'beaver-alt-log__row beaver-alt-log__row--' + item.status;

			if ( item.thumb ) {
				var img = document.createElement( 'img' );
				img.className = 'beaver-alt-log__thumb';
				img.src = item.thumb;
				img.alt = '';
				row.appendChild( img );
			}

			var text = document.createElement( 'div' );
			text.className = 'beaver-alt-log__text';

			var name = document.createElement( 'strong' );
			name.textContent = item.title || '#' + item.id;
			text.appendChild( name );

			var detail = document.createElement( 'span' );
			detail.textContent = item.alt ? item.alt : item.message;
			text.appendChild( detail );

			row.appendChild( text );
			log.appendChild( row );
		} );

		log.scrollTop = log.scrollHeight;
	}

	/**
	 * Copies fresh counter values onto the cards.
	 *
	 * @param {Object} stats Stats payload.
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
	 * Runs batches until the queue is empty or Stop is pressed.
	 *
	 * @param {number} total   Queue length.
	 * @param {number} done    Items already completed.
	 * @param {number} retries Retries already spent on the current batch.
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
				if ( data.locked ) {
					setRunning( false );
					notify( 'beaver-alt-notice', settings.i18n.locked, 'error' );
					return;
				}

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
				 * repeating the crash.
				 */
				if ( retries < 1 ) {
					notify( 'beaver-alt-notice', settings.i18n.recovering, 'error' );
					runBatches( total, done, retries + 1 );
					return;
				}

				setRunning( false );
				notify( 'beaver-alt-notice', error.message, 'error' );
			} );
	}

	/**
	 * Scans the library and starts the batch loop.
	 *
	 * @param {boolean} force  Re-describe images already done.
	 * @param {boolean} resume Continue an existing queue.
	 */
	function start( force, resume ) {
		if ( running ) {
			return;
		}

		stopRequested = false;
		setRunning( true );
		notify( 'beaver-alt-notice', settings.i18n.scanning, 'info' );

		request( 'scan', { force: force ? 1 : 0, resume: resume ? 1 : 0 } )
			.then( function ( data ) {
				refreshStats( data.stats );

				if ( ! data.total ) {
					setRunning( false );
					notify( 'beaver-alt-notice', settings.i18n.none, 'success' );
					return;
				}

				/*
				 * Spending money should be a decision, not a side effect of
				 * pressing Start. Resuming skips the prompt — the run was
				 * already agreed to.
				 */
				if ( data.estimate && ! resume ) {
					if ( ! window.confirm( data.estimate + '\n\n' + settings.i18n.proceed ) ) {
						setRunning( false );
						notify( 'beaver-alt-notice', settings.i18n.cancelled, 'info' );
						return;
					}
				}

				notify( 'beaver-alt-notice', settings.i18n.working, 'info' );
				setProgress( data.done, data.total, settings.i18n.working );
				runBatches( data.total, data.done, 0 );
			} )
			.catch( function ( error ) {
				setRunning( false );
				notify( 'beaver-alt-notice', error.message, 'error' );
			} );
	}

	if ( startButton ) {
		startButton.addEventListener( 'click', function () {
			start( false, '1' === startButton.dataset.resume );
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
			request( 'cancel', {} ).catch( function () {} );
		} );
	}

	if ( resetButton ) {
		resetButton.addEventListener( 'click', function () {
			request( 'reset_stats', {} )
				.then( function ( data ) {
					refreshStats( data.stats );
				} )
				.catch( function ( error ) {
					notify( 'beaver-alt-notice', error.message, 'error' );
				} );
		} );
	}

	/* --------------------------------------------------------------------
	 * Review queue
	 * ----------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest
			? event.target.closest( '.beaver-alt-approve, .beaver-alt-reject' )
			: null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var row = button.closest( '.beaver-alt-review__row' );

		if ( ! row ) {
			return;
		}

		var input = row.querySelector( '.beaver-alt-review__input' );
		var status = row.querySelector( '.beaver-alt-review__status' );
		var approve = button.classList.contains( 'beaver-alt-approve' );

		row.querySelectorAll( 'button' ).forEach( function ( node ) {
			node.disabled = true;
		} );

		if ( status ) {
			status.textContent = settings.i18n.working1;
		}

		request( 'decide', {
			id: row.dataset.id,
			decision: approve ? 'approve' : 'reject',
			alt: input ? input.value : '',
		} )
			.then( function ( data ) {
				row.classList.add( 'is-done' );

				if ( status ) {
					status.textContent = data.message;
				}

				var badge = document.querySelector( '[data-stat="pending"]' );

				if ( badge ) {
					badge.textContent = data.pending;
				}

				window.setTimeout( function () {
					row.remove();
				}, 600 );
			} )
			.catch( function ( error ) {
				row.querySelectorAll( 'button' ).forEach( function ( node ) {
					node.disabled = false;
				} );

				if ( status ) {
					status.textContent = error.message;
				}
			} );
	} );

	var bulkButton = document.getElementById( 'beaver-alt-bulk-approve' );

	if ( bulkButton ) {
		bulkButton.addEventListener( 'click', function () {
			var select = document.getElementById( 'beaver-alt-bulk-confidence' );
			var status = document.getElementById( 'beaver-alt-bulk-status' );

			bulkButton.disabled = true;

			if ( status ) {
				status.textContent = settings.i18n.working1;
			}

			request( 'bulk_approve', { confidence: select ? select.value : 'high' } )
				.then( function ( data ) {
					if ( status ) {
						status.textContent = data.message;
					}

					// Rows that were published are gone; the simplest honest
					// refresh is to reload rather than guess which survived.
					window.setTimeout( function () {
						window.location.reload();
					}, 900 );
				} )
				.catch( function ( error ) {
					bulkButton.disabled = false;

					if ( status ) {
						status.textContent = error.message;
					}
				} );
		} );
	}

	/* --------------------------------------------------------------------
	 * Media library row action
	 * ----------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest
			? event.target.closest( '.beaver-alt-row-action' )
			: null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var cell = button.closest( '.column-beaver_alt' ) || button.parentNode;
		var original = button.textContent;

		button.disabled = true;
		button.textContent = settings.i18n.working1;

		request( 'single', { id: button.dataset.id } )
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

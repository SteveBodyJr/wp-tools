/**
 * Beaver AI Chat - the conversation list and the live conversation view.
 */

/*
 * Working through the queue: change a conversation's status from the list
 * without a page reload, because reloading the list loses your place in it.
 */
( function () {
	'use strict';

	var L = window.BAC_LEAD || {};
	var selects = document.querySelectorAll( '.bac-status' );

	if ( ! L.ajaxUrl || ! L.statusNonce || ! selects.length ) {
		return;
	}

	Array.prototype.forEach.call( selects, function ( select ) {
		var note = select.parentNode.querySelector( '.bac-status-note' );

		select.addEventListener( 'change', function () {
			var previous = select.getAttribute( 'data-bac-current' );

			select.disabled = true;
			if ( note ) {
				note.className = 'bac-status-note is-busy';
				note.textContent = L.i18n && L.i18n.saving ? L.i18n.saving : 'Saving…';
			}

			var data = new window.FormData();
			data.append( 'action', 'bac_set_status' );
			data.append( 'nonce', L.statusNonce );
			data.append( 'lead', select.getAttribute( 'data-bac-lead' ) );
			data.append( 'status', select.value );

			window.fetch( L.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( j ) {
					select.disabled = false;

					if ( ! j || ! j.success ) {
						select.value = previous;
						if ( note ) {
							note.className = 'bac-status-note is-bad';
							note.textContent = ( j && j.data && j.data.message ) || ( L.i18n && L.i18n.failed ) || 'Failed';
						}
						return;
					}

					select.setAttribute( 'data-bac-current', select.value );

					// Keep the owner column honest without a reload.
					var row = select.closest( 'tr' );
					var owner = row ? row.querySelector( '.column-bac_owner' ) : null;
					if ( owner ) {
						owner.textContent = j.data.owner || '—';
					}

					if ( note ) {
						note.className = 'bac-status-note is-ok';
						note.textContent = '✓';
						window.setTimeout( function () {
							note.textContent = '';
							note.className = 'bac-status-note';
						}, 1600 );
					}
				} )
				.catch( function () {
					select.disabled = false;
					select.value = previous;
					if ( note ) {
						note.className = 'bac-status-note is-bad';
						note.textContent = ( L.i18n && L.i18n.failed ) || 'Failed';
					}
				} );
		} );
	} );
} )();

/*
 * Live conversation view.
 *
 * Keeps an open conversation up to date while the visitor is still typing, so
 * the team can follow a chat as it happens instead of waiting for it to end.
 * Polls gently, backs off once the chat goes quiet, and pauses entirely while
 * the tab is in the background.
 */
( function () {
	'use strict';

	var L = window.BAC_LEAD || {};

	if ( ! L.ajaxUrl || ! L.leadId ) {
		return;
	}

	var FAST = 8000;    // while the conversation is moving
	var SLOW = 30000;   // once it has gone quiet
	var GIVE_UP = 30 * 60 * 1000; // stop polling after half an hour idle

	var container = document.getElementById( 'bac-lead-live' );

	if ( ! container ) {
		return;
	}

	var lastModified = 0;
	var idleSince = Date.now();
	var timer = null;

	function schedule( delay ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( poll, delay );
	}

	function poll() {
		// Nothing to do while the tab is hidden; pick up again on focus.
		if ( document.hidden ) {
			schedule( FAST );
			return;
		}

		if ( Date.now() - idleSince > GIVE_UP ) {
			return;
		}

		var data = new window.FormData();
		data.append( 'action', 'bac_lead_poll' );
		data.append( 'nonce', L.nonce );
		data.append( 'lead', L.leadId );

		window.fetch( L.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success || ! j.data ) {
					schedule( SLOW );
					return;
				}

				var modified = parseInt( j.data.modified, 10 ) || 0;

				if ( modified !== lastModified ) {
					if ( lastModified !== 0 ) {
						render( j.data.html );
						flash();
					}
					lastModified = modified;
					idleSince = Date.now();
					schedule( FAST );
				} else {
					schedule( Date.now() - idleSince > 120000 ? SLOW : FAST );
				}
			} )
			.catch( function () {
				schedule( SLOW );
			} );
	}

	function render( html ) {
		var thread = container.querySelector( '.bac-thread' );
		var pinned = thread ? thread.scrollTop + thread.clientHeight >= thread.scrollHeight - 40 : true;

		container.innerHTML = html;

		var fresh = container.querySelector( '.bac-thread' );
		if ( fresh && pinned ) {
			fresh.scrollTop = fresh.scrollHeight;
		}
	}

	function flash() {
		container.classList.remove( 'is-updated' );
		// Force a reflow so the animation restarts on every update.
		void container.offsetWidth;
		container.classList.add( 'is-updated' );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			schedule( 500 );
		}
	} );

	// Start scrolled to the newest message.
	var thread = container.querySelector( '.bac-thread' );
	if ( thread ) {
		thread.scrollTop = thread.scrollHeight;
	}

	schedule( FAST );
} )();

/**
 * Beaver PWA admin behaviour.
 *
 * Plain DOM: the screens only need a media picker and three POST requests.
 */
( function ( config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var frame = null;

	function byId( id ) {
		return document.getElementById( id );
	}

	function feedback( message, state ) {
		var element = byId( 'beaver-pwa-feedback' );

		if ( ! element ) {
			return;
		}

		element.textContent = message;
		element.className = 'beaver-pwa-feedback' + ( state ? ' beaver-pwa-feedback--' + state : '' );
	}

	function post( action, done ) {
		var body = new window.FormData();

		body.append( 'action', action );
		body.append( 'nonce', config.nonce );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			done( payload );
		} ).catch( function () {
			done( { success: false, data: { message: config.i18n.failed } } );
		} );
	}

	function busy( button, state ) {
		if ( ! button ) {
			return;
		}

		button.disabled = state;
		button.classList.toggle( 'disabled', state );
	}

	/*
	 * Icon picker.
	 */
	function bindIconPicker() {
		var choose = byId( 'beaver-pwa-icon-choose' );
		var clear = byId( 'beaver-pwa-icon-clear' );
		var field = byId( 'beaver-pwa-icon-id' );
		var preview = byId( 'beaver-pwa-icon-preview' );

		if ( ! choose || ! field || ! window.wp || ! window.wp.media ) {
			return;
		}

		choose.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( ! frame ) {
				frame = window.wp.media( {
					title: config.i18n.chooseIcon,
					button: { text: config.i18n.useIcon },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var source = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

					field.value = attachment.id;

					if ( preview ) {
						preview.src = source;
						preview.hidden = false;
					}

					if ( clear ) {
						clear.hidden = false;
					}
				} );
			}

			frame.open();
		} );

		if ( clear ) {
			clear.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				field.value = '0';
				clear.hidden = true;

				if ( preview ) {
					preview.hidden = true;
				}
			} );
		}
	}

	/*
	 * Dashboard actions.
	 */
	function bindDashboard() {
		var recheck = byId( 'beaver-pwa-recheck' );
		var clear = byId( 'beaver-pwa-clear' );
		var rebuild = byId( 'beaver-pwa-regenerate' );

		if ( recheck ) {
			recheck.addEventListener( 'click', function () {
				var label = recheck.textContent;

				busy( recheck, true );
				recheck.textContent = config.i18n.checking;

				post( 'beaver_pwa_recheck', function ( payload ) {
					busy( recheck, false );
					recheck.textContent = label;

					if ( ! payload || ! payload.success ) {
						feedback( config.i18n.failed, 'error' );

						return;
					}

					var list = byId( 'beaver-pwa-checklist' );
					var status = byId( 'beaver-pwa-status' );

					if ( list ) {
						list.innerHTML = payload.data.html;
					}

					if ( status ) {
						status.classList.toggle( 'beaver-pwa-status--ready', !! payload.data.ready );
						status.classList.toggle( 'beaver-pwa-status--blocked', ! payload.data.ready );
					}
				} );
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				if ( ! window.confirm( config.i18n.confirmWipe ) ) {
					return;
				}

				busy( clear, true );
				feedback( config.i18n.working );

				post( 'beaver_pwa_clear_cache', function ( payload ) {
					busy( clear, false );

					if ( ! payload || ! payload.success ) {
						feedback( config.i18n.failed, 'error' );

						return;
					}

					var version = byId( 'beaver-pwa-version' );

					if ( version ) {
						version.textContent = payload.data.version;
					}

					feedback( payload.data.message, 'done' );
				} );
			} );
		}

		if ( rebuild ) {
			rebuild.addEventListener( 'click', function () {
				busy( rebuild, true );
				feedback( config.i18n.working );

				post( 'beaver_pwa_regenerate', function ( payload ) {
					busy( rebuild, false );

					if ( ! payload || ! payload.success ) {
						feedback( payload && payload.data && payload.data.message ? payload.data.message : config.i18n.failed, 'error' );

						return;
					}

					var icon = byId( 'beaver-pwa-preview-icon' );

					if ( icon && payload.data.preview ) {
						icon.src = payload.data.preview + '?t=' + Date.now();
					}

					feedback( payload.data.message, 'done' );
				} );
			} );
		}
	}

	function start() {
		bindIconPicker();
		bindDashboard();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( window.beaverPWAAdmin ) );

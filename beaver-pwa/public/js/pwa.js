/**
 * Beaver PWA front end.
 *
 * Registers the service worker and drives the install prompt. Nothing is shown
 * until the browser confirms the site can actually be installed, so the prompt
 * never appears where it would not work.
 */
( function ( config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var DISMISS_KEY = 'beaverPWA:dismissed';
	var deferred = null;
	var promptEl = null;
	var promptShown = false;
	var reloading = false;

	/**
	 * Page builders and the customiser render the site inside an iframe. A
	 * worker registered from there would cache editor output.
	 */
	function inEditor() {
		try {
			if ( window.top !== window.self ) {
				return true;
			}
		} catch ( error ) {
			return true;
		}

		return (
			window.location.search.indexOf( 'elementor-preview' ) !== -1 ||
			document.body.classList.contains( 'elementor-editor-active' ) ||
			!! window.wp && !! window.wp.customize && !! window.wp.customize.preview
		);
	}

	function isStandalone() {
		return (
			( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches ) ||
			( window.matchMedia && window.matchMedia( '(display-mode: fullscreen)' ).matches ) ||
			window.navigator.standalone === true ||
			document.referrer.indexOf( 'android-app://' ) === 0
		);
	}

	function isIOS() {
		var ua = window.navigator.userAgent;
		var iOS = /iPad|iPhone|iPod/.test( ua ) ||
			( window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1 );

		// Only Safari can add to the home screen on iOS.
		var safari = /Safari/.test( ua ) && ! /CriOS|FxiOS|EdgiOS|OPiOS|Chrome/.test( ua );

		return iOS && safari;
	}

	function readDismissal() {
		try {
			return parseInt( window.localStorage.getItem( DISMISS_KEY ), 10 ) || 0;
		} catch ( error ) {
			return 0;
		}
	}

	function writeDismissal( value ) {
		try {
			if ( value ) {
				window.localStorage.setItem( DISMISS_KEY, String( value ) );
			} else {
				window.localStorage.removeItem( DISMISS_KEY );
			}
		} catch ( error ) {}
	}

	function isDismissed() {
		var days = config.prompt.dismissDays;
		var at = readDismissal();

		if ( ! at ) {
			return false;
		}

		if ( ! days ) {
			// Zero days means the dismissal only lasts for this page view.
			return false;
		}

		return ( Date.now() - at ) < days * 86400000;
	}

	/*
	 * -------------------------------------------------------------------
	 * Service worker
	 * -------------------------------------------------------------------
	 */

	function register() {
		if ( ! ( 'serviceWorker' in window.navigator ) || ! window.isSecureContext ) {
			return;
		}

		if ( ! config.register || ! config.swUrl ) {
			return;
		}

		window.navigator.serviceWorker.register( config.swUrl, { scope: config.scope } )
			.then( watchForUpdates )
			.catch( function () {} );
	}

	function watchForUpdates( registration ) {
		if ( ! registration || ! config.updateToast ) {
			return;
		}

		if ( registration.waiting && window.navigator.serviceWorker.controller ) {
			showUpdateToast( registration );
		}

		registration.addEventListener( 'updatefound', function () {
			var installing = registration.installing;

			if ( ! installing ) {
				return;
			}

			installing.addEventListener( 'statechange', function () {
				// A controller already present means this is an update, not a
				// first install, so there is something worth refreshing for.
				if ( installing.state === 'installed' && window.navigator.serviceWorker.controller ) {
					showUpdateToast( registration );
				}
			} );
		} );
	}

	function showUpdateToast( registration ) {
		if ( document.getElementById( 'beaver-pwa-toast' ) ) {
			return;
		}

		var toast = document.createElement( 'div' );
		var text = document.createElement( 'span' );
		var action = document.createElement( 'button' );
		var close = document.createElement( 'button' );

		toast.className = 'beaver-pwa-toast';
		toast.id = 'beaver-pwa-toast';
		toast.setAttribute( 'role', 'status' );

		text.textContent = config.i18n.updateReady;

		action.type = 'button';
		action.className = 'beaver-pwa-toast__action';
		action.textContent = config.i18n.refresh;
		action.addEventListener( 'click', function () {
			if ( registration.waiting ) {
				registration.waiting.postMessage( { type: 'BPWA_SKIP_WAITING' } );
			} else {
				window.location.reload();
			}
		} );

		close.type = 'button';
		close.className = 'beaver-pwa-toast__close';
		close.setAttribute( 'aria-label', config.i18n.dismiss );
		close.textContent = '×';
		close.addEventListener( 'click', function () {
			toast.remove();
		} );

		toast.appendChild( text );
		toast.appendChild( action );
		toast.appendChild( close );
		document.body.appendChild( toast );

		window.navigator.serviceWorker.addEventListener( 'controllerchange', function () {
			if ( reloading ) {
				return;
			}

			reloading = true;
			window.location.reload();
		} );
	}

	/*
	 * -------------------------------------------------------------------
	 * Install prompt
	 * -------------------------------------------------------------------
	 */

	function installButtons() {
		return document.querySelectorAll( '[data-beaver-pwa-install]' );
	}

	function revealButtons() {
		var buttons = installButtons();

		for ( var index = 0; index < buttons.length; index++ ) {
			buttons[ index ].hidden = false;
		}
	}

	function hideButtons() {
		var buttons = installButtons();

		for ( var index = 0; index < buttons.length; index++ ) {
			buttons[ index ].hidden = true;
		}
	}

	function showPrompt( iosMode ) {
		if ( ! promptEl || promptShown || isDismissed() ) {
			return;
		}

		if ( iosMode ) {
			toggle( promptEl.querySelector( '[data-beaver-pwa-role="text"]' ), false );
			toggle( promptEl.querySelector( '[data-beaver-pwa-role="ios"]' ), true );
			toggle( promptEl.querySelector( '.beaver-pwa-prompt__action' ), false );
		}

		promptShown = true;
		promptEl.hidden = false;

		// Let the element paint before the transition starts.
		window.requestAnimationFrame( function () {
			promptEl.classList.add( 'is-visible' );
		} );
	}

	function hidePrompt( remember ) {
		if ( promptEl ) {
			promptEl.classList.remove( 'is-visible' );
			promptEl.hidden = true;
		}

		promptShown = false;

		if ( remember ) {
			writeDismissal( Date.now() );
		}
	}

	function toggle( element, visible ) {
		if ( element ) {
			element.hidden = ! visible;
		}
	}

	function requestInstall() {
		if ( ! deferred ) {
			if ( isIOS() ) {
				showPrompt( true );
			}

			return;
		}

		deferred.prompt();

		deferred.userChoice.then( function ( choice ) {
			if ( choice && choice.outcome !== 'accepted' ) {
				hidePrompt( true );
			} else {
				hidePrompt( false );
			}

			deferred = null;
			hideButtons();
		} ).catch( function () {} );
	}

	function bindPrompt() {
		promptEl = document.getElementById( 'beaver-pwa-prompt' );

		document.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( ! target || ! target.closest ) {
				return;
			}

			if ( target.closest( '[data-beaver-pwa-install]' ) ) {
				event.preventDefault();
				requestInstall();

				return;
			}

			if ( target.closest( '[data-beaver-pwa-close]' ) ) {
				event.preventDefault();
				hidePrompt( true );
			}
		} );

		window.addEventListener( 'beforeinstallprompt', function ( event ) {
			event.preventDefault();
			deferred = event;

			revealButtons();

			if ( config.prompt.enabled ) {
				window.setTimeout( function () {
					showPrompt( false );
				}, config.prompt.delay * 1000 );
			}
		} );

		window.addEventListener( 'appinstalled', function () {
			deferred = null;
			writeDismissal( 0 );
			hidePrompt( false );
			hideButtons();
			document.documentElement.classList.add( 'beaver-pwa-installed' );
		} );

		// iOS never fires beforeinstallprompt, so the hint is the only route.
		if ( config.prompt.iosHint && isIOS() ) {
			revealButtons();

			if ( config.prompt.enabled ) {
				window.setTimeout( function () {
					showPrompt( true );
				}, config.prompt.delay * 1000 );
			}
		}
	}

	function start() {
		if ( inEditor() ) {
			return;
		}

		if ( isStandalone() ) {
			// Already installed: mark the document so a theme can adapt, and
			// never ask again.
			document.documentElement.classList.add( 'beaver-pwa-standalone' );
			register();

			return;
		}

		register();
		bindPrompt();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}( window.beaverPWA ) );

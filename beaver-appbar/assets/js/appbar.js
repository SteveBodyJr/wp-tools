/**
 * Beaver App Bar — front end.
 *
 * Loaded only on requests that draw the bar, and every part of it exits early
 * if the thing it manages is not on the page. No dependencies, no framework,
 * nothing global left behind.
 */
( function () {
	'use strict';

	var bar;
	var items;
	var reduce = window.matchMedia && window.matchMedia( '( prefers-reduced-motion: reduce )' ).matches;

	/**
	 * Moves the page's bottom spacing onto the footer.
	 *
	 * The stylesheet pads the body, which always works but leaves a strip of
	 * page background under a dark footer. Where there is a footer with a
	 * background of its own, the padding goes there instead so the footer runs
	 * all the way to the bottom edge behind the bar. Inline, because a theme's
	 * own footer rule would otherwise win on specificity.
	 */
	function spaceFooter() {
		var foot = document.querySelector( 'footer.site-footer, #colophon, footer[role="contentinfo"], .site-footer, body > footer' );

		if ( ! foot ) {
			return;
		}

		var style = window.getComputedStyle( foot );

		// A transparent footer would show the same strip either way, so there is
		// nothing to gain by moving the padding onto it.
		if ( ! style.backgroundColor || 'rgba(0, 0, 0, 0)' === style.backgroundColor || 'transparent' === style.backgroundColor ) {
			return;
		}

		var pad = parseFloat( style.paddingBottom ) || 0;

		foot.style.paddingBottom = 'calc( ' + pad + 'px + var( --bappbar-h, 0px ) )';
		document.documentElement.classList.add( 'bappbar-footer-spaced' );
	}

	/**
	 * Which tab is lit.
	 *
	 * The server marks the page being viewed. From there, whichever section is
	 * on screen takes over, and the top of the page hands it back — without that
	 * last part, scrolling back up would leave the last section still lit.
	 */
	var initial;
	var current;
	var atTop = true;

	function setActive( el ) {
		items.forEach( function ( item ) {
			var on = item === el;

			if ( item.classList.contains( 'is-active' ) !== on ) {
				item.classList.toggle( 'is-active', on );
			}

			if ( 'A' !== item.tagName ) {
				return;
			}

			if ( on ) {
				item.setAttribute( 'aria-current', 'page' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );
	}

	function watchSections() {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var map = {};
		var watched = [];

		items.forEach( function ( link ) {
			if ( ! link.hash || link.hash.length < 2 || 'A' !== link.tagName ) {
				return;
			}

			if ( link.pathname !== window.location.pathname ) {
				return; // Points at another page: let it navigate.
			}

			var target;

			try {
				target = document.querySelector( link.hash );
			} catch ( e ) {
				target = null;
			}

			if ( ! target || ! target.id ) {
				return;
			}

			map[ '#' + target.id ] = link;
			watched.push( target );
		} );

		if ( ! watched.length ) {
			return;
		}

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}

				var link = map[ '#' + entry.target.id ];

				if ( ! link ) {
					return;
				}

				current = link;

				if ( ! atTop ) {
					setActive( link );
				}
			} );
		}, { rootMargin: '-45% 0px -50% 0px', threshold: 0 } );

		watched.forEach( function ( target ) {
			observer.observe( target );
		} );
	}

	/**
	 * One scroll handler for both jobs, batched into a frame, and the class is
	 * only touched when the state actually flips rather than on every pixel.
	 */
	function watchScroll() {
		var autohide = bar.hasAttribute( 'data-autohide' );
		var hidden = false;
		var last = window.scrollY || 0;
		var queued = false;

		function apply() {
			queued = false;

			var y = Math.max( 0, window.scrollY || 0 );
			var top = y < 140;

			if ( top !== atTop ) {
				atTop = top;
				setActive( top ? initial : current );
			}

			if ( ! autohide ) {
				return;
			}

			var delta = y - last;

			if ( Math.abs( delta ) <= 6 ) {
				return; // Ignore the jitter of a rubber-band scroll.
			}

			last = y;

			var next = delta > 0 && y > 220;

			if ( next !== hidden ) {
				hidden = next;
				bar.classList.toggle( 'is-hidden', next );
			}
		}

		apply();

		window.addEventListener( 'scroll', function () {
			if ( ! queued ) {
				queued = true;
				window.requestAnimationFrame( apply );
			}
		}, { passive: true } );
	}

	/**
	 * The menu and search sheets.
	 */
	function sheets() {
		var open = null;
		var opener = null;
		var scrollLock = '';

		function focusable( panel ) {
			return Array.prototype.slice.call(
				panel.querySelectorAll( 'a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea' )
			).filter( function ( el ) {
				return null !== el.offsetParent;
			} );
		}

		function close() {
			if ( ! open ) {
				return;
			}

			var sheet = open;

			open = null;
			sheet.classList.remove( 'is-open' );
			bar.classList.remove( 'is-sheet' );
			document.body.style.overflow = scrollLock;

			if ( opener ) {
				opener.setAttribute( 'aria-expanded', 'false' );
				opener.focus();
				opener = null;
			}

			// Kept in the flow until the slide-down has finished, then taken out
			// of the accessibility tree properly.
			window.setTimeout( function () {
				if ( ! sheet.classList.contains( 'is-open' ) ) {
					sheet.hidden = true;
				}
			}, reduce ? 0 : 340 );
		}

		function show( sheet, button ) {
			if ( open ) {
				close();
			}

			open = sheet;
			opener = button;
			scrollLock = document.body.style.overflow;

			sheet.hidden = false;
			bar.classList.add( 'is-sheet' );
			document.body.style.overflow = 'hidden';

			if ( button ) {
				button.setAttribute( 'aria-expanded', 'true' );
			}

			// A frame between "in the DOM" and "open" so the panel slides up
			// rather than appearing already there.
			window.requestAnimationFrame( function () {
				sheet.classList.add( 'is-open' );

				var first = focusable( sheet.querySelector( '.beaver-appbar-sheet__panel' ) )[ 0 ];

				if ( first ) {
					first.focus();
				}
			} );
		}

		bar.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-appbar-sheet]' );

			if ( ! button ) {
				return;
			}

			var name = button.getAttribute( 'data-appbar-sheet' );
			var sheet = document.querySelector( '.beaver-appbar-sheet[data-appbar-panel="' + name + '"]' );

			if ( sheet ) {
				show( sheet, button );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( open && event.target.closest( '[data-appbar-close]' ) ) {
				close();
			}
		} );

		// A link inside the sheet is a destination: close on the way out.
		document.addEventListener( 'click', function ( event ) {
			if ( open && event.target.closest( '.beaver-appbar-sheet__body a[href]' ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! open ) {
				return;
			}

			if ( 'Escape' === event.key ) {
				close();

				return;
			}

			if ( 'Tab' !== event.key ) {
				return;
			}

			var stops = focusable( open.querySelector( '.beaver-appbar-sheet__panel' ) );

			if ( ! stops.length ) {
				return;
			}

			var first = stops[ 0 ];
			var last = stops[ stops.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );
	}

	/**
	 * The back-to-top tab.
	 */
	function backToTop() {
		bar.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-appbar-top]' ) ) {
				return;
			}

			window.scrollTo( { top: 0, behavior: reduce ? 'auto' : 'smooth' } );
		} );
	}

	/**
	 * On a phone the on-screen keyboard slides the viewport up and a fixed bar
	 * ends up over the field being typed into, so it steps aside while a form
	 * control has focus. The short delay stops it flickering as the visitor tabs
	 * from one field to the next.
	 */
	function dodgeKeyboard() {
		if ( ! window.matchMedia || ! window.matchMedia( '( pointer: coarse )' ).matches ) {
			return;
		}

		var restore = null;

		document.addEventListener( 'focusin', function ( event ) {
			var tag = event.target && event.target.tagName;

			if ( 'INPUT' !== tag && 'TEXTAREA' !== tag && 'SELECT' !== tag ) {
				return;
			}

			if ( restore ) {
				window.clearTimeout( restore );
				restore = null;
			}

			bar.classList.add( 'is-typing' );
		} );

		document.addEventListener( 'focusout', function () {
			if ( restore ) {
				window.clearTimeout( restore );
			}

			restore = window.setTimeout( function () {
				restore = null;
				bar.classList.remove( 'is-typing' );
			}, 120 );
		} );
	}

	/**
	 * Finds the bar and wires everything to it.
	 */
	function start() {
		bar = document.querySelector( '.beaver-appbar' );

		if ( ! bar ) {
			return;
		}

		items = Array.prototype.slice.call( bar.querySelectorAll( '.beaver-appbar__item' ) );
		initial = bar.querySelector( '.beaver-appbar__item.is-active' );
		current = initial;

		spaceFooter();
		watchSections();
		watchScroll();
		sheets();
		backToTop();
		dodgeKeyboard();
	}

	// The bar is printed on wp_footer, and a theme or another plugin can move
	// the footer scripts above it, so this waits for the document rather than
	// assuming the markup is already there.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );

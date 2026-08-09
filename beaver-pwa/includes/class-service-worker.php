<?php
/**
 * Service worker generation and the offline fallback page.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates the service worker from the current settings.
 *
 * The worker is never written to disk. Its cache names carry a signature
 * derived from the settings, so saving the settings screen is enough to make
 * every browser fetch a new worker and drop the caches the old one owned.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Service_Worker {

	/**
	 * Body returned by the heartbeat endpoint.
	 */
	const HEARTBEAT_TOKEN = 'beaver-pwa-ok';

	/**
	 * Builds the configuration handed to the worker.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function config() {
		$offline_enabled = (bool) Beaver_PWA_Settings::get( 'offline_enabled' );

		$precache = array();

		if ( $offline_enabled ) {
			$precache[] = self::offline_url();
		}

		$precache[] = Beaver_PWA_Routes::url( 'manifest' );
		$precache   = array_merge( $precache, Beaver_PWA_Icons::precache_urls() );

		return array(
			'version'    => Beaver_PWA_Settings::cache_version(),
			'scope'      => Beaver_PWA_Settings::scope_path(),
			'offline'    => self::offline_url(),
			'heartbeat'  => Beaver_PWA_Routes::url( 'alive' ),
			'precache'   => array_values( array_unique( array_filter( $precache ) ) ),
			'exclude'    => self::exclusions(),
			'pages'      => (bool) Beaver_PWA_Settings::get( 'cache_pages' ),
			'assets'     => (bool) Beaver_PWA_Settings::get( 'cache_assets' ),
			'images'     => (bool) Beaver_PWA_Settings::get( 'cache_images' ),
			'pageLimit'  => (int) Beaver_PWA_Settings::get( 'page_cache_limit' ),
			'imageLimit' => (int) Beaver_PWA_Settings::get( 'image_cache_limit' ),
			'offlineOn'  => $offline_enabled,
			'immediate'  => ! Beaver_PWA_Settings::get( 'update_toast' ),
		);
	}

	/**
	 * URL fragments the worker must never touch.
	 *
	 * Anything personal, transactional or generated belongs here: a cached
	 * checkout or a cached admin screen is worse than no cache at all.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function exclusions() {
		$list = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-cron.php',
			'/wp-json',
			'/xmlrpc.php',
			'/feed',
			'/sitemap',
			'.xml',
			'preview=true',
			'customize_changeset_uuid',
			'customize_theme',
			'elementor-preview',
			'action=elementor',
			'beaver_pwa=alive',
			'add-to-cart=',
			'wc-ajax=',
			'nocache',
		);

		$list[] = wp_parse_url( Beaver_PWA_Routes::url( 'sw' ), PHP_URL_PATH );
		$list[] = 'beaver_pwa=sw';

		// WooCommerce pages are per-visitor by definition, wherever they live.
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$page_id = wc_get_page_id( $page );

				if ( $page_id > 0 ) {
					$path = wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH );

					if ( $path ) {
						$list[] = $path;
					}
				}
			}
		}

		$list = array_merge( $list, Beaver_PWA_Settings::exclusion_list() );

		/**
		 * Filters the list of URL fragments excluded from every cache.
		 *
		 * @since 1.0.0
		 *
		 * @param array $list Fragments matched against path plus query string.
		 */
		$list = (array) apply_filters( 'beaver_pwa_cache_exclusions', $list );

		return array_values( array_unique( array_filter( array_map( 'strval', $list ) ) ) );
	}

	/**
	 * URL of the page shown when a request cannot be met.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function offline_url() {
		$page_id = (int) Beaver_PWA_Settings::get( 'offline_page_id' );

		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		return Beaver_PWA_Routes::url( 'offline' );
	}

	/**
	 * Renders the worker script.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function script() {
		$config = wp_json_encode( self::config(), JSON_UNESCAPED_SLASHES );

		return strtr( self::template(), array( '__BPWA_CONFIG__' => $config ) );
	}

	/**
	 * Sends the worker and stops.
	 *
	 * @since 1.0.0
	 */
	public static function serve() {
		header( 'Content-Type: application/javascript; charset=utf-8' );

		// Lets the worker control the whole site even when it is served from a
		// URL that a browser would otherwise scope more narrowly.
		header( 'Service-Worker-Allowed: ' . Beaver_PWA_Settings::scope_path() );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Robots-Tag: noindex' );

		echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated JavaScript.

		exit;
	}

	/**
	 * Sends the heartbeat the worker uses to confirm the plugin is still live.
	 *
	 * @since 1.0.0
	 */
	public static function serve_heartbeat() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Robots-Tag: noindex' );

		echo esc_html( self::HEARTBEAT_TOKEN . ':' . Beaver_PWA_Settings::cache_version() );

		exit;
	}

	/**
	 * Sends the built-in offline page and stops.
	 *
	 * The page is deliberately self-contained: no stylesheet, no font and no
	 * script from anywhere else, because none of them would load.
	 *
	 * @since 1.0.0
	 */
	public static function serve_offline() {
		$name       = Beaver_PWA_Settings::app_name();
		$theme      = Beaver_PWA_Settings::get( 'theme_color' );
		$background = Beaver_PWA_Settings::get( 'background_color' );
		$icon       = Beaver_PWA_Icons::preview_url();
		$home       = home_url( '/' );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );

		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', (string) get_bloginfo( 'language' ) ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?php echo esc_attr( $theme ); ?>">
<title><?php echo esc_html( sprintf( /* translators: %s: site name. */ __( 'Offline: %s', 'beaver-pwa' ), $name ) ); ?></title>
<style>
	:root { color-scheme: light dark; }
	* { box-sizing: border-box; }
	body {
		margin: 0;
		min-height: 100vh;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 2rem 1.25rem calc( 2rem + env( safe-area-inset-bottom ) );
		background: <?php echo esc_html( $background ); ?>;
		color: <?php echo esc_html( $theme ); ?>;
		font: 400 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		text-align: center;
	}
	.wrap { width: 100%; max-width: 26rem; }
	.icon { width: 72px; height: 72px; border-radius: 18px; object-fit: cover; margin-bottom: 1.25rem; }
	h1 { margin: 0 0 .5rem; font-size: 1.5rem; line-height: 1.25; }
	p { margin: 0 0 1.5rem; opacity: .75; }
	button {
		font: inherit;
		font-weight: 600;
		padding: .75rem 1.5rem;
		border: 0;
		border-radius: 999px;
		background: <?php echo esc_html( $theme ); ?>;
		color: <?php echo esc_html( $background ); ?>;
		cursor: pointer;
	}
	button:focus-visible { outline: 3px solid currentColor; outline-offset: 3px; }
	.saved { margin-top: 2.5rem; text-align: start; }
	.saved h2 { font-size: .75rem; text-transform: uppercase; letter-spacing: .08em; opacity: .6; margin: 0 0 .5rem; }
	.saved ul { list-style: none; margin: 0; padding: 0; }
	.saved li { border-top: 1px solid currentColor; border-color: color-mix( in srgb, currentColor 15%, transparent ); }
	.saved a { display: block; padding: .7rem .25rem; color: inherit; text-decoration: none; font-size: .95rem; }
	.saved a:hover { text-decoration: underline; }
	[hidden] { display: none !important; }
</style>
</head>
<body>
<div class="wrap">
	<?php if ( $icon ) : ?>
		<img class="icon" src="<?php echo esc_url( $icon ); ?>" alt="" width="72" height="72">
	<?php endif; ?>
	<h1><?php esc_html_e( 'You are offline', 'beaver-pwa' ); ?></h1>
	<p><?php esc_html_e( 'This page has not been saved to your device yet. It will load as soon as you are back online.', 'beaver-pwa' ); ?></p>
	<button type="button" id="retry"><?php esc_html_e( 'Try again', 'beaver-pwa' ); ?></button>
	<section class="saved" id="saved" hidden>
		<h2><?php esc_html_e( 'Available offline', 'beaver-pwa' ); ?></h2>
		<ul id="saved-list"></ul>
	</section>
</div>
<script>
( function () {
	var home = <?php echo wp_json_encode( $home ); ?>;

	document.getElementById( 'retry' ).addEventListener( 'click', function () {
		location.reload();
	} );

	window.addEventListener( 'online', function () {
		location.reload();
	} );

	if ( ! ( 'caches' in window ) ) {
		return;
	}

	caches.keys().then( function ( names ) {
		var pageCaches = names.filter( function ( name ) {
			return name.indexOf( 'beaver-pwa-pages-' ) === 0;
		} );

		return Promise.all( pageCaches.map( function ( name ) {
			return caches.open( name ).then( function ( cache ) {
				return cache.keys();
			} );
		} ) );
	} ).then( function ( groups ) {
		var seen = {};
		var list = document.getElementById( 'saved-list' );
		var count = 0;

		groups.forEach( function ( requests ) {
			requests.forEach( function ( request ) {
				if ( count >= 12 || seen[ request.url ] || request.url === location.href ) {
					return;
				}

				seen[ request.url ] = true;
				count++;

				var label = request.url.replace( home, '' ).replace( /\/$/, '' ) || '<?php echo esc_js( __( 'Home', 'beaver-pwa' ) ); ?>';
				var item = document.createElement( 'li' );
				var link = document.createElement( 'a' );

				link.href = request.url;
				link.textContent = decodeURIComponent( label );
				item.appendChild( link );
				list.appendChild( item );
			} );
		} );

		if ( count ) {
			document.getElementById( 'saved' ).hidden = false;
		}
	} ).catch( function () {} );
}() );
</script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * The worker source, with a single placeholder for its configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function template() {
		return <<<'JS'
/*!
 * Beaver PWA service worker.
 *
 * Generated by WordPress from the plugin settings. Editing a copy of this file
 * has no effect: it is rebuilt on every request.
 */
const BPWA = __BPWA_CONFIG__;

const PREFIX = 'beaver-pwa';
const SHELL  = PREFIX + '-shell-' + BPWA.version;
const PAGES  = PREFIX + '-pages-' + BPWA.version;
const ASSETS = PREFIX + '-assets-' + BPWA.version;
const IMAGES = PREFIX + '-images-' + BPWA.version;
const KEEP   = [ SHELL, PAGES, ASSETS, IMAGES ];

// Themes and plugins version their assets in the query string, so each release
// adds entries. The cap stops that growing without end.
const ASSET_LIMIT = 150;

self.addEventListener( 'install', function ( event ) {
	event.waitUntil( ( async function () {
		const cache = await caches.open( SHELL );

		// One unreachable URL must not fail the whole installation.
		await Promise.all( BPWA.precache.map( function ( url ) {
			return cache.add( new Request( url, { cache: 'reload', credentials: 'same-origin' } ) ).catch( function () {
				return null;
			} );
		} ) );

		if ( BPWA.immediate ) {
			await self.skipWaiting();
		}
	}() ) );
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil( ( async function () {
		const names = await caches.keys();

		await Promise.all( names.map( function ( name ) {
			if ( name.indexOf( PREFIX + '-' ) === 0 && KEEP.indexOf( name ) === -1 ) {
				return caches.delete( name );
			}

			return null;
		} ) );

		if ( self.registration.navigationPreload ) {
			try {
				await self.registration.navigationPreload.enable();
			} catch ( error ) {}
		}

		await self.clients.claim();
		await heartbeat();
	}() ) );
} );

self.addEventListener( 'message', function ( event ) {
	const data = event.data || {};

	if ( data.type === 'BPWA_SKIP_WAITING' ) {
		self.skipWaiting();
	}

	if ( data.type === 'BPWA_CLEAR' ) {
		event.waitUntil( clear() );
	}

	if ( data.type === 'BPWA_UNREGISTER' ) {
		event.waitUntil( selfDestruct() );
	}
} );

self.addEventListener( 'fetch', function ( event ) {
	const request = event.request;

	if ( request.method !== 'GET' || request.headers.has( 'range' ) ) {
		return;
	}

	let url;

	try {
		url = new URL( request.url );
	} catch ( error ) {
		return;
	}

	// Same origin only: a cached opaque response cannot be inspected, so its
	// size and freshness would both be guesswork.
	if ( url.origin !== self.location.origin ) {
		return;
	}

	if ( url.pathname.indexOf( BPWA.scope ) !== 0 || isExcluded( url ) ) {
		return;
	}

	if ( request.mode === 'navigate' ) {
		event.respondWith( handleNavigation( event ) );

		return;
	}

	const destination = request.destination;

	if ( BPWA.assets && ( destination === 'style' || destination === 'script' || destination === 'font' ) ) {
		event.respondWith( staleWhileRevalidate( event, ASSETS ) );

		return;
	}

	if ( BPWA.images && destination === 'image' ) {
		event.respondWith( cacheFirst( event, IMAGES, BPWA.imageLimit ) );
	}
} );

/**
 * Pages: always try the network first so nobody reads yesterday's content,
 * and keep a copy only as the offline safety net.
 */
async function handleNavigation( event ) {
	const request = event.request;

	try {
		const preloaded = await event.preloadResponse;
		const response  = preloaded || await fetch( request );

		if ( BPWA.pages && isStorable( response ) ) {
			const copy = response.clone();

			event.waitUntil( store( PAGES, request, copy, BPWA.pageLimit ) );
		}

		return response;
	} catch ( error ) {
		const cached = await caches.match( request, { ignoreVary: true } );

		if ( cached ) {
			return cached;
		}

		if ( BPWA.offlineOn ) {
			const offline = await caches.match( BPWA.offline, { ignoreSearch: true, ignoreVary: true } );

			if ( offline ) {
				return offline;
			}
		}

		return new Response(
			'<!DOCTYPE html><meta charset="utf-8"><title>Offline</title><p>You are offline.',
			{ status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
		);
	}
}

/**
 * Stylesheets, scripts and fonts: answer from the cache at once, then refresh
 * it in the background so the next load is current.
 */
async function staleWhileRevalidate( event, cacheName ) {
	const request = event.request;
	const cache   = await caches.open( cacheName );
	const cached  = await cache.match( request );

	const network = fetch( request ).then( function ( response ) {
		if ( isStorable( response ) ) {
			cache.put( request, response.clone() ).then( function () {
				return trim( cache, ASSET_LIMIT );
			} ).catch( function () {} );
		}

		return response;
	} ).catch( function () {
		return null;
	} );

	if ( cached ) {
		event.waitUntil( network );

		return cached;
	}

	const response = await network;

	return response || new Response( '', { status: 504, statusText: 'Offline' } );
}

/**
 * Images: served straight from the cache once seen, with a hard entry limit so
 * a large media library cannot fill the device.
 */
async function cacheFirst( event, cacheName, limit ) {
	const request = event.request;
	const cache   = await caches.open( cacheName );
	const cached  = await cache.match( request );

	if ( cached ) {
		return cached;
	}

	try {
		const response = await fetch( request );

		if ( isStorable( response ) ) {
			const copy = response.clone();

			event.waitUntil( store( cacheName, request, copy, limit ) );
		}

		return response;
	} catch ( error ) {
		return new Response( '', { status: 504, statusText: 'Offline' } );
	}
}

async function store( cacheName, request, response, limit ) {
	const cache = await caches.open( cacheName );

	await cache.put( request, response ).catch( function () {} );
	await trim( cache, limit );
}

async function trim( cache, limit ) {
	if ( ! limit ) {
		return;
	}

	const keys = await cache.keys();

	for ( let index = 0; index < keys.length - limit; index++ ) {
		await cache.delete( keys[ index ] );
	}
}

/**
 * A response is only worth storing when it is a complete, public, same-origin
 * document. WordPress marks anything personal as private or no-store, which is
 * what keeps a logged-in visitor's pages out of the cache.
 */
function isStorable( response ) {
	if ( ! response || ! response.ok || response.type !== 'basic' ) {
		return false;
	}

	const control = response.headers.get( 'Cache-Control' ) || '';

	return ! /(no-store|private)/i.test( control );
}

function isExcluded( url ) {
	const target = url.pathname + url.search;

	for ( let index = 0; index < BPWA.exclude.length; index++ ) {
		if ( target.indexOf( BPWA.exclude[ index ] ) !== -1 ) {
			return true;
		}
	}

	return false;
}

async function clear() {
	const names = await caches.keys();

	await Promise.all( names.map( function ( name ) {
		return name.indexOf( PREFIX + '-' ) === 0 ? caches.delete( name ) : null;
	} ) );
}

/**
 * A service worker outlives the site that installed it. If the plugin is
 * switched off, this check fails and the worker removes itself instead of
 * serving a frozen copy of the site for ever.
 *
 * A failed request proves nothing (the device may simply be offline), so only
 * a definite answer from the server counts.
 */
async function heartbeat() {
	let response;

	try {
		response = await fetch( BPWA.heartbeat, { cache: 'no-store', credentials: 'same-origin' } );
	} catch ( error ) {
		return;
	}

	if ( response && response.ok ) {
		const body = await response.text().catch( function () {
			return '';
		} );

		if ( body.indexOf( 'beaver-pwa-ok' ) !== -1 ) {
			return;
		}
	}

	await selfDestruct();
}

async function selfDestruct() {
	await clear();

	try {
		await self.registration.unregister();
	} catch ( error ) {}

	const windows = await self.clients.matchAll( { type: 'window' } );

	windows.forEach( function ( client ) {
		if ( client.navigate ) {
			client.navigate( client.url ).catch( function () {} );
		}
	} );
}
JS;
	}
}

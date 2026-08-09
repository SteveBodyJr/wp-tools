<?php
/**
 * Icon library.
 *
 * @package BeaverAppBar
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's own icons, drawn as inline SVG.
 *
 * Inline and self-contained on purpose. An icon font or a sprite sheet would be
 * one more request on every page view for the sake of five glyphs, and a CDN
 * link would tell a third party who visited the site. These are drawn to one
 * spec — a 24px box, 1.7 stroke, round caps and joins — so they sit together as
 * a set rather than looking borrowed from three places.
 *
 * @since 1.0.0
 */
class Beaver_AppBar_Icons {

	/**
	 * Outline icons. The paths are stroked in the current colour.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Key => inner SVG markup.
	 */
	public static function outline() {
		return array(
			'home'      => '<path d="M4 10.6 12 4l8 6.6V19a1.6 1.6 0 0 1-1.6 1.6H5.6A1.6 1.6 0 0 1 4 19v-8.4Z"/><path d="M9.6 20.6v-6h4.8v6"/>',
			'grid'      => '<rect x="3.5" y="3.5" width="7" height="7" rx="2.1"/><rect x="13.5" y="3.5" width="7" height="7" rx="2.1"/><rect x="3.5" y="13.5" width="7" height="7" rx="2.1"/><rect x="13.5" y="13.5" width="7" height="7" rx="2.1"/>',
			'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
			'search'    => '<circle cx="11" cy="11" r="6.6"/><path d="m20.5 20.5-4.8-4.8"/>',
			'briefcase' => '<rect x="3" y="7.5" width="18" height="12.5" rx="2.5"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5M3 12.6h18"/>',
			'message'   => '<path d="M20 15a2.5 2.5 0 0 1-2.5 2.5H8.4L4 21V6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V15Z"/><path d="M8.5 9.4h7M8.5 12.9h4"/>',
			'send'      => '<path d="M21 3 10.4 13.6M21 3l-6.7 18-3.9-7.4L3 9.7 21 3Z"/>',
			'phone'     => '<path d="M6 3h3l1.5 5-2 1.5a11 11 0 0 0 5 5l1.5-2 5 1.5v3a2 2 0 0 1-2.2 2A16 16 0 0 1 4 5.2 2 2 0 0 1 6 3Z"/>',
			'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
			'user'      => '<circle cx="12" cy="8" r="3.6"/><path d="M4.9 20a7.1 7.1 0 0 1 14.2 0"/>',
			'cart'      => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2l2.4 12.3a1 1 0 0 0 1 .7h9.2a1 1 0 0 0 1-.8L21 7H6"/>',
			'store'     => '<path d="M4 9 5 4h14l1 5M4 9h16M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9M9 20v-5h6v5"/>',
			'calendar'  => '<rect x="3.5" y="5.5" width="17" height="15" rx="2.5"/><path d="M8 3.5v4M16 3.5v4M3.5 10.5h17"/>',
			'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
			'location'  => '<path d="M12 21c4-4 6-7.2 6-10a6 6 0 1 0-12 0c0 2.8 2 6 6 10Z"/><circle cx="12" cy="11" r="2.2"/>',
			'compass'   => '<circle cx="12" cy="12" r="9"/><path d="m15.3 8.7-1.9 4.6-4.6 1.9 1.9-4.6 4.6-1.9Z"/>',
			'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
			'camera'    => '<path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h2.7l1.3-2.2h6.9L16.8 7h2.7A1.5 1.5 0 0 1 21 8.5v9A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-9Z"/><circle cx="12" cy="12.8" r="3.4"/>',
			'book'      => '<path d="M5 4h11a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2V4Z"/><path d="M5 18a2 2 0 0 1 2-2h11"/>',
			'heart'     => '<path d="M12 20S4 14.5 4 9a4 4 0 0 1 8-1 4 4 0 0 1 8 1c0 5.5-8 11-8 11Z"/>',
			'star'      => '<path d="m12 3 2.6 5.6 6 .7-4.4 4.1 1.2 6L12 16.9 6.6 19.4l1.2-6L3.4 9.3l6-.7L12 3Z"/>',
			'bookmark'  => '<path d="M6.6 4.5h10.8a1 1 0 0 1 1 1V20l-6.4-3.6L5.6 20V5.5a1 1 0 0 1 1-1Z"/>',
			'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.4M12 7.7h.01"/>',
			'plus'      => '<path d="M12 5.2v13.6M5.2 12h13.6"/>',
			'up'        => '<path d="M12 19.5V5M6 11l6-6 6 6"/>',
			'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
		);
	}

	/**
	 * Brand icons, filled rather than stroked.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Key => inner SVG markup.
	 */
	public static function filled() {
		return array(
			'whatsapp' => '<path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 0 1 6.9 12.6l-.2.3.8 3-3-.8-.3.2A8.2 8.2 0 1 1 12 3.8Zm-3.1 4.1c-.2 0-.4.1-.6.3-.3.3-.9.9-.9 2.1s.9 2.5 1 2.7c.1.2 1.8 2.9 4.4 3.9 2.2.9 2.6.7 3.1.7.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2l-.7-.4c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1-.7-.3-1.4-.6-2.1-1.5-.5-.6-.9-1.3-1-1.5-.1-.2 0-.3.1-.4l.4-.4c.1-.2.2-.3.2-.5.1-.2 0-.3 0-.5l-.7-1.6c-.2-.4-.3-.4-.5-.4h-.6Z"/>',
		);
	}

	/**
	 * Builds the SVG for one icon.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key  Icon key.
	 * @param int    $size Pixel size of the square box.
	 * @return string SVG markup, or an empty string when the key is unknown.
	 */
	public static function svg( $key, $size = 22 ) {
		$key     = sanitize_key( $key );
		$size    = (int) $size;
		$outline = self::outline();
		$filled  = self::filled();

		if ( isset( $outline[ $key ] ) ) {
			return sprintf(
				'<svg class="beaver-appbar__svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
				$size,
				$outline[ $key ]
			);
		}

		if ( isset( $filled[ $key ] ) ) {
			return sprintf(
				'<svg class="beaver-appbar__svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">%2$s</svg>',
				$size,
				$filled[ $key ]
			);
		}

		return '';
	}

	/**
	 * The icon picker's options, in the order they are offered.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Key => human label.
	 */
	public static function choices() {
		return array(
			'home'      => __( 'Home', 'beaver-appbar' ),
			'grid'      => __( 'Grid / Services', 'beaver-appbar' ),
			'menu'      => __( 'Menu', 'beaver-appbar' ),
			'search'    => __( 'Search', 'beaver-appbar' ),
			'briefcase' => __( 'Work / Portfolio', 'beaver-appbar' ),
			'message'   => __( 'Message / Chat', 'beaver-appbar' ),
			'send'      => __( 'Send / Enquire', 'beaver-appbar' ),
			'whatsapp'  => __( 'WhatsApp', 'beaver-appbar' ),
			'phone'     => __( 'Call', 'beaver-appbar' ),
			'mail'      => __( 'Email', 'beaver-appbar' ),
			'user'      => __( 'Account / About', 'beaver-appbar' ),
			'cart'      => __( 'Cart', 'beaver-appbar' ),
			'store'     => __( 'Shop', 'beaver-appbar' ),
			'calendar'  => __( 'Booking', 'beaver-appbar' ),
			'clock'     => __( 'Hours', 'beaver-appbar' ),
			'location'  => __( 'Find us', 'beaver-appbar' ),
			'compass'   => __( 'Explore', 'beaver-appbar' ),
			'globe'     => __( 'Global', 'beaver-appbar' ),
			'camera'    => __( 'Gallery', 'beaver-appbar' ),
			'book'      => __( 'Blog / Guide', 'beaver-appbar' ),
			'heart'     => __( 'Favourites', 'beaver-appbar' ),
			'star'      => __( 'Reviews', 'beaver-appbar' ),
			'bookmark'  => __( 'Saved', 'beaver-appbar' ),
			'info'      => __( 'Info', 'beaver-appbar' ),
			'plus'      => __( 'Plus / New', 'beaver-appbar' ),
			'up'        => __( 'Back to top', 'beaver-appbar' ),
		);
	}

	/**
	 * Whether an icon key exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Icon key.
	 * @return bool True when the key is one of ours.
	 */
	public static function exists( $key ) {
		$key = sanitize_key( $key );

		return isset( self::outline()[ $key ] ) || isset( self::filled()[ $key ] );
	}
}

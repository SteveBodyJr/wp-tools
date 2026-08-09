<?php
/**
 * Settings.
 *
 * @package BeaverAppBar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the bar is: whether it shows, where it shows, how it looks, and
 * what is in it.
 *
 * All of it lives in one autoloaded option. A tab bar is read on every front-end
 * request, and one row that is already in the alloptions query costs nothing,
 * where a dozen separate rows would each be a lookup.
 *
 * @since 1.0.0
 */
class Beaver_AppBar_Settings {

	const OPTION = 'beaver_appbar';

	/** Maximum items. Six is already tight on a 360px screen. */
	const MAX_ITEMS = 5;

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * What an item can open.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Type => human label.
	 */
	public static function types() {
		return array(
			'link'     => __( 'A page, post or section', 'beaver-appbar' ),
			'menu'     => __( 'Menu sheet', 'beaver-appbar' ),
			'search'   => __( 'Search sheet', 'beaver-appbar' ),
			'whatsapp' => __( 'WhatsApp chat', 'beaver-appbar' ),
			'call'     => __( 'Phone call', 'beaver-appbar' ),
			'email'    => __( 'Email', 'beaver-appbar' ),
			'top'      => __( 'Back to top', 'beaver-appbar' ),
		);
	}

	/**
	 * Where the bar is allowed to appear, and the width it stops at.
	 *
	 * 600 and 1000 rather than round numbers: 600 is the widest phone in
	 * landscape, and 1000 is where most themes swap their burger for a full
	 * menu, which is the point at which a bottom bar stops earning its space.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,mixed>> Key => label and breakpoint.
	 */
	public static function device_options() {
		return array(
			'phones' => array(
				'label' => __( 'Phones only', 'beaver-appbar' ),
				'upto'  => 600,
			),
			'mobile' => array(
				'label' => __( 'Phones and tablets', 'beaver-appbar' ),
				'upto'  => 1000,
			),
			'all'    => array(
				'label' => __( 'Every device, including computers', 'beaver-appbar' ),
				'upto'  => 0,
			),
		);
	}

	/**
	 * Shipped defaults.
	 *
	 * Switched off, but with a usable starter bar already filled in, so the
	 * first thing an admin sees is a real example rather than an empty table.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function defaults() {
		return array(
			'enabled'  => 0,
			'devices'  => 'mobile',
			'style'    => 'glass',
			'scheme'   => 'auto',
			'accent'   => '#2f56f0',
			'labels'   => 1,
			'autohide' => 0,
			'whatsapp' => '',
			'phone'    => '',
			'email'    => '',
			'menu'     => 0,
			'items'    => array(
				array(
					'label' => __( 'Home', 'beaver-appbar' ),
					'icon'  => 'home',
					'type'  => 'link',
					'url'   => '/',
					'cta'   => 0,
				),
				array(
					'label' => __( 'Menu', 'beaver-appbar' ),
					'icon'  => 'menu',
					'type'  => 'menu',
					'url'   => '',
					'cta'   => 0,
				),
				array(
					'label' => __( 'Search', 'beaver-appbar' ),
					'icon'  => 'search',
					'type'  => 'search',
					'url'   => '',
					'cta'   => 0,
				),
				array(
					'label' => __( 'Contact', 'beaver-appbar' ),
					'icon'  => 'send',
					'type'  => 'link',
					'url'   => '/contact/',
					'cta'   => 1,
				),
			),
		);
	}

	/**
	 * Returns all settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );

			if ( ! is_array( self::$cache['items'] ) ) {
				self::$cache['items'] = array();
			}
		}

		return self::$cache;
	}

	/**
	 * Reads one field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Field.
	 * @param mixed  $default Fallback.
	 * @return mixed Value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merges changes in and saves.
	 *
	 * @since 1.0.0
	 *
	 * @param array $changes Fields to change.
	 * @return array The saved settings.
	 */
	public static function update( array $changes ) {
		$settings = self::sanitize( array_merge( self::all(), $changes ) );

		update_option( self::OPTION, $settings, 'yes' );

		self::$cache = $settings;

		return $settings;
	}

	/**
	 * Drops the cache.
	 *
	 * @since 1.0.0
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Validates a settings payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$devices = isset( $input['devices'] ) ? sanitize_key( $input['devices'] ) : $defaults['devices'];
		$style   = isset( $input['style'] ) ? sanitize_key( $input['style'] ) : $defaults['style'];
		$scheme  = isset( $input['scheme'] ) ? sanitize_key( $input['scheme'] ) : $defaults['scheme'];

		return array(
			'enabled'  => empty( $input['enabled'] ) ? 0 : 1,
			'devices'  => array_key_exists( $devices, self::device_options() ) ? $devices : $defaults['devices'],
			'style'    => in_array( $style, array( 'glass', 'float' ), true ) ? $style : $defaults['style'],
			'scheme'   => in_array( $scheme, array( 'auto', 'light', 'dark' ), true ) ? $scheme : $defaults['scheme'],
			'accent'   => self::sanitize_hex( $input['accent'] ?? '', $defaults['accent'] ),
			'labels'   => empty( $input['labels'] ) ? 0 : 1,
			'autohide' => empty( $input['autohide'] ) ? 0 : 1,
			'whatsapp' => preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp'] ?? '' ) ),
			'phone'    => sanitize_text_field( $input['phone'] ?? '' ),
			'email'    => sanitize_email( $input['email'] ?? '' ),
			'menu'     => absint( $input['menu'] ?? 0 ),
			'items'    => self::sanitize_items( $input['items'] ?? array() ),
		);
	}

	/**
	 * Validates the item rows.
	 *
	 * A row with no label is dropped rather than kept: the label is also the
	 * item's accessible name, so a nameless tab is a tab a screen reader cannot
	 * announce.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $rows Raw rows.
	 * @return array Clean rows, at most MAX_ITEMS of them.
	 */
	private static function sanitize_items( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$types = self::types();
		$clean = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label = sanitize_text_field( $row['label'] ?? '' );

			if ( '' === trim( $label ) ) {
				continue;
			}

			$type = sanitize_key( $row['type'] ?? 'link' );
			$icon = sanitize_key( $row['icon'] ?? '' );

			$clean[] = array(
				'label' => $label,
				'icon'  => Beaver_AppBar_Icons::exists( $icon ) ? $icon : 'home',
				'type'  => array_key_exists( $type, $types ) ? $type : 'link',
				// Not esc_url_raw(): that strips "#section" and "/path" down to
				// nothing useful. The value is escaped at render instead.
				'url'   => sanitize_text_field( $row['url'] ?? '' ),
				'cta'   => empty( $row['cta'] ) ? 0 : 1,
			);

			if ( count( $clean ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Validates a colour.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value    Raw colour.
	 * @param string $fallback Value to use when the input is not a hex colour.
	 * @return string Hex colour.
	 */
	private static function sanitize_hex( $value, $fallback ) {
		$value = sanitize_hex_color( (string) $value );

		return $value ? $value : $fallback;
	}
}

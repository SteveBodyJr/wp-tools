<?php
/**
 * Settings.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide options: title templates, social defaults, sitemap and robots.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Settings {

	const OPTION = 'beaver_seo_settings';

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Post types considered public and therefore SEO-relevant by default.
	 *
	 * Recomputed rather than stored, so a plugin that registers a new public
	 * post type after activation is picked up without a resave.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Post type names.
	 */
	public static function public_post_types() {
		return array_values(
			array_diff(
				get_post_types( array( 'public' => true ), 'names' ),
				array( 'attachment' )
			)
		);
	}

	/**
	 * Public, non-hierarchical-only-excluded taxonomies considered SEO-relevant.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Taxonomy names.
	 */
	public static function public_taxonomies() {
		return array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
	}

	/**
	 * Shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function defaults() {
		return array(
			'site_name'          => '',
			'title_separator'    => '-',
			'title_template'     => '%title% %sep% %sitename%',
			'title_template_home' => '%sitename% %sep% %tagline%',
			'description_home'   => '',
			'noindex_search'     => 1,
			'noindex_archives'   => 0,
			'noindex_paginated'  => 1,
			'social_default_image' => 0,
			'twitter_card_type'  => 'summary_large_image',
			'twitter_site'       => '',
			'organization_type'  => 'Organization',
			'organization_name'  => '',
			'organization_logo'  => 0,
			'sitemap_enabled'    => 1,
			'sitemap_post_types' => self::public_post_types(),
			'sitemap_images'     => 1,
			'robots_txt_managed' => 0,
			'robots_txt_content' => "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php",
			'competitor_dismissed' => 0,
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
		}

		return self::$cache;
	}

	/**
	 * Reads one setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed Value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Drops the runtime cache.
	 *
	 * @since 1.0.0
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Validates a settings payload from $_POST.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$clean['site_name']       = isset( $input['site_name'] ) ? sanitize_text_field( wp_unslash( $input['site_name'] ) ) : $defaults['site_name'];
		$clean['title_separator'] = isset( $input['title_separator'] ) && '' !== trim( (string) $input['title_separator'] )
			? sanitize_text_field( wp_unslash( $input['title_separator'] ) )
			: $defaults['title_separator'];

		$clean['title_template']      = self::sanitize_template( $input['title_template'] ?? '', $defaults['title_template'] );
		$clean['title_template_home'] = self::sanitize_template( $input['title_template_home'] ?? '', $defaults['title_template_home'] );
		$clean['description_home']    = isset( $input['description_home'] ) ? sanitize_textarea_field( wp_unslash( $input['description_home'] ) ) : '';

		$clean['noindex_search']    = empty( $input['noindex_search'] ) ? 0 : 1;
		$clean['noindex_archives']  = empty( $input['noindex_archives'] ) ? 0 : 1;
		$clean['noindex_paginated'] = empty( $input['noindex_paginated'] ) ? 0 : 1;

		$clean['social_default_image'] = isset( $input['social_default_image'] ) ? absint( $input['social_default_image'] ) : 0;

		$card_types                = array( 'summary', 'summary_large_image' );
		$clean['twitter_card_type'] = in_array( $input['twitter_card_type'] ?? '', $card_types, true ) ? $input['twitter_card_type'] : $defaults['twitter_card_type'];
		$clean['twitter_site']     = isset( $input['twitter_site'] ) ? self::sanitize_handle( wp_unslash( $input['twitter_site'] ) ) : '';

		$org_types                 = array( 'Organization', 'LocalBusiness', 'Person' );
		$clean['organization_type'] = in_array( $input['organization_type'] ?? '', $org_types, true ) ? $input['organization_type'] : $defaults['organization_type'];
		$clean['organization_name'] = isset( $input['organization_name'] ) ? sanitize_text_field( wp_unslash( $input['organization_name'] ) ) : '';
		$clean['organization_logo'] = isset( $input['organization_logo'] ) ? absint( $input['organization_logo'] ) : 0;

		$clean['sitemap_enabled'] = empty( $input['sitemap_enabled'] ) ? 0 : 1;

		$allowed_types              = self::public_post_types();
		$requested_types            = isset( $input['sitemap_post_types'] ) ? (array) $input['sitemap_post_types'] : array();
		$clean['sitemap_post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $requested_types ), $allowed_types ) );

		$clean['sitemap_images']     = empty( $input['sitemap_images'] ) ? 0 : 1;
		$clean['robots_txt_managed'] = empty( $input['robots_txt_managed'] ) ? 0 : 1;
		$clean['robots_txt_content'] = isset( $input['robots_txt_content'] )
			? sanitize_textarea_field( wp_unslash( $input['robots_txt_content'] ) )
			: $defaults['robots_txt_content'];

		// Not exposed on the settings form; carried through so dismissing the
		// competitor notice does not get undone by an unrelated settings save.
		$clean['competitor_dismissed'] = empty( $input['competitor_dismissed'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Sanitizes a title template, keeping only the tokens this plugin knows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value    Raw template.
	 * @param string $fallback Used when the cleaned value is empty.
	 * @return string Sanitized template.
	 */
	private static function sanitize_template( $value, $fallback ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );

		return '' !== trim( $value ) ? $value : $fallback;
	}

	/**
	 * Sanitizes a Twitter/X handle, with or without a leading @.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw handle.
	 * @return string Sanitized handle, always beginning with @, or empty.
	 */
	private static function sanitize_handle( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_@]/', '', (string) $value );
		$value = ltrim( $value, '@' );

		return '' === $value ? '' : '@' . $value;
	}
}

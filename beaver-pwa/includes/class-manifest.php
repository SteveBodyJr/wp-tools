<?php
/**
 * Web app manifest.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and serves the manifest a browser reads before offering to install.
 *
 * The manifest is generated on request rather than written to disk: there is
 * nothing to keep in sync, nothing to leave behind, and no write permission
 * needed anywhere outside the uploads folder.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Manifest {

	/**
	 * Assembles the manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function data() {
		$scope = Beaver_PWA_Settings::scope_path();

		$manifest = array(
			'id'               => Beaver_PWA_Settings::app_id(),
			'name'             => Beaver_PWA_Settings::app_name(),
			'short_name'       => Beaver_PWA_Settings::short_name(),
			'start_url'        => Beaver_PWA_Settings::start_url(),
			'scope'            => $scope,
			'display'          => Beaver_PWA_Settings::get( 'display' ),
			'orientation'      => Beaver_PWA_Settings::get( 'orientation' ),
			'theme_color'      => Beaver_PWA_Settings::get( 'theme_color' ),
			'background_color' => Beaver_PWA_Settings::get( 'background_color' ),
			'lang'             => str_replace( '_', '-', (string) get_bloginfo( 'language' ) ),
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'icons'            => Beaver_PWA_Icons::manifest_icons(),
		);

		$description = Beaver_PWA_Settings::description();

		if ( '' !== $description ) {
			$manifest['description'] = $description;
		}

		// Give the browser a fallback chain rather than dropping straight to a tab.
		if ( 'standalone' === $manifest['display'] ) {
			$manifest['display_override'] = array( 'standalone', 'minimal-ui', 'browser' );
		}

		$categories = Beaver_PWA_Settings::categories();

		if ( $categories ) {
			$manifest['categories'] = $categories;
		}

		$shortcuts = self::shortcuts();

		if ( $shortcuts ) {
			$manifest['shortcuts'] = $shortcuts;
		}

		/**
		 * Filters the manifest immediately before it is encoded.
		 *
		 * @since 1.0.0
		 *
		 * @param array $manifest Manifest members.
		 */
		return (array) apply_filters( 'beaver_pwa_manifest', $manifest );
	}

	/**
	 * Builds the long-press shortcut menu from the chosen pages.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function shortcuts() {
		$rows  = (array) Beaver_PWA_Settings::get( 'shortcuts' );
		$icons = Beaver_PWA_Icons::manifest_icons();
		$icon  = array();

		foreach ( $icons as $candidate ) {
			if ( '192x192' === $candidate['sizes'] ) {
				$icon = array( $candidate );
				break;
			}
		}

		$shortcuts = array();

		foreach ( $rows as $row ) {
			$page_id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;

			if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
				continue;
			}

			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';

			if ( '' === $label ) {
				$label = wp_strip_all_tags( get_the_title( $page_id ) );
			}

			if ( '' === $label ) {
				continue;
			}

			$shortcut = array(
				'name' => $label,
				'url'  => get_permalink( $page_id ),
			);

			if ( $icon ) {
				$shortcut['icons'] = $icon;
			}

			$shortcuts[] = $shortcut;
		}

		return $shortcuts;
	}

	/**
	 * Encoded manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function to_json() {
		return (string) wp_json_encode( self::data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Sends the manifest and stops.
	 *
	 * @since 1.0.0
	 */
	public static function serve() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );

		echo self::to_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.

		exit;
	}
}

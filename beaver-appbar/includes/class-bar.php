<?php
/**
 * The bar itself: assets and front-end markup.
 *
 * @package BeaverAppBar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Draws the bar, and nothing else.
 *
 * Every decision here is aimed at one thing: a site with the bar switched off,
 * or a visitor on a screen too wide for it, should be unable to tell the plugin
 * is installed. The assets are enqueued behind the same test that renders the
 * markup, so "off" means no stylesheet, no script and no HTML rather than
 * something hidden with CSS.
 *
 * @since 1.0.0
 */
class Beaver_AppBar_Bar {

	/**
	 * Cached answer for the current request.
	 *
	 * @var bool|null
	 */
	private static $render = null;

	/**
	 * Cached prepared items.
	 *
	 * @var array|null
	 */
	private static $items = null;

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// 5, not the default 10: footer scripts print on wp_footer at 20, and a
		// callback added later at the same priority runs after them, which put
		// the script tag above the markup it looks for. The script copes with
		// either order on its own, but the markup belongs first regardless.
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
	}

	/**
	 * Whether the bar is being drawn on this request.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the bar will render.
	 */
	public static function should_render() {
		if ( null !== self::$render ) {
			return self::$render;
		}

		$show = (bool) Beaver_AppBar_Settings::get( 'enabled' );

		if ( $show && ( is_admin() || is_feed() || is_embed() ) ) {
			$show = false;
		}

		if ( $show && count( self::items() ) < 2 ) {
			$show = false; // A single tab is not a navigation bar.
		}

		/**
		 * Filters whether the bar appears on this request.
		 *
		 * Return false to hide it on particular templates, for logged-out users,
		 * inside a checkout, and so on.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $show Whether to render.
		 */
		self::$render = (bool) apply_filters( 'beaver_appbar_show', $show );

		return self::$render;
	}

	/**
	 * Loads the stylesheet and script, only when there is a bar to style.
	 *
	 * @since 1.0.0
	 */
	public static function enqueue() {
		if ( ! self::should_render() ) {
			return;
		}

		wp_enqueue_style( 'beaver-appbar', BEAVER_APPBAR_URL . 'assets/css/appbar.css', array(), BEAVER_APPBAR_VERSION );
		wp_enqueue_script( 'beaver-appbar', BEAVER_APPBAR_URL . 'assets/js/appbar.js', array(), BEAVER_APPBAR_VERSION, true );

		wp_add_inline_style( 'beaver-appbar', self::inline_css() );
	}

	/**
	 * The handful of rules that depend on the settings.
	 *
	 * The breakpoint is one of them, and a media query cannot read a custom
	 * property, so the width the bar stops at is written here rather than being
	 * three sets of rules in the stylesheet waiting for a class that selects one.
	 *
	 * `html body` rather than `body`: it beats the theme's own `body` rule on
	 * specificity whichever stylesheet the site happens to load second, so the
	 * page always gets its bottom spacing.
	 *
	 * @since 1.0.0
	 *
	 * @return string CSS.
	 */
	private static function inline_css() {
		$settings = Beaver_AppBar_Settings::all();
		$devices  = Beaver_AppBar_Settings::device_options();
		$upto     = isset( $devices[ $settings['devices'] ] ) ? (int) $devices[ $settings['devices'] ]['upto'] : 0;

		// The height the bar occupies is worked out here, where the style and the
		// labels are both known, rather than as four combinations of classes in
		// the stylesheet. Everything that has to make room for the bar reads it.
		$bar = empty( $settings['labels'] ) ? '54px' : '62px';
		$gap = 'float' === $settings['style'] ? '14px' : '0px';

		$css = ':root{--bappbar-accent:' . $settings['accent'] . ';--bappbar-bar:' . $bar . ';--bappbar-gap:' . $gap . '}';

		// Space at the foot of the page so the bar never covers the last of it.
		// The script moves this onto the footer where there is one, which keeps a
		// dark footer running to the bottom edge instead of ending in a strip of
		// page background.
		$css .= 'html body{padding-bottom:var(--bappbar-h,0px)}';
		$css .= 'html.bappbar-footer-spaced body{padding-bottom:0}';

		if ( $upto > 0 ) {
			$css .= '@media(min-width:' . ( $upto + 1 ) . 'px){';
			$css .= '.beaver-appbar,.beaver-appbar-sheet{display:none}';
			$css .= 'html body,html.bappbar-footer-spaced body{padding-bottom:0}';
			$css .= ':root{--bappbar-h:0px}';
			$css .= '}';
		}

		return $css;
	}

	/**
	 * The items, resolved into something renderable.
	 *
	 * Rows that cannot work are dropped rather than shown broken: a WhatsApp tab
	 * with no number saved, a call tab with no phone number, a link with nothing
	 * in it.
	 *
	 * @since 1.0.0
	 *
	 * @return array Prepared items.
	 */
	public static function items() {
		if ( null !== self::$items ) {
			return self::$items;
		}

		$settings = Beaver_AppBar_Settings::all();
		$out      = array();

		foreach ( (array) $settings['items'] as $row ) {
			$item = self::prepare( $row, $settings );

			if ( $item ) {
				$out[] = $item;
			}
		}

		self::$items = $out;

		return self::$items;
	}

	/**
	 * Turns one saved row into a renderable item.
	 *
	 * @since 1.0.0
	 *
	 * @param array $row      Saved row.
	 * @param array $settings All settings.
	 * @return array|false The item, or false when it cannot work.
	 */
	private static function prepare( $row, $settings ) {
		$label = trim( (string) ( $row['label'] ?? '' ) );

		if ( '' === $label ) {
			return false;
		}

		$type     = (string) ( $row['type'] ?? 'link' );
		$url      = '';
		$external = false;
		$sheet    = '';

		switch ( $type ) {
			case 'whatsapp':
				$number = preg_replace( '/[^0-9]/', '', (string) $settings['whatsapp'] );
				$url    = $number ? 'https://wa.me/' . $number : '';

				$external = true;
				break;

			case 'call':
				$number = preg_replace( '/[^0-9+]/', '', (string) $settings['phone'] );
				$url    = $number ? 'tel:' . $number : '';
				break;

			case 'email':
				$url = is_email( $settings['email'] ) ? 'mailto:' . $settings['email'] : '';
				break;

			case 'menu':
			case 'search':
				$sheet = $type;
				break;

			case 'top':
				break;

			default:
				$type = 'link';
				$url  = self::resolve_url( (string) ( $row['url'] ?? '' ) );
				break;
		}

		// Everything except the buttons needs somewhere to go.
		if ( '' === $url && '' === $sheet && 'top' !== $type ) {
			return false;
		}

		return array(
			'label'    => $label,
			'icon'     => (string) ( $row['icon'] ?? 'home' ),
			'type'     => $type,
			'url'      => $url,
			'sheet'    => $sheet,
			'cta'      => ! empty( $row['cta'] ),
			'external' => $external || self::is_external( $url ),
		);
	}

	/**
	 * Resolves whatever was typed into the link field.
	 *
	 * A bare "#section" is left exactly as typed, because that is what it means
	 * in HTML: an anchor on the page you are already on. A homepage section is
	 * written "/#section", which does resolve against the site address and so
	 * works from anywhere on the site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Saved value.
	 * @return string URL, or '' when the field is empty.
	 */
	private static function resolve_url( $raw ) {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '#^(https?:|mailto:|tel:)#i', $raw ) || '#' === $raw[0] ) {
			return $raw;
		}

		return home_url( '/' . ltrim( $raw, '/' ) );
	}

	/**
	 * Whether a link leaves the site, and so should open in a new tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Link.
	 * @return bool True when the host is not this site's.
	 */
	private static function is_external( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false; // Same-site paths, anchors, tel: and mailto:.
		}

		return strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Whether an item points at the page being viewed.
	 *
	 * Links carrying an anchor are left alone: on a one-page site they all share
	 * the same path, so the script highlights whichever section is on screen
	 * instead of the server guessing.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Prepared item.
	 * @return bool True when this is the current page.
	 */
	private static function is_current( $item ) {
		if ( 'link' !== $item['type'] || $item['external'] || '' === $item['url'] ) {
			return false;
		}

		if ( wp_parse_url( $item['url'], PHP_URL_FRAGMENT ) || '#' === $item['url'][0] ) {
			return false;
		}

		// Parsed and compared only, never printed. phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$here    = untrailingslashit( (string) wp_parse_url( $request, PHP_URL_PATH ) );
		$there   = untrailingslashit( (string) wp_parse_url( $item['url'], PHP_URL_PATH ) );

		return $here === $there;
	}

	/**
	 * Prints the bar and any sheets it opens.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! self::should_render() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts in markup().
		echo self::markup();

		self::render_sheets( self::items(), Beaver_AppBar_Settings::all() );
	}

	/**
	 * Builds the bar's markup.
	 *
	 * Shared with the settings screen, so the preview an admin is looking at is
	 * the same HTML and the same stylesheet as the thing visitors get, rather
	 * than a drawing of it that can drift.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $preview Light the first tab regardless of the current URL.
	 * @return string HTML.
	 */
	public static function markup( $preview = false ) {
		$settings = Beaver_AppBar_Settings::all();
		$items    = self::items();

		$classes = array(
			'beaver-appbar',
			'beaver-appbar--' . $settings['style'],
			'beaver-appbar--' . $settings['scheme'],
		);

		if ( empty( $settings['labels'] ) ) {
			$classes[] = 'beaver-appbar--nolabels';
		}

		$html = sprintf(
			'<nav class="%s" aria-label="%s"%s><ul class="beaver-appbar__row">',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr__( 'Quick navigation', 'beaver-appbar' ),
			empty( $settings['autohide'] ) ? '' : ' data-autohide'
		);

		foreach ( $items as $index => $item ) {
			$html .= '<li class="beaver-appbar__cell">' . self::item_markup( $item, $preview && 0 === $index ) . '</li>';
		}

		return $html . '</ul></nav>';
	}

	/**
	 * Builds one tab.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item  Prepared item.
	 * @param bool  $force Mark this one as current whatever the URL says.
	 * @return string HTML.
	 */
	private static function item_markup( $item, $force = false ) {
		$current = $force || self::is_current( $item );

		$classes = array( 'beaver-appbar__item' );

		if ( $item['cta'] ) {
			$classes[] = 'beaver-appbar__item--cta';
		}

		if ( $current ) {
			$classes[] = 'is-active';
		}

		$inner = '<span class="beaver-appbar__ico">' . Beaver_AppBar_Icons::svg( $item['icon'] ) . '</span>'
			. '<span class="beaver-appbar__lb">' . esc_html( $item['label'] ) . '</span>';

		// A tab that opens a panel or scrolls the page is a button. Only a tab
		// that goes somewhere is a link.
		if ( '' === $item['url'] ) {
			return sprintf(
				'<button type="button" class="%1$s"%2$s%3$s>%4$s</button>',
				esc_attr( implode( ' ', $classes ) ),
				$item['sheet'] ? ' data-appbar-sheet="' . esc_attr( $item['sheet'] ) . '" aria-haspopup="dialog" aria-expanded="false"' : '',
				'top' === $item['type'] ? ' data-appbar-top' : '',
				$inner
			);
		}

		return sprintf(
			'<a class="%1$s" href="%2$s"%3$s%4$s>%5$s</a>',
			esc_attr( implode( ' ', $classes ) ),
			esc_url( $item['url'] ),
			$current ? ' aria-current="page"' : '',
			$item['external'] ? ' target="_blank" rel="noopener noreferrer"' : '',
			$inner
		);
	}

	/**
	 * Prints the sheets, but only the ones an item actually opens.
	 *
	 * @since 1.0.0
	 *
	 * @param array $items    Prepared items.
	 * @param array $settings All settings.
	 */
	private static function render_sheets( $items, $settings ) {
		$needed = array();

		foreach ( $items as $item ) {
			if ( $item['sheet'] ) {
				$needed[ $item['sheet'] ] = true;
			}
		}

		if ( ! $needed ) {
			return;
		}

		$scheme = 'beaver-appbar-sheet--' . $settings['scheme'];

		foreach ( array_keys( $needed ) as $sheet ) {
			$title = 'search' === $sheet
				? __( 'Search this site', 'beaver-appbar' )
				: __( 'Menu', 'beaver-appbar' );
			?>
			<div class="beaver-appbar-sheet <?php echo esc_attr( $scheme ); ?>" data-appbar-panel="<?php echo esc_attr( $sheet ); ?>" hidden>
				<div class="beaver-appbar-sheet__veil" data-appbar-close></div>
				<div class="beaver-appbar-sheet__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $title ); ?>">
					<div class="beaver-appbar-sheet__head">
						<span class="beaver-appbar-sheet__title"><?php echo esc_html( $title ); ?></span>
						<button type="button" class="beaver-appbar-sheet__close" data-appbar-close aria-label="<?php esc_attr_e( 'Close', 'beaver-appbar' ); ?>">
							<?php echo Beaver_AppBar_Icons::svg( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SVG. ?>
						</button>
					</div>
					<div class="beaver-appbar-sheet__body">
						<?php
						if ( 'search' === $sheet ) {
							get_search_form();
						} else {
							self::render_menu( $settings );
						}
						?>
					</div>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Prints the menu inside the menu sheet.
	 *
	 * Falls back rather than printing nothing: the chosen menu, then whatever is
	 * assigned to the theme's primary location, then the site's pages. A sheet
	 * that opens on an empty panel is worse than any of the three.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings All settings.
	 */
	private static function render_menu( $settings ) {
		$args = array(
			'container'   => false,
			'menu_class'  => 'beaver-appbar-menu',
			'depth'       => 2,
			'fallback_cb' => false,
		);

		$menu_id = (int) $settings['menu'];

		if ( $menu_id && wp_get_nav_menu_object( $menu_id ) ) {
			$args['menu'] = $menu_id;

			wp_nav_menu( $args );

			return;
		}

		foreach ( array( 'primary', 'main', 'menu-1', 'header' ) as $location ) {
			if ( has_nav_menu( $location ) ) {
				$args['theme_location'] = $location;

				wp_nav_menu( $args );

				return;
			}
		}

		echo '<ul class="beaver-appbar-menu">';
		wp_list_pages(
			array(
				'title_li' => '',
				'depth'    => 2,
			)
		);
		echo '</ul>';
	}
}

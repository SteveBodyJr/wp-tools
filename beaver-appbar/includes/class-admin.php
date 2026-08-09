<?php
/**
 * Admin screen.
 *
 * @package BeaverAppBar
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen: is the bar on, how does it look, and what is in it.
 *
 * Under Appearance rather than a top-level menu, because that is what it is —
 * a piece of the site's navigation, not a tool of its own. The plugins-list row
 * carries a shortcut for anyone who looks there first.
 *
 * @since 1.0.0
 */
class Beaver_AppBar_Admin {

	const NONCE      = 'beaver_appbar_save';
	const CAPABILITY = 'edit_theme_options';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Adds the screen under Appearance.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_theme_page(
			__( 'App Bar', 'beaver-appbar' ),
			__( 'App Bar', 'beaver-appbar' ),
			self::CAPABILITY,
			BEAVER_APPBAR_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads assets on this screen only.
	 *
	 * The front-end stylesheet is loaded here too, so the preview is the real
	 * bar rather than an imitation of it that can fall out of step. The admin
	 * stylesheet, which loads after it, is what lifts the bar out of its fixed
	 * position and into the preview box.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, BEAVER_APPBAR_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-appbar', BEAVER_APPBAR_URL . 'assets/css/appbar.css', array(), BEAVER_APPBAR_VERSION );
		wp_enqueue_style( 'beaver-appbar-admin', BEAVER_APPBAR_URL . 'admin/css/admin.css', array( 'beaver-appbar' ), BEAVER_APPBAR_VERSION );
		wp_enqueue_script( 'beaver-appbar-admin', BEAVER_APPBAR_URL . 'admin/js/admin.js', array(), BEAVER_APPBAR_VERSION, true );

		$settings = Beaver_AppBar_Settings::all();
		$bar      = empty( $settings['labels'] ) ? '54px' : '62px';

		wp_add_inline_style(
			'beaver-appbar-admin',
			':root{--bappbar-accent:' . $settings['accent'] . ';--bappbar-bar:' . $bar . ';--bappbar-gap:0px}'
		);

		wp_localize_script(
			'beaver-appbar-admin',
			'beaverAppBar',
			array(
				'max'   => Beaver_AppBar_Settings::MAX_ITEMS,
				'icons' => self::icon_svgs(),
				'i18n'  => array(
					'full'   => sprintf(
						/* translators: %d: maximum number of items. */
						__( 'The bar holds %d items. Remove one before adding another.', 'beaver-appbar' ),
						Beaver_AppBar_Settings::MAX_ITEMS
					),
					'remove' => __( 'Remove this item?', 'beaver-appbar' ),
				),
			)
		);
	}

	/**
	 * Every icon as markup, for the picker's live preview.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Key => SVG.
	 */
	private static function icon_svgs() {
		$out = array();

		foreach ( array_keys( Beaver_AppBar_Icons::choices() ) as $key ) {
			$out[ $key ] = Beaver_AppBar_Icons::svg( $key, 20 );
		}

		return $out;
	}

	/**
	 * Handles the form post.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['beaver_appbar_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		// The whole payload goes through Settings::sanitize(), which is the only
		// place that decides what a valid setting is.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above, sanitized in Settings::sanitize().
		Beaver_AppBar_Settings::update(
			array(
				'enabled'  => isset( $_POST['enabled'] ) ? 1 : 0,
				'labels'   => isset( $_POST['labels'] ) ? 1 : 0,
				'autohide' => isset( $_POST['autohide'] ) ? 1 : 0,
				'devices'  => wp_unslash( $_POST['devices'] ?? '' ),
				'style'    => wp_unslash( $_POST['style'] ?? '' ),
				'scheme'   => wp_unslash( $_POST['scheme'] ?? '' ),
				'accent'   => wp_unslash( $_POST['accent'] ?? '' ),
				'whatsapp' => wp_unslash( $_POST['whatsapp'] ?? '' ),
				'phone'    => wp_unslash( $_POST['phone'] ?? '' ),
				'email'    => wp_unslash( $_POST['email'] ?? '' ),
				'menu'     => wp_unslash( $_POST['menu'] ?? 0 ),
				'items'    => isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		wp_safe_redirect( add_query_arg( 'ba_saved', '1', admin_url( 'themes.php?page=' . BEAVER_APPBAR_SLUG ) ) );
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-appbar' ) );
		}

		$settings = Beaver_AppBar_Settings::all();
		$devices  = Beaver_AppBar_Settings::device_options();
		$items    = $settings['items'] ? $settings['items'] : array( array() );
		?>
		<div class="wrap beaver-appbar-admin">
			<h1><?php esc_html_e( 'App Bar', 'beaver-appbar' ); ?></h1>

			<?php if ( isset( $_GET['ba_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'beaver-appbar' ); ?></p></div>
			<?php endif; ?>

			<p class="beaver-appbar-lede">
				<?php esc_html_e( 'A row of icons fixed to the bottom of the screen, the way a phone app works, so the things people came for are one tap away instead of a scroll back to the header.', 'beaver-appbar' ); ?>
			</p>

			<?php self::render_preview( $settings ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'themes.php?page=' . BEAVER_APPBAR_SLUG ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_appbar_action" value="save" />

				<h2><?php esc_html_e( 'Where it shows', 'beaver-appbar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Bottom bar', 'beaver-appbar' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'], 1 ); ?> />
								<?php esc_html_e( 'Show it on the site', 'beaver-appbar' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off means off: no markup, no stylesheet and no script are sent, so the site is exactly as it would be without the plugin.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-devices"><?php esc_html_e( 'Show on', 'beaver-appbar' ); ?></label></th>
						<td>
							<select id="ba-devices" name="devices">
								<?php foreach ( $devices as $key => $meta ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['devices'], $key ); ?>>
										<?php echo esc_html( $meta['label'] ); ?>
										<?php echo $meta['upto'] ? esc_html( sprintf( ' (up to %dpx)', (int) $meta['upto'] ) ) : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'A bottom bar is a phone pattern. On a computer the menu is already in reach, so it usually earns nothing there.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'How it looks', 'beaver-appbar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ba-style"><?php esc_html_e( 'Style', 'beaver-appbar' ); ?></label></th>
						<td>
							<select id="ba-style" name="style">
								<option value="glass" <?php selected( $settings['style'], 'glass' ); ?>><?php esc_html_e( 'Edge to edge, frosted glass', 'beaver-appbar' ); ?></option>
								<option value="float" <?php selected( $settings['style'], 'float' ); ?>><?php esc_html_e( 'Floating rounded bar with a margin', 'beaver-appbar' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-scheme"><?php esc_html_e( 'Light or dark', 'beaver-appbar' ); ?></label></th>
						<td>
							<select id="ba-scheme" name="scheme">
								<option value="auto" <?php selected( $settings['scheme'], 'auto' ); ?>><?php esc_html_e( 'Follow the visitor\'s device', 'beaver-appbar' ); ?></option>
								<option value="light" <?php selected( $settings['scheme'], 'light' ); ?>><?php esc_html_e( 'Always light', 'beaver-appbar' ); ?></option>
								<option value="dark" <?php selected( $settings['scheme'], 'dark' ); ?>><?php esc_html_e( 'Always dark', 'beaver-appbar' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Pick a fixed one if the site itself does not change with the device, or the bar will be the only thing on the page that does.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-accent"><?php esc_html_e( 'Accent colour', 'beaver-appbar' ); ?></label></th>
						<td>
							<input type="color" id="ba-accent" name="accent" value="<?php echo esc_attr( $settings['accent'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The active tab, the highlighted action and the focus ring. Use the site\'s own brand colour.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behaviour', 'beaver-appbar' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="labels" value="1" <?php checked( $settings['labels'], 1 ); ?> />
								<?php esc_html_e( 'Show the word under each icon', 'beaver-appbar' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Icons alone look cleaner and are guessed wrong more often. Hiding the words keeps them for screen readers.', 'beaver-appbar' ); ?></p>
							<br />
							<label>
								<input type="checkbox" name="autohide" value="1" <?php checked( $settings['autohide'], 1 ); ?> />
								<?php esc_html_e( 'Hide while scrolling down', 'beaver-appbar' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'It returns the moment the visitor scrolls back up. Left off, it stays put, which is how a real app behaves.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'What goes in it', 'beaver-appbar' ); ?></h2>
				<p class="description beaver-appbar-hint">
					<?php
					printf(
						/* translators: %d: maximum number of items. */
						esc_html__( 'Up to %d, which is as many as fit on a phone. An item whose detail is missing is skipped rather than shown broken.', 'beaver-appbar' ),
						(int) Beaver_AppBar_Settings::MAX_ITEMS
					);
					?>
				</p>

				<table class="widefat striped beaver-appbar-items" id="beaver-appbar-items">
					<thead>
						<tr>
							<th class="beaver-appbar-items__order"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'beaver-appbar' ); ?></span></th>
							<th><?php esc_html_e( 'Label', 'beaver-appbar' ); ?></th>
							<th><?php esc_html_e( 'Icon', 'beaver-appbar' ); ?></th>
							<th><?php esc_html_e( 'Opens', 'beaver-appbar' ); ?></th>
							<th><?php esc_html_e( 'Link', 'beaver-appbar' ); ?></th>
							<th class="beaver-appbar-items__flag"><?php esc_html_e( 'Main action', 'beaver-appbar' ); ?></th>
							<th class="beaver-appbar-items__bin"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'beaver-appbar' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_values( $items ) as $index => $row ) : ?>
							<?php self::render_row( (int) $index, (array) $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="beaver-appbar-add"><?php esc_html_e( '+ Add item', 'beaver-appbar' ); ?></button>
				</p>

				<h2><?php esc_html_e( 'Details the items use', 'beaver-appbar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ba-whatsapp"><?php esc_html_e( 'WhatsApp number', 'beaver-appbar' ); ?></label></th>
						<td>
							<input type="text" id="ba-whatsapp" name="whatsapp" class="regular-text" value="<?php echo esc_attr( $settings['whatsapp'] ); ?>" placeholder="255760550617" />
							<p class="description"><?php esc_html_e( 'Digits including the country code, no plus and no spaces.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-phone"><?php esc_html_e( 'Phone number', 'beaver-appbar' ); ?></label></th>
						<td><input type="text" id="ba-phone" name="phone" class="regular-text" value="<?php echo esc_attr( $settings['phone'] ); ?>" placeholder="+255 760 550 617" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-email"><?php esc_html_e( 'Email address', 'beaver-appbar' ); ?></label></th>
						<td><input type="email" id="ba-email" name="email" class="regular-text" value="<?php echo esc_attr( $settings['email'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ba-menu"><?php esc_html_e( 'Menu in the sheet', 'beaver-appbar' ); ?></label></th>
						<td>
							<select id="ba-menu" name="menu">
								<option value="0"><?php esc_html_e( 'The theme\'s own main menu', 'beaver-appbar' ); ?></option>
								<?php foreach ( wp_get_nav_menus() as $menu ) : ?>
									<option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( $settings['menu'], $menu->term_id ); ?>>
										<?php echo esc_html( $menu->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used by an item set to "Menu sheet". With none chosen it follows whatever the theme has in its main menu position, and falls back to the site\'s pages.', 'beaver-appbar' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save', 'beaver-appbar' ) ); ?>
			</form>

			<?php self::render_credit(); ?>
		</div>

		<?php self::render_template(); ?>
		<?php
	}

	/**
	 * One item row.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $index Row index. Used only to group the fields; the order
	 *                     that counts is the order of the rows in the page.
	 * @param array $row   Saved values.
	 */
	private static function render_row( $index, $row ) {
		$name  = 'items[' . $index . ']';
		$id    = 'ba-item-' . $index;
		$label = $row['label'] ?? '';
		$icon  = $row['icon'] ?? 'home';
		$type  = $row['type'] ?? 'link';
		$url   = $row['url'] ?? '';

		// Set here as well as in the script, so the row is already right on the
		// first paint rather than rearranging itself once the page has loaded.
		$row_class = 'link' === $type ? 'beaver-appbar-item' : 'beaver-appbar-item is-linkless';
		?>
		<tr class="<?php echo esc_attr( $row_class ); ?>">
			<td class="beaver-appbar-items__order">
				<button type="button" class="button-link beaver-appbar-move" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'beaver-appbar' ); ?>">&#9650;</button>
				<button type="button" class="button-link beaver-appbar-move" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'beaver-appbar' ); ?>">&#9660;</button>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-label"><?php esc_html_e( 'Label', 'beaver-appbar' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id ); ?>-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" />
			</td>
			<td class="beaver-appbar-items__icon">
				<span class="beaver-appbar-swatch" data-icon-preview>
					<?php echo Beaver_AppBar_Icons::svg( $icon, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted SVG. ?>
				</span>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-icon"><?php esc_html_e( 'Icon', 'beaver-appbar' ); ?></label>
				<select id="<?php echo esc_attr( $id ); ?>-icon" name="<?php echo esc_attr( $name ); ?>[icon]" data-icon-select>
					<?php foreach ( Beaver_AppBar_Icons::choices() as $key => $text ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $icon, $key ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-type"><?php esc_html_e( 'Opens', 'beaver-appbar' ); ?></label>
				<select id="<?php echo esc_attr( $id ); ?>-type" name="<?php echo esc_attr( $name ); ?>[type]" data-type-select>
					<?php foreach ( Beaver_AppBar_Settings::types() as $key => $text ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-url"><?php esc_html_e( 'Link', 'beaver-appbar' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $id ); ?>-url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $url ); ?>" class="regular-text" placeholder="/contact/" data-url-field />
				<span class="beaver-appbar-note" data-url-note><?php esc_html_e( 'A path such as /contact/, a homepage section such as /#services, or a full address.', 'beaver-appbar' ); ?></span>
				<span class="beaver-appbar-item__auto"><?php esc_html_e( 'Nothing to fill in. This one uses the details saved below.', 'beaver-appbar' ); ?></span>
			</td>
			<td class="beaver-appbar-items__flag">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cta]" value="1" <?php checked( ! empty( $row['cta'] ), true ); ?> />
					<span class="screen-reader-text"><?php esc_html_e( 'Highlight as the main action', 'beaver-appbar' ); ?></span>
				</label>
			</td>
			<td class="beaver-appbar-items__bin">
				<button type="button" class="button-link beaver-appbar-remove" aria-label="<?php esc_attr_e( 'Remove this item', 'beaver-appbar' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * The blank row the Add button clones.
	 *
	 * Kept in a <template> so its fields are never posted with the form, and
	 * built by the same function as a real row so the two cannot drift apart.
	 *
	 * @since 1.0.0
	 */
	private static function render_template() {
		echo '<template id="beaver-appbar-template">';
		self::render_row( 0, array( 'label' => '', 'icon' => 'home', 'type' => 'link', 'url' => '' ) );
		echo '</template>';
	}

	/**
	 * The preview.
	 *
	 * The real markup and the real stylesheet, showing what is saved. Anything
	 * else would be a drawing of the bar that slowly stops matching it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings All settings.
	 */
	private static function render_preview( $settings ) {
		$items = Beaver_AppBar_Bar::items();
		?>
		<div class="beaver-appbar-preview">
			<div class="beaver-appbar-preview__phone">
				<div class="beaver-appbar-preview__screen">
					<?php if ( count( $items ) < 2 ) : ?>
						<p class="beaver-appbar-preview__empty"><?php esc_html_e( 'Add at least two items and the bar appears here.', 'beaver-appbar' ); ?></p>
					<?php else : ?>
						<?php echo Beaver_AppBar_Bar::markup( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts. ?>
					<?php endif; ?>
				</div>
			</div>
			<p class="beaver-appbar-note">
				<?php
				echo empty( $settings['enabled'] )
					? esc_html__( 'This is what is saved. The bar is currently switched off, so visitors do not see it.', 'beaver-appbar' )
					: esc_html__( 'This is what is saved, drawn with the same markup and stylesheet the site uses.', 'beaver-appbar' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-appbar-credit">
			<img class="beaver-appbar-credit__logo" width="300" height="152"
				src="<?php echo esc_url( BEAVER_APPBAR_URL . 'assets/digital-beaver-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-appbar' ); ?>" />
			<div class="beaver-appbar-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-appbar' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-appbar' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}

<?php
/**
 * Translated address prefixes: /fr/circuits/... instead of /fr/tours/..., /es/destinos/...
 *
 * The fixed first part of the addresses of a post type or taxonomy ("tours", "destinations",
 * "destination-area") gets its own word per language. A layer around BP_Slugs's path map:
 * - to_original() turns a translated prefix back into the original one before the map and
 *   WordPress read the address;
 * - to_language() puts the language's prefix on any path built in that language.
 * So posts, archives (/fr/circuits/), pages of archives (/fr/circuits/page/2/), terms and
 * feeds all follow, links, canonical, hreflang and sitemaps included (they go through
 * BP_Slugs). Old addresses with the original prefix get a 301 (BP_Slugs::old_address_target).
 *
 * Only when translated addresses are on, only for languages written in Latin letters, and
 * only for the prefixes the owner saved (empty = keep the original). Nothing changes until the
 * owner saves: Draft fills the boxes from stored translations (or the engine) for checking.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prefixes.
 */
final class BP_Slug_Bases {

	/** Language => [ original prefix => translated prefix ]. */
	const OPTION = 'beaver_press_slug_bases';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bp_slug_bases', array( __CLASS__, 'save' ) );
	}

	/**
	 * Original prefixes on this site: prefix => what it is for (post type or taxonomy label).
	 *
	 * @return array
	 */
	public static function originals() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && ! empty( $type->rewrite['slug'] ) ) {
				$out[ trim( (string) $type->rewrite['slug'], '/' ) ] = (string) $type->labels->name;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' !== $tax->name && ! empty( $tax->rewrite['slug'] ) ) {
				$out[ trim( (string) $tax->rewrite['slug'], '/' ) ] = (string) $tax->labels->name;
			}
		}
		unset( $out[''] );
		return (array) apply_filters( 'beaver_press_slug_bases', $out );
	}

	/**
	 * Saved prefixes (only languages and prefixes that still exist).
	 *
	 * @return array Language => [ original => translated ].
	 */
	public static function saved() {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $language => $bases ) {
			if ( ! BP_Slugs::latin_language( (string) $language ) ) {
				continue;
			}
			foreach ( (array) $bases as $original => $translated ) {
				if ( '' !== (string) $translated && $translated !== $original ) {
					$out[ $language ][ (string) $original ] = (string) $translated;
				}
			}
		}
		return $out;
	}

	/**
	 * Replace a prefix at the start of a path (whole segments only).
	 *
	 * @param string $path Path without slashes at the ends.
	 * @param string $from Prefix to find.
	 * @param string $to   Prefix to put.
	 * @return string|null Path, or null when it does not start with the prefix.
	 */
	private static function swap( $path, $from, $to ) {
		if ( $path === $from ) {
			return $to;
		}
		if ( 0 === strpos( $path, $from . '/' ) ) {
			return $to . substr( $path, strlen( $from ) );
		}
		return null;
	}

	/**
	 * A path in a language, its translated prefix turned back into the original.
	 *
	 * @param string $path     Path.
	 * @param string $language Language.
	 * @return string
	 */
	public static function to_original( $path, $language ) {
		foreach ( self::saved()[ $language ] ?? array() as $original => $translated ) {
			$swapped = self::swap( (string) $path, $translated, $original );
			if ( null !== $swapped ) {
				return $swapped;
			}
		}
		return (string) $path;
	}

	/**
	 * A path with original prefix, given the language's prefix.
	 *
	 * @param string $path     Path.
	 * @param string $language Language.
	 * @return string
	 */
	public static function to_language( $path, $language ) {
		foreach ( self::saved()[ $language ] ?? array() as $original => $translated ) {
			$swapped = self::swap( (string) $path, $original, $translated );
			if ( null !== $swapped ) {
				return $swapped;
			}
		}
		return (string) $path;
	}

	/**
	 * Why a prefix cannot be used in a language, or ''.
	 *
	 * @param string $translated Translated prefix.
	 * @param string $original   Its original.
	 * @param array  $others     Other translated prefixes of the language (original => translated).
	 * @return string
	 */
	public static function clash( $translated, $original, array $others ) {
		$originals = self::originals();
		if ( isset( $originals[ $translated ] ) ) {
			/* translators: %s: prefix */
			return sprintf( __( '"%s" is already the prefix of other addresses.', 'beaver-press' ), $translated );
		}
		foreach ( $others as $o => $t ) {
			if ( $o !== $original && $t === $translated ) {
				/* translators: %s: prefix */
				return sprintf( __( '"%s" is used twice.', 'beaver-press' ), $translated );
			}
		}
		$first = strtok( $translated, '/' );
		$page  = get_page_by_path( $first, OBJECT, array( 'page', 'post' ) );
		if ( $page && 'publish' === $page->post_status ) {
			/* translators: %s: prefix */
			return sprintf( __( '"%s" is the address of a page or post.', 'beaver-press' ), $first );
		}
		if ( in_array( $first, (array) array_values( (array) TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings()['url-slugs'] ), true ) ) {
			/* translators: %s: prefix */
			return sprintf( __( '"%s" is a language\'s address code.', 'beaver-press' ), $first );
		}
		return '';
	}

	/**
	 * Suggested prefix for a language: the original words, from stored translations first,
	 * then the engine when allowed.
	 *
	 * @param string $original Original prefix ("destination-area").
	 * @param string $label    What it is for ("Destination Areas").
	 * @param string $language Language.
	 * @param bool   $machine  Ask the engine when nothing is stored.
	 * @return string
	 */
	public static function draft( $original, $label, $language, $machine ) {
		$words = ucfirst( str_replace( array( '-', '_', '/' ), ' ', (string) $original ) );
		foreach ( array( $words, (string) $label ) as $text ) {
			$slug = BP_Slugs::suggest( $text, $language, false );
			if ( '' !== $slug ) {
				return $slug;
			}
		}
		return $machine ? BP_Slugs::suggest( $words, $language, true ) : '';
	}

	/**
	 * Save (or draft into the boxes).
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_slug_bases' );
		$posted    = isset( $_POST['bases'] ) && is_array( $_POST['bases'] ) ? wp_unslash( $_POST['bases'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value slugified below.
		$originals = self::originals();
		$languages = array_filter( array_keys( BP_Run::languages() ), array( 'BP_Slugs', 'latin_language' ) );
		$draft     = isset( $_POST['draft'] );
		$save      = array();
		$problems  = array();
		foreach ( $languages as $language ) {
			foreach ( $originals as $original => $label ) {
				$value = BP_Slugs::slugify( str_replace( '/', ' ', (string) ( $posted[ $language ][ $original ] ?? '' ) ) );
				if ( $draft && '' === $value ) {
					$value = self::draft( $original, $label, $language, true );
				}
				if ( '' !== $value && $value !== $original ) {
					$save[ $language ][ $original ] = $value;
				}
			}
			foreach ( $save[ $language ] ?? array() as $original => $value ) {
				$why = self::clash( $value, $original, $save[ $language ] );
				if ( '' !== $why ) {
					$problems[] = $language . ': ' . $why;
					unset( $save[ $language ][ $original ] );
				}
			}
		}
		$key = 'bp_slug_bases_' . get_current_user_id();
		if ( $draft ) {
			set_transient( $key, array( 'draft' => $save, 'problems' => $problems ), 600 );
		} else {
			update_option( self::OPTION, $save, false );
			BP_Slugs::forget_map();
			BP_Cache::clear(); // Ready pages carry the old links.
			set_transient( $key, array( 'saved' => true, 'problems' => $problems ), 60 );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '#bp-bases' ) );
		exit;
	}

	/**
	 * Card on the Set-up page (when translated addresses are on).
	 */
	public static function render() {
		if ( ! BP_Slugs::enabled() ) {
			return;
		}
		$languages = array_filter( BP_Run::languages(), array( 'BP_Slugs', 'latin_language' ), ARRAY_FILTER_USE_KEY );
		if ( ! $languages ) {
			return;
		}
		$key    = 'bp_slug_bases_' . get_current_user_id();
		$result = get_transient( $key );
		if ( false !== $result ) {
			delete_transient( $key );
		}
		$values = is_array( $result ) && isset( $result['draft'] ) ? $result['draft'] : self::saved();
		?>
		<div class="bp-card" id="bp-bases">
			<h2><?php esc_html_e( 'Address prefixes', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The fixed first part of addresses, per language: /fr/circuits/... instead of /fr/tours/... Empty keeps the original. Old addresses are sent to the new ones (301), and links, menus, sitemaps and language links follow. Languages not written in Latin letters keep the original addresses.', 'beaver-press' ); ?></p>
			<?php if ( is_array( $result ) && ! empty( $result['saved'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Prefixes saved. Pages are served at their new addresses now.', 'beaver-press' ); ?></p></div>
			<?php elseif ( is_array( $result ) && isset( $result['draft'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Drafted below and not saved yet: check each word, then Save prefixes.', 'beaver-press' ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $result ) && ! empty( $result['problems'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Left out:', 'beaver-press' ); ?> <?php echo esc_html( implode( ' ', (array) $result['problems'] ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bp_slug_bases" />
				<?php wp_nonce_field( 'bp_slug_bases' ); ?>
				<table class="widefat striped bp-bases">
					<thead><tr><th><?php esc_html_e( 'Prefix', 'beaver-press' ); ?></th>
						<?php foreach ( $languages as $name ) : ?>
							<th><?php echo esc_html( $name ); ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
						<?php foreach ( self::originals() as $original => $label ) : ?>
							<tr>
								<th scope="row"><code><?php echo esc_html( $original ); ?></code><br /><span class="description"><?php echo esc_html( $label ); ?></span></th>
								<?php foreach ( $languages as $code => $name ) : ?>
									<td><input type="text" class="regular-text bp-bases__in" name="bases[<?php echo esc_attr( $code ); ?>][<?php echo esc_attr( $original ); ?>]" value="<?php echo esc_attr( (string) ( $values[ $code ][ $original ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $original ); ?>" aria-label="<?php echo esc_attr( $original . ' - ' . $name ); ?>" /></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button class="button button-primary"><?php esc_html_e( 'Save prefixes', 'beaver-press' ); ?></button>
					<button class="button" name="draft" value="1" title="<?php esc_attr_e( 'Fills empty boxes from stored translations, or asks the engine (a few words). Nothing is saved.', 'beaver-press' ); ?>"><?php esc_html_e( 'Draft empty boxes', 'beaver-press' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}

<?php
/**
 * Admin screens and AJAX endpoints.
 *
 * @package BeaverFileManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the screens and answers every request the interface makes.
 *
 * Each endpoint runs the same three checks before it does anything: a nonce, the
 * capability, and — for anything that changes a file — the write policy.
 *
 * @since 1.0.0
 */
class Beaver_FM_Admin {

	const MENU_SLUG     = 'beaver-fm';
	const LOG_SLUG      = 'beaver-fm-log';
	const SETTINGS_SLUG = 'beaver-fm-settings';
	const NONCE_ACTION  = 'beaver_fm_ajax';

	/**
	 * Registers admin hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( 'Beaver_FM_Settings', 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );

		$endpoints = array(
			'list'          => 'ajax_list',
			'tree'          => 'ajax_tree',
			'read'          => 'ajax_read',
			'save'          => 'ajax_save',
			'create'        => 'ajax_create',
			'rename'        => 'ajax_rename',
			'delete'        => 'ajax_delete',
			'transfer'      => 'ajax_transfer',
			'chmod'         => 'ajax_chmod',
			'upload'        => 'ajax_upload',
			'zip'           => 'ajax_zip',
			'unzip'         => 'ajax_unzip',
			'search'        => 'ajax_search',
			'info'          => 'ajax_info',
			'backups'       => 'ajax_backups',
			'backup_read'   => 'ajax_backup_read',
			'backup_restore' => 'ajax_backup_restore',
			'trash'         => 'ajax_trash',
			'trash_restore' => 'ajax_trash_restore',
			'trash_delete'  => 'ajax_trash_delete',
			'trash_empty'   => 'ajax_trash_empty',
			'download'      => 'ajax_download',
			'preview'       => 'ajax_preview',
		);

		foreach ( $endpoints as $action => $method ) {
			add_action( 'wp_ajax_beaver_fm_' . $action, array( __CLASS__, $method ) );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Menu and assets
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the admin menu.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		$capability = Beaver_FM_Settings::capability();

		add_menu_page(
			__( 'Beaver FileManager', 'beaver-filemanager' ),
			__( 'Beaver Files', 'beaver-filemanager' ),
			$capability,
			self::MENU_SLUG,
			array( __CLASS__, 'render_manager' ),
			'dashicons-open-folder',
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'File Manager', 'beaver-filemanager' ),
			__( 'File Manager', 'beaver-filemanager' ),
			$capability,
			self::MENU_SLUG,
			array( __CLASS__, 'render_manager' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Activity Log', 'beaver-filemanager' ),
			__( 'Activity Log', 'beaver-filemanager' ),
			$capability,
			self::LOG_SLUG,
			array( __CLASS__, 'render_log' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'beaver-filemanager' ),
			__( 'Settings', 'beaver-filemanager' ),
			$capability,
			self::SETTINGS_SLUG,
			array( 'Beaver_FM_Settings', 'render' )
		);
	}

	/**
	 * Enqueues the interface on the file manager screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'beaver-fm-admin',
			BEAVER_FM_URL . 'admin/css/admin.css',
			array(),
			BEAVER_FM_VERSION
		);

		if ( false !== strpos( (string) $hook_suffix, self::LOG_SLUG ) || false !== strpos( (string) $hook_suffix, self::SETTINGS_SLUG ) ) {
			return;
		}

		$editor_settings = self::editor_settings();

		wp_enqueue_script(
			'beaver-fm-admin',
			BEAVER_FM_URL . 'admin/js/admin.js',
			$editor_settings ? array( 'code-editor' ) : array(),
			BEAVER_FM_VERSION,
			true
		);

		$start = isset( $_GET['path'] ) ? Beaver_FM_Filesystem::clean_relative( wp_unslash( $_GET['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_localize_script(
			'beaver-fm-admin',
			'beaverFM',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'root'           => Beaver_FM_Filesystem::root_path(),
				'startPath'      => $start,
				'canWrite'       => Beaver_FM_Settings::can_write(),
				'writeBlock'     => Beaver_FM_Settings::write_block_reason(),
				'canZip'         => Beaver_FM_Filesystem::can_zip(),
				'useTrash'       => (bool) Beaver_FM_Settings::value( 'use_trash' ),
				'maxUpload'      => Beaver_FM_Settings::max_upload_bytes(),
				'maxUploadText'  => size_format( Beaver_FM_Settings::max_upload_bytes() ),
				'shortcuts'      => self::shortcuts(),
				'editorSettings' => $editor_settings,
				'i18n'           => self::strings(),
			)
		);
	}

	/**
	 * Builds a CodeMirror settings object for each language the editor handles.
	 *
	 * Calling `wp_enqueue_code_editor()` once per type is what pulls in the
	 * matching linter, so the editor can flag a broken JSON file the same way
	 * the core theme editor does.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty when the user has syntax highlighting switched off.
	 */
	private static function editor_settings() {
		$samples = array(
			'php'  => 'sample.php',
			'js'   => 'sample.js',
			'json' => 'sample.json',
			'css'  => 'sample.css',
			'scss' => 'sample.scss',
			'html' => 'sample.html',
			'xml'  => 'sample.xml',
			'md'   => 'sample.md',
			'yaml' => 'sample.yml',
			'sql'  => 'sample.sql',
			'sh'   => 'sample.sh',
			'ini'  => 'sample.ini',
			'txt'  => 'sample.txt',
		);

		$settings = array();

		foreach ( $samples as $key => $file ) {
			$result = wp_enqueue_code_editor( array( 'file' => $file ) );

			if ( false === $result ) {
				return array();
			}

			$settings[ $key ] = $result;
		}

		return $settings;
	}

	/**
	 * Quick links to the folders people actually want, when they are reachable.
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	private static function shortcuts() {
		$root = Beaver_FM_Filesystem::root_path();

		if ( '' === $root ) {
			return array();
		}

		$candidates = array(
			array( __( 'Site root', 'beaver-filemanager' ), ABSPATH ),
			array( __( 'wp-content', 'beaver-filemanager' ), WP_CONTENT_DIR ),
			array( __( 'Themes', 'beaver-filemanager' ), get_theme_root() ),
			array( __( 'Plugins', 'beaver-filemanager' ), WP_PLUGIN_DIR ),
			array( __( 'Must-use plugins', 'beaver-filemanager' ), WPMU_PLUGIN_DIR ),
			array( __( 'Uploads', 'beaver-filemanager' ), Beaver_FM_Filesystem::uploads_dir() ),
			array( __( 'Active theme', 'beaver-filemanager' ), get_stylesheet_directory() ),
		);

		$shortcuts = array();
		$seen      = array();

		foreach ( $candidates as $candidate ) {
			list( $label, $path ) = $candidate;

			$real = realpath( $path );

			if ( ! $real ) {
				continue;
			}

			$real = Beaver_FM_Filesystem::norm( $real );

			if ( ! Beaver_FM_Filesystem::within( $real, $root ) ) {
				continue;
			}

			$relative = Beaver_FM_Filesystem::relative( $real );

			if ( isset( $seen[ $relative ] ) ) {
				continue;
			}

			$seen[ $relative ] = true;

			$shortcuts[] = array(
				'label' => $label,
				'path'  => $relative,
			);
		}

		return $shortcuts;
	}

	/**
	 * Strings the JavaScript builds sentences from.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string>
	 */
	private static function strings() {
		return array(
			'loading'        => __( 'Loading…', 'beaver-filemanager' ),
			'empty'          => __( 'This folder is empty.', 'beaver-filemanager' ),
			'noResults'      => __( 'Nothing matched that search.', 'beaver-filemanager' ),
			'items'          => __( '%d items', 'beaver-filemanager' ),
			'selected'       => __( '%d selected', 'beaver-filemanager' ),
			'searchResults'  => __( 'Search results for “%s”', 'beaver-filemanager' ),
			'searchCapped'   => __( 'Stopped early — this is a partial list. Narrow the search or start it from a smaller folder.', 'beaver-filemanager' ),
			'scanned'        => __( '%d files scanned', 'beaver-filemanager' ),
			'confirmDelete'  => __( 'Move %s to the trash?', 'beaver-filemanager' ),
			'confirmErase'   => __( 'Permanently delete %s? This cannot be undone.', 'beaver-filemanager' ),
			'confirmEmpty'   => __( 'Permanently delete everything in the trash?', 'beaver-filemanager' ),
			'oneItem'        => __( '“%s”', 'beaver-filemanager' ),
			'manyItems'      => __( '%d items', 'beaver-filemanager' ),
			'newFileTitle'   => __( 'New file', 'beaver-filemanager' ),
			'newFolderTitle' => __( 'New folder', 'beaver-filemanager' ),
			'renameTitle'    => __( 'Rename', 'beaver-filemanager' ),
			'zipTitle'       => __( 'Create archive', 'beaver-filemanager' ),
			'chmodTitle'     => __( 'Change permissions', 'beaver-filemanager' ),
			'nameLabel'      => __( 'Name', 'beaver-filemanager' ),
			'saved'          => __( 'Saved.', 'beaver-filemanager' ),
			'savedBackup'    => __( 'Saved. The previous version is in the history.', 'beaver-filemanager' ),
			'noChanges'      => __( 'Nothing to save — the file is unchanged.', 'beaver-filemanager' ),
			'unsaved'        => __( 'You have unsaved changes. Close the editor anyway?', 'beaver-filemanager' ),
			'saving'         => __( 'Saving…', 'beaver-filemanager' ),
			'uploading'      => __( 'Uploading %1$d of %2$d…', 'beaver-filemanager' ),
			'uploaded'       => __( '%d files uploaded.', 'beaver-filemanager' ),
			'copied'         => __( '%d items copied.', 'beaver-filemanager' ),
			'moved'          => __( '%d items moved.', 'beaver-filemanager' ),
			'deleted'        => __( '%d items deleted.', 'beaver-filemanager' ),
			'trashed'        => __( '%d items moved to the trash.', 'beaver-filemanager' ),
			'restored'       => __( 'Restored.', 'beaver-filemanager' ),
			'permsChanged'   => __( 'Permissions updated on %d items.', 'beaver-filemanager' ),
			'extracted'      => __( 'Extracted %d files.', 'beaver-filemanager' ),
			'archived'       => __( 'Archive created.', 'beaver-filemanager' ),
			'clipboardCopy'  => __( '%d items ready to copy.', 'beaver-filemanager' ),
			'clipboardCut'   => __( '%d items ready to move.', 'beaver-filemanager' ),
			'pathCopied'     => __( 'Path copied to the clipboard.', 'beaver-filemanager' ),
			'urlCopied'      => __( 'URL copied to the clipboard.', 'beaver-filemanager' ),
			'copyFailed'     => __( 'This browser would not let the page copy to the clipboard.', 'beaver-filemanager' ),
			'btnCopyPath'    => __( 'Copy full path', 'beaver-filemanager' ),
			'btnCopyUrl'     => __( 'Copy URL', 'beaver-filemanager' ),
			'notEditable'    => __( 'This file is not text, or it is larger than the editor limit. Download it instead.', 'beaver-filemanager' ),
			'requestFailed'  => __( 'The server did not answer. Check your connection and try again.', 'beaver-filemanager' ),
			'conflictSave'   => __( 'Overwrite the newer version on the server?', 'beaver-filemanager' ),
			'syntaxSave'     => __( 'Save the file anyway, syntax error and all?', 'beaver-filemanager' ),
			'trashEmpty'     => __( 'The trash is empty.', 'beaver-filemanager' ),
			'noBackups'      => __( 'No earlier versions have been saved yet.', 'beaver-filemanager' ),
			'restoreBackup'  => __( 'Load this version into the editor?', 'beaver-filemanager' ),
			'readOnlyFile'   => __( 'Read-only — the web server cannot write to this file.', 'beaver-filemanager' ),
			'line'           => __( 'Line %d', 'beaver-filemanager' ),
			'chmodHelp'      => __( 'Common values: 644 for files, 755 for folders.', 'beaver-filemanager' ),
			'applyRecursive' => __( 'Apply to everything inside as well', 'beaver-filemanager' ),
			'overwrite'      => __( 'Replace files that already exist', 'beaver-filemanager' ),
			'overwriteHint'  => __( 'Leave this unticked and anything that clashes is saved beside it with -1 added to the name.', 'beaver-filemanager' ),
			'skipTrash'      => __( 'Skip the trash and delete permanently', 'beaver-filemanager' ),
			'noZip'          => __( 'This server has no ZipArchive support, so archives cannot be created here.', 'beaver-filemanager' ),
			'uploadTooBig'   => __( '“%1$s” is larger than the %2$s upload limit.', 'beaver-filemanager' ),
			'extractTitle'   => __( 'Extract archive', 'beaver-filemanager' ),
			'extractAsk'     => __( 'Unpack “%s” into this folder?', 'beaver-filemanager' ),
			'moveHere'       => __( 'Move here', 'beaver-filemanager' ),
			'copyHere'       => __( 'Copy here', 'beaver-filemanager' ),
			'archiveName'    => __( 'Archive name', 'beaver-filemanager' ),
			'modeLabel'      => __( 'Mode', 'beaver-filemanager' ),
			'loadVersion'    => __( 'Load into editor', 'beaver-filemanager' ),

			'colName'        => __( 'Name', 'beaver-filemanager' ),
			'colSize'        => __( 'Size', 'beaver-filemanager' ),
			'colPerms'       => __( 'Permissions', 'beaver-filemanager' ),
			'colModified'    => __( 'Modified', 'beaver-filemanager' ),
			'colFrom'        => __( 'Came from', 'beaver-filemanager' ),
			'colDeleted'     => __( 'Deleted', 'beaver-filemanager' ),

			'btnEdit'        => __( 'Edit', 'beaver-filemanager' ),
			'btnPreview'     => __( 'Preview', 'beaver-filemanager' ),
			'btnDownload'    => __( 'Download', 'beaver-filemanager' ),
			'btnDetails'     => __( 'Details', 'beaver-filemanager' ),
			'btnClose'       => __( 'Close', 'beaver-filemanager' ),
			'btnDelete'      => __( 'Delete', 'beaver-filemanager' ),
			'btnRestore'     => __( 'Restore', 'beaver-filemanager' ),
			'btnApply'       => __( 'Apply', 'beaver-filemanager' ),
			'btnExtract'     => __( 'Extract', 'beaver-filemanager' ),
			'btnMove'        => __( 'Move', 'beaver-filemanager' ),
			'btnCopy'        => __( 'Copy', 'beaver-filemanager' ),
			'btnOverwrite'   => __( 'Overwrite', 'beaver-filemanager' ),
			'btnSaveAnyway'  => __( 'Save anyway', 'beaver-filemanager' ),
			'btnEmptyTrash'  => __( 'Empty trash', 'beaver-filemanager' ),

			'infoName'       => __( 'Name', 'beaver-filemanager' ),
			'infoPath'       => __( 'Path', 'beaver-filemanager' ),
			'infoFullPath'   => __( 'Full path', 'beaver-filemanager' ),
			'infoType'       => __( 'Type', 'beaver-filemanager' ),
			'infoSize'       => __( 'Size', 'beaver-filemanager' ),
			'infoPerms'      => __( 'Permissions', 'beaver-filemanager' ),
			'infoOwner'      => __( 'Owner', 'beaver-filemanager' ),
			'infoModified'   => __( 'Modified', 'beaver-filemanager' ),
			'infoCreated'    => __( 'Created', 'beaver-filemanager' ),
			'infoContains'   => __( 'Contains', 'beaver-filemanager' ),
			'infoCounts'     => __( '%1$d folders, %2$d files', 'beaver-filemanager' ),
			'infoCapped'     => __( ' (counted up to a limit)', 'beaver-filemanager' ),
			'infoDimensions' => __( 'Dimensions', 'beaver-filemanager' ),
			'infoChecksum'   => __( 'MD5', 'beaver-filemanager' ),
			'infoArchive'    => __( 'Inside the archive — %d entries', 'beaver-filemanager' ),
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Screens
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the maker's mark shown at the foot of every plugin screen.
	 *
	 * Attribution only — it never renders on the front end, and nothing in the
	 * plugin depends on it. Mirrors the credit block the other Digital Beaver
	 * plugins use, so the branding reads the same wherever a client meets it.
	 *
	 * @since 1.1.0
	 */
	public static function render_credit() {
		?>
		<div class="beaver-fm-credit">
			<img class="beaver-fm-credit__logo" width="300" height="152"
				src="<?php echo esc_url( BEAVER_FM_URL . 'assets/digital-beaver-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-filemanager' ); ?>" />
			<div class="beaver-fm-credit__text">
				<strong><?php esc_html_e( 'Designed &amp; built by Digital Beaver', 'beaver-filemanager' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site built like this one?', 'beaver-filemanager' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Replaces the admin footer credit on this plugin's screens.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Existing footer text.
	 * @return string Filtered footer text.
	 */
	public static function footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return $text;
		}

		return sprintf(
			/* translators: 1: plugin name, 2: linked company name. */
			esc_html__( '%1$s — built by %2$s', 'beaver-filemanager' ),
			'<strong>' . esc_html__( 'Beaver FileManager', 'beaver-filemanager' ) . '</strong>',
			'<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">Digital Beaver</a>'
		);
	}

	/**
	 * Renders the file manager screen.
	 *
	 * The markup here is only the shell — the listing, tree and dialogs are
	 * filled in by the script so that navigating a folder never reloads WP.
	 *
	 * @since 1.0.0
	 */
	public static function render_manager() {
		if ( ! Beaver_FM_Settings::user_can_browse() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'beaver-filemanager' ) );
		}

		$root     = Beaver_FM_Filesystem::root_path();
		$writable = Beaver_FM_Settings::can_write();
		$disk     = Beaver_FM_Filesystem::disk();

		?>
		<div class="wrap beaver-fm" id="beaver-fm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Beaver FileManager', 'beaver-filemanager' ); ?></h1>

			<?php if ( '' === $root ) : ?>
				<div class="notice notice-error">
					<p>
						<?php esc_html_e( 'The folder this manager is set to browse does not exist on this server.', 'beaver-filemanager' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Fix it in Settings', 'beaver-filemanager' ); ?></a>
					</p>
				</div>
				<?php
				self::render_credit();
				?>
				</div>
				<?php
				return;
			endif;
			?>

			<p class="beaver-fm-rootline">
				<code><?php echo esc_html( $root ); ?></code>
				<?php if ( '' !== $disk['freeText'] ) : ?>
					<span class="beaver-fm-rootline__disk">
						<?php
						printf(
							/* translators: 1: free space, 2: total space. */
							esc_html__( '%1$s free of %2$s', 'beaver-filemanager' ),
							esc_html( $disk['freeText'] ),
							esc_html( $disk['totalText'] )
						);
						?>
					</span>
				<?php endif; ?>
			</p>

			<?php if ( ! $writable ) : ?>
				<div class="notice notice-warning inline beaver-fm-readonly-notice">
					<p>
						<strong><?php esc_html_e( 'Read-only.', 'beaver-filemanager' ); ?></strong>
						<?php echo esc_html( Beaver_FM_Settings::write_block_reason() ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Settings', 'beaver-filemanager' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="beaver-fm-app" data-writable="<?php echo $writable ? '1' : '0'; ?>">

				<div class="beaver-fm-toolbar">
					<div class="beaver-fm-toolbar__group">
						<button type="button" class="button" data-fm="up" title="<?php esc_attr_e( 'Up one folder', 'beaver-filemanager' ); ?>">
							<span class="dashicons dashicons-arrow-up-alt"></span>
						</button>
						<button type="button" class="button" data-fm="refresh" title="<?php esc_attr_e( 'Refresh', 'beaver-filemanager' ); ?>">
							<span class="dashicons dashicons-update"></span>
						</button>
					</div>

					<div class="beaver-fm-toolbar__group beaver-fm-writeonly">
						<button type="button" class="button" data-fm="new-file"><span class="dashicons dashicons-media-default"></span> <?php esc_html_e( 'New file', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button" data-fm="new-folder"><span class="dashicons dashicons-portfolio"></span> <?php esc_html_e( 'New folder', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button" data-fm="upload"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Upload', 'beaver-filemanager' ); ?></button>
					</div>

					<div class="beaver-fm-toolbar__group">
						<button type="button" class="button" data-fm="download" data-needs="selection"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="copy" data-needs="selection"><?php esc_html_e( 'Copy', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="cut" data-needs="selection"><?php esc_html_e( 'Cut', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="paste" data-needs="clipboard"><?php esc_html_e( 'Paste', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="rename" data-needs="one"><?php esc_html_e( 'Rename', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="chmod" data-needs="selection"><?php esc_html_e( 'Permissions', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="zip" data-needs="selection"><?php esc_html_e( 'Compress', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-writeonly" data-fm="unzip" data-needs="archive"><?php esc_html_e( 'Extract', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button beaver-fm-danger beaver-fm-writeonly" data-fm="delete" data-needs="selection"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'beaver-filemanager' ); ?></button>
					</div>

					<div class="beaver-fm-toolbar__spacer"></div>

					<div class="beaver-fm-toolbar__group">
						<button type="button" class="button" data-fm="trash" title="<?php esc_attr_e( 'Trash', 'beaver-filemanager' ); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Trash', 'beaver-filemanager' ); ?></button>
					</div>

					<div class="beaver-fm-search">
						<input type="search" id="beaver-fm-search-input" placeholder="<?php esc_attr_e( 'Search this folder…', 'beaver-filemanager' ); ?>">
						<button type="button" class="button" data-fm="search"><span class="dashicons dashicons-search"></span></button>
						<label class="beaver-fm-search__opt"><input type="checkbox" id="beaver-fm-search-contents"> <?php esc_html_e( 'in file contents', 'beaver-filemanager' ); ?></label>
					</div>
				</div>

				<div class="beaver-fm-body">
					<aside class="beaver-fm-sidebar">
						<div class="beaver-fm-shortcuts" id="beaver-fm-shortcuts"></div>
						<div class="beaver-fm-tree" id="beaver-fm-tree"></div>
					</aside>

					<main class="beaver-fm-main">
						<nav class="beaver-fm-crumbs" id="beaver-fm-crumbs"></nav>
						<div class="beaver-fm-listing" id="beaver-fm-listing" tabindex="0"></div>
						<div class="beaver-fm-dropzone" id="beaver-fm-dropzone" hidden>
							<div><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Drop files to upload here', 'beaver-filemanager' ); ?></div>
						</div>
					</main>
				</div>

				<div class="beaver-fm-status">
					<span id="beaver-fm-status-left"></span>
					<span id="beaver-fm-status-right"></span>
				</div>
			</div>

			<input type="file" id="beaver-fm-file-input" multiple hidden>

			<!-- Editor -->
			<div class="beaver-fm-overlay" id="beaver-fm-editor" hidden>
				<div class="beaver-fm-overlay__panel beaver-fm-editor">
					<header class="beaver-fm-editor__head">
						<div class="beaver-fm-editor__title">
							<strong id="beaver-fm-editor-name"></strong>
							<span id="beaver-fm-editor-path"></span>
						</div>
						<div class="beaver-fm-editor__actions">
							<span class="beaver-fm-editor__state" id="beaver-fm-editor-state"></span>
							<button type="button" class="button" data-fm="editor-history"><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'History', 'beaver-filemanager' ); ?></button>
							<button type="button" class="button button-primary" data-fm="editor-save"><?php esc_html_e( 'Save', 'beaver-filemanager' ); ?></button>
							<button type="button" class="button" data-fm="editor-close"><?php esc_html_e( 'Close', 'beaver-filemanager' ); ?></button>
						</div>
					</header>
					<div class="beaver-fm-editor__body">
						<textarea id="beaver-fm-editor-area" spellcheck="false"></textarea>
						<aside class="beaver-fm-editor__history" id="beaver-fm-editor-history" hidden>
							<h3><?php esc_html_e( 'Earlier versions', 'beaver-filemanager' ); ?></h3>
							<div id="beaver-fm-editor-history-list"></div>
						</aside>
					</div>
					<footer class="beaver-fm-editor__foot">
						<span id="beaver-fm-editor-meta"></span>
						<span class="beaver-fm-editor__hint"><?php esc_html_e( 'Ctrl/Cmd + S saves. Esc closes.', 'beaver-filemanager' ); ?></span>
					</footer>
				</div>
			</div>

			<!-- Preview -->
			<div class="beaver-fm-overlay" id="beaver-fm-preview" hidden>
				<div class="beaver-fm-overlay__panel beaver-fm-preview">
					<header class="beaver-fm-preview__head">
						<strong id="beaver-fm-preview-name"></strong>
						<div>
							<a href="#" class="button" id="beaver-fm-preview-download"><?php esc_html_e( 'Download', 'beaver-filemanager' ); ?></a>
							<button type="button" class="button" data-fm="preview-close"><?php esc_html_e( 'Close', 'beaver-filemanager' ); ?></button>
						</div>
					</header>
					<div class="beaver-fm-preview__body" id="beaver-fm-preview-body"></div>
				</div>
			</div>

			<!-- Details -->
			<div class="beaver-fm-overlay" id="beaver-fm-info" hidden>
				<div class="beaver-fm-overlay__panel beaver-fm-info">
					<header>
						<strong><?php esc_html_e( 'Details', 'beaver-filemanager' ); ?></strong>
						<button type="button" class="button" data-fm="info-close"><?php esc_html_e( 'Close', 'beaver-filemanager' ); ?></button>
					</header>
					<div class="beaver-fm-info__body" id="beaver-fm-info-body"></div>
				</div>
			</div>

			<!-- Trash -->
			<div class="beaver-fm-overlay" id="beaver-fm-trash" hidden>
				<div class="beaver-fm-overlay__panel beaver-fm-trashpanel">
					<header>
						<strong><?php esc_html_e( 'Trash', 'beaver-filemanager' ); ?></strong>
						<div>
							<button type="button" class="button beaver-fm-danger" data-fm="trash-empty"><?php esc_html_e( 'Empty trash', 'beaver-filemanager' ); ?></button>
							<button type="button" class="button" data-fm="trash-close"><?php esc_html_e( 'Close', 'beaver-filemanager' ); ?></button>
						</div>
					</header>
					<div class="beaver-fm-trash__body" id="beaver-fm-trash-body"></div>
				</div>
			</div>

			<!-- Generic dialog -->
			<div class="beaver-fm-overlay beaver-fm-overlay--dialog" id="beaver-fm-dialog" hidden>
				<div class="beaver-fm-overlay__panel beaver-fm-dialog">
					<h2 id="beaver-fm-dialog-title"></h2>
					<div id="beaver-fm-dialog-body"></div>
					<div class="beaver-fm-dialog__foot">
						<button type="button" class="button" data-fm="dialog-cancel"><?php esc_html_e( 'Cancel', 'beaver-filemanager' ); ?></button>
						<button type="button" class="button button-primary" data-fm="dialog-ok"><?php esc_html_e( 'OK', 'beaver-filemanager' ); ?></button>
					</div>
				</div>
			</div>

			<div class="beaver-fm-toasts" id="beaver-fm-toasts"></div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the activity log screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_log() {
		if ( ! Beaver_FM_Settings::user_can_browse() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'beaver-filemanager' ) );
		}

		if ( isset( $_POST['beaver_fm_clear_log'] ) ) {
			check_admin_referer( 'beaver_fm_clear_log' );
			Beaver_FM_Logger::clear();

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The log has been cleared.', 'beaver-filemanager' ) . '</p></div>';
		}

		$entries = Beaver_FM_Logger::recent( 300 );
		$usage   = Beaver_FM_Editor::storage_usage();

		?>
		<div class="wrap beaver-fm-logscreen">
			<h1><?php esc_html_e( 'Beaver FileManager Activity', 'beaver-filemanager' ); ?></h1>

			<p class="description">
				<?php
				printf(
					/* translators: 1: backup storage size, 2: trash storage size. */
					esc_html__( 'Backups are using %1$s and the trash is holding %2$s.', 'beaver-filemanager' ),
					esc_html( $usage['backups'] ),
					esc_html( $usage['trash'] )
				);
				?>
			</p>

			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'Nothing has been changed through the file manager yet.', 'beaver-filemanager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'beaver-filemanager' ); ?></th>
							<th><?php esc_html_e( 'Who', 'beaver-filemanager' ); ?></th>
							<th><?php esc_html_e( 'What', 'beaver-filemanager' ); ?></th>
							<th><?php esc_html_e( 'Path', 'beaver-filemanager' ); ?></th>
							<th><?php esc_html_e( 'Note', 'beaver-filemanager' ); ?></th>
							<th><?php esc_html_e( 'From', 'beaver-filemanager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['when'] ); ?></td>
								<td><?php echo esc_html( $entry['user'] ); ?></td>
								<td><?php echo esc_html( $entry['label'] ); ?></td>
								<td><code><?php echo esc_html( $entry['path'] ); ?></code></td>
								<td><?php echo esc_html( $entry['detail'] ); ?></td>
								<td><?php echo esc_html( $entry['ip'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" style="margin-top:16px;">
					<?php wp_nonce_field( 'beaver_fm_clear_log' ); ?>
					<button type="submit" name="beaver_fm_clear_log" value="1" class="button"><?php esc_html_e( 'Clear log', 'beaver-filemanager' ); ?></button>
				</form>
			<?php endif; ?>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/*
	 * -----------------------------------------------------------------------
	 * Request plumbing
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Runs the standard checks and stops the request when one fails.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $needs_write Whether the endpoint changes something.
	 */
	private static function guard( $needs_write = false ) {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session expired. Reload the page and try again.', 'beaver-filemanager' ) ),
				403
			);
		}

		if ( ! Beaver_FM_Settings::user_can_browse() ) {
			wp_send_json_error(
				array( 'message' => __( 'Your account cannot manage files on this site.', 'beaver-filemanager' ) ),
				403
			);
		}

		if ( $needs_write && ! Beaver_FM_Settings::can_write() ) {
			wp_send_json_error(
				array( 'message' => Beaver_FM_Settings::write_block_reason() ),
				403
			);
		}
	}

	/**
	 * Sends a result, turning a `WP_Error` into a failed response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result Result or error.
	 * @param array $extra  Extra keys merged into a successful response.
	 */
	private static function respond( $result, $extra = array() ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'data'    => $result->get_error_data(),
				)
			);
		}

		wp_send_json_success( array_merge( is_array( $result ) ? $result : array( 'result' => $result ), $extra ) );
	}

	/**
	 * Reads a relative path from the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function path_param( $key = 'path' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		return isset( $_REQUEST[ $key ] ) ? Beaver_FM_Filesystem::clean_relative( wp_unslash( $_REQUEST[ $key ] ) ) : '';
	}

	/**
	 * Reads a list of relative paths from the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Request key.
	 * @return string[]
	 */
	private static function paths_param( $key = 'paths' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$raw = isset( $_REQUEST[ $key ] ) ? wp_unslash( $_REQUEST[ $key ] ) : array();

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array( $raw );
		}

		$paths = array();

		foreach ( (array) $raw as $path ) {
			$clean = Beaver_FM_Filesystem::clean_relative( $path );

			if ( '' !== $clean ) {
				$paths[] = $clean;
			}
		}

		return $paths;
	}

	/**
	 * Reads a boolean flag from the request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Request key.
	 * @return bool
	 */
	private static function flag( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		if ( ! isset( $_REQUEST[ $key ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$value = wp_unslash( $_REQUEST[ $key ] );

		return in_array( $value, array( '1', 1, true, 'true', 'yes' ), true );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Endpoints — reading
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Lists a folder.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_list() {
		self::guard();

		$listing = Beaver_FM_Filesystem::list_dir( self::path_param() );

		self::respond( $listing, array( 'disk' => Beaver_FM_Filesystem::disk() ) );
	}

	/**
	 * Lists sub-folders for the sidebar tree.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_tree() {
		self::guard();

		$folders = Beaver_FM_Filesystem::list_folders( self::path_param() );

		self::respond(
			is_wp_error( $folders ) ? $folders : array(
				'path'    => self::path_param(),
				'folders' => $folders,
			)
		);
	}

	/**
	 * Opens a file in the editor.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_read() {
		self::guard();

		$path = self::path_param();
		$file = Beaver_FM_Filesystem::read( $path );

		if ( is_wp_error( $file ) ) {
			self::respond( $file );
		}

		$file['backups'] = Beaver_FM_Editor::backups( $path );

		self::respond( $file );
	}

	/**
	 * Returns the details panel data.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_info() {
		self::guard();

		self::respond( Beaver_FM_Filesystem::info( self::path_param() ) );
	}

	/**
	 * Searches under a folder.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_search() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$query = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$ext = isset( $_REQUEST['ext'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ext'] ) ) : '';

		self::respond(
			Beaver_FM_Filesystem::search(
				self::path_param(),
				$query,
				array(
					'contents'  => self::flag( 'contents' ),
					'sensitive' => self::flag( 'sensitive' ),
					'ext'       => $ext,
				)
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Endpoints — writing
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Saves editor contents.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_save() {
		self::guard( true );

		/*
		 * The body is raw file content, so it must not be sanitized — only
		 * unslashed. Everything that decides *where* it lands is validated.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw file body by design.
		$content = isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';

		$result = Beaver_FM_Editor::save(
			self::path_param(),
			$content,
			$hash,
			array(
				'force'         => self::flag( 'force' ),
				'ignore_syntax' => self::flag( 'ignore_syntax' ),
			)
		);

		self::respond( $result );
	}

	/**
	 * Creates a file or a folder.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_create() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$name = isset( $_POST['name'] ) ? (string) wp_unslash( $_POST['name'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'file';

		$result = 'folder' === $type
			? Beaver_FM_Filesystem::create_folder( self::path_param(), $name )
			: Beaver_FM_Filesystem::create_file( self::path_param(), $name );

		self::respond( is_wp_error( $result ) ? $result : array( 'entry' => $result ) );
	}

	/**
	 * Renames an item.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_rename() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$name = isset( $_POST['name'] ) ? (string) wp_unslash( $_POST['name'] ) : '';

		$result = Beaver_FM_Filesystem::rename( self::path_param(), $name );

		self::respond( is_wp_error( $result ) ? $result : array( 'entry' => $result ) );
	}

	/**
	 * Deletes items.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_delete() {
		self::guard( true );

		self::respond( Beaver_FM_Filesystem::delete( self::paths_param(), self::flag( 'permanent' ) ) );
	}

	/**
	 * Copies or moves items.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_transfer() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'copy';

		self::respond(
			Beaver_FM_Filesystem::transfer(
				self::paths_param(),
				self::path_param( 'dest' ),
				'move' === $mode ? 'move' : 'copy',
				self::flag( 'overwrite' )
			),
			array( 'mode' => $mode )
		);
	}

	/**
	 * Changes permissions.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_chmod() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

		self::respond( Beaver_FM_Filesystem::chmod( self::paths_param(), $mode, self::flag( 'recursive' ) ) );
	}

	/**
	 * Receives uploaded files.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_upload() {
		self::guard( true );

		if ( empty( $_FILES['files'] ) || ! isset( $_FILES['files']['name'] ) ) {
			self::respond( new WP_Error( 'beaver_fm_no_files', __( 'No files arrived with that request.', 'beaver-filemanager' ) ) );
		}

		$dir       = self::path_param();
		$overwrite = self::flag( 'overwrite' );
		$names     = (array) $_FILES['files']['name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$entries   = array();
		$errors    = array();

		foreach ( array_keys( $names ) as $index ) {
			$file = array(
				'name'     => $_FILES['files']['name'][ $index ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'type'     => $_FILES['files']['type'][ $index ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'tmp_name' => $_FILES['files']['tmp_name'][ $index ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'error'    => $_FILES['files']['error'][ $index ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'size'     => $_FILES['files']['size'][ $index ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			);

			$result = Beaver_FM_Filesystem::receive_upload( $dir, $file, $overwrite );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			$entries[] = $result;
		}

		self::respond(
			array(
				'entries' => $entries,
				'errors'  => $errors,
			)
		);
	}

	/**
	 * Creates a zip archive.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_zip() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$name = isset( $_POST['name'] ) ? (string) wp_unslash( $_POST['name'] ) : '';

		$result = Beaver_FM_Filesystem::zip( self::paths_param(), self::path_param( 'dest' ), $name );

		self::respond( is_wp_error( $result ) ? $result : array( 'entry' => $result ) );
	}

	/**
	 * Extracts a zip archive.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_unzip() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$dest = isset( $_POST['dest'] ) ? self::path_param( 'dest' ) : null;

		self::respond( Beaver_FM_Filesystem::unzip( self::path_param(), $dest ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Endpoints — history and trash
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Lists the stored versions of a file.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_backups() {
		self::guard();

		self::respond( array( 'backups' => Beaver_FM_Editor::backups( self::path_param() ) ) );
	}

	/**
	 * Returns the contents of one stored version.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_backup_read() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';

		$contents = Beaver_FM_Editor::backup_contents( self::path_param(), $id );

		self::respond( is_wp_error( $contents ) ? $contents : array( 'content' => $contents ) );
	}

	/**
	 * Writes a stored version back over the live file.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_backup_restore() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';

		self::respond( Beaver_FM_Editor::restore_backup( self::path_param(), $id ) );
	}

	/**
	 * Lists the trash.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_trash() {
		self::guard();

		self::respond( array( 'items' => Beaver_FM_Editor::trash() ) );
	}

	/**
	 * Restores one trashed item.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_trash_restore() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';

		$result = Beaver_FM_Editor::restore_trash( $id );

		self::respond( is_wp_error( $result ) ? $result : array( 'entry' => $result ) );
	}

	/**
	 * Erases one trashed item.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_trash_delete() {
		self::guard( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran first.
		$id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';

		self::respond( Beaver_FM_Editor::delete_trash( $id ) );
	}

	/**
	 * Empties the trash.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_trash_empty() {
		self::guard( true );

		self::respond( array( 'removed' => Beaver_FM_Editor::empty_trash() ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Endpoints — streaming
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Verifies a streaming request and returns the file to send.
	 *
	 * These are plain browser navigations rather than fetches, so they answer
	 * with `wp_die()` instead of JSON when something is wrong.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path of a readable file.
	 */
	private static function stream_target() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'That link has expired. Reload the file manager and try again.', 'beaver-filemanager' ), '', array( 'response' => 403 ) );
		}

		if ( ! Beaver_FM_Settings::user_can_browse() ) {
			wp_die( esc_html__( 'You do not have permission to download files from this site.', 'beaver-filemanager' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$path     = isset( $_GET['path'] ) ? Beaver_FM_Filesystem::clean_relative( wp_unslash( $_GET['path'] ) ) : '';
		$absolute = Beaver_FM_Filesystem::resolve( $path, true );

		if ( is_wp_error( $absolute ) ) {
			wp_die( esc_html( $absolute->get_error_message() ), '', array( 'response' => 404 ) );
		}

		if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			wp_die( esc_html__( 'That file cannot be read.', 'beaver-filemanager' ), '', array( 'response' => 404 ) );
		}

		return $absolute;
	}

	/**
	 * Sends a file as a download.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_download() {
		$absolute = self::stream_target();

		Beaver_FM_Logger::record( 'download', Beaver_FM_Filesystem::relative( $absolute ) );
		Beaver_FM_Filesystem::stream( $absolute, false );
	}

	/**
	 * Sends a file for inline preview.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_preview() {
		$absolute = self::stream_target();

		Beaver_FM_Filesystem::stream( $absolute, true );
	}
}

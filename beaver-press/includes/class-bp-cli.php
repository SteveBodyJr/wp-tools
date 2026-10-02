<?php
/**
 * WP-CLI: run and follow translations from the command line.
 *
 *     wp beaver-press status
 *     wp beaver-press estimate [--languages=fr_FR,de_DE]
 *     wp beaver-press run [--languages=fr_FR,de_DE] [--pages=<url>,<url>] [--budget=<characters>] [--background]
 *     wp beaver-press resume | pause | cancel
 *     wp beaver-press check [--languages=...] [--pages=...]
 *     wp beaver-press pages [--language=fr_FR] [--missing] [--format=table|csv|json]
 *     wp beaver-press draft-addresses [--languages=...] [--pages=...] [--machine]
 *     wp beaver-press cache-clear
 *
 * Commands run as the Translate-site run does (same queue, same state as Settings ->
 * Beaver Press), so a run started here shows in wp-admin and the other way round.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translate the site, check progress and costs from WP-CLI.
 */
class BP_CLI {

	/**
	 * Engine, key, run and progress per language.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-press status
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function status( $args, $assoc ) {
		$mt      = get_option( 'trp_machine_translation_settings', array() );
		$mt      = is_array( $mt ) ? $mt : array();
		$engine  = BP_Engine_Settings::current( $mt );
		$blocker = BP_Run::blocker();
		$daily   = BP_Usage::daily_limit();
		WP_CLI::log( sprintf( 'Engine:   %s, model %s, key %s', $engine['provider'], $engine['model'], BP_Keys::get( $engine['provider'] ) ? 'saved (' . BP_Keys::hint( $engine['provider'] ) . ')' : 'missing' ) );
		WP_CLI::log( sprintf( 'Ready:    %s', '' === $blocker ? 'yes' : $blocker ) );
		WP_CLI::log( sprintf( 'Today:    %s of %s characters (daily limit)', number_format( $daily['used'] ), $daily['limit'] > 0 ? number_format( $daily['limit'] ) : 'no limit' ) );
		$this->print_run();
		$counts = BP_Complete::counts();
		$rows   = array();
		foreach ( BP_Run::languages() as $code => $name ) {
			$s      = BP_Run::string_counts( $code );
			$rows[] = array(
				'language'       => $name,
				'code'           => $code,
				'texts'          => $s['done'] . ' / ' . $s['total'],
				'pages_complete' => isset( $counts[ $code ] ) ? $counts[ $code ][0] . ' / ' . ( $counts[ $code ][0] + $counts[ $code ][1] ) . ' checked' : 'not checked',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'language', 'code', 'texts', 'pages_complete' ) );
	}

	/**
	 * What is left to translate and roughly what it costs.
	 *
	 * ## OPTIONS
	 *
	 * [--languages=<codes>]
	 * : Comma-separated language codes (default: all).
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function estimate( $args, $assoc ) {
		$mt     = get_option( 'trp_machine_translation_settings', array() );
		$engine = BP_Engine_Settings::current( is_array( $mt ) ? $mt : array() );
		$rows   = array();
		foreach ( $this->languages( $assoc ) as $code ) {
			$e      = BP_Usage::estimate( $code, $engine );
			$rows[] = array(
				'language'   => BP_Run::languages()[ $code ],
				'texts'      => $e['strings'],
				'characters' => $e['chars'],
				'requests'   => $e['requests'],
				'cost'       => null === $e['cost'] ? 'unknown for ' . $engine['model'] : '$' . number_format( (float) $e['cost'], 2 ),
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'language', 'texts', 'characters', 'requests', 'cost' ) );
	}

	/**
	 * Translate the site (or chosen pages) now, showing progress until it finishes or pauses.
	 *
	 * ## OPTIONS
	 *
	 * [--languages=<codes>]
	 * : Comma-separated language codes (default: all).
	 *
	 * [--pages=<urls>]
	 * : Comma-separated page addresses in the original language (default: every public page).
	 *
	 * [--budget=<characters>]
	 * : Pause after this many characters sent.
	 *
	 * [--background]
	 * : Only start (or add to) the run; WP-Cron and the admin page carry it on.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-press run --languages=de_DE --budget=200000
	 *     wp beaver-press run --pages=https://example.com/,https://example.com/contact/
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function run( $args, $assoc ) {
		$languages = $this->languages( $assoc );
		$pages     = isset( $assoc['pages'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc['pages'] ) ) ) : null;
		$state     = BP_Run::state();
		if ( null !== $pages && in_array( $state['status'], array( 'running', 'paused', 'error' ), true ) ) {
			$result = BP_Run::add( $pages, $languages );
		} elseif ( in_array( $state['status'], array( 'running', 'paused', 'error' ), true ) ) {
			WP_CLI::error( 'A run is already in progress (' . $state['status'] . '). Use "wp beaver-press resume", or "cancel" first.' );
			return;
		} else {
			$result = BP_Run::start( $languages, absint( $assoc['budget'] ?? 0 ), $pages );
		}
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::log( sprintf( 'Queued: %d page visits (%s).', (int) $result['total'], implode( ', ', array_map( static fn( $c ) => BP_Run::languages()[ $c ] ?? $c, (array) $result['languages'] ) ) ) );
		if ( ! empty( $assoc['background'] ) ) {
			WP_CLI::success( 'Started in the background.' );
			return;
		}
		$this->drive();
	}

	/**
	 * Continue a paused or stopped run and follow it (a run already going is followed).
	 *
	 * [--background]
	 * : Only resume; do not follow.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function resume( $args, $assoc ) {
		if ( 'running' === BP_Run::state()['status'] ) {
			if ( empty( $assoc['background'] ) ) {
				WP_CLI::log( 'The run is already going; following it here.' );
				$this->drive();
			} else {
				WP_CLI::success( 'The run is already going.' );
			}
			return;
		}
		$state = BP_Run::control( 'resume' );
		if ( 'running' !== $state['status'] ) {
			WP_CLI::error( $state['message'] ? $state['message'] : 'Nothing to resume (' . $state['status'] . ').' );
		}
		if ( empty( $assoc['background'] ) ) {
			$this->drive();
		} else {
			WP_CLI::success( 'Resumed.' );
		}
	}

	/**
	 * Pause the run.
	 */
	public function pause() {
		$state = BP_Run::control( 'pause' );
		WP_CLI::success( 'Run: ' . $state['status'] . '.' );
	}

	/**
	 * Cancel the run (translations already stored are kept).
	 */
	public function cancel() {
		BP_Run::control( 'cancel' );
		WP_CLI::success( 'Run cancelled. Translations already stored are kept.' );
	}

	/**
	 * Count what is still missing, page by page, without translating (free).
	 *
	 * ## OPTIONS
	 *
	 * [--languages=<codes>]
	 * : Comma-separated language codes (default: all).
	 *
	 * [--pages=<urls>]
	 * : Comma-separated page addresses (default: every public page).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function check( $args, $assoc ) {
		$languages = $this->languages( $assoc );
		$urls      = isset( $assoc['pages'] ) ? BP_Run::known_urls( array_map( 'trim', explode( ',', (string) $assoc['pages'] ) ) ) : BP_Run::urls();
		$total     = count( $urls ) * count( $languages );
		$bar       = WP_CLI\Utils\make_progress_bar( 'Checking (nothing is sent to the provider)', $total );
		foreach ( $urls as $url ) {
			foreach ( $languages as $code ) {
				BP_Run::visitor_fetch( BP_Run::url_in( $url, $code ) );
				$bar->tick();
			}
		}
		$bar->finish();
		$counts = BP_Complete::counts();
		foreach ( $languages as $code ) {
			$c = $counts[ $code ] ?? array( 0, 0 );
			WP_CLI::log( sprintf( '%s: %d of %d checked pages complete', BP_Run::languages()[ $code ], $c[0], $c[0] + $c[1] ) );
		}
	}

	/**
	 * Progress per page and language.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<code>]
	 * : Only this language.
	 *
	 * [--missing]
	 * : Only pages that are not complete in every shown language.
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function pages( $args, $assoc ) {
		$languages = isset( $assoc['language'] ) ? $this->languages( array( 'languages' => $assoc['language'] ) ) : array_keys( BP_Run::languages() );
		$map       = BP_Complete::map( true );
		$rows      = array();
		foreach ( BP_Run::pages() as $page ) {
			$row      = array(
				'page' => $page['title'],
				'type' => $page['type'],
			);
			$complete = true;
			foreach ( $languages as $code ) {
				$m            = $map[ $code ][ $page['key'] ] ?? null;
				$row[ $code ] = null === $m ? 'not checked' : ( 0 === (int) $m ? 'complete' : $m . ' missing' );
				$complete     = $complete && 0 === $m;
			}
			if ( empty( $assoc['missing'] ) || ! $complete ) {
				$rows[] = $row;
			}
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array_merge( array( 'page', 'type' ), $languages ) );
	}

	/**
	 * Draft translated page addresses from translated titles.
	 *
	 * ## OPTIONS
	 *
	 * [--languages=<codes>]
	 * : Comma-separated language codes (default: all).
	 *
	 * [--pages=<urls>]
	 * : Comma-separated page addresses (default: every public page).
	 *
	 * [--machine]
	 * : Ask the engine for titles that are not translated yet (small cost).
	 *
	 * @subcommand draft-addresses
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function draft_addresses( $args, $assoc ) {
		if ( ! BP_Slugs::enabled() ) {
			WP_CLI::error( 'Translated page addresses are switched off (Beaver Press -> Languages -> Automatic Translation).' );
		}
		delete_option( BP_Slugs::BACKFILL_OPTION );
		$drafted = 0;
		do {
			$step     = BP_Slugs::backfill(
				20,
				! empty( $assoc['machine'] ),
				empty( $assoc['languages'] ) ? array() : array_map( 'trim', explode( ',', $assoc['languages'] ) ),
				empty( $assoc['pages'] ) ? array() : array_map( 'trim', explode( ',', $assoc['pages'] ) )
			);
			$drafted += $step['drafted'];
			WP_CLI::log( sprintf( '%d / %d pages checked, %d addresses drafted', $step['offset'], $step['total'], $drafted ) );
		} while ( $step['offset'] < $step['total'] );
		delete_option( BP_Slugs::BACKFILL_OPTION );
		WP_CLI::success( sprintf( '%d translated addresses drafted.', $drafted ) );
	}


	/**
	 * Export this site's Beaver Press settings (never the API keys) as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file instead of the screen.
	 *
	 * @subcommand settings-export
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function settings_export( $args, $assoc ) {
		$json = BP_Transfer::export_json();
		if ( empty( $assoc['file'] ) ) {
			WP_CLI::line( $json );
			return;
		}
		if ( false === file_put_contents( (string) $assoc['file'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI file output.
			WP_CLI::error( 'Could not write ' . $assoc['file'] );
		}
		WP_CLI::success( 'Settings written to ' . $assoc['file'] );
	}

	/**
	 * Import Beaver Press settings from a JSON file (API keys and translations are not touched).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Settings file from "settings-export" or the admin page.
	 *
	 * @subcommand settings-import
	 *
	 * @param array $args Positional arguments.
	 */
	public function settings_import( $args ) {
		$path = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( 'Cannot read ' . $path );
		}
		$result = BP_Transfer::import_json( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI file input.
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result as $line ) {
			WP_CLI::log( '- ' . $line );
		}
		WP_CLI::success( 'Settings imported.' );
	}

	/**
	 * Translate the form messages and the visitor's confirmation email.
	 *
	 * ## OPTIONS
	 *
	 * [--languages=<codes>]
	 * : Comma-separated language codes (default: all).
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function forms( $args, $assoc ) {
		$blocker = BP_Run::blocker();
		if ( '' !== $blocker ) {
			WP_CLI::error( $blocker );
		}
		$languages = $this->languages( $assoc );
		$step      = BP_Forms::prepare( $languages, 0, 20 );
		WP_CLI::log( sprintf( 'Confirmation email: %d texts translated.', $step['emails'] ) );
		$bar = WP_CLI\Utils\make_progress_bar( 'Form pages with each result', max( 1, $step['total'] ) );
		for ( $i = 0; $i < $step['offset']; $i++ ) {
			$bar->tick();
		}
		while ( $step['offset'] < $step['total'] ) {
			$before = $step['offset'];
			$step   = BP_Forms::prepare( $languages, $step['offset'], 20 );
			for ( $i = $before; $i < $step['offset']; $i++ ) {
				$bar->tick();
			}
		}
		$bar->finish();
		WP_CLI::success( 'Forms prepared.' );
	}

	/**
	 * Export translations to a file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : File to write (.json, .json.gz or .csv).
	 *
	 * [--language=<code>]
	 * : One language (needed for CSV), e.g. fr_FR. Default: all.
	 *
	 * @subcommand export
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function export( $args, $assoc ) {
		$file     = (string) $assoc['file'];
		$language = (string) ( $assoc['language'] ?? '' );
		if ( '.csv' === substr( $file, -4 ) ) {
			if ( ! in_array( $language, BP_Backup::languages(), true ) ) {
				WP_CLI::error( 'CSV needs --language=<one of: ' . implode( ', ', BP_Backup::languages() ) . '>' );
			}
			$h = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI.
			BP_Backup::csv( $language, $h );
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		} else {
			$json = (string) wp_json_encode( BP_Backup::data( $language ? array( $language ) : array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			file_put_contents( $file, '.gz' === substr( $file, -3 ) ? gzencode( $json, 6 ) : $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI.
		}
		WP_CLI::success( 'Written: ' . $file . ' (' . size_format( (int) filesize( $file ) ) . ')' );
	}

	/**
	 * Import translations from a file (backup first). Dry run unless --apply.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : JSON, .json.gz or CSV file.
	 *
	 * [--language=<code>]
	 * : Language of a CSV file.
	 *
	 * [--apply]
	 * : Write. Without it nothing is changed and the report shows what would happen.
	 *
	 * [--overwrite-machine]
	 * : Replace existing machine translations with the file's.
	 *
	 * [--overwrite-manual]
	 * : Replace existing manual translations too.
	 *
	 * @subcommand import
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function import( $args, $assoc ) {
		$data    = BP_Backup::read_file( (string) $assoc['file'], (string) ( $assoc['language'] ?? '' ) );
		$checked = is_wp_error( $data ) ? $data : BP_Backup::validate( $data );
		if ( is_wp_error( $checked ) ) {
			WP_CLI::error( $checked->get_error_message() );
		}
		$c = $checked['count'];
		WP_CLI::log( sprintf( 'File: %d rows, %d duplicates, %d invalid%s.', $c['rows'], $c['duplicates'], $c['invalid'], $c['unknown_languages'] ? ', skipped languages ' . implode( ', ', $c['unknown_languages'] ) : '' ) );
		$report = BP_Backup::import(
			$checked,
			array(
				'dry_run'           => empty( $assoc['apply'] ),
				'overwrite_machine' => ! empty( $assoc['overwrite-machine'] ),
				'overwrite_manual'  => ! empty( $assoc['overwrite-manual'] ),
			)
		);
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}
		foreach ( BP_Backup::report_lines( $report ) as $line ) {
			WP_CLI::log( $line );
		}
		empty( $assoc['apply'] ) ? WP_CLI::warning( 'Dry run: nothing written. Add --apply to import.' ) : WP_CLI::success( 'Imported. Backup made first: ' . $report['backup'] );
	}

	/**
	 * Back up every translation now.
	 *
	 * @subcommand backup
	 */
	public function backup() {
		$id = BP_Backup::create( 'WP-CLI' );
		is_wp_error( $id ) ? WP_CLI::error( $id->get_error_message() ) : WP_CLI::success( 'Backup ' . $id );
	}

	/**
	 * List translation backups.
	 *
	 * @subcommand backups
	 */
	public function backups() {
		$rows = array();
		foreach ( BP_Backup::backups() as $id => $b ) {
			$rows[] = array(
				'id'     => $id,
				'made'   => wp_date( 'Y-m-d H:i', (int) $b['time'] ),
				'reason' => $b['reason'],
				'rows'   => $b['rows'],
				'size'   => size_format( (int) $b['size'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'made', 'reason', 'rows', 'size' ) );
	}

	/**
	 * Put a translation backup back (a backup of the current state is made first).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id (see backups).
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @subcommand restore
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function restore( $args, $assoc ) {
		WP_CLI::confirm( 'Put every translation back as in backup ' . $args[0] . '?', $assoc );
		$report = BP_Backup::restore( (string) $args[0] );
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}
		foreach ( BP_Backup::report_lines( $report ) as $line ) {
			WP_CLI::log( $line );
		}
		WP_CLI::success( 'Restored. The state before was saved as ' . $report['backup'] );
	}

	/**
	 * Finish pages that miss a few texts now (what the daily top-up does at night).
	 *
	 * @subcommand topup
	 */
	public function topup() {
		$items = BP_Topup::incomplete();
		WP_CLI::log( sprintf( '%d page visits with texts missing.', count( $items ) ) );
		delete_option( BP_Topup::STATE_OPTION );
		do {
			$state = BP_Topup::tick( 60 );
			if ( '' !== $state['reason'] ) {
				WP_CLI::error( $state['reason'] );
			}
			WP_CLI::log( sprintf( '%d visited, %d now complete, %d left', $state['visited'], $state['completed'], $state['left'] ) );
		} while ( $state['left'] > 0 );
		wp_clear_scheduled_hook( BP_Topup::MORE_CRON );
		WP_CLI::success( 'Top-up done.' );
	}

	/**
	 * Build the ready pages now: every public page in every language, opened as a visitor
	 * (free: visitors never start paid translation).
	 *
	 * @subcommand cache-warm
	 */
	public function cache_warm() {
		$total = count( BP_Cache::warm_urls() );
		$bar   = WP_CLI\Utils\make_progress_bar( 'Building ready pages', $total );
		delete_option( BP_Cache::WARM_OPTION );
		$shown = 0;
		do {
			$step = BP_Cache::warm( 20 );
			for ( ; $shown < $step['offset']; $shown++ ) {
				$bar->tick();
			}
		} while ( $step['offset'] < $step['total'] );
		$bar->finish();
		WP_CLI::success( sprintf( '%d pages ready.', BP_Cache::count() ) );
	}

	/**
	 * Empty the ready translated pages.
	 *
	 * @subcommand cache-clear
	 */
	public function cache_clear() {
		BP_Cache::clear();
		WP_CLI::success( 'Ready translated pages cleared.' );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Language codes from --languages, checked against the site's languages.
	 *
	 * @param array $assoc Options.
	 * @return string[]
	 */
	private function languages( array $assoc ) {
		$all = array_keys( BP_Run::languages() );
		if ( empty( $assoc['languages'] ) ) {
			return $all;
		}
		$asked   = array_filter( array_map( 'trim', explode( ',', (string) $assoc['languages'] ) ) );
		$unknown = array_diff( $asked, $all );
		if ( $unknown ) {
			WP_CLI::error( sprintf( 'Unknown language: %s. This site has: %s', implode( ', ', $unknown ), implode( ', ', $all ) ) );
		}
		return array_values( $asked );
	}

	/**
	 * Run steps until the run is no longer running, with a progress bar.
	 */
	private function drive() {
		$state = BP_Run::state();
		$bar   = WP_CLI\Utils\make_progress_bar( 'Translating', max( 1, (int) $state['total'] ) );
		$shown = (int) $state['pos'];
		for ( $i = 0; $i < $shown; $i++ ) {
			$bar->tick();
		}
		while ( 'running' === $state['status'] ) {
			$state = BP_Run::step();
			if ( get_transient( BP_Run::LOCK ) && (int) $state['pos'] === $shown ) {
				sleep( 2 ); // Another process (admin page, cron) holds the lock; follow it.
				$state = BP_Run::state();
			}
			for ( ; $shown < (int) $state['pos']; $shown++ ) {
				$bar->tick();
			}
		}
		$bar->finish();
		$this->print_run();
		if ( 'done' === $state['status'] ) {
			WP_CLI::success( 'Finished.' );
		} elseif ( 'paused' === $state['status'] ) {
			WP_CLI::warning( $state['message'] ? $state['message'] : 'Paused.' );
		} else {
			WP_CLI::error( $state['message'] ? $state['message'] : 'Stopped (' . $state['status'] . ').' );
		}
	}

	/**
	 * One line about the run.
	 */
	private function print_run() {
		$state = BP_Run::state();
		$usage = BP_Usage::run();
		WP_CLI::log(
			sprintf(
				'Run:      %s, %d / %d page visits, %d failed; this run %s requests, %s characters%s',
				$state['status'],
				(int) ( $state['pos'] ?? 0 ),
				(int) ( $state['total'] ?? 0 ),
				count( (array) ( $state['failed'] ?? array() ) ),
				number_format( (int) ( $usage['requests'] ?? 0 ) ),
				number_format( (int) ( $usage['chars'] ?? 0 ) ),
				! empty( $state['message'] ) ? ' - ' . $state['message'] : ''
			)
		);
	}
}

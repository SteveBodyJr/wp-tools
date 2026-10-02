<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
/*
 * Visitor confirmation email in the visitor's language. The email is captured with
 * pre_wp_mail (never sent) and no enquiry is saved. ACF keeps a field's value for the
 * request, so each language runs in its own PHP process: this file calls itself.
 */
if ( isset( $argv[1] ) && '--one' === $argv[1] ) {
	require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
	$caught = null;
	add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$caught ) { $caught = $atts; return true; }, 10, 2 );
	$slug = (string) ( $argv[2] ?? '' );
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( 'action' => 'att_submit_inquiry', 'att_form_type' => 'contact_message', 'trp-form-language' => $slug, '_wp_http_referer' => $home . ( $slug ? $slug . '/' : '' ) . 'contact/' );
	$language = BP_Forms::submission_language();
	$fake     = 987654321;
	if ( function_exists( 'att_core_send_visitor_confirmation' ) ) {
		att_core_send_visitor_confirmation( $fake, array( 'email' => 'visitor@example.invalid', 'full_name' => 'Test Visitor' ) );
		delete_post_meta( $fake, '_att_visitor_confirmation_sent' );
	}
	echo wp_json_encode( array( 'language' => $language, 'subject' => $caught['subject'] ?? null, 'stored' => $language ? count( (array) ( get_option( BP_Forms::OPTION )[ $language ] ?? array() ) ) : 0 ) );
	exit;
}
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$one = static function ( $slug ) { return json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --one ' . escapeshellarg( $slug ) ), true ); };
$en = $one( '' );
$fr = $one( getenv( 'BP_TEST_SLUG' ) ?: 'fr' );
if ( null === ( $en['subject'] ?? null ) ) {
	echo "SKIP confirmation email switched off in Theme Settings (or att-core missing)\n";
} else {
	ok( 'original language: no translation language', '' === $en['language'] );
	ok( 'translated page: language detected', '' !== $fr['language'], (string) $fr['language'] );
	ok( 'prepared language: subject translated, else unchanged', $fr['stored'] > 0 ? $fr['subject'] !== $en['subject'] : $fr['subject'] === $en['subject'], $fr['subject'] . ' / ' . $en['subject'] );
}
echo "\n$pass passed, $fail failed\n";

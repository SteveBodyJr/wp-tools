<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
require_once ABSPATH . 'wp-admin/includes/user.php';
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$tr = wp_insert_user( array( 'user_login' => 'bp_b12_tr_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => BP_Roles::ROLE ) );
$ed = wp_insert_user( array( 'user_login' => 'bp_b12_ed_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$role = get_role( BP_Roles::ROLE );
ok( 'role exists with read + bp_translate only', $role && array( 'read' => true, 'bp_translate' => true ) == $role->capabilities, wp_json_encode( $role ? $role->capabilities : null ) );
ok( 'TranslatePress capability is bp_translate', 'bp_translate' === apply_filters( 'trp_translating_capability', 'manage_options' ) );
ok( 'admin has it', user_can( $admin, 'bp_translate' ) );
ok( 'translator has it', user_can( $tr, 'bp_translate' ) );
ok( 'editor does not', ! user_can( $ed, 'bp_translate' ) );
foreach ( array( 'manage_options', 'edit_posts', 'upload_files', 'edit_pages', 'list_users', 'activate_plugins', 'edit_theme_options' ) as $c ) { ok( "translator lacks $c", ! user_can( $tr, $c ) ); }
wp_set_current_user( $tr ); ok( 'translator: no "view as" other roles', false === apply_filters( 'trp_allow_translator_role_to_view_page_as_other_roles', true ) );
wp_set_current_user( $admin ); ok( 'admin: "view as" kept', true === apply_filters( 'trp_allow_translator_role_to_view_page_as_other_roles', true ) );
$u = get_user_by( 'id', $tr ); wp_set_current_user( 0 );
ok( 'translator login lands in the visual editor', add_query_arg( 'trp-edit-translation', 'true', home_url( '/' ) ) === BP_Roles::login_redirect( admin_url(), '', $u ) );
ok( 'requested redirect respected', home_url( '/fr/' ) === BP_Roles::login_redirect( home_url( '/fr/' ), home_url( '/fr/' ), $u ) );
ok( 'admin login unchanged', admin_url() === BP_Roles::login_redirect( admin_url(), '', get_user_by( 'id', $admin ) ) );
wp_delete_user( $tr ); wp_delete_user( $ed );
echo "\n$pass passed, $fail failed\n";

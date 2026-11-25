<?php
/**
 * WM Prototype NDA Gate — Interception & Lifecycle Verification Suite
 *
 * Run via WP-CLI: wp eval-file tests/test-nda-interception.php --allow-root
 */


if ( ! defined( 'WM_NDA_TESTING_MODE' ) ) {
	define( 'WM_NDA_TESTING_MODE', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	echo "ABSPATH not defined. Must be run within WordPress context via WP-CLI.\n";
	exit( 1 );
}

echo "\n===============================================================\n";
echo "WM PROTOTYPE NDA GATE — INTERCEPTION & LIFECYCLE TEST SUITE\n";
echo "Environment: " . PHP_OS . " | PHP " . PHP_VERSION . " | WP " . get_bloginfo( 'version' ) . "\n";
echo "===============================================================\n\n";

$pass_count = 0;
$total_tests = 6;

// Ensure clean database tables state
global $wpdb;
$wpdb->query( "TRUNCATE TABLE " . WM_NDA_Database::terms_table() );
$wpdb->query( "TRUNCATE TABLE " . WM_NDA_Database::acceptances_table() );
delete_transient( 'wm_nda_active_term' );
wp_cache_flush_group( 'wm_nda_gate' );

WM_NDA_Database::seed_default_term();

// -------------------------------------------------------------
// TEST 1: Verify Initial Active Term in InnoDB Table
// -------------------------------------------------------------
echo "[TEST 1/{$total_tests}] Initial Active NDA Term Seeding & Integrity...\n";
$term = WM_NDA_Database::get_active_term();
if ( $term && '1.0.0' === $term->term_version && (int) $term->is_active === 1 && ! empty( $term->term_hash ) ) {
	echo "  -> PASS: Term v{$term->term_version} active with SHA-256: " . substr( $term->term_hash, 0, 16 ) . "...\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Active term not found or invalid.\n";
}

// -------------------------------------------------------------
// TEST 2: Authentication Interception (Missing Checkbox Rejection)
// -------------------------------------------------------------
echo "\n[TEST 2/{$total_tests}] Login Interception without NDA Checkbox...\n";
$test_user = get_user_by( 'login', 'admin' );
if ( ! $test_user ) {
	$user_id = wp_create_user( 'ndatestuser', 'Password123!', 'ndatest@example.com' );
	$test_user = get_user_by( 'id', $user_id );
}

// Clear any prior acceptance for clean test
global $wpdb;
$wpdb->delete( WM_NDA_Database::acceptances_table(), [ 'user_id' => $test_user->ID ] );
wp_cache_flush_group( 'wm_nda_gate' );

unset( $_POST['wm_nda_accept'] );
$interceptor = new WM_NDA_Interceptor();
$auth_result = $interceptor->validate_login_nda( $test_user, 'any_password' );

if ( is_wp_error( $auth_result ) && 'wm_nda_required' === $auth_result->get_error_code() ) {
	echo "  -> PASS: Authentication rejected with WP_Error('wm_nda_required') as expected.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Login was not intercepted when checkbox was missing.\n";
}

// -------------------------------------------------------------
// TEST 3: Authentication with Checkbox & Instant Signature Recording
// -------------------------------------------------------------
echo "\n[TEST 3/{$total_tests}] Login Interception with NDA Checkbox Acceptance...\n";
$_POST['wm_nda_accept'] = '1';
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)';

$auth_success = $interceptor->validate_login_nda( $test_user, 'any_password' );
$has_accepted = WM_NDA_Database::has_user_accepted( (int) $test_user->ID, (int) $term->term_id );

if ( $auth_success instanceof WP_User && $has_accepted ) {
	echo "  -> PASS: User authenticated and signature registered in database.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: User acceptance was not recorded properly.\n";
}

// -------------------------------------------------------------
// TEST 4: REST API /wm-nda/v1/verify & /wm-nda/v1/active-terms
// -------------------------------------------------------------
echo "\n[TEST 4/{$total_tests}] REST API Endpoints Verification...\n";
$rest = new WM_NDA_REST_API();

$terms_req = new WP_REST_Request( 'GET', '/wm-nda/v1/active-terms' );
$terms_res = $rest->handle_active_terms( $terms_req );

// /verify answers for the CURRENT session. Asking about somebody else used to be allowed for
// anonymous callers, which turned the route into an oracle for who has signed the NDA — on the
// plugin whose subject is confidentiality. Both directions are asserted here.
$prev_user = get_current_user_id();

wp_set_current_user( (int) $test_user->ID );
$verify_req = new WP_REST_Request( 'GET', '/wm-nda/v1/verify' );
$verify_res = $rest->handle_verify( $verify_req );

wp_set_current_user( 0 );
$snoop_req = new WP_REST_Request( 'GET', '/wm-nda/v1/verify' );
$snoop_req->set_param( 'user_id', $test_user->ID );
$snoop_res = $rest->handle_verify( $snoop_req );

wp_set_current_user( $prev_user );

$own_ok   = 200 === $verify_res->get_status() && true === $verify_res->get_data()['is_accepted'];
$snoop_ok = 403 === $snoop_res->get_status();

if ( 200 === $terms_res->get_status() && $own_ok && $snoop_ok ) {
	echo "  -> PASS: REST API returns the active term, answers for the current session, and refuses to disclose another user's status.\n";
	$pass_count++;
} else {
	printf(
		"  -> FAIL: REST API endpoint verification failed (terms=%d own=%s snoop=%d).\n",
		$terms_res->get_status(),
		$own_ok ? 'ok' : 'no',
		$snoop_res->get_status()
	);
}

// -------------------------------------------------------------
// TEST 5: Version Bump Re-acceptance Enforcement
// -------------------------------------------------------------
echo "\n[TEST 5/{$total_tests}] Version Bump (1.0.0 -> 2.0.0) Re-acceptance Gate...\n";
$new_content = WM_NDA_I18n::get_default_legal_content() . "\n<!-- Updated for Prototype V2 -->";
$new_term_id = WM_NDA_Database::bump_version( '2.0.0', 'Vertraulichkeitsvereinbarung V2', $new_content, (int) $test_user->ID );
$new_term = WM_NDA_Database::get_active_term();

$has_accepted_v2 = WM_NDA_Database::has_user_accepted( (int) $test_user->ID, (int) $new_term->term_id );

if ( $new_term && '2.0.0' === $new_term->term_version && false === $has_accepted_v2 ) {
	echo "  -> PASS: Version bump triggered. User acceptance invalidated for active v2.0.0.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Version bump did not require re-acceptance.\n";
}

// -------------------------------------------------------------
// TEST 6: Re-signing Version 2.0.0 via REST API /wm-nda/v1/accept
// -------------------------------------------------------------
echo "\n[TEST 6/{$total_tests}] Async Re-signing v2.0.0 via REST API...\n";
wp_set_current_user( $test_user->ID );
$accept_req = new WP_REST_Request( 'POST', '/wm-nda/v1/accept' );
$accept_req->set_param( 'term_id', (int) $new_term->term_id );
$accept_res = $rest->handle_accept( $accept_req );

$has_now_accepted_v2 = WM_NDA_Database::has_user_accepted( (int) $test_user->ID, (int) $new_term->term_id );

if ( 200 === $accept_res->get_status() && true === $has_now_accepted_v2 ) {
	echo "  -> PASS: v2.0.0 signature recorded with Acceptance ID: " . $accept_res->get_data()['acceptance_id'] . "\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Async re-signing failed.\n";
}

echo "\n-------------------------------------------------------------\n";
if ( $pass_count === $total_tests ) {
	echo "VERDICT: PASS ({$pass_count}/{$total_tests} Interception & Lifecycle Tests Successful)\n";
} else {
	echo "VERDICT: FAIL ({$pass_count}/{$total_tests} Tests Passed)\n";
}
echo "-------------------------------------------------------------\n\n";
// A runner reads the exit code; the verdict line alone let a failing run pass (reader audit 2026-09-15).
exit( $pass_count === $total_tests ? 0 : 1 );

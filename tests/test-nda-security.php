<?php
/**
 * WM Prototype NDA Gate — Security and hardening checks
 *
 * Run via WP-CLI: wp eval-file tests/test-nda-security.php --allow-root
 */


if ( ! defined( 'WM_NDA_TESTING_MODE' ) ) {
	define( 'WM_NDA_TESTING_MODE', true );
}

if ( ! defined( 'ABSPATH' ) ) {
	echo "ABSPATH not defined. Must be run within WordPress context via WP-CLI.\n";
	exit( 1 );
}

echo "\n===============================================================\n";
echo "WM PROTOTYPE NDA GATE — SECURITY CHECKS\n";
echo "Environment: " . PHP_OS . " | PHP " . PHP_VERSION . " | WP " . get_bloginfo( 'version' ) . "\n";
echo "===============================================================\n\n";

$pass_count = 0;
$total_tests = 5;

// -------------------------------------------------------------
// TEST 1: IP pseudonymisation (daily-salted HMAC)
// -------------------------------------------------------------
echo "[TEST 1/{$total_tests}] IP Pseudonymization via Daily-Salted HMAC-SHA256...\n";
$ip = '203.0.113.195';
$hmac1 = WM_NDA_Database::generate_ip_hmac( $ip );
$hmac2 = WM_NDA_Database::generate_ip_hmac( $ip );

if ( ! empty( $hmac1 ) && 64 === strlen( $hmac1 ) && $hmac1 === $hmac2 && ! str_contains( $hmac1, $ip ) ) {
	echo "  -> PASS: IP successfully transformed into 64-char HMAC hash: " . substr( $hmac1, 0, 16 ) . "...\n";
	$pass_count++;
} else {
	echo "  -> FAIL: IP HMAC generation is invalid or leaks IP.\n";
}

// -------------------------------------------------------------
// TEST 2: Cryptographic Signature Integrity & Tampering Defense
// -------------------------------------------------------------
echo "\n[TEST 2/{$total_tests}] Signature Cryptogram Generation & Tamper Detection...\n";
$user_id = 999;
$term_id = 1;
$term_hash = hash( 'sha256', 'sample_term_content' );
$timestamp = '2026-08-27 00:00:00';
$sig_valid = WM_NDA_Database::generate_signature_hash( $user_id, $term_id, $term_hash, $timestamp, $hmac1 );

// Simulate Tampering with payload
$sig_tampered = WM_NDA_Database::generate_signature_hash( $user_id, $term_id, $term_hash, '2026-08-27 00:00:01', $hmac1 );

if ( 64 === strlen( $sig_valid ) && $sig_valid !== $sig_tampered ) {
	echo "  -> PASS: Tampering alters the signature hash.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Cryptographic signature failure.\n";
}

// -------------------------------------------------------------
// TEST 3: CSV Formula Injection (CWE-1236) Neutralization
// -------------------------------------------------------------
echo "\n[TEST 3/{$total_tests}] CWE-1236 Formula Injection Neutralization in Exports...\n";
$malicious_inputs = [
	'=cmd|\' /C calc\'!A0' => '\'=cmd|\' /C calc\'!A0',
	'+1234567890'          => '\'+1234567890',
	'-SUM(A1:A10)'         => '\'-SUM(A1:A10)',
	'@SUM(1+1)'            => '\'@SUM(1+1)',
	"\tDANGEROUS"          => "'\tDANGEROUS",
	'Normal User Name'     => 'Normal User Name',
];

$cwe_passed = true;
foreach ( $malicious_inputs as $raw => $expected ) {
	$sanitized = WM_NDA_Exporter::sanitize_csv_cell( $raw );
	if ( $sanitized !== $expected ) {
		$cwe_passed = false;
		echo "  -> ERROR: Input '{$raw}' sanitized to '{$sanitized}', expected '{$expected}'.\n";
	}
}

if ( $cwe_passed ) {
	echo "  -> PASS: All formula injection prefixes (=, +, -, @, \\t) safely escaped.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: CWE-1236 vulnerability detected in CSV sanitizer.\n";
}

// -------------------------------------------------------------
// TEST 4: REST API Pre-Dispatch 403 Forbidden on Protected Endpoints
// -------------------------------------------------------------
echo "\n[TEST 4/{$total_tests}] REST API Interception & 403 Forbidden for Unsigned Users...\n";
$interceptor = new WM_NDA_Interceptor();
// wp_delete_user() lives in wp-admin/includes/user.php, which WP-CLI does not load: without it the
// cleanup below was a fatal error, the probe user survived, and every later run failed test 4 with
// wp_create_user() returning WP_Error( 'existing_user_login' ).
require_once ABSPATH . 'wp-admin/includes/user.php';
$stale_probe = get_user_by( 'login', 'unsigned_api_user' );
if ( $stale_probe instanceof WP_User ) {
	wp_delete_user( (int) $stale_probe->ID );
}
$unconsented_user_id = wp_create_user( 'unsigned_api_user', wp_generate_password( 24, true, true ), 'unsigned@example.com' );
if ( is_wp_error( $unconsented_user_id ) ) {
	echo "  -> ERROR: could not create probe user: " . $unconsented_user_id->get_error_message() . "\n";
}
wp_set_current_user( (int) $unconsented_user_id );

// The test measures the mechanism, not the site's settings: a site with the REST gate switched
// off (option "0" left behind by an earlier session) made this test fail for the wrong reason.
$rest_gate_before = get_option( 'wm_nda_enforce_rest_api', 1 );
update_option( 'wm_nda_enforce_rest_api', 1 );

$protected_request = new WP_REST_Request( 'GET', '/wp/v2/plugins' );
$intercepted_res = $interceptor->enforce_rest_nda( null, null, $protected_request );

update_option( 'wm_nda_enforce_rest_api', $rest_gate_before );

if ( is_wp_error( $intercepted_res ) && 'rest_forbidden' === $intercepted_res->get_error_code() && 403 === $intercepted_res->get_error_data()['status'] && WM_NDA_I18n::get_consent_required_text() === $intercepted_res->get_error_message() ) {
	echo "  -> PASS: Protected REST endpoint returns HTTP 403 saying which acceptance is missing.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Protected REST request was not intercepted.\n";
}

// -------------------------------------------------------------
// TEST 5: REST API /wm-nda/v1/ Whitelist Exemption
// -------------------------------------------------------------
echo "\n[TEST 5/{$total_tests}] Whitelist Exemption for Plugin's Own Accept Route...\n";
$plugin_own_request = new WP_REST_Request( 'POST', '/wm-nda/v1/accept' );
$unblocked_res = $interceptor->enforce_rest_nda( null, null, $plugin_own_request );

if ( null === $unblocked_res ) {
	echo "  -> PASS: Plugin own route '/wm-nda/v1/accept' successfully exempted from blocking.\n";
	$pass_count++;
} else {
	echo "  -> FAIL: Plugin accept endpoint was accidentally blocked.\n";
}

// Cleanup test user
wp_delete_user( $unconsented_user_id );

echo "\n-------------------------------------------------------------\n";
if ( $pass_count === $total_tests ) {
	echo "VERDICT: PASS ({$pass_count}/{$total_tests} security checks passed)\n";
} else {
	echo "VERDICT: FAIL ({$pass_count}/{$total_tests} Tests Passed)\n";
}
echo "-------------------------------------------------------------\n\n";
// A runner reads the exit code; the verdict line alone let a failing run pass (reader audit 2026-09-15).
exit( $pass_count === $total_tests ? 0 : 1 );

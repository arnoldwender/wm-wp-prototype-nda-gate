<?php
/**
 * Audit-sink test.
 *
 * The bypass message told users "Der Vorgang wurde forensisch erfasst" unconditionally,
 * while the write itself was wrapped in class_exists( 'WM_Sentinel_Logger' ). With
 * wm-activity-sentinel deactivated the plugin claimed a forensic record and wrote nothing.
 * Since 2026-09-15 the REST and admin-ajax denials, where nothing is written, claim no record at all.
 *
 * Run: wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/test-audit-fallback.php
 * Exit: 1 when an assertion fails.
 *
 * @package WM_NDA_Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

$GLOBALS['wm_nda_passed'] = 0;
$GLOBALS['wm_nda_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition under test.
 * @param string $label     Description.
 * @return void
 */
function wm_nda_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_nda_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_nda_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

echo "\n=== NDA AUDIT SINK TEST ===\n\n";

$sentinel_active = class_exists( 'WM_Sentinel_Logger' );
echo 'WM Activity Sentinel: ' . ( $sentinel_active ? "aktiv\n" : "NICHT aktiv\n" );
echo 'WM_NDA_Audit::is_forensic(): ' . ( WM_NDA_Audit::is_forensic() ? "true\n\n" : "false\n\n" );

// --- Case 1: the audit write never silently drops ------------------------
echo "Case 1 — Audit-Schreibvorgang\n";
$written = WM_NDA_Audit::record( 1, 'nda_test_event', 'security_gate', 1, 'critical', array( 'probe' => true ) );
wm_nda_assert( true === $written, 'record() meldet einen erfolgten Schreibvorgang' );
wm_nda_assert( WM_NDA_Audit::is_forensic() === $sentinel_active, 'is_forensic() spiegelt die tatsächliche Verfügbarkeit von Sentinel' );

// --- Case 2: the login refusal may not overclaim ---------------------------
echo "\nCase 2 — Wortlaut der Ablehnung beim Login\n";
$forensic_text = WM_NDA_I18n::get_login_required_text( true );
$fallback_text = WM_NDA_I18n::get_login_required_text( false );

wm_nda_assert( false !== strpos( $forensic_text, 'forensisch' ), 'Mit Sentinel: Text nennt "forensisch"' );
wm_nda_assert( false === strpos( $fallback_text, 'forensisch' ), 'Ohne Sentinel: Text behauptet KEINE forensische Erfassung' );
wm_nda_assert( false !== strpos( $fallback_text, 'protokolliert' ), 'Ohne Sentinel: Text sagt "protokolliert"' );
wm_nda_assert( $forensic_text !== $fallback_text, 'Beide Varianten unterscheiden sich' );

// --- Case 3: the live message matches the live sink ----------------------
echo "\nCase 3 — Gelieferter Text passt zum tatsächlichen Sink\n";
$live = WM_NDA_I18n::get_login_required_text( WM_NDA_Audit::is_forensic() );
$claims_forensic = ( false !== strpos( $live, 'forensisch' ) );
wm_nda_assert( $claims_forensic === WM_NDA_Audit::is_forensic(), 'Die Behauptung im Text deckt sich mit dem verfügbaren Sink' );

$rest_text = WM_NDA_I18n::get_consent_required_text();
wm_nda_assert( false === strpos( $rest_text, 'forensisch' ) && false === strpos( $rest_text, 'protokolliert' ), 'REST- und admin-ajax-Ablehnung behaupten keine Erfassung (dort wird nichts geschrieben)' );

// --- Case 4: no call site bypasses the audit router ----------------------
echo "\nCase 4 — Keine direkten Sentinel-Aufrufe mehr in der Logik\n";
$logic = array(
	WM_NDA_GATE_PATH . 'includes/class-nda-interceptor.php',
	WM_NDA_GATE_PATH . 'includes/class-nda-database.php',
);
$direct = 0;
foreach ( $logic as $file ) {
	$src = file_get_contents( $file );
	$direct += substr_count( $src, 'WM_Sentinel_Logger::log(' );
}
wm_nda_assert( 0 === $direct, 'Interceptor und Database rufen Sentinel nicht mehr direkt auf' );

echo "\n----------------------------------------------------\n";
$p = (int) $GLOBALS['wm_nda_passed'];
$f = (int) $GLOBALS['wm_nda_failed'];
echo "Tests bestanden: {$p} / " . ( $p + $f ) . "\n";
echo ( 0 === $f && $p > 0 ) ? "VERDICT: PASS\n" : "VERDICT: FAIL\n";
echo "----------------------------------------------------\n";
exit( 0 === $f && $p > 0 ? 0 : 1 );

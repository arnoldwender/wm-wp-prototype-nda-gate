<?php
/**
 * WM Prototype NDA Gate — WordPress privacy tools (personal data export and erasure) against a real database
 *
 * Run via WP-CLI: wp eval-file tests/test-nda-privacy-tools.php --allow-root
 *
 * Records one probe acceptance, exports it through the core exporter callback, pseudonymises it
 * through the core eraser callback, checks that the signature register still verifies, and removes
 * the probe row again. The probe is the newest row, so the chain tip is restored exactly.
 *
 * Why this exists: until 2026-09-14 both callbacks called a method that did not exist and died with
 * a fatal on first use, and the eraser rewrote a signed field. The standalone suite mocks $wpdb;
 * this file proves the two callbacks against the real table and the %i placeholders.
 *
 * Never run it against a production register: it writes and deletes a row in the acceptance table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "ABSPATH not defined. Must be run within WordPress context via WP-CLI.\n";
	exit( 1 );
}

global $wpdb;

$wm_p_table    = WM_NDA_Database::acceptances_table();
$wm_p_user_id  = 987654;
$wm_p_email    = 'privacy-probe@example.com';
$wm_p_passed   = 0;
$wm_p_failed   = 0;

$wm_p_check = static function ( bool $ok, string $label ) use ( &$wm_p_passed, &$wm_p_failed ): void {
	if ( $ok ) {
		++$wm_p_passed;
		echo "  [PASS] {$label}\n";
	} else {
		++$wm_p_failed;
		echo "  [FAIL] {$label}\n";
	}
};

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test reads the register directly.
$wm_p_max_id = static function () use ( $wpdb, $wm_p_table ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(acceptance_id) FROM %i', $wm_p_table ) );
};
$wm_p_probe_max_id = static function () use ( $wpdb, $wm_p_table, $wm_p_user_id ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(acceptance_id) FROM %i WHERE user_id = %d', $wm_p_table, $wm_p_user_id ) );
};
$wm_p_row = static function ( int $id ) use ( $wpdb, $wm_p_table ): ?object {
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE acceptance_id = %d', $wm_p_table, $id ) );
	return $row ?: null;
};

echo "\n=== NDA GATE — PRIVACY TOOLS (EXPORTER / ERASER) AGAINST THE REAL REGISTER ===\n\n";

$wm_p_term = WM_NDA_Database::get_active_term();
if ( ! $wm_p_term ) {
	echo "  [FAIL] no active NDA term: activate the plugin first (activation seeds the default term)\n";
	echo "\nVERDICT: FAIL (0/8)\n";
	exit( 1 );
}

// A probe left behind by an interrupted run can only be removed while it is still the newest row;
// deleting a row from the middle of the chain would break every row after it.
$wm_p_stale = $wm_p_probe_max_id();
if ( $wm_p_stale > 0 ) {
	if ( $wm_p_stale !== $wm_p_max_id() ) {
		echo "  [FAIL] stale probe row #{$wm_p_stale} is not the newest row; remove it by hand on this scratch install\n";
		echo "\nVERDICT: FAIL (0/8)\n";
		exit( 1 );
	}
	$wpdb->delete( $wm_p_table, [ 'user_id' => $wm_p_user_id ], [ '%d' ] );
	$wm_p_left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wm_p_table ) );
	if ( $wm_p_left > 0 ) {
		// The tip is sealed since 2026-09-15; an unsealed one no longer verifies.
		WM_NDA_Database::seal_tip( WM_NDA_Database::current_chain_tip(), $wm_p_left );
	} else {
		delete_option( WM_NDA_Database::TIP_OPTION );
	}
	echo "  [INFO] removed stale probe row #{$wm_p_stale} from an earlier run\n";
}

$wm_p_tip_before = get_option( WM_NDA_Database::TIP_OPTION, null );

// 1. Record the probe acceptance.
$wm_p_id = WM_NDA_Database::record_acceptance( $wm_p_user_id, (int) $wm_p_term->term_id, $wm_p_email, 'Privacy Probe', 'privacy-probe-agent/1.0', '203.0.113.7' );
$wm_p_check( is_int( $wm_p_id ) && $wm_p_id > 0, 'a probe acceptance is recorded for ' . $wm_p_email );
$wm_p_id = (int) $wm_p_id;
$wm_p_before = $wm_p_row( $wm_p_id );

// 2. Export: the core exporter callback returns the record.
$wm_p_export = WM_NDA_Exporter::export_personal_data( $wm_p_email );
$wm_p_item   = $wm_p_export['data'][0] ?? null;
$wm_p_values = is_array( $wm_p_item ) ? array_column( $wm_p_item['data'] ?? [], 'value', 'name' ) : [];
$wm_p_check(
	is_array( $wm_p_item ) && 'wm_nda_signatures' === ( $wm_p_item['group_id'] ?? '' ) && "wm-nda-sig-{$wm_p_id}" === ( $wm_p_item['item_id'] ?? '' ),
	'export_personal_data returns the probe as export item wm-nda-sig-' . $wm_p_id
);
$wm_p_check(
	$wm_p_email === ( $wm_p_values['Unterzeichner E-Mail'] ?? '' ) && ( $wm_p_before->signature_hash ?? '' ) === ( $wm_p_values['Signatur-Hash (SHA-256)'] ?? '' ),
	'the export item carries the e-mail and the stored signature hash'
);

// 3. Control: the register verifies with the probe row in place.
$wm_p_reg = WM_NDA_Database::verify_register();
$wm_p_check( true === $wm_p_reg['valid'], 'control: the register verifies before the erasure (' . (string) ( $wm_p_reg['error'] ?? 'ok' ) . ')' );

// 4. Erasure: the core eraser callback pseudonymises the record and keeps the acceptance.
$wm_p_erase = WM_NDA_Exporter::erase_personal_data( $wm_p_email );
$wm_p_after = $wm_p_row( $wm_p_id );
$wm_p_check( 1 === (int) ( $wm_p_erase['items_removed'] ?? 0 ) && true === ( $wm_p_erase['done'] ?? false ), 'erase_personal_data reports one pseudonymised record' );
$wm_p_check(
	$wm_p_after
	&& '[Pseudonymisiert]' === $wm_p_after->user_name
	&& "anonymized-{$wm_p_id}@privacy.local" === $wm_p_after->user_email
	&& '[Anonymisiert]' === $wm_p_after->user_agent
	&& $wm_p_before->signature_hash === $wm_p_after->signature_hash,
	'name, e-mail and user agent are gone; the signature hash is unchanged'
);

// 5. The register must still verify: ip_hmac is part of the signed payload and must survive.
$wm_p_reg = WM_NDA_Database::verify_register();
$wm_p_check( true === $wm_p_reg['valid'], 'the register still verifies after the erasure (' . (string) ( $wm_p_reg['error'] ?? 'ok' ) . ')' );

// 6. A second export for the erased address finds nothing.
$wm_p_export = WM_NDA_Exporter::export_personal_data( $wm_p_email );
$wm_p_check( [] === ( $wm_p_export['data'] ?? null ), 'a second export for the erased e-mail finds nothing' );

// Cleanup: the probe is the newest row; delete it and put the chain tip back exactly as it was.
$wpdb->delete( $wm_p_table, [ 'acceptance_id' => $wm_p_id ], [ '%d' ] );
if ( null === $wm_p_tip_before ) {
	delete_option( WM_NDA_Database::TIP_OPTION );
} else {
	update_option( WM_NDA_Database::TIP_OPTION, $wm_p_tip_before, false );
}
$wm_p_reg = WM_NDA_Database::verify_register();
if ( true !== $wm_p_reg['valid'] ) {
	echo "  [WARN] register after cleanup: " . (string) $wm_p_reg['error'] . "\n";
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

$wm_p_total = $wm_p_passed + $wm_p_failed;
echo "\n-------------------------------------------------------------\n";
if ( 0 === $wm_p_failed ) {
	echo "VERDICT: PASS ({$wm_p_passed}/{$wm_p_total} privacy-tool checks against the real register)\n";
} else {
	echo "VERDICT: FAIL ({$wm_p_passed}/{$wm_p_total} Tests Passed)\n";
}
echo "-------------------------------------------------------------\n\n";
exit( 0 === $wm_p_failed ? 0 : 1 );

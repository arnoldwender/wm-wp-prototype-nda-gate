<?php
/**
 * Falsifiers for the "revisionssicher" claims on the consent register.
 *
 * Measured on 2026-08-27, before this release:
 *
 *   - WM_NDA_Database::generate_signature_hash() was only ever WRITTEN. `grep` found no code
 *     anywhere in the plugin that recomputed a stored row, so the "kryptografische Prüfsummen"
 *     the admin screen offers for submission to a court had never been checked by anything.
 *   - The acceptances table had no chain. Deleting a consent row left no trace at all — which is
 *     precisely what "revisionssicher" is supposed to rule out.
 *   - The signing key fell back to the literal 'wm_nda_auth_key_2026' shipped in the source, so
 *     on an install without AUTH_KEY anyone with a copy of the plugin could mint a consent.
 *
 * Mutation checks:
 *   - remove the hash_equals() on the signature in verify_register() → case 3 fails.
 *   - remove the prev_hash comparison → case 4 fails.
 *   - remove the tip comparison → case 5 fails.
 *
 * 2026-09-15 (reader audit D2, D6): the tip is sealed, so deleting the newest row together with the tip
 * option, or blanking every prev_hash, no longer verifies (cases 5b, 5c); case 8 checks the checksum of the
 * real JSON certificate instead of a hand-built payload. The fixtures passed the IP as the user agent.
 *
 * WARNING: deletes rows from the acceptances table. Scratch installs only.
 *
 * Run: wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/test-register-integrity.php --allow-root
 * Exit: 1 when an assertion fails.
 *
 * @package WM_NDA_Gate
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_n_passed'] = 0;
$GLOBALS['wm_n_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_n_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_n_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_n_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
$table = WM_NDA_Database::acceptances_table();

echo "\n=== NDA REGISTER INTEGRITY TEST ===\n\n";

WM_NDA_Database::create_tables();
$term = WM_NDA_Database::get_active_term();

if ( ! $term ) {
	WM_NDA_Database::seed_default_term();
	$term = WM_NDA_Database::get_active_term();
}
wm_n_assert( (bool) $term, 'an active NDA term exists to sign' );

/**
 * Fresh register with $n chained acceptances.
 *
 * @param int $n Acceptances.
 * @return array Inserted ids.
 */
function wm_n_fixture( $n = 3 ) {
	global $wpdb;
	$table = WM_NDA_Database::acceptances_table();
	$term  = WM_NDA_Database::get_active_term();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$table}" );
	delete_option( WM_NDA_Database::TIP_OPTION );

	for ( $i = 1; $i <= $n; $i++ ) {
		WM_NDA_Database::record_acceptance(
			9000 + $i,
			(int) $term->term_id,
			"probe{$i}@example.test",
			"Probe {$i}",
			'PHPUnit/1.0',
			'203.0.113.' . $i
		);
	}

	return array_map( 'intval', $wpdb->get_col( "SELECT acceptance_id FROM {$table} ORDER BY acceptance_id ASC" ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// --- Case 1: a verifier exists at all --------------------------------------
wm_n_assert(
	method_exists( 'WM_NDA_Database', 'verify_register' ),
	'the plugin has a routine that recomputes stored signatures'
);

// --- Case 2: an untouched register verifies --------------------------------
$ids = wm_n_fixture();
$r   = WM_NDA_Database::verify_register();
wm_n_assert( true === $r['valid'], 'an untouched register verifies' );
wm_n_assert( 3 === $r['checked'], 'all three acceptances were recomputed (got ' . $r['checked'] . ')' );

// --- Case 3: editing a signed field is detected ----------------------------
$ids = wm_n_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = 424242 WHERE acceptance_id = %d", $ids[1] ) );
$r = WM_NDA_Database::verify_register();
wm_n_assert( false === $r['valid'], 'editing an acceptance is detected' );
wm_n_assert( (int) $ids[1] === (int) $r['broken_at_id'], 'the edited acceptance is the one reported' );

// --- Case 4: deleting an acceptance is detected ----------------------------
// This is what "revisionssicher" has to mean. Before the chain, this left no trace whatsoever.
$ids = wm_n_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE acceptance_id = %d", $ids[1] ) );
$r = WM_NDA_Database::verify_register();
wm_n_assert( false === $r['valid'], 'deleting a consent from the middle is detected' );

// --- Case 5: deleting the NEWEST acceptance is detected --------------------
$ids = wm_n_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE acceptance_id = %d", end( $ids ) ) );
$r = WM_NDA_Database::verify_register();
wm_n_assert( false === $r['valid'], 'deleting the newest consent is caught by the recorded tip' );

// --- Case 5b: deleting the newest acceptance AND the tip option ------------
$ids = wm_n_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE acceptance_id = %d", end( $ids ) ) );
delete_option( WM_NDA_Database::TIP_OPTION );
$r = WM_NDA_Database::verify_register();
wm_n_assert( false === $r['valid'], 'deleting the newest consent together with the tip option is detected' );

// --- Case 5c: blanking every predecessor ------------------------------------
wm_n_fixture();
$wpdb->query( "UPDATE {$table} SET prev_hash = ''" );
$r = WM_NDA_Database::verify_register();
wm_n_assert( false === $r['valid'], 'blanking every prev_hash does not turn the register into verified legacy rows' );

// --- Case 6: no shipped key literal in executable code ---------------------
$code = implode(
	"\n",
	array_filter(
		file( WM_NDA_GATE_PATH . 'includes/class-nda-database.php', FILE_IGNORE_NEW_LINES ),
		static function ( $line ) {
			$t = ltrim( $line );
			return '' !== $t && 0 !== strpos( $t, '*' ) && 0 !== strpos( $t, '//' ) && 0 !== strpos( $t, '/*' );
		}
	)
);
wm_n_assert( false === strpos( $code, 'wm_nda_auth_key_2026' ), 'no fallback signing key is shipped in executable code' );
wm_n_assert( false === strpos( $code, 'wm_nda_default_salt_2026' ), 'no fallback IP salt is shipped in executable code' );

// --- Case 7: a legacy unsigned row is reported as unchained, not intact ----
$ids = wm_n_fixture();
$wpdb->query( "DELETE FROM {$table}" );
delete_option( WM_NDA_Database::TIP_OPTION );
$wpdb->insert(
	$table,
	array(
		'user_id'              => 8001,
		'term_id'              => (int) $term->term_id,
		'user_email'           => 'legacy@example.test',
		'user_name'            => 'Legacy',
		'term_hash_at_signing' => $term->term_hash,
		'ip_hmac'              => str_repeat( 'a', 64 ),
		'user_agent'           => 'legacy',
		'accepted_at'          => current_time( 'mysql', true ),
		'prev_hash'            => '',
		'signature_hash'       => str_repeat( 'b', 64 ),
	)
);
WM_NDA_Database::record_acceptance( 8002, (int) $term->term_id, 'after@example.test', 'After', 'PHPUnit/1.0', '203.0.113.99' );
$r = WM_NDA_Database::verify_register();
wm_n_assert( true === $r['valid'], 'a pre-chain row before the chain is not called tampering' );
wm_n_assert( 1 === $r['unchained_legacy'], 'the pre-chain row is counted as unchained, not as verified' );
wm_n_assert( 1 === $r['checked'], 'only the chained row counts as checked' );

// --- Case 8: the export checksum can actually be reproduced ----------------
// The recipe is read from the real certificate and followed as a recipient would: remove the fields it
// names, re-encode, hash. Until 2026-09-15 a field added after hashing made that fail.
wm_n_fixture();
$cert = WM_NDA_Exporter::build_json_certificate( 0 );
preg_match_all( '/certificate_metadata\.([a-z0-9_]+)/', (string) $cert['certificate_metadata']['master_checksum_method'], $named );
$recipient = $cert;
foreach ( $named[1] as $key ) {
	unset( $recipient['certificate_metadata'][ $key ] );
}
$recomputed = hash( 'sha256', (string) wp_json_encode( $recipient, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
wm_n_assert( hash_equals( (string) $cert['certificate_metadata']['master_checksum_sha256'], $recomputed ), 'the recipe stated in the JSON certificate reproduces its checksum' );

// --- Teardown -------------------------------------------------------------
$wpdb->query( "DELETE FROM {$table}" );
delete_option( WM_NDA_Database::TIP_OPTION );

echo "\n=== RESULT: {$GLOBALS['wm_n_passed']} passed, {$GLOBALS['wm_n_failed']} failed ===\n\n";
exit( 0 === $GLOBALS['wm_n_failed'] ? 0 : 1 );

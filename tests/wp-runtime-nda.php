<?php
/**
 * Runtime check of wm-prototype-nda-gate inside a real WordPress. Writes nothing.
 *
 * Reads the register, the gate URL, the REST refusal, the JSON certificate, the KPIs, the hub health, the
 * integrity route, the privacy export and the compiled catalogs of the live site. The plugin's options and
 * transients are compared before and after and the check exits 1 when one changed; the plugin's cache group is
 * non-persistent for this process, and the active term and the REST option are supplied through pre_ filters so
 * that no transient is warmed. The register is whatever the site holds: the check requires that it verifies or
 * that it says why, in words that match what the rows show.
 *
 *   wp eval-file wp-content/plugins/wm-prototype-nda-gate/tests/wp-runtime-nda.php --user=1
 *   ssh <box> 'wp --path=<www> eval-file - --user=1' < tests/wp-runtime-nda.php
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS.
 *
 * @package WM_Prototype_NDA_Gate
 */

defined( 'ABSPATH' ) || exit;

if ( ! method_exists( 'WM_NDA_Database', 'seal_tip' ) ) {
	WP_CLI::error( 'wm-prototype-nda-gate with the reader-pass fixes (WM_NDA_Database::seal_tip) is not loaded.' );
}

// has_user_accepted() answers true for everyone under WP-CLI unless this is defined.
if ( ! defined( 'WM_NDA_TESTING_MODE' ) ) {
	define( 'WM_NDA_TESTING_MODE', true );
}
wp_cache_add_non_persistent_groups( [ 'wm_nda_gate' ] );

global $wpdb;
$GLOBALS['ndart']       = [ 'pass' => 0, 'fail' => 0 ];
$GLOBALS['ndart_check'] = static function ( bool $ok, string $label ): void {
	$GLOBALS['ndart'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
};
$GLOBALS['ndart_state'] = static function (): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',
			$wpdb->options,
			$wpdb->esc_like( 'wm_nda' ) . '%',
			$wpdb->esc_like( '_transient_wm_nda' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wm_nda' ) . '%'
		),
		ARRAY_A
	);
};
$GLOBALS['ndart_before'] = $GLOBALS['ndart_state']();

register_shutdown_function(
	static function (): void {
		$same = $GLOBALS['ndart_state']() === $GLOBALS['ndart_before'];
		echo "\n  STORAGE CHECK: " . ( $same ? 'no plugin option or transient written' : 'DIFFERENT' ) . "\n";
		echo "  RUNTIME RESULTS: {$GLOBALS['ndart']['pass']} PASSED | {$GLOBALS['ndart']['fail']} FAILED\n";
		exit( $same && 0 === $GLOBALS['ndart']['fail'] ? 0 : 1 );
	}
);

$check = $GLOBALS['ndart_check'];
$acc   = WM_NDA_Database::acceptances_table();

// The active term straight from the table, handed to get_active_term() so it never sets its transient.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$GLOBALS['ndart_term'] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE is_active = 1 ORDER BY term_id DESC LIMIT 1', WM_NDA_Database::terms_table() ) );
$GLOBALS['ndart_pre_term'] = static fn() => $GLOBALS['ndart_term'] ?: false;
add_filter( 'pre_transient_wm_nda_active_term', $GLOBALS['ndart_pre_term'] );

echo "\n=== WM PROTOTYPE NDA GATE · RUNTIME ===\n";

// --- Register ----------------------------------------------------------------------------------------------------
$register = WM_NDA_Database::verify_register();
$tip      = get_option( WM_NDA_Database::TIP_OPTION, null );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$rows       = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY acceptance_id ASC', $acc ) );
$valid_rows = count( array_filter( $rows, [ WM_NDA_Database::class, 'row_signature_valid' ] ) );

$check( '3' === (string) get_option( 'wm_nda_schema_version' ), 'schema 3 (sealed register tip) is installed' );
$check(
	$register['valid'] ? ( [] === $rows || ( is_array( $tip ) && isset( $tip['mac'] ) ) ) : '' !== (string) $register['error'],
	'the register verifies with a sealed tip, or states why not: ' . ( $register['valid'] ? "valid, {$register['checked']} chained" : (string) $register['error'] )
);
$check(
	$register['valid'] || $valid_rows > 0 || ( str_contains( (string) $register['error'], 'Schlüssel' ) && ! str_contains( (string) $register['error'], 'nachträglich verändert' ) ),
	"when no row matches this installation's key the register names the key, not altered rows ({$valid_rows} of " . count( $rows ) . ' rows check out; was: "nachträglich verändert")'
);

// --- Gate URL and REST refusal -------------------------------------------------------------------------------------
$interceptor = new WM_NDA_Interceptor();
$wanted      = home_url( '/?p=123&page=2' );
parse_str( (string) wp_parse_url( $interceptor->gate_url( $wanted ), PHP_URL_QUERY ), $gate_query );
$check( 'wm-nda-gate' === ( $gate_query['page'] ?? '' ) && $wanted === ( $gate_query['redirect_to'] ?? '' ), 'the gate URL keeps its page argument and carries the requested URL whole (was: page=2, redirect loop)' );

$GLOBALS['ndart_fake'] = (object) [ 'term_id' => 987654, 'term_hash' => str_repeat( '0', 64 ), 'term_version' => 'runtime', 'term_title' => 'Runtime', 'term_content' => '<p>Runtime</p>', 'created_at' => '2026-01-01 00:00:00' ];
$pre_fake              = static fn() => $GLOBALS['ndart_fake'];
$pre_rest              = static fn() => '1';
remove_filter( 'pre_transient_wm_nda_active_term', $GLOBALS['ndart_pre_term'] );
add_filter( 'pre_transient_wm_nda_active_term', $pre_fake );
add_filter( 'pre_option_wm_nda_enforce_rest_api', $pre_rest );
wp_set_current_user( 1 );
$denied = $interceptor->enforce_rest_nda( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
ob_start();
WM_NDA_Modal::render_gate_modal( $GLOBALS['ndart_fake'], true );
$modal = (string) ob_get_clean();
remove_filter( 'pre_option_wm_nda_enforce_rest_api', $pre_rest );
remove_filter( 'pre_transient_wm_nda_active_term', $pre_fake );
add_filter( 'pre_transient_wm_nda_active_term', $GLOBALS['ndart_pre_term'] );

$check( is_wp_error( $denied ) && WM_NDA_I18n::get_consent_required_text() === $denied->get_error_message(), 'REST 403 for a logged-in user without an acceptance says what is missing (was: "Sicherheitsverstoß … forensisch erfasst")' );
$check( str_contains( $modal, 'data-msg-success' ) && ! str_contains( $modal, 'Schutzsystem' ), 'the modal hands its messages to the script and shows no statute-named badge' );

// --- Exports, KPIs, privacy ------------------------------------------------------------------------------------------
$cert = WM_NDA_Exporter::build_json_certificate( 0 );
preg_match_all( '/certificate_metadata\.([a-z0-9_]+)/', (string) $cert['certificate_metadata']['master_checksum_method'], $named );
$recipient = $cert;
foreach ( $named[1] as $key ) {
	unset( $recipient['certificate_metadata'][ $key ] );
}
$check(
	count( $rows ) === count( $cert['signature_records'] ) && hash_equals( (string) $cert['certificate_metadata']['master_checksum_sha256'], hash( 'sha256', (string) wp_json_encode( $recipient, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
	'the JSON certificate carries every acceptance (' . count( $cert['signature_records'] ) . ') and its stated recipe reproduces the checksum'
);

$kpis = WM_NDA_Database::get_kpis();
$check( $kpis['accepted_active_users'] <= $kpis['total_users'] && $kpis['acceptance_rate'] <= 100, "KPIs stay within the accounts that exist ({$kpis['accepted_active_users']} of {$kpis['total_users']}, {$kpis['acceptance_rate']} %)" );

$admin = get_user_by( 'id', 1 );
if ( $admin ) {
	$export = WM_NDA_Exporter::export_personal_data( $admin->user_email );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$own = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_email = %s OR user_id = %d', $acc, $admin->user_email, $admin->ID ) );
	$check( $own === count( $export['data'] ?? [] ), "the privacy export for account 1 finds its acceptances by stored e-mail and by account ({$own})" );
}

// --- Hub and REST integrity route --------------------------------------------------------------------------------------
if ( interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	if ( ! class_exists( 'WM_NDA_Spoke_Adapter' ) ) {
		require_once WM_NDA_GATE_PATH . 'includes/class-nda-spoke-adapter.php';
	}
	$health = ( new WM_NDA_Spoke_Adapter() )->get_health_status();
	$check( $register['valid'] ? in_array( $health, [ 'HEALTHY', 'DEGRADED', 'NEEDS_CONFIG' ], true ) : 'ERROR' === $health, "hub health {$health} matches the register above" );
} else {
	echo "  [INFO] hub not installed: adapter not checked\n";
}

$integrity = rest_do_request( new WP_REST_Request( 'GET', '/wm-nda/v1/register-integrity' ) );
$check( ( $register['valid'] ? 200 : 409 ) === $integrity->get_status(), 'GET /wm-nda/v1/register-integrity answers ' . $integrity->get_status() . ' for the register above' );

// --- Catalogs ----------------------------------------------------------------------------------------------------------
require_once ABSPATH . WPINC . '/pomo/mo.php';
$fr       = new MO();
$fr_ok    = $fr->import_from_file( WM_NDA_GATE_PATH . 'languages/wm-prototype-nda-gate-fr_FR.mo' );
$de       = new MO();
$de_ok    = $de->import_from_file( WM_NDA_GATE_PATH . 'languages/wm-prototype-nda-gate-de_DE.mo' );
$double   = array_filter( array_keys( $de->entries + $fr->entries ), static fn( $k ) => str_contains( (string) $k, 'Ã' ) );
$check( $fr_ok && $de_ok && [] === $double && 'Réglages' === $fr->translate( 'Einstellungen' ), 'the de_DE and fr_FR catalogs load without double-encoded entries and French translates "Einstellungen" (was: rejected by WordPress, 0 entries)' );

remove_filter( 'pre_transient_wm_nda_active_term', $GLOBALS['ndart_pre_term'] );

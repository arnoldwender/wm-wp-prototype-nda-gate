<?php
/**
 * Plugin Name:       WM Prototype NDA Gate
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-prototype-nda-gate/
 * Description:       Verlangt von angemeldeten Benutzern die Bestätigung einer Vertraulichkeitsvereinbarung vor Login, wp-admin, Frontend und REST und speichert jede Bestätigung in einem verketteten HMAC-Register mit CSV- und JSON-Export. Keine rechtliche Bewertung.
 * Version:           1.3.4
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-prototype-nda-gate
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Global Plugin Constants
if ( ! defined( 'WM_NDA_GATE_VERSION' ) ) {
	define( 'WM_NDA_GATE_VERSION', '1.3.4' );
}
if ( ! defined( 'WM_NDA_GATE_FILE' ) ) {
	define( 'WM_NDA_GATE_FILE', __FILE__ );
}
if ( ! defined( 'WM_NDA_GATE_PATH' ) ) {
	define( 'WM_NDA_GATE_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WM_NDA_GATE_URL' ) ) {
	define( 'WM_NDA_GATE_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WM_NDA_GATE_BASENAME' ) ) {
	define( 'WM_NDA_GATE_BASENAME', plugin_basename( __FILE__ ) );
}

// Require Core Subsystems
require_once WM_NDA_GATE_PATH . 'includes/class-nda-audit.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-evidence.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-watermark.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-database.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-i18n.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-modal.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-interceptor.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-rest-api.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-exporter.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-retention.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-seeder.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-admin.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-cli.php';
require_once WM_NDA_GATE_PATH . 'includes/class-nda-core.php';

/**
 * Register as Spoke in WM Suite Hub
 */
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'WM_NDA_Spoke_Adapter' ) && file_exists( WM_NDA_GATE_PATH . 'includes/class-nda-spoke-adapter.php' ) ) {
		require_once WM_NDA_GATE_PATH . 'includes/class-nda-spoke-adapter.php';
	}
	if ( class_exists( 'WM_NDA_Spoke_Adapter' ) ) {
		$modules[] = new WM_NDA_Spoke_Adapter();
	}
	return $modules;
} );

/**
 * Provision NDA tables, default term, cache salt, and default options for the current site.
 */
function wm_nda_provision_site(): void {
	WM_NDA_Database::create_tables();
	update_option( 'wm_nda_schema_version', WM_NDA_Database::SCHEMA_VERSION, false );
	WM_NDA_Database::seed_default_term();
	// A reactivated install keeps its register: its tip is sealed when the register verifies (see the upgrade below).
	WM_NDA_Database::seal_existing_tip();
	if ( '' === (string) get_option( WM_NDA_Database::CACHE_SALT_OPTION, '' ) ) {
		WM_NDA_Database::rotate_cache_salt();
	}

	// Set default settings if not already present
	if ( false === get_option( 'wm_nda_require_login_checkbox' ) ) {
		update_option( 'wm_nda_require_login_checkbox', 1 );
	}
	if ( false === get_option( 'wm_nda_enforce_admin_gate' ) ) {
		update_option( 'wm_nda_enforce_admin_gate', 1 );
	}
	if ( false === get_option( 'wm_nda_enforce_frontend_gate' ) ) {
		update_option( 'wm_nda_enforce_frontend_gate', 1 );
	}
	if ( false === get_option( 'wm_nda_enforce_rest_api' ) ) {
		update_option( 'wm_nda_enforce_rest_api', 1 );
	}
	// Off by default: the overlay paints on every screen of the site, and that is the operator's call.
	if ( false === get_option( WM_NDA_Watermark::OPTION ) ) {
		update_option( WM_NDA_Watermark::OPTION, 0 );
	}
	if ( false === get_option( 'wm_nda_retention_days' ) ) {
		update_option( 'wm_nda_retention_days', 90, false );
	}
	if ( false === get_option( 'wm_nda_project_name' ) ) {
		update_option( 'wm_nda_project_name', get_bloginfo( 'name' ) . ' (Prototyp)' );
	}
	if ( false === get_option( 'wm_nda_delete_data_on_uninstall' ) ) {
		update_option( 'wm_nda_delete_data_on_uninstall', 0, false );
	}
}

/**
 * Activation Lifecycle Hook (single site or network-wide multisite).
 */
function wm_nda_activate_plugin( bool $network_wide = false ): void {
	if ( $network_wide && is_multisite() && function_exists( 'get_sites' ) ) {
		$site_ids = get_sites( [
			'fields' => 'ids',
			'number' => 0,
		] );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				wm_nda_provision_site();
			} finally {
				restore_current_blog();
			}
		}
		return;
	}

	wm_nda_provision_site();
}
register_activation_hook( __FILE__, 'wm_nda_activate_plugin' );

/**
 * Provision newly initialized multisite blogs when the plugin is network-activated.
 *
 * @param WP_Site|object $new_site Newly initialized site object.
 */
function wm_nda_initialize_site( object $new_site ): void {
	if ( ! is_multisite() ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	$blog_id = (int) ( $new_site->blog_id ?? 0 );
	if ( $blog_id <= 0 ) {
		return;
	}

	switch_to_blog( $blog_id );
	try {
		wm_nda_provision_site();
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'wm_nda_initialize_site', 10, 1 );

/**
 * Schema upgrades for installs that were already active.
 *
 * create_tables() only runs on activation, so adding the chain column would otherwise never
 * reach a site that already has the plugin switched on — and every acceptance would fail on a
 * missing prev_hash column.
 *
 * Schema 3 (2026-09-15) seals the register tip. A register that verifies under the unsealed rules gets its
 * tip sealed; one that does not keeps it, and verify_register() reports why. No row is re-signed.
 *
 * Schema 4 (2026-09-16) adds interaction_json. dbDelta adds the column empty, and an empty record is
 * left out of the signed payload, so every acceptance written before it keeps verifying untouched.
 */
function wm_nda_maybe_upgrade_schema(): void {
	if ( version_compare( (string) get_option( 'wm_nda_schema_version', '0' ), WM_NDA_Database::SCHEMA_VERSION, '>=' ) ) {
		return;
	}
	WM_NDA_Database::create_tables();
	WM_NDA_Database::seal_existing_tip();
	if ( '' === (string) get_option( WM_NDA_Database::CACHE_SALT_OPTION, '' ) ) {
		WM_NDA_Database::rotate_cache_salt();
	}
	update_option( 'wm_nda_schema_version', WM_NDA_Database::SCHEMA_VERSION, false );
}
add_action( 'plugins_loaded', 'wm_nda_maybe_upgrade_schema', 1 );

/**
 * Deactivation Lifecycle Hook
 */
register_deactivation_hook( __FILE__, function () {
	// Clean up transients
	delete_transient( 'wm_nda_active_term' );
	WM_NDA_Retention::unschedule();
} );

/**
 * The daily retention pass.
 *
 * Until 2026-09-17 wm_nda_retention_days was written, displayed and read back into its own form,
 * and applied by nothing. The schedule follows the setting: below 30 days means keep everything
 * and the event is removed.
 */
add_action( 'plugins_loaded', function () {
	WM_NDA_Retention::init();
}, 5 );

/**
 * Internationalization & Text Domain Loading
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'wm-prototype-nda-gate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Bootstrap Plugin Core Instance
 */
add_action( 'plugins_loaded', function () {
	WM_NDA_Core::get_instance();
} );

/**
 * Plugin Action Links in wp-admin/plugins.php
 */
add_filter( 'plugin_action_links_' . WM_NDA_GATE_BASENAME, function ( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wm-nda-settings' ) ),
		esc_html__( 'Einstellungen', 'wm-prototype-nda-gate' )
	);
	$docs_link = sprintf(
		// An inline green (3.77:1 on white) set this link apart from the others (reader audit D21).
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wm-nda-docs' ) ),
		esc_html__( 'Handbuch', 'wm-prototype-nda-gate' )
	);
	$signatures_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=wm-nda-signatures' ) ),
		esc_html__( 'Signatur-Protokoll', 'wm-prototype-nda-gate' )
	);
	return array_merge( [ $settings_link, $docs_link, $signatures_link ], $links );
} );

/**
 * Plugin Row Meta in wp-admin/plugins.php
 */
add_filter( 'plugin_row_meta', function ( array $links, string $file ): array {
	if ( WM_NDA_GATE_BASENAME === $file ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wm-nda-docs' ) ),
			esc_html__( 'Handbuch', 'wm-prototype-nda-gate' )
		);
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wm-nda-versions' ) ),
			esc_html__( 'NDA-Vertragstext bearbeiten', 'wm-prototype-nda-gate' )
		);
	}
	return $links;
}, 10, 2 );

<?php
/**
 * WM Suite Prototype NDA Gate — Uninstaller
 *
 * Triggered when the plugin is deleted via WordPress Admin. Preserves options, user meta and both
 * NDA register tables by default so an accidental removal cannot destroy signed acceptance records.
 * Deletes plugin state and drops both tables only when `wm_nda_delete_data_on_uninstall` is enabled.
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! (bool) get_option( 'wm_nda_delete_data_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;

// 1. Options (prefixed names: this file runs in the global scope). The frontend gate option and the
//    two dead options an earlier factory reset wrote were left behind until 2026-09-14.
$wm_nda_gate_options = [
	'wm_nda_schema_version',
	'wm_nda_require_login_checkbox',
	'wm_nda_enforce_admin_gate',
	'wm_nda_enforce_frontend_gate',
	'wm_nda_enforce_rest_api',
	'wm_nda_watermark',
	'wm_nda_retention_days',
	'wm_nda_project_name',
	'wm_nda_register_tip',
	'wm_nda_cache_salt',
	'wm_nda_delete_data_on_uninstall',
	'wm_nda_enforce_login_gate',
	'wm_nda_daily_salt',
	'wm_nda_salt_date',
];

foreach ( $wm_nda_gate_options as $wm_nda_gate_option ) {
	delete_option( $wm_nda_gate_option );
	delete_site_option( $wm_nda_gate_option );
}

// 2. Transient and user meta written on signing.
delete_transient( 'wm_nda_active_term' );
foreach ( [ 'wm_nda_last_accepted_term_id', 'wm_nda_last_accepted_at', 'wm_nda_pending' ] as $wm_nda_gate_meta_key ) {
	delete_metadata( 'user', 0, $wm_nda_gate_meta_key, '', true );
}

// 3. Tables.
$wm_nda_gate_terms_table       = $wpdb->prefix . 'wm_nda_terms';
$wm_nda_gate_acceptances_table = $wpdb->prefix . 'wm_nda_acceptances';

$wpdb->query( "DROP TABLE IF EXISTS {$wm_nda_gate_acceptances_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names from $wpdb->prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$wm_nda_gate_terms_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names from $wpdb->prefix.

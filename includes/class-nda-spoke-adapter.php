<?php
/**
 * WM Suite Prototype NDA Gate — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface): health and telemetry
 * from the active term and the acceptance register, self-test and cache actions.
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;

final class WM_NDA_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-prototype-nda-gate';
	}

	public function get_name(): string {
		return 'WM Prototype NDA Gate';
	}

	public function get_version(): string {
		return defined( 'WM_NDA_GATE_VERSION' ) ? WM_NDA_GATE_VERSION : '1.3.4';
	}

	public function get_description(): string {
		return 'Vertraulichkeitsvereinbarung vor Login, wp-admin, Frontend und REST für angemeldete Benutzer; verkettetes HMAC-Register der Zustimmungen.';
	}

	/** Options of the four checkpoints. */
	private const GATE_OPTIONS = [
		'wm_nda_require_login_checkbox',
		'wm_nda_enforce_admin_gate',
		'wm_nda_enforce_frontend_gate',
		'wm_nda_enforce_rest_api',
	];

	/**
	 * ERROR without the tables or when the register does not verify, NEEDS_CONFIG without an active term,
	 * DEGRADED when every gate is off, the key comes from the database or pre-chain rows cannot be checked,
	 * HEALTHY otherwise.
	 *
	 * verify_register() returns an array. Until 2026-09-14 this method tested the array itself, which is
	 * always truthy, so a tampered or truncated register was reported HEALTHY, telemetry said VERIFIED
	 * and self_test succeeded. Until 2026-09-15 a missing acceptances table and four switched-off gates
	 * were HEALTHY as well (reader audit D12).
	 */
	public function get_health_status(): string {
		if ( ! WM_NDA_Database::tables_exist() ) {
			return 'ERROR';
		}

		$term = WM_NDA_Database::get_active_term();
		if ( ! $term ) {
			return 'NEEDS_CONFIG';
		}

		$register = WM_NDA_Database::verify_register();
		if ( ! $register['valid'] ) {
			return 'ERROR';
		}

		$gates_on = array_filter( array_map( static fn( string $option ): bool => (bool) get_option( $option, 1 ), self::GATE_OPTIONS ) );
		if ( [] === $gates_on || 'wp-config' !== $register['key_source'] || $register['unchained_legacy'] > 0 ) {
			return 'DEGRADED';
		}

		return 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-prototype-nda-gate@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_NDA_GATE_FILE ),
				],
			],
			// The licence the plugin ships under: the header, readme.txt and LICENSE say GPLv2 or later.
			// Until 2026-11-05 this declared a proprietary licence with an id and a name at once;
			// `Proprietary` is not an SPDX id, and CycloneDX takes an SPDX id or a name, not both.
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			// Features, not legal labels: the plugin records acceptances, it makes no legal assessment.
			'properties'  => [
				[
					'name'  => 'wm:features:gates',
					'value' => 'wp-login checkbox (authenticate, after the password check), wp-admin and frontend redirect, REST 403 for logged-in users without a signature',
				],
				[
					'name'  => 'wm:features:register',
					'value' => 'HMAC-SHA256 chained acceptances with a tip counter outside the table, CSV and JSON export',
				],
			],
		];
	}

	/** WM Activity Sentinel is an optional audit sink; without it events go to error_log. */
	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-nda-settings' );
	}

	public function get_telemetry_metrics(): array {
		$term     = WM_NDA_Database::get_active_term();
		$register = WM_NDA_Database::verify_register();

		return [
			'tables_present'     => WM_NDA_Database::tables_exist(),
			'active_version'     => $term ? $term->term_version : null,
			'active_term_hash'   => $term ? substr( $term->term_hash, 0, 16 ) : null,
			'total_signatures'   => WM_NDA_Database::get_acceptances_count(),
			'register_integrity' => $register['valid'] ? 'VERIFIED' : 'BROKEN',
			'register_error'     => $register['error'],
			'unchained_legacy'   => (int) $register['unchained_legacy'],
			'key_source'         => $register['key_source'],
			'audit_sink'         => WM_NDA_Audit::is_forensic() ? 'sentinel' : 'error_log',
			'enforce_login'      => (bool) get_option( 'wm_nda_require_login_checkbox', 1 ),
			'enforce_admin'      => (bool) get_option( 'wm_nda_enforce_admin_gate', 1 ),
			'enforce_frontend'   => (bool) get_option( 'wm_nda_enforce_frontend_gate', 1 ),
			'enforce_rest'       => (bool) get_option( 'wm_nda_enforce_rest_api', 1 ),
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
			case 'verify_register':
				// Without the table the register reads as empty and "verified"; that is no result.
				if ( ! WM_NDA_Database::tables_exist() ) {
					return [
						'success' => false,
						'message' => 'Signatur-Register nicht prüfbar: die Tabellen des Plugins fehlen.',
					];
				}
				$register = WM_NDA_Database::verify_register();
				return [
					'success' => (bool) $register['valid'],
					'message' => $register['valid']
						? sprintf( 'Signatur-Register geprüft: %d verkettete Zustimmungen nachgerechnet.', (int) $register['checked'] )
						: 'Signatur-Register NICHT integer: ' . (string) $register['error'],
				];

			case 'purge_cache':
				delete_transient( 'wm_nda_active_term' );
				return [
					'success' => true,
					'message' => 'Zwischenspeicher der aktiven NDA-Version geleert.',
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}

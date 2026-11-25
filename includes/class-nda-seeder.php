<?php
/**
 * WM Suite Prototype NDA Gate — Demo Data & Factory Reset
 *
 * The acceptance register is a hash chain: every row signs its predecessor and the newest link is
 * mirrored in a sealed option. Two rules follow, and until 2026-09-14 this class broke both:
 *
 * 1. Demo rows never go into a register that holds real signatures. They used user IDs 1 to 5, so
 *    importing demo data made the site's first five accounts count as signed for the active term,
 *    and mixed invented acceptances into the evidence.
 * 2. Rows are never deleted from a chain that must keep verifying. Deleting the demo rows left the
 *    register permanently "NICHT integer"; the factory reset truncated the table but kept the tip
 *    option, so the empty register reported the same.
 *
 * A demo row is a row without an account: user ID 0. Until 2026-09-15 demo rows were recognised by an
 * "@demo-" e-mail or a "[Demo]" name, so "Demodaten bereinigen" also deleted a real acceptance signed from
 * such an address (reader audit D7).
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Seeder {

	/**
	 * Rows in the register: all, demo (user ID 0), real.
	 *
	 * @return array{total: int, demo: int, real: int}
	 */
	public static function register_counts(): array {
		global $wpdb;
		$table = WM_NDA_Database::acceptances_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts decide whether demo data may be touched.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$demo  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, 0 ) );
		// phpcs:enable

		return [
			'total' => $total,
			'demo'  => $demo,
			'real'  => max( 0, $total - $demo ),
		];
	}

	/**
	 * Seed five demo acceptances for the active term, only into a register without real signatures.
	 *
	 * @return array{terms: int, signatures: int, refused: bool}
	 */
	public static function seed_demo_data(): array {
		global $wpdb;
		WM_NDA_Database::seed_default_term();

		$active_term = WM_NDA_Database::get_active_term();
		if ( ! $active_term ) {
			return [ 'terms' => 0, 'signatures' => 0, 'refused' => false ];
		}

		// Same lock as record_acceptance(): a real acceptance arriving meanwhile would fork the chain.
		if ( ! WM_NDA_Database::acquire_register_lock() ) {
			return [ 'terms' => 0, 'signatures' => 0, 'refused' => false ];
		}

		try {
			if ( self::register_counts()['real'] > 0 ) {
				return [ 'terms' => 0, 'signatures' => 0, 'refused' => true ];
			}

			$acceptances_table = WM_NDA_Database::acceptances_table();

			// user_id 0: no WordPress account, so has_user_accepted() never counts a real user as signed.
			$demo_signers = [
				[
					'name'       => 'Dr. Maximilian von Berg [Demo]',
					'email'      => 'm.berg@example.com',
					'ip'         => '192.168.1.101',
					'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36',
					'interaction' => [ 'pointer_type' => 'mouse', 'accept_input' => 'pointer', 'move_samples' => 184, 'path_px' => 3120, 'pressure_max_permille' => 500, 'pressure_samples' => 0, 'dwell_ms' => 74200, 'check_to_accept_ms' => 2600, 'scroll_pct' => 100, 'viewport_w' => 1680, 'viewport_h' => 1010, 'tz_offset_min' => 120 ],
				],
				[
					'name'       => 'Katharina Bauer [Demo]',
					'email'      => 'k.bauer@example.net',
					'ip'         => '192.168.1.102',
					'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0.0.0 Safari/537.36',
					'interaction' => [ 'pointer_type' => 'mouse', 'accept_input' => 'keyboard', 'move_samples' => 41, 'path_px' => 690, 'dwell_ms' => 128400, 'check_to_accept_ms' => 9100, 'scroll_pct' => 96, 'viewport_w' => 1920, 'viewport_h' => 947, 'tz_offset_min' => 120 ],
				],
				[
					'name'       => 'Prof. Dr. Stefan Meier [Demo]',
					'email'      => 's.meier@example.org',
					'ip'         => '192.168.1.103',
					'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
					'interaction' => [ 'pointer_type' => 'mouse', 'accept_input' => 'pointer', 'move_samples' => 302, 'path_px' => 5480, 'pressure_max_permille' => 500, 'dwell_ms' => 213700, 'check_to_accept_ms' => 4300, 'scroll_pct' => 100, 'viewport_w' => 1440, 'viewport_h' => 860, 'tz_offset_min' => 120 ],
				],
				[
					'name'       => 'Elena Rostova [Demo]',
					'email'      => 'e.rostova@example.com',
					'ip'         => '192.168.1.104',
					'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
					'interaction' => [ 'pointer_type' => 'touch', 'accept_input' => 'pointer', 'move_samples' => 27, 'path_px' => 410, 'pressure_max_permille' => 830, 'pressure_samples' => 27, 'dwell_ms' => 96500, 'check_to_accept_ms' => 3800, 'scroll_pct' => 88, 'viewport_w' => 390, 'viewport_h' => 763, 'tz_offset_min' => 180 ],
				],
				[
					'name'       => 'Thorsten Lindner [Demo]',
					'email'      => 't.lindner@example.org',
					'ip'         => '192.168.1.105',
					'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7) AppleWebKit/605.1.15 Safari/605.1.15',
					'interaction' => [ 'pointer_type' => 'pen', 'accept_input' => 'pointer', 'move_samples' => 96, 'path_px' => 1740, 'pressure_max_permille' => 612, 'pressure_samples' => 96, 'dwell_ms' => 55100, 'check_to_accept_ms' => 1900, 'scroll_pct' => 73, 'viewport_w' => 1112, 'viewport_h' => 834, 'tz_offset_min' => 120 ],
				],
			];

			$seeded_signatures = 0;
			$term_id           = (int) $active_term->term_id;
			$term_hash         = (string) $active_term->term_hash;

			foreach ( $demo_signers as $signer ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$acceptances_table} WHERE user_email = %s AND term_id = %d", $signer['email'], $term_id ) );
				if ( (int) $existing > 0 ) {
					continue;
				}

				$timestamp = current_time( 'mysql', true );
				$ip_hmac   = WM_NDA_Database::generate_ip_hmac( $signer['ip'] );
				$prev_hash = WM_NDA_Database::current_chain_tip();
				// Through the same sanitiser as a real acceptance: demo rows that skipped it would
				// carry a record no browser could have produced, and the screens would show it.
				$interaction_json = WM_NDA_Evidence::canonical_json( WM_NDA_Evidence::sanitize( $signer['interaction'] ) );
				$sig_hash  = WM_NDA_Database::generate_signature_hash( 0, $term_id, $term_hash, $timestamp, $ip_hmac, $prev_hash, WM_NDA_Evidence::fingerprint( $interaction_json ) );

				$inserted = $wpdb->insert(
					$acceptances_table,
					[
						'user_id'              => 0,
						'term_id'              => $term_id,
						'user_email'           => $signer['email'],
						'user_name'            => $signer['name'],
						'term_hash_at_signing' => $term_hash,
						'ip_hmac'              => $ip_hmac,
						'user_agent'           => $signer['user_agent'],
						'interaction_json'     => $interaction_json,
						'accepted_at'          => $timestamp,
						'prev_hash'            => $prev_hash,
						'signature_hash'       => $sig_hash,
					],
					[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
				);

				if ( ! $inserted ) {
					break;
				}

				WM_NDA_Database::seal_tip( $sig_hash, self::register_counts()['total'] );

				$seeded_signatures++;
			}
		} finally {
			WM_NDA_Database::release_register_lock();
		}

		delete_transient( 'wm_nda_active_term' );
		wp_cache_flush_group( 'wm_nda_gate' );

		return [
			'terms'      => 1,
			'signatures' => $seeded_signatures,
			'refused'    => false,
		];
	}

	/**
	 * Delete the demo rows, only when the register holds nothing else: then the whole register and its
	 * tip go, and the empty register verifies. With real signatures in the chain nothing is deleted.
	 *
	 * @return array{deleted: int, refused: bool}
	 */
	public static function delete_demo_data(): array {
		global $wpdb;
		$counts = self::register_counts();

		if ( 0 === $counts['demo'] ) {
			return [ 'deleted' => 0, 'refused' => false ];
		}
		if ( $counts['real'] > 0 ) {
			return [ 'deleted' => 0, 'refused' => true ];
		}

		$table = WM_NDA_Database::acceptances_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting demo rows, nothing to cache.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $table, 0 ) );
		delete_option( WM_NDA_Database::TIP_OPTION );

		delete_transient( 'wm_nda_active_term' );
		wp_cache_flush_group( 'wm_nda_gate' );

		return [ 'deleted' => $counts['demo'], 'refused' => false ];
	}

	/**
	 * Factory reset: every acceptance and term deleted, default term restored, gate options back to on.
	 */
	public static function reset_to_factory_defaults(): void {
		WM_NDA_Database::reset_tables();

		// Options the gate actually reads. Until 2026-09-14 this wrote wm_nda_enforce_login_gate and
		// deleted wm_nda_daily_salt / wm_nda_salt_date, which nothing reads, and never touched the
		// login checkbox option.
		update_option( 'wm_nda_require_login_checkbox', 1 );
		update_option( 'wm_nda_enforce_frontend_gate', 1 );
		update_option( 'wm_nda_enforce_admin_gate', 1 );
		update_option( 'wm_nda_enforce_rest_api', 1 );
		update_option( WM_NDA_Watermark::OPTION, 0 );
		update_option( 'wm_nda_retention_days', 90, false );
		update_option( 'wm_nda_delete_data_on_uninstall', 0, false );
		delete_option( 'wm_nda_enforce_login_gate' );
	}
}

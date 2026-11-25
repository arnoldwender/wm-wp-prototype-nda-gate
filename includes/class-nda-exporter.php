<?php
/**
 * WM Suite Prototype NDA Gate — CSV and JSON export, WordPress privacy exporter and eraser
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Exporter {

	/** The recipe every JSON export states; following it reproduces master_checksum_sha256. */
	public const CHECKSUM_METHOD = 'sha256 over this document re-encoded with JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE after removing certificate_metadata.master_checksum_sha256';

	/**
	 * Sanitize cell to prevent CSV Formula Injection (CWE-1236)
	 */
	public static function sanitize_csv_cell( string $value ): string {
		$dangerous_prefixes = [ '=', '+', '-', '@', "\t", "\r" ];
		$first_char = mb_substr( $value, 0, 1 );

		if ( in_array( $first_char, $dangerous_prefixes, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Export acceptances as a secure, sanitized CSV file
	 */
	public static function export_csv( int $term_id = 0 ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-prototype-nda-gate' ), 403 );
		}

		check_admin_referer( 'wm_nda_export_csv' );

		$filename = 'wm-nda-audit-signatures-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		self::write_csv( $output, WM_NDA_Database::get_all_acceptances( $term_id ) );

		// php://output is the HTTP response stream, not the filesystem; WP_Filesystem does not apply.
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- response stream, not a file.
		exit;
	}

	/**
	 * Write acceptances as CSV (UTF-8 BOM, semicolon) to an open stream; every cell passes sanitize_csv_cell().
	 *
	 * The empty escape character follows RFC 4180. Without it PHP 8.4 deprecates the call, and with
	 * display_errors on the notice was written into the file (reader audit 2026-09-15, D17).
	 *
	 * @param resource $output      Writable stream.
	 * @param array    $acceptances Rows from WM_NDA_Database::get_all_acceptances().
	 */
	public static function write_csv( $output, array $acceptances ): void {
		// Output UTF-8 BOM for Excel compatibility
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$output,
			[
				'Acceptance ID',
				'User ID',
				'Benutzername',
				'E-Mail-Adresse',
				'NDA Version',
				'Vertragstitel',
				'Dokumenten-Hash (SHA-256)',
				'IP-HMAC',
				'User Agent',
				'Interaktionsnachweis (JSON)',
				'Zeitstempel (UTC)',
				'Signatur-Hash (HMAC-SHA256)',
			],
			';',
			'"',
			''
		);

		foreach ( $acceptances as $row ) {
			fputcsv(
				$output,
				[
					self::sanitize_csv_cell( (string) $row->acceptance_id ),
					self::sanitize_csv_cell( (string) $row->user_id ),
					self::sanitize_csv_cell( (string) $row->user_name ),
					self::sanitize_csv_cell( (string) $row->user_email ),
					self::sanitize_csv_cell( (string) $row->term_version ),
					self::sanitize_csv_cell( (string) $row->term_title ),
					self::sanitize_csv_cell( (string) $row->term_hash_at_signing ),
					self::sanitize_csv_cell( (string) $row->ip_hmac ),
					self::sanitize_csv_cell( (string) $row->user_agent ),
					// The stored form, not a rendered summary: it is what the signature covers, so a
					// recipient can hash this cell and compare.
					self::sanitize_csv_cell( (string) ( $row->interaction_json ?? '' ) ),
					self::sanitize_csv_cell( (string) $row->accepted_at ),
					self::sanitize_csv_cell( (string) $row->signature_hash ),
				],
				';',
				'"',
				''
			);
		}
	}

	/**
	 * Download the register as JSON: every acceptance with its chained signature, the current verification and a
	 * checksum over the file.
	 */
	public static function export_json( int $term_id = 0 ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-prototype-nda-gate' ), 403 );
		}

		check_admin_referer( 'wm_nda_export_json' );

		$payload  = self::build_json_certificate( $term_id );
		// Until 1.3.4 the download was named "wm-nda-audit-certificate-…": nothing about it is a certificate.
		$filename = 'wm-nda-audit-register-' . gmdate( 'Y-m-d-His' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// A JSON document served as a download (Content-Type application/json): HTML escaping would corrupt it.
		echo (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/**
	 * The JSON export with every acceptance of the term (0: all).
	 *
	 * The method and the key certificate_metadata keep their names from 1.2: the checksum recipe of every export
	 * names the key, and scripts read it. The file is no certificate; register_integrity says what was checked.
	 *
	 * Everything but master_checksum_sha256 is in place before the checksum is taken, so removing that one field
	 * reproduces it. Until 2026-09-15 master_checksum_scope was added after hashing and the stated recipe never
	 * matched (reader audit D6); the export also stopped at 10 000 rows without saying so (D16).
	 */
	public static function build_json_certificate( int $term_id = 0 ): array {
		$active_term = $term_id > 0 ? WM_NDA_Database::get_term_by_id( $term_id ) : WM_NDA_Database::get_active_term();
		$acceptances = WM_NDA_Database::get_all_acceptances( $term_id );

		$payload = [
			'certificate_metadata' => [
				'issuer'                 => 'WM Suite Prototype NDA Gate',
				'system_url'             => site_url(),
				'generated_at_utc'       => current_time( 'mysql', true ),
				'project_name'           => get_option( 'wm_nda_project_name', get_bloginfo( 'name' ) ),
				'total_signatures'       => count( $acceptances ),
				// Was 'compliance_shield' => 'GeschGehG § 2 Nr. 1 lit. b, UrhG § 69e, DSGVO Art. 6/7': a legal
				// label printed into an evidence file. The export states what it holds, not what it is worth.
				'scope'                  => 'Acceptances recorded by this plugin with their chained signatures. No legal assessment.',
				'signature_payload'      => 'HMAC-SHA256 over prev_hash|user_id|term_id|term_hash_at_signing|accepted_at_utc|ip_hmac, with |interaction_sha256 appended when the acceptance carries an interaction record.',
				'master_checksum_method' => self::CHECKSUM_METHOD,
				'master_checksum_scope'  => 'Covers this exported file only. It shows the document was not altered after export; it says nothing about the database it was read from. That question is answered by register_integrity.',
			],
			'term_definition'      => $active_term ? [
				'term_id'      => (int) $active_term->term_id,
				'term_version' => $active_term->term_version,
				'term_title'   => $active_term->term_title,
				'term_hash'    => $active_term->term_hash,
				'created_at'   => $active_term->created_at,
			] : null,
			'signature_records'    => array_map(
				static function ( $a ) {
					return [
						'acceptance_id'        => (int) $a->acceptance_id,
						'user_id'              => (int) $a->user_id,
						'user_name'            => $a->user_name,
						'user_email'           => $a->user_email,
						'term_version'         => $a->term_version,
						'term_hash_at_signing' => $a->term_hash_at_signing,
						'ip_hmac'              => $a->ip_hmac,
						'user_agent'           => $a->user_agent,
						// What the browser reported, and the hash of it that the signature covers.
						// Empty for acceptances recorded before schema 4 and for browsers that sent none.
						'interaction'          => WM_NDA_Evidence::from_row( $a ),
						'interaction_sha256'   => WM_NDA_Evidence::fingerprint( (string) ( $a->interaction_json ?? '' ) ),
						'accepted_at_utc'      => $a->accepted_at,
						'prev_hash'            => $a->prev_hash,
						'signature_hash'       => $a->signature_hash,
					];
				},
				$acceptances
			),
		];

		// The register verification travels with the export. Without it the recipient holds a
		// list of hashes and no statement about whether they still match the rows they describe.
		$payload['register_integrity'] = WM_NDA_Database::verify_register();

		$payload['certificate_metadata']['master_checksum_sha256'] = hash( 'sha256', (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return $payload;
	}

	/**
	 * Register WordPress Core Personal Data Exporter.
	 */
	public static function register_privacy_exporters( array $exporters ): array {
		$exporters['wm-nda-signatures'] = [
			'exporter_friendly_name' => __( 'WM Prototype NDA Gate Signaturen', 'wm-prototype-nda-gate' ),
			'callback'               => [ __CLASS__, 'export_personal_data' ],
		];
		return $exporters;
	}

	/**
	 * Register the WordPress personal data eraser.
	 */
	public static function register_privacy_erasers( array $erasers ): array {
		$erasers['wm-nda-signatures'] = [
			'eraser_friendly_name' => __( 'WM Prototype NDA Gate Signaturen', 'wm-prototype-nda-gate' ),
			'callback'             => [ __CLASS__, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/**
	 * ID of the account with this e-mail address, or -1 (matches no row; demo rows carry 0).
	 *
	 * The privacy tools matched only the e-mail stored at signing, so an account that changed its address got
	 * nothing exported and nothing erased (reader audit 2026-09-15, D13).
	 */
	private static function account_id( string $email_address ): int {
		$user = get_user_by( 'email', $email_address );
		return $user ? (int) $user->ID : -1;
	}

	/**
	 * Exporter Callback for WordPress Core Privacy Tool.
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$export_items = [];

		// Until 2026-09-14 this called WM_NDA_Database::get_acceptances_table(), a method that never
		// existed: the core privacy exporter and eraser died with a fatal on first use. Caught by
		// test group 8, which now exercises both callbacks.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy export reads the register directly; nothing to cache.
		$signatures = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.*, t.term_title, t.term_version
				 FROM %i a
				 LEFT JOIN %i t ON a.term_id = t.term_id
				 WHERE a.user_email = %s OR a.user_id = %d',
				WM_NDA_Database::acceptances_table(),
				WM_NDA_Database::terms_table(),
				$email_address,
				self::account_id( $email_address )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! empty( $signatures ) ) {
			foreach ( $signatures as $sig ) {
				$item_data = [
					[
						'name'  => __( 'NDA Vereinbarung', 'wm-prototype-nda-gate' ),
						'value' => esc_html( $sig->term_title ?? 'Prototyp-NDA' ),
					],
					[
						'name'  => __( 'Version', 'wm-prototype-nda-gate' ),
						'value' => esc_html( $sig->term_version ?? '1.0' ),
					],
					[
						'name'  => __( 'Unterzeichner Name', 'wm-prototype-nda-gate' ),
						'value' => esc_html( $sig->user_name ),
					],
					[
						'name'  => __( 'Unterzeichner E-Mail', 'wm-prototype-nda-gate' ),
						'value' => esc_html( $sig->user_email ),
					],
					[
						'name'  => __( 'Signatur-Zeitpunkt (UTC)', 'wm-prototype-nda-gate' ),
						'value' => esc_html( $sig->accepted_at ),
					],
					[
						'name'  => __( 'Signatur-Hash (SHA-256)', 'wm-prototype-nda-gate' ),
						'value' => esc_html( $sig->signature_hash ),
					],
				];

				// The click-wrap record is data about this person's device and behaviour, so it
				// goes into the personal data export. Rows without one simply add nothing.
				foreach ( WM_NDA_Evidence::describe( WM_NDA_Evidence::from_row( $sig ) ) as $detail ) {
					$item_data[] = [
						'name'  => $detail['label'],
						'value' => esc_html( $detail['value'] ),
					];
				}

				$export_items[] = [
					'group_id'    => 'wm_nda_signatures',
					'group_label' => __( 'Prototyp NDA Signaturen', 'wm-prototype-nda-gate' ),
					'item_id'     => "wm-nda-sig-{$sig->acceptance_id}",
					'data'        => $item_data,
				];
			}
		}

		return [
			'data' => $export_items,
			'done' => true,
		];
	}

	/**
	 * Eraser Callback for WordPress Core Privacy Tool.
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$items_removed  = 0;
		$items_retained = false;
		$messages       = [];

		$table      = WM_NDA_Database::acceptances_table();
		$account_id = self::account_id( $email_address );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- erasure must see the live rows.
		$signatures = $wpdb->get_results(
			$wpdb->prepare( 'SELECT acceptance_id FROM %i WHERE user_email = %s OR user_id = %d', $table, $email_address, $account_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! empty( $signatures ) ) {
			foreach ( $signatures as $sig ) {
				// Pseudonymise the PII and keep the acceptance. ip_hmac stays as it is: it is part of
				// the signed payload (see WM_NDA_Database::verify_register()), so rewriting it — as this
				// method did until 2026-09-14 — made the register report every erased row as tampered.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					[
						// Until 1.3.3 the placeholder named Art. 17 DSGVO; it now says what happened (Arnold, 21.11.2026).
						'user_name'  => '[Pseudonymisiert]',
						'user_email' => sprintf( 'anonymized-%d@privacy.local', (int) $sig->acceptance_id ),
						'user_agent' => '[Anonymisiert]',
					],
					[ 'acceptance_id' => (int) $sig->acceptance_id ]
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$items_removed++;
			}

			// The account's own pointers to its last acceptance go with the personal data.
			if ( $account_id > 0 ) {
				delete_user_meta( $account_id, 'wm_nda_last_accepted_term_id' );
				delete_user_meta( $account_id, 'wm_nda_last_accepted_at' );
			}

			// Account ID, signature, time, version and IP pseudonym stay: WordPress shows retained data only when
			// the eraser says so, and until 2026-09-15 it said nothing was retained (reader audit D13).
			$items_retained = true;

			$messages[] = sprintf(
				/* translators: %d: Number of signature records */
				__( '%d NDA-Signaturdatensätze wurden pseudonymisiert: Name, E-Mail-Adresse und User-Agent sind ersetzt.', 'wm-prototype-nda-gate' ),
				count( $signatures )
			);
			$messages[] = __( 'Beibehalten, weil sie Teil der verketteten Signatur sind: Konto-ID, Signatur-Hash, Zeitstempel, Vertragsversion, das tagesgesalzene IP-Pseudonym und der Interaktionsnachweis (Eingabegerät, Dauer, Zeigerweg, Sichtfenster, Zeitzonenversatz). Die Konto-ID verweist weiter auf das WordPress-Konto, wer den Salt kennt, kann das IP-Pseudonym gegen Kandidaten-Adressen prüfen, und Sichtfenster und Zeitzonenversatz beschreiben das benutzte Gerät.', 'wm-prototype-nda-gate' );
		}

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}
}

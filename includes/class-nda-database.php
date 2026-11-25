<?php
/**
 * WM Suite Prototype NDA Gate — Database Layer
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Database {

	/**
	 * Bumped whenever create_tables() or the stored register format changes.
	 * 3 (2026-09-15): the register tip carries a key check and a seal.
	 * 4 (2026-09-16): acceptances carry the click-wrap interaction record, signed with the row.
	 */
	public const SCHEMA_VERSION = '4';

	/** Predecessor of the first chained acceptance. */
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/** Mirrors the register tip outside the acceptances table so tail deletion is detectable. */
	public const TIP_OPTION = 'wm_nda_register_tip';

	/** Part of every acceptance cache key; a new value retires every cached answer on any object-cache backend. */
	public const CACHE_SALT_OPTION = 'wm_nda_cache_salt';

	/** Column limits of create_tables(): wpdb refuses a longer value instead of cutting it. */
	public const MAX_VERSION_LENGTH = 50;
	public const MAX_TITLE_LENGTH   = 255;
	public const MAX_NAME_LENGTH    = 100;

	/** Rows per query when the whole register is read for an export. */
	public const EXPORT_BATCH = 1000;

	/** The placeholder of wp-config-sample.php; WordPress itself treats it as no key (wp_salt()). */
	private const KEY_PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Table name for NDA terms versions
	 */
	public static function terms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_nda_terms';
	}

	/**
	 * Table name for user acceptance records
	 */
	public static function acceptances_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_nda_acceptances';
	}

	/**
	 * Create or update InnoDB database tables via dbDelta
	 */
	public static function create_tables(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$terms_table = self::terms_table();
		$acceptances_table = self::acceptances_table();

		$sql_terms = "CREATE TABLE {$terms_table} (
			term_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			term_version VARCHAR(50) NOT NULL,
			term_title VARCHAR(255) NOT NULL,
			term_content LONGTEXT NOT NULL,
			term_hash VARCHAR(64) NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (term_id),
			UNIQUE KEY term_version_hash (term_version, term_hash),
			KEY is_active_idx (is_active)
		) ENGINE=InnoDB {$charset_collate};";

		$sql_acceptances = "CREATE TABLE {$acceptances_table} (
			acceptance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			term_id BIGINT UNSIGNED NOT NULL,
			user_email VARCHAR(100) NOT NULL,
			user_name VARCHAR(100) NOT NULL,
			term_hash_at_signing VARCHAR(64) NOT NULL,
			ip_hmac VARCHAR(64) NOT NULL,
			user_agent TEXT NOT NULL,
			interaction_json TEXT NOT NULL,
			accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			prev_hash VARCHAR(64) NOT NULL DEFAULT '',
			signature_hash VARCHAR(64) NOT NULL,
			PRIMARY KEY  (acceptance_id),
			KEY user_term_idx (user_id, term_id),
			KEY accepted_at_idx (accepted_at)
		) ENGINE=InnoDB {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql_terms );
		dbDelta( $sql_acceptances );
	}

	/**
	 * Whether both tables exist. The hub reported HEALTHY without the acceptances table while every
	 * acceptance failed (reader audit 2026-09-15, D12).
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( [ self::terms_table(), self::acceptances_table() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live schema check.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Seed initial default NDA term if none exists
	 */
	public static function seed_default_term(): void {
		global $wpdb;
		$terms_table = self::terms_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- seed check.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $terms_table ) );
		if ( $count > 0 ) {
			return;
		}

		$version = '1.0.0';
		$title = 'Geheimhaltungs- und Prototyp-Nutzungsvereinbarung (B2B)';
		$content = WM_NDA_I18n::get_default_legal_content();
		$hash = hash( 'sha256', $content );

		$wpdb->insert(
			$terms_table,
			[
				'term_version' => $version,
				'term_title'   => $title,
				'term_content' => $content,
				'term_hash'    => $hash,
				'is_active'    => 1,
				'created_at'   => current_time( 'mysql', true ),
				'created_by'   => 1,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);
	}

	/**
	 * Retrieve the currently active NDA term
	 */
	public static function get_active_term(): ?object {
		$cached = get_transient( 'wm_nda_active_term' );
		if ( false !== $cached && is_object( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$terms_table = self::terms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in transient below.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE is_active = %d ORDER BY term_id DESC LIMIT 1', $terms_table, 1 ) );

		if ( $row ) {
			set_transient( 'wm_nda_active_term', $row, HOUR_IN_SECONDS * 12 );
			return $row;
		}

		return null;
	}

	/**
	 * Retrieve term by ID
	 */
	public static function get_term_by_id( int $term_id ): ?object {
		global $wpdb;
		$terms_table = self::terms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- term lookup by primary key.
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d', $terms_table, $term_id )
		);
	}

	/**
	 * Check if user has accepted the specified (or currently active) term.
	 *
	 * Access needs a chained row whose signature checks out with this installation's key. Until 2026-09-15 any
	 * row for the user and term counted, so a row rewritten in the database (another user_id, a blanked
	 * prev_hash) opened the gates without the key (reader audit D2).
	 */
	public static function has_user_accepted( int $user_id, ?int $term_id = null ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// Allow bypass for WP-CLI / cron, unless explicitly in testing mode
		if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'WM_NDA_TESTING_MODE' ) ) {
			return true;
		}

		if ( null === $term_id ) {
			$active_term = self::get_active_term();
			if ( ! $active_term ) {
				return true; // If no active term is configured, do not block
			}
			$term_id = (int) $active_term->term_id;
		}

		$cache_key = self::acceptance_cache_key( $user_id, $term_id );
		$cached = wp_cache_get( $cache_key, 'wm_nda_gate' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$result = self::find_valid_acceptance_id( $user_id, $term_id ) > 0;
		wp_cache_set( $cache_key, $result, 'wm_nda_gate', HOUR_IN_SECONDS * 6 );

		return $result;
	}

	/**
	 * Object-cache key for one user and term. The salt changes on a factory reset: term IDs start at 1 again,
	 * and on object caches without group flushing a cached "accepted" outlived the reset (reader audit D10).
	 */
	public static function acceptance_cache_key( int $user_id, int $term_id ): string {
		return "wm_nda_user_{$user_id}_term_{$term_id}_" . (string) get_option( self::CACHE_SALT_OPTION, '' );
	}

	/**
	 * New cache salt: every cached acceptance answer is retired.
	 */
	public static function rotate_cache_salt(): void {
		update_option( self::CACHE_SALT_OPTION, bin2hex( random_bytes( 8 ) ), true );
	}

	/**
	 * ID of the user's chained acceptance of a term whose signature checks out, or 0.
	 */
	public static function find_valid_acceptance_id( int $user_id, int $term_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- access decision, cached by the caller.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND term_id = %d AND prev_hash <> '' ORDER BY acceptance_id ASC", self::acceptances_table(), $user_id, $term_id ) );

		foreach ( (array) $rows as $row ) {
			if ( self::row_signature_valid( $row ) ) {
				return (int) $row->acceptance_id;
			}
		}

		return 0;
	}

	/**
	 * Whether a stored row's signature matches its content under this installation's key.
	 */
	public static function row_signature_valid( object $row ): bool {
		if ( '' === (string) ( $row->prev_hash ?? '' ) ) {
			return false;
		}

		$expected = self::generate_signature_hash(
			(int) $row->user_id,
			(int) $row->term_id,
			(string) $row->term_hash_at_signing,
			(string) $row->accepted_at,
			(string) $row->ip_hmac,
			(string) $row->prev_hash,
			// Taken from the column on every check, never from a stored hash beside it: a second
			// column could be left alone while the record it describes is rewritten.
			WM_NDA_Evidence::fingerprint( (string) ( $row->interaction_json ?? '' ) )
		);

		return hash_equals( $expected, (string) $row->signature_hash );
	}

	/**
	 * Record a user's acceptance of a term version.
	 */
	public static function record_acceptance(
		int $user_id,
		int $term_id,
		string $user_email,
		string $user_name,
		string $user_agent,
		string $ip_address,
		array $interaction = []
	): int|false {
		global $wpdb;
		$acceptances_table = self::acceptances_table();

		$term = self::get_term_by_id( $term_id );
		if ( ! $term ) {
			return false;
		}

		// Two acceptances at the same moment read the same tip and forked the chain for good (reader audit
		// 2026-09-15, D4). The duplicate check, the tip read, the insert and the new seal run under one lock.
		if ( ! self::acquire_register_lock() ) {
			return false;
		}

		try {
			$existing_id = self::find_valid_acceptance_id( $user_id, $term_id );
			if ( $existing_id > 0 ) {
				return $existing_id;
			}

			$timestamp = current_time( 'mysql', true );
			$ip_hmac = self::generate_ip_hmac( $ip_address );
			$prev_hash = self::current_chain_tip();
			// Stored and signed in the same canonical form, so the row proves the record it carries.
			$interaction_json = WM_NDA_Evidence::canonical_json( WM_NDA_Evidence::sanitize( $interaction ) );
			$signature_hash = self::generate_signature_hash( $user_id, $term_id, $term->term_hash, $timestamp, $ip_hmac, $prev_hash, WM_NDA_Evidence::fingerprint( $interaction_json ) );

			$wpdb->query( 'START TRANSACTION' );

			$inserted = $wpdb->insert(
				$acceptances_table,
				[
					'user_id'              => $user_id,
					'term_id'              => $term_id,
					'user_email'           => sanitize_email( $user_email ),
					// WordPress allows display names of 250 characters; the column holds 100 and wpdb refused the
					// whole row, so such an account could never sign (reader audit D9). The name is not signed.
					'user_name'            => mb_substr( sanitize_text_field( $user_name ), 0, self::MAX_NAME_LENGTH ),
					'term_hash_at_signing' => $term->term_hash,
					'ip_hmac'              => $ip_hmac,
					'user_agent'           => sanitize_text_field( $user_agent ),
					// Named in the insert even when empty: the column is NOT NULL and TEXT takes no
					// default, so an omitted value depends on the server's sql_mode.
					'interaction_json'     => $interaction_json,
					'accepted_at'          => $timestamp,
					'prev_hash'            => $prev_hash,
					'signature_hash'       => $signature_hash,
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( ! $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			$acceptance_id = (int) $wpdb->insert_id;
			$wpdb->query( 'COMMIT' );

			// Outside the transaction and in a different table on purpose: that separation is what makes
			// deleting the newest acceptances detectable; the seal makes rewriting the option detectable.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- register tip, must read the table now.
			self::seal_tip( $signature_hash, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $acceptances_table ) ) );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) { error_log( '[WM NDA Gate Error] ' . $e->getMessage() ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			return false;
		} finally {
			self::release_register_lock();
		}

		wp_cache_delete( self::acceptance_cache_key( $user_id, $term_id ), 'wm_nda_gate' );
		delete_user_meta( $user_id, 'wm_nda_pending' );
		update_user_meta( $user_id, 'wm_nda_last_accepted_term_id', $term_id );
		update_user_meta( $user_id, 'wm_nda_last_accepted_at', $timestamp );

		do_action( 'wm_nda_accepted', $acceptance_id, $user_id, $term_id, $signature_hash );

		WM_NDA_Audit::record(
			$user_id,
			'nda_consent_signed',
			'nda_term',
			$term_id,
			'notice',
			[
				'acceptance_id'  => $acceptance_id,
				'term_version'   => $term->term_version,
				'signature_hash' => $signature_hash,
				'ip_hmac'        => $ip_hmac,
				'interaction'    => '' !== $interaction_json,
			]
		);

		return $acceptance_id;
	}

	/**
	 * Serialises writes to the register. MySQL named locks are server-wide, so the name carries the database
	 * and table. SQLite (WordPress Playground, wp-now) has no named locks and serialises nothing here.
	 */
	public static function acquire_register_lock(): bool {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::register_lock_name(), 10 ) );
	}

	/**
	 * Releases the lock taken by acquire_register_lock().
	 */
	public static function release_register_lock(): void {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::register_lock_name() ) );
	}

	/**
	 * Lock name, at most 64 characters (MySQL limit).
	 */
	private static function register_lock_name(): string {
		return 'wm_nda_' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . self::acceptances_table() ), 0, 40 );
	}

	/**
	 * Daily-salted HMAC of the IP address: the register never stores the address itself.
	 */
	public static function generate_ip_hmac( string $ip_address ): string {
		// Same reasoning as chain_key(): a literal shipped in the plugin is not a salt. wp_salt()
		// is per-site even when the constant is missing.
		$salt = defined( 'NONCE_SALT' ) && '' !== (string) NONCE_SALT ? (string) NONCE_SALT : wp_salt( 'nonce' );
		$daily_salt = gmdate( 'Y-m-d' ) . $salt;
		return hash_hmac( 'sha256', $ip_address, $daily_salt );
	}

	/**
	 * Chained HMAC signature of one acceptance (recomputed by verify_register()).
	 */
	public static function generate_signature_hash(
		int $user_id,
		int $term_id,
		string $term_hash,
		string $timestamp,
		string $ip_hmac,
		string $prev_hash = self::GENESIS_HASH,
		string $evidence_hash = ''
	): string {
		$payload = "{$prev_hash}|{$user_id}|{$term_id}|{$term_hash}|{$timestamp}|{$ip_hmac}";

		// Appended only when the acceptance carries a click-wrap record, so every row written before
		// schema 4 signs exactly the payload it was signed with and keeps verifying. An empty field
		// must not be distinguishable from the old payload, or the whole register turns "tampered"
		// on the day this column arrives.
		if ( '' !== $evidence_hash ) {
			$payload .= "|{$evidence_hash}";
		}

		return hash_hmac( 'sha256', $payload, self::chain_key() );
	}

	/**
	 * Whether a configured key can sign: not empty and not the public placeholder of wp-config-sample.php.
	 * Until 2026-09-15 the placeholder counted as a key, so anyone could sign an acceptance that verified
	 * (reader audit D5).
	 */
	public static function is_usable_key( string $key ): bool {
		return '' !== $key && self::KEY_PLACEHOLDER !== $key;
	}

	/**
	 * Where the signing key comes from on this install.
	 *
	 * Until this release the fallback was the literal 'wm_nda_auth_key_2026', shipped in this
	 * file. On any install without AUTH_KEY the "secret" was public: anyone holding a copy of the
	 * plugin could mint a signature for a consent that was never given.
	 *
	 * @return string 'wp-config' or 'database'.
	 */
	public static function chain_key_source(): string {
		return defined( 'AUTH_KEY' ) && self::is_usable_key( (string) AUTH_KEY ) ? 'wp-config' : 'database';
	}

	/**
	 * The HMAC key for the signature chain. Changing it (a new AUTH_KEY, a database moved to another
	 * installation) leaves every stored signature unverifiable; there is no re-signing.
	 *
	 * @return string
	 */
	private static function chain_key(): string {
		return 'wp-config' === self::chain_key_source() ? (string) AUTH_KEY : wp_salt( 'auth' );
	}

	/**
	 * Seal of the newest chained acceptance, or the genesis constant.
	 *
	 * @return string
	 */
	public static function current_chain_tip(): string {
		global $wpdb;
		$table = self::acceptances_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live chain tip under register lock.
		$tip = $wpdb->get_var( $wpdb->prepare( "SELECT signature_hash FROM %i WHERE prev_hash <> '' ORDER BY acceptance_id DESC LIMIT 1", $table ) );

		return $tip ? (string) $tip : self::GENESIS_HASH;
	}

	/**
	 * Store the register tip with a check of the key and a seal over hash and count.
	 *
	 * Until 2026-09-15 the tip was a plain option: deleting the newest acceptance and copying the previous
	 * signature into the option, or deleting the option, left a register that verified (reader audit D2).
	 * Rewriting a sealed tip needs the key. Putting back an older sealed tip that was copied beforehand is
	 * still not detected.
	 */
	public static function seal_tip( string $hash, int $count ): void {
		update_option(
			self::TIP_OPTION,
			[
				'hash'  => $hash,
				'count' => $count,
				'key'   => self::key_check(),
				'mac'   => self::tip_mac( $hash, $count ),
			],
			false
		);
	}

	/**
	 * Seal over the tip's hash and count.
	 */
	private static function tip_mac( string $hash, int $count ): string {
		return hash_hmac( 'sha256', "wm-nda-tip|{$hash}|{$count}", self::chain_key() );
	}

	/**
	 * Short value derived from the key: tells a changed key apart from altered rows without revealing the key.
	 */
	private static function key_check(): string {
		return substr( hash_hmac( 'sha256', 'wm-nda-key-check', self::chain_key() ), 0, 16 );
	}

	/**
	 * Schema 3 migration: seal the tip of a register that verifies under the rules before the seal existed.
	 *
	 * A chained register is sealed only when its unsealed tip names the last row and the row count; a register
	 * of pre-chain rows only is sealed with the genesis hash. Anything else keeps its tip, and verify_register()
	 * reports it. No row is written.
	 *
	 * @return bool Whether the register now carries a sealed tip (or needs none).
	 */
	public static function seal_existing_tip(): bool {
		$tip = get_option( self::TIP_OPTION, null );
		if ( is_array( $tip ) && isset( $tip['mac'] ) ) {
			return true;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY acceptance_id ASC', self::acceptances_table() ) );
		if ( [] === $rows ) {
			return true;
		}

		$walk = self::walk_chain( $rows );
		if ( null !== $walk['failure'] ) {
			return false;
		}

		if ( $walk['checked'] > 0 && ( ! is_array( $tip ) || ! hash_equals( (string) ( $tip['hash'] ?? '' ), $walk['last_hash'] ) || (int) ( $tip['count'] ?? -1 ) !== count( $rows ) ) ) {
			return false;
		}

		self::seal_tip( $walk['last_hash'], count( $rows ) );
		return true;
	}

	/**
	 * Recompute every acceptance and report the first one that does not check out.
	 *
	 * Before this existed, generate_signature_hash() was only ever WRITTEN. Nothing in the plugin
	 * ever recomputed a stored row, so "kryptografische Prüfsummen" and "forensische Validierung"
	 * described hashes that no code had any occasion to disagree with. A signature nobody checks
	 * is a string in a column.
	 *
	 * Rows written before the chain existed carry an empty prev_hash and are reported as
	 * unchained: they were signed with the old payload and cannot be verified against the new
	 * one, and calling them intact would be the same overclaim.
	 *
	 * @return array{valid:bool, checked:int, unchained_legacy:int, broken_at_id:?int, error:?string, key_source:string}
	 */
	public static function verify_register(): array {
		global $wpdb;
		$table = self::acceptances_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- full chain verification must read live rows.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY acceptance_id ASC', $table ) );

		$result = array(
			'valid'            => true,
			'checked'          => 0,
			'unchained_legacy' => 0,
			'broken_at_id'     => null,
			'error'            => null,
			'key_source'       => self::chain_key_source(),
		);

		$tip = get_option( self::TIP_OPTION, null );

		if ( empty( $rows ) ) {
			if ( is_array( $tip ) && (int) ( $tip['count'] ?? 0 ) > 0 ) {
				$result['valid'] = false;
				$result['error'] = sprintf(
					/* translators: %d: previously recorded number of acceptances */
					__( 'Das Register ist leer, obwohl zuletzt %d Zustimmungen verzeichnet waren.', 'wm-prototype-nda-gate' ),
					(int) $tip['count']
				);
			}
			return $result;
		}

		$walk = self::walk_chain( (array) $rows );

		$result['checked']          = $walk['checked'];
		$result['unchained_legacy'] = $walk['unchained_legacy'];
		$result['broken_at_id']     = $walk['broken_at_id'];

		if ( null !== $walk['failure'] ) {
			$result['valid'] = false;
			$result['error'] = self::chain_failure_message( $walk, $tip );
			return $result;
		}

		// A register with its newest rows removed verifies perfectly on its own; the sealed tip catches it.
		$tip_error = self::tip_error( $tip, $walk['last_hash'], count( $rows ) );
		if ( null !== $tip_error ) {
			$result['valid'] = false;
			$result['error'] = $tip_error;
		}

		return $result;
	}

	/**
	 * Walk the rows in order. Returns codes, not messages: the schema migration runs before translations load.
	 *
	 * @param array $rows Rows ordered by acceptance_id.
	 * @return array{failure:?string, checked:int, unchained_legacy:int, broken_at_id:?int, last_hash:string}
	 */
	private static function walk_chain( array $rows ): array {
		$walk = [
			'failure'          => null,
			'checked'          => 0,
			'unchained_legacy' => 0,
			'broken_at_id'     => null,
			'last_hash'        => self::GENESIS_HASH,
		];

		$expected_prev = self::GENESIS_HASH;
		$seen_chained  = false;

		foreach ( $rows as $row ) {
			$id = (int) $row->acceptance_id;

			if ( '' === (string) $row->prev_hash ) {
				if ( $seen_chained ) {
					$walk['failure']      = 'inserted';
					$walk['broken_at_id'] = $id;
					return $walk;
				}
				++$walk['unchained_legacy'];
				continue;
			}

			$seen_chained = true;
			++$walk['checked'];

			if ( ! hash_equals( $expected_prev, (string) $row->prev_hash ) ) {
				$walk['failure']      = 'predecessor';
				$walk['broken_at_id'] = $id;
				return $walk;
			}

			if ( ! self::row_signature_valid( $row ) ) {
				// No stored signature matching the key points at a different key, not at one altered row.
				$walk['failure']      = self::any_signature_valid( $rows ) ? 'signature' : 'key';
				$walk['broken_at_id'] = $id;
				return $walk;
			}

			$expected_prev = (string) $row->signature_hash;
		}

		$walk['last_hash'] = $expected_prev;
		return $walk;
	}

	/**
	 * Whether any chained row's signature matches this installation's key.
	 */
	private static function any_signature_valid( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( self::row_signature_valid( $row ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Message for a failed chain walk.
	 */
	private static function chain_failure_message( array $walk, mixed $tip ): string {
		$id = (int) $walk['broken_at_id'];

		switch ( $walk['failure'] ) {
			case 'inserted':
				return __( 'Ein unsignierter Datensatz wurde nachträglich in die Kette eingefügt.', 'wm-prototype-nda-gate' );

			case 'predecessor':
				return sprintf(
					/* translators: %d: acceptance id */
					__( 'Kettenbruch bei Zustimmung #%d: eine Zustimmung wurde gelöscht, eingeschleust oder umsortiert.', 'wm-prototype-nda-gate' ),
					$id
				);

			case 'key':
				// Until 2026-09-15 a key that differs from the signing key was reported as an altered row
				// (reader audit D5; measured on the demo Box, whose database came from another installation).
				return self::tip_key_differs( $tip )
					? self::key_changed_message()
					: __( 'Keine gespeicherte Signatur passt zum Schlüssel dieser Installation: der Signaturschlüssel (AUTH_KEY) wurde geändert, das Register stammt aus einer anderen Installation, oder jede Zeile wurde verändert. Mit diesem Schlüssel lässt sich das nicht unterscheiden.', 'wm-prototype-nda-gate' );

			default:
				return sprintf(
					/* translators: %d: acceptance id */
					__( 'Die Signatur der Zustimmung #%d passt nicht zu ihrem Inhalt: der Datensatz wurde nachträglich verändert oder mit einem anderen Schlüssel signiert.', 'wm-prototype-nda-gate' ),
					$id
				);
		}
	}

	/**
	 * Problem with the tip of a register whose rows walked cleanly, or null.
	 */
	private static function tip_error( mixed $tip, string $last_hash, int $row_count ): ?string {
		if ( ! is_array( $tip ) || ! isset( $tip['hash'], $tip['count'] ) ) {
			return __( 'Die Kettenspitze (Option wm_nda_register_tip) fehlt: ob die neuesten Zustimmungen entfernt wurden, lässt sich nicht prüfen.', 'wm-prototype-nda-gate' );
		}

		if ( ! isset( $tip['key'], $tip['mac'] ) ) {
			return __( 'Die Kettenspitze trägt kein Siegel: ob die neuesten Zustimmungen entfernt wurden, lässt sich nicht prüfen.', 'wm-prototype-nda-gate' );
		}

		if ( self::tip_key_differs( $tip ) ) {
			return self::key_changed_message();
		}

		if ( ! hash_equals( self::tip_mac( (string) $tip['hash'], (int) $tip['count'] ), (string) $tip['mac'] ) ) {
			return __( 'Das Siegel der Kettenspitze passt nicht zu ihrem Inhalt: die Option wurde ohne den Schlüssel verändert.', 'wm-prototype-nda-gate' );
		}

		if ( ! hash_equals( (string) $tip['hash'], $last_hash ) || (int) $tip['count'] !== $row_count ) {
			return sprintf(
				/* translators: 1: rows present, 2: rows recorded in the sealed tip */
				__( 'Das Ende des Registers passt nicht zur versiegelten Kettenspitze (%1$d Zustimmungen vorhanden, %2$d verzeichnet): Zustimmungen wurden entfernt oder ohne Signatur angehängt.', 'wm-prototype-nda-gate' ),
				$row_count,
				(int) $tip['count']
			);
		}

		return null;
	}

	/**
	 * Whether the tip was sealed with a key other than this installation's.
	 */
	private static function tip_key_differs( mixed $tip ): bool {
		return is_array( $tip ) && isset( $tip['key'] ) && ! hash_equals( (string) $tip['key'], self::key_check() );
	}

	/**
	 * Message for a register sealed with another key.
	 */
	private static function key_changed_message(): string {
		return __( 'Der Signaturschlüssel dieser Installation ist ein anderer als der, mit dem das Register versiegelt wurde (AUTH_KEY geändert oder Datenbank aus einer anderen Installation übernommen). Die gespeicherten Signaturen lassen sich mit diesem Schlüssel nicht prüfen.', 'wm-prototype-nda-gate' );
	}

	/**
	 * Create and activate a new version of the NDA (Version Bump).
	 *
	 * The previous version stays active until the new one is stored. Until 2026-09-15 every term was deactivated
	 * first; a version number over 50 characters then failed to insert, no term was active, every gate let
	 * everyone through and the screen reported success (reader audit D1).
	 */
	public static function bump_version(
		string $new_version,
		string $new_title,
		string $new_content,
		int $created_by
	): int|false {
		global $wpdb;
		$terms_table = self::terms_table();

		$version = sanitize_text_field( $new_version );
		$title   = sanitize_text_field( $new_title );
		$content = wp_kses_post( $new_content );

		if ( '' === $version || '' === $title || '' === trim( $content ) || mb_strlen( $version ) > self::MAX_VERSION_LENGTH || mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return false;
		}

		// The hash covers the text as stored, after sanitising.
		$term_hash = hash( 'sha256', $content );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- version lookup.
		$term_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT term_id FROM %i WHERE term_version = %s AND term_hash = %s', $terms_table, $version, $term_hash ) );

		if ( $term_id <= 0 ) {
			$inserted = $wpdb->insert(
				$terms_table,
				[
					'term_version' => $version,
					'term_title'   => $title,
					'term_content' => $content,
					'term_hash'    => $term_hash,
					'is_active'    => 0,
					'created_at'   => current_time( 'mysql', true ),
					'created_by'   => $created_by,
				],
				[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
			);

			if ( ! $inserted ) {
				return false;
			}

			$term_id = (int) $wpdb->insert_id;
		}

		// One statement switches the stored version on and every other one off.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- version switch.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_active = CASE WHEN term_id = %d THEN 1 ELSE 0 END', $terms_table, $term_id ) );

		// Clear transient cache so all users are required to sign the new version
		delete_transient( 'wm_nda_active_term' );
		wp_cache_flush_group( 'wm_nda_gate' );

		return $term_id;
	}

	/**
	 * Fetch paginated and filterable acceptance records
	 */
	public static function get_acceptances( array $args = [] ): array {
		global $wpdb;
		$acceptances_table = self::acceptances_table();
		$terms_table = self::terms_table();

		$defaults = [
			'term_id' => 0,
			'search'  => '',
			'limit'   => 50,
			'offset'  => 0,
			'orderby' => 'accepted_at',
			'order'   => 'DESC',
		];
		$r = wp_parse_args( $args, $defaults );

		$where = [ '1=1' ];
		$values = [];

		if ( $r['term_id'] > 0 ) {
			$where[] = 'a.term_id = %d';
			$values[] = $r['term_id'];
		}

		if ( ! empty( $r['search'] ) ) {
			$where[] = '(a.user_name LIKE %s OR a.user_email LIKE %s)';
			$like = '%' . $wpdb->esc_like( $r['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$where_clause = implode( ' AND ', $where );
		$allowed_orders = [ 'accepted_at', 'user_name', 'user_email', 'acceptance_id' ];
		$orderby = in_array( $r['orderby'], $allowed_orders, true ) ? $r['orderby'] : 'accepted_at';
		$order = ( 'ASC' === strtoupper( $r['order'] ) ) ? 'ASC' : 'DESC';

		// Every dynamic part is either a whitelisted literal (orderby, order), a literal fragment
		// with placeholders (where), or a placeholder value; the table names go through %i.
		$sql = "SELECT a.*, t.term_version, t.term_title
				FROM %i a
				JOIN %i t ON a.term_id = t.term_id
				WHERE {$where_clause}
				ORDER BY a.{$orderby} {$order}
				LIMIT %d OFFSET %d";
		array_unshift( $values, $acceptances_table, $terms_table );
		$values[] = $r['limit'];
		$values[] = $r['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- assembled from literals and placeholders above; all values pass through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Every acceptance of a term (0: all), oldest first, read in batches. The exports stopped at 10 000 rows
	 * without saying so (reader audit D16).
	 */
	public static function get_all_acceptances( int $term_id = 0 ): array {
		$all    = [];
		$offset = 0;

		do {
			$batch = self::get_acceptances(
				[
					'term_id' => $term_id,
					'limit'   => self::EXPORT_BATCH,
					'offset'  => $offset,
					'orderby' => 'acceptance_id',
					'order'   => 'ASC',
				]
			);
			array_push( $all, ...$batch );
			$offset += self::EXPORT_BATCH;
		} while ( count( $batch ) === self::EXPORT_BATCH );

		return $all;
	}

	/**
	 * Retrieve total count of acceptances matching filters
	 */
	public static function get_acceptances_count( array $args = [] ): int {
		global $wpdb;
		$acceptances_table = self::acceptances_table();

		$defaults = [
			'term_id' => 0,
			'search'  => '',
		];
		$r = wp_parse_args( $args, $defaults );

		$where = [ '1=1' ];
		$values = [];

		if ( $r['term_id'] > 0 ) {
			$where[] = 'term_id = %d';
			$values[] = $r['term_id'];
		}

		if ( ! empty( $r['search'] ) ) {
			$where[] = '(user_name LIKE %s OR user_email LIKE %s)';
			$like = '%' . $wpdb->esc_like( $r['search'] ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$where_clause = implode( ' AND ', $where );
		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $acceptances_table ) );
		}

		array_unshift( $values, $acceptances_table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- assembled from literals and placeholders; values pass through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_clause}", $values ) );
	}

	/**
	 * Retrieve all terms versions
	 */
	public static function get_all_terms(): array {
		global $wpdb;
		$terms_table = self::terms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin version listing.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY term_id DESC', $terms_table ) );
	}

	/**
	 * Compute Dashboard Key Performance Indicators (KPIs)
	 */
	public static function get_kpis(): array {
		global $wpdb;
		$acceptances_table = self::acceptances_table();
		$active_term = self::get_active_term();
		$active_term_id = $active_term ? (int) $active_term->term_id : 0;

		$total_users = (int) count_users()['total_users'];

		$accepted_active_users = 0;
		if ( $active_term_id > 0 ) {
			$accepted_active_users = (int) $wpdb->get_var(
				$wpdb->prepare(
					// Accounts that still exist: rows of deleted users pushed the rate over 100 % (reader audit D15).
					// Demo rows (user_id 0) have no account and drop out through the join.
					'SELECT COUNT(DISTINCT a.user_id) FROM %i a INNER JOIN %i u ON u.ID = a.user_id WHERE a.term_id = %d',
					$acceptances_table,
					$wpdb->users,
					$active_term_id
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dashboard KPI count.
		$total_signatures = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $acceptances_table ) );
		$pending_users = max( 0, $total_users - $accepted_active_users );
		$acceptance_rate = $total_users > 0 ? round( ( $accepted_active_users / $total_users ) * 100, 1 ) : 0;

		return [
			'total_users'           => $total_users,
			'accepted_active_users' => $accepted_active_users,
			'pending_users'         => $pending_users,
			'acceptance_rate'       => $acceptance_rate,
			'total_signatures'      => $total_signatures,
			'active_version'        => $active_term ? $active_term->term_version : 'N/A',
			'active_term_id'        => $active_term_id,
		];
	}

	/**
	 * Reset NDA tables to factory defaults (Truncate acceptances, restore standard default term)
	 */
	public static function reset_tables(): void {
		global $wpdb;
		$terms_table = self::terms_table();
		$acceptances_table = self::acceptances_table();

		delete_transient( 'wm_nda_active_term' );
		// The tip counts the rows written; kept after the truncation it made the empty register report
		// "Das Register ist leer, obwohl zuletzt N Zustimmungen verzeichnet waren" (until 2026-09-14).
		delete_option( self::TIP_OPTION );
		wp_cache_flush_group( 'wm_nda_gate' );
		// Term IDs start at 1 again below; the new salt retires cached answers where the group flush does nothing.
		self::rotate_cache_salt();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- factory reset of the plugin's own tables; names from $wpdb->prefix.
		$wpdb->query( "TRUNCATE TABLE {$acceptances_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- factory reset of the plugin's own tables; names from $wpdb->prefix.
		$wpdb->query( "TRUNCATE TABLE {$terms_table}" );

		self::seed_default_term();
	}
}

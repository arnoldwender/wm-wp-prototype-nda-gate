<?php
/**
 * WM Suite Prototype NDA Gate — REST API Controller
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_REST_API {

	/**
	 * Register REST routes
	 */
	public function register_routes(): void {
		register_rest_route(
			'wm-nda/v1',
			'/accept',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_accept' ],
				'permission_callback' => [ $this, 'check_user_auth' ],
				'args'                => [
					'term_id'     => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => fn( $val ) => is_numeric( $val ) && (int) $val > 0,
					],
					// Deliberately without a schema type: a malformed record must not become a 400
					// that refuses the acceptance itself. WM_NDA_Evidence::sanitize() drops whatever
					// it does not recognise, and the acceptance is stored either way.
					'interaction' => [
						'required'    => false,
						'description' => __( 'Vom Browser gemeldete Interaktionsdaten der Click-Wrap-Bestätigung.', 'wm-prototype-nda-gate' ),
					],
				],
			]
		);

		register_rest_route(
			'wm-nda/v1',
			'/verify',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_verify' ],
				'permission_callback' => [ $this, 'check_public_nda_access' ],
			]
		);

		// Admin only. Unlike /verify this exposes the audit state of the whole register.
		register_rest_route(
			'wm-nda/v1',
			'/register-integrity',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_register_integrity' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'wm-nda/v1',
			'/active-terms',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_active_terms' ],
				'permission_callback' => [ $this, 'check_public_nda_access' ],
			]
		);
	}

	/**
	 * Permission check for public read routes (`/verify` and `/active-terms`).
	 *
	 * Keeps public NDA status/terms lookup working for visitors and the login/gate modal while
	 * enforcing transient-based IP HMAC-SHA256 rate limiting (default 60 req/min, filterable via
	 * `wm_nda_rest_rate_limit`, bypassed for `manage_options`).
	 */
	public function check_public_nda_access( WP_REST_Request $request ): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		/**
		 * Filters the per-minute IP rate limit for public NDA REST endpoints (`/verify`, `/active-terms`).
		 *
		 * @param int             $limit   Max requests per minute per daily-salted IP HMAC (default 60).
		 * @param WP_REST_Request $request Incoming REST request.
		 */
		$limit = (int) apply_filters( 'wm_nda_rest_rate_limit', 60, $request );
		if ( $limit <= 0 ) {
			return true;
		}

		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
		if ( ! $this->enforce_rate_limit( $ip_address, $limit, 'pub_' ) ) {
			return new WP_Error(
				'wm_nda_rate_limited',
				__( 'Zu viele Anfragen. Bitte warten Sie eine Minute.', 'wm-prototype-nda-gate' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Permission check: User must be authenticated
	 */
	public function check_user_auth( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentifizierung erforderlich.', 'wm-prototype-nda-gate' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Rate limiter using transients (daily-salted IP HMAC-SHA256; never stores raw IPs)
	 */
	private function enforce_rate_limit( string $ip, int $limit = 10, string $bucket = '' ): bool {
		// The register's daily-salted HMAC. An unsalted MD5 of the address in the option name was reversed by
		// enumerating a /24 (reader audit 2026-09-15, D14).
		$transient_key = 'wm_nda_rl_' . $bucket . substr( WM_NDA_Database::generate_ip_hmac( $ip ), 0, 32 );
		$attempts      = (int) get_transient( $transient_key );

		if ( $attempts >= $limit ) {
			return false;
		}

		set_transient( $transient_key, $attempts + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Handle POST /wm-nda/v1/accept
	 */
	public function handle_accept( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );

		if ( ! $this->enforce_rate_limit( $ip_address ) ) {
			return new WP_Error(
				'rest_rate_limited',
				__( 'Zu viele Anfragen. Bitte warten Sie eine Minute.', 'wm-prototype-nda-gate' ),
				[ 'status' => 429 ]
			);
		}

		$current_user = wp_get_current_user();
		$user_id = (int) $current_user->ID;
		$term_id = (int) $request->get_param( 'term_id' );

		$active_term = WM_NDA_Database::get_active_term();
		if ( ! $active_term || (int) $active_term->term_id !== $term_id ) {
			return new WP_Error(
				'rest_invalid_term',
				__( 'Die angeforderte NDA-Version ist nicht die aktive Fassung.', 'wm-prototype-nda-gate' ),
				[ 'status' => 400 ]
			);
		}

		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown' ) );
		$interaction = WM_NDA_Evidence::sanitize( $request->get_param( 'interaction' ) );

		$acceptance_id = WM_NDA_Database::record_acceptance(
			$user_id,
			$term_id,
			$current_user->user_email,
			$current_user->display_name,
			$user_agent,
			$ip_address,
			$interaction
		);

		if ( ! $acceptance_id ) {
			return new WP_Error(
				'rest_acceptance_failed',
				__( 'Fehler beim Speichern der Signatur in der Datenbank.', 'wm-prototype-nda-gate' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'success'              => true,
				'message'              => __( 'NDA-Zustimmung gespeichert.', 'wm-prototype-nda-gate' ),
				'acceptance_id'        => $acceptance_id,
				'user_id'              => $user_id,
				'term_version'         => $active_term->term_version,
				'accepted_at'          => current_time( 'mysql', true ),
				// Says whether a record was kept. An existing acceptance is returned unchanged, so a
				// true here does not promise that THIS request is the one that wrote it.
				'interaction_recorded' => [] !== $interaction,
			],
			200
		);
	}

	/**
	 * Handle GET /wm-nda/v1/register-integrity
	 *
	 * Admin only, and deliberately separate from /verify: that one answers "has this user
	 * accepted", which is a different question from "is the register still trustworthy".
	 * Answers 409 on a failing register so a monitor can act on the status code alone.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_register_integrity(): WP_REST_Response {
		$result = WM_NDA_Database::verify_register();

		return new WP_REST_Response( $result, $result['valid'] ? 200 : 409 );
	}

	/**
	 * Handle GET /wm-nda/v1/verify
	 */
	public function handle_verify( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		// The route is public so the modal can ask about the current session. Answering for an
		// arbitrary user_id turned it into a disclosure oracle — on the plugin whose subject is
		// confidentiality, an anonymous caller could enumerate who has signed the NDA.
		if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Für andere Benutzer ist diese Auskunft Administratoren vorbehalten.', 'wm-prototype-nda-gate' ) ],
				403
			);
		}

		$active_term = WM_NDA_Database::get_active_term();
		$is_accepted = ( $user_id > 0 && $active_term )
			? WM_NDA_Database::has_user_accepted( $user_id, (int) $active_term->term_id )
			: false;

		return new WP_REST_Response(
			[
				'user_id'        => $user_id,
				'is_accepted'    => $is_accepted,
				'active_version' => $active_term ? $active_term->term_version : null,
			],
			200
		);
	}

	/**
	 * Handle GET /wm-nda/v1/active-terms
	 */
	public function handle_active_terms( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$active_term = WM_NDA_Database::get_active_term();
		if ( ! $active_term ) {
			return new WP_Error(
				'rest_no_active_term',
				__( 'Keine aktive Vertraulichkeitsvereinbarung konfiguriert.', 'wm-prototype-nda-gate' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'term_id'      => (int) $active_term->term_id,
				'term_version' => $active_term->term_version,
				'term_title'   => $active_term->term_title,
				'term_content' => $active_term->term_content,
				'term_hash'    => $active_term->term_hash,
				'created_at'   => $active_term->created_at,
			],
			200
		);
	}
}

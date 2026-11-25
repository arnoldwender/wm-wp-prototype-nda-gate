<?php
/**
 * WM Suite Prototype NDA Gate — Interception & Enforcement Engine
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Interceptor {

	/** Hidden admin page that hosts the gate modal: admin.php?page=wm-nda-gate */
	public const GATE_PAGE_SLUG = 'wm-nda-gate';

	/**
	 * Register all core WordPress interception hooks
	 */
	public function init_hooks(): void {
		// 1. Login Form UI & Script Injection
		add_action( 'login_form', [ $this, 'render_login_checkbox' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_assets' ] );
		add_action( 'login_footer', [ $this, 'render_login_modal' ] );

		// 2. Authentication Validation (Pre-Cookie Issuance). `authenticate` at 99 runs after core has
		//    checked the password (priority 20), so only valid credentials reach the NDA check. Until
		//    2026-09-14 this was `wp_authenticate_user`, which WordPress applies BEFORE comparing the
		//    password: a wrong password with the box ticked recorded a signature in the user's name, and
		//    a username alone produced a "bypass attempt" audit entry and revealed that the user exists.
		add_filter( 'authenticate', [ $this, 'validate_login_nda' ], 99, 3 );

		// 3. Admin Panel Enforcement (redirect before any admin screen is built)
		add_action( 'admin_init', [ $this, 'enforce_admin_gate' ], 1 );
		add_action( 'admin_menu', [ $this, 'register_gate_page' ] );
		add_filter( 'woocommerce_prevent_admin_access', [ $this, 'keep_gate_page_reachable' ] );

		// 4. Frontend / Staging Enforcement (before the theme prints anything)
		add_action( 'template_redirect', [ $this, 'enforce_frontend_gate' ], 0 );

		// 5. REST API Pre-Dispatch Gate
		add_filter( 'rest_pre_dispatch', [ $this, 'enforce_rest_nda' ], 5, 3 );
	}

	/**
	 * Render accessible checkbox on wp-login.php form
	 */
	public function render_login_checkbox(): void {
		if ( ! (bool) get_option( 'wm_nda_require_login_checkbox', 1 ) ) {
			return;
		}

		$term = WM_NDA_Database::get_active_term();
		if ( ! $term ) {
			return;
		}

		?>
		<div class="wm-nda-login-field" role="group" aria-labelledby="wm-nda-login-legend">
			<span id="wm-nda-login-legend" class="screen-reader-text"><?php esc_html_e( 'Vertraulichkeitsvereinbarung (NDA)', 'wm-prototype-nda-gate' ); ?></span>
			
			<?php
			// Not `required`: the browser cannot know whether this user already signed the active term, and
			// until 2026-09-14 it refused to submit the form for users who had. The server decides
			// (validate_login_nda) and answers with wm_nda_required when a signature is still missing.
			?>
			<label for="wm_nda_accept" class="wm-nda-login-label">
				<input type="checkbox" name="wm_nda_accept" id="wm_nda_accept" value="1" aria-describedby="wm-nda-login-hint" />
				<span class="wm-nda-login-custom-checkbox" aria-hidden="true"></span>
				<span class="wm-nda-login-text">
					<?php echo esc_html( WM_NDA_I18n::get_login_checkbox_label() ); ?>
					<button type="button" class="wm-nda-open-modal-link" id="wm-nda-open-modal-trigger" aria-haspopup="dialog">
						<?php esc_html_e( '[Vollständigen NDA-Text lesen]', 'wm-prototype-nda-gate' ); ?>
					</button>
				</span>
			</label>
			<p id="wm-nda-login-hint" class="wm-nda-login-hint description"><?php esc_html_e( 'Nur nötig, wenn Sie die aktuelle Fassung noch nicht bestätigt haben.', 'wm-prototype-nda-gate' ); ?></p>

			<?php // Filled by assets/js/nda-gate-modal.js with what the browser observed; empty without JavaScript. ?>
			<input type="hidden" name="wm_nda_interaction" id="wm_nda_interaction" value="" />
		</div>
		<?php
	}

	/**
	 * Enqueue frontend login scripts & styles
	 */
	public function enqueue_login_assets(): void {
		if ( ! (bool) get_option( 'wm_nda_require_login_checkbox', 1 ) ) {
			return;
		}

		wp_enqueue_style(
			'wm-nda-login',
			WM_NDA_GATE_URL . 'assets/css/nda-login.css',
			[],
			(string) ( @filemtime( WM_NDA_GATE_PATH . 'assets/css/nda-login.css' ) ?: WM_NDA_GATE_VERSION )
		);

		$this->enqueue_gate_assets();
	}

	/**
	 * Modal stylesheet and script, shared by the login screen and the gate page. Versioned by
	 * file time: a file updated under an unchanged plugin version is served from the browser
	 * cache (measured in the fleet 2026-09-14).
	 */
	public function enqueue_gate_assets(): void {
		wp_enqueue_style(
			'wm-nda-gate-modal',
			WM_NDA_GATE_URL . 'assets/css/nda-gate-modal.css',
			[ 'dashicons' ],
			(string) ( @filemtime( WM_NDA_GATE_PATH . 'assets/css/nda-gate-modal.css' ) ?: WM_NDA_GATE_VERSION )
		);

		wp_enqueue_script(
			'wm-nda-gate-modal',
			WM_NDA_GATE_URL . 'assets/js/nda-gate-modal.js',
			[],
			(string) ( @filemtime( WM_NDA_GATE_PATH . 'assets/js/nda-gate-modal.js' ) ?: WM_NDA_GATE_VERSION ),
			true
		);
	}

	/**
	 * Render modal markup in login footer
	 */
	public function render_login_modal(): void {
		if ( ! (bool) get_option( 'wm_nda_require_login_checkbox', 1 ) ) {
			return;
		}

		WM_NDA_Modal::render_gate_modal( null, false );
	}

	/**
	 * NDA check on a login whose credentials WordPress has already accepted.
	 *
	 * Hooked to `authenticate` at priority 99: `$user` is a WP_User only when the password matched.
	 * Users who already signed the active term pass without the box; users who have not signed need it.
	 *
	 * @param WP_User|WP_Error|null $user     Result of the earlier authenticate callbacks.
	 * @param mixed                 $username Submitted username (unused).
	 * @param mixed                 $password Submitted password (unused).
	 */
	public function validate_login_nda( mixed $user, mixed $username = '', mixed $password = '' ): mixed {
		// Failed or not yet decided authentication passes through untouched: nothing is recorded.
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		// Application passwords (REST, XML-RPC clients) have no form to tick; they were never gated here.
		if ( function_exists( 'did_action' ) && did_action( 'application_password_did_authenticate' ) ) {
			return $user;
		}

		// Allow bypass for WP-CLI, unless in testing mode
		if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'WM_NDA_TESTING_MODE' ) ) {
			return $user;
		}

		if ( ! (bool) get_option( 'wm_nda_require_login_checkbox', 1 ) ) {
			return $user;
		}

		$term = WM_NDA_Database::get_active_term();
		if ( ! $term ) {
			return $user;
		}

		// Check if checkbox was submitted
		// Note: In case of XML-RPC or automated scripts, require checkbox in POST
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php form: core issues no nonce there, the credentials are the proof.
		$is_checked = isset( $_POST['wm_nda_accept'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['wm_nda_accept'] ) );

		// If user already accepted this term previously, permit login
		if ( WM_NDA_Database::has_user_accepted( (int) $user->ID, (int) $term->term_id ) ) {
			return $user;
		}

		if ( ! $is_checked ) {
			do_action( 'wm_nda_bypass_attempt', (int) $user->ID, (int) $term->term_id );

			WM_NDA_Audit::record(
				(int) $user->ID,
				'nda_bypass_attempt',
				'security_gate',
				(int) $term->term_id,
				'critical',
				[
					'attempted_user' => $user->user_login,
					'term_version'   => $term->term_version,
					'reason'         => 'Login attempted without checking required NDA acceptance box.',
				]
			);

			// The message may only claim a forensic record when one was actually written.
			return new WP_Error(
				'wm_nda_required',
				'<strong>' . esc_html__( 'Fehler:', 'wm-prototype-nda-gate' ) . '</strong> ' . esc_html( WM_NDA_I18n::get_login_required_text( WM_NDA_Audit::is_forensic() ) )
			);
		}

		// If checked, record signature immediately
		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) );
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown' ) );

		// The login form posts the record as a string; the modal's script fills the hidden field. A
		// browser that ran no script submits nothing and the acceptance is stored without a record.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same wp-login.php form as the checkbox above; the credentials are the proof.
		$interaction = WM_NDA_Evidence::sanitize( isset( $_POST['wm_nda_interaction'] ) ? wp_unslash( (string) $_POST['wm_nda_interaction'] ) : '' );

		$recorded = WM_NDA_Database::record_acceptance(
			(int) $user->ID,
			(int) $term->term_id,
			$user->user_email,
			$user->display_name,
			$user_agent,
			$ip_address,
			$interaction
		);

		// A login that went on without a stored acceptance only moved the refusal to the next page (reader audit
		// 2026-09-15, D9).
		if ( false === $recorded ) {
			return new WP_Error(
				'wm_nda_not_recorded',
				'<strong>' . esc_html__( 'Fehler:', 'wm-prototype-nda-gate' ) . '</strong> ' . esc_html__( 'Ihre Bestätigung konnte nicht gespeichert werden; die Anmeldung wurde deshalb nicht fortgesetzt. Bitte versuchen Sie es erneut.', 'wm-prototype-nda-gate' )
			);
		}

		return $user;
	}

	/**
	 * Keep wp-admin closed until the active term is signed.
	 *
	 * Until this release the "gate" was an overlay printed in admin_footer: the requested admin
	 * screen — post lists, settings, exports — had already been generated underneath it, and
	 * removing one element in the browser's inspector showed all of it (fleet audit 2026-09-13
	 * §6.2 #25). Now nothing is generated: unsigned users are redirected, at admin_init priority 1,
	 * to a page that contains only the modal, and admin-ajax answers 403 instead of running the
	 * action. WP-CLI and cron are exempt so that server processes are never blocked.
	 */
	public function enforce_admin_gate(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! (bool) get_option( 'wm_nda_enforce_admin_gate', 1 ) ) {
			return;
		}

		$term = $this->pending_term( get_current_user_id() );

		if ( $this->is_gate_page() ) {
			if ( null === $term ) {
				// Signed, or nothing to sign: the modal's reload lands here and continues.
				wp_safe_redirect( $this->after_gate_url() );
				exit;
			}

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_gate_assets' ] );
			return;
		}

		if ( null === $term ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			wp_send_json_error(
				[
					'code'     => 'wm_nda_required',
					'message'  => WM_NDA_I18n::get_consent_required_text(),
					'gate_url' => $this->gate_url(),
				],
				403
			);
			return;
		}

		wp_safe_redirect( $this->gate_url( $this->current_url() ) );
		exit;
	}

	/**
	 * Frontend / staging layer: logged-in users who have not signed the active term are sent to
	 * the gate before the theme prints anything. Documented since 1.0.0, present since this
	 * release. Anonymous visitors are not this plugin's business — there is nobody to sign.
	 */
	public function enforce_frontend_gate(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! (bool) get_option( 'wm_nda_enforce_frontend_gate', 1 ) ) {
			return;
		}

		if ( null === $this->pending_term( get_current_user_id() ) ) {
			return;
		}

		// A gate moved to a frontend page through wm_nda_gate_url redirected that page to itself (reader audit D11).
		$current = $this->current_url();
		if ( $this->same_page( $this->gate_url(), $current ) ) {
			return;
		}

		wp_safe_redirect( $this->gate_url( $current ) );
		exit;
	}

	/**
	 * Register the page that hosts the modal. No parent, so it appears in no menu but is served
	 * at admin.php?page=wm-nda-gate; capability "read", so every logged-in role can sign, not
	 * only administrators.
	 */
	public function register_gate_page(): void {
		$hook = add_submenu_page(
			'',
			__( 'Vertraulichkeitsvereinbarung (NDA)', 'wm-prototype-nda-gate' ),
			__( 'NDA', 'wm-prototype-nda-gate' ),
			'read',
			self::GATE_PAGE_SLUG,
			[ $this, 'render_gate_page' ]
		);

		// A page with no parent gets no title from get_admin_page_title(): the global stays null,
		// admin-header.php passes it to strip_tags() and PHP 8.1+ prints a deprecation before the
		// headers (measured 2026-09-14: "headers already sent" on every gate visit under WP_DEBUG).
		// The load- hook runs before admin-header.php, so the title is set there.
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action(
				'load-' . $hook,
				static function (): void {
					$GLOBALS['title'] = __( 'Vertraulichkeitsvereinbarung (NDA)', 'wm-prototype-nda-gate' );
				}
			);
		}
	}

	/**
	 * The gate page prints only the modal; enforce_admin_gate() has already redirected everyone
	 * who has nothing left to sign.
	 */
	public function render_gate_page(): void {
		$term = WM_NDA_Database::get_active_term();
		if ( ! $term ) {
			return;
		}

		echo '<div class="wrap wm-nda-gate-page">';
		echo '<h1 class="screen-reader-text">' . esc_html( WM_NDA_I18n::get_gate_heading() ) . '</h1>';
		WM_NDA_Modal::render_gate_modal( $term, true );
		echo '</div>';
	}

	/**
	 * WooCommerce sends customers away from wp-admin on admin_init. With the gate page living in
	 * wp-admin that would loop (frontend → gate → My Account → gate), so it is lifted for that one
	 * page and nowhere else.
	 */
	public function keep_gate_page_reachable( mixed $prevent ): bool {
		return $this->is_gate_page() ? false : (bool) $prevent;
	}

	/**
	 * URL of the gate page, optionally carrying where to continue after signing.
	 */
	public function gate_url( string $redirect_to = '' ): string {
		$args = [ 'page' => self::GATE_PAGE_SLUG ];
		if ( '' !== $redirect_to ) {
			// add_query_arg() does not encode values: a requested URL with its own "&page=" replaced the gate's page
			// argument and the gate redirected to itself with a growing URL; other arguments were cut from
			// redirect_to (reader audit 2026-09-15, D3).
			$args['redirect_to'] = rawurlencode( $redirect_to );
		}

		/**
		 * Filters where unsigned users are sent. Sites that keep some roles out of wp-admin
		 * entirely can point this at a frontend page that renders WM_NDA_Modal.
		 *
		 * @param string $url         Gate URL.
		 * @param string $redirect_to Requested URL to continue to after signing.
		 */
		return (string) apply_filters( 'wm_nda_gate_url', add_query_arg( $args, admin_url( 'admin.php' ) ), $redirect_to );
	}

	/**
	 * Whether this request is the gate page. Only admin.php dispatches plugin pages —
	 * index.php?page=wm-nda-gate would render the dashboard, so the slug alone is not enough.
	 */
	public function is_gate_page(): bool {
		if ( 'admin.php' !== (string) ( $GLOBALS['pagenow'] ?? '' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing decision only, nothing is changed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		return self::GATE_PAGE_SLUG === $page;
	}

	/**
	 * Whether two URLs name the same page: host and path, the query ignored.
	 */
	private function same_page( string $a, string $b ): bool {
		$pa = wp_parse_url( $a );
		$pb = wp_parse_url( $b );
		if ( ! is_array( $pa ) || ! is_array( $pb ) ) {
			return false;
		}

		return strtolower( (string) ( $pa['host'] ?? '' ) ) === strtolower( (string) ( $pb['host'] ?? '' ) )
			&& rtrim( (string) ( $pa['path'] ?? '/' ), '/' ) === rtrim( (string) ( $pb['path'] ?? '/' ), '/' );
	}

	/**
	 * The active term when the user has not signed it; null when there is nothing left to sign.
	 */
	private function pending_term( int $user_id ): ?object {
		$term = WM_NDA_Database::get_active_term();
		if ( ! $term || WM_NDA_Database::has_user_accepted( $user_id, (int) $term->term_id ) ) {
			return null;
		}

		return $term;
	}

	/**
	 * Where to continue after signing: redirect_to on the same host, else the admin root.
	 */
	private function after_gate_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated against the site host below.
		$raw = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';

		return '' === $raw ? admin_url() : wp_validate_redirect( $raw, admin_url() );
	}

	/**
	 * The URL being requested, to come back to it after signing. A POST has no page worth
	 * returning to, so those land on the admin root or the home page.
	 */
	private function current_url(): string {
		$method = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) );
		if ( 'GET' !== $method ) {
			return is_admin() ? admin_url() : home_url( '/' );
		}

		$host = sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$uri  = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) );

		return esc_url_raw( set_url_scheme( 'http://' . $host . $uri ) );
	}

	/**
	 * Intercept REST API requests
	 */
	public function enforce_rest_nda( mixed $result, mixed $server, WP_REST_Request $request ): mixed {
		if ( ! (bool) get_option( 'wm_nda_enforce_rest_api', 1 ) ) {
			return $result;
		}

		$route = $request->get_route();

		// Whitelist plugin's own REST routes, user self endpoints, and suite hub endpoints
		$whitelisted = (array) apply_filters(
			'wm_nda_whitelisted_routes',
			[ '/wm-nda/v1/', '/wp/v2/users/me', '/wm-hub/v1/' ],
			$request
		);

		foreach ( $whitelisted as $prefix ) {
			if ( is_string( $prefix ) && str_starts_with( $route, $prefix ) ) {
				return $result;
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$term = WM_NDA_Database::get_active_term();
			if ( $term && ! WM_NDA_Database::has_user_accepted( $user_id, (int) $term->term_id ) ) {
				// States what is missing. Until 2026-09-15 it called a pending signature a "Sicherheitsverstoß" and
				// claimed a forensic record that nothing on this path wrote (reader audit D8).
				return new WP_Error(
					'rest_forbidden',
					esc_html( WM_NDA_I18n::get_consent_required_text() ),
					[ 'status' => 403 ]
				);
			}
		}

		return $result;
	}
}

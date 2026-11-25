<?php
/**
 * WM Suite Prototype NDA Gate — Enterprise Admin Dashboard & Cockpit
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Admin {

	/**
	 * Register all admin hooks
	 */
	public function init_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
		add_action( 'admin_init', [ $this, 'handle_export_requests' ] );
		add_action( 'current_screen', [ $this, 'add_contextual_help' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	/**
	 * Register plugin settings in WordPress options schema.
	 */
	public function register_settings(): void {
		register_setting(
			'wm_nda_settings_group',
			'wm_nda_delete_data_on_uninstall',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn( mixed $val ): int => empty( $val ) ? 0 : 1,
				'default'           => 0,
			]
		);
	}

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_nda_gate_widget',
			__( 'WM Prototype NDA Gate — Signaturen', 'wm-prototype-nda-gate' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		// Same figures as the cockpit (WM_NDA_Database::get_kpis()). Until 2026-09-14 the widget
		// counted rows of wm_nda_access_tokens and wm_nda_audit_log, tables this plugin never
		// creates, and read options nothing writes, so it always showed 0 tokens and "Standby".
		$kpis         = WM_NDA_Database::get_kpis();
		$gate_enabled = (bool) get_option( 'wm_nda_enforce_admin_gate', 1 ) || (bool) get_option( 'wm_nda_enforce_frontend_gate', 1 );
		$pending      = (int) $kpis['pending_users'];
		?>
		<div class="wm-dash-widget-box wm-nda-dash-widget" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? wm_get_suite_theme() : 'cyber-events' ); ?>">
			<div class="wm-dash-banner <?php echo $gate_enabled ? 'is-valid' : 'is-warning'; ?>">
				<span class="dashicons <?php echo $gate_enabled ? 'dashicons-lock' : 'dashicons-unlock'; ?>" aria-hidden="true"></span>
				<div>
					<div class="wm-dash-banner-title">
						<?php echo $gate_enabled ? esc_html__( 'NDA-Gate aktiv (Admin- und/oder Frontend-Gate an)', 'wm-prototype-nda-gate' ) : esc_html__( 'NDA-Schranke deaktiviert (Admin- und Frontend-Gate aus)', 'wm-prototype-nda-gate' ); ?>
					</div>
					<div class="wm-dash-banner-sub"><?php esc_html_e( 'Verkettetes Signatur-Register (HMAC-SHA256)', 'wm-prototype-nda-gate' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val is-on"><?php echo esc_html( (string) $kpis['accepted_active_users'] ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Signiert (aktive Version)', 'wm-prototype-nda-gate' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val <?php echo $pending > 0 ? 'is-danger' : 'is-on'; ?>"><?php echo esc_html( (string) $pending ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Ausstehend', 'wm-prototype-nda-gate' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val">v<?php echo esc_html( (string) $kpis['active_version'] ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'NDA-Version', 'wm-prototype-nda-gate' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-nda-dashboard' ) ); ?>" class="wm-dash-btn">
					<?php esc_html_e( 'NDA Gate Cockpit', 'wm-prototype-nda-gate' ); ?> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin styles and scripts
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		// Own screens (toplevel_page_wm-nda-dashboard, wm-nda-gate_page_*), the gate page, the page
		// the hub dispatches (wm-suite_page_wm-prototype-nda-gate) and the dashboard, where the
		// widget renders from the family classes.
		$is_nda_page  = str_contains( $hook_suffix, 'wm-nda' ) || str_contains( $hook_suffix, 'wm-prototype-nda-gate' );
		$is_dashboard = ( 'index.php' === $hook_suffix );
		if ( ! $is_nda_page && ! $is_dashboard ) {
			return;
		}

		// Family sheets (canonical in the hub, byte-identical copies here): every WM plugin registers
		// the same two handles, so WordPress loads one copy however many plugins are active. Sheets
		// and scripts are versioned by file time: a file updated under an unchanged plugin version
		// was served from the browser cache (measured 2026-09-14). The NDA layer comes last.
		$root = dirname( __DIR__ ) . '/assets/';
		wp_enqueue_style(
			'wm-admin-tokens',
			WM_NDA_GATE_URL . 'assets/css/wm-admin-tokens.css',
			[],
			(string) ( @filemtime( $root . 'css/wm-admin-tokens.css' ) ?: WM_NDA_GATE_VERSION )
		);
		wp_enqueue_style(
			'wm-admin-ui',
			WM_NDA_GATE_URL . 'assets/css/wm-admin-ui.css',
			[ 'wm-admin-tokens' ],
			(string) ( @filemtime( $root . 'css/wm-admin-ui.css' ) ?: WM_NDA_GATE_VERSION )
		);
		wp_enqueue_style(
			'wm-nda-admin',
			WM_NDA_GATE_URL . 'assets/css/nda-admin.css',
			[ 'wm-admin-ui', 'dashicons' ],
			(string) ( @filemtime( $root . 'css/nda-admin.css' ) ?: WM_NDA_GATE_VERSION )
		);

		if ( ! $is_nda_page ) {
			return;
		}

		wp_enqueue_script(
			'wm-nda-admin',
			WM_NDA_GATE_URL . 'assets/js/nda-admin.js',
			[],
			(string) ( @filemtime( $root . 'js/nda-admin.js' ) ?: WM_NDA_GATE_VERSION ),
			true
		);

		// The confirmation was German hard-coded in the script; it now comes from the catalog.
		wp_localize_script(
			'wm-nda-admin',
			'wmNdaAdmin',
			[
				'confirmVersion' => __( 'Neue Fassung veröffentlichen? Die bisherige wird deaktiviert, und wer die neue noch nicht bestätigt hat, wird beim nächsten Zugriff darum gebeten.', 'wm-prototype-nda-gate' ),
			]
		);
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * A stored hash shortened for a table cell: its first $length characters and an ellipsis, printed inside
	 * <code class="wm-nda-hash">. WordPress gives every .widefat descendant word-wrap: break-word and these tables
	 * are table-layout: fixed, so a column narrower than the token cut it where the column ended: "8dfac83423be6a76"
	 * on one line and "..." on the next (versions screen at 1440 px, IP-HMAC on the signatures screen, 21.11.2026).
	 * nda-admin.css keeps the class on one line and clips it with an ellipsis where the column is narrower.
	 */
	public static function short_hash( string $hash, int $length = 16 ): string {
		return substr( $hash, 0, $length ) . '…';
	}

	/**
	 * An e-mail address for a table cell, escaped, with a line-break opportunity after the "@". In a column narrower
	 * than the address, word-wrap: break-word cut it anywhere ("e.rostova@exa" / "mple.com", signatures screen at
	 * 1280 px, 21.11.2026); <wbr> lets it break where a reader expects it. Echo it through wp_kses() with only wbr.
	 */
	public static function breakable_email( string $email ): string {
		return str_replace( '@', '@<wbr>', esc_html( $email ) );
	}

	/**
	 * Family header shared by the six screens: eyebrow badge with a dashicon, title, subtitle,
	 * and the WM Suite badge carrying the hub/standalone state. Strings arrive translated.
	 */
	private static function render_page_header( string $icon, string $eyebrow, string $title, string $subtitle ): void {
		?>
		<header class="wm-admin-header wm-nda-admin-header">
			<div class="wm-header-title">
				<span class="wm-badge wm-badge-cyan wm-nda-eyebrow">
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $eyebrow ); ?>
				</span>
				<h1><?php echo esc_html( $title ); ?></h1>
				<p class="wm-subtitle"><?php echo esc_html( $subtitle ); ?></p>
			</div>
			<div class="wm-header-badges">
				<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-prototype-nda-gate' ); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
					<?php esc_html_e( 'WM Suite', 'wm-prototype-nda-gate' ); ?>
					<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-prototype-nda-gate' ) : esc_html__( 'Standalone', 'wm-prototype-nda-gate' ); ?></span>
				</span>
			</div>
		</header>
		<?php
	}

	/**
	 * Register Top-Level and Submenu pages in WP-Admin
	 */
	public function register_admin_menu(): void {
		$kpis = WM_NDA_Database::get_kpis();
		$pending_badge = $kpis['pending_users'] > 0 
			? ' <span class="update-plugins count-' . esc_attr( (string) $kpis['pending_users'] ) . '"><span class="plugin-count">' . esc_html( (string) $kpis['pending_users'] ) . '</span></span>' 
			: '';

		add_menu_page(
			__( 'WM NDA Gate', 'wm-prototype-nda-gate' ),
			__( 'WM NDA Gate', 'wm-prototype-nda-gate' ) . $pending_badge,
			'manage_options',
			'wm-nda-dashboard',
			[ $this, 'render_dashboard_page' ],
			self::family_menu_icon(),
			26
		);

		add_submenu_page(
			'wm-nda-dashboard',
			__( 'Dashboard & KPIs', 'wm-prototype-nda-gate' ),
			__( 'Dashboard', 'wm-prototype-nda-gate' ),
			'manage_options',
			'wm-nda-dashboard',
			[ $this, 'render_dashboard_page' ]
		);

		add_submenu_page(
			'wm-nda-dashboard',
			__( 'Aktivitäten & Signaturen', 'wm-prototype-nda-gate' ),
			__( 'Signaturen & Log', 'wm-prototype-nda-gate' ) . $pending_badge,
			'manage_options',
			'wm-nda-signatures',
			[ $this, 'render_signatures_page' ]
		);

		add_submenu_page(
			'wm-nda-dashboard',
			__( 'NDA-Versionen', 'wm-prototype-nda-gate' ),
			__( 'Versionen', 'wm-prototype-nda-gate' ),
			'manage_options',
			'wm-nda-versions',
			[ $this, 'render_versions_page' ]
		);

		add_submenu_page(
			'wm-nda-dashboard',
			__( 'Export', 'wm-prototype-nda-gate' ),
			__( 'Export', 'wm-prototype-nda-gate' ),
			'manage_options',
			'wm-nda-export',
			[ $this, 'render_export_page' ]
		);

		add_submenu_page(
			'wm-nda-dashboard',
			__( 'Einstellungen', 'wm-prototype-nda-gate' ),
			__( 'Einstellungen', 'wm-prototype-nda-gate' ),
			'manage_options',
			'wm-nda-settings',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			'wm-nda-dashboard',
			__( 'Handbuch & Dokumentation', 'wm-prototype-nda-gate' ),
			__( 'Handbuch', 'wm-prototype-nda-gate' ),
			'manage_options',
			'wm-nda-docs',
			[ $this, 'render_docs_page' ]
		);
	}

	/**
	 * Handle admin POST submissions (Settings, Version Bump)
	 */
	public function handle_form_submissions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save Settings
		if ( isset( $_POST['wm_nda_save_settings'] ) ) {
			check_admin_referer( 'wm_nda_settings_nonce' );

			update_option( 'wm_nda_require_login_checkbox', isset( $_POST['wm_nda_require_login_checkbox'] ) ? 1 : 0 );
			update_option( 'wm_nda_enforce_admin_gate', isset( $_POST['wm_nda_enforce_admin_gate'] ) ? 1 : 0 );
			update_option( 'wm_nda_enforce_frontend_gate', isset( $_POST['wm_nda_enforce_frontend_gate'] ) ? 1 : 0 );
			update_option( 'wm_nda_enforce_rest_api', isset( $_POST['wm_nda_enforce_rest_api'] ) ? 1 : 0 );
			update_option( WM_NDA_Watermark::OPTION, isset( $_POST[ WM_NDA_Watermark::OPTION ] ) ? 1 : 0 );
			update_option( 'wm_nda_project_name', sanitize_text_field( wp_unslash( $_POST['wm_nda_project_name'] ?? '' ) ) );
			update_option( 'wm_nda_retention_days', absint( $_POST['wm_nda_retention_days'] ?? 90 ), false );
			update_option( 'wm_nda_delete_data_on_uninstall', isset( $_POST['wm_nda_delete_data_on_uninstall'] ) ? 1 : 0, false );
			// The schedule follows the setting: "keep everything" must not leave a daily event
			// running against a threshold that never matches.
			WM_NDA_Retention::sync_schedule();

			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-nda-settings', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Create / Bump NDA Version
		if ( isset( $_POST['wm_nda_create_version'] ) ) {
			check_admin_referer( 'wm_nda_version_nonce' );

			$version = sanitize_text_field( wp_unslash( $_POST['term_version'] ?? '1.0.1' ) );
			$title = sanitize_text_field( wp_unslash( $_POST['term_title'] ?? '' ) );
			$content = wp_kses_post( wp_unslash( $_POST['term_content'] ?? '' ) );

			// The redirect reports what bump_version() returned. Until 2026-09-15 it always said "bumped", also when
			// nothing was stored and no version was active any more (reader audit D1).
			$bumped = WM_NDA_Database::bump_version( $version, $title, $content, get_current_user_id() );
			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-nda-versions', false === $bumped ? 'bump_failed' : 'bumped' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		// Handle Seed Demo Signatures
		if ( isset( $_POST['wm_nda_seed_demo'] ) && check_admin_referer( 'wm_nda_demo_action', 'wm_nda_demo_nonce' ) ) {
			$res = WM_NDA_Seeder::seed_demo_data();
			$arg = $res['refused'] ? [ 'demo_refused' => 'seed' ] : [ 'demo_seeded' => $res['signatures'] ];
			wp_safe_redirect( add_query_arg( array_merge( [ 'page' => 'wm-nda-settings' ], $arg ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Handle Delete Demo Signatures
		if ( isset( $_POST['wm_nda_delete_demo'] ) && check_admin_referer( 'wm_nda_demo_action', 'wm_nda_demo_nonce' ) ) {
			$res = WM_NDA_Seeder::delete_demo_data();
			$arg = $res['refused'] ? [ 'demo_refused' => 'delete' ] : [ 'demo_deleted' => $res['deleted'] ];
			wp_safe_redirect( add_query_arg( array_merge( [ 'page' => 'wm-nda-settings' ], $arg ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Handle Factory Reset (Auf Werkseinstellungen zurücksetzen)
		if ( isset( $_POST['wm_nda_factory_reset'] ) && check_admin_referer( 'wm_nda_reset_action', 'wm_nda_reset_nonce' ) ) {
			WM_NDA_Seeder::reset_to_factory_defaults();
			wp_safe_redirect( add_query_arg( [ 'page' => 'wm-nda-settings', 'factory_reset' => '1' ], admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Handle Export Requests
	 */
	public function handle_export_requests(): void {
		if ( isset( $_GET['action'] ) && 'wm_nda_export_csv' === $_GET['action'] ) {
			$term_id = absint( $_GET['term_id'] ?? 0 );
			WM_NDA_Exporter::export_csv( $term_id );
		}

		if ( isset( $_GET['action'] ) && 'wm_nda_export_json' === $_GET['action'] ) {
			$term_id = absint( $_GET['term_id'] ?? 0 );
			WM_NDA_Exporter::export_json( $term_id );
		}
	}

	/**
	 * Render Unified Navigation Tabs
	 */
	public static function render_nav_tabs( string $current_page ): void {
		// The icon of each tab is a dashicon rendered next to the label (no emoji in the strings).
		$tabs = [
			'wm-nda-dashboard'  => [ 'label' => __( 'Dashboard & KPIs', 'wm-prototype-nda-gate' ), 'icon' => 'dashicons-chart-pie' ],
			'wm-nda-signatures' => [ 'label' => __( 'Signaturen & Log', 'wm-prototype-nda-gate' ), 'icon' => 'dashicons-edit' ],
			'wm-nda-versions'   => [ 'label' => __( 'Versionen', 'wm-prototype-nda-gate' ), 'icon' => 'dashicons-media-document' ],
			'wm-nda-export'     => [ 'label' => __( 'Export', 'wm-prototype-nda-gate' ), 'icon' => 'dashicons-download' ],
			'wm-nda-settings'   => [ 'label' => __( 'Einstellungen', 'wm-prototype-nda-gate' ), 'icon' => 'dashicons-admin-generic' ],
			'wm-nda-docs'       => [ 'label' => __( 'Handbuch & Glossar', 'wm-prototype-nda-gate' ), 'icon' => 'dashicons-book' ],
		];
		?>
		<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Plugin-Navigation', 'wm-prototype-nda-gate' ); ?>">
			<?php foreach ( $tabs as $page => $data ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>"
				   class="wm-tab-link <?php echo $current_page === $page ? 'active' : ''; ?>"<?php echo $current_page === $page ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $data['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render Dashboard & KPIs Cockpit
	 */
	public function render_dashboard_page(): void {
		$kpis = WM_NDA_Database::get_kpis();
		$active_term = WM_NDA_Database::get_active_term();
		$recent_acceptances = WM_NDA_Database::get_acceptances( [ 'limit' => 5 ] );

		?>
		<div class="wrap wm-admin wm-nda-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-shield',
				__( 'Vertraulichkeitsvereinbarung', 'wm-prototype-nda-gate' ),
				__( 'WM Prototype NDA Gate — Cockpit', 'wm-prototype-nda-gate' ),
				__( 'NDA für angemeldete Benutzer, Signatur-Register und Export.', 'wm-prototype-nda-gate' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-nda-dashboard' ); ?>

			<!-- Quick Jump Navigation -->
			<nav class="wm-jump-nav">
				<span class="wm-jump-nav-label"><?php esc_html_e( 'Springen zu:', 'wm-prototype-nda-gate' ); ?></span>
				<a href="#wm-sec-guide" class="wm-jump-link"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><?php esc_html_e( 'Kurzanleitung', 'wm-prototype-nda-gate' ); ?></a>
				<a href="#wm-sec-kpis" class="wm-jump-link"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Kennzahlen', 'wm-prototype-nda-gate' ); ?></a>
				<a href="#wm-sec-preview" class="wm-jump-link"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Vorschau des Modals', 'wm-prototype-nda-gate' ); ?></a>
				<a href="#wm-sec-signatures" class="wm-jump-link"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Letzte Signaturen', 'wm-prototype-nda-gate' ); ?></a>
			</nav>

			<!-- Quick Plain Language Guide -->
			<section id="wm-sec-guide" class="wm-quick-guide">
				<div class="wm-quick-guide-title">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Was dieses Plugin tut', 'wm-prototype-nda-gate' ); ?></span>
				</div>
				<p class="wm-quick-guide-desc">
					<?php esc_html_e( 'Für Websites, Prototypen oder Staging-Umgebungen, die angemeldeten Kunden oder Partnern gezeigt werden: Das Plugin verlangt vor Login, wp-admin, Frontend und REST die Bestätigung einer Vertraulichkeitsvereinbarung und speichert jede Bestätigung mit Zeitpunkt, Hash des Vertragstextes und verketteter Signatur. Nicht angemeldete Besucher sind nicht betroffen. Ob Vereinbarung und Maßnahmen rechtlich tragen, beurteilt das Plugin nicht.', 'wm-prototype-nda-gate' ); ?>
				</p>
				<div class="wm-quick-guide-actions">
					<strong><?php esc_html_e( 'Schnellzugriff:', 'wm-prototype-nda-gate' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-nda-versions' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
						<?php esc_html_e( 'NDA-Text bearbeiten (neue Version)', 'wm-prototype-nda-gate' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-nda-export' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Signaturen exportieren', 'wm-prototype-nda-gate' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-nda-docs' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-book" aria-hidden="true"></span>
						<?php esc_html_e( 'Handbuch öffnen', 'wm-prototype-nda-gate' ); ?>
					</a>
				</div>
			</section>

			<!-- KPI Cards Grid -->
			<div id="wm-sec-kpis" class="wm-kpi-grid wm-nda-kpi-grid">
				<div class="wm-kpi-card wm-nda-kpi-card">
					<div class="wm-nda-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-chart-pie"></span></div>
					<div class="wm-nda-kpi-data">
						<span class="wm-kpi-lbl wm-nda-kpi-label"><?php esc_html_e( 'Bestätigungsquote', 'wm-prototype-nda-gate' ); ?></span>
						<span class="wm-kpi-val wm-nda-kpi-value"><?php echo esc_html( (string) $kpis['acceptance_rate'] ); ?>%</span>
					</div>
					<div class="wm-nda-kpi-bar">
						<div class="wm-nda-kpi-bar-fill" style="width: <?php echo esc_attr( (string) $kpis['acceptance_rate'] ); ?>%;"></div>
					</div>
				</div>

				<div class="wm-kpi-card wm-nda-kpi-card">
					<div class="wm-nda-kpi-icon wm-kpi-success" aria-hidden="true"><span class="dashicons dashicons-yes-alt"></span></div>
					<div class="wm-nda-kpi-data">
						<span class="wm-kpi-lbl wm-nda-kpi-label"><?php esc_html_e( 'Signiert (aktive Version) / Benutzer', 'wm-prototype-nda-gate' ); ?></span>
						<span class="wm-kpi-val wm-nda-kpi-value"><?php echo esc_html( (string) $kpis['accepted_active_users'] ); ?> / <?php echo esc_html( (string) $kpis['total_users'] ); ?></span>
					</div>
				</div>

				<div class="wm-kpi-card wm-nda-kpi-card <?php echo esc_attr( $kpis['pending_users'] > 0 ? 'wm-kpi-warning' : '' ); ?>">
					<div class="wm-nda-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-clock"></span></div>
					<div class="wm-nda-kpi-data">
						<span class="wm-kpi-lbl wm-nda-kpi-label"><?php esc_html_e( 'Ausstehend', 'wm-prototype-nda-gate' ); ?></span>
						<span class="wm-kpi-val wm-nda-kpi-value"><?php echo esc_html( (string) $kpis['pending_users'] ); ?></span>
					</div>
				</div>

				<div class="wm-kpi-card wm-nda-kpi-card">
					<div class="wm-nda-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-media-document"></span></div>
					<div class="wm-nda-kpi-data">
						<span class="wm-kpi-lbl wm-nda-kpi-label"><?php esc_html_e( 'NDA-Version', 'wm-prototype-nda-gate' ); ?></span>
						<span class="wm-kpi-val wm-nda-kpi-value">v<?php echo esc_html( $kpis['active_version'] ); ?></span>
					</div>
				</div>
			</div>

			<!-- Interactive Preview of NDA Modal -->
			<div id="wm-sec-preview" class="wm-card wm-nda-card wm-nda-card-accent">
				<h2><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e( 'Vorschau: das Signatur-Modal', 'wm-prototype-nda-gate' ); ?></h2>
				<p class="wm-field-desc">
					<?php esc_html_e( 'Vereinfachte Darstellung des Modals, das angemeldete Benutzer ohne Signatur sehen (Titel und Textanfang der aktiven Version):', 'wm-prototype-nda-gate' ); ?>
				</p>
				<!-- A still of the gate, not a control: hidden from assistive tech, its fake controls out of the tab order. -->
				<div class="wm-nda-preview-stage" aria-hidden="true">
					<div class="wm-nda-preview-card">
						<div class="wm-nda-preview-head">
							<span class="dashicons dashicons-lock"></span>
							<h3><?php echo esc_html( $active_term ? $active_term->term_title : __( 'Vertraulichkeitsvereinbarung (NDA)', 'wm-prototype-nda-gate' ) ); ?></h3>
						</div>
						<div class="wm-nda-preview-body">
							<?php echo $active_term ? wp_kses_post( wp_trim_words( $active_term->term_content, 40 ) ) : esc_html__( 'Keine aktive Version hinterlegt.', 'wm-prototype-nda-gate' ); ?>
						</div>
						<div class="wm-nda-preview-foot">
							<label>
								<input type="checkbox" checked disabled tabindex="-1" /> <?php echo esc_html( WM_NDA_I18n::get_login_checkbox_label() ); ?>
							</label>
							<button type="button" class="button button-primary" tabindex="-1"><?php echo esc_html( WM_NDA_I18n::get_accept_button_text() ); ?> <span class="dashicons dashicons-arrow-right-alt"></span></button>
						</div>
					</div>
				</div>
			</div>

			<!-- Active Version Card -->
			<div class="wm-card wm-nda-card">
				<h2><?php esc_html_e( 'Aktive Vertraulichkeitsvereinbarung', 'wm-prototype-nda-gate' ); ?></h2>
				<?php if ( $active_term ) : ?>
					<div class="wm-nda-active-meta">
						<p><strong><?php esc_html_e( 'Titel:', 'wm-prototype-nda-gate' ); ?></strong> <?php echo esc_html( $active_term->term_title ); ?></p>
						<p><strong><?php esc_html_e( 'Version:', 'wm-prototype-nda-gate' ); ?></strong> v<?php echo esc_html( $active_term->term_version ); ?> &bull; <strong><?php esc_html_e( 'Erstellt am:', 'wm-prototype-nda-gate' ); ?></strong> <?php echo esc_html( $active_term->created_at ); ?> UTC</p>
						<p><strong><?php esc_html_e( 'Integritäts-Hash (SHA-256):', 'wm-prototype-nda-gate' ); ?></strong> <code><?php echo esc_html( $active_term->term_hash ); ?></code></p>
					</div>
					<div class="wm-nda-actions-row">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-nda-versions' ) ); ?>" class="button button-secondary">
							<span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Neue Version veröffentlichen (Version Bump)', 'wm-prototype-nda-gate' ); ?>
						</a>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wm-nda-export&action=wm_nda_export_json&term_id=' . $active_term->term_id ), 'wm_nda_export_json' ) ); ?>" class="button button-primary">
							<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Register als JSON herunterladen', 'wm-prototype-nda-gate' ); ?>
						</a>
					</div>
				<?php else : ?>
					<p class="wm-nda-alert wm-nda-alert-warning"><?php esc_html_e( 'Keine aktive NDA-Version hinterlegt.', 'wm-prototype-nda-gate' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Recent Acceptances Table -->
			<div id="wm-sec-signatures" class="wm-card wm-nda-card">
				<h2><?php esc_html_e( 'Letzte Signaturen', 'wm-prototype-nda-gate' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Benutzer', 'wm-prototype-nda-gate' ); ?></th>
							<th><?php esc_html_e( 'E-Mail', 'wm-prototype-nda-gate' ); ?></th>
							<th><?php esc_html_e( 'Version', 'wm-prototype-nda-gate' ); ?></th>
							<th><?php esc_html_e( 'Signiert am (UTC)', 'wm-prototype-nda-gate' ); ?></th>
							<th><?php esc_html_e( 'IP-HMAC', 'wm-prototype-nda-gate' ); ?></th>
							<th><?php esc_html_e( 'Signatur-Hash', 'wm-prototype-nda-gate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $recent_acceptances ) ) : ?>
							<?php foreach ( $recent_acceptances as $acc ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $acc->user_name ); ?></strong></td>
									<td><?php echo wp_kses( self::breakable_email( $acc->user_email ), [ 'wbr' => [] ] ); ?></td>
									<td><span class="wm-nda-badge">v<?php echo esc_html( $acc->term_version ); ?></span></td>
									<td><?php echo esc_html( $acc->accepted_at ); ?></td>
									<td><code class="wm-nda-hash"><?php echo esc_html( self::short_hash( $acc->ip_hmac ) ); ?></code></td>
									<td><code class="wm-nda-hash"><?php echo esc_html( self::short_hash( $acc->signature_hash ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="6"><?php esc_html_e( 'Bisher keine Signaturen erfasst.', 'wm-prototype-nda-gate' ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Signatures & Audit Log Table
	 */
	public function render_signatures_page(): void {
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$term_id = absint( $_GET['term_id'] ?? 0 );
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset = ( $paged - 1 ) * $per_page;

		$terms = WM_NDA_Database::get_all_terms();
		$total_items = WM_NDA_Database::get_acceptances_count( [ 'term_id' => $term_id, 'search' => $search ] );
		$acceptances = WM_NDA_Database::get_acceptances( [
			'term_id' => $term_id,
			'search'  => $search,
			'limit'   => $per_page,
			'offset'  => $offset,
		] );

		$total_pages = ceil( $total_items / $per_page );

		?>
		<div class="wrap wm-admin wm-nda-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-edit',
				__( 'Signatur-Register', 'wm-prototype-nda-gate' ),
				__( 'Aktivitäten & Signaturprotokoll', 'wm-prototype-nda-gate' ),
				__( 'Alle gespeicherten Bestätigungen mit Signatur und Prüfergebnis des Registers.', 'wm-prototype-nda-gate' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-nda-signatures' ); ?>

			<?php
			// Measured on every page load. Until this release nothing in the plugin ever
			// recomputed a stored signature, so the checksums in the table below had never been
			// checked against the rows they describe.
			$integrity = WM_NDA_Database::verify_register();
			?>
			<div class="notice notice-<?php echo $integrity['valid'] ? 'success' : 'error'; ?> inline wm-nda-integrity-notice">
				<p>
					<strong>
						<?php
						/* translators: %d: number of chained acceptances recomputed and verified */
						$integrity_ok_text = __( 'Register integer — %d Zustimmungen neu berechnet und verkettet geprüft.', 'wm-prototype-nda-gate' );
						echo esc_html( $integrity['valid'] ? sprintf( $integrity_ok_text, (int) $integrity['checked'] ) : __( 'Register NICHT integer', 'wm-prototype-nda-gate' ) );
						?>
					</strong>
				</p>
				<?php if ( ! $integrity['valid'] ) : ?>
					<p><?php echo esc_html( (string) $integrity['error'] ); ?></p>
				<?php endif; ?>
				<?php if ( $integrity['unchained_legacy'] > 0 ) : ?>
					<p>
						<?php
						/* translators: %d: number of acceptances recorded before the chain existed */
						$legacy_text = __( '%d Zustimmung(en) stammen aus der Zeit vor der Verkettung und tragen keine prüfbare Signatur. Sie gelten als ungeprüft, nicht als integer.', 'wm-prototype-nda-gate' );
						echo esc_html( sprintf( $legacy_text, (int) $integrity['unchained_legacy'] ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( 'wp-config' !== $integrity['key_source'] ) : ?>
					<p><?php esc_html_e( 'AUTH_KEY ist nicht definiert: der Signaturschlüssel stammt aus der Datenbank — also von dort, wo ein Angreifer ohnehin schreiben würde. Der Nachweis hält hier nur gegen Unfälle. Setzen Sie AUTH_KEY in der wp-config.php.', 'wm-prototype-nda-gate' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Filter Bar -->
			<form method="get" class="wm-nda-filter-bar">
				<input type="hidden" name="page" value="wm-nda-signatures" />
				
				<select name="term_id">
					<option value="0"><?php esc_html_e( 'Alle Versionen anzeigen', 'wm-prototype-nda-gate' ); ?></option>
					<?php foreach ( $terms as $t ) : ?>
						<option value="<?php echo esc_attr( (string) $t->term_id ); ?>" <?php selected( $term_id, (int) $t->term_id ); ?>>
							v<?php echo esc_html( $t->term_version ); ?> &mdash; <?php echo esc_html( $t->term_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Benutzer oder E-Mail suchen...', 'wm-prototype-nda-gate' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filtern', 'wm-prototype-nda-gate' ); ?></button>
				
				<?php if ( ! empty( $search ) || $term_id > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wm-nda-signatures' ) ); ?>" class="button button-link"><?php esc_html_e( 'Zurücksetzen', 'wm-prototype-nda-gate' ); ?></a>
				<?php endif; ?>
			</form>

			<!-- Signatures Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="wm-nda-col-id"><?php esc_html_e( 'ID', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'Benutzer', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'E-Mail', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'Version', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'Signatur-Zeitpunkt (UTC)', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'IP-HMAC', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'Interaktionsnachweis', 'wm-prototype-nda-gate' ); ?></th>
						<th><?php esc_html_e( 'Signatur-Hash (HMAC-SHA256)', 'wm-prototype-nda-gate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $acceptances ) ) : ?>
						<?php foreach ( $acceptances as $acc ) : ?>
							<tr>
								<td>#<?php echo esc_html( (string) $acc->acceptance_id ); ?></td>
								<td><strong><?php echo esc_html( $acc->user_name ); ?></strong> (ID: <?php echo esc_html( (string) $acc->user_id ); ?>)</td>
								<td><?php echo wp_kses( self::breakable_email( $acc->user_email ), [ 'wbr' => [] ] ); ?></td>
								<td><span class="wm-nda-badge">v<?php echo esc_html( $acc->term_version ); ?></span></td>
								<td><?php echo esc_html( $acc->accepted_at ); ?></td>
								<td><code class="wm-nda-hash"><?php echo esc_html( self::short_hash( $acc->ip_hmac, 20 ) ); ?></code></td>
								<?php $interaction = WM_NDA_Evidence::from_row( $acc ); ?>
								<td class="wm-nda-hyphenate">
									<?php if ( [] === $interaction ) : ?>
										<?php echo esc_html( WM_NDA_Evidence::short_summary( $interaction ) ); ?>
									<?php else : ?>
										<details>
											<summary><?php echo esc_html( WM_NDA_Evidence::short_summary( $interaction ) ); ?></summary>
											<dl class="wm-nda-interaction-detail">
												<?php foreach ( WM_NDA_Evidence::describe( $interaction ) as $detail ) : ?>
													<dt><?php echo esc_html( $detail['label'] ); ?></dt>
													<dd><?php echo esc_html( $detail['value'] ); ?></dd>
												<?php endforeach; ?>
											</dl>
											<p class="description"><?php esc_html_e( 'Vom Browser gemeldet und mit der Signatur dieser Zeile versiegelt: nachträglich geändert bricht die Kette. Ob der Browser richtig gemeldet hat, kann das Plugin nicht prüfen.', 'wm-prototype-nda-gate' ); ?></p>
										</details>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $acc->signature_hash ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'Keine Einträge gefunden.', 'wm-prototype-nda-gate' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							/* translators: %d: total number of acceptance records */
							$entries_text = __( '%d Einträge', 'wm-prototype-nda-gate' );
							echo esc_html( sprintf( $entries_text, (int) $total_items ) );
							?>
						</span>
						<?php
						echo wp_kses_post(
							(string) paginate_links(
								[
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								]
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render NDA Versions & Version Bump Manager
	 */
	public function render_versions_page(): void {
		$terms = WM_NDA_Database::get_all_terms();
		$active_term = WM_NDA_Database::get_active_term();

		?>
		<div class="wrap wm-admin wm-nda-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-media-document',
				__( 'Versionen', 'wm-prototype-nda-gate' ),
				__( 'NDA-Versionen', 'wm-prototype-nda-gate' ),
				__( 'Erstellen Sie neue Versionen des Vertragstextes. Das Veröffentlichen einer neuen Version erzwingt für alle Benutzer eine erneute Zustimmung (Version Bump Re-acceptance).', 'wm-prototype-nda-gate' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-nda-versions' ); ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from the redirect after a nonce-checked action.
			if ( isset( $_GET['bumped'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Die Fassung ist gespeichert und aktiv. Wer sie noch nicht bestätigt hat, wird beim nächsten Zugriff darum gebeten.', 'wm-prototype-nda-gate' ); ?></p>
				</div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from the redirect after a nonce-checked action.
			if ( isset( $_GET['bump_failed'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						/* translators: 1: maximum characters of the version number, 2: maximum characters of the title */
						$bump_failed_text = __( 'Die Fassung wurde nicht gespeichert; die bisherige bleibt aktiv. Versionsnummer höchstens %1$d Zeichen, Titel höchstens %2$d Zeichen, Text nicht leer.', 'wm-prototype-nda-gate' );
						echo esc_html( sprintf( $bump_failed_text, WM_NDA_Database::MAX_VERSION_LENGTH, WM_NDA_Database::MAX_TITLE_LENGTH ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="wm-nda-columns">
				<!-- Version List -->
				<div class="wm-nda-col-main">
					<div class="wm-card wm-nda-card">
						<h2><?php esc_html_e( 'Bestehende NDA-Versionen', 'wm-prototype-nda-gate' ); ?></h2>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th class="wm-nda-col-version"><?php esc_html_e( 'Version', 'wm-prototype-nda-gate' ); ?></th>
									<th><?php esc_html_e( 'Titel', 'wm-prototype-nda-gate' ); ?></th>
									<th><?php esc_html_e( 'Status', 'wm-prototype-nda-gate' ); ?></th>
									<th><?php esc_html_e( 'SHA-256 Hash', 'wm-prototype-nda-gate' ); ?></th>
									<th><?php esc_html_e( 'Erstellt am (UTC)', 'wm-prototype-nda-gate' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $terms as $t ) : ?>
									<tr>
										<td><strong>v<?php echo esc_html( $t->term_version ); ?></strong></td>
										<td class="wm-nda-hyphenate"><?php echo esc_html( $t->term_title ); ?></td>
										<td>
											<?php if ( (int) $t->is_active === 1 ) : ?>
												<span class="wm-nda-status-pill wm-status-active"><?php esc_html_e( 'Aktiv', 'wm-prototype-nda-gate' ); ?></span>
											<?php else : ?>
												<span class="wm-nda-status-pill wm-status-archived"><?php esc_html_e( 'Archiviert', 'wm-prototype-nda-gate' ); ?></span>
											<?php endif; ?>
										</td>
										<td><code class="wm-nda-hash"><?php echo esc_html( self::short_hash( $t->term_hash ) ); ?></code></td>
										<td><?php echo esc_html( $t->created_at ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Create New Version Form -->
				<div class="wm-nda-col-side">
					<div class="wm-card wm-nda-card">
						<h2><?php esc_html_e( 'Neue Version herausgeben', 'wm-prototype-nda-gate' ); ?></h2>
						<form method="post">
							<?php wp_nonce_field( 'wm_nda_version_nonce' ); ?>
							
							<p>
								<label for="term_version"><strong><?php esc_html_e( 'Versionsnummer:', 'wm-prototype-nda-gate' ); ?></strong></label><br />
								<input type="text" id="term_version" name="term_version" class="regular-text" value="<?php echo esc_attr( $active_term ? '1.0.' . ( count( $terms ) ) : '1.0.0' ); ?>" maxlength="<?php echo esc_attr( (string) WM_NDA_Database::MAX_VERSION_LENGTH ); ?>" required />
							</p>

							<p>
								<label for="term_title"><strong><?php esc_html_e( 'Vertragstitel:', 'wm-prototype-nda-gate' ); ?></strong></label><br />
								<input type="text" id="term_title" name="term_title" class="large-text" value="Geheimhaltungs- und Prototyp-Nutzungsvereinbarung (B2B)" maxlength="<?php echo esc_attr( (string) WM_NDA_Database::MAX_TITLE_LENGTH ); ?>" required />
							</p>

							<p>
								<label for="term_content"><strong><?php esc_html_e( 'Vertragstext (HTML erlaubt):', 'wm-prototype-nda-gate' ); ?></strong></label><br />
								<textarea id="term_content" name="term_content" rows="12" class="large-text" required><?php echo esc_textarea( $active_term ? $active_term->term_content : WM_NDA_I18n::get_default_legal_content() ); ?></textarea>
							</p>

							<p class="description">
								<?php esc_html_e( 'Achtung: Durch Veröffentlichung wird die bisherige Version sofort deaktiviert und alle Benutzer werden beim nächsten Zugriff zur erneuten Bestätigung aufgefordert.', 'wm-prototype-nda-gate' ); ?>
							</p>

							<p>
								<button type="submit" name="wm_nda_create_version" class="button button-primary button-large">
									<span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Version aktivieren', 'wm-prototype-nda-gate' ); ?>
								</button>
							</p>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the export page (CSV and JSON).
	 */
	public function render_export_page(): void {
		$active_term = WM_NDA_Database::get_active_term();

		?>
		<div class="wrap wm-admin wm-nda-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-download',
				__( 'Export', 'wm-prototype-nda-gate' ),
				__( 'Export des Signatur-Registers', 'wm-prototype-nda-gate' ),
				__( 'Das Zustimmungsregister als CSV oder als JSON mit Signaturkette und aktuellem Prüfergebnis. Welche Beweiskraft das hat, beurteilt das Plugin nicht.', 'wm-prototype-nda-gate' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-nda-export' ); ?>

			<div class="wm-nda-columns">
				<div class="wm-card wm-nda-card">
					<h2><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'CSV-Signaturprotokoll (CWE-1236 gehärtet)', 'wm-prototype-nda-gate' ); ?></h2>
					<p><?php esc_html_e( 'Strukturierter tabellarischer Export aller Signaturvorgänge mit automatischer Bereinigung von potenziellen Formel-Injektionen (Formulas/Macros).', 'wm-prototype-nda-gate' ); ?></p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wm-nda-export&action=wm_nda_export_csv' ), 'wm_nda_export_csv' ) ); ?>" class="button button-secondary">
						<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'CSV-Datei herunterladen', 'wm-prototype-nda-gate' ); ?>
					</a>
				</div>

				<div class="wm-card wm-nda-card">
					<h2><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'JSON-Export mit Prüfergebnis', 'wm-prototype-nda-gate' ); ?></h2>
					<p><?php esc_html_e( 'Vollständiger JSON-Export mit Signaturkette, Prüfergebnis des Registers und einer SHA-256-Prüfsumme; wie sie sich nachrechnen lässt, steht in der Datei. Die Prüfsumme belegt, dass das Dokument nach dem Export unverändert ist; über die Datenbank sagt sie nichts — dafür steht das mitexportierte Prüfergebnis.', 'wm-prototype-nda-gate' ); ?></p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wm-nda-export&action=wm_nda_export_json' ), 'wm_nda_export_json' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'JSON herunterladen', 'wm-prototype-nda-gate' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Settings Page
	 */
	public function render_settings_page(): void {
		$require_login = (bool) get_option( 'wm_nda_require_login_checkbox', 1 );
		$enforce_admin = (bool) get_option( 'wm_nda_enforce_admin_gate', 1 );
		$enforce_frontend = (bool) get_option( 'wm_nda_enforce_frontend_gate', 1 );
		$enforce_rest = (bool) get_option( 'wm_nda_enforce_rest_api', 1 );
		$watermark    = (bool) get_option( WM_NDA_Watermark::OPTION, 0 );
		$project_name = get_option( 'wm_nda_project_name', get_bloginfo( 'name' ) . ' (Prototyp)' );
		$retention_days = (int) get_option( 'wm_nda_retention_days', 90 );
		$delete_on_uninstall = (bool) get_option( 'wm_nda_delete_data_on_uninstall', 0 );

		?>
		<div class="wrap wm-admin wm-nda-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-admin-generic',
				__( 'Einstellungen', 'wm-prototype-nda-gate' ),
				__( 'Einstellungen — WM Prototype NDA Gate', 'wm-prototype-nda-gate' ),
				__( 'Login-Checkbox, Admin-, Frontend- und REST-Sperre, Projektname, Demodaten.', 'wm-prototype-nda-gate' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-nda-settings' ); ?>
			
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Einstellungen erfolgreich gespeichert.', 'wm-prototype-nda-gate' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['demo_seeded'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						/* translators: %d: number of demo signatures created */
						$seeded_text = __( '%d Demodaten-Signaturen (ohne WordPress-Konto) wurden erzeugt.', 'wm-prototype-nda-gate' );
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter from the redirect after a nonce-checked action.
						echo esc_html( sprintf( $seeded_text, absint( $_GET['demo_seeded'] ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['demo_deleted'] ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p>
						<?php
						/* translators: %d: number of demo signatures deleted */
						$deleted_text = __( '%d Demodaten-Signaturen wurden gelöscht.', 'wm-prototype-nda-gate' );
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counter from the redirect after a nonce-checked action.
						echo esc_html( sprintf( $deleted_text, absint( $_GET['demo_deleted'] ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from the redirect after a nonce-checked action.
			if ( isset( $_GET['demo_refused'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Nicht ausgeführt: das Register enthält echte Signaturen. Demodaten werden nicht mit ihnen verkettet und nicht aus einer Kette mit ihnen gelöscht, sonst verifiziert das Register nicht mehr.', 'wm-prototype-nda-gate' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['factory_reset'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><strong><?php esc_html_e( 'Werkseinstellungen wiederhergestellt:', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Alle Signaturen wurden gelöscht, die Standard-NDA v1.0.0 wurde neu initialisiert und alle Optionen zurückgesetzt.', 'wm-prototype-nda-gate' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" class="wm-card wm-nda-card">
				<?php wp_nonce_field( 'wm_nda_settings_nonce' ); ?>
				
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Projektbezeichnung (Prototyp)', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<input type="text" name="wm_nda_project_name" value="<?php echo esc_attr( $project_name ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Erscheint im Modal. In den Vertragstext gelangt er nur, wenn die Standardfassung neu angelegt wird (Aktivierung ohne Fassung, Werkseinstellungen); die aktive Fassung ändert sich nicht.', 'wm-prototype-nda-gate' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Login-Checkbox erzwingen', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wm_nda_require_login_checkbox" value="1" <?php checked( $require_login ); ?> />
								<?php esc_html_e( 'Checkbox auf wp-login.php einblenden; Benutzer ohne Signatur der aktiven Version werden nach der Passwortprüfung ohne Häkchen abgewiesen.', 'wm-prototype-nda-gate' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Admin-Gate (wp-admin)', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wm_nda_enforce_admin_gate" value="1" <?php checked( $enforce_admin ); ?> />
								<?php esc_html_e( 'Benutzer ohne Signatur der aktuellen NDA-Version werden vor dem Aufbau jeder wp-admin-Seite auf die Signaturseite umgeleitet; admin-ajax antwortet mit HTTP 403.', 'wm-prototype-nda-gate' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Frontend-Gate (Prototyp / Staging)', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wm_nda_enforce_frontend_gate" value="1" <?php checked( $enforce_frontend ); ?> />
								<?php esc_html_e( 'Angemeldete Benutzer ohne Signatur werden von jeder Frontend-Seite auf die Signaturseite umgeleitet (template_redirect). Nicht angemeldete Besucher sind davon nicht betroffen.', 'wm-prototype-nda-gate' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'REST API Schutz', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wm_nda_enforce_rest_api" value="1" <?php checked( $enforce_rest ); ?> />
								<?php esc_html_e( 'REST-API-Endpunkte für Benutzer ohne aktive NDA-Signatur mit HTTP 403 Forbidden sperren.', 'wm-prototype-nda-gate' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Forensisches Bildschirm-Wasserzeichen', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wm_nda_watermark" value="1" <?php checked( $watermark ); ?> />
								<?php esc_html_e( 'Legt über jede Seite ein schwaches Raster, das Konto-ID und Minute des Aufrufs trägt. Aus einem unskalierten Screenshot lässt es sich mit "wp nda wasserzeichen-lesen" zurücklesen.', 'wm-prototype-nda-gate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Schwach heißt nicht unsichtbar: auf großen einfarbigen Flächen ist das Raster erkennbar. Es überlebt Zuschneiden und JPEG, nicht aber Skalieren, Drehen oder Abfotografieren. Es benennt das Konto, dem die Seite ausgeliefert wurde — nicht, wer den Screenshot gemacht hat. Standardmäßig aus.', 'wm-prototype-nda-gate' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Aufbewahrungsfrist', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<input type="number" name="wm_nda_retention_days" value="<?php echo esc_attr( (string) $retention_days ); ?>" min="30" max="3650" class="small-text" /> <?php esc_html_e( 'Tage', 'wm-prototype-nda-gate' ); ?>
							<p class="description"><?php esc_html_e( 'Täglich angewendet: Bei Signaturen, die älter als diese Frist sind, werden Name, E-Mail-Adresse und Browserkennung ersetzt. Gelöscht wird nichts — eine gelöschte Zeile würde das Register unterbrechen. Konto-ID, Zeitstempel, Vertragsversion, IP-Pseudonym und Interaktionsnachweis bleiben, weil sie Teil der verketteten Signatur sind. Unter 30 Tagen bedeutet: alles behalten.', 'wm-prototype-nda-gate' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Daten bei Deinstallation löschen', 'wm-prototype-nda-gate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wm_nda_delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
								<?php esc_html_e( 'Alle Plugin-Optionen, Benutzer-Metadaten sowie beide NDA-Register-Tabellen beim Löschen des Plugins endgültig entfernen.', 'wm-prototype-nda-gate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Standardmäßig deaktiviert, damit ein versehentliches Löschen oder Neuinstallieren des Plugins keine gespeicherten Zustimmungsnachweise vernichtet.', 'wm-prototype-nda-gate' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="wm_nda_save_settings" class="button button-primary">
						<?php esc_html_e( 'Einstellungen speichern', 'wm-prototype-nda-gate' ); ?>
					</button>
				</p>
			</form>

			<!-- SECTION: Demodaten-Management & Factory Reset -->
			<div class="wm-card wm-nda-card wm-nda-card-accent">
				<h2>
					<span class="dashicons dashicons-database-import wm-nda-icon-accent" aria-hidden="true"></span>
					<?php esc_html_e( 'Demodaten-Management & Werkseinstellungen (Reset)', 'wm-prototype-nda-gate' ); ?>
				</h2>
				<p class="wm-field-desc">
					<?php esc_html_e( 'Fünf Beispiel-Signaturen ohne WordPress-Konto für Präsentationen – nur in einem Register ohne echte Signaturen; gelöscht werden können sie nur, solange keine echten Signaturen mit ihnen verkettet sind. Oder das NDA Gate auf Werkseinstellungen zurücksetzen.', 'wm-prototype-nda-gate' ); ?>
				</p>

				<div class="wm-nda-actions-row">
					<!-- Form 1: Import Demo Data -->
					<form method="post">
						<?php wp_nonce_field( 'wm_nda_demo_action', 'wm_nda_demo_nonce' ); ?>
						<button type="submit" name="wm_nda_seed_demo" class="button button-secondary">
							<span class="dashicons dashicons-download wm-nda-btn-icon" aria-hidden="true"></span>
							<?php esc_html_e( 'Demodaten importieren (5 Beispiel-Signaturen)', 'wm-prototype-nda-gate' ); ?>
						</button>
					</form>

					<!-- Form 2: Delete Demo Data Only -->
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Demodaten löschen? Das geht nur, wenn das Register keine echten Signaturen enthält.', 'wm-prototype-nda-gate' ) ); ?>');">
						<?php wp_nonce_field( 'wm_nda_demo_action', 'wm_nda_demo_nonce' ); ?>
						<button type="submit" name="wm_nda_delete_demo" class="button button-secondary wm-nda-btn-danger-text">
							<span class="dashicons dashicons-trash wm-nda-btn-icon" aria-hidden="true"></span>
							<?php esc_html_e( 'Demodaten bereinigen (Nur Test-Signaturen)', 'wm-prototype-nda-gate' ); ?>
						</button>
					</form>
				</div>

				<hr class="wm-nda-divider" />

				<!-- Danger Zone: Factory Reset -->
				<div class="wm-nda-danger-zone">
					<h4>
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php esc_html_e( 'Gefahrenbereich: Auf Werkseinstellungen zurücksetzen', 'wm-prototype-nda-gate' ); ?>
					</h4>
					<p>
						<?php esc_html_e( 'Dieser Vorgang löscht unwiderruflich ALLE Signaturen und NDA-Versionen, stellt die Standard-NDA v1.0.0 wieder her und setzt alle Einstellungen auf die Werksstandards zurück.', 'wm-prototype-nda-gate' ); ?>
					</p>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'WARNUNG: Sind Sie sicher? Dies löscht ALLE Signaturen und setzt das NDA Gate vollständig auf Werkseinstellungen zurück!', 'wm-prototype-nda-gate' ) ); ?>');">
						<?php wp_nonce_field( 'wm_nda_reset_action', 'wm_nda_reset_nonce' ); ?>
						<button type="submit" name="wm_nda_factory_reset" class="wm-btn wm-btn-danger">
							<span class="dashicons dashicons-undo" aria-hidden="true"></span>
							<?php esc_html_e( 'Werkseinstellungen wiederherstellen (Factory Reset)', 'wm-prototype-nda-gate' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Add WordPress Native Contextual Help Tabs
	 */
	public function add_contextual_help(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'wm-nda' ) ) {
			return;
		}

		$screen->add_help_tab( [
			'id'      => 'wm_nda_help_overview',
			'title'   => __( '4-Stufen Perimeter-Schutz', 'wm-prototype-nda-gate' ),
			'content' => '<p><strong>' . esc_html__( 'WM Prototype NDA Gate', 'wm-prototype-nda-gate' ) . '</strong> ' . esc_html__( 'verlangt von angemeldeten Benutzern die Bestätigung der aktiven Vertraulichkeitsvereinbarung an vier Stellen: Login-Checkbox, Admin-Weiterleitung, Frontend-Weiterleitung und REST-Sperre.', 'wm-prototype-nda-gate' ) . '</p>',
		] );

		$screen->add_help_tab( [
			'id'      => 'wm_nda_help_version_bump',
			'title'   => __( 'Version-Bump & Re-Signing', 'wm-prototype-nda-gate' ),
			// Until 1.3.4 this said a new version declares every earlier signature invalid. It does not: they stay in
			// the register and keep verifying; they just do not cover the new version.
			'content' => '<p>' . esc_html__( 'Wird der NDA-Vertragstext aktualisiert und eine neue Versionsnummer vergeben, müssen alle Benutzer den neuen Text vor dem nächsten Zugriff bestätigen. Die bisherigen Bestätigungen bleiben mit ihrer Fassung im Register und lassen sich weiter prüfen.', 'wm-prototype-nda-gate' ) . '</p>',
		] );

		$screen->add_help_tab( [
			'id'      => 'wm_nda_help_register',
			'title'   => __( 'Signatur-Register', 'wm-prototype-nda-gate' ),
			'content' => '<p>' . esc_html__( 'Jede Signatur ist ein HMAC-SHA256 über die Signatur der vorhergehenden Zustimmung, User-ID, Versions-ID, Vertrags-Hash, Zeitstempel und die pseudonymisierte IP; die Spitze der Kette ist mit demselben Schlüssel versiegelt. Eine geänderte, gelöschte oder eingeschleuste Zustimmung fällt auf, solange der Schlüssel (AUTH_KEY aus der wp-config.php, ohne ihn ein Salt aus der Datenbank) nicht bekannt ist; eine vorher kopierte ältere Kettenspitze, die zurückgespielt wird, fällt nicht auf. Ein neuer Schlüssel macht alle gespeicherten Signaturen unprüfbar. IP-Adressen werden als HMAC mit einem täglich wechselnden Salt gespeichert.', 'wm-prototype-nda-gate' ) . '</p>',
		] );

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Handbuch:', 'wm-prototype-nda-gate' ) . '</strong></p>' .
			'<p><a href="' . esc_url( admin_url( 'admin.php?page=wm-nda-docs' ) ) . '" class="button button-secondary">' . esc_html__( 'Vollständiges Handbuch öffnen', 'wm-prototype-nda-gate' ) . '</a></p>' .
			'<p>' . esc_html__( 'Entwickelt von WM Suite.', 'wm-prototype-nda-gate' ) . '</p>'
		);
	}

	/**
	 * Render In-Plugin Documentation & Handbook Page
	 */
	public function render_docs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-prototype-nda-gate' ) );
		}
		?>
		<div class="wrap wm-admin wm-nda-admin-wrap">
			<?php
			self::render_page_header(
				'dashicons-book',
				__( 'Handbuch', 'wm-prototype-nda-gate' ),
				__( 'WM Prototype NDA Gate — Handbuch', 'wm-prototype-nda-gate' ),
				__( 'Die vier Prüfstellen, das Signatur-Register, Hooks und Grenzen.', 'wm-prototype-nda-gate' )
			);
			?>

			<?php self::render_nav_tabs( 'wm-nda-docs' ); ?>

			<div class="wm-grid-2 wm-nda-docs-grid">
				<!-- CARD 1: 4-Layer Architecture -->
				<div class="wm-card wm-nda-card">
					<h2>
						<span class="dashicons dashicons-shield"></span>
						<?php esc_html_e( '1. 4-Stufen Perimeter-Interzeption', 'wm-prototype-nda-gate' ); ?>
					</h2>
					<p><?php esc_html_e( 'Angemeldete Benutzer ohne Signatur der aktiven Version werden an vier Stellen angehalten:', 'wm-prototype-nda-gate' ); ?></p>
					<ul class="wm-nda-list">
						<li><strong><?php esc_html_e( 'Ebene 1 (wp-login.php):', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Checkbox (24 × 24 px) auf wp-login.php; nach der Passwortprüfung wird die Anmeldung ohne Häkchen abgewiesen, wenn keine Signatur der aktiven Version vorliegt.', 'wm-prototype-nda-gate' ); ?></li>
						<li><strong><?php esc_html_e( 'Ebene 2 (Frontend / Staging):', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Leitet angemeldete Benutzer ohne Signatur bei template_redirect auf die Signaturseite um, bevor das Theme etwas ausgibt.', 'wm-prototype-nda-gate' ); ?></li>
						<li><strong><?php esc_html_e( 'Ebene 3 (REST-API):', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Antwortet angemeldeten Benutzern ohne Signatur mit HTTP 403 (rest_forbidden); ausgenommen sind /wm-nda/v1/, /wp/v2/users/me und /wm-hub/v1/ (Filter wm_nda_whitelisted_routes). Anonyme Anfragen sind nicht betroffen.', 'wm-prototype-nda-gate' ); ?></li>
						<li><strong><?php esc_html_e( 'Ebene 4 (wp-admin):', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Leitet bei admin_init auf die Signaturseite um, bevor eine wp-admin-Seite aufgebaut wird; dort das Vollbild-Modal mit WAI-ARIA Focus-Trap. admin-ajax antwortet mit HTTP 403.', 'wm-prototype-nda-gate' ); ?></li>
					</ul>
				</div>

				<!-- CARD 2: The default agreement text (until 1.3.3 a list of the statutes the old text cited) -->
				<div class="wm-card wm-nda-card">
					<h2>
						<span class="dashicons dashicons-businessman"></span>
						<?php esc_html_e( '2. Der mitgelieferte Vertragstext', 'wm-prototype-nda-gate' ); ?>
					</h2>
					<p><?php esc_html_e( 'Der mitgelieferte Text ist ein Muster ohne juristische Prüfung und nennt keine Vorschriften. Er beschreibt den Gegenstand, die Pflichten, was bei einer Zustimmung gespeichert wird, und dass der Zugang bei einem Verstoß gesperrt werden kann.', 'wm-prototype-nda-gate' ); ?></p>
					<p><?php esc_html_e( 'Ob Vereinbarung und Maßnahmen im Einzelfall tragen, beurteilt das Plugin nicht. Lassen Sie den Text prüfen und veröffentlichen Sie Ihre Fassung im Reiter "Versionen"; bereits veröffentlichte Versionen behalten ihren Text und ihre Signaturen.', 'wm-prototype-nda-gate' ); ?></p>
				</div>
			</div>

			<!-- CARD 3: Signature Cryptogram & Developer Hook -->
			<div class="wm-card wm-nda-card">
				<h2>
					<span class="dashicons dashicons-code-standards"></span>
					<?php esc_html_e( '3. Signatur-Kryptogramm & Entwickler-Hooks', 'wm-prototype-nda-gate' ); ?>
				</h2>
				<p><?php esc_html_e( 'Bei jeder Bestätigung wird eine HMAC-SHA256-Signatur berechnet, die die vorherige Signatur einschließt:', 'wm-prototype-nda-gate' ); ?></p>
				<pre>signature_hash = HMAC-SHA256(
    prev_hash | user_id | term_id | term_hash | accepted_at | ip_hmac
    [ | sha256( interaction_json ) ]   nur wenn ein Interaktionsnachweis vorliegt,
    AUTH_KEY   (ohne AUTH_KEY: wp_salt( 'auth' ))
)</pre>
				<p><strong><?php esc_html_e( 'Entwickler-Action Hook:', 'wm-prototype-nda-gate' ); ?></strong></p>
				<pre><code><span class="wm-code-cm">/**
 * Hook nach erfolgreicher NDA-Signierung
 */</span>
add_action( <span class="wm-code-str">'wm_nda_accepted'</span>, <span class="wm-code-kw">function</span>( <span class="wm-code-var">$acceptance_id</span>, <span class="wm-code-var">$user_id</span>, <span class="wm-code-var">$term_id</span>, <span class="wm-code-var">$signature_hash</span> ) {
    <span class="wm-code-cm">// Beispiel: Benachrichtigung an Compliance-Team oder CRM senden</span>
    <span class="wm-code-kw">if</span> ( class_exists( <span class="wm-code-str">'WM_Sentinel_Logger'</span> ) ) {
        <span class="wm-code-str">WM_Sentinel_Logger</span>::<span class="wm-code-fn">log</span>( <span class="wm-code-var">$user_id</span>, <span class="wm-code-str">'nda_consent_signed'</span>, <span class="wm-code-str">'nda_term'</span>, <span class="wm-code-var">$term_id</span>, <span class="wm-code-str">'notice'</span>, [
            <span class="wm-code-str">'acceptance_id'</span>  => <span class="wm-code-var">$acceptance_id</span>,
            <span class="wm-code-str">'signature_hash'</span> => <span class="wm-code-var">$signature_hash</span>,
        ] );
    }
}, <span class="wm-code-var">10</span>, <span class="wm-code-var">4</span> );</code></pre>
			</div>

			<!-- CARD 4: Gotchas & FAQs -->
			<div class="wm-card wm-nda-card">
				<h2>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( '4. Wichtige Hinweise, Gotchas & Best Practices', 'wm-prototype-nda-gate' ); ?>
				</h2>
				<ul class="wm-nda-list">
					<li><strong><?php esc_html_e( 'Version Bump & Re-Signing:', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Sobald Sie im Reiter "Versionen" eine neue Version anlegen, muss jeder Benutzer die neue Fassung bestätigen; die bisherigen Bestätigungen bleiben mit ihrer Fassung im Register.', 'wm-prototype-nda-gate' ); ?></li>
					<li><strong><?php esc_html_e( 'WP-CLI & Cron-Automatisierung:', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Hintergrund-Crons und CLI-Befehle werden automatisch vom Gate ausgenommen, um Serverprozesse nicht zu blockieren.', 'wm-prototype-nda-gate' ); ?></li>
					<li><strong><?php esc_html_e( 'Export:', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Unter "Export" gibt es CSV und JSON; das JSON enthält das Prüfergebnis des Registers und eine Prüfsumme über die Datei. Einen PDF-Export gibt es nicht.', 'wm-prototype-nda-gate' ); ?></li>
					<li><strong><?php esc_html_e( 'Interaktionsnachweis:', 'wm-prototype-nda-gate' ); ?></strong> <?php esc_html_e( 'Der Browser meldet bei der Bestätigung Eingabegerät, Verweildauer, Zeigerweg, gelesenen Anteil und Sichtfenster. Diese Angaben gehen in die Signatur ein, sind danach also nicht mehr änderbar, ohne die Kette zu brechen — aber sie stammen vom Client: sie zeigen, dass der Dialog bedient wurde, nicht von wem. Bestätigungen ohne JavaScript und alle vor dem 16.09.2026 tragen keinen.', 'wm-prototype-nda-gate' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

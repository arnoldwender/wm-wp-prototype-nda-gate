<?php
/**
 * WM Suite Prototype NDA Gate — Main Singleton Core Orchestrator
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_NDA_Core {

	/**
	 * Singleton instance
	 */
	private static ?self $instance = null;

	/**
	 * Subsystems
	 */
	public WM_NDA_Interceptor $interceptor;
	public WM_NDA_REST_API $rest_api;
	public WM_NDA_Admin $admin;

	/**
	 * Guard so GenerateBlocks dynamic tag registration runs at most once per request.
	 */
	private static bool $gb_tag_registered = false;

	/**
	 * Get singleton instance
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
		$this->interceptor = new WM_NDA_Interceptor();
		$this->rest_api    = new WM_NDA_REST_API();
		$this->admin       = new WM_NDA_Admin();

		$this->init();
	}

	/**
	 * Initialize subsystems
	 */
	private function init(): void {
		// Initialize interception hooks
		$this->interceptor->init_hooks();

		// Register REST API endpoints
		add_action( 'rest_api_init', [ $this->rest_api, 'register_routes' ] );

		// Forensic screen watermark: off unless wm_nda_watermark is on, and only for accounts that
		// have signed — an account that has not signed sees the gate, not the content.
		add_action( 'wp_enqueue_scripts', [ 'WM_NDA_Watermark', 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ 'WM_NDA_Watermark', 'enqueue_assets' ] );
		add_action( 'wp_footer', [ 'WM_NDA_Watermark', 'render_overlay' ] );
		add_action( 'admin_footer', [ 'WM_NDA_Watermark', 'render_overlay' ] );

		// Register the WordPress privacy tools (personal data exporter and eraser).
		add_filter( 'wp_privacy_personal_data_exporters', [ 'WM_NDA_Exporter', 'register_privacy_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ 'WM_NDA_Exporter', 'register_privacy_erasers' ] );

		// Register WP 6.5+ Block Bindings (`wm-nda/status`) & GenerateBlocks 2.x Dynamic Tag (`{{wm_nda_status}}`)
		add_action( 'init', [ self::class, 'register_block_bindings' ] );
		add_action( 'init', [ self::class, 'register_generateblocks_tags' ], 20 );
		add_action( 'generateblocks_after_init', [ self::class, 'register_generateblocks_tags' ] );

		// Register Admin Dashboard if in admin context
		if ( is_admin() ) {
			$this->admin->init_hooks();
		}
	}

	/**
	 * Register WordPress 6.5+ Block Bindings source `wm-nda/status`.
	 */
	public static function register_block_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'wm-nda/status',
			[
				'label'              => __( 'WM NDA Gate — Status', 'wm-prototype-nda-gate' ),
				'get_value_callback' => [ self::class, 'get_block_binding_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
	}

	/**
	 * Register GenerateBlocks 2.x Dynamic Tag `{{wm_nda_status}}`.
	 *
	 * @param bool $force Reset duplicate guard (used by isolated test runs).
	 */
	public static function register_generateblocks_tags( bool $force = false ): void {
		if ( $force ) {
			self::$gb_tag_registered = false;
		}
		if ( self::$gb_tag_registered || ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			return;
		}

		new GenerateBlocks_Register_Dynamic_Tag(
			[
				'title'       => __( 'WM NDA Gate — Status', 'wm-prototype-nda-gate' ),
				'tag'         => 'wm_nda_status',
				'type'        => 'user',
				'supports'    => [],
				'description' => __( 'Zeigt den aktuellen NDA-Bestätigungsstatus oder die aktive Vertragsversion an.', 'wm-prototype-nda-gate' ),
				'return'      => [ self::class, 'render_gb_dynamic_tag' ],
			]
		);
		self::$gb_tag_registered = true;
	}

	/**
	 * Callback for WP 6.5+ Block Bindings (`wm-nda/status`).
	 *
	 * @param array<string, mixed> $source_args    Binding arguments (`key`: status|version|title|project|accepted).
	 * @param mixed                $block_instance Block instance.
	 * @param string               $attribute_name Bound attribute name.
	 */
	public static function get_block_binding_value( array $source_args, mixed $block_instance = null, string $attribute_name = '' ): string {
		$raw_key = $source_args['key'] ?? ( $source_args['field'] ?? 'status' );
		$field   = is_string( $raw_key ) ? sanitize_key( $raw_key ) : 'status';
		return self::resolve_status_value( $field );
	}

	/**
	 * Callback for GenerateBlocks 2.x Dynamic Tag (`{{wm_nda_status}}` or `{{wm_nda_status key:version}}`).
	 *
	 * @param array<string, mixed> $options  Tag options.
	 * @param mixed                $block    Block data.
	 * @param mixed                $instance Block instance.
	 */
	public static function render_gb_dynamic_tag( array $options = [], mixed $block = null, mixed $instance = null ): string {
		$raw_key = $options['key'] ?? ( $options['field'] ?? 'status' );
		$field   = is_string( $raw_key ) ? sanitize_key( $raw_key ) : 'status';
		return esc_html( self::resolve_status_value( $field ) );
	}

	/**
	 * Resolve a dynamic NDA status/metadata field for Block Bindings and GenerateBlocks.
	 */
	public static function resolve_status_value( string $field = 'status' ): string {
		$term    = WM_NDA_Database::get_active_term();
		$user_id = is_user_logged_in() ? get_current_user_id() : 0;

		switch ( $field ) {
			case 'version':
			case 'active_version':
				$value = $term ? (string) $term->term_version : '';
				break;

			case 'title':
				$value = $term ? (string) $term->term_title : '';
				break;

			case 'project':
				$value = (string) get_option( 'wm_nda_project_name', get_bloginfo( 'name' ) . ' (Prototyp)' );
				break;

			case 'accepted':
				$value = ( $user_id > 0 && $term && WM_NDA_Database::has_user_accepted( $user_id, (int) $term->term_id ) ) ? '1' : '0';
				break;

			case 'state':
				if ( $user_id <= 0 ) {
					$value = 'logged_out';
				} elseif ( ! $term ) {
					$value = 'unconfigured';
				} elseif ( WM_NDA_Database::has_user_accepted( $user_id, (int) $term->term_id ) ) {
					$value = 'signed';
				} else {
					$value = 'pending';
				}
				break;

			case 'label':
			case 'status':
			default:
				if ( $user_id <= 0 ) {
					$value = __( 'Nicht angemeldet', 'wm-prototype-nda-gate' );
				} elseif ( ! $term ) {
					$value = __( 'Keine aktive Vereinbarung', 'wm-prototype-nda-gate' );
				} elseif ( WM_NDA_Database::has_user_accepted( $user_id, (int) $term->term_id ) ) {
					$value = sprintf(
						/* translators: %s: active NDA version string */
						__( 'NDA v%s bestätigt', 'wm-prototype-nda-gate' ),
						(string) $term->term_version
					);
				} else {
					$value = sprintf(
						/* translators: %s: active NDA version string */
						__( 'NDA v%s ausstehend', 'wm-prototype-nda-gate' ),
						(string) $term->term_version
					);
				}
				break;
		}

		/**
		 * Filters the resolved NDA dynamic status value for Block Bindings and GenerateBlocks.
		 *
		 * @param string $value   Resolved text value.
		 * @param string $field   Requested field key (`status`, `version`, `title`, `project`, `accepted`).
		 * @param int    $user_id Current user ID (0 when logged out).
		 */
		return (string) apply_filters( 'wm_nda_status_value', $value, $field, $user_id );
	}
}

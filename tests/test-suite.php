<?php
/**
 * Standalone Test Suite for WM Prototype NDA Gate
 *
 * Runs without external database or live WordPress server: an in-memory $wpdb and WordPress function stubs
 * around the plugin's real classes. Groups 1-9 cover seeding, IP hashing, the acceptance chain, CSV escaping,
 * the login check, the hub adapter, the admin and frontend gate, the privacy tools, demo data and wording.
 * Groups 10 and 11 pin the corrections of the reader audit of 2026-09-15 (tests/mutations.php undoes each one).
 *
 * The stubs follow core where a difference would hide a defect: add_query_arg() does not encode new values,
 * wpdb refuses strings longer than their column, the terms UPDATE and the demo DELETE act on the tables,
 * GET_LOCK answers, apply_filters() runs registered callbacks and wp_cache_flush_group() can be switched off.
 * Every group runs inside try/catch with warnings raised as exceptions, so a crash is a failed assertion
 * and the results line is always printed.
 *
 *   php tests/test-suite.php
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

namespace ArnoldWender\SuiteHub\Contracts {
	if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
		interface WM_Plugin_Module_Interface {
			public function get_module_slug(): string;
			public function get_name(): string;
			public function get_version(): string;
			public function get_description(): string;
			public function get_health_status(): string;
			public function get_sbom_data(): array;
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../' );
	}
	if ( ! defined( 'WPINC' ) ) {
		define( 'WPINC', 'wp-includes' );
	}
	if ( ! defined( 'WM_NDA_TESTING_MODE' ) ) {
		define( 'WM_NDA_TESTING_MODE', true );
	}
	if ( ! defined( 'WM_NDA_GATE_VERSION' ) ) {
		define( 'WM_NDA_GATE_VERSION', '1.3.4' );
	}
	if ( ! defined( 'WM_NDA_GATE_FILE' ) ) {
		define( 'WM_NDA_GATE_FILE', dirname( __DIR__ ) . '/wm-prototype-nda-gate.php' );
	}
	if ( ! defined( 'WM_NDA_GATE_PATH' ) ) {
		define( 'WM_NDA_GATE_PATH', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'WM_NDA_GATE_URL' ) ) {
		define( 'WM_NDA_GATE_URL', 'https://example.com/wp-content/plugins/wm-prototype-nda-gate/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	// A usable key, as on a configured site: chain_key_source() is "wp-config".
	if ( ! defined( 'AUTH_KEY' ) ) {
		define( 'AUTH_KEY', 'standalone-suite-auth-key-not-a-secret' );
	}

	// The audit fallback writes to error_log(); keep the suite output readable.
	ini_set( 'error_log', '/dev/null' );

	// -------------------------------------------------------------
	// WP_Error, WP_User, WP_REST_Request
	// -------------------------------------------------------------
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public mixed $data;

			public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! class_exists( 'WP_User' ) ) {
		class WP_User {
			public int $ID;
			public string $user_login;
			public string $user_email;
			public string $display_name;

			public function __construct( int $id = 1, string $login = 'admin', string $email = 'admin@example.com' ) {
				$this->ID           = $id;
				$this->user_login   = $login;
				$this->user_email   = $email;
				$this->display_name = ucfirst( $login );
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {
			public function __construct( public mixed $data = null, public int $status = 200 ) {}

			public function get_data(): mixed {
				return $this->data;
			}

			public function get_status(): int {
				return $this->status;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Server' ) ) {
		class WP_REST_Server {
			public const READABLE  = 'GET';
			public const CREATABLE = 'POST';
		}
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			private array $params = [];

			public function __construct( private string $method = 'GET', private string $route = '' ) {}

			public function get_route(): string {
				return $this->route;
			}

			public function set_param( string $key, mixed $value ): void {
				$this->params[ $key ] = $value;
			}

			public function get_param( string $key ): mixed {
				return $this->params[ $key ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_Site' ) ) {
		class WP_Site {
			public int|string $blog_id;

			public function __construct( int|string $blog_id = 1 ) {
				$this->blog_id = $blog_id;
			}
		}
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	// -------------------------------------------------------------
	// OPTIONS, TRANSIENTS, CACHE, USERS, MULTISITE
	// -------------------------------------------------------------
	$GLOBALS['mock_options']                = [];
	$GLOBALS['mock_transients']             = [];
	$GLOBALS['mock_cache']                  = [];
	$GLOBALS['mock_usermeta']               = [];
	$GLOBALS['mock_filters']                = [];
	$GLOBALS['mock_flush_group_works']      = true;
	$GLOBALS['mock_is_multisite']           = false;
	$GLOBALS['mock_network_active_plugins'] = [];
	$GLOBALS['mock_sites']                  = [ 1 ];
	$GLOBALS['mock_current_blog_id']        = 1;
	$GLOBALS['mock_blog_stack']             = [];
	$GLOBALS['mock_site_options']           = [];
	$GLOBALS['mock_site_transients']        = [];
	$GLOBALS['mock_option_autoloads']       = [];
	$GLOBALS['mock_dbdelta_queries']        = [];
	$GLOBALS['mock_dbdelta_throw']          = null;
	$GLOBALS['mock_cron']                   = [];
	// Accounts: ID => [ login, e-mail ]. mock_user_ids are the accounts that exist for count_users() and the KPI join.
	$GLOBALS['mock_users']    = [
		1 => [ 'admin', 'admin@example.com' ],
		2 => [ 'testguest', 'guest@example.com' ],
		3 => [ 'unsigned', 'unsigned@example.com' ],
	];
	$GLOBALS['mock_user_ids'] = [ 1, 2, 3 ];

	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['mock_options'][ $option ] ?? $default;
	}

	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['mock_options'][ $option ] = $value;
		$blog_id                            = (int) ( $GLOBALS['mock_current_blog_id'] ?? 1 );
		$GLOBALS['mock_option_autoloads'][ $blog_id ][ $option ] = $autoload;
		return true;
	}

	function delete_option( string $option ): bool {
		unset( $GLOBALS['mock_options'][ $option ] );
		$blog_id = (int) ( $GLOBALS['mock_current_blog_id'] ?? 1 );
		unset( $GLOBALS['mock_option_autoloads'][ $blog_id ][ $option ] );
		return true;
	}

	function is_multisite(): bool {
		return (bool) ( $GLOBALS['mock_is_multisite'] ?? false );
	}

	function is_plugin_active_for_network( string $plugin ): bool {
		return in_array( $plugin, $GLOBALS['mock_network_active_plugins'] ?? [], true );
	}

	function get_sites( array $args = [] ): array {
		return $GLOBALS['mock_sites'] ?? [ 1 ];
	}

	function switch_to_blog( int $new_blog_id, bool $deprecated = false ): bool {
		$current                                     = (int) ( $GLOBALS['mock_current_blog_id'] ?? 1 );
		$GLOBALS['mock_site_options'][ $current ]    = $GLOBALS['mock_options'];
		$GLOBALS['mock_site_transients'][ $current ] = $GLOBALS['mock_transients'];
		$GLOBALS['mock_blog_stack'][]                = $current;
		$GLOBALS['mock_current_blog_id']             = $new_blog_id;
		$GLOBALS['mock_options']                     = $GLOBALS['mock_site_options'][ $new_blog_id ] ?? [];
		$GLOBALS['mock_transients']                  = $GLOBALS['mock_site_transients'][ $new_blog_id ] ?? [];
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->prefix = 1 === $new_blog_id ? 'wp_' : "wp_{$new_blog_id}_";
		}
		return true;
	}

	function restore_current_blog(): bool {
		if ( [] === $GLOBALS['mock_blog_stack'] ) {
			return false;
		}
		$current                                     = (int) ( $GLOBALS['mock_current_blog_id'] ?? 1 );
		$GLOBALS['mock_site_options'][ $current ]    = $GLOBALS['mock_options'];
		$GLOBALS['mock_site_transients'][ $current ] = $GLOBALS['mock_transients'];
		$prev                                        = (int) array_pop( $GLOBALS['mock_blog_stack'] );
		$GLOBALS['mock_current_blog_id']             = $prev;
		$GLOBALS['mock_options']                     = $GLOBALS['mock_site_options'][ $prev ] ?? [];
		$GLOBALS['mock_transients']                  = $GLOBALS['mock_site_transients'][ $prev ] ?? [];
		if ( isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb']->prefix = 1 === $prev ? 'wp_' : "wp_{$prev}_";
		}
		return true;
	}

	function dbDelta( string|array $queries = '', bool $execute = true ): array {
		if ( is_callable( $GLOBALS['mock_dbdelta_throw'] ?? null ) ) {
			( $GLOBALS['mock_dbdelta_throw'] )( $queries );
		}
		$list = is_array( $queries ) ? $queries : [ $queries ];
		foreach ( $list as $sql ) {
			$GLOBALS['mock_dbdelta_queries'][] = $sql;
			if ( preg_match( '/CREATE\s+TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
				$table = $m[1];
				if ( ! isset( $GLOBALS['wpdb']->tables[ $table ] ) ) {
					$GLOBALS['wpdb']->tables[ $table ]   = [];
					$GLOBALS['wpdb']->auto_inc[ $table ] = 0;
				}
				$GLOBALS['wpdb']->dropped = array_values( array_diff( $GLOBALS['wpdb']->dropped, [ $table ] ) );
			}
		}
		return [];
	}

	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return $GLOBALS['mock_cron'][ $hook ] ?? false;
	}

	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ): bool {
		$GLOBALS['mock_cron'][ $hook ] = $timestamp;
		return true;
	}

	function wp_unschedule_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ): bool {
		unset( $GLOBALS['mock_cron'][ $hook ] );
		return true;
	}

	function delete_site_option( string $option ): bool {
		unset( $GLOBALS['mock_options'][ $option ] );
		return true;
	}

	function get_transient( string $transient ): mixed {
		return $GLOBALS['mock_transients'][ $transient ] ?? false;
	}

	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['mock_transients'][ $transient ] = $value;
		return true;
	}

	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['mock_transients'][ $transient ] );
		return true;
	}

	function wp_cache_get( string $key, string $group = '' ): mixed {
		return $GLOBALS['mock_cache'][ $group . ':' . $key ] ?? false;
	}

	function wp_cache_set( string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		$GLOBALS['mock_cache'][ $group . ':' . $key ] = $data;
		return true;
	}

	function wp_cache_delete( string $key, string $group = '' ): bool {
		unset( $GLOBALS['mock_cache'][ $group . ':' . $key ] );
		return true;
	}

	// Object caches without group support make this a no-op (core cache-compat.php); mock_flush_group_works switches that on.
	function wp_cache_flush_group( string $group = '' ): bool {
		if ( ! $GLOBALS['mock_flush_group_works'] ) {
			return false;
		}
		foreach ( array_keys( $GLOBALS['mock_cache'] ) as $k ) {
			if ( str_starts_with( $k, $group . ':' ) ) {
				unset( $GLOBALS['mock_cache'][ $k ] );
			}
		}
		return true;
	}

	function update_user_meta( int $user_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool {
		$GLOBALS['mock_usermeta'][ $user_id . ':' . $meta_key ] = $meta_value;
		return true;
	}

	function delete_user_meta( int $user_id, string $meta_key, mixed $meta_value = '' ): bool {
		unset( $GLOBALS['mock_usermeta'][ $user_id . ':' . $meta_key ] );
		return true;
	}

	function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
		return $GLOBALS['mock_usermeta'][ $user_id . ':' . $key ] ?? ( $single ? '' : [] );
	}

	function delete_metadata( string $type, int $id, string $key, mixed $value = '', bool $all = false ): bool {
		return true;
	}

	function get_user_by( string $field, mixed $value ): WP_User|false {
		foreach ( $GLOBALS['mock_users'] as $id => [ $login, $email ] ) {
			if ( ( 'id' === $field && (int) $value === $id ) || ( 'email' === $field && $value === $email ) || ( 'login' === $field && $value === $login ) ) {
				return new WP_User( $id, $login, $email );
			}
		}
		return false;
	}

	function count_users(): array {
		return [ 'total_users' => count( $GLOBALS['mock_user_ids'] ) ];
	}

	// -------------------------------------------------------------
	// FORMATTING, ESCAPING, URLS
	// -------------------------------------------------------------
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}

	function sanitize_email( string $email ): string {
		return filter_var( trim( $email ), FILTER_SANITIZE_EMAIL ) ?: '';
	}

	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}

	function is_email( string $email ): bool {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	// Removes script elements like the real one; a hash taken before sanitising then differs from the stored text.
	function wp_kses_post( string $data ): string {
		return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $data );
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}

	function esc_url_raw( string $url, ?array $protocols = null ): string {
		return $url;
	}

	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	function number_format_i18n( float $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals, ',', '.' );
	}

	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}

	function wp_parse_args( array $args, array $defaults = [] ): array {
		return array_merge( $defaults, $args );
	}

	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}

	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	function wp_hash( string $data, string $scheme = 'auth' ): string {
		return hash_hmac( 'sha256', $data, 'mock-secret-salt-key-2026' );
	}

	function wp_salt( string $scheme = 'auth' ): string {
		return 'mock-salt-wm-nda-gate-secret-key-2026';
	}

	function get_bloginfo( string $show = '' ): string {
		return 'WM Suite Test Site';
	}

	function site_url( string $path = '' ): string {
		return 'https://example.com' . $path;
	}

	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}

	function home_url( string $path = '', ?string $scheme = null ): string {
		return 'https://example.com' . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
	}

	function set_url_scheme( string $url, ?string $scheme = null ): string {
		return (string) preg_replace( '#^https?://#', 'https://', $url );
	}

	// Like core: the query already in the URL is re-encoded, the new values are joined as given
	// (functions.php add_query_arg(), build_query() without encoding). The previous stub encoded the new
	// values and hid the redirect loop of the gate URL (reader audit 2026-09-15, D3).
	function add_query_arg( mixed ...$args ): string {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = (string) ( $args[1] ?? '' );
		} else {
			$params = [ (string) $args[0] => $args[1] ];
			$url    = (string) ( $args[2] ?? '' );
		}
		$parts = explode( '?', $url, 2 );
		parse_str( $parts[1] ?? '', $query );
		$query = array_map( static fn( $v ) => is_string( $v ) ? urlencode( $v ) : $v, $query );
		foreach ( $params as $k => $v ) {
			$query[ $k ] = $v;
		}
		$pairs = [];
		foreach ( $query as $k => $v ) {
			if ( false !== $v ) {
				$pairs[] = $k . '=' . $v;
			}
		}
		return $parts[0] . ( [] !== $pairs ? '?' . implode( '&', $pairs ) : '' );
	}

	// Same rule as core: relative or same-host locations pass, anything else falls back.
	function wp_validate_redirect( string $location, string $fallback = '' ): string {
		$host = parse_url( $location, PHP_URL_HOST );
		return ( null === $host || 'example.com' === $host ) ? $location : $fallback;
	}

	// -------------------------------------------------------------
	// HOOKS, CAPABILITIES, REQUEST STATE
	// -------------------------------------------------------------
	$GLOBALS['mock_hooks']              = [];
	$GLOBALS['mock_did_action']         = [];
	$GLOBALS['mock_activation_hooks']   = [];
	$GLOBALS['mock_deactivation_hooks'] = [];

	function absint( mixed $maybeint ): int {
		return (int) abs( (int) $maybeint );
	}

	function plugin_dir_path( string $file ): string {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}

	function plugin_dir_url( string $file ): string {
		return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}

	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}

	function register_activation_hook( string $file, callable $callback ): void {
		$GLOBALS['mock_activation_hooks'][ $file ] = $callback;
	}

	function register_deactivation_hook( string $file, callable $callback ): void {
		$GLOBALS['mock_deactivation_hooks'][ $file ] = $callback;
	}

	function load_plugin_textdomain( string $domain, string|false $deprecated = false, string|false $plugin_rel_path = false ): bool {
		return true;
	}

	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['mock_hooks'][] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $accepted_args ];
	}

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['mock_hooks'][] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $accepted_args ];
	}

	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['mock_did_action'][ $hook ] ?? 0 );
	}

	// Runs the callbacks a test put into mock_filters; the previous stub ran none and hid the frontend gate loop (D11).
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['mock_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}

	function do_action( string $hook, mixed ...$args ): void {}

	function wp_enqueue_style( string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all' ): void {}
	function wp_enqueue_script( string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = [] ): void {}

	function wp_get_current_user(): object {
		return new WP_User( 1, 'admin', 'admin@example.com' );
	}

	$GLOBALS['mock_user_caps']           = [];
	$GLOBALS['mock_rest_routes']         = [];
	$GLOBALS['mock_registered_settings'] = [];
	$GLOBALS['mock_block_bindings']      = [];
	$GLOBALS['mock_gb_tags']             = [];

	function current_user_can( string $cap ): bool {
		return $GLOBALS['mock_user_caps'][ $cap ] ?? true;
	}

	function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): bool {
		$GLOBALS['mock_rest_routes'][ $namespace . $route ] = $args;
		return true;
	}

	function register_setting( string $option_group, string $option_name, array|callable $args = [] ): void {
		$GLOBALS['mock_registered_settings'][ $option_name ] = [
			'group' => $option_group,
			'args'  => is_array( $args ) ? $args : [],
		];
	}

	if ( ! function_exists( 'register_block_bindings_source' ) ) {
		function register_block_bindings_source( string $source_name, array $source_properties ): bool {
			$GLOBALS['mock_block_bindings'][ $source_name ] = $source_properties;
			return true;
		}
	}

	if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
		class GenerateBlocks_Register_Dynamic_Tag {
			public function __construct( array $config ) {
				if ( ! empty( $config['tag'] ) ) {
					$GLOBALS['mock_gb_tags'][ (string) $config['tag'] ] = $config;
				}
			}
		}
	}

	function check_admin_referer( string $action = '', string $query_arg = '_wpnonce' ): bool {
		return true;
	}

	function wp_die( string $msg = '', int|string $title = '' ): void {}

	function wp_create_nonce( string|int $action = -1 ): string {
		return substr( hash( 'sha256', 'mock_nonce_' . (string) $action ), 0, 10 );
	}

	function wp_verify_nonce( string $nonce, string|int $action = -1 ): bool {
		return true;
	}

	$GLOBALS['mock_current_user_id'] = 1;
	$GLOBALS['mock_logged_in']       = true;
	$GLOBALS['mock_doing_ajax']      = false;
	$GLOBALS['mock_is_admin']        = true;
	$GLOBALS['mock_redirects']       = [];
	$GLOBALS['mock_json_errors']     = [];
	$GLOBALS['mock_submenu_pages']   = [];

	function get_current_user_id(): int {
		return (int) ( $GLOBALS['mock_current_user_id'] ?? 1 );
	}

	function is_user_logged_in(): bool {
		return (bool) ( $GLOBALS['mock_logged_in'] ?? true );
	}

	function wp_doing_ajax(): bool {
		return (bool) ( $GLOBALS['mock_doing_ajax'] ?? false );
	}

	function wp_doing_cron(): bool {
		return false;
	}

	function is_admin(): bool {
		return (bool) ( $GLOBALS['mock_is_admin'] ?? true );
	}

	// Stands in for the `exit` that follows every redirect / JSON answer in production.
	class WM_NDA_Test_Exit extends RuntimeException {}

	function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		$GLOBALS['mock_redirects'][] = [
			'location' => wp_validate_redirect( $location, admin_url() ),
			'status'   => $status,
		];
		throw new WM_NDA_Test_Exit( 'redirect' );
	}

	function wp_send_json_error( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void {
		$GLOBALS['mock_json_errors'][] = [ 'data' => $data, 'status' => $status_code ];
		throw new WM_NDA_Test_Exit( 'json' );
	}

	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', mixed $position = null ): string|false {
		$GLOBALS['mock_submenu_pages'][] = compact( 'parent_slug', 'capability', 'menu_slug' );
		return 'admin_page_' . $menu_slug;
	}

	// -------------------------------------------------------------
	// IN-MEMORY WPDB
	// -------------------------------------------------------------
	class Mock_WPDB {
		public string $prefix      = 'wp_';
		public string $users       = 'wp_users';
		public array $tables       = [];
		public array $auto_inc     = [];
		public int $insert_id      = 0;
		public string $last_error  = '';
		/** Every statement in order, for the lock test. */
		public array $log          = [];
		/** Tables SHOW TABLES does not report. */
		public array $dropped      = [];
		/** Answer of SELECT GET_LOCK(). */
		public string $lock_answer = '1';

		/** Column limits of create_tables(); core wpdb refuses a longer string instead of cutting it (process_fields()). */
		private const LIMITS = [
			'term_version'         => 50,
			'term_title'           => 255,
			'term_hash'            => 64,
			'user_email'           => 100,
			'user_name'            => 100,
			'term_hash_at_signing' => 64,
			'ip_hmac'              => 64,
			'prev_hash'            => 64,
			'signature_hash'       => 64,
		];

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, mixed ...$args ): string {
			if ( isset( $args[0] ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$idx = 0;
			return (string) preg_replace_callback(
				'/%[dsfi]/',
				function ( array $m ) use ( &$idx, $args ) {
					$val = $args[ $idx++ ] ?? '';
					return match ( $m[0] ) {
						'%i'    => '`' . str_replace( '`', '``', (string) $val ) . '`',
						'%d'    => (string) (int) $val,
						'%f'    => (string) (float) $val,
						default => "'" . addslashes( (string) $val ) . "'",
					};
				},
				$query
			);
		}

		private function acc(): string {
			return $this->prefix . 'wm_nda_acceptances';
		}

		private function terms(): string {
			return $this->prefix . 'wm_nda_terms';
		}

		private static function is_pattern_demo( object $r ): bool {
			return str_contains( (string) ( $r->user_email ?? '' ), '@demo-' ) || str_contains( (string) ( $r->user_name ?? '' ), '[Demo]' );
		}

		public function query( string $query ): int|bool {
			$this->log[] = $query;

			if ( preg_match( '/DROP\s+TABLE\s+IF\s+EXISTS\s+`?([^`\s]+)`?/i', $query, $m ) ) {
				if ( ! in_array( $m[1], $this->dropped, true ) ) {
					$this->dropped[] = $m[1];
				}
				return true;
			}
			if ( preg_match( '/TRUNCATE\s+TABLE\s+`?([^`\s]+)`?/i', $query, $m ) ) {
				$this->tables[ $m[1] ]   = [];
				$this->auto_inc[ $m[1] ] = 0;
				return true;
			}
			if ( preg_match( '/DELETE\s+FROM\s+`?([^`\s]+)`?\s+WHERE\s+user_id\s*=\s*(\d+)/i', $query, $m ) ) {
				$this->tables[ $m[1] ] = array_values( array_filter( $this->tables[ $m[1] ] ?? [], static fn( $r ) => (int) $r->user_id !== (int) $m[2] ) );
				return true;
			}
			// SQL LIKE on the demo address and name, as the seeder queried until 2026-09-15.
			if ( preg_match( '/DELETE\s+FROM\s+`?([^`\s]+)`?\s+WHERE\s+user_email\s+LIKE/i', $query, $m ) ) {
				$this->tables[ $m[1] ] = array_values( array_filter( $this->tables[ $m[1] ] ?? [], static fn( $r ) => ! self::is_pattern_demo( $r ) ) );
				return true;
			}
			if ( preg_match( '/UPDATE\s+`?([^`\s]+)`?\s+SET\s+is_active\s*=\s*CASE\s+WHEN\s+term_id\s*=\s*(\d+)\s+THEN\s+1\s+ELSE\s+0\s+END/i', $query, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? [] as $row ) {
					$row->is_active = (int) $row->term_id === (int) $m[2] ? 1 : 0;
				}
				return true;
			}
			// Every term off, as bump_version() did first until 2026-09-15.
			if ( preg_match( '/UPDATE\s+`?([^`\s]+)`?\s+SET\s+is_active\s*=\s*0\s*$/i', $query, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? [] as $row ) {
					$row->is_active = 0;
				}
				return true;
			}
			return true;
		}

		public function insert( string $table, array $data, ?array $format = null ): int|bool {
			$this->log[] = "INSERT INTO {$table}";

			foreach ( $data as $column => $value ) {
				if ( isset( self::LIMITS[ $column ] ) && is_string( $value ) && mb_strlen( $value ) > self::LIMITS[ $column ] ) {
					$this->last_error = "Processing the value for the following field failed: {$column}";
					return false;
				}
			}

			if ( ! isset( $this->tables[ $table ] ) ) {
				$this->tables[ $table ]   = [];
				$this->auto_inc[ $table ] = 0;
			}

			$this->auto_inc[ $table ]++;
			$this->insert_id = $this->auto_inc[ $table ];

			if ( str_contains( $table, 'terms' ) && ! isset( $data['term_id'] ) ) {
				$data['term_id'] = $this->insert_id;
			}
			if ( str_contains( $table, 'acceptances' ) && ! isset( $data['acceptance_id'] ) ) {
				$data['acceptance_id'] = $this->insert_id;
			}

			$this->tables[ $table ][] = (object) $data;
			return 1;
		}

		private function table_in( string $query ): ?string {
			foreach ( array_keys( $this->tables ) as $t ) {
				if ( str_contains( $query, $t ) ) {
					return $t;
				}
			}
			return null;
		}

		public function get_row( string $query, string $output = 'OBJECT' ): ?object {
			$this->log[] = $query;
			$table_name  = $this->table_in( $query );
			if ( ! $table_name || empty( $this->tables[ $table_name ] ) ) {
				return null;
			}

			$rows = $this->tables[ $table_name ];

			if ( str_contains( $query, 'is_active = 1' ) ) {
				foreach ( array_reverse( $rows ) as $row ) {
					if ( ! empty( $row->is_active ) ) {
						return $row;
					}
				}
				return null;
			}

			if ( preg_match( '/term_id = (\d+)/', $query, $m ) ) {
				foreach ( $rows as $row ) {
					if ( (int) $row->term_id === (int) $m[1] ) {
						return $row;
					}
				}
				return null;
			}

			return reset( $rows ) ?: null;
		}

		public function get_var( string $query ): mixed {
			$this->log[] = $query;
			$acc         = $this->tables[ $this->acc() ] ?? [];

			if ( str_contains( $query, 'GET_LOCK(' ) ) {
				return $this->lock_answer;
			}
			if ( str_contains( $query, 'RELEASE_LOCK(' ) ) {
				return '1';
			}
			if ( preg_match( "/SHOW TABLES LIKE '(.*)'/", $query, $m ) ) {
				// esc_like() plus prepare() leave backslashes in the pattern; table names have none.
				$name = str_replace( '\\', '', $m[1] );
				return in_array( $name, $this->dropped, true ) ? null : $name;
			}
			if ( str_contains( $query, 'INNER JOIN' ) && preg_match( '/a\.term_id = (\d+)/', $query, $m ) ) {
				$ids = [];
				foreach ( $acc as $r ) {
					if ( (int) $r->term_id === (int) $m[1] && in_array( (int) $r->user_id, $GLOBALS['mock_user_ids'], true ) ) {
						$ids[ (int) $r->user_id ] = true;
					}
				}
				return count( $ids );
			}
			if ( str_contains( $query, 'COUNT(DISTINCT user_id)' ) && preg_match( '/term_id = (\d+)/', $query, $m ) ) {
				$ids = [];
				foreach ( $acc as $r ) {
					if ( (int) $r->term_id === (int) $m[1] && (int) $r->user_id > 0 ) {
						$ids[ (int) $r->user_id ] = true;
					}
				}
				return count( $ids );
			}
			if ( preg_match( "/SELECT term_id FROM .* WHERE term_version = '((?:[^'\\\\]|\\\\.)*)' AND term_hash = '([0-9a-f]+)'/", $query, $m ) ) {
				foreach ( $this->tables[ $this->terms() ] ?? [] as $t ) {
					if ( stripslashes( $m[1] ) === (string) $t->term_version && $m[2] === (string) $t->term_hash ) {
						return $t->term_id;
					}
				}
				return null;
			}
			if ( str_contains( $query, 'SELECT signature_hash' ) ) {
				$chained = array_values( array_filter( $acc, static fn( $r ) => '' !== (string) $r->prev_hash ) );
				return [] === $chained ? null : end( $chained )->signature_hash;
			}

			$table_name = $this->table_in( $query );
			if ( ! $table_name || empty( $this->tables[ $table_name ] ) ) {
				return null;
			}
			$rows = $this->tables[ $table_name ];

			if ( str_contains( $query, 'SELECT COUNT(*)' ) ) {
				if ( ! str_contains( $query, 'WHERE' ) ) {
					return count( $rows );
				}
				if ( str_contains( $query, "LIKE '%@demo-%'" ) ) {
					return count( array_filter( $rows, static fn( $r ) => self::is_pattern_demo( $r ) ) );
				}
				if ( preg_match( "/user_email = '([^']+)'/", $query, $me ) ) {
					$email = stripslashes( $me[1] );
					return count( array_filter( $rows, static fn( $r ) => ( $r->user_email ?? '' ) === $email ) );
				}

				$user_id = preg_match( '/user_id = (\d+)/', $query, $mu ) ? (int) $mu[1] : null;
				$term_id = preg_match( '/term_id = (\d+)/', $query, $mt ) ? (int) $mt[1] : null;
				return count(
					array_filter(
						$rows,
						static fn( $row ) => ( null === $user_id || (int) ( $row->user_id ?? 0 ) === $user_id ) && ( null === $term_id || (int) ( $row->term_id ?? 0 ) === $term_id )
					)
				);
			}

			return null;
		}

		public function get_results( string $query, string $output = 'OBJECT' ): array {
			$this->log[] = $query;
			$acc         = $this->tables[ $this->acc() ] ?? [];
			$terms       = $this->tables[ $this->terms() ] ?? [];
			$with_term   = static function ( object $a ) use ( $terms ): object {
				$row = clone $a;
				foreach ( $terms as $t ) {
					if ( (int) $t->term_id === (int) $a->term_id ) {
						$row->term_title   = $t->term_title;
						$row->term_version = $t->term_version;
					}
				}
				return $row;
			};

			// Privacy tools: by the e-mail stored at signing, or by account ID.
			if ( preg_match( "/user_email = '((?:[^'\\\\]|\\\\.)*)'(?:\s+OR\s+(?:a\.)?user_id = (-?\d+))?/", $query, $m ) ) {
				$email = stripslashes( $m[1] );
				$uid   = isset( $m[2] ) ? (int) $m[2] : null;
				$out   = [];
				foreach ( $acc as $a ) {
					if ( $a->user_email === $email || ( null !== $uid && (int) $a->user_id === $uid ) ) {
						$out[] = $with_term( $a );
					}
				}
				return $out;
			}
			// find_valid_acceptance_id(): one account's chained rows of a term.
			if ( preg_match( "/WHERE user_id = (\d+) AND term_id = (\d+) AND prev_hash <> ''/", $query, $m ) ) {
				return array_values( array_filter( $acc, static fn( $r ) => (int) $r->user_id === (int) $m[1] && (int) $r->term_id === (int) $m[2] && '' !== (string) $r->prev_hash ) );
			}
			// get_acceptances(): join with the terms, optional term filter, order, LIMIT / OFFSET.
			if ( str_contains( $query, 'JOIN' ) && preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $m ) ) {
				$rows = $acc;
				if ( preg_match( '/a\.term_id = (\d+)/', $query, $mt ) ) {
					$rows = array_filter( $rows, static fn( $r ) => (int) $r->term_id === (int) $mt[1] );
				}
				$rows = array_values( $rows );
				if ( str_contains( $query, 'DESC' ) ) {
					$rows = array_reverse( $rows );
				}
				return array_map( $with_term, array_slice( $rows, (int) $m[2], (int) $m[1] ) );
			}

			// The retention pass: accepted before the cutoff and still carrying an identifying
			// field. Without this branch the query falls through to the catch-all below, which
			// hands back EVERY row — the pass would then look like it pseudonymised today's
			// signatures too, and the assertions would agree with it.
			if ( preg_match( "/WHERE accepted_at < '([^']*)'\s+AND \(\s*user_name <> '([^']*)' OR user_agent <> '([^']*)'\s*\)/s", $query, $m ) ) {
				$cutoff = stripslashes( $m[1] );
				$name   = stripslashes( $m[2] );
				$agent  = stripslashes( $m[3] );
				$out    = [];
				foreach ( $acc as $a ) {
					if ( (string) $a->accepted_at >= $cutoff ) {
						continue;
					}
					if ( (string) $a->user_name === $name && (string) ( $a->user_agent ?? '' ) === $agent ) {
						continue;
					}
					$out[] = (object) [ 'acceptance_id' => (int) $a->acceptance_id ];
				}
				usort( $out, static fn( $x, $y ) => $x->acceptance_id <=> $y->acceptance_id );
				return preg_match( '/LIMIT (\d+)/', $query, $ml ) ? array_slice( $out, 0, (int) $ml[1] ) : $out;
			}

			$table_name = $this->table_in( $query );
			return $table_name ? $this->tables[ $table_name ] : [];
		}

		public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
			$n = 0;
			foreach ( $this->tables[ $table ] ?? [] as $row ) {
				$match = true;
				foreach ( $where as $k => $v ) {
					if ( (string) ( $row->$k ?? '' ) !== (string) $v ) {
						$match = false;
					}
				}
				if ( $match ) {
					foreach ( $data as $k => $v ) {
						$row->$k = $v;
					}
					++$n;
				}
			}
			return $n;
		}
	}

	$GLOBALS['wpdb'] = new Mock_WPDB();

	// -------------------------------------------------------------
	// LOAD PLUGIN SOURCE CODE UNDER TEST
	// -------------------------------------------------------------
	require_once __DIR__ . '/../includes/class-nda-i18n.php';
	require_once __DIR__ . '/../includes/class-nda-audit.php';
	require_once __DIR__ . '/../includes/class-nda-evidence.php';
	require_once __DIR__ . '/../includes/class-nda-watermark.php';
	require_once __DIR__ . '/../includes/class-nda-database.php';
	require_once __DIR__ . '/../includes/class-nda-exporter.php';
	require_once __DIR__ . '/../includes/class-nda-retention.php';
	require_once __DIR__ . '/../includes/class-nda-interceptor.php';
	require_once __DIR__ . '/../includes/class-nda-rest-api.php';
	require_once __DIR__ . '/../includes/class-nda-seeder.php';
	require_once __DIR__ . '/../includes/class-nda-spoke-adapter.php';
	require_once __DIR__ . '/../includes/class-nda-modal.php';
	require_once __DIR__ . '/../includes/class-nda-admin.php';
	require_once __DIR__ . '/../includes/class-nda-core.php';
	require_once __DIR__ . '/../wm-prototype-nda-gate.php';
	$GLOBALS['mock_boot_hooks'] = $GLOBALS['mock_hooks'];

	// ---------------------------------------------------------
	// TEST RUNNER AND HELPERS
	// ---------------------------------------------------------
	$passed = 0;
	$failed = 0;

	function test_assert( bool $condition, string $description ): void {
		global $passed, $failed;
		if ( $condition ) {
			echo "  [PASS] {$description}\n";
			$passed++;
		} else {
			echo "  [FAIL] {$description}\n";
			$failed++;
		}
	}

	// A group that throws counts as one failure and the run goes on to the results line.
	function wm_nda_crashed( string $group, Throwable $t ): void {
		test_assert( false, "{$group} ran to its end (" . get_class( $t ) . ': ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine() . ')' );
	}

	set_error_handler(
		static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
			if ( ! ( error_reporting() & $errno ) ) {
				return false;
			}
			throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
		}
	);

	// Runs one gate call: 'redirect' | 'json' when production would have exited, null when it fell through.
	function wm_nda_run_gate( callable $fn ): ?string {
		$GLOBALS['mock_redirects']   = [];
		$GLOBALS['mock_json_errors'] = [];
		try {
			$fn();
			return null;
		} catch ( WM_NDA_Test_Exit $e ) {
			return $e->getMessage();
		}
	}

	function wm_nda_last_redirect(): string {
		$r = end( $GLOBALS['mock_redirects'] );
		return $r ? (string) $r['location'] : '';
	}

	// Source of a plugin file without comments, so an explanation of an old defect does not count as the defect.
	function wm_nda_code( string $relative ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative ) ) as $tok ) {
			if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$code .= is_array( $tok ) ? $tok[1] : $tok;
		}
		return $code;
	}

	// Empty register with the default term and $rows chained acceptances of accounts 101, 102, ...
	function wm_nda_fresh_register( int $rows = 3 ): object {
		WM_NDA_Database::reset_tables();
		$term = WM_NDA_Database::get_active_term();
		for ( $i = 1; $i <= $rows; $i++ ) {
			WM_NDA_Database::record_acceptance( 100 + $i, (int) $term->term_id, "signer{$i}@example.com", "Signer {$i}", 'UA', "203.0.113.{$i}" );
		}
		return $term;
	}

	// WCAG contrast ratio of two #rrggbb colours.
	function wm_nda_contrast( string $fg, string $bg ): float {
		$luminance = static function ( string $hex ): float {
			$channels = array_map( static fn( string $h ): float => hexdec( $h ) / 255, str_split( ltrim( $hex, '#' ), 2 ) );
			$channels = array_map( static fn( float $v ): float => $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4, $channels );
			return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
		};
		$a = $luminance( $fg );
		$b = $luminance( $bg );
		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	// Custom properties with a #rrggbb value defined in a sheet.
	function wm_nda_css_tokens( string $css ): array {
		preg_match_all( '/(--wm-nda-[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m );
		return array_combine( $m[1], array_map( 'strtolower', $m[2] ) );
	}

	// msgids of a .po / .pot file, continuation lines joined.
	function wm_nda_po_msgids( string $file ): array {
		$ids     = [];
		$current = null;
		$open    = false;
		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( str_starts_with( $line, 'msgid "' ) ) {
				if ( null !== $current ) {
					$ids[] = $current;
				}
				$current = stripcslashes( substr( $line, 7, -1 ) );
				$open    = true;
				continue;
			}
			if ( $open && str_starts_with( $line, '"' ) ) {
				$current .= stripcslashes( substr( $line, 1, -1 ) );
				continue;
			}
			$open = false;
		}
		if ( null !== $current ) {
			$ids[] = $current;
		}
		return array_values( array_filter( $ids, static fn( string $id ): bool => '' !== $id ) );
	}

	// The checks MO::import_from_reader() makes before it accepts a catalog (wp-includes/pomo/mo.php).
	function wm_nda_mo_readable( string $file ): bool {
		$data = (string) file_get_contents( $file );
		if ( strlen( $data ) < 28 ) {
			return false;
		}
		if ( 0x950412de === unpack( 'V', substr( $data, 0, 4 ) )[1] ) {
			$e = 'V';
		} elseif ( 0x950412de === unpack( 'N', substr( $data, 0, 4 ) )[1] ) {
			$e = 'N';
		} else {
			return false;
		}
		$h = unpack( "{$e}revision/{$e}total/{$e}orig/{$e}trans/{$e}hashlen/{$e}hashaddr", substr( $data, 4, 24 ) );
		if ( 0 !== $h['revision'] || $h['trans'] - $h['orig'] !== $h['total'] * 8 || $h['hashaddr'] - $h['trans'] !== $h['total'] * 8 ) {
			return false;
		}
		for ( $i = 0; $i < $h['total']; $i++ ) {
			foreach ( [ $h['orig'], $h['trans'] ] as $table ) {
				$entry = unpack( "{$e}len/{$e}pos", substr( $data, $table + $i * 8, 8 ) );
				if ( $entry['pos'] + $entry['len'] > strlen( $data ) ) {
					return false;
				}
			}
		}
		return true;
	}

	echo "===============================================================\n";
	echo "WM PROTOTYPE NDA GATE — STANDALONE TEST SUITE\n";
	echo "Environment: Standalone PHP " . PHP_VERSION . " | In-Memory Mock WPDB\n";
	echo "===============================================================\n\n";

	// ---------------------------------------------------------
	// 1. DATABASE & INITIAL SEEDING
	// ---------------------------------------------------------
	echo "[TEST GROUP 1] Database & Active Term Seeding\n";
	try {
		WM_NDA_Database::seed_default_term();
		$term = WM_NDA_Database::get_active_term();

		test_assert( null !== $term, 'Default NDA term exists and is loaded as active' );
		test_assert( '1.0.0' === $term->term_version, 'Active term version is 1.0.0' );
		test_assert( 64 === strlen( $term->term_hash ), 'Active term hash is a valid 64-character SHA-256' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 1', $t );
	}

	// ---------------------------------------------------------
	// 2. DAILY-SALTED IP HASHING
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 2] Daily-Salted IP Hashing & Privacy\n";
	try {
		$ip1 = '192.168.1.100';
		$ip2 = '10.0.0.1';

		$hash1        = WM_NDA_Database::generate_ip_hmac( $ip1 );
		$hash2        = WM_NDA_Database::generate_ip_hmac( $ip2 );
		$hash1_repeat = WM_NDA_Database::generate_ip_hmac( $ip1 );

		test_assert( 64 === strlen( $hash1 ), 'IP HMAC produces a 64-char SHA-256 hash' );
		test_assert( $hash1 !== $ip1, 'IP is never stored in raw plaintext' );
		test_assert( $hash1 !== $hash2, 'Different IPs produce distinct HMAC hashes' );
		test_assert( $hash1 === $hash1_repeat, 'Deterministic HMAC for same IP within same day' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 2', $t );
	}

	// ---------------------------------------------------------
	// 3. ACCEPTANCE CHAIN
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 3] Cryptographic Acceptance Chain Integrity\n";
	try {
		$acc1 = WM_NDA_Database::record_acceptance(
			1,
			(int) $term->term_id,
			'admin@example.com',
			'Administrator',
			'Mozilla/5.0 Test Browser',
			'127.0.0.1'
		);

		test_assert( false !== $acc1 && $acc1 > 0, 'First acceptance recorded successfully with ID ' . var_export( $acc1, true ) );
		test_assert( true === WM_NDA_Database::has_user_accepted( 1, (int) $term->term_id ), 'User 1 verified as accepted' );
		test_assert( false === WM_NDA_Database::has_user_accepted( 999, (int) $term->term_id ), 'User 999 correctly identified as not accepted' );

		$verification = WM_NDA_Database::verify_register();
		test_assert( true === $verification['valid'], 'Register cryptographic HMAC chain passes full integrity verification (' . (string) $verification['error'] . ')' );
		test_assert( $verification['checked'] >= 1, 'Verified at least 1 chained acceptance record' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 3', $t );
	}

	// ---------------------------------------------------------
	// 4. CWE-1236 FORMULA INJECTION MITIGATION
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 4] CWE-1236 Formula Injection Sanitization\n";
	try {
		test_assert( "'=SUM(A1:A10)" === WM_NDA_Exporter::sanitize_csv_cell( '=SUM(A1:A10)' ), 'Escapes leading "=" with single quote' );
		test_assert( "'+12345" === WM_NDA_Exporter::sanitize_csv_cell( '+12345' ), 'Escapes leading "+" with single quote' );
		test_assert( "'-CMD.EXE" === WM_NDA_Exporter::sanitize_csv_cell( '-CMD.EXE' ), 'Escapes leading "-" with single quote' );
		test_assert( "'@IMPORTXML(\"evil.com\")" === WM_NDA_Exporter::sanitize_csv_cell( '@IMPORTXML("evil.com")' ), 'Escapes leading "@" with single quote' );
		test_assert( 'WM Suite GmbH' === WM_NDA_Exporter::sanitize_csv_cell( 'WM Suite GmbH' ), 'Preserves safe alphanumeric text unaltered' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 4', $t );
	}

	// ---------------------------------------------------------
	// 5. LOGIN CHECK
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 5] Login check and hook order\n";
	try {
		$interceptor = new WM_NDA_Interceptor();

		$_POST['wm_nda_accept'] = '0';
		$mock_user   = new WP_User( 2, 'testguest', 'guest@example.com' );
		$auth_result = $interceptor->validate_login_nda( $mock_user, 'testguest' );

		test_assert( is_wp_error( $auth_result ), 'Rejects wp-login authentication without NDA checkbox' );
		test_assert( 'wm_nda_required' === ( is_wp_error( $auth_result ) ? $auth_result->get_error_code() : '' ), 'Returns "wm_nda_required" error code' );

		$_POST['wm_nda_accept'] = '1';
		$auth_granted = $interceptor->validate_login_nda( $mock_user, 'testguest' );
		test_assert( ! is_wp_error( $auth_granted ) && $auth_granted instanceof WP_User && 2 === $auth_granted->ID, 'Grants authentication when NDA checkbox is submitted' );

		// 2026-09-14: the check ran on wp_authenticate_user, which WordPress applies before comparing the
		// password. Measured on a local WordPress install: a wrong password with the box ticked recorded a signature.
		$GLOBALS['mock_hooks'] = [];
		$interceptor->init_hooks();
		$login_hooks = array_values( array_filter( $GLOBALS['mock_hooks'], static fn( $h ) => is_array( $h['callback'] ) && 'validate_login_nda' === $h['callback'][1] ) );
		test_assert(
			1 === count( $login_hooks ) && 'authenticate' === $login_hooks[0]['hook'] && $login_hooks[0]['priority'] > 20 && 3 === $login_hooks[0]['args'],
			'The login check runs on authenticate after core verified the password, not on wp_authenticate_user (' . wp_json_encode( array_map( static fn( $h ) => $h['hook'] . '@' . $h['priority'], $login_hooks ) ) . ')'
		);

		$count_before           = count( $wpdb->tables['wp_wm_nda_acceptances'] ?? [] );
		$_POST['wm_nda_accept'] = '1';
		$wrong_password         = new WP_Error( 'incorrect_password', 'Das Passwort ist nicht korrekt.' );
		$failed_login           = $interceptor->validate_login_nda( $wrong_password, 'testguest3', 'wrong' );
		test_assert(
			$failed_login === $wrong_password && count( $wpdb->tables['wp_wm_nda_acceptances'] ?? [] ) === $count_before,
			'A failed password check passes through unchanged and records no signature, even with the box ticked'
		);

		$GLOBALS['mock_did_action']['application_password_did_authenticate'] = 1;
		$_POST['wm_nda_accept'] = '0';
		$app_user               = new WP_User( 4, 'apiclient', 'api@example.com' );
		test_assert( $app_user === $interceptor->validate_login_nda( $app_user, 'apiclient', 'xxxx xxxx' ), 'Application-password logins are not asked for a checkbox they cannot tick' );
		$GLOBALS['mock_did_action'] = [];

		$_POST['wm_nda_accept'] = '0';
		$signed_again           = $interceptor->validate_login_nda( new WP_User( 2, 'testguest', 'guest@example.com' ), 'testguest', 'pw' );
		test_assert( $signed_again instanceof WP_User, 'A user who already signed the active term logs in without ticking the box again' );

		ob_start();
		$interceptor->render_login_checkbox();
		$checkbox_html = (string) ob_get_clean();
		test_assert(
			str_contains( $checkbox_html, 'id="wm_nda_accept"' ) && ! preg_match( '/<input[^>]*id="wm_nda_accept"[^>]*\brequired\b/', $checkbox_html ) && str_contains( $checkbox_html, 'aria-describedby="wm-nda-login-hint"' ),
			'The login checkbox is not `required` in the browser (signed users could not submit the form) and carries its hint'
		);

		$_POST['wm_nda_accept'] = '0';
		$missing_box            = $interceptor->validate_login_nda( new WP_User( 5, 'newcomer', 'new@example.com' ), 'newcomer', 'pw' );
		$missing_msg            = is_wp_error( $missing_box ) ? $missing_box->get_error_message() : '';
		test_assert(
			str_contains( $missing_msg, 'Kästchen' ) && ! str_contains( $missing_msg, 'Sicherheitsverstoß' ) && ! str_contains( WM_NDA_I18n::get_login_required_text( false ), 'forensisch' ),
			'A forgotten box on login gets an instruction, not a "Sicherheitsverstoß"; no forensic claim without a forensic sink'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 5', $t );
	}

	// ---------------------------------------------------------
	// 6. SUITE HUB SPOKE ADAPTER & SBOM CONTRACT
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 6] Suite Hub Spoke Adapter & SBOM Contract\n";
	try {
		$adapter = new WM_NDA_Spoke_Adapter();

		test_assert( 'wm-prototype-nda-gate' === $adapter->get_module_slug(), 'Module slug is "wm-prototype-nda-gate"' );
		test_assert( 'WM Prototype NDA Gate' === $adapter->get_name(), 'Module name is "WM Prototype NDA Gate"' );
		test_assert( WM_NDA_GATE_VERSION === $adapter->get_version(), 'Module version is the plugin version (WM_NDA_GATE_VERSION)' );
		test_assert( 'HEALTHY' === $adapter->get_health_status(), 'Module health status is HEALTHY' );

		$sbom = $adapter->get_sbom_data();
		test_assert( 'application' === $sbom['type'], 'SBOM type is "application"' );
		test_assert( 'pkg:wordpress/wm-prototype-nda-gate@' . WM_NDA_GATE_VERSION === $sbom['bom-ref'], 'SBOM bom-ref matches Package URL specification' );
		test_assert( ! empty( $sbom['hashes'] ), 'SBOM contains SHA-256 cryptographic hashes' );
		// The header and readme.txt went to GPLv2 or later on 2026-09-16 (9a8fc9c) and the adapter did not follow:
		// it kept the `Proprietary` it was written with in 1cdc7d8, an id and a name at once, which CycloneDX 1.6 does not accept.
		test_assert(
			[ [ 'license' => [ 'id' => 'GPL-2.0-or-later' ] ] ] === ( $sbom['licenses'] ?? null ),
			'SBOM declares the licence the plugin ships under, as an SPDX id alone (Proprietary, with an id and a name, until 2026-11-05): ' . (string) json_encode( $sbom['licenses'] ?? null )
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 6', $t );
	}

	// ---------------------------------------------------------
	// 7. ADMIN & FRONTEND GATE — NOTHING IS SERVED UNDER THE MODAL
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 7] Admin & Frontend Gate (redirect before output, 403 on admin-ajax)\n";
	try {
		$gate                      = new WM_NDA_Interceptor();
		$_GET                      = [];
		$_SERVER['HTTP_HOST']      = 'example.com';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// User 3 never signed anything; user 1 signed in group 3.
		$GLOBALS['mock_current_user_id'] = 3;
		$GLOBALS['pagenow']              = 'edit.php';
		$_SERVER['REQUEST_URI']          = '/wp-admin/edit.php?post_type=page';
		$outcome                         = wm_nda_run_gate( fn() => $gate->enforce_admin_gate() );
		$loc                             = wm_nda_last_redirect();
		test_assert( 'redirect' === $outcome, 'Unsigned user requesting wp-admin/edit.php is redirected before the page renders (' . var_export( $outcome, true ) . ')' );
		test_assert( str_contains( $loc, 'admin.php?page=wm-nda-gate' ), 'Redirect target is the gate page: ' . $loc );
		test_assert( str_contains( $loc, 'redirect_to=' . rawurlencode( 'https://example.com/wp-admin/edit.php?post_type=page' ) ), 'The requested admin URL travels along as redirect_to' );

		$GLOBALS['pagenow']     = 'admin.php';
		$_GET['page']           = 'wm-nda-gate';
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wm-nda-gate';
		test_assert( null === wm_nda_run_gate( fn() => $gate->enforce_admin_gate() ), 'The gate page itself is not redirected (no loop)' );

		$GLOBALS['pagenow']     = 'index.php';
		$_SERVER['REQUEST_URI'] = '/wp-admin/index.php?page=wm-nda-gate';
		test_assert( 'redirect' === wm_nda_run_gate( fn() => $gate->enforce_admin_gate() ), 'index.php?page=wm-nda-gate is still redirected: the slug does not unlock the dashboard' );

		$_GET                            = [];
		$GLOBALS['mock_current_user_id'] = 1;
		$GLOBALS['pagenow']              = 'edit.php';
		$_SERVER['REQUEST_URI']          = '/wp-admin/edit.php';
		test_assert( null === wm_nda_run_gate( fn() => $gate->enforce_admin_gate() ), 'Signed user is not redirected' );

		$GLOBALS['pagenow']  = 'admin.php';
		$_GET['page']        = 'wm-nda-gate';
		$_GET['redirect_to'] = 'https://example.com/wp-admin/edit.php?post_type=page';
		$outcome             = wm_nda_run_gate( fn() => $gate->enforce_admin_gate() );
		test_assert( 'redirect' === $outcome && 'https://example.com/wp-admin/edit.php?post_type=page' === wm_nda_last_redirect(), 'Signed user landing on the gate page continues to redirect_to' );
		$_GET['redirect_to'] = 'https://evil.example.org/phish';
		$outcome             = wm_nda_run_gate( fn() => $gate->enforce_admin_gate() );
		test_assert( 'redirect' === $outcome && 'https://example.com/wp-admin/' === wm_nda_last_redirect(), 'A foreign-host redirect_to falls back to the admin URL (open-redirect guard)' );

		$_GET                            = [];
		$GLOBALS['mock_current_user_id'] = 3;
		$GLOBALS['mock_doing_ajax']      = true;
		$GLOBALS['pagenow']              = 'admin-ajax.php';
		$outcome                         = wm_nda_run_gate( fn() => $gate->enforce_admin_gate() );
		$err                             = end( $GLOBALS['mock_json_errors'] );
		test_assert( 'json' === $outcome && 403 === (int) ( $err['status'] ?? 0 ) && 'wm_nda_required' === (string) ( $err['data']['code'] ?? '' ), 'admin-ajax.php from an unsigned user answers 403 wm_nda_required instead of running the action' );
		$GLOBALS['mock_doing_ajax'] = false;

		$GLOBALS['mock_options']['wm_nda_enforce_admin_gate'] = 0;
		$GLOBALS['pagenow']                                   = 'edit.php';
		test_assert( null === wm_nda_run_gate( fn() => $gate->enforce_admin_gate() ), 'wm_nda_enforce_admin_gate=0 switches the admin redirect off' );
		unset( $GLOBALS['mock_options']['wm_nda_enforce_admin_gate'] );

		$GLOBALS['mock_is_admin'] = false;
		$GLOBALS['pagenow']       = 'index.php';
		$_SERVER['REQUEST_URI']   = '/prototyp/dashboard/?tab=2';
		$outcome                  = wm_nda_run_gate( fn() => $gate->enforce_frontend_gate() );
		$loc                      = wm_nda_last_redirect();
		test_assert( 'redirect' === $outcome && str_contains( $loc, 'page=wm-nda-gate' ) && str_contains( $loc, rawurlencode( 'https://example.com/prototyp/dashboard/?tab=2' ) ), 'Unsigned user on the frontend is sent to the gate with the page they wanted as redirect_to' );
		$GLOBALS['mock_options']['wm_nda_enforce_frontend_gate'] = 0;
		test_assert( null === wm_nda_run_gate( fn() => $gate->enforce_frontend_gate() ), 'wm_nda_enforce_frontend_gate=0 leaves the frontend alone' );
		unset( $GLOBALS['mock_options']['wm_nda_enforce_frontend_gate'] );
		$GLOBALS['mock_logged_in'] = false;
		test_assert( null === wm_nda_run_gate( fn() => $gate->enforce_frontend_gate() ), 'Anonymous visitors are not redirected (nobody to sign)' );
		$GLOBALS['mock_logged_in']       = true;
		$GLOBALS['mock_current_user_id'] = 1;
		test_assert( null === wm_nda_run_gate( fn() => $gate->enforce_frontend_gate() ), 'Signed user browses the frontend freely' );
		$GLOBALS['mock_is_admin'] = true;

		$GLOBALS['mock_submenu_pages'] = [];
		$outcome                       = wm_nda_run_gate( fn() => $gate->register_gate_page() );
		$reg                           = end( $GLOBALS['mock_submenu_pages'] );
		test_assert( null === $outcome && is_array( $reg ) && 'wm-nda-gate' === $reg['menu_slug'] && 'read' === $reg['capability'] && '' === $reg['parent_slug'], 'Gate page is registered as admin.php?page=wm-nda-gate for capability "read" with no menu parent' );

		$GLOBALS['pagenow'] = 'admin.php';
		$_GET['page']       = 'wm-nda-gate';
		test_assert( false === $gate->keep_gate_page_reachable( true ), 'WooCommerce "prevent admin access" is lifted on the gate page' );
		$_GET               = [];
		$GLOBALS['pagenow'] = 'edit.php';
		test_assert( true === $gate->keep_gate_page_reachable( true ), '...and left untouched on every other admin screen' );
		$GLOBALS['mock_current_user_id'] = 1;
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 7', $t );
	}

	// ---------------------------------------------------------
	// 8. WORDPRESS PRIVACY TOOLS
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 8] Privacy exporter / eraser (WordPress personal data tools)\n";
	try {
		$privacy_export = WM_NDA_Exporter::export_personal_data( 'admin@example.com' );
		$first          = $privacy_export['data'][0] ?? null;
		test_assert( is_array( $first ) && 'wm_nda_signatures' === ( $first['group_id'] ?? '' ), 'export_personal_data returns the signature of admin@example.com as a WordPress export item' );
		$names = array_column( $first['data'] ?? [], 'name' );
		test_assert( in_array( 'Unterzeichner E-Mail', $names, true ) && in_array( 'Signatur-Hash (SHA-256)', $names, true ), 'the export item carries the e-mail and the signature hash' );

		$register_before_erase = WM_NDA_Database::verify_register();
		test_assert( true === $register_before_erase['valid'] && $register_before_erase['checked'] >= 1, 'control: the register verifies before the erasure' );

		$privacy_erase = WM_NDA_Exporter::erase_personal_data( 'admin@example.com' );
		$erased_row    = null;
		foreach ( $wpdb->tables['wp_wm_nda_acceptances'] as $row ) {
			if ( 1 === (int) $row->user_id ) {
				$erased_row = $row;
			}
		}
		test_assert( ( $privacy_erase['items_removed'] ?? 0 ) >= 1 && true === ( $privacy_erase['done'] ?? false ), 'erase_personal_data reports the pseudonymised records' );
		test_assert( $erased_row && '[Pseudonymisiert]' === $erased_row->user_name && str_starts_with( (string) $erased_row->user_email, 'anonymized-' ) && 64 === strlen( (string) $erased_row->signature_hash ), 'the row keeps its signature hash and loses name, e-mail, user agent' );
		// ip_hmac is part of the signed payload: rewriting it turns an erasure into a "tampered"
		// verdict for the whole register. The pseudonym stays.
		$register_after_erase = WM_NDA_Database::verify_register();
		test_assert( true === $register_after_erase['valid'], 'the register still verifies after the erasure (error: ' . (string) ( $register_after_erase['error'] ?? '' ) . ')' );
		// The account still exists: its retained, pseudonymised record belongs in its personal data export.
		$export_after_erase = WM_NDA_Exporter::export_personal_data( 'admin@example.com' );
		$after_values       = array_column( $export_after_erase['data'][0]['data'] ?? [], 'value', 'name' );
		test_assert( '[Pseudonymisiert]' === ( $after_values['Unterzeichner Name'] ?? '' ), 'a second export for the account lists the retained record under its pseudonym' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 8', $t );
	}

	// ---------------------------------------------------------
	// 9. HONEST ADAPTER, DEMO DATA THAT CANNOT FORGE OR BREAK THE REGISTER (2026-09-14)
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 9] Adapter verdict, demo data, factory reset, CLI and wording\n";
	try {
		$acc_table = 'wp_wm_nda_acceptances';
		$last_row  = end( $wpdb->tables[ $acc_table ] );
		$saved_sig = $last_row->signature_hash;
		$last_row->signature_hash = str_repeat( 'f', 64 );
		$tele_broken = $adapter->get_telemetry_metrics();
		$self_broken = $adapter->execute_action( 'self_test' );
		test_assert(
			'ERROR' === $adapter->get_health_status() && 'BROKEN' === $tele_broken['register_integrity'] && false === $self_broken['success'],
			'A tampered register makes the adapter report ERROR / BROKEN / failed self_test (it read the result array as true)'
		);
		$last_row->signature_hash = $saved_sig;
		test_assert( 'HEALTHY' === $adapter->get_health_status() && true === $adapter->execute_action( 'self_test' )['success'], '...and HEALTHY with a boolean success once the row is restored' );
		test_assert( [] === $adapter->get_dependencies() && ! str_contains( (string) wp_json_encode( $adapter->get_sbom_data() ), 'wm:compliance' ), 'Sentinel is not declared as a dependency and the SBOM carries no legal labels' );

		$rows_before = count( $wpdb->tables[ $acc_table ] );
		$seed_live   = WM_NDA_Seeder::seed_demo_data();
		$del_live    = WM_NDA_Seeder::delete_demo_data();
		test_assert(
			true === $seed_live['refused'] && count( $wpdb->tables[ $acc_table ] ) === $rows_before && 0 === $del_live['deleted'],
			'A register with real signatures refuses demo import and changes nothing'
		);

		WM_NDA_Database::reset_tables();
		$after_reset = WM_NDA_Database::verify_register();
		test_assert( true === $after_reset['valid'] && null === get_option( WM_NDA_Database::TIP_OPTION, null ), 'After a reset the empty register verifies (the tip used to survive the truncation)' );

		$fresh_term = WM_NDA_Database::get_active_term();
		$seed_fresh = WM_NDA_Seeder::seed_demo_data();
		$demo_users = array_unique( array_map( static fn( $r ) => (int) $r->user_id, $wpdb->tables[ $acc_table ] ) );
		wp_cache_flush_group( 'wm_nda_gate' );
		test_assert(
			5 === $seed_fresh['signatures'] && [ 0 ] === array_values( $demo_users ) && false === WM_NDA_Database::has_user_accepted( 1, (int) $fresh_term->term_id ) && true === WM_NDA_Database::verify_register()['valid'],
			'Demo signatures use user_id 0: no real account counts as signed, and the chain verifies'
		);
		$del_fresh = WM_NDA_Seeder::delete_demo_data();
		test_assert(
			5 === $del_fresh['deleted'] && [] === $wpdb->tables[ $acc_table ] && true === WM_NDA_Database::verify_register()['valid'],
			'Deleting demo data from a demo-only register leaves an empty register that verifies'
		);

		WM_NDA_Seeder::seed_demo_data();
		WM_NDA_Database::record_acceptance( 7, (int) $fresh_term->term_id, 'real@example.com', 'Real User', 'UA', '10.0.0.7' );
		test_assert( true === WM_NDA_Seeder::delete_demo_data()['refused'] && true === WM_NDA_Database::verify_register()['valid'], 'Demo rows chained with a real signature are not deleted, so the register keeps verifying' );

		$cli_code = wm_nda_code( 'includes/class-nda-cli.php' );
		preg_match_all( '/WM_NDA_Seeder::([a-z_]+)\(/', $cli_code, $cli_calls );
		$missing = array_filter( array_unique( $cli_calls[1] ), static fn( $m ) => ! method_exists( 'WM_NDA_Seeder', $m ) );
		test_assert( [] === array_values( $missing ) && ! str_contains( $cli_code, 'Rechtsschild' ), 'Every WM_NDA_Seeder method the CLI calls exists (seed() and factory_reset() did not) and status prints no legal label' );

		$admin_code = wm_nda_code( 'includes/class-nda-admin.php' );
		test_assert(
			! preg_match( '/Panel de Control|Vista Previa|Firmas|Saltar a|Accesos rápidos|unwiderlegbar|PDF- und JSON-Zertifikate|NONCE_SALT|Enterprise-Signaturen|Rechtliche Grundlagen|rechtliche Wirksamkeit/u', $admin_code ) && str_contains( $admin_code, 'prev_hash | user_id' ),
			'Admin: no Spanish leftovers, no "unwiderlegbar"/PDF promise, the handbook formula names prev_hash and AUTH_KEY'
		);
		// Until 2026-09-17 the label read "Aufbewahrungsfrist (nicht angewendet)", which was honest
		// and left the field useless. Now WM_NDA_Retention applies it nightly, so the text has to
		// say what the pass does — neutralise the identifying fields, delete nothing.
		test_assert(
			str_contains( $admin_code, 'Täglich angewendet' )
				&& str_contains( $admin_code, 'Gelöscht wird nichts' )
				&& ! str_contains( $admin_code, 'Aufbewahrungsfrist (nicht angewendet)' ),
			'The retention field describes the nightly pass: fields neutralised, nothing deleted'
		);

		$uninstall_code = (string) file_get_contents( __DIR__ . '/../uninstall.php' );
		test_assert( str_contains( $uninstall_code, "'wm_nda_enforce_frontend_gate'" ) && str_contains( $uninstall_code, 'wm_nda_last_accepted_term_id' ) && str_contains( $uninstall_code, "'wm_nda_cache_salt'" ) && str_contains( $uninstall_code, "'wm_nda_watermark'" ), 'Uninstall removes the frontend gate option, the cache salt, the watermark switch and the signing user meta' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 9', $t );
	}

	// ---------------------------------------------------------
	// 10. READER AUDIT 2026-09-15 — REGISTER, GATES, EXPORTS, PRIVACY
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 10] Reader audit 2026-09-15: register, gates, exports, privacy\n";

	// D1: a failed publish keeps the previous version active and the screen says so.
	try {
		$term10    = wm_nda_fresh_register( 1 );
		$before_id = (int) $term10->term_id;
		$refused1  = WM_NDA_Database::bump_version( str_repeat( '9', 55 ), 'Titel', '<p>Neuer Text</p>', 1 );
		delete_transient( 'wm_nda_active_term' );
		$still = WM_NDA_Database::get_active_term();
		test_assert( false === $refused1 && null !== $still && $before_id === (int) $still->term_id, 'D1: a version number over 50 characters is refused and the previous version stays active (was: no active version, every gate open)' );

		$new_id = WM_NDA_Database::bump_version( '2.0.0', 'Titel', '<p>Neuer Text</p><script>alert(1)</script>', 1 );
		delete_transient( 'wm_nda_active_term' );
		$active10 = WM_NDA_Database::get_active_term();
		$actives  = array_filter( $wpdb->tables['wp_wm_nda_terms'], static fn( $t ) => 1 === (int) $t->is_active );
		test_assert( is_int( $new_id ) && null !== $active10 && $new_id === (int) $active10->term_id && 1 === count( $actives ), 'D1: a valid new version becomes the only active one' );
		test_assert( null !== $active10 && hash( 'sha256', (string) $active10->term_content ) === $active10->term_hash, 'D1: the text hash covers the text as stored, after sanitising' );
		test_assert( str_contains( wm_nda_code( 'includes/class-nda-admin.php' ), '$bumped = WM_NDA_Database::bump_version(' ) && str_contains( wm_nda_code( 'includes/class-nda-admin.php' ), "'bump_failed'" ), 'D1: the versions screen reports a failed publish instead of "bumped"' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D1', $t );
	}

	// D2: register changes without the key.
	try {
		wm_nda_fresh_register( 3 );
		array_pop( $wpdb->tables['wp_wm_nda_acceptances'] );
		$tip2          = get_option( WM_NDA_Database::TIP_OPTION );
		$tip2['hash']  = end( $wpdb->tables['wp_wm_nda_acceptances'] )->signature_hash;
		$tip2['count'] = 2;
		update_option( WM_NDA_Database::TIP_OPTION, $tip2 );
		test_assert( false === WM_NDA_Database::verify_register()['valid'], 'D2: deleting the newest acceptance and rewriting the tip without the key is detected (was: valid)' );

		wm_nda_fresh_register( 3 );
		array_pop( $wpdb->tables['wp_wm_nda_acceptances'] );
		delete_option( WM_NDA_Database::TIP_OPTION );
		test_assert( false === WM_NDA_Database::verify_register()['valid'], 'D2: deleting the newest acceptance together with the tip option is detected (was: valid)' );

		wm_nda_fresh_register( 3 );
		foreach ( $wpdb->tables['wp_wm_nda_acceptances'] as $r ) {
			$r->prev_hash = '';
		}
		$wpdb->tables['wp_wm_nda_acceptances'][1]->user_id = 555;
		$GLOBALS['mock_user_ids'][]                        = 555;
		wp_cache_flush_group( 'wm_nda_gate' );
		test_assert( false === WM_NDA_Database::verify_register()['valid'] && false === WM_NDA_Database::has_user_accepted( 555, 1 ), 'D2: blanked predecessors and a rewritten account are detected and open no gate (was: valid, user 555 let through)' );

		wm_nda_fresh_register( 2 );
		$wpdb->tables['wp_wm_nda_acceptances'][0]->user_id = 556;
		$GLOBALS['mock_user_ids'][]                        = 556;
		wp_cache_flush_group( 'wm_nda_gate' );
		test_assert( false === WM_NDA_Database::has_user_accepted( 556, 1 ), 'D2: a row rewritten to another account opens no gate: access needs a signature that checks out (was: any row)' );

		wm_nda_fresh_register( 2 );
		$unsealed = get_option( WM_NDA_Database::TIP_OPTION );
		unset( $unsealed['key'], $unsealed['mac'] );
		update_option( WM_NDA_Database::TIP_OPTION, $unsealed );
		$unsealed_result = WM_NDA_Database::verify_register();
		test_assert( false === $unsealed_result['valid'] && str_contains( (string) $unsealed_result['error'], 'Siegel' ), 'D2: an unsealed tip is no proof that the newest rows are all there' );
		test_assert( true === WM_NDA_Database::seal_existing_tip() && true === WM_NDA_Database::verify_register()['valid'], 'D2: the schema 3 migration seals an unsealed tip that matches the rows' );
		$mismatch = get_option( WM_NDA_Database::TIP_OPTION );
		unset( $mismatch['key'], $mismatch['mac'] );
		$mismatch['count'] = 5;
		update_option( WM_NDA_Database::TIP_OPTION, $mismatch );
		test_assert( false === WM_NDA_Database::seal_existing_tip() && false === WM_NDA_Database::verify_register()['valid'], 'D2: the migration refuses to seal a tip that does not match the rows' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D2', $t );
	}

	// D5: the placeholder key, and a key that differs from the signing key.
	try {
		test_assert( false === WM_NDA_Database::is_usable_key( 'put your unique phrase here' ) && false === WM_NDA_Database::is_usable_key( '' ) && true === WM_NDA_Database::is_usable_key( AUTH_KEY ), 'D5: the wp-config-sample placeholder is not a signing key (was: accepted, anyone could sign)' );

		wm_nda_fresh_register( 2 );
		foreach ( $wpdb->tables['wp_wm_nda_acceptances'] as $r ) {
			$r->signature_hash = hash( 'sha256', 'signed-elsewhere-' . $r->acceptance_id );
		}
		$other_key = WM_NDA_Database::verify_register();
		test_assert( false === $other_key['valid'] && str_contains( (string) $other_key['error'], 'Schlüssel dieser Installation' ) && ! str_contains( (string) $other_key['error'], 'nachträglich verändert' ), 'D5: when no signature matches the key the register names the key, not an altered row (was: "nachträglich verändert", measured on the demo Box)' );

		$sealed_other          = get_option( WM_NDA_Database::TIP_OPTION );
		$sealed_other['key']   = 'ffffffffffffffff';
		update_option( WM_NDA_Database::TIP_OPTION, $sealed_other );
		test_assert( str_contains( (string) WM_NDA_Database::verify_register()['error'], 'versiegelt' ), 'D5: a tip sealed with another key says the key changed' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D5', $t );
	}

	// D3, D8, D11: gate URL, REST denial text, a gate on a frontend page.
	try {
		$gate10 = new WM_NDA_Interceptor();
		$wanted = 'https://example.com/?p=123&page=2';
		parse_str( (string) parse_url( $gate10->gate_url( $wanted ), PHP_URL_QUERY ), $gate_query );
		test_assert( 'wm-nda-gate' === ( $gate_query['page'] ?? '' ) && $wanted === ( $gate_query['redirect_to'] ?? '' ), 'D3: a requested URL with its own page argument keeps the gate page and comes back whole (was: page=2, redirect loop)' );

		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_current_user_id'] = 301;
		$rest_denied                     = $gate10->enforce_rest_nda( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$rest_msg                        = is_wp_error( $rest_denied ) ? $rest_denied->get_error_message() : '';
		test_assert( is_wp_error( $rest_denied ) && WM_NDA_I18n::get_consent_required_text() === $rest_msg && ! str_contains( $rest_msg, 'forensisch' ) && ! str_contains( $rest_msg, 'Sicherheitsverstoß' ), 'D8: the REST 403 says what is missing and claims no record (was: "Sicherheitsverstoß … forensisch erfasst", nothing written)' );

		$GLOBALS['mock_filters']['wm_nda_gate_url'] = [ static fn( string $url ): string => 'https://example.com/nda-bestaetigung/' ];
		$GLOBALS['mock_is_admin']                   = false;
		$_SERVER['REQUEST_URI']                     = '/nda-bestaetigung/';
		$front_on_gate                              = wm_nda_run_gate( fn() => $gate10->enforce_frontend_gate() );
		$_SERVER['REQUEST_URI']                     = '/andere-seite/';
		$front_elsewhere                            = wm_nda_run_gate( fn() => $gate10->enforce_frontend_gate() );
		$GLOBALS['mock_filters']                    = [];
		$GLOBALS['mock_is_admin']                   = true;
		$GLOBALS['mock_current_user_id']            = 1;
		test_assert( null === $front_on_gate && 'redirect' === $front_elsewhere, 'D11: a gate moved to a frontend page is not redirected to itself; other pages still go there (was: redirect loop)' );
	} catch ( Throwable $t ) {
		$GLOBALS['mock_filters']         = [];
		$GLOBALS['mock_is_admin']        = true;
		$GLOBALS['mock_current_user_id'] = 1;
		wm_nda_crashed( 'Group 10 D3/D8/D11', $t );
	}

	// D4, D9: the register lock, long display names, a login whose acceptance was not stored.
	try {
		wm_nda_fresh_register( 0 );
		$wpdb->log = [];
		WM_NDA_Database::record_acceptance( 201, 1, 'lock@example.com', 'Lock', 'UA', '203.0.113.201' );
		$position = static function ( string $needle ) use ( $wpdb ): int {
			foreach ( $wpdb->log as $i => $q ) {
				if ( str_contains( $q, $needle ) ) {
					return $i;
				}
			}
			return -1;
		};
		$lock_at    = $position( 'GET_LOCK(' );
		$tip_at     = $position( 'SELECT signature_hash' );
		$insert_at  = $position( 'INSERT INTO wp_wm_nda_acceptances' );
		$release_at = $position( 'RELEASE_LOCK(' );
		test_assert( $lock_at >= 0 && $lock_at < $tip_at && $tip_at < $insert_at && $insert_at < $release_at, "D4: tip read and insert run under the register lock (lock {$lock_at}, tip {$tip_at}, insert {$insert_at}, release {$release_at}; was: two simultaneous acceptances forked the chain)" );

		$rows_before_refusal = count( $wpdb->tables['wp_wm_nda_acceptances'] );
		$wpdb->lock_answer   = '0';
		$refused_write       = WM_NDA_Database::record_acceptance( 202, 1, 'nolock@example.com', 'No Lock', 'UA', '203.0.113.202' );
		$_POST['wm_nda_accept'] = '1';
		$refused_login       = ( new WM_NDA_Interceptor() )->validate_login_nda( new WP_User( 204, 'lockout', 'lockout@example.com' ), 'lockout', 'pw' );
		$wpdb->lock_answer   = '1';
		test_assert( false === $refused_write && count( $wpdb->tables['wp_wm_nda_acceptances'] ) === $rows_before_refusal, 'D4: without the lock nothing is written' );
		test_assert( is_wp_error( $refused_login ) && 'wm_nda_not_recorded' === $refused_login->get_error_code(), 'D9: a login whose acceptance could not be stored is refused (was: let through, then gated on the next page)' );

		$long_id  = WM_NDA_Database::record_acceptance( 203, 1, 'long@example.com', str_repeat( 'Ä', 134 ), 'UA', '203.0.113.203' );
		$long_row = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert( is_int( $long_id ) && $long_id > 0 && 100 === mb_strlen( (string) $long_row->user_name ), 'D9: a display name over 100 characters is stored cut to the column (was: refused, the account could never sign)' );
	} catch ( Throwable $t ) {
		$wpdb->lock_answer = '1';
		wm_nda_crashed( 'Group 10 D4/D9', $t );
	}

	// D6, D16, D17: exports.
	try {
		wm_nda_fresh_register( 2 );
		$cert = WM_NDA_Exporter::build_json_certificate( 0 );
		preg_match_all( '/certificate_metadata\.([a-z0-9_]+)/', (string) $cert['certificate_metadata']['master_checksum_method'], $named );
		$recipient = $cert;
		foreach ( $named[1] as $key ) {
			unset( $recipient['certificate_metadata'][ $key ] );
		}
		test_assert( hash_equals( (string) $cert['certificate_metadata']['master_checksum_sha256'], hash( 'sha256', (string) wp_json_encode( $recipient, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ), 'D6: following the recipe stated in the JSON export reproduces its checksum (was: a field added after hashing)' );

		$csv = fopen( 'php://memory', 'w+' );
		WM_NDA_Exporter::write_csv(
			$csv,
			[ (object) [ 'acceptance_id' => 1, 'user_id' => 1, 'user_name' => 'Back\\"slash', 'user_email' => 'a@example.com', 'term_version' => '1', 'term_title' => 'T', 'term_hash_at_signing' => 'h', 'ip_hmac' => 'i', 'user_agent' => 'UA', 'accepted_at' => 't', 'signature_hash' => 's' ] ]
		);
		rewind( $csv );
		$csv_out = (string) stream_get_contents( $csv );
		fclose( $csv );
		test_assert( str_contains( $csv_out, '"Back\\""slash"' ) && ! str_contains( $csv_out, 'DSGVO' ), 'D17: CSV cells follow RFC 4180 with an explicit empty escape character (was: the PHP 8.4 deprecation text in the file); no legal label in the header' );

		WM_NDA_Database::reset_tables();
		$big = [];
		for ( $i = 1; $i <= 10005; $i++ ) {
			$big[] = (object) [ 'acceptance_id' => $i, 'user_id' => 0, 'term_id' => 1, 'user_email' => "d{$i}@example.com", 'user_name' => "D{$i}", 'term_hash_at_signing' => 'x', 'ip_hmac' => 'y', 'user_agent' => 'UA', 'accepted_at' => '2026-09-15 00:00:00', 'prev_hash' => '', 'signature_hash' => 'z' ];
		}
		$wpdb->tables['wp_wm_nda_acceptances'] = $big;
		$big_cert                              = WM_NDA_Exporter::build_json_certificate( 0 );
		test_assert( 10005 === count( $big_cert['signature_records'] ) && 10005 === $big_cert['certificate_metadata']['total_signatures'], 'D16: the export carries every acceptance, not the first 10 000 (was: cut without a word)' );
		unset( $big, $big_cert );
		WM_NDA_Database::reset_tables();
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D6/D16/D17', $t );
	}

	// D7: demo rows are rows without an account.
	try {
		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_user_ids'][] = 42;
		WM_NDA_Database::record_acceptance( 42, 1, 'anna@demo-agentur.de', 'Anna [Demo] Real', 'UA', '203.0.113.42' );
		$seed7 = WM_NDA_Seeder::seed_demo_data();
		WM_NDA_Seeder::delete_demo_data();
		test_assert( 1 === WM_NDA_Seeder::register_counts()['real'] && true === $seed7['refused'] && 1 === count( $wpdb->tables['wp_wm_nda_acceptances'] ), 'D7: a real acceptance from an "@demo-" address counts as real; demo import refused, nothing deleted (was: deleted)' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D7', $t );
	}

	// D10: cached acceptances after a factory reset on an object cache without group flushing.
	try {
		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_user_ids'][] = 7;
		WM_NDA_Database::record_acceptance( 7, 1, 'seven@example.com', 'Seven', 'UA', '203.0.113.7' );
		test_assert( true === WM_NDA_Database::has_user_accepted( 7, 1 ), 'D10 control: user 7 counts as signed and the answer is cached' );
		$GLOBALS['mock_flush_group_works'] = false;
		WM_NDA_Database::reset_tables();
		$after_reset7                      = WM_NDA_Database::has_user_accepted( 7, 1 );
		$GLOBALS['mock_flush_group_works'] = true;
		test_assert( false === $after_reset7, 'D10: after a factory reset a cached acceptance no longer counts (was: user 7 let through)' );
	} catch ( Throwable $t ) {
		$GLOBALS['mock_flush_group_works'] = true;
		wm_nda_crashed( 'Group 10 D10', $t );
	}

	// D12: hub health.
	try {
		wm_nda_fresh_register( 1 );
		$adapter12 = new WM_NDA_Spoke_Adapter();
		test_assert( 'HEALTHY' === $adapter12->get_health_status(), 'D12 control: HEALTHY with tables, a verifying register, gates on and AUTH_KEY' );
		$gate_options = [ 'wm_nda_require_login_checkbox', 'wm_nda_enforce_admin_gate', 'wm_nda_enforce_frontend_gate', 'wm_nda_enforce_rest_api' ];
		foreach ( $gate_options as $option ) {
			$GLOBALS['mock_options'][ $option ] = 0;
		}
		$all_off = $adapter12->get_health_status();
		foreach ( $gate_options as $option ) {
			unset( $GLOBALS['mock_options'][ $option ] );
		}
		$wpdb->dropped = [ 'wp_wm_nda_acceptances' ];
		$no_table      = $adapter12->get_health_status();
		$self_no_table = $adapter12->execute_action( 'self_test' );
		$wpdb->dropped = [];
		test_assert( 'DEGRADED' === $all_off, 'D12: every gate switched off is DEGRADED (was: HEALTHY)' );
		test_assert( 'ERROR' === $no_table && false === $self_no_table['success'], 'D12: a missing acceptances table is ERROR and fails self_test (was: HEALTHY, VERIFIED)' );
	} catch ( Throwable $t ) {
		$wpdb->dropped = [];
		wm_nda_crashed( 'Group 10 D12', $t );
	}

	// D13: privacy tools after an e-mail change.
	try {
		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_users'][401] = [ 'lena', 'lena.neu@example.com' ];
		$GLOBALS['mock_user_ids'][] = 401;
		WM_NDA_Database::record_acceptance( 401, 1, 'lena.alt@example.com', 'Lena', 'UA', '203.0.113.41' );
		$export13 = WM_NDA_Exporter::export_personal_data( 'lena.neu@example.com' );
		$erase13  = WM_NDA_Exporter::erase_personal_data( 'lena.neu@example.com' );
		$row13    = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert( 1 === count( $export13['data'] ), 'D13: the privacy export finds the acceptances of the account after an e-mail change (was: 0 items)' );
		test_assert( 1 === $erase13['items_removed'] && true === $erase13['items_retained'] && '[Pseudonymisiert]' === $row13->user_name, 'D13: the eraser pseudonymises them and reports retained data (was: 0 removed, items_retained 0)' );
		$retained_msg = implode( ' ', $erase13['messages'] );
		test_assert( str_contains( $retained_msg, 'Konto-ID' ) && ! str_contains( $retained_msg, 'keinen Rückschluss' ) && ! str_contains( $retained_msg, 'DSGVO-konform' ) && '' === get_user_meta( 401, 'wm_nda_last_accepted_term_id', true ), 'D13: the eraser names the retained account ID, claims no "no inference" and removes the account meta' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D13', $t );
	}

	// D14, D15: rate-limit key, KPIs after deleted accounts.
	try {
		$GLOBALS['mock_transients'] = [];
		( new ReflectionMethod( WM_NDA_REST_API::class, 'enforce_rate_limit' ) )->invoke( new WM_NDA_REST_API(), '203.0.113.77' );
		$rl_keys = array_keys( $GLOBALS['mock_transients'] );
		test_assert( [ 'wm_nda_rl_' . substr( WM_NDA_Database::generate_ip_hmac( '203.0.113.77' ), 0, 32 ) ] === $rl_keys && ! str_contains( implode( '', $rl_keys ), md5( '203.0.113.77' ) ), 'D14: the rate-limit key is the salted IP HMAC, not an MD5 of the address (was: reversible by enumeration)' );

		wm_nda_fresh_register( 3 );
		$saved_ids                  = $GLOBALS['mock_user_ids'];
		$GLOBALS['mock_user_ids']   = [ 101, 104 ];
		$k15                        = WM_NDA_Database::get_kpis();
		$GLOBALS['mock_user_ids']   = $saved_ids;
		test_assert( 1 === $k15['accepted_active_users'] && 1 === $k15['pending_users'] && 50.0 === (float) $k15['acceptance_rate'], 'D15: KPIs count only accounts that still exist (was: 3 signed of 2 users, 150 %, nobody pending)' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 10 D14/D15', $t );
	}

	// ---------------------------------------------------------
	// 11. READER AUDIT 2026-09-15 — ASSETS, WORDING, CATALOGS, DOCUMENTS
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 11] Reader audit 2026-09-15: assets, wording, catalogs, documents\n";
	$root = dirname( __DIR__ );

	try {
		$modal_js = (string) file_get_contents( $root . '/assets/js/nda-gate-modal.js' );
		$admin_js = (string) file_get_contents( $root . '/assets/js/nda-admin.js' );
		test_assert( str_contains( $modal_js, "'button:not([disabled]), [href], input:not([disabled])" ), 'D20: the focus trap lists only controls that can take focus (was: Tab left the dialog while accept was disabled)' );
		test_assert( str_contains( $modal_js, "message('success')" ) && ! str_contains( $modal_js, 'erfolgreich signiert' ) && str_contains( $admin_js, 'wmNdaAdmin' ) && ! str_contains( $admin_js, 'Sind Sie sicher' ), 'Modal and admin messages come from the catalog, not German literals in the scripts' );
		test_assert( ! method_exists( 'WM_NDA_I18n', 'get_gate_subheading' ) && ! str_contains( $admin_js, 'versionForm' ), 'D22: dead code gone (get_gate_subheading, versionForm)' );

		$legal_labels = wm_nda_code( 'includes/class-nda-admin.php' ) . wm_nda_code( 'includes/class-nda-modal.php' ) . wm_nda_code( 'includes/class-nda-exporter.php' );
		test_assert( ! preg_match( '/Letzte rechtliche Signaturen|IP-HMAC \(DSGVO|lit\. b & c|GeschGehG & UrhG Schutzsystem|DSGVO-konform|keinen Rückschluss/u', $legal_labels ), 'F10/F11: no legal label on the admin screens, the modal badge, the CSV header or the eraser message' );

		$main_code = wm_nda_code( 'wm-prototype-nda-gate.php' );
		test_assert( ! str_contains( $main_code, 'style=' ) && 1 === substr_count( wm_nda_code( 'includes/class-nda-admin.php' ), 'style="' ), 'D21: no inline style with a colour in the plugin links or the admin screens (only the dynamic width of the acceptance bar)' );

		$missing_strict = [];
		foreach ( array_merge( (array) glob( $root . '/includes/*.php' ), [ $root . '/wm-prototype-nda-gate.php', $root . '/uninstall.php' ] ) as $php ) {
			if ( ! str_contains( (string) file_get_contents( $php ), 'declare(strict_types=1);' ) ) {
				$missing_strict[] = basename( $php );
			}
		}
		test_assert( [] === $missing_strict, 'F13: every shipped PHP file declares strict types (missing: ' . implode( ', ', $missing_strict ) . ')' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 11 code', $t );
	}

	try {
		$modal_css = (string) file_get_contents( $root . '/assets/css/nda-gate-modal.css' );
		$login_css = (string) file_get_contents( $root . '/assets/css/nda-login.css' );
		$tokens    = wm_nda_css_tokens( $modal_css ) + wm_nda_css_tokens( $login_css );
		$pairs     = [
			[ '--wm-nda-text', '--wm-nda-surface', 4.5 ],
			[ '--wm-nda-heading', '--wm-nda-surface', 4.5 ],
			[ '--wm-nda-heading', '--wm-nda-surface-muted', 4.5 ],
			[ '--wm-nda-text-muted', '--wm-nda-surface', 4.5 ],
			[ '--wm-nda-text-muted', '--wm-nda-surface-muted', 4.5 ],
			[ '--wm-nda-text-muted', '--wm-nda-surface-code', 4.5 ],
			[ '--wm-nda-heading', '--wm-nda-code-bg', 4.5 ],
			[ '--wm-nda-badge-text', '--wm-nda-badge-bg', 4.5 ],
			[ '--wm-nda-notice-text', '--wm-nda-notice-bg', 4.5 ],
			[ '--wm-nda-accept-text', '--wm-nda-accept-bg', 4.5 ],
			[ '--wm-nda-accept-text', '--wm-nda-accept-bg-hover', 4.5 ],
			[ '--wm-nda-danger', '--wm-nda-surface-code', 4.5 ],
			[ '--wm-nda-danger', '--wm-nda-danger-bg', 4.5 ],
			[ '--wm-nda-success', '--wm-nda-success-bg', 4.5 ],
			[ '--wm-nda-focus', '--wm-nda-surface', 3.0 ],
			[ '--wm-nda-login-text', '--wm-nda-login-bg', 4.5 ],
			[ '--wm-nda-login-link', '--wm-nda-login-bg', 4.5 ],
			[ '--wm-nda-login-link-hover', '--wm-nda-login-bg', 4.5 ],
			[ '--wm-nda-login-accent', '--wm-nda-login-bg', 3.0 ],
		];
		$low = [];
		foreach ( $pairs as [ $fg, $bg, $min ] ) {
			if ( ! isset( $tokens[ $fg ], $tokens[ $bg ] ) ) {
				$low[] = "{$fg}/{$bg} undefined";
				continue;
			}
			$ratio = wm_nda_contrast( $tokens[ $fg ], $tokens[ $bg ] );
			if ( $ratio < $min ) {
				$low[] = sprintf( '%s on %s %.2f:1', $fg, $bg, $ratio );
			}
		}
		test_assert( [] === $low, 'D21: every measured pair of the modal and the login checkbox reaches its threshold (' . implode( '; ', $low ) . ')' );

		$undefined = [];
		$raw_hex   = [];
		foreach ( [ 'nda-gate-modal.css' => $modal_css, 'nda-login.css' => $login_css ] as $name => $css ) {
			preg_match_all( '/var\((--wm-nda-[a-z0-9-]+)\)/', $css, $used );
			preg_match_all( '/(--wm-nda-[a-z0-9-]+)\s*:/', $css, $defined );
			foreach ( array_diff( array_unique( $used[1] ), $defined[1] ) as $var ) {
				$undefined[] = "{$name} {$var}";
			}
			// Comments may quote the colours a fix replaced; blank them and keep the line numbers.
			$rules = (string) preg_replace_callback( '#/\*.*?\*/#s', static fn( array $c ): string => str_repeat( "\n", substr_count( $c[0], "\n" ) ), $css );
			foreach ( preg_split( '/\R/', $rules ) ?: [] as $n => $line ) {
				if ( ! preg_match( '/^\s*--wm-nda-/', $line ) && preg_match( '/#[0-9a-fA-F]{3,6}\b/', $line ) ) {
					$raw_hex[] = "{$name}:" . ( $n + 1 );
				}
			}
		}
		test_assert( [] === $undefined && [] === $raw_hex, 'Modal and login sheets use only tokens they define (undefined: ' . implode( ', ', $undefined ) . '; raw colours: ' . implode( ', ', $raw_hex ) . ')' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 11 contrast', $t );
	}

	try {
		$lang       = $root . '/languages/';
		$double     = [];
		$unreadable = [];
		foreach ( array_merge( (array) glob( $lang . '*.po' ), [ $lang . 'wm-prototype-nda-gate.pot' ] ) as $po ) {
			// A UTF-8 lead byte read as Latin-1 / Windows-1252 followed by a continuation byte read the same way:
			// "Ã¤" for "ä", "Ð¸" for "и", "Ã„" for "Ä". Covers the Latin, Cyrillic and Greek catalogs.
			if ( preg_match( '/[\x{00C2}-\x{00DF}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]/u', (string) file_get_contents( $po ) ) ) {
				$double[] = basename( $po );
			}
		}
		foreach ( (array) glob( $lang . '*.mo' ) as $mo ) {
			if ( ! wm_nda_mo_readable( $mo ) ) {
				$unreadable[] = basename( $mo );
			}
		}
		test_assert( [] === $double, 'Catalogs carry no double-encoded UTF-8 (' . implode( ', ', array_slice( $double, 0, 5 ) ) . ')' );
		test_assert( [] === $unreadable, 'Every compiled catalog passes the checks WordPress makes before loading it (was: rejected, 0 entries on both demo sites; ' . implode( ', ', array_slice( $unreadable, 0, 5 ) ) . ')' );

		$pot_ids = array_flip( wm_nda_po_msgids( $lang . 'wm-prototype-nda-gate.pot' ) );
		$code_strings = [];
		foreach ( array_merge( (array) glob( $root . '/includes/*.php' ), [ $root . '/wm-prototype-nda-gate.php' ] ) as $php ) {
			preg_match_all( "/\\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'wm-prototype-nda-gate'/", (string) file_get_contents( $php ), $m );
			foreach ( $m[1] as $s ) {
				$code_strings[] = str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $s );
			}
		}
		$not_in_pot = array_values( array_filter( array_unique( $code_strings ), static fn( string $s ): bool => ! isset( $pot_ids[ $s ] ) ) );
		test_assert( [] !== $code_strings && [] === $not_in_pot, 'The template holds every string of the code (' . count( $not_in_pot ) . ' missing, e.g. ' . (string) ( $not_in_pot[0] ?? '' ) . ')' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 11 catalogs', $t );
	}

	try {
		$readme    = (string) file_get_contents( $root . '/README.md' );
		$distignore = (string) file_get_contents( $root . '/.distignore' );
		$ci        = (string) file_get_contents( $root . '/.github/workflows/ci.yml' );
		$changelog = (string) file_get_contents( $root . '/CHANGELOG.md' );
		$humans    = (string) file_get_contents( $root . '/public/humans.txt' );
		$pr        = (string) file_get_contents( $root . '/.github/PULL_REQUEST_TEMPLATE.md' );
		$claude    = (string) file_get_contents( $root . '/CLAUDE.md' );
		// The README may name the file to explain why it is not shown; an image embed is what counts.
		test_assert( ! preg_match( '/!\[[^\]]*\]\([^)]*prototype-nda-gate-banner\.jpg\)/', $readme ) && str_contains( $distignore, "\nassets/images/\n" ),'README embeds no marketing banner (it shows watermarking, blockchain and "Legal Compliance Ready") and the package leaves assets/images/ out' );
		test_assert( str_contains( $ci, 'run: bash tests/run-mutations.sh' ), 'CI runs the mutation check' );
		test_assert( ! preg_match( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $changelog ), 'CHANGELOG headings carry no emoji' );
		test_assert( ! str_contains( $humans, 'CEO' ) && ! str_contains( $humans, 'PHP 8.1' ) && ! str_contains( $humans, 'Gutenberg' ) && ! str_contains( $humans, 'Compliant' ), 'humans.txt: no CEO, PHP 8.2, no Gutenberg or React, no compliance claim (F23, C4)' );
		test_assert( str_contains( $pr, 'tests/test-suite.php' ) && ! str_contains( $pr, 'test-nda-interception.php' ), 'The PR checklist names the suite CI runs, not a destructive WP-CLI suite (F24)' );
		test_assert( ! preg_match( '/test-nda-privacy-tools\.php`?,? (?:and )?`?test-register-integrity/', $claude ), 'CLAUDE.md does not call the privacy-tools suite destructive (F21)' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 11 documents', $t );
	}


	// ---------------------------------------------------------
	// 12. CLICK-WRAP INTERACTION RECORD (2026-09-16)
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 12] Click-wrap interaction record: sanitiser, seal, transports, exports\n";

	// The sanitiser is the whole trust boundary: everything below comes from a browser.
	try {
		$raw = [
			'pointer_type'          => 'pen',
			'accept_input'          => 'pointer',
			'move_samples'          => 42,
			'path_px'               => 1337,
			'pressure_max_permille' => 612,
			'scroll_pct'            => 101,          // out of range
			'dwell_ms'              => 'schnell',    // not numeric
			'viewport_w'            => -1,           // out of range
			'tz_offset_min'         => 120,
			'schema'                => 9,            // claimed by the client
			'mouse_track'           => [ [ 10, 20 ], [ 11, 21 ] ], // unknown key
		];
		$clean = WM_NDA_Evidence::sanitize( $raw );

		test_assert(
			! isset( $clean['scroll_pct'], $clean['dwell_ms'], $clean['viewport_w'], $clean['mouse_track'] ),
			'Sanitiser drops values outside their range, non-numeric values and unknown keys (' . (string) wp_json_encode( array_keys( $clean ) ) . ')'
		);
		test_assert(
			612 === ( $clean['pressure_max_permille'] ?? 0 ) && 'pen' === ( $clean['pointer_type'] ?? '' ) && 42 === ( $clean['move_samples'] ?? 0 ),
			'Sanitiser keeps the fields that are in range and in the enumeration'
		);
		test_assert( WM_NDA_Evidence::SCHEMA === ( $clean['schema'] ?? 0 ), 'The schema number is set by the server, not taken from the request (client claimed 9)' );
		test_assert( [] === WM_NDA_Evidence::sanitize( [ 'pointer_type' => 'laserpointer', 'scroll_pct' => 500 ] ), 'A record whose every field is refused is no record at all' );
		test_assert( [] === WM_NDA_Evidence::sanitize( '{"move_samples":1,"pad":"' . str_repeat( 'x', WM_NDA_Evidence::MAX_INPUT_BYTES ) . '"}' ), 'A JSON larger than MAX_INPUT_BYTES is dropped before it is parsed' );
		test_assert( [] === WM_NDA_Evidence::sanitize( '' ) && [] === WM_NDA_Evidence::sanitize( 'not json' ) && [] === WM_NDA_Evidence::sanitize( null ), 'Nothing, and nonsense, produce no record' );

		// Two clients that send the same facts in another order must sign the same thing.
		$a = WM_NDA_Evidence::canonical_json( WM_NDA_Evidence::sanitize( [ 'move_samples' => 7, 'pointer_type' => 'touch', 'dwell_ms' => 900 ] ) );
		$b = WM_NDA_Evidence::canonical_json( WM_NDA_Evidence::sanitize( [ 'dwell_ms' => 900, 'pointer_type' => 'touch', 'move_samples' => 7 ] ) );
		test_assert( $a === $b && '' !== $a, 'Two requests with the same facts in another order produce the same stored record' );

		// ...and the canonical form does the sorting itself, for records the sanitiser did not build.
		// The assertion above passes on the sanitiser's own field order alone and proved nothing here
		// (the mutation that removed ksort() survived it).
		$sorted_a = WM_NDA_Evidence::canonical_json( [ 'schema' => 1, 'move_samples' => 7, 'dwell_ms' => 900 ] );
		$sorted_b = WM_NDA_Evidence::canonical_json( [ 'dwell_ms' => 900, 'schema' => 1, 'move_samples' => 7 ] );
		test_assert(
			$sorted_a === $sorted_b && str_starts_with( $sorted_a, '{"dwell_ms"' ),
			'The canonical form sorts the keys itself, so the same record always hashes the same (' . $sorted_a . ' / ' . $sorted_b . ')'
		);
		test_assert( ! preg_match( '/[0-9]\.[0-9]/', $a ), 'The stored record carries no float: serialize_precision would make the same record hash differently on another server (' . $a . ')' );
		test_assert( '' === WM_NDA_Evidence::canonical_json( [] ) && '' === WM_NDA_Evidence::fingerprint( '' ), 'No record means an empty canonical form and an empty fingerprint' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 12 sanitiser', $t );
	}

	// The payload: acceptances written before this column must keep verifying, and a record must change the signature.
	try {
		$legacy = hash_hmac( 'sha256', 'PREVHASH|5|1|TERMHASH|2026-09-16 10:00:00|IPHMAC', AUTH_KEY );
		$empty  = WM_NDA_Database::generate_signature_hash( 5, 1, 'TERMHASH', '2026-09-16 10:00:00', 'IPHMAC', 'PREVHASH', '' );
		$sealed = WM_NDA_Database::generate_signature_hash( 5, 1, 'TERMHASH', '2026-09-16 10:00:00', 'IPHMAC', 'PREVHASH', 'EVIDENCEHASH' );
		test_assert( hash_equals( $legacy, $empty ), 'Without a record the signed payload is byte-for-byte the one of schema 3: every acceptance written before today keeps verifying' );
		test_assert(
			! hash_equals( $empty, $sealed ) && hash_equals( hash_hmac( 'sha256', 'PREVHASH|5|1|TERMHASH|2026-09-16 10:00:00|IPHMAC|EVIDENCEHASH', AUTH_KEY ), $sealed ),
			'With a record the fingerprint is appended to the payload and the signature changes'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 12 payload', $t );
	}

	// The seal: the record cannot be edited, blanked or added afterwards without breaking the chain.
	try {
		wm_nda_fresh_register( 0 );
		$record = [ 'pointer_type' => 'mouse', 'accept_input' => 'pointer', 'move_samples' => 180, 'path_px' => 2400, 'pressure_max_permille' => 500, 'dwell_ms' => 64000, 'check_to_accept_ms' => 3100, 'scroll_pct' => 100, 'viewport_w' => 1600, 'viewport_h' => 900, 'tz_offset_min' => 120 ];
		$sealed_id = WM_NDA_Database::record_acceptance( 501, 1, 'sealed@example.com', 'Sealed', 'UA', '203.0.113.51', $record );
		$sealed_row = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		$stored = (string) $sealed_row->interaction_json;

		test_assert( is_int( $sealed_id ) && $sealed_id > 0 && '' !== $stored && str_contains( $stored, '"pointer_type":"mouse"' ), 'An acceptance stores the record it was given (' . $stored . ')' );
		test_assert( true === WM_NDA_Database::verify_register()['valid'], 'control: the register with a record verifies' );

		$sealed_row->interaction_json = str_replace( '"dwell_ms":64000', '"dwell_ms":600', $stored );
		test_assert( false === WM_NDA_Database::verify_register()['valid'] && false === WM_NDA_Database::row_signature_valid( $sealed_row ), 'Rewriting the record breaks the signature of its row: 64 s of reading cannot be turned into 0.6 s afterwards' );

		$sealed_row->interaction_json = '';
		test_assert( false === WM_NDA_Database::verify_register()['valid'], 'Deleting the record breaks the signature too: an inconvenient record cannot be blanked' );

		$sealed_row->interaction_json = $stored;
		wp_cache_flush_group( 'wm_nda_gate' );
		test_assert( true === WM_NDA_Database::verify_register()['valid'] && true === WM_NDA_Database::has_user_accepted( 501, 1 ), 'Putting the record back makes the row verify again and the gate opens' );

		// A row that never carried one: adding a record afterwards must not pass either.
		$plain_id  = WM_NDA_Database::record_acceptance( 502, 1, 'plain@example.com', 'Plain', 'UA', '203.0.113.52' );
		$plain_row = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert( is_int( $plain_id ) && '' === (string) $plain_row->interaction_json && true === WM_NDA_Database::verify_register()['valid'], 'An acceptance without a record is stored with an empty one and verifies' );
		$plain_row->interaction_json = '{"dwell_ms":180000,"pointer_type":"pen","schema":1}';
		test_assert( false === WM_NDA_Database::verify_register()['valid'], 'Inventing a record for an acceptance that carried none is detected' );
		$plain_row->interaction_json = '';
		test_assert( true === WM_NDA_Database::verify_register()['valid'], 'control: the register verifies again once the invented record is removed' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 12 seal', $t );
	}

	// The two transports: the login form and the REST route.
	try {
		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_current_user_id'] = 1;

		$_POST['wm_nda_accept']      = '1';
		$_POST['wm_nda_interaction'] = '{"pointer_type":"touch","accept_input":"pointer","move_samples":31,"path_px":540,"pressure_max_permille":770,"pressure_samples":31,"dwell_ms":41000,"scroll_pct":92,"viewport_w":390,"viewport_h":763,"tz_offset_min":120}';
		$login_user                  = ( new WM_NDA_Interceptor() )->validate_login_nda( new WP_User( 610, 'signer610', 's610@example.com' ), 'signer610', 'pw' );
		$login_row                   = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert(
			$login_user instanceof WP_User && str_contains( (string) $login_row->interaction_json, '"pointer_type":"touch"' ) && WM_NDA_Database::row_signature_valid( $login_row ),
			'The wp-login.php path stores the record posted in its hidden field and signs it (' . (string) $login_row->interaction_json . ')'
		);
		test_assert(
			str_contains( (string) $login_row->interaction_json, '"schema":1' ) && ! str_contains( (string) $login_row->interaction_json, 'accept_input":"trust-me' ),
			'The login record went through the sanitiser, not into the column as it arrived'
		);

		$_POST['wm_nda_accept']      = '1';
		$_POST['wm_nda_interaction'] = str_repeat( 'x', WM_NDA_Evidence::MAX_INPUT_BYTES + 1 );
		$flood_user                  = ( new WM_NDA_Interceptor() )->validate_login_nda( new WP_User( 611, 'signer611', 's611@example.com' ), 'signer611', 'pw' );
		$flood_row                   = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert(
			$flood_user instanceof WP_User && '' === (string) $flood_row->interaction_json && WM_NDA_Database::row_signature_valid( $flood_row ),
			'A login whose record is nonsense still signs: the acceptance matters, the record is best effort'
		);
		unset( $_POST['wm_nda_interaction'] );
		$_POST['wm_nda_accept'] = '0';

		$rest    = new WM_NDA_REST_API();
		$request = new WP_REST_Request( 'POST', '/wm-nda/v1/accept' );
		$request->set_param( 'term_id', 1 );
		$request->set_param( 'interaction', [ 'pointer_type' => 'mouse', 'accept_input' => 'keyboard', 'move_samples' => 12, 'path_px' => 90, 'dwell_ms' => 150000, 'scroll_pct' => 100, 'viewport_w' => 1920, 'viewport_h' => 1040, 'tz_offset_min' => 120 ] );
		$GLOBALS['mock_transients'] = [];
		$response                   = $rest->handle_accept( $request );
		$rest_row                   = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert(
			$response instanceof WP_REST_Response && 200 === $response->get_status() && true === ( $response->get_data()['interaction_recorded'] ?? null ) && str_contains( (string) $rest_row->interaction_json, '"accept_input":"keyboard"' ),
			'POST /accept stores the record it was sent and says so in the answer'
		);

		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_transients'] = [];
		$bad_request                = new WP_REST_Request( 'POST', '/wm-nda/v1/accept' );
		$bad_request->set_param( 'term_id', 1 );
		$bad_request->set_param( 'interaction', 'held mich fest' );
		$bad_response = $rest->handle_accept( $bad_request );
		$bad_row      = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert(
			$bad_response instanceof WP_REST_Response && 200 === $bad_response->get_status() && false === ( $bad_response->get_data()['interaction_recorded'] ?? null ) && '' === (string) $bad_row->interaction_json,
			'A malformed record does not refuse the acceptance: it is dropped and the signature is written without it'
		);
	} catch ( Throwable $t ) {
		unset( $_POST['wm_nda_interaction'] );
		wm_nda_crashed( 'Group 12 transports', $t );
	}

	// What the record is shown as, and what it is not claimed to be.
	try {
		$mouse = WM_NDA_Evidence::describe( WM_NDA_Evidence::sanitize( [ 'pointer_type' => 'mouse', 'pressure_max_permille' => 500, 'dwell_ms' => 64000 ] ) );
		$pen   = WM_NDA_Evidence::describe( WM_NDA_Evidence::sanitize( [ 'pointer_type' => 'pen', 'pressure_max_permille' => 612 ] ) );
		$mouse_values = array_column( $mouse, 'value' );
		$pen_values   = array_column( $pen, 'value' );
		test_assert(
			in_array( 'kein Drucksensor (Zeigegerät meldet den Festwert 0,5)', $mouse_values, true ),
			'0.5 from a mouse is named the constant the Pointer Events specification prescribes, not half pressure (' . implode( ' | ', $mouse_values ) . ')'
		);
		test_assert( in_array( '0,61 von 1,0', $pen_values, true ), 'A pen reports a measurement and it is shown as one (' . implode( ' | ', $pen_values ) . ')' );
		test_assert( in_array( '64,0 s', $mouse_values, true ), 'Durations are shown in seconds with one decimal, not in browser milliseconds' );
		test_assert( [] === WM_NDA_Evidence::describe( [] ) && 'Kein Interaktionsnachweis' === WM_NDA_Evidence::short_summary( [] ), 'An acceptance without a record says so instead of showing an empty table' );

		$admin_code = wm_nda_code( 'includes/class-nda-admin.php' );
		test_assert(
			str_contains( $admin_code, 'Vom Browser gemeldet und mit der Signatur dieser Zeile versiegelt' ) && ! preg_match( '/biometrisch|zweifelsfrei|identität des unterzeichners/ui', $admin_code ),
			'The signatures screen says where the record comes from and claims no biometric identification'
		);
		test_assert( str_contains( $admin_code, 'sha256( interaction_json )' ), 'The handbook formula names the appended fingerprint' );

		// The browser is the other end of this contract; until now nothing measured it.
		$gate_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/nda-gate-modal.js' );
		test_assert(
			str_contains( $gate_js, 'interaction: buildRecord()' ) && str_contains( $gate_js, 'hiddenField.value = JSON.stringify(buildRecord())' ),
			'The script sends the record on both paths: in the REST body and in the login form field'
		);

		// Field names the script sends, against the fields the server accepts. A name that drifts on
		// one side alone is silently dropped by the sanitiser and the record loses a fact.
		$build = (string) ( preg_split( '/function buildRecord\(\) \{/', $gate_js )[1] ?? '' );
		$build = (string) ( preg_split( '/\n\t\t\}/', $build )[0] ?? '' );
		preg_match_all( '/(?:record\.([a-z_]+)\s*=|^\s*([a-z_]+):)/m', $build, $sent );
		$sent_fields   = array_values( array_unique( array_filter( array_merge( $sent[1], $sent[2] ) ) ) );
		$known_fields  = array_merge( array_keys( WM_NDA_Evidence::NUMERIC_FIELDS ), [ 'pointer_type', 'accept_input' ] );
		$unknown_sent  = array_values( array_diff( $sent_fields, $known_fields ) );
		test_assert(
			[] !== $sent_fields && [] === $unknown_sent,
			'Every field the script sends is one the server knows; nothing else (unknown: ' . implode( ', ', $unknown_sent ) . ')'
		);
		test_assert(
			! preg_match( '/(client[XY]|screen[XY]|pageX)\s*:/', $build ),
			'No coordinate leaves the browser: the script sends counts and distances, not a pointer path'
		);

		ob_start();
		( new WM_NDA_Interceptor() )->render_login_checkbox();
		$login_html = (string) ob_get_clean();
		test_assert(
			str_contains( $login_html, 'name="wm_nda_interaction"' ) && str_contains( $login_html, 'type="hidden"' ),
			'The login form carries the field the record travels in (without it the login path signs without one)'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 12 wording', $t );
	}

	// Exports, privacy tools and demo data.
	try {
		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_users'][701] = [ 'ronja', 'ronja@example.com' ];
		$GLOBALS['mock_user_ids'][] = 701;
		WM_NDA_Database::record_acceptance( 701, 1, 'ronja@example.com', 'Ronja', 'UA', '203.0.113.71', [ 'pointer_type' => 'pen', 'accept_input' => 'pointer', 'move_samples' => 88, 'path_px' => 1500, 'pressure_max_permille' => 640, 'dwell_ms' => 52000, 'scroll_pct' => 100, 'viewport_w' => 1112, 'viewport_h' => 834, 'tz_offset_min' => 120 ] );
		$row701 = end( $wpdb->tables['wp_wm_nda_acceptances'] );

		$cert   = WM_NDA_Exporter::build_json_certificate( 0 );
		$record = $cert['signature_records'][0] ?? [];
		test_assert(
			'pen' === ( $record['interaction']['pointer_type'] ?? '' ) && hash_equals( hash( 'sha256', (string) $row701->interaction_json ), (string) ( $record['interaction_sha256'] ?? '' ) ),
			'The JSON export carries the record and the fingerprint the signature covers, so a recipient can recompute it'
		);
		test_assert( str_contains( (string) $cert['certificate_metadata']['signature_payload'], 'interaction_sha256' ), 'The JSON export states how the fingerprint enters the signed payload' );

		$csv_stream = fopen( 'php://memory', 'w+' );
		WM_NDA_Exporter::write_csv( $csv_stream, WM_NDA_Database::get_all_acceptances( 0 ) );
		rewind( $csv_stream );
		$csv_text = (string) stream_get_contents( $csv_stream );
		fclose( $csv_stream );
		test_assert( str_contains( $csv_text, 'Interaktionsnachweis (JSON)' ) && str_contains( $csv_text, '""pointer_type"":""pen""' ), 'The CSV carries the stored record, quoted as RFC 4180 requires' );

		$export701 = WM_NDA_Exporter::export_personal_data( 'ronja@example.com' );
		$labels    = array_column( $export701['data'][0]['data'] ?? [], 'name' );
		test_assert( in_array( 'Eingabegerät', $labels, true ) && in_array( 'Zeit im Dialog', $labels, true ), 'The personal data export lists the interaction record: it is data about this person (' . implode( ', ', $labels ) . ')' );

		$erase701 = WM_NDA_Exporter::erase_personal_data( 'ronja@example.com' );
		$row_after = end( $wpdb->tables['wp_wm_nda_acceptances'] );
		test_assert(
			true === ( $erase701['items_retained'] ?? false ) && str_contains( implode( ' ', $erase701['messages'] ), 'Interaktionsnachweis' ) && true === WM_NDA_Database::verify_register()['valid'],
			'The eraser keeps the record, names it among the retained data and leaves the register verifying'
		);
		test_assert( str_contains( (string) $row_after->interaction_json, '"pointer_type":"pen"' ), 'control: the erasure did not silently drop the record (that would break the chain)' );

		WM_NDA_Database::reset_tables();
		WM_NDA_Seeder::seed_demo_data();
		$demo_records = array_filter( array_map( static fn( $r ) => (string) $r->interaction_json, $wpdb->tables['wp_wm_nda_acceptances'] ) );
		$demo_types   = [];
		foreach ( $wpdb->tables['wp_wm_nda_acceptances'] as $r ) {
			$demo_types[] = (string) ( WM_NDA_Evidence::from_row( $r )['pointer_type'] ?? '' );
		}
		test_assert(
			5 === count( $demo_records ) && true === WM_NDA_Database::verify_register()['valid'] && in_array( 'touch', $demo_types, true ) && in_array( 'pen', $demo_types, true ),
			'Demo data carries records of different devices and the demo register verifies with them'
		);
		WM_NDA_Database::reset_tables();
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 12 exports', $t );
	}


	// ---------------------------------------------------------
	// 13. FORENSIC SCREEN WATERMARK (2026-09-16)
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 13] Forensic watermark: block, round trip through an image, limits, overlay\n";

	// A synthetic screenshot: a flat page would let the reader look better than it is.
	function wm_nda_page_image( int $width, int $height ) {
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width, $height, (int) imagecolorallocate( $image, 250, 250, 250 ) );
		$ink = (int) imagecolorallocate( $image, 30, 30, 35 );
		for ( $y = 40; $y < $height - 40; $y += 26 ) {
			$line = (int) round( $width * 0.62 ) - ( intdiv( $y, 26 ) % 5 ) * 40;
			imagefilledrectangle( $image, 60, $y, 60 + max( 40, $line ), $y + 9, $ink );
		}
		return $image;
	}

	// A page with a hero image: text blocks that carry the pattern next to blocks that swing by a
	// hundred steps. This is the shape of a real home page, and it is what the weighting is for.
	function wm_nda_hero_image( int $width, int $height ) {
		$image = wm_nda_page_image( $width, $height );
		for ( $y = 0; $y < (int) ( $height * 0.42 ); $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$r = (int) ( 127 + 120 * sin( $x / 47.0 ) );
				$g = (int) ( 127 + 118 * sin( $y / 29.0 ) );
				$b = (int) ( 127 + 122 * sin( ( $x + $y ) / 61.0 ) );
				imagesetpixel( $image, $x, $y, (int) imagecolorallocate( $image, max( 0, min( 255, $r ) ), max( 0, min( 255, $g ) ), max( 0, min( 255, $b ) ) ) );
			}
		}
		return $image;
	}

	function wm_nda_write_png( $image ): string {
		$file = tempnam( sys_get_temp_dir(), 'wm-nda-wm' ) . '.png';
		imagepng( $image, $file );
		return $file;
	}

	// The block itself: what the browser is handed, and what comes back out of it.
	try {
		test_assert( extension_loaded( 'gd' ), 'GD is available: without it the watermark can be painted but never read back' );

		$minutes = WM_NDA_Watermark::minutes_since_epoch( (int) strtotime( '2026-09-16 10:30:00 UTC' ) );
		$bits    = WM_NDA_Watermark::block_bits( 4711, $minutes );

		test_assert( count( $bits ) === WM_NDA_Watermark::block_cells(), 'A block carries exactly one bit per cell (' . count( $bits ) . ')' );
		test_assert(
			array_slice( $bits, 0, WM_NDA_Watermark::SYNC_BITS ) === WM_NDA_Watermark::int_to_bits( WM_NDA_Watermark::SYNC_WORD, WM_NDA_Watermark::SYNC_BITS ),
			'Every block opens with the sync word, which is what lets a cropped screenshot be aligned'
		);

		$decoded = WM_NDA_Watermark::decode_bits( $bits );
		test_assert(
			is_array( $decoded ) && 4711 === $decoded['user_id'] && $minutes === $decoded['minutes'] && '2026-09-16 10:30' === $decoded['issued_at'],
			'A block decodes back to the account and the minute it was made for (' . (string) wp_json_encode( $decoded ) . ')'
		);

		// The hexadecimal the page carries is the same bits, nothing more: the browser never sees the
		// account, the minute or the key.
		$hex       = WM_NDA_Watermark::block_hex( 4711, $minutes );
		$from_hex  = [];
		foreach ( str_split( $hex ) as $nibble ) {
			$from_hex = array_merge( $from_hex, WM_NDA_Watermark::int_to_bits( (int) hexdec( $nibble ), 4 ) );
		}
		test_assert( $from_hex === $bits, 'The hexadecimal handed to the browser unpacks to the same bits' );

		foreach ( [ 8, 30, 60, 79 ] as $position ) {
			$tampered              = $bits;
			$tampered[ $position ] = 1 - $tampered[ $position ];
			if ( null !== WM_NDA_Watermark::decode_bits( $tampered ) ) {
				test_assert( false, "A block with bit {$position} flipped still verified" );
				break;
			}
		}
		test_assert( true, 'A single flipped bit anywhere in the payload fails the MAC: the reader cannot be talked into an account' );

		test_assert(
			WM_NDA_Watermark::mac( 1, 4711, $minutes ) !== WM_NDA_Watermark::mac( 1, 4712, $minutes )
			&& WM_NDA_Watermark::mac( 1, 4711, $minutes ) !== WM_NDA_Watermark::mac( 1, 4711, $minutes + 1 ),
			'The MAC changes with the account and with the minute'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 13 block', $t );
	}

	// The round trip: paint it the way the browser does, read it back off the pixels.
	try {
		$minutes = WM_NDA_Watermark::minutes_since_epoch( (int) strtotime( '2026-09-16 10:30:00 UTC' ) );

		$page = wm_nda_page_image( 1440, 900 );
		WM_NDA_Watermark::render_onto( $page, 8125, $minutes );
		$file   = wm_nda_write_png( $page );
		$read   = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert(
			true === $read['found'] && 8125 === $read['user_id'] && '2026-09-16 10:30' === $read['issued_at'],
			'A 1440 × 900 screenshot of a page full of text gives back the account and the minute (' . (string) wp_json_encode( $read ) . ')'
		);

		// A 2× display: the screenshot is twice the size with twice the cell, and is reduced on the way in.
		$retina = wm_nda_page_image( 2880, 1800 );
		WM_NDA_Watermark::render_onto( $retina, 8125, $minutes, 2 );
		$file = wm_nda_write_png( $retina );
		$read = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( true === $read['found'] && 8125 === $read['user_id'], 'A screenshot from a 2× display reads back the same account' );

		// JPEG: what a leak actually travels as.
		$jpeg = wm_nda_page_image( 1440, 900 );
		WM_NDA_Watermark::render_onto( $jpeg, 8125, $minutes );
		$file = tempnam( sys_get_temp_dir(), 'wm-nda-wm' ) . '.jpg';
		imagejpeg( $jpeg, $file, 75 );
		$read = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( true === $read['found'] && 8125 === $read['user_id'], 'A JPEG at quality 75 still reads back the account' );

		// A dark page. Black ink alone changed nothing there, which is why both inks are painted.
		$dark = imagecreatetruecolor( 1440, 900 );
		imagefilledrectangle( $dark, 0, 0, 1440, 900, (int) imagecolorallocate( $dark, 24, 26, 31 ) );
		for ( $y = 40; $y < 860; $y += 26 ) {
			imagefilledrectangle( $dark, 60, $y, 60 + 700 - ( intdiv( $y, 26 ) % 5 ) * 40, $y + 9, (int) imagecolorallocate( $dark, 226, 228, 233 ) );
		}
		WM_NDA_Watermark::render_onto( $dark, 8125, $minutes );
		$file = wm_nda_write_png( $dark );
		$read = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( true === $read['found'] && 8125 === $read['user_id'], 'A dark page reads back too: the two inks keep the distance between a one and a zero the same wherever they land' );

		// Smaller screenshots: fewer blocks to average, which is where the reader used to give up.
		foreach ( [ [ 1120, 768 ], [ 960, 640 ] ] as [ $w, $h ] ) {
			$small = wm_nda_page_image( $w, $h );
			WM_NDA_Watermark::render_onto( $small, 8125, $minutes );
			$file = wm_nda_write_png( $small );
			$read = WM_NDA_Watermark::extract_from_file( $file );
			unlink( $file );
			test_assert( true === $read['found'] && 8125 === $read['user_id'], "A {$w} × {$h} screenshot of the same page still names the account (" . var_export( $read['blocks'], true ) . ' blocks)' );
		}

		// A page whose top half is a hero image. Counting every block equally, the hero drowned the
		// text blocks and the MAC failed; weighting each block by how quiet it is recovers it.
		$hero = wm_nda_hero_image( 1440, 900 );
		WM_NDA_Watermark::render_onto( $hero, 8125, $minutes );
		$file = wm_nda_write_png( $hero );
		$read = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( true === $read['found'] && 8125 === $read['user_id'], 'A page with a hero image over its top half still names the account (measured on a real browser screenshot first)' );

		// A crop that is not aligned to the grid, which is what a window screenshot looks like.
		$full = wm_nda_page_image( 1440, 900 );
		WM_NDA_Watermark::render_onto( $full, 8125, $minutes );
		$crop = imagecrop( $full, [ 'x' => 37, 'y' => 53, 'width' => 960, 'height' => 640 ] );
		$file = wm_nda_write_png( $crop );
		$read = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( true === $read['found'] && 8125 === $read['user_id'], 'A crop that starts in the middle of a block is found anyway (was: only aligned crops)' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 13 round trip', $t );
	}

	// What it must never do, and what it does not claim to do.
	try {
		$minutes = WM_NDA_Watermark::minutes_since_epoch( (int) strtotime( '2026-09-16 10:30:00 UTC' ) );

		foreach ( [ 'page' => wm_nda_page_image( 1440, 900 ), 'flat' => imagecreatetruecolor( 800, 600 ) ] as $name => $clean ) {
			$file  = wm_nda_write_png( $clean );
			$empty = WM_NDA_Watermark::extract_from_file( $file );
			unlink( $file );
			test_assert(
				false === $empty['found'] && null === $empty['user_id'],
				"An image with no watermark ({$name}) names no account: the MAC is what decides, not the grid"
			);
		}

		// A limit, pinned as a limit: scaling destroys the grid and the reader says so instead of guessing.
		$scaled_source = wm_nda_page_image( 1440, 900 );
		WM_NDA_Watermark::render_onto( $scaled_source, 8125, $minutes );
		$scaled = imagescale( $scaled_source, 1152, 720 );
		$file   = wm_nda_write_png( $scaled );
		$read   = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( false === $read['found'] && null === $read['user_id'], 'A screenshot the leaker resized is reported as not found, never as some other account' );

		// Too small to average anything: one block of pattern against a page of text is not evidence.
		$tiny = wm_nda_page_image( 300, 240 );
		WM_NDA_Watermark::render_onto( $tiny, 8125, $minutes );
		$file = wm_nda_write_png( $tiny );
		$read = WM_NDA_Watermark::extract_from_file( $file );
		unlink( $file );
		test_assert( false === $read['found'], 'A crop holding a single block of the pattern is answered with "not found" instead of a guess' );
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 13 limits', $t );
	}

	// The overlay: when it is printed, what it carries, and what it must not carry.
	try {
		wm_nda_fresh_register( 0 );
		$GLOBALS['mock_current_user_id'] = 1;
		$GLOBALS['mock_logged_in']       = true;
		WM_NDA_Database::record_acceptance( 1, 1, 'admin@example.com', 'Administrator', 'UA', '203.0.113.1' );

		unset( $GLOBALS['mock_options'][ WM_NDA_Watermark::OPTION ] );
		test_assert( false === WM_NDA_Watermark::should_render(), 'Off by default: no overlay until the operator switches it on' );

		$GLOBALS['mock_options'][ WM_NDA_Watermark::OPTION ] = 1;
		test_assert( true === WM_NDA_Watermark::should_render(), 'On, for an account that signed the active agreement' );

		$GLOBALS['mock_current_user_id'] = 909;
		wp_cache_flush_group( 'wm_nda_gate' );
		test_assert( false === WM_NDA_Watermark::should_render(), 'No overlay for an account that has not signed: it sees the gate, not the content' );

		$GLOBALS['mock_current_user_id'] = 1;
		$GLOBALS['mock_logged_in']       = false;
		test_assert( false === WM_NDA_Watermark::should_render(), 'No overlay for anonymous visitors' );
		$GLOBALS['mock_logged_in'] = true;

		ob_start();
		WM_NDA_Watermark::render_overlay();
		$overlay = (string) ob_get_clean();
		unset( $GLOBALS['mock_options'][ WM_NDA_Watermark::OPTION ] );

		$expected_hex = WM_NDA_Watermark::block_hex( 1 );
		test_assert(
			str_contains( $overlay, 'data-block="' . $expected_hex . '"' ) && str_contains( $overlay, 'aria-hidden="true"' ),
			'The overlay carries the finished block and is hidden from assistive technology'
		);
		test_assert(
			! preg_match( '/data-user|data-account|data-issued|data-minute|' . preg_quote( (string) WM_NDA_Watermark::minutes_since_epoch(), '/' ) . '/', $overlay ),
			'The page never carries the account or the minute in readable form — only the bits: ' . $overlay
		);
	} catch ( Throwable $t ) {
		unset( $GLOBALS['mock_options'][ WM_NDA_Watermark::OPTION ] );
		$GLOBALS['mock_logged_in'] = true;
		wm_nda_crashed( 'Group 13 overlay', $t );
	}

	// The browser paints what the server reads. A constant that drifts on one side breaks the other.
	try {
		$watermark_js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/nda-watermark.js' );
		$watermark_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/nda-watermark.css' );

		test_assert(
			str_contains( $watermark_js, "parseInt(host.dataset.cell, 10) || 16" )
			&& str_contains( $watermark_js, "parseInt(host.dataset.cols, 10) || 10" )
			&& str_contains( $watermark_js, "parseInt(host.dataset.rows, 10) || 8" )
			&& 16 === WM_NDA_Watermark::CELL_PX && 10 === WM_NDA_Watermark::BLOCK_COLS && 8 === WM_NDA_Watermark::BLOCK_ROWS,
			'The fallbacks in the script are the geometry the server reads back'
		);
		test_assert(
			str_contains( $watermark_js, '1 === ((bx + by) % 2)' ) && true === WM_NDA_Watermark::CARRIER_INVERTS_ODD_BLOCKS,
			'The script inverts the same blocks the reader subtracts (without it the reader cancels the pattern instead of the page)'
		);
		test_assert(
			str_contains( $watermark_js, "'rgba(0,0,0,'" ) && str_contains( $watermark_js, "'rgba(255,255,255,'" ),
			'The script paints opposite inks — the same modulation the reader assumes, and the one that works on dark pages'
		);
		test_assert(
			str_contains( $watermark_css, 'pointer-events: none' ) && str_contains( $watermark_css, 'position: fixed' ),
			'The overlay covers the viewport and takes no clicks'
		);

		// What the overlay costs in contrast, pinned so raising the alpha cannot pass unnoticed. Worst
		// case per pixel: a zero bit lightens the text while a one bit darkens the paper next to it.
		$alpha        = WM_NDA_Watermark::ALPHA_PERMILLE / 1000;
		$over_text    = 110 * ( 1 - $alpha ) + 255 * $alpha;
		$over_paper   = 255 * ( 1 - $alpha );
		$before       = wm_nda_contrast( '#6e6e6e', '#ffffff' );
		$after        = wm_nda_contrast(
			sprintf( '#%02x%02x%02x', (int) round( $over_text ), (int) round( $over_text ), (int) round( $over_text ) ),
			sprintf( '#%02x%02x%02x', (int) round( $over_paper ), (int) round( $over_paper ), (int) round( $over_paper ) )
		);
		test_assert(
			$before >= 5.0 && $after >= 4.4 && $after < 4.5,
			sprintf( 'The documented accessibility cost holds: grey #6e6e6e on white goes from %.2f:1 to %.2f:1 under the overlay — text that sat just above the 4.5:1 threshold falls below it', $before, $after )
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 13 client', $t );
	}

	echo "\n[TEST GROUP 14] Retention (wm_nda_retention_days)\n";

	try {
		// Until 2026-09-17 the setting was written, displayed, read back into its own form — and
		// applied by nothing. These pin both halves of the fix: that it acts, and that acting
		// leaves the register verifying, which is why it deletes nothing.
		$nda_seed_rows = static function ( array $rows ) use ( $wpdb ): void {
			$wpdb->tables['wp_wm_nda_acceptances'] = $rows;
		};
		$nda_row = static function ( int $id, string $when, string $name = 'Echter Name', string $agent = 'Mozilla/5.0' ): object {
			return (object) [
				'acceptance_id'        => $id,
				'user_id'              => $id + 10,
				'term_id'              => 1,
				'user_email'           => "u{$id}@example.com",
				'user_name'            => $name,
				'term_hash_at_signing' => 'h',
				'ip_hmac'              => 'signed-and-untouchable',
				'user_agent'           => $agent,
				'accepted_at'          => $when,
				'prev_hash'            => '',
				'signature_hash'       => 's',
			];
		};
		$long_ago = gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) );
		$today    = gmdate( 'Y-m-d H:i:s' );

		// A value the form cannot produce (its minimum is 30) means keep everything — never
		// "pseudonymise everything". Measured with 10, not 0: 0 would also come back as 0 from a
		// version with no floor at all, so the assertion has to fail when the floor is removed.
		$GLOBALS['mock_options']['wm_nda_retention_days'] = 10;
		test_assert( 0 === WM_NDA_Retention::configured_days(), 'A retention below the form minimum is read as keep-everything, not as zero days' );
		$GLOBALS['mock_options']['wm_nda_retention_days'] = 90;
		test_assert( 90 === WM_NDA_Retention::configured_days(), 'A retention inside the accepted range is used as it stands' );

		// With retention off, not one row is touched.
		$GLOBALS['mock_options']['wm_nda_retention_days'] = 0;
		$nda_seed_rows( [ $nda_row( 1, $long_ago ) ] );
		$off = WM_NDA_Retention::apply();
		test_assert(
			'retention_off' === $off['reason'] && 0 === $off['applied'] && 'Echter Name' === $wpdb->tables['wp_wm_nda_acceptances'][0]->user_name,
			'With retention off nothing is changed'
		);

		// The pass itself.
		$GLOBALS['mock_options']['wm_nda_retention_days'] = 90;
		$nda_seed_rows( [ $nda_row( 1, $long_ago ), $nda_row( 2, $today ) ] );

		$dry = WM_NDA_Retention::apply( true );
		test_assert(
			1 === $dry['candidates'] && 0 === $dry['applied'] && 'Echter Name' === $wpdb->tables['wp_wm_nda_acceptances'][0]->user_name,
			'A dry run counts the aged signature and writes nothing'
		);

		$done   = WM_NDA_Retention::apply();
		$aged   = $wpdb->tables['wp_wm_nda_acceptances'][0];
		$recent = $wpdb->tables['wp_wm_nda_acceptances'][1];
		test_assert(
			1 === $done['applied']
				&& WM_NDA_Retention::NEUTRAL_NAME === (string) $aged->user_name
				&& WM_NDA_Retention::NEUTRAL_AGENT === (string) $aged->user_agent
				&& str_contains( (string) $aged->user_email, '@privacy.local' ),
			'The aged signature loses name, address and browser identifier'
		);
		test_assert(
			'Echter Name' === (string) $recent->user_name && 'Mozilla/5.0' === (string) $recent->user_agent,
			'A signature inside the window keeps its own values'
		);
		test_assert( 2 === count( $wpdb->tables['wp_wm_nda_acceptances'] ), 'The pass deletes no row' );

		// THE assertion of this design: ip_hmac is part of the signed payload, so touching it is
		// what made the register report every erased row as tampered until 2026-09-14. The pass
		// must leave it exactly as it was.
		test_assert(
			'signed-and-untouchable' === (string) $aged->ip_hmac,
			'ip_hmac is never rewritten — it is inside the signature, and the signature must keep verifying'
		);

		// Idempotent: a second run finds nothing left to neutralise.
		$second = WM_NDA_Retention::apply();
		test_assert( 0 === $second['candidates'] && 0 === $second['applied'], 'A second pass finds nothing' );

		$GLOBALS['mock_options']['wm_nda_retention_days'] = 90;
		WM_NDA_Database::reset_tables();
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 14', $t );
	}

	// ---------------------------------------------------------
	// 15. V1.2.0 RELEASE READINESS: OPT-IN UNINSTALL, PUBLIC REST RATE LIMIT, BLOCK BINDINGS & GENERATEBLOCKS
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 15] v1.2.0: opt-in uninstall, public REST rate limit, Block Bindings & GenerateBlocks\n";
	try {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		// 1. D-UNINSTALL: default preserves options and tables; explicit opt-in removes options and drops tables.
		wm_nda_fresh_register( 1 );
		$wpdb->dropped = [];
		update_option( 'wm_nda_project_name', 'Alpha-Prototyp' );
		delete_option( 'wm_nda_delete_data_on_uninstall' );
		include $root . '/uninstall.php';
		test_assert(
			'Alpha-Prototyp' === get_option( 'wm_nda_project_name' ) && [] === $wpdb->dropped,
			'D-UNINSTALL: uninstall.php preserves plugin options and NDA tables when wm_nda_delete_data_on_uninstall is off (default)'
		);

		update_option( 'wm_nda_delete_data_on_uninstall', 1 );
		include $root . '/uninstall.php';
		test_assert(
			false === get_option( 'wm_nda_project_name', false )
				&& false === get_option( 'wm_nda_delete_data_on_uninstall', false )
				&& in_array( 'wp_wm_nda_acceptances', $wpdb->dropped, true )
				&& in_array( 'wp_wm_nda_terms', $wpdb->dropped, true ),
			'D-UNINSTALL: uninstall.php deletes plugin options and drops both NDA tables when wm_nda_delete_data_on_uninstall is enabled'
		);
		$wpdb->dropped = [];
		wm_nda_fresh_register( 1 );

		$GLOBALS['mock_registered_settings'] = [];
		( new WM_NDA_Admin() )->register_settings();
		test_assert(
			isset( $GLOBALS['mock_registered_settings']['wm_nda_delete_data_on_uninstall'] )
				&& str_contains( wm_nda_code( 'includes/class-nda-admin.php' ), 'name="wm_nda_delete_data_on_uninstall"' ),
			'D-UNINSTALL: WM_NDA_Admin registers wm_nda_delete_data_on_uninstall and renders its settings checkbox'
		);

		// 2. SEC-02: /verify and /active-terms use check_public_nda_access with salted IP HMAC rate limiting.
		$GLOBALS['mock_rest_routes'] = [];
		$rest15                      = new WM_NDA_REST_API();
		$rest15->register_routes();
		$verify_cb = $GLOBALS['mock_rest_routes']['wm-nda/v1/verify']['permission_callback'] ?? null;
		$terms_cb  = $GLOBALS['mock_rest_routes']['wm-nda/v1/active-terms']['permission_callback'] ?? null;
		test_assert(
			is_array( $verify_cb ) && 'check_public_nda_access' === ( $verify_cb[1] ?? '' )
				&& is_array( $terms_cb ) && 'check_public_nda_access' === ( $terms_cb[1] ?? '' ),
			'SEC-02: /verify and /active-terms register check_public_nda_access instead of __return_true'
		);

		$GLOBALS['mock_transients']                  = [];
		$GLOBALS['mock_user_caps']                   = [ 'manage_options' => false ];
		$GLOBALS['mock_filters']['wm_nda_rest_rate_limit'] = [ static fn(): int => 2 ];
		$_SERVER['REMOTE_ADDR']                      = '198.51.100.88';
		$pub_req                                     = new WP_REST_Request( 'GET', '/wm-nda/v1/active-terms' );
		$pub_1                                       = $rest15->check_public_nda_access( $pub_req );
		$pub_2                                       = $rest15->check_public_nda_access( $pub_req );
		$pub_3                                       = $rest15->check_public_nda_access( $pub_req );
		$pub_keys                                    = array_keys( $GLOBALS['mock_transients'] );
		test_assert(
			true === $pub_1
				&& true === $pub_2
				&& is_wp_error( $pub_3 )
				&& 'wm_nda_rate_limited' === $pub_3->get_error_code()
				&& 429 === ( $pub_3->get_error_data()['status'] ?? 0 )
				&& 1 === count( $pub_keys )
				&& str_starts_with( $pub_keys[0], 'wm_nda_rl_pub_' )
				&& ! str_contains( $pub_keys[0], '198.51.100.88' ),
			'SEC-02: check_public_nda_access enforces wm_nda_rest_rate_limit with 429 and stores only the salted IP HMAC'
		);

		$GLOBALS['mock_user_caps'] = [ 'manage_options' => true ];
		$pub_admin                 = $rest15->check_public_nda_access( $pub_req );
		test_assert( true === $pub_admin, 'SEC-02: administrators with manage_options bypass the public REST rate limit' );
		$GLOBALS['mock_user_caps'] = [];
		unset( $GLOBALS['mock_filters']['wm_nda_rest_rate_limit'] );

		// 3. Ecosystem bridges: WP 6.5+ Block Bindings (`wm-nda/status`) and GenerateBlocks (`wm_nda_status`).
		$GLOBALS['mock_block_bindings'] = [];
		$GLOBALS['mock_gb_tags']        = [];
		$ref_gb_flag                    = new ReflectionProperty( WM_NDA_Core::class, 'gb_tag_registered' );
		$ref_gb_flag->setValue( null, false );
		WM_NDA_Core::register_block_bindings();
		WM_NDA_Core::register_generateblocks_tags();

		test_assert(
			isset( $GLOBALS['mock_block_bindings']['wm-nda/status'] )
				&& is_callable( $GLOBALS['mock_block_bindings']['wm-nda/status']['get_value_callback'] ?? null ),
			'Ecosystem: WP 6.5+ Block Bindings source wm-nda/status is registered'
		);
		test_assert(
			isset( $GLOBALS['mock_gb_tags']['wm_nda_status'] )
				&& is_callable( $GLOBALS['mock_gb_tags']['wm_nda_status']['return'] ?? null ),
			'Ecosystem: GenerateBlocks dynamic tag wm_nda_status is registered'
		);

		$bb_cb = $GLOBALS['mock_block_bindings']['wm-nda/status']['get_value_callback'];
		$gb_cb = $GLOBALS['mock_gb_tags']['wm_nda_status']['return'];

		$GLOBALS['mock_logged_in']       = true;
		$GLOBALS['mock_current_user_id'] = 101; // Signed in wm_nda_fresh_register( 1 ).
		test_assert(
			'signed' === $bb_cb( [ 'field' => 'state' ] )
				&& str_contains( (string) $bb_cb( [ 'field' => 'label' ] ), '1.0.0' )
				&& '1.0.0' === $gb_cb( [ 'key' => 'active_version' ], [], null ),
			'Ecosystem: Block Bindings and GenerateBlocks resolve signed status and active version'
		);

		$GLOBALS['mock_current_user_id'] = 3; // Unsigned user.
		test_assert( 'pending' === $gb_cb( [ 'key' => 'state' ], [], null ), 'Ecosystem: unsigned user resolves to pending state' );

		$GLOBALS['mock_logged_in'] = false;
		test_assert( 'logged_out' === $bb_cb( [ 'field' => 'state' ] ), 'Ecosystem: anonymous visitor resolves to logged_out state' );

		$GLOBALS['mock_filters']['wm_nda_status_value'] = [ static fn( string $val, string $field ): string => "filtered:{$field}:{$val}" ];
		test_assert(
			str_starts_with( WM_NDA_Core::resolve_status_value( 'state' ), 'filtered:state:' ),
			'Ecosystem: wm_nda_status_value filter customises dynamic status output'
		);
		unset( $GLOBALS['mock_filters']['wm_nda_status_value'] );
		$GLOBALS['mock_logged_in']       = true;
		$GLOBALS['mock_current_user_id'] = 1;
	} catch ( Throwable $t ) {
		$GLOBALS['mock_user_caps'] = [];
		unset( $GLOBALS['mock_filters']['wm_nda_rest_rate_limit'], $GLOBALS['mock_filters']['wm_nda_status_value'] );
		wm_nda_crashed( 'Group 15', $t );
	}

	// ---------------------------------------------------------
	// 16. R2-B8: MULTISITE PROVISIONING (wp_initialize_site) & NON-FRONTEND AUTOLOAD = FALSE
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 16] R2-B8: Multisite wp_initialize_site & non-frontend autoload = false\n";
	try {
		$activation_cb = $GLOBALS['mock_activation_hooks'][ WM_NDA_GATE_FILE ] ?? null;
		$init_hooks    = array_values(
			array_filter(
				$GLOBALS['mock_boot_hooks'],
				static fn( array $h ): bool => 'wp_initialize_site' === $h['hook']
			)
		);
		$init_site_cb = $init_hooks[0]['callback'] ?? null;

		test_assert(
			is_callable( $activation_cb ) && 'wm_nda_activate_plugin' === $activation_cb,
			'R2-B8: register_activation_hook binds wm_nda_activate_plugin'
		);
		test_assert(
			1 === count( $init_hooks ) && is_callable( $init_site_cb ) && 'wm_nda_initialize_site' === $init_site_cb,
			'R2-B8: wp_initialize_site hook binds wm_nda_initialize_site'
		);

		// 1. Single-site activation provisioning & autoload = false on non-frontend options.
		$GLOBALS['mock_is_multisite']           = false;
		$GLOBALS['mock_network_active_plugins'] = [];
		$GLOBALS['mock_current_blog_id']        = 1;
		$GLOBALS['mock_blog_stack']             = [];
		$GLOBALS['mock_options']                = [];
		$GLOBALS['mock_site_options']           = [];
		$GLOBALS['mock_transients']             = [];
		$GLOBALS['mock_site_transients']        = [];
		$GLOBALS['mock_option_autoloads']       = [];
		$GLOBALS['mock_dbdelta_queries']        = [];
		$GLOBALS['mock_dbdelta_throw']          = null;
		$wpdb->prefix                           = 'wp_';
		$wpdb->tables                           = [];
		$wpdb->auto_inc                         = [];
		$wpdb->dropped                          = [];

		$activation_cb( false );
		$active_after_activate = WM_NDA_Database::get_active_term();
		test_assert(
			null !== $active_after_activate
				&& '1.0.0' === $active_after_activate->term_version
				&& isset( $wpdb->tables['wp_wm_nda_terms'], $wpdb->tables['wp_wm_nda_acceptances'] ),
			'R2-B8: single-site activation provisions tables and default term 1.0.0'
		);
		test_assert(
			WM_NDA_Database::SCHEMA_VERSION === get_option( 'wm_nda_schema_version' )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_schema_version'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_retention_days'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_delete_data_on_uninstall'] ?? null ),
			'R2-B8: activation sets autoload=false on wm_nda_schema_version, wm_nda_retention_days, wm_nda_delete_data_on_uninstall'
		);
		test_assert(
			true === ( $GLOBALS['mock_option_autoloads'][1][ WM_NDA_Database::CACHE_SALT_OPTION ] ?? null )
				&& null === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_enforce_frontend_gate'] ?? null ),
			'R2-B8: activation keeps autoload=true on wm_nda_cache_salt and leaves frontend gate options intact'
		);

		// 2. Schema upgrade path sets autoload=false on wm_nda_schema_version.
		$GLOBALS['mock_options']['wm_nda_schema_version'] = '3';
		unset( $GLOBALS['mock_option_autoloads'][1]['wm_nda_schema_version'] );
		wm_nda_maybe_upgrade_schema();
		test_assert(
			WM_NDA_Database::SCHEMA_VERSION === get_option( 'wm_nda_schema_version' )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_schema_version'] ?? null ),
			'R2-B8: schema upgrade writes wm_nda_schema_version with autoload=false'
		);

		// 3. Factory reset sets autoload=false on non-frontend options and preserves cache salt autoload.
		$GLOBALS['mock_option_autoloads'][1] = [];
		WM_NDA_Seeder::reset_to_factory_defaults();
		test_assert(
			false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_retention_days'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_delete_data_on_uninstall'] ?? null )
				&& true === ( $GLOBALS['mock_option_autoloads'][1][ WM_NDA_Database::CACHE_SALT_OPTION ] ?? null )
				&& null === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_enforce_frontend_gate'] ?? null ),
			'R2-B8: factory reset sets autoload=false on retention/uninstall options while keeping cache salt and frontend options intact'
		);

		// 4. Admin settings save sets autoload=false on retention/uninstall options while keeping frontend gate options intact.
		$GLOBALS['mock_option_autoloads'][1] = [];
		$_POST                               = [
			'wm_nda_save_settings'           => '1',
			'wm_nda_require_login_checkbox'  => '1',
			'wm_nda_enforce_admin_gate'      => '1',
			'wm_nda_enforce_frontend_gate'   => '1',
			'wm_nda_enforce_rest_api'        => '1',
			'wm_nda_project_name'            => 'Autoload Test',
			'wm_nda_retention_days'          => '120',
			'wm_nda_delete_data_on_uninstall' => '1',
		];
		$save_outcome = wm_nda_run_gate( static fn() => ( new WM_NDA_Admin() )->handle_form_submissions() );
		$_POST        = [];
		test_assert(
			'redirect' === $save_outcome
				&& 120 === get_option( 'wm_nda_retention_days' )
				&& 1 === get_option( 'wm_nda_delete_data_on_uninstall' )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_retention_days'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_delete_data_on_uninstall'] ?? null )
				&& null === ( $GLOBALS['mock_option_autoloads'][1]['wm_nda_enforce_frontend_gate'] ?? null ),
			'R2-B8: admin settings save sets autoload=false on wm_nda_retention_days and wm_nda_delete_data_on_uninstall only'
		);

		// 5. wp_initialize_site guards: no-op when single-site or when not network-activated.
		$GLOBALS['mock_is_multisite']           = false;
		$GLOBALS['mock_network_active_plugins'] = [ plugin_basename( WM_NDA_GATE_FILE ) ];
		$init_site_cb( new WP_Site( 2 ) );
		test_assert(
			! isset( $wpdb->tables['wp_2_wm_nda_terms'] ) && ! isset( $GLOBALS['mock_site_options'][2] ),
			'R2-B8: wp_initialize_site does nothing when is_multisite() is false'
		);

		$GLOBALS['mock_is_multisite']           = true;
		$GLOBALS['mock_network_active_plugins'] = [];
		$init_site_cb( new WP_Site( 2 ) );
		test_assert(
			! isset( $wpdb->tables['wp_2_wm_nda_terms'] ) && ! isset( $GLOBALS['mock_site_options'][2] ),
			'R2-B8: wp_initialize_site does nothing when plugin is not network-activated'
		);

		// 6. wp_initialize_site provisions new blog when network-activated and restores current blog.
		$GLOBALS['mock_network_active_plugins'] = [ plugin_basename( WM_NDA_GATE_FILE ) ];
		$init_site_cb( new WP_Site( 2 ) );
		$site2_opts = $GLOBALS['mock_site_options'][2] ?? [];
		test_assert(
			isset( $wpdb->tables['wp_2_wm_nda_terms'], $wpdb->tables['wp_2_wm_nda_acceptances'] )
				&& 1 === count( $wpdb->tables['wp_2_wm_nda_terms'] )
				&& '1.0.0' === ( $wpdb->tables['wp_2_wm_nda_terms'][0]->term_version ?? '' ),
			'R2-B8: wp_initialize_site creates tables and seeds default term on the new blog'
		);
		test_assert(
			WM_NDA_Database::SCHEMA_VERSION === ( $site2_opts['wm_nda_schema_version'] ?? null )
				&& '' !== (string) ( $site2_opts[ WM_NDA_Database::CACHE_SALT_OPTION ] ?? '' )
				&& 1 === ( $site2_opts['wm_nda_enforce_frontend_gate'] ?? null )
				&& 90 === ( $site2_opts['wm_nda_retention_days'] ?? null )
				&& 0 === ( $site2_opts['wm_nda_delete_data_on_uninstall'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][2]['wm_nda_schema_version'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][2]['wm_nda_retention_days'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][2]['wm_nda_delete_data_on_uninstall'] ?? null )
				&& true === ( $GLOBALS['mock_option_autoloads'][2][ WM_NDA_Database::CACHE_SALT_OPTION ] ?? null ),
			'R2-B8: wp_initialize_site seeds blog 2 options with autoload=false on non-frontend keys and autoload=true on cache salt'
		);
		test_assert(
			1 === $GLOBALS['mock_current_blog_id'] && 'wp_' === $wpdb->prefix && [] === $GLOBALS['mock_blog_stack'],
			'R2-B8: wp_initialize_site restores the original blog context after provisioning'
		);

		// 7. Exception safety: restore_current_blog() still executes in finally when provisioning throws.
		$caught_init_error           = false;
		$GLOBALS['mock_dbdelta_throw'] = static function (): void {
			throw new RuntimeException( 'simulated dbDelta failure' );
		};
		try {
			$init_site_cb( new WP_Site( 3 ) );
		} catch ( RuntimeException $e ) {
			$caught_init_error = ( 'simulated dbDelta failure' === $e->getMessage() );
		} finally {
			$GLOBALS['mock_dbdelta_throw'] = null;
		}
		test_assert(
			$caught_init_error && 1 === $GLOBALS['mock_current_blog_id'] && 'wp_' === $wpdb->prefix && [] === $GLOBALS['mock_blog_stack'],
			'R2-B8: restore_current_blog() runs in finally even when provisioning throws'
		);

		// 8. Network-wide activation provisions every site in get_sites() and restores blog context.
		$GLOBALS['mock_sites'] = [ 1, 4 ];
		$activation_cb( true );
		$site4_opts = $GLOBALS['mock_site_options'][4] ?? [];
		test_assert(
			isset( $wpdb->tables['wp_4_wm_nda_terms'], $wpdb->tables['wp_4_wm_nda_acceptances'] )
				&& 1 === count( $wpdb->tables['wp_4_wm_nda_terms'] )
				&& WM_NDA_Database::SCHEMA_VERSION === ( $site4_opts['wm_nda_schema_version'] ?? null )
				&& false === ( $GLOBALS['mock_option_autoloads'][4]['wm_nda_retention_days'] ?? null )
				&& 1 === $GLOBALS['mock_current_blog_id']
				&& 'wp_' === $wpdb->prefix,
			'R2-B8: network-wide activation provisions all sites in get_sites() and restores blog context'
		);

		$GLOBALS['mock_is_multisite']           = false;
		$GLOBALS['mock_network_active_plugins'] = [];
		$GLOBALS['mock_sites']                  = [ 1 ];
		$GLOBALS['mock_current_blog_id']        = 1;
		$GLOBALS['mock_blog_stack']             = [];
		$GLOBALS['mock_cron']                   = [];
		$wpdb->prefix                           = 'wp_';
	} catch ( Throwable $t ) {
		$GLOBALS['mock_dbdelta_throw']          = null;
		$GLOBALS['mock_is_multisite']           = false;
		$GLOBALS['mock_network_active_plugins'] = [];
		$GLOBALS['mock_sites']                  = [ 1 ];
		$GLOBALS['mock_current_blog_id']        = 1;
		$GLOBALS['mock_blog_stack']             = [];
		$GLOBALS['mock_cron']                   = [];
		$_POST                                  = [];
		$wpdb->prefix                           = 'wp_';
		wm_nda_crashed( 'Group 16', $t );
	}

	// ---------------------------------------------------------
	// 17. SHORTENED HASHES IN THE ADMIN TABLES (2026-11-21)
	// The tables are table-layout: fixed and WordPress gives every .widefat descendant word-wrap:
	// break-word: a column narrower than "8dfac83423be6a76..." printed the dots on a second line
	// (versions screen at 1440 px; IP-HMAC on the signatures screen; the cockpit at 1280 px).
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 17] Shortened hashes in the admin tables\n";

	try {
		$hash_root  = dirname( __DIR__ );
		$admin_code = wm_nda_code( 'includes/class-nda-admin.php' );
		$admin_css  = (string) file_get_contents( $hash_root . '/assets/css/nda-admin.css' );
		$sample     = hash( 'sha256', 'shortened-hash-sample' );

		test_assert(
			substr( $sample, 0, 16 ) . '…' === WM_NDA_Admin::short_hash( $sample ) && substr( $sample, 0, 20 ) . '…' === WM_NDA_Admin::short_hash( $sample, 20 ),
			'short_hash(): the prefix and one ellipsis character (was: "..." printed after the code element)'
		);
		test_assert(
			4 === substr_count( $admin_code, '<code class="wm-nda-hash"><?php echo esc_html( self::short_hash( ' )
				&& ! str_contains( $admin_code, '...</code>' )
				&& ! preg_match( '/substr\(\s*\$(?:acc|t)->(?:ip_hmac|signature_hash|term_hash)/', $admin_code ),
			'Every shortened hash of the cockpit, the signatures and the versions screen is one <code class="wm-nda-hash"> from short_hash()'
		);

		preg_match( '/\.wm-nda-admin-wrap code\.wm-nda-hash\s*\{([^}]*)\}/', $admin_css, $hash_rule );
		$hash_decl = (string) preg_replace( '/\s+/', ' ', $hash_rule[1] ?? '' );
		test_assert(
			str_contains( $hash_decl, 'white-space: nowrap;' ) && str_contains( $hash_decl, 'display: inline-block;' ) && str_contains( $hash_decl, 'max-width: 100%;' ),
			'nda-admin.css keeps a shortened hash on one line inside its cell'
		);
		test_assert(
			str_contains( $hash_decl, 'overflow: hidden;' ) && str_contains( $hash_decl, 'text-overflow: ellipsis;' ),
			'nda-admin.css clips a shortened hash with an ellipsis where its column is narrower, instead of drawing it into the next column'
		);

		preg_match( '/\.wm-nda-admin-wrap \.wm-nda-col-version\s*\{([^}]*)\}/', $admin_css, $col_rule );
		test_assert(
			str_contains( $admin_code, '<th class="wm-nda-col-version">' ) && 1 === preg_match( '/width:\s*\d/', $col_rule[1] ?? '' ),
			'Versions table: the version column has its own width, so title and hash share what five equal columns left half empty'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 17', $t );
	}

	// ---------------------------------------------------------
	// 18. TEXT IN NARROW ADMIN COLUMNS (2026-11-21)
	// Same tables, narrower screens: at 1280 px e-mail addresses broke anywhere ("e.rostova@exa" /
	// "mple.com") and "Interaktionsnachweis" inside the word; at 1100 px the versions table kept
	// 105 px per column beside the form, so the status pill spilled into the next column.
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 18] Text in narrow admin columns\n";

	try {
		$narrow_code = wm_nda_code( 'includes/class-nda-admin.php' );
		$narrow_css  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/nda-admin.css' );

		test_assert(
			'e.rostova@<wbr>example.com' === WM_NDA_Admin::breakable_email( 'e.rostova@example.com' )
				&& '&lt;b&gt;@<wbr>x' === WM_NDA_Admin::breakable_email( '<b>@x' ),
			'breakable_email(): the address escaped, with a line-break opportunity right after the @'
		);
		test_assert(
			2 === substr_count( $narrow_code, "<td><?php echo wp_kses( self::breakable_email( \$acc->user_email ), [ 'wbr' => [] ] ); ?></td>" )
				&& ! str_contains( $narrow_code, 'esc_html( $acc->user_email )' ),
			'Cockpit and signatures print the e-mail through breakable_email(), with wp_kses() letting only <wbr> through'
		);

		preg_match( '/\.wm-nda-admin-wrap \.widefat thead th,\s*\.wm-nda-admin-wrap \.widefat \.wm-nda-hyphenate\s*\{([^}]*)\}/', $narrow_css, $hyphen_rule );
		$hyphen_decl = $hyphen_rule[1] ?? '';
		test_assert(
			1 === preg_match( '/(?<![\w-])hyphens:\s*auto;/', $hyphen_decl ) && str_contains( $hyphen_decl, 'hyphenate-limit-chars:' )
				&& str_contains( $hyphen_decl, '-webkit-hyphenate-limit-before: 4;' ) && str_contains( $hyphen_decl, '-webkit-hyphenate-limit-after: 4;' ),
			'nda-admin.css hyphenates headers and .wm-nda-hyphenate cells at a syllable instead of breaking inside the word, no fragment under four letters in Chromium, Firefox and Safari'
		);
		test_assert(
			2 === substr_count( $narrow_code, '<td class="wm-nda-hyphenate">' )
				&& str_contains( $narrow_code, '<td class="wm-nda-hyphenate"><?php echo esc_html( $t->term_title ); ?></td>' ),
			'The version title and the interaction summary are the hyphenated cells (names and e-mail addresses are not)'
		);
		test_assert(
			1 === preg_match( '/@media \(max-width: 1280px\)\s*\{\s*\.wm-nda-columns:has\(\.wm-nda-col-side\)\s*\{\s*grid-template-columns: 1fr;/', $narrow_css ),
			'Versions screen: the form moves below the table from 1280 px down, where two thirds of the page no longer hold the table'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 18', $t );
	}

	// ---------------------------------------------------------
	// 19. LEGAL TEXTS NEUTRALISED (Arnold, 21.11.2026)
	// Until 1.3.2 the default agreement cited GeschGehG, UrhG and DSGVO and named "WM Suite" as the
	// owner, the checkbox and the accept button were "verbindlich", the modal promised a "rechtlich
	// verbindliche Anerkennung", the eraser and the retention texts cited Art. 17 Abs. 3 lit. e DSGVO
	// as permission, and the versions screen called the active version "Gültig". The plugin records
	// clicks; it does not decide what they are worth in law (rule 6 of CLAUDE.md).
	// ---------------------------------------------------------
	echo "\n[TEST GROUP 19] Legal texts neutralised\n";

	try {
		$legal_root = dirname( __DIR__ );
		$checkbox   = WM_NDA_I18n::get_login_checkbox_label();
		$accept     = WM_NDA_I18n::get_accept_button_text();
		test_assert(
			'Ich habe die Vertraulichkeitsvereinbarung (NDA) für dieses Prototyp-System gelesen und stimme ihr zu.' === $checkbox
				&& 'NDA akzeptieren & fortfahren' === $accept,
			'Checkbox and accept button state what the user does, without "verbindlich" or announced legal steps'
		);

		// An explicit project name: the stubbed blog name contains "WM Suite", which the text may not name.
		$default_text = WM_NDA_I18n::get_default_legal_content( 'Projekt Nord' );
		test_assert(
			! preg_match( '/GeschGehG|UrhG|DSGVO|§|WM Suite|datenschutzkonform|revisionssicher|strafrechtlich/u', $default_text ),
			'The default agreement cites no statute, names no vendor and claims no compliance'
		);
		test_assert(
			str_contains( $default_text, '<strong>Projekt Nord</strong>' )
				&& str_contains( $default_text, 'Gesetzlich zwingende Rechte bleiben unberührt.' )
				&& str_contains( $default_text, 'Benutzer-ID, E-Mail-Adresse, Anzeigename, User-Agent, Zeitpunkt (UTC), der Prüfsumme dieses Textes, einem Pseudonym Ihrer IP-Adresse und dem Interaktionsnachweis' ),
			'The default agreement names the project, keeps mandatory rights and lists every field the register stores'
		);

		$modal_code = wm_nda_code( 'includes/class-nda-modal.php' );
		test_assert(
			str_contains( $modal_code, 'Gespeichert werden Benutzer-ID, E-Mail-Adresse, Anzeigename, User-Agent, Zeitpunkt, die Prüfsumme des Textes, ein Pseudonym Ihrer IP-Adresse und der Interaktionsnachweis.' )
				&& ! preg_match( '/GeschGehG|UrhG|verbindlich/u', $modal_code ),
			'The modal notice says what the click stores instead of citing § 2 GeschGehG and § 69e UrhG'
		);

		$exporter_code = wm_nda_code( 'includes/class-nda-exporter.php' );
		test_assert(
			! str_contains( $exporter_code, 'Art. 17' ) && str_contains( $exporter_code, "'Beibehalten, weil sie Teil der verketteten Signatur sind: Konto-ID" ),
			'The eraser gives the chained signature as the reason for what it keeps and cites no Art. 17 as permission'
		);

		$legal_admin = wm_nda_code( 'includes/class-nda-admin.php' );
		test_assert(
			str_contains( $legal_admin, "esc_html_e( 'Aktiv', 'wm-prototype-nda-gate' )" )
				&& str_contains( $legal_admin, "esc_html_e( 'Version aktivieren', 'wm-prototype-nda-gate' )" )
				&& ! preg_match( '/Gültig|verbindlich|Art\. 17/u', $legal_admin ),
			'Versions screen and settings: "Aktiv", "Version aktivieren", and no Art. 17 in the retention field'
		);
		test_assert(
			str_contains( $legal_admin, 'Der mitgelieferte Text ist ein Muster ohne juristische Prüfung und nennt keine Vorschriften.' )
				&& ! preg_match( '/GeschGehG|UrhG|DSGVO Art\. 6/u', $legal_admin ),
			'Handbook card 2 calls the default text an unreviewed sample instead of listing the statutes the old text cited'
		);

		$cli_raw       = (string) file_get_contents( $legal_root . '/includes/class-nda-cli.php' );
		$retention_raw = (string) file_get_contents( $legal_root . '/includes/class-nda-retention.php' );
		test_assert(
			! str_contains( $cli_raw, 'Art. 17' ) && ! str_contains( $retention_raw, 'Art. 17' ) && ! str_contains( wm_nda_code( 'includes/class-nda-cli.php' ), 'Gültig' ),
			'"wp help nda retention" (the method docblock) and the retention class cite no Art. 17; "wp nda status" prints "Aktiv seit", not "Gültig seit"'
		);

		$stale_docs = [];
		foreach ( [ 'README.md', 'README.de.md', 'README.es.md', 'DEVELOPERS.md' ] as $legal_doc ) {
			if ( preg_match( "/Arnold's review|awaiting Arnold|Arnolds Prüfung|revisión por Arnold/u", (string) file_get_contents( $legal_root . '/' . $legal_doc ) ) ) {
				$stale_docs[] = $legal_doc;
			}
		}
		test_assert( [] === $stale_docs, 'No document still says the legal texts wait for a review (stale: ' . implode( ', ', $stale_docs ) . ')' );

		// Extended on 22.11.2026 (release 1.3.4). Until then the help tab and the handbook said a new version
		// declares every earlier acceptance invalid (they stay in the register and keep verifying), the export
		// page, the handbook and the JSON download spoke of certificates, docs/DSGVO-COMPLIANCE.md listed
		// possible legal bases, the READMEs had "Compliance" headings and reserved all rights next to the GPL,
		// readme.txt was tagged compliance, gdpr and dsgvo, and "wp nda demodaten --reset" refused --yes.
		test_assert(
			str_contains( $legal_admin, 'Die bisherigen Bestätigungen bleiben mit ihrer Fassung im Register und lassen sich weiter prüfen.' )
				&& str_contains( $legal_admin, 'die bisherigen Bestätigungen bleiben mit ihrer Fassung im Register.' )
				&& ! preg_match( '/ungültig|Zertifikat|DSGVO|gdpr/iu', $legal_admin ),
			'Help tab and handbook: earlier acceptances stay in the register with their version; no "ungültig", no certificate, no DSGVO label'
		);

		$rest_code = wm_nda_code( 'includes/class-nda-rest-api.php' );
		test_assert(
			str_contains( $rest_code, "'Die angeforderte NDA-Version ist nicht die aktive Fassung.'" ) && ! str_contains( $rest_code, 'ungültig' ),
			'POST /accept for another version says it is not the active one instead of calling it invalid'
		);

		test_assert(
			str_contains( $exporter_code, "'wm-nda-audit-register-'" ) && ! str_contains( $exporter_code, 'audit-certificate' ),
			'The JSON download is named an export of the register, not a certificate'
		);

		$data_doc = is_readable( $legal_root . '/docs/DATA-PROCESSING.md' ) ? (string) file_get_contents( $legal_root . '/docs/DATA-PROCESSING.md' ) : '';
		test_assert(
			! file_exists( $legal_root . '/docs/DSGVO-COMPLIANCE.md' )
				&& str_contains( $data_doc, '`wm_nda_retention_days`' )
				&& ! preg_match( '/Art\. ?\d|lit\. [a-f]\b|Mögliche Rechtsgrundlagen|Arnolds Prüfung|ungeprüft|DSGVO/u', $data_doc ),
			'docs/DATA-PROCESSING.md (until 1.3.3 DSGVO-COMPLIANCE.md) describes the processing and names no legal basis'
		);

		$readme_paths = [ 'README.md', 'README.de.md', 'README.es.md' ];
		foreach ( glob( $legal_root . '/docs/translations/README.*.md' ) ?: [] as $translated_readme ) {
			$readme_paths[] = 'docs/translations/' . basename( $translated_readme );
		}
		$stale_readmes = [];
		foreach ( $readme_paths as $readme_path ) {
			$readme_text = (string) file_get_contents( $legal_root . '/' . $readme_path );
			if (
				preg_match( '/^#+ .*(Compliance|Konformität|Cumplimiento|⚖)/mu', $readme_text )
				|| preg_match( '/PHP-8\.1|Rechte vorbehalten|derechos reservados|rights reserved/u', $readme_text )
				|| ! str_contains( $readme_text, 'DATA-PROCESSING.md' )
				|| ! str_contains( $readme_text, '--reset [--yes]' )
			) {
				$stale_readmes[] = $readme_path;
			}
		}
		test_assert(
			27 === count( $readme_paths ) && [] === $stale_readmes,
			'27 READMEs: no "Compliance" heading, no PHP 8.1 badge, no reserved rights next to the GPL, a link to DATA-PROCESSING.md and --yes in the CLI line (stale: ' . implode( ', ', $stale_readmes ) . ')'
		);

		test_assert(
			str_contains( $cli_raw, '* [--yes]' ) && str_contains( wm_nda_code( 'includes/class-nda-cli.php' ), "zurücksetzen?', \$assoc_args );" ) && ! preg_match( '/compliance/i', $cli_raw ),
			'"wp nda demodaten --reset --yes" is declared and reaches WP_CLI::confirm(); "wp help nda" claims no compliance'
		);

		$readme_txt = (string) file_get_contents( $legal_root . '/readme.txt' );
		test_assert(
			1 === preg_match( '/^Tags: (.+)$/m', $readme_txt, $tag_line ) && ! preg_match( '/compliance|gdpr|dsgvo/i', $tag_line[1] ),
			'readme.txt tags name what the plugin does, not compliance, GDPR or DSGVO'
		);
	} catch ( Throwable $t ) {
		wm_nda_crashed( 'Group 19', $t );
	}

	echo "\n===============================================================\n";
	echo "  TEST SUITE RESULTS: {$passed} PASSED | {$failed} FAILED\n";
	echo "===============================================================\n\n";

	exit( $failed > 0 ? 1 : 0 );
}

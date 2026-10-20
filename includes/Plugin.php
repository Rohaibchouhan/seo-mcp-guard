<?php
declare(strict_types=1);

namespace SEOMCPGuard;

/**
 * Main Plugin singleton class for SEO MCP Guard.
 *
 * Bootstraps all subsystems: settings, auth, routing, safety engine, audit log.
 * Handles uninstall cleanup when the user requests data deletion.
 */
class Plugin {

	/** @var static|null Singleton instance */
	private static ?self $instance = null;

	/** @var Settings Plugin settings manager */
	private Settings $settings;

	/** @var Auth Authentication handler */
	private Auth $auth;

	/** @var MCPRouter MCP REST router */
	private MCPRouter $router;

	/** @var SafetyEngine Content safety / rollback engine */
	private SafetyEngine $safety;

	/** @var AuditLog Structured audit logger */
	private AuditLog $audit_log;

	/** Prevent direct instantiation. */
	private function __construct() {}

	/** Prevent cloning. */
	private function __clone() {}

	/** Prevent unserialization. */
	public function __wakeup(): void {
		throw new \RuntimeException( 'SEO MCP Guard Plugin instance cannot be unserialized.' );
	}

	/**
	 * Returns the single plugin instance, creating it on first call.
	 */
	public static function instance(): static {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Boot all plugin subsystems.
	 * Called on the `plugins_loaded` hook (or equivalent entry point).
	 */
	public function boot(): void {
		$this->init_settings();
		$this->init_components();

		if ( is_admin() ) {
			$this->init_admin();
		}

		$this->register_hooks();
	}

	// -------------------------------------------------------------------------
	// Private boot helpers
	// -------------------------------------------------------------------------

	/**
	 * Instantiate the Settings object and load persisted options.
	 */
	private function init_settings(): void {
		$this->settings = new Settings();
	}

	/**
	 * Instantiate core components that are always needed (admin or front-end).
	 */
	private function init_components(): void {
		$this->auth      = new Auth( $this->settings );
		$this->audit_log = new AuditLog();
		$this->safety    = new SafetyEngine( $this->settings, $this->audit_log );
		$this->router    = new MCPRouter(
			$this->settings,
			$this->auth,
			$this->safety,
			$this->audit_log
		);
	}

	/**
	 * Initialise admin-only classes (settings pages, meta boxes, etc.).
	 */
	private function init_admin(): void {
		// Admin class is responsible for hooking into `admin_menu`, `admin_init`, etc.
		$admin = new Admin( $this->settings );
		$admin->boot();
	}

	/**
	 * Register WordPress hooks for routing, cron, and textdomain.
	 */
	private function register_hooks(): void {
		// Load plugin text domain for i18n.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Register MCP REST routes.
		add_action( 'rest_api_init', [ $this->router, 'register_routes' ] );

		// Schedule the daily audit-log cleanup cron if not already scheduled.
		add_action( 'init', [ $this, 'maybe_schedule_cron' ] );

		// Hook for the cron event itself.
		add_action( 'seo_mcp_guard_cleanup', [ $this->audit_log, 'prune_old_records' ] );
	}

	// -------------------------------------------------------------------------
	// Public action callbacks
	// -------------------------------------------------------------------------

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'seo-mcp-guard',
			false,
			dirname( plugin_basename( SEO_MCP_GUARD_FILE ) ) . '/languages/'
		);
	}

	/**
	 * Register the daily cleanup cron event if it is not already scheduled.
	 */
	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( 'seo_mcp_guard_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'seo_mcp_guard_cleanup' );
		}
	}

	// -------------------------------------------------------------------------
	// Getters – allow other plugin code to access subsystems cleanly
	// -------------------------------------------------------------------------

	public function settings(): Settings {
		return $this->settings;
	}

	public function auth(): Auth {
		return $this->auth;
	}

	public function router(): MCPRouter {
		return $this->router;
	}

	public function safety(): SafetyEngine {
		return $this->safety;
	}

	public function audit_log(): AuditLog {
		return $this->audit_log;
	}

	// -------------------------------------------------------------------------
	// Static uninstall handler (registered via register_uninstall_hook)
	// -------------------------------------------------------------------------

	/**
	 * Runs when the plugin is deleted from the WordPress admin.
	 *
	 * Only wipes data when the user has explicitly set `delete_data_on_uninstall`.
	 */
	public static function uninstall(): void {
		// We need a fresh Settings instance because the plugin is not booted
		// during an uninstall call – WordPress calls this statically.
		$settings = new Settings();

		if ( ! $settings->get( 'delete_data_on_uninstall', false ) ) {
			return;
		}

		global $wpdb;

		// ------------------------------------------------------------------
		// 1. Drop custom tables.
		// ------------------------------------------------------------------
		$tables = [
			$wpdb->prefix . 'seo_mcp_guard_changes',
			$wpdb->prefix . 'seo_mcp_guard_audit',
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		// ------------------------------------------------------------------
		// 2. Delete all options whose names start with 'seo_mcp_guard_'.
		// ------------------------------------------------------------------
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'seo_mcp_guard_' ) . '%'
			)
		);

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}

		// ------------------------------------------------------------------
		// 3. Delete all transients whose keys begin with 'seo_mcp_guard_'.
		// ------------------------------------------------------------------
		// WordPress stores transients in the options table as
		// _transient_{key} and _transient_timeout_{key}.
		$transient_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_seo_mcp_guard_' ) . '%'
			)
		);

		foreach ( $transient_rows as $option_name ) {
			// Strip the '_transient_timeout_' or '_transient_' prefix to get the bare key.
			$transient_key = preg_replace( '/^_transient_timeout_/', '', (string) $option_name );
			$transient_key = preg_replace( '/^_transient_/', '', $transient_key );
			delete_transient( $transient_key );
		}

		// ------------------------------------------------------------------
		// 4. Clear any scheduled cron events added by this plugin.
		// ------------------------------------------------------------------
		wp_clear_scheduled_hook( 'seo_mcp_guard_cleanup' );
	}
}

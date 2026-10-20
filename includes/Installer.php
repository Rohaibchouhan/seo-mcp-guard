<?php
declare(strict_types=1);

namespace SEOMCPGuard;

/**
 * Handles plugin activation and deactivation routines for SEO MCP Guard.
 *
 * Creates (or upgrades) the two custom database tables on activation,
 * stores the current DB schema version, and cleans up scheduled events
 * on deactivation.
 */
class Installer {

	/**
	 * Current database schema version.
	 * Bump this whenever the table structure changes so existing installs upgrade.
	 */
	private const DB_VERSION     = '1.0.0';
	private const DB_VERSION_KEY = 'seo_mcp_guard_db_version';

	// -------------------------------------------------------------------------
	// Activation
	// -------------------------------------------------------------------------

	/**
	 * Run all activation tasks:
	 *  - Create / upgrade custom tables via dbDelta().
	 *  - Store the DB version in wp_options.
	 *  - Flush rewrite rules so REST routes are recognised immediately.
	 */
	public function activate(): void {
		$this->create_tables();
		$this->maybe_upgrade();
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Deactivation
	// -------------------------------------------------------------------------

	/**
	 * Run all deactivation tasks:
	 *  - Flush rewrite rules to remove our custom REST routes from the cache.
	 *  - Clear any scheduled cron events registered by this plugin.
	 */
	public function deactivate(): void {
		flush_rewrite_rules();
		wp_clear_scheduled_hook( 'seo_mcp_guard_cleanup' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Create (or upgrade) custom database tables using dbDelta().
	 *
	 * dbDelta() is safe to call repeatedly; it only applies the delta between
	 * the current state and the desired schema.
	 */
	private function create_tables(): void {
		global $wpdb;

		// dbDelta() requires this file to be loaded.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// ------------------------------------------------------------------
		// Table 1: seo_mcp_guard_changes
		// Tracks every field-level change made by MCP tools so they can be
		// rolled back individually or in batches.
		// ------------------------------------------------------------------
		$changes_table = $wpdb->prefix . 'seo_mcp_guard_changes';

		$sql_changes = "CREATE TABLE {$changes_table} (
  id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  change_id       varchar(64)         NOT NULL,
  batch_id        varchar(64)         NOT NULL,
  idempotency_key varchar(128)        NOT NULL,
  post_id         bigint(20) unsigned NOT NULL,
  tool_name       varchar(64)         NOT NULL DEFAULT '',
  field           varchar(128)        NOT NULL,
  old_value       longtext,
  new_value       longtext,
  user_id         bigint(20) unsigned NOT NULL,
  status          varchar(32)         NOT NULL DEFAULT 'applied',
  created_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY change_id (change_id),
  KEY idx_post_id (post_id),
  KEY idx_batch_id (batch_id),
  KEY idx_idempotency (idempotency_key)
) {$charset_collate};";

		// ------------------------------------------------------------------
		// Table 2: seo_mcp_guard_audit
		// Append-only log of every MCP request, including who called what,
		// from which IP, and what the outcome was.
		// ------------------------------------------------------------------
		$audit_table = $wpdb->prefix . 'seo_mcp_guard_audit';

		$sql_audit = "CREATE TABLE {$audit_table} (
  id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_type       varchar(64)         NOT NULL,
  post_id          bigint(20) unsigned,
  user_id          bigint(20) unsigned NOT NULL,
  tool_name        varchar(64)         NOT NULL,
  request_data     longtext,
  response_summary longtext,
  ip_address       varchar(45),
  created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_post_id (post_id),
  KEY idx_event_type (event_type),
  KEY idx_created_at (created_at)
) {$charset_collate};";

		dbDelta( $sql_changes );
		dbDelta( $sql_audit );
	}

	/**
	 * Store the current DB schema version (or update it after an upgrade).
	 * Future versions of the plugin can compare this to DB_VERSION and run
	 * additional ALTER TABLE / data-migration logic before calling update_option().
	 */
	private function maybe_upgrade(): void {
		$installed_version = get_option( self::DB_VERSION_KEY, '0.0.0' );

		if ( version_compare( (string) $installed_version, self::DB_VERSION, '<' ) ) {
			// Place version-gated migration steps here as the schema evolves.
			// e.g. if ( version_compare( $installed_version, '1.1.0', '<' ) ) { ... }

			update_option( self::DB_VERSION_KEY, self::DB_VERSION, autoload: false );
		}
	}
}

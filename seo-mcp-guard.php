<?php
/**
 * Plugin Name:       SEO MCP Guard
 * Plugin URI:        https://example.com/seo-mcp-guard
 * Description:       MCP server for SEO work only, integrating with Rank Math. Exposes a JSON-RPC 2.0 endpoint over HTTP with strict whitelisting, safety engine, and full audit trail.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            SEO MCP Guard
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-mcp-guard
 * Domain Path:       /languages
 * Network:           false
 */

declare( strict_types=1 );

namespace SEOMCPGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEO_MCP_GUARD_VERSION', '1.0.0' );
define( 'SEO_MCP_GUARD_PLUGIN_FILE', __FILE__ );
define( 'SEO_MCP_GUARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEO_MCP_GUARD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEO_MCP_GUARD_DB_VERSION', '1.0.0' );

/**
 * Auto-loader for plugin classes.
 */
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'SEOMCPGuard\\' ) !== 0 ) {
		return;
	}
	$relative = str_replace( 'SEOMCPGuard\\', '', $class );
	$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
	$file     = SEO_MCP_GUARD_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, function (): void {
	( new Installer() )->activate();
} );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, function (): void {
	( new Installer() )->deactivate();
} );

/**
 * Uninstall hook (static callback or uninstall.php).
 */
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\Plugin::uninstall' );

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function (): void {
	load_plugin_textdomain( 'seo-mcp-guard', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	Plugin::instance()->boot();
}, 5 );

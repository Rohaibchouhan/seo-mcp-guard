<?php
/**
 * Fired when the plugin is deleted via WordPress admin.
 *
 * @package SEOMCPGuard
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/Settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Plugin.php';

\SEOMCPGuard\Plugin::uninstall();

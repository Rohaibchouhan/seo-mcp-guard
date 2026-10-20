<?php
/**
 * Settings Page Renderer
 *
 * Renders the full plugin settings form with all controls, descriptions,
 * and proper escaping on every output.
 *
 * @package SEOMCPGuard\Admin
 */

declare(strict_types=1);

namespace SEOMCPGuard\Admin;

use SEOMCPGuard\Settings;

/**
 * Class SettingsPage
 *
 * Responsible solely for rendering the HTML of the Settings admin page.
 * All output is escaped through esc_html, esc_attr, or wp_kses_post.
 */
class SettingsPage {

    /**
     * SettingsPage constructor.
     *
     * @param Settings $settings    Plugin settings service.
     * @param string   $nonce_action Nonce action used for the form.
     */
    public function __construct(
        private Settings $settings,
        private string   $nonce_action
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Output the complete settings page HTML.
     */
    public function render(): void {
        $opts        = $this->settings->all();
        $post_types  = get_post_types( [ 'public' => true ], 'objects' );
        $endpoint    = rest_url( 'seo-mcp/v1/mcp' );

        // Existing values (with sensible defaults).
        $enabled                 = (bool) ( $opts['enabled']                 ?? true );
        $read_only_mode          = (bool) ( $opts['read_only_mode']          ?? false );
        $dry_run_by_default      = (bool) ( $opts['dry_run_by_default']      ?? false );
        $allow_production_writes = (bool) ( $opts['allow_production_writes'] ?? false );
        $allowed_post_types      = (array) ( $opts['allowed_post_types']     ?? [] );
        $allowed_post_ids_raw    = implode( "\n", (array) ( $opts['allowed_post_ids'] ?? [] ) );
        $rate_limit              = max( 1, min( 1000, (int) ( $opts['rate_limit_per_minute'] ?? 60 ) ) );
        $max_batch               = max( 1, min( 100,  (int) ( $opts['max_batch_size']        ?? 10 ) ) );
        $retention_days          = max( 30, min( 365, (int) ( $opts['log_retention_days']    ?? 90 ) ) );
        $delete_on_uninstall     = (bool) ( $opts['delete_data_on_uninstall'] ?? false );

        // Success notice.
        $saved = isset( $_GET['seo_mcp_saved'] ) && '1' === $_GET['seo_mcp_saved'];
        ?>
        <div class="wrap seo-mcp-guard-wrap">
            <h1 class="seo-mcp-guard-page-title">
                <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                <?php esc_html_e( 'SEO MCP Guard – Settings', 'seo-mcp-guard' ); ?>
            </h1>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible seo-mcp-notice">
                <p><?php esc_html_e( 'Settings saved successfully.', 'seo-mcp-guard' ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="" id="seo-mcp-settings-form" novalidate>
                <?php wp_nonce_field( $this->nonce_action ); ?>
                <input type="hidden" name="seo_mcp_guard_action" value="save_settings">

                <!-- ============================================================
                     SECTION: Core Controls
                ============================================================ -->
                <div class="seo-mcp-card">
                    <div class="seo-mcp-card-header">
                        <h2><?php esc_html_e( 'Core Controls', 'seo-mcp-guard' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Master switches that govern whether the MCP endpoint is active and in what mode it operates.', 'seo-mcp-guard' ); ?>
                        </p>
                    </div>
                    <div class="seo-mcp-card-body">

                        <!-- 1. Kill Switch (Enable / Disable) -->
                        <div class="seo-mcp-field seo-mcp-field--prominent">
                            <div class="seo-mcp-field-label-group">
                                <label for="seo_mcp_enabled" class="seo-mcp-field-label seo-mcp-field-label--large">
                                    <?php esc_html_e( 'Enable SEO MCP Guard', 'seo-mcp-guard' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Master kill switch. Disabling this immediately blocks all MCP API requests regardless of other settings.', 'seo-mcp-guard' ); ?>
                                </p>
                            </div>
                            <label class="seo-mcp-toggle seo-mcp-toggle--large" aria-label="<?php esc_attr_e( 'Enable SEO MCP Guard', 'seo-mcp-guard' ); ?>">
                                <input
                                    type="checkbox"
                                    id="seo_mcp_enabled"
                                    name="enabled"
                                    value="1"
                                    <?php checked( $enabled ); ?>
                                >
                                <span class="seo-mcp-toggle-slider" aria-hidden="true"></span>
                                <span class="seo-mcp-toggle-label-on"><?php esc_html_e( 'ON', 'seo-mcp-guard' ); ?></span>
                                <span class="seo-mcp-toggle-label-off"><?php esc_html_e( 'OFF', 'seo-mcp-guard' ); ?></span>
                            </label>
                        </div>

                        <!-- 2. Read-Only Mode -->
                        <div class="seo-mcp-field">
                            <div class="seo-mcp-field-label-group">
                                <label for="seo_mcp_read_only" class="seo-mcp-field-label">
                                    <?php esc_html_e( 'Read-Only Mode', 'seo-mcp-guard' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled, the MCP endpoint will only serve GET / read requests. All write operations will be rejected with a 403 response.', 'seo-mcp-guard' ); ?>
                                </p>
                            </div>
                            <label class="seo-mcp-toggle" aria-label="<?php esc_attr_e( 'Read-Only Mode', 'seo-mcp-guard' ); ?>">
                                <input
                                    type="checkbox"
                                    id="seo_mcp_read_only"
                                    name="read_only_mode"
                                    value="1"
                                    <?php checked( $read_only_mode ); ?>
                                >
                                <span class="seo-mcp-toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>

                        <!-- 3. Dry Run by Default -->
                        <div class="seo-mcp-field">
                            <div class="seo-mcp-field-label-group">
                                <label for="seo_mcp_dry_run" class="seo-mcp-field-label">
                                    <?php esc_html_e( 'Dry Run by Default', 'seo-mcp-guard' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Write requests will be simulated and logged but not committed to the database. The MCP client receives a response indicating what would have changed.', 'seo-mcp-guard' ); ?>
                                </p>
                            </div>
                            <label class="seo-mcp-toggle" aria-label="<?php esc_attr_e( 'Dry Run by Default', 'seo-mcp-guard' ); ?>">
                                <input
                                    type="checkbox"
                                    id="seo_mcp_dry_run"
                                    name="dry_run_by_default"
                                    value="1"
                                    <?php checked( $dry_run_by_default ); ?>
                                >
                                <span class="seo-mcp-toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>

                        <!-- 4. Allow Production Writes -->
                        <div class="seo-mcp-field seo-mcp-field--danger">
                            <div class="seo-mcp-field-label-group">
                                <label for="seo_mcp_prod_writes" class="seo-mcp-field-label">
                                    <?php esc_html_e( 'Allow Production Writes', 'seo-mcp-guard' ); ?>
                                    <span class="seo-mcp-badge seo-mcp-badge--danger"><?php esc_html_e( 'Caution', 'seo-mcp-guard' ); ?></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Allows the MCP endpoint to commit real database writes on a live production site. Enable only when you fully trust the MCP client and have reviewed the audit log.', 'seo-mcp-guard' ); ?>
                                </p>
                            </div>
                            <label class="seo-mcp-toggle seo-mcp-toggle--danger" aria-label="<?php esc_attr_e( 'Allow Production Writes', 'seo-mcp-guard' ); ?>">
                                <input
                                    type="checkbox"
                                    id="seo_mcp_prod_writes"
                                    name="allow_production_writes"
                                    value="1"
                                    <?php checked( $allow_production_writes ); ?>
                                >
                                <span class="seo-mcp-toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>

                    </div><!-- .seo-mcp-card-body -->
                </div><!-- .seo-mcp-card -->

                <!-- ============================================================
                     SECTION: Content Scope
                ============================================================ -->
                <div class="seo-mcp-card">
                    <div class="seo-mcp-card-header">
                        <h2><?php esc_html_e( 'Content Scope', 'seo-mcp-guard' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Restrict which post types and individual posts the MCP endpoint may read or modify.', 'seo-mcp-guard' ); ?>
                        </p>
                    </div>
                    <div class="seo-mcp-card-body">

                        <!-- 5. Allowed Post Types -->
                        <div class="seo-mcp-field seo-mcp-field--stack">
                            <label class="seo-mcp-field-label">
                                <?php esc_html_e( 'Allowed Post Types', 'seo-mcp-guard' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'The MCP endpoint may only operate on posts of the selected types. Uncheck all to block access to every post type.', 'seo-mcp-guard' ); ?>
                            </p>
                            <div class="seo-mcp-checkbox-grid">
                                <?php foreach ( $post_types as $pt_slug => $pt_obj ) : ?>
                                <label class="seo-mcp-checkbox-label">
                                    <input
                                        type="checkbox"
                                        name="allowed_post_types[]"
                                        value="<?php echo esc_attr( $pt_slug ); ?>"
                                        <?php checked( in_array( $pt_slug, $allowed_post_types, true ) ); ?>
                                    >
                                    <span class="seo-mcp-checkbox-text">
                                        <?php echo esc_html( $pt_obj->labels->singular_name ); ?>
                                        <code class="seo-mcp-pt-slug"><?php echo esc_html( $pt_slug ); ?></code>
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 6. Post ID Allowlist -->
                        <div class="seo-mcp-field seo-mcp-field--stack" id="seo-mcp-post-id-field">
                            <label for="seo_mcp_post_ids" class="seo-mcp-field-label">
                                <?php esc_html_e( 'Post ID Allowlist', 'seo-mcp-guard' ); ?>
                                <span class="seo-mcp-badge seo-mcp-badge--info"><?php esc_html_e( 'Optional', 'seo-mcp-guard' ); ?></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'If populated, the MCP endpoint may only operate on the listed post IDs. Enter one ID per line or comma-separated. Leave blank to allow all posts of the selected types above.', 'seo-mcp-guard' ); ?>
                            </p>
                            <textarea
                                id="seo_mcp_post_ids"
                                name="allowed_post_ids"
                                rows="5"
                                class="large-text code seo-mcp-textarea"
                                placeholder="<?php esc_attr_e( "42\n137\n2048", 'seo-mcp-guard' ); ?>"
                            ><?php echo esc_textarea( $allowed_post_ids_raw ); ?></textarea>
                        </div>

                    </div>
                </div>

                <!-- ============================================================
                     SECTION: Rate Limiting & Batching
                ============================================================ -->
                <div class="seo-mcp-card">
                    <div class="seo-mcp-card-header">
                        <h2><?php esc_html_e( 'Rate Limiting &amp; Batching', 'seo-mcp-guard' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Protect your site from runaway MCP agents by capping request frequency and batch sizes.', 'seo-mcp-guard' ); ?>
                        </p>
                    </div>
                    <div class="seo-mcp-card-body seo-mcp-card-body--row">

                        <!-- 7. Rate Limit -->
                        <div class="seo-mcp-field seo-mcp-field--inline">
                            <label for="seo_mcp_rate_limit" class="seo-mcp-field-label">
                                <?php esc_html_e( 'Rate Limit (requests / minute)', 'seo-mcp-guard' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Maximum number of MCP API requests allowed per token per minute. Range: 1 – 1000.', 'seo-mcp-guard' ); ?>
                            </p>
                            <input
                                type="number"
                                id="seo_mcp_rate_limit"
                                name="rate_limit_per_minute"
                                value="<?php echo esc_attr( (string) $rate_limit ); ?>"
                                min="1"
                                max="1000"
                                step="1"
                                class="small-text seo-mcp-number-input"
                                required
                            >
                            <span class="seo-mcp-unit"><?php esc_html_e( 'req/min', 'seo-mcp-guard' ); ?></span>
                        </div>

                        <!-- 8. Max Batch Size -->
                        <div class="seo-mcp-field seo-mcp-field--inline">
                            <label for="seo_mcp_batch_size" class="seo-mcp-field-label">
                                <?php esc_html_e( 'Max Batch Size', 'seo-mcp-guard' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Maximum number of posts that may be updated in a single batch request. Range: 1 – 100.', 'seo-mcp-guard' ); ?>
                            </p>
                            <input
                                type="number"
                                id="seo_mcp_batch_size"
                                name="max_batch_size"
                                value="<?php echo esc_attr( (string) $max_batch ); ?>"
                                min="1"
                                max="100"
                                step="1"
                                class="small-text seo-mcp-number-input"
                                required
                            >
                            <span class="seo-mcp-unit"><?php esc_html_e( 'posts', 'seo-mcp-guard' ); ?></span>
                        </div>

                    </div>
                </div>

                <!-- ============================================================
                     SECTION: Audit Log
                ============================================================ -->
                <div class="seo-mcp-card">
                    <div class="seo-mcp-card-header">
                        <h2><?php esc_html_e( 'Audit Log', 'seo-mcp-guard' ); ?></h2>
                    </div>
                    <div class="seo-mcp-card-body">

                        <!-- 9. Retention Days -->
                        <div class="seo-mcp-field seo-mcp-field--inline">
                            <label for="seo_mcp_retention" class="seo-mcp-field-label">
                                <?php esc_html_e( 'Log Retention Period', 'seo-mcp-guard' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'How many days to keep audit log entries before they are automatically purged. Range: 30 – 365.', 'seo-mcp-guard' ); ?>
                            </p>
                            <input
                                type="number"
                                id="seo_mcp_retention"
                                name="log_retention_days"
                                value="<?php echo esc_attr( (string) $retention_days ); ?>"
                                min="30"
                                max="365"
                                step="1"
                                class="small-text seo-mcp-number-input"
                                required
                            >
                            <span class="seo-mcp-unit"><?php esc_html_e( 'days', 'seo-mcp-guard' ); ?></span>
                        </div>

                    </div>
                </div>

                <!-- ============================================================
                     SECTION: MCP Endpoint
                ============================================================ -->
                <div class="seo-mcp-card">
                    <div class="seo-mcp-card-header">
                        <h2><?php esc_html_e( 'MCP Endpoint', 'seo-mcp-guard' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'Use this URL when configuring your MCP client or Antigravity agent.', 'seo-mcp-guard' ); ?>
                        </p>
                    </div>
                    <div class="seo-mcp-card-body">

                        <!-- 10. Endpoint URL (read-only) -->
                        <div class="seo-mcp-field seo-mcp-field--stack">
                            <label for="seo_mcp_endpoint_url" class="seo-mcp-field-label">
                                <?php esc_html_e( 'Endpoint URL', 'seo-mcp-guard' ); ?>
                            </label>
                            <div class="seo-mcp-copy-row">
                                <input
                                    type="text"
                                    id="seo_mcp_endpoint_url"
                                    value="<?php echo esc_attr( $endpoint ); ?>"
                                    class="large-text code"
                                    readonly
                                    aria-label="<?php esc_attr_e( 'MCP Endpoint URL', 'seo-mcp-guard' ); ?>"
                                >
                                <button
                                    type="button"
                                    class="button seo-mcp-copy-btn"
                                    data-target="seo_mcp_endpoint_url"
                                    aria-label="<?php esc_attr_e( 'Copy endpoint URL', 'seo-mcp-guard' ); ?>"
                                >
                                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                    <?php esc_html_e( 'Copy', 'seo-mcp-guard' ); ?>
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ============================================================
                     SECTION: Danger Zone
                ============================================================ -->
                <div class="seo-mcp-card seo-mcp-card--danger">
                    <div class="seo-mcp-card-header">
                        <h2>
                            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                            <?php esc_html_e( 'Danger Zone', 'seo-mcp-guard' ); ?>
                        </h2>
                    </div>
                    <div class="seo-mcp-card-body">

                        <!-- 11. Delete data on uninstall -->
                        <div class="seo-mcp-field">
                            <div class="seo-mcp-field-label-group">
                                <label for="seo_mcp_delete_data" class="seo-mcp-field-label">
                                    <?php esc_html_e( 'Delete All Data on Uninstall', 'seo-mcp-guard' ); ?>
                                    <span class="seo-mcp-badge seo-mcp-badge--danger"><?php esc_html_e( 'Irreversible', 'seo-mcp-guard' ); ?></span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When the plugin is deleted from WordPress, permanently remove all plugin settings, API tokens, and audit log entries. This cannot be undone. Deactivating without deleting keeps data intact.', 'seo-mcp-guard' ); ?>
                                </p>
                            </div>
                            <label class="seo-mcp-toggle seo-mcp-toggle--danger" aria-label="<?php esc_attr_e( 'Delete data on uninstall', 'seo-mcp-guard' ); ?>">
                                <input
                                    type="checkbox"
                                    id="seo_mcp_delete_data"
                                    name="delete_data_on_uninstall"
                                    value="1"
                                    data-confirm="<?php esc_attr_e( 'This will delete ALL plugin data when you uninstall the plugin. Are you sure?', 'seo-mcp-guard' ); ?>"
                                    <?php checked( $delete_on_uninstall ); ?>
                                >
                                <span class="seo-mcp-toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>

                    </div>
                </div>

                <!-- Submit -->
                <div class="seo-mcp-submit-row">
                    <?php submit_button(
                        __( 'Save Settings', 'seo-mcp-guard' ),
                        'primary large',
                        'submit',
                        false,
                        [ 'id' => 'seo-mcp-save-btn' ]
                    ); ?>
                    <span class="seo-mcp-save-spinner spinner" id="seo-mcp-save-spinner"></span>
                </div>

            </form>
        </div><!-- .seo-mcp-guard-wrap -->
        <?php
    }
}

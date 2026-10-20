<?php
/**
 * Admin Controller
 *
 * Registers admin menus, handles form submissions, enqueues assets,
 * and processes all AJAX actions for the SEO MCP Guard plugin.
 *
 * @package SEOMCPGuard\Admin
 */

declare(strict_types=1);

namespace SEOMCPGuard\Admin;

use SEOMCPGuard\Settings;
use SEOMCPGuard\Safety\ChangeLog;

/**
 * Class AdminController
 *
 * Central admin controller: registers all menu pages, wires up hooks for
 * settings saving, asset enqueueing, and AJAX handlers.
 */
class AdminController {

    /** @var string Nonce action for settings form. */
    private const NONCE_SETTINGS = 'seo_mcp_guard_settings_nonce';

    /** @var string Nonce action for AJAX token generation. */
    private const NONCE_TOKEN_GEN = 'seo_mcp_generate_token_nonce';

    /** @var string Nonce action for AJAX token revocation. */
    private const NONCE_TOKEN_REV = 'seo_mcp_revoke_token_nonce';

    /** @var string Nonce action for AJAX rollback. */
    private const NONCE_ROLLBACK = 'seo_mcp_rollback_nonce';

    /** @var string Nonce action for AJAX log export. */
    private const NONCE_EXPORT = 'seo_mcp_export_log_nonce';

    /** @var string Parent menu slug. */
    private const MENU_SLUG = 'seo-mcp-guard';

    /** @var SettingsPage|null Lazily instantiated settings page renderer. */
    private ?SettingsPage $settings_page = null;

    /** @var AuditLogPage|null Lazily instantiated audit log page renderer. */
    private ?AuditLogPage $audit_log_page = null;

    /** @var TokenManagerPage|null Lazily instantiated token manager page renderer. */
    private ?TokenManagerPage $token_manager_page = null;

    /**
     * AdminController constructor.
     *
     * @param Settings  $settings   Plugin settings manager.
     * @param ChangeLog $change_log Change-log / audit-log service.
     */
    public function __construct(
        private Settings $settings,
        private ChangeLog $change_log
    ) {}

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    /**
     * Wire up all WordPress hooks.
     *
     * Call once from the plugin bootstrap (after plugins_loaded).
     */
    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers (logged-in users only via wp_ajax_* hooks).
        add_action( 'wp_ajax_seo_mcp_generate_token', [ $this, 'ajax_generate_token' ] );
        add_action( 'wp_ajax_seo_mcp_revoke_token', [ $this, 'ajax_revoke_token' ] );
        add_action( 'wp_ajax_seo_mcp_rollback', [ $this, 'ajax_rollback' ] );
        add_action( 'wp_ajax_seo_mcp_export_log', [ $this, 'ajax_export_log' ] );
    }

    // -------------------------------------------------------------------------
    // Menu Registration
    // -------------------------------------------------------------------------

    /**
     * Register the top-level admin menu and all sub-pages.
     */
    public function register_menus(): void {
        // Main (top-level) menu page – rendered by SettingsPage.
        add_menu_page(
            __( 'SEO MCP Guard', 'seo-mcp-guard' ),   // Page title.
            __( 'SEO MCP Guard', 'seo-mcp-guard' ),   // Menu label.
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_settings_page' ],
            'dashicons-lock',
            80
        );

        // Sub-page: Settings (same slug as parent so it appears as first item).
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Settings – SEO MCP Guard', 'seo-mcp-guard' ),
            __( 'Settings', 'seo-mcp-guard' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_settings_page' ]
        );

        // Sub-page: Audit Log.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Audit Log – SEO MCP Guard', 'seo-mcp-guard' ),
            __( 'Audit Log', 'seo-mcp-guard' ),
            'manage_options',
            'seo-mcp-guard-audit-log',
            [ $this, 'render_audit_log_page' ]
        );

        // Sub-page: Token Manager.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Token Manager – SEO MCP Guard', 'seo-mcp-guard' ),
            __( 'Token Manager', 'seo-mcp-guard' ),
            'manage_options',
            'seo-mcp-guard-tokens',
            [ $this, 'render_token_manager_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Page Render Callbacks
    // -------------------------------------------------------------------------

    /**
     * Render the settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'seo-mcp-guard' ) );
        }
        $this->get_settings_page()->render();
    }

    /**
     * Render the audit log page.
     */
    public function render_audit_log_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'seo-mcp-guard' ) );
        }
        $this->get_audit_log_page()->render();
    }

    /**
     * Render the token manager page.
     */
    public function render_token_manager_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'seo-mcp-guard' ) );
        }
        $this->get_token_manager_page()->render();
    }

    // -------------------------------------------------------------------------
    // Settings Form Submission
    // -------------------------------------------------------------------------

    /**
     * Handle all admin form POST submissions (settings save).
     *
     * Hooked to admin_init so it runs before any output.
     */
    public function handle_form_submissions(): void {
        // Only process our own form.
        if ( ! isset( $_POST['seo_mcp_guard_action'] ) ) {
            return;
        }

        $action = sanitize_key( $_POST['seo_mcp_guard_action'] );

        if ( 'save_settings' !== $action ) {
            return;
        }

        // Capability check.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'seo-mcp-guard' ) );
        }

        // Nonce verification.
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_SETTINGS ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'seo-mcp-guard' ) );
        }

        // Parse and sanitize each setting.
        $new_settings = $this->sanitize_settings_post( $_POST );

        // Persist.
        $this->settings->update( $new_settings );

        // Redirect back with a success notice.
        $redirect = add_query_arg(
            [ 'page' => self::MENU_SLUG, 'seo_mcp_saved' => '1' ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Sanitize and normalise raw $_POST data for the settings form.
     *
     * @param  array<string,mixed> $post Raw $_POST array.
     * @return array<string,mixed>        Sanitised settings keyed by setting name.
     */
    private function sanitize_settings_post( array $post ): array {
        $sanitized = [];

        // Boolean toggles (checkboxes / switches) – absent means false.
        $bool_fields = [
            'enabled',
            'read_only_mode',
            'dry_run_by_default',
            'allow_production_writes',
            'delete_data_on_uninstall',
        ];
        foreach ( $bool_fields as $field ) {
            $sanitized[ $field ] = ! empty( $post[ $field ] );
        }

        // Post types allowlist – array of post-type slugs.
        $registered_types = array_keys( get_post_types( [ 'public' => true ] ) );
        $raw_types         = isset( $post['allowed_post_types'] ) && is_array( $post['allowed_post_types'] )
            ? (array) $post['allowed_post_types']
            : [];
        $sanitized['allowed_post_types'] = array_values(
            array_intersect( array_map( 'sanitize_key', $raw_types ), $registered_types )
        );

        // Post ID allowlist – textarea, comma/newline separated integers.
        $raw_ids = isset( $post['allowed_post_ids'] ) ? sanitize_textarea_field( wp_unslash( $post['allowed_post_ids'] ) ) : '';
        $id_list  = preg_split( '/[\s,]+/', $raw_ids, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        $sanitized['allowed_post_ids'] = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', $id_list )
                )
            )
        );

        // Numeric settings with clamping.
        $sanitized['rate_limit_per_minute'] = max( 1, min( 1000, absint( $post['rate_limit_per_minute'] ?? 60 ) ) );
        $sanitized['max_batch_size']         = max( 1, min( 100, absint( $post['max_batch_size'] ?? 10 ) ) );
        $sanitized['log_retention_days']     = max( 30, min( 365, absint( $post['log_retention_days'] ?? 90 ) ) );

        return $sanitized;
    }

    // -------------------------------------------------------------------------
    // Asset Enqueueing
    // -------------------------------------------------------------------------

    /**
     * Enqueue CSS and JS only on our own admin pages.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // Only enqueue on our pages.
        $our_hooks = [
            'toplevel_page_seo-mcp-guard',
            'seo-mcp-guard_page_seo-mcp-guard-audit-log',
            'seo-mcp-guard_page_seo-mcp-guard-tokens',
        ];
        if ( ! in_array( $hook_suffix, $our_hooks, true ) ) {
            return;
        }

        $plugin_url = plugin_dir_url( dirname( __DIR__ ) . '/seo-mcp-guard.php' );
        $version    = defined( 'SEO_MCP_GUARD_VERSION' ) ? SEO_MCP_GUARD_VERSION : '1.0.0';

        wp_enqueue_style(
            'seo-mcp-guard-admin',
            $plugin_url . 'assets/css/admin.css',
            [],
            $version
        );

        wp_enqueue_script(
            'seo-mcp-guard-admin',
            $plugin_url . 'assets/js/admin.js',
            [ 'jquery' ],
            $version,
            true
        );

        // Pass data to JS.
        wp_localize_script(
            'seo-mcp-guard-admin',
            'seoMcpGuard',
            [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonces'           => [
                    'generateToken' => wp_create_nonce( self::NONCE_TOKEN_GEN ),
                    'revokeToken'   => wp_create_nonce( self::NONCE_TOKEN_REV ),
                    'rollback'      => wp_create_nonce( self::NONCE_ROLLBACK ),
                    'exportLog'     => wp_create_nonce( self::NONCE_EXPORT ),
                ],
                'i18n'             => [
                    'confirmRevoke'   => __( 'Are you sure you want to revoke this token? This cannot be undone.', 'seo-mcp-guard' ),
                    'confirmRollback' => __( 'Roll back this change? The post field will be restored to its previous value.', 'seo-mcp-guard' ),
                    'copied'          => __( 'Copied!', 'seo-mcp-guard' ),
                    'copyFailed'      => __( 'Copy failed – please copy manually.', 'seo-mcp-guard' ),
                    'tokenGenError'   => __( 'Failed to generate token. Please try again.', 'seo-mcp-guard' ),
                    'revokeError'     => __( 'Failed to revoke token. Please try again.', 'seo-mcp-guard' ),
                    'rollbackError'   => __( 'Rollback failed. Please try again.', 'seo-mcp-guard' ),
                    'rollbackDone'    => __( 'Rolled back successfully.', 'seo-mcp-guard' ),
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX: Generate a new API token.
     *
     * Expects POST: nonce, label.
     * Returns JSON: { success, token, label, created_at, id }
     */
    public function ajax_generate_token(): void {
        $this->verify_ajax_nonce( self::NONCE_TOKEN_GEN );
        $this->verify_capability();

        $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        if ( '' === $label ) {
            wp_send_json_error( [ 'message' => __( 'Token label is required.', 'seo-mcp-guard' ) ], 422 );
        }

        // Delegate token generation to the settings/token service.
        try {
            $result = $this->settings->generate_token( $label );
            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: Revoke an existing API token.
     *
     * Expects POST: nonce, token_id.
     * Returns JSON: { success }
     */
    public function ajax_revoke_token(): void {
        $this->verify_ajax_nonce( self::NONCE_TOKEN_REV );
        $this->verify_capability();

        $token_id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
        if ( $token_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid token ID.', 'seo-mcp-guard' ) ], 422 );
        }

        try {
            $this->settings->revoke_token( $token_id );
            wp_send_json_success( [ 'token_id' => $token_id ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: Rollback a single change-log entry.
     *
     * Expects POST: nonce, change_id.
     * Returns JSON: { success, change_id }
     */
    public function ajax_rollback(): void {
        $this->verify_ajax_nonce( self::NONCE_ROLLBACK );
        $this->verify_capability();

        $change_id = isset( $_POST['change_id'] ) ? sanitize_text_field( wp_unslash( $_POST['change_id'] ) ) : '';
        if ( '' === $change_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid change ID.', 'seo-mcp-guard' ) ], 422 );
        }

        try {
            $this->change_log->rollback( $change_id );
            wp_send_json_success( [ 'change_id' => $change_id ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * AJAX: Export the audit log as a CSV download.
     *
     * Expects POST: nonce, optional filters (post_id, status, date_from, date_to).
     * Streams a CSV file directly.
     */
    public function ajax_export_log(): void {
        $this->verify_ajax_nonce( self::NONCE_EXPORT );
        $this->verify_capability();

        $filters = [
            'post_id'   => isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0,
            'status'    => isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '',
            'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
            'date_to'   => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
        ];

        try {
            $entries = $this->change_log->get_entries( $filters );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }

        // Stream as CSV.
        $filename = 'seo-mcp-guard-audit-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        if ( false === $out ) {
            wp_die( 'Could not open output stream.' );
        }

        // UTF-8 BOM for Excel compatibility.
        fwrite( $out, "\xEF\xBB\xBF" );

        // Header row.
        fputcsv( $out, [ 'Change ID', 'Batch ID', 'Post ID', 'Field', 'Old Value', 'New Value', 'User', 'Status', 'Timestamp' ] );

        foreach ( $entries as $entry ) {
            fputcsv( $out, [
                $entry['change_id']  ?? '',
                $entry['batch_id']   ?? '',
                $entry['post_id']    ?? '',
                $entry['field']      ?? '',
                $entry['old_value']  ?? '',
                $entry['new_value']  ?? '',
                $entry['user']       ?? '',
                $entry['status']     ?? '',
                $entry['created_at'] ?? '',
            ] );
        }

        fclose( $out );
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Verify an AJAX nonce from $_POST['nonce'], die on failure.
     *
     * @param string $action Nonce action string.
     */
    private function verify_ajax_nonce( string $action ): void {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'seo-mcp-guard' ) ], 403 );
        }
    }

    /**
     * Verify the current user has manage_options, die on failure.
     */
    private function verify_capability(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'seo-mcp-guard' ) ], 403 );
        }
    }

    /**
     * Lazily instantiate and return the SettingsPage renderer.
     */
    private function get_settings_page(): SettingsPage {
        if ( null === $this->settings_page ) {
            $this->settings_page = new SettingsPage( $this->settings, self::NONCE_SETTINGS );
        }
        return $this->settings_page;
    }

    /**
     * Lazily instantiate and return the AuditLogPage renderer.
     */
    private function get_audit_log_page(): AuditLogPage {
        if ( null === $this->audit_log_page ) {
            $this->audit_log_page = new AuditLogPage( $this->change_log );
        }
        return $this->audit_log_page;
    }

    /**
     * Lazily instantiate and return the TokenManagerPage renderer.
     */
    private function get_token_manager_page(): TokenManagerPage {
        if ( null === $this->token_manager_page ) {
            $this->token_manager_page = new TokenManagerPage( $this->settings );
        }
        return $this->token_manager_page;
    }
}

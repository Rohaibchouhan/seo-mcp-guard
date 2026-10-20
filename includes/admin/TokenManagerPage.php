<?php
/**
 * Token Manager Page Renderer
 *
 * Renders the API token management admin page: lists existing tokens,
 * allows generating new ones (showing the plaintext once), and revoking.
 * Also displays MCP connection configuration for the user's convenience.
 *
 * @package SEOMCPGuard\Admin
 */

declare(strict_types=1);

namespace SEOMCPGuard\Admin;

use SEOMCPGuard\Settings;

/**
 * Class TokenManagerPage
 *
 * Handles all output for the Token Manager admin page.
 * No business logic – delegates everything to Settings.
 */
class TokenManagerPage {

    /**
     * TokenManagerPage constructor.
     *
     * @param Settings $settings Plugin settings / token service.
     */
    public function __construct(
        private Settings $settings
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Output the complete Token Manager page HTML.
     */
    public function render(): void {
        $tokens      = $this->settings->get_tokens();   // Returns array of token metadata (no plaintext).
        $endpoint    = rest_url( 'seo-mcp/v1/mcp' );
        $site_url    = trailingslashit( get_site_url() );

        // Check if a newly generated token was just stored in a transient so
        // we can display it once. The AJAX handler sets this transient.
        $new_token_data = $this->settings->consume_pending_display_token();
        ?>
        <div class="wrap seo-mcp-guard-wrap">
            <h1 class="seo-mcp-guard-page-title">
                <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                <?php esc_html_e( 'SEO MCP Guard – Token Manager', 'seo-mcp-guard' ); ?>
            </h1>

            <!-- ================================================================
                 Newly Generated Token (shown once)
            ================================================================ -->
            <div id="seo-mcp-new-token-container" class="seo-mcp-new-token-container" <?php echo $new_token_data ? '' : 'hidden'; ?>>
                <?php if ( $new_token_data ) : ?>
                <?php $this->render_new_token_box( $new_token_data ); ?>
                <?php endif; ?>
            </div>

            <!-- ================================================================
                 Inline AJAX notices
            ================================================================ -->
            <div id="seo-mcp-token-notice" class="seo-mcp-ajax-notice" role="alert" aria-live="polite" hidden></div>

            <!-- ================================================================
                 Generate New Token Form
            ================================================================ -->
            <div class="seo-mcp-card">
                <div class="seo-mcp-card-header">
                    <h2><?php esc_html_e( 'Generate New Token', 'seo-mcp-guard' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Each token grants an MCP client access to the endpoint. Give every client its own token so you can revoke access individually.', 'seo-mcp-guard' ); ?>
                    </p>
                </div>
                <div class="seo-mcp-card-body">
                    <form id="seo-mcp-generate-token-form" class="seo-mcp-generate-form" novalidate>
                        <div class="seo-mcp-generate-row">
                            <label for="seo_mcp_token_label" class="screen-reader-text">
                                <?php esc_html_e( 'Token label', 'seo-mcp-guard' ); ?>
                            </label>
                            <input
                                type="text"
                                id="seo_mcp_token_label"
                                name="label"
                                placeholder="<?php esc_attr_e( 'e.g. Antigravity Production Agent', 'seo-mcp-guard' ); ?>"
                                class="regular-text"
                                maxlength="100"
                                required
                                autocomplete="off"
                            >
                            <button type="submit" class="button button-primary" id="seo-mcp-gen-btn">
                                <span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
                                <?php esc_html_e( 'Generate Token', 'seo-mcp-guard' ); ?>
                            </button>
                            <span class="spinner seo-mcp-gen-spinner" id="seo-mcp-gen-spinner"></span>
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'The token will be displayed only once immediately after generation. Store it in a safe location.', 'seo-mcp-guard' ); ?>
                        </p>
                    </form>
                </div>
            </div>

            <!-- ================================================================
                 Existing Tokens Table
            ================================================================ -->
            <div class="seo-mcp-card seo-mcp-card--table">
                <div class="seo-mcp-card-header">
                    <h2><?php esc_html_e( 'Active Tokens', 'seo-mcp-guard' ); ?></h2>
                </div>
                <div class="seo-mcp-card-body seo-mcp-card-body--no-pad">

                    <?php if ( empty( $tokens ) ) : ?>
                    <div class="seo-mcp-empty-state">
                        <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                        <p><?php esc_html_e( 'No tokens yet. Generate one above to connect your MCP client.', 'seo-mcp-guard' ); ?></p>
                    </div>

                    <?php else : ?>
                    <div class="seo-mcp-table-wrapper">
                        <table class="wp-list-table widefat fixed striped seo-mcp-token-table" id="seo-mcp-token-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="column-label"><?php esc_html_e( 'Label', 'seo-mcp-guard' ); ?></th>
                                    <th scope="col" class="column-token_id"><?php esc_html_e( 'Token ID', 'seo-mcp-guard' ); ?></th>
                                    <th scope="col" class="column-created"><?php esc_html_e( 'Created', 'seo-mcp-guard' ); ?></th>
                                    <th scope="col" class="column-last_used"><?php esc_html_e( 'Last Used', 'seo-mcp-guard' ); ?></th>
                                    <th scope="col" class="column-token_actions"><?php esc_html_e( 'Actions', 'seo-mcp-guard' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="seo-mcp-token-tbody">
                                <?php foreach ( $tokens as $token ) : ?>
                                <?php $this->render_token_row( $token ); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- ================================================================
                 MCP Connection Configuration
            ================================================================ -->
            <div class="seo-mcp-card">
                <div class="seo-mcp-card-header">
                    <h2><?php esc_html_e( 'MCP Connection Configuration', 'seo-mcp-guard' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Use the values below when configuring your MCP client or Antigravity agent.', 'seo-mcp-guard' ); ?>
                    </p>
                </div>
                <div class="seo-mcp-card-body">

                    <!-- Endpoint URL -->
                    <div class="seo-mcp-field seo-mcp-field--stack">
                        <label class="seo-mcp-field-label" for="seo_mcp_conn_url">
                            <?php esc_html_e( 'Endpoint URL', 'seo-mcp-guard' ); ?>
                        </label>
                        <div class="seo-mcp-copy-row">
                            <input
                                type="text"
                                id="seo_mcp_conn_url"
                                class="large-text code"
                                value="<?php echo esc_attr( $endpoint ); ?>"
                                readonly
                            >
                            <button type="button" class="button seo-mcp-copy-btn" data-target="seo_mcp_conn_url"
                                    aria-label="<?php esc_attr_e( 'Copy endpoint URL', 'seo-mcp-guard' ); ?>">
                                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                <?php esc_html_e( 'Copy', 'seo-mcp-guard' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Authorization Header -->
                    <div class="seo-mcp-field seo-mcp-field--stack" style="margin-top:16px;">
                        <label class="seo-mcp-field-label">
                            <?php esc_html_e( 'Authorization Header', 'seo-mcp-guard' ); ?>
                        </label>
                        <div class="seo-mcp-copy-row">
                            <input
                                type="text"
                                id="seo_mcp_conn_auth"
                                class="large-text code"
                                value="Bearer &lt;your-token-here&gt;"
                                readonly
                            >
                            <button type="button" class="button seo-mcp-copy-btn" data-target="seo_mcp_conn_auth"
                                    aria-label="<?php esc_attr_e( 'Copy authorization header', 'seo-mcp-guard' ); ?>">
                                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                <?php esc_html_e( 'Copy', 'seo-mcp-guard' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Antigravity MCP config block -->
                    <div class="seo-mcp-field seo-mcp-field--stack" style="margin-top:24px;">
                        <label class="seo-mcp-field-label">
                            <?php esc_html_e( 'Antigravity MCP Configuration Block', 'seo-mcp-guard' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Copy this JSON into your Antigravity agent\'s mcp_servers configuration. Replace <your-token-here> with an actual token.', 'seo-mcp-guard' ); ?>
                        </p>
                        <div class="seo-mcp-code-block-wrapper">
                            <button
                                type="button"
                                class="button seo-mcp-copy-code-btn"
                                data-target="seo-mcp-ag-config"
                                aria-label="<?php esc_attr_e( 'Copy configuration block', 'seo-mcp-guard' ); ?>"
                            >
                                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                <?php esc_html_e( 'Copy', 'seo-mcp-guard' ); ?>
                            </button>
                            <pre class="seo-mcp-config-block" id="seo-mcp-ag-config"><?php
                            // Output the JSON config block. We build the array in PHP so values are always escaped.
                            $ag_config = [
                                'mcpServers' => [
                                    'seo-mcp-guard' => [
                                        'url'     => $endpoint,
                                        'headers' => [
                                            'Authorization' => 'Bearer <your-token-here>',
                                        ],
                                        'description' => 'SEO MCP Guard – WordPress SEO meta editor',
                                    ],
                                ],
                            ];
                            echo esc_html(
                                json_encode( $ag_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
                            );
                            ?></pre>
                        </div>
                    </div>

                    <!-- Curl Example -->
                    <div class="seo-mcp-field seo-mcp-field--stack" style="margin-top:24px;">
                        <label class="seo-mcp-field-label">
                            <?php esc_html_e( 'cURL Quick-Test', 'seo-mcp-guard' ); ?>
                        </label>
                        <div class="seo-mcp-code-block-wrapper">
                            <button type="button" class="button seo-mcp-copy-code-btn" data-target="seo-mcp-curl-example"
                                    aria-label="<?php esc_attr_e( 'Copy cURL example', 'seo-mcp-guard' ); ?>">
                                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                                <?php esc_html_e( 'Copy', 'seo-mcp-guard' ); ?>
                            </button>
                            <pre class="seo-mcp-config-block" id="seo-mcp-curl-example"><?php
                            printf(
                                "curl -X POST \\\n  %s \\\n  -H 'Authorization: Bearer <your-token-here>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"method\":\"tools/list\"}'",
                                esc_html( $endpoint )
                            );
                            ?></pre>
                        </div>
                    </div>

                </div>
            </div>

        </div><!-- .seo-mcp-guard-wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Render the "newly generated token" display box.
     *
     * @param array<string,mixed> $token_data Array with keys: token (plaintext), label, id, created_at.
     */
    private function render_new_token_box( array $token_data ): void {
        $plaintext  = (string) ( $token_data['token']      ?? '' );
        $label      = (string) ( $token_data['label']      ?? '' );
        $created_at = (string) ( $token_data['created_at'] ?? '' );
        ?>
        <div class="seo-mcp-card seo-mcp-card--new-token">
            <div class="seo-mcp-card-header seo-mcp-card-header--success">
                <h2>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <?php esc_html_e( 'Token Generated Successfully', 'seo-mcp-guard' ); ?>
                </h2>
            </div>
            <div class="seo-mcp-card-body">
                <div class="seo-mcp-new-token-alert" role="alert">
                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                    <p>
                        <strong><?php esc_html_e( 'This is the only time this token will be displayed.', 'seo-mcp-guard' ); ?></strong>
                        <?php esc_html_e( 'Copy it now and store it securely. It cannot be recovered after you leave this page.', 'seo-mcp-guard' ); ?>
                    </p>
                </div>
                <dl class="seo-mcp-token-meta">
                    <dt><?php esc_html_e( 'Label', 'seo-mcp-guard' ); ?></dt>
                    <dd><?php echo esc_html( $label ); ?></dd>
                    <?php if ( $created_at ) : ?>
                    <dt><?php esc_html_e( 'Created', 'seo-mcp-guard' ); ?></dt>
                    <dd><?php echo esc_html( $created_at ); ?></dd>
                    <?php endif; ?>
                </dl>
                <div class="seo-mcp-token-display-row">
                    <label for="seo-mcp-new-token-value" class="screen-reader-text">
                        <?php esc_html_e( 'Your new API token', 'seo-mcp-guard' ); ?>
                    </label>
                    <input
                        type="text"
                        id="seo-mcp-new-token-value"
                        class="large-text code seo-mcp-token-display"
                        value="<?php echo esc_attr( $plaintext ); ?>"
                        readonly
                        autocomplete="off"
                        spellcheck="false"
                    >
                    <button
                        type="button"
                        class="button button-primary seo-mcp-copy-btn seo-mcp-copy-token-btn"
                        data-target="seo-mcp-new-token-value"
                        aria-label="<?php esc_attr_e( 'Copy token to clipboard', 'seo-mcp-guard' ); ?>"
                    >
                        <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                        <?php esc_html_e( 'Copy Token', 'seo-mcp-guard' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single row of the token table.
     *
     * @param array<string,mixed> $token Token metadata (no plaintext).
     */
    private function render_token_row( array $token ): void {
        $token_id   = (int)    ( $token['id']         ?? 0 );
        $label      = (string) ( $token['label']      ?? '' );
        $created_at = (string) ( $token['created_at'] ?? '' );
        $last_used  = (string) ( $token['last_used']  ?? '' );
        $prefix     = (string) ( $token['prefix']     ?? '' ); // Short prefix for identification.
        ?>
        <tr class="seo-mcp-token-row" id="seo-mcp-token-<?php echo esc_attr( (string) $token_id ); ?>" data-token-id="<?php echo esc_attr( (string) $token_id ); ?>">
            <!-- Label -->
            <td class="column-label">
                <strong><?php echo esc_html( $label ); ?></strong>
                <?php if ( $prefix ) : ?>
                <br>
                <code class="seo-mcp-token-prefix" title="<?php esc_attr_e( 'Token prefix (first 8 characters)', 'seo-mcp-guard' ); ?>">
                    <?php echo esc_html( $prefix ); ?>…
                </code>
                <?php endif; ?>
            </td>

            <!-- Token ID (internal DB ID) -->
            <td class="column-token_id">
                <code>#<?php echo esc_html( (string) $token_id ); ?></code>
            </td>

            <!-- Created -->
            <td class="column-created">
                <?php echo esc_html( $created_at ?: '—' ); ?>
            </td>

            <!-- Last Used -->
            <td class="column-last_used">
                <?php if ( $last_used ) : ?>
                    <?php echo esc_html( $last_used ); ?>
                <?php else : ?>
                    <span class="seo-mcp-no-value"><?php esc_html_e( 'Never', 'seo-mcp-guard' ); ?></span>
                <?php endif; ?>
            </td>

            <!-- Actions -->
            <td class="column-token_actions">
                <button
                    type="button"
                    class="button button-secondary seo-mcp-revoke-btn"
                    data-token-id="<?php echo esc_attr( (string) $token_id ); ?>"
                    data-label="<?php echo esc_attr( $label ); ?>"
                    aria-label="<?php
                        /* translators: %s: token label */
                        echo esc_attr( sprintf( __( 'Revoke token: %s', 'seo-mcp-guard' ), $label ) );
                    ?>"
                >
                    <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                    <?php esc_html_e( 'Revoke', 'seo-mcp-guard' ); ?>
                </button>
                <span class="spinner seo-mcp-revoke-spinner" id="seo-mcp-revoke-spinner-<?php echo esc_attr( (string) $token_id ); ?>"></span>
            </td>

        </tr>
        <?php
    }
}

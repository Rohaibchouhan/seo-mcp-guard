<?php
/**
 * Audit Log Page Renderer
 *
 * Renders the paginated, filterable audit-log table with per-row rollback,
 * batch rollback, colour-coded status badges, and CSV export.
 *
 * @package SEOMCPGuard\Admin
 */

declare(strict_types=1);

namespace SEOMCPGuard\Admin;

use SEOMCPGuard\Safety\ChangeLog;

/**
 * Class AuditLogPage
 *
 * Renders the Audit Log admin page. Uses a manual table (WP_List_Table style)
 * so it can be embedded inside our custom card layout without additional
 * class registration overhead.
 */
class AuditLogPage {

    /** @var int Number of entries shown per page. */
    private const PER_PAGE = 25;

    /**
     * AuditLogPage constructor.
     *
     * @param ChangeLog $change_log Change-log / audit-log service.
     */
    public function __construct(
        private ChangeLog $change_log
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Output the complete audit-log page HTML.
     */
    public function render(): void {
        // ------------------------------------------------------------------
        // Collect filter inputs (sanitised early, used throughout the method).
        // ------------------------------------------------------------------
        $filter_post_id  = isset( $_GET['filter_post_id'] )  ? absint( $_GET['filter_post_id'] )                                         : 0;
        $filter_status   = isset( $_GET['filter_status'] )   ? sanitize_key( wp_unslash( $_GET['filter_status'] ) )                      : '';
        $filter_date_from = isset( $_GET['filter_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_date_from'] ) )           : '';
        $filter_date_to   = isset( $_GET['filter_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['filter_date_to'] ) )             : '';
        $current_page    = isset( $_GET['paged'] )            ? max( 1, absint( $_GET['paged'] ) )                                        : 1;
        $filter_batch_id = isset( $_GET['filter_batch_id'] )  ? sanitize_text_field( wp_unslash( $_GET['filter_batch_id'] ) )             : '';

        $filters = [
            'post_id'   => $filter_post_id,
            'status'    => $filter_status,
            'date_from' => $filter_date_from,
            'date_to'   => $filter_date_to,
            'batch_id'  => $filter_batch_id,
            'limit'     => self::PER_PAGE,
            'offset'    => ( $current_page - 1 ) * self::PER_PAGE,
        ];

        // Retrieve data.
        $entries     = $this->change_log->get_entries( $filters );
        $total_count = $this->change_log->count_entries( $filters );
        $total_pages = max( 1, (int) ceil( $total_count / self::PER_PAGE ) );

        // Base URL for pagination / filter links.
        $base_url = admin_url( 'admin.php?page=seo-mcp-guard-audit-log' );

        $allowed_statuses = [ 'applied', 'rolled_back', 'dry_run', 'halted' ];
        ?>
        <div class="wrap seo-mcp-guard-wrap">
            <h1 class="seo-mcp-guard-page-title">
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                <?php esc_html_e( 'SEO MCP Guard – Audit Log', 'seo-mcp-guard' ); ?>
            </h1>

            <!-- ================================================================
                 Filter Bar
            ================================================================ -->
            <div class="seo-mcp-card seo-mcp-card--compact">
                <form method="get" action="" class="seo-mcp-filter-form" id="seo-mcp-filter-form">
                    <input type="hidden" name="page" value="seo-mcp-guard-audit-log">

                    <!-- Post ID -->
                    <div class="seo-mcp-filter-field">
                        <label for="filter_post_id" class="screen-reader-text">
                            <?php esc_html_e( 'Filter by Post ID', 'seo-mcp-guard' ); ?>
                        </label>
                        <input
                            type="number"
                            id="filter_post_id"
                            name="filter_post_id"
                            value="<?php echo esc_attr( $filter_post_id ? (string) $filter_post_id : '' ); ?>"
                            placeholder="<?php esc_attr_e( 'Post ID…', 'seo-mcp-guard' ); ?>"
                            min="1"
                            class="seo-mcp-filter-input"
                        >
                    </div>

                    <!-- Status -->
                    <div class="seo-mcp-filter-field">
                        <label for="filter_status" class="screen-reader-text">
                            <?php esc_html_e( 'Filter by Status', 'seo-mcp-guard' ); ?>
                        </label>
                        <select id="filter_status" name="filter_status" class="seo-mcp-filter-select">
                            <option value=""><?php esc_html_e( 'All Statuses', 'seo-mcp-guard' ); ?></option>
                            <?php foreach ( $allowed_statuses as $status ) : ?>
                            <option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>>
                                <?php echo esc_html( $this->format_status_label( $status ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date From -->
                    <div class="seo-mcp-filter-field">
                        <label for="filter_date_from" class="screen-reader-text">
                            <?php esc_html_e( 'Date from', 'seo-mcp-guard' ); ?>
                        </label>
                        <input
                            type="date"
                            id="filter_date_from"
                            name="filter_date_from"
                            value="<?php echo esc_attr( $filter_date_from ); ?>"
                            class="seo-mcp-filter-input"
                            placeholder="<?php esc_attr_e( 'From date', 'seo-mcp-guard' ); ?>"
                        >
                    </div>

                    <!-- Date To -->
                    <div class="seo-mcp-filter-field">
                        <label for="filter_date_to" class="screen-reader-text">
                            <?php esc_html_e( 'Date to', 'seo-mcp-guard' ); ?>
                        </label>
                        <input
                            type="date"
                            id="filter_date_to"
                            name="filter_date_to"
                            value="<?php echo esc_attr( $filter_date_to ); ?>"
                            class="seo-mcp-filter-input"
                            placeholder="<?php esc_attr_e( 'To date', 'seo-mcp-guard' ); ?>"
                        >
                    </div>

                    <!-- Batch ID -->
                    <div class="seo-mcp-filter-field">
                        <label for="filter_batch_id" class="screen-reader-text">
                            <?php esc_html_e( 'Filter by Batch ID', 'seo-mcp-guard' ); ?>
                        </label>
                        <input
                            type="text"
                            id="filter_batch_id"
                            name="filter_batch_id"
                            value="<?php echo esc_attr( $filter_batch_id ); ?>"
                            placeholder="<?php esc_attr_e( 'Batch ID…', 'seo-mcp-guard' ); ?>"
                            class="seo-mcp-filter-input code"
                            maxlength="36"
                        >
                    </div>

                    <div class="seo-mcp-filter-actions">
                        <?php submit_button( __( 'Filter', 'seo-mcp-guard' ), 'secondary', 'filter_submit', false ); ?>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="button">
                            <?php esc_html_e( 'Reset', 'seo-mcp-guard' ); ?>
                        </a>
                        <button
                            type="button"
                            id="seo-mcp-export-csv"
                            class="button button-secondary seo-mcp-export-btn"
                            data-post-id="<?php echo esc_attr( (string) $filter_post_id ); ?>"
                            data-status="<?php echo esc_attr( $filter_status ); ?>"
                            data-date-from="<?php echo esc_attr( $filter_date_from ); ?>"
                            data-date-to="<?php echo esc_attr( $filter_date_to ); ?>"
                        >
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                            <?php esc_html_e( 'Export CSV', 'seo-mcp-guard' ); ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ================================================================
                 Status Summary (counts by status)
            ================================================================ -->
            <div class="seo-mcp-status-tabs">
                <?php foreach ( $allowed_statuses as $status ) : ?>
                <?php $count = $this->change_log->count_entries( [ 'status' => $status ] ); ?>
                <a
                    href="<?php echo esc_url( add_query_arg( 'filter_status', $status, $base_url ) ); ?>"
                    class="seo-mcp-status-tab <?php echo $filter_status === $status ? 'is-active' : ''; ?>"
                >
                    <span class="seo-mcp-badge seo-mcp-badge--<?php echo esc_attr( $status ); ?>">
                        <?php echo esc_html( $this->format_status_label( $status ) ); ?>
                    </span>
                    <span class="seo-mcp-status-count"><?php echo esc_html( (string) $count ); ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ================================================================
                 Inline Notices (AJAX rollback feedback)
            ================================================================ -->
            <div id="seo-mcp-ajax-notice" class="seo-mcp-ajax-notice" role="alert" aria-live="polite" hidden></div>

            <!-- ================================================================
                 Log Table
            ================================================================ -->
            <div class="seo-mcp-card seo-mcp-card--table">

                <?php if ( empty( $entries ) ) : ?>
                <div class="seo-mcp-empty-state">
                    <span class="dashicons dashicons-database" aria-hidden="true"></span>
                    <p><?php esc_html_e( 'No audit log entries found matching your filters.', 'seo-mcp-guard' ); ?></p>
                </div>
                <?php else : ?>

                <!-- Pagination (top) -->
                <?php $this->render_pagination( $current_page, $total_pages, $total_count, $filters, $base_url ); ?>

                <div class="seo-mcp-table-wrapper">
                    <table class="wp-list-table widefat fixed striped seo-mcp-log-table" id="seo-mcp-log-table">
                        <thead>
                            <tr>
                                <th scope="col" class="column-change_id"><?php esc_html_e( 'Change ID', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-batch_id"><?php esc_html_e( 'Batch ID', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-post"><?php esc_html_e( 'Post', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-field"><?php esc_html_e( 'Field', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-old_value"><?php esc_html_e( 'Old Value', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-new_value"><?php esc_html_e( 'New Value', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-user"><?php esc_html_e( 'User', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-status"><?php esc_html_e( 'Status', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-time"><?php esc_html_e( 'Time', 'seo-mcp-guard' ); ?></th>
                                <th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'seo-mcp-guard' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="seo-mcp-log-tbody">
                            <?php foreach ( $entries as $entry ) : ?>
                            <?php $this->render_log_row( $entry ); ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Change ID', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Batch ID', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Post', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Field', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Old Value', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'New Value', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'User', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Status', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Time', 'seo-mcp-guard' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Actions', 'seo-mcp-guard' ); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div><!-- .seo-mcp-table-wrapper -->

                <!-- Pagination (bottom) -->
                <?php $this->render_pagination( $current_page, $total_pages, $total_count, $filters, $base_url ); ?>

                <?php endif; ?>

            </div><!-- .seo-mcp-card--table -->

        </div><!-- .seo-mcp-guard-wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Render a single table row for one audit-log entry.
     *
     * @param array<string,mixed> $entry Audit log entry data.
     */
    private function render_log_row( array $entry ): void {
        $change_id  = (string) ( $entry['change_id']  ?? '' );
        $batch_id   = (string) ( $entry['batch_id']   ?? '' );
        $post_id    = (int)    ( $entry['post_id']     ?? 0  );
        $field      = (string) ( $entry['field']       ?? '' );
        $old_value  = (string) ( $entry['old_value']   ?? '' );
        $new_value  = (string) ( $entry['new_value']   ?? '' );
        $user       = (string) ( $entry['user']        ?? '' );
        $status     = (string) ( $entry['status']      ?? '' );
        $created_at = (string) ( $entry['created_at']  ?? '' );

        // Post link.
        $post_title = $post_id ? get_the_title( $post_id ) : '';
        $post_edit  = $post_id ? get_edit_post_link( $post_id ) : '';

        $row_class = 'seo-mcp-log-row seo-mcp-log-row--' . sanitize_html_class( $status );
        ?>
        <tr
            class="<?php echo esc_attr( $row_class ); ?>"
            data-change-id="<?php echo esc_attr( $change_id ); ?>"
            data-batch-id="<?php echo esc_attr( $batch_id ); ?>"
            data-status="<?php echo esc_attr( $status ); ?>"
        >
            <!-- Change ID (truncated, full ID in title) -->
            <td class="column-change_id" title="<?php echo esc_attr( $change_id ); ?>">
                <code class="seo-mcp-id-cell"><?php echo esc_html( $this->truncate_id( $change_id ) ); ?></code>
            </td>

            <!-- Batch ID (truncated) -->
            <td class="column-batch_id" title="<?php echo esc_attr( $batch_id ); ?>">
                <?php if ( $batch_id ) : ?>
                <a
                    href="<?php echo esc_url( add_query_arg( 'filter_batch_id', $batch_id, admin_url( 'admin.php?page=seo-mcp-guard-audit-log' ) ) ); ?>"
                    title="<?php esc_attr_e( 'View all changes in this batch', 'seo-mcp-guard' ); ?>"
                    class="seo-mcp-batch-link"
                >
                    <code><?php echo esc_html( $this->truncate_id( $batch_id ) ); ?></code>
                </a>
                <?php else : ?>
                <span class="seo-mcp-no-value">—</span>
                <?php endif; ?>
            </td>

            <!-- Post -->
            <td class="column-post">
                <?php if ( $post_id && $post_edit ) : ?>
                <a href="<?php echo esc_url( $post_edit ); ?>" title="<?php echo esc_attr( 'ID: ' . $post_id ); ?>">
                    <?php echo esc_html( $post_title ?: (string) $post_id ); ?>
                </a>
                <?php elseif ( $post_id ) : ?>
                <span title="<?php echo esc_attr( 'ID: ' . $post_id ); ?>"><?php echo esc_html( (string) $post_id ); ?></span>
                <?php else : ?>
                <span class="seo-mcp-no-value">—</span>
                <?php endif; ?>
            </td>

            <!-- Field -->
            <td class="column-field">
                <code><?php echo esc_html( $field ); ?></code>
            </td>

            <!-- Old Value -->
            <td class="column-old_value">
                <div class="seo-mcp-value-cell seo-mcp-value-cell--old" title="<?php echo esc_attr( $old_value ); ?>">
                    <?php echo esc_html( $this->truncate_value( $old_value ) ); ?>
                </div>
            </td>

            <!-- New Value -->
            <td class="column-new_value">
                <div class="seo-mcp-value-cell seo-mcp-value-cell--new" title="<?php echo esc_attr( $new_value ); ?>">
                    <?php echo esc_html( $this->truncate_value( $new_value ) ); ?>
                </div>
            </td>

            <!-- User -->
            <td class="column-user"><?php echo esc_html( $user ); ?></td>

            <!-- Status badge -->
            <td class="column-status">
                <span class="seo-mcp-badge seo-mcp-badge--<?php echo esc_attr( $status ); ?>">
                    <?php echo esc_html( $this->format_status_label( $status ) ); ?>
                </span>
            </td>

            <!-- Time -->
            <td class="column-time">
                <time datetime="<?php echo esc_attr( $created_at ); ?>" title="<?php echo esc_attr( $created_at ); ?>">
                    <?php echo esc_html( $this->format_time( $created_at ) ); ?>
                </time>
            </td>

            <!-- Actions -->
            <td class="column-actions">
                <?php if ( 'applied' === $status ) : ?>
                <button
                    type="button"
                    class="button button-small seo-mcp-rollback-btn"
                    data-change-id="<?php echo esc_attr( $change_id ); ?>"
                    aria-label="<?php
                        /* translators: %s: change ID */
                        echo esc_attr( sprintf( __( 'Roll back change %s', 'seo-mcp-guard' ), $this->truncate_id( $change_id ) ) );
                    ?>"
                >
                    <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                    <?php esc_html_e( 'Rollback', 'seo-mcp-guard' ); ?>
                </button>
                <?php elseif ( 'rolled_back' === $status ) : ?>
                <span class="seo-mcp-no-value"><?php esc_html_e( 'Rolled back', 'seo-mcp-guard' ); ?></span>
                <?php elseif ( 'dry_run' === $status ) : ?>
                <span class="seo-mcp-no-value"><?php esc_html_e( 'Dry run', 'seo-mcp-guard' ); ?></span>
                <?php else : ?>
                <span class="seo-mcp-no-value">—</span>
                <?php endif; ?>

                <?php if ( $batch_id ) : ?>
                <button
                    type="button"
                    class="button button-small seo-mcp-batch-rollback-btn"
                    data-batch-id="<?php echo esc_attr( $batch_id ); ?>"
                    title="<?php esc_attr_e( 'Roll back entire batch', 'seo-mcp-guard' ); ?>"
                    style="margin-top:4px;"
                >
                    <span class="dashicons dashicons-undo" aria-hidden="true"></span>
                    <?php esc_html_e( 'Batch', 'seo-mcp-guard' ); ?>
                </button>
                <?php endif; ?>
            </td>

        </tr>
        <?php
    }

    /**
     * Render the pagination controls.
     *
     * @param int                  $current_page Current page number.
     * @param int                  $total_pages  Total number of pages.
     * @param int                  $total_count  Total number of entries.
     * @param array<string,mixed>  $filters      Current active filters.
     * @param string               $base_url     Base URL without paging args.
     */
    private function render_pagination( int $current_page, int $total_pages, int $total_count, array $filters, string $base_url ): void {
        if ( $total_pages <= 1 ) {
            return;
        }

        // Build a URL with current filters preserved.
        $paged_url = add_query_arg(
            array_filter( [
                'filter_post_id'  => $filters['post_id'] ?: null,
                'filter_status'   => $filters['status']  ?: null,
                'filter_date_from' => $filters['date_from'] ?: null,
                'filter_date_to'   => $filters['date_to']   ?: null,
            ] ),
            $base_url
        );
        ?>
        <div class="tablenav top seo-mcp-tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    /* translators: %s: number of entries */
                    echo esc_html( sprintf( _n( '%s entry', '%s entries', $total_count, 'seo-mcp-guard' ), number_format_i18n( $total_count ) ) );
                    ?>
                </span>
                <span class="pagination-links">
                    <?php if ( $current_page > 1 ) : ?>
                    <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $paged_url ) ); ?>" title="<?php esc_attr_e( 'First page', 'seo-mcp-guard' ); ?>">
                        <span aria-hidden="true">«</span>
                    </a>
                    <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $paged_url ) ); ?>" title="<?php esc_attr_e( 'Previous page', 'seo-mcp-guard' ); ?>">
                        <span aria-hidden="true">‹</span>
                    </a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <label for="current-page-selector" class="screen-reader-text">
                            <?php esc_html_e( 'Current page', 'seo-mcp-guard' ); ?>
                        </label>
                        <?php
                        /* translators: 1: current page, 2: total pages */
                        printf(
                            esc_html__( '%1$s of %2$s', 'seo-mcp-guard' ),
                            '<span class="current-page">' . esc_html( (string) $current_page ) . '</span>',
                            '<span class="total-pages">' . esc_html( (string) $total_pages ) . '</span>'
                        );
                        ?>
                    </span>

                    <?php if ( $current_page < $total_pages ) : ?>
                    <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $paged_url ) ); ?>" title="<?php esc_attr_e( 'Next page', 'seo-mcp-guard' ); ?>">
                        <span aria-hidden="true">›</span>
                    </a>
                    <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $paged_url ) ); ?>" title="<?php esc_attr_e( 'Last page', 'seo-mcp-guard' ); ?>">
                        <span aria-hidden="true">»</span>
                    </a>
                    <?php endif; ?>
                </span>
            </div>
            <br class="clear">
        </div>
        <?php
    }

    /**
     * Return a human-readable label for a status slug.
     *
     * @param  string $status Status slug (applied, rolled_back, dry_run, halted).
     * @return string         Human-readable label.
     */
    private function format_status_label( string $status ): string {
        return match ( $status ) {
            'applied'     => __( 'Applied',      'seo-mcp-guard' ),
            'rolled_back' => __( 'Rolled Back',  'seo-mcp-guard' ),
            'dry_run'     => __( 'Dry Run',      'seo-mcp-guard' ),
            'halted'      => __( 'Halted',       'seo-mcp-guard' ),
            default       => ucfirst( str_replace( '_', ' ', $status ) ),
        };
    }

    /**
     * Truncate a UUID / ID string for display.
     *
     * @param  string $id   Full ID string.
     * @param  int    $len  Number of characters to keep (default 8).
     * @return string       Truncated string with ellipsis if needed.
     */
    private function truncate_id( string $id, int $len = 8 ): string {
        if ( '' === $id ) {
            return '—';
        }
        return strlen( $id ) > $len ? substr( $id, 0, $len ) . '…' : $id;
    }

    /**
     * Truncate a field value string for table display.
     *
     * @param  string $value Full value.
     * @param  int    $max   Max characters before truncation.
     * @return string
     */
    private function truncate_value( string $value, int $max = 80 ): string {
        if ( '' === $value ) {
            return '—';
        }
        $value = wp_strip_all_tags( $value );
        return mb_strlen( $value ) > $max
            ? mb_substr( $value, 0, $max ) . '…'
            : $value;
    }

    /**
     * Format a UTC datetime string for display in local time.
     *
     * @param  string $datetime ISO-8601 / MySQL datetime string.
     * @return string           Formatted time relative to local timezone.
     */
    private function format_time( string $datetime ): string {
        if ( '' === $datetime ) {
            return '—';
        }
        $ts = strtotime( $datetime );
        if ( false === $ts ) {
            return esc_html( $datetime );
        }
        return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ?: $datetime;
    }
}

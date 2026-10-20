/**
 * SEO MCP Guard – Admin JavaScript
 *
 * Handles clipboard copying, token generation & revocation,
 * audit log rollback, and settings interactions.
 *
 * @package SEOMCPGuard
 */

/* global seoMcpGuard, jQuery */

(function ($) {
    'use strict';

    $(function () {
        initClipboard();
        initTokenManager();
        initAuditLog();
        initSettingsPage();
    });

    /**
     * Initialize clipboard copy handlers.
     */
    function initClipboard() {
        // Generic copy button targeting an input or element by ID.
        $(document).on('click', '.seo-mcp-copy-btn, .seo-mcp-copy-code-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const targetId = $btn.data('target');
            let textToCopy = '';

            if (targetId) {
                const $target = $('#' + targetId);
                if ($target.length) {
                    if ($target.is('input, textarea')) {
                        textToCopy = $target.val();
                    } else {
                        textToCopy = $target.text().trim();
                    }
                }
            } else if ($btn.hasClass('seo-mcp-copy-token-btn')) {
                const $input = $('#seo-mcp-new-token-value');
                if ($input.length) {
                    textToCopy = $input.val();
                }
            }

            if (!textToCopy) {
                return;
            }

            copyText(textToCopy, $btn);
        });
    }

    /**
     * Copy text to clipboard with fallback.
     */
    function copyText(text, $btn) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                showCopySuccess($btn);
            }).catch(function () {
                fallbackCopyText(text, $btn);
            });
        } else {
            fallbackCopyText(text, $btn);
        }
    }

    /**
     * Fallback copy text using textarea selection.
     */
    function fallbackCopyText(text, $btn) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-9999px';
        textArea.style.top = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess($btn);
            } else {
                alert(seoMcpGuard.i18n.copyFailed || 'Copy failed');
            }
        } catch (err) {
            alert(seoMcpGuard.i18n.copyFailed || 'Copy failed');
        } finally {
            document.body.removeChild(textArea);
        }
    }

    /**
     * Show temporary visual success feedback on button.
     */
    function showCopySuccess($btn) {
        const originalHtml = $btn.html();
        const copiedText = seoMcpGuard.i18n.copied || 'Copied!';

        $btn.html('<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' + copiedText);
        $btn.addClass('button-primary').prop('disabled', true);

        setTimeout(function () {
            $btn.html(originalHtml);
            $btn.removeClass('button-primary').prop('disabled', false);
        }, 2000);
    }

    /**
     * Initialize Token Manager functionality.
     */
    function initTokenManager() {
        // Generate Token Form
        const $tokenForm = $('#seo-mcp-generate-token-form');
        if ($tokenForm.length) {
            $tokenForm.on('submit', function (e) {
                // If form has custom action, handle via AJAX
                const $labelInput = $('#seo_mcp_token_label');
                const label = $.trim($labelInput.val());

                if (!label) {
                    alert(seoMcpGuard.i18n.tokenGenError || 'Please enter a token label.');
                    $labelInput.focus();
                    return false;
                }

                // If submitting via standard POST, let it submit.
                // If AJAX is desired:
                if ($tokenForm.data('ajax') === true) {
                    e.preventDefault();
                    const $submitBtn = $tokenForm.find(':submit');
                    $submitBtn.prop('disabled', true);

                    $.ajax({
                        url: seoMcpGuard.ajaxUrl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'seo_mcp_generate_token',
                            nonce: seoMcpGuard.nonces.generateToken,
                            label: label
                        }
                    }).done(function (response) {
                        if (response.success && response.data) {
                            window.location.reload();
                        } else {
                            alert(response.data && response.data.message ? response.data.message : seoMcpGuard.i18n.tokenGenError);
                            $submitBtn.prop('disabled', false);
                        }
                    }).fail(function () {
                        alert(seoMcpGuard.i18n.tokenGenError || 'Failed to generate token.');
                        $submitBtn.prop('disabled', false);
                    });
                }
            });
        }

        // Revoke Token Button
        $(document).on('click', '.seo-mcp-revoke-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const tokenId = $btn.data('token-id');

            if (!confirm(seoMcpGuard.i18n.confirmRevoke || 'Are you sure you want to revoke this token?')) {
                return;
            }

            const $row = $('#seo-mcp-token-' + tokenId);
            const $spinner = $('#seo-mcp-revoke-spinner-' + tokenId);

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: seoMcpGuard.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'seo_mcp_revoke_token',
                    nonce: seoMcpGuard.nonces.revokeToken,
                    token_id: tokenId
                }
            }).done(function (response) {
                if (response.success) {
                    $row.fadeOut(300, function () {
                        $(this).remove();
                        if ($('#seo-mcp-token-tbody tr').length === 0) {
                            window.location.reload();
                        }
                    });
                } else {
                    alert(response.data && response.data.message ? response.data.message : seoMcpGuard.i18n.revokeError);
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            }).fail(function () {
                alert(seoMcpGuard.i18n.revokeError || 'Failed to revoke token.');
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            });
        });
    }

    /**
     * Initialize Audit Log functionality.
     */
    function initAuditLog() {
        const $notice = $('#seo-mcp-ajax-notice');

        // Rollback Single Change
        $(document).on('click', '.seo-mcp-rollback-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const changeId = $btn.data('change-id');

            if (!confirm(seoMcpGuard.i18n.confirmRollback || 'Roll back this change?')) {
                return;
            }

            $btn.prop('disabled', true).text('Rolling back…');

            $.ajax({
                url: seoMcpGuard.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'seo_mcp_rollback',
                    nonce: seoMcpGuard.nonces.rollback,
                    change_id: changeId
                }
            }).done(function (response) {
                if (response.success) {
                    const $row = $btn.closest('tr');
                    $row.find('.column-status').html('<span class="seo-mcp-badge seo-mcp-badge--rolled_back">rolled_back</span>');
                    $btn.replaceWith('<span class="seo-mcp-no-value">—</span>');

                    showNotice('notice-success', seoMcpGuard.i18n.rollbackDone || 'Rolled back successfully.');
                } else {
                    const msg = response.data && response.data.message ? response.data.message : seoMcpGuard.i18n.rollbackError;
                    showNotice('notice-error', msg);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-undo" aria-hidden="true"></span> Rollback');
                }
            }).fail(function () {
                showNotice('notice-error', seoMcpGuard.i18n.rollbackError || 'Rollback failed.');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-undo" aria-hidden="true"></span> Rollback');
            });
        });

        // Batch Rollback
        $(document).on('click', '.seo-mcp-batch-rollback-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const batchId = $btn.data('batch-id');

            if (!confirm('Are you sure you want to roll back all changes in batch "' + batchId + '"?')) {
                return;
            }

            $btn.prop('disabled', true).text('Rolling back batch…');

            $.ajax({
                url: seoMcpGuard.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'seo_mcp_rollback',
                    nonce: seoMcpGuard.nonces.rollback,
                    batch_id: batchId
                }
            }).done(function (response) {
                if (response.success) {
                    showNotice('notice-success', 'Batch rolled back successfully. Refreshing page…');
                    setTimeout(function () {
                        window.location.reload();
                    }, 1200);
                } else {
                    const msg = response.data && response.data.message ? response.data.message : seoMcpGuard.i18n.rollbackError;
                    showNotice('notice-error', msg);
                    $btn.prop('disabled', false).text('Rollback Batch');
                }
            }).fail(function () {
                showNotice('notice-error', seoMcpGuard.i18n.rollbackError || 'Batch rollback failed.');
                $btn.prop('disabled', false).text('Rollback Batch');
            });
        });

        // CSV Export
        $(document).on('click', '#seo-mcp-export-csv', function (e) {
            e.preventDefault();
            const $form = $('#seo-mcp-filter-form');
            const postId = $('#filter_post_id').val() || '';
            const status = $('#filter_status').val() || '';
            const dateFrom = $('#filter_date_from').val() || '';
            const dateTo = $('#filter_date_to').val() || '';

            // Construct export POST form
            const exportForm = document.createElement('form');
            exportForm.method = 'POST';
            exportForm.action = seoMcpGuard.ajaxUrl;
            exportForm.style.display = 'none';

            const fields = {
                action: 'seo_mcp_export_log',
                nonce: seoMcpGuard.nonces.exportLog,
                post_id: postId,
                status: status,
                date_from: dateFrom,
                date_to: dateTo
            };

            for (const key in fields) {
                if (Object.prototype.hasOwnProperty.call(fields, key)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = fields[key];
                    exportForm.appendChild(input);
                }
            }

            document.body.appendChild(exportForm);
            exportForm.submit();
            document.body.removeChild(exportForm);
        });

        function showNotice(type, message) {
            if (!$notice.length) return;
            $notice.removeClass('notice-success notice-error notice-warning')
                   .addClass('notice ' + type + ' is-dismissible')
                   .html('<p>' + escapeHtml(message) + '</p>')
                   .removeAttr('hidden')
                   .show();
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
        }
    }

    /**
     * Initialize Settings Page UI enhancements.
     */
    function initSettingsPage() {
        // Dynamic toggle warnings or helper text if needed
        const $enabledToggle = $('#seo_mcp_enabled');
        if ($enabledToggle.length) {
            $enabledToggle.on('change', function () {
                const isEnabled = $(this).is(':checked');
                $('.seo-mcp-settings-subsections').toggleClass('is-disabled', !isEnabled);
            });
        }
    }

})(jQuery);

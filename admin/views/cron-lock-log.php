<?php
/**
 * Cron Lock Log Page View
 *
 * One row per price/stock cron invocation. Use this to verify the lock-file
 * overlap protection is firing as expected (skipped vs proceeding, stale
 * recovery, lock removal at shutdown).
 *
 * @package AutoSync_Product_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ASM_PLUGIN_DIR . 'includes/class-cron-lock-log.php';

global $wpdb;
$table_name   = ASM_Cron_Lock_Log::get_table_name();
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

$rows  = array();
$total = 0;
if ($table_exists) {
    $rows  = ASM_Cron_Lock_Log::get_recent(100);
    $total = ASM_Cron_Lock_Log::get_total_count();
}

$logging_enabled    = ASM_Cron_Lock_Log::is_enabled();
$stale_minutes_raw  = get_option('asm_lock_stale_minutes', '');
$stale_minutes_eff  = ASM_Cron_Lock_Log::get_stale_minutes();
$stale_default      = ASM_Cron_Lock_Log::DEFAULT_STALE_MINUTES;

$lock_state_label = array(
    'not_found'    => 'Not Found',
    'found_active' => 'Found (Active)',
    'found_stale'  => 'Found (Stale)',
);

$action_label = array(
    'created'                    => 'Creating Lock File',
    'cleared_stale_and_created'  => 'Deleting Stale + Creating Lock File',
    'skipped'                    => 'No Action (Skipped)',
);

$outcome_label = array(
    'proceeding' => 'Cron Proceeding (New Process)',
    'completed'  => 'Cron Proceeding (New Process)',
    'skipped'    => 'Cron Prevented by Lock',
);
?>

<div class="asm-card">
    <h2 style="margin-top:0;">Cron Lock Log</h2>
    <p class="description">
        One row per price/stock cron invocation. Verifies the lock-file overlap
        protection: whether a previous run was still in progress, whether the
        cron was skipped or allowed to proceed, and whether the lock was
        removed cleanly at shutdown.
    </p>

    <?php if (!$table_exists): ?>
        <div class="notice notice-warning">
            <p>
                The cron lock log table does not exist yet. Deactivate and
                reactivate the AutoSync Product Manager plugin to create it,
                or wait for the next cron run.
            </p>
        </div>
    <?php else: ?>
        <div class="asm-card" style="background:#fff;border:1px solid #c3c4c7;padding:15px 20px;margin-top:15px;display:flex;flex-wrap:wrap;gap:24px;align-items:center;">
            <div>
                <strong>Total entries:</strong> <?php echo (int) $total; ?>
                &nbsp;|&nbsp;
                <strong>Showing:</strong> latest <?php echo count($rows); ?>
            </div>

            <div style="display:flex;align-items:center;gap:8px;">
                <label for="asm-lock-log-toggle" style="font-weight:600;">Logging:</label>
                <input
                    type="checkbox"
                    id="asm-lock-log-toggle"
                    <?php checked($logging_enabled, true); ?>
                />
                <span id="asm-lock-log-toggle-label" style="color:<?php echo $logging_enabled ? '#1d7a3a' : '#b32d2e'; ?>;font-weight:600;">
                    <?php echo $logging_enabled ? 'Enabled' : 'Disabled'; ?>
                </span>
            </div>

            <div style="display:flex;align-items:center;gap:8px;">
                <label for="asm-lock-stale-minutes" style="font-weight:600;" title="Lock files older than this are treated as stale and removed before the next sync proceeds.">
                    Stale lock age (min):
                </label>
                <input
                    type="number"
                    id="asm-lock-stale-minutes"
                    min="1"
                    step="1"
                    value="<?php echo esc_attr($stale_minutes_raw); ?>"
                    placeholder="<?php echo (int) $stale_default; ?> (default)"
                    style="width:120px;"
                />
                <button type="button" class="button button-secondary" id="asm-lock-stale-save">Save</button>
                <span class="description" style="color:#646970;">
                    Currently effective: <strong><?php echo (int) $stale_minutes_eff; ?> min</strong>
                    <?php if ($stale_minutes_raw === '' || !is_numeric($stale_minutes_raw)): ?>
                        (default fallback)
                    <?php endif; ?>
                </span>
            </div>

            <div style="margin-left:auto;">
                <button type="button" class="button button-secondary" id="asm-lock-log-clear" style="color:#b32d2e;border-color:#b32d2e;">
                    Clear All Logs
                </button>
            </div>
        </div>

        <div id="asm-lock-log-status" style="margin-top:10px;"></div>

        <table class="widefat striped" style="margin-top:15px;table-layout:auto;width:100%;">
            <thead>
                <tr>
                    <th style="white-space:nowrap;">ID</th>
                    <th style="white-space:nowrap;">Cron Started At</th>
                    <th style="white-space:nowrap;">Cron Ended At</th>
                    <th style="white-space:nowrap;">Lock File State</th>
                    <th style="white-space:nowrap;">Lock Age (min)</th>
                    <th style="white-space:nowrap;">Action Taken</th>
                    <th style="white-space:nowrap;">Process Outcome</th>
                    <th style="white-space:nowrap;">Lock Removed</th>
                    <th style="min-width:240px;">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:20px;">
                            No cron lock log entries yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $state_key   = $row->lock_state;
                        $action_key  = $row->action_taken;
                        $outcome_key = $row->process_outcome;

                        $state_text   = isset($lock_state_label[$state_key]) ? $lock_state_label[$state_key] : $state_key;
                        $action_text  = isset($action_label[$action_key]) ? $action_label[$action_key] : $action_key;
                        $outcome_text = isset($outcome_label[$outcome_key]) ? $outcome_label[$outcome_key] : $outcome_key;

                        $outcome_color = '#646970';
                        if ($outcome_key === 'skipped') {
                            $outcome_color = '#b32d2e';
                        } elseif ($outcome_key === 'completed') {
                            $outcome_color = '#1d7a3a';
                        } elseif ($outcome_key === 'proceeding') {
                            $outcome_color = '#996800';
                        }

                        if ($row->lock_removed === null) {
                            $removed_text = '—';
                        } else {
                            $removed_text = ((int) $row->lock_removed === 1) ? 'Yes' : 'No';
                        }

                        $is_orphan = ($outcome_key === 'proceeding' && empty($row->cron_ended_at));
                        ?>
                        <tr<?php echo $is_orphan ? ' style="background:#fff8e5;"' : ''; ?>>
                            <td><?php echo (int) $row->id; ?></td>
                            <td><?php echo esc_html($row->cron_started_at); ?></td>
                            <td>
                                <?php if (!empty($row->cron_ended_at)): ?>
                                    <?php echo esc_html($row->cron_ended_at); ?>
                                <?php elseif ($outcome_key === 'skipped'): ?>
                                    <span style="color:#646970;">—</span>
                                <?php else: ?>
                                    <span style="color:#b32d2e;" title="Process did not reach shutdown handler — likely timed out or crashed.">In progress / crashed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($state_text); ?></td>
                            <td><?php echo $row->lock_age_minutes !== null ? esc_html($row->lock_age_minutes) : '—'; ?></td>
                            <td><?php echo esc_html($action_text); ?></td>
                            <td style="color:<?php echo esc_attr($outcome_color); ?>;font-weight:600;">
                                <?php echo esc_html($outcome_text); ?>
                            </td>
                            <td><?php echo esc_html($removed_text); ?></td>
                            <td><?php echo esc_html($row->notes); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        // Deferred to DOM-ready: asmData is localized to the asm-admin script,
        // which is enqueued in_footer = true. An inline IIFE here would run
        // during body parse — before the footer — and silently early-return
        // because window.asmData is not yet defined, leaving every button
        // un-bound. jQuery(fn) waits for DOMContentLoaded, by which point
        // the footer (and asmData) has been parsed.
        jQuery(function ($) {
            if (!window.asmData || !window.asmData.ajax_url) {
                if (window.console && console.warn) {
                    console.warn('ASM lock log: asmData not available; admin.js may have failed to enqueue.');
                }
                return;
            }

            var $status = $('#asm-lock-log-status');

            function showStatus(message, isError) {
                $status.html(
                    '<div class="notice ' + (isError ? 'notice-error' : 'notice-success') + ' inline" style="padding:8px 12px;margin:0;">' +
                    '<p style="margin:0;">' + $('<div>').text(message).html() + '</p>' +
                    '</div>'
                );
            }

            // Toggle logging on/off
            $('#asm-lock-log-toggle').on('change', function () {
                var enabled = this.checked ? '1' : '0';
                var $cb = $(this);
                $cb.prop('disabled', true);

                $.post(asmData.ajax_url, {
                    action:  'asm_toggle_lock_log',
                    nonce:   asmData.nonce,
                    enabled: enabled
                }).done(function (resp) {
                    if (resp && resp.success) {
                        var on = !!resp.data.enabled;
                        $('#asm-lock-log-toggle-label')
                            .text(on ? 'Enabled' : 'Disabled')
                            .css('color', on ? '#1d7a3a' : '#b32d2e');
                        showStatus(resp.data.message || 'Updated.', false);
                    } else {
                        // Revert visual state on failure
                        $cb.prop('checked', !$cb.prop('checked'));
                        showStatus((resp && resp.data) ? resp.data : 'Toggle failed.', true);
                    }
                }).fail(function () {
                    $cb.prop('checked', !$cb.prop('checked'));
                    showStatus('Network error while toggling logging.', true);
                }).always(function () {
                    $cb.prop('disabled', false);
                });
            });

            // Save stale lock age
            $('#asm-lock-stale-save').on('click', function () {
                var $btn = $(this);
                var minutes = $('#asm-lock-stale-minutes').val();
                $btn.prop('disabled', true).text('Saving…');

                $.post(asmData.ajax_url, {
                    action:  'asm_save_lock_stale_minutes',
                    nonce:   asmData.nonce,
                    minutes: minutes
                }).done(function (resp) {
                    if (resp && resp.success) {
                        showStatus(resp.data.message || 'Saved.', false);
                        // Reload so the "Currently effective" label re-renders correctly.
                        setTimeout(function () { window.location.reload(); }, 600);
                    } else {
                        showStatus((resp && resp.data) ? resp.data : 'Save failed.', true);
                    }
                }).fail(function () {
                    showStatus('Network error while saving stale lock age.', true);
                }).always(function () {
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // Clear all logs
            $('#asm-lock-log-clear').on('click', function () {
                if (!window.confirm('Delete ALL cron lock log entries? This cannot be undone.')) {
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing…');

                $.post(asmData.ajax_url, {
                    action: 'asm_clear_lock_log',
                    nonce:  asmData.nonce
                }).done(function (resp) {
                    if (resp && resp.success) {
                        showStatus(resp.data.message || 'Cleared.', false);
                        setTimeout(function () { window.location.reload(); }, 600);
                    } else {
                        showStatus((resp && resp.data) ? resp.data : 'Clear failed.', true);
                        $btn.prop('disabled', false).text('Clear All Logs');
                    }
                }).fail(function () {
                    showStatus('Network error while clearing logs.', true);
                    $btn.prop('disabled', false).text('Clear All Logs');
                });
            });
        });
        </script>
    <?php endif; ?>
</div>

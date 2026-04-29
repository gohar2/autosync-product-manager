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
        <div class="asm-card" style="background:#fff;border:1px solid #c3c4c7;padding:15px 20px;margin-top:15px;">
            <strong>Total entries:</strong> <?php echo (int) $total; ?>
            &nbsp;|&nbsp;
            <strong>Showing:</strong> latest <?php echo count($rows); ?>
        </div>

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
    <?php endif; ?>
</div>

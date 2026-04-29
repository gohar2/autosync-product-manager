<?php
/**
 * Cron Lock Log
 *
 * One row per price/stock cron invocation. Captures lock-file lifecycle
 * (state on entry, action taken, outcome, removal on exit) so the admin
 * panel can show whether overlap protection is actually firing.
 *
 * @package AutoSync_Product_Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASM_Cron_Lock_Log {

    private static $table_name = 'asm_cron_lock_log';

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cron_started_at datetime NOT NULL,
            cron_ended_at datetime DEFAULT NULL,
            lock_state varchar(32) NOT NULL,
            lock_age_minutes decimal(10,2) DEFAULT NULL,
            action_taken varchar(64) NOT NULL,
            process_outcome varchar(32) NOT NULL,
            lock_removed tinyint(1) DEFAULT NULL,
            notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY cron_started_at (cron_started_at),
            KEY process_outcome (process_outcome)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Record cron start. Returns the inserted row id, or 0 on failure.
     *
     * @param string     $lock_state       'not_found' | 'found_active' | 'found_stale'
     * @param string     $action_taken     'created' | 'cleared_stale_and_created' | 'skipped'
     * @param string     $process_outcome  'proceeding' | 'skipped'
     * @param float|null $lock_age_minutes Age of pre-existing lock in minutes
     * @param string     $notes
     */
    public static function log_start($lock_state, $action_taken, $process_outcome, $lock_age_minutes = null, $notes = '') {
        global $wpdb;

        $table = self::get_table_name();

        $data = array(
            'cron_started_at' => current_time('mysql'),
            'lock_state'      => $lock_state,
            'action_taken'    => $action_taken,
            'process_outcome' => $process_outcome,
            'notes'           => $notes,
        );
        $format = array('%s', '%s', '%s', '%s', '%s');

        if ($lock_age_minutes !== null) {
            $data['lock_age_minutes'] = round((float) $lock_age_minutes, 2);
            $format[] = '%f';
        }

        $inserted = $wpdb->insert($table, $data, $format);

        // Self-heal: if the table is missing on a live site (e.g. plugin updated
        // in place without reactivation), create it and retry once.
        if ($inserted === false) {
            self::create_table();
            $inserted = $wpdb->insert($table, $data, $format);
        }

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Record cron end (called from shutdown handler when sync proceeded).
     *
     * @param int    $log_id
     * @param bool   $lock_removed
     * @param string $notes Appended to existing notes.
     */
    public static function log_end($log_id, $lock_removed, $notes = '') {
        global $wpdb;

        if (!$log_id) {
            return false;
        }

        $table = self::get_table_name();

        $existing_notes = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT notes FROM {$table} WHERE id = %d", $log_id)
        );

        $combined_notes = $existing_notes;
        if ($notes !== '') {
            $combined_notes = trim($existing_notes . ($existing_notes !== '' ? ' | ' : '') . $notes);
        }

        return $wpdb->update(
            $table,
            array(
                'cron_ended_at'   => current_time('mysql'),
                'lock_removed'    => $lock_removed ? 1 : 0,
                'process_outcome' => 'completed',
                'notes'           => $combined_notes,
            ),
            array('id' => $log_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Fetch most-recent log rows for the admin panel.
     */
    public static function get_recent($limit = 100) {
        global $wpdb;
        $table = self::get_table_name();
        $limit = max(1, (int) $limit);
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}");
    }

    public static function get_total_count() {
        global $wpdb;
        $table = self::get_table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}

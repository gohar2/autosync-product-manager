<?php
/**
 * Size cap for the external cron log.
 *
 * The runners are scheduled as `php cron-runner.php >> .../asm-cron-external.log 2>&1`, so the
 * shell, not PHP, owns the log handle. We rotate by copy-then-truncate: the shell opened the file
 * with O_APPEND, so its handle keeps writing at the new end of file and no crontab change is needed.
 *
 * Tunables (environment):
 *   ASM_CRON_LOG_MAX_MB  cap before rotating (default 50)
 *   ASM_CRON_LOG         extra log path to cap, if it can't be detected from stdout/stderr
 *
 * @package AutoSync_Product_Manager
 */

if (!function_exists('asm_cron_rotate_log')) {
    function asm_cron_rotate_log() {
        $max_mb = (int) getenv('ASM_CRON_LOG_MAX_MB');
        $max_bytes = ($max_mb > 0 ? $max_mb : 50) * 1048576;
        $keep = 3;

        // Wherever stdout/stderr are redirected (Linux), plus the plugin-root default.
        $paths = array();
        foreach (array(1, 2) as $fd) {
            $target = @readlink('/proc/self/fd/' . $fd);
            if ($target && $target[0] === '/') {
                $paths[] = $target;
            }
        }
        if (getenv('ASM_CRON_LOG')) {
            $paths[] = getenv('ASM_CRON_LOG');
        }
        $paths[] = dirname(__DIR__) . '/asm-cron-external.log';

        foreach (array_unique($paths) as $path) {
            clearstatcache(true, $path);
            if (!is_file($path) || !is_writable($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size <= $max_bytes) {
                continue;
            }

            // Both runners share one log; only one of them rotates at a time.
            $lock = @fopen($path . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                continue;
            }

            $gz = function_exists('gzopen');
            $ext = $gz ? '.gz' : '';
            @unlink($path . '.' . $keep . $ext);
            for ($i = $keep - 1; $i >= 1; $i--) {
                if (file_exists($path . '.' . $i . $ext)) {
                    @rename($path . '.' . $i . $ext, $path . '.' . ($i + 1) . $ext);
                }
            }

            // Archive at most the last $max_bytes so a multi-GB log doesn't stall the run.
            $src = @fopen($path, 'rb');
            $dst = $gz ? @gzopen($path . '.1.gz', 'wb6') : @fopen($path . '.1', 'wb');
            if ($src && $dst) {
                $skipped = max(0, $size - $max_bytes);
                fseek($src, $skipped);
                $write = $gz ? 'gzwrite' : 'fwrite';
                if ($skipped > 0) {
                    $write($dst, '[asm log rotate: first ' . round($skipped / 1048576) . " MB discarded]\n");
                }
                while (!feof($src)) {
                    $write($dst, fread($src, 1048576));
                }
            }
            if ($src) {
                fclose($src);
            }
            if ($dst) {
                $gz ? gzclose($dst) : fclose($dst);
            }

            $fh = @fopen($path, 'r+');
            if ($fh) {
                ftruncate($fh, 0);
                fclose($fh);
            }

            flock($lock, LOCK_UN);
            fclose($lock);

            echo '[' . date('Y-m-d H:i:s') . '] ASM cron log rotated (' . round($size / 1048576) . ' MB -> ' . basename($path) . '.1' . $ext . ")\n";
        }
    }
}

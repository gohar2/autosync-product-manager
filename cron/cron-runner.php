<?php
/**
 * External Cron Runner for AutoSync Product Manager
 * 
 * This file can be called directly via server cron (crontab) or via HTTP
 * 
 * Usage:
 * - Via crontab: php /home/username/staging2.sdtireandwheel.com/public_html/wp-content/plugins/autosync-product-manager/cron/cron-runner.php
 * - Via HTTP (WordPress endpoint - recommended): curl https://staging2.sdtireandwheel.com/?asm_cron_run=1&key=YOUR_SECRET_KEY
 * - Via HTTP (direct PHP - if server allows): curl https://staging2.sdtireandwheel.com/wp-content/plugins/autosync-product-manager/cron/cron-runner.php?key=YOUR_SECRET_KEY
 * - Via wget: wget -q -O - https://staging2.sdtireandwheel.com/?asm_cron_run=1&key=YOUR_SECRET_KEY
 * 
 * IMPORTANT: Change the $expected_key value below for security!
 */

// Security: Only allow execution from command line or with secret key
$allowed = false;

// Allow if running from CLI
if (php_sapi_name() === 'cli') {
    $allowed = true;
}

// Allow if secret key is provided (for HTTP access)
$secret_key = '';
if (getenv('ASM_CRON_SECRET')) {
    $secret_key = getenv('ASM_CRON_SECRET');
} elseif (isset($_GET['key'])) {
    $secret_key = trim($_GET['key']);
} elseif (isset($_REQUEST['key'])) {
    $secret_key = trim($_REQUEST['key']);
}

// CHANGE THIS SECRET KEY TO SOMETHING SECURE!
$expected_key = 'your-very-secure-secret-key-here';

if (!empty($secret_key) && $secret_key === $expected_key) {
    $allowed = true;
}

if (!$allowed) {
    if (php_sapi_name() === 'cli') {
        die("Access denied. This script must be run from command line or with valid secret key.\n");
    } else {
        http_response_code(403);
        header('Content-Type: text/plain');
        $error = "Access denied. Use CLI or provide valid secret key via ?key= parameter.\n\n";
        $error .= "Expected key: your-very-secure-secret-key-here\n";
        $error .= "Key provided: " . (!empty($secret_key) ? "'" . htmlspecialchars($secret_key, ENT_QUOTES) . "'" : "NONE") . "\n";
        $error .= "\nCorrect URL format:\n";
        $error .= "https://staging2.sdtireandwheel.com/wp-content/plugins/autosync-product-manager/cron/cron-runner.php?key=your-very-secure-secret-key-here\n";
        if (!empty($secret_key) && $secret_key !== $expected_key) {
            $error .= "\nNote: Key provided but doesn't match. Check for extra spaces or typos.\n";
        }
        die($error);
    }
}

// Load WordPress
// Try multiple possible paths.
// NOTE: This script is typically located at:
//   /home/customer/www/staging7.sdtireandwheel.com/public_html/wp-content/plugins/autosync-product-manager/cron/cron-runner.php
// Adjust or extend these paths if your structure is different.
$possible_paths = array(
    // Explicit path for this staging7 site
    '/home/customer/www/staging7.sdtireandwheel.com/public_html/wp-load.php',

    // Generic relatives from the cron directory:
    // cron → plugin → plugins → wp-content → public_html
    dirname(__DIR__, 4) . '/wp-load.php',  // /public_html/wp-load.php
    dirname(__DIR__, 3) . '/wp-load.php',  // /wp-content/wp-load.php (fallback)
    dirname(__DIR__, 5) . '/wp-load.php',  // one level higher, in case of custom structure
);

$wp_load_path = null;
$checked_paths = array();
foreach ($possible_paths as $path) {
    $checked_paths[] = $path;
    if (file_exists($path) && is_readable($path)) {
        $wp_load_path = $path;
        break;
    }
}

if (!$wp_load_path) {
    $error = "WordPress not found. Please check the path.\n\n";
    $error .= "Current file location: " . __FILE__ . "\n";
    $error .= "Current directory: " . getcwd() . "\n";
    $error .= "Current user: " . get_current_user() . "\n\n";
    $error .= "Checked paths:\n";
    foreach ($checked_paths as $path) {
        $exists = file_exists($path) ? "EXISTS" : "NOT FOUND";
        $readable = file_exists($path) && is_readable($path) ? "READABLE" : "NOT READABLE";
        $error .= "  - $path [$exists, $readable]\n";
    }
    $error .= "\nTo find WordPress path, run:\n";
    $error .= "  find /home -name 'wp-load.php' -path '*/staging7.sdtireandwheel.com/*' 2>/dev/null\n";
    $error .= "  find /var/www -name 'wp-load.php' 2>/dev/null\n";
    die($error);
}

// Check if we can read the file
if (!is_readable($wp_load_path)) {
    die("WordPress file found but not readable: $wp_load_path\n");
}

require_once $wp_load_path;

// Verify WordPress loaded
if (!defined('ABSPATH')) {
    die("WordPress failed to load from: $wp_load_path\n");
}

// Check if we can access WordPress functions
if (!function_exists('get_option')) {
    die("WordPress loaded but functions not available. Check WordPress installation.\n");
}

// Set execution limits for large syncs (80,000+ products)
// @set_time_limit(0); // No time limit
// @ini_set('memory_limit', '1024M'); // 1GB memory
// @ini_set('max_execution_time', '0');
// @ini_set('max_input_time', '0');

// Disable WordPress cron during execution to prevent conflicts
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

// Disable Query Monitor and other debugging tools
if (!defined('QM_DISABLED')) {
    define('QM_DISABLED', true);
}

// Load plugin classes
// __DIR__ points to the "cron" directory; plugin root is one level up.
$plugin_dir = dirname(__DIR__);

if (!class_exists('ASM_Cron_Sync')) {
    require_once $plugin_dir . '/includes/class-cron-sync.php';
}

if (!class_exists('ASM_Logger')) {
    require_once $plugin_dir . '/includes/class-logger.php';
}

if (!class_exists('ASM_Brand_Manager')) {
    require_once $plugin_dir . '/includes/class-brand-manager.php';
}

if (!class_exists('ASM_API_Client')) {
    require_once $plugin_dir . '/includes/class-api-client.php';
}

if (!class_exists('ASM_SQL_Batch_Inserter')) {
    require_once $plugin_dir . '/includes/class-sql-batch-inserter.php';
}

if (!class_exists('ASM_Sync_Status_Manager')) {
    require_once $plugin_dir . '/includes/class-sync-status-manager.php';
}

if (!class_exists('ASM_Email_Notifier')) {
    require_once $plugin_dir . '/includes/class-email-notifier.php';
}

// Log start
$start_time = microtime(true);
$timestamp = date('Y-m-d H:i:s');

if (php_sapi_name() === 'cli') {
    echo "========================================\n";
    echo "AutoSync Product Manager - External Cron\n";
    echo "Started at: {$timestamp}\n";
    echo "========================================\n\n";
} else {
    header('Content-Type: application/json');
}

error_log("ASM External Cron: Starting sync at {$timestamp}");

// Run sync
try {
    $stats = ASM_Cron_Sync::sync_all_active_brands();
    
    $duration = round(microtime(true) - $start_time, 2);
    $end_timestamp = date('Y-m-d H:i:s');
    
    // Output results
    if (php_sapi_name() === 'cli') {
        echo "\n========================================\n";
        echo "Sync completed successfully!\n";
        echo "Completed at: {$end_timestamp}\n";
        echo "Duration: {$duration} seconds\n";
        echo "----------------------------------------\n";
        echo "TIRES:\n";
        echo "  Brands Processed: {$stats['tires']['brands_processed']}\n";
        echo "  Imported: " . number_format($stats['tires']['total_imported']) . "\n";
        echo "  Updated: " . number_format($stats['tires']['total_updated']) . "\n";
        echo "  Skipped: " . number_format($stats['tires']['total_skipped']) . "\n";
        echo "  Pages: {$stats['tires']['total_pages']}\n";
        echo "----------------------------------------\n";
        echo "WHEELS:\n";
        echo "  Brands Processed: {$stats['wheels']['brands_processed']}\n";
        echo "  Imported: " . number_format($stats['wheels']['total_imported']) . "\n";
        echo "  Updated: " . number_format($stats['wheels']['total_updated']) . "\n";
        echo "  Skipped: " . number_format($stats['wheels']['total_skipped']) . "\n";
        echo "  Pages: {$stats['wheels']['total_pages']}\n";
        echo "========================================\n";
    } else {
        echo json_encode(array(
            'success' => true,
            'started_at' => $timestamp,
            'completed_at' => $end_timestamp,
            'duration_seconds' => $duration,
            'stats' => array(
                'tires' => array(
                    'brands_processed' => $stats['tires']['brands_processed'],
                    'imported' => $stats['tires']['total_imported'],
                    'updated' => $stats['tires']['total_updated'],
                    'skipped' => $stats['tires']['total_skipped'],
                    'pages' => $stats['tires']['total_pages']
                ),
                'wheels' => array(
                    'brands_processed' => $stats['wheels']['brands_processed'],
                    'imported' => $stats['wheels']['total_imported'],
                    'updated' => $stats['wheels']['total_updated'],
                    'skipped' => $stats['wheels']['total_skipped'],
                    'pages' => $stats['wheels']['total_pages']
                )
            )
        ), JSON_PRETTY_PRINT);
    }
    
    error_log("ASM External Cron: Sync completed successfully in {$duration} seconds");
    exit(0);
    
} catch (Exception $e) {
    $error_msg = 'ASM External Cron: Error - ' . $e->getMessage();
    
    // Always log full details to error_log
    error_log($error_msg);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Trace: " . $e->getTraceAsString());
    }
    
    if (php_sapi_name() === 'cli') {
        // CLI can show full details
        echo "\n========================================\n";
        echo "ERROR: Sync failed!\n";
        echo "Error: " . $e->getMessage() . "\n";
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo "File: " . $e->getFile() . "\n";
            echo "Line: " . $e->getLine() . "\n";
        }
        echo "========================================\n";
    } else {
        // HTTP responses: generic in production, detailed in debug
        http_response_code(500);
        $response = array(
            'success' => false,
            'error' => defined('WP_DEBUG') && WP_DEBUG 
                ? $e->getMessage() 
                : 'An error occurred during sync. Please check logs.',
            'timestamp' => date('Y-m-d H:i:s')
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $response['file'] = $e->getFile();
            $response['line'] = $e->getLine();
            $response['trace'] = $e->getTraceAsString();
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
    }
    exit(1);
    
} catch (Error $e) {
    $error_msg = 'ASM External Cron: Fatal Error - ' . $e->getMessage();
    
    // Always log full details to error_log
    error_log($error_msg);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Trace: " . $e->getTraceAsString());
    }
    
    if (php_sapi_name() === 'cli') {
        // CLI can show full details
        echo "\n========================================\n";
        echo "FATAL ERROR: Sync failed!\n";
        echo "Error: " . $e->getMessage() . "\n";
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo "File: " . $e->getFile() . "\n";
            echo "Line: " . $e->getLine() . "\n";
        }
        echo "========================================\n";
    } else {
        // HTTP responses: generic in production, detailed in debug
        http_response_code(500);
        $response = array(
            'success' => false,
            'error' => defined('WP_DEBUG') && WP_DEBUG 
                ? 'Fatal error: ' . $e->getMessage()
                : 'A fatal error occurred during sync. Please check logs.',
            'timestamp' => date('Y-m-d H:i:s')
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $response['file'] = $e->getFile();
            $response['line'] = $e->getLine();
            $response['trace'] = $e->getTraceAsString();
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
    }
    exit(1);
}


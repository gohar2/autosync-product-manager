<?php
/**
 * Plugin Name: AutoSync Product Manager
 * Plugin URI: https://sdtireandwheel.com
 * Description: Simple product synchronization from AutoSync Studio API using external cron endpoints and extra product deletion functionality.
 * Version: 6.1
 * Author: Syed
 * Author URI: https://sdtireandwheel.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * Text Domain: autosync-manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ASM_VERSION', '0.0.9');
define('ASM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class AutoSync_Product_Manager {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Tracks the one-time cleanup that removes schedules created by older versions.
     */
    private const LEGACY_CRON_CLEANUP_OPTION = 'asm_internal_wp_cron_disabled_at';

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once ASM_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-product-importer.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-generator.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-cron-sync.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-product-deleter.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-image-downloader.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-email-notifier.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-slack-notifier.php';

        $this->api_client = new ASM_API_Client();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Public cron endpoint (accessible via HTTP)
        add_action('init', array($this, 'handle_public_cron_endpoint'));
        
        // Register WooCommerce email class (after WooCommerce is loaded)
        // Use plugins_loaded with priority to ensure WooCommerce is available
        add_action('plugins_loaded', array($this, 'load_wc_email_class'), 20);
        add_filter('woocommerce_email_classes', array($this, 'register_wc_email_class'));

        // AJAX handlers
        add_action('wp_ajax_asm_test_api', array($this, 'ajax_test_api'));
        // add_action('wp_ajax_asm_import_test_product', array($this, 'ajax_import_test_product'));
        // add_action('wp_ajax_asm_import_test_tire', array($this, 'ajax_import_test_tire'));
        add_action('wp_ajax_asm_bulk_import', array($this, 'ajax_bulk_import'));
        add_action('wp_ajax_asm_bulk_import_tires', array($this, 'ajax_bulk_import_tires'));
        add_action('wp_ajax_asm_batch_insert', array($this, 'ajax_batch_insert'));
        add_action('wp_ajax_asm_batch_insert_optimized', array($this, 'ajax_batch_insert_optimized'));
        add_action('wp_ajax_asm_rebuild_hash_table', array($this, 'ajax_rebuild_hash_table'));
        add_action('wp_ajax_asm_truncate_hash_table', array($this, 'ajax_truncate_hash_table'));
        add_action('wp_ajax_asm_empty_price_stock_hash', array($this, 'ajax_empty_price_stock_hash'));
        add_action('wp_ajax_asm_empty_price_stock_hash_batch', array($this, 'ajax_empty_price_stock_hash_batch'));
        add_action('wp_ajax_asm_check_duplicate_skus', array($this, 'ajax_check_duplicate_skus'));
        add_action('wp_ajax_asm_delete_duplicate_product', array($this, 'ajax_delete_duplicate_product'));
        add_action('wp_ajax_asm_bulk_delete_duplicates', array($this, 'ajax_bulk_delete_duplicates'));
        add_action('wp_ajax_asm_bulk_delete_duplicates_batch', array($this, 'ajax_bulk_delete_duplicates_batch'));
        add_action('wp_ajax_asm_sync_inventory_quantity', array($this, 'ajax_sync_inventory_quantity'));
        add_action('wp_ajax_asm_sync_inventory_quantity_batch', array($this, 'ajax_sync_inventory_quantity_batch'));
        
        // Brand management AJAX handlers
        add_action('wp_ajax_asm_fetch_brands', array($this, 'ajax_fetch_brands'));
        add_action('wp_ajax_asm_update_brand_status', array($this, 'ajax_update_brand_status'));
        add_action('wp_ajax_asm_batch_update_brand_status', array($this, 'ajax_batch_update_brand_status'));
        add_action('wp_ajax_asm_get_activated_brands', array($this, 'ajax_get_activated_brands'));
        add_action('wp_ajax_asm_save_sync_status', array($this, 'ajax_save_sync_status'));
        add_action('wp_ajax_asm_get_sync_status', array($this, 'ajax_get_sync_status'));
        add_action('wp_ajax_asm_check_and_fetch_brands', array($this, 'ajax_check_and_fetch_brands'));
        add_action('wp_ajax_asm_test_slack', array($this, 'ajax_test_slack'));
        add_action('wp_ajax_asm_delete_products_by_brand', array($this, 'ajax_delete_products_by_brand'));
        add_action('wp_ajax_asm_get_brand_product_counts', array($this, 'ajax_get_brand_product_counts'));
        add_action('wp_ajax_asm_delete_extra_products', array($this, 'ajax_delete_extra_products'));

        // Hook into product deletion to clean up hashes
        add_action('before_delete_post', array($this, 'delete_product_hash_on_deletion'), 10, 1);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Remove any WP-Cron events scheduled by older plugin versions.
        add_action('init', array($this, 'disable_internal_cron_events'), 1);
        
        // Handle activation redirect
        add_action('admin_init', array($this, 'handle_activation_redirect'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'AutoSync Manager',
            'AutoSync Manager',
            'manage_options',
            'autosync-manager',
            array($this, 'render_settings_page'),
            'dashicons-update',
            56
        );

        // Add Hash Management submenu
        add_submenu_page(
            'autosync-manager',
            'Hash Management',
            'Hash Management',
            'manage_options',
            'autosync-hash-management',
            array($this, 'render_hash_management_page')
        );

        // Add Brand Management submenu
        add_submenu_page(
            'autosync-manager',
            'Brand Management',
            'Brand Management',
            'manage_options',
            'autosync-brand-management',
            array($this, 'render_brand_management_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Load assets for main settings page and hash management page
        $allowed_hooks = array(
            'toplevel_page_autosync-manager',
            'autosync-manager_page_autosync-hash-management',
            'autosync-manager_page_autosync-brand-management'
        );
        
        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        wp_enqueue_style(
            'asm-admin',
            ASM_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            ASM_VERSION
        );

        // Ensure jQuery is loaded
        wp_enqueue_script('jquery');

        wp_enqueue_script(
            'asm-admin',
            ASM_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            ASM_VERSION,
            true
        );

        wp_localize_script('asm-admin', 'asmData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asm_nonce'),
            'plugin_url' => ASM_PLUGIN_URL,
        ));
    }

    /**
     * Handle price/stock sync endpoint
     */
    private function handle_price_stock_sync_endpoint() {
        // Check secret key
        $secret_key = isset($_GET['key']) ? trim($_GET['key']) : '';
        $expected_key = 'your-very-secure-secret-key-here'; // CHANGE THIS!

        if (empty($secret_key) || $secret_key !== $expected_key) {
            http_response_code(403);
            header('Content-Type: text/plain');
            die('Access denied. Invalid or missing secret key.');
        }

        // Set execution limits
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '0');
        @ini_set('max_input_time', '0');

        // Load required class
        require_once ASM_PLUGIN_DIR . 'includes/class-price-stock-sync.php';

        // Log start
        error_log('ASM Price/Stock Sync Endpoint: Starting sync at ' . date('Y-m-d H:i:s'));

        $start_time = microtime(true);

        try {
            // Run price/stock sync (it will save status internally)
            $stats = ASM_Price_Stock_Sync::sync_all_brands();

            $duration = round(microtime(true) - $start_time, 2);

            error_log('ASM Price/Stock Sync Endpoint: Completed in ' . $duration . ' seconds');
            error_log('ASM Price/Stock Sync: Tires - ' . ($stats['tires']['total_updated'] ?? 0) . ' updated, ' . ($stats['tires']['total_skipped'] ?? 0) . ' skipped');
            error_log('ASM Price/Stock Sync: Wheels - ' . ($stats['wheels']['total_updated'] ?? 0) . ' updated, ' . ($stats['wheels']['total_skipped'] ?? 0) . ' skipped');

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(array(
                'success' => true,
                'message' => 'Price/Stock sync completed',
                'stats' => $stats,
                'duration' => $duration,
                'timestamp' => date('Y-m-d H:i:s')
            ), JSON_PRETTY_PRINT);
            exit(0);

        } catch (Exception $e) {
            $error_msg = 'ASM Price/Stock Sync Endpoint: Error - ' . $e->getMessage();
            error_log($error_msg);
            
            // Log full trace only in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Trace: " . $e->getTraceAsString());
            }

            http_response_code(500);
            header('Content-Type: application/json');
            
            $response = array(
                'success' => false,
                'error' => defined('WP_DEBUG') && WP_DEBUG 
                    ? $e->getMessage() 
                    : 'An error occurred during price/stock sync. Please check logs.',
                'timestamp' => date('Y-m-d H:i:s')
            );
            
            // Include trace only in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $response['trace'] = $e->getTraceAsString();
                $response['file'] = $e->getFile();
                $response['line'] = $e->getLine();
            }
            
            echo json_encode($response, JSON_PRETTY_PRINT);
            exit(1);
        }
    }

    /**
     * Handle public cron endpoint (accessible via HTTP)
     * 
     * Usage: https://staging2.sdtireandwheel.com/?asm_cron_run=1&key=your-very-secure-secret-key-here
     * For price/stock sync: https://staging2.sdtireandwheel.com/?asm_price_stock_sync=1&key=your-very-secure-secret-key-here
     * 
     * Note: For large syncs (80,000+ products), this endpoint returns immediately
     * and runs the sync in the background to avoid 504 Gateway Timeout errors.
     * Use CLI method (cron/cron-runner.php) for best results.
     */
    public function handle_public_cron_endpoint() {
        // Check for price/stock sync endpoint
        if (isset($_GET['asm_price_stock_sync']) && $_GET['asm_price_stock_sync'] === '1') {
            $this->handle_price_stock_sync_endpoint();
            return;
        }
        
        // Only process if the query parameter is set
        if (!isset($_GET['asm_cron_run']) || $_GET['asm_cron_run'] !== '1') {
            return;
        }

        // Check secret key
        $secret_key = isset($_GET['key']) ? trim($_GET['key']) : '';
        $expected_key = 'your-very-secure-secret-key-here'; // CHANGE THIS!

        if (empty($secret_key) || $secret_key !== $expected_key) {
            http_response_code(403);
            header('Content-Type: text/plain');
            die('Access denied. Invalid or missing secret key.');
        }

        // Check if async mode is requested (default for large syncs)
        $async = !isset($_GET['sync']) || $_GET['sync'] !== 'wait';
        
        if ($async) {
            // Return immediately and run in background to avoid 504 timeout
            // This is recommended for large syncs (80,000+ products)
            
            // Send immediate response
            header('Content-Type: application/json');
            header('Connection: close');
            ignore_user_abort(true);
            
            $response = array(
                'success' => true,
                'message' => 'Sync started in background. This may take several hours for large product counts.',
                'started_at' => date('Y-m-d H:i:s'),
                'note' => 'Check WooCommerce logs or sync status in admin panel for progress.',
                'recommendation' => 'For best results with 80,000+ products, use CLI method: php cron/cron-runner.php'
            );
            
            echo json_encode($response, JSON_PRETTY_PRINT);
            
            // Flush output and close connection
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                // For non-FastCGI servers
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }
            
            // Now run the sync in background
            $this->run_background_sync();
            exit(0);
        } else {
            // Synchronous mode (for testing small syncs only)
            // WARNING: This will timeout on large syncs!
            
            // Set execution limits
            @set_time_limit(0);
            @ini_set('memory_limit', '1024M');
            @ini_set('max_execution_time', '0');
            @ini_set('max_input_time', '0');
            
            // Disable WordPress cron during execution
            if (!defined('DISABLE_WP_CRON')) {
                define('DISABLE_WP_CRON', true);
            }
            
            // Load required classes
            if (!class_exists('ASM_Cron_Sync')) {
                require_once ASM_PLUGIN_DIR . 'includes/class-cron-sync.php';
                require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';
                require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
                require_once ASM_PLUGIN_DIR . 'includes/class-api-client.php';
                require_once ASM_PLUGIN_DIR . 'includes/class-sql-batch-inserter.php';
                require_once ASM_PLUGIN_DIR . 'includes/class-sync-status-manager.php';
                require_once ASM_PLUGIN_DIR . 'includes/class-product-deleter.php';
            }
            
            // Log start
            error_log('ASM Public Cron Endpoint: Starting sync at ' . date('Y-m-d H:i:s'));
            
            $start_time_overall = microtime(true);
            
            // Run sync
            try {
                $stats = ASM_Cron_Sync::sync_all_active_brands();
                $end_time_overall = microtime(true);
                $duration_overall = round($end_time_overall - $start_time_overall, 2);
                
                // Add duration to stats for email
                $stats['duration'] = $duration_overall;
                
                // Send email notification
                ASM_Email_Notifier::send_sync_email('all', $stats);
                
                // Send Slack notification
                try {
                    ASM_Slack_Notifier::send_sync_notification('all', $stats);
                } catch (Exception $e) {
                    error_log('ASM Public Cron: Exception sending Slack notification: ' . $e->getMessage());
                }
                
                // Output results as JSON
                header('Content-Type: application/json');
                echo json_encode(array(
                    'success' => true,
                    'started_at' => date('Y-m-d H:i:s', $start_time_overall),
                    'completed_at' => date('Y-m-d H:i:s', $end_time_overall),
                    'duration_seconds' => $duration_overall,
                    'stats' => $stats
                ), JSON_PRETTY_PRINT);
                
                error_log('ASM Public Cron Endpoint: Sync completed successfully');
                exit(0);
                
            } catch (Exception $e) {
                $error_msg = 'ASM Public Cron Endpoint: Error - ' . $e->getMessage();
                error_log($error_msg);
                
                // Log full trace only in debug mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Trace: " . $e->getTraceAsString());
                }
                
                http_response_code(500);
                header('Content-Type: application/json');
                
                $response = array(
                    'success' => false,
                    'error' => defined('WP_DEBUG') && WP_DEBUG 
                        ? $e->getMessage() 
                        : 'An error occurred during sync. Please check logs.',
                    'timestamp' => date('Y-m-d H:i:s')
                );
                
                // Include trace only in debug mode
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $response['trace'] = $e->getTraceAsString();
                    $response['file'] = $e->getFile();
                    $response['line'] = $e->getLine();
                }
                
                echo json_encode($response, JSON_PRETTY_PRINT);
                exit(1);
            }
        }
    }
    
    /**
     * Run sync in background (called after HTTP response is sent)
     */
    private function run_background_sync() {
        // Set execution limits for large syncs
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '0');
        @ini_set('max_input_time', '0');
        
        // Disable WordPress cron during execution
        if (!defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }
        
        // Load required classes if not already loaded
        if (!class_exists('ASM_Cron_Sync')) {
            require_once ASM_PLUGIN_DIR . 'includes/class-cron-sync.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-api-client.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-sql-batch-inserter.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-sync-status-manager.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-product-deleter.php';
        }
        
        // Log start
        error_log('ASM Public Cron Endpoint (Background): Starting sync at ' . date('Y-m-d H:i:s'));
        
        $start_time_overall = microtime(true);
        
        // Run sync
        try {
            $stats = ASM_Cron_Sync::sync_all_active_brands();
            $end_time_overall = microtime(true);
            $duration_overall = round($end_time_overall - $start_time_overall, 2);
            
            // Add duration to stats for email
            $stats['duration'] = $duration_overall;
            
            // Send email notification
            ASM_Email_Notifier::send_sync_email('all', $stats);
            
            error_log('ASM Public Cron Endpoint (Background): Sync completed successfully');
            error_log('ASM Public Cron Endpoint (Background): Duration: ' . $duration_overall . ' seconds');
            error_log('ASM Public Cron Endpoint (Background): Stats: ' . json_encode($stats));
            
        } catch (Exception $e) {
            $error_msg = 'ASM Public Cron Endpoint (Background): Error - ' . $e->getMessage();
            error_log($error_msg);
            // Log full trace only in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ASM Public Cron Endpoint (Background): Trace: ' . $e->getTraceAsString());
            }
        }
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API settings with explicit sanitize callbacks
        register_setting('asm_settings', 'asm_api_key', array(
            'sanitize_callback' => array($this, 'sanitize_api_key')
        ));
        register_setting('asm_settings', 'asm_api_url', array(
            'sanitize_callback' => array($this, 'sanitize_api_url')
        ));

        // Sync settings
        register_setting('asm_sync_settings', 'asm_product_import_mode');
        register_setting('asm_sync_settings', 'asm_image_mode');
        register_setting('asm_sync_settings', 'asm_slack_webhook_url', array(
            'sanitize_callback' => array($this, 'sanitize_webhook_url')
        ));
        register_setting('asm_sync_settings', 'asm_slack_enabled');

        // Set defaults
        if (false === get_option('asm_product_import_mode')) {
            add_option('asm_product_import_mode', 'both');
        }
        if (false === get_option('asm_image_mode')) {
            add_option('asm_image_mode', 'external');
        }
        if (false === get_option('asm_slack_enabled')) {
            // Default: Slack notifications ON
            add_option('asm_slack_enabled', '1');
        }
    }

    /**
     * Sanitize API key setting
     * 
     * @param string $value The API key value to sanitize
     * @return string Sanitized API key
     */
    public function sanitize_api_key($value) {
        return sanitize_text_field($value);
    }

    /**
     * Sanitize API URL setting
     * 
     * @param string $value The API URL value to sanitize
     * @return string Sanitized API URL
     */
    public function sanitize_api_url($value) {
        $url = esc_url_raw($value);
        // Validate it's a proper URL
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            add_settings_error('asm_api_url', 'invalid_url', 'Invalid API URL format.');
            return get_option('asm_api_url'); // Return existing value on error
        }
        return $url;
    }

    /**
     * Sanitize webhook URL setting
     * 
     * @param string $value The webhook URL value to sanitize
     * @return string Sanitized webhook URL
     */
    public function sanitize_webhook_url($value) {
        $url = esc_url_raw($value);
        // Validate it's a proper URL (webhooks should be HTTPS)
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            add_settings_error('asm_slack_webhook_url', 'invalid_webhook', 'Invalid webhook URL format.');
            return get_option('asm_slack_webhook_url'); // Return existing value on error
        }
        return $url;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        include ASM_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * AJAX: Test API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $api_key = sanitize_text_field($_POST['api_key'] ?? '');

        if (empty($api_key)) {
            wp_send_json_error('API key is required');
        }

        // Test the connection
        $this->api_client->set_api_key($api_key);
        $result = $this->api_client->test_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }


    /**
     * AJAX: Test Slack notification
     */
    /*
    public function ajax_test_slack() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = ASM_Slack_Notifier::send_test_notification();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }*/

    /**
     * AJAX: Import test product
     */
    /*
    public function ajax_import_test_product() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Get a single wheel from API
        $result = $this->api_client->get_single_wheel();

        if (!$result) {
            wp_send_json_error('Failed to fetch product from API');
        }

        // Import the product
        $importer = new ASM_Product_Importer();
        $product_id = $importer->create_wheel_product($result['product'], $result['image_urls']);

        if (!$product_id) {
            wp_send_json_error('Failed to create product');
        }

        // Get product details
        $product = wc_get_product($product_id);

        // Send email notification
        ASM_Email_Notifier::send_sync_email('single', array(
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => $product->get_regular_price(),
            'stock' => $product->get_stock_quantity()
        ));

        wp_send_json_success(array(
            'message' => 'Product imported successfully!',
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => $product->get_regular_price(),
            'stock' => $product->get_stock_quantity(),
            'edit_link' => admin_url('post.php?post=' . $product_id . '&action=edit'),
        ));
    }
    */

    /**
     * AJAX: Import test tire product
     */
    /*
    public function ajax_import_test_tire() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Get a single tire from API
        $result = $this->api_client->get_single_tire();

        if (!$result) {
            wp_send_json_error('Failed to fetch tire from API');
        }

        // Import the tire product
        $importer = new ASM_Product_Importer();
        $product_id = $importer->create_tire_product($result['product'], $result['image_urls']);

        if (!$product_id) {
            wp_send_json_error('Failed to create tire product');
        }

        // Get product details
        $product = wc_get_product($product_id);

        // Send email notification
        ASM_Email_Notifier::send_sync_email('single', array(
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => $product->get_regular_price(),
            'stock' => $product->get_stock_quantity()
        ));

        wp_send_json_success(array(
            'message' => 'Tire product imported successfully!',
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => $product->get_regular_price(),
            'stock' => $product->get_stock_quantity(),
            'edit_link' => admin_url('post.php?post=' . $product_id . '&action=edit'),
        ));
    }
    */

    /**
     * AJAX: Bulk import products (FETCH ONLY - optimized for batch processing)
     * Fetches 500 products per request (max API limit) for batch insertion
     */
    public function ajax_bulk_import() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $page_number = isset($_POST['page_number']) ? intval($_POST['page_number']) : 1;
        $page_size = isset($_POST['page_size']) ? intval($_POST['page_size']) : 500; // Max API limit
        $brand_id = isset($_POST['brand_id']) ? intval($_POST['brand_id']) : 0;

        // Limit page size to 500 (max API limit)
        if ($page_size > 500) {
            $page_size = 500;
        }

        // Get products from API - FETCH ONLY, no creation
        $result = $this->api_client->get_wheels($page_number, $page_size, $brand_id);

        if (!$result || empty($result['products'])) {
            wp_send_json_error('No products found from API');
        }

        // Convert products to array format for JSON encoding
        $products_data = array();
        foreach ($result['products'] as $product_data) {
            $product_array = json_decode(json_encode($product_data), true);
            $sku = $product_data->Pn ?? '';
            $products_data[] = array(
                'data' => $product_array,
                'sku' => $sku,
            );
        }

        wp_send_json_success(array(
            'products' => $products_data,
            'image_urls' => $result['image_urls'],
            'total_fetched' => count($result['products']),
            'has_more' => $result['has_more'] === true,
            'current_page' => $page_number,
        ));
    }

    /**
     * AJAX: Optimized batch insert products (500 at a time)
     * High-performance batch insertion with optimizations for 80,000+ products
     */
    public function ajax_batch_insert_optimized() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Increase limits for batch processing
        @set_time_limit(300); // 5 minutes
        @ini_set('memory_limit', '512M');

        // Get products data
        $products_json = isset($_POST['products']) ? $_POST['products'] : '';
        $image_urls = isset($_POST['image_urls']) ? $_POST['image_urls'] : array();
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'wheel';

        // Decode products
        if (is_string($products_json)) {
            $products = json_decode(stripslashes($products_json), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid JSON data: ' . json_last_error_msg());
            }
        } else {
            $products = $products_json;
        }

        if (empty($products) || !is_array($products)) {
            wp_send_json_error('No products provided for batch insert');
        }

        $product_count = count($products);
        $start_time = microtime(true);

        // PERFORMANCE OPTIMIZATIONS
        // Disable WordPress hooks during bulk import
        wp_suspend_cache_addition(true);
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);
        
        // Disable object cache
        global $wp_object_cache;
        $cache_enabled = wp_using_ext_object_cache();
        if ($cache_enabled) {
            wp_suspend_cache_addition(true);
        }

        // Disable WooCommerce hooks that slow down bulk operations
        remove_action('save_post', array('WC_Admin_Meta_Boxes', 'save_meta_boxes'), 20);
        remove_action('woocommerce_process_product_meta', 'WC_Meta_Box_Product_Data::save', 10);
        remove_action('woocommerce_update_product', 'WC_Meta_Box_Product_Data::save', 10);

        // Enable optimized mode (reduces logging)
        define('ASM_OPTIMIZED_BATCH_MODE', true);

        // Use raw SQL batch insertion for maximum performance
        require_once ASM_PLUGIN_DIR . 'includes/class-sql-batch-inserter.php';
        
        $result = ASM_SQL_Batch_Inserter::batch_insert_products($products, $image_urls, $type);
        
        // Ensure all values are integers and validate
        $imported = isset($result['imported']) ? (int) $result['imported'] : 0;
        $updated = isset($result['updated']) ? (int) $result['updated'] : 0;
        $skipped = isset($result['skipped']) ? (int) $result['skipped'] : 0;
        $product_ids = isset($result['product_ids']) ? $result['product_ids'] : array();
        $errors = isset($result['errors']) ? $result['errors'] : array();
        
        // Validate values are not NaN
        if (!is_numeric($imported) || is_nan($imported)) $imported = 0;
        if (!is_numeric($updated) || is_nan($updated)) $updated = 0;
        if (!is_numeric($skipped) || is_nan($skipped)) $skipped = 0;
        
        // Ensure they're integers
        $imported = (int) $imported;
        $updated = (int) $updated;
        $skipped = (int) $skipped;
        
        global $wpdb;
        
        // Log results for debugging
        // ASM_Logger::info('Batch insert results', array(
        //     'imported' => $imported,
        //     'updated' => $updated,
        //     'skipped' => $skipped,
        //     'product_ids_count' => count($product_ids),
        //     'errors_count' => count($errors),
        //     'wpdb_last_error' => $wpdb->last_error ?? 'none',
        //     'wpdb_last_query' => $wpdb->last_query ?? 'none'
        // ));

        // Re-enable optimizations
        wp_suspend_cache_addition(false);
        wp_defer_term_counting(false);
        wp_defer_comment_counting(false);

        // Re-enable WooCommerce hooks
        add_action('save_post', array('WC_Admin_Meta_Boxes', 'save_meta_boxes'), 20);
        add_action('woocommerce_process_product_meta', 'WC_Meta_Box_Product_Data::save', 10);
        add_action('woocommerce_update_product', 'WC_Meta_Box_Product_Data::save', 10);

        // Disable optimized mode
        if (defined('ASM_OPTIMIZED_BATCH_MODE')) {
            define('ASM_OPTIMIZED_BATCH_MODE', false);
        }

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        // Ensure all response values are valid numbers
        $response_imported = (int) $imported;
        $response_updated = (int) $updated;
        $response_skipped = (int) $skipped;
        $response_total = (int) $product_count;
        $response_duration = is_numeric($duration) && !is_nan($duration) ? round($duration, 2) : 0;
        $response_rate = ($response_total > 0 && $response_duration > 0) ? round($response_total / $response_duration, 2) : 0;
        
        // Note: Email notification is sent only after full sync completion (in ajax_save_sync_status)
        // Not sending batch-wise emails to avoid spam
        
        wp_send_json_success(array(
            'imported' => $response_imported,
            'updated' => $response_updated,
            'skipped' => $response_skipped,
            'total_processed' => $response_total,
            'duration_seconds' => $response_duration,
            'products_per_second' => $response_rate,
            'errors' => $errors,
            'product_ids' => array_slice($product_ids, 0, 100),
        ));
    }

    /**
     * AJAX: Batch insert products (500 at a time) - Legacy method
     */
    public function ajax_batch_insert() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            // ASM_Logger::error('Unauthorized batch insert attempt');
            wp_send_json_error('Unauthorized');
        }

        // Get products data - may be JSON encoded
        $products_json = isset($_POST['products']) ? $_POST['products'] : '';
        $image_urls = isset($_POST['image_urls']) ? $_POST['image_urls'] : array();
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'wheel';

        // ASM_Logger::info('Starting batch insert', array(
        //     'type' => $type,
        //     'products_json_length' => strlen($products_json),
        //     'has_image_urls' => !empty($image_urls)
        // ));

        // Decode products if JSON string
        if (is_string($products_json)) {
            $products = json_decode(stripslashes($products_json), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // ASM_Logger::error('JSON decode error', array(
                //     'error' => json_last_error_msg(),
                //     'json_length' => strlen($products_json)
                // ));
                wp_send_json_error('Invalid JSON data: ' . json_last_error_msg());
            }
        } else {
            $products = $products_json;
        }

        if (empty($products) || !is_array($products)) {
            // ASM_Logger::error('No products provided for batch insert', array(
            //     'products_type' => gettype($products),
            //     'products_count' => is_array($products) ? count($products) : 0
            // ));
            wp_send_json_error('No products provided for batch insert');
        }

        $product_count = count($products);
        // ASM_Logger::info('Processing batch insert', array(
        //     'product_count' => $product_count,
        //     'type' => $type
        // ));

        // Enable error reporting for debugging
        $old_error_reporting = error_reporting(E_ALL);
        $old_display_errors = ini_get('display_errors');
        ini_set('display_errors', 0); // Don't display, but log
        
        // Capture any PHP errors
        set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errors) {
            $error_msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
            // ASM_Logger::error($error_msg);
            return false; // Continue with normal error handling
        });

        $importer = new ASM_Product_Importer();
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();
        $product_ids = array();
        $start_time = microtime(true);
        
        // ASM_Logger::info('Starting product import loop', array(
        //     'product_count' => $product_count,
        //     'type' => $type,
        //     'woocommerce_active' => class_exists('WooCommerce')
        // ));

        foreach ($products as $index => $product_item) {
            try {
                // ASM_Logger::info('━━━ Processing Product #' . ($index + 1) . ' ━━━', array(
                //     'index' => $index,
                //     'product_item_type' => gettype($product_item),
                //     'product_item_keys' => is_array($product_item) ? array_keys($product_item) : 'not_array',
                //     'has_data_key' => isset($product_item['data']),
                //     'has_sku_key' => isset($product_item['sku'])
                // ));

                // Handle product data - convert array to object
                if (isset($product_item['data'])) {
                    if (is_array($product_item['data'])) {
                        $product_data = (object) $product_item['data'];
                    } elseif (is_string($product_item['data'])) {
                        $decoded = json_decode(stripslashes($product_item['data']), false);
                        $product_data = $decoded ? $decoded : (object) json_decode($product_item['data'], true);
                    } else {
                        $product_data = $product_item['data'];
                    }
                } else {
                    // Fallback: treat entire item as product data
                    $product_data = is_array($product_item) ? (object) $product_item : $product_item;
                }

                // Ensure product_data is an object
                if (!is_object($product_data)) {
                    $skipped++;
                    $error_msg = '❌ SKIPPED: Invalid product data format at index ' . $index . ' (type: ' . gettype($product_data) . ')';
                    $errors[] = $error_msg;
                    // ASM_Logger::error($error_msg, array(
                    //     'index' => $index,
                    //     'product_item_type' => gettype($product_item),
                    //     'product_data_type' => gettype($product_data),
                    //     'has_data_key' => isset($product_item['data']),
                    //     'product_item_sample' => is_array($product_item) ? array_slice($product_item, 0, 3, true) : $product_item
                    // ));
                    continue;
                }

                // Get SKU
                $sku = isset($product_item['sku']) ? $product_item['sku'] : '';
                if (empty($sku)) {
                    $sku = ($type === 'tire') ? (isset($product_data->PartNumber) ? $product_data->PartNumber : '') : (isset($product_data->Pn) ? $product_data->Pn : '');
                }

                if (empty($sku)) {
                    $skipped++;
                    $error_msg = '❌ SKIPPED: No SKU found at index ' . $index;
                    $errors[] = $error_msg;
                    // ASM_Logger::error($error_msg, array(
                    //     'index' => $index,
                    //     'type' => $type,
                    //     'sku_field' => ($type === 'tire') ? 'PartNumber' : 'Pn',
                    //     'product_data_keys' => is_object($product_data) ? array_keys((array)$product_data) : 'not_object',
                    //     'product_data_sample' => is_object($product_data) ? array_slice((array)$product_data, 0, 10, true) : 'not_object'
                    // ));
                    continue;
                }

                // ASM_Logger::info('✓ Product SKU found', array(
                //     'index' => $index,
                //     'sku' => $sku,
                //     'type' => $type
                // ));

                $existing_id = wc_get_product_id_by_sku($sku);

                // Import product
                // ASM_Logger::info('→ Calling product importer', array(
                //     'index' => $index,
                //     'sku' => $sku,
                //     'type' => $type,
                //     'existing_id' => $existing_id,
                //     'action' => $existing_id ? 'UPDATE' : 'CREATE'
                // ));

                $import_start_time = microtime(true);
                if ($type === 'tire') {
                    $product_id = $importer->create_tire_product($product_data, $image_urls);
                } else {
                    $product_id = $importer->create_wheel_product($product_data, $image_urls);
                }
                $import_duration = round(microtime(true) - $import_start_time, 3);

                // ASM_Logger::info('← Product importer returned', array(
                //     'index' => $index,
                //     'sku' => $sku,
                //     'product_id' => $product_id,
                //     'is_numeric' => is_numeric($product_id),
                //     'is_valid' => ($product_id && is_numeric($product_id)),
                //     'duration_seconds' => $import_duration
                // ));

                if ($product_id && is_numeric($product_id)) {
                    $product_ids[] = $product_id;
                    if ($existing_id) {
                        $updated++;
                        // ASM_Logger::info('✅ PRODUCT UPDATED SUCCESSFULLY', array(
                        //     'index' => $index,
                        //     'product_id' => $product_id,
                        //     'sku' => $sku,
                        //     'action' => 'UPDATE',
                        //     'duration_seconds' => $import_duration
                        // ));
                    } else {
                        $imported++;
                        // ASM_Logger::info('✅ PRODUCT CREATED SUCCESSFULLY', array(
                        //     'index' => $index,
                        //     'product_id' => $product_id,
                        //     'sku' => $sku,
                        //     'action' => 'CREATE',
                        //     'duration_seconds' => $import_duration
                        // ));
                    }
                } else {
                    $skipped++;
                    $error_msg = '❌ FAILED TO IMPORT SKU: ' . $sku . ' (returned: ' . var_export($product_id, true) . ')';
                    $errors[] = $error_msg;
                    // ASM_Logger::error($error_msg, array(
                    //     'index' => $index,
                    //     'sku' => $sku,
                    //     'type' => $type,
                    //     'product_id_returned' => $product_id,
                    //     'product_id_type' => gettype($product_id),
                    //     'product_id_is_numeric' => is_numeric($product_id),
                    //     'product_id_is_false' => ($product_id === false),
                    //     'product_id_is_null' => ($product_id === null),
                    //     'product_id_is_empty' => empty($product_id),
                    //     'duration_seconds' => $import_duration
                    // ));
                }
            } catch (Exception $e) {
                $skipped++;
                $error_msg = 'Error importing product at index ' . $index . ': ' . $e->getMessage();
                $errors[] = $error_msg;
                // ASM_Logger::error($error_msg, array(
                //     'index' => $index,
                //     'exception' => $e->getMessage(),
                //     'exception_trace' => $e->getTraceAsString()
                // ));
            } catch (Error $e) {
                $skipped++;
                $error_msg = 'Fatal error importing product at index ' . $index . ': ' . $e->getMessage();
                $errors[] = $error_msg;
                // ASM_Logger::error($error_msg, array(
                //     'index' => $index,
                //     'error' => $e->getMessage(),
                //     'error_trace' => $e->getTraceAsString()
                // ));
            }
        }

        // Restore error handling
        restore_error_handler();
        error_reporting($old_error_reporting);
        ini_set('display_errors', $old_display_errors);

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        // Ensure all values are integers
        $response_data = array(
            'imported' => (int) $imported,
            'updated' => (int) $updated,
            'skipped' => (int) $skipped,
            'total_processed' => (int) $product_count,
            'batch_size' => (int) $product_count,
            'duration_seconds' => $duration,
            'errors' => $errors, // Include ALL errors for debugging
            'product_ids' => array_slice($product_ids, 0, 100), // Limit IDs to first 100 for response size
            'debug_info' => array(
                'woocommerce_active' => class_exists('WooCommerce'),
                'product_count_received' => $product_count,
                'products_processed' => $imported + $updated + $skipped
            )
        );

        // ASM_Logger::info('═══════════════════════════════════════════════════════', array());
        // ASM_Logger::info('📊 BATCH INSERT SUMMARY', array(
        //     'total_products_received' => $product_count,
        //     'products_imported' => $imported,
        //     'products_updated' => $updated,
        //     'products_skipped' => $skipped,
        //     'total_processed' => ($imported + $updated + $skipped),
        //     'success_rate' => $product_count > 0 ? round((($imported + $updated) / $product_count) * 100, 2) . '%' : '0%',
        //     'duration_seconds' => $duration,
        //     'type' => $type,
        //     'error_count' => count($errors),
        //     'has_errors' => count($errors) > 0
        // ));
        
        if (count($errors) > 0) {
            // ASM_Logger::error('❌ ERRORS ENCOUNTERED (' . count($errors) . ' total)', array(
            //     'errors' => $errors,
            //     'first_10_errors' => array_slice($errors, 0, 10)
            // ));
        }
        
        // ASM_Logger::info('═══════════════════════════════════════════════════════', array());

        // Include errors in response for debugging
        $response_data['error_count'] = count($errors);
        $response_data['all_errors'] = $errors; // Include all errors for debugging

        // Note: Email notification is sent only after full sync completion (in ajax_save_sync_status)
        // Not sending batch-wise emails to avoid spam

        wp_send_json_success($response_data);
    }

    /**
     * AJAX: Bulk import tires
     */
    public function ajax_bulk_import_tires() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $page_number = isset($_POST['page_number']) ? intval($_POST['page_number']) : 1;
        $page_size = isset($_POST['page_size']) ? intval($_POST['page_size']) : 500; // Max API limit
        $brand_id = isset($_POST['brand_id']) ? intval($_POST['brand_id']) : 0;

        // Limit page size to 500 (max API limit)
        if ($page_size > 500) {
            $page_size = 500;
        }

        // Get tires from API - FETCH ONLY, no creation
        $result = $this->api_client->get_tires($page_number, $page_size, $brand_id);

        if (!$result || empty($result['products'])) {
            wp_send_json_error('No tire products found from API');
        }

        // Convert products to array format for JSON encoding
        $products_data = array();
        foreach ($result['products'] as $product_data) {
            $product_array = json_decode(json_encode($product_data), true);
            $sku = $product_data->PartNumber ?? '';
            $products_data[] = array(
                'data' => $product_array,
                'sku' => $sku,
            );
        }

        wp_send_json_success(array(
            'products' => $products_data,
            'image_urls' => $result['image_urls'],
            'total_fetched' => count($result['products']),
            'has_more' => $result['has_more'] === true,
            'current_page' => $page_number,
        ));
    }

    /**
     * Get API client instance
     */
    public function get_api_client() {
        return $this->api_client;
    }

    /**
     * Clear legacy WP-Cron events once after this external-cron-only version loads.
     */
    public function disable_internal_cron_events() {
        if (get_option(self::LEGACY_CRON_CLEANUP_OPTION)) {
            return;
        }

        if (self::clear_internal_cron_events()) {
            update_option(self::LEGACY_CRON_CLEANUP_OPTION, time(), false);
        }
    }

    /**
     * Remove plugin-owned WP-Cron schedules left behind by older versions.
     */
    private static function clear_internal_cron_events() {
        require_once ASM_PLUGIN_DIR . 'includes/class-cron-sync.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-price-stock-sync.php';

        $full_sync_cleared = ASM_Cron_Sync::unregister_cron();
        $price_stock_cleared = ASM_Price_Stock_Sync::unregister_cron();

        delete_option('asm_price_stock_sync_interval');

        return $full_sync_cleared !== false && $price_stock_cleared !== false;
    }

    /**
     * Handle activation redirect to settings page
     */
    public function handle_activation_redirect() {
        // Check if we should redirect
        if (!get_transient('asm_activation_redirect')) {
            return;
        }

        // Delete the transient so we only redirect once
        delete_transient('asm_activation_redirect');

        // Don't redirect if:
        // - Doing AJAX
        // - Network admin
        // - User doesn't have manage_options capability
        // - Already on the settings page
        if (
            wp_doing_ajax() ||
            is_network_admin() ||
            !current_user_can('manage_options') ||
            (isset($_GET['page']) && $_GET['page'] === 'autosync-manager')
        ) {
            return;
        }

        // Redirect to settings page
        wp_safe_redirect(admin_url('admin.php?page=autosync-manager'));
        exit;
    }


    /**
     * Plugin activation hook - Static method
     * Creates hash table on plugin activation
     */
    public static function activate() {
        // Define constants if not already defined
        if (!defined('ASM_PLUGIN_DIR')) {
            define('ASM_PLUGIN_DIR', plugin_dir_path(__FILE__));
        }
        if (!defined('ASM_PLUGIN_URL')) {
            define('ASM_PLUGIN_URL', plugin_dir_url(__FILE__));
        }
        if (!defined('ASM_VERSION')) {
            define('ASM_VERSION', '0.0.9');
        }
        
        // Load required files
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
        require_once ASM_PLUGIN_DIR . 'includes/class-cron-lock-log.php';

        // Create hash table
        ASM_Hash_Manager::create_table();

        // Create cron lock log table
        ASM_Cron_Lock_Log::create_table();
        
        // Older versions scheduled WP-Cron events; this version is external-cron only.
        self::clear_internal_cron_events();
        update_option(self::LEGACY_CRON_CLEANUP_OPTION, time(), false);
        
        // Set transient to trigger redirect after activation
        // Only redirect if not bulk activation
        if (!isset($_GET['activate-multi'])) {
            set_transient('asm_activation_redirect', true, 30);
        }
        
        // Log activation
        if (function_exists('error_log')) {
            error_log('AutoSync Product Manager: Tables created on activation');
        }
    }

    /**
     * Plugin deactivation hook - Static method
     * Cleanup: Drop hash table, clear legacy schedules, and delete wp_options
     */
    public static function deactivate() {
        // Load required files
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        
        // Clear plugin-owned WP-Cron events on deactivation.
        self::clear_internal_cron_events();
        
        // Drop hash table on deactivation
        ASM_Hash_Manager::drop_table();
        
        // Delete all plugin options from wp_options table
        // Use multiple methods to ensure complete removal
        $options_to_delete = array(
            'asm_api_key',
            'asm_api_url',
            'asm_product_import_mode',
            'asm_image_mode',
            'asm_products_brands',
            'asm_products_sync_status',
            'asm_settings',
            'asm_sync_settings',
            'asm_price_stock_sync_interval',
            self::LEGACY_CRON_CLEANUP_OPTION
        );
        
        global $wpdb;
        
        foreach ($options_to_delete as $option_name) {
            // Method 1: Use WordPress delete_option function
            delete_option($option_name);
            
            // Method 2: Clear from cache
            wp_cache_delete($option_name, 'options');
            
            // Method 3: Direct database deletion as fallback
            $wpdb->delete(
                $wpdb->options,
                array('option_name' => $option_name),
                array('%s')
            );
        }
        
        // Clear alloptions cache completely
        wp_cache_delete('alloptions', 'options');
        
        // Flush object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Log cleanup
        if (function_exists('error_log')) {
            error_log('AutoSync Product Manager: Plugin deactivated - Options and tables cleaned up');
        }
    }

    /**
     * Plugin uninstall hook - Static method
     * Complete cleanup when plugin is deleted
     */
    public static function uninstall() {
        // Load required files
        if (!defined('ASM_PLUGIN_DIR')) {
            define('ASM_PLUGIN_DIR', plugin_dir_path(__FILE__));
        }
        
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        
        // Clear plugin-owned WP-Cron events.
        self::clear_internal_cron_events();
        
        // Drop hash table
        ASM_Hash_Manager::drop_table();
        
        // Delete all plugin options from wp_options table
        $options_to_delete = array(
            'asm_api_key',
            'asm_api_url',
            'asm_product_import_mode',
            'asm_image_mode',
            'asm_products_brands',
            'asm_products_sync_status',
            'asm_settings',
            'asm_sync_settings',
            'asm_price_stock_sync_interval',
            self::LEGACY_CRON_CLEANUP_OPTION
        );
        
        global $wpdb;
        
        foreach ($options_to_delete as $option_name) {
            // Method 1: Use WordPress delete_option function
            delete_option($option_name);
            
            // Method 2: Clear from cache
            wp_cache_delete($option_name, 'options');
            
            // Method 3: Direct database deletion as fallback
            $wpdb->delete(
                $wpdb->options,
                array('option_name' => $option_name),
                array('%s')
            );
        }
        
        // Clear alloptions cache completely
        wp_cache_delete('alloptions', 'options');
        
        // Flush object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Log cleanup
        if (function_exists('error_log')) {
            error_log('AutoSync Product Manager: Plugin uninstalled - All data cleaned up');
        }
    }

    /**
     * Render hash management page
     */
    public function render_hash_management_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        include ASM_PLUGIN_DIR . 'admin/views/hash-management.php';
    }

    /**
     * AJAX: Rebuild hash table
     */
    public function ajax_rebuild_hash_table() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed',
                    'nonce_received' => isset($_POST['nonce']) ? 'yes' : 'no',
                    'post_data_keys' => array_keys($_POST)
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            // Log start
            // ASM_Logger::info('AJAX create hash table started', array(
            //     'user_id' => get_current_user_id()
            // ));

            // Send immediate response to test AJAX connectivity
            // This helps debug if the endpoint is being reached
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ASM: AJAX endpoint reached for rebuild_hash_table');
            }

            // Create hash table structure (empty table only)
            $results = ASM_Hash_Manager::create_hash_table_structure();

            // Log completion
            // ASM_Logger::info('AJAX create hash table completed', array(
            //     'results' => $results
            // ));

            if ($results['success']) {
                wp_send_json_success(array(
                    'message' => $results['message'],
                    'table_exists' => $results['table_exists'],
                    'note' => 'Hashes will be created automatically during bulk import operations.'
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $results['message']
                ));
            }
            exit;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $error_trace = $e->getTraceAsString();
            
            // ASM_Logger::error('Exception in ajax_rebuild_hash_table', array(
            //     'message' => $error_message,
            //     'trace' => $error_trace,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error rebuilding hash table: ' . $error_message,
                'trace' => defined('WP_DEBUG') && WP_DEBUG ? $error_trace : null
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            $error_trace = $e->getTraceAsString();
            
            // ASM_Logger::error('Fatal error in ajax_rebuild_hash_table', array(
            //     'message' => $error_message,
            //     'trace' => $error_trace,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error rebuilding hash table: ' . $error_message,
                'trace' => defined('WP_DEBUG') && WP_DEBUG ? $error_trace : null
            ));
            exit;
        }
    }

    /**
     * AJAX: Truncate hash table
     */
    public function ajax_truncate_hash_table() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            $result = ASM_Hash_Manager::truncate_table();
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'message' => $result['message'],
                    'rows_deleted' => $result['rows_deleted']
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $result['message']
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error truncating hash table: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Initialize empty price_stock_hash batch process
     */
    public function ajax_empty_price_stock_hash() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
            
            // Get total count of rows that need to be cleared
            $total_count = ASM_Hash_Manager::get_price_stock_hash_count();
            
            if ($total_count === 0) {
                wp_send_json_success(array(
                    'message' => 'No price_stock_hash values to clear',
                    'total' => 0,
                    'completed' => true
                ));
                exit;
            }

            // Store initial state in transient
            $user_id = get_current_user_id();
            $transient_key = 'asm_empty_price_stock_hash_' . $user_id;
            set_transient($transient_key, array(
                'total_count' => $total_count,
                'processed' => 0,
                'start_time' => microtime(true)
            ), HOUR_IN_SECONDS);

            wp_send_json_success(array(
                'message' => 'Batch processing initialized',
                'total' => $total_count,
                'processed' => 0,
                'has_more' => true,
                'completed' => false
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error initializing price_stock_hash clear: ' . $e->getMessage()
            ));
        }
    }

    /**
     * AJAX: Process batch of price_stock_hash clearing
     */
    public function ajax_empty_price_stock_hash_batch() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        try {
            require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
            
            $user_id = get_current_user_id();
            $transient_key = 'asm_empty_price_stock_hash_' . $user_id;
            $batch_state = get_transient($transient_key);

            if (false === $batch_state) {
                wp_send_json_error(array(
                    'message' => 'Batch data not found. Please start the process again.',
                    'completed' => true
                ));
                exit;
            }

            // Process one batch (500 rows)
            $result = ASM_Hash_Manager::empty_price_stock_hash_batch(500);
            
            if (!$result['success']) {
                wp_send_json_error(array(
                    'message' => $result['message'],
                    'completed' => true
                ));
                exit;
            }

            // Update batch state
            $batch_state['processed'] += $result['rows_updated'];
            
            if ($result['has_more']) {
                // More rows to process
                set_transient($transient_key, $batch_state, HOUR_IN_SECONDS);
                wp_send_json_success(array(
                    'message' => $result['message'],
                    'total' => $batch_state['total_count'],
                    'processed' => $batch_state['processed'],
                    'batch_updated' => $result['rows_updated'],
                    'remaining' => $result['remaining'],
                    'has_more' => true,
                    'completed' => false
                ));
            } else {
                // All done
                delete_transient($transient_key);
                $duration = round(microtime(true) - $batch_state['start_time'], 2);
                wp_send_json_success(array(
                    'message' => 'Price/Stock hash cleared successfully',
                    'total' => $batch_state['total_count'],
                    'processed' => $batch_state['processed'],
                    'batch_updated' => $result['rows_updated'],
                    'duration' => $duration,
                    'has_more' => false,
                    'completed' => true
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error processing batch: ' . $e->getMessage(),
                'completed' => true
            ));
        }
    }

    /**
     * AJAX: Check for duplicate SKUs
     */
    public function ajax_check_duplicate_skus() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed'
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            global $wpdb;
            
            // Get pagination parameters
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
            $offset = ($page - 1) * $per_page;

            $posts_table = $wpdb->posts;
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            
            // Check if WooCommerce lookup table exists (faster than postmeta)
            $lookup_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$lookup_table}'") === $lookup_table;
            
            // Check MySQL version for window function support
            $mysql_version = $wpdb->get_var("SELECT VERSION()");
            $supports_window_functions = version_compare($mysql_version, '8.0.0', '>=');
            
            if ($lookup_table_exists && $supports_window_functions) {
                // OPTIMIZED: Use WooCommerce lookup table with window functions (MySQL 8.0+)
                $duplicates_query = "
                    SELECT 
                        lookup.sku,
                        p.ID as product_id,
                        p.post_title,
                        p.post_date,
                        ROW_NUMBER() OVER (PARTITION BY lookup.sku ORDER BY p.ID ASC) as row_num
                    FROM {$lookup_table} lookup
                    INNER JOIN {$posts_table} p ON lookup.product_id = p.ID
                    WHERE lookup.sku != ''
                        AND lookup.sku IS NOT NULL
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                        AND lookup.sku IN (
                            SELECT sku
                            FROM {$lookup_table} lookup2
                            INNER JOIN {$posts_table} p2 ON lookup2.product_id = p2.ID
                            WHERE lookup2.sku != ''
                                AND lookup2.sku IS NOT NULL
                                AND p2.post_type = 'product'
                                AND p2.post_status != 'trash'
                            GROUP BY lookup2.sku
                            HAVING COUNT(*) > 1
                        )
                    ORDER BY lookup.sku ASC, p.ID ASC
                ";
                
                $all_results = $wpdb->get_results($duplicates_query, ARRAY_A);
                
                // Filter out the first product for each SKU (row_num = 1)
                $all_duplicates = array();
                foreach ($all_results as $row) {
                    if (intval($row['row_num']) > 1) {
                        $all_duplicates[] = array(
                            'sku' => $row['sku'],
                            'product_id' => intval($row['product_id']),
                            'title' => $row['post_title'],
                            'date_created' => $row['post_date'],
                            'edit_link' => admin_url('post.php?post=' . intval($row['product_id']) . '&action=edit')
                        );
                    }
                }
            } else {
                // FALLBACK: Compatible with older MySQL versions - get all duplicates and filter in PHP
                if ($lookup_table_exists) {
                    // Use lookup table but without window functions
                    $duplicate_skus_query = "
                        SELECT lookup.sku, COUNT(*) as count
                        FROM {$lookup_table} lookup
                        INNER JOIN {$posts_table} p ON lookup.product_id = p.ID
                        WHERE lookup.sku != ''
                            AND lookup.sku IS NOT NULL
                            AND p.post_type = 'product'
                            AND p.post_status != 'trash'
                        GROUP BY lookup.sku
                        HAVING COUNT(*) > 1
                        ORDER BY COUNT(*) DESC, lookup.sku ASC
                    ";
                    
                    $duplicate_skus = $wpdb->get_results($duplicate_skus_query, ARRAY_A);
                    
                    if (empty($duplicate_skus)) {
                        $all_duplicates = array();
                    } else {
                        // Get all products with duplicate SKUs in one query
                        $skus_list = array_map(function($item) use ($wpdb) {
                            return $wpdb->prepare('%s', $item['sku']);
                        }, $duplicate_skus);
                        $skus_sql = implode(',', $skus_list);
                        
                        $all_products_query = "
                            SELECT 
                                lookup.sku,
                                p.ID as product_id,
                                p.post_title,
                                p.post_date
                            FROM {$lookup_table} lookup
                            INNER JOIN {$posts_table} p ON lookup.product_id = p.ID
                            WHERE lookup.sku IN ({$skus_sql})
                                AND p.post_type = 'product'
                                AND p.post_status != 'trash'
                            ORDER BY lookup.sku ASC, p.ID ASC
                        ";
                        
                        $all_products = $wpdb->get_results($all_products_query, ARRAY_A);
                        
                        // Group by SKU and exclude first product for each SKU
                        $all_duplicates = array();
                        $current_sku = null;
                        $sku_count = 0;
                        
                        foreach ($all_products as $product) {
                            if ($product['sku'] !== $current_sku) {
                                $current_sku = $product['sku'];
                                $sku_count = 0;
                            }
                            $sku_count++;
                            
                            // Skip first product (original), add rest as duplicates
                            if ($sku_count > 1) {
                                $all_duplicates[] = array(
                                    'sku' => $product['sku'],
                                    'product_id' => intval($product['product_id']),
                                    'title' => $product['post_title'],
                                    'date_created' => $product['post_date'],
                                    'edit_link' => admin_url('post.php?post=' . intval($product['product_id']) . '&action=edit')
                                );
                            }
                        }
                    }
                } else {
                    // Use postmeta table (slower but works if lookup table doesn't exist)
                    $duplicate_skus_query = "
                        SELECT pm.meta_value as sku, COUNT(*) as count
                        FROM {$wpdb->postmeta} pm
                        INNER JOIN {$posts_table} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_sku'
                            AND pm.meta_value != ''
                            AND pm.meta_value IS NOT NULL
                            AND p.post_type = 'product'
                            AND p.post_status != 'trash'
                        GROUP BY pm.meta_value
                        HAVING COUNT(*) > 1
                        ORDER BY COUNT(*) DESC, pm.meta_value ASC
                    ";
                    
                    $duplicate_skus = $wpdb->get_results($duplicate_skus_query, ARRAY_A);
                    
                    if (empty($duplicate_skus)) {
                        $all_duplicates = array();
                    } else {
                        // Get all products with duplicate SKUs in one query
                        $skus_list = array_map(function($item) use ($wpdb) {
                            return $wpdb->prepare('%s', $item['sku']);
                        }, $duplicate_skus);
                        $skus_sql = implode(',', $skus_list);
                        
                        $all_products_query = "
                            SELECT 
                                pm.meta_value as sku,
                                p.ID as product_id,
                                p.post_title,
                                p.post_date
                            FROM {$wpdb->postmeta} pm
                            INNER JOIN {$posts_table} p ON pm.post_id = p.ID
                            WHERE pm.meta_key = '_sku'
                                AND pm.meta_value IN ({$skus_sql})
                                AND p.post_type = 'product'
                                AND p.post_status != 'trash'
                            ORDER BY pm.meta_value ASC, p.ID ASC
                        ";
                        
                        $all_products = $wpdb->get_results($all_products_query, ARRAY_A);
                        
                        // Group by SKU and exclude first product for each SKU
                        $all_duplicates = array();
                        $current_sku = null;
                        $sku_count = 0;
                        
                        foreach ($all_products as $product) {
                            if ($product['sku'] !== $current_sku) {
                                $current_sku = $product['sku'];
                                $sku_count = 0;
                            }
                            $sku_count++;
                            
                            // Skip first product (original), add rest as duplicates
                            if ($sku_count > 1) {
                                $all_duplicates[] = array(
                                    'sku' => $product['sku'],
                                    'product_id' => intval($product['product_id']),
                                    'title' => $product['post_title'],
                                    'date_created' => $product['post_date'],
                                    'edit_link' => admin_url('post.php?post=' . intval($product['product_id']) . '&action=edit')
                                );
                            }
                        }
                    }
                }
            }

            $total = count($all_duplicates);
            
            // Apply pagination
            $paginated_duplicates = array_slice($all_duplicates, $offset, $per_page);

            wp_send_json_success(array(
                'duplicates' => $paginated_duplicates,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page
            ));
            exit;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Exception in ajax_check_duplicate_skus', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error checking duplicate SKUs: ' . $error_message
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Fatal error in ajax_check_duplicate_skus', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error checking duplicate SKUs: ' . $error_message
            ));
            exit;
        }
    }

    /**
     * AJAX: Delete a single duplicate product
     */
    public function ajax_delete_duplicate_product() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed'
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            
            if (!$product_id) {
                wp_send_json_error(array(
                    'message' => 'Product ID is required'
                ));
                exit;
            }

            // Verify product exists
            $product = wc_get_product($product_id);
            if (!$product) {
                wp_send_json_error(array(
                    'message' => 'Product not found'
                ));
                exit;
            }

            // Verify this is actually a duplicate (not the original)
            // Get the SKU
            $sku = $product->get_sku();
            
            if (empty($sku)) {
                wp_send_json_error(array(
                    'message' => 'Product does not have a SKU'
                ));
                exit;
            }

            // Check if there are multiple products with this SKU
            global $wpdb;
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            $lookup_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$lookup_table}'") === $lookup_table;
            
            if ($lookup_table_exists) {
                $count_query = $wpdb->prepare("
                    SELECT COUNT(*) 
                    FROM {$lookup_table} lookup
                    INNER JOIN {$wpdb->posts} p ON lookup.product_id = p.ID
                    WHERE lookup.sku = %s
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                ", $sku);
            } else {
                $count_query = $wpdb->prepare("
                    SELECT COUNT(*) 
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_sku'
                        AND pm.meta_value = %s
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                ", $sku);
            }
            
            $product_count = intval($wpdb->get_var($count_query));
            
            if ($product_count <= 1) {
                wp_send_json_error(array(
                    'message' => 'This product is not a duplicate. Only duplicate products can be deleted.'
                ));
                exit;
            }

            // Get all products with this SKU, ordered by ID (oldest first)
            if ($lookup_table_exists) {
                $products_query = $wpdb->prepare("
                    SELECT p.ID
                    FROM {$lookup_table} lookup
                    INNER JOIN {$wpdb->posts} p ON lookup.product_id = p.ID
                    WHERE lookup.sku = %s
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                    ORDER BY p.ID ASC
                ", $sku);
            } else {
                $products_query = $wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_sku'
                        AND pm.meta_value = %s
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                    ORDER BY p.ID ASC
                ", $sku);
            }
            
            $products = $wpdb->get_col($products_query);
            $first_product_id = intval($products[0]);
            
            // Verify this is NOT the first product (original)
            if ($product_id === $first_product_id) {
                wp_send_json_error(array(
                    'message' => 'Cannot delete the original product. Only duplicates can be deleted.'
                ));
                exit;
            }

            // Delete the product (this will also clean up meta, terms, etc.)
            $deleted = wp_delete_post($product_id, true);
            
            if ($deleted) {
                // Also clean up hash if exists
                ASM_Hash_Manager::delete_hash($sku);
                
                wp_send_json_success(array(
                    'message' => 'Duplicate product deleted successfully',
                    'product_id' => $product_id
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'Failed to delete product'
                ));
            }
            exit;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Exception in ajax_delete_duplicate_product', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error deleting duplicate product: ' . $error_message
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Fatal error in ajax_delete_duplicate_product', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error deleting duplicate product: ' . $error_message
            ));
            exit;
        }
    }

    /**
     * AJAX: Bulk delete all duplicate products (initialize batch processing)
     */
    public function ajax_bulk_delete_duplicates() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed'
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            global $wpdb;
            
            $posts_table = $wpdb->posts;
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            $lookup_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$lookup_table}'") === $lookup_table;
            
            // Get all duplicate products (excluding originals)
            $all_duplicates = array();
            
            if ($lookup_table_exists) {
                // Get all duplicate SKUs
                $duplicate_skus_query = "
                    SELECT lookup.sku, COUNT(*) as count
                    FROM {$lookup_table} lookup
                    INNER JOIN {$posts_table} p ON lookup.product_id = p.ID
                    WHERE lookup.sku != ''
                        AND lookup.sku IS NOT NULL
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                    GROUP BY lookup.sku
                    HAVING COUNT(*) > 1
                ";
                
                $duplicate_skus = $wpdb->get_results($duplicate_skus_query, ARRAY_A);
                
                foreach ($duplicate_skus as $sku_data) {
                    $sku = $sku_data['sku'];
                    
                    // Get all products with this SKU, ordered by ID (oldest first)
                    $products_query = $wpdb->prepare("
                        SELECT p.ID
                        FROM {$lookup_table} lookup
                        INNER JOIN {$posts_table} p ON lookup.product_id = p.ID
                        WHERE lookup.sku = %s
                            AND p.post_type = 'product'
                            AND p.post_status != 'trash'
                        ORDER BY p.ID ASC
                    ", $sku);
                    
                    $products = $wpdb->get_col($products_query);
                    
                    // Skip the first product (original), add the rest as duplicates
                    if (count($products) > 1) {
                        array_shift($products); // Remove first product
                        $all_duplicates = array_merge($all_duplicates, $products);
                    }
                }
            } else {
                // Use postmeta table
                $duplicate_skus_query = "
                    SELECT pm.meta_value as sku, COUNT(*) as count
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$posts_table} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_sku'
                        AND pm.meta_value != ''
                        AND pm.meta_value IS NOT NULL
                        AND p.post_type = 'product'
                        AND p.post_status != 'trash'
                    GROUP BY pm.meta_value
                    HAVING COUNT(*) > 1
                ";
                
                $duplicate_skus = $wpdb->get_results($duplicate_skus_query, ARRAY_A);
                
                foreach ($duplicate_skus as $sku_data) {
                    $sku = $sku_data['sku'];
                    
                    // Get all products with this SKU, ordered by ID (oldest first)
                    $products_query = $wpdb->prepare("
                        SELECT p.ID
                        FROM {$wpdb->postmeta} pm
                        INNER JOIN {$posts_table} p ON pm.post_id = p.ID
                        WHERE pm.meta_key = '_sku'
                            AND pm.meta_value = %s
                            AND p.post_type = 'product'
                            AND p.post_status != 'trash'
                        ORDER BY p.ID ASC
                    ", $sku);
                    
                    $products = $wpdb->get_col($products_query);
                    
                    // Skip the first product (original), add the rest as duplicates
                    if (count($products) > 1) {
                        array_shift($products); // Remove first product
                        $all_duplicates = array_merge($all_duplicates, $products);
                    }
                }
            }

            if (empty($all_duplicates)) {
                wp_send_json_success(array(
                    'message' => 'No duplicate products found',
                    'deleted' => 0,
                    'total' => 0
                ));
                exit;
            }

            // Convert to integers and remove duplicates
            $all_duplicates = array_unique(array_map('intval', $all_duplicates));
            $total_count = count($all_duplicates);
            
            // Store duplicate IDs in transient for batch processing (expires in 1 hour)
            $user_id = get_current_user_id();
            $transient_key = 'asm_bulk_delete_duplicates_' . $user_id;
            set_transient($transient_key, array(
                'product_ids' => $all_duplicates,
                'total' => $total_count,
                'processed' => 0,
                    'deleted' => 0
            ), HOUR_IN_SECONDS);

            wp_send_json_success(array(
                'message' => 'Batch processing initialized',
                'total' => $total_count,
                'processed' => 0,
                'deleted' => 0,
                'has_more' => true
            ));
            exit;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Exception in ajax_bulk_delete_duplicates', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error bulk deleting duplicates: ' . $error_message
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Fatal error in ajax_bulk_delete_duplicates', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error bulk deleting duplicates: ' . $error_message
                ));
                exit;
            }
    }

    /**
     * AJAX: Process batch of duplicate products deletion
     */
    public function ajax_bulk_delete_duplicates_batch() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed'
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            // Increase execution time limit for batch processing
            @set_time_limit(300); // 5 minutes per batch
            @ini_set('max_execution_time', '300');
            @ini_set('memory_limit', '512M');

            $user_id = get_current_user_id();
            $transient_key = 'asm_bulk_delete_duplicates_' . $user_id;
            $batch_data = get_transient($transient_key);

            if (false === $batch_data || empty($batch_data['product_ids'])) {
                wp_send_json_error(array(
                    'message' => 'Batch data not found. Please start the deletion process again.',
                    'completed' => true
                ));
                exit;
            }

            $product_ids = $batch_data['product_ids'];
            $total = $batch_data['total'];
            $processed = $batch_data['processed'];
            $deleted = $batch_data['deleted'];
            
            // Batch size - process 50 products at a time
            $batch_size = 50;
            // Since we store remaining IDs, always slice from index 0
            $batch_ids = array_slice($product_ids, 0, $batch_size);
            
            if (empty($batch_ids)) {
                // All done, clean up transient
                delete_transient($transient_key);
                
                wp_send_json_success(array(
                    'message' => 'All duplicates deleted successfully',
                    'total' => $total,
                    'processed' => $total,
                    'deleted' => $deleted,
                    'has_more' => false,
                    'completed' => true
                ));
                exit;
            }

            global $wpdb;
            
            // Use faster bulk deletion method similar to ASM_Product_Deleter
            // Disable WordPress hooks temporarily for faster deletion
            remove_action('before_delete_post', 'wp_delete_post_revision');
            
            $batch_deleted = 0;
            $skus_to_clean = array();
            
            // Convert to integers for safety
            $batch_ids = array_map('intval', $batch_ids);
            $batch_ids_str = implode(',', $batch_ids);
            
            // Get SKUs in bulk via SQL (much faster than individual get_post_meta calls)
            if (!empty($batch_ids_str)) {
                $skus = $wpdb->get_col(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$batch_ids_str}) AND meta_key = '_sku' AND meta_value != ''"
                );
                $skus_to_clean = array_filter($skus); // Remove empty values
            }

            // Delete product variations first (if any)
            $variation_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$batch_ids_str}) AND post_type = 'product_variation'");
            if (!empty($variation_ids)) {
                $variation_ids_str = implode(',', array_map('intval', $variation_ids));
                
                // Delete variation meta
                $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$variation_ids_str})");
                
                // Delete variation term relationships
                $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$variation_ids_str})");
                
                // Delete variation posts
                $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$variation_ids_str})");
            }

            // Delete product meta
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$batch_ids_str})");

            // Delete term relationships
            $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$batch_ids_str})");

            // Delete posts
            $deleted_result = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$batch_ids_str})");
                
            if ($deleted_result !== false) {
                $batch_deleted = $deleted_result;
                
                // Clean up cache
                clean_post_cache($batch_ids);
                
                // Clear object cache
                foreach ($batch_ids as $product_id) {
                    wp_cache_delete($product_id, 'posts');
                }
                
                // Delete hashes for deleted products (bulk delete for performance)
                if (!empty($skus_to_clean)) {
                    $hash_table_name = ASM_Hash_Manager::get_table_name();
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hash_table_name}'") === $hash_table_name;
                    if ($table_exists) {
                        $escaped_skus = array_map(function($sku) use ($wpdb) {
                            return "'" . esc_sql($sku) . "'";
                        }, $skus_to_clean);
                        $skus_list = implode(',', $escaped_skus);
                        $wpdb->query("DELETE FROM {$hash_table_name} WHERE sku IN ({$skus_list})");
                    }
                }
            } else {
                // Fallback to wp_delete_post if direct deletion fails
                foreach ($batch_ids as $product_id) {
                    $result = wp_delete_post($product_id, true);
                    if ($result) {
                        $batch_deleted++;
                }
            }

                // Delete hashes for products deleted via fallback
                if (!empty($skus_to_clean)) {
                    $hash_table_name = ASM_Hash_Manager::get_table_name();
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hash_table_name}'") === $hash_table_name;
                    if ($table_exists) {
                        $escaped_skus = array_map(function($sku) use ($wpdb) {
                            return "'" . esc_sql($sku) . "'";
                        }, $skus_to_clean);
                        $skus_list = implode(',', $escaped_skus);
                        $wpdb->query("DELETE FROM {$hash_table_name} WHERE sku IN ({$skus_list})");
                    }
                }
            }

            // Re-enable hooks
            add_action('before_delete_post', 'wp_delete_post_revision');

            // Update progress
            $new_processed = $processed + count($batch_ids);
            $new_deleted = $deleted + $batch_deleted;
            $has_more = $new_processed < $total;
            
            // Update transient with new progress - remove processed batch from remaining IDs
            $remaining_ids = array_slice($product_ids, count($batch_ids));
            set_transient($transient_key, array(
                'product_ids' => $remaining_ids,
                'total' => $total,
                'processed' => $new_processed,
                'deleted' => $new_deleted
            ), HOUR_IN_SECONDS);

            wp_send_json_success(array(
                'message' => sprintf('Processed batch: %d/%d deleted', $new_deleted, $total),
                'total' => $total,
                'processed' => $new_processed,
                'deleted' => $new_deleted,
                'has_more' => $has_more,
                'completed' => !$has_more
            ));
            exit;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Exception in ajax_bulk_delete_duplicates_batch', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error processing batch: ' . $error_message
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Fatal error in ajax_bulk_delete_duplicates_batch', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error processing batch: ' . $error_message
            ));
            exit;
        }
    }

    /**
     * Render brand management page
     */
    public function render_brand_management_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        include ASM_PLUGIN_DIR . 'admin/views/brand-management.php';
    }

    /**
     * AJAX: Initialize inventory and quantity sync (batch processing)
     */
    public function ajax_sync_inventory_quantity() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed'
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            // Load required classes
            require_once ASM_PLUGIN_DIR . 'includes/class-price-stock-sync.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';

            // Check if hash table is empty - price/stock sync requires hash table to be populated
            $hash_count = ASM_Hash_Manager::get_total_count();
            if ($hash_count == 0) {
                wp_send_json_error(array(
                    'message' => 'Hash table is empty. Please run full product sync first to sync price/stock.',
                    'completed' => true
                ));
                exit;
            }

            // Get active brands for batch processing
            $brands_structure = ASM_Price_Stock_Sync::get_active_brands_for_batch();
            
            // Count total brands
            $total_tire_brands = count($brands_structure['tires']);
            $total_wheel_brands = count($brands_structure['wheels']);
            
            if ($total_tire_brands == 0 && $total_wheel_brands == 0) {
                wp_send_json_success(array(
                    'message' => 'No active brands found for price/stock sync',
                    'total' => 0,
                    'total_products' => 0,
                    'processed' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'has_more' => false,
                    'completed' => true
                ));
                exit;
            }

            // Initialize batch state
            $batch_state = array(
                'tires' => $brands_structure['tires'],
                'wheels' => $brands_structure['wheels'],
                'total_processed' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 0
            );

            // Calculate total brands for progress display
            $total_brands = $total_tire_brands + $total_wheel_brands;
            
            // Store batch state in transient (expires in 2 hours)
            $user_id = get_current_user_id();
            $transient_key = 'asm_sync_inventory_quantity_' . $user_id;
            $batch_state['start_time'] = microtime(true);
            set_transient($transient_key, $batch_state, 2 * HOUR_IN_SECONDS);

            wp_send_json_success(array(
                'message' => 'Batch processing initialized',
                'total_brands' => $total_brands,
                'total_tire_brands' => $total_tire_brands,
                'total_wheel_brands' => $total_wheel_brands,
                'total_products' => 0, // Will be updated during processing
                'total' => 0,
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'has_more' => true,
                'completed' => false
            ));
            exit;

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Exception in ajax_sync_inventory_quantity', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error initializing inventory and quantity sync: ' . $error_message
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Fatal error in ajax_sync_inventory_quantity', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error initializing inventory and quantity sync: ' . $error_message
            ));
            exit;
        }
    }

    /**
     * AJAX: Process batch of inventory and quantity sync
     */
    public function ajax_sync_inventory_quantity_batch() {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'asm_nonce')) {
                wp_send_json_error(array(
                    'message' => 'Security check failed'
                ));
                exit;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'Unauthorized - insufficient permissions'
                ));
                exit;
            }

            // Increase execution time limit for batch processing
            @set_time_limit(300); // 5 minutes per batch
            @ini_set('max_execution_time', '300');
            @ini_set('memory_limit', '512M');

            // Load required class
            require_once ASM_PLUGIN_DIR . 'includes/class-price-stock-sync.php';

            $user_id = get_current_user_id();
            $transient_key = 'asm_sync_inventory_quantity_' . $user_id;
            $batch_state = get_transient($transient_key);

            if (false === $batch_state || empty($batch_state)) {
                wp_send_json_error(array(
                    'message' => 'Batch data not found. Please start the sync process again.',
                    'completed' => true
                ));
                exit;
            }

            // Process one batch
            $result = ASM_Price_Stock_Sync::process_batch($batch_state);
            
            $updated_batch_state = $result['batch_state'];
            $batch_stats = $result['batch_stats'];
            $completed = $result['completed'];
            $current_type = isset($result['current_type']) ? $result['current_type'] : null;
            $current_brand_name = isset($result['current_brand_name']) ? $result['current_brand_name'] : '';
            $current_brand_num = isset($result['current_brand_num']) ? $result['current_brand_num'] : 0;
            $total_brands = isset($result['total_brands']) ? $result['total_brands'] : 0;
            $completed_brands = isset($result['completed_brands']) ? $result['completed_brands'] : 0;

            // Initialize per-type counters if not exists
            if (!isset($updated_batch_state['tires_updated'])) {
                $updated_batch_state['tires_updated'] = 0;
                $updated_batch_state['tires_skipped'] = 0;
                $updated_batch_state['tires_errors'] = 0;
                $updated_batch_state['wheels_updated'] = 0;
                $updated_batch_state['wheels_skipped'] = 0;
                $updated_batch_state['wheels_errors'] = 0;
            }

            // Update totals
            $updated_batch_state['total_processed'] += $batch_stats['products_processed'];
            $updated_batch_state['total_updated'] += $batch_stats['updated'];
            $updated_batch_state['total_skipped'] += $batch_stats['skipped'];
            $updated_batch_state['total_errors'] += $batch_stats['errors'];
            
            // Update per-type stats based on current product type
            if ($current_type === 'tire') {
                $updated_batch_state['tires_updated'] += $batch_stats['updated'];
                $updated_batch_state['tires_skipped'] += $batch_stats['skipped'];
                $updated_batch_state['tires_errors'] += $batch_stats['errors'];
            } elseif ($current_type === 'wheel') {
                $updated_batch_state['wheels_updated'] += $batch_stats['updated'];
                $updated_batch_state['wheels_skipped'] += $batch_stats['skipped'];
                $updated_batch_state['wheels_errors'] += $batch_stats['errors'];
            }

            if ($completed) {
                // All done, clean up transient
                delete_transient($transient_key);
                
                // Prepare stats for status saving
                $final_stats = array(
                    'start_time' => isset($batch_state['start_time']) ? $batch_state['start_time'] : microtime(true),
                    'duration' => isset($batch_state['duration']) ? $batch_state['duration'] : 0,
                    'tires' => array(
                        'brands_processed' => isset($updated_batch_state['tires']) ? count($updated_batch_state['tires']) : 0,
                        'total_updated' => isset($updated_batch_state['tires_updated']) ? (int)$updated_batch_state['tires_updated'] : 0,
                        'total_skipped' => isset($updated_batch_state['tires_skipped']) ? (int)$updated_batch_state['tires_skipped'] : 0,
                        'total_errors' => isset($updated_batch_state['tires_errors']) ? (int)$updated_batch_state['tires_errors'] : 0
                    ),
                    'wheels' => array(
                        'brands_processed' => isset($updated_batch_state['wheels']) ? count($updated_batch_state['wheels']) : 0,
                        'total_updated' => isset($updated_batch_state['wheels_updated']) ? (int)$updated_batch_state['wheels_updated'] : 0,
                        'total_skipped' => isset($updated_batch_state['wheels_skipped']) ? (int)$updated_batch_state['wheels_skipped'] : 0,
                        'total_errors' => isset($updated_batch_state['wheels_errors']) ? (int)$updated_batch_state['wheels_errors'] : 0
                    )
                );
                
                // Calculate duration if not set
                if (!isset($batch_state['duration']) && isset($batch_state['start_time'])) {
                    $final_stats['duration'] = round(microtime(true) - $batch_state['start_time'], 2);
                }
                
                // Save sync status
                require_once ASM_PLUGIN_DIR . 'includes/class-sync-status-manager.php';
                $sync_data = ASM_Price_Stock_Sync_Status_Manager::format_sync_data_from_stats($final_stats, 'manual');
                ASM_Price_Stock_Sync_Status_Manager::save_sync_status($sync_data);
                
                wp_send_json_success(array(
                    'message' => 'Inventory and quantity sync completed',
                    'total' => $updated_batch_state['total_processed'],
                    'total_products' => $updated_batch_state['total_processed'],
                    'processed' => $updated_batch_state['total_processed'],
                    'updated' => $updated_batch_state['total_updated'],
                    'skipped' => $updated_batch_state['total_skipped'],
                    'errors' => $updated_batch_state['total_errors'],
                    'has_more' => false,
                    'completed' => true
                ));
                exit;
            } else {
                // Save updated batch state
                set_transient($transient_key, $updated_batch_state, 2 * HOUR_IN_SECONDS);
                
                wp_send_json_success(array(
                    'message' => 'Batch processed',
                    'total' => $updated_batch_state['total_processed'], // Running total
                    'processed' => $updated_batch_state['total_processed'],
                    'updated' => $updated_batch_state['total_updated'],
                    'skipped' => $updated_batch_state['total_skipped'],
                    'errors' => $updated_batch_state['total_errors'],
                    'batch_updated' => $batch_stats['updated'],
                    'batch_skipped' => $batch_stats['skipped'],
                    'batch_processed' => $batch_stats['products_processed'],
                    'current_brand_name' => $current_brand_name,
                    'current_brand_num' => $current_brand_num,
                    'total_brands' => $total_brands,
                    'completed_brands' => $completed_brands,
                    'has_more' => true,
                    'completed' => false
                ));
                exit;
            }

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Exception in ajax_sync_inventory_quantity_batch', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Error processing batch: ' . $error_message
            ));
            exit;

        } catch (Error $e) {
            $error_message = $e->getMessage();
            
            // ASM_Logger::error('Fatal error in ajax_sync_inventory_quantity_batch', array(
            //     'message' => $error_message,
            //     'file' => $e->getFile(),
            //     'line' => $e->getLine()
            // ));

            wp_send_json_error(array(
                'message' => 'Fatal error processing batch: ' . $error_message
            ));
            exit;
        }
    }

    /**
     * AJAX: Fetch brands from API and store in database
     */
    public function ajax_fetch_brands() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Fetch all tire brands
        $tire_result = $this->api_client->get_all_tire_brands();
        $tire_brands_saved = 0;
        
        if ($tire_result && !empty($tire_result['brands'])) {
            $brands_to_save = array();
            foreach ($tire_result['brands'] as $brand) {
                // save_brand will preserve existing is_active status if brand_id already exists
                // New brands will default to is_active = true
                $brands_to_save[] = array(
                    'brand_name' => $brand->Brand,
                    'brand_id' => $brand->Id,
                    'product_type' => 'tire',
                    'is_active' => 1 // Default for new brands, ignored for existing ones
                );
            }
            $tire_brands_saved = ASM_Brand_Manager::batch_save_brands($brands_to_save);
        }

        // Fetch all wheel brands
        $wheel_result = $this->api_client->get_all_wheel_brands();
        $wheel_brands_saved = 0;
        
        if ($wheel_result && !empty($wheel_result['brands'])) {
            $brands_to_save = array();
            foreach ($wheel_result['brands'] as $brand) {
                // save_brand will preserve existing is_active status if brand_id already exists
                // New brands will default to is_active = true
                $brands_to_save[] = array(
                    'brand_name' => $brand->Brand,
                    'brand_id' => $brand->Id,
                    'product_type' => 'wheel',
                    'is_active' => 1 // Default for new brands, ignored for existing ones
                );
            }
            $wheel_brands_saved = ASM_Brand_Manager::batch_save_brands($brands_to_save);
        }

        wp_send_json_success(array(
            'message' => 'Brands fetched and saved successfully',
            'tire_brands_saved' => $tire_brands_saved,
            'wheel_brands_saved' => $wheel_brands_saved,
            'total_saved' => $tire_brands_saved + $wheel_brands_saved
        ));
    }

    /**
     * AJAX: Update brand active status
     */
    public function ajax_update_brand_status() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $brand_id = isset($_POST['brand_id']) ? intval($_POST['brand_id']) : 0;
        $product_type = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : '';
        $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 0;

        if (empty($brand_id) || empty($product_type)) {
            wp_send_json_error('Invalid parameters');
        }

        $result = ASM_Brand_Manager::update_active_status($brand_id, $product_type, $is_active);

        if ($result) {
            wp_send_json_success(array(
                'message' => 'Brand status updated successfully'
            ));
        } else {
            wp_send_json_error('Failed to update brand status');
        }
    }

    /**
     * AJAX: Batch update brand active status
     */
    public function ajax_batch_update_brand_status() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $updates_json = isset($_POST['updates']) ? $_POST['updates'] : '';
        
        if (empty($updates_json)) {
            wp_send_json_error('No updates provided');
        }

        // Decode JSON updates
        $updates = json_decode(stripslashes($updates_json), true);
        
        if (!is_array($updates) || empty($updates)) {
            wp_send_json_error('Invalid updates format');
        }

        // Validate and sanitize updates
        $valid_updates = array();
        foreach ($updates as $update) {
            if (!isset($update['brand_id']) || !isset($update['product_type']) || !isset($update['is_active'])) {
                continue;
            }
            
            $valid_updates[] = array(
                'brand_id' => intval($update['brand_id']),
                'product_type' => sanitize_text_field($update['product_type']),
                'is_active' => intval($update['is_active'])
            );
        }

        if (empty($valid_updates)) {
            wp_send_json_error('No valid updates provided');
        }

        $result = ASM_Brand_Manager::batch_update_active_status($valid_updates);

        if ($result['updated'] > 0) {
            wp_send_json_success(array(
                'message' => sprintf('Successfully updated %d brand(s)', $result['updated']),
                'updated' => $result['updated'],
                'errors' => $result['errors']
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'No brands were updated',
                'errors' => $result['errors']
            ));
        }
    }


    /**
     * AJAX: Get activated brands for product type
     */
    public function ajax_get_activated_brands() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $product_type = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : '';
        
        if (empty($product_type) || !in_array($product_type, array('tire', 'wheel'))) {
            wp_send_json_error('Invalid product type');
        }

        $all_brands = ASM_Brand_Manager::get_brands($product_type);
        $activated_brands = array();
        
        foreach ($all_brands as $brand) {
            // Handle both boolean and integer is_active values
            $is_active = is_bool($brand->is_active) ? ($brand->is_active ? 1 : 0) : intval($brand->is_active);
            if ($is_active == 1) {
                $activated_brands[] = array(
                    'brand_id' => $brand->brand_id,
                    'brand_name' => $brand->brand_name
                );
            }
        }

        wp_send_json_success(array(
            'brands' => $activated_brands,
            'count' => count($activated_brands)
        ));
    }

    /**
     * AJAX: Save sync status
     */
    public function ajax_save_sync_status() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $sync_data_json = isset($_POST['sync_data']) ? stripslashes($_POST['sync_data']) : '{}';
        $sync_data = json_decode($sync_data_json, true);

        if (empty($sync_data) || !is_array($sync_data)) {
            wp_send_json_error('Invalid sync data');
        }

        // Add sync type if not present
        if (!isset($sync_data['sync_type'])) {
            $sync_data['sync_type'] = 'manual';
        }

        $saved = ASM_Sync_Status_Manager::save_sync_status($sync_data);

        // Send email notification after saving sync status
        // Always format as 'all' sync type to show both tires and wheels sections (even if one is empty)
        $email_stats = array(
            'tires' => array(
                'brands_processed' => isset($sync_data['summary']['total_brands_tires']) ? intval($sync_data['summary']['total_brands_tires']) : 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0
            ),
            'wheels' => array(
                'brands_processed' => isset($sync_data['summary']['total_brands_wheels']) ? intval($sync_data['summary']['total_brands_wheels']) : 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0
            ),
            'duration' => isset($sync_data['summary']['total_time']) ? floatval($sync_data['summary']['total_time']) : 0
        );
        
        // Calculate tire and wheel stats from brands array
        if (isset($sync_data['brands']) && is_array($sync_data['brands'])) {
            foreach ($sync_data['brands'] as $brand) {
                $product_type = isset($brand['product_type']) ? $brand['product_type'] : '';
                $imported = isset($brand['imported']) ? intval($brand['imported']) : 0;
                $updated = isset($brand['updated']) ? intval($brand['updated']) : 0;
                $skipped = isset($brand['skipped']) ? intval($brand['skipped']) : 0;
                $pages = isset($brand['pages']) ? intval($brand['pages']) : 0;
                
                if ($product_type === 'tire') {
                    $email_stats['tires']['total_imported'] += $imported;
                    $email_stats['tires']['total_updated'] += $updated;
                    $email_stats['tires']['total_skipped'] += $skipped;
                    $email_stats['tires']['total_pages'] += $pages;
                } elseif ($product_type === 'wheel') {
                    $email_stats['wheels']['total_imported'] += $imported;
                    $email_stats['wheels']['total_updated'] += $updated;
                    $email_stats['wheels']['total_skipped'] += $skipped;
                    $email_stats['wheels']['total_pages'] += $pages;
                }
            }
        }
        
        // Send email notification
        error_log('ASM: Preparing to send email notification');
        error_log('ASM: Email will be sent to: sdtireandwheel@yopmail.com');
        error_log('ASM: Email stats structure: ' . json_encode($email_stats));
        
        try {
            $email_sent = ASM_Email_Notifier::send_sync_email('all', $email_stats);
            
            // Log email attempt result
            error_log('ASM: Email notification attempt. Result: ' . ($email_sent ? 'SUCCESS' : 'FAILED'));
            error_log('ASM: Email stats: ' . json_encode($email_stats));
            error_log('ASM: Email sent to: sdtireandwheel@yopmail.com');
            
            if ($email_sent) {
                error_log('ASM: SUCCESS - Email notification sent successfully to sdtireandwheel@yopmail.com');
            } else {
                error_log('ASM: WARNING - Email notification failed to send. Check SMTP configuration.');
                error_log('ASM: Please check error logs for detailed error messages.');
            }
        } catch (Exception $e) {
            error_log('ASM: Exception sending email: ' . $e->getMessage());
            error_log('ASM: Exception trace: ' . $e->getTraceAsString());
            // ASM_Logger::error('Exception sending sync email', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        } catch (Error $e) {
            error_log('ASM: Fatal error sending email: ' . $e->getMessage());
            error_log('ASM: Fatal error trace: ' . $e->getTraceAsString());
            // ASM_Logger::error('Fatal error sending sync email', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        }

        // Send Slack notification
        try {
            $slack_sent = ASM_Slack_Notifier::send_sync_notification('all', $email_stats);
            
            if ($slack_sent) {
                error_log('ASM: SUCCESS - Slack notification sent successfully');
            } else {
                error_log('ASM: Slack notification not sent (webhook may not be configured)');
            }
        } catch (Exception $e) {
            error_log('ASM: Exception sending Slack notification: ' . $e->getMessage());
            // ASM_Logger::error('Exception sending sync Slack notification', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        } catch (Error $e) {
            error_log('ASM: Fatal error sending Slack notification: ' . $e->getMessage());
            // ASM_Logger::error('Fatal error sending sync Slack notification', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        }

        if ($saved) {
            wp_send_json_success(array(
                'message' => 'Sync status saved successfully'
            ));
        } else {
            wp_send_json_error('Failed to save sync status');
        }
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $sync_status = ASM_Sync_Status_Manager::get_sync_status();

        if ($sync_status === null) {
            wp_send_json_success(array(
                'sync_status' => null,
                'message' => 'No sync status found'
            ));
        } else {
            wp_send_json_success(array(
                'sync_status' => $sync_status
            ));
        }
    }

    /**
     * AJAX: Check if brands exist and fetch if they don't
     */
    public function ajax_check_and_fetch_brands() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Check if any brands exist (active or inactive)
        $total_brands = ASM_Brand_Manager::get_total_count();
        
        if ($total_brands > 0) {
            wp_send_json_success(array(
                'brands_exist' => true,
                'total_brands' => $total_brands,
                'brands_fetched' => 0,
                'message' => 'Brands already exist in database'
            ));
        }
        
        // No brands found - fetch from API
        $tire_result = $this->api_client->get_all_tire_brands();
        $tire_brands_saved = 0;
        
        if ($tire_result && !empty($tire_result['brands'])) {
            $brands_to_save = array();
            foreach ($tire_result['brands'] as $brand) {
                $brands_to_save[] = array(
                    'brand_name' => $brand->Brand,
                    'brand_id' => $brand->Id,
                    'product_type' => 'tire',
                    'is_active' => 1 // Default to active for new brands
                );
            }
            $tire_brands_saved = ASM_Brand_Manager::batch_save_brands($brands_to_save);
        }

        // Fetch all wheel brands
        $wheel_result = $this->api_client->get_all_wheel_brands();
        $wheel_brands_saved = 0;
        
        if ($wheel_result && !empty($wheel_result['brands'])) {
            $brands_to_save = array();
            foreach ($wheel_result['brands'] as $brand) {
                $brands_to_save[] = array(
                    'brand_name' => $brand->Brand,
                    'brand_id' => $brand->Id,
                    'product_type' => 'wheel',
                    'is_active' => 1 // Default to active for new brands
                );
            }
            $wheel_brands_saved = ASM_Brand_Manager::batch_save_brands($brands_to_save);
        }

        $total_saved = $tire_brands_saved + $wheel_brands_saved;
        
        if ($total_saved > 0) {
            wp_send_json_success(array(
                'brands_exist' => false,
                'brands_fetched' => $total_saved,
                'tire_brands_saved' => $tire_brands_saved,
                'wheel_brands_saved' => $wheel_brands_saved,
                'message' => sprintf('Fetched and saved %d brands from API', $total_saved)
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Failed to fetch brands from API. Please check your API key and connection.'
            ));
        }
    }

    /**
     * AJAX: Delete products by brand
     */
    public function ajax_delete_products_by_brand() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $brand_name = isset($_POST['brand_name']) ? sanitize_text_field($_POST['brand_name']) : '';
        $product_type = isset($_POST['product_type']) ? sanitize_text_field($_POST['product_type']) : 'both';
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 500;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        if (empty($brand_name)) {
            wp_send_json_error('Brand name is required');
        }

        // Validate product type
        if (!in_array($product_type, array('tire', 'wheel', 'both'))) {
            $product_type = 'both';
        }

        // Validate batch size
        if ($batch_size < 1 || $batch_size > 1000) {
            $batch_size = 500;
        }

        // Delete products
        $result = ASM_Product_Deleter::delete_products_by_brand($brand_name, $product_type, $batch_size, $offset);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get product counts for brands
     */
    public function ajax_get_brand_product_counts() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $brand_names_json = isset($_POST['brand_names']) ? stripslashes($_POST['brand_names']) : '[]';
        $brand_names = json_decode($brand_names_json, true);

        if (!is_array($brand_names) || empty($brand_names)) {
            wp_send_json_error('Invalid brand names');
        }

        // Get counts for all brands in one optimized query
        $counts = ASM_Product_Deleter::get_product_counts_for_brands($brand_names);

        wp_send_json_success(array(
            'counts' => $counts
        ));
    }

    /**
     * AJAX: Delete extra products (products without _is_autosync_product meta)
     * Supports batch processing to handle large deletions efficiently
     */
    public function ajax_delete_extra_products() {
        check_ajax_referer('asm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Check if hash table has entries
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        $hash_count = ASM_Hash_Manager::get_total_count();
        
        if ($hash_count == 0) {
            wp_send_json_error(array(
                'message' => 'Hash table is empty. Please sync products first before deleting extra products.'
            ));
        }

        // Load deletion class
        require_once ASM_PLUGIN_DIR . 'includes/class-product-deleter.php';
        
        // Get batch size from request or use default
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 500;
        if ($batch_size < 1 || $batch_size > 1000) {
            $batch_size = 500;
        }
        
        // Delete products without _is_autosync_product meta
        $result = ASM_Product_Deleter::delete_extra_products($batch_size);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'deleted_count' => $result['deleted_count'],
                'has_more' => isset($result['has_more']) ? $result['has_more'] : false,
                'completed' => !isset($result['has_more']) || !$result['has_more']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }

    /**
     * Delete product hash when product is deleted
     * Hooked into 'before_delete_post' to catch all product deletions
     *
     * @param int $post_id Post ID being deleted
     */
    public function delete_product_hash_on_deletion($post_id) {
        // Only process product post types
        $post_type = get_post_type($post_id);
        if ($post_type !== 'product') {
            return;
        }

        // Get SKU before deletion (post meta still exists at this point)
        $sku = get_post_meta($post_id, '_sku', true);
        
        if (!empty($sku)) {
            // Delete hash for this SKU
            ASM_Hash_Manager::delete_hash($sku);
            
            // Log the hash deletion
            // ASM_Logger::info('Hash deleted on product deletion', array(
            //     'product_id' => $post_id,
            //     'sku' => $sku
            // ));
        }
    }

    /**
     * Load WooCommerce email class when WooCommerce is initialized
     */
    public function load_wc_email_class() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Wait for WC_Email to be available (it's loaded during WooCommerce init)
        if (!class_exists('WC_Email')) {
            // If WC_Email isn't loaded yet, hook into woocommerce_init (only once)
            if (!has_action('woocommerce_init', array($this, 'load_wc_email_class'))) {
                add_action('woocommerce_init', array($this, 'load_wc_email_class'), 5);
            }
            return;
        }
        
        // Load our email class if not already loaded
        if (!class_exists('WC_Email_Product_Sync', false)) {
            $email_file = ASM_PLUGIN_DIR . 'includes/class-wc-email-product-sync.php';
            if (file_exists($email_file)) {
                require_once $email_file;
                error_log('ASM: Loaded WC_Email_Product_Sync class');
            } else {
                error_log('ASM: Email class file not found: ' . $email_file);
            }
        }
    }

    /**
     * Register WooCommerce email class
     *
     * @param array $emails Existing email classes
     * @return array Modified email classes
     */
    public function register_wc_email_class($emails) {
        // Only register if WooCommerce and WC_Email are available
        if (class_exists('WooCommerce') && class_exists('WC_Email')) {
            // Load the class file if not already loaded
            if (!class_exists('WC_Email_Product_Sync', false)) {
                $email_file = ASM_PLUGIN_DIR . 'includes/class-wc-email-product-sync.php';
                if (file_exists($email_file)) {
                    require_once $email_file;
                } else {
                    error_log('ASM: WooCommerce email class file not found: ' . $email_file);
                    return $emails;
                }
            }
            
            // Register the email class
            if (class_exists('WC_Email_Product_Sync')) {
                try {
                    $email_instance = new WC_Email_Product_Sync();
                    $emails['WC_Email_Product_Sync'] = $email_instance;
                    error_log('ASM: Successfully registered WC_Email_Product_Sync');
                } catch (Exception $e) {
                    error_log('ASM: Error registering WC_Email_Product_Sync: ' . $e->getMessage());
                }
            } else {
                error_log('ASM: WC_Email_Product_Sync class not found after requiring file');
            }
        } else {
            error_log('ASM: WooCommerce or WC_Email not available for email registration');
        }
        return $emails;
    }
}

/**
 * Initialize the plugin
 */
function ASM() {
    return AutoSync_Product_Manager::instance();
}

// Register activation, deactivation, and uninstall hooks (must be outside class)
register_activation_hook(__FILE__, array('AutoSync_Product_Manager', 'activate'));
register_deactivation_hook(__FILE__, array('AutoSync_Product_Manager', 'deactivate'));
register_uninstall_hook(__FILE__, array('AutoSync_Product_Manager', 'uninstall'));

// Start the plugin
ASM();

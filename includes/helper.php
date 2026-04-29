<?php
/**
 * Plugin Name: Helper
 * Plugin URI: https://sdtireandwheel.com/
 * Description: A plugin to fetch tire records from 3rd party API via AJAX form submission
 * Version: 1.0.0
 * Author: Syed
 * Author URI: https://sdtireandwheel.com/
 * License: GPL v2 or later
 * Text Domain: helper-plugin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HELPER_PLUGIN_VERSION', '1.0.0');
define('HELPER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HELPER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class Helper_Plugin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_helper_fetch_tires', array($this, 'ajax_fetch_tires'));
        add_action('wp_ajax_helper_delete_products', array($this, 'ajax_delete_products'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Helper Plugin', 'helper-plugin'),
            __('Helper', 'helper-plugin'),
            'manage_options',
            'helper-plugin',
            array($this, 'render_admin_page'),
            'dashicons-admin-generic',
            30
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin page
        if ($hook !== 'toplevel_page_helper-plugin') {
            return;
        }
        
        wp_enqueue_script(
            'helper-admin-js',
            HELPER_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            HELPER_PLUGIN_VERSION,
            true
        );
        
        wp_enqueue_style(
            'helper-admin-css',
            HELPER_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            HELPER_PLUGIN_VERSION
        );
        
        // Localize script for AJAX
        wp_localize_script('helper-admin-js', 'helperAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('helper_ajax_nonce')
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        include_once HELPER_PLUGIN_DIR . 'admin/views/admin-page.php';
    }
    
    /**
     * AJAX handler to fetch tires
     */
    public function ajax_fetch_tires() {
        // Verify nonce
        check_ajax_referer('helper_ajax_nonce', 'nonce');
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }
        
        // Get form data
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'tires';
        $brand = isset($_POST['brand']) ? sanitize_text_field($_POST['brand']) : '';
        $page_size = isset($_POST['page_size']) ? intval($_POST['page_size']) : 500;
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'API key is required'));
            return;
        }
        
        // Validate type
        $type = ($type === 'wheels') ? 'wheels' : 'tires';
        $type_capitalized = ucfirst($type); // "Tires" or "Wheels" for API response key
        
        // Set execution time and memory limit
        @ini_set('max_execution_time', 300);
        @ini_set('memory_limit', '512M');
        
        $base_url = "https://api.autosyncstudio.com/{$type}";
        $all_items = array();
        $page = 1;
        $more_items = true;
        $errors = array();
        $last_url = '';
        
        while ($more_items) {
            // Build URL with pagination
            $url = $base_url . "?key={$api_key}&f-brand={$brand}&p-number={$page}&p-size={$page_size}";
            $last_url = $url; // Store the last URL used
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                $errors[] = "cURL Error on page {$page}: " . curl_error($ch);
                curl_close($ch);
                break;
            }
            
            curl_close($ch);
            
            // Check HTTP status code
            if ($http_code !== 200) {
                $errors[] = "HTTP Error {$http_code} on page {$page}";
                break;
            }
            
            $data = json_decode($response, true);
            
            // Check for both Tires and Wheels keys in response
            if (!$data || (!isset($data["Tires"]) && !isset($data["Wheels"]))) {
                $errors[] = "Invalid API response on page {$page}";
                break;
            }
            
            // Merge items into master list (handle both Tires and Wheels)
            $items_key = isset($data["Tires"]) ? "Tires" : "Wheels";
            $all_items = array_merge($all_items, $data[$items_key]);
            
            if (isset($data["MoreItems"]) && $data["MoreItems"]) {
                $more_items = true;
                $page++;
            } else {
                $more_items = false;
            }
        }
        
        // Send response
        if (!empty($errors)) {
            wp_send_json_error(array(
                'message' => 'Errors occurred while fetching data',
                'errors' => $errors,
                'tires' => $all_items,
                'count' => count($all_items),
                'last_url' => $last_url
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Successfully fetched ' . $type,
                'tires' => $all_items,
                'count' => count($all_items),
                'pages_fetched' => $page,
                'type' => $type,
                'last_url' => $last_url
            ));
        }
    }
    
    /**
     * AJAX handler to delete products by category
     */
    public function ajax_delete_products() {
        // Verify nonce
        check_ajax_referer('helper_ajax_nonce', 'nonce');
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }
        
        // Get form data
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $delete_limit = isset($_POST['delete_limit']) ? intval($_POST['delete_limit']) : 50;
        
        if (empty($category_id)) {
            wp_send_json_error(array('message' => 'Please select a category'));
            return;
        }
        
        // Validate limit
        if ($delete_limit < 1 || $delete_limit > 500) {
            $delete_limit = 50;
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => 'WooCommerce is not active'));
            return;
        }
        
        // Set execution time and memory limit for bulk deletion
        @ini_set('max_execution_time', 300);
        @ini_set('memory_limit', '512M');
        
        // Get products in the category
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $delete_limit,
            'post_status' => 'any',
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $category_id,
                ),
            ),
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        
        $products = get_posts($args);
        
        if (empty($products)) {
            wp_send_json_success(array(
                'message' => 'No products found in the selected category',
                'deleted_count' => 0,
                'total_found' => 0
            ));
            return;
        }
        
        $deleted_count = 0;
        $failed_count = 0;
        $errors = array();
        
        // Disable WordPress hooks temporarily for faster deletion
        remove_action('before_delete_post', 'wp_delete_post_revision');
        
        // Use direct database deletion for speed (much faster than wp_delete_post)
        global $wpdb;
        
        $product_ids = array_map('intval', $products);
        $product_ids_str = implode(',', $product_ids);
        
        // Delete product variations first (if any)
        $variation_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$product_ids_str}) AND post_type = 'product_variation'");
        if (!empty($variation_ids)) {
            $variation_ids_str = implode(',', array_map('intval', $variation_ids));
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$variation_ids_str})");
            $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$variation_ids_str})");
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$variation_ids_str})");
        }
        
        // Delete product meta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$product_ids_str})");
        
        // Delete term relationships
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$product_ids_str})");
        
        // Delete posts
        $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$product_ids_str})");
        
        if ($deleted !== false) {
            $deleted_count = $deleted;
            
            // Clean up cache
            clean_post_cache($products);
            
            // Clear object cache
            foreach ($products as $product_id) {
                wp_cache_delete($product_id, 'posts');
            }
            
            // Clear WooCommerce transients
            if (function_exists('wc_delete_product_transients')) {
                foreach ($products as $product_id) {
                    wc_delete_product_transients($product_id);
                }
            }
        } else {
            // Fallback to wp_delete_post if direct deletion fails
            foreach ($products as $product_id) {
                $result = wp_delete_post($product_id, true);
                
                if ($result) {
                    $deleted_count++;
                } else {
                    $failed_count++;
                    $errors[] = "Failed to delete product ID: {$product_id}";
                }
            }
        }
        
        // Re-enable hooks
        add_action('before_delete_post', 'wp_delete_post_revision');
        
        // Get category name
        $category = get_term($category_id, 'product_cat');
        $category_name = $category && !is_wp_error($category) ? $category->name : 'Unknown';
        
        // Send response
        if ($failed_count > 0) {
            wp_send_json_error(array(
                'message' => "Deleted {$deleted_count} products from '{$category_name}' category. {$failed_count} failed.",
                'deleted_count' => $deleted_count,
                'failed_count' => $failed_count,
                'total_found' => count($products),
                'errors' => $errors
            ));
        } else {
            wp_send_json_success(array(
                'message' => "Successfully deleted {$deleted_count} products from '{$category_name}' category",
                'deleted_count' => $deleted_count,
                'total_found' => count($products),
                'category_name' => $category_name
            ));
        }
    }
}

// Initialize the plugin
new Helper_Plugin();


<?php
/**
 * Settings Page View
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1>AutoSync Product Manager</h1>
    <p class="description">Settings Page</p>

    <!-- Tabs Navigation -->
    <nav class="nav-tab-wrapper">
        <a href="#dashboard" class="nav-tab nav-tab-active">Dashboard</a>
        <a href="#api-config" class="nav-tab">API Configuration</a>
        <a href="#sync-settings" class="nav-tab">Sync Settings</a>
        <a href="#helper" class="nav-tab">Helper</a>
        <a href="#cron-lock-log" class="nav-tab">Cron Lock Log</a>
    </nav>

    <div class="asm-container">
        <!-- Dashboard Tab -->
        <div id="dashboard" class="asm-tab-content active">
            <?php include ASM_PLUGIN_DIR . 'admin/views/dashboard.php'; ?>
        </div>

        <!-- API Configuration Tab -->
        <div id="api-config" class="asm-tab-content">
            <div class="asm-card">
                <h2>API Configuration</h2>

                <form method="post" action="options.php">
                    <?php settings_fields('asm_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="asm_api_key">API Key</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="asm_api_key"
                                   name="asm_api_key"
                                   value="<?php echo esc_attr(get_option('asm_api_key')); ?>"
                                   class="regular-text"
                                   placeholder="Enter your AutoSync API key" />
                            <p class="description">Your AutoSync Studio API key (required)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="asm_api_url">API URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="asm_api_url"
                                   name="asm_api_url"
                                   value="<?php echo esc_attr(get_option('asm_api_url', 'https://api.autosyncstudio.com')); ?>"
                                   class="regular-text" />
                            <p class="description">AutoSync API base URL (default: https://api.autosyncstudio.com)</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
            </div>

            <!-- API Test Section -->
            <div class="asm-card">
                <h2>Test API Connection</h2>
                <p>Click the button below to verify your API connection is working.</p>

                <button type="button" id="asm-test-api" class="button button-primary">
                    Test Connection
                </button>

                <div id="asm-test-result" style="margin-top: 20px;"></div>
            </div>
            

            <?php /* Import Test Wheel Product Section
            <div class="asm-card">
                <h2>Import Test Wheel Product</h2>
                <p>Import a single wheel product from the API to test the import functionality.</p>

                <button type="button" id="asm-import-test" class="button button-secondary">
                    Import Test Wheel
                </button>

                <div id="asm-import-result" style="margin-top: 20px;"></div>
            </div>
            */ ?>

            <?php /* Import Test Tire Product Section
            <div class="asm-card">
                <h2>Import Test Tire Product</h2>
                <p>Import a single tire product from the API to test the tire import functionality.</p>

                <button type="button" id="asm-import-test-tire" class="button button-secondary">
                    Import Test Tire
                </button>

                <div id="asm-import-tire-result" style="margin-top: 20px;"></div>
            </div>
            */ ?>

            <!-- Bulk Import Wheels Section -->
            <?php /*
            <div class="asm-card">
                <h2>Bulk Import Wheels</h2>
                <p>Import multiple wheel products with pagination support.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="asm_bulk_page_size">Products Per Page</label>
                        </th>
                        <td>
                            <select id="asm_bulk_page_size">
                                <option value="500" selected>500</option>
                            </select>
                            <p class="description">Number of products to import per batch</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="asm_bulk_import_limit">Import Limit (Optional)</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="asm_bulk_import_limit"
                                   class="small-text"
                                   placeholder="e.g., 50"
                                   min="1" />
                            <p class="description">Maximum number of products to import (leave empty for no limit)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="asm_bulk_brand">Brand Filter (Optional)</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="asm_bulk_brand"
                                   class="regular-text"
                                   placeholder="e.g., Method, Fuel, etc." />
                            <p class="description">Leave empty to import all brands</p>
                        </td>
                    </tr>
                </table>

                <button type="button" id="asm-bulk-import" class="button button-primary">
                    Start Bulk Import
                </button>

                <div id="asm-bulk-progress" style="margin-top: 20px; display: none;">
                    <div class="asm-progress-bar">
                        <div class="asm-progress-fill"></div>
                    </div>
                    <p class="asm-progress-text"></p>
                </div>

                <div id="asm-bulk-result" style="margin-top: 20px;"></div>
            </div>
            */ ?>
            <!-- Bulk Import Tires Section -->
             <?php /*
            <div class="asm-card">
                <h2>Bulk Import Tires</h2>
                <p>Import multiple tire products with pagination support.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="asm_bulk_tire_page_size">Products Per Page</label>
                        </th>
                        <td>
                            <select id="asm_bulk_tire_page_size">
                                <option value="500" selected>500</option>
                            </select>
                            <p class="description">Number of tire products to import per batch</p>
                        </td>
                    </tr>
                   
                    <tr>
                        <th scope="row">
                            <label for="asm_bulk_tire_import_limit">Import Limit (Optional)</label>
                        </th>
                        <td>
                            <input type="number"
                                   id="asm_bulk_tire_import_limit"
                                   class="small-text"
                                   placeholder="e.g., 50"
                                   min="1" />
                            <p class="description">Maximum number of tire products to import (leave empty for no limit)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="asm_bulk_tire_brand">Brand Filter (Optional)</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="asm_bulk_tire_brand"
                                   class="regular-text"
                                   placeholder="e.g., Michelin, Goodyear, etc." />
                            <p class="description">Leave empty to import all tire brands</p>
                        </td>
                    </tr>
                </table>

                <button type="button" id="asm-bulk-import-tires" class="button button-primary">
                    Start Tire Bulk Import
                </button>

                <div id="asm-bulk-tire-progress" style="margin-top: 20px; display: none;">
                    <div class="asm-progress-bar">
                        <div class="asm-progress-fill"></div>
                    </div>
                    <p class="asm-progress-text"></p>
                </div>

                <div id="asm-bulk-tire-result" style="margin-top: 20px;"></div>
            </div>
            */ ?>
        </div>

        <!-- Sync Settings Tab -->
        <div id="sync-settings" class="asm-tab-content">
            <div class="asm-card">
                <h2>Sync Settings</h2>

                <form method="post" action="options.php">
                    <?php settings_fields('asm_sync_settings'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="asm_product_import_mode">Product Import Mode</label>
                            </th>
                            <td>
                                <select id="asm_product_import_mode" name="asm_product_import_mode">
                                    <option value="both" <?php selected(get_option('asm_product_import_mode', 'both'), 'both'); ?>>
                                        Both (Create & Update)
                                    </option>
                                    <option value="create" <?php selected(get_option('asm_product_import_mode'), 'create'); ?>>
                                        Create Only
                                    </option>
                                    <option value="update" <?php selected(get_option('asm_product_import_mode'), 'update'); ?>>
                                        Update Only
                                    </option>
                                </select>
                                <p class="description">Choose whether to create new products, update existing ones, or both</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="asm_image_mode">Image Storage Mode</label>
                            </th>
                            <td>
                                <select id="asm_image_mode" name="asm_image_mode">
                                    <option value="external" <?php selected(get_option('asm_image_mode', 'external'), 'external'); ?>>
                                        External URLs (Faster)
                                    </option>
                                    <option value="download" <?php selected(get_option('asm_image_mode'), 'download'); ?>>
                                        Download to WordPress
                                    </option>
                                </select>
                                <p class="description">External URLs are faster but depend on API server. Download stores images locally.</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </form>
            </div>

            <!-- Email Notifications Section -->
            <div class="asm-card" style="margin-top: 20px;">
                <h2>Email Notifications</h2>
                <p class="description">Configure email notifications for product sync completion.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Product Sync Notification Email</label>
                        </th>
                        <td>
                            <p>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=email&section=wc_email_product_sync')); ?>" 
                                   class="button button-secondary" 
                                   target="_blank">
                                    <span class="dashicons dashicons-email-alt" style="margin-top: 3px;"></span> 
                                    Configure Email Settings
                                </a>
                            </p>
                            <p class="description">
                                Click the button above to open WooCommerce email settings where you can enable/disable the Product Sync notification email and configure recipient addresses.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Slack Notifications Section -->
            <div class="asm-card" style="margin-top: 20px;">
                <h2>Slack Notifications</h2>
                <p class="description">Configure Slack webhook to receive notifications when product sync completes.</p>

                <form method="post" action="options.php">
                    <?php settings_fields('asm_sync_settings'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="asm_slack_enabled">Enable Slack Notifications</label>
                            </th>
                            <td>
                                <!-- Hidden field ensures "0" is saved when checkbox is unchecked -->
                                <input type="hidden" name="asm_slack_enabled" value="0" />

                                <label>
                                    <input type="checkbox"
                                           id="asm_slack_enabled"
                                           name="asm_slack_enabled"
                                           value="1"
                                           <?php checked(get_option('asm_slack_enabled', '1'), '1'); ?> />
                                    Turn on Slack notifications for product syncs
                                </label>
                                <p class="description">
                                    Turn this off to stop sending Slack notifications without removing the webhook URL.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="asm_slack_webhook_url">Slack Webhook URL</label>
                            </th>
                            <td>
                                <input type="url"
                                       id="asm_slack_webhook_url"
                                       name="asm_slack_webhook_url"
                                       value="<?php echo esc_attr(get_option('asm_slack_webhook_url', '')); ?>"
                                       class="regular-text"
                                       placeholder="https://hooks.slack.com/services/YOUR/WEBHOOK/URL" />
                                <p class="description">
                                    Enter your Slack webhook URL to receive sync completion notifications.<br>
                                    <a href="https://api.slack.com/messaging/webhooks" target="_blank">Learn how to create a Slack webhook</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </form>

                <!-- Test Slack Notification -->
                <?php /*
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <h3>Test Slack Notification</h3>
                    <p>Click the button below to send a test notification to your Slack channel.</p>

                    <button type="button" id="asm-test-slack" class="button button-secondary">
                        Send Test Notification
                    </button>

                    <div id="asm-test-slack-result" style="margin-top: 15px;"></div>
                </div>
                */?>
            </div>

            <!-- Automatic Sync (Action Scheduler) Section -->
            <!-- <div class="asm-card" style="margin-top: 20px;">
                <h2>Automatic Sync (Action Scheduler)</h2>
                <p>Products from all active brands are automatically synced daily at 2:00 AM using Action Scheduler.</p>
                
                <?php
                require_once ASM_PLUGIN_DIR . 'includes/class-cron-sync.php';
                $cron_status = ASM_Cron_Sync::get_cron_status();
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">WordPress Cron Status</th>
                        <td>
                            <?php if (!$cron_status['wp_cron_enabled']): ?>
                                <span style="color: #d63638;">✗ WordPress Cron Disabled</span><br>
                                <small style="color: #d63638;">WP-CRON is disabled. Please enable it or use a real cron job to trigger the sync.</small>
                            <?php elseif ($cron_status['scheduled']): ?>
                                <span style="color: #00a32a;">✓ Scheduled</span><br>
                                <small>Next sync: <?php echo esc_html($cron_status['next_run']); ?></small><br>
                                <small style="color: #646970;">Hook: <code><?php echo esc_html($cron_status['hook']); ?></code> | Schedule: <code><?php echo esc_html($cron_status['schedule']); ?></code></small>
                            <?php else: ?>
                                <span style="color: #d63638;">✗ Not Scheduled</span><br>
                                <small>Click "Save Changes" or reactivate the plugin to schedule the sync.</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p class="description">
                    WordPress Cron will automatically sync all active brands (both wheels and tires) every day at 2:00 AM.
                    No manual action is required. Products are fetched and stored directly using the optimized batch insertion system.
                    <br><br>
                    <strong>Note:</strong> For better reliability on production sites, consider using a real cron job instead of WP-Cron. 
                    You can use a cron tool plugin to manage this, or set up a server cron job that calls: 
                    <code>wp cron event run asm_daily_sync_all_brands</code>
                </p>
            </div> -->
        </div>

        <!-- Helper Tab -->
        <div id="helper" class="asm-tab-content">
            <?php
            // Load required classes
            require_once ASM_PLUGIN_DIR . 'includes/class-product-deleter.php';
            require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
            
            // Get all brands (fast - just from options)
            $tire_brands = ASM_Brand_Manager::get_brands('tire');
            $wheel_brands = ASM_Brand_Manager::get_brands('wheel');
            
            // Build brands arrays (counts will be loaded via AJAX for better performance)
            $tire_brands_with_counts = array();
            $wheel_brands_with_counts = array();
            
            foreach ($tire_brands as $brand) {
                $tire_brands_with_counts[] = array(
                    'brand_name' => $brand->brand_name,
                    'brand_id' => $brand->brand_id,
                    'product_count' => 0 // Will be loaded via AJAX
                );
            }
            
            foreach ($wheel_brands as $brand) {
                $wheel_brands_with_counts[] = array(
                    'brand_name' => $brand->brand_name,
                    'brand_id' => $brand->brand_id,
                    'product_count' => 0 // Will be loaded via AJAX
                );
            }
            ?>
            
            <div class="asm-card">
                <h2>Delete Products by Brand</h2>
                <p class="description">
                    <strong>Warning:</strong> This will permanently delete products and all their meta fields. This action cannot be undone.
                </p>

                <!-- Tire Brands Section -->
                <?php if (!empty($tire_brands_with_counts)): ?>
                <div style="margin-top: 30px;">
                    <h3>Tire Brands</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 30px;">
                                    <input type="checkbox" id="asm-select-all-tires" />
                                </th>
                                <th>Brand ID</th>
                                <th>Brand Name</th>
                                <th style="width: 150px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tire_brands_with_counts as $brand): ?>
                            <tr data-brand-name="<?php echo esc_attr($brand['brand_name']); ?>" data-product-type="tire">
                                <td>
                                    <input type="checkbox" class="asm-brand-checkbox-delete" 
                                           data-brand-name="<?php echo esc_attr($brand['brand_name']); ?>"
                                           data-product-type="tire"
                                           data-product-count="0" />
                                </td>
                                <td><?php echo esc_html($brand['brand_id']); ?></td>
                                <td><strong><?php echo esc_html($brand['brand_name']); ?></strong></td>
                                <td style="text-align: center;">
                                    <button type="button" 
                                            class="button button-small button-link-delete asm-delete-single-brand"
                                            data-brand-name="<?php echo esc_attr($brand['brand_name']); ?>"
                                            data-product-type="tire"
                                            data-product-count="0">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top: 15px;">
                        <button type="button" id="asm-delete-selected-tires" class="button button-primary" disabled>
                            Delete Selected Tire Brands
                        </button>
                    </div>
                    <div id="asm-delete-tires-result" style="margin-top: 15px;"></div>
                </div>
                <?php endif; ?>

                <!-- Wheel Brands Section -->
                <?php if (!empty($wheel_brands_with_counts)): ?>
                <div style="margin-top: 40px;">
                    <h3>Wheel Brands</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 30px;">
                                    <input type="checkbox" id="asm-select-all-wheels" />
                                </th>
                                <th>Brand ID</th>
                                <th>Brand Name</th>
                                <th style="width: 150px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wheel_brands_with_counts as $brand): ?>
                            <tr data-brand-name="<?php echo esc_attr($brand['brand_name']); ?>" data-product-type="wheel">
                                <td>
                                    <input type="checkbox" class="asm-brand-checkbox-delete" 
                                           data-brand-name="<?php echo esc_attr($brand['brand_name']); ?>"
                                           data-product-type="wheel"
                                           data-product-count="0" />
                                </td>
                                <td><?php echo esc_html($brand['brand_id']); ?></td>
                                <td><strong><?php echo esc_html($brand['brand_name']); ?></strong></td>
                                <td style="text-align: center;">
                                    <button type="button" 
                                            class="button button-small button-link-delete asm-delete-single-brand"
                                            data-brand-name="<?php echo esc_attr($brand['brand_name']); ?>"
                                            data-product-type="wheel"
                                            data-product-count="0">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top: 15px;">
                        <button type="button" id="asm-delete-selected-wheels" class="button button-primary" disabled>
                            Delete Selected Wheel Brands
                        </button>
                    </div>
                    <div id="asm-delete-wheels-result" style="margin-top: 15px;"></div>
                </div>
                <?php endif; ?>

                <?php if (empty($tire_brands_with_counts) && empty($wheel_brands_with_counts)): ?>
                <p>No brands found. Please fetch brands from the Brand Management page first.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cron Lock Log Tab -->
        <div id="cron-lock-log" class="asm-tab-content">
            <?php include ASM_PLUGIN_DIR . 'admin/views/cron-lock-log.php'; ?>
        </div>

    </div>
</div>

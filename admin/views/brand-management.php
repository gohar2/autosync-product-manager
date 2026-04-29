<?php
/**
 * Brand Management Page View
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load required classes
require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-sync-status-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';

// Get brands from options
$tire_brands = ASM_Brand_Manager::get_brands('tire');
$wheel_brands = ASM_Brand_Manager::get_brands('wheel');
$total_tire_brands = ASM_Brand_Manager::get_total_count('tire');
$total_wheel_brands = ASM_Brand_Manager::get_total_count('wheel');

// Get saved sync status (server-side, no AJAX)
$saved_sync_status = ASM_Sync_Status_Manager::get_sync_status();

// Get saved price/stock sync status
$price_stock_sync_status = ASM_Price_Stock_Sync_Status_Manager::get_sync_status();

// Check if hash table exists
global $wpdb;
$hash_table_name = ASM_Hash_Manager::get_table_name();
$hash_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hash_table_name}'") === $hash_table_name;

// Count active brands
$active_tire_brands = 0;
$active_wheel_brands = 0;
if (!empty($tire_brands)) {
    foreach ($tire_brands as $brand) {
        if (!empty($brand->is_active)) {
            $active_tire_brands++;
        }
    }
}
if (!empty($wheel_brands)) {
    foreach ($wheel_brands as $brand) {
        if (!empty($brand->is_active)) {
            $active_wheel_brands++;
        }
    }
}
?>

<div class="wrap">
    <h1>Brand Management</h1>
    <p class="description">Manage active brands for wheels and tires</p>

    <!-- Fetch All Brands Button (moved above container) -->
    <div style="margin-bottom: 20px;">
        <button type="button" id="asm-fetch-brands" class="button button-primary">
            Fetch All Brands
        </button>
        <div id="asm-fetch-result" style="margin-top: 15px;"></div>
    </div>

    <div class="asm-container">
        <!-- Table Status and Sync Status Section -->
        <div class="asm-status-row">
            <!-- Price/Stock Sync Status -->
            <div class="asm-card asm-card-half">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <h2 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <span class="dashicons dashicons-update" style="color: #0073aa;"></span>
                        Price/Stock Sync Status
                    </h2>
                    <?php if ($hash_table_exists): ?>
                        <button type="button" id="asm-sync-inventory-quantity" class="button button-primary" style="background-color: #00a32a;">
                            <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity
                        </button>
                    <?php endif; ?>
                </div>

                <div id="asm-price-stock-sync-result" style="display: none; margin-bottom: 15px;"></div>

                <?php if ($hash_table_exists && $price_stock_sync_status): ?>
                    <div style="margin-bottom: 15px;">
                        <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                            Last Sync: <?php 
                                echo esc_html($price_stock_sync_status['last_sync_time'] ?? 'Never'); 
                                if (isset($price_stock_sync_status['sync_type'])) {
                                    echo ' (' . esc_html(ucfirst($price_stock_sync_status['sync_type'])) . ')';
                                }
                            ?>
                        </div>

                        <?php if (isset($price_stock_sync_status['summary'])): 
                            $summary = $price_stock_sync_status['summary'];
                            $totalTimeDisplay = isset($summary['total_time']) 
                                ? ($summary['total_time'] < 60 
                                    ? number_format($summary['total_time'], 1) . 's' 
                                    : number_format($summary['total_time'] / 60, 1) . 'm')
                                : '0s';
                        ?>
                            <div class="asm-status-item asm-status-success" style="margin-bottom: 15px; padding: 15px; background: #e8f5e9; border-left: 3px solid #00a32a;">
                                <span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 20px; margin-right: 10px;"></span>
                                <div><strong style="font-size: 16px;">Overall Summary</strong><br>
                                <span style="margin-top: 10px; display: inline-block;">
                                Tires: <strong><?php echo esc_html(number_format($summary['tires_updated'] ?? 0)); ?></strong> updated, 
                                <strong><?php echo esc_html(number_format($summary['tires_skipped'] ?? 0)); ?></strong> skipped<?php 
                                    if (isset($summary['tires_errors']) && $summary['tires_errors'] > 0) {
                                        echo ', <strong style="color: #dc3232;">' . esc_html(number_format($summary['tires_errors'])) . '</strong> errors';
                                    }
                                ?><br>
                                Wheels: <strong><?php echo esc_html(number_format($summary['wheels_updated'] ?? 0)); ?></strong> updated, 
                                <strong><?php echo esc_html(number_format($summary['wheels_skipped'] ?? 0)); ?></strong> skipped<?php 
                                    if (isset($summary['wheels_errors']) && $summary['wheels_errors'] > 0) {
                                        echo ', <strong style="color: #dc3232;">' . esc_html(number_format($summary['wheels_errors'])) . '</strong> errors';
                                    }
                                ?><br>
                                Total: <strong><?php echo esc_html(number_format($summary['total_updated'] ?? 0)); ?></strong> updated, 
                                <strong><?php echo esc_html(number_format($summary['total_skipped'] ?? 0)); ?></strong> skipped | 
                                Total Time: <strong><?php echo esc_html($totalTimeDisplay); ?></strong><br>
                                Tire Brands: <strong><?php echo esc_html(number_format($summary['total_brands_tires'] ?? 0)); ?></strong> | 
                                Wheel Brands: <strong><?php echo esc_html(number_format($summary['total_brands_wheels'] ?? 0)); ?></strong>
                                </span></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($hash_table_exists): ?>
                    <div class="asm-status-placeholder">
                        <p class="asm-status-message" style="color: #666;">
                            <span class="dashicons dashicons-info" style="color: #ffb900; margin-right: 5px;"></span>
                            No sync status available yet. Run "Sync Inventory & Quantity" to see statistics.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="asm-status-placeholder">
                        <p class="asm-status-message" style="color: #666;">
                            <span class="dashicons dashicons-info" style="color: #ffb900; margin-right: 5px;"></span>
                            Hash table does not exist. Please create it from the Hash Management page first.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sync Status Section -->
            <div class="asm-card asm-card-half asm-sync-status-card">
                <div class="asm-section-header">
                    <h2>
                        <span class="dashicons dashicons-update" style="margin-right: 5px;"></span>
                        Full Product Sync Status
                    </h2>
                    <button type="button" id="asm-sync-all" class="button button-primary">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync All
                    </button>
                </div>
                <div id="asm-sync-status-content" class="asm-sync-status-content">
                    <?php if ($saved_sync_status && !empty($saved_sync_status['summary'])): ?>
                        <?php
                        // Display sync status server-side
                        $summary = $saved_sync_status['summary'];
                        $lastSyncTime = isset($saved_sync_status['last_sync_time']) ? $saved_sync_status['last_sync_time'] : (isset($saved_sync_status['last_sync_date']) ? $saved_sync_status['last_sync_date'] : 'Unknown');
                        $syncType = isset($saved_sync_status['sync_type']) && $saved_sync_status['sync_type'] === 'cron' ? 'Automatic (Cron)' : 'Manual';
                        ?>
                        <div class="asm-status-item asm-status-info" style="margin-bottom: 15px;">
                            <span class="dashicons dashicons-info"></span>
                            <div><strong>Last Sync:</strong> <?php echo esc_html($lastSyncTime); ?> (<?php echo esc_html($syncType); ?>)</div>
                        </div>
                        
                        <?php
                        // Overall Summary
                        $totalTimeDisplay = ($summary['total_time'] < 60) ? number_format($summary['total_time'], 1) . 's' : number_format($summary['total_time'] / 60, 1) . 'm';
                        ?>
                        <div class="asm-status-item asm-status-success" style="margin-bottom: 15px; padding: 15px; background: #e8f5e9; border-left: 3px solid #00a32a;">
                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 20px; margin-right: 10px;"></span>
                            <div><strong style="font-size: 16px;">Overall Summary</strong><br>
                            <span style="margin-top: 10px; display: inline-block;">
                            Pages: <strong><?php echo esc_html(number_format($summary['total_pages'])); ?></strong> | 
                            Imported: <strong><?php echo esc_html(number_format($summary['total_imported'])); ?></strong> | 
                            Updated: <strong><?php echo esc_html(number_format($summary['total_updated'])); ?></strong> | 
                            Skipped: <strong><?php echo esc_html(number_format($summary['total_skipped'])); ?></strong> | 
                            Total Time: <strong><?php echo esc_html($totalTimeDisplay); ?></strong><br>
                            Pages Processed: <strong><?php echo esc_html(number_format($summary['pages_processed'] ?? $summary['total_pages'])); ?></strong> | 
                            Tire Brands: <strong><?php echo esc_html(number_format($summary['total_brands_tires'] ?? 0)); ?></strong> | 
                            Wheel Brands: <strong><?php echo esc_html(number_format($summary['total_brands_wheels'] ?? 0)); ?></strong>
                            </span></div>
                            </div>
                        
                        <?php if (!empty($saved_sync_status['brands']) && is_array($saved_sync_status['brands'])): ?>
                            <div style="margin-top: 15px;"><strong>Brand-wise Details:</strong></div>
                            <?php foreach ($saved_sync_status['brands'] as $brand): ?>
                                <?php
                                $brandTimeDisplay = ($brand['time'] < 60) ? number_format($brand['time'], 1) . 's' : number_format($brand['time'] / 60, 1) . 'm';
                                $icon_svg = ($brand['product_type'] === 'tire') ? 'tire.svg' : 'wheel.svg';
                                ?>
                                <div class="asm-status-item" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 2px solid #2271b1;">
                                    <img src="<?php echo esc_url(ASM_PLUGIN_URL . 'admin/img/' . $icon_svg); ?>" alt="<?php echo esc_attr($brand['product_type']); ?>" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 8px; color: #2271b1;" />
                                    <div><strong><?php echo esc_html($brand['brand_name']); ?></strong> (<?php echo esc_html($brand['product_type']); ?>)<br>
                                    <span style="font-size: 12px; color: #646970;">
                                    Pages: <?php echo esc_html(number_format($brand['pages'])); ?> | 
                                    Imported: <?php echo esc_html(number_format($brand['imported'])); ?> | 
                                    Updated: <?php echo esc_html(number_format($brand['updated'])); ?> | 
                                    Skipped: <?php echo esc_html(number_format($brand['skipped'])); ?> | 
                                    Time: <?php echo esc_html($brandTimeDisplay); ?>
                                    </span></div>
                                    </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="asm-status-placeholder">
                            <p class="asm-status-message">
                                <span class="dashicons dashicons-info" style="color: #2271b1; margin-right: 5px;"></span>
                                Sync status will appear here when you start syncing products.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tire Brands -->
        <?php if (!empty($tire_brands)): ?>
        <div class="asm-card">
            <div class="asm-section-header">
                <h2>Tire Brands (<?php echo count($tire_brands); ?>)</h2>
                <div class="asm-header-actions">
                    <button type="button" id="asm-sync-tires" class="button button-primary">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Tires (<?php echo esc_html($active_tire_brands); ?>)
                    </button>
                </div>
            </div>
            
            <!-- Bulk Actions Toolbar for Tires -->
            <div class="asm-bulk-actions-toolbar" data-product-type="tire">
                <div class="asm-bulk-actions-left">
                    <button type="button" class="asm-bulk-btn asm-select-all" data-action="select-all">
                        <span class="dashicons dashicons-yes"></span> Select All
                    </button>
                    <button type="button" class="asm-bulk-btn asm-deselect-all" data-action="deselect-all">
                        <span class="dashicons dashicons-dismiss"></span> Deselect All
                    </button>
                    <button type="button" class="asm-bulk-btn asm-activate-selected" data-action="activate">
                        <span class="dashicons dashicons-yes-alt"></span> Activate Selected
                    </button>
                </div>
            </div>
            
            <div id="asm-sync-tires-result" style="margin-top: 15px; margin-bottom: 15px; display: none;"></div>
            <div id="asm-bulk-tires-result" class="asm-bulk-result" style="display: none;"></div>
            <div class="asm-brands-grid">
                <?php foreach ($tire_brands as $brand): ?>
                <div class="asm-brand-item <?php echo $brand->is_active ? '' : 'inactive'; ?>" data-brand-id="<?php echo esc_attr($brand->brand_id); ?>" data-product-type="tire">
                    <label class="asm-brand-label">
                        <input type="checkbox" 
                               class="asm-brand-checkbox" 
                               data-brand-id="<?php echo esc_attr($brand->brand_id); ?>"
                               data-product-type="tire"
                               data-brand-name="<?php echo esc_attr($brand->brand_name); ?>"
                               <?php checked($brand->is_active, 1); ?>>
                        <span class="asm-brand-name"><?php echo esc_html($brand->brand_name); ?></span>
                        <span class="asm-brand-loading asm-loading-delete" style="display: none;">
                            <span class="spinner is-active" style="float: none; margin: 0 5px;"></span>
                            <span class="asm-loading-text">Deactivating...</span>
                        </span>
                        <span class="asm-brand-loading asm-loading-activate" style="display: none;">
                            <span class="spinner is-active" style="float: none; margin: 0 5px;"></span>
                            <span class="asm-loading-text">Activating...</span>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="asm-card">
            <h2>Tire Brands</h2>
            <p>No tire brands found. Click "Fetch All Brands" to import brands from the API.</p>
        </div>
        <?php endif; ?>

        <!-- Wheel Brands -->
        <?php if (!empty($wheel_brands)): ?>
        <div class="asm-card">
            <div class="asm-section-header">
                <h2>Wheel Brands (<?php echo count($wheel_brands); ?>)</h2>
                <div class="asm-header-actions">
                    <button type="button" id="asm-sync-wheels" class="button button-primary">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Wheels (<?php echo esc_html($active_wheel_brands); ?>)
                    </button>
                </div>
            </div>
            
            <!-- Bulk Actions Toolbar for Wheels -->
            <div class="asm-bulk-actions-toolbar" data-product-type="wheel">
                <div class="asm-bulk-actions-left">
                    <button type="button" class="asm-bulk-btn asm-select-all" data-action="select-all">
                        <span class="dashicons dashicons-yes"></span> Select All
                    </button>
                    <button type="button" class="asm-bulk-btn asm-deselect-all" data-action="deselect-all">
                        <span class="dashicons dashicons-dismiss"></span> Deselect All
                    </button>
                    <button type="button" class="asm-bulk-btn asm-activate-selected" data-action="activate">
                        <span class="dashicons dashicons-yes-alt"></span> Activate Selected
                    </button>
                </div>
            </div>
            
            <div id="asm-sync-wheels-result" style="margin-top: 15px; margin-bottom: 15px; display: none;"></div>
            <div id="asm-bulk-wheels-result" class="asm-bulk-result" style="display: none;"></div>
            <div class="asm-brands-grid">
                <?php foreach ($wheel_brands as $brand): ?>
                <div class="asm-brand-item <?php echo $brand->is_active ? '' : 'inactive'; ?>" data-brand-id="<?php echo esc_attr($brand->brand_id); ?>" data-product-type="wheel">
                    <label class="asm-brand-label">
                        <input type="checkbox" 
                               class="asm-brand-checkbox" 
                               data-brand-id="<?php echo esc_attr($brand->brand_id); ?>"
                               data-product-type="wheel"
                               data-brand-name="<?php echo esc_attr($brand->brand_name); ?>"
                               <?php checked($brand->is_active, 1); ?>>
                        <span class="asm-brand-name"><?php echo esc_html($brand->brand_name); ?></span>
                        <span class="asm-brand-loading asm-loading-delete" style="display: none;">
                            <span class="spinner is-active" style="float: none; margin: 0 5px;"></span>
                            <span class="asm-loading-text">Deactivating...</span>
                        </span>
                        <span class="asm-brand-loading asm-loading-activate" style="display: none;">
                            <span class="spinner is-active" style="float: none; margin: 0 5px;"></span>
                            <span class="asm-loading-text">Activating...</span>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="asm-card">
            <h2>Wheel Brands</h2>
            <p>No wheel brands found. Click "Fetch All Brands" to import brands from the API.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Get AJAX URL and nonce from WordPress
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo wp_create_nonce('asm_nonce'); ?>';
    
    // Fetch brands from API
    $('#asm-fetch-brands').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $result = $('#asm-fetch-result');
        
        $button.prop('disabled', true).text('Fetching...');
        $result.html('<p>Fetching brands from API. This may take a moment...</p>');
        
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_fetch_brands',
                nonce: asmData.nonce
            },
            timeout: 300000, // 5 minutes
            success: function(response) {
                if (response && response.success) {
                    var msg = 'Brands fetched successfully!<br>';
                    msg += 'Tire brands saved: ' + response.data.tire_brands_saved + '<br>';
                    msg += 'Wheel brands saved: ' + response.data.wheel_brands_saved + '<br>';
                    msg += 'Total saved: ' + response.data.total_saved;
                    $result.html('<div class="notice notice-success"><p>' + msg + '</p></div>');
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $result.html('<div class="notice notice-error"><p>' + (response.data || 'Failed to fetch brands') + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>AJAX error occurred</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Fetch All Brands');
            }
        });
    });

    // Track if bulk operation is in progress
    var bulkOperationInProgress = {
        tire: false,
        wheel: false
    };
    
    // Function to save sync status
    function saveSyncStatus($brandsContainer, startTime, tireStats, wheelStats) {
        var brands = [];
        var tireBrands = [];
        var wheelBrands = [];
        
        // Collect brand-wise stats from the UI
        $brandsContainer.find('.asm-brand-progress').each(function() {
            var $progress = $(this);
            var $summary = $progress.find('.asm-brand-summary');
            if ($summary.length === 0) return;
            
            var brandId = $progress.data('brand-id');
            var brandNameText = $progress.find('.asm-brand-progress-header strong').text();
            var brandName = brandNameText.replace(/^\[\d+\/\d+\]\s*/, '');
            
            // Get product type from data attribute (set when brand progress is created)
            // This is the primary method - data-product-type is set when brand progress is created
            var productType = $progress.data('product-type');
            
            // If data attribute is missing, try to determine from context
            if (!productType || productType === '') {
                // Check parent containers as fallback
                if ($progress.closest('.asm-sync-session[data-product-type="wheel"]').length > 0) {
                    productType = 'wheel';
                } else if ($progress.closest('.asm-sync-session[data-product-type="tire"]').length > 0) {
                    productType = 'tire';
                } else {
                    // Last resort: default to tire (but log warning)
                    console.warn('Could not determine product type for brand:', brandName, 'Defaulting to tire');
                    productType = 'tire';
                }
            }
            
            // Ensure productType is valid
            if (productType !== 'tire' && productType !== 'wheel') {
                console.error('Invalid product type for brand:', brandName, 'Type:', productType);
                return; // Skip this brand
            }
            
            var imported = parseInt($summary.data('imported') || 0);
            var updated = parseInt($summary.data('updated') || 0);
            var skipped = parseInt($summary.data('skipped') || 0);
            var pages = parseInt($summary.data('pages') || 0);
            
            // Extract time from summary text
            var timeText = $summary.html();
            var timeMatch = timeText.match(/Time:\s*<strong>([^<]+)<\/strong>/);
            var time = 0;
            if (timeMatch) {
                var timeStr = timeMatch[1].trim();
                if (timeStr.indexOf('m') !== -1) {
                    time = parseFloat(timeStr.replace('m', '')) * 60;
                } else {
                    time = parseFloat(timeStr.replace('s', ''));
                }
            }
            
            var brandData = {
                product_type: productType,
                brand_id: brandId,
                brand_name: brandName,
                imported: imported,
                updated: updated,
                skipped: skipped,
                pages: pages,
                time: time
            };
            
            brands.push(brandData);
            if (productType === 'tire') {
                tireBrands.push(brandData);
            } else {
                wheelBrands.push(brandData);
            }
        });
        
        // Calculate summary from brand-wise data (this is the source of truth)
        var totalTime = ((Date.now() - startTime) / 1000);
        var totalPages = 0, totalImported = 0, totalUpdated = 0, totalSkipped = 0;
        
        brands.forEach(function(brand) {
            totalPages += brand.pages;
            totalImported += brand.imported;
            totalUpdated += brand.updated;
            totalSkipped += brand.skipped;
        });
        
        // Note: We use brand-wise calculated totals instead of callback stats
        // because the brand-wise data is the actual source of truth from the UI
        // Callback stats (tireStats/wheelStats) may have aggregation issues
        
        // Debug logging to help identify issues
        console.log('Saving sync status:', {
            totalBrands: brands.length,
            tireBrands: tireBrands.length,
            wheelBrands: wheelBrands.length,
            brands: brands.map(function(b) { return b.brand_name + ' (' + b.product_type + ')'; })
        });
        
        // Add timestamps to sync data
        var now = new Date();
        var syncData = {
            sync_type: 'manual',
            brands: brands,
            summary: {
                total_pages: totalPages,
                total_imported: totalImported,
                total_updated: totalUpdated,
                total_skipped: totalSkipped,
                total_time: totalTime,
                pages_processed: totalPages,
                total_brands_tires: tireBrands.length,
                total_brands_wheels: wheelBrands.length
            },
            last_sync_time: now.toISOString().slice(0, 19).replace('T', ' '),
            last_sync_date: now.toISOString().slice(0, 10)
        };
        
        // Save via AJAX
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_save_sync_status',
                nonce: asmData.nonce,
                sync_data: JSON.stringify(syncData)
            },
            success: function(response) {
                if (response && response.success) {
                    console.log('Sync status saved successfully');
                    // Display sync status without page reload
                    loadSavedSyncStatus();
                } else {
                    console.error('Failed to save sync status:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error saving sync status:', error);
            }
        });
    }
    
    // Checkbox change handler - UI only, no AJAX
    // Checkboxes are used for selection only - actual activation/deactivation happens via bulk buttons
    $('.asm-brand-checkbox').on('change', function() {
        var $checkbox = $(this);
        var productType = $checkbox.data('product-type');
        
        // Update sync button with active brand count (based on checked checkboxes)
        updateSyncButtonCount(productType);
        
        // Update bulk buttons enable/disable state
        updateBulkButtons(productType);
    });
    
    // Function to refresh brand visual states based on checkbox state
    // Checkbox checked = active (no inactive class)
    // Checkbox unchecked = inactive (has inactive class)
    function refreshAllBrandStates(productType) {
        $('.asm-brand-checkbox[data-product-type="' + productType + '"]').each(function() {
            var $checkbox = $(this);
            var $brandItem = $checkbox.closest('.asm-brand-item');
            // Checkbox state reflects active/inactive status
            if ($checkbox.is(':checked')) {
                $brandItem.removeClass('inactive');
            } else {
                $brandItem.addClass('inactive');
            }
        });
    }
    
    // Initialize inactive class for unchecked items on page load
    function initializeBrandStates() {
        $('.asm-brand-checkbox').each(function() {
            var $checkbox = $(this);
            var $brandItem = $checkbox.closest('.asm-brand-item');
            if ($checkbox.is(':checked')) {
                $brandItem.removeClass('inactive');
            } else {
                $brandItem.addClass('inactive');
            }
        });
    }
    
    // Load and display saved sync status (called after saveSyncStatus completes)
    function loadSavedSyncStatus() {
        // Fetch sync status via AJAX and display it without page reload
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_get_sync_status',
                nonce: asmData.nonce
            },
            success: function(response) {
                if (response && response.success && response.data.sync_status) {
                    displaySyncStatus(response.data.sync_status);
                }
            }
        });
    }
    
    // Helper function to format numbers with commas
    function formatNumber(num) {
        if (num === null || num === undefined || isNaN(num)) return '0';
        return parseInt(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    // Display sync status in the UI
    function displaySyncStatus(syncStatus) {
        var $statusContent = $('#asm-sync-status-content');
        
        if (!syncStatus || !syncStatus.summary) {
            return;
        }
        
        var summary = syncStatus.summary;
        var lastSyncTime = syncStatus.last_sync_time || syncStatus.last_sync_date || 'Unknown';
        var syncType = syncStatus.sync_type === 'cron' ? 'Automatic (Cron)' : 'Manual';
        
        var html = '<div class="asm-status-item asm-status-info" style="margin-bottom: 15px;">' +
            '<span class="dashicons dashicons-info"></span>' +
            '<div><strong>Last Sync:</strong> ' + lastSyncTime + ' (' + syncType + ')</div>' +
            '</div>';
        
        // Overall Summary
        var totalTimeDisplay = summary.total_time < 60 ? summary.total_time.toFixed(1) + 's' : (summary.total_time / 60).toFixed(1) + 'm';
        html += '<div class="asm-status-item asm-status-success" style="margin-bottom: 15px; padding: 15px; background: #e8f5e9; border-left: 3px solid #00a32a;">' +
            '<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 20px; margin-right: 10px;"></span>' +
            '<div><strong style="font-size: 16px;">Overall Summary</strong><br>' +
            '<span style="margin-top: 10px; display: inline-block;">' +
            'Pages: <strong>' + formatNumber(summary.total_pages) + '</strong> | ' +
            'Imported: <strong>' + formatNumber(summary.total_imported) + '</strong> | ' +
            'Updated: <strong>' + formatNumber(summary.total_updated) + '</strong> | ' +
            'Skipped: <strong>' + formatNumber(summary.total_skipped) + '</strong> | ' +
            'Total Time: <strong>' + totalTimeDisplay + '</strong><br>' +
            'Pages Processed: <strong>' + formatNumber(summary.pages_processed || summary.total_pages) + '</strong> | ' +
            'Tire Brands: <strong>' + formatNumber(summary.total_brands_tires || 0) + '</strong> | ' +
            'Wheel Brands: <strong>' + formatNumber(summary.total_brands_wheels || 0) + '</strong>' +
            '</span></div>' +
            '</div>';
        
        // Brand-wise details
        if (syncStatus.brands && syncStatus.brands.length > 0) {
            html += '<div style="margin-top: 15px;"><strong>Brand-wise Details:</strong></div>';
            syncStatus.brands.forEach(function(brand) {
                var brandTimeDisplay = brand.time < 60 ? brand.time.toFixed(1) + 's' : (brand.time / 60).toFixed(1) + 'm';
                var iconSvg = brand.product_type === 'tire' ? 'tire.svg' : 'wheel.svg';
                html += '<div class="asm-status-item" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 2px solid #2271b1;">' +
                    '<img src="' + asmData.plugin_url + 'admin/img/' + iconSvg + '" alt="' + brand.product_type + '" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 8px; color: #2271b1;" />' +
                    '<div><strong>' + brand.brand_name + '</strong> (' + brand.product_type + ')<br>' +
                    '<span style="font-size: 12px; color: #646970;">' +
                    'Pages: ' + formatNumber(brand.pages) + ' | ' +
                    'Imported: ' + formatNumber(brand.imported) + ' | ' +
                    'Updated: ' + formatNumber(brand.updated) + ' | ' +
                    'Skipped: ' + formatNumber(brand.skipped) + ' | ' +
                    'Time: ' + brandTimeDisplay +
                    '</span></div>' +
                    '</div>';
            });
        }
        
        $statusContent.html(html);
    }
    
    // Initialize on page load
    initializeBrandStates();
    
    // Load saved sync status on page load (server-side, no AJAX)
    // Sync status is loaded via PHP when page renders

    // ============================================
    // BULK ACTIONS FUNCTIONALITY
    // ============================================
    
    // Enable/disable bulk action buttons
    function updateBulkButtons(productType) {
        var $toolbar = $('.asm-bulk-actions-toolbar[data-product-type="' + productType + '"]');
        var $checkboxes = $('.asm-brand-checkbox[data-product-type="' + productType + '"]');
        var totalCount = $checkboxes.length;
        
        // Enable "Update Selected" button if there are any brands
        if (totalCount > 0) {
            $toolbar.find('.asm-activate-selected').prop('disabled', false).removeClass('disabled');
        } else {
            $toolbar.find('.asm-activate-selected').prop('disabled', true).addClass('disabled');
        }
    }
    
    // Initialize bulk buttons
    updateBulkButtons('tire');
    updateBulkButtons('wheel');
    
    // Select All for a product type - only selects checkboxes, doesn't activate brands
    $(document).on('click', '.asm-select-all[data-action="select-all"]', function() {
        var productType = $(this).closest('.asm-bulk-actions-toolbar').data('product-type');
        var $checkboxes = $('.asm-brand-checkbox[data-product-type="' + productType + '"]');
        
        // Just select all checkboxes - no activation/deactivation
        $checkboxes.prop('checked', true);
        
        // Update visual state based on checkbox state
        refreshAllBrandStates(productType);
        
        // Update sync button count
        updateSyncButtonCount(productType);
        
        // Update bulk buttons enable/disable state
        updateBulkButtons(productType);
    });
    
    // Deselect All for a product type - only deselects checkboxes, doesn't deactivate brands
    $(document).on('click', '.asm-deselect-all[data-action="deselect-all"]', function() {
        var productType = $(this).closest('.asm-bulk-actions-toolbar').data('product-type');
        var $checkboxes = $('.asm-brand-checkbox[data-product-type="' + productType + '"]');
        
        // Just deselect all checkboxes - no deactivation
        $checkboxes.prop('checked', false);
        
        // Update visual state based on checkbox state
        refreshAllBrandStates(productType);
        
        // Update sync button count
        updateSyncButtonCount(productType);
        
        // Update bulk buttons enable/disable state
        updateBulkButtons(productType);
    });
    
    // Update Selected - Processes ALL brands based on checkbox state
    // Checked brands = Active, Unchecked brands = Inactive
    $(document).on('click', '.asm-activate-selected[data-action="activate"]', function() {
        var $toolbar = $(this).closest('.asm-bulk-actions-toolbar');
        var productType = $toolbar.data('product-type');
        var $allCheckboxes = $('.asm-brand-checkbox[data-product-type="' + productType + '"]');
        
        if ($allCheckboxes.length === 0) {
            return;
        }
        
        bulkUpdateAllBrands(productType, $allCheckboxes);
    });
    
    // Bulk update ALL brands based on checkbox state
    // Checked = Active, Unchecked = Inactive
    function bulkUpdateAllBrands(productType, $allCheckboxes) {
        var $toolbar = $('.asm-bulk-actions-toolbar[data-product-type="' + productType + '"]');
        var $result = $('#asm-bulk-' + productType + '-result');
        var $buttons = $toolbar.find('.asm-bulk-btn');
        
        // Set bulk operation flag
        bulkOperationInProgress[productType] = true;
        
        // Prepare updates array for ALL brands
        var updates = [];
        $allCheckboxes.each(function() {
            var $checkbox = $(this);
            var isChecked = $checkbox.is(':checked');
            
            updates.push({
                brand_id: $checkbox.data('brand-id'),
                product_type: productType,
                is_active: isChecked ? 1 : 0
            });
        });
        
        // Disable all buttons and checkboxes
        $buttons.prop('disabled', true);
        $('.asm-brand-checkbox[data-product-type="' + productType + '"]').prop('disabled', true);
        
        // Show loading state
        $result.removeClass('notice-success notice-error').addClass('notice notice-info').show();
        var checkedCount = $allCheckboxes.filter(':checked').length;
        var uncheckedCount = $allCheckboxes.length - checkedCount;
        $result.html('<p><span class="spinner is-active" style="float: none; margin: 0 5px;"></span> Updating brands... (Activating: ' + checkedCount + ', Deactivating: ' + uncheckedCount + ')</p>');
        
        // Visual feedback on all brand items
        $allCheckboxes.closest('.asm-brand-item').addClass('asm-bulk-processing');
        
        // Send bulk update request
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_batch_update_brand_status',
                nonce: asmData.nonce,
                updates: JSON.stringify(updates)
            },
            timeout: 30000,
            success: function(response) {
                if (response && response.success) {
                    // Update UI for ALL brands based on their checkbox state
                    $allCheckboxes.each(function() {
                        var $checkbox = $(this);
                        var $brandItem = $checkbox.closest('.asm-brand-item');
                        var $brandName = $checkbox.siblings('.asm-brand-name');
                        var isChecked = $checkbox.is(':checked');
                        
                        // Checkbox state already reflects desired active/inactive status
                        // Update visual state based on checkbox state
                        if (isChecked) {
                            // Brand is active - remove inactive class
                            $brandItem.removeClass('inactive');
                            $brandName.show();
                        } else {
                            // Brand is inactive - add inactive class
                            $brandItem.addClass('inactive');
                            $brandName.show();
                        }
                    });
                    
                    // Show success message
                    $result.removeClass('notice-info').addClass('notice-success');
                    $result.html('<p><strong>Success:</strong> ' + response.data.message + '</p>');
                    
                    // Hide message after 3 seconds
                    setTimeout(function() {
                        $result.fadeOut(300);
                    }, 3000);
                    
                    // Update sync button with active brand count
                    updateSyncButtonCount(productType);
                    
                    // Update bulk buttons state
                    updateBulkButtons(productType);
                } else {
                    $result.removeClass('notice-info').addClass('notice-error');
                    $result.html('<p><strong>Error:</strong> ' + (response.data || 'Failed to update brands') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                $result.removeClass('notice-info').addClass('notice-error');
                $result.html('<p><strong>Error:</strong> AJAX request failed: ' + error + '</p>');
            },
            complete: function() {
                // Clear bulk operation flag
                bulkOperationInProgress[productType] = false;
                
                // Re-enable buttons and checkboxes
                $buttons.prop('disabled', false);
                $('.asm-brand-checkbox[data-product-type="' + productType + '"]').prop('disabled', false);
                
                // Remove processing class
                $allCheckboxes.closest('.asm-brand-item').removeClass('asm-bulk-processing');
                
                // Update sync button with active brand count
                updateSyncButtonCount(productType);
                
                // Update bulk buttons state
                updateBulkButtons(productType);
            }
        });
    }
    
    // Function to update sync button with active brand count
    function updateSyncButtonCount(productType) {
        var $checkboxes = $('.asm-brand-checkbox[data-product-type="' + productType + '"]');
        var activeCount = 0;
        $checkboxes.each(function() {
            if ($(this).is(':checked')) {
                activeCount++;
            }
        });
        
        var buttonId = productType === 'tire' ? '#asm-sync-tires' : '#asm-sync-wheels';
        var buttonText = productType === 'tire' ? 'Sync Tires' : 'Sync Wheels';
        var $button = $(buttonId);
        var $icon = $button.find('.dashicons');
        
        // Update button text while preserving icon
        if ($icon.length) {
            $button.html($icon[0].outerHTML + ' ' + buttonText + ' (' + activeCount + ')');
        } else {
            $button.text(buttonText + ' (' + activeCount + ')');
        }
    }
    

    // Sync Tires - Fetch products for all activated tire brands
    $('#asm-sync-tires').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $result = $('#asm-sync-tires-result');
        var $statusContent = $('#asm-sync-status-content');
        
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Syncing...');
        $result.html('<p>Getting activated tire brands...</p>');
        
        // Clear and initialize status section
        $statusContent.html('<div class="asm-sync-session" data-product-type="tire">' +
            '<div class="asm-sync-header">' +
            '<img src="' + asmData.plugin_url + 'admin/img/tire.svg" alt="tire" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 8px; color: #d63638;" />' +
            '<strong>Tire Sync Started</strong>' +
            '<span class="asm-sync-time">' + new Date().toLocaleTimeString() + '</span>' +
            '</div>' +
            '<div class="asm-sync-details"></div>' +
            '</div>');
        
        // Scroll to status section
        setTimeout(function() {
            $('html, body').animate({
                scrollTop: $('#asm-sync-status-content').offset().top - 100
            }, 500);
        }, 100);
        
        // First, get activated brands
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_get_activated_brands',
                nonce: asmData.nonce,
                product_type: 'tire'
            },
            success: function(response) {
                if (response && response.success && response.data.brands.length > 0) {
                    var brands = response.data.brands;
                    var $session = $statusContent.find('.asm-sync-session[data-product-type="tire"]');
                    var $details = $session.find('.asm-sync-details');
                    
                    // Create structure: initial message at top, then brand progress container
                    $details.html('<div class="asm-status-item asm-status-info">' +
                        '<span class="dashicons dashicons-info"></span>' +
                        '<span>Found <strong>' + brands.length + '</strong> activated tire brands. Starting sync...</span>' +
                        '</div>' +
                        '<div class="asm-brands-progress-container"></div>');
                    
                    // Get reference to the brand progress container
                    var $brandsContainer = $details.find('.asm-brands-progress-container');
                    
                    // Start syncing brands one by one with start time
                    var syncStartTime = Date.now();
                    syncBrandsSequentially('tire', brands, 0, $button, $result, $brandsContainer, syncStartTime, $details);
                } else {
                    $result.html('<div class="notice notice-warning"><p>No activated tire brands found. Please activate at least one brand.</p></div>');
                    var $session = $statusContent.find('.asm-sync-session[data-product-type="tire"]');
                    if ($session.length) {
                        $session.find('.asm-sync-details').html('<div class="asm-status-item asm-status-warning">' +
                            '<span class="dashicons dashicons-warning"></span>' +
                            '<span>No activated tire brands found. Please activate at least one brand.</span>' +
                            '</div>');
                    }
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Tires');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Error fetching activated brands</p></div>');
                var $session = $statusContent.find('.asm-sync-session[data-product-type="tire"]');
                if ($session.length) {
                    $session.find('.asm-sync-details').html('<div class="asm-status-item asm-status-error">' +
                        '<span class="dashicons dashicons-dismiss"></span>' +
                        '<span>Error fetching activated brands</span>' +
                        '</div>');
                }
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Tires');
            }
        });
    });

    // Sync Wheels - Fetch products for all activated wheel brands
    $('#asm-sync-wheels').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $result = $('#asm-sync-wheels-result');
        var $statusContent = $('#asm-sync-status-content');
        
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Syncing...');
        $result.html('<p>Getting activated wheel brands...</p>');
        
        // Clear and initialize status section
        $statusContent.html('<div class="asm-sync-session" data-product-type="wheel">' +
            '<div class="asm-sync-header">' +
            '<img src="' + asmData.plugin_url + 'admin/img/wheel.svg" alt="wheel" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 8px; color: #2271b1;" />' +
            '<strong>Wheel Sync Started</strong>' +
            '<span class="asm-sync-time">' + new Date().toLocaleTimeString() + '</span>' +
            '</div>' +
            '<div class="asm-sync-details"></div>' +
            '</div>');
        
        // Scroll to status section
        setTimeout(function() {
            $('html, body').animate({
                scrollTop: $('#asm-sync-status-content').offset().top - 100
            }, 500);
        }, 100);
        
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_get_activated_brands',
                nonce: asmData.nonce,
                product_type: 'wheel'
            },
            success: function(response) {
                if (response && response.success && response.data.brands.length > 0) {
                    var brands = response.data.brands;
                    var $session = $statusContent.find('.asm-sync-session[data-product-type="wheel"]');
                    var $details = $session.find('.asm-sync-details');
                    
                    $details.html('<div class="asm-status-item asm-status-info">' +
                        '<span class="dashicons dashicons-info"></span>' +
                        '<span>Found <strong>' + brands.length + '</strong> activated wheel brands. Starting sync...</span>' +
                        '</div>' +
                        '<div class="asm-brands-progress-container"></div>');
                    
                    // Get reference to the brand progress container
                    var $brandsContainer = $details.find('.asm-brands-progress-container');
                    
                    // Start syncing brands one by one with start time
                    var syncStartTime = Date.now();
                    syncBrandsSequentially('wheel', brands, 0, $button, $result, $brandsContainer, syncStartTime, $details);
                } else {
                    $result.html('<div class="notice notice-warning"><p>No activated wheel brands found. Please activate at least one brand.</p></div>');
                    var $session = $statusContent.find('.asm-sync-session[data-product-type="wheel"]');
                    if ($session.length) {
                        $session.find('.asm-sync-details').html('<div class="asm-status-item asm-status-warning">' +
                            '<span class="dashicons dashicons-warning"></span>' +
                            '<span>No activated wheel brands found. Please activate at least one brand.</span>' +
                            '</div>');
                    }
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Wheels');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Error fetching activated brands</p></div>');
                var $session = $statusContent.find('.asm-sync-session[data-product-type="wheel"]');
                if ($session.length) {
                    $session.find('.asm-sync-details').html('<div class="asm-status-item asm-status-error">' +
                        '<span class="dashicons dashicons-dismiss"></span>' +
                        '<span>Error fetching activated brands</span>' +
                        '</div>');
                }
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Wheels');
            }
        });
    });

    // Sync All - Sync both tires and wheels sequentially
    $('#asm-sync-all').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $statusContent = $('#asm-sync-status-content');
        var $syncTiresBtn = $('#asm-sync-tires');
        var $syncWheelsBtn = $('#asm-sync-wheels');
        
        // Disable all sync buttons
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Syncing All...');
        $syncTiresBtn.prop('disabled', true);
        $syncWheelsBtn.prop('disabled', true);
        
        // Store overall start time for combined stats
        var overallSyncStartTime = Date.now();
        var tireStats = null;
        var wheelStats = null;
        
        // Create shared container for all brand progress
        var $sharedBrandsContainer = $('<div class="asm-brands-progress-container"></div>');
        
        // Create combined stats summary div (will be updated as sync progresses)
        var $combinedStatsSummary = $('<div class="asm-status-item asm-status-success asm-sync-complete" style="display: none; margin-top: 10px; padding: 15px; background: #e8f5e9; border-left: 3px solid #00a32a;">' +
            '<span class="dashicons dashicons-update asm-spinning" style="color: #00a32a; font-size: 20px; margin-right: 10px;"></span>' +
            '<div class="asm-complete-summary">' +
            '<strong style="font-size: 16px;">Sync Progress</strong><br>' +
            '<span class="asm-stats" style="margin-top: 10px; display: inline-block;">' +
            'Total Pages: <strong>0</strong> | ' +
            'Imported: <strong>0</strong> | ' +
            'Updated: <strong>0</strong> | ' +
            'Skipped: <strong>0</strong> | ' +
            'Total Time: <strong>0s</strong>' +
            '</span>' +
            '</div>' +
            '</div>');
        
        // Clear and initialize status section with shared container
        $statusContent.html('<div class="asm-status-item asm-status-info">' +
            '<span class="dashicons dashicons-info"></span>' +
            '<span>Checking brands...</span>' +
            '</div>');
        $statusContent.append($combinedStatsSummary);
        $statusContent.append($sharedBrandsContainer);
        
        // Ensure stats summary is hidden by default
        $combinedStatsSummary.hide();
        
        // First, check if brands exist, fetch if they don't
        function checkAndFetchBrands() {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: asmData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'asm_check_and_fetch_brands',
                        nonce: asmData.nonce
                    },
                    timeout: 300000, // 5 minutes
                    success: function(response) {
                        if (response && response.success) {
                            if (response.data.brands_fetched > 0) {
                                $statusContent.find('.asm-status-item.asm-status-info').first().html(
                                    '<span class="dashicons dashicons-info"></span>' +
                                    '<span>Fetched ' + response.data.brands_fetched + ' brands from API. Starting sync...</span>'
                                );
                            } else {
                                $statusContent.find('.asm-status-item.asm-status-info').first().html(
                                    '<span class="dashicons dashicons-info"></span>' +
                                    '<span>Brands ready. Starting sync for all active brands...</span>'
                                );
                            }
                            resolve();
                        } else {
                            // Continue even if fetch fails (brands might already exist)
                            $statusContent.find('.asm-status-item.asm-status-info').first().html(
                                '<span class="dashicons dashicons-warning"></span>' +
                                '<span>Could not fetch brands. Continuing with existing brands...</span>'
                            );
                            resolve();
                        }
                    },
                    error: function() {
                        // Continue even on error
                        $statusContent.find('.asm-status-item.asm-status-info').first().html(
                            '<span class="dashicons dashicons-info"></span>' +
                            '<span>Starting sync for all active brands...</span>'
                        );
                        resolve();
                    }
                });
            });
        }
        
        // Function to update combined stats summary
        function updateCombinedStats() {
            var totalPages = 0, totalImported = 0, totalUpdated = 0, totalSkipped = 0, totalImagesDownloaded = 0;
            
            // Calculate totals from all brand summaries
            $sharedBrandsContainer.find('.asm-brand-summary').each(function() {
                var $summary = $(this);
                totalPages += parseInt($summary.data('pages') || 0);
                totalImported += parseInt($summary.data('imported') || 0);
                totalUpdated += parseInt($summary.data('updated') || 0);
                totalSkipped += parseInt($summary.data('skipped') || 0);
                totalImagesDownloaded += parseInt($summary.data('images-downloaded') || 0);
            });
            
            var totalElapsedTime = ((Date.now() - overallSyncStartTime) / 1000).toFixed(1);
            var totalTimeDisplay = totalElapsedTime < 60 ? totalElapsedTime + 's' : (totalElapsedTime / 60).toFixed(1) + 'm';
            
            // Check if sync is complete (both tire and wheel stats are available)
            var isComplete = tireStats !== null && wheelStats !== null;
            
            if (isComplete) {
                // Use final stats from callbacks
                totalPages = (tireStats ? tireStats.totalPages : 0) + (wheelStats ? wheelStats.totalPages : 0);
                totalImported = (tireStats ? tireStats.totalImported : 0) + (wheelStats ? wheelStats.totalImported : 0);
                totalUpdated = (tireStats ? tireStats.totalUpdated : 0) + (wheelStats ? wheelStats.totalUpdated : 0);
                totalSkipped = (tireStats ? tireStats.totalSkipped : 0) + (wheelStats ? wheelStats.totalSkipped : 0);
                
                $combinedStatsSummary.html(
                    '<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 20px; margin-right: 10px;"></span>' +
                    '<div class="asm-complete-summary">' +
                    '<strong style="font-size: 16px;">✓ All Sync Complete!</strong><br>' +
                    '<span class="asm-stats" style="margin-top: 10px; display: inline-block;">' +
                    'Total Pages: <strong>' + totalPages + '</strong> | ' +
                    'Imported: <strong>' + totalImported + '</strong> | ' +
                    'Updated: <strong>' + totalUpdated + '</strong> | ' +
                    'Skipped: <strong>' + totalSkipped + '</strong> | ' +
                    'Total Time: <strong>' + totalTimeDisplay + '</strong>' +
                    '</span>' +
                    '</div>'
                );
            } else {
                // Update with current progress
                $combinedStatsSummary.find('.asm-stats').html(
                    'Total Pages: <strong>' + totalPages + '</strong> | ' +
                    'Imported: <strong>' + totalImported + '</strong> | ' +
                    'Updated: <strong>' + totalUpdated + '</strong> | ' +
                    'Skipped: <strong>' + totalSkipped + '</strong> | ' +
                    'Total Time: <strong>' + totalTimeDisplay + '</strong>'
                );
            }
        }
        
        // Update stats periodically during sync
        var statsUpdateInterval = setInterval(function() {
            updateCombinedStats();
        }, 1000);
        
        // Scroll to status section
        setTimeout(function() {
            $('html, body').animate({
                scrollTop: $('#asm-sync-status-content').offset().top - 100
            }, 500);
        }, 100);
        
        // Function to sync tires
        function syncTires() {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: asmData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'asm_get_activated_brands',
                        nonce: asmData.nonce,
                        product_type: 'tire'
                    },
                    success: function(response) {
                        if (response && response.success && response.data.brands.length > 0) {
                            var brands = response.data.brands;
                            var syncStartTime = Date.now();
                            
                            // Update initial info message
                            $statusContent.find('.asm-status-item.asm-status-info').first().html(
                                '<span class="dashicons dashicons-info"></span>' +
                                '<span>Found <strong>' + brands.length + '</strong> activated tire brands. Starting sync...</span>'
                            );
                            
                            // Use shared container and status details
                            syncBrandsSequentially('tire', brands, 0, null, null, $sharedBrandsContainer, syncStartTime, $statusContent, function(stats) {
                                tireStats = stats;
                                resolve();
                            });
                        } else {
                            // Don't show stats if no brands found
                            resolve();
                        }
                    },
                    error: function() {
                        resolve(); // Continue even on error
                    }
                });
            });
        }
        
        // Function to sync wheels
        function syncWheels() {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: asmData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'asm_get_activated_brands',
                        nonce: asmData.nonce,
                        product_type: 'wheel'
                    },
                    success: function(response) {
                        if (response && response.success && response.data.brands.length > 0) {
                            var brands = response.data.brands;
                            var syncStartTime = Date.now();
                            
                            // Update initial info message for wheels
                            var $infoMsg = $statusContent.find('.asm-status-item.asm-status-info').first();
                            if ($infoMsg.length) {
                                $infoMsg.html(
                                    '<span class="dashicons dashicons-info"></span>' +
                                    '<span>Found <strong>' + brands.length + '</strong> activated wheel brands. Starting sync...</span>'
                                );
                            }
                            
                            // Use shared container and status details
                            syncBrandsSequentially('wheel', brands, 0, null, null, $sharedBrandsContainer, syncStartTime, $statusContent, function(stats) {
                                wheelStats = stats;
                                resolve();
                            });
                        } else {
                            resolve();
                        }
                    },
                    error: function() {
                        resolve(); // Continue even on error
                    }
                });
            });
        }
        
        // Start syncing: check brands first, then sync tires, then wheels
        checkAndFetchBrands().then(function() {
            return syncTires();
        }).then(function() {
            return syncWheels();
        }).then(function() {
            // Clear update interval
            clearInterval(statsUpdateInterval);
            
            // Final update of stats (will show completion message)
            updateCombinedStats();
            
            // Show stats summary only when all syncs are complete
            $combinedStatsSummary.show();
            
            // Collect brand-wise stats and save sync status
            saveSyncStatus($sharedBrandsContainer, overallSyncStartTime, tireStats, wheelStats);
            
            // Re-enable buttons
            $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync All');
            $syncTiresBtn.prop('disabled', false);
            $syncWheelsBtn.prop('disabled', false);
        });
    });

    // Sync brands sequentially - processes one brand at a time
    function syncBrandsSequentially(productType, brands, brandIndex, $button, $result, $brandsContainer, overallStartTime, $statusDetails, onCompleteCallback) {
        if (brandIndex >= brands.length) {
            // All brands processed
            var totalImported = 0, totalUpdated = 0, totalSkipped = 0, totalPages = 0, totalImagesDownloaded = 0;
            $brandsContainer.find('.asm-brand-summary').each(function() {
                var $summary = $(this);
                totalImported += parseInt($summary.data('imported') || 0);
                totalUpdated += parseInt($summary.data('updated') || 0);
                totalSkipped += parseInt($summary.data('skipped') || 0);
                totalImagesDownloaded += parseInt($summary.data('images-downloaded') || 0);
                totalPages += parseInt($summary.data('pages') || 0);
            });
            
            // Calculate total time
            var totalElapsedTime = ((Date.now() - overallStartTime) / 1000).toFixed(1);
            var totalTimeDisplay = totalElapsedTime < 60 ? totalElapsedTime + 's' : (totalElapsedTime / 60).toFixed(1) + 'm';
            
            // Only show completion message if NOT called from Sync All (no callback provided)
            if (!onCompleteCallback) {
                // Insert completion message right after the initial info message div
                $statusDetails.find('.asm-status-item.asm-status-info').first().after('<div class="asm-status-item asm-status-success asm-sync-complete">' +
                    '<span class="dashicons dashicons-yes-alt"></span>' +
                    '<div class="asm-complete-summary">' +
                    '<strong>✓ All brands synced successfully!</strong><br>' +
                    '<span class="asm-stats">Total Pages: <strong>' + totalPages + '</strong> | ' +
                    'Imported: <strong>' + totalImported + '</strong> | ' +
                    'Updated: <strong>' + totalUpdated + '</strong> | ' +
                    'Skipped: <strong>' + totalSkipped + '</strong>' +
                    (totalImagesDownloaded > 0 ? ' | Images Downloaded: <strong>' + totalImagesDownloaded + '</strong>' : '') +
                    ' | Total Time: <strong>' + totalTimeDisplay + '</strong></span>' +
                    '</div>' +
                    '</div>');
            }
            
            if ($result) {
                $result.append('<div class="notice notice-success"><p><strong>✓ All brands synced successfully!</strong></p></div>');
            }
            if ($button) {
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync ' + (productType === 'tire' ? 'Tires' : 'Wheels'));
            }
            
            // Call completion callback if provided (pass stats for aggregation)
            if (onCompleteCallback && typeof onCompleteCallback === 'function') {
                onCompleteCallback({
                    totalPages: totalPages,
                    totalImported: totalImported,
                    totalUpdated: totalUpdated,
                    totalSkipped: totalSkipped,
                    totalImagesDownloaded: totalImagesDownloaded,
                    totalTime: totalElapsedTime
                });
            } else {
                // Individual sync (tires only or wheels only) - save sync status and send email
                var stats = {
                    totalPages: totalPages,
                    totalImported: totalImported,
                    totalUpdated: totalUpdated,
                    totalSkipped: totalSkipped,
                    totalImagesDownloaded: totalImagesDownloaded,
                    totalTime: totalElapsedTime
                };
                
                // Create appropriate stats object based on product type
                var tireStats = null;
                var wheelStats = null;
                
                if (productType === 'tire') {
                    tireStats = stats;
                } else if (productType === 'wheel') {
                    wheelStats = stats;
                }
                
                // Save sync status (this will trigger email notification)
                saveSyncStatus($brandsContainer, overallStartTime, tireStats, wheelStats);
            }
            return;
        }
        
        var brand = brands[brandIndex];
        var currentBrandNum = brandIndex + 1;
        var totalBrands = brands.length;
        
        // Add brand progress to status section (prepend to show at top of brand progress container)
        var $brandProgress = $('<div class="asm-brand-progress" data-brand-id="' + brand.brand_id + '" data-product-type="' + productType + '">' +
            '<div class="asm-brand-progress-header">' +
            '<span class="dashicons dashicons-update asm-spinning"></span>' +
            '<strong>[' + currentBrandNum + '/' + totalBrands + '] ' + brand.brand_name + '</strong>' +
            '</div>' +
            '<div class="asm-brand-progress-details"></div>' +
            '</div>');
        $brandsContainer.prepend($brandProgress); // Prepend to show current sync at top
        
        if ($result) {
            $result.append('<p><strong>[' + currentBrandNum + '/' + totalBrands + '] Syncing brand: ' + brand.brand_name + '...</strong></p>');
        }
        
        // Use existing bulk import logic but with brand ID filter
        var syncState = {
            currentPage: 1,
            totalImported: 0,
            totalUpdated: 0,
            totalSkipped: 0,
            totalImagesDownloaded: 0, // Track image downloads separately
            brandName: brand.brand_name,
            brandId: brand.brand_id,
            imageUrls: null,
            hasMore: true,
            $brandProgress: $brandProgress,
            totalPages: 0,
            startTime: Date.now()
        };
        
        syncBrandProducts(productType, brand.brand_id, syncState, function() {
            // Brand completed, move to next
            var $progressDetails = syncState.$brandProgress.find('.asm-brand-progress-details');
            
            // Calculate elapsed time for this brand
            var elapsedTime = ((Date.now() - syncState.startTime) / 1000).toFixed(1);
            var timeDisplay = elapsedTime < 60 ? elapsedTime + 's' : (elapsedTime / 60).toFixed(1) + 'm';
            
            $progressDetails.html('<div class="asm-brand-summary" data-imported="' + syncState.totalImported + '" ' +
                'data-updated="' + syncState.totalUpdated + '" data-skipped="' + syncState.totalSkipped + '" ' +
                'data-images-downloaded="' + syncState.totalImagesDownloaded + '" ' +
                'data-pages="' + syncState.totalPages + '">' +
                '<span class="dashicons dashicons-yes" style="color: #00a32a;"></span>' +
                '<span>Pages: <strong>' + syncState.totalPages + '</strong> | ' +
                'Imported: <strong>' + syncState.totalImported + '</strong> | ' +
                'Updated: <strong>' + syncState.totalUpdated + '</strong> | ' +
                'Skipped: <strong>' + syncState.totalSkipped + '</strong>' +
                (syncState.totalImagesDownloaded > 0 ? ' | Images Downloaded: <strong>' + syncState.totalImagesDownloaded + '</strong>' : '') +
                ' | Time: <strong>' + timeDisplay + '</strong></span>' +
                '</div>');
            syncState.$brandProgress.find('.asm-brand-progress-header .dashicons').removeClass('asm-spinning').addClass('dashicons-yes-alt').css('color', '#00a32a');
            
            if ($result) {
                var resultText = '✓ Completed ' + brand.brand_name + ' - Pages: ' + syncState.totalPages + ', Imported: ' + syncState.totalImported + ', Updated: ' + syncState.totalUpdated + ', Skipped: ' + syncState.totalSkipped;
                if (syncState.totalImagesDownloaded > 0) {
                    resultText += ', Images Downloaded: ' + syncState.totalImagesDownloaded;
                }
                resultText += ', Time: ' + timeDisplay;
                $result.append('<p>' + resultText + '</p>');
            }
            
            // Process next brand after short delay
            setTimeout(function() {
                syncBrandsSequentially(productType, brands, brandIndex + 1, $button, $result, $brandsContainer, overallStartTime, $statusDetails, onCompleteCallback);
            }, 500);
        }, $result, $brandProgress);
    }

    // Sync products for a single brand
    function syncBrandProducts(productType, brandId, syncState, onComplete, $result, $brandProgress) {
        var action = productType === 'tire' ? 'asm_bulk_import_tires' : 'asm_bulk_import';
        var pageSize = 500;
        
        // Update progress in status section
        if ($brandProgress && syncState.currentPage === 1) {
            var $progressDetails = $brandProgress.find('.asm-brand-progress-details');
            $progressDetails.html('<div class="asm-status-item asm-status-info">' +
                '<span class="dashicons dashicons-download"></span>' +
                '<span>Fetching products (Page ' + syncState.currentPage + ')...</span>' +
                '</div>');
        }
        
        // Fetch products for this brand using brand ID
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: action,
                nonce: asmData.nonce,
                page_number: syncState.currentPage,
                page_size: pageSize,
                brand_id: brandId  // Send brand ID instead of brand name
            },
            timeout: 30000,
            success: function(response) {
                if (response.success && response.data && response.data.products) {
                    // Store image URLs
                    if (!syncState.imageUrls && response.data.image_urls) {
                        syncState.imageUrls = response.data.image_urls;
                    }
                    
                    var products = response.data.products;
                    syncState.hasMore = response.data.has_more === true;
                    
                    // Update total pages (track the highest page number reached)
                    syncState.totalPages = syncState.currentPage;
                    
                    if (products.length > 0) {
                        // Update progress
                        if ($brandProgress) {
                            var $progressDetails = $brandProgress.find('.asm-brand-progress-details');
                            $progressDetails.html('<div class="asm-status-item asm-status-info">' +
                                '<span class="dashicons dashicons-database"></span>' +
                                '<span>Processing ' + products.length + ' products (Page ' + syncState.currentPage + ')...</span>' +
                                '</div>');
                        }
                        
                        // Insert batch
                        insertBrandBatch(productType, products, syncState, function() {
                            if ($brandProgress) {
                                var $progressDetails = $brandProgress.find('.asm-brand-progress-details');
                                $progressDetails.html('<div class="asm-status-item asm-status-info">' +
                                    '<span class="dashicons dashicons-yes"></span>' +
                                    '<span>Page ' + syncState.currentPage + ' completed. Imported: ' + syncState.totalImported + ', Updated: ' + syncState.totalUpdated + '</span>' +
                                    '</div>');
                            }
                            
                            if (syncState.hasMore) {
                                syncState.currentPage++;
                                syncBrandProducts(productType, brandId, syncState, onComplete, $result, $brandProgress);
                            } else {
                                onComplete();
                            }
                        }, $result, $brandProgress);
                    } else if (!syncState.hasMore) {
                        onComplete();
                    } else {
                        syncState.currentPage++;
                        syncBrandProducts(productType, brandId, syncState, onComplete, $result, $brandProgress);
                    }
                } else {
                    // No products or error - move to next brand
                    if ($brandProgress) {
                        var $progressDetails = $brandProgress.find('.asm-brand-progress-details');
                        $progressDetails.html('<div class="asm-status-item asm-status-warning">' +
                            '<span class="dashicons dashicons-info"></span>' +
                            '<span>No products found for this brand.</span>' +
                            '</div>');
                    }
                    onComplete();
                }
            },
            error: function() {
                // Error fetching - move to next brand
                if ($brandProgress) {
                    var $progressDetails = $brandProgress.find('.asm-brand-progress-details');
                    $progressDetails.html('<div class="asm-status-item asm-status-error">' +
                        '<span class="dashicons dashicons-dismiss"></span>' +
                        '<span>Error fetching page ' + syncState.currentPage + '</span>' +
                        '</div>');
                }
                if ($result) {
                    $result.append('<p style="color: red;">⚠ Error fetching brand ID ' + brandId + ' page ' + syncState.currentPage + '</p>');
                }
                onComplete();
            }
        });
    }

    // Insert batch for a brand
    function insertBrandBatch(productType, products, syncState, onComplete, $result, $brandProgress) {
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_batch_insert_optimized',
                nonce: asmData.nonce,
                products: JSON.stringify(products),
                image_urls: syncState.imageUrls,
                type: productType
            },
            timeout: 300000,
            success: function(response) {
                if (response.success && response.data) {
                    syncState.totalImported += parseInt(response.data.imported || 0, 10);
                    syncState.totalUpdated += parseInt(response.data.updated || 0, 10);
                    syncState.totalSkipped += parseInt(response.data.skipped || 0, 10);
                    syncState.totalImagesDownloaded += parseInt(response.data.images_downloaded || 0, 10);
                }
                onComplete();
            },
            error: function() {
                if ($brandProgress) {
                    var $progressDetails = $brandProgress.find('.asm-brand-progress-details');
                    $progressDetails.html('<div class="asm-status-item asm-status-error">' +
                        '<span class="dashicons dashicons-dismiss"></span>' +
                        '<span>Error inserting batch</span>' +
                        '</div>');
                }
                if ($result) {
                    $result.append('<p style="color: red;">⚠ Error inserting batch for ' + syncState.brandName + '</p>');
                }
                onComplete();
            }
        });
    }

    // Sync Inventory and Quantity with batch processing and progress tracking
    $('#asm-sync-inventory-quantity').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('This will sync price and stock for all products where the price_stock_hash has changed.\n\nThis may take a while depending on the number of products.\n\nContinue?')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#asm-price-stock-sync-result');
        var isProcessing = false;
        
        $button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Initializing...');
        $result.removeClass('error success').addClass('loading').show();
        $result.html('<p>Initializing price/stock sync...</p>');
        
        // Function to process a batch
        function processPriceStockBatch() {
            if (isProcessing) {
                return;
            }
            
            isProcessing = true;
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asm_sync_inventory_quantity_batch',
                    nonce: nonce
                },
                timeout: 300000, // 5 minutes per batch
                success: function(response) {
                    isProcessing = false;
                    
                    if (response && response.success) {
                        var data = response.data;
                        var processed = data.processed || 0;
                        var total = data.total || 0;
                        var updated = data.updated || 0;
                        var skipped = data.skipped || 0;
                        var errors = data.errors || 0;
                        var completed = data.completed === true;
                        var currentBrandName = data.current_brand_name || '';
                        var currentBrandNum = data.current_brand_num || 0;
                        var totalBrands = data.total_brands || 0;
                        var completedBrands = data.completed_brands || 0;
                        
                        // Build progress display
                        var progressHtml = '<p><strong>Syncing inventory and quantity...</strong><br>';
                        
                        // Show brand progress if available
                        if (currentBrandName && totalBrands > 0) {
                            progressHtml += '<span style="font-size: 16px; color: #0073aa; font-weight: bold;">' +
                                          '[' + currentBrandNum + '/' + totalBrands + '] ' + currentBrandName + '</span><br>';
                        } else if (totalBrands > 0) {
                            progressHtml += '<span style="font-size: 14px; color: #666;">Brands: ' + 
                                          (completedBrands || 0) + ' / ' + totalBrands + ' completed</span><br>';
                        }
                        
                        // Show product counts
                        if (total > 0) {
                            progressHtml += '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                          processed + ' / ' + total + ' products processed</span><br>';
                        } else {
                            progressHtml += '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                          processed + ' products processed</span><br>';
                        }
                        
                        progressHtml += 'Updated: <strong>' + updated + '</strong> | ' +
                                      'Skipped: <strong>' + skipped + '</strong> | ' +
                                      'Errors: <strong>' + errors + '</strong></p>';
                        
                        $result.html(progressHtml);
                        
                        // Update button text
                        if (totalBrands > 0) {
                            $button.text('Syncing... (' + (completedBrands || 0) + '/' + totalBrands + ' brands, ' + processed + ' products)');
                        } else {
                            $button.text('Syncing... (' + processed + ' products)');
                        }
                        
                        if (completed) {
                            // All done
                            $result.removeClass('loading error').addClass('success').show();
                            var message = '<strong>✓ Price/Stock Sync completed successfully!</strong><br>';
                            message += 'Total Processed: ' + total + '<br>';
                            message += 'Updated: ' + updated + ' | Skipped: ' + skipped + ' | Errors: ' + errors;
                            $result.html(message);
                            $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity');
                            
                            // Reload page to show updated status
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            // Continue processing
                            setTimeout(processPriceStockBatch, 100);
                        }
                    } else {
                        $result.removeClass('loading success').addClass('error').show();
                        var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to process batch';
                        $result.html('<strong>✗ Sync Failed</strong><br>' + errorMsg);
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity');
                    }
                },
                error: function(xhr, status, error) {
                    isProcessing = false;
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = 'AJAX Error: ' + error;
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. The operation may still be processing.';
                    }
                    $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity');
                }
            });
        }
        
        // Initialize batch processing
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_sync_inventory_quantity',
                nonce: nonce
            },
            timeout: 60000, // 1 minute for initialization
            success: function(response) {
                if (response && response.success) {
                    var data = response.data;
                    
                    // Check if no active brands
                    if (data.completed === true && (data.total === 0 || data.total_products === 0)) {
                        $result.removeClass('loading error').addClass('success').show();
                        $result.html('<strong>✓ ' + (data.message || 'No active brands found for price/stock sync.') + '</strong>');
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity');
                        return;
                    }
                    
                    // Get brand counts from initialization
                    var totalBrands = data.total_brands || 0;
                    var totalTireBrands = data.total_tire_brands || 0;
                    var totalWheelBrands = data.total_wheel_brands || 0;
                    
                    // Start processing batches with initial progress display
                    var brandInfo = '';
                    if (totalBrands > 0) {
                        brandInfo = '<br><span style="font-size: 14px; color: #666;">Total Brands: <strong>' + totalBrands + '</strong>';
                        if (totalTireBrands > 0) brandInfo += ' (Tires: ' + totalTireBrands;
                        if (totalWheelBrands > 0) {
                            if (totalTireBrands > 0) brandInfo += ', ';
                            else brandInfo += ' (';
                            brandInfo += 'Wheels: ' + totalWheelBrands;
                        }
                        if (totalTireBrands > 0 || totalWheelBrands > 0) brandInfo += ')';
                        brandInfo += '</span>';
                    }
                    
                    $result.html('<p><strong>Syncing inventory and quantity...</strong><br>' + 
                                '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                'Initializing... 0 products processed</span>' + brandInfo + '</p>');
                    $button.text('Syncing... (0/' + totalBrands + ' brands)');
                    
                    // Process first batch immediately
                    processPriceStockBatch();
                } else {
                    // Handle error response (e.g., hash table empty)
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to initialize price/stock sync';
                    $result.html('<strong>✗ Initialization Failed</strong><br>' + errorMsg);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity');
                }
            },
            error: function(xhr, status, error) {
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                }
                $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Sync Inventory & Quantity');
            }
        });
    });
});
</script>

<style>
.asm-container {
    margin-top: 20px;
}
.asm-status-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}
.asm-card-half {
    flex: 1;
}
.asm-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    margin-bottom: 20px;
}
.asm-sync-status-card {
    display: flex;
    flex-direction: column;
    height: 400px;
}
.asm-sync-status-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 10px;
    margin-top: 10px;
}
.asm-status-placeholder {
    text-align: center;
    padding: 40px 20px;
    color: #646970;
}
.asm-status-message {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}
.asm-sync-session {
    margin-bottom: 15px;
    border-left: 3px solid #2271b1;
    padding-left: 15px;
}
.asm-sync-header {
    display: flex;
    align-items: center;
    font-size: 16px;
    font-weight: 600;
    color: #1d2327;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e5e5e5;
}
.asm-sync-time {
    margin-left: auto;
    font-size: 12px;
    font-weight: normal;
    color: #646970;
}
.asm-sync-details {
    padding-left: 5px;
}
.asm-status-item {
    display: flex;
    align-items: flex-start;
    padding: 8px 10px;
    margin-bottom: 8px;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1.6;
    animation: fadeIn 0.3s ease-in;
}
.asm-status-item .dashicons {
    margin-right: 8px;
    margin-top: 2px;
    flex-shrink: 0;
}
.asm-status-info {
    background: #e7f5fe;
    color: #0969da;
    border-left: 3px solid #0969da;
}
.asm-status-success {
    background: #e8f5e9;
    color: #1e7e34;
    border-left: 3px solid #00a32a;
}
.asm-status-warning {
    background: #fff3cd;
    color: #856404;
    border-left: 3px solid #ffc107;
}
.asm-status-error {
    background: #fce8e6;
    color: #b32d2e;
    border-left: 3px solid #d63638;
}
.asm-brand-progress {
    background: #f8f9fa;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 12px;
    margin-bottom: 10px;
}
.asm-brand-progress-header {
    display: flex;
    align-items: center;
    font-weight: 600;
    color: #1d2327;
    margin-bottom: 8px;
}
.asm-brand-progress-header .dashicons {
    margin-right: 8px;
}
.asm-brand-progress-details {
    padding-left: 26px;
}
.asm-brand-summary {
    display: flex;
    align-items: center;
    font-size: 12px;
    color: #646970;
}
.asm-brand-summary .dashicons {
    margin-right: 6px;
}
.asm-sync-complete {
    margin-top: 15px;
    padding: 15px;
    font-size: 14px;
}
.asm-complete-summary {
    flex: 1;
}
.asm-complete-summary strong {
    display: block;
    margin-bottom: 8px;
    font-size: 15px;
}
.asm-stats {
    font-size: 13px;
    color: #646970;
}
.asm-stats strong {
    color: #1d2327;
}
@keyframes asm-spinning {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}
.asm-spinning {
    animation: asm-spinning 1s linear infinite;
}
@media (max-width: 1200px) {
    .asm-status-row {
        flex-direction: column;
    }
    .asm-sync-status-card {
        height: 350px;
    }
}
.asm-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    margin-bottom: 20px;
}
.asm-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}
.asm-section-header h2 {
    margin: 0;
    border-bottom: none;
    padding-bottom: 0;
}
.asm-sync-status-card .asm-section-header {
    margin-bottom: 15px;
}
.asm-brands-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-top: 15px;
    max-height: 500px;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 10px;
    padding-bottom: 10px;
}
@media (max-width: 1200px) {
    .asm-brands-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .asm-brands-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .asm-brands-grid {
        grid-template-columns: 1fr;
    }
}
.asm-brand-item {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 3px;
    padding: 12px 15px;
    transition: opacity 0.2s ease;
    position: relative;
    cursor: pointer;
}
.asm-brand-item:hover {
    border-color: #8c8f94;
}
.asm-brand-item.inactive {
    opacity: 0.5;
    background: #f6f7f7;
}
.asm-brand-label {
    display: flex;
    align-items: center;
    cursor: pointer;
    margin: -12px -15px;
    padding: 12px 15px;
    font-size: 14px;
    position: relative;
}
.asm-brand-checkbox {
    margin-right: 10px;
    cursor: pointer;
    width: 18px;
    height: 18px;
}
.asm-brand-name {
    flex: 1;
    font-weight: 500;
    color: #23282d;
}
.asm-brand-loading {
    display: flex;
    align-items: center;
    font-size: 13px;
    animation: fadeIn 0.3s ease-in;
}
.asm-loading-delete {
    color: #d63638;
}
.asm-loading-activate {
    color: #2271b1;
}
.asm-brand-loading .spinner {
    margin: 0 5px 0 0;
    float: none;
}
.asm-loading-text {
    font-style: italic;
}
.asm-brand-success {
    display: flex;
    align-items: center;
    color: #00a32a;
    font-size: 13px;
    font-weight: 600;
    animation: fadeIn 0.3s ease-in, slideUp 0.4s ease-out;
}
.asm-success-text {
    font-style: normal;
}
.asm-brand-item.inactive {
    opacity: 0.6;
    background: #f5f5f5;
}

/* Animations */
@keyframes pulseActivate {
    0% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.4);
    }
    50% {
        transform: scale(1.02);
        box-shadow: 0 0 0 8px rgba(76, 175, 80, 0);
    }
    100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideUp {
    from {
        transform: translateY(5px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes checkmark {
    0% {
        transform: scale(0);
    }
    50% {
        transform: scale(1.2);
    }
    100% {
        transform: scale(1);
    }
}

.asm-brand-success .dashicons-yes-alt {
    animation: checkmark 0.5s ease-out;
}

/* Custom scrollbar styling */
.asm-brands-grid::-webkit-scrollbar {
    width: 8px;
}
.asm-brands-grid::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
.asm-brands-grid::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}
.asm-brands-grid::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Sync Status Scrollbar */
.asm-sync-status-content::-webkit-scrollbar {
    width: 8px;
}
.asm-sync-status-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
.asm-sync-status-content::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}
.asm-sync-status-content::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Bulk Actions Toolbar Styles - Simple */
.asm-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.asm-bulk-actions-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    margin-bottom: 20px;
}

.asm-bulk-actions-left {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.asm-bulk-actions-right {
    display: flex;
    align-items: center;
}

.asm-bulk-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 3px;
    color: #2c3338;
    font-size: 13px;
    cursor: pointer;
    transition: border-color 0.2s ease;
}

.asm-bulk-btn:hover:not(.disabled) {
    border-color: #8c8f94;
}

.asm-bulk-btn:active:not(.disabled) {
    background: #f0f0f1;
}

.asm-bulk-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f6f7f7;
}

.asm-bulk-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
    margin-top: 2px;
}

.asm-bulk-result {
    margin-top: 15px;
    margin-bottom: 15px;
    padding: 12px 15px;
    border-radius: 6px;
    animation: fadeIn 0.3s ease-in;
}

.asm-bulk-result.notice-success {
    background: #e8f5e9;
    border-left: 4px solid #00a32a;
    color: #1e7e34;
}

.asm-bulk-result.notice-error {
    background: #fce8e6;
    border-left: 4px solid #d63638;
    color: #b32d2e;
}

.asm-bulk-result.notice-info {
    background: #e7f5fe;
    border-left: 4px solid #2271b1;
    color: #0969da;
}

.asm-brand-item.asm-bulk-processing {
    opacity: 0.7;
    border-color: #8c8f94;
}

@media (max-width: 768px) {
    .asm-bulk-actions-toolbar {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .asm-bulk-actions-left {
        flex-direction: column;
    }
    
    .asm-bulk-btn {
        width: 100%;
        justify-content: center;
    }
    
    .asm-bulk-actions-right {
        justify-content: center;
    }
}

/* Price/Stock Sync Result Styles */
#asm-price-stock-sync-result {
    padding: 15px;
    border-radius: 4px;
    margin-top: 10px;
    display: none;
}

#asm-price-stock-sync-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#asm-price-stock-sync-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#asm-price-stock-sync-result.loading {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}
</style>


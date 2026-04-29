<?php
/**
 * Dashboard Page View
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

// Calculate percentages
$tire_brand_percentage = $total_tire_brands > 0 ? round(($active_tire_brands / $total_tire_brands) * 100, 1) : 0;
$wheel_brand_percentage = $total_wheel_brands > 0 ? round(($active_wheel_brands / $total_wheel_brands) * 100, 1) : 0;

// Get hash table count
global $wpdb;
$hash_table_name = ASM_Hash_Manager::get_table_name();
$hash_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hash_table_name}'") === $hash_table_name;
$total_hash_tables = $hash_table_exists ? ASM_Hash_Manager::get_total_count() : 0;

// Get total product counts for tire and wheel brands
$tire_category = get_term_by('slug', 'tires', 'product_cat');
$wheel_category = get_term_by('slug', 'wheels', 'product_cat');

$total_tire_products = 0;
$total_wheel_products = 0;

if ($tire_category) {
    // Get all child term IDs including the parent
    $tire_term_ids = array($tire_category->term_id);
    $tire_children = get_term_children($tire_category->term_id, 'product_cat');
    if (!is_wp_error($tire_children) && !empty($tire_children)) {
        $tire_term_ids = array_merge($tire_term_ids, $tire_children);
    }
    $tire_term_ids = array_map('intval', $tire_term_ids);
    $tire_term_ids_safe = array_map('absint', $tire_term_ids);
    $tire_term_ids_string = implode(',', $tire_term_ids_safe);
    
    // Build query with sanitized IDs
    $query = "SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tt.term_id IN ({$tire_term_ids_string})
        AND p.post_type = 'product'
        AND p.post_status != 'trash'";
    
    $total_tire_products = intval($wpdb->get_var($query));
}

if ($wheel_category) {
    // Get all child term IDs including the parent
    $wheel_term_ids = array($wheel_category->term_id);
    $wheel_children = get_term_children($wheel_category->term_id, 'product_cat');
    if (!is_wp_error($wheel_children) && !empty($wheel_children)) {
        $wheel_term_ids = array_merge($wheel_term_ids, $wheel_children);
    }
    $wheel_term_ids = array_map('intval', $wheel_term_ids);
    $wheel_term_ids_safe = array_map('absint', $wheel_term_ids);
    $wheel_term_ids_string = implode(',', $wheel_term_ids_safe);
    
    // Build query with sanitized IDs
    $query = "SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tt.term_id IN ({$wheel_term_ids_string})
        AND p.post_type = 'product'
        AND p.post_status != 'trash'";
    
    $total_wheel_products = intval($wpdb->get_var($query));
}

?>

<div class="asm-dashboard-header">
    <div>
        <h1 class="asm-dashboard-title">Dashboard</h1>
        <p class="asm-dashboard-subtitle">Monitor and manage product brands and synchronization</p>
    </div>
        <div class="asm-dashboard-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=autosync-hash-management')); ?>" class="button button-secondary">
                <span class="dashicons dashicons-database"></span> Hash Management
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=autosync-brand-management')); ?>" class="button button-primary">
                <span class="dashicons dashicons-admin-generic"></span> Brand Management
            </a>
        </div>
    </div>

    <div class="asm-container asm-dashboard-container">
        <!-- KPI Cards -->
        <div class="asm-kpi-row">
            <div class="asm-kpi-card">
                <div class="asm-kpi-header">
                    <div class="asm-kpi-icon">
                        <img src="<?php echo esc_url(ASM_PLUGIN_URL . 'admin/img/tire.svg'); ?>" alt="Tire Icon" />
                    </div>
                    <div class="asm-kpi-label">Total Tire Brands</div>
                </div>
                <div class="asm-kpi-content">
                    <div class="asm-kpi-main">
                        <div class="asm-kpi-sublabel">Brands:</div>
                        <div class="asm-kpi-value"><?php echo esc_html(number_format($total_tire_brands)); ?></div>
                    </div>
                    <div class="asm-kpi-secondary">
                        <div class="asm-kpi-sublabel-small">Products:</div>
                        <div class="asm-kpi-value-small"><?php echo esc_html(number_format($total_tire_products)); ?></div>
                    </div>
                </div>
            </div>

            <div class="asm-kpi-card">
                <div class="asm-kpi-header">
                    <div class="asm-kpi-icon">
                        <img src="<?php echo esc_url(ASM_PLUGIN_URL . 'admin/img/wheel.svg'); ?>" alt="Wheel Icon" />
                    </div>
                    <div class="asm-kpi-label">Total Wheel Brands</div>
                </div>
                <div class="asm-kpi-content">
                    <div class="asm-kpi-main">
                        <div class="asm-kpi-sublabel">Brands:</div>
                        <div class="asm-kpi-value"><?php echo esc_html(number_format($total_wheel_brands)); ?></div>
                    </div>
                    <div class="asm-kpi-secondary">
                        <div class="asm-kpi-sublabel-small">Products:</div>
                        <div class="asm-kpi-value-small"><?php echo esc_html(number_format($total_wheel_products)); ?></div>
                    </div>
                </div>
            </div>

            <div class="asm-kpi-card">
                <div class="asm-kpi-header">
                    <div class="asm-kpi-icon">
                        <img src="<?php echo esc_url(ASM_PLUGIN_URL . 'admin/img/database-svgrepo-com.svg'); ?>" alt="Database Icon" style="height: 25px; width: 25px;" />
                    </div>
                    <div class="asm-kpi-label">Hash Entries</div>
                </div>
                <div class="asm-kpi-main">
                    <div class="asm-kpi-sublabel">Entries:</div>
                    <div class="asm-kpi-value"><?php echo esc_html(number_format($total_hash_tables)); ?></div>
                </div>
            </div>

            <div class="asm-kpi-card">
                <div class="asm-kpi-header">
                    <div class="asm-kpi-icon">
                        <img src="<?php echo esc_url(ASM_PLUGIN_URL . 'admin/img/connection-quality-veryhigh-svgrepo-com.svg'); ?>" alt="Connection Icon" style="height: 35px; width: 35px;" />
                    </div>
                    <div class="asm-kpi-label">Active Brands</div>
                </div>
                <div class="asm-kpi-content asm-kpi-progress-content">
                    <div class="asm-progress-item">
                        <div class="asm-progress-label">
                            <span>Active Tire Brands:</span>
                            <span class="asm-progress-count"><?php echo esc_html($active_tire_brands); ?> of <?php echo esc_html($total_tire_brands); ?> (<?php echo esc_html($tire_brand_percentage); ?>%)</span>
                        </div>
                        <div class="asm-progress-bar-small">
                            <div class="asm-progress-fill-small" style="width: <?php echo esc_attr($tire_brand_percentage); ?>%;"></div>
                        </div>
                    </div>
                    <div class="asm-progress-item">
                        <div class="asm-progress-label">
                            <span>Active Wheel Brands:</span>
                            <span class="asm-progress-count"><?php echo esc_html($active_wheel_brands); ?> of <?php echo esc_html($total_wheel_brands); ?> (<?php echo esc_html($wheel_brand_percentage); ?>%)</span>
                        </div>
                        <div class="asm-progress-bar-small">
                            <div class="asm-progress-fill-small" style="width: <?php echo esc_attr($wheel_brand_percentage); ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Fetch Brands Result -->
        <div id="asm-fetch-result" style="margin-top: 0; margin-bottom: 24px; display: none;"></div>

        <!-- Status Row -->
        <div class="asm-status-row">
            <!-- Brand Information -->
            <!-- <div class="asm-card">
                <h2>Brand Information</h2>
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row" style="font-weight: 500; padding: 12px 0;">Total Tire Brands</th>
                        <td style="padding: 12px 0;"><strong><?php echo esc_html(number_format($total_tire_brands)); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row" style="font-weight: 500; padding: 12px 0;">Total Wheel Brands</th>
                        <td style="padding: 12px 0;"><strong><?php echo esc_html(number_format($total_wheel_brands)); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row" style="font-weight: 500; padding: 12px 0;">Active Tire Brands</th>
                        <td style="padding: 12px 0;"><strong><?php echo esc_html(number_format($active_tire_brands)); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row" style="font-weight: 500; padding: 12px 0;">Active Wheel Brands</th>
                        <td style="padding: 12px 0;"><strong><?php echo esc_html(number_format($active_wheel_brands)); ?></strong></td>
                    </tr>
                </table>
            </div> -->

            <!-- Sync Status Section -->
            <div class="asm-card asm-sync-status-card">
                <div class="asm-section-header">
                    <h2>
                        <span class="dashicons dashicons-update"></span>
                        Sync Status
                    </h2>
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
                    
        
    </div>

<script>
jQuery(document).ready(function($) {
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
        
        // Calculate summary
        var totalTime = ((Date.now() - startTime) / 1000);
        var totalPages = 0, totalImported = 0, totalUpdated = 0, totalSkipped = 0;
        
        brands.forEach(function(brand) {
            totalPages += brand.pages;
            totalImported += brand.imported;
            totalUpdated += brand.updated;
            totalSkipped += brand.skipped;
        });
        
        // Use stats from callbacks if available, otherwise use calculated values
        if (tireStats && wheelStats) {
            totalPages = (tireStats.totalPages || 0) + (wheelStats.totalPages || 0);
            totalImported = (tireStats.totalImported || 0) + (wheelStats.totalImported || 0);
            totalUpdated = (tireStats.totalUpdated || 0) + (wheelStats.totalUpdated || 0);
            totalSkipped = (tireStats.totalSkipped || 0) + (wheelStats.totalSkipped || 0);
        }
        
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
            var totalPages = 0, totalImported = 0, totalUpdated = 0, totalSkipped = 0;
            
            // Calculate totals from all brand summaries
            $sharedBrandsContainer.find('.asm-brand-summary').each(function() {
                var $summary = $(this);
                totalPages += parseInt($summary.data('pages') || 0);
                totalImported += parseInt($summary.data('imported') || 0);
                totalUpdated += parseInt($summary.data('updated') || 0);
                totalSkipped += parseInt($summary.data('skipped') || 0);
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
            var totalImported = 0, totalUpdated = 0, totalSkipped = 0, totalPages = 0;
            $brandsContainer.find('.asm-brand-summary').each(function() {
                var $summary = $(this);
                totalImported += parseInt($summary.data('imported') || 0);
                totalUpdated += parseInt($summary.data('updated') || 0);
                totalSkipped += parseInt($summary.data('skipped') || 0);
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
                    'Skipped: <strong>' + totalSkipped + '</strong> | ' +
                    'Total Time: <strong>' + totalTimeDisplay + '</strong></span>' +
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
                    totalTime: totalElapsedTime
                });
            } else {
                // Individual sync (tires only or wheels only) - save sync status and send email
                var stats = {
                    totalPages: totalPages,
                    totalImported: totalImported,
                    totalUpdated: totalUpdated,
                    totalSkipped: totalSkipped,
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
                'data-pages="' + syncState.totalPages + '">' +
                '<span class="dashicons dashicons-yes" style="color: #00a32a;"></span>' +
                '<span>Pages: <strong>' + syncState.totalPages + '</strong> | ' +
                'Imported: <strong>' + syncState.totalImported + '</strong> | ' +
                'Updated: <strong>' + syncState.totalUpdated + '</strong> | ' +
                'Skipped: <strong>' + syncState.totalSkipped + '</strong> | ' +
                'Time: <strong>' + timeDisplay + '</strong></span>' +
                '</div>');
            syncState.$brandProgress.find('.asm-brand-progress-header .dashicons').removeClass('asm-spinning').addClass('dashicons-yes-alt').css('color', '#00a32a');
            
            if ($result) {
                $result.append('<p>✓ Completed ' + brand.brand_name + ' - Pages: ' + syncState.totalPages + ', Imported: ' + syncState.totalImported + ', Updated: ' + syncState.totalUpdated + ', Skipped: ' + syncState.totalSkipped + ', Time: ' + timeDisplay + '</p>');
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
});
</script>

<style>
/* ============================================
   Brand Management Dashboard Styles
   ============================================ */

/* Dashboard Header */
.asm-dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid #e0e0e0;
}

.asm-dashboard-title {
    font-size: 24px;
    font-weight: 500;
    color: #1d2327;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
}

.asm-dashboard-subtitle {
    font-size: 14px;
    color: #646970;
    margin: 0;
    font-weight: 400;
}

.asm-dashboard-actions {
    display: flex;
    gap: 12px;
    align-items: center;
}

.asm-dashboard-actions .button {
    height: 36px;
    padding: 0 16px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.asm-dashboard-actions .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* KPI Cards Grid */
.asm-kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

.asm-kpi-card {
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 24px;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.asm-kpi-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 3px;
    height: 100%;
    background: #50575e;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.asm-kpi-card:hover {
    border-color: #c3c4c7;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.asm-kpi-card:hover::before {
    opacity: 1;
}

.asm-kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    gap: 12px;
}

.asm-kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    /* background: #f6f7f7; */
}

.asm-kpi-icon .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
    color: #50575e;
}

.asm-kpi-icon svg {
    width: 20px;
    height: 20px;
    color: #50575e;
    flex-shrink: 0;
}

.asm-kpi-icon img {
    width: 30px;
    height: 30px;
    object-fit: contain;
    flex-shrink: 0;
    filter: brightness(0) saturate(100%) invert(30%) sepia(0%) saturate(0%) hue-rotate(0deg) brightness(95%) contrast(90%);
}

.asm-kpi-content {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.asm-kpi-main {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}

.asm-kpi-secondary {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    padding-top: 8px;
    border-top: 1px solid #f0f0f1;
}

.asm-kpi-label {
    font-size: 14px;
    color: #1d2327;
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
    margin: 0;
    line-height: 1.4;
    flex: 1;
    text-align: right;
}

.asm-kpi-value {
    font-size: 32px;
    font-weight: 600;
    color: #1d2327;
    line-height: 1.2;
    margin: 0;
    letter-spacing: -0.5px;
    flex-shrink: 0;
}

.asm-kpi-sublabel {
    font-size: 14px;
    color: #646970;
    font-weight: 500;
    margin: 0;
    line-height: 1.2;
}

.asm-kpi-sublabel-small {
    font-size: 12px;
    color: #8c8f94;
    font-weight: 500;
    margin: 0;
    line-height: 1.2;
}

.asm-kpi-value-small {
    font-size: 20px;
    font-weight: 600;
    color: #646970;
    line-height: 1.2;
    margin: 0;
    letter-spacing: -0.3px;
    flex-shrink: 0;
}

.asm-kpi-value-small {
    font-size: 18px !important;
    line-height: 1.4 !important;
}

/* KPI Progress Content */
.asm-kpi-progress-content {
    flex-direction: column;
    gap: 16px;
    align-items: stretch;
}

.asm-progress-item {
    width: 100%;
}

.asm-progress-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    font-size: 12px;
    color: #646970;
    font-weight: 500;
}

.asm-progress-count {
    color: #1d2327;
    font-weight: 600;
}

.asm-progress-bar-small {
    width: 100%;
    height: 8px;
    background: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.asm-progress-fill-small {
    height: 100%;
    background: linear-gradient(90deg, #2271b1 0%, #135e96 100%);
    border-radius: 4px;
    transition: width 0.3s ease;
}

/* Dashboard Container */
.asm-dashboard-container {
    margin-top: 0;
}

/* Status Row */
.asm-status-row {
    /* display: grid; */
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 32px;
}
/* .asm-brands-container{
    display:flex;
    gap:20px;
} */

/* Enhanced Cards */
.asm-card {
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 24px;
    margin-bottom: 32px !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.asm-card h2 {
    margin: 0 0 20px 0;
    padding: 0 0 16px 0;
    border-bottom: 1px solid #f0f0f1;
    font-size: 16px;
    font-weight: 600;
    color: #1d2327;
    letter-spacing: -0.2px;
}

.asm-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f0f0f1;
}

.asm-section-header h2 {
    margin: 0;
    padding: 0;
    border: none;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
    font-weight: 600;
    color: #1d2327;
}

.asm-sync-status-card {
    display: flex;
    flex-direction: column;
    min-height: 400px;
}

.asm-sync-status-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 8px;
    min-height: 400px;
    max-height: 500px;
}

/* Brand Grid */
.asm-brands-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 20px;
    max-height: 600px;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 8px;
    padding-bottom: 8px;
}

/* Brand Items */
.asm-brand-item {
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 16px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.asm-brand-item:hover {
    border-color: #c3c4c7;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
}

.asm-brand-item.inactive {
    opacity: 0.65;
    background: #fafafa;
}

.asm-brand-label {
    display: flex;
    align-items: center;
    cursor: pointer;
    margin: -16px;
    padding: 16px;
}

.asm-brand-checkbox {
    margin-right: 12px;
    cursor: pointer;
    width: 18px;
    height: 18px;
}

.asm-brand-name {
    flex: 1;
    font-weight: 500;
    color: #1d2327;
    font-size: 14px;
}

/* Bulk Actions */
.asm-bulk-actions-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: #fafafa;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    margin-bottom: 24px;
}

.asm-bulk-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #ffffff;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    color: #2c3338;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.asm-bulk-btn:hover:not(.disabled) {
    border-color: #8c8f94;
    background: #f6f7f7;
}

.asm-bulk-btn:active:not(.disabled) {
    background: #f0f0f1;
}

.asm-bulk-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Status Items */
.asm-status-item {
    display: flex;
    align-items: flex-start;
    padding: 12px 16px;
    margin-bottom: 12px;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1.6;
}

.asm-status-item .dashicons {
    margin-right: 10px;
    margin-top: 2px;
    flex-shrink: 0;
}

.asm-status-info {
    background: #f0f6fc;
    color: #0969da;
    border-left: 3px solid #0969da;
}

.asm-status-success {
    background: #f0f9ff;
    color: #1e7e34;
    border-left: 3px solid #00a32a;
}

.asm-status-warning {
    background: #fffbf0;
    color: #856404;
    border-left: 3px solid #dba617;
}

.asm-status-error {
    background: #fcf0f1;
    color: #b32d2e;
    border-left: 3px solid #d63638;
}

/* Responsive */
@media (max-width: 1200px) {
    .asm-status-row {
        grid-template-columns: 1fr;
    }
    
    .asm-kpi-row {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .asm-brands-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }
}

@media (max-width: 768px) {
    .asm-dashboard-header {
        flex-direction: column;
        gap: 16px;
    }
    
    .asm-dashboard-actions {
        width: 100%;
    }
    
    .asm-dashboard-actions .button {
        flex: 1;
        justify-content: center;
    }
    
    .asm-kpi-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    
    .asm-kpi-card {
        padding: 20px;
    }
    
    .asm-kpi-value {
        font-size: 28px;
    }
    
    .asm-brands-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .asm-kpi-row {
        grid-template-columns: 1fr;
    }
    
    .asm-brands-grid {
        grid-template-columns: 1fr;
    }
    
    .asm-bulk-actions-toolbar {
        flex-direction: column;
        gap: 12px;
    }
    
    .asm-bulk-actions-left {
        width: 100%;
        flex-direction: column;
    }
    
    .asm-bulk-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>


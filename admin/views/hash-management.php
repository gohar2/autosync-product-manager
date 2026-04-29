<?php
/**
 * Hash Management Page View
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load required classes
require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';

// Check if table exists before getting statistics
global $wpdb;
$table_name = ASM_Hash_Manager::get_table_name();
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

// Get hash statistics only if table exists
$total_hashes = 0;
if ($table_exists) {
    $total_hashes = ASM_Hash_Manager::get_total_count();
}
?>

<div class="wrap">
    <h1>Hash Management</h1>
    <p class="description">Manage product hash table for change detection</p>

    <div class="asm-container">
        <!-- Hash Table Info -->
        <div class="asm-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 style="margin: 0;">Hash Table Information</h2>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" id="asm-rebuild-hash-table" class="button button-primary">
                        <?php echo $table_exists ? 'Rebuild Hash Table' : 'Create Hash Table'; ?>
                    </button>
                    
                    <?php if ($table_exists): ?>
                        <button type="button" id="asm-truncate-hash-table" class="button button-secondary" style="background-color: #dc3232; border-color: #dc3232; color: #fff;">
                            Empty Hash Table
                        </button>
                        <button type="button" id="asm-empty-price-stock-hash" class="button button-secondary" style="background-color: #d63638; border-color: #d63638; color: #fff;">
                            Empty Price/Stock Hash
                        </button>
                    <?php endif; ?>
                    <button type="button" id="asm-check-duplicate-skus" class="button button-secondary">
                        Check Duplicate SKU's
                    </button>
                </div>
            </div>

            <div id="asm-rebuild-result" style="display: none;"></div>
            <?php if ($table_exists): ?>
                <div id="asm-truncate-result" style="display: none;"></div>
                <div id="asm-empty-price-stock-hash-result" style="display: none;"></div>
            <?php endif; ?>

            <p>The hash table stores SHA-256 hashes of product data to detect changes. When importing products, the system compares the hash of incoming data with stored hashes. If the hash matches, the product is skipped (no update needed). If the hash differs or doesn't exist, the product is updated or inserted.</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Total Hashes</th>
                    <td><strong><?php echo esc_html(number_format($total_hashes)); ?></strong></td>
                </tr>
                <tr>
                    <th scope="row">Table Status</th>
                    <td>
                        <?php
                        global $wpdb;
                        $table_name = ASM_Hash_Manager::get_table_name();
                        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
                        if ($table_exists) {
                            echo '<span style="color: green;">✓ Table exists</span>';
                        } else {
                            echo '<span style="color: red;">✗ Table does not exist</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Delete Extra Products Section -->
        <div class="asm-card" style="margin-top: 20px;">
            <h2>Delete Extra Products</h2>
            <p class="description">
                Delete products that were not imported through AutoSync. This will remove products that don't have the <code>_is_autosync_product</code> meta field.
            </p>
            
            <?php if ($table_exists && $total_hashes > 0): ?>
                <div style="margin-top: 15px;">
                    <button type="button" id="asm-delete-extra-products" class="button button-secondary" style="background-color: #dc3232; border-color: #dc3232; color: #fff;">
                        Delete Extra Products
                    </button>
                </div>
                <div id="asm-delete-extra-products-result" style="display: none; margin-top: 15px;"></div>
            <?php else: ?>
                <div style="padding: 15px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; color: #856404;">
                    <strong>⚠️ Hash table is empty.</strong><br>
                    Please sync products first before deleting extra products. The hash table needs to have entries to ensure proper product tracking.
                </div>
            <?php endif; ?>
        </div>

        <!-- Duplicate SKUs Table -->
        <div id="asm-duplicate-skus-container" class="asm-card" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 style="margin: 0;">Duplicate SKU Products</h2>
                <button type="button" id="asm-bulk-delete-duplicates" class="button button-secondary" style="background-color: #dc3232; border-color: #dc3232; color: #fff; display: none;">
                    Delete All Duplicates
                </button>
            </div>
            <div id="asm-duplicate-skus-result" style="display: none;"></div>
            <div id="asm-duplicate-skus-pagination-top"></div>
            <div id="asm-duplicate-skus-table-container"></div>
            <div id="asm-duplicate-skus-pagination"></div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    console.log('Hash management page script loaded');
    
    // Get AJAX URL and nonce from WordPress
    var ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo wp_create_nonce('asm_nonce'); ?>';
    
    console.log('AJAX setup', { ajax_url: ajaxUrl, has_nonce: !!nonce });

    // Check if button exists
    if ($('#asm-rebuild-hash-table').length === 0) {
        console.error('Button #asm-rebuild-hash-table not found');
        return;
    }

    $('#asm-rebuild-hash-table').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $button = $(this);
        var $result = $('#asm-rebuild-result');
        var $truncateButton = $('#asm-truncate-hash-table');
        var $truncateResult = $('#asm-truncate-result');

        console.log('Create/Rebuild hash table button clicked');

        // Show loading state
        var buttonText = $button.text();
        $button.prop('disabled', true).text('Processing...');
        if ($truncateButton.length) {
            $truncateButton.prop('disabled', true); // Disable truncate button during rebuild
        }
        $result.removeClass('success error').addClass('loading').show();
        $truncateResult.html('').removeClass('success error loading').hide(); // Clear and hide truncate result
        $result.html('<p>Processing hash table structure...</p>');

        // Make AJAX request
        console.log('Sending AJAX request', {
            url: ajaxUrl,
            action: 'asm_rebuild_hash_table',
            has_nonce: !!nonce
        });

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_rebuild_hash_table',
                nonce: nonce
            },
            timeout: 300000, // 5 minutes timeout
            beforeSend: function() {
                console.log('AJAX request started');
            },
            success: function(response) {
                console.log('AJAX success response', response);
                
                if (response && response.success) {
                    $result.removeClass('loading error').addClass('success').show();
                    var message = response.data.message || 'Hash table created successfully';
                    var note = response.data.note || '';
                    var tableExists = response.data.table_exists || false;
                    
                    var html = '<strong>✓ ' + message + '</strong><br><br>';
                    if (tableExists) {
                        html += '<p>The hash table already exists and is ready to use.</p>';
                    } else {
                        html += '<p>The hash table structure has been created successfully.</p>';
                    }
                    if (note) {
                        html += '<p><em>' + note + '</em></p>';
                    }
                    html += '<p><em>Page will refresh in 3 seconds...</em></p>';
                    
                    $result.html(html);
                    
                    // Refresh page after 3 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    console.error('AJAX response indicates failure', response);
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = 'Unknown error occurred';
                    if (response && response.data) {
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (response.data.message) {
                            errorMsg = response.data.message;
                        }
                    }
                    $result.html(
                        '<strong>✗ Rebuild Failed</strong><br>' +
                        errorMsg
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error', { 
                    xhr: xhr, 
                    status: status, 
                    error: error, 
                    responseText: xhr.responseText,
                    readyState: xhr.readyState,
                    statusCode: xhr.status
                });
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                
                if (status === 'timeout') {
                    errorMsg = 'Request timed out after 5 minutes. The operation may still be processing. Check server logs.';
                } else if (xhr.status === 0) {
                    errorMsg = 'Connection failed. Please check your network connection and try again.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error (500). Check server error logs for details.';
                }
                
                if (xhr.responseText) {
                    try {
                        var errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data) {
                            if (typeof errorResponse.data === 'string') {
                                errorMsg += '<br>Details: ' + errorResponse.data;
                            } else if (errorResponse.data.message) {
                                errorMsg += '<br>Details: ' + errorResponse.data.message;
                            } else {
                                errorMsg += '<br>Details: ' + JSON.stringify(errorResponse.data);
                            }
                        }
                    } catch (e) {
                        errorMsg += '<br>Raw Response: ' + xhr.responseText.substring(0, 500);
                    }
                }
                $result.html(
                    '<strong>✗ Request Failed</strong><br>' +
                    errorMsg + '<br><br>' +
                    '<small>Status: ' + status + ' | HTTP Code: ' + xhr.status + '</small>'
                );
            },
            complete: function() {
                var buttonText = '<?php echo $table_exists ? "Rebuild Hash Table" : "Create Hash Table"; ?>';
                $button.prop('disabled', false).text(buttonText);
                $('#asm-truncate-hash-table').prop('disabled', false); // Re-enable truncate button
                console.log('AJAX request completed');
            }
        });
    });

    // Truncate hash table button
    $('#asm-truncate-hash-table').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Confirm action
        if (!confirm('Are you sure you want to empty the hash table?\n\nThis will delete all hash records permanently. After emptying, all products will be treated as new during the next import.\n\nThis action cannot be undone.')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#asm-truncate-result');
        var $rebuildResult = $('#asm-rebuild-result');
        var $rebuildButton = $('#asm-rebuild-hash-table');

        console.log('Empty hash table button clicked');

        // Disable both buttons during operation
        $button.prop('disabled', true).text('Emptying...');
        $rebuildButton.prop('disabled', true);
        $rebuildResult.html('').removeClass('success error loading').hide(); // Clear and hide rebuild result
        $result.removeClass('success error').addClass('loading').show();
        $result.html('<p>Emptying hash table. This may take a moment...</p>');

        // Make AJAX request
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_truncate_hash_table',
                nonce: nonce
            },
            timeout: 300000, // 5 minutes timeout
            success: function(response) {
                console.log('AJAX success response', response);
                
                if (response && response.success) {
                    $result.removeClass('loading error').addClass('success').show();
                    var message = response.data.message || 'Hash table emptied successfully';
                    var rowsDeleted = response.data.rows_deleted || 0;
                    
                    var html = '<strong>✓ ' + message + '</strong><br><br>';
                    html += '<p>All hash records have been deleted. The table structure remains intact.</p>';
                    html += '<p><em>Page will refresh in 3 seconds...</em></p>';
                    
                    $result.html(html);
                    
                    // Refresh page after 3 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    console.error('AJAX response indicates failure', response);
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = 'Unknown error occurred';
                    if (response && response.data) {
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (response.data.message) {
                            errorMsg = response.data.message;
                        }
                    }
                    $result.html(
                        '<strong>✗ Empty Failed</strong><br>' +
                        errorMsg
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error', { 
                    xhr: xhr, 
                    status: status, 
                    error: error
                });
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                } else if (xhr.status === 0) {
                    errorMsg = 'Connection failed. Please check your network connection and try again.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error (500). Check server error logs for details.';
                }
                
                $result.html(
                    '<strong>✗ Request Failed</strong><br>' +
                    errorMsg
                );
            },
            complete: function() {
                $button.prop('disabled', false).text('Empty Hash Table');
                $('#asm-rebuild-hash-table').prop('disabled', false);
                console.log('AJAX request completed');
            }
        });
    });

    // Empty Price/Stock Hash button with batch processing
    $('#asm-empty-price-stock-hash').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Confirm action
        if (!confirm('Are you sure you want to empty all price_stock_hash values?\n\nThis will clear the price_stock_hash column for all records in the hash table. After clearing, all products will be treated as having changed price/stock during the next price/stock sync.\n\nThis action cannot be undone.')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#asm-empty-price-stock-hash-result');
        var isProcessing = false;

        console.log('Empty price/stock hash button clicked');

        // Disable button during operation
        $button.prop('disabled', true).text('Initializing...');
        $result.removeClass('success error').addClass('loading').show();
        $result.html('<p>Initializing batch process...</p>');

        // Function to process a batch
        function processBatch() {
            if (isProcessing) {
                return;
            }
            
            isProcessing = true;
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asm_empty_price_stock_hash_batch',
                    nonce: nonce
                },
                timeout: 300000, // 5 minutes per batch
                success: function(response) {
                    isProcessing = false;
                    
                    if (response && response.success) {
                        var data = response.data;
                        var processed = data.processed || 0;
                        var total = data.total || 0;
                        var batchUpdated = data.batch_updated || 0;
                        var remaining = data.remaining || 0;
                        var completed = data.completed === true;
                        
                        // Update progress display
                        var progressMsg = '<p><strong>Clearing price_stock_hash...</strong><br>';
                        progressMsg += '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                    processed + ' / ' + total + ' rows cleared</span><br>';
                        if (remaining > 0) {
                            progressMsg += 'Remaining: ' + remaining + ' rows';
                        }
                        progressMsg += '</p>';
                        $result.html(progressMsg);
                        $button.text('Clearing... (' + processed + '/' + total + ')');
                        
                        if (completed) {
                            // All done
                            $result.removeClass('loading error').addClass('success').show();
                            var finalMsg = '<strong>✓ Price/Stock hash cleared successfully!</strong><br>';
                            finalMsg += 'Total cleared: ' + total + ' rows<br>';
                            if (data.duration) {
                                finalMsg += 'Duration: ' + data.duration + ' seconds<br>';
                            }
                            finalMsg += '<p><em>Page will refresh in 3 seconds...</em></p>';
                            $result.html(finalMsg);
                            $button.prop('disabled', false).text('Empty Price/Stock Hash');
                            
                            // Refresh page after 3 seconds
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else {
                            // Continue processing
                            setTimeout(processBatch, 100);
                        }
                    } else {
                        $result.removeClass('loading success').addClass('error').show();
                        var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to process batch';
                        $result.html('<strong>✗ Clear Failed</strong><br>' + errorMsg);
                        $button.prop('disabled', false).text('Empty Price/Stock Hash');
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
                    $button.prop('disabled', false).text('Empty Price/Stock Hash');
                }
            });
        }
        
        // Initialize batch processing
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_empty_price_stock_hash',
                nonce: nonce
            },
            timeout: 60000, // 1 minute for initialization
            success: function(response) {
                if (response && response.success) {
                    var data = response.data;
                    var total = data.total || 0;
                    
                    if (total === 0) {
                        $result.removeClass('loading error').addClass('success').show();
                        $result.html('<strong>✓ ' + (data.message || 'No price_stock_hash values to clear') + '</strong>');
                        $button.prop('disabled', false).text('Empty Price/Stock Hash');
                        return;
                    }
                    
                    // Start processing batches
                    $result.html('<p><strong>Initializing price_stock_hash clearing...</strong><br>' + 
                                '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                '0 / ' + total + ' rows cleared</span></p>');
                    $button.text('Clearing... (0/' + total + ')');
                    
                    // Process first batch immediately
                    processBatch();
                } else {
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to initialize price_stock_hash clearing';
                    $result.html('<strong>✗ Initialization Failed</strong><br>' + errorMsg);
                    $button.prop('disabled', false).text('Empty Price/Stock Hash');
                }
            },
            error: function(xhr, status, error) {
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                }
                $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
                $button.prop('disabled', false).text('Empty Price/Stock Hash');
            }
        });
    });

    // Check duplicate SKUs button
    $('#asm-check-duplicate-skus').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $button = $(this);
        var $container = $('#asm-duplicate-skus-container');
        var $result = $('#asm-duplicate-skus-result');
        var $tableContainer = $('#asm-duplicate-skus-table-container');
        var $pagination = $('#asm-duplicate-skus-pagination');

        console.log('Check duplicate SKUs button clicked');

        // Show loading state
        $button.prop('disabled', true).text('Checking...');
        $container.show();
        $result.removeClass('success error').addClass('loading').show();
        $result.html('<p>Searching for duplicate SKUs. This may take a moment...</p>');
        $tableContainer.html('');
        $pagination.html('');

        // Make AJAX request
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_check_duplicate_skus',
                nonce: nonce,
                page: 1,
                per_page: 20
            },
            timeout: 300000, // 5 minutes timeout
            success: function(response) {
                console.log('AJAX success response', response);
                
                if (response && response.success) {
                    $result.removeClass('loading error').addClass('success').show();
                    var duplicates = response.data.duplicates || [];
                    var total = response.data.total || 0;
                    var currentPage = response.data.page || 1;
                    var perPage = response.data.per_page || 20;
                    var totalPages = Math.ceil(total / perPage);

                    if (total === 0) {
                        $result.html('<strong>✓ No duplicate SKUs found</strong>');
                        $tableContainer.html('');
                        $pagination.html('');
                    } else {
                        $result.html('<strong>✓ Found ' + total + ' duplicate product(s)</strong>');
                        
                        // Show bulk delete button
                        $('#asm-bulk-delete-duplicates').show();
                        
                        // Build pagination (top)
                        var $paginationTop = $('#asm-duplicate-skus-pagination-top');
                        if (totalPages > 1) {
                            var paginationTopHtml = buildPaginationHtml(total, currentPage, totalPages);
                            $paginationTop.html(paginationTopHtml);
                            attachPaginationHandlers($paginationTop, perPage);
                        } else {
                            $paginationTop.html('');
                        }
                        
                        // Build table
                        var tableHtml = '<table class="wp-list-table widefat fixed striped">';
                        tableHtml += '<thead><tr>';
                        tableHtml += '<th>SKU</th>';
                        tableHtml += '<th>Product ID</th>';
                        tableHtml += '<th>Product Title</th>';
                        tableHtml += '<th>Date Created</th>';
                        tableHtml += '<th>Actions</th>';
                        tableHtml += '</tr></thead><tbody>';
                        
                        duplicates.forEach(function(product) {
                            tableHtml += '<tr id="asm-duplicate-row-' + product.product_id + '">';
                            tableHtml += '<td><strong>' + (product.sku || 'N/A') + '</strong></td>';
                            tableHtml += '<td>';
                            if (product.product_id) {
                                tableHtml += '<a href="' + product.edit_link + '" target="_blank">' + product.product_id + '</a>';
                            } else {
                                tableHtml += '<em>N/A</em>';
                            }
                            tableHtml += '</td>';
                            tableHtml += '<td>' + (product.title || 'N/A') + '</td>';
                            tableHtml += '<td>' + (product.date_created || 'N/A') + '</td>';
                            tableHtml += '<td>';
                            if (product.product_id) {
                                tableHtml += '<button type="button" class="button button-small asm-delete-duplicate" data-product-id="' + product.product_id + '" data-sku="' + (product.sku || '') + '" style="background-color: #dc3232; border-color: #dc3232; color: #fff;">Delete</button>';
                            }
                            tableHtml += '</td>';
                            tableHtml += '</tr>';
                        });
                        
                        tableHtml += '</tbody></table>';
                        $tableContainer.html(tableHtml);
                        
                        // Attach delete button handlers
                        attachDeleteHandlers();

                        // Build pagination (bottom)
                        if (totalPages > 1) {
                            var paginationHtml = buildPaginationHtml(total, currentPage, totalPages);
                            $pagination.html(paginationHtml);
                            attachPaginationHandlers($pagination, perPage);
                        } else {
                            $pagination.html('');
                        }
                    }
                } else {
                    console.error('AJAX response indicates failure', response);
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = 'Unknown error occurred';
                    if (response && response.data) {
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (response.data.message) {
                            errorMsg = response.data.message;
                        }
                    }
                    $result.html('<strong>✗ Check Failed</strong><br>' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error', { xhr: xhr, status: status, error: error });
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                } else if (xhr.status === 0) {
                    errorMsg = 'Connection failed. Please check your network connection and try again.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error (500). Check server error logs for details.';
                }
                
                $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
            },
            complete: function() {
                $button.prop('disabled', false).text('Check Duplicate SKU\'s');
                console.log('AJAX request completed');
            }
        });
    });

    // Helper function to build pagination HTML
    function buildPaginationHtml(total, currentPage, totalPages) {
        var paginationHtml = '<div class="tablenav">';
        paginationHtml += '<div class="tablenav-pages">';
        paginationHtml += '<span class="displaying-num">' + total + ' items</span>';
        
        // Previous button
        if (currentPage > 1) {
            paginationHtml += '<a class="prev-page button" data-page="' + (currentPage - 1) + '">‹</a>';
        } else {
            paginationHtml += '<span class="tablenav-pages-navspan" aria-hidden="true">‹</span>';
        }
        
        // Page numbers
        var startPage = Math.max(1, currentPage - 2);
        var endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            paginationHtml += '<a class="first-page button" data-page="1">1</a>';
            if (startPage > 2) {
                paginationHtml += '<span class="paging-input"><span class="tablenav-paging-text">…</span></span>';
            }
        }
        
        for (var i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                paginationHtml += '<span class="paging-input"><span class="tablenav-paging-text">' + i + ' of <span class="total-pages">' + totalPages + '</span></span></span>';
            } else {
                paginationHtml += '<a class="page-numbers button" data-page="' + i + '">' + i + '</a>';
            }
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHtml += '<span class="paging-input"><span class="tablenav-paging-text">…</span></span>';
            }
            paginationHtml += '<a class="last-page button" data-page="' + totalPages + '">' + totalPages + '</a>';
        }
        
        // Next button
        if (currentPage < totalPages) {
            paginationHtml += '<a class="next-page button" data-page="' + (currentPage + 1) + '">›</a>';
        } else {
            paginationHtml += '<span class="tablenav-pages-navspan" aria-hidden="true">›</span>';
        }
        
        paginationHtml += '</div></div>';
        return paginationHtml;
    }

    // Helper function to attach pagination handlers
    function attachPaginationHandlers($container, perPage) {
        $container.find('a[data-page]').off('click').on('click', function(e) {
            e.preventDefault();
            var page = parseInt($(this).data('page'));
            loadDuplicateSKUs(page, perPage);
        });
    }

    // Helper function to attach delete button handlers
    function attachDeleteHandlers() {
        $('.asm-delete-duplicate').off('click').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var productId = $button.data('product-id');
            var sku = $button.data('sku');
            var $row = $button.closest('tr');
            
            if (!confirm('Are you sure you want to delete this duplicate product?\n\nProduct ID: ' + productId + '\nSKU: ' + sku + '\n\nThis will only delete the duplicate, not the original product.\n\nThis action cannot be undone.')) {
                return;
            }
            
            $button.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asm_delete_duplicate_product',
                    nonce: nonce,
                    product_id: productId
                },
                timeout: 30000,
                success: function(response) {
                    if (response && response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            // Update total count and refresh if needed
                            var remainingRows = $('#asm-duplicate-skus-table-container tbody tr').length;
                            if (remainingRows === 0) {
                                // Reload current page or go to previous page
                                var currentPage = parseInt($('#asm-duplicate-skus-pagination .tablenav-pages .tablenav-paging-text').text().split(' of ')[0]) || 1;
                                if (currentPage > 1) {
                                    loadDuplicateSKUs(currentPage - 1, 20);
                                } else {
                                    $('#asm-duplicate-skus-container').hide();
                                }
                            }
                        });
                    } else {
                        alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to delete product'));
                        $button.prop('disabled', false).text('Delete');
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX Error: ' + error);
                    $button.prop('disabled', false).text('Delete');
                }
            });
        });
    }

    // Function to load duplicate SKUs for pagination
    function loadDuplicateSKUs(page, perPage) {
        var $result = $('#asm-duplicate-skus-result');
        var $tableContainer = $('#asm-duplicate-skus-table-container');
        var $pagination = $('#asm-duplicate-skus-pagination');
        var $paginationTop = $('#asm-duplicate-skus-pagination-top');

        $result.removeClass('error').addClass('loading').show();
        $result.html('<p>Loading page ' + page + '...</p>');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_check_duplicate_skus',
                nonce: nonce,
                page: page,
                per_page: perPage
            },
            timeout: 300000,
            success: function(response) {
                if (response && response.success) {
                    $result.removeClass('loading error').addClass('success').show();
                    var duplicates = response.data.duplicates || [];
                    var total = response.data.total || 0;
                    var currentPage = response.data.page || 1;
                    var totalPages = Math.ceil(total / perPage);

                    $result.html('<strong>✓ Found ' + total + ' duplicate product(s)</strong>');
                    
                    // Show bulk delete button
                    $('#asm-bulk-delete-duplicates').show();
                    
                    // Build pagination (top)
                    if (totalPages > 1) {
                        var paginationTopHtml = buildPaginationHtml(total, currentPage, totalPages);
                        $paginationTop.html(paginationTopHtml);
                        attachPaginationHandlers($paginationTop, perPage);
                    } else {
                        $paginationTop.html('');
                    }
                    
                    // Build table
                    var tableHtml = '<table class="wp-list-table widefat fixed striped">';
                    tableHtml += '<thead><tr>';
                    tableHtml += '<th>SKU</th>';
                    tableHtml += '<th>Product ID</th>';
                    tableHtml += '<th>Product Title</th>';
                    tableHtml += '<th>Date Created</th>';
                    tableHtml += '<th>Actions</th>';
                    tableHtml += '</tr></thead><tbody>';
                    
                    duplicates.forEach(function(product) {
                        tableHtml += '<tr id="asm-duplicate-row-' + product.product_id + '">';
                        tableHtml += '<td><strong>' + (product.sku || 'N/A') + '</strong></td>';
                        tableHtml += '<td>';
                        if (product.product_id) {
                            tableHtml += '<a href="' + product.edit_link + '" target="_blank">' + product.product_id + '</a>';
                        } else {
                            tableHtml += '<em>N/A</em>';
                        }
                        tableHtml += '</td>';
                        tableHtml += '<td>' + (product.title || 'N/A') + '</td>';
                        tableHtml += '<td>' + (product.date_created || 'N/A') + '</td>';
                        tableHtml += '<td>';
                        if (product.product_id) {
                            tableHtml += '<button type="button" class="button button-small asm-delete-duplicate" data-product-id="' + product.product_id + '" data-sku="' + (product.sku || '') + '" style="background-color: #dc3232; border-color: #dc3232; color: #fff;">Delete</button>';
                        }
                        tableHtml += '</td>';
                        tableHtml += '</tr>';
                    });
                    
                    tableHtml += '</tbody></table>';
                    $tableContainer.html(tableHtml);
                    
                    // Attach delete button handlers
                    attachDeleteHandlers();

                    // Build pagination (bottom)
                    if (totalPages > 1) {
                        var paginationHtml = buildPaginationHtml(total, currentPage, totalPages);
                        $pagination.html(paginationHtml);
                        attachPaginationHandlers($pagination, perPage);
                    } else {
                        $pagination.html('');
                    }
                } else {
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = response && response.data && response.data.message ? response.data.message : 'Unknown error occurred';
                    $result.html('<strong>✗ Load Failed</strong><br>' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                $result.removeClass('loading success').addClass('error').show();
                $result.html('<strong>✗ Request Failed</strong><br>Error: ' + error);
            }
        });
    }

    // Delete extra products button with batch processing
    $('#asm-delete-extra-products').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Show confirmation popup
        if (!confirm('⚠️ WARNING: This action cannot be undone!\n\nThis will permanently delete all products that do NOT have the _is_autosync_product meta field.\n\nAll product data, meta fields, and relations will be removed.\n\nAre you sure you want to continue?')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#asm-delete-extra-products-result');
        var isProcessing = false;
        var totalDeleted = 0;
        
        console.log('Delete extra products button clicked');
        
        // Function to process a batch
        function processBatch() {
            if (isProcessing) {
                return;
            }
            
            isProcessing = true;
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asm_delete_extra_products',
                    nonce: nonce,
                    batch_size: 500 // Process 500 products per batch
                },
                timeout: 300000, // 5 minutes per batch
                success: function(response) {
                    isProcessing = false;
                    
                    if (response && response.success) {
                        var data = response.data;
                        var batchDeleted = data.deleted_count || 0;
                        var hasMore = data.has_more === true;
                        var completed = data.completed === true;
                        
                        totalDeleted += batchDeleted;
                        
                        // Update progress display
                        var progressMsg = '<p><strong>Deleting extra products...</strong><br>';
                        progressMsg += '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                    totalDeleted + ' product(s) deleted</span>';
                        if (hasMore) {
                            progressMsg += '<br>Processing next batch...';
                        }
                        progressMsg += '</p>';
                        $result.html(progressMsg);
                        $button.text('Deleting... (' + totalDeleted + ' deleted)');
                        
                        if (completed || !hasMore) {
                            // All done
                            $result.removeClass('loading error').addClass('success').show();
                            var finalMsg = '<strong>✓ Deletion completed successfully!</strong><br>';
                            finalMsg += 'Total deleted: ' + totalDeleted + ' product(s)<br>';
                            finalMsg += '<p><em>Page will refresh in 3 seconds...</em></p>';
                            $result.html(finalMsg);
                            $button.prop('disabled', false).text('Delete Extra Products');
                            
                            // Refresh page after 3 seconds
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        } else {
                            // Continue processing next batch
                            setTimeout(processBatch, 100);
                        }
                    } else {
                        $result.removeClass('loading success').addClass('error').show();
                        var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to delete products';
                        $result.html('<strong>✗ Delete Failed</strong><br>' + errorMsg);
                        $button.prop('disabled', false).text('Delete Extra Products');
                    }
                },
                error: function(xhr, status, error) {
                    isProcessing = false;
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = 'AJAX Error: ' + error;
                    
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. The operation may still be processing.';
                    } else if (xhr.status === 0) {
                        errorMsg = 'Connection failed. Please check your network connection and try again.';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error (500). Check server error logs for details.';
                    }
                    
                    if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMsg += '<br>Details: ' + errorResponse.data.message;
                            }
                        } catch (e) {
                            errorMsg += '<br>Raw Response: ' + xhr.responseText.substring(0, 500);
                        }
                    }
                    
                    $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
                    $button.prop('disabled', false).text('Delete Extra Products');
                }
            });
        }
        
        // Show loading state and start processing
        $button.prop('disabled', true).text('Initializing...');
        $result.removeClass('success error').addClass('loading').show();
        $result.html('<p>Initializing deletion process...</p>');
        
        // Start processing first batch
        processBatch();
    });

    // Bulk delete all duplicates with batch processing and progress tracking
    $('#asm-bulk-delete-duplicates').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to delete ALL duplicate products?\n\nThis will permanently delete all duplicate products but will keep the original products.\n\nThis action cannot be undone.')) {
            return;
        }
        
        var $button = $(this);
        var $result = $('#asm-duplicate-skus-result');
        var pollInterval = null;
        var isProcessing = false;
        
        $button.prop('disabled', true).text('Initializing...');
        $result.removeClass('error success').addClass('loading').show();
        $result.html('<p>Initializing batch deletion...</p>');
        
        // Function to process a batch
        function processBatch() {
            if (isProcessing) {
                return;
            }
            
            isProcessing = true;
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                    action: 'asm_bulk_delete_duplicates_batch',
                nonce: nonce
            },
                timeout: 300000, // 5 minutes per batch
            success: function(response) {
                    isProcessing = false;
                    
                if (response && response.success) {
                        var data = response.data;
                        var deleted = data.deleted || 0;
                        var total = data.total || 0;
                        var hasMore = data.has_more !== false;
                        var completed = data.completed === true;
                        
                        // Update progress display
                        $result.html('<p><strong>Deleting duplicates...</strong><br>' + 
                                    '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                    deleted + ' / ' + total + ' deleted</span></p>');
                        $button.text('Deleting... (' + deleted + '/' + total + ')');
                        
                        if (completed || !hasMore) {
                            // All done
                            clearInterval(pollInterval);
                    $result.removeClass('loading error').addClass('success').show();
                    $result.html('<strong>✓ Successfully deleted ' + deleted + ' duplicate product(s)</strong>');
                    
                    // Hide table and buttons
                    $('#asm-duplicate-skus-table-container').html('');
                    $('#asm-duplicate-skus-pagination').html('');
                    $('#asm-duplicate-skus-pagination-top').html('');
                    $('#asm-bulk-delete-duplicates').hide();
                } else {
                            // Continue processing
                            // Process next batch immediately
                            setTimeout(processBatch, 100);
                        }
                    } else {
                        clearInterval(pollInterval);
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to delete duplicates';
                    $result.html('<strong>✗ Delete Failed</strong><br>' + errorMsg);
                    $button.prop('disabled', false).text('Delete All Duplicates');
                }
            },
            error: function(xhr, status, error) {
                    isProcessing = false;
                    clearInterval(pollInterval);
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                if (status === 'timeout') {
                        errorMsg = 'Request timed out. Please try again.';
                }
                $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
                $button.prop('disabled', false).text('Delete All Duplicates');
                }
            });
        }
        
        // Initialize batch processing
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'asm_bulk_delete_duplicates',
                nonce: nonce
            },
            timeout: 60000, // 1 minute for initialization
            success: function(response) {
                if (response && response.success) {
                    var data = response.data;
                    var total = data.total || 0;
                    
                    if (total === 0) {
                        $result.removeClass('loading error').addClass('success').show();
                        $result.html('<strong>✓ No duplicate products found</strong>');
                        $button.prop('disabled', false).text('Delete All Duplicates');
                        return;
                    }
                    
                    // Start processing batches
                    $result.html('<p><strong>Deleting duplicates...</strong><br>' + 
                                '<span style="font-size: 18px; font-weight: bold; color: #0073aa;">' + 
                                '0 / ' + total + ' deleted</span></p>');
                    $button.text('Deleting... (0/' + total + ')');
                    
                    // Process first batch immediately
                    processBatch();
                } else {
                    $result.removeClass('loading success').addClass('error').show();
                    var errorMsg = response && response.data && response.data.message ? response.data.message : 'Failed to initialize deletion';
                    $result.html('<strong>✗ Initialization Failed</strong><br>' + errorMsg);
                    $button.prop('disabled', false).text('Delete All Duplicates');
                }
            },
            error: function(xhr, status, error) {
                $result.removeClass('loading success').addClass('error').show();
                var errorMsg = 'AJAX Error: ' + error;
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                }
                $result.html('<strong>✗ Request Failed</strong><br>' + errorMsg);
                $button.prop('disabled', false).text('Delete All Duplicates');
            }
        });
    });

});
</script>

<style>
#asm-rebuild-result {
    padding: 15px;
    border-radius: 4px;
    margin-top: 10px;
    display: none;
}

#asm-rebuild-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#asm-rebuild-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#asm-rebuild-result.loading {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

#asm-truncate-result {
    padding: 15px;
    border-radius: 4px;
    margin-top: 10px;
    display: none;
}

#asm-truncate-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#asm-truncate-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#asm-truncate-result.loading {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

#asm-empty-price-stock-hash-result {
    padding: 15px;
    border-radius: 4px;
    margin-top: 10px;
    display: none;
}

#asm-empty-price-stock-hash-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#asm-empty-price-stock-hash-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#asm-empty-price-stock-hash-result.loading {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

#asm-duplicate-skus-result {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    display: none;
}

#asm-duplicate-skus-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#asm-duplicate-skus-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#asm-duplicate-skus-result.loading {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

#asm-duplicate-skus-table-container {
    margin-bottom: 20px;
}

#asm-duplicate-skus-pagination {
    margin-top: 20px;
}

#asm-duplicate-skus-pagination-top {
    margin-bottom: 20px;
}

#asm-duplicate-skus-pagination-top .tablenav,
#asm-duplicate-skus-pagination .tablenav {
    display: flex;
    justify-content: flex-end;
    align-items: center;
}

#asm-delete-extra-products-result {
    padding: 15px;
    border-radius: 4px;
    display: none;
}

#asm-delete-extra-products-result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

#asm-delete-extra-products-result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

#asm-delete-extra-products-result.loading {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}
</style>


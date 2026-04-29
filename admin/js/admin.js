/**
 * Admin JavaScript for AutoSync Product Manager
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        /**
         * Tab Switching
         */
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            var target = $(this).attr('href');

            // Update tabs
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // Update content
            $('.asm-tab-content').removeClass('active').hide();
            $(target).addClass('active').fadeIn(200);
            
            // If Helper tab is shown, initialize button states
            if (target === '#helper') {
                setTimeout(function() {
                    updateDeleteButtonState('tire');
                    updateDeleteButtonState('wheel');
                }, 300);
            }
        });

        /* Import Test Product
        $('#asm-import-test').on('click', function() {
            var $button = $(this);
            var $result = $('#asm-import-result');

            // Show loading state
            $button.prop('disabled', true).text('Importing...');
            $result.removeClass('success error').addClass('loading');
            $result.html('Fetching product from API and creating in WooCommerce...');

            // Make AJAX request
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_import_test_product',
                    nonce: asmData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('loading error').addClass('success');
                        $result.html(
                            '<strong>✓ Success!</strong><br>' +
                            response.data.message + '<br><br>' +
                            '<strong>Product Details:</strong><br>' +
                            'Name: ' + response.data.product_name + '<br>' +
                            'SKU: ' + response.data.sku + '<br>' +
                            'Price: $' + response.data.price + '<br>' +
                            'Stock: ' + response.data.stock + '<br><br>' +
                            '<a href="' + response.data.edit_link + '" class="button button-small">View Product</a>'
                        );
                    } else {
                        $result.removeClass('loading success').addClass('error');
                        $result.html(
                            '<strong>✗ Import Failed</strong><br>' +
                            (response.data || 'Unknown error occurred')
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error');
                    $result.html(
                        '<strong>✗ Request Failed</strong><br>' +
                        'AJAX Error: ' + error
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).text('Import Test Product');
                }
            });
        });
        */

        /* Import Test Tire Product
        $('#asm-import-test-tire').on('click', function() {
            var $button = $(this);
            var $result = $('#asm-import-tire-result');

            // Show loading state
            $button.prop('disabled', true).text('Importing...');
            $result.removeClass('success error').addClass('loading');
            $result.html('Fetching tire product from API and creating in WooCommerce...');

            // Make AJAX request
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_import_test_tire',
                    nonce: asmData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('loading error').addClass('success');
                        $result.html(
                            '<strong>✓ Success!</strong><br>' +
                            response.data.message + '<br><br>' +
                            '<strong>Product Details:</strong><br>' +
                            'Name: ' + response.data.product_name + '<br>' +
                            'SKU: ' + response.data.sku + '<br>' +
                            'Price: $' + response.data.price + '<br>' +
                            'Stock: ' + response.data.stock + '<br><br>' +
                            '<a href="' + response.data.edit_link + '" class="button button-small">View Product</a>'
                        );
                    } else {
                        $result.removeClass('loading success').addClass('error');
                        $result.html(
                            '<strong>✗ Import Failed</strong><br>' +
                            (response.data || 'Unknown error occurred')
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error');
                    $result.html(
                        '<strong>✗ Request Failed</strong><br>' +
                        'AJAX Error: ' + error
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).text('Import Test Tire');
                }
            });
        });
        */

        /**
         * Bulk Import Products - Processes ALL pages for selected brand
         * Fetches 500 products per request and processes in batches
         */
        var bulkImportState = {
            currentPage: 1,
            totalImported: 0,
            totalUpdated: 0,
            totalSkipped: 0,
            isRunning: false,
            importLimit: 0,
            productIds: [],
            accumulatedProducts: [],
            imageUrls: null,
            batchSize: 500,
            startTime: null,
            totalPages: 0,
            hasMore: true,
            consecutiveErrors: 0,
            maxConsecutiveErrors: 3
        };

        $('#asm-bulk-import').on('click', function() {
            var $button = $(this);
            var $progress = $('#asm-bulk-progress');
            var $result = $('#asm-bulk-result');
            var pageSize = 500; // Always use max API limit for speed
            var brand = $('#asm_bulk_brand').val().trim();
            var importLimit = parseInt($('#asm_bulk_import_limit').val()) || 0;

            // Reset state - ensure all values are initialized as numbers
            bulkImportState = {
                currentPage: 1,
                totalImported: 0,
                totalUpdated: 0,
                totalSkipped: 0,
                isRunning: true,
                importLimit: importLimit || 0,
                productIds: [],
                accumulatedProducts: [],
                imageUrls: null,
                batchSize: 500,
                startTime: Date.now(),
                totalPages: 0,
                hasMore: true,
                consecutiveErrors: 0,
                maxConsecutiveErrors: 3
            };
            
            // Ensure numeric values
            bulkImportState.totalImported = parseInt(bulkImportState.totalImported || 0, 10);
            bulkImportState.totalUpdated = parseInt(bulkImportState.totalUpdated || 0, 10);
            bulkImportState.totalSkipped = parseInt(bulkImportState.totalSkipped || 0, 10);

            // Show progress, hide result
            $progress.show();
            $result.html('');
            $button.prop('disabled', true).text('Importing...');

            // Start optimized import
            optimizedBulkImport(pageSize, brand, $button, $progress, $result);
        });

        /**
         * Optimized Bulk Import - Fetches 500 products and processes in batches
         * Designed for 80,000+ products in 1-2 hours
         */
        function optimizedBulkImport(pageSize, brand, $button, $progress, $result) {
            var $progressText = $progress.find('.asm-progress-text');

            var imported = parseInt(bulkImportState.totalImported || 0, 10);
            var updated = parseInt(bulkImportState.totalUpdated || 0, 10);
            var skipped = parseInt(bulkImportState.totalSkipped || 0, 10);
            var elapsed = Math.floor((Date.now() - bulkImportState.startTime) / 1000);
            var rate = bulkImportState.currentPage > 1 ? Math.round((imported + updated) / elapsed) : 0;

            $progressText.html(
                '<strong>Fetching Page ' + bulkImportState.currentPage + ' (500 products)...</strong><br>' +
                (brand ? '<strong>Brand:</strong> ' + brand + '<br>' : '') +
                'Total: Created ' + imported + ' | Updated ' + updated + ' | Skipped ' + skipped + '<br>' +
                'Rate: ~' + rate + ' products/second | Elapsed: ' + formatTime(elapsed) + '<br>' +
                '<em>Processing all pages for ' + (brand || 'all brands') + '...</em>'
            );

            // Fetch 500 products from API
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_bulk_import',
                    nonce: asmData.nonce,
                    page_number: bulkImportState.currentPage,
                    page_size: pageSize, // 500
                    brand: brand
                },
                timeout: 30000, // 30 second timeout for API fetch
                success: function(response) {
                    // Reset consecutive errors on success
                    bulkImportState.consecutiveErrors = 0;
                    
                    if (response.success && response.data && response.data.products) {
                        // Store image URLs
                        if (!bulkImportState.imageUrls && response.data.image_urls) {
                            bulkImportState.imageUrls = response.data.image_urls;
                        }

                        // Get products from response
                        var products = response.data.products;
                        bulkImportState.hasMore = response.data.has_more === true;

                        // Immediately send batch for insertion
                        if (products.length > 0) {
                            insertOptimizedBatch(products, bulkImportState.hasMore, pageSize, brand, $button, $progress, $result);
                        } else if (!bulkImportState.hasMore) {
                            // No products and no more pages - complete
                            showBulkImportComplete($button, $progress, $result, false);
                        } else {
                            // Empty page but has more - continue to next page
                            bulkImportState.currentPage++;
                            setTimeout(function() {
                                optimizedBulkImport(pageSize, brand, $button, $progress, $result);
                            }, 100);
                        }
                    } else {
                        // Handle fetch error - retry up to maxConsecutiveErrors times
                        bulkImportState.consecutiveErrors++;
                        
                        if (bulkImportState.consecutiveErrors >= bulkImportState.maxConsecutiveErrors) {
                            $result.removeClass('loading success').addClass('error');
                            $result.html(
                                '<strong>✗ Fetch Failed</strong><br>' +
                                'Failed ' + bulkImportState.consecutiveErrors + ' times in a row.<br>' +
                                (response.data || 'Unknown error occurred') + '<br>' +
                                '<strong>Stopped at page ' + bulkImportState.currentPage + '</strong>'
                            );
                            $button.prop('disabled', false).text('Start Bulk Import');
                            bulkImportState.isRunning = false;
                        } else {
                            // Retry current page after short delay
                            setTimeout(function() {
                                optimizedBulkImport(pageSize, brand, $button, $progress, $result);
                            }, 2000);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    // Handle network error - retry up to maxConsecutiveErrors times
                    bulkImportState.consecutiveErrors++;
                    
                    if (bulkImportState.consecutiveErrors >= bulkImportState.maxConsecutiveErrors) {
                        $result.removeClass('loading success').addClass('error');
                        $result.html(
                            '<strong>✗ Request Failed</strong><br>' +
                            'Network error occurred ' + bulkImportState.consecutiveErrors + ' times.<br>' +
                            'AJAX Error: ' + error + '<br>' +
                            'Status: ' + status + '<br>' +
                            '<strong>Stopped at page ' + bulkImportState.currentPage + '</strong>'
                        );
                        $button.prop('disabled', false).text('Start Bulk Import');
                        bulkImportState.isRunning = false;
                    } else {
                        // Retry current page after short delay
                        var $progressText = $progress.find('.asm-progress-text');
                        $progressText.html(
                            '<strong>⚠️ Error fetching page ' + bulkImportState.currentPage + '</strong><br>' +
                            'Retrying... (Attempt ' + (bulkImportState.consecutiveErrors + 1) + ' of ' + bulkImportState.maxConsecutiveErrors + ')<br>' +
                            'Error: ' + error
                        );
                        setTimeout(function() {
                            optimizedBulkImport(pageSize, brand, $button, $progress, $result);
                        }, 2000);
                    }
                }
            });
        }

        /**
         * Insert optimized batch of products (500 at a time)
         */
        function insertOptimizedBatch(products, hasMore, pageSize, brand, $button, $progress, $result) {
            var $progressText = $progress.find('.asm-progress-text');
            var batchCount = products.length;

            $progressText.html(
                '<strong>Inserting Batch (Page ' + bulkImportState.currentPage + ')...</strong><br>' +
                'Batch Size: ' + batchCount + ' products<br>' +
                'Total: Created ' + bulkImportState.totalImported + ' | Updated ' + bulkImportState.totalUpdated + ' | Skipped ' + bulkImportState.totalSkipped + '<br>' +
                '<em>Processing at high speed...</em>'
            );

            // Send batch insert request with optimized endpoint
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_batch_insert_optimized',
                    nonce: asmData.nonce,
                    products: JSON.stringify(products),
                    image_urls: bulkImportState.imageUrls,
                    type: 'wheel'
                },
                dataType: 'json',
                timeout: 300000, // 5 minutes timeout for large batches
                success: function(response) {
                    console.log('Batch insert response:', response);
                    console.log('Response data:', response.data);
                    
                    if (!response || !response.success) {
                        console.error('Batch insert failed - response:', response);
                        $result.removeClass('loading success').addClass('error');
                        $result.html('<strong>✗ Batch Insert Failed</strong><br>' + (response && response.data ? JSON.stringify(response.data) : 'Unknown error'));
                        $button.prop('disabled', false).text('Start Bulk Import');
                        bulkImportState.isRunning = false;
                        return;
                    }
                    
                    if (!response.data) {
                        console.error('Batch insert failed - no data in response:', response);
                        $result.removeClass('loading success').addClass('error');
                        $result.html('<strong>✗ Batch Insert Failed</strong><br>No data in response');
                        $button.prop('disabled', false).text('Start Bulk Import');
                        bulkImportState.isRunning = false;
                        return;
                    }
                    
                    // Parse response values - ensure they're numbers
                    var batchImported = parseInt(response.data.imported || 0, 10);
                    var batchUpdated = parseInt(response.data.updated || 0, 10);
                    var batchSkipped = parseInt(response.data.skipped || 0, 10);
                    var duration = parseFloat(response.data.duration_seconds || 0);
                    var rate = parseFloat(response.data.products_per_second || 0);
                    
                    // Validate parsed values - ensure no NaN
                    if (isNaN(batchImported) || !isFinite(batchImported)) {
                        console.warn('batchImported is NaN, using 0');
                        batchImported = 0;
                    }
                    if (isNaN(batchUpdated) || !isFinite(batchUpdated)) {
                        console.warn('batchUpdated is NaN, using 0');
                        batchUpdated = 0;
                    }
                    if (isNaN(batchSkipped) || !isFinite(batchSkipped)) {
                        console.warn('batchSkipped is NaN, using 0');
                        batchSkipped = 0;
                    }
                    
                    console.log('Batch stats - Imported:', batchImported, 'Updated:', batchUpdated, 'Skipped:', batchSkipped);
                    
                    // Log errors if any
                    if (response.data.errors && response.data.errors.length > 0) {
                        console.error('Batch insert errors:', response.data.errors);
                    }

                    // Update totals - ensure they're numbers
                    var currentImported = parseInt(bulkImportState.totalImported || 0, 10) || 0;
                    var currentUpdated = parseInt(bulkImportState.totalUpdated || 0, 10) || 0;
                    var currentSkipped = parseInt(bulkImportState.totalSkipped || 0, 10) || 0;
                    
                    bulkImportState.totalImported = currentImported + batchImported;
                    bulkImportState.totalUpdated = currentUpdated + batchUpdated;
                    bulkImportState.totalSkipped = currentSkipped + batchSkipped;
                    
                    console.log('Updated totals - Imported:', bulkImportState.totalImported, 'Updated:', bulkImportState.totalUpdated, 'Skipped:', bulkImportState.totalSkipped);

                    // Collect product IDs
                    if (response.data.product_ids && Array.isArray(response.data.product_ids)) {
                        if (!Array.isArray(bulkImportState.productIds)) {
                            bulkImportState.productIds = [];
                        }
                        bulkImportState.productIds = bulkImportState.productIds.concat(response.data.product_ids);
                    }

                    var totalProcessed = bulkImportState.totalImported + bulkImportState.totalUpdated;
                    var limitReached = bulkImportState.importLimit > 0 && totalProcessed >= bulkImportState.importLimit;
                    var elapsed = Math.floor((Date.now() - bulkImportState.startTime) / 1000);
                    var overallRate = elapsed > 0 ? Math.round((bulkImportState.totalImported + bulkImportState.totalUpdated) / elapsed) : 0;

                    // Update progress
                    $progressText.html(
                        '<strong>✓ Batch ' + bulkImportState.currentPage + ' Complete</strong><br>' +
                        'Batch: Created ' + batchImported + ' | Updated ' + batchUpdated + ' | Skipped ' + batchSkipped + '<br>' +
                        'Batch Speed: ' + rate + ' products/sec | Duration: ' + duration + 's<br>' +
                        'Total: Created ' + bulkImportState.totalImported + ' | Updated ' + bulkImportState.totalUpdated + ' | Skipped ' + bulkImportState.totalSkipped + '<br>' +
                        'Overall Rate: ~' + overallRate + ' products/sec | Elapsed: ' + formatTime(elapsed)
                    );

                    // Check if we should continue
                    if (limitReached) {
                        // Import limit reached - stop
                        showBulkImportComplete($button, $progress, $result, true);
                    } else if (!hasMore) {
                        // No more pages - complete
                        showBulkImportComplete($button, $progress, $result, false);
                    } else {
                        // Continue to next page - process ALL pages for brand
                        bulkImportState.currentPage++;
                        setTimeout(function() {
                            optimizedBulkImport(pageSize, brand, $button, $progress, $result);
                        }, 50); // Minimal delay for next batch
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error');
                    $result.html(
                        '<strong>✗ Batch Insert Failed</strong><br>' +
                        'AJAX Error: ' + error + '<br>' +
                        'Status: ' + status + '<br>' +
                        'HTTP Status: ' + xhr.status
                    );
                    $button.prop('disabled', false).text('Start Bulk Import');
                    bulkImportState.isRunning = false;
                }
            });
        }

        /**
         * Format time in seconds to readable format
         */
        function formatTime(seconds) {
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            var secs = seconds % 60;
            
            if (hours > 0) {
                return hours + 'h ' + minutes + 'm ' + secs + 's';
            } else if (minutes > 0) {
                return minutes + 'm ' + secs + 's';
            } else {
                return secs + 's';
            }
        }

        function showBulkImportComplete($button, $progress, $result, limitReached) {
            $progress.hide();
            $result.removeClass('loading error').addClass('success');
            
            // Ensure values are numbers - handle NaN properly
            var rawImported = bulkImportState.totalImported;
            var rawUpdated = bulkImportState.totalUpdated;
            var rawSkipped = bulkImportState.totalSkipped;
            var rawPages = bulkImportState.currentPage;
            
            // Parse with fallback and validation
            var imported = parseInt(rawImported || 0, 10);
            var updated = parseInt(rawUpdated || 0, 10);
            var skipped = parseInt(rawSkipped || 0, 10);
            var pagesImported = parseInt(rawPages || 0, 10);
            
            // Final validation - ensure no NaN
            if (isNaN(imported) || !isFinite(imported)) imported = 0;
            if (isNaN(updated) || !isFinite(updated)) updated = 0;
            if (isNaN(skipped) || !isFinite(skipped)) skipped = 0;
            if (isNaN(pagesImported) || !isFinite(pagesImported)) pagesImported = 0;
            
            var totalProcessed = imported + updated + skipped;
            var elapsed = bulkImportState.startTime ? Math.floor((Date.now() - bulkImportState.startTime) / 1000) : 0;
            var avgRate = elapsed > 0 && (imported + updated) > 0 ? Math.round((imported + updated) / elapsed) : 0;
            
            var message = '<strong>✓ Bulk Import Complete!</strong><br><br>';
            if (limitReached) {
                message += '<strong style="color: #ff6600;">Import limit reached!</strong><br><br>';
            } else {
                message += '<strong style="color: #28a745;">All pages processed successfully!</strong><br><br>';
            }
            message += '<strong>Summary:</strong><br>' +
                'Products Created: ' + imported + '<br>' +
                'Products Updated: ' + updated + '<br>' +
                'Products Skipped: ' + skipped + '<br>' +
                'Total Processed: ' + totalProcessed + '<br>' +
                'Pages Processed: ' + pagesImported + '<br>' +
                'Total Time: ' + formatTime(elapsed) + '<br>' +
                'Average Rate: ~' + avgRate + ' products/second<br>';

            // Add product IDs with links
            if (bulkImportState.productIds && Array.isArray(bulkImportState.productIds) && bulkImportState.productIds.length > 0) {
                message += '<br><strong>Product IDs:</strong> ';
                var productLinks = bulkImportState.productIds.slice(0, 50).map(function(id) {
                    return '<a href="post.php?post=' + id + '&action=edit" target="_blank">' + id + '</a>';
                });
                message += productLinks.join(', ');
                if (bulkImportState.productIds.length > 50) {
                    message += ' <em>(showing first 50 of ' + bulkImportState.productIds.length + ')</em>';
                }
            }

            $result.html(message);
            $button.prop('disabled', false).text('Start Bulk Import');
            bulkImportState.isRunning = false;
        }

        // Old batch insertion code removed - using optimized batch insert instead

        // Duplicate functions removed - using the ones defined earlier

        /**
         * Test API Connection
         */
        $('#asm-test-api').on('click', function() {
            var $button = $(this);
            var $result = $('#asm-test-result');
            var apiKey = $('#asm_api_key').val();

            if (!apiKey) {
                $result.removeClass('success loading').addClass('error');
                $result.html('<strong>Error:</strong> Please enter an API key first.');
                return;
            }

            // Show loading state
            $button.prop('disabled', true).text('Testing...');
            $result.removeClass('success error').addClass('loading');
            $result.html('Connecting to AutoSync API...');

            // Make AJAX request
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_test_api',
                    nonce: asmData.nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('loading error').addClass('success');
                        $result.html(
                            '<strong>✓ Success!</strong><br>' +
                            response.data.message
                        );
                    } else {
                        $result.removeClass('loading success').addClass('error');
                        $result.html(
                            '<strong>✗ Connection Failed</strong><br>' +
                            (response.data || 'Unknown error occurred')
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error');
                    $result.html(
                        '<strong>✗ Request Failed</strong><br>' +
                        'AJAX Error: ' + error
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).text('Test Connection');
                }
            });
        });

        /**
         * Test Slack Notification
         */
        /*
        $('#asm-test-slack').on('click', function() {
            var $button = $(this);
            var $result = $('#asm-test-slack-result');
            var webhookUrl = $('#asm_slack_webhook_url').val();

            if (!webhookUrl) {
                $result.removeClass('success loading').addClass('error');
                $result.html('<strong>Error:</strong> Please enter a Slack webhook URL first.');
                return;
            }

            // Show loading state
            $button.prop('disabled', true).text('Sending...');
            $result.removeClass('success error').addClass('loading');
            $result.html('Sending test notification to Slack...');

            // Make AJAX request
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_test_slack',
                    nonce: asmData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('loading error').addClass('success');
                        $result.html(
                            '<strong>✓ Success!</strong><br>' +
                            response.data.message + '<br>' +
                            '<small>Check your Slack channel to verify the notification was received.</small>'
                        );
                    } else {
                        $result.removeClass('loading success').addClass('error');
                        var errorMsg = response.data && response.data.message 
                            ? response.data.message 
                            : 'Failed to send test notification';
                        $result.html('<strong>✗ Error:</strong> ' + errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error');
                    $result.html('<strong>✗ Error:</strong> ' + error + ' (Status: ' + status + ')');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Send Test Notification');
                }
            });
        });
        */

        /**
         * Bulk Import Tires - Processes ALL pages for selected brand
         */
        var bulkTireImportState = {
            currentPage: 1,
            totalImported: 0,
            totalUpdated: 0,
            totalSkipped: 0,
            isRunning: false,
            importLimit: 0,
            productIds: [],
            accumulatedProducts: [],
            imageUrls: null,
            batchSize: 500,
            startTime: null,
            totalPages: 0,
            hasMore: true,
            consecutiveErrors: 0,
            maxConsecutiveErrors: 3
        };

        $('#asm-bulk-import-tires').on('click', function() {
            var $button = $(this);
            var $progress = $('#asm-bulk-tire-progress');
            var $result = $('#asm-bulk-tire-result');
            var pageSize = parseInt($('#asm_bulk_tire_page_size').val());
            var brand = $('#asm_bulk_tire_brand').val().trim();
            var importLimit = parseInt($('#asm_bulk_tire_import_limit').val()) || 0;

            // Reset state
            bulkTireImportState = {
                currentPage: 1,
                totalImported: 0,
                totalUpdated: 0,
                totalSkipped: 0,
                isRunning: true,
                importLimit: importLimit,
                productIds: [],
                accumulatedProducts: [],
                imageUrls: null,
                batchSize: 500,
                startTime: Date.now(),
                totalPages: 0,
                hasMore: true,
                consecutiveErrors: 0,
                maxConsecutiveErrors: 3
            };

            // Show progress, hide result
            $progress.show();
            $result.html('');
            $button.prop('disabled', true).text('Importing Tires...');

            // Start optimized import
            optimizedBulkImportTires(pageSize, brand, $button, $progress, $result);
        });

        /**
         * Optimized Bulk Import Tires - Fetches 500 products and processes in batches
         */
        function optimizedBulkImportTires(pageSize, brand, $button, $progress, $result) {
            var $progressText = $progress.find('.asm-progress-text');

            var imported = parseInt(bulkTireImportState.totalImported || 0, 10);
            var updated = parseInt(bulkTireImportState.totalUpdated || 0, 10);
            var skipped = parseInt(bulkTireImportState.totalSkipped || 0, 10);
            var elapsed = Math.floor((Date.now() - bulkTireImportState.startTime) / 1000);
            var rate = bulkTireImportState.currentPage > 1 ? Math.round((imported + updated) / elapsed) : 0;

            $progressText.html(
                '<strong>Fetching Tire Page ' + bulkTireImportState.currentPage + ' (500 products)...</strong><br>' +
                (brand ? '<strong>Brand:</strong> ' + brand + '<br>' : '') +
                'Total: Created ' + imported + ' | Updated ' + updated + ' | Skipped ' + skipped + '<br>' +
                'Rate: ~' + rate + ' products/second | Elapsed: ' + formatTime(elapsed) + '<br>' +
                '<em>Processing all pages for ' + (brand || 'all brands') + '...</em>'
            );

            // Fetch 500 products from API
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_bulk_import_tires',
                    nonce: asmData.nonce,
                    page_number: bulkTireImportState.currentPage,
                    page_size: pageSize, // 500
                    brand: brand
                },
                timeout: 30000,
                success: function(response) {
                    // Reset consecutive errors on success
                    bulkTireImportState.consecutiveErrors = 0;
                    
                    if (response.success && response.data && response.data.products) {
                        // Store image URLs
                        if (!bulkTireImportState.imageUrls && response.data.image_urls) {
                            bulkTireImportState.imageUrls = response.data.image_urls;
                        }

                        // Get products from response
                        var products = response.data.products;
                        bulkTireImportState.hasMore = response.data.has_more === true;

                        // Immediately send batch for insertion
                        if (products.length > 0) {
                            insertOptimizedTireBatch(products, bulkTireImportState.hasMore, pageSize, brand, $button, $progress, $result);
                        } else if (!bulkTireImportState.hasMore) {
                            // No products and no more pages - complete
                            showBulkTireImportComplete($button, $progress, $result, false);
                        } else {
                            // Empty page but has more - continue to next page
                            bulkTireImportState.currentPage++;
                            setTimeout(function() {
                                optimizedBulkImportTires(pageSize, brand, $button, $progress, $result);
                            }, 100);
                        }
                    } else {
                        // Handle fetch error - retry up to maxConsecutiveErrors times
                        bulkTireImportState.consecutiveErrors++;
                        
                        if (bulkTireImportState.consecutiveErrors >= bulkTireImportState.maxConsecutiveErrors) {
                            $result.removeClass('loading success').addClass('error');
                            $result.html(
                                '<strong>✗ Fetch Failed</strong><br>' +
                                'Failed ' + bulkTireImportState.consecutiveErrors + ' times in a row.<br>' +
                                (response.data || 'Unknown error occurred') + '<br>' +
                                '<strong>Stopped at page ' + bulkTireImportState.currentPage + '</strong>'
                            );
                            $button.prop('disabled', false).text('Start Tire Bulk Import');
                            bulkTireImportState.isRunning = false;
                        } else {
                            // Retry current page after short delay
                            setTimeout(function() {
                                optimizedBulkImportTires(pageSize, brand, $button, $progress, $result);
                            }, 2000);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    // Handle network error - retry up to maxConsecutiveErrors times
                    bulkTireImportState.consecutiveErrors++;
                    
                    if (bulkTireImportState.consecutiveErrors >= bulkTireImportState.maxConsecutiveErrors) {
                        $result.removeClass('loading success').addClass('error');
                        $result.html(
                            '<strong>✗ Request Failed</strong><br>' +
                            'Network error occurred ' + bulkTireImportState.consecutiveErrors + ' times.<br>' +
                            'AJAX Error: ' + error + '<br>' +
                            'Status: ' + status + '<br>' +
                            '<strong>Stopped at page ' + bulkTireImportState.currentPage + '</strong>'
                        );
                        $button.prop('disabled', false).text('Start Tire Bulk Import');
                        bulkTireImportState.isRunning = false;
                    } else {
                        // Retry current page after short delay
                        var $progressText = $progress.find('.asm-progress-text');
                        $progressText.html(
                            '<strong>⚠️ Error fetching tire page ' + bulkTireImportState.currentPage + '</strong><br>' +
                            'Retrying... (Attempt ' + (bulkTireImportState.consecutiveErrors + 1) + ' of ' + bulkTireImportState.maxConsecutiveErrors + ')<br>' +
                            'Error: ' + error
                        );
                        setTimeout(function() {
                            optimizedBulkImportTires(pageSize, brand, $button, $progress, $result);
                        }, 2000);
                    }
                }
            });
        }

        /**
         * Insert optimized batch of tires (500 at a time)
         */
        function insertOptimizedTireBatch(products, hasMore, pageSize, brand, $button, $progress, $result) {
            var $progressText = $progress.find('.asm-progress-text');
            var batchCount = products.length;

            $progressText.html(
                '<strong>Inserting Tire Batch (Page ' + bulkTireImportState.currentPage + ')...</strong><br>' +
                'Batch Size: ' + batchCount + ' tires<br>' +
                'Total: Created ' + bulkTireImportState.totalImported + ' | Updated ' + bulkTireImportState.totalUpdated + ' | Skipped ' + bulkTireImportState.totalSkipped + '<br>' +
                '<em>Processing at high speed...</em>'
            );

            // Send batch insert request with optimized endpoint
            $.ajax({
                url: asmData.ajax_url,
                type: 'POST',
                data: {
                    action: 'asm_batch_insert_optimized',
                    nonce: asmData.nonce,
                    products: JSON.stringify(products),
                    image_urls: bulkTireImportState.imageUrls,
                    type: 'tire'
                },
                dataType: 'json',
                timeout: 300000,
                success: function(response) {
                    if (response.success && response.data) {
                        // Parse response values
                        var batchImported = parseInt(response.data.imported || 0, 10) || 0;
                        var batchUpdated = parseInt(response.data.updated || 0, 10) || 0;
                        var batchSkipped = parseInt(response.data.skipped || 0, 10) || 0;
                        var duration = response.data.duration_seconds || 0;
                        var rate = response.data.products_per_second || 0;

                        // Update totals
                        bulkTireImportState.totalImported += batchImported;
                        bulkTireImportState.totalUpdated += batchUpdated;
                        bulkTireImportState.totalSkipped += batchSkipped;

                        // Collect product IDs
                        if (response.data.product_ids && Array.isArray(response.data.product_ids)) {
                            bulkTireImportState.productIds = bulkTireImportState.productIds.concat(response.data.product_ids);
                        }

                        var totalProcessed = bulkTireImportState.totalImported + bulkTireImportState.totalUpdated;
                        var limitReached = bulkTireImportState.importLimit > 0 && totalProcessed >= bulkTireImportState.importLimit;
                        var elapsed = Math.floor((Date.now() - bulkTireImportState.startTime) / 1000);
                        var overallRate = elapsed > 0 ? Math.round((bulkTireImportState.totalImported + bulkTireImportState.totalUpdated) / elapsed) : 0;

                        // Update progress
                        $progressText.html(
                            '<strong>✓ Tire Batch ' + bulkTireImportState.currentPage + ' Complete</strong><br>' +
                            'Batch: Created ' + batchImported + ' | Updated ' + batchUpdated + ' | Skipped ' + batchSkipped + '<br>' +
                            'Batch Speed: ' + rate + ' products/sec | Duration: ' + duration + 's<br>' +
                            'Total: Created ' + bulkTireImportState.totalImported + ' | Updated ' + bulkTireImportState.totalUpdated + ' | Skipped ' + bulkTireImportState.totalSkipped + '<br>' +
                            'Overall Rate: ~' + overallRate + ' products/sec | Elapsed: ' + formatTime(elapsed)
                        );

                        // Check if we should continue
                        if (limitReached) {
                            // Import limit reached - stop
                            showBulkTireImportComplete($button, $progress, $result, true);
                        } else if (!hasMore) {
                            // No more pages - complete
                            showBulkTireImportComplete($button, $progress, $result, false);
                        } else {
                            // Continue to next page - process ALL pages for brand
                            bulkTireImportState.currentPage++;
                            setTimeout(function() {
                                optimizedBulkImportTires(pageSize, brand, $button, $progress, $result);
                            }, 50);
                        }
                    } else {
                        $result.removeClass('loading success').addClass('error');
                        $result.html(
                            '<strong>✗ Batch Insert Failed</strong><br>' +
                            (response.data || 'Unknown error occurred')
                        );
                        $button.prop('disabled', false).text('Start Tire Bulk Import');
                        bulkTireImportState.isRunning = false;
                    }
                },
                error: function(xhr, status, error) {
                    $result.removeClass('loading success').addClass('error');
                    $result.html(
                        '<strong>✗ Batch Insert Failed</strong><br>' +
                        'AJAX Error: ' + error
                    );
                    $button.prop('disabled', false).text('Start Tire Bulk Import');
                    bulkTireImportState.isRunning = false;
                }
            });
        }

        // Old insertTireBatch function removed - using insertOptimizedTireBatch instead

        function showBulkTireImportComplete($button, $progress, $result, limitReached) {
            $progress.hide();
            $result.removeClass('loading error').addClass('success');
            
            // Ensure values are numbers, not NaN
            var imported = parseInt(bulkTireImportState.totalImported || 0, 10);
            var updated = parseInt(bulkTireImportState.totalUpdated || 0, 10);
            var skipped = parseInt(bulkTireImportState.totalSkipped || 0, 10);
            var totalProcessed = imported + updated + skipped;
            var pagesImported = parseInt(bulkTireImportState.currentPage || 0, 10);
            
            var message = '<strong>✓ Tire Bulk Import Complete!</strong><br><br>';
            if (limitReached) {
                message += '<strong style="color: #ff6600;">Import limit reached!</strong><br><br>';
            } else {
                message += '<strong style="color: #28a745;">All pages processed successfully!</strong><br><br>';
            }
            message += '<strong>Summary:</strong><br>' +
                'Tires Created: ' + imported + '<br>' +
                'Tires Updated: ' + updated + '<br>' +
                'Tires Skipped: ' + skipped + '<br>' +
                'Total Processed: ' + totalProcessed + '<br>' +
                'Pages Processed: ' + pagesImported + '<br>';

            // Add product IDs with links
            if (bulkTireImportState.productIds && bulkTireImportState.productIds.length > 0) {
                message += '<strong>Product IDs:</strong> ';
                var productLinks = bulkTireImportState.productIds.slice(0, 50).map(function(id) {
                    return '<a href="post.php?post=' + id + '&action=edit" target="_blank">' + id + '</a>';
                });
                message += productLinks.join(', ');
                if (bulkTireImportState.productIds.length > 50) {
                    message += ' <em>(showing first 50 of ' + bulkTireImportState.productIds.length + ')</em>';
                }
            }

            $result.html(message);
            $button.prop('disabled', false).text('Start Tire Bulk Import');
            bulkTireImportState.isRunning = false;
        }

    });

    /**
     * Helper Tab - Delete Products by Brand
     */
    
    
    // Select All checkboxes for tires (using event delegation)
    $(document).on('change', '#asm-select-all-tires', function() {
        var isChecked = $(this).is(':checked');
        $('.asm-brand-checkbox-delete[data-product-type="tire"]').prop('checked', isChecked).trigger('change');
        // updateDeleteButtonState is called by the individual checkbox change handler
    });

    // Select All checkboxes for wheels (using event delegation)
    $(document).on('change', '#asm-select-all-wheels', function() {
        var isChecked = $(this).is(':checked');
        $('.asm-brand-checkbox-delete[data-product-type="wheel"]').prop('checked', isChecked).trigger('change');
        // updateDeleteButtonState is called by the individual checkbox change handler
    });

    // Update delete button state based on checkbox selection
    function updateDeleteButtonState(productType) {
        var $checkboxes = $('.asm-brand-checkbox-delete[data-product-type="' + productType + '"]:checked');
        var buttonId = productType === 'tire' ? 'asm-delete-selected-tires' : 'asm-delete-selected-wheels';
        var $button = $('#' + buttonId);
        
        // Ensure button exists - try multiple ways to find it
        if ($button.length === 0) {
            // Try finding it in the helper tab specifically
            $button = $('#helper').find('#' + buttonId);
        }
        
        if ($button.length === 0) {
            console.warn('Button not found: #' + buttonId + ' for product type: ' + productType);
            return;
        }
        
        var checkedCount = $checkboxes.length;
        if (checkedCount > 0) {
            // Enable button - use multiple methods to ensure it works
            $button.removeAttr('disabled');
            $button.prop('disabled', false);
            $button.removeClass('disabled');
            $button.text('Delete Selected ' + (productType === 'tire' ? 'Tire' : 'Wheel') + ' Brands (' + checkedCount + ')');
        } else {
            // Disable button
            $button.attr('disabled', 'disabled');
            $button.prop('disabled', true);
            $button.addClass('disabled');
            $button.text('Delete Selected ' + (productType === 'tire' ? 'Tire' : 'Wheel') + ' Brands');
        }
    }
    
    // Initialize button states when Helper tab is shown
    $(document).on('click', '.nav-tab[href="#helper"]', function() {
        // Small delay to ensure DOM is ready
        setTimeout(function() {
            updateDeleteButtonState('tire');
            updateDeleteButtonState('wheel');
        }, 200);
    });
    
    // Also initialize on document ready if Helper tab is already active
    setTimeout(function() {
        if ($('#helper').hasClass('active') || $('#helper').is(':visible')) {
            updateDeleteButtonState('tire');
            updateDeleteButtonState('wheel');
        }
    }, 500);

    // Checkbox change handler
    $(document).on('change', '.asm-brand-checkbox-delete', function() {
        var $checkbox = $(this);
        var productType = $checkbox.data('product-type');
        
        // Ensure productType is valid
        if (!productType) {
            console.warn('Checkbox missing data-product-type attribute');
            return;
        }
        
        // Immediately update button state
        updateDeleteButtonState(productType);
        
        // Update select all checkbox state
        var $selectAll = productType === 'tire' ? $('#asm-select-all-tires') : $('#asm-select-all-wheels');
        if ($selectAll.length > 0) {
            var $allCheckboxes = $('.asm-brand-checkbox-delete[data-product-type="' + productType + '"]');
            var $checkedCheckboxes = $allCheckboxes.filter(':checked');
            $selectAll.prop('checked', $allCheckboxes.length === $checkedCheckboxes.length && $allCheckboxes.length > 0);
        }
    });

    // Delete single brand
    $(document).on('click', '.asm-delete-single-brand', function(e) {
        e.preventDefault();
        var $button = $(this);
        var brandName = $button.data('brand-name');
        var productType = $button.data('product-type');

        if (!confirm('Are you sure you want to delete all products for brand "' + brandName + '" (' + productType + ')?\n\nThis action cannot be undone!')) {
            return;
        }

        deleteBrandProducts(brandName, productType, $button.closest('tr'));
    });

    // Delete selected brands (using event delegation)
    $(document).on('click', '#asm-delete-selected-tires', function(e) {
        e.preventDefault();
        var $checkboxes = $('.asm-brand-checkbox-delete[data-product-type="tire"]:checked');
        if ($checkboxes.length === 0) {
            return;
        }

        var brands = [];
        $checkboxes.each(function() {
            brands.push($(this).data('brand-name'));
        });

        if (!confirm('Are you sure you want to delete products for ' + brands.length + ' tire brand(s)?\n\nThis action cannot be undone!')) {
            return;
        }

        deleteMultipleBrands(brands, 'tire');
    });

    $(document).on('click', '#asm-delete-selected-wheels', function(e) {
        e.preventDefault();
        var $checkboxes = $('.asm-brand-checkbox-delete[data-product-type="wheel"]:checked');
        if ($checkboxes.length === 0) {
            return;
        }

        var brands = [];
        $checkboxes.each(function() {
            brands.push($(this).data('brand-name'));
        });

        if (!confirm('Are you sure you want to delete products for ' + brands.length + ' wheel brand(s)?\n\nThis action cannot be undone!')) {
            return;
        }

        deleteMultipleBrands(brands, 'wheel');
    });

    // Delete products for a single brand
    function deleteBrandProducts(brandName, productType, $row) {
        var $result = productType === 'tire' ? $('#asm-delete-tires-result') : $('#asm-delete-wheels-result');
        var $button = $row.find('.asm-delete-single-brand');
        var $checkbox = $row.find('.asm-brand-checkbox-delete');
        
        $button.prop('disabled', true).text('Deleting...');
        $checkbox.prop('disabled', true);
        
        var totalDeleted = 0;
        var updateProgress = function(deletedInBatch, remaining, total) {
            totalDeleted += (deletedInBatch || 0);
            
            var progressText = 'Deleting products for brand: <strong>' + brandName + '</strong>';
            progressText += ' (<strong>' + totalDeleted + '</strong> deleted';
            if (remaining > 0) {
                progressText += ', ' + remaining + ' remaining';
            }
            progressText += ')...';
            
            $result.html('<div class="notice notice-info"><p><span class="spinner is-active" style="float: none; margin: 0 5px;"></span> ' + progressText + '</p></div>');
        };
        
        $result.html('<div class="notice notice-info"><p><span class="spinner is-active" style="float: none; margin: 0 5px;"></span> Starting deletion for brand: <strong>' + brandName + '</strong>...</p></div>');

        deleteProductsBatch(brandName, productType, 0, function(result, deletedInBatch, remaining, total) {
            // Handle progress updates (when result is null but progress info is provided)
            if (result === null && deletedInBatch !== undefined) {
                updateProgress(deletedInBatch, remaining, total);
                return; // Continue processing, don't show final message yet
            }
            
            // Handle final result
            if (result && result.success) {
                // Use accumulated totalDeleted count instead of server message
                var finalMessage = 'Successfully deleted ' + totalDeleted + ' product' + (totalDeleted !== 1 ? 's' : '') + ' for brand: ' + brandName;
                $result.html('<div class="notice notice-success"><p><strong>Success:</strong> ' + finalMessage + '</p></div>');
                $button.prop('disabled', false).text('Delete');
                $checkbox.prop('disabled', false).prop('checked', false);
            } else if (result && !result.success) {
                $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + (result.message || 'Failed to delete products') + '</p></div>');
                $button.prop('disabled', false).text('Delete');
                $checkbox.prop('disabled', false);
            }
        });
    }

    // Delete products for multiple brands
    function deleteMultipleBrands(brands, productType) {
        var $result = productType === 'tire' ? $('#asm-delete-tires-result') : $('#asm-delete-wheels-result');
        var $button = productType === 'tire' ? $('#asm-delete-selected-tires') : $('#asm-delete-selected-wheels');
        var $checkboxes = $('.asm-brand-checkbox-delete[data-product-type="' + productType + '"]:checked');
        
        $button.prop('disabled', true).text('Deleting...');
        $checkboxes.prop('disabled', true);
        
        var totalDeleted = 0;
        var totalFailed = 0;
        var currentIndex = 0;

        $result.html('<div class="notice notice-info"><p><span class="spinner is-active" style="float: none; margin: 0 5px;"></span> Deleting products for ' + brands.length + ' brand(s)...</p></div>');

        function deleteNextBrand() {
            if (currentIndex >= brands.length) {
                // All brands processed
                var message = 'Deleted products for ' + totalDeleted + ' brand(s).';
                if (totalFailed > 0) {
                    message += ' Failed: ' + totalFailed + ' brand(s).';
                }
                $result.html('<div class="notice notice-success"><p><strong>Success:</strong> ' + message + '</p></div>');
                $button.prop('disabled', false).text('Delete Selected ' + (productType === 'tire' ? 'Tire' : 'Wheel') + ' Brands');
                $checkboxes.prop('disabled', false).prop('checked', false);
                return;
            }

            var brandName = brands[currentIndex];
            $result.html('<div class="notice notice-info"><p><span class="spinner is-active" style="float: none; margin: 0 5px;"></span> Deleting products for brand: <strong>' + brandName + '</strong> (' + (currentIndex + 1) + '/' + brands.length + ')...</p></div>');

            deleteProductsBatch(brandName, productType, 0, function(result) {
                if (result.success) {
                    totalDeleted++;
                } else {
                    totalFailed++;
                }
                currentIndex++;
                deleteNextBrand();
            });
        }

        deleteNextBrand();
    }

    // Delete products in batches
    function deleteProductsBatch(brandName, productType, offset, callback) {
        $.ajax({
            url: asmData.ajax_url,
            type: 'POST',
            data: {
                action: 'asm_delete_products_by_brand',
                nonce: asmData.nonce,
                brand_name: brandName,
                product_type: productType,
                batch_size: 500,
                offset: offset
            },
            timeout: 300000, // 5 minutes
            success: function(response) {
                if (response.success && response.data) {
                    var deletedInBatch = response.data.deleted_count || 0;
                    var remaining = response.data.remaining_count || 0;
                    var total = response.data.total_found || 0;
                    var hasMore = response.data.has_more === true;
                    
                    // Send progress update to callback (for UI updates)
                    if (typeof callback === 'function') {
                        callback(null, deletedInBatch, remaining, total);
                    }
                    
                    if (hasMore === true) {
                        // Continue deleting next batch
                        var nextOffset = response.data.offset !== undefined ? response.data.offset : (offset + 500);
                        // Minimal delay to allow UI to update (reduced from 50ms to 10ms for faster deletion)
                        setTimeout(function() {
                            deleteProductsBatch(brandName, productType, nextOffset, callback);
                        }, 10);
                    } else {
                        // All batches complete
                        if (typeof callback === 'function') {
                            callback({
                                success: true,
                                message: response.data.message || 'Products deleted successfully',
                                deleted_count: deletedInBatch
                            });
                        }
                    }
                } else {
                    var errorMsg = 'Failed to delete products';
                    if (response.data) {
                        if (typeof response.data === 'string') {
                            errorMsg = response.data;
                        } else if (response.data.message) {
                            errorMsg = response.data.message;
                        }
                    }
                    if (typeof callback === 'function') {
                        callback({
                            success: false,
                            message: errorMsg
                        });
                    }
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'AJAX error: ' + error;
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    if (typeof xhr.responseJSON.data === 'string') {
                        errorMsg = xhr.responseJSON.data;
                    } else if (xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }
                }
                callback({
                    success: false,
                    message: errorMsg
                });
            }
        });
    }

})(jQuery);

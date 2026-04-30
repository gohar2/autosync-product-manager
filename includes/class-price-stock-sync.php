<?php
/**
 * Price Stock Sync Class
 *
 * Handles price and stock synchronization for external cron runners and public endpoints.
 * Only updates price and stock when price_stock_hash changes
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';
require_once ASM_PLUGIN_DIR . 'includes/class-brand-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-api-client.php';
require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-hash-generator.php';
require_once ASM_PLUGIN_DIR . 'includes/class-sync-status-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-cron-lock-log.php';

class ASM_Price_Stock_Sync {

    /**
     * Legacy WordPress cron hook name, kept only to clear old scheduled events.
     */
    const CRON_HOOK = 'asm_price_stock_sync_cron';

    /**
     * Map a PHP error-type bitmask value to a short readable label for log notes.
     */
    private static function php_error_type_label($type) {
        $map = array(
            E_ERROR             => 'E_ERROR',
            E_PARSE             => 'E_PARSE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        );
        return isset($map[$type]) ? $map[$type] : ('TYPE_' . $type);
    }

    /**
     * Sync price and stock for all active brands
     * Only updates products where price_stock_hash has changed
     *
     * @return array Statistics
     */
    public static function sync_all_brands() {
        // Lock file overlap protection prevents multiple external requests from running
        // the same long price/stock sync at the same time.
        $lock_file = WP_CONTENT_DIR . '/autosync-price-stock.lock';
        // Stale-lock age is admin-configurable via ASM_Cron_Lock_Log option;
        // falls back to 45 min when unset/empty/invalid.
        $lock_timeout = ASM_Cron_Lock_Log::get_stale_minutes() * 60;

        $lock_state       = 'not_found';
        $action_taken     = 'created';
        $lock_age_minutes = null;

        if (file_exists($lock_file)) {
            $lock_age         = time() - filemtime($lock_file);
            $lock_age_minutes = round($lock_age / 60, 2);

            if ($lock_age < $lock_timeout) {
                $lock_state      = 'found_active';
                $action_taken    = 'skipped';
                $process_outcome = 'skipped';
                $notes           = 'Active lock detected; new cron process prevented.';

                error_log('AutoSync: Skipped — sync already running (' . $lock_age_minutes . ' min ago)');
                ASM_Cron_Lock_Log::log_start($lock_state, $action_taken, $process_outcome, $lock_age_minutes, $notes);
                return array('message' => 'Sync already in progress. Skipped.');
            }

            unlink($lock_file); // stale lock — remove and continue
            $lock_state   = 'found_stale';
            $action_taken = 'cleared_stale_and_created';
        }

        file_put_contents($lock_file, time());

        $log_id = ASM_Cron_Lock_Log::log_start(
            $lock_state,
            $action_taken,
            'proceeding',
            $lock_age_minutes,
            'Lock file created; cron proceeding as new process.'
        );

        // Crash-detection contract: the lock is removed at shutdown ONLY when the
        // sync reached a clean exit point and set $completed_normally = true.
        // If a fatal/uncaught exception aborts the run before that, or the process
        // is hard-killed (SIGKILL/OOM/worker recycle) and this handler never fires,
        // the lock stays on disk so the next cron tick can detect it as a stale
        // lock left behind by a crashed run.
        $completed_normally = false;

        register_shutdown_function(function () use ($lock_file, $log_id, &$completed_normally) {
            $removed = false;
            $note    = '';

            if (!$completed_normally) {
                // Pull crash details from PHP's last-error buffer so the admin
                // can see WHY the lock was kept on disk (typical cases: OOM,
                // fatal in third-party code, max_execution_time exceeded).
                // Filtered to fatal-class errors only — warnings/notices
                // shouldn't be reported as the crash cause.
                $crash_detail = '';
                $last_error   = error_get_last();
                $fatal_mask   = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
                if (is_array($last_error) && isset($last_error['type']) && ($last_error['type'] & $fatal_mask)) {
                    $type_label = self::php_error_type_label((int) $last_error['type']);
                    $message    = isset($last_error['message']) ? (string) $last_error['message'] : '';
                    $file       = isset($last_error['file']) ? (string) $last_error['file'] : '';
                    $line       = isset($last_error['line']) ? (int) $last_error['line'] : 0;
                    $crash_detail = sprintf(
                        ' Crash reason: [%s] %s in %s:%d',
                        $type_label,
                        $message,
                        $file,
                        $line
                    );
                }

                $note = file_exists($lock_file)
                    ? 'Lock kept on disk — sync did not complete normally (crash or fatal error).' . $crash_detail
                    : 'Lock file missing at shutdown after abnormal termination.' . $crash_detail;
            } elseif (file_exists($lock_file)) {
                $removed = (bool) @unlink($lock_file);
                $note    = $removed
                    ? 'Lock file removed at shutdown (sync completed).'
                    : 'Lock file removal failed at shutdown.';
            } else {
                $note = 'Lock file already gone at shutdown.';
            }

            ASM_Cron_Lock_Log::log_end($log_id, $removed, $note);
        });

        // Check if hash table has entries - price/stock sync requires full product sync first
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        $hash_count = ASM_Hash_Manager::get_total_count();
        
        if ($hash_count == 0) {
            // ASM_Logger::info('Price/Stock sync skipped - hash table is empty. Please run full product sync first.');
            $completed_normally = true;
            return array(
                'start_time' => microtime(true),
                'duration' => 0,
                'tires' => array(
                    'brands_processed' => 0,
                    'total_updated' => 0,
                    'total_skipped' => 0,
                    'total_errors' => 0
                ),
                'wheels' => array(
                    'brands_processed' => 0,
                    'total_updated' => 0,
                    'total_skipped' => 0,
                    'total_errors' => 0
                ),
                'message' => 'Hash table is empty. Please run full product sync first.'
            );
        }
        
        // Check if API key is configured
        $api_key = get_option('asm_api_key', '');
        if (empty($api_key)) {
            // ASM_Logger::info('Price/Stock sync skipped - API key not configured.');
            $completed_normally = true;
            return array(
                'start_time' => microtime(true),
                'duration' => 0,
                'tires' => array(
                    'brands_processed' => 0,
                    'total_updated' => 0,
                    'total_skipped' => 0,
                    'total_errors' => 0
                ),
                'wheels' => array(
                    'brands_processed' => 0,
                    'total_updated' => 0,
                    'total_skipped' => 0,
                    'total_errors' => 0
                ),
                'message' => 'API key not configured.'
            );
        }

        $stats = array(
            'start_time' => microtime(true),
            'tires' => array(
                'brands_processed' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 0
            ),
            'wheels' => array(
                'brands_processed' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 0
            )
        );

        // Increase execution time limit
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $api_client = new ASM_API_Client();

        // Sync tires
        try {
            $tire_stats = self::sync_product_type('tire', $api_client);
            $stats['tires'] = $tire_stats;
        } catch (Exception $e) {
            // ASM_Logger::error('Tire price/stock sync failed', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            $stats['tires'] = array(
                'brands_processed' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 1,
                'error' => $e->getMessage()
            );
        }

        // Sync wheels
        try {
            $wheel_stats = self::sync_product_type('wheel', $api_client);
            $stats['wheels'] = $wheel_stats;
        } catch (Exception $e) {
            // ASM_Logger::error('Wheel price/stock sync failed', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            $stats['wheels'] = array(
                'brands_processed' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 1,
                'error' => $e->getMessage()
            );
        }

        $duration = round(microtime(true) - $stats['start_time'], 2);
        $stats['duration'] = $duration;

        // ASM_Logger::info('Price/Stock sync completed', array(
        //     'duration_seconds' => $duration,
        //     'tire_stats' => $stats['tires'],
        //     'wheel_stats' => $stats['wheels']
        // ));

        // Save sync status
        self::save_sync_status($stats);

        $completed_normally = true;
        return $stats;
    }

    /**
     * Sync price and stock for a specific product type
     *
     * @param string $product_type 'tire' or 'wheel'
     * @param ASM_API_Client $api_client API client instance
     * @return array Statistics
     */
    private static function sync_product_type($product_type, $api_client) {
        try {
            // ASM_Logger::info('Starting price/stock sync for product type', array('type' => $product_type));

            // Get all active brands for this product type
            $all_brands = ASM_Brand_Manager::get_brands($product_type);
            $active_brands = array();

            foreach ($all_brands as $brand) {
                $is_active = is_bool($brand->is_active) ? ($brand->is_active ? 1 : 0) : intval($brand->is_active);
                if ($is_active == 1) {
                    $active_brands[] = $brand;
                }
            }

            if (empty($active_brands)) {
                // ASM_Logger::info('No active brands found for product type', array('type' => $product_type));
                return array(
                    'brands_processed' => 0,
                    'total_updated' => 0,
                    'total_skipped' => 0,
                    'total_errors' => 0
                );
            }

            $stats = array(
                'brands_processed' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 0
            );

            // Process each active brand
            foreach ($active_brands as $brand) {
                try {
                    $brand_stats = self::sync_brand($product_type, $brand, $api_client);
                    $stats['brands_processed']++;
                    $stats['total_updated'] += $brand_stats['updated'];
                    $stats['total_skipped'] += $brand_stats['skipped'];
                    $stats['total_errors'] += $brand_stats['errors'];
                } catch (Exception $e) {
                    $stats['total_errors']++;
                    // ASM_Logger::error('Error syncing brand for price/stock', array(
                    //     'brand' => $brand->brand_name,
                    //     'type' => $product_type,
                    //     'error' => $e->getMessage()
                    // ));
                }
            }

            return $stats;

        } catch (Exception $e) {
            // ASM_Logger::error('Price/stock sync failed for product type', array(
            //     'type' => $product_type,
            //     'error' => $e->getMessage()
            // ));
            throw $e;
        }
    }

    /**
     * Sync price and stock for a specific brand
     *
     * @param string $product_type 'tire' or 'wheel'
     * @param object $brand Brand object
     * @param ASM_API_Client $api_client API client instance
     * @return array Statistics
     */
    private static function sync_brand($product_type, $brand, $api_client) {
        $stats = array(
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0
        );

        $page = 1;
        $page_size = 500;
        $has_more = true;

        while ($has_more) {
            try {
                // Fetch products from API
                if ($product_type === 'tire') {
                    $response = $api_client->get_tires($page, $page_size, $brand->brand_id);
                } else {
                    $response = $api_client->get_wheels($page, $page_size, $brand->brand_id);
                }

                if (!$response || !isset($response['products']) || empty($response['products'])) {
                    $has_more = false;
                    break;
                }

                $products = $response['products'];
                
                // Process each product
                foreach ($products as $api_data) {
                    try {
                        $result = self::sync_single_product($api_data, $product_type);
                        if ($result === 'updated') {
                            $stats['updated']++;
                        } elseif ($result === 'skipped') {
                            $stats['skipped']++;
                        } else {
                            $stats['errors']++;
                        }
                    } catch (Exception $e) {
                        $stats['errors']++;
                        // ASM_Logger::error('Error syncing product price/stock', array(
                        //     'sku' => isset($api_data->PartNumber) ? $api_data->PartNumber : (isset($api_data->Pn) ? $api_data->Pn : 'unknown'),
                        //     'error' => $e->getMessage()
                        // ));
                    }
                }

                // Check if there are more pages
                $has_more = isset($response['has_more']) ? $response['has_more'] : (count($products) >= $page_size);
                $page++;

            } catch (Exception $e) {
                $stats['errors']++;
                // ASM_Logger::error('Error fetching products for price/stock sync', array(
                //     'brand' => $brand->brand_name,
                //     'page' => $page,
                //     'error' => $e->getMessage()
                // ));
                $has_more = false;
            }
        }

        return $stats;
    }

    /**
     * Sync price and stock for multiple products in bulk
     * Uses batch operations for better performance
     *
     * @param array $products Array of product objects from API
     * @param string $product_type 'tire' or 'wheel'
     * @return array Statistics: processed, updated, skipped, errors
     */
    private static function sync_products_bulk($products, $product_type) {
        global $wpdb;
        
        $stats = array(
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0
        );
        
        if (empty($products) || !is_array($products)) {
            return $stats;
        }
        
        $sku_field = ($product_type === 'tire') ? 'PartNumber' : 'Pn';
        $product_data_map = array();
        $skus = array();
        
        // Step 1: Extract SKUs and prepare product data
        foreach ($products as $api_data) {
            $sku = isset($api_data->$sku_field) ? $api_data->$sku_field : '';
            if (empty($sku)) {
                $stats['errors']++;
                continue;
            }
            
            $skus[] = $sku;
            $product_data_map[$sku] = $api_data;
        }
        
        if (empty($skus)) {
            return $stats;
        }
        
        $stats['processed'] = count($skus);
        
        // Step 2: Batch get existing price_stock_hashes
        $existing_hashes = ASM_Hash_Manager::batch_get_price_stock_hashes($skus);
        
        // Step 3: Batch get product IDs by SKU
        $sku_to_product_id = array();
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $product_ids_query = $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = '_sku' AND meta_value IN ({$placeholders})",
            $skus
        );
        $product_id_results = $wpdb->get_results($product_ids_query, ARRAY_A);
        foreach ($product_id_results as $row) {
            $sku_to_product_id[$row['meta_value']] = intval($row['post_id']);
        }
        
        // Step 4: Generate new hashes and identify products that need updates
        $products_to_update = array();
        $hash_updates = array();
        $hash_records = ASM_Hash_Manager::batch_get_hash_records($skus);
        
        foreach ($skus as $sku) {
            if (!isset($product_data_map[$sku])) {
                $stats['errors']++;
                continue;
            }
            
            $api_data = $product_data_map[$sku];
            $new_price_stock_hash = ASM_Hash_Generator::generate_price_stock_hash_from_api_data($api_data);
            
            if (empty($new_price_stock_hash)) {
                $stats['errors']++;
                continue;
            }
            
            // Check if hash matches
            $existing_hash = isset($existing_hashes[$sku]) ? $existing_hashes[$sku] : false;
            if ($existing_hash !== false && hash_equals($existing_hash, $new_price_stock_hash)) {
                // Hash matches - skip
                $stats['skipped']++;
                continue;
            }
            
            // Check if product exists
            if (!isset($sku_to_product_id[$sku])) {
                // Product doesn't exist locally - skip
                $stats['skipped']++;
                continue;
            }
            
            $product_id = $sku_to_product_id[$sku];
            
            // Prepare update data
            $price = isset($api_data->Price) ? floatval($api_data->Price) : 0;
            $inventory = isset($api_data->Inventory) ? $api_data->Inventory : '';
            
            $products_to_update[$product_id] = array(
                'sku' => $sku,
                'price' => $price,
                'inventory' => $inventory,
                'new_hash' => $new_price_stock_hash
            );
            
            // Prepare hash update - only update existing hash records
            // Price/stock sync should NOT create new hash records - that's done by full product sync
            $existing_hash_record = isset($hash_records[$sku]) ? $hash_records[$sku] : null;
            if ($existing_hash_record) {
                $hash_updates[] = array(
                    'sku' => $sku,
                    'hash' => $existing_hash_record->hash,
                    'product_id' => $product_id,
                    'product_type' => $existing_hash_record->product_type,
                    'brand' => $existing_hash_record->brand,
                    'price_stock_hash' => $new_price_stock_hash
                );
            } else {
                // Hash record doesn't exist - this shouldn't happen if full sync ran first
                // Log warning and skip hash creation (price/stock sync should only update existing hashes)
                // ASM_Logger::warning('Price/Stock sync: Hash record not found for SKU in bulk sync. Full product sync may not have run.', array(
                //     'sku' => $sku,
                //     'product_id' => $product_id
                // ));
                // Don't add to hash_updates - skip hash record creation
                // Product price/stock will be updated, but hash record creation is skipped
            }
        }
        
        if (empty($products_to_update)) {
            return $stats;
        }
        
        // Step 5: Bulk update prices and stock using true bulk SQL operations
        // Collect all meta values to update
        $meta_to_insert = array();
        $product_ids_to_update = array();
        
        foreach ($products_to_update as $product_id => $update_data) {
            $product_ids_to_update[] = $product_id;
            $price = floatval($update_data['price']);
            $inventory = $update_data['inventory'];
            
            // Add price meta
            if ($price > 0) {
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_regular_price',
                    'meta_value' => $price
                );
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_price',
                    'meta_value' => $price
                );
            }
            
            // Add stock meta
            if ($inventory !== '') {
                $inventory_int = intval($inventory);
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_stock',
                    'meta_value' => $inventory_int
                );
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_manage_stock',
                    'meta_value' => 'yes'
                );
                $stock_status = ($inventory_int == 0) ? 'outofstock' : 'instock';
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_stock_status',
                    'meta_value' => $stock_status
                );
            } else {
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_manage_stock',
                    'meta_value' => 'no'
                );
                $meta_to_insert[] = array(
                    'product_id' => $product_id,
                    'meta_key' => '_stock_status',
                    'meta_value' => 'instock'
                );
            }
        }
        
        // Use bulk_insert_meta method (same as full product sync)
        if (!empty($meta_to_insert)) {
            $table_prefix = $wpdb->prefix;
            self::bulk_insert_meta($meta_to_insert, $table_prefix);
        }
        
        // Update post modified time in bulk
        if (!empty($product_ids_to_update)) {
            $chunks = array_chunk($product_ids_to_update, 100);
            foreach ($chunks as $chunk) {
                $ids_sql = implode(',', array_map('intval', $chunk));
                $wpdb->query(
                    "UPDATE {$wpdb->posts} 
                     SET post_modified = NOW(), post_modified_gmt = UTC_TIMESTAMP()
                     WHERE ID IN ({$ids_sql})"
                );
            }
        }
        
        // Step 6: Hash updates should only contain existing hash records
        // Removed code that creates new hash records - price/stock sync should only update existing hashes
        // If hash record doesn't exist, it means full product sync hasn't run, so we skip hash creation
        
        // Step 7: Batch update hashes
        if (!empty($hash_updates)) {
            $hash_data_for_save = array();
            foreach ($hash_updates as $hash_update) {
                $hash_data_for_save[] = array(
                    'sku' => $hash_update['sku'],
                    'hash' => isset($hash_update['hash']) ? $hash_update['hash'] : '',
                    'product_id' => $hash_update['product_id'],
                    'product_type' => isset($hash_update['product_type']) ? $hash_update['product_type'] : null,
                    'brand' => isset($hash_update['brand']) ? $hash_update['brand'] : null,
                    'price_stock_hash' => $hash_update['price_stock_hash']
                );
            }
            ASM_Hash_Manager::batch_save_hashes($hash_data_for_save);
        }
        
        $stats['updated'] = count($products_to_update);
        
        return $stats;
    }

    /**
     * Sync price and stock for a single product
     *
     * @param object $api_data Product data from API
     * @param string $product_type 'tire' or 'wheel'
     * @return string 'updated', 'skipped', or 'error'
     */
    private static function sync_single_product($api_data, $product_type) {
        // Get SKU
        $sku_field = ($product_type === 'tire') ? 'PartNumber' : 'Pn';
        $sku = isset($api_data->$sku_field) ? $api_data->$sku_field : '';

        if (empty($sku)) {
            return 'error';
        }

        // Generate price_stock_hash from API data
        $new_price_stock_hash = ASM_Hash_Generator::generate_price_stock_hash_from_api_data($api_data);

        if (empty($new_price_stock_hash)) {
            return 'error';
        }

        // Check if price_stock_hash exists and matches
        $existing_price_stock_hash = ASM_Hash_Manager::get_price_stock_hash($sku);
        
        // If hash exists and matches, skip (no changes in API)
        if ($existing_price_stock_hash !== false && hash_equals($existing_price_stock_hash, $new_price_stock_hash)) {
            // Hash matches - no price/stock changes in API, skip
            // ASM_Logger::info('Product price/stock skipped - hash matches (no API changes)', array(
            //     'sku' => $sku,
            //     'type' => $product_type
            // ));
            return 'skipped';
        }
        
        // Log if we're updating
        if ($existing_price_stock_hash === false) {
            // ASM_Logger::info('Product price/stock will be updated - hash not found', array(
            //     'sku' => $sku,
            //     'type' => $product_type
            // ));
        } else {
            // ASM_Logger::info('Product price/stock will be updated - hash differs (API changed)', array(
            //     'sku' => $sku,
            //     'type' => $product_type,
            //     'old_hash' => substr($existing_price_stock_hash, 0, 8) . '...',
            //     'new_hash' => substr($new_price_stock_hash, 0, 8) . '...'
            // ));
        }

        // Hash doesn't exist or doesn't match - update price and stock from API
        $product_id = wc_get_product_id_by_sku($sku);

        if (!$product_id) {
            // Product doesn't exist locally - skip (we only update existing products)
            return 'skipped';
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return 'error';
        }

        // Update price from API
        $price = isset($api_data->Price) ? floatval($api_data->Price) : 0;
        if ($price > 0) {
            $product->set_regular_price($price);
        }

        // Update inventory from API
        $inventory = isset($api_data->Inventory) ? $api_data->Inventory : '';
        if ($inventory !== '') {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($inventory);

            if ($inventory == 0) {
                $product->set_stock_status('outofstock');
            } else {
                $product->set_stock_status('instock');
            }
        } else {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        // Save product
        $product->save();

        // Update price_stock_hash in database - only update existing hash records
        // Price/stock sync should NOT create new hash records - that's done by full product sync
        $existing_hash_record = ASM_Hash_Manager::get_hash_record($sku);
        if ($existing_hash_record) {
            // Update existing hash record with new price_stock_hash
            ASM_Hash_Manager::save_hash(
                $sku,
                $existing_hash_record->hash,
                $product_id,
                $existing_hash_record->product_type,
                $existing_hash_record->brand,
                $new_price_stock_hash
            );
        } else {
            // Hash record doesn't exist - this shouldn't happen if full sync ran first
            // Log warning and skip hash creation (price/stock sync should only update existing hashes)
            // ASM_Logger::warning('Price/Stock sync: Hash record not found for SKU. Full product sync may not have run.', array(
            //     'sku' => $sku,
            //     'product_id' => $product_id,
            //     'type' => $product_type
            // ));
            // Don't create hash record here - it should be created by full product sync
            // Product price/stock has been updated, but hash record creation is skipped
        }

        return 'updated';
    }

    /**
     * Get all active brands for batch processing
     * Returns structure for tracking batch progress
     *
     * @return array Brands structure for batch processing
     */
    public static function get_active_brands_for_batch() {
        $brands_structure = array(
            'tires' => array(),
            'wheels' => array()
        );

        // Get active tire brands
        $tire_brands = ASM_Brand_Manager::get_brands('tire');
        foreach ($tire_brands as $brand) {
            $is_active = is_bool($brand->is_active) ? ($brand->is_active ? 1 : 0) : intval($brand->is_active);
            if ($is_active == 1) {
                $brands_structure['tires'][] = array(
                    'brand_id' => $brand->brand_id,
                    'brand_name' => $brand->brand_name
                );
            }
        }

        // Get active wheel brands
        $wheel_brands = ASM_Brand_Manager::get_brands('wheel');
        foreach ($wheel_brands as $brand) {
            $is_active = is_bool($brand->is_active) ? ($brand->is_active ? 1 : 0) : intval($brand->is_active);
            if ($is_active == 1) {
                $brands_structure['wheels'][] = array(
                    'brand_id' => $brand->brand_id,
                    'brand_name' => $brand->brand_name
                );
            }
        }

        return $brands_structure;
    }

    /**
     * Process a single batch of products for price/stock sync
     * Used for AJAX batch processing
     *
     * @param array $batch_state Current batch state from transient
     * @return array Updated batch state and batch stats
     */
    public static function process_batch($batch_state) {
        $api_client = new ASM_API_Client();
        $page_size = 500; // Max API limit - same as full sync for performance
        $max_pages_per_batch = 1; // Process 1 page (500 products) per AJAX call for faster progress updates
        
        $batch_stats = array(
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'products_processed' => 0
        );

        // Initialize brand states if needed
        if (!empty($batch_state['tires'])) {
            foreach ($batch_state['tires'] as $brand_idx => $brand_data) {
                if (!isset($brand_data['current_page'])) {
                    $batch_state['tires'][$brand_idx]['current_page'] = 1;
                }
                if (!isset($brand_data['completed'])) {
                    $batch_state['tires'][$brand_idx]['completed'] = false;
                }
            }
        }
        if (!empty($batch_state['wheels'])) {
            foreach ($batch_state['wheels'] as $brand_idx => $brand_data) {
                if (!isset($brand_data['current_page'])) {
                    $batch_state['wheels'][$brand_idx]['current_page'] = 1;
                }
                if (!isset($brand_data['completed'])) {
                    $batch_state['wheels'][$brand_idx]['completed'] = false;
                }
            }
        }

        $pages_processed = 0;
        $current_type = null;
        
        // Process multiple brands in one batch call until max_pages_per_batch is reached
        while ($pages_processed < $max_pages_per_batch) {
            // Find next incomplete brand
            $current_brand = null;
            $current_brand_idx = null;
            $current_page = 1;

            // Check tires first
            if (!empty($batch_state['tires'])) {
                foreach ($batch_state['tires'] as $brand_idx => $brand_data) {
                    if (!isset($brand_data['completed']) || !$brand_data['completed']) {
                        $current_type = 'tire';
                        $current_brand = $brand_data;
                        $current_brand_idx = $brand_idx;
                        $current_page = isset($brand_data['current_page']) ? $brand_data['current_page'] : 1;
                        break;
                    }
                }
            }

            // If no tire brand to process, check wheels
            if (!$current_brand && !empty($batch_state['wheels'])) {
                foreach ($batch_state['wheels'] as $brand_idx => $brand_data) {
                    if (!isset($brand_data['completed']) || !$brand_data['completed']) {
                        $current_type = 'wheel';
                        $current_brand = $brand_data;
                        $current_brand_idx = $brand_idx;
                        $current_page = isset($brand_data['current_page']) ? $brand_data['current_page'] : 1;
                        break;
                    }
                }
            }

            // No more brands to process
            if (!$current_brand) {
                break;
            }

            // Process pages for current brand
            $has_more = true;
            $brand_pages_processed = 0;
            
            while ($has_more && $pages_processed < $max_pages_per_batch) {
                try {
                    // Fetch products from API
                    if ($current_type === 'tire') {
                        $response = $api_client->get_tires($current_page, $page_size, $current_brand['brand_id']);
                    } else {
                        $response = $api_client->get_wheels($current_page, $page_size, $current_brand['brand_id']);
                    }

                    if (!$response || !isset($response['products']) || empty($response['products'])) {
                        // No more products for this brand - mark as completed
                        $has_more = false;
                        if ($current_type === 'tire' && $current_brand_idx !== null) {
                            $batch_state['tires'][$current_brand_idx]['completed'] = true;
                        } elseif ($current_type === 'wheel' && $current_brand_idx !== null) {
                            $batch_state['wheels'][$current_brand_idx]['completed'] = true;
                        }
                        break;
                    }

                    $products = $response['products'];
                    
                    // Process products in bulk
                    $bulk_result = self::sync_products_bulk($products, $current_type);
                    $batch_stats['products_processed'] += $bulk_result['processed'];
                    $batch_stats['updated'] += $bulk_result['updated'];
                    $batch_stats['skipped'] += $bulk_result['skipped'];
                    $batch_stats['errors'] += $bulk_result['errors'];

                    // Check if there are more pages for this brand
                    $has_more = isset($response['has_more']) ? $response['has_more'] : (count($products) >= $page_size);
                    $pages_processed++;
                    $brand_pages_processed++;
                    
                    if ($has_more) {
                        $current_page++;
                        // Update page number in batch state
                        if ($current_type === 'tire' && $current_brand_idx !== null) {
                            $batch_state['tires'][$current_brand_idx]['current_page'] = $current_page;
                        } elseif ($current_type === 'wheel' && $current_brand_idx !== null) {
                            $batch_state['wheels'][$current_brand_idx]['current_page'] = $current_page;
                        }
                    } else {
                        // No more pages - mark brand as completed
                        if ($current_type === 'tire' && $current_brand_idx !== null) {
                            $batch_state['tires'][$current_brand_idx]['completed'] = true;
                        } elseif ($current_type === 'wheel' && $current_brand_idx !== null) {
                            $batch_state['wheels'][$current_brand_idx]['completed'] = true;
                        }
                    }
                } catch (Exception $e) {
                    $batch_stats['errors']++;
                    // ASM_Logger::error('Error fetching products for price/stock sync batch', array(
                    //     'brand' => $current_brand['brand_name'],
                    //     'type' => $current_type,
                    //     'page' => $current_page,
                    //     'error' => $e->getMessage()
                    // ));
                    // Mark brand as completed on error to avoid infinite loops
                    if ($current_type === 'tire' && $current_brand_idx !== null) {
                        $batch_state['tires'][$current_brand_idx]['completed'] = true;
                    } elseif ($current_type === 'wheel' && $current_brand_idx !== null) {
                        $batch_state['wheels'][$current_brand_idx]['completed'] = true;
                    }
                    $has_more = false;
                    break;
                }
            }
            
            // If brand still has more pages but we've reached max_pages_per_batch, update page number
            if ($has_more && $pages_processed >= $max_pages_per_batch) {
                if ($current_type === 'tire' && $current_brand_idx !== null) {
                    $batch_state['tires'][$current_brand_idx]['current_page'] = $current_page;
                } elseif ($current_type === 'wheel' && $current_brand_idx !== null) {
                    $batch_state['wheels'][$current_brand_idx]['current_page'] = $current_page;
                }
            }
        }

        // Calculate brand progress
        $total_brands = 0;
        $completed_brands = 0;
        $current_brand_num = 0;
        $current_brand_name = '';
        
        // Count total and completed brands
        if (!empty($batch_state['tires'])) {
            $total_brands += count($batch_state['tires']);
            foreach ($batch_state['tires'] as $brand) {
                if (isset($brand['completed']) && $brand['completed']) {
                    $completed_brands++;
                }
            }
        }
        if (!empty($batch_state['wheels'])) {
            $total_brands += count($batch_state['wheels']);
            foreach ($batch_state['wheels'] as $brand) {
                if (isset($brand['completed']) && $brand['completed']) {
                    $completed_brands++;
                }
            }
        }
        
        // Find current brand being processed and calculate its position
        if ($current_brand && isset($current_brand['brand_name'])) {
            $current_brand_name = $current_brand['brand_name'];
            // Calculate brand number (1-based) - count all brands up to and including current
            $brand_counter = 0;
            
            // Count tire brands
            if (!empty($batch_state['tires'])) {
                foreach ($batch_state['tires'] as $brand_idx => $brand_data) {
                    $brand_counter++;
                    if ($brand_idx === $current_brand_idx && $current_type === 'tire') {
                        $current_brand_num = $brand_counter;
                        break;
                    }
                }
            }
            
            // If not found in tires, check wheels
            if ($current_brand_num == 0 && !empty($batch_state['wheels'])) {
                // Start counter from tire count
                $brand_counter = !empty($batch_state['tires']) ? count($batch_state['tires']) : 0;
                foreach ($batch_state['wheels'] as $brand_idx => $brand_data) {
                    $brand_counter++;
                    if ($brand_idx === $current_brand_idx && $current_type === 'wheel') {
                        $current_brand_num = $brand_counter;
                        break;
                    }
                }
            }
        }

        // Check if all brands are completed
        $all_completed = true;
        if (!empty($batch_state['tires'])) {
            foreach ($batch_state['tires'] as $brand) {
                if (!isset($brand['completed']) || !$brand['completed']) {
                    $all_completed = false;
                    break;
                }
            }
        }
        if ($all_completed && !empty($batch_state['wheels'])) {
            foreach ($batch_state['wheels'] as $brand) {
                if (!isset($brand['completed']) || !$brand['completed']) {
                    $all_completed = false;
                    break;
                }
            }
        }

        return array(
            'batch_state' => $batch_state,
            'batch_stats' => $batch_stats,
            'completed' => $all_completed,
            'current_type' => $current_type,
            'current_brand_name' => $current_brand_name,
            'current_brand_num' => $current_brand_num,
            'total_brands' => $total_brands,
            'completed_brands' => $completed_brands
        );
    }

    /**
     * Clear legacy WordPress cron events for price/stock sync.
     */
    public static function unregister_cron() {
        return wp_clear_scheduled_hook(self::CRON_HOOK) !== false;
    }

    /**
     * Save sync status after completion
     *
     * @param array $stats Sync statistics
     * @param string $sync_type 'manual' or 'cron'
     */
    public static function save_sync_status($stats, $sync_type = 'manual') {
        try {
            $sync_data = ASM_Price_Stock_Sync_Status_Manager::format_sync_data_from_stats($stats, $sync_type);
            ASM_Price_Stock_Sync_Status_Manager::save_sync_status($sync_data);
        } catch (Exception $e) {
            // ASM_Logger::error('Failed to save price/stock sync status', array(
            //     'error' => $e->getMessage()
            // ));
        }
    }

    /**
     * Bulk insert/update meta values using true bulk SQL operations
     * Same approach as full product sync for maximum performance
     *
     * @param array $meta_array Array of meta data: product_id, meta_key, meta_value
     * @param string $table_prefix Database table prefix
     */
    private static function bulk_insert_meta($meta_array, $table_prefix) {
        global $wpdb;
        
        $table = $table_prefix . 'postmeta';
        
        if (empty($meta_array)) {
            return;
        }
        
        // Filter out invalid product IDs
        $valid_meta = array();
        foreach ($meta_array as $meta) {
            if (is_numeric($meta['product_id']) && intval($meta['product_id']) > 0) {
                $valid_meta[] = $meta;
            }
        }
        
        if (empty($valid_meta)) {
            return;
        }
        
        // Group meta by product_id for batch deletion
        $product_meta_keys = array();
        foreach ($valid_meta as $meta) {
            $product_id = intval($meta['product_id']);
            $meta_key = esc_sql($meta['meta_key']);
            if (!isset($product_meta_keys[$product_id])) {
                $product_meta_keys[$product_id] = array();
            }
            if (!in_array($meta_key, $product_meta_keys[$product_id])) {
                $product_meta_keys[$product_id][] = $meta_key;
            }
        }
        
        // Batch delete existing meta (one query per product)
        foreach ($product_meta_keys as $product_id => $meta_keys) {
            if (empty($meta_keys)) {
                continue;
            }
            $keys_sql = "'" . implode("','", array_map('esc_sql', $meta_keys)) . "'";
            $delete_sql = "DELETE FROM {$table} WHERE post_id = {$product_id} AND meta_key IN ({$keys_sql})";
            $wpdb->query($delete_sql);
        }
        
        // Process in chunks of 1000 to avoid query size limits
        $chunk_size = 1000;
        $chunks = array_chunk($valid_meta, $chunk_size);
        
        foreach ($chunks as $chunk) {
            $values = array();
            
            foreach ($chunk as $meta) {
                $product_id = intval($meta['product_id']);
                $meta_key = esc_sql($meta['meta_key']);
                // Handle serialized data properly - use addslashes for serialized strings
                $meta_value = is_serialized($meta['meta_value']) 
                    ? addslashes($meta['meta_value']) 
                    : esc_sql($meta['meta_value']);
                
                $values[] = "({$product_id}, '{$meta_key}', '{$meta_value}')";
            }
            
            if (empty($values)) {
                continue;
            }
            
            // Multi-row INSERT - true bulk operation
            $values_sql = implode(',', $values);
            $insert_sql = "INSERT INTO {$table} (post_id, meta_key, meta_value) VALUES {$values_sql}";
            
            $result = $wpdb->query($insert_sql);
            
            if ($result === false) {
                // ASM_Logger::error('Failed to bulk insert meta in price/stock sync', array(
                //     'error' => $wpdb->last_error,
                //     'chunk_size' => count($chunk)
                // ));
            }
        }
    }
}


<?php
/**
 * Hash Manager Class
 *
 * Manages product hash storage and comparison for change detection
 * Uses SHA-256 hashing to detect product data changes
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';

class ASM_Hash_Manager {

    /**
     * Table name (without prefix)
     */
    private static $table_name = 'asm_product_hashes';

    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Create hash table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sku varchar(255) NOT NULL,
            hash varchar(64) NOT NULL,
            price_stock_hash varchar(64) DEFAULT NULL,
            product_id bigint(20) UNSIGNED DEFAULT NULL,
            product_type varchar(20) DEFAULT NULL,
            brand varchar(255) DEFAULT NULL,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku (sku),
            KEY hash (hash),
            KEY price_stock_hash (price_stock_hash),
            KEY product_id (product_id),
            KEY product_type (product_type),
            KEY brand (brand)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add brand column if table exists but column doesn't (for existing installations)
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        if ($table_exists) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'brand'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN brand varchar(255) DEFAULT NULL AFTER product_type");
                // Check if index already exists before adding
                $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'brand'");
                if (empty($index_exists)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD KEY brand (brand)");
                }
                // ASM_Logger::info('Added brand column to existing hash table');
            }
            
            // Add price_stock_hash column if table exists but column doesn't (for existing installations)
            $price_stock_hash_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'price_stock_hash'");
            if (empty($price_stock_hash_exists)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN price_stock_hash varchar(64) DEFAULT NULL AFTER hash");
                // Check if index already exists before adding
                $index_exists = $wpdb->get_results("SHOW INDEX FROM {$table_name} WHERE Key_name = 'price_stock_hash'");
                if (empty($index_exists)) {
                    $wpdb->query("ALTER TABLE {$table_name} ADD KEY price_stock_hash (price_stock_hash)");
                }
                // ASM_Logger::info('Added price_stock_hash column to existing hash table');
            }
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // ASM_Logger::info('Hash table created successfully', array(
        //     'table_name' => $table_name
        // ));
    }

    /**
     * Drop hash table (for uninstall)
     */
    public static function drop_table() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

        // ASM_Logger::info('Hash table dropped', array(
        //     'table_name' => $table_name
        // ));
    }

    /**
     * Truncate hash table (empty all data but keep table structure)
     *
     * @return array Results with status
     */
    public static function truncate_table() {
        global $wpdb;
        
        $results = array(
            'success' => false,
            'message' => '',
            'rows_deleted' => 0
        );
        
        try {
            $table_name = self::get_table_name();
            
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            
            if (!$table_exists) {
                $results['message'] = 'Hash table does not exist';
                return $results;
            }
            
            // Get count before truncate
            $count_before = self::get_total_count();
            
            // Truncate table (faster than DELETE and resets AUTO_INCREMENT)
            $wpdb->query("TRUNCATE TABLE {$table_name}");
            
            // Verify truncation
            $count_after = self::get_total_count();
            
            if ($count_after === 0) {
                $results['success'] = true;
                $results['rows_deleted'] = $count_before;
                $results['message'] = sprintf('Hash table emptied successfully. %d records deleted.', $count_before);
                
                // ASM_Logger::info('Hash table truncated', array(
                //     'table_name' => $table_name,
                //     'rows_deleted' => $count_before
                // ));
            } else {
                $results['message'] = 'Failed to truncate hash table. Some records may still exist.';
                // ASM_Logger::error('Failed to truncate hash table', array(
                //     'table_name' => $table_name,
                //     'count_before' => $count_before,
                //     'count_after' => $count_after
                // ));
            }
            
        } catch (Exception $e) {
            $results['message'] = 'Error truncating hash table: ' . $e->getMessage();
            // ASM_Logger::error('Exception truncating hash table', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        }
        
        return $results;
    }

    /**
     * Get total count of rows with price_stock_hash that need to be cleared
     *
     * @return int Count of rows
     */
    public static function get_price_stock_hash_count() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE price_stock_hash IS NOT NULL"
        );
        
        return (int)$count;
    }

    /**
     * Empty price_stock_hash column in batches (set values to NULL)
     * Uses raw SQL query for performance - processes 500 rows at a time
     *
     * @param int $batch_size Number of rows to process per batch (default: 500)
     * @return array Result with success status, message, rows_updated, and has_more flag
     */
    public static function empty_price_stock_hash_batch($batch_size = 500) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $results = array(
            'success' => false,
            'message' => '',
            'rows_updated' => 0,
            'has_more' => false
        );

        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            
            if (!$table_exists) {
                $results['message'] = 'Hash table does not exist';
                return $results;
            }

            // Check if price_stock_hash column exists
            $column_exists = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM {$table_name} LIKE %s",
                    'price_stock_hash'
                )
            );
            
            if (empty($column_exists)) {
                $results['message'] = 'price_stock_hash column does not exist in the hash table';
                return $results;
            }

            // Get IDs of rows to update (batch of 500)
            $batch_size = absint($batch_size); // Ensure it's a positive integer
            $ids_to_update = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$table_name} 
                     WHERE price_stock_hash IS NOT NULL 
                     LIMIT %d",
                    $batch_size
                )
            );
            
            if (empty($ids_to_update)) {
                $results['success'] = true;
                $results['rows_updated'] = 0;
                $results['has_more'] = false;
                $results['remaining'] = 0;
                $results['message'] = 'No more rows to clear';
                return $results;
            }
            
            // Prepare IDs for IN clause - sanitize all IDs
            $ids_sanitized = array_map('absint', $ids_to_update);
            $ids_placeholder = implode(',', $ids_sanitized);
            
            // Use raw SQL UPDATE query to set price_stock_hash to NULL for the batch
            // Since IDs are already sanitized as integers, this is safe
            $rows_updated = $wpdb->query(
                "UPDATE {$table_name} 
                 SET price_stock_hash = NULL 
                 WHERE id IN ({$ids_placeholder})"
            );

            if ($rows_updated === false) {
                $results['message'] = 'Failed to clear price_stock_hash. Database error: ' . $wpdb->last_error;
                // ASM_Logger::error('Failed to clear price_stock_hash batch', array(
                //     'error' => $wpdb->last_error
                // ));
                return $results;
            }

            // Check if there are more rows to process
            $remaining_count = self::get_price_stock_hash_count();
            $has_more = $remaining_count > 0;

            $results['success'] = true;
            $results['rows_updated'] = (int)$rows_updated;
            $results['has_more'] = $has_more;
            $results['remaining'] = $remaining_count;
            $results['message'] = $has_more 
                ? "Processed {$rows_updated} rows. {$remaining_count} remaining."
                : 'Price/Stock hash cleared successfully';
            
            // ASM_Logger::info('Price/Stock hash batch cleared', array(
            //     'rows_updated' => $results['rows_updated'],
            //     'remaining' => $remaining_count
            // ));

        } catch (Exception $e) {
            $results['message'] = 'Exception while clearing price_stock_hash: ' . $e->getMessage();
            // ASM_Logger::error('Exception clearing price_stock_hash batch', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        }

        return $results;
    }

    /**
     * Get hash for a SKU
     *
     * @param string $sku Product SKU
     * @return string|false Hash value or false if not found
     */
    public static function get_hash($sku) {
        global $wpdb;
        
        if (empty($sku)) {
            return false;
        }

        $table_name = self::get_table_name();
        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT hash FROM {$table_name} WHERE sku = %s LIMIT 1",
            $sku
        ));

        return $hash ? $hash : false;
    }

    /**
     * Get hash record for a SKU
     *
     * @param string $sku Product SKU
     * @return object|false Hash record or false if not found
     */
    public static function get_hash_record($sku) {
        global $wpdb;
        
        if (empty($sku)) {
            return false;
        }

        $table_name = self::get_table_name();
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE sku = %s LIMIT 1",
            $sku
        ));

        return $record ? $record : false;
    }

    /**
     * Save or update hash for a SKU
     *
     * @param string $sku Product SKU
     * @param string $hash SHA-256 hash
     * @param int|null $product_id Product ID
     * @param string|null $product_type Product type (wheel/tire)
     * @param string|null $brand Product brand
     * @param string|null $price_stock_hash Price and stock hash
     * @return bool Success
     */
    public static function save_hash($sku, $hash, $product_id = null, $product_type = null, $brand = null, $price_stock_hash = null) {
        global $wpdb;
        
        if (empty($sku) || empty($hash)) {
            return false;
        }

        $table_name = self::get_table_name();
        
        // Check if hash exists for this SKU
        $existing = self::get_hash_record($sku);
        
        $update_data = array(
            'hash' => $hash,
            'product_id' => $product_id,
            'product_type' => $product_type,
            'brand' => $brand,
            'last_updated' => current_time('mysql')
        );
        $update_format = array('%s', '%d', '%s', '%s', '%s');
        
        // Add price_stock_hash if provided
        if ($price_stock_hash !== null) {
            $update_data['price_stock_hash'] = $price_stock_hash;
            $update_format[] = '%s';
        }
        
        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $table_name,
                $update_data,
                array('sku' => $sku),
                $update_format,
                array('%s')
            );
        } else {
            // Insert new record
            $insert_data = array(
                'sku' => $sku,
                'hash' => $hash,
                'product_id' => $product_id,
                'product_type' => $product_type,
                'brand' => $brand,
                'created_at' => current_time('mysql'),
                'last_updated' => current_time('mysql')
            );
            $insert_format = array('%s', '%s', '%d', '%s', '%s', '%s', '%s');
            
            // Add price_stock_hash if provided
            if ($price_stock_hash !== null) {
                $insert_data['price_stock_hash'] = $price_stock_hash;
                $insert_format[] = '%s';
            }
            
            $result = $wpdb->insert(
                $table_name,
                $insert_data,
                $insert_format
            );
        }

        if ($result === false) {
            // ASM_Logger::error('Failed to save hash', array(
            //     'sku' => $sku,
            //     'error' => $wpdb->last_error
            // ));
            return false;
        }

        return true;
    }

    /**
     * Get price_stock_hash for a SKU
     *
     * @param string $sku Product SKU
     * @return string|false Hash value or false if not found
     */
    public static function get_price_stock_hash($sku) {
        global $wpdb;
        
        if (empty($sku)) {
            return false;
        }

        $table_name = self::get_table_name();
        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT price_stock_hash FROM {$table_name} WHERE sku = %s LIMIT 1",
            $sku
        ));

        return $hash ? $hash : false;
    }

    /**
     * Batch get price_stock_hashes for multiple SKUs
     *
     * @param array $skus Array of SKUs
     * @return array Associative array: SKU => price_stock_hash (or false if not found)
     */
    public static function batch_get_price_stock_hashes($skus) {
        global $wpdb;
        
        if (empty($skus) || !is_array($skus)) {
            return array();
        }

        $table_name = self::get_table_name();
        
        // Prepare SKUs for IN clause
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $query = $wpdb->prepare(
            "SELECT sku, price_stock_hash FROM {$table_name} WHERE sku IN ({$placeholders})",
            $skus
        );

        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Convert to associative array
        $hash_map = array();
        if ($results) {
            foreach ($results as $row) {
                $hash_map[$row['sku']] = $row['price_stock_hash'] ? $row['price_stock_hash'] : false;
            }
        }
        
        // Add false for SKUs not found
        foreach ($skus as $sku) {
            if (!isset($hash_map[$sku])) {
                $hash_map[$sku] = false;
            }
        }

        return $hash_map;
    }

    /**
     * Batch get hash records for multiple SKUs (includes all hash data)
     *
     * @param array $skus Array of SKUs
     * @return array Associative array: SKU => hash record object
     */
    public static function batch_get_hash_records($skus) {
        global $wpdb;
        
        if (empty($skus) || !is_array($skus)) {
            return array();
        }

        $table_name = self::get_table_name();
        
        // Prepare SKUs for IN clause
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE sku IN ({$placeholders})",
            $skus
        );

        $results = $wpdb->get_results($query, OBJECT);
        
        // Convert to associative array
        $hash_map = array();
        if ($results) {
            foreach ($results as $row) {
                $hash_map[$row->sku] = $row;
            }
        }

        return $hash_map;
    }

    /**
     * Check if price_stock_hash exists and matches
     *
     * @param string $sku Product SKU
     * @param string $new_hash New hash to compare
     * @return bool True if hash matches, false if different or doesn't exist
     */
    public static function price_stock_hash_matches($sku, $new_hash) {
        $existing_hash = self::get_price_stock_hash($sku);
        
        if ($existing_hash === false) {
            // Hash doesn't exist
            return false;
        }

        // Compare hashes (case-sensitive)
        return hash_equals($existing_hash, $new_hash);
    }

    /**
     * Delete hash for a SKU
     *
     * @param string $sku Product SKU
     * @return bool Success
     */
    public static function delete_hash($sku) {
        global $wpdb;
        
        if (empty($sku)) {
            return false;
        }

        $table_name = self::get_table_name();
        $result = $wpdb->delete(
            $table_name,
            array('sku' => $sku),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Check if hash exists and matches
     *
     * @param string $sku Product SKU
     * @param string $new_hash New hash to compare
     * @return bool True if hash matches, false if different or doesn't exist
     */
    public static function hash_matches($sku, $new_hash) {
        $existing_hash = self::get_hash($sku);
        
        if ($existing_hash === false) {
            // Hash doesn't exist
            return false;
        }

        // Compare hashes (case-sensitive)
        return hash_equals($existing_hash, $new_hash);
    }

    /**
     * Get all hashes (for admin UI)
     *
     * @param int $limit Limit results
     * @param int $offset Offset
     * @return array Hash records
     */
    public static function get_all_hashes($limit = 100, $offset = 0) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY last_updated DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        return $results ? $results : array();
    }

    /**
     * Get total hash count
     *
     * @return int Total count
     */
    public static function get_total_count() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        return intval($count);
    }

    /**
     * Batch get hashes for multiple SKUs
     *
     * @param array $skus Array of SKUs
     * @return array Associative array: SKU => hash
     */
    public static function batch_get_hashes($skus) {
        global $wpdb;
        
        if (empty($skus) || !is_array($skus)) {
            return array();
        }

        $table_name = self::get_table_name();
        
        // Prepare SKUs for IN clause
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $query = $wpdb->prepare(
            "SELECT sku, hash FROM {$table_name} WHERE sku IN ({$placeholders})",
            $skus
        );

        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Convert to associative array
        $hash_map = array();
        if ($results) {
            foreach ($results as $row) {
                $hash_map[$row['sku']] = $row['hash'];
            }
        }

        return $hash_map;
    }

    /**
     * Batch save hashes
     *
     * @param array $hashes Array of arrays with keys: sku, hash, product_id, product_type, brand
     * @return int Number of hashes saved
     */
    public static function batch_save_hashes($hashes) {
        global $wpdb;
        
        if (empty($hashes) || !is_array($hashes)) {
            return 0;
        }

        $table_name = self::get_table_name();
        $saved = 0;

        // Process in chunks to avoid query size limits
        $chunks = array_chunk($hashes, 100);
        
        foreach ($chunks as $chunk) {
            $values = array();
            $placeholders = array();
            
            foreach ($chunk as $hash_data) {
                $sku = isset($hash_data['sku']) ? $hash_data['sku'] : '';
                $hash = isset($hash_data['hash']) ? $hash_data['hash'] : '';
                $price_stock_hash = isset($hash_data['price_stock_hash']) ? $hash_data['price_stock_hash'] : null;
                $product_id = isset($hash_data['product_id']) ? intval($hash_data['product_id']) : null;
                $product_type = isset($hash_data['product_type']) ? $hash_data['product_type'] : null;
                $brand = isset($hash_data['brand']) ? $hash_data['brand'] : null;
                
                if (empty($sku) || empty($hash)) {
                    continue;
                }

                $sku_escaped = esc_sql($sku);
                $hash_escaped = esc_sql($hash);
                $price_stock_hash_escaped = $price_stock_hash ? "'" . esc_sql($price_stock_hash) . "'" : 'NULL';
                $product_id_escaped = $product_id ? intval($product_id) : 'NULL';
                $product_type_escaped = $product_type ? "'" . esc_sql($product_type) . "'" : 'NULL';
                $brand_escaped = $brand ? "'" . esc_sql($brand) . "'" : 'NULL';
                $now = esc_sql(current_time('mysql'));

                $values[] = "('{$sku_escaped}', '{$hash_escaped}', {$price_stock_hash_escaped}, {$product_id_escaped}, {$product_type_escaped}, {$brand_escaped}, '{$now}', '{$now}')";
            }

            if (empty($values)) {
                continue;
            }

            // Use INSERT ... ON DUPLICATE KEY UPDATE for batch upsert
            $values_sql = implode(',', $values);
            $sql = "INSERT INTO {$table_name} (sku, hash, price_stock_hash, product_id, product_type, brand, created_at, last_updated) 
                    VALUES {$values_sql}
                    ON DUPLICATE KEY UPDATE 
                    hash = VALUES(hash),
                    price_stock_hash = VALUES(price_stock_hash),
                    product_id = VALUES(product_id),
                    product_type = VALUES(product_type),
                    brand = VALUES(brand),
                    last_updated = VALUES(last_updated)";

            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                $saved += count($chunk);
            }
        }

        return $saved;
    }

    /**
     * Create hash table structure (empty table only)
     * Hashes will be created automatically during bulk import operations
     *
     * @return array Results with status
     */
    public static function create_hash_table_structure() {
        $results = array(
            'success' => false,
            'message' => '',
            'table_exists' => false
        );

        try {
            // Check if table already exists
            global $wpdb;
            $table_name = self::get_table_name();
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            
            if ($table_exists) {
                $results['success'] = true;
                $results['table_exists'] = true;
                $results['message'] = 'Hash table already exists';
                // ASM_Logger::info('Hash table already exists', array(
                //     'table_name' => $table_name
                // ));
                return $results;
            }

            // Create the table
            self::create_table();
            
            // Verify table was created
            $table_exists_after = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            
            if ($table_exists_after) {
                $results['success'] = true;
                $results['table_exists'] = true;
                $results['message'] = 'Hash table created successfully';
                // ASM_Logger::info('Hash table created successfully', array(
                //     'table_name' => $table_name
                // ));
            } else {
                $results['success'] = false;
                $results['message'] = 'Failed to create hash table';
                // ASM_Logger::error('Failed to verify hash table creation', array(
                //     'table_name' => $table_name,
                //     'wpdb_error' => $wpdb->last_error
                // ));
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Error creating hash table: ' . $e->getMessage();
            // ASM_Logger::error('Exception creating hash table', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        }

        return $results;
    }

    /**
     * Determine product type from product meta or categories
     *
     * @param WC_Product $product Product object
     * @return string Product type (wheel/tire)
     */
    private static function determine_product_type($product) {
        // Check StyleId meta (wheels have 'wgp-' prefix)
        $style_id = $product->get_meta('StyleId');
        if (!empty($style_id) && strpos($style_id, 'wgp-') === 0) {
            return 'wheel';
        }

        // Check ModelId meta (tires have 'tgp-' prefix)
        $model_id = $product->get_meta('ModelId');
        if (!empty($model_id) && strpos($model_id, 'tgp-') === 0) {
            return 'tire';
        }

        // Check categories
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
        if (in_array('wheels', $categories)) {
            return 'wheel';
        }
        if (in_array('tires', $categories)) {
            return 'tire';
        }

        // Default to wheel if cannot determine
        return 'wheel';
    }
}


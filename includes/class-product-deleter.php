<?php
/**
 * Product Deleter Class
 *
 * Handles brand-wise product deletion with meta fields cleanup
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

class ASM_Product_Deleter {

    /**
     * Delete products by brand
     *
     * @param string $brand_name Brand name
     * @param string $product_type Product type ('tire' or 'wheel')
     * @param int $batch_size Batch size for deletion
     * @param int $offset Offset for pagination
     * @return array Results with deleted count and has_more flag
     */
    public static function delete_products_by_brand($brand_name, $product_type = 'both', $batch_size = 100, $offset = 0) {
        global $wpdb;

        // Increase execution time limit for large deletions
        @set_time_limit(300); // 5 minutes per batch
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '512M');

        if (empty($brand_name)) {
            return array(
                'success' => false,
                'message' => 'Brand name is required',
                'deleted_count' => 0,
                'has_more' => false
            );
        }

        // Validate batch size
        if ($batch_size < 1 || $batch_size > 1000) {
            $batch_size = 500;
        }

        // Use direct SQL query for better performance and reliability
        // This avoids WP_Query limitations with large datasets and offset issues
        $brand_meta_table = $wpdb->postmeta;
        $posts_table = $wpdb->posts;
        
        // Add product type filter if needed
        // Use subquery approach with EXISTS to avoid excluding products that don't have ModelId/StyleId
        // This ensures products are found even if they're missing type identifiers
        $type_where = '';
        
        if ($product_type === 'tire') {
            // For tires: include products with ModelId LIKE 'tgp-%'
            // Also include products that don't have StyleId LIKE 'wgp-%' (to catch edge cases)
            // This ensures we don't miss products that might be tires but are missing ModelId
            $type_where = "AND (
                EXISTS (
                    SELECT 1 FROM {$brand_meta_table} pm2 
                    WHERE pm2.post_id = p.ID 
                    AND pm2.meta_key = 'ModelId' 
                    AND pm2.meta_value LIKE 'tgp-%'
                )
                OR NOT EXISTS (
                    SELECT 1 FROM {$brand_meta_table} pm3 
                    WHERE pm3.post_id = p.ID 
                    AND pm3.meta_key = 'StyleId' 
                    AND pm3.meta_value LIKE 'wgp-%'
                )
            )";
        } elseif ($product_type === 'wheel') {
            // For wheels: include products with StyleId LIKE 'wgp-%'
            // Also include products that don't have ModelId LIKE 'tgp-%' (to catch edge cases)
            // This ensures we don't miss products that might be wheels but are missing StyleId
            $type_where = "AND (
                EXISTS (
                    SELECT 1 FROM {$brand_meta_table} pm2 
                    WHERE pm2.post_id = p.ID 
                    AND pm2.meta_key = 'StyleId' 
                    AND pm2.meta_value LIKE 'wgp-%'
                )
                OR NOT EXISTS (
                    SELECT 1 FROM {$brand_meta_table} pm3 
                    WHERE pm3.post_id = p.ID 
                    AND pm3.meta_key = 'ModelId' 
                    AND pm3.meta_value LIKE 'tgp-%'
                )
            )";
        }
        
        // Use cursor-based pagination (ID > last_id) instead of OFFSET for better reliability
        // If offset is 0, start from beginning. Otherwise, use the offset as the last deleted ID
        $last_id = max(0, $offset);
        
        // Get product IDs for this batch using cursor-based pagination
        // Optimized query: use EXISTS subquery instead of JOIN for better performance
        // Note: For optimal performance, ensure database indexes exist on:
        // - wp_posts: (post_type, post_status, ID)
        // - wp_postmeta: (post_id, meta_key, meta_value) or (meta_key, meta_value, post_id)
        $ids_query = $wpdb->prepare(
            "SELECT DISTINCT p.ID
            FROM {$posts_table} p
            WHERE EXISTS (
                SELECT 1 FROM {$brand_meta_table} pm1 
                WHERE pm1.post_id = p.ID 
                AND pm1.meta_key = 'Brand' 
                AND pm1.meta_value = %s
            )
            AND p.post_type = 'product'
            AND p.post_status != 'trash'
            AND p.ID > %d
            {$type_where}
            ORDER BY p.ID ASC
            LIMIT %d",
            $brand_name,
            $last_id,
            $batch_size
        );
        
        $product_ids = $wpdb->get_col($ids_query);

        // If no products found in this batch, we're done
        if (empty($product_ids)) {
            return array(
                'success' => true,
                'message' => sprintf('All products deleted for brand: %s', $brand_name),
                'deleted_count' => 0,
                'has_more' => false,
                'total_found' => 0,
                'remaining_count' => 0
            );
        }

        // Disable WordPress hooks temporarily for faster deletion
        remove_action('before_delete_post', 'wp_delete_post_revision');

        // Use direct database deletion for speed (much faster than wp_delete_post)
        global $wpdb;

        $deleted_count = 0;
        $failed_count = 0;
        $errors = array();

        // Convert to integers for safety
        $product_ids = array_map('intval', $product_ids);
        $product_ids_str = implode(',', $product_ids);

        // Get SKUs in bulk via SQL (much faster than individual get_post_meta calls)
        require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
        $skus_to_delete = array();
        if (!empty($product_ids_str)) {
            $skus = $wpdb->get_col(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$product_ids_str}) AND meta_key = '_sku' AND meta_value != ''"
            );
            $skus_to_delete = array_filter($skus); // Remove empty values
        }

        // Delete product variations first (if any)
        $variation_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$product_ids_str}) AND post_type = 'product_variation'");
        if (!empty($variation_ids)) {
            $variation_ids_str = implode(',', array_map('intval', $variation_ids));
            
            // Delete variation meta
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$variation_ids_str})");
            
            // Delete variation term relationships
            $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$variation_ids_str})");
            
            // Delete variation posts
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$variation_ids_str})");
        }

        // Delete product reviews/comments (if any)
        $comment_ids = $wpdb->get_col("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ({$product_ids_str})");
        if (!empty($comment_ids)) {
            $comment_ids_str = implode(',', array_map('intval', $comment_ids));
            // Delete comment meta
            $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$comment_ids_str})");
            // Delete comments
            $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_ID IN ({$comment_ids_str})");
        }

        // Delete post revisions (if any)
        $revision_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$product_ids_str}) AND post_type = 'revision'");
        if (!empty($revision_ids)) {
            $revision_ids_str = implode(',', array_map('intval', $revision_ids));
            // Delete revision meta
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$revision_ids_str})");
            // Delete revisions
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$revision_ids_str})");
        }

        // Delete WooCommerce product meta lookup table entries (bulk delete - fast)
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$lookup_table}'") === $lookup_table) {
            $wpdb->query("DELETE FROM {$lookup_table} WHERE product_id IN ({$product_ids_str})");
        }

        // Delete WooCommerce product attributes lookup (bulk delete - fast)
        $attributes_lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$attributes_lookup_table}'") === $attributes_lookup_table) {
            $wpdb->query("DELETE FROM {$attributes_lookup_table} WHERE product_id IN ({$product_ids_str})");
        }

        // Delete product meta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$product_ids_str})");

        // Delete term relationships
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$product_ids_str})");

        // Delete posts
        $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$product_ids_str})");

        if ($deleted !== false) {
            $deleted_count = $deleted;
            
            // Clean up cache (bulk operation - fast)
            clean_post_cache($product_ids);
            
            // Clear object cache in bulk if available (WP 6.0+), otherwise skip individual deletions for performance
            if (function_exists('wp_cache_delete_multiple')) {
                wp_cache_delete_multiple($product_ids, 'posts');
            }
            // Skip individual cache deletions for performance - clean_post_cache handles most of it
            
            // Delete WooCommerce transients efficiently
            // Instead of calling wc_delete_product_transients() 500 times (very slow),
            // we'll just increment the transient version once to invalidate all product caches
            if (class_exists('WooCommerce') && class_exists('WC_Cache_Helper')) {
                // Increment transient version to invalidate all product caches at once (much faster)
                WC_Cache_Helper::get_transient_version('product', true);
                
                // Also delete specific product transients in bulk via SQL for better performance
                // Get all product-related transient names and delete them in one query
                $transient_prefixes = array(
                    'wc_product_children_',
                    'wc_var_prices_',
                    'wc_child_has_weight_',
                    'wc_child_has_dimensions_',
                );
                
                // Build list of transient option names to delete
                $transient_names = array();
                foreach ($product_ids as $product_id) {
                    foreach ($transient_prefixes as $prefix) {
                        $transient_names[] = '_transient_' . $prefix . $product_id;
                        $transient_names[] = '_transient_timeout_' . $prefix . $product_id;
                    }
                }
                
                // Delete transients in bulk if we have any
                if (!empty($transient_names)) {
                    // Process in chunks to avoid query size limits
                    $chunks = array_chunk($transient_names, 200);
                    foreach ($chunks as $chunk) {
                        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
                            $chunk
                        ));
                    }
                }
            }
            
            // Delete hashes for deleted products (after bulk deletion) - bulk delete for performance
            // This ensures hashes are cleaned up when products are deleted via bulk deletion
            if (!empty($skus_to_delete)) {
                $hash_table_name = ASM_Hash_Manager::get_table_name();
                // Check if hash table exists before attempting deletion (safety check)
                if ($wpdb->get_var("SHOW TABLES LIKE '{$hash_table_name}'") === $hash_table_name) {
                    $escaped_skus = array_map(function($sku) use ($wpdb) {
                        return "'" . esc_sql($sku) . "'";
                    }, $skus_to_delete);
                    $skus_list = implode(',', $escaped_skus);
                    $wpdb->query("DELETE FROM {$hash_table_name} WHERE sku IN ({$skus_list})");
                }
            }
        } else {
            // Fallback to wp_delete_post if direct deletion fails
            foreach ($product_ids as $product_id) {
                $result = wp_delete_post($product_id, true);
                
                if ($result) {
                    $deleted_count++;
                } else {
                    $failed_count++;
                    $errors[] = "Failed to delete product ID: {$product_id}";
                }
            }
            
            // Delete hashes for products deleted via fallback (if any SKUs were found) - bulk delete for performance
            // This ensures hashes are cleaned up even when using wp_delete_post fallback
            if (!empty($skus_to_delete)) {
                $hash_table_name = ASM_Hash_Manager::get_table_name();
                // Check if hash table exists before attempting deletion (safety check)
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$hash_table_name}'") === $hash_table_name;
                if ($table_exists) {
                    $escaped_skus = array_map(function($sku) use ($wpdb) {
                        return "'" . esc_sql($sku) . "'";
                    }, $skus_to_delete);
                    $skus_list = implode(',', $escaped_skus);
                    $wpdb->query("DELETE FROM {$hash_table_name} WHERE sku IN ({$skus_list})");
                }
            }
        }

        // Re-enable hooks
        add_action('before_delete_post', 'wp_delete_post_revision');

        // Get the last deleted product ID for cursor-based pagination
        $last_deleted_id = !empty($product_ids) ? max($product_ids) : $offset;
        
        // Check if there are more products to delete - use same query logic as main query
        // This ensures we detect ALL remaining products, not just those matching type filter
        $has_more = false;
        if ($deleted_count > 0) {
            // Quick check: try to get one more product ID after the last deleted one
            // Use same EXISTS subquery approach for consistency
            $check_query = $wpdb->prepare(
                "SELECT p.ID
                FROM {$posts_table} p
                WHERE EXISTS (
                    SELECT 1 FROM {$brand_meta_table} pm1 
                    WHERE pm1.post_id = p.ID 
                    AND pm1.meta_key = 'Brand' 
                    AND pm1.meta_value = %s
                )
                AND p.post_type = 'product'
                AND p.post_status != 'trash'
                AND p.ID > %d
                {$type_where}
                LIMIT 1",
                $brand_name,
                $last_deleted_id
            );
            $has_more = $wpdb->get_var($check_query) !== null;
        }

        // Build appropriate message
        if ($deleted_count > 0) {
            $message = sprintf('Deleted %d product%s for brand: %s', $deleted_count, $deleted_count > 1 ? 's' : '', $brand_name);
            if ($has_more) {
                $message .= ' (More products remaining)';
            } else {
                $message .= ' (All products deleted)';
            }
        } else {
            $message = sprintf('No products found to delete for brand: %s', $brand_name);
        }

        return array(
            'success' => true,
            'message' => $message,
            'deleted_count' => $deleted_count,
            'failed_count' => $failed_count,
            'errors' => $errors,
            'has_more' => $has_more,
            'total_found' => $deleted_count,
            'remaining_count' => $has_more ? 1 : 0,
            'offset' => $last_deleted_id
        );
    }

    /**
     * Get product count by brand (optimized)
     *
     * @param string $brand_name Brand name
     * @param string $product_type Product type ('tire', 'wheel', or 'both')
     * @return int Product count
     */
    public static function get_product_count_by_brand($brand_name, $product_type = 'both') {
        if (empty($brand_name)) {
            return 0;
        }

        global $wpdb;

        // Use direct SQL query for better performance
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = 'Brand'
            AND pm.meta_value = %s
            AND p.post_type = 'product'
            AND p.post_status != 'trash'",
            $brand_name
        ));

        return intval($count);
    }

    /**
     * Get product counts for multiple brands in a single query (optimized)
     *
     * @param array $brand_names Array of brand names
     * @return array Associative array: brand_name => product_count
     */
    public static function get_product_counts_for_brands($brand_names) {
        global $wpdb;

        if (empty($brand_names) || !is_array($brand_names)) {
            return array();
        }

        // Prepare placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($brand_names), '%s'));
        
        // Get counts for all brands in one query
        $query = $wpdb->prepare(
            "SELECT pm.meta_value as brand_name, COUNT(DISTINCT pm.post_id) as product_count
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = 'Brand'
            AND pm.meta_value IN ({$placeholders})
            AND p.post_type = 'product'
            AND p.post_status != 'trash'
            GROUP BY pm.meta_value",
            $brand_names
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        // Convert to associative array
        $counts = array();
        foreach ($results as $row) {
            $counts[$row['brand_name']] = intval($row['product_count']);
        }

        // Ensure all brands have a count (even if 0)
        foreach ($brand_names as $brand_name) {
            if (!isset($counts[$brand_name])) {
                $counts[$brand_name] = 0;
            }
        }

        return $counts;
    }

    /**
     * Get all brands with product counts
     *
     * @param string $product_type Product type ('tire', 'wheel', or 'both')
     * @return array Brands with product counts
     */
    public static function get_brands_with_product_counts($product_type = 'both') {
        global $wpdb;

        // Get all unique brand names from product meta
        $brands_query = "
            SELECT DISTINCT meta_value as brand_name, COUNT(DISTINCT post_id) as product_count
            FROM {$wpdb->postmeta}
            INNER JOIN {$wpdb->posts} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID
            WHERE {$wpdb->postmeta}.meta_key = 'Brand'
            AND {$wpdb->postmeta}.meta_value != ''
            AND {$wpdb->posts}.post_type = 'product'
            AND {$wpdb->posts}.post_status != 'trash'
            GROUP BY meta_value
            ORDER BY brand_name ASC
        ";

        $brands = $wpdb->get_results($brands_query);

        $result = array();
        foreach ($brands as $brand) {
            $result[] = array(
                'brand_name' => $brand->brand_name,
                'product_count' => intval($brand->product_count)
            );
        }

        return $result;
    }

    /**
     * Delete products that don't have _is_autosync_product meta field
     * Removes all product relations (postmeta, term_relationships, etc.)
     * Uses bulk SQL operations for much better performance
     *
     * @param int $batch_size Batch size for deletion (default: 500)
     * @return array Results with success status, message, and deleted count
     */
    public static function delete_extra_products($batch_size = 500) {
        global $wpdb;

        // Increase execution time limit for large deletions
        @set_time_limit(300); // 5 minutes per batch
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '512M');

        $results = array(
            'success' => false,
            'message' => '',
            'deleted_count' => 0,
            'has_more' => false
        );

        try {
            // Validate batch size
            if ($batch_size < 1 || $batch_size > 1000) {
                $batch_size = 500;
            }

            // Get uncategorized category term ID to exclude from deletion
            $uncategorized_term = get_term_by('slug', 'uncategorized', 'product_cat');
            $uncategorized_term_id = $uncategorized_term ? intval($uncategorized_term->term_id) : 0;
            
            // Get term_taxonomy_id for uncategorized category
            $uncategorized_term_taxonomy_id = 0;
            if ($uncategorized_term_id > 0) {
                $term_taxonomy = $wpdb->get_row($wpdb->prepare(
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_cat'",
                    $uncategorized_term_id
                ));
                if ($term_taxonomy) {
                    $uncategorized_term_taxonomy_id = intval($term_taxonomy->term_taxonomy_id);
                }
            }
            
            // Find products without _is_autosync_product meta using optimized query
            // Exclude products in the "uncategorized" category
            $exclude_uncategorized = '';
            if ($uncategorized_term_taxonomy_id > 0) {
                $exclude_uncategorized = "
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM {$wpdb->term_relationships} tr 
                        WHERE tr.object_id = p.ID 
                        AND tr.term_taxonomy_id = {$uncategorized_term_taxonomy_id}
                    )
                ";
            }
            
            $products_query = "
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'product'
                AND p.post_status != 'trash'
                AND NOT EXISTS (
                    SELECT 1 
                    FROM {$wpdb->postmeta} pm 
                    WHERE pm.post_id = p.ID 
                    AND pm.meta_key = '_is_autosync_product'
                    AND pm.meta_value = 'yes'
                )
                {$exclude_uncategorized}
                LIMIT %d
            ";

            $product_ids = $wpdb->get_col($wpdb->prepare($products_query, $batch_size));

            if (empty($product_ids)) {
                $results['success'] = true;
                $results['message'] = 'No extra products found. All products have the _is_autosync_product meta field.';
                $results['deleted_count'] = 0;
                $results['has_more'] = false;
                return $results;
            }

            // Disable WordPress hooks temporarily for faster deletion
            remove_action('before_delete_post', 'wp_delete_post_revision');

            // Convert to integers for safety
            $product_ids = array_map('intval', $product_ids);
            $product_ids_str = implode(',', $product_ids);

            // Get SKUs in bulk via SQL (much faster than individual get_post_meta calls)
            $skus = $wpdb->get_col(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$product_ids_str}) AND meta_key = '_sku' AND meta_value != ''"
            );
            $skus_to_clean = array_filter($skus); // Remove empty values

            // Delete product variations first (if any) - bulk delete
            $variation_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$product_ids_str}) AND post_type = 'product_variation'");
            if (!empty($variation_ids)) {
                $variation_ids_str = implode(',', array_map('intval', $variation_ids));
                // Delete variation meta
                $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$variation_ids_str})");
                // Delete variation term relationships
                $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$variation_ids_str})");
                // Delete variations
                $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$variation_ids_str})");
            }

            // Delete post revisions (if any) - bulk delete
            $revision_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$product_ids_str}) AND post_type = 'revision'");
            if (!empty($revision_ids)) {
                $revision_ids_str = implode(',', array_map('intval', $revision_ids));
                // Delete revision meta
                $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$revision_ids_str})");
                // Delete revisions
                $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$revision_ids_str})");
            }

            // Delete WooCommerce product meta lookup table entries (bulk delete - fast)
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$lookup_table}'") === $lookup_table) {
                $wpdb->query("DELETE FROM {$lookup_table} WHERE product_id IN ({$product_ids_str})");
            }

            // Delete WooCommerce product attributes lookup (bulk delete - fast)
            $attributes_lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$attributes_lookup_table}'") === $attributes_lookup_table) {
                $wpdb->query("DELETE FROM {$attributes_lookup_table} WHERE product_id IN ({$product_ids_str})");
            }

            // Delete product meta (bulk delete - fast)
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$product_ids_str})");

            // Delete term relationships (bulk delete - fast)
            $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$product_ids_str})");

            // Delete posts (bulk delete - fast)
            $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$product_ids_str})");

            if ($deleted !== false) {
                $deleted_count = $deleted;

                // Clean up hashes in bulk (much faster than individual calls)
                if (!empty($skus_to_clean)) {
                    require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
                    foreach ($skus_to_clean as $sku) {
                        ASM_Hash_Manager::delete_hash($sku);
                    }
                }

                // Clean up object term cache
                foreach ($product_ids as $product_id) {
                    clean_object_term_cache($product_id, 'product');
                    clean_post_cache($product_id);
                }

                // Clean taxonomy caches
                clean_term_cache(null, 'product_cat');
                clean_term_cache(null, 'product_tag');

                $results['success'] = true;
                $results['deleted_count'] = $deleted_count;

                // Check if there are more products to delete
                // Exclude products in the "uncategorized" category
                $exclude_uncategorized_remaining = '';
                if ($uncategorized_term_taxonomy_id > 0) {
                    $exclude_uncategorized_remaining = "
                        AND NOT EXISTS (
                            SELECT 1 
                            FROM {$wpdb->term_relationships} tr 
                            WHERE tr.object_id = p.ID 
                            AND tr.term_taxonomy_id = {$uncategorized_term_taxonomy_id}
                        )
                    ";
                }
                
                $remaining_query = "
                    SELECT COUNT(DISTINCT p.ID)
                    FROM {$wpdb->posts} p
                    WHERE p.post_type = 'product'
                    AND p.post_status != 'trash'
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM {$wpdb->postmeta} pm 
                        WHERE pm.post_id = p.ID 
                        AND pm.meta_key = '_is_autosync_product'
                        AND pm.meta_value = 'yes'
                    )
                    {$exclude_uncategorized_remaining}
                ";
                $remaining_count = intval($wpdb->get_var($remaining_query));
                $results['has_more'] = ($remaining_count > 0);

                if ($results['has_more']) {
                    $results['message'] = sprintf(
                        'Successfully deleted %d extra product(s). %d more product(s) remaining.',
                        $deleted_count,
                        $remaining_count
                    );
                } else {
                    $results['message'] = sprintf(
                        'Successfully deleted %d extra product(s). All extra products have been removed.',
                        $deleted_count
                    );
                }
            } else {
                $results['success'] = false;
                $results['message'] = 'Failed to delete products. Database error: ' . $wpdb->last_error;
            }

        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Error deleting extra products: ' . $e->getMessage();
            // ASM_Logger::error('Error deleting extra products', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        } catch (Error $e) {
            $results['success'] = false;
            $results['message'] = 'Fatal error deleting extra products: ' . $e->getMessage();
            // ASM_Logger::error('Fatal error deleting extra products', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
        }

        return $results;
    }
}


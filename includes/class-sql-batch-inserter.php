<?php
/**
 * SQL Batch Inserter
 *
 * Simple raw SQL insertion for WooCommerce products
 * Uses basic INSERT queries (one per record)
 *
 * @package AutoSync_Product_Manager
 * @since 0.0.9
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';
require_once ASM_PLUGIN_DIR . 'includes/class-hash-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-hash-generator.php';
require_once ASM_PLUGIN_DIR . 'includes/class-image-downloader.php';

class ASM_SQL_Batch_Inserter {

    /**
     * Static cache for attributes and terms to avoid repeated lookups
     * Structure: 
     *   attribute_cache: array('attribute_name' => array('id' => X, 'slug' => '...', 'taxonomy' => 'pa_...'))
     *   term_cache: array('taxonomy_name' => array('term_value' => array('term_id' => X, 'term_taxonomy_id' => Y)))
     */
    private static $attribute_cache = array();
    private static $term_cache = array();
    private static $attributes_registered = false;
    
    /**
     * Attribute name to slug mapping
     * Key = Attribute Name, Value = Slug
     */
    private static $attribute_slug_map = array(
        'AspectRatio'       => 'aspectratio',
        'BoltCircle'        => 'boltcircle',
        'BoltCircle2'       => 'boltcircle2',
        'Bore'              => 'bore',
        'Brand'             => 'brand',
        'Diameter'          => 'diameter',
        'Finish'            => 'finish',
        'Gdiameter'         => 'gdiameter',
        'InchWidth'         => 'inchwidth',
        'LugCount'          => 'lugcount',
        'Material'          => 'material',
        'product_niche_tag' => 'product_niche_tag',
        'RimDiameter'       => 'rimdiameter',
        'SectionWidth'      => 'sectionwidth',
        'Structure'         => 'structure',
        'TireLoadRating'    => 'tireloadrating',
        'TireModels'        => 'tiremodels',
        'TireSize'          => 'tiresize',
        'Weight'            => 'weight',
        'WheelSize'         => 'wheelsize',
        'Width'             => 'width',
    );

    /**
     * Reset static caches (call at start of each batch to ensure fresh state)
     */
    public static function reset_caches() {
        self::$attribute_cache = array();
        self::$term_cache = array();
        self::$attributes_registered = false;
    }
    
    /**
     * Batch insert products using raw SQL queries - OPTIMIZED VERSION
     * 
     * @param array $products Array of product data arrays
     * @param array $image_urls Image base URLs
     * @param string $type Product type ('wheel' or 'tire')
     * @return array Results with imported, updated, skipped counts and product IDs
     */
    public static function batch_insert_products($products, $image_urls = array(), $type = 'wheel') {
        global $wpdb;

        // Debug: log at very start to confirm method is called
        $asm_log_file = dirname(__DIR__) . '/asm-debug.log';
        file_put_contents($asm_log_file, '[' . date('Y-m-d H:i:s') . '] batch_insert_products() CALLED - type: ' . $type . ', products count: ' . count($products) . ', log file: ' . $asm_log_file . PHP_EOL, FILE_APPEND);

        // Reset caches at start of batch
        self::reset_caches();
        
        $table_prefix = $wpdb->prefix;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $images_downloaded = 0; // Track image downloads separately
        $product_ids = array();
        $errors = array();
        
        $import_mode = get_option('asm_product_import_mode', 'both');
        $current_user_id = get_current_user_id();
        $now = current_time('mysql');
        $now_gmt = current_time('mysql', 1);
        
        // Prepare bulk insert arrays
        $posts_to_insert = array();
        $posts_to_update = array();
        $meta_to_insert = array();
        $term_relationships = array();
        $skus_to_lookup = array(); // For batch SKU lookup
        $product_data_map = array(); // Map SKU to product data
        $sku_to_product_id_map = array(); // Map SKU to product ID for hash saving
        
        // Step 1: Collect all SKUs and product data (no DB queries yet)
        foreach ($products as $index => $product_item) {
            try {
                // Handle product data - convert array to object
                if (isset($product_item['data'])) {
                    if (is_array($product_item['data'])) {
                        $api_data = (object) $product_item['data'];
                    } else {
                        $api_data = is_object($product_item['data']) ? $product_item['data'] : (object) $product_item['data'];
                    }
                } else {
                    $api_data = is_array($product_item) ? (object) $product_item : $product_item;
                }
                
                if (!is_object($api_data)) {
                    $skipped++;
                    $errors[] = 'Invalid product data format at index ' . $index;
                    continue;
                }
                
                // Get SKU
                $sku_field = ($type === 'tire') ? 'PartNumber' : 'Pn';
                $sku = isset($product_item['sku']) ? $product_item['sku'] : '';
                if (empty($sku)) {
                    $sku = isset($api_data->$sku_field) ? $api_data->$sku_field : '';
                }
                
                if (empty($sku)) {
                    $skipped++;
                    $errors[] = 'Empty SKU at index ' . $index;
                    continue;
                }
                
                // Store SKU for batch lookup
                $skus_to_lookup[] = $sku;
                $product_data_map[$sku] = array(
                    'api_data' => $api_data,
                    'index' => $index
                );
                
            } catch (Exception $e) {
                $skipped++;
                if (count($errors) < 10) {
                    $errors[] = 'Error processing product at index ' . $index . ': ' . $e->getMessage();
                }
                // ASM_Logger::error('Error processing product in batch', array(
                //     'index' => $index,
                //     'error' => $e->getMessage()
                // ));
            }
        }
        
        // Step 2: HASH-BASED CHANGE DETECTION
        // Generate hashes for all products and filter out unchanged ones
        $hash_skipped = 0;
        $products_to_process = array(); // SKUs that need processing
        $product_hashes = array(); // Store hashes and brands for later saving: SKU => array('hash' => ..., 'brand' => ...)
        
        if (!empty($skus_to_lookup)) {
            // Batch get existing hashes
            $existing_hashes = ASM_Hash_Manager::batch_get_hashes($skus_to_lookup);
            
            // Generate hashes and check for matches
            foreach ($skus_to_lookup as $sku) {
                if (!isset($product_data_map[$sku])) {
                    continue;
                }
                
                $api_data = $product_data_map[$sku]['api_data'];
                
                // Generate hash from API data
                $new_hash = ASM_Hash_Generator::generate_hash_from_api_data($api_data, $type);
                
                if (empty($new_hash)) {
                    // Hash generation failed - include in processing
                    $products_to_process[] = $sku;
                    continue;
                }
                
                // Get brand from API data
                $brand = isset($api_data->Brand) ? $api_data->Brand : null;
                
                // Check if hash matches
                $existing_hash = isset($existing_hashes[$sku]) ? $existing_hashes[$sku] : false;
                
                if ($existing_hash && hash_equals($existing_hash, $new_hash)) {
                    // Hash matches - skip product update but still need to download images if missing
                    $hash_skipped++;
                    $skipped++;
                    
                    // Get existing product ID if available - IMPORTANT: Keep this for image downloads
                    $existing_product_id = wc_get_product_id_by_sku($sku);
                    if ($existing_product_id) {
                        // Add to product_ids so images can still be downloaded even if product data hasn't changed
                        if (!in_array($existing_product_id, $product_ids)) {
                        $product_ids[] = $existing_product_id;
                        }
                    }
                    
                    // ASM_Logger::info('Product skipped - hash matches (but will check images)', array(
                    //     'sku' => $sku,
                    //     'type' => $type,
                    //     'brand' => $brand,
                    //     'product_id' => $existing_product_id
                    // ));
                } else {
                    // Hash doesn't match or doesn't exist - include in processing
                    $products_to_process[] = $sku;
                    // Generate price_stock_hash
                    $price_stock_hash = ASM_Hash_Generator::generate_price_stock_hash_from_api_data($api_data);
                    
                    $product_hashes[$sku] = array(
                        'hash' => $new_hash,
                        'price_stock_hash' => $price_stock_hash,
                        'brand' => $brand
                    );
                }
            }
            
            // ASM_Logger::info('Hash checking completed', array(
            //     'total_products' => count($skus_to_lookup),
            //     'hash_skipped' => $hash_skipped,
            //     'to_process' => count($products_to_process)
            // ));
            
            // Update skus_to_lookup to only include products that need processing
            $skus_to_lookup = $products_to_process;
        }
        
        // Step 2.5: PRE-REGISTER ALL ATTRIBUTES AND BATCH PROCESS TERMS (PERFORMANCE OPTIMIZATION)
        // Collect all unique attribute names and values across all products
        $all_attribute_names = array();
        $all_term_data = array(); // Array of arrays: array('taxonomy' => 'pa_brand', 'value' => 'Michelin')
        
        foreach ($skus_to_lookup as $sku) {
            if (!isset($product_data_map[$sku])) {
                continue;
            }
            
            $api_data = $product_data_map[$sku]['api_data'];
            
            // Get attribute data for this product type - all original attributes
            if ($type === 'tire') {
                $attribute_data = array(
                    'P/N' => $api_data->PartNumber ?? '',
                    'Brand' => $api_data->Brand ?? '',
                    'Model' => $api_data->Model ?? '',
                    'Asin' => $api_data->Asin ?? '',
                    'Size' => $api_data->Size ?? '',
                    'Overall Diameter' => $api_data->OverallDiameter ?? '',
                    'Section Width' => $api_data->SectionWidth ?? '',
                    'Overall Width' => $api_data->OverallWidth ?? '',
                    'Load Capacity Single' => $api_data->LoadCapacitySingle ?? '',
                    'Load Range' => $api_data->LoadRange ?? '',
                    'Measuring Rim Width' => $api_data->MeasuringRimWidth ?? '',
                    'Aspect Ratio' => $api_data->AspectRatio ?? '',
                    'Tread Depth' => $api_data->TreadDepth ?? '',
                    'Weight' => $api_data->Weight ?? '',
                    'Max Inflation Pressure' => $api_data->MaxInflationPressure ?? '',
                    'Revolutions Per Mile' => $api_data->RevolutionsPerMile ?? '',
                    'Speed Rating' => $api_data->SpeedRating ?? '',
                    'Load Rating' => $api_data->LoadRating ?? '',
                    'Ean' => $api_data->Ean ?? '',
                    'Warranty' => $api_data->Warranty ?? '',
                    'Sidewall' => $api_data->Sidewall ?? '',
                    'Diameter' => $api_data->Diameter ?? '',
                );
            } else {
                // Construct bolt patterns from LugCount + BoltCircle
                $bolt_patterns = array();
                if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle1)) {
                    $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle1;
                }
                if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle2)) {
                    $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle2;
                }
                
                $finish_parts = array();
                if (!empty($api_data->Finish)) {
                    $finish_parts[] = $api_data->Finish;
                }
                if (!empty($api_data->Color)) {
                    $finish_parts[] = $api_data->Color;
                }
                
                $attribute_data = array(
                    'PN' => $api_data->Pn ?? '',
                    'Brand' => $api_data->Brand ?? '',
                    'Model' => $api_data->ModelName ?? '',
                    'Finish' => implode(' ', $finish_parts),
                    'Size' => (!empty($api_data->Diameter) && !empty($api_data->Width)) ? ($api_data->Diameter . ' x ' . $api_data->Width) : '',
                    'BoltPatterns' => implode(' / ', $bolt_patterns),
                    'Diameter' => $api_data->Diameter ?? '',
                    'Offset' => $api_data->Offset ?? '',
                    'Bore' => $api_data->Bore ?? '',
                    'Load Rating' => $api_data->LoadRating ?? '',
                    'Structure' => $api_data->Structure ?? '',
                    'Material' => $api_data->Material ?? '',
                    'Weight' => $api_data->Weight ?? '',
                    'Width' => $api_data->Width ?? '',
                    'BoltCircle' => $api_data->BoltCircle1 ?? '',
                    'BoltCircle2' => $api_data->BoltCircle2 ?? '',
                    'LugCount' => $api_data->LugCount ?? '',
                    'StyleId' => $api_data->StyleId ?? '',
                );
            }
            
            // Normalize attribute names and collect (use mapped names where available)
            foreach ($attribute_data as $name => $value) {
                if (!empty($value) && $value !== '') {
                    $normalized_name = self::normalize_attribute_name($name);
                    $all_attribute_names[$normalized_name] = true;
                }
            }
        }
        
        // Pre-register all attributes at once
        if (!empty($all_attribute_names)) {
            self::batch_register_attributes(array_keys($all_attribute_names));
        }
        
        // Collect all unique term values for batch processing
        foreach ($skus_to_lookup as $sku) {
            if (!isset($product_data_map[$sku])) {
                continue;
            }
            
            $api_data = $product_data_map[$sku]['api_data'];
            
            // Get attribute data again (same as above) - all original attributes
            if ($type === 'tire') {
                $attribute_data = array(
                    'P/N' => $api_data->PartNumber ?? '',
                    'Brand' => $api_data->Brand ?? '',
                    'Model' => $api_data->Model ?? '',
                    'Asin' => $api_data->Asin ?? '',
                    'Size' => $api_data->Size ?? '',
                    'Overall Diameter' => $api_data->OverallDiameter ?? '',
                    'Section Width' => $api_data->SectionWidth ?? '',
                    'Overall Width' => $api_data->OverallWidth ?? '',
                    'Load Capacity Single' => $api_data->LoadCapacitySingle ?? '',
                    'Load Range' => $api_data->LoadRange ?? '',
                    'Measuring Rim Width' => $api_data->MeasuringRimWidth ?? '',
                    'Aspect Ratio' => $api_data->AspectRatio ?? '',
                    'Tread Depth' => $api_data->TreadDepth ?? '',
                    'Weight' => $api_data->Weight ?? '',
                    'Max Inflation Pressure' => $api_data->MaxInflationPressure ?? '',
                    'Revolutions Per Mile' => $api_data->RevolutionsPerMile ?? '',
                    'Speed Rating' => $api_data->SpeedRating ?? '',
                    'Load Rating' => $api_data->LoadRating ?? '',
                    'Ean' => $api_data->Ean ?? '',
                    'Warranty' => $api_data->Warranty ?? '',
                    'Sidewall' => $api_data->Sidewall ?? '',
                    'Diameter' => $api_data->Diameter ?? '',
                );
            } else {
                // Construct bolt patterns from LugCount + BoltCircle
                $bolt_patterns = array();
                if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle1)) {
                    $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle1;
                }
                if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle2)) {
                    $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle2;
                }
                
                $finish_parts = array();
                if (!empty($api_data->Finish)) {
                    $finish_parts[] = $api_data->Finish;
                }
                if (!empty($api_data->Color)) {
                    $finish_parts[] = $api_data->Color;
                }
                
                $attribute_data = array(
                    'PN' => $api_data->Pn ?? '',
                    'Brand' => $api_data->Brand ?? '',
                    'Model' => $api_data->ModelName ?? '',
                    'Finish' => implode(' ', $finish_parts),
                    'Size' => (!empty($api_data->Diameter) && !empty($api_data->Width)) ? ($api_data->Diameter . ' x ' . $api_data->Width) : '',
                    'BoltPatterns' => implode(' / ', $bolt_patterns),
                    'Diameter' => $api_data->Diameter ?? '',
                    'Offset' => $api_data->Offset ?? '',
                    'Bore' => $api_data->Bore ?? '',
                    'Load Rating' => $api_data->LoadRating ?? '',
                    'Structure' => $api_data->Structure ?? '',
                    'Material' => $api_data->Material ?? '',
                    'Weight' => $api_data->Weight ?? '',
                    'Width' => $api_data->Width ?? '',
                    'BoltCircle' => $api_data->BoltCircle1 ?? '',
                    'BoltCircle2' => $api_data->BoltCircle2 ?? '',
                    'LugCount' => $api_data->LugCount ?? '',
                    'StyleId' => $api_data->StyleId ?? '',
                );
            }
            
            // Normalize attribute names and collect term data (use mapped names where available)
            foreach ($attribute_data as $name => $value) {
                if (!empty($value) && $value !== '') {
                    $normalized_name = self::normalize_attribute_name($name);
                    $attr_cache = self::get_cached_attribute($normalized_name);
                    if ($attr_cache) {
                        $all_term_data[] = array(
                            'taxonomy' => $attr_cache['taxonomy'],
                            'value' => strval($value)
                        );
                    }
                }
            }
        }
        
        // Batch process all terms at once
        if (!empty($all_term_data)) {
            self::batch_get_or_create_terms($all_term_data);
        }
        
        // If all products were skipped due to hash matches, we still need to download images
        // So we continue processing to download images for hash-skipped products
        // But we skip the actual product insert/update steps
        $all_hash_skipped = empty($skus_to_lookup);
        
        // Step 3: Batch lookup all SKUs in ONE query (MAJOR OPTIMIZATION)
        $existing_products = array(); // SKU => product_id map
        if (!empty($skus_to_lookup)) {
            $existing_products = self::batch_get_product_ids_by_sku($skus_to_lookup, $table_prefix);
            // ASM_Logger::info('Batch SKU lookup completed', array(
            //     'total_skus' => count($skus_to_lookup),
            //     'existing_products_found' => count($existing_products)
            // ));
        } elseif ($all_hash_skipped) {
            // If all products were hash-skipped, we still need their product IDs for image downloads
            // Get product IDs for all hash-skipped products
            $all_skus = array_keys($product_data_map);
            if (!empty($all_skus)) {
                $existing_products = self::batch_get_product_ids_by_sku($all_skus, $table_prefix);
            //     ASM_Logger::info('Batch SKU lookup for hash-skipped products', array(
            //         'total_skus' => count($all_skus),
            //     'existing_products_found' => count($existing_products)
            // ));
            }
        }
        
        // Step 4: Pre-compute slugs and detect collisions
        // Slug = title + size + bolt pattern + offset. SKU appended only on collision.
        // Format: /brand-model-finish-diameterxwidth-boltpattern-offset/

        // First pass: build base slug per SKU, count occurrences, track first owner
        $slug_count = array(); // base_slug => count
        $slug_first_owner = array(); // base_slug => first sku to claim it
        $sku_slug_data = array(); // sku => base_slug

        $slug_log_count = 0; // Log first 3 products for debugging
        foreach ($skus_to_lookup as $sku) {
            if (!isset($product_data_map[$sku])) {
                continue;
            }
            $api_data = $product_data_map[$sku]['api_data'];
            $existing_id = isset($existing_products[$sku]) ? $existing_products[$sku] : 0;
            if (($existing_id && $import_mode === 'create') || (!$existing_id && $import_mode === 'update')) {
                continue;
            }

            $title = self::format_product_title($api_data, $type);

            // Construct bolt pattern from LugCount + BoltCircle (API doesn't provide BoltPattern directly)
            $bolt_pattern_1 = '';
            if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle1)) {
                $bolt_pattern_1 = $api_data->LugCount . 'x' . $api_data->BoltCircle1;
            }

            // Build slug: title + size + bolt pattern + offset (each included only if available)
            // Format: /brand-model-finish-diameterxwidth-boltpattern-offset/
            $slug_parts = array($title);
            if (!empty($api_data->Diameter) && !empty($api_data->Width)) {
                $slug_parts[] = $api_data->Diameter . 'x' . $api_data->Width;
            }
            if (!empty($bolt_pattern_1)) {
                $slug_parts[] = $bolt_pattern_1;
            }
            if (!empty($api_data->Offset) || (isset($api_data->Offset) && $api_data->Offset === '0')) {
                $slug_parts[] = 'et' . $api_data->Offset;
            }
            $base_slug = sanitize_title(implode(' ', $slug_parts));

            // Log first 3 products to custom log file
            if ($slug_log_count < 3) {
                $log_entry = '[' . date('Y-m-d H:i:s') . '] SKU: ' . $sku . ' | Slug: ' . $base_slug . ' | BoltPattern: ' . ($bolt_pattern_1 ?: 'NONE') . ' | LugCount: ' . ($api_data->LugCount ?? 'N/A') . ' | BoltCircle1: ' . ($api_data->BoltCircle1 ?? 'N/A') . PHP_EOL;
                file_put_contents($asm_log_file, $log_entry, FILE_APPEND);
                $slug_log_count++;
            }

            $sku_slug_data[$sku] = $base_slug;

            if (!isset($slug_count[$base_slug])) {
                $slug_count[$base_slug] = 0;
                $slug_first_owner[$base_slug] = $sku;
            }
            $slug_count[$base_slug]++;
        }

        // Query DB for slugs already taken by products outside this batch
        $db_taken_slugs = array();
        if (!empty($slug_count)) {
            $slugs_to_check = array_keys($slug_count);
            $placeholders = implode(',', array_fill(0, count($slugs_to_check), '%s'));
            $existing_ids_in_batch = array_filter(array_values($existing_products));
            $exclude_clause = '';
            if (!empty($existing_ids_in_batch)) {
                $exclude_ids = implode(',', array_map('intval', $existing_ids_in_batch));
                $exclude_clause = " AND ID NOT IN ({$exclude_ids})";
            }
            $db_slugs = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_name FROM {$wpdb->posts}
                     WHERE post_name IN ({$placeholders})
                     AND post_type = 'product'
                     AND post_status != 'trash'{$exclude_clause}",
                    $slugs_to_check
                )
            );
            if ($db_slugs) {
                foreach ($db_slugs as $db_slug) {
                    $db_taken_slugs[$db_slug] = true;
                }
            }
        }

        // Step 5: Categorize products and prepare data (with cached category/tag lookups)
        $category_cache = array(); // Cache for category lookups
        $tag_cache = array(); // Cache for tag lookups
        foreach ($skus_to_lookup as $sku) {
            if (!isset($product_data_map[$sku])) {
                continue;
            }

            $api_data = $product_data_map[$sku]['api_data'];
            $existing_id = isset($existing_products[$sku]) ? $existing_products[$sku] : 0;

            // Check import mode
            if ($existing_id && $import_mode === 'create') {
                $skipped++;
                continue;
            }
            if (!$existing_id && $import_mode === 'update') {
                $skipped++;
                continue;
            }

            // Determine slug: base slug = title + size + bolt pattern + offset
            // Append SKU only if slug collides (external DB or within batch)
            $title = self::format_product_title($api_data, $type);
            $base_slug = isset($sku_slug_data[$sku]) ? $sku_slug_data[$sku] : sanitize_title($title);

            $is_unique = !isset($db_taken_slugs[$base_slug])
                && ($slug_count[$base_slug] <= 1 || $slug_first_owner[$base_slug] === $sku);

            if ($is_unique) {
                $post_name = $base_slug;
            } else {
                // Rebuild slug parts + SKU for collision fallback
                $slug_parts = array($title);
                if (!empty($api_data->Diameter) && !empty($api_data->Width)) {
                    $slug_parts[] = $api_data->Diameter . 'x' . $api_data->Width;
                }
                // Construct bolt pattern from LugCount + BoltCircle1
                if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle1)) {
                    $slug_parts[] = $api_data->LugCount . 'x' . $api_data->BoltCircle1;
                }
                if (!empty($api_data->Offset) || (isset($api_data->Offset) && $api_data->Offset === '0')) {
                    $slug_parts[] = 'et' . $api_data->Offset;
                }
                $post_name = sanitize_title(implode(' ', $slug_parts) . ' ' . $sku);
            }
            $description = self::get_product_description($api_data, $type);
            
            // Use index for placeholder to track which product
            $product_index = $existing_id ? null : count($posts_to_insert);
            
            if ($existing_id) {
                // Update existing product
                $posts_to_update[] = array(
                    'id' => $existing_id,
                    'title' => $title,
                    'description' => $description,
                    'post_name' => $post_name,
                    'sku' => $sku,
                    'api_data' => $api_data
                );
            } else {
                // Insert new product
                $posts_to_insert[] = array(
                    'title' => $title,
                    'description' => $description,
                    'post_name' => $post_name,
                    'sku' => $sku,
                    'api_data' => $api_data,
                    'index' => $product_index
                );
            }
            
            // Prepare meta fields
            $meta_fields = self::get_product_meta_fields($api_data, $image_urls, $type, $sku);
            
            // Add product attributes (WooCommerce stores these as serialized _product_attributes meta)
            // Returns array with 'attributes' (serialized) and 'term_relationships' (term taxonomy IDs)
            $product_attributes_result = self::get_product_attributes_serialized($api_data, $type);
            if (!empty($product_attributes_result) && isset($product_attributes_result['attributes'])) {
                $meta_fields['_product_attributes'] = $product_attributes_result['attributes'];
                
                // Add attribute term relationships to term_relationships array
                if (!empty($product_attributes_result['term_relationships'])) {
                    foreach ($product_attributes_result['term_relationships'] as $term_taxonomy_id) {
                        $term_relationships[] = array(
                            'product_id' => $existing_id ? $existing_id : 'NEW_' . $product_index,
                            'term_taxonomy_id' => $term_taxonomy_id,
                            'is_new' => !$existing_id,
                            'index' => $product_index
                        );
                    }
                }
            }
            
            // Store meta for bulk insert/update
            foreach ($meta_fields as $meta_key => $meta_value) {
                if ($meta_value === '' || $meta_value === null) {
                    continue;
                }
                // Serialize arrays/objects for meta storage
                if (is_array($meta_value) || is_object($meta_value)) {
                    $meta_value = maybe_serialize($meta_value);
                }
                $meta_to_insert[] = array(
                    'product_id' => $existing_id ? $existing_id : 'NEW_' . $product_index,
                    'meta_key' => $meta_key,
                    'meta_value' => $meta_value,
                    'is_new' => !$existing_id,
                    'index' => $product_index,
                    'sku' => $sku
                );
            }
            
            // Get categories (with caching)
            $category_ids = self::get_or_create_categories_cached($api_data, $type, $category_cache);
            foreach ($category_ids as $cat_id) {
                $term_relationships[] = array(
                    'product_id' => $existing_id ? $existing_id : 'NEW_' . $product_index,
                    'term_taxonomy_id' => $cat_id,
                    'is_new' => !$existing_id,
                    'index' => $product_index
                );
            }
            
            // Get tags (VehicleTypeTags and SegmentTags)
            $tag_ids = self::get_or_create_tags_cached($api_data, $tag_cache);
            foreach ($tag_ids as $tag_id) {
                $term_relationships[] = array(
                    'product_id' => $existing_id ? $existing_id : 'NEW_' . $product_index,
                    'term_taxonomy_id' => $tag_id,
                    'is_new' => !$existing_id,
                    'index' => $product_index
                );
            }
            
            // Set product type taxonomy term (Simple product)
            // WooCommerce uses product_type taxonomy with 'simple' term
            // Create relation in wp_term_relationships using existing simple term from wp_terms
            $product_type_term_id = self::get_or_create_product_type_term('simple');
            if ($product_type_term_id) {
                $term_relationships[] = array(
                    'product_id' => $existing_id ? $existing_id : 'NEW_' . $product_index,
                    'term_taxonomy_id' => $product_type_term_id,
                    'is_new' => !$existing_id,
                    'index' => $product_index
                );
            }
            
            // Store product ID reference for existing products
            if ($existing_id) {
                $product_ids[] = $existing_id;
            }
        }
        
        // Execute bulk inserts
        try {
            // Start transaction
            $wpdb->query('START TRANSACTION');
            
            // Track which product IDs were actually updated/created (for image download logic)
            $actually_updated_product_ids = array();
            
            // 1. Bulk INSERT new posts
            if (!empty($posts_to_insert)) {
                $inserted_ids = self::bulk_insert_posts($posts_to_insert, $current_user_id, $now, $now_gmt);
                
                if (empty($inserted_ids)) {
                    throw new Exception('Failed to insert posts. No IDs returned.');
                }
                
                if (count($inserted_ids) !== count($posts_to_insert)) {
                    // ASM_Logger::warning('Post count mismatch', array(
                    //     'expected' => count($posts_to_insert),
                    //     'got' => count($inserted_ids),
                    //     'inserted_ids' => $inserted_ids
                    // ));
                    // Continue with what we got instead of throwing
                }
                
                // Map new product IDs to meta and relationships using index
                // Batch update GUIDs instead of individual updates
                $guid_updates = array();
                foreach ($inserted_ids as $idx => $new_id) {
                    if (!isset($posts_to_insert[$idx])) {
                        continue; // Skip if index mismatch
                    }
                    
                    $placeholder = 'NEW_' . $posts_to_insert[$idx]['index'];
                    $guid_updates[$new_id] = esc_sql(site_url() . '/?p=' . $new_id);
                    
                    // Update meta placeholders
                    foreach ($meta_to_insert as $meta_idx => $meta) {
                        if ($meta['product_id'] === $placeholder) {
                            $meta_to_insert[$meta_idx]['product_id'] = $new_id;
                        }
                    }
                    
                    // Update relationships placeholders
                    foreach ($term_relationships as $rel_idx => $rel) {
                        if ($rel['product_id'] === $placeholder) {
                            $term_relationships[$rel_idx]['product_id'] = $new_id;
                        }
                    }
                    
                    $product_ids[] = $new_id;
                    $actually_updated_product_ids[$new_id] = true; // Track as updated
                    $imported++; // Count AFTER successful insert
                    
                    // Track SKU to product ID mapping for hash saving
                    $sku = isset($posts_to_insert[$idx]['sku']) ? $posts_to_insert[$idx]['sku'] : '';
                    if ($sku) {
                        $sku_to_product_id_map[$sku] = $new_id;
                    }
                }
                
                // Batch update GUIDs in one query
                if (!empty($guid_updates)) {
                    self::batch_update_guids($guid_updates);
                }
            }
            
            // 2. Bulk UPDATE existing posts
            if (!empty($posts_to_update)) {
                // ASM_Logger::info('Starting post updates', array(
                //     'posts_to_update_count' => count($posts_to_update),
                //     'sample_skus' => array_slice(array_column($posts_to_update, 'sku'), 0, 5)
                // ));
                
                $updated_count = self::bulk_update_posts($posts_to_update, $now, $now_gmt);
                $updated += $updated_count; // Count AFTER successful update
                
                // ASM_Logger::info('Post updates completed', array(
                //     'updated_count' => $updated_count,
                //     'total_updated' => $updated
                // ));
                
                // Add updated product IDs to product_ids array and track SKU mapping
                foreach ($posts_to_update as $post_update) {
                    if (!in_array($post_update['id'], $product_ids)) {
                        $product_ids[] = $post_update['id'];
                    }
                    
                    $actually_updated_product_ids[$post_update['id']] = true; // Track as updated
                    
                    // Track SKU to product ID mapping for hash saving
                    $sku = isset($post_update['sku']) ? $post_update['sku'] : '';
                    if ($sku) {
                        $sku_to_product_id_map[$sku] = $post_update['id'];
                    }
                }
            }
            
            // 3. Bulk INSERT/UPDATE meta (handles both new and existing products)
            if (!empty($meta_to_insert)) {
                // ASM_Logger::info('Starting meta updates', array(
                //     'meta_to_insert_count' => count($meta_to_insert),
                //     'unique_product_ids' => count(array_unique(array_column($meta_to_insert, 'product_id')))
                // ));
                
                self::bulk_insert_meta($meta_to_insert, $table_prefix);
                
                // ASM_Logger::info('Meta updates completed');
            }
            
            // 4. Remove old product_type terms from existing products before adding new ones
            // This ensures products don't have multiple product_type terms (e.g., 'simple' and 'external')
            if (!empty($posts_to_update)) {
                $update_product_ids = array_column($posts_to_update, 'id');
                if (!empty($update_product_ids)) {
                    self::remove_old_product_type_terms($update_product_ids, $table_prefix);
                }
            }
            
            // 5. Bulk INSERT term relationships
            if (!empty($term_relationships)) {
                self::bulk_insert_term_relationships($term_relationships, $table_prefix);
            }
            
            // 6. Ensure product_type taxonomy is set for all processed products (safety check)
            // This fixes cases where taxonomy relationship might not have been created
            if (!empty($product_ids)) {
                self::ensure_product_type_taxonomy($product_ids, $table_prefix);
            }
            
            // 7. Batch save hashes for all processed products
            if (!empty($product_hashes) && !empty($sku_to_product_id_map)) {
                $hashes_to_save = array();
                
                // Collect hashes with product IDs using SKU mapping
                foreach ($product_hashes as $sku => $hash_data) {
                    if (isset($sku_to_product_id_map[$sku])) {
                        $hash = is_array($hash_data) ? $hash_data['hash'] : $hash_data;
                        $price_stock_hash = is_array($hash_data) && isset($hash_data['price_stock_hash']) ? $hash_data['price_stock_hash'] : null;
                        $brand = is_array($hash_data) && isset($hash_data['brand']) ? $hash_data['brand'] : null;
                        
                        $hashes_to_save[] = array(
                            'sku' => $sku,
                            'hash' => $hash,
                            'price_stock_hash' => $price_stock_hash,
                            'product_id' => $sku_to_product_id_map[$sku],
                            'product_type' => $type,
                            'brand' => $brand
                        );
                    }
                }
                
                if (!empty($hashes_to_save)) {
                    $hashes_saved = ASM_Hash_Manager::batch_save_hashes($hashes_to_save);
                    // ASM_Logger::info('Batch hashes saved', array(
                    //     'hashes_saved' => $hashes_saved,
                    //     'total_hashes' => count($hashes_to_save)
                    // ));
                }
            }
            
            // 6. Download and attach images if download mode is enabled
            // Smart image download logic:
            // 1. Products that were created/updated (hash didn't match) - ALWAYS check/download images
            // 2. Hash-skipped products that are MISSING images - download images
            // 3. Hash-skipped products that already have images - SKIP (no unnecessary updates)
            $image_mode = get_option('asm_image_mode', 'external');
            if ($image_mode === 'download') {
                // Get product IDs that were actually updated/created (not hash-skipped)
                $updated_product_ids = array_keys($actually_updated_product_ids);
                
                // ALWAYS check ALL products in the current batch for missing images
                // This ensures every product in every page/batch is checked, regardless of hash status
                $hash_skipped_without_images = array();
                
                // Get all product IDs from product_data_map (contains ALL products in current batch)
                $all_skus_from_map = array_keys($product_data_map);
                if (!empty($all_skus_from_map)) {
                    // Get product IDs for all products in this batch
                    $all_existing_products = self::batch_get_product_ids_by_sku($all_skus_from_map, $table_prefix);
                    
                    // Identify products that are NOT in the updated list (these are hash-skipped)
                    $hash_skipped_product_ids = array();
                    foreach ($all_existing_products as $sku => $prod_id) {
                        if (!isset($actually_updated_product_ids[$prod_id])) {
                            $hash_skipped_product_ids[] = $prod_id;
                        }
                    }
                    
                    if (!empty($hash_skipped_product_ids)) {
                        // Batch check which hash-skipped products are missing images
                        $products_with_images = self::batch_check_products_have_images($hash_skipped_product_ids);
                        
                        // Only include hash-skipped products that are missing images
                        foreach ($hash_skipped_product_ids as $prod_id) {
                            if (!isset($products_with_images[$prod_id]) || !$products_with_images[$prod_id]) {
                                $hash_skipped_without_images[] = $prod_id;
                            }
                        }
                    }
                }
                
                // Combine: updated products (always process) + hash-skipped products missing images
                $all_product_ids_for_images = array_unique(array_merge($updated_product_ids, $hash_skipped_without_images));
                
                // ASM_Logger::info('Preparing image downloads', array(
                //     'updated_product_ids_count' => count($updated_product_ids),
                //     'hash_skipped_total' => isset($hash_skipped) ? $hash_skipped : 0,
                //     'hash_skipped_missing_images_count' => count($hash_skipped_without_images),
                //     'hash_skipped_with_images_skipped' => (isset($hash_skipped) ? $hash_skipped : 0) - count($hash_skipped_without_images),
                //     'all_product_ids_count' => count($all_product_ids_for_images),
                //     'product_data_map_count' => count($product_data_map),
                //     'total_products_in_batch' => count($products),
                //     'note' => 'Checking ALL products in batch for missing images (updated + hash-skipped)'
                // ));
                
                if (!empty($all_product_ids_for_images)) {
                    $image_download_result = self::download_images_for_products($all_product_ids_for_images, $products, $image_urls, $type, $product_data_map);
                    if (isset($image_download_result['images_updated'])) {
                        $images_downloaded = $image_download_result['images_updated'];
                    }
                } else {
                    // ASM_Logger::info('No products need image downloads', array(
                    //     'all_have_images' => true,
                    //     'total_products' => count($product_ids),
                    //     'hash_skipped' => isset($hash_skipped) ? $hash_skipped : 0,
                    //     'updated_count' => count($updated_product_ids)
                    // ));
                }
            }
            
            // 7. Update ALL WooCommerce lookup tables and relationships
            if (!empty($product_ids)) {
                // Update wp_wc_product_meta_lookup (for SKU searches, filtering, etc.)
                self::update_woocommerce_lookup_table($product_ids, $table_prefix);
                
                // Update wp_wc_product_attributes_lookup (WooCommerce 6.3+ for attribute filtering)
                self::update_woocommerce_attributes_lookup_table($product_ids, $table_prefix);
                
                // Update term taxonomy counts (required for proper category/tag counts)
                self::update_term_taxonomy_counts($term_relationships, $table_prefix);
                
                // Clean term cache (required for proper term relationships)
                self::clean_term_cache_for_products($product_ids);
                
                // ASM_Logger::info('All WooCommerce tables and relationships updated', array(
                //     'product_ids_count' => count($product_ids),
                //     'term_relationships_count' => count($term_relationships)
                // ));
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // ASM_Logger::info('SQL batch insert completed', array(
            //     'imported' => $imported,
            //     'updated' => $updated,
            //     'skipped' => $skipped,
            //     'hash_skipped' => isset($hash_skipped) ? $hash_skipped : 0,
            //     'total_products' => count($products),
            //     'posts_to_insert_count' => count($posts_to_insert),
            //     'posts_to_update_count' => count($posts_to_update),
            //     'meta_to_insert_count' => count($meta_to_insert),
            //     'term_relationships_count' => count($term_relationships)
            // ));
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            $error_msg = 'Batch insert failed: ' . $e->getMessage();
            $errors[] = $error_msg;
            // ASM_Logger::error('SQL batch insert failed', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'posts_to_insert_count' => count($posts_to_insert),
            //     'posts_to_update_count' => count($posts_to_update)
            // ));
            
            // Reset counts on failure
            $imported = 0;
            $updated = 0;
        }
        
        // Adjust skipped count: if images were downloaded for hash-skipped products, 
        // they shouldn't be counted as fully "skipped" - count them as "updated" instead
        $adjusted_skipped = $skipped;
        $adjusted_updated = $updated;
        
        if (isset($images_downloaded) && $images_downloaded > 0) {
            // Products that had images downloaded but were hash-skipped should be counted as "updated"
            // (They were hash-skipped for content but had images updated)
            $hash_skipped_with_images = min($images_downloaded, isset($hash_skipped) ? $hash_skipped : 0);
            $adjusted_updated += $hash_skipped_with_images;
            $adjusted_skipped = max(0, $skipped - $hash_skipped_with_images);
        }
        
        return array(
            'imported' => (int) $imported,
            'updated' => (int) $adjusted_updated,
            'skipped' => (int) $adjusted_skipped,
            'hash_skipped' => isset($hash_skipped) ? (int) $hash_skipped : 0,
            'images_updated' => isset($images_downloaded) ? (int) $images_downloaded : 0, // Products that had images downloaded
            'product_ids' => $product_ids,
            'errors' => array_slice($errors, 0, 20) // Limit errors
        );
    }
    
    /**
     * OPTIMIZED: Multi-row INSERT for posts - Single query for all posts
     */
    private static function bulk_insert_posts($posts, $user_id, $now, $now_gmt) {
        global $wpdb;
        
        $table = $wpdb->posts;
        $inserted_ids = array();
        
        if (empty($posts)) {
            return array();
        }
        
        // Process in chunks of 1000 to avoid query size limits
        $chunk_size = 1000;
        $chunks = array_chunk($posts, $chunk_size);
        
        foreach ($chunks as $chunk) {
            $values = array();
            $now_escaped = esc_sql($now);
            $now_gmt_escaped = esc_sql($now_gmt);
            $site_url_escaped = esc_sql(site_url());
            
            // Build multi-row VALUES clause
            foreach ($chunk as $post) {
                $title = esc_sql($post['title']);
                $description = esc_sql($post['description']);
                $post_name = esc_sql($post['post_name']);
                $guid_placeholder = esc_sql($site_url_escaped . '/?p=PLACEHOLDER');
                
                $values[] = "({$user_id}, '{$now_escaped}', '{$now_gmt_escaped}', '{$description}', '{$title}', '', 'publish', 'closed', 'closed', '', '{$post_name}', '', '', '{$now_escaped}', '{$now_gmt_escaped}', '', 0, '{$guid_placeholder}', 0, 'product', '', 0)";
            }
            
            if (empty($values)) {
                continue;
            }
            
            // Single INSERT query for all posts in chunk
            $values_sql = implode(',', $values);
            $sql = "INSERT INTO {$table} (
                post_author, post_date, post_date_gmt, post_content, post_title,
                post_excerpt, post_status, comment_status, ping_status, post_password,
                post_name, to_ping, pinged, post_modified, post_modified_gmt,
                post_content_filtered, post_parent, guid, menu_order, post_type,
                post_mime_type, comment_count
            ) VALUES {$values_sql}";
            
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                // Get inserted IDs (MySQL returns first insert_id, then we calculate the rest)
                $first_id = $wpdb->insert_id;
                $count = count($chunk);
                
                for ($i = 0; $i < $count; $i++) {
                    $inserted_ids[] = $first_id + $i;
                }
            } else {
                // ASM_Logger::error('Failed to bulk insert posts', array(
                //     'error' => $wpdb->last_error,
                //     'chunk_size' => count($chunk)
                // ));
            }
        }
        
        return $inserted_ids;
    }
    
    /**
     * Batch update GUIDs for newly inserted posts
     */
    private static function batch_update_guids($guid_updates) {
        global $wpdb;
        
        if (empty($guid_updates)) {
            return;
        }
        
        $table = $wpdb->posts;
        $site_url_escaped = esc_sql(site_url());
        
        // Build CASE statement for batch UPDATE
        $cases = array();
        $ids = array();
        
        foreach ($guid_updates as $post_id => $guid) {
            $post_id = intval($post_id);
            $guid_escaped = esc_sql($guid);
            $cases[] = "WHEN {$post_id} THEN '{$guid_escaped}'";
            $ids[] = $post_id;
        }
        
        if (empty($cases)) {
            return;
        }
        
        $ids_sql = implode(',', $ids);
        $cases_sql = implode(' ', $cases);
        
        $sql = "UPDATE {$table} SET guid = CASE ID {$cases_sql} END WHERE ID IN ({$ids_sql})";
        
        $wpdb->query($sql);
    }
    
    /**
     * OPTIMIZED: Batch UPDATE posts using CASE statements - Single query for all updates
     */
    private static function bulk_update_posts($posts, $now, $now_gmt) {
        global $wpdb;
        
        $table = $wpdb->posts;
        $updated_count = 0;
        
        if (empty($posts)) {
            return 0;
        }
        
        // Process in chunks of 500 to avoid query size limits
        $chunk_size = 500;
        $chunks = array_chunk($posts, $chunk_size);
        
        foreach ($chunks as $chunk) {
            // Build CASE statements for each field
            $title_cases = array();
            $content_cases = array();
            $name_cases = array();
            $ids = array();
            
            $now_escaped = esc_sql($now);
            $now_gmt_escaped = esc_sql($now_gmt);
            
            foreach ($chunk as $post) {
                $product_id = intval($post['id']);
                $title = esc_sql($post['title']);
                $description = esc_sql($post['description']);
                $post_name = esc_sql($post['post_name']);
                
                $title_cases[] = "WHEN {$product_id} THEN '{$title}'";
                $content_cases[] = "WHEN {$product_id} THEN '{$description}'";
                $name_cases[] = "WHEN {$product_id} THEN '{$post_name}'";
                $ids[] = $product_id;
            }
            
            if (empty($ids)) {
                continue;
            }
            
            $ids_sql = implode(',', $ids);
            $title_sql = implode(' ', $title_cases);
            $content_sql = implode(' ', $content_cases);
            $name_sql = implode(' ', $name_cases);
            
            // Single UPDATE query with CASE statements
            $sql = "UPDATE {$table} SET 
                    post_title = CASE ID {$title_sql} END,
                    post_content = CASE ID {$content_sql} END,
                    post_name = CASE ID {$name_sql} END,
                    post_modified = '{$now_escaped}',
                    post_modified_gmt = '{$now_gmt_escaped}'
                    WHERE ID IN ({$ids_sql}) AND post_type = 'product'";
            
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                $updated_count += $result;
            } else {
                // ASM_Logger::error('Failed to batch update posts', array(
                //     'error' => $wpdb->last_error,
                //     'chunk_size' => count($chunk)
                // ));
            }
        }
        
        return $updated_count;
    }
    
    /**
     * Batch get product IDs by SKUs - OPTIMIZED: Single query for all SKUs
     * 
     * @param array $skus Array of SKUs to lookup
     * @param string $table_prefix Database table prefix
     * @return array Associative array: SKU => product_id
     */
    private static function batch_get_product_ids_by_sku($skus, $table_prefix) {
        global $wpdb;
        
        if (empty($skus)) {
            return array();
        }
        
        $meta_table = $table_prefix . 'postmeta';
        $posts_table = $wpdb->posts;
        
        // Escape SKUs for IN clause (wpdb->prepare doesn't work well with IN)
        $escaped_skus = array_map(function($sku) use ($wpdb) {
            return $wpdb->prepare('%s', $sku);
        }, $skus);
        
        $skus_sql = implode(',', $escaped_skus);
        
        // Single query to get all product IDs by SKU
        $sql = "SELECT pm.meta_value as sku, pm.post_id 
            FROM {$meta_table} pm
            INNER JOIN {$posts_table} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value IN ({$skus_sql})
            AND p.post_type = 'product'
            AND p.post_status != 'trash'";
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        // Convert to associative array: SKU => product_id
        $product_map = array();
        if ($results) {
            foreach ($results as $row) {
                $product_map[$row['sku']] = intval($row['post_id']);
            }
        }
        
        return $product_map;
    }
    
    /**
     * Get product ID by SKU using raw SQL (legacy method - kept for compatibility)
     */
    private static function get_product_id_by_sku($sku, $table_prefix) {
        global $wpdb;
        
        if (empty($sku)) {
            return 0;
        }
        
        $sku_escaped = esc_sql($sku);
        $meta_table = $table_prefix . 'postmeta';
        $posts_table = $wpdb->posts;
        
        // Query to find product ID by SKU - WooCommerce stores SKU in _sku meta key
        $sql = $wpdb->prepare(
            "SELECT pm.post_id 
            FROM {$meta_table} pm
            INNER JOIN {$posts_table} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value = %s
            AND p.post_type = 'product'
            AND p.post_status != 'trash'
            LIMIT 1",
            $sku
        );
        
        $product_id = $wpdb->get_var($sql);
        
        return $product_id ? intval($product_id) : 0;
    }
    
    /**
     * OPTIMIZED: Multi-row INSERT/UPDATE meta using INSERT ... ON DUPLICATE KEY UPDATE
     * Groups by product_id and meta_key to minimize queries
     */
    private static function bulk_insert_meta($meta_array, $table_prefix) {
        global $wpdb;
        
        $table = $table_prefix . 'postmeta';
        
        if (empty($meta_array)) {
            return;
        }
        
        // Filter out placeholder IDs
        $valid_meta = array();
        foreach ($meta_array as $meta) {
            if (is_numeric($meta['product_id']) && !(is_string($meta['product_id']) && strpos($meta['product_id'], 'NEW_') === 0)) {
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
            
            // Multi-row INSERT
            $values_sql = implode(',', $values);
            $insert_sql = "INSERT INTO {$table} (post_id, meta_key, meta_value) VALUES {$values_sql}";
            
            $result = $wpdb->query($insert_sql);
            
            if ($result === false) {
                // ASM_Logger::error('Failed to bulk insert meta', array(
                //     'error' => $wpdb->last_error,
                //     'chunk_size' => count($chunk)
                // ));
            }
        }
    }
    
    /**
     * OPTIMIZED: Multi-row INSERT for term relationships - Single query for all relationships
     */
    private static function bulk_insert_term_relationships($relationships, $table_prefix) {
        global $wpdb;
        
        $table = $table_prefix . 'term_relationships';
        
        if (empty($relationships)) {
            return;
        }
        
        // Filter valid relationships
        $valid_rels = array();
        foreach ($relationships as $rel) {
            $product_id = isset($rel['product_id']) && is_numeric($rel['product_id']) ? intval($rel['product_id']) : 0;
            $term_taxonomy_id = isset($rel['term_taxonomy_id']) && is_numeric($rel['term_taxonomy_id']) ? intval($rel['term_taxonomy_id']) : 0;
            
            if ($product_id > 0 && $term_taxonomy_id > 0) {
                // Skip placeholder IDs
                if (!(is_string($rel['product_id']) && strpos($rel['product_id'], 'NEW_') === 0)) {
                    $valid_rels[] = array(
                        'product_id' => $product_id,
                        'term_taxonomy_id' => $term_taxonomy_id
                    );
                }
            }
        }
        
        if (empty($valid_rels)) {
            return;
        }
        
        // Process in chunks of 1000 to avoid query size limits
        $chunk_size = 1000;
        $chunks = array_chunk($valid_rels, $chunk_size);
        
        foreach ($chunks as $chunk) {
            $values = array();
            
            foreach ($chunk as $rel) {
                $product_id = intval($rel['product_id']);
                $term_taxonomy_id = intval($rel['term_taxonomy_id']);
                $values[] = "({$product_id}, {$term_taxonomy_id}, 0)";
            }
            
            if (empty($values)) {
                continue;
            }
            
            // Multi-row INSERT IGNORE (prevents duplicates)
            $values_sql = implode(',', $values);
            $sql = "INSERT IGNORE INTO {$table} (object_id, term_taxonomy_id, term_order) VALUES {$values_sql}";
            
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                // ASM_Logger::error('Failed to bulk insert term relationships', array(
                //     'error' => $wpdb->last_error,
                //     'chunk_size' => count($chunk)
                // ));
            }
        }
    }
    
    /**
     * Format product title
     * Wheels: Brand Name + Model + Finish
     * Tires: Brand Name + Model + Size
     */
    private static function format_product_title($api_data, $type) {
        if ($type === 'tire') {
            $parts = array();
            if (!empty($api_data->Brand)) $parts[] = $api_data->Brand;
            if (!empty($api_data->Model)) $parts[] = $api_data->Model;
            if (!empty($api_data->Size)) $parts[] = $api_data->Size;
            return implode(' ', $parts);
        } else {
            $parts = array();
            if (!empty($api_data->Brand)) $parts[] = $api_data->Brand;
            // Use ModelName for wheels (consistent with API structure)
            if (!empty($api_data->ModelName)) {
                $parts[] = $api_data->ModelName;
            } elseif (!empty($api_data->Model)) {
                // Fallback to Model if ModelName not available
                $parts[] = $api_data->Model;
            }
            if (!empty($api_data->Finish)) $parts[] = $api_data->Finish;
            return implode(' ', $parts);
        }
    }
    
    /**
     * Get product description
     */
    private static function get_product_description($api_data, $type) {
        if ($type === 'tire') {
            $parts = array();
            if (!empty($api_data->Benefits)) {
                $parts[] = '<p><strong>Benefits:</strong> ' . esc_html($api_data->Benefits) . '</p>';
            }
            if (!empty($api_data->Features)) {
                $parts[] = '<p><strong>Features:</strong> ' . esc_html($api_data->Features) . '</p>';
            }
            if (!empty($parts)) {
                return implode('', $parts);
            }
        }
        return isset($api_data->Description) ? esc_html($api_data->Description) : '';
    }
    
    /**
     * Batch register all global WooCommerce attributes (called once per batch)
     * Uses direct SQL for maximum performance
     * 
     * @param array $attribute_names Array of attribute names to register
     * @return bool Success
     */
    private static function batch_register_attributes($attribute_names) {
        global $wpdb;
        
        if (empty($attribute_names) || self::$attributes_registered) {
            return true;
        }
        
        // Get all existing attributes in one query
        $existing_attributes = $wpdb->get_results(
            "SELECT attribute_id, attribute_name, attribute_label 
             FROM {$wpdb->prefix}woocommerce_attribute_taxonomies",
            OBJECT_K
        );
        
        // Build lookup map: slug => attribute_data
        // Also build reverse map: label => attribute_data (for finding by display name)
        $existing_slugs = array();
        $existing_by_label = array();
        if ($existing_attributes) {
            foreach ($existing_attributes as $attr) {
                // attribute_name is stored as slug in DB
                $slug = $attr->attribute_name;
                $label = $attr->attribute_label;
                $existing_slugs[$slug] = array(
                    'id' => $attr->attribute_id,
                    'name' => $attr->attribute_name,
                    'label' => $label
                );
                // Also index by label for reverse lookup
                if (!empty($label)) {
                    $existing_by_label[$label] = $existing_slugs[$slug];
                }
            }
        }
        
        // Register missing attributes
        $attributes_to_insert = array();
        foreach ($attribute_names as $name) {
            if (empty($name)) {
                continue;
            }
            
            // Get slug from mapping, fallback to sanitized name if not in mapping
            $slug = isset(self::$attribute_slug_map[$name]) 
                ? self::$attribute_slug_map[$name] 
                : wc_sanitize_taxonomy_name($name);
            
            // Check cache first
            if (isset(self::$attribute_cache[$name])) {
                continue;
            }
            
            // Check if already exists (by slug first, then by label/name)
            $attr_data = null;
            if (isset($existing_slugs[$slug])) {
                $attr_data = $existing_slugs[$slug];
            } elseif (isset($existing_by_label[$name])) {
                // Found by label/display name
                $attr_data = $existing_by_label[$name];
                $slug = $attr_data['name']; // Use the slug from existing attribute
            }
            
            if ($attr_data) {
                $taxonomy_name = wc_attribute_taxonomy_name($slug);
                self::$attribute_cache[$name] = array(
                    'id' => $attr_data['id'],
                    'slug' => $slug,
                    'taxonomy' => $taxonomy_name
                );
                // Ensure taxonomy is registered
                if (!taxonomy_exists($taxonomy_name)) {
                    register_taxonomy($taxonomy_name, array('product'), array());
                }
                continue;
            }
            
            // Prepare for batch insert
            $attributes_to_insert[] = array(
                'name' => $name,
                'slug' => $slug
            );
        }
        
        // Batch insert new attributes using direct SQL (much faster)
        if (!empty($attributes_to_insert)) {
            $values = array();
            foreach ($attributes_to_insert as $attr) {
                $name_escaped = esc_sql($attr['name']); // Display name (label)
                $slug_escaped = esc_sql($attr['slug']); // Slug (attribute_name in DB)
                // WooCommerce: attribute_name = slug, attribute_label = display name
                $values[] = "('{$slug_escaped}', '{$name_escaped}', 'select', 'menu_order', 0)";
            }
            
            if (!empty($values)) {
                $values_sql = implode(',', $values);
                $wpdb->query(
                    "INSERT INTO {$wpdb->prefix}woocommerce_attribute_taxonomies 
                     (attribute_name, attribute_label, attribute_type, attribute_orderby, attribute_public) 
                     VALUES {$values_sql}"
                );
                
                // Clear WooCommerce attribute cache
                delete_transient('wc_attribute_taxonomies');
                
                // Reload attributes to get IDs
                $new_attributes = $wpdb->get_results(
                    "SELECT attribute_id, attribute_name 
                     FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
                     WHERE attribute_name IN ('" . implode("','", array_column($attributes_to_insert, 'slug')) . "')",
                    OBJECT_K
                );
                
                // Cache new attributes and register taxonomies
                foreach ($attributes_to_insert as $attr) {
                    $slug = $attr['slug'];
                    if (isset($new_attributes[$slug])) {
                        $attr_id = $new_attributes[$slug]->attribute_id;
                        $taxonomy_name = wc_attribute_taxonomy_name($slug);
                        self::$attribute_cache[$attr['name']] = array(
                            'id' => $attr_id,
                            'slug' => $slug,
                            'taxonomy' => $taxonomy_name
                        );
                        register_taxonomy($taxonomy_name, array('product'), array());
                    }
                }
            }
        }
        
        self::$attributes_registered = true;
        return true;
    }
    
    /**
     * Batch get or create taxonomy terms using direct SQL (FAST)
     * 
     * @param array $term_data Array of arrays: array('taxonomy' => 'pa_brand', 'value' => 'Michelin')
     * @return array Array mapping: array('taxonomy|value' => array('term_id' => X, 'term_taxonomy_id' => Y))
     */
    private static function batch_get_or_create_terms($term_data) {
        global $wpdb;
        
        if (empty($term_data)) {
            return array();
        }
        
        $result_map = array();
        $terms_to_create = array();
        
        // Group by taxonomy for efficient batch lookup
        $taxonomy_groups = array();
        foreach ($term_data as $item) {
            $taxonomy = $item['taxonomy'];
            $value = $item['value'];
            $cache_key = $taxonomy . '|' . $value;
            
            // Check cache first
            if (isset(self::$term_cache[$taxonomy][$value])) {
                $result_map[$cache_key] = self::$term_cache[$taxonomy][$value];
                continue;
            }
            
            if (!isset($taxonomy_groups[$taxonomy])) {
                $taxonomy_groups[$taxonomy] = array();
            }
            $taxonomy_groups[$taxonomy][] = $value;
        }
        
        // Batch lookup existing terms using direct SQL (much faster than get_term_by)
        foreach ($taxonomy_groups as $taxonomy => $values) {
            // Ensure taxonomy exists
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, array('product'), array());
            }
            
            // Sanitize values for SQL
            $values_escaped = array_map('esc_sql', array_unique($values));
            $values_sql = "'" . implode("','", $values_escaped) . "'";
            
            // Batch lookup terms in one query
            $existing_terms = $wpdb->get_results(
                "SELECT t.term_id, t.name, tt.term_taxonomy_id 
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = '{$taxonomy}' 
                 AND t.name IN ({$values_sql})",
                OBJECT_K
            );
            
            // Cache existing terms
            foreach ($existing_terms as $term) {
                $cache_key = $taxonomy . '|' . $term->name;
                $result_map[$cache_key] = array(
                    'term_id' => $term->term_id,
                    'term_taxonomy_id' => $term->term_taxonomy_id
                );
                
                // Update cache
                if (!isset(self::$term_cache[$taxonomy])) {
                    self::$term_cache[$taxonomy] = array();
                }
                self::$term_cache[$taxonomy][$term->name] = $result_map[$cache_key];
            }
            
            // Find terms that need to be created
            foreach ($values as $value) {
                $cache_key = $taxonomy . '|' . $value;
                if (!isset($result_map[$cache_key])) {
                    $terms_to_create[] = array(
                        'taxonomy' => $taxonomy,
                        'value' => $value,
                        'cache_key' => $cache_key
                    );
                }
            }
        }
        
        // Batch create missing terms using direct SQL (much faster than wp_insert_term)
        if (!empty($terms_to_create)) {
            // Group by taxonomy for batch insert
            $create_groups = array();
            foreach ($terms_to_create as $item) {
                $taxonomy = $item['taxonomy'];
                if (!isset($create_groups[$taxonomy])) {
                    $create_groups[$taxonomy] = array();
                }
                $create_groups[$taxonomy][] = $item;
            }
            
            foreach ($create_groups as $taxonomy => $items) {
                $values_to_insert = array();
                foreach ($items as $item) {
                    $value = esc_sql($item['value']);
                    $slug = sanitize_title($value);
                    $slug_escaped = esc_sql($slug);
                    $now = current_time('mysql');
                    $values_to_insert[] = "('{$value}', '{$slug_escaped}', '{$now}')";
                }
                
                if (!empty($values_to_insert)) {
                    $values_sql = implode(',', $values_to_insert);
                    
                    // Insert terms
                    $wpdb->query(
                        "INSERT IGNORE INTO {$wpdb->terms} (name, slug, term_group) 
                         VALUES {$values_sql}"
                    );
                    
                    // Get inserted term IDs
                    $value_names = array_map(function($item) {
                        return esc_sql($item['value']);
                    }, $items);
                    $value_names_sql = "'" . implode("','", $value_names) . "'";
                    
                    $new_terms_result = $wpdb->get_results(
                        "SELECT term_id, name FROM {$wpdb->terms} 
                         WHERE name IN ({$value_names_sql})"
                    );
                    
                    // Convert to associative array keyed by name
                    $new_terms = array();
                    foreach ($new_terms_result as $term) {
                        $new_terms[$term->name] = $term;
                    }
                    
                    // Insert term_taxonomy entries
                    $tt_values = array();
                    $term_ids_list = array();
                    foreach ($new_terms as $term) {
                        $term_id = intval($term->term_id);
                        $tt_values[] = "({$term_id}, '{$taxonomy}', '', 0)";
                        $term_ids_list[] = $term_id;
                    }
                    
                    if (!empty($tt_values)) {
                        $tt_values_sql = implode(',', $tt_values);
                        $wpdb->query(
                            "INSERT IGNORE INTO {$wpdb->term_taxonomy} (term_id, taxonomy, description, parent) 
                             VALUES {$tt_values_sql}"
                        );
                        
                        // Get term_taxonomy_ids
                        if (!empty($term_ids_list)) {
                            $term_ids_sql = implode(',', $term_ids_list);
                            
                            $term_taxonomies_result = $wpdb->get_results(
                                "SELECT term_id, term_taxonomy_id 
                                 FROM {$wpdb->term_taxonomy} 
                                 WHERE term_id IN ({$term_ids_sql}) 
                                 AND taxonomy = '{$taxonomy}'"
                            );
                            
                            // Convert to associative array keyed by term_id
                            $term_taxonomies = array();
                            foreach ($term_taxonomies_result as $tt) {
                                $term_taxonomies[$tt->term_id] = $tt;
                            }
                            
                            // Cache new terms
                            foreach ($items as $item) {
                                $value = $item['value'];
                                if (isset($new_terms[$value]) && isset($term_taxonomies[$new_terms[$value]->term_id])) {
                                    $term_id = $new_terms[$value]->term_id;
                                    $term_taxonomy_id = $term_taxonomies[$term_id]->term_taxonomy_id;
                                    
                                    $result_map[$item['cache_key']] = array(
                                        'term_id' => $term_id,
                                        'term_taxonomy_id' => $term_taxonomy_id
                                    );
                                    
                                    // Update cache
                                    if (!isset(self::$term_cache[$taxonomy])) {
                                        self::$term_cache[$taxonomy] = array();
                                    }
                                    self::$term_cache[$taxonomy][$value] = $result_map[$item['cache_key']];
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $result_map;
    }
    
    /**
     * Get cached attribute data
     * 
     * @param string $attribute_name Attribute name
     * @return array|false Attribute data or false
     */
    private static function get_cached_attribute($attribute_name) {
        return isset(self::$attribute_cache[$attribute_name]) ? self::$attribute_cache[$attribute_name] : false;
    }
    
    /**
     * Get cached term data
     * 
     * @param string $taxonomy Taxonomy name
     * @param string $term_value Term value
     * @return array|false Term data or false
     */
    private static function get_cached_term($taxonomy, $term_value) {
        return isset(self::$term_cache[$taxonomy][$term_value]) ? self::$term_cache[$taxonomy][$term_value] : false;
    }
    
    /**
     * Normalize attribute name - use mapped name if exists, otherwise use original
     * 
     * @param string $original_name Original attribute name
     * @return string Normalized attribute name (mapped if available, otherwise original)
     */
    private static function normalize_attribute_name($original_name) {
        // Direct match in mapping
        if (isset(self::$attribute_slug_map[$original_name])) {
            return $original_name;
        }
        
        // Try converting spaces to camelCase (e.g., "Aspect Ratio" -> "AspectRatio")
        $normalized = str_replace(' ', '', ucwords(str_replace(array('/', '-'), ' ', $original_name)));
        if (isset(self::$attribute_slug_map[$normalized])) {
            return $normalized;
        }
        
        // Try lowercase comparison
        foreach (self::$attribute_slug_map as $mapped_name => $slug) {
            if (strtolower($mapped_name) === strtolower($original_name)) {
                return $mapped_name;
            }
        }
        
        // Return original if no mapping found
        return $original_name;
    }
    
    /**
     * Get product attributes as serialized WooCommerce format using global attributes
     * Returns serialized string ready for _product_attributes meta field
     * Also returns term relationships to be stored separately
     * 
     * @param object $api_data API data
     * @param string $type Product type ('wheel' or 'tire')
     * @return array Array with 'attributes' (serialized) and 'term_relationships' (array of term_taxonomy_ids)
     */
    private static function get_product_attributes_serialized($api_data, $type) {
        $attributes_array = array();
        $term_relationships = array();
        
        if ($type === 'tire') {
            // Tire attributes - all original attributes
            $attribute_data = array(
                'P/N' => $api_data->PartNumber ?? '',
                'Brand' => $api_data->Brand ?? '',
                'Model' => $api_data->Model ?? '',
                'Asin' => $api_data->Asin ?? '',
                'Size' => $api_data->Size ?? '',
                'Overall Diameter' => $api_data->OverallDiameter ?? '',
                'Section Width' => $api_data->SectionWidth ?? '',
                'Overall Width' => $api_data->OverallWidth ?? '',
                'Load Capacity Single' => $api_data->LoadCapacitySingle ?? '',
                'Load Range' => $api_data->LoadRange ?? '',
                'Measuring Rim Width' => $api_data->MeasuringRimWidth ?? '',
                'Aspect Ratio' => $api_data->AspectRatio ?? '',
                'Tread Depth' => $api_data->TreadDepth ?? '',
                'Weight' => $api_data->Weight ?? '',
                'Max Inflation Pressure' => $api_data->MaxInflationPressure ?? '',
                'Revolutions Per Mile' => $api_data->RevolutionsPerMile ?? '',
                'Speed Rating' => $api_data->SpeedRating ?? '',
                'Load Rating' => $api_data->LoadRating ?? '',
                'Ean' => $api_data->Ean ?? '',
                'Warranty' => $api_data->Warranty ?? '',
                'Sidewall' => $api_data->Sidewall ?? '',
                'Diameter' => $api_data->Diameter ?? '',
            );
        } else {
            // Wheel attributes - all original attributes
            // Construct bolt patterns from LugCount + BoltCircle
            $bolt_patterns = array();
            if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle1)) {
                $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle1;
            }
            if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle2)) {
                $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle2;
            }
            
            $finish_parts = array();
            if (!empty($api_data->Finish)) {
                $finish_parts[] = $api_data->Finish;
            }
            if (!empty($api_data->Color)) {
                $finish_parts[] = $api_data->Color;
            }
            
            $attribute_data = array(
                'PN' => $api_data->Pn ?? '',
                'Brand' => $api_data->Brand ?? '',
                'Model' => $api_data->ModelName ?? '',
                'Finish' => implode(' ', $finish_parts),
                'Size' => (!empty($api_data->Diameter) && !empty($api_data->Width)) ? ($api_data->Diameter . ' x ' . $api_data->Width) : '',
                'BoltPatterns' => implode(' / ', $bolt_patterns),
                'Diameter' => $api_data->Diameter ?? '',
                'Offset' => $api_data->Offset ?? '',
                'Bore' => $api_data->Bore ?? '',
                'Load Rating' => $api_data->LoadRating ?? '',
                'Structure' => $api_data->Structure ?? '',
                'Material' => $api_data->Material ?? '',
                'Weight' => $api_data->Weight ?? '',
                'Width' => $api_data->Width ?? '',
                'BoltCircle' => $api_data->BoltCircle1 ?? '',
                'BoltCircle2' => $api_data->BoltCircle2 ?? '',
                'LugCount' => $api_data->LugCount ?? '',
                'StyleId' => $api_data->StyleId ?? '',
            );
        }
        
        // Normalize attribute names and remove duplicates (use mapped names where available)
        $normalized_attributes = array();
        foreach ($attribute_data as $name => $value) {
            if (!empty($value) && $value !== '') {
                // Normalize name (use mapped name if available)
                $normalized_name = self::normalize_attribute_name($name);
                
                // If we already have this normalized name, skip duplicate
                // But prefer mapped names over original names
                if (isset($normalized_attributes[$normalized_name])) {
                    // If current name is mapped and existing is not, replace it
                    if (isset(self::$attribute_slug_map[$normalized_name]) && 
                        !isset(self::$attribute_slug_map[$name])) {
                        $normalized_attributes[$normalized_name] = $value;
                    }
                    continue;
                }
                
                $normalized_attributes[$normalized_name] = $value;
            }
        }
        
        // Convert to WooCommerce attribute format using global attributes (FAST - uses cache)
        $position = 0;
        foreach ($normalized_attributes as $name => $value) {
            // Get cached attribute (already registered in batch)
            $attr_cache = self::get_cached_attribute($name);
            
            if (!$attr_cache) {
                continue; // Skip if attribute not found in cache
            }
            
            $taxonomy_name = $attr_cache['taxonomy'];
            
            // Get cached term (already created/looked up in batch)
            $term_cache = self::get_cached_term($taxonomy_name, strval($value));
            
            if (!$term_cache) {
                continue; // Skip if term not found in cache
            }
            
            $term_id = $term_cache['term_id'];
            $term_taxonomy_id = $term_cache['term_taxonomy_id'];
            
            // Add to term relationships
            $term_relationships[] = $term_taxonomy_id;
            
            // Store in attributes array using taxonomy name (for global attributes)
            // WooCommerce stores term IDs as pipe-separated string for global attributes
            $attributes_array[$taxonomy_name] = array(
                'name' => $taxonomy_name, // Use taxonomy name for global attributes
                'value' => strval($term_id), // Use term ID as string (pipe-separated if multiple)
                    'position' => $position,
                    'visible' => 1,
                    'variation' => 0,
                'is_taxonomy' => 1 // Mark as global taxonomy attribute
                );
                $position++;
            }
        
        // Return both serialized attributes and term relationships
        return array(
            'attributes' => maybe_serialize($attributes_array),
            'term_relationships' => $term_relationships
        );
    }
    
    /**
     * Get product meta fields
     */
    private static function get_product_meta_fields($api_data, $image_urls, $type, $sku) {
        global $wpdb;
        
        $meta = array();
        
        // Core WooCommerce meta
        $meta['_sku'] = $sku;
        $meta['_visibility'] = 'visible';
        $meta['_stock_status'] = 'instock';
        $meta['_tax_status'] = 'taxable';
        $meta['_tax_class'] = '';
        $meta['_manage_stock'] = 'no';
        $meta['_backorders'] = 'yes';
        $meta['_sold_individually'] = 'no';
        $meta['_virtual'] = 'no';
        $meta['_downloadable'] = 'no';
        $meta['_product_version'] = WC_VERSION;
        // Set product type to 'simple' (Simple product type)
        $meta['_product_type'] = 'simple';
        
        // Mark product as AutoSync product
        $meta['_is_autosync_product'] = 'yes';
        
        // Price
        if (isset($api_data->Price)) {
            $meta['_regular_price'] = floatval($api_data->Price);
            $meta['_price'] = floatval($api_data->Price);
        }
        
        // Inventory
        $inventory = isset($api_data->Inventory) ? $api_data->Inventory : '';
        if ($inventory !== '') {
            $meta['_manage_stock'] = 'yes';
            $meta['_stock'] = intval($inventory);
            $meta['_stock_status'] = ($inventory == 0) ? 'outofstock' : 'instock';
        }
        
        // Weight
        if (isset($api_data->Weight) && !empty($api_data->Weight)) {
            $meta['_weight'] = floatval($api_data->Weight);
        }
        
        // API ID
        if (isset($api_data->Id)) {
            $meta['p_id_api'] = $api_data->Id;
        }
        
        // Type-specific meta
        if ($type === 'tire') {
            $meta['_sku'] = $api_data->PartNumber ?? $sku;
            if (isset($api_data->Brand)) $meta['Brand'] = $api_data->Brand;
            if (isset($api_data->BrandId)) $meta['BrandId'] = $api_data->BrandId;
            if (isset($api_data->ModelId)) $meta['ModelId'] = 'tgp-' . $api_data->ModelId;
            if (isset($api_data->Size)) $meta['Size'] = $api_data->Size;
            if (isset($api_data->Model)) $meta['Model'] = $api_data->Model;
            if (isset($api_data->OverallDiameter)) $meta['OverallDiameter'] = $api_data->OverallDiameter;
            if (isset($api_data->Sidewall)) $meta['Sidewall'] = $api_data->Sidewall;
            if (isset($api_data->LoadCapacitySingle)) $meta['LoadCapacitySingle'] = $api_data->LoadCapacitySingle;
            if (isset($api_data->MeasuringRimWidth)) $meta['MeasuringRimWidth'] = $api_data->MeasuringRimWidth;
            if (isset($api_data->SpeedRating)) $meta['SpeedRating'] = $api_data->SpeedRating;
            if (isset($api_data->LoadRating)) $meta['LoadRating'] = $api_data->LoadRating;
            if (isset($api_data->LoadRange)) $meta['LoadRange'] = $api_data->LoadRange;
            if (isset($api_data->Diameter)) $meta['Diameter'] = $api_data->Diameter;
            if (isset($api_data->SectionWidth)) {
                $meta['Width'] = $api_data->SectionWidth;
                $meta['SectionWidth'] = $api_data->SectionWidth;
            }
            if (isset($api_data->Upc)) $meta['Upc'] = $api_data->Upc;
            if (isset($api_data->DisplayName)) $meta['Description'] = $api_data->DisplayName;
            
            // Tire images - ALWAYS store full URLs for hash comparison (even in download mode)
            $image_mode = get_option('asm_image_mode', 'external');
            
            if (isset($image_urls['img'])) {
                $image_base = !empty($image_urls['img'])
                    ? $image_urls['img']
                    : 'https://storage.googleapis.com/autosync_tires/';
                
                // Always store full URLs (for hash comparison)
                if (!empty($api_data->ImgAngle)) {
                    $meta['feature_img_link'] = $image_base . $api_data->ImgAngle;
                }
                if (!empty($api_data->ImgFront)) {
                    $meta['galleryimage1'] = $image_base . $api_data->ImgFront;
                }
                if (!empty($api_data->ImgSide1)) {
                    $meta['galleryimage2'] = $image_base . $api_data->ImgSide1;
                }
                if (!empty($api_data->ImgThumb)) {
                    $meta['galleryimage3'] = $image_base . $api_data->ImgThumb;
                }
                if (!empty($api_data->ImgSide2)) {
                    $meta['galleryimage4'] = $image_base . $api_data->ImgSide2;
                }
            }
            // Note: In 'download' mode, images are downloaded and attached after product creation
            // But we still store full URLs in feature_img_link/galleryimage for hash comparison
        } else {
            // Wheel meta
            $meta['_sku'] = $api_data->Pn ?? $sku;
            if (isset($api_data->StyleId)) $meta['StyleId'] = 'wgp-' . $api_data->StyleId;
            if (isset($api_data->Brand)) {
                $meta['Brand'] = $api_data->Brand;
                $meta['brand_name'] = $api_data->Brand;
            }
            if (isset($api_data->BrandId)) $meta['BrandId'] = $api_data->BrandId;
            
            // Wheel specifications - construct bolt patterns from LugCount + BoltCircle
            $bolt_patterns = array();
            if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle1)) {
                $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle1;
            }
            if (!empty($api_data->LugCount) && !empty($api_data->BoltCircle2)) {
                $bolt_patterns[] = $api_data->LugCount . 'x' . $api_data->BoltCircle2;
            }
            $meta['BoltPattern'] = !empty($bolt_patterns) ? implode(' / ', $bolt_patterns) : '';
            
            $offset = $api_data->Offset ?? '';
            if (!empty($offset) && strpos($offset, 'mm') === false) {
                $offset .= ' mm';
            }
            if ($offset) $meta['Offset'] = $offset;
            
            if (isset($api_data->Diameter) && isset($api_data->Width)) {
                $meta['Size'] = $api_data->Diameter . 'x' . $api_data->Width;
            }
            if (isset($api_data->Bore)) $meta['Bore'] = $api_data->Bore;
            if (isset($api_data->Diameter)) $meta['Diameter'] = $api_data->Diameter;
            if (isset($api_data->Upc)) $meta['Upc'] = $api_data->Upc;
            
            $finish_parts = array();
            if (!empty($api_data->Finish)) $finish_parts[] = $api_data->Finish;
            if (!empty($api_data->Color)) $finish_parts[] = $api_data->Color;
            if (!empty($finish_parts)) $meta['Finish'] = implode(' ', $finish_parts);
            
            if (isset($api_data->Width)) $meta['Width'] = $api_data->Width;
            
            // Wheel images - ALWAYS store full URLs for hash comparison (even in download mode)
            $image_mode = get_option('asm_image_mode', 'external');
            
            if (isset($image_urls['img'])) {
                $image_base = !empty($image_urls['img'])
                    ? $image_urls['img']
                    : 'https://wheels.autosyncstudio.com/webp/';
                
                // Always store full URLs (for hash comparison)
                if (!empty($api_data->Img0001)) {
                    $meta['feature_img_link'] = $image_base . $api_data->Img0001;
                }
                
                $gallery_index = 1;
                for ($i = 2; $i <= 20; $i++) {
                    $img_key = 'Img' . str_pad($i, 4, '0', STR_PAD_LEFT);
                    if (isset($api_data->$img_key) && !empty($api_data->$img_key)) {
                        $meta['galleryimage' . $gallery_index] = $image_base . $api_data->$img_key;
                        $gallery_index++;
                    }
                }
            }
            // Note: In 'download' mode, images are downloaded and attached after product creation
            // But we still store full URLs in feature_img_link/galleryimage for hash comparison
        }
        
        return $meta;
    }
    
    /**
     * Get or create tags with caching - OPTIMIZED: Cache lookups to avoid repeated queries
     * Returns term_taxonomy_ids for use in term_relationships table
     */
    private static function get_or_create_tags_cached($api_data, &$cache) {
        global $wpdb;
        $tag_taxonomy_ids = array();
        
        // Process VehicleTypeTags
        if (isset($api_data->VehicleTypeTags) && is_array($api_data->VehicleTypeTags)) {
            foreach ($api_data->VehicleTypeTags as $tag_name) {
                if (empty($tag_name)) {
                    continue;
                }
                
                $tag_slug = sanitize_title($tag_name);
                $cache_key = 'tag_' . $tag_slug;
                
                // Check cache first
                if (isset($cache[$cache_key])) {
                    $tag_taxonomy_ids[] = $cache[$cache_key];
                } else {
                    // Check if tag exists
                    $tag_term = get_term_by('slug', $tag_slug, 'product_tag');
                    
                    if (!$tag_term) {
                        // Create tag
                        $new_tag = wp_insert_term($tag_name, 'product_tag');
                        if (!is_wp_error($new_tag)) {
                            $tag_term_id = $new_tag['term_id'];
                        } else {
                            continue; // Skip if creation failed
                        }
                    } else {
                        $tag_term_id = $tag_term->term_id;
                    }
                    
                    // Get term_taxonomy_id
                    $term_taxonomy = $wpdb->get_row($wpdb->prepare(
                        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_tag'",
                        $tag_term_id
                    ));
                    
                    if ($term_taxonomy) {
                        $tag_taxonomy_id = $term_taxonomy->term_taxonomy_id;
                        $cache[$cache_key] = $tag_taxonomy_id; // Cache it
                        $tag_taxonomy_ids[] = $tag_taxonomy_id;
                    }
                }
            }
        }
        
        // Process SegmentTags
        if (isset($api_data->SegmentTags) && is_array($api_data->SegmentTags)) {
            foreach ($api_data->SegmentTags as $tag_name) {
                if (empty($tag_name)) {
                    continue;
                }
                
                $tag_slug = sanitize_title($tag_name);
                $cache_key = 'tag_' . $tag_slug;
                
                // Check cache first
                if (isset($cache[$cache_key])) {
                    $tag_taxonomy_ids[] = $cache[$cache_key];
                } else {
                    // Check if tag exists
                    $tag_term = get_term_by('slug', $tag_slug, 'product_tag');
                    
                    if (!$tag_term) {
                        // Create tag
                        $new_tag = wp_insert_term($tag_name, 'product_tag');
                        if (!is_wp_error($new_tag)) {
                            $tag_term_id = $new_tag['term_id'];
                        } else {
                            continue; // Skip if creation failed
                        }
                    } else {
                        $tag_term_id = $tag_term->term_id;
                    }
                    
                    // Get term_taxonomy_id
                    $term_taxonomy = $wpdb->get_row($wpdb->prepare(
                        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_tag'",
                        $tag_term_id
                    ));
                    
                    if ($term_taxonomy) {
                        $tag_taxonomy_id = $term_taxonomy->term_taxonomy_id;
                        $cache[$cache_key] = $tag_taxonomy_id; // Cache it
                        $tag_taxonomy_ids[] = $tag_taxonomy_id;
                    }
                }
            }
        }
        
        return $tag_taxonomy_ids;
    }
    
    /**
     * Get or create categories with caching - OPTIMIZED: Cache lookups to avoid repeated queries
     */
    private static function get_or_create_categories_cached($api_data, $type, &$cache) {
        global $wpdb;
        
        $category_ids = array();
        
        // Main category (Wheels or Tires)
        $category_slug = $type === 'tire' ? 'tires' : 'wheels';
        $category_name = $type === 'tire' ? 'Tires' : 'Wheels';
        $cache_key = 'main_' . $category_slug;
        
        // Check cache first
        if (isset($cache[$cache_key])) {
            $main_term_taxonomy_id = $cache[$cache_key];
        } else {
            $main_term = get_term_by('slug', $category_slug, 'product_cat');
            
            if (!$main_term) {
                // Create main category
                $main_term_result = wp_insert_term($category_name, 'product_cat', array('slug' => $category_slug, 'parent' => 0));
                if (!is_wp_error($main_term_result)) {
                    $main_term_id = $main_term_result['term_id'];
                } else {
                    return array(); // Failed to create
                }
            } else {
                $main_term_id = $main_term->term_id;
            }
            
            // Get term_taxonomy_id for main category
            $term_taxonomy = $wpdb->get_row($wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_cat'",
                $main_term_id
            ));
            
            if ($term_taxonomy) {
                $main_term_taxonomy_id = $term_taxonomy->term_taxonomy_id;
                $cache[$cache_key] = $main_term_taxonomy_id; // Cache it
            } else {
                return array();
            }
        }
        
        $category_ids[] = $main_term_taxonomy_id;
        
        // Brand category (child of main category)
        $brand = isset($api_data->Brand) ? sanitize_text_field($api_data->Brand) : '';
        if (!empty($brand)) {
            $brand_slug = sanitize_title($brand);
            $brand_cache_key = 'brand_' . $brand_slug;
            
            // Check cache first
            if (isset($cache[$brand_cache_key])) {
                $brand_term_taxonomy_id = $cache[$brand_cache_key];
            } else {
                $brand_term = get_term_by('slug', $brand_slug, 'product_cat');
                
                if (!$brand_term) {
                    // Create brand category as child
                    $main_term_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                        $main_term_taxonomy_id
                    ));
                    
                    $brand_term_result = wp_insert_term($brand, 'product_cat', array('slug' => $brand_slug, 'parent' => $main_term_id));
                    if (!is_wp_error($brand_term_result)) {
                        $brand_term_id = $brand_term_result['term_id'];
                    } else {
                        return $category_ids; // Return main category only
                    }
                } else {
                    $brand_term_id = $brand_term->term_id;
                    
                    // Update parent if incorrect
                    $main_term_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                        $main_term_taxonomy_id
                    ));
                    
                    if ($brand_term->parent != $main_term_id) {
                        wp_update_term($brand_term_id, 'product_cat', array('parent' => $main_term_id));
                    }
                }
                
                // Get term_taxonomy_id for brand category
                $brand_term_taxonomy = $wpdb->get_row($wpdb->prepare(
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_cat'",
                    $brand_term_id
                ));
                
                if ($brand_term_taxonomy) {
                    $brand_term_taxonomy_id = $brand_term_taxonomy->term_taxonomy_id;
                    $cache[$brand_cache_key] = $brand_term_taxonomy_id; // Cache it
                } else {
                    return $category_ids;
                }
            }
            
            $category_ids[] = $brand_term_taxonomy_id;
        }
        
        return $category_ids;
    }
    
    /**
     * Get or create categories and return term_taxonomy_ids (legacy method - kept for compatibility)
     */
    private static function get_or_create_categories($api_data, $type) {
        $cache = array();
        return self::get_or_create_categories_cached($api_data, $type, $cache);
    }
    
    /**
     * Get or create product type taxonomy term (cached)
     * WooCommerce uses 'product_type' taxonomy to store product types like 'simple', 'external', etc.
     * Uses existing 'simple' term from wp_terms or creates it if it doesn't exist
     * 
     * @param string $product_type Product type (default: 'simple')
     * @return int|false Term taxonomy ID or false on failure
     */
    private static function get_or_create_product_type_term($product_type = 'simple') {
        global $wpdb;
        
        static $cache = array();
        
        // Check cache first
        if (isset($cache[$product_type])) {
            return $cache[$product_type];
        }
        
        // Get or create the product type term (use existing simple term from wp_terms)
        $term = get_term_by('slug', $product_type, 'product_type');
        
        if (!$term || is_wp_error($term)) {
            // Term doesn't exist, create it
            $term_result = wp_insert_term($product_type, 'product_type', array('slug' => $product_type));
            
            if (is_wp_error($term_result)) {
                return false;
            }
            
            $term_id = $term_result['term_id'];
        } else {
            $term_id = $term->term_id;
        }
        
        // Get term_taxonomy_id for wp_term_relationships
        $term_taxonomy = $wpdb->get_row($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_type'",
            $term_id
        ));
        
        if ($term_taxonomy) {
            $term_taxonomy_id = $term_taxonomy->term_taxonomy_id;
            $cache[$product_type] = $term_taxonomy_id; // Cache it
            return $term_taxonomy_id;
        }
        
        return false;
    }
    
    /**
     * Remove old product_type terms from existing products
     * This ensures products only have the 'simple' product_type term
     * 
     * @param array $product_ids Array of product IDs
     * @param string $table_prefix Database table prefix
     */
    private static function remove_old_product_type_terms($product_ids, $table_prefix) {
        global $wpdb;
        
        if (empty($product_ids)) {
            return;
        }
        
        // Get all product_type term_taxonomy_ids (simple, grouped, variable, etc.)
        $term_taxonomy_ids = $wpdb->get_col(
            "SELECT term_taxonomy_id 
             FROM {$wpdb->term_taxonomy} 
             WHERE taxonomy = 'product_type'"
        );
        
        if (empty($term_taxonomy_ids)) {
            return;
        }
        
        // Remove all product_type term relationships for these products
        $product_ids_sql = implode(',', array_map('intval', $product_ids));
        $term_taxonomy_ids_sql = implode(',', array_map('intval', $term_taxonomy_ids));
        
        $wpdb->query(
            "DELETE FROM {$table_prefix}term_relationships 
             WHERE object_id IN ({$product_ids_sql}) 
             AND term_taxonomy_id IN ({$term_taxonomy_ids_sql})"
        );
    }
    
    /**
     * Ensure all products have the product_type taxonomy term relationship
     * This is a safety check to ensure product_type taxonomy is set correctly
     * Fixes issue where taxonomy relationship might not be created on some servers
     * 
     * @param array $product_ids Array of product IDs
     * @param string $table_prefix Database table prefix
     */
    private static function ensure_product_type_taxonomy($product_ids, $table_prefix) {
        global $wpdb;
        
        if (empty($product_ids)) {
            return;
        }
        
        // Get product_type term_taxonomy_id for 'simple'
        $term = get_term_by('slug', 'simple', 'product_type');
        if (!$term || is_wp_error($term)) {
            return; // Term doesn't exist, skip
        }
        
        $term_taxonomy_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'product_type'",
            $term->term_id
        ));
        
        if (!$term_taxonomy_id) {
            return; // Term taxonomy doesn't exist, skip
        }
        
        // Filter to only numeric product IDs
        $valid_product_ids = array_filter(array_map('intval', $product_ids), function($id) {
            return $id > 0;
        });
        
        if (empty($valid_product_ids)) {
            return;
        }
        
        // Check which products are missing the product_type term relationship
        $product_ids_sql = implode(',', $valid_product_ids);
        
        $existing_rels = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$table_prefix}term_relationships 
             WHERE object_id IN ({$product_ids_sql}) 
             AND term_taxonomy_id = %d",
            $term_taxonomy_id
        ));
        
        $missing_ids = array_diff($valid_product_ids, array_map('intval', $existing_rels));
        
        if (!empty($missing_ids)) {
            // Insert missing relationships in batches
            $chunk_size = 1000;
            $chunks = array_chunk($missing_ids, $chunk_size);
            
            foreach ($chunks as $chunk) {
                $values = array();
                foreach ($chunk as $product_id) {
                    $values[] = "(" . intval($product_id) . ", " . intval($term_taxonomy_id) . ", 0)";
                }
                
                if (!empty($values)) {
                    $values_sql = implode(',', $values);
                    $wpdb->query(
                        "INSERT IGNORE INTO {$table_prefix}term_relationships (object_id, term_taxonomy_id, term_order) 
                         VALUES {$values_sql}"
                    );
                }
            }
        }
    }
    
    /**
     * Update WooCommerce product lookup table
     * This is REQUIRED for SKU searches to work in WooCommerce
     * 
     * @param array $product_ids Array of product IDs to update
     * @param string $table_prefix Database table prefix
     */
    private static function update_woocommerce_lookup_table($product_ids, $table_prefix) {
        global $wpdb;
        
        // Check if lookup table exists (WooCommerce 3.6+)
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        
        // Check if WooCommerce is active and lookup table exists
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
            // ASM_Logger::warning('WooCommerce not active - skipping lookup table update');
            return;
        }
        
        // Try to query the table to see if it exists
        // Suppress errors and check result
        $wpdb->suppress_errors = true;
        $test_query = $wpdb->query("SELECT 1 FROM {$lookup_table} LIMIT 1");
        $wpdb->suppress_errors = false;
        
        if ($test_query === false && !empty($wpdb->last_error)) {
            // Table doesn't exist - might be an older WooCommerce version
            // ASM_Logger::warning('WooCommerce lookup table does not exist - SKU searches may not work', array(
            //     'table' => $lookup_table,
            //     'error' => $wpdb->last_error
            // ));
            return;
        }
        
        if (empty($product_ids)) {
            return;
        }
        
        // Filter to only valid numeric product IDs
        $valid_product_ids = array_filter(array_map('intval', $product_ids), function($id) {
            return $id > 0;
        });
        
        if (empty($valid_product_ids)) {
            return;
        }
        
        // Process in chunks to avoid query size limits
        $chunk_size = 500;
        $chunks = array_chunk($valid_product_ids, $chunk_size);
        
        foreach ($chunks as $chunk) {
            $ids_sql = implode(',', $chunk);
            
            // Step 1: Insert product IDs into lookup table if they don't exist (INSERT IGNORE)
            $values = array();
            foreach ($chunk as $product_id) {
                $values[] = '(' . intval($product_id) . ')';
            }
            if (!empty($values)) {
                $values_sql = implode(',', $values);
                $wpdb->query(
                    "INSERT IGNORE INTO {$lookup_table} (`product_id`) VALUES {$values_sql}"
                );
            }
            
            // Step 2: Update SKU field (most critical for SKU searches!)
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_sku'
                SET lookup.sku = meta.meta_value
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 3: Update stock_status
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_stock_status'
                SET lookup.stock_status = COALESCE(meta.meta_value, 'instock')
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 4: Update virtual field
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_virtual'
                SET lookup.virtual = CASE WHEN meta.meta_value = 'yes' THEN 1 ELSE 0 END
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 5: Update downloadable field
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_downloadable'
                SET lookup.downloadable = CASE WHEN meta.meta_value = 'yes' THEN 1 ELSE 0 END
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 6: Update tax_status
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_tax_status'
                SET lookup.tax_status = COALESCE(meta.meta_value, 'taxable')
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 7: Update tax_class
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_tax_class'
                SET lookup.tax_class = COALESCE(meta.meta_value, '')
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 8: Update min_price and max_price from _price meta
            // WooCommerce stores the calculated price in _price meta key
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} meta ON lookup.product_id = meta.post_id AND meta.meta_key = '_price'
                SET lookup.min_price = CAST(COALESCE(meta.meta_value, 0) AS DECIMAL(19,4)),
                    lookup.max_price = CAST(COALESCE(meta.meta_value, 0) AS DECIMAL(19,4))
                WHERE lookup.product_id IN ({$ids_sql}) AND meta.meta_value != '' AND meta.meta_value IS NOT NULL"
            );
            
            // Step 9: Update onsale (check if sale_price exists and is set)
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} sale_meta ON lookup.product_id = sale_meta.post_id AND sale_meta.meta_key = '_sale_price'
                INNER JOIN {$wpdb->postmeta} price_meta ON lookup.product_id = price_meta.post_id AND price_meta.meta_key = '_price'
                SET lookup.onsale = CASE 
                    WHEN sale_meta.meta_value != '' AND sale_meta.meta_value IS NOT NULL 
                         AND price_meta.meta_value = sale_meta.meta_value 
                    THEN 1 
                    ELSE 0 
                END
                WHERE lookup.product_id IN ({$ids_sql})"
            );
            
            // Step 10: Update stock_quantity (if manage_stock is yes)
            $wpdb->query(
                "UPDATE {$lookup_table} lookup
                INNER JOIN {$wpdb->postmeta} manage_meta ON lookup.product_id = manage_meta.post_id AND manage_meta.meta_key = '_manage_stock'
                LEFT JOIN {$wpdb->postmeta} stock_meta ON lookup.product_id = stock_meta.post_id AND stock_meta.meta_key = '_stock'
                SET lookup.stock_quantity = CASE 
                    WHEN manage_meta.meta_value = 'yes' THEN CAST(COALESCE(stock_meta.meta_value, 0) AS DECIMAL(19,4))
                    ELSE NULL
                END
                WHERE lookup.product_id IN ({$ids_sql})"
            );
        }
        
        // ASM_Logger::info('WooCommerce lookup table updated successfully', array(
        //     'product_count' => count($valid_product_ids)
        // ));
    }
    
    /**
     * Update WooCommerce product attributes lookup table (wp_wc_product_attributes_lookup)
     * Required for WooCommerce 6.3+ attribute filtering
     * 
     * @param array $product_ids Array of product IDs
     * @param string $table_prefix Database table prefix
     */
    private static function update_woocommerce_attributes_lookup_table($product_ids, $table_prefix) {
        global $wpdb;
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Check if table exists (WooCommerce 6.3+)
        $attributes_lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        
        // Check if table exists
        $wpdb->suppress_errors = true;
        $test_query = $wpdb->query("SELECT 1 FROM {$attributes_lookup_table} LIMIT 1");
        $wpdb->suppress_errors = false;
        
        if ($test_query === false && !empty($wpdb->last_error)) {
            // Table doesn't exist - might be older WooCommerce version
            // ASM_Logger::info('WooCommerce attributes lookup table does not exist (WooCommerce < 6.3)', array(
            //     'table' => $attributes_lookup_table
            // ));
            return;
        }
        
        if (empty($product_ids)) {
            return;
        }
        
        // Filter to only valid numeric product IDs
        $valid_product_ids = array_filter(array_map('intval', $product_ids), function($id) {
            return $id > 0;
        });
        
        if (empty($valid_product_ids)) {
            return;
        }
        
        // Process in chunks to avoid query size limits
        $chunk_size = 500;
        $chunks = array_chunk($valid_product_ids, $chunk_size);
        
        foreach ($chunks as $chunk) {
            $ids_sql = implode(',', $chunk);
            
            // Delete existing entries for these products (will be rebuilt)
            $wpdb->query("DELETE FROM {$attributes_lookup_table} WHERE product_id IN ({$ids_sql})");
            
            // Get all taxonomy-based attributes for these products
            // WooCommerce stores taxonomy attributes in wp_term_relationships
            // We need to get product attributes that are taxonomies (pa_*)
            $attribute_taxonomies = wc_get_attribute_taxonomies();
            
            if (empty($attribute_taxonomies)) {
                continue;
            }
            
            $attributes_to_insert = array();
            
            foreach ($attribute_taxonomies as $attribute) {
                $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
                
                // Get all term relationships for this taxonomy for these products
                $attr_term_relationships = $wpdb->get_results(
                    "SELECT tr.object_id as product_id, tt.term_id, tt.term_taxonomy_id
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                    WHERE tr.object_id IN ({$ids_sql})
                    AND tt.taxonomy = %s
                    AND p.post_type = 'product'
                    AND p.post_status = 'publish'",
                    $taxonomy
                );
                
                foreach ($attr_term_relationships as $rel) {
                    $product_id = intval($rel->product_id);
                    $term_id = intval($rel->term_id);
                    
                    // Get stock status for this product
                    $stock_status = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->postmeta} 
                        WHERE post_id = %d AND meta_key = '_stock_status'",
                        $product_id
                    ));
                    $in_stock = ($stock_status === 'instock' || $stock_status === 'onbackorder') ? 1 : 0;
                    
                    $attributes_to_insert[] = array(
                        'product_id' => $product_id,
                        'product_or_parent_id' => $product_id,
                        'taxonomy' => $taxonomy,
                        'term_id' => $term_id,
                        'is_variation_attribute' => 0,
                        'in_stock' => $in_stock
                    );
                }
            }
            
            // Bulk insert attributes
            if (!empty($attributes_to_insert)) {
                $values = array();
                foreach ($attributes_to_insert as $attr) {
                    $values[] = $wpdb->prepare(
                        "(%d, %d, %s, %d, %d, %d)",
                        $attr['product_id'],
                        $attr['product_or_parent_id'],
                        $attr['taxonomy'],
                        $attr['term_id'],
                        $attr['is_variation_attribute'],
                        $attr['in_stock']
                    );
                }
                
                if (!empty($values)) {
                    $values_sql = implode(',', $values);
                    $wpdb->query(
                        "INSERT IGNORE INTO {$attributes_lookup_table} 
                        (product_id, product_or_parent_id, taxonomy, term_id, is_variation_attribute, in_stock) 
                        VALUES {$values_sql}"
                    );
                }
            }
        }
        
        // ASM_Logger::info('WooCommerce attributes lookup table updated successfully', array(
        //     'product_count' => count($valid_product_ids)
        // ));
    }
    
    /**
     * Update term taxonomy counts
     * Required for proper category/tag counts in WordPress
     * 
     * @param array $term_relationships Array of term relationships
     * @param string $table_prefix Database table prefix
     */
    private static function update_term_taxonomy_counts($term_relationships, $table_prefix) {
        global $wpdb;
        
        if (empty($term_relationships)) {
            return;
        }
        
        // Get unique term_taxonomy_ids that need count updates
        $term_taxonomy_ids = array();
        foreach ($term_relationships as $rel) {
            if (isset($rel['term_taxonomy_id']) && is_numeric($rel['term_taxonomy_id'])) {
                $term_taxonomy_ids[] = intval($rel['term_taxonomy_id']);
            }
        }
        
        if (empty($term_taxonomy_ids)) {
            return;
        }
        
        $term_taxonomy_ids = array_unique($term_taxonomy_ids);
        $term_taxonomy_table = $table_prefix . 'term_taxonomy';
        
        // Update counts for each term taxonomy
        foreach ($term_taxonomy_ids as $term_taxonomy_id) {
            // Count relationships for this term taxonomy
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                WHERE tr.term_taxonomy_id = %d
                AND p.post_status = 'publish'
                AND p.post_type IN ('product', 'product_variation')",
                $term_taxonomy_id
            ));
            
            // Update the count
            $wpdb->update(
                $term_taxonomy_table,
                array('count' => intval($count)),
                array('term_taxonomy_id' => $term_taxonomy_id),
                array('%d'),
                array('%d')
            );
        }
        
        // ASM_Logger::info('Term taxonomy counts updated', array(
        //     'term_taxonomy_ids_count' => count($term_taxonomy_ids)
        // ));
    }
    
    /**
     * Clean term cache for products
     * Required for proper term relationship caching
     * 
     * @param array $product_ids Array of product IDs
     */
    private static function clean_term_cache_for_products($product_ids) {
        if (empty($product_ids)) {
            return;
        }
        
        // Clean term cache for each product
        foreach ($product_ids as $product_id) {
            if (is_numeric($product_id) && $product_id > 0) {
                // Clean object term cache
                clean_object_term_cache(intval($product_id), 'product');
                
                // Clean post cache
                clean_post_cache(intval($product_id));
            }
        }
        
        // Also clean taxonomy caches
        clean_term_cache(null, 'product_cat');
        clean_term_cache(null, 'product_tag');
        
        // ASM_Logger::info('Term cache cleaned for products', array(
        //     'product_count' => count($product_ids)
        // ));
    }
    
    /**
     * Download images for products created via batch insert
     * Processes in chunks to avoid timeouts and memory issues
     * 
     * @param array $product_ids Array of product IDs
     * @param array $products Array of product data (original products array)
     * @param array $image_urls Image base URLs
     * @param string $type Product type
     * @param array $product_data_map Map of SKU to product data
     */
    private static function download_images_for_products($product_ids, $products, $image_urls, $type, $product_data_map) {
        if (empty($product_ids)) {
            // ASM_Logger::info('No product IDs provided for image downloads');
            return array('images_updated' => 0);
        }
        // Note: product_data_map may be empty if all products were hash-skipped
        // We'll get API data from $products array instead
        
        // Increase time limit for image downloads
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        
        // ASM_Logger::info('Starting image downloads for batch products', array(
        //     'product_count' => count($product_ids),
        //     'product_data_map_count' => count($product_data_map),
        //     'type' => $type
        // ));
        
        // Get SKUs for all product IDs - process in chunks to avoid query size limits
        global $wpdb;
        $chunk_size = 500;
        $product_id_chunks = array_chunk($product_ids, $chunk_size);
        
        $sku_to_product_id = array();
        
        foreach ($product_id_chunks as $chunk) {
            $ids_sql = implode(',', array_map('intval', $chunk));
            $sku_results = $wpdb->get_results(
                "SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} 
                WHERE post_id IN ({$ids_sql}) AND meta_key = '_sku'",
                ARRAY_A
            );
            
            foreach ($sku_results as $row) {
                $sku_to_product_id[$row['sku']] = intval($row['post_id']);
            }
        }
        
        if (empty($sku_to_product_id)) {
            // ASM_Logger::warning('No SKUs found for product IDs', array(
            //     'product_ids_count' => count($product_ids)
            // ));
            return;
        }
        
        // ASM_Logger::info('SKU mapping completed', array(
        //     'mapped_products' => count($sku_to_product_id),
        //     'total_product_ids' => count($product_ids)
        // ));
        
        // Process images in chunks to avoid timeouts and memory issues
        $chunk_size = 50; // Process 50 products at a time for images
        $product_data_array = array();
        
        // Build array of products to process (only those that have product IDs)
        // Include products from product_data_map AND hash-skipped products from original products array
        foreach ($product_data_map as $sku => $data) {
            if (isset($sku_to_product_id[$sku])) {
                $product_data_array[] = array(
                    'sku' => $sku,
                    'product_id' => $sku_to_product_id[$sku],
                    'api_data' => $data['api_data']
                );
            }
        }
        
        // Also include hash-skipped products that aren't in product_data_map
        // (They were skipped from processing but still need images downloaded)
        // Build a lookup of SKUs already included
        $included_skus = array();
        foreach ($product_data_array as $existing) {
            $included_skus[$existing['sku']] = true;
        }
        
        // Add hash-skipped products from original products array
        // These products have matching hashes but may need images downloaded
        foreach ($products as $index => $product_item) {
            // Handle different product array structures
            $sku = '';
            $api_data = null;
            
            if (is_array($product_item)) {
                $sku = isset($product_item['sku']) ? $product_item['sku'] : '';
                $api_data = isset($product_item['data']) ? $product_item['data'] : (isset($product_item['api_data']) ? $product_item['api_data'] : null);
            } elseif (is_object($product_item)) {
                // Direct API data object - extract SKU
                if ($type === 'wheel' && isset($product_item->Pn)) {
                    $sku = $product_item->Pn;
                    $api_data = $product_item;
                } elseif ($type === 'tire' && isset($product_item->PartNumber)) {
                    $sku = $product_item->PartNumber;
                    $api_data = $product_item;
                }
            }
            
            if (empty($sku) || isset($included_skus[$sku])) {
                continue;
            }
            
            // Check if this product ID is in our list and needs images
            if (isset($sku_to_product_id[$sku])) {
                $product_id = $sku_to_product_id[$sku];
                
                // Only include if it's in the product_ids list (products that need images)
                if (in_array($product_id, $product_ids) && $api_data) {
                    $product_data_array[] = array(
                        'sku' => $sku,
                        'product_id' => $product_id,
                        'api_data' => $api_data
                    );
                    $included_skus[$sku] = true;
                }
            }
        }
        
        $total_products = count($product_data_array);
        $chunks = array_chunk($product_data_array, $chunk_size);
        $downloaded_count = 0;
        $error_count = 0;
        $skipped_count = 0;
        $images_updated = 0; // Track products that had images downloaded (for statistics)
        $start_time = microtime(true);
        
        // ASM_Logger::info('Processing image downloads in chunks', array(
        //     'total_products' => $total_products,
        //     'chunk_size' => $chunk_size,
        //     'total_chunks' => count($chunks)
        // ));
        
        foreach ($chunks as $chunk_index => $chunk) {
            $chunk_start_time = microtime(true);
            
            foreach ($chunk as $product_item) {
                $product_id = $product_item['product_id'];
                $sku = $product_item['sku'];
                $api_data = $product_item['api_data'];
                
                // Ensure api_data is an object
                if (is_array($api_data)) {
                    $api_data = (object) $api_data;
                }
                
                if (!is_object($api_data)) {
                    $skipped_count++;
                    continue;
                }
                
                try {
                    // Double-check: Skip if product already has downloaded images (WordPress attachments)
                    // (This is a safety check - we should have filtered these out earlier)
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $has_featured = $product->get_image_id() > 0;
                        $has_gallery = !empty($product->get_gallery_image_ids());
                        
                        // Skip if product already has downloaded images (either featured OR gallery)
                        // Products in "External URLs" mode won't have these, so they'll be processed
                        if ($has_featured || $has_gallery) {
                            $skipped_count++;
                            // ASM_Logger::info('Product already has downloaded images - skipping download', array(
                            //     'product_id' => $product_id,
                            //     'sku' => $sku,
                            //     'has_featured' => $has_featured,
                            //     'has_gallery' => !empty($has_gallery)
                            // ));
                            continue;
                        }
                    }
                    
                    // Download and attach images
                    $result = ASM_Image_Downloader::download_and_attach_images($product_id, $api_data, $image_urls, $type);
                    if ($result['featured_image_id'] > 0 || !empty($result['gallery_image_ids'])) {
                        $downloaded_count++;
                        // Track that this product had images updated (even if it was hash-skipped)
                        // This will be used to adjust the "updated" vs "skipped" counts
                        $images_updated++;
                    } else {
                        $skipped_count++;
                        // ASM_Logger::info('No images downloaded for product', array(
                        //     'product_id' => $product_id,
                        //     'sku' => $sku,
                        //     'has_featured' => $result['featured_image_id'] > 0,
                        //     'has_gallery' => !empty($result['gallery_image_ids'])
                        // ));
                    }
                } catch (Exception $e) {
                    $error_count++;
                    // ASM_Logger::error('Error downloading images for product', array(
                    //     'product_id' => $product_id,
                    //     'sku' => $sku,
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    // Continue processing other products even if one fails
                } catch (Error $e) {
                    $error_count++;
                    // ASM_Logger::error('Fatal error downloading images for product', array(
                    //     'product_id' => $product_id,
                    //     'sku' => $sku,
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    // Continue processing other products even if one fails
                }
            }
            
            $chunk_duration = round(microtime(true) - $chunk_start_time, 2);
            
            // Log progress every chunk
            // ASM_Logger::info('Image download chunk completed', array(
            //     'chunk' => $chunk_index + 1,
            //     'total_chunks' => count($chunks),
            //     'chunk_size' => count($chunk),
            //     'downloaded' => $downloaded_count,
            //     'errors' => $error_count,
            //     'skipped' => $skipped_count,
            //     'chunk_duration' => $chunk_duration,
            //     'elapsed_time' => round(microtime(true) - $start_time, 2)
            // ));
            
            // Force garbage collection every chunk to free memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Small delay between chunks to avoid overwhelming the server
            if ($chunk_index < count($chunks) - 1) {
                usleep(100000); // 0.1 seconds
            }
        }
        
        $total_duration = round(microtime(true) - $start_time, 2);
        
        // ASM_Logger::info('Image downloads completed', array(
        //     'products_with_images' => $downloaded_count,
        //     'errors' => $error_count,
        //     'skipped' => $skipped_count,
        //     'total_processed' => $total_products,
        //     'total_duration' => $total_duration,
        //     'average_per_product' => $total_products > 0 ? round($total_duration / $total_products, 2) : 0
        // ));
        
        // Return results for tracking
        return array(
            'images_updated' => $images_updated, // Products that had images downloaded
            'downloaded_count' => $downloaded_count,
            'error_count' => $error_count,
            'skipped_count' => $skipped_count,
            'total_processed' => $total_products
        );
    }
    
    /**
     * Batch check which products have images (featured or gallery)
     * Returns associative array: product_id => true/false
     * 
     * @param array $product_ids Array of product IDs to check
     * @return array Associative array: product_id => has_images (boolean)
     */
    private static function batch_check_products_have_images($product_ids) {
        global $wpdb;
        
        if (empty($product_ids)) {
            return array();
        }
        
        $results = array();
        
        // Process in chunks to avoid query size limits
        $chunks = array_chunk($product_ids, 500);
        
        foreach ($chunks as $chunk) {
            $ids_sql = implode(',', array_map('intval', $chunk));
            
            // Check for featured images (_thumbnail_id meta)
            $featured_query = "
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE post_id IN ({$ids_sql}) 
                AND meta_key = '_thumbnail_id' 
                AND meta_value > 0
            ";
            $featured_results = $wpdb->get_col($featured_query);
            
            // Check for gallery images (_product_image_gallery meta)
            $gallery_query = "
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE post_id IN ({$ids_sql}) 
                AND meta_key = '_product_image_gallery' 
                AND meta_value != '' 
                AND meta_value IS NOT NULL
            ";
            $gallery_results = $wpdb->get_col($gallery_query);
            
            // Mark products that have downloaded images (WordPress attachments)
            // Products synced in "External URLs" mode won't have these, so they'll be marked as missing images
            foreach ($chunk as $prod_id) {
                $has_featured = in_array($prod_id, $featured_results);
                $has_gallery = in_array($prod_id, $gallery_results);
                // Product has downloaded images if it has EITHER featured OR gallery
                // (This ensures products with only one type still count as having images)
                $results[$prod_id] = ($has_featured || $has_gallery);
            }
        }
        
        return $results;
    }
}


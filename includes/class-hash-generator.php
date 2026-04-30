<?php
/**
 * Hash Generator Class
 *
 * Generates SHA-256 hashes from product data for change detection
 * Includes both product content AND image URLs to detect any changes
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ASM_Hash_Generator {

    /**
     * Generate hash from API product data
     * Includes both product content AND image URLs for change detection
     *
     * @param object $api_data Product data from API
     * @param string $type Product type (wheel/tire)
     * @return string SHA-256 hash
     */
    public static function generate_hash_from_api_data($api_data, $type = 'wheel') {
        if (empty($api_data) || !is_object($api_data)) {
            return '';
        }

        // Convert object to array for processing
        $data_array = (array) $api_data;

        // Construct full image URLs and add them to data array for hashing
        // This ensures any change to image URLs (base or filename) is detected
        $image_urls = self::construct_image_urls($api_data, $type);
        // Always include _image_urls in hash, even if empty (for consistency with existing products)
        $data_array['_image_urls'] = $image_urls;

        // Remove image base fields and filename fields (we use full URLs instead)
        $data_array = self::remove_image_base_fields($data_array, $type);

        // Normalize and sort data for consistent hashing
        $normalized_data = self::normalize_data($data_array, $type);

        // Create JSON string from normalized data
        $json_string = wp_json_encode($normalized_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Generate SHA-256 hash
        $hash = hash('sha256', $json_string);

        return $hash;
    }

    /**
     * Generate hash from WooCommerce product object
     * Used for rebuilding hash table from existing products
     *
     * @param WC_Product $product Product object
     * @param string $type Product type (wheel/tire)
     * @return string SHA-256 hash
     */
    public static function generate_hash_from_product($product, $type = 'wheel') {
        if (!$product || !is_a($product, 'WC_Product')) {
            return '';
        }

        // Build data array from product
        $data_array = array();

        // Basic product data
        $data_array['sku'] = $product->get_sku();
        $data_array['name'] = $product->get_name();
        $data_array['price'] = $product->get_regular_price();
        $data_array['description'] = $product->get_description();
        $data_array['weight'] = $product->get_weight();

        // Inventory
        $data_array['manage_stock'] = $product->get_manage_stock();
        $data_array['stock_quantity'] = $product->get_stock_quantity();
        $data_array['stock_status'] = $product->get_stock_status();

        // Collect image URLs directly from stored meta fields (feature_img_link, galleryimage1, etc.)
        // These are the actual full URLs that were constructed from API data
        $image_urls = array();
        
        // Featured image URL
        $featured_url = get_post_meta($product->get_id(), 'feature_img_link', true);
        if (!empty($featured_url)) {
            $image_urls[] = $featured_url;
        }
        
        // Gallery image URLs
        for ($i = 1; $i <= 20; $i++) {
            $gallery_url = get_post_meta($product->get_id(), 'galleryimage' . $i, true);
            if (!empty($gallery_url)) {
                $image_urls[] = $gallery_url;
            }
        }
        
        // Sort URLs for consistent hashing (same as API data)
        sort($image_urls);
        // Always include _image_urls in hash, even if empty (for consistency)
        $data_array['_image_urls'] = $image_urls;

        // Get all product meta (excluding image URL fields - we use _image_urls instead)
        $meta_data = get_post_meta($product->get_id());

        foreach ($meta_data as $key => $value) {
            // Skip WordPress attachment IDs
            if (in_array($key, array('_thumbnail_id', '_product_image_gallery'))) {
                continue;
            }

            // Skip image URL meta keys (we use _image_urls instead)
            if ($key === 'feature_img_link' || strpos($key, 'galleryimage') === 0) {
                continue;
            }

            // Skip WooCommerce internal meta keys that don't affect product data
            if (strpos($key, '_') === 0 && !in_array($key, array('_sku', '_weight', '_regular_price', '_price'))) {
                // Include only relevant custom meta
                if (strpos($key, '_product_attributes') === 0 ||
                    strpos($key, '_') !== 0 ||
                    in_array($key, array('_sku', '_weight', '_regular_price', '_price'))) {
                    // Include this meta
                } else {
                    // Skip WooCommerce internal meta
                    continue;
                }
            }

            // get_post_meta($id) returns array of arrays — first element matches the
            // single-value form get_post_meta($id, $key, true) would return, without
            // a second cache/DB hit per key.
            $meta_value = is_array($value) && isset($value[0]) ? $value[0] : $value;

            // Skip empty values
            if ($meta_value === '' || $meta_value === null) {
                continue;
            }

            // Skip serialized arrays/objects for now (we'll handle specific ones)
            if (is_serialized($meta_value)) {
                // Only include product attributes
                if ($key === '_product_attributes') {
                    $data_array['meta'][$key] = maybe_unserialize($meta_value);
                }
                continue;
            }

            $data_array['meta'][$key] = $meta_value;
        }

        // Get categories
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        if (!empty($categories) && !is_wp_error($categories)) {
            $data_array['categories'] = $categories;
        }

        // Get tags
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        if (!empty($tags) && !is_wp_error($tags)) {
            $data_array['tags'] = $tags;
        }

        // Normalize and sort data
        $normalized_data = self::normalize_data($data_array, $type);

        // Create JSON string
        $json_string = wp_json_encode($normalized_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Generate SHA-256 hash
        $hash = hash('sha256', $json_string);

        return $hash;
    }

    /**
     * Construct full image URLs from API data
     * Combines base URL with filenames to create complete image URLs
     * Uses only filenames if base URL is not available (for consistent hashing)
     *
     * @param object $api_data API product data
     * @param string $type Product type
     * @return array Array of full image URLs (sorted for consistency)
     */
    private static function construct_image_urls($api_data, $type) {
        $image_urls = array();
        
        // Get base URL from API data or use defaults
        $image_base = '';
        if ($type === 'tire') {
            $image_base = isset($api_data->ImgUrlBase) && !empty($api_data->ImgUrlBase) 
                ? $api_data->ImgUrlBase 
                : 'https://storage.googleapis.com/autosync_tires/';
        } else {
            $image_base = isset($api_data->ImgUrlBase) && !empty($api_data->ImgUrlBase) 
                ? $api_data->ImgUrlBase 
                : 'https://wheels.autosyncstudio.com/webp/';
        }
        
        if ($type === 'tire') {
            // Tire images
            if (!empty($api_data->ImgAngle)) {
                $image_urls[] = $image_base . $api_data->ImgAngle;
            }
            if (!empty($api_data->ImgFront)) {
                $image_urls[] = $image_base . $api_data->ImgFront;
            }
            if (!empty($api_data->ImgSide1)) {
                $image_urls[] = $image_base . $api_data->ImgSide1;
            }
            if (!empty($api_data->ImgSide2)) {
                $image_urls[] = $image_base . $api_data->ImgSide2;
            }
            if (!empty($api_data->ImgThumb)) {
                $image_urls[] = $image_base . $api_data->ImgThumb;
            }
        } else {
            // Wheel images (Img0001 to Img0020)
            for ($i = 1; $i <= 20; $i++) {
                $img_key = 'Img' . str_pad($i, 4, '0', STR_PAD_LEFT);
                if (!empty($api_data->$img_key)) {
                    $image_urls[] = $image_base . $api_data->$img_key;
                }
            }
        }
        
        // Sort URLs for consistent hashing
        sort($image_urls);
        
        return $image_urls;
    }
    

    /**
     * Remove image URL base fields and filename fields from data array
     * We use full constructed URLs instead (stored in _image_urls)
     *
     * @param array $data_array Data array
     * @param string $type Product type
     * @return array Data array with image fields removed
     */
    private static function remove_image_base_fields($data_array, $type) {
        // Remove image URL base fields
        $base_url_fields = array(
            'ImgUrlBase',
            'RotationImgUrlBase',
            'BrandLogoImgUrlBase'
        );

        foreach ($base_url_fields as $field) {
            unset($data_array[$field]);
        }

        // Remove image filename fields (we use full URLs in _image_urls instead)
        if ($type === 'tire') {
            $image_fields = array('ImgAngle', 'ImgFront', 'ImgSide1', 'ImgSide2', 'ImgThumb');
        } else {
            $image_fields = array();
            for ($i = 1; $i <= 20; $i++) {
                $image_fields[] = 'Img' . str_pad($i, 4, '0', STR_PAD_LEFT);
            }
        }

        foreach ($image_fields as $field) {
            unset($data_array[$field]);
        }

        return $data_array;
    }

    /**
     * Check if a meta key is a WordPress attachment ID (not image URL)
     * We exclude attachment IDs but include image URLs in the hash
     *
     * @param string $key Meta key
     * @return bool True if it's an attachment ID field
     */
    private static function is_image_meta_key($key) {
        // Only exclude WordPress attachment ID fields
        // Include image URL fields (feature_img_link, galleryimage1, etc.) in hash
        $attachment_id_patterns = array(
            '_thumbnail_id',
            '_product_image_gallery'
        );

        foreach ($attachment_id_patterns as $pattern) {
            if (strpos($key, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize data for consistent hashing
     * Sorts arrays, converts objects to arrays, handles nulls
     *
     * @param array $data Data array
     * @param string $type Product type
     * @return array Normalized data
     */
    private static function normalize_data($data, $type) {
        $normalized = array();

        // Type-specific field ordering for consistency
        if ($type === 'tire') {
            // Tire-specific field order (including full image URLs for hash)
            $field_order = array(
                'Id', 'PartNumber', 'Brand', 'BrandId', 'Model', 'ModelId',
                'Size', 'LoadRating', 'SpeedRating', 'Diameter', 'SectionWidth',
                'Sidewall', 'OverallDiameter', 'LoadCapacitySingle', 'LoadRange',
                'MeasuringRimWidth', 'AspectRatio', 'TreadDepth', 'Weight',
                'MaxInflationPressure', 'RevolutionsPerMile', 'Upc', 'Asin',
                'Ean', 'Warranty', 'Price', 'Inventory', 'Description',
                'DisplayName', 'Benefits', 'Features', 'VehicleTypeTags',
                'SegmentTags',
                // Full image URLs (included in hash to detect image changes)
                '_image_urls',
                'sku', 'name', 'price', 'description', 'weight',
                'manage_stock', 'stock_quantity', 'stock_status', 'meta',
                'categories', 'tags'
            );
        } else {
            // Wheel-specific field order (including full image URLs for hash)
            $field_order = array(
                'Id', 'Pn', 'Brand', 'BrandId', 'ModelName', 'StyleId',
                'Diameter', 'Width', 'Offset',
                'Bore', 'Finish', 'Color', 'Weight', 'Upc', 'Price',
                'Inventory', 'Description', 'Structure', 'Material',
                'LoadRating', 'BoltCircle1', 'BoltCircle2', 'LugCount',
                // Full image URLs (included in hash to detect image changes)
                '_image_urls',
                'sku', 'name', 'price', 'description', 'weight', 'manage_stock',
                'stock_quantity', 'stock_status', 'meta', 'categories', 'tags'
            );
        }

        // Process data in field order
        foreach ($field_order as $field) {
            if (isset($data[$field])) {
                $normalized[$field] = self::normalize_value($data[$field]);
            }
        }

        // Add any remaining fields not in the order list
        foreach ($data as $key => $value) {
            if (!isset($normalized[$key])) {
                $normalized[$key] = self::normalize_value($value);
            }
        }

        // Sort the normalized array by key for consistency
        ksort($normalized);

        return $normalized;
    }

    /**
     * Generate price and stock hash from API product data
     * Only includes Price and Inventory fields for change detection
     *
     * @param object $api_data Product data from API
     * @return string SHA-256 hash
     */
    public static function generate_price_stock_hash_from_api_data($api_data) {
        if (empty($api_data) || !is_object($api_data)) {
            return '';
        }

        // Only include Price and Inventory
        $price_stock_data = array(
            'Price' => isset($api_data->Price) ? $api_data->Price : '',
            'Inventory' => isset($api_data->Inventory) ? $api_data->Inventory : ''
        );

        // Normalize values
        $price_stock_data['Price'] = is_numeric($price_stock_data['Price']) ? strval($price_stock_data['Price']) : '';
        $price_stock_data['Inventory'] = is_numeric($price_stock_data['Inventory']) ? strval($price_stock_data['Inventory']) : '';

        // Create JSON string
        $json_string = wp_json_encode($price_stock_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Generate SHA-256 hash
        $hash = hash('sha256', $json_string);

        return $hash;
    }

    /**
     * Generate price and stock hash from WooCommerce product object
     * Used for rebuilding hash table from existing products
     *
     * @param WC_Product $product Product object
     * @return string SHA-256 hash
     */
    public static function generate_price_stock_hash_from_product($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return '';
        }

        // Only include price and stock
        $price_stock_data = array(
            'Price' => $product->get_regular_price(),
            'Inventory' => $product->get_stock_quantity()
        );

        // Normalize values
        $price_stock_data['Price'] = is_numeric($price_stock_data['Price']) ? strval($price_stock_data['Price']) : '';
        $price_stock_data['Inventory'] = is_numeric($price_stock_data['Inventory']) ? strval($price_stock_data['Inventory']) : '';

        // Create JSON string
        $json_string = wp_json_encode($price_stock_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Generate SHA-256 hash
        $hash = hash('sha256', $json_string);

        return $hash;
    }

    /**
     * Normalize a single value
     *
     * @param mixed $value Value to normalize
     * @return mixed Normalized value
     */
    private static function normalize_value($value) {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            // Convert to string for consistent comparison
            return strval($value);
        }

        if (is_array($value)) {
            // Recursively normalize array
            $normalized = array();
            foreach ($value as $k => $v) {
                $normalized[$k] = self::normalize_value($v);
            }
            // Sort array by key for consistency
            ksort($normalized);
            return $normalized;
        }

        if (is_object($value)) {
            // Convert object to array
            return self::normalize_value((array) $value);
        }

        // Convert to string and trim
        return trim(strval($value));
    }
}


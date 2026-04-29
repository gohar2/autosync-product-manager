<?php
/**
 * Brand Manager Class
 *
 * Manages brand storage and active status for wheels and tires
 * Stores data in wp_options table with key "asm_products_brands" as JSON
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';

class ASM_Brand_Manager {

    /**
     * Option key for storing brands
     */
    private static $option_key = 'asm_products_brands';

    /**
     * Get all brands data from options
     *
     * @return array Brands data structure: {"wheels": [...], "tires": [...]}
     */
    private static function get_all_brands_data() {
        $data = get_option(self::$option_key, '');
        
        if (empty($data)) {
            return array(
                'wheels' => array(),
                'tires' => array()
            );
        }
        
        $decoded = json_decode($data, true);
        
        if (!is_array($decoded)) {
            return array(
                'wheels' => array(),
                'tires' => array()
            );
        }
        
        // Ensure both keys exist
        if (!isset($decoded['wheels'])) {
            $decoded['wheels'] = array();
        }
        if (!isset($decoded['tires'])) {
            $decoded['tires'] = array();
        }
        
        return $decoded;
    }

    /**
     * Save all brands data to options
     *
     * @param array $data Brands data structure
     * @return bool Success
     */
    private static function save_all_brands_data($data) {
        $json = json_encode($data);
        return update_option(self::$option_key, $json);
    }

    /**
     * Get all brands by product type
     *
     * @param string $product_type 'wheel' or 'tire'
     * @return array Brand records (as objects for compatibility)
     */
    public static function get_brands($product_type) {
        $all_data = self::get_all_brands_data();
        $product_key = $product_type === 'tire' ? 'tires' : 'wheels';
        
        if (!isset($all_data[$product_key])) {
            return array();
        }
        
        $brands = array();
        foreach ($all_data[$product_key] as $brand_data) {
            // Convert to object for compatibility with existing code
            $brand = new stdClass();
            $brand->brand_name = isset($brand_data['brand_name']) ? $brand_data['brand_name'] : '';
            $brand->brand_id = isset($brand_data['brand_id']) ? intval($brand_data['brand_id']) : 0;
            // Return is_active as integer (1/0) for compatibility
            $is_active_bool = isset($brand_data['is_active']) ? (bool)$brand_data['is_active'] : false;
            $brand->is_active = $is_active_bool ? 1 : 0;
            $brands[] = $brand;
        }
        
        // Sort by brand name
        usort($brands, function($a, $b) {
            return strcmp($a->brand_name, $b->brand_name);
        });
        
        return $brands;
    }

    /**
     * Save or update brand
     *
     * @param string $brand_name Brand name
     * @param int $brand_id Brand ID from API
     * @param string $product_type 'wheel' or 'tire'
     * @param int $is_active Active status (1 or 0) - only used for new brands
     * @return bool Success
     */
    public static function save_brand($brand_name, $brand_id, $product_type, $is_active = 1) {
        if (empty($brand_name) || empty($brand_id) || empty($product_type)) {
            return false;
        }

        $all_data = self::get_all_brands_data();
        $product_key = $product_type === 'tire' ? 'tires' : 'wheels';
        
        // Find existing brand by brand_id
        $found_index = -1;
        $existing_is_active = null;
        foreach ($all_data[$product_key] as $index => $brand_data) {
            if (isset($brand_data['brand_id']) && intval($brand_data['brand_id']) === intval($brand_id)) {
                $found_index = $index;
                $existing_is_active = isset($brand_data['is_active']) ? $brand_data['is_active'] : true;
                break;
            }
        }
        
        if ($found_index >= 0) {
            // Brand already exists - preserve existing is_active status, only update brand_name if changed
            $all_data[$product_key][$found_index]['brand_name'] = sanitize_text_field($brand_name);
            // Preserve existing is_active status - don't change it
            return self::save_all_brands_data($all_data);
        } else {
            // Add new brand with is_active = true by default
            $brand_data = array(
                'brand_name' => sanitize_text_field($brand_name),
                'brand_id' => intval($brand_id),
                'is_active' => true // New brands default to active
            );
            $all_data[$product_key][] = $brand_data;
            return self::save_all_brands_data($all_data);
        }
    }

    /**
     * Update brand active status
     *
     * @param int $brand_id Brand ID
     * @param string $product_type 'wheel' or 'tire'
     * @param int $is_active Active status (1 or 0)
     * @return bool Success
     */
    public static function update_active_status($brand_id, $product_type, $is_active) {
        if (empty($brand_id) || empty($product_type)) {
            return false;
        }

        $all_data = self::get_all_brands_data();
        $product_key = $product_type === 'tire' ? 'tires' : 'wheels';
        
        // Find and update brand
        foreach ($all_data[$product_key] as $index => $brand_data) {
            if (isset($brand_data['brand_id']) && intval($brand_data['brand_id']) === intval($brand_id)) {
                $all_data[$product_key][$index]['is_active'] = (bool)$is_active;
                return self::save_all_brands_data($all_data);
            }
        }
        
        return false;
    }

    /**
     * Batch save brands
     *
     * @param array $brands Array of brand arrays with keys: brand_name, brand_id, product_type, is_active
     * @return int Number of brands saved
     */
    public static function batch_save_brands($brands) {
        if (empty($brands) || !is_array($brands)) {
            return 0;
        }

        $saved = 0;
        foreach ($brands as $brand) {
            $brand_name = isset($brand['brand_name']) ? $brand['brand_name'] : '';
            $brand_id = isset($brand['brand_id']) ? intval($brand['brand_id']) : 0;
            $product_type = isset($brand['product_type']) ? $brand['product_type'] : '';
            $is_active = isset($brand['is_active']) ? intval($brand['is_active']) : 1;

            if (self::save_brand($brand_name, $brand_id, $product_type, $is_active)) {
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Batch update brand active status
     *
     * @param array $updates Array of arrays with keys: brand_id, product_type, is_active
     * @return array Results with updated count and errors
     */
    public static function batch_update_active_status($updates) {
        if (empty($updates) || !is_array($updates)) {
            return array(
                'updated' => 0,
                'errors' => array()
            );
        }

        $all_data = self::get_all_brands_data();
        $updated = 0;
        $errors = array();

        foreach ($updates as $update) {
            $brand_id = isset($update['brand_id']) ? intval($update['brand_id']) : 0;
            $product_type = isset($update['product_type']) ? $update['product_type'] : '';
            $is_active = isset($update['is_active']) ? intval($update['is_active']) : 0;

            if (empty($brand_id) || empty($product_type)) {
                $errors[] = 'Invalid update data: missing brand_id or product_type';
                continue;
            }

            $product_key = $product_type === 'tire' ? 'tires' : 'wheels';
            
            // Find and update brand
            $found = false;
            foreach ($all_data[$product_key] as $index => $brand_data) {
                if (isset($brand_data['brand_id']) && intval($brand_data['brand_id']) === $brand_id) {
                    $all_data[$product_key][$index]['is_active'] = (bool)$is_active;
                    $updated++;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $errors[] = 'Brand ID ' . $brand_id . ' not found for ' . $product_type;
            }
        }

        // Save all changes at once
        if ($updated > 0) {
            self::save_all_brands_data($all_data);
        }

        return array(
            'updated' => $updated,
            'errors' => $errors
        );
    }

    /**
     * Get total brand count
     *
     * @param string|null $product_type Optional product type filter
     * @return int Total count
     */
    public static function get_total_count($product_type = null) {
        $all_data = self::get_all_brands_data();
        
        if ($product_type) {
            $product_key = $product_type === 'tire' ? 'tires' : 'wheels';
            return isset($all_data[$product_key]) ? count($all_data[$product_key]) : 0;
        }
        
        $total = 0;
        if (isset($all_data['wheels'])) {
            $total += count($all_data['wheels']);
        }
        if (isset($all_data['tires'])) {
            $total += count($all_data['tires']);
        }
        
        return $total;
    }
}

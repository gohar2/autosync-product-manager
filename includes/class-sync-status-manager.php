<?php
/**
 * Sync Status Manager Class
 *
 * Manages sync status storage and retrieval
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ASM_Sync_Status_Manager {

    /**
     * Option name for storing sync status
     */
    const OPTION_NAME = 'asm_products_sync_status';

    /**
     * Save sync status
     *
     * @param array $sync_data Sync data including brands, summary, etc.
     * @return bool Success
     */
    public static function save_sync_status($sync_data) {
        // Add timestamp
        $sync_data['last_sync_time'] = current_time('mysql');
        $sync_data['last_sync_timestamp'] = current_time('timestamp');
        $sync_data['last_sync_date'] = date('Y-m-d', current_time('timestamp'));
        
        // Save to option
        $saved = update_option(self::OPTION_NAME, json_encode($sync_data, JSON_PRETTY_PRINT));
        
        return $saved !== false;
    }

    /**
     * Get sync status
     *
     * @return array|null Sync status data or null if not found
     */
    public static function get_sync_status() {
        $option_value = get_option(self::OPTION_NAME);
        
        if (empty($option_value)) {
            return null;
        }
        
        $data = json_decode($option_value, true);
        
        return is_array($data) ? $data : null;
    }

    /**
     * Format sync data from cron sync stats
     *
     * @param array $stats Stats from cron sync
     * @param string $sync_type 'manual' or 'cron'
     * @return array Formatted sync data
     */
    public static function format_sync_data_from_stats($stats, $sync_type = 'manual') {
        $sync_data = array(
            'sync_type' => $sync_type,
            'brands' => array(),
            'summary' => array(
                'total_pages' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_time' => 0,
                'pages_processed' => 0,
                'total_brands_tires' => 0,
                'total_brands_wheels' => 0
            )
        );

        // Process tire brands if available
        if (isset($stats['tires']) && isset($stats['tires']['brands'])) {
            foreach ($stats['tires']['brands'] as $brand_data) {
                $sync_data['brands'][] = array(
                    'product_type' => 'tire',
                    'brand_id' => $brand_data['brand_id'],
                    'brand_name' => $brand_data['brand_name'],
                    'imported' => isset($brand_data['imported']) ? (int)$brand_data['imported'] : 0,
                    'updated' => isset($brand_data['updated']) ? (int)$brand_data['updated'] : 0,
                    'skipped' => isset($brand_data['skipped']) ? (int)$brand_data['skipped'] : 0,
                    'pages' => isset($brand_data['pages']) ? (int)$brand_data['pages'] : 0,
                    'time' => isset($brand_data['time']) ? (float)$brand_data['time'] : 0
                );
            }
            $sync_data['summary']['total_brands_tires'] = count($stats['tires']['brands']);
        } else if (isset($stats['tires'])) {
            // Aggregate stats for tires
            $sync_data['summary']['total_brands_tires'] = isset($stats['tires']['brands_processed']) ? (int)$stats['tires']['brands_processed'] : 0;
        }

        // Process wheel brands if available
        if (isset($stats['wheels']) && isset($stats['wheels']['brands'])) {
            foreach ($stats['wheels']['brands'] as $brand_data) {
                $sync_data['brands'][] = array(
                    'product_type' => 'wheel',
                    'brand_id' => $brand_data['brand_id'],
                    'brand_name' => $brand_data['brand_name'],
                    'imported' => isset($brand_data['imported']) ? (int)$brand_data['imported'] : 0,
                    'updated' => isset($brand_data['updated']) ? (int)$brand_data['updated'] : 0,
                    'skipped' => isset($brand_data['skipped']) ? (int)$brand_data['skipped'] : 0,
                    'pages' => isset($brand_data['pages']) ? (int)$brand_data['pages'] : 0,
                    'time' => isset($brand_data['time']) ? (float)$brand_data['time'] : 0
                );
            }
            $sync_data['summary']['total_brands_wheels'] = count($stats['wheels']['brands']);
        } else if (isset($stats['wheels'])) {
            // Aggregate stats for wheels
            $sync_data['summary']['total_brands_wheels'] = isset($stats['wheels']['brands_processed']) ? (int)$stats['wheels']['brands_processed'] : 0;
        }

        // Calculate summary totals
        if (isset($stats['tires'])) {
            $sync_data['summary']['total_pages'] += isset($stats['tires']['total_pages']) ? (int)$stats['tires']['total_pages'] : 0;
            $sync_data['summary']['total_imported'] += isset($stats['tires']['total_imported']) ? (int)$stats['tires']['total_imported'] : 0;
            $sync_data['summary']['total_updated'] += isset($stats['tires']['total_updated']) ? (int)$stats['tires']['total_updated'] : 0;
            $sync_data['summary']['total_skipped'] += isset($stats['tires']['total_skipped']) ? (int)$stats['tires']['total_skipped'] : 0;
        }

        if (isset($stats['wheels'])) {
            $sync_data['summary']['total_pages'] += isset($stats['wheels']['total_pages']) ? (int)$stats['wheels']['total_pages'] : 0;
            $sync_data['summary']['total_imported'] += isset($stats['wheels']['total_imported']) ? (int)$stats['wheels']['total_imported'] : 0;
            $sync_data['summary']['total_updated'] += isset($stats['wheels']['total_updated']) ? (int)$stats['wheels']['total_updated'] : 0;
            $sync_data['summary']['total_skipped'] += isset($stats['wheels']['total_skipped']) ? (int)$stats['wheels']['total_skipped'] : 0;
        }

        // Total time
        if (isset($stats['start_time'])) {
            $sync_data['summary']['total_time'] = round(microtime(true) - $stats['start_time'], 2);
        } else if (isset($stats['duration'])) {
            $sync_data['summary']['total_time'] = (float)$stats['duration'];
        }

        // Pages processed
        $sync_data['summary']['pages_processed'] = $sync_data['summary']['total_pages'];

        return $sync_data;
    }
}

/**
 * Price/Stock Sync Status Manager Class
 *
 * Manages price/stock sync status storage and retrieval
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */
class ASM_Price_Stock_Sync_Status_Manager {

    /**
     * Option name for storing price/stock sync status
     */
    const OPTION_NAME = 'asm_price_stock_sync_status';

    /**
     * Save price/stock sync status
     *
     * @param array $sync_data Sync data including stats, etc.
     * @return bool Success
     */
    public static function save_sync_status($sync_data) {
        // Add timestamp
        $sync_data['last_sync_time'] = current_time('mysql');
        $sync_data['last_sync_timestamp'] = current_time('timestamp');
        $sync_data['last_sync_date'] = date('Y-m-d', current_time('timestamp'));
        
        // Save to option
        $saved = update_option(self::OPTION_NAME, json_encode($sync_data, JSON_PRETTY_PRINT));
        
        return $saved !== false;
    }

    /**
     * Get price/stock sync status
     *
     * @return array|null Sync status data or null if not found
     */
    public static function get_sync_status() {
        $option_value = get_option(self::OPTION_NAME);
        
        if (empty($option_value)) {
            return null;
        }
        
        $data = json_decode($option_value, true);
        
        return is_array($data) ? $data : null;
    }

    /**
     * Format sync data from price/stock sync stats
     *
     * @param array $stats Stats from price/stock sync
     * @param string $sync_type 'manual' or 'cron'
     * @return array Formatted sync data
     */
    public static function format_sync_data_from_stats($stats, $sync_type = 'manual') {
        $sync_data = array(
            'sync_type' => $sync_type,
            'summary' => array(
                'tires_updated' => 0,
                'tires_skipped' => 0,
                'tires_errors' => 0,
                'wheels_updated' => 0,
                'wheels_skipped' => 0,
                'wheels_errors' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_errors' => 0,
                'total_time' => 0,
                'total_brands_tires' => 0,
                'total_brands_wheels' => 0
            )
        );

        // Process tire stats
        if (isset($stats['tires'])) {
            $sync_data['summary']['tires_updated'] = isset($stats['tires']['total_updated']) ? (int)$stats['tires']['total_updated'] : 0;
            $sync_data['summary']['tires_skipped'] = isset($stats['tires']['total_skipped']) ? (int)$stats['tires']['total_skipped'] : 0;
            $sync_data['summary']['tires_errors'] = isset($stats['tires']['total_errors']) ? (int)$stats['tires']['total_errors'] : 0;
            $sync_data['summary']['total_brands_tires'] = isset($stats['tires']['brands_processed']) ? (int)$stats['tires']['brands_processed'] : 0;
        }

        // Process wheel stats
        if (isset($stats['wheels'])) {
            $sync_data['summary']['wheels_updated'] = isset($stats['wheels']['total_updated']) ? (int)$stats['wheels']['total_updated'] : 0;
            $sync_data['summary']['wheels_skipped'] = isset($stats['wheels']['total_skipped']) ? (int)$stats['wheels']['total_skipped'] : 0;
            $sync_data['summary']['wheels_errors'] = isset($stats['wheels']['total_errors']) ? (int)$stats['wheels']['total_errors'] : 0;
            $sync_data['summary']['total_brands_wheels'] = isset($stats['wheels']['brands_processed']) ? (int)$stats['wheels']['brands_processed'] : 0;
        }

        // Calculate totals
        $sync_data['summary']['total_updated'] = $sync_data['summary']['tires_updated'] + $sync_data['summary']['wheels_updated'];
        $sync_data['summary']['total_skipped'] = $sync_data['summary']['tires_skipped'] + $sync_data['summary']['wheels_skipped'];
        $sync_data['summary']['total_errors'] = $sync_data['summary']['tires_errors'] + $sync_data['summary']['wheels_errors'];

        // Total time
        if (isset($stats['duration'])) {
            $sync_data['summary']['total_time'] = (float)$stats['duration'];
        } elseif (isset($stats['start_time'])) {
            $sync_data['summary']['total_time'] = round(microtime(true) - $stats['start_time'], 2);
        }

        return $sync_data;
    }
}


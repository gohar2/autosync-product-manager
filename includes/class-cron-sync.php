<?php
/**
 * Cron Sync Class
 *
 * Handles product synchronization for external cron runners and public endpoints.
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
require_once ASM_PLUGIN_DIR . 'includes/class-sql-batch-inserter.php';
require_once ASM_PLUGIN_DIR . 'includes/class-sync-status-manager.php';
require_once ASM_PLUGIN_DIR . 'includes/class-email-notifier.php';

class ASM_Cron_Sync {

    /**
     * Legacy WordPress cron hook name, kept only to clear old scheduled events.
     */
    const CRON_HOOK = 'asm_daily_sync_all_brands';

    /**
     * Clear legacy WordPress cron events.
     */
    public static function unregister_cron() {
        return wp_clear_scheduled_hook(self::CRON_HOOK) !== false;
    }

    /**
     * Check if brands exist, fetch and save if they don't
     * 
     * @param ASM_API_Client $api_client API client instance
     * @return bool True if brands exist or were fetched successfully
     */
    private static function ensure_brands_exist($api_client) {
        // Check if any brands exist (active or inactive)
        $total_brands = ASM_Brand_Manager::get_total_count();
        
        if ($total_brands > 0) {
            // ASM_Logger::info('Brands already exist in database', array(
            //     'total_brands' => $total_brands
            // ));
            return true;
        }
        
        // No brands found - fetch from API
        // ASM_Logger::info('No brands found - fetching from API', array());
        
        try {
            // Fetch all tire brands
            $tire_result = $api_client->get_all_tire_brands();
            $tire_brands_saved = 0;
            
            if ($tire_result && !empty($tire_result['brands'])) {
                $brands_to_save = array();
                foreach ($tire_result['brands'] as $brand) {
                    $brands_to_save[] = array(
                        'brand_name' => $brand->Brand,
                        'brand_id' => $brand->Id,
                        'product_type' => 'tire',
                        'is_active' => 1 // Default to active for new brands
                    );
                }
                $tire_brands_saved = ASM_Brand_Manager::batch_save_brands($brands_to_save);
            }

            // Fetch all wheel brands
            $wheel_result = $api_client->get_all_wheel_brands();
            $wheel_brands_saved = 0;
            
            if ($wheel_result && !empty($wheel_result['brands'])) {
                $brands_to_save = array();
                foreach ($wheel_result['brands'] as $brand) {
                    $brands_to_save[] = array(
                        'brand_name' => $brand->Brand,
                        'brand_id' => $brand->Id,
                        'product_type' => 'wheel',
                        'is_active' => 1 // Default to active for new brands
                    );
                }
                $wheel_brands_saved = ASM_Brand_Manager::batch_save_brands($brands_to_save);
            }

            $total_saved = $tire_brands_saved + $wheel_brands_saved;
            
            // ASM_Logger::info('Brands fetched and saved from API', array(
            //     'tire_brands_saved' => $tire_brands_saved,
            //     'wheel_brands_saved' => $wheel_brands_saved,
            //     'total_saved' => $total_saved
            // ));
            
            return $total_saved > 0;
            
        } catch (Exception $e) {
            // ASM_Logger::error('Error fetching brands from API', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            return false;
        } catch (Error $e) {
            // ASM_Logger::error('Fatal error fetching brands from API', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            return false;
        }
    }

    /**
     * Sync all active brands.
     *
     * Called by the public HTTP endpoint and the direct external runner.
     */
    public static function sync_all_active_brands() {
        // Disable Query Monitor early to save memory
        if (!defined('QM_DISABLED')) {
            define('QM_DISABLED', true);
        }
        
        // Disable other memory-intensive plugins during sync
        if (defined('SAVEQUERIES')) {
            // Disable query saving if enabled
            if (SAVEQUERIES) {
                define('SAVEQUERIES', false);
            }
        }
        
        // ASM_Logger::info('=== EXTERNAL PRODUCT SYNC STARTED ===', array(
        //     'time' => current_time('mysql'),
        //     'memory_limit' => ini_get('memory_limit'),
        //     'max_execution_time' => ini_get('max_execution_time'),
        //     'php_version' => PHP_VERSION,
        //     'initial_memory' => size_format(memory_get_usage(true))
        // ));

        // Increase limits for cron processing
        // Note: set_time_limit(0) may not work in all environments (especially on staging)
        $original_time_limit = ini_get('max_execution_time');
        $original_memory_limit = ini_get('memory_limit');
        
        @set_time_limit(0); // No time limit
        // Try to set memory limit to 1GB (1024M) for large syncs
        @ini_set('memory_limit', '1024M');
        // Also try WordPress function
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        
        $new_time_limit = ini_get('max_execution_time');
        $new_memory_limit = ini_get('memory_limit');
        
        // Force garbage collection before starting
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Log memory and time limit changes
        // ASM_Logger::info('Resource limits configured', array(
        //     'original_memory_limit' => $original_memory_limit,
        //     'new_memory_limit' => $new_memory_limit,
        //     'original_time_limit' => $original_time_limit,
        //     'new_time_limit' => $new_time_limit,
        //     'time_limit_changed' => ($original_time_limit != $new_time_limit),
        //     'memory_limit_changed' => ($original_memory_limit != $new_memory_limit)
        // ));
        
        // Warn if limits couldn't be increased
        if ($original_time_limit == $new_time_limit && $new_time_limit > 0 && $new_time_limit < 300) {
            // ASM_Logger::warning('Time limit may be too low for sync execution', array(
            //     'current_limit' => $new_time_limit,
            //     'recommendation' => 'Consider increasing max_execution_time in php.ini or running via WP-CLI'
            // ));
        }

        $api_client = new ASM_API_Client();
        
        // Check if brands exist, fetch if they don't
        if (!self::ensure_brands_exist($api_client)) {
            // ASM_Logger::warning('Failed to fetch brands - sync may have no brands to process');
        }
        $stats = array(
            'tires' => array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array() // Brand-wise stats
            ),
            'wheels' => array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array() // Brand-wise stats
            ),
            'start_time' => microtime(true)
        );

        // Sync tires first - with error handling to ensure wheels still run if tires fail
        try {
            // ASM_Logger::info('Starting tire sync', array());
            $stats['tires'] = self::sync_product_type('tire', $api_client);
            // ASM_Logger::info('Tire sync completed successfully', array(
            //     'tire_stats' => $stats['tires']
            // ));
        } catch (Exception $e) {
            // ASM_Logger::error('Tire sync failed with exception', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            $stats['tires'] = array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array(),
                'error' => $e->getMessage()
            );
        } catch (Error $e) {
            // ASM_Logger::error('Tire sync failed with fatal error', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            $stats['tires'] = array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array(),
                'error' => $e->getMessage()
            );
        }
        
        // Clean up after tires sync
        unset($api_client);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Recreate API client for wheels
        $api_client = new ASM_API_Client();

        // Then sync wheels - with error handling to ensure completion even if wheels fail
        try {
            // ASM_Logger::info('Starting wheel sync', array());
            $stats['wheels'] = self::sync_product_type('wheel', $api_client);
            // ASM_Logger::info('Wheel sync completed successfully', array(
            //     'wheel_stats' => $stats['wheels']
            // ));
        } catch (Exception $e) {
            // ASM_Logger::error('Wheel sync failed with exception', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            $stats['wheels'] = array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array(),
                'error' => $e->getMessage()
            );
        } catch (Error $e) {
            // ASM_Logger::error('Wheel sync failed with fatal error', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            $stats['wheels'] = array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array(),
                'error' => $e->getMessage()
            );
        }
        
        // Final cleanup
        unset($api_client);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $duration = round(microtime(true) - $stats['start_time'], 2);
        $stats['duration'] = $duration;

        // ASM_Logger::info('=== EXTERNAL PRODUCT SYNC COMPLETED ===', array(
        //     'duration_seconds' => $duration,
        //     'tire_stats' => $stats['tires'],
        //     'wheel_stats' => $stats['wheels'],
        //     'memory_peak' => size_format(memory_get_peak_usage(true))
        // ));

        // Save sync status
        $sync_data = ASM_Sync_Status_Manager::format_sync_data_from_stats($stats, 'cron');
        ASM_Sync_Status_Manager::save_sync_status($sync_data);

        // Send email notification with error handling
        $stats['duration'] = $duration;
        
        // Log the email address that will receive the notification
        $admin_email = get_option('admin_email');
        // ASM_Logger::info('Preparing to send cron sync email notification', array(
        //     'admin_email' => $admin_email,
        //     'email_configured' => !empty($admin_email),
        //     'stats_summary' => array(
        //         'tires_imported' => isset($stats['tires']['total_imported']) ? $stats['tires']['total_imported'] : 0,
        //         'tires_updated' => isset($stats['tires']['total_updated']) ? $stats['tires']['total_updated'] : 0,
        //         'wheels_imported' => isset($stats['wheels']['total_imported']) ? $stats['wheels']['total_imported'] : 0,
        //         'wheels_updated' => isset($stats['wheels']['total_updated']) ? $stats['wheels']['total_updated'] : 0,
        //         'duration' => $duration
        //     )
        // ));
        error_log('ASM Cron: Email will be sent to: ' . ($admin_email ? $admin_email : 'NOT CONFIGURED'));
        
        try {
            $email_sent = ASM_Email_Notifier::send_sync_email('all', $stats);
            
            // Log email attempt result
            // ASM_Logger::info('Cron sync email notification result', array(
            //     'email_sent' => $email_sent,
            //     'admin_email' => $admin_email,
            //     'stats_summary' => array(
            //         'tires_imported' => isset($stats['tires']['total_imported']) ? $stats['tires']['total_imported'] : 0,
            //         'wheels_imported' => isset($stats['wheels']['total_imported']) ? $stats['wheels']['total_imported'] : 0,
            //         'duration' => $duration
            //     )
            // ));
            
            if ($email_sent) {
                error_log('ASM Cron: SUCCESS - Email notification sent successfully to: ' . $admin_email);
            } else {
                // ASM_Logger::warning('Cron sync email notification failed to send', array(
                //     'admin_email' => $admin_email,
                //     'stats' => $stats
                // ));
                error_log('ASM Cron: WARNING - Email notification failed to send to: ' . $admin_email);
                error_log('ASM Cron: Please check SMTP configuration and WordPress admin email setting.');
            }
        } catch (Exception $e) {
            // ASM_Logger::error('Exception sending cron sync email', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'admin_email' => $admin_email
            // ));
            error_log('ASM Cron: Exception sending email to ' . $admin_email . ': ' . $e->getMessage());
        } catch (Error $e) {
            // ASM_Logger::error('Fatal error sending cron sync email', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'admin_email' => $admin_email
            // ));
            error_log('ASM Cron: Fatal error sending email to ' . $admin_email . ': ' . $e->getMessage());
        }

        // Send Slack notification
        try {
            $slack_sent = ASM_Slack_Notifier::send_sync_notification('all', $stats);
            
            if ($slack_sent) {
                error_log('ASM Cron: SUCCESS - Slack notification sent successfully');
            } else {
                error_log('ASM Cron: Slack notification not sent (webhook may not be configured)');
            }
        } catch (Exception $e) {
            // ASM_Logger::error('Exception sending cron sync Slack notification', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            error_log('ASM Cron: Exception sending Slack notification: ' . $e->getMessage());
        } catch (Error $e) {
            // ASM_Logger::error('Fatal error sending cron sync Slack notification', array(
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            error_log('ASM Cron: Fatal error sending Slack notification: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Sync products for a specific type (tire or wheel)
     *
     * @param string $product_type 'tire' or 'wheel'
     * @param ASM_API_Client $api_client API client instance
     * @return array Statistics
     */
    private static function sync_product_type($product_type, $api_client) {
        try {
            // ASM_Logger::info('Starting sync for product type', array('type' => $product_type));

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
                    'total_imported' => 0,
                    'total_updated' => 0,
                    'total_skipped' => 0,
                    'total_pages' => 0,
                    'brands' => array()
                );
            }

            // ASM_Logger::info('Found active brands', array(
            //     'type' => $product_type,
            //     'count' => count($active_brands)
            // ));

            $stats = array(
                'brands_processed' => 0,
                'total_imported' => 0,
                'total_updated' => 0,
                'total_skipped' => 0,
                'total_pages' => 0,
                'brands' => array() // Brand-wise stats
            );

            // Process each active brand sequentially
            foreach ($active_brands as $brand_index => $brand) {
                try {
                    $brand_start_time = microtime(true);
                    $brand_stats = self::sync_single_brand($product_type, $brand->brand_id, $api_client);
                    $brand_duration = round(microtime(true) - $brand_start_time, 2);
                    
                    $stats['brands_processed']++;
                    $stats['total_imported'] += $brand_stats['imported'];
                    $stats['total_updated'] += $brand_stats['updated'];
                    $stats['total_skipped'] += $brand_stats['skipped'];
                    $stats['total_pages'] += $brand_stats['pages'];

                    // Store brand-wise stats
                    $stats['brands'][] = array(
                        'brand_id' => $brand->brand_id,
                        'brand_name' => $brand->brand_name,
                        'imported' => $brand_stats['imported'],
                        'updated' => $brand_stats['updated'],
                        'skipped' => $brand_stats['skipped'],
                        'pages' => $brand_stats['pages'],
                        'time' => $brand_duration
                    );

                    // ASM_Logger::info('Brand sync completed', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand->brand_id,
                    //     'brand_name' => $brand->brand_name,
                    //     'brand_index' => $brand_index + 1,
                    //     'total_brands' => count($active_brands),
                    //     'stats' => $brand_stats,
                    //     'duration' => $brand_duration
                    // ));
                } catch (Exception $e) {
                    // ASM_Logger::error('Error syncing brand', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand->brand_id,
                    //     'brand_name' => isset($brand->brand_name) ? $brand->brand_name : 'Unknown',
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    // Continue with next brand even if this one fails
                    continue;
                } catch (Error $e) {
                    // ASM_Logger::error('Fatal error syncing brand', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand->brand_id,
                    //     'brand_name' => isset($brand->brand_name) ? $brand->brand_name : 'Unknown',
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    // Continue with next brand even if this one fails
                    continue;
                }

                // Small delay between brands to avoid overwhelming the API
                usleep(500000); // 0.5 seconds
                
                // Force garbage collection between brands to free memory
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            // Final cleanup
            unset($active_brands);
            unset($all_brands);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            return $stats;
        } catch (Exception $e) {
            // ASM_Logger::error('Error in sync_product_type', array(
            //     'type' => $product_type,
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            throw $e; // Re-throw to be caught by parent
        } catch (Error $e) {
            // ASM_Logger::error('Fatal error in sync_product_type', array(
            //     'type' => $product_type,
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ));
            throw $e; // Re-throw to be caught by parent
        }
    }

    /**
     * Sync products for a single brand
     * Optimized for 80,000+ products with external cron
     *
     * @param string $product_type 'tire' or 'wheel'
     * @param int $brand_id Brand ID
     * @param ASM_API_Client $api_client API client instance
     * @return array Statistics
     */
    private static function sync_single_brand($product_type, $brand_id, $api_client) {
        $stats = array(
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'pages' => 0
        );

        $page_number = 1;
        $page_size = 500; // Max API limit
        $image_urls = null;
        $has_more = true;
        
        // Safety limits for 80,000+ products
        $max_pages = 200; // 200 pages * 500 = 100,000 products max per brand
        $consecutive_empty_pages = 0;
        $max_consecutive_empty = 3; // Stop after 3 consecutive empty pages
        $start_time = microtime(true);
        $max_execution_time = 7200; // 2 hours max per brand (for external cron)
        $last_progress_time = $start_time;
        $stuck_threshold = 600; // 10 minutes without progress = stuck
        
        // Check memory before starting
        $memory_before = memory_get_usage(true);
        $memory_limit_bytes = self::convert_to_bytes(ini_get('memory_limit'));
        
        // ASM_Logger::info('Starting brand sync', array(
        //     'type' => $product_type,
        //     'brand_id' => $brand_id,
        //     'max_pages' => $max_pages,
        //     'max_execution_time' => $max_execution_time,
        //     'initial_memory' => size_format($memory_before)
        // ));

        while ($has_more && $page_number <= $max_pages) {
            // Check execution time - stop if taking too long
            $current_time = microtime(true);
            $elapsed_time = $current_time - $start_time;
            
            if ($elapsed_time > $max_execution_time) {
                // ASM_Logger::warning('Brand sync timeout - stopping to prevent infinite execution', array(
                //     'type' => $product_type,
                //     'brand_id' => $brand_id,
                //     'elapsed_time' => round($elapsed_time, 2),
                //     'max_execution_time' => $max_execution_time,
                //     'pages_processed' => $stats['pages']
                // ));
                break;
            }
            
            // Check if sync is stuck (no progress for too long)
            $time_since_progress = $current_time - $last_progress_time;
            if ($time_since_progress > $stuck_threshold) {
                // ASM_Logger::error('Brand sync appears stuck - no progress for ' . round($time_since_progress, 2) . ' seconds', array(
                //     'type' => $product_type,
                //     'brand_id' => $brand_id,
                //     'page' => $page_number,
                //     'time_since_progress' => round($time_since_progress, 2),
                //     'stuck_threshold' => $stuck_threshold
                // ));
                break;
            }
            
            // Check memory usage and reduce batch size if needed
            $current_memory = memory_get_usage(true);
            $memory_usage_percent = ($memory_limit_bytes > 0) ? ($current_memory / $memory_limit_bytes) * 100 : 0;
            
            // If memory usage is above 80%, reduce page size
            if ($memory_usage_percent > 80 && $page_size > 100) {
                $page_size = max(100, intval($page_size * 0.7)); // Reduce by 30%
                // ASM_Logger::warning('High memory usage detected - reducing batch size', array(
                //     'memory_usage_percent' => round($memory_usage_percent, 2),
                //     'new_page_size' => $page_size,
                //     'current_memory' => size_format($current_memory)
                // ));
            }
            
            // Fetch products from API - with error handling and retry
            $api_success = false;
            $retry_count = 0;
            $max_retries = 2;
            
            while (!$api_success && $retry_count <= $max_retries) {
                try {
                    if ($product_type === 'tire') {
                        $result = $api_client->get_tires($page_number, $page_size, $brand_id);
                    } else {
                        $result = $api_client->get_wheels($page_number, $page_size, $brand_id);
                    }
                    $api_success = true;
                } catch (Exception $e) {
                    $retry_count++;
                    if ($retry_count > $max_retries) {
                        // ASM_Logger::error('API call failed after retries', array(
                        //     'type' => $product_type,
                        //     'brand_id' => $brand_id,
                        //     'page' => $page_number,
                        //     'retry_count' => $retry_count,
                        //     'error' => $e->getMessage(),
                        //     'trace' => $e->getTraceAsString()
                        // ));
                        $has_more = false;
                        break 2; // Break out of both loops
                    } else {
                        // ASM_Logger::warning('API call failed, retrying', array(
                        //     'type' => $product_type,
                        //     'brand_id' => $brand_id,
                        //     'page' => $page_number,
                        //     'retry_count' => $retry_count,
                        //     'error' => $e->getMessage()
                        // ));
                        sleep(3); // Wait 3 seconds before retry
                    }
                } catch (Error $e) {
                    // ASM_Logger::error('Fatal error in API call', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand_id,
                    //     'page' => $page_number,
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    $has_more = false;
                    break 2; // Break out of both loops
                }
            }
            
            if (!$api_success) {
                break; // Exit if API call failed after retries
            }

            // Check for empty result or no products
            if (!$result || empty($result['products'])) {
                $consecutive_empty_pages++;
                
                // ASM_Logger::warning('Empty page received from API', array(
                //     'type' => $product_type,
                //     'brand_id' => $brand_id,
                //     'page' => $page_number,
                //     'consecutive_empty' => $consecutive_empty_pages,
                //     'has_more_flag' => isset($result['has_more']) ? $result['has_more'] : 'not set'
                // ));
                
                // If we get multiple consecutive empty pages, stop syncing this brand
                if ($consecutive_empty_pages >= $max_consecutive_empty) {
                    // ASM_Logger::warning('Stopping sync - too many consecutive empty pages', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand_id,
                    //     'page' => $page_number,
                    //     'consecutive_empty' => $consecutive_empty_pages
                    // ));
                    $has_more = false;
                    break;
                }
                
                // If has_more is false, stop; otherwise continue to next page
                if (!isset($result['has_more']) || $result['has_more'] !== true) {
                    $has_more = false;
                    break;
                }
                
                // Continue to next page if has_more is true
                $page_number++;
                continue;
            }
            
            // Reset consecutive empty pages counter when we get products
            $consecutive_empty_pages = 0;

            // Store image URLs from first page
            if ($image_urls === null && isset($result['image_urls'])) {
                $image_urls = $result['image_urls'];
            }

            // Convert products to array format
            $products_data = array();
            foreach ($result['products'] as $product_data) {
                $product_array = json_decode(json_encode($product_data), true);
                $sku = ($product_type === 'tire') 
                    ? ($product_data->PartNumber ?? '') 
                    : ($product_data->Pn ?? '');
                
                // Skip products without SKU
                if (empty($sku)) {
                    continue;
                }
                
                $products_data[] = array(
                    'data' => $product_array,
                    'sku' => $sku,
                );
            }
            
            // Clear the result array from memory immediately after processing
            unset($result['products']);

            // Batch insert products with error handling
            if (!empty($products_data)) {
                try {
                    $batch_result = ASM_SQL_Batch_Inserter::batch_insert_products(
                        $products_data,
                        $image_urls,
                        $product_type
                    );

                    $stats['imported'] += isset($batch_result['imported']) ? (int)$batch_result['imported'] : 0;
                    $stats['updated'] += isset($batch_result['updated']) ? (int)$batch_result['updated'] : 0;
                    $stats['skipped'] += isset($batch_result['skipped']) ? (int)$batch_result['skipped'] : 0;
                    $stats['pages']++;
                    
                    // Update last progress time
                    $last_progress_time = microtime(true);

                    // Log progress every 10 pages for large syncs
                    if ($stats['pages'] % 10 == 0) {
                        // ASM_Logger::info('Brand sync progress', array(
                        //     'type' => $product_type,
                        //     'brand_id' => $brand_id,
                        //     'current_page' => $page_number,
                        //     'pages_processed' => $stats['pages'],
                        //     'total_imported' => $stats['imported'],
                        //     'total_updated' => $stats['updated'],
                        //     'total_skipped' => $stats['skipped'],
                        //     'elapsed_time' => round($current_time - $start_time, 2),
                        //     'memory_usage' => size_format(memory_get_usage(true)),
                        //     'memory_peak' => size_format(memory_get_peak_usage(true))
                        // ));
                    } else {
                        // Regular batch log
                        // ASM_Logger::info('Batch processed', array(
                        //     'type' => $product_type,
                        //     'brand_id' => $brand_id,
                        //     'page' => $page_number,
                        //     'products_fetched' => count($products_data),
                        //     'imported' => $batch_result['imported'] ?? 0,
                        //     'updated' => $batch_result['updated'] ?? 0,
                        //     'skipped' => $batch_result['skipped'] ?? 0
                        // ));
                    }
                } catch (Exception $e) {
                    // ASM_Logger::error('Batch insert exception', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand_id,
                    //     'page' => $page_number,
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    // Continue to next page even if this one failed
                } catch (Error $e) {
                    // ASM_Logger::error('Fatal error in batch insert', array(
                    //     'type' => $product_type,
                    //     'brand_id' => $brand_id,
                    //     'page' => $page_number,
                    //     'error' => $e->getMessage(),
                    //     'trace' => $e->getTraceAsString()
                    // ));
                    // Fatal errors - stop immediately
                    break;
                }
                
                // Clear products_data from memory after processing
                unset($products_data);
                if (isset($batch_result)) {
                    unset($batch_result);
                }
                
                // Force garbage collection every 5 pages to free memory
                if ($stats['pages'] % 5 == 0 && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            // Check if there are more pages BEFORE unsetting
            $has_more = isset($result['has_more']) && $result['has_more'] === true;
            
            // Clear result completely
            unset($result);
            
            $page_number++;

            // Small delay between pages
            usleep(100000); // 0.1 seconds
        }
        
        // Check if we hit the maximum page limit
        if ($page_number > $max_pages && $has_more) {
            // ASM_Logger::warning('Brand sync stopped - reached maximum page limit', array(
            //     'type' => $product_type,
            //     'brand_id' => $brand_id,
            //     'max_pages' => $max_pages,
            //     'pages_processed' => $stats['pages'],
            //     'total_imported' => $stats['imported'],
            //     'message' => 'Sync stopped at page limit. Consider increasing max_pages if this brand has more products.'
            // ));
        }
        
        // Calculate final elapsed time
        $final_time = microtime(true);
        $elapsed_time = $final_time - $start_time;
        
        // Determine stop reason
        $stopped_reason = 'normal';
        if ($page_number > $max_pages) {
            $stopped_reason = 'page_limit';
        } elseif ($consecutive_empty_pages >= $max_consecutive_empty) {
            $stopped_reason = 'empty_pages';
        } elseif ($elapsed_time > $max_execution_time) {
            $stopped_reason = 'timeout';
        }
        
        // Log completion
        // ASM_Logger::info('Brand sync completed', array(
        //     'type' => $product_type,
        //     'brand_id' => $brand_id,
        //     'total_pages' => $stats['pages'],
        //     'total_imported' => $stats['imported'],
        //     'total_updated' => $stats['updated'],
        //     'total_skipped' => $stats['skipped'],
        //     'stopped_reason' => $stopped_reason,
        //     'elapsed_time' => round($elapsed_time, 2),
        //     'memory_peak' => size_format(memory_get_peak_usage(true))
        // ));

        return $stats;
    }

    /**
     * Convert memory limit string to bytes
     * 
     * @param string $memory_limit Memory limit string (e.g., "512M", "1G")
     * @return int Memory limit in bytes
     */
    private static function convert_to_bytes($memory_limit) {
        if ($memory_limit == -1) {
            return PHP_INT_MAX; // Unlimited
        }
        
        $memory_limit = trim($memory_limit);
        $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $value = intval($memory_limit);
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // Fall through
            case 'm':
                $value *= 1024;
                // Fall through
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}


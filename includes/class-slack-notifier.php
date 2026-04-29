<?php
/**
 * Slack Notifier Class
 *
 * Sends Slack notifications after product sync operations
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ASM_Slack_Notifier {

    /**
     * Send Slack notification after sync completion
     *
     * @param string $sync_type Type of sync: 'single', 'multiple', 'all'
     * @param array $stats Sync statistics
     * @return bool Success status
     */
    public static function send_sync_notification($sync_type, $stats = array()) {
        // Check if Slack notifications are enabled in settings
        $enabled = get_option('asm_slack_enabled', '1');
        if (empty($enabled) || $enabled === '0' || $enabled === 0) {
            // ASM_Logger::info('Slack notifications disabled in settings - skipping Slack notification', array(
            //     'sync_type' => $sync_type
            // ));
            return false;
        }

        $webhook_url = get_option('asm_slack_webhook_url', '');
        
        if (empty($webhook_url)) {
            // ASM_Logger::info('Slack webhook URL not configured - skipping Slack notification', array(
            //     'sync_type' => $sync_type
            // ));
            return false;
        }

        // Validate webhook URL format
        if (!filter_var($webhook_url, FILTER_VALIDATE_URL) || strpos($webhook_url, 'hooks.slack.com') === false) {
            // ASM_Logger::warning('Invalid Slack webhook URL format', array(
            //     'sync_type' => $sync_type,
            //     'webhook_url' => $webhook_url
            // ));
            return false;
        }

        // Build Slack message payload
        $payload = self::build_slack_payload($sync_type, $stats);
        
        // Send to Slack
        $response = self::send_to_slack($webhook_url, $payload);
        
        if ($response['success']) {
            // ASM_Logger::info('Slack notification sent successfully', array(
            //     'sync_type' => $sync_type,
            //     'response_code' => $response['code']
            // ));
        } else {
            // ASM_Logger::error('Failed to send Slack notification', array(
            //     'sync_type' => $sync_type,
            //     'error' => $response['error'],
            //     'response_code' => $response['code']
            // ));
        }
        
        return $response['success'];
    }

    /**
     * Build Slack message payload
     *
     * @param string $sync_type Type of sync
     * @param array $stats Sync statistics
     * @return array Slack payload
     */
    private static function build_slack_payload($sync_type, $stats) {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        // Get current time in site's timezone
        $current_time = current_time('timestamp');
        
        // Format date as MM-DD-YYYY using site timezone
        $date = date('m-d-Y', $current_time);
        
        // Format time as 12-hour format with AM/PM using site timezone
        $time = date('h:i A', $current_time);
        
        $payload = array(
            'text' => self::build_title($sync_type, $site_name),
            'blocks' => array()
        );

        // Header block
        $payload['blocks'][] = array(
            'type' => 'header',
            'text' => array(
                'type' => 'plain_text',
                'text' => self::build_title($sync_type, $site_name),
                'emoji' => true
            )
        );

        // Divider
        $payload['blocks'][] = array('type' => 'divider');
        
        // Site information section (at the top, before Summary)
        // Use plain_text to avoid markdown formatting issues
        $payload['blocks'][] = array(
            'type' => 'section',
            'fields' => array(
                array(
                    'type' => 'plain_text',
                    'text' => 'Site Name: ' . html_entity_decode($site_name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                ),
                array(
                    'type' => 'plain_text',
                    'text' => 'Site URL: ' . html_entity_decode($site_url, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                ),
                array(
                    'type' => 'plain_text',
                    'text' => 'Date: ' . $date
                ),
                array(
                    'type' => 'plain_text',
                    'text' => 'Time: ' . $time
                )
            )
        );
        
        // Divider before summary
        $payload['blocks'][] = array('type' => 'divider');

        // Content based on sync type
        switch ($sync_type) {
            case 'single':
                $payload['blocks'] = array_merge($payload['blocks'], self::build_single_product_blocks($stats));
                break;
            
            case 'multiple':
                $payload['blocks'] = array_merge($payload['blocks'], self::build_multiple_products_blocks($stats));
                break;
            
            case 'all':
            default:
                $payload['blocks'] = array_merge($payload['blocks'], self::build_all_products_blocks($stats));
                break;
        }

        // Footer
        $payload['blocks'][] = array('type' => 'divider');
        $payload['blocks'][] = array(
            'type' => 'context',
            'elements' => array(
                array(
                    'type' => 'plain_text',
                    'text' => sprintf('Sync completed on %s at %s', $date, $time)
                )
            )
        );

        return $payload;
    }

    /**
     * Build title for Slack message
     *
     * @param string $sync_type Type of sync
     * @param string $site_name Site name
     * @return string Title
     */
    private static function build_title($sync_type, $site_name) {
        switch ($sync_type) {
            case 'single':
                return '✅ Single Product Sync Completed';
            
            case 'multiple':
                return '✅ Batch Product Sync Completed';
            
            case 'all':
            default:
                return '✅ Full Product Sync Completed';
        }
    }

    /**
     * Build blocks for single product sync
     *
     * @param array $stats Sync statistics
     * @return array Slack blocks
     */
    private static function build_single_product_blocks($stats) {
        $blocks = array();
        
        $fields = array();
        
        if (isset($stats['product_name'])) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Product Name:*\n%s', html_entity_decode($stats['product_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            );
        }
        
        if (isset($stats['sku'])) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*SKU:*\n%s', html_entity_decode($stats['sku'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            );
        }
        
        if (isset($stats['product_id'])) {
            $edit_link = admin_url('post.php?post=' . intval($stats['product_id']) . '&action=edit');
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Product ID:*\n<%s|%d>', esc_url($edit_link), intval($stats['product_id']))
            );
        }
        
        if (isset($stats['price'])) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Price:*\n$%s', number_format(floatval($stats['price']), 2))
            );
        }
        
        if (isset($stats['stock'])) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Stock:*\n%d', intval($stats['stock']))
            );
        }
        
        if (!empty($fields)) {
            $blocks[] = array(
                'type' => 'section',
                'fields' => $fields
            );
        }
        
        return $blocks;
    }

    /**
     * Build blocks for multiple products sync
     *
     * @param array $stats Sync statistics
     * @return array Slack blocks
     */
    private static function build_multiple_products_blocks($stats) {
        $blocks = array();
        
        $total_processed = isset($stats['total_processed']) ? intval($stats['total_processed']) : 0;
        $imported = isset($stats['imported']) ? intval($stats['imported']) : 0;
        $updated = isset($stats['updated']) ? intval($stats['updated']) : 0;
        $skipped = isset($stats['skipped']) ? intval($stats['skipped']) : 0;
        $duration = isset($stats['duration_seconds']) ? floatval($stats['duration_seconds']) : 0;
        
        $fields = array(
            array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Total Processed:*\n%d', $total_processed)
            ),
            array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Imported:*\n:white_check_mark: %d', $imported)
            ),
            array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Updated:*\n:arrows_counterclockwise: %d', $updated)
            )
        );
        
        if ($skipped > 0) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Skipped:*\n:warning: %d', $skipped)
            );
        }
        
        if ($duration > 0) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Duration:*\n%s', self::format_duration($duration))
            );
        }
        
        if (isset($stats['products_per_second']) && $stats['products_per_second'] > 0) {
            $fields[] = array(
                'type' => 'mrkdwn',
                'text' => sprintf('*Processing Rate:*\n%.2f products/sec', $stats['products_per_second'])
            );
        }
        
        $blocks[] = array(
            'type' => 'section',
            'fields' => $fields
        );
        
        return $blocks;
    }

    /**
     * Build blocks for all products sync
     *
     * @param array $stats Sync statistics
     * @return array Slack blocks
     */
    private static function build_all_products_blocks($stats) {
        $blocks = array();
        
        // Calculate totals
        $total_imported = (isset($stats['tires']['total_imported']) ? intval($stats['tires']['total_imported']) : 0) + 
                         (isset($stats['wheels']['total_imported']) ? intval($stats['wheels']['total_imported']) : 0);
        $total_updated = (isset($stats['tires']['total_updated']) ? intval($stats['tires']['total_updated']) : 0) + 
                        (isset($stats['wheels']['total_updated']) ? intval($stats['wheels']['total_updated']) : 0);
        $total_skipped = (isset($stats['tires']['total_skipped']) ? intval($stats['tires']['total_skipped']) : 0) + 
                        (isset($stats['wheels']['total_skipped']) ? intval($stats['wheels']['total_skipped']) : 0);
        $duration = isset($stats['duration']) ? self::format_duration($stats['duration']) : 'N/A';
        
        // Simple summary section with only the 4 required fields
        // Use plain text format for better readability
        $summary_text = "Total Imported: " . $total_imported . "\n" .
                        "Total Updated: " . $total_updated . "\n" .
                        "Total Skipped: " . $total_skipped . "\n" .
                        "Total Duration: " . $duration;
        
        $blocks[] = array(
            'type' => 'section',
            'text' => array(
                'type' => 'plain_text',
                'text' => $summary_text
            )
        );
        
        return $blocks;
    }

    /**
     * Send payload to Slack webhook
     *
     * @param string $webhook_url Slack webhook URL
     * @param array $payload Message payload
     * @return array Response with success status, code, and error message
     */
    private static function send_to_slack($webhook_url, $payload) {
        $response = array(
            'success' => false,
            'code' => 0,
            'error' => ''
        );
        
        $body = json_encode($payload);
        
        $args = array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 10,
            'method' => 'POST'
        );
        
        $http_response = wp_remote_post($webhook_url, $args);
        
        if (is_wp_error($http_response)) {
            $response['error'] = $http_response->get_error_message();
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($http_response);
        $response_body = wp_remote_retrieve_body($http_response);
        
        $response['code'] = $response_code;
        
        // Slack returns 200 on success
        if ($response_code === 200) {
            $response['success'] = true;
        } else {
            $response['error'] = $response_body ? $response_body : 'Unknown error';
        }
        
        return $response;
    }

    /**
     * Format duration in seconds to human-readable format
     *
     * @param float $seconds Duration in seconds
     * @return string Formatted duration
     */
    public static function format_duration($seconds) {
        if ($seconds < 60) {
            return number_format($seconds, 2) . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ' . number_format($secs, 0) . ' second' . ($secs != 1 ? 's' : '');
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $secs = $seconds % 60;
            return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ' . $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ' . number_format($secs, 0) . ' second' . ($secs != 1 ? 's' : '');
        }
    }

    /**
     * Send a test notification to Slack
     *
     * @return array Test results
     */
    public static function send_test_notification() {
        $webhook_url = get_option('asm_slack_webhook_url', '');
        
        $results = array(
            'success' => false,
            'message' => '',
            'details' => array()
        );
        
        if (empty($webhook_url)) {
            $results['message'] = 'Slack webhook URL not configured';
            return $results;
        }
        
        if (!filter_var($webhook_url, FILTER_VALIDATE_URL) || strpos($webhook_url, 'hooks.slack.com') === false) {
            $results['message'] = 'Invalid Slack webhook URL format';
            $results['details']['webhook_url'] = $webhook_url;
            return $results;
        }
        
        $test_payload = array(
            'text' => 'Test notification from AutoSync Product Manager',
            'blocks' => array(
                array(
                    'type' => 'header',
                    'text' => array(
                        'type' => 'plain_text',
                        'text' => '🧪 Test Notification',
                        'emoji' => true
                    )
                ),
                array(
                    'type' => 'section',
                    'text' => array(
                        'type' => 'mrkdwn',
                        'text' => 'This is a test notification from the *AutoSync Product Manager* plugin. If you received this message, your Slack integration is working correctly!'
                    )
                ),
                array(
                    'type' => 'context',
                    'elements' => array(
                        array(
                            'type' => 'mrkdwn',
                            'text' => sprintf('*Site:* %s | *Time:* %s', get_bloginfo('name'), date('Y-m-d H:i:s'))
                        )
                    )
                )
            )
        );
        
        $response = self::send_to_slack($webhook_url, $test_payload);
        
        $results['details'] = array(
            'webhook_url' => $webhook_url,
            'response_code' => $response['code'],
            'error' => $response['error']
        );
        
        if ($response['success']) {
            $results['success'] = true;
            $results['message'] = 'Test notification sent successfully';
        } else {
            $results['message'] = 'Failed to send test notification: ' . ($response['error'] ? $response['error'] : 'Unknown error');
        }
        
        return $results;
    }
}


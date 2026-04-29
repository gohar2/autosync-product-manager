<?php
/**
 * Email Notifier Class
 *
 * Sends email notifications after product sync operations
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ASM_Email_Notifier {

    /**
     * Send email notification after sync completion
     *
     * @param string $sync_type Type of sync: 'single', 'multiple', 'all'
     * @param array $stats Sync statistics
     * @return bool Success status
     */
    public static function send_sync_email($sync_type, $stats = array()) {
        // Try to use WooCommerce email class if available
        // Check WooCommerce is active first
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            // ASM_Logger::info('WooCommerce not available, using fallback email method', array(
            //     'sync_type' => $sync_type
            // ));
        } else {
            $wc = WC();
            if (!$wc || !method_exists($wc, 'mailer')) {
                // ASM_Logger::info('WooCommerce mailer not available, using fallback email method', array(
                //     'sync_type' => $sync_type
                // ));
            } else {
                $wc_emails = $wc->mailer();
                if (!$wc_emails || !method_exists($wc_emails, 'get_emails')) {
                    // ASM_Logger::info('WooCommerce emails not available, using fallback email method', array(
                    //     'sync_type' => $sync_type
                    // ));
                } else {
                    $emails = $wc_emails->get_emails();
                    
                    // Log available email classes for debugging
                    // ASM_Logger::info('Checking for WooCommerce email class', array(
                    //     'available_emails' => array_keys($emails),
                    //     'looking_for' => 'WC_Email_Product_Sync'
                    // ));
                    
                    if (isset($emails['WC_Email_Product_Sync'])) {
                        $email = $emails['WC_Email_Product_Sync'];
                        
                        // Check if email is enabled
                        if ($email->is_enabled()) {
                            // ASM_Logger::info('Sending sync notification email via WooCommerce', array(
                            //     'sync_type' => $sync_type,
                            //     'recipient' => $email->get_recipient(),
                            //     'email_id' => $email->id,
                            //     'email_enabled' => $email->is_enabled(),
                            //     'template_base' => $email->template_base,
                            //     'template_html' => $email->template_html
                            // ));
                            
                            // Add a marker to identify this is a WooCommerce email
                            error_log('ASM EMAIL: Using WooCommerce email system - Email ID: ' . $email->id);
                            
                            // Trigger the WooCommerce email
                            $email->trigger($sync_type, $stats);
                            
                            return true;
                        } else {
                            // ASM_Logger::warning('WooCommerce email is disabled - sync notification not sent', array(
                            //     'sync_type' => $sync_type,
                            //     'email_id' => $email->id
                            // ));
                            return false;
                        }
                    } else {
                        // ASM_Logger::warning('WC_Email_Product_Sync not found in WooCommerce emails, using fallback', array(
                        //     'sync_type' => $sync_type,
                        //     'available_emails' => array_keys($emails)
                        // ));
                    }
                }
            }
        }
        
        // Fallback to original method if WooCommerce email is not available
        error_log('ASM EMAIL: Using FALLBACK email method (NOT WooCommerce)');
        
        // Hardcoded email address for sync notifications
        $to = 'sdtireandwheel@yopmail.com';
        
        if (empty($to)) {
            // ASM_Logger::error('Email address not configured - cannot send sync notification');
            return false;
        }

        // Use a different "From" address to avoid sender/receiver being the same
        // Try to get a noreply email or use a different format
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        // Extract domain from site URL for noreply address
        $domain = parse_url($site_url, PHP_URL_HOST);
        if (empty($domain)) {
            $domain = 'noreply';
        }
        
        // Create a noreply sender address
        $from_email = 'noreply@' . $domain;
        
        // If domain extraction failed, use admin email but with different format
        if ($from_email === 'noreply@' || strpos($from_email, '@') === false) {
            // Fallback: use admin email but prefix with noreply
            $admin_email_parts = explode('@', $to);
            if (count($admin_email_parts) === 2) {
                $from_email = 'noreply@' . $admin_email_parts[1];
            } else {
                $from_email = $to; // Last resort
            }
        }

        // Build subject based on sync type
        $subject = self::build_subject($sync_type, $stats);
        
        // Build email body
        $message = self::build_message($sync_type, $stats);
        
        // Build headers string for PHP mail() function
        // Note: Headers must be separated by \r\n (not \n) for proper email formatting
        $headers_string = "MIME-Version: 1.0\r\n";
        $headers_string .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers_string .= "From: " . $site_name . " <" . $from_email . ">\r\n";
        $headers_string .= "Reply-To: " . $to . "\r\n";
        $headers_string .= "X-Mailer: PHP/" . phpversion();
        
        // Log email attempt with full details
        // ASM_Logger::info('Attempting to send sync notification email (fallback method)', array(
        //     'sync_type' => $sync_type,
        //     'recipient' => $to,
        //     'from_email' => $from_email,
        //     'subject' => $subject,
        //     'method' => 'PHP mail()',
        //     'message_length' => strlen($message),
        //     'headers_length' => strlen($headers_string)
        // ));
        
        // Use wp_mail() first (recommended) - it works with SMTP plugins like WP Mail SMTP
        // This is more reliable than PHP mail() on most hosting providers
        $headers_array = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $from_email . '>',
            'Reply-To: ' . $to
        );
        
        $sent = wp_mail($to, $subject, $message, $headers_array);
        $error_message = '';
        
        // Check for wp_mail errors
        global $phpmailer;
        if (isset($phpmailer) && is_object($phpmailer)) {
            if (!empty($phpmailer->ErrorInfo)) {
                $error_message = $phpmailer->ErrorInfo;
            }
        }
        
        // Fallback to PHP mail() only if wp_mail() fails and function exists
        if (!$sent && function_exists('mail')) {
            // ASM_Logger::warning('wp_mail() failed, trying PHP mail() as fallback', array(
            //     'error' => $error_message
            // ));
            
            // Use PHP mail() function as fallback
            $old_error_reporting = error_reporting(E_ALL & ~E_WARNING);
            $sent = @mail($to, $subject, $message, $headers_string);
            error_reporting($old_error_reporting);
            
            // Get last error if mail() failed
            $last_error = error_get_last();
            if (!$sent && $last_error) {
                $error_message = $last_error['message'];
            }
        }
        
        if ($sent) {
            // ASM_Logger::info('Sync notification email sent successfully (fallback method)', array(
            //     'sync_type' => $sync_type,
            //     'recipient' => $to,
            //     'from_email' => $from_email,
            //     'method' => $sent ? 'PHP mail()' : 'wp_mail()'
            // ));
        } else {
            // ASM_Logger::error('Failed to send sync notification email', array(
            //     'sync_type' => $sync_type,
            //     'recipient' => $to,
            //     'from_email' => $from_email,
            //     'error' => $error_message,
            //     'php_mail_return' => $sent
            // ));
        }
        
        return $sent;
    }

    /**
     * Build email subject
     *
     * @param string $sync_type Type of sync
     * @param array $stats Sync statistics
     * @return string Email subject
     */
    private static function build_subject($sync_type, $stats) {
        $site_name = get_bloginfo('name');
        $timestamp = date('Y-m-d H:i:s');
        
        switch ($sync_type) {
            case 'single':
                $product_name = isset($stats['product_name']) ? $stats['product_name'] : 'Product';
                return sprintf('[%s] Product Sync: %s - %s', $site_name, $product_name, $timestamp);
            
            case 'multiple':
                $count = isset($stats['total_processed']) ? $stats['total_processed'] : 0;
                return sprintf('[%s] Batch Product Sync: %d Products - %s', $site_name, $count, $timestamp);
            
            case 'all':
            default:
                // Check if this is a partial sync (tires only or wheels only)
                $has_tires = isset($stats['tires']) && (isset($stats['tires']['total_imported']) || isset($stats['tires']['total_updated']) || isset($stats['tires']['brands_processed']));
                $has_wheels = isset($stats['wheels']) && (isset($stats['wheels']['total_imported']) || isset($stats['wheels']['total_updated']) || isset($stats['wheels']['brands_processed']));
                
                if ($has_tires && !$has_wheels) {
                    return sprintf('[%s] Tire Sync Completed - %s', $site_name, $timestamp);
                } elseif ($has_wheels && !$has_tires) {
                    return sprintf('[%s] Wheel Sync Completed - %s', $site_name, $timestamp);
                } else {
                return sprintf('[%s] Full Product Sync Completed - %s', $site_name, $timestamp);
                }
        }
    }

    /**
     * Build email message body
     *
     * @param string $sync_type Type of sync
     * @param array $stats Sync statistics
     * @return string Email message
     */
    private static function build_message($sync_type, $stats) {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        $timestamp = date('Y-m-d H:i:s');
        
        $message = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">';
        $message .= '<div style="max-width: 600px; margin: 0 auto; padding: 20px;">';
        $message .= '<h2 style="color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;">Product Sync Notification</h2>';
        
        switch ($sync_type) {
            case 'single':
                $message .= self::build_single_product_message($stats);
                break;
            
            case 'multiple':
                $message .= self::build_multiple_products_message($stats);
                break;
            
            case 'all':
            default:
                $message .= self::build_all_products_message($stats);
                break;
        }
        
        $message .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">';
        $message .= '<p style="color: #666; font-size: 12px;">';
        $message .= 'This is an automated notification from <strong>' . esc_html($site_name) . '</strong>.<br>';
        $message .= 'Sync completed at: ' . esc_html($timestamp) . '<br>';
        $message .= 'Site URL: <a href="' . esc_url($site_url) . '">' . esc_html($site_url) . '</a>';
        $message .= '</p>';
        $message .= '</div>';
        $message .= '</body></html>';
        
        return $message;
    }

    /**
     * Build message for single product sync
     *
     * @param array $stats Sync statistics
     * @return string Message HTML
     */
    private static function build_single_product_message($stats) {
        $message = '<h3 style="color: #27ae60;">Single Product Sync Completed</h3>';
        $message .= '<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">';
        
        if (isset($stats['product_name'])) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Product Name:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($stats['product_name']) . '</td></tr>';
        }
        
        if (isset($stats['sku'])) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">SKU:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($stats['sku']) . '</td></tr>';
        }
        
        if (isset($stats['product_id'])) {
            $edit_link = admin_url('post.php?post=' . intval($stats['product_id']) . '&action=edit');
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Product ID:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">';
            $message .= '<a href="' . esc_url($edit_link) . '">' . intval($stats['product_id']) . '</a>';
            $message .= '</td></tr>';
        }
        
        if (isset($stats['price'])) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Price:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">$' . number_format(floatval($stats['price']), 2) . '</td></tr>';
        }
        
        if (isset($stats['stock'])) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Stock:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . intval($stats['stock']) . '</td></tr>';
        }
        
        $message .= '</table>';
        
        return $message;
    }

    /**
     * Build message for multiple products sync
     *
     * @param array $stats Sync statistics
     * @return string Message HTML
     */
    private static function build_multiple_products_message($stats) {
        $message = '<h3 style="color: #27ae60;">Batch Product Sync Completed</h3>';
        $message .= '<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">';
        
        $total_processed = isset($stats['total_processed']) ? intval($stats['total_processed']) : 0;
        $imported = isset($stats['imported']) ? intval($stats['imported']) : 0;
        $updated = isset($stats['updated']) ? intval($stats['updated']) : 0;
        $skipped = isset($stats['skipped']) ? intval($stats['skipped']) : 0;
        $duration = isset($stats['duration_seconds']) ? floatval($stats['duration_seconds']) : 0;
        
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Processed:</td>';
        $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . $total_processed . '</td></tr>';
        
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Imported:</td>';
        $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #27ae60;">' . $imported . '</td></tr>';
        
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Updated:</td>';
        $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #3498db;">' . $updated . '</td></tr>';
        
        if ($skipped > 0) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Skipped:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c;">' . $skipped . '</td></tr>';
        }
        
        if ($duration > 0) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Duration:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . self::format_duration($duration) . '</td></tr>';
        }
        
        if (isset($stats['products_per_second']) && $stats['products_per_second'] > 0) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Processing Rate:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . number_format($stats['products_per_second'], 2) . ' products/second</td></tr>';
        }
        
        $message .= '</table>';
        
        return $message;
    }

    /**
     * Build message for all products sync
     *
     * @param array $stats Sync statistics
     * @return string Message HTML
     */
    private static function build_all_products_message($stats) {
        // Determine if this is a partial sync
        $has_tires = isset($stats['tires']) && (isset($stats['tires']['total_imported']) || isset($stats['tires']['total_updated']) || isset($stats['tires']['brands_processed']));
        $has_wheels = isset($stats['wheels']) && (isset($stats['wheels']['total_imported']) || isset($stats['wheels']['total_updated']) || isset($stats['wheels']['brands_processed']));
        
        if ($has_tires && !$has_wheels) {
            $message = '<h3 style="color: #27ae60;">Tire Sync Completed</h3>';
        } elseif ($has_wheels && !$has_tires) {
            $message = '<h3 style="color: #27ae60;">Wheel Sync Completed</h3>';
        } else {
        $message = '<h3 style="color: #27ae60;">Full Product Sync Completed</h3>';
        }
        
        // Tires section
        if (isset($stats['tires'])) {
            $message .= '<h4 style="color: #2c3e50; margin-top: 20px;">Tires</h4>';
            $message .= '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
            
            $tire_stats = $stats['tires'];
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Brands Processed:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . (isset($tire_stats['brands_processed']) ? intval($tire_stats['brands_processed']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Imported:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #27ae60;">' . (isset($tire_stats['total_imported']) ? intval($tire_stats['total_imported']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Updated:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #3498db;">' . (isset($tire_stats['total_updated']) ? intval($tire_stats['total_updated']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Skipped:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c;">' . (isset($tire_stats['total_skipped']) ? intval($tire_stats['total_skipped']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Pages:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . (isset($tire_stats['total_pages']) ? intval($tire_stats['total_pages']) : 0) . '</td></tr>';
            
            $message .= '</table>';
        }
        
        // Wheels section
        if (isset($stats['wheels'])) {
            $message .= '<h4 style="color: #2c3e50; margin-top: 20px;">Wheels</h4>';
            $message .= '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
            
            $wheel_stats = $stats['wheels'];
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Brands Processed:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . (isset($wheel_stats['brands_processed']) ? intval($wheel_stats['brands_processed']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Imported:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #27ae60;">' . (isset($wheel_stats['total_imported']) ? intval($wheel_stats['total_imported']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Updated:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #3498db;">' . (isset($wheel_stats['total_updated']) ? intval($wheel_stats['total_updated']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Skipped:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c;">' . (isset($wheel_stats['total_skipped']) ? intval($wheel_stats['total_skipped']) : 0) . '</td></tr>';
            
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Pages:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . (isset($wheel_stats['total_pages']) ? intval($wheel_stats['total_pages']) : 0) . '</td></tr>';
            
            $message .= '</table>';
        }
        
        // Summary - only show if both tires and wheels were synced, or show appropriate summary for partial syncs
        if ($has_tires && $has_wheels) {
            // Full sync - show combined summary
        $message .= '<h4 style="color: #2c3e50; margin-top: 20px;">Summary</h4>';
        $message .= '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
        
        $total_imported = (isset($stats['tires']['total_imported']) ? intval($stats['tires']['total_imported']) : 0) + 
                         (isset($stats['wheels']['total_imported']) ? intval($stats['wheels']['total_imported']) : 0);
        $total_updated = (isset($stats['tires']['total_updated']) ? intval($stats['tires']['total_updated']) : 0) + 
                        (isset($stats['wheels']['total_updated']) ? intval($stats['wheels']['total_updated']) : 0);
        $total_skipped = (isset($stats['tires']['total_skipped']) ? intval($stats['tires']['total_skipped']) : 0) + 
                        (isset($stats['wheels']['total_skipped']) ? intval($stats['wheels']['total_skipped']) : 0);
        
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Imported:</td>';
        $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #27ae60; font-size: 16px;"><strong>' . $total_imported . '</strong></td></tr>';
        
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Updated:</td>';
        $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #3498db; font-size: 16px;"><strong>' . $total_updated . '</strong></td></tr>';
        
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Skipped:</td>';
        $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c;">' . $total_skipped . '</td></tr>';
        
        if (isset($stats['duration'])) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Duration:</td>';
            $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 16px;"><strong>' . self::format_duration($stats['duration']) . '</strong></td></tr>';
        }
        
        $message .= '</table>';
        } else {
            // Partial sync - show duration if available
            if (isset($stats['duration'])) {
                $message .= '<h4 style="color: #2c3e50; margin-top: 20px;">Sync Duration</h4>';
                $message .= '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
                $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;">Total Duration:</td>';
                $message .= '<td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 16px;"><strong>' . self::format_duration($stats['duration']) . '</strong></td></tr>';
                $message .= '</table>';
            }
        }
        
        return $message;
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
     * Send a simple test email to verify SMTP configuration
     * Call this function directly to test if emails are working
     *
     * @return bool Success status
     */
    public static function send_test_email() {
        // Hardcoded email address for test emails
        $to = 'sdtireandwheel@yopmail.com';
        
        if (empty($to)) {
            error_log('ASM Email Test: Email address not configured');
            return false;
        }
        
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        if (empty($domain)) {
            $domain = 'noreply';
        }
        
        $from_email = 'noreply@' . $domain;
        
        if ($from_email === 'noreply@' || strpos($from_email, '@') === false) {
            $admin_email_parts = explode('@', $to);
            if (count($admin_email_parts) === 2) {
                $from_email = 'noreply@' . $admin_email_parts[1];
            } else {
                $from_email = $to;
            }
        }
        
        $subject = 'Test Email from ' . $site_name . ' - AutoSync Plugin';
        $message = '<html><body style="font-family: Arial, sans-serif;">';
        $message .= '<h2>Test Email</h2>';
        $message .= '<p>This is a test email from AutoSync Product Manager plugin.</p>';
        $message .= '<p>If you received this email, your SMTP configuration is working correctly.</p>';
        $message .= '<p>Time: ' . date('Y-m-d H:i:s') . '</p>';
        $message .= '</body></html>';
        
        $headers_string = "MIME-Version: 1.0\r\n";
        $headers_string .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers_string .= "From: " . $site_name . " <" . $from_email . ">\r\n";
        $headers_string .= "Reply-To: " . $to;
        
        error_log('ASM Email Test: Sending test email to ' . $to);
        error_log('ASM Email Test: From: ' . $from_email);
        error_log('ASM Email Test: Subject: ' . $subject);
        
        $sent = mail($to, $subject, $message, $headers_string);
        
        if ($sent) {
            error_log('ASM Email Test: SUCCESS - Test email sent');
        } else {
            error_log('ASM Email Test: FAILED - Test email not sent');
            $last_error = error_get_last();
            if ($last_error) {
                error_log('ASM Email Test: Last error: ' . $last_error['message']);
            }
        }
        
        return $sent;
    }

    /**
     * Test email functionality
     * Can be called to verify email is working
     *
     * @return array Test results
     */
    public static function test_email() {
        // Hardcoded email address for test emails
        $to = 'sdtireandwheel@yopmail.com';
        $results = array(
            'success' => false,
            'message' => '',
            'details' => array()
        );
        
        if (empty($to)) {
            $results['message'] = 'Email address not configured';
            return $results;
        }
        
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        if (empty($domain)) {
            $domain = 'noreply';
        }
        
        $from_email = 'noreply@' . $domain;
        
        if ($from_email === 'noreply@' || strpos($from_email, '@') === false) {
            $admin_email_parts = explode('@', $to);
            if (count($admin_email_parts) === 2) {
                $from_email = 'noreply@' . $admin_email_parts[1];
            } else {
                $from_email = $to;
            }
        }
        
        $subject = 'Test Email from ' . $site_name;
        $message = '<html><body><h2>Test Email</h2><p>This is a test email from AutoSync Product Manager.</p><p>If you received this, email notifications are working correctly.</p></body></html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $from_email . '>',
            'Reply-To: ' . $to
        );
        
        $results['details'] = array(
            'to' => $to,
            'from' => $from_email,
            'subject' => $subject,
            'wp_mail_exists' => function_exists('wp_mail')
        );
        
        if (!function_exists('wp_mail')) {
            $results['message'] = 'wp_mail function not available';
            return $results;
        }
        
        $sent = wp_mail($to, $subject, $message, $headers);
        
        global $phpmailer;
        $error_message = '';
        if (isset($phpmailer) && is_object($phpmailer)) {
            if (!empty($phpmailer->ErrorInfo)) {
                $error_message = $phpmailer->ErrorInfo;
            }
        }
        
        $results['details']['wp_mail_return'] = $sent;
        $results['details']['phpmailer_error'] = $error_message;
        
        if ($sent) {
            $results['success'] = true;
            $results['message'] = 'Test email sent successfully';
        } else {
            $results['message'] = 'Failed to send test email: ' . ($error_message ? $error_message : 'Unknown error');
        }
        
        return $results;
    }
}

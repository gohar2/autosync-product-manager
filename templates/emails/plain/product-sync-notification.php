<?php
/**
 * Product Sync Notification Email (Plain)
 *
 * @package AutoSync_Product_Manager
 * @var string $email_heading Email heading
 * @var string $additional_content Additional content
 * @var string $sync_type Sync type
 * @var array $sync_stats Sync statistics
 * @var WC_Email $email Email object
 */

if (!defined('ABSPATH')) {
    exit;
}

echo "= " . esc_html($email_heading) . " =\n\n";
echo esc_html__('This is an automated notification from', 'autosync-manager') . ' ' . esc_html(get_bloginfo('name')) . ".\n\n";

$site_url = get_site_url();
$timestamp = date('Y-m-d H:i:s');

switch ($sync_type) {
    case 'single':
        echo esc_html__('Single Product Sync Completed', 'autosync-manager') . "\n\n";
        if (isset($sync_stats['product_name'])) {
            echo esc_html__('Product Name:', 'autosync-manager') . ' ' . esc_html($sync_stats['product_name']) . "\n";
        }
        if (isset($sync_stats['sku'])) {
            echo esc_html__('SKU:', 'autosync-manager') . ' ' . esc_html($sync_stats['sku']) . "\n";
        }
        if (isset($sync_stats['product_id'])) {
            echo esc_html__('Product ID:', 'autosync-manager') . ' ' . intval($sync_stats['product_id']) . "\n";
        }
        if (isset($sync_stats['price'])) {
            echo esc_html__('Price:', 'autosync-manager') . ' ' . wc_price($sync_stats['price']) . "\n";
        }
        if (isset($sync_stats['stock'])) {
            echo esc_html__('Stock:', 'autosync-manager') . ' ' . intval($sync_stats['stock']) . "\n";
        }
        break;
    
    case 'multiple':
        echo esc_html__('Batch Product Sync Completed', 'autosync-manager') . "\n\n";
        $total_processed = isset($sync_stats['total_processed']) ? intval($sync_stats['total_processed']) : 0;
        $imported = isset($sync_stats['imported']) ? intval($sync_stats['imported']) : 0;
        $updated = isset($sync_stats['updated']) ? intval($sync_stats['updated']) : 0;
        $skipped = isset($sync_stats['skipped']) ? intval($sync_stats['skipped']) : 0;
        $duration = isset($sync_stats['duration_seconds']) ? floatval($sync_stats['duration_seconds']) : 0;
        
        echo esc_html__('Total Processed:', 'autosync-manager') . ' ' . $total_processed . "\n";
        echo esc_html__('Imported:', 'autosync-manager') . ' ' . $imported . "\n";
        echo esc_html__('Updated:', 'autosync-manager') . ' ' . $updated . "\n";
        if ($skipped > 0) {
            echo esc_html__('Skipped:', 'autosync-manager') . ' ' . $skipped . "\n";
        }
        if ($duration > 0) {
            echo esc_html__('Duration:', 'autosync-manager') . ' ' . ASM_Email_Notifier::format_duration($duration) . "\n";
        }
        break;
    
    case 'all':
    default:
        echo esc_html__('Full Product Sync Completed', 'autosync-manager') . "\n\n";
        
        // Calculate summary totals
        $total_imported = (isset($sync_stats['tires']['total_imported']) ? intval($sync_stats['tires']['total_imported']) : 0) + 
                         (isset($sync_stats['wheels']['total_imported']) ? intval($sync_stats['wheels']['total_imported']) : 0);
        $total_updated = (isset($sync_stats['tires']['total_updated']) ? intval($sync_stats['tires']['total_updated']) : 0) + 
                        (isset($sync_stats['wheels']['total_updated']) ? intval($sync_stats['wheels']['total_updated']) : 0);
        $total_skipped = (isset($sync_stats['tires']['total_skipped']) ? intval($sync_stats['tires']['total_skipped']) : 0) + 
                        (isset($sync_stats['wheels']['total_skipped']) ? intval($sync_stats['wheels']['total_skipped']) : 0);
        
        // Summary section (moved above)
        echo esc_html__('Summary:', 'autosync-manager') . "\n";
        echo esc_html__('Total Imported:', 'autosync-manager') . ' ' . $total_imported . "\n";
        echo esc_html__('Total Updated:', 'autosync-manager') . ' ' . $total_updated . "\n";
        echo esc_html__('Total Skipped:', 'autosync-manager') . ' ' . $total_skipped . "\n";
        if (isset($sync_stats['duration'])) {
            echo esc_html__('Total Duration:', 'autosync-manager') . ' ' . ASM_Email_Notifier::format_duration($sync_stats['duration']) . "\n";
        }
        echo "\n";
        
        // Detailed statistics table format
        echo esc_html__('Detailed Statistics:', 'autosync-manager') . "\n";
        echo str_pad('', 30) . str_pad(esc_html__('Tires', 'autosync-manager'), 15) . esc_html__('Wheels', 'autosync-manager') . "\n";
        echo str_repeat('-', 60) . "\n";
        
        $tire_stats = isset($sync_stats['tires']) ? $sync_stats['tires'] : array();
        $wheel_stats = isset($sync_stats['wheels']) ? $sync_stats['wheels'] : array();
        
        echo str_pad(esc_html__('Brands Processed:', 'autosync-manager'), 30) . 
             str_pad(isset($tire_stats['brands_processed']) ? intval($tire_stats['brands_processed']) : 0, 15) . 
             (isset($wheel_stats['brands_processed']) ? intval($wheel_stats['brands_processed']) : 0) . "\n";
        echo str_pad(esc_html__('Total Imported:', 'autosync-manager'), 30) . 
             str_pad(isset($tire_stats['total_imported']) ? intval($tire_stats['total_imported']) : 0, 15) . 
             (isset($wheel_stats['total_imported']) ? intval($wheel_stats['total_imported']) : 0) . "\n";
        echo str_pad(esc_html__('Total Updated:', 'autosync-manager'), 30) . 
             str_pad(isset($tire_stats['total_updated']) ? intval($tire_stats['total_updated']) : 0, 15) . 
             (isset($wheel_stats['total_updated']) ? intval($wheel_stats['total_updated']) : 0) . "\n";
        echo str_pad(esc_html__('Total Skipped:', 'autosync-manager'), 30) . 
             str_pad(isset($tire_stats['total_skipped']) ? intval($tire_stats['total_skipped']) : 0, 15) . 
             (isset($wheel_stats['total_skipped']) ? intval($wheel_stats['total_skipped']) : 0) . "\n";
        echo str_pad(esc_html__('Total Pages:', 'autosync-manager'), 30) . 
             str_pad(isset($tire_stats['total_pages']) ? intval($tire_stats['total_pages']) : 0, 15) . 
             (isset($wheel_stats['total_pages']) ? intval($wheel_stats['total_pages']) : 0) . "\n";
        break;
}

echo "\n" . esc_html__('Sync completed at:', 'autosync-manager') . ' ' . esc_html($timestamp) . "\n";
echo esc_html__('Site URL:', 'autosync-manager') . ' ' . esc_url($site_url) . "\n";

if ($additional_content) {
    echo "\n" . esc_html($additional_content) . "\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text'));


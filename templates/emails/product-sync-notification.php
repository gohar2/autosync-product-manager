<?php
/**
 * Product Sync Notification Email (HTML)
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

do_action('woocommerce_email_header', $email_heading, $email);

$site_name = get_bloginfo('name');
$site_url = set_url_scheme(get_site_url(), 'https');
$timestamp = date('Y-m-d H:i:s');
?>

<p><?php printf(esc_html__('This is an automated notification from %s.', 'autosync-manager'), esc_html($site_name)); ?></p>

<?php
switch ($sync_type) {
    case 'single':
        ?>
        <h3 style="color: #27ae60;"><?php esc_html_e('Single Product Sync Completed', 'autosync-manager'); ?></h3>
        <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
            <?php if (isset($sync_stats['product_name'])) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Product Name:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo esc_html($sync_stats['product_name']); ?></td>
                </tr>
            <?php endif; ?>
            <?php if (isset($sync_stats['sku'])) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('SKU:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo esc_html($sync_stats['sku']); ?></td>
                </tr>
            <?php endif; ?>
            <?php if (isset($sync_stats['product_id'])) : 
                $edit_link = admin_url('post.php?post=' . intval($sync_stats['product_id']) . '&action=edit');
                ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Product ID:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;">
                        <a href="<?php echo esc_url($edit_link); ?>"><?php echo intval($sync_stats['product_id']); ?></a>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if (isset($sync_stats['price'])) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Price:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo wc_price($sync_stats['price']); ?></td>
                </tr>
            <?php endif; ?>
            <?php if (isset($sync_stats['stock'])) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Stock:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo intval($sync_stats['stock']); ?></td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
        break;
    
    case 'multiple':
        $total_processed = isset($sync_stats['total_processed']) ? intval($sync_stats['total_processed']) : 0;
        $imported = isset($sync_stats['imported']) ? intval($sync_stats['imported']) : 0;
        $updated = isset($sync_stats['updated']) ? intval($sync_stats['updated']) : 0;
        $skipped = isset($sync_stats['skipped']) ? intval($sync_stats['skipped']) : 0;
        $duration = isset($sync_stats['duration_seconds']) ? floatval($sync_stats['duration_seconds']) : 0;
        ?>
        <h3 style="color: #27ae60;"><?php esc_html_e('Batch Product Sync Completed', 'autosync-manager'); ?></h3>
        <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Total Processed:', 'autosync-manager'); ?></td>
                <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo $total_processed; ?></td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Imported:', 'autosync-manager'); ?></td>
                <td style="padding: 8px; border-bottom: 1px solid #eee; color: #27ae60;"><?php echo $imported; ?></td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Updated:', 'autosync-manager'); ?></td>
                <td style="padding: 8px; border-bottom: 1px solid #eee; color: #3498db;"><?php echo $updated; ?></td>
            </tr>
            <?php if ($skipped > 0) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Skipped:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c;"><?php echo $skipped; ?></td>
                </tr>
            <?php endif; ?>
            <?php if ($duration > 0) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Duration:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee;"><?php echo ASM_Email_Notifier::format_duration($duration); ?></td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
        break;
    
    case 'all':
    default:
        ?>
        <h3 style="color: #27ae60;"><?php esc_html_e('Full Product Sync Completed', 'autosync-manager'); ?></h3>
        
        <?php
        // Calculate summary totals
        $total_imported = (isset($sync_stats['tires']['total_imported']) ? intval($sync_stats['tires']['total_imported']) : 0) + 
                         (isset($sync_stats['wheels']['total_imported']) ? intval($sync_stats['wheels']['total_imported']) : 0);
        $total_updated = (isset($sync_stats['tires']['total_updated']) ? intval($sync_stats['tires']['total_updated']) : 0) + 
                        (isset($sync_stats['wheels']['total_updated']) ? intval($sync_stats['wheels']['total_updated']) : 0);
        $total_skipped = (isset($sync_stats['tires']['total_skipped']) ? intval($sync_stats['tires']['total_skipped']) : 0) + 
                        (isset($sync_stats['wheels']['total_skipped']) ? intval($sync_stats['wheels']['total_skipped']) : 0);
        ?>
        
        <!-- Summary Section (moved above Tires/Wheels) -->
        <h4 style="color: #2c3e50; margin-top: 20px;"><?php esc_html_e('Summary', 'autosync-manager'); ?></h4>
        <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Total Imported:', 'autosync-manager'); ?></td>
                <td style="padding: 8px; border-bottom: 1px solid #eee; color: #27ae60; font-size: 16px;"><strong><?php echo $total_imported; ?></strong></td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Total Updated:', 'autosync-manager'); ?></td>
                <td style="padding: 8px; border-bottom: 1px solid #eee; color: #3498db; font-size: 16px;"><strong><?php echo $total_updated; ?></strong></td>
            </tr>
            <tr>
                <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Total Skipped:', 'autosync-manager'); ?></td>
                <td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c;"><?php echo $total_skipped; ?></td>
            </tr>
            <?php if (isset($sync_stats['duration'])) : ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;"><?php esc_html_e('Total Duration:', 'autosync-manager'); ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid #eee; font-size: 16px;"><strong><?php echo ASM_Email_Notifier::format_duration($sync_stats['duration']); ?></strong></td>
                </tr>
            <?php endif; ?>
        </table>
        
        <!-- Tires and Wheels Comparison Table -->
        <h4 style="color: #2c3e50; margin-top: 20px;"><?php esc_html_e('Detailed Statistics', 'autosync-manager'); ?></h4>
        <table style="width: 100%; border-collapse: collapse; margin: 10px 0; border: 1px solid #ddd;">
            <thead>
                <tr style="background-color: #f5f5f5;">
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left; font-weight: bold;"></th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: center; font-weight: bold;"><?php esc_html_e('Tires', 'autosync-manager'); ?></th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: center; font-weight: bold;"><?php esc_html_e('Wheels', 'autosync-manager'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tire_stats = isset($sync_stats['tires']) ? $sync_stats['tires'] : array();
                $wheel_stats = isset($sync_stats['wheels']) ? $sync_stats['wheels'] : array();
                ?>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e('Brands Processed:', 'autosync-manager'); ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><?php echo isset($tire_stats['brands_processed']) ? intval($tire_stats['brands_processed']) : 0; ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><?php echo isset($wheel_stats['brands_processed']) ? intval($wheel_stats['brands_processed']) : 0; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e('Total Imported:', 'autosync-manager'); ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: #27ae60;"><?php echo isset($tire_stats['total_imported']) ? intval($tire_stats['total_imported']) : 0; ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: #27ae60;"><?php echo isset($wheel_stats['total_imported']) ? intval($wheel_stats['total_imported']) : 0; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e('Total Updated:', 'autosync-manager'); ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: #3498db;"><?php echo isset($tire_stats['total_updated']) ? intval($tire_stats['total_updated']) : 0; ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: #3498db;"><?php echo isset($wheel_stats['total_updated']) ? intval($wheel_stats['total_updated']) : 0; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e('Total Skipped:', 'autosync-manager'); ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: #e74c3c;"><?php echo isset($tire_stats['total_skipped']) ? intval($tire_stats['total_skipped']) : 0; ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: #e74c3c;"><?php echo isset($wheel_stats['total_skipped']) ? intval($wheel_stats['total_skipped']) : 0; ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e('Total Pages:', 'autosync-manager'); ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><?php echo isset($tire_stats['total_pages']) ? intval($tire_stats['total_pages']) : 0; ?></td>
                    <td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><?php echo isset($wheel_stats['total_pages']) ? intval($wheel_stats['total_pages']) : 0; ?></td>
                </tr>
            </tbody>
        </table>
        <?php
        break;
}
?>

<p style="color: #666; font-size: 12px; margin-top: 20px;">
    <?php printf(esc_html__('Sync completed at: %s', 'autosync-manager'), esc_html($timestamp)); ?><br>
    <?php printf(esc_html__('Site URL: %s', 'autosync-manager'), '<a href="' . esc_url($site_url) . '">' . esc_html($site_url) . '</a>'); ?>
</p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);


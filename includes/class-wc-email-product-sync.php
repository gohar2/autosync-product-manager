<?php
/**
 * Product Sync Notification Email
 *
 * WooCommerce email class for product sync notifications
 *
 * @package AutoSync_Product_Manager
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Only proceed if WC_Email class exists (WooCommerce is loaded)
if (!class_exists('WC_Email')) {
    return;
}

if (!class_exists('WC_Email_Product_Sync', false)) :

/**
 * Product Sync Notification Email Class
 *
 * @class       WC_Email_Product_Sync
 * @extends     WC_Email
 */
class WC_Email_Product_Sync extends WC_Email {

    /**
     * Sync type
     *
     * @var string
     */
    public $sync_type;

    /**
     * Sync statistics
     *
     * @var array
     */
    public $sync_stats;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id             = 'asm_product_sync';
        $this->title          = __('Product Sync Notification', 'autosync-manager');
        $this->description    = __('Product sync notification emails are sent after product synchronization operations complete.', 'autosync-manager');
        $this->template_html  = 'emails/product-sync-notification.php';
        $this->template_plain = 'emails/plain/product-sync-notification.php';
        $this->template_base  = ASM_PLUGIN_DIR . 'templates/';
        
        // Default recipient
        $this->recipient = $this->get_option('recipient', 'sdtireandwheel@yopmail.com');
        
        // Call parent constructor
        parent::__construct();
    }

    /**
     * Get email subject.
     *
     * @return string
     */
    public function get_default_subject() {
        return __('[{site_title}] Product Sync Notification', 'autosync-manager');
    }

    /**
     * Get email heading.
     *
     * @return string
     */
    public function get_default_heading() {
        return __('Product Sync Notification', 'autosync-manager');
    }

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        parent::init_form_fields();
        
        // Add recipient field
        $this->form_fields = array_merge(
            array(
                'recipient' => array(
                    'title'       => __('Recipient(s)', 'autosync-manager'),
                    'type'        => 'text',
                    'description' => __('Enter recipients (comma-separated) for this email. Defaults to admin email.', 'autosync-manager'),
                    'placeholder' => get_option('admin_email'),
                    'default'     => 'sdtireandwheel@yopmail.com',
                    'desc_tip'    => true,
                ),
            ),
            $this->form_fields
        );
    }

    /**
     * Trigger the sending of this email.
     *
     * @param string $sync_type Type of sync: 'single', 'multiple', 'all'
     * @param array  $stats Sync statistics
     */
    public function trigger($sync_type = 'all', $stats = array()) {
        $this->setup_locale();

        $this->sync_type = $sync_type;
        $this->sync_stats = $stats;

        // Update placeholders
        $site_name = get_bloginfo('name');
        $timestamp = date('Y-m-d H:i:s');
        
        $this->placeholders['{sync_type}'] = ucfirst($sync_type);
        $this->placeholders['{timestamp}'] = $timestamp;
        $this->placeholders['{site_name}'] = $site_name;

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }

        $this->restore_locale();
    }

    /**
     * Get content html.
     *
     * @return string
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'email_heading'      => $this->get_heading(),
                'additional_content'  => $this->get_additional_content(),
                'sync_type'          => $this->sync_type,
                'sync_stats'         => $this->sync_stats,
                'email'              => $this,
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Get content plain.
     *
     * @return string
     */
    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'email_heading'      => $this->get_heading(),
                'additional_content'  => $this->get_additional_content(),
                'sync_type'          => $this->sync_type,
                'sync_stats'         => $this->sync_stats,
                'email'              => $this,
            ),
            '',
            $this->template_base
        );
    }
}

endif;


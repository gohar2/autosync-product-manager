<?php
/**
 * Image Downloader Class
 *
 * Downloads images from external URLs and uploads them to WordPress media library
 * Attaches images to WooCommerce products as featured image and gallery
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once ASM_PLUGIN_DIR . 'includes/class-logger.php';

class ASM_Image_Downloader {

    /**
     * Download and attach images to a WooCommerce product
     *
     * @param int $product_id Product ID
     * @param object $api_data API product data
     * @param array $image_urls Image base URLs from API
     * @param string $type Product type ('wheel' or 'tire')
     * @return array Array with featured_image_id and gallery_image_ids
     */
    public static function download_and_attach_images($product_id, $api_data, $image_urls = array(), $type = 'wheel') {
        if (!$product_id || !is_numeric($product_id)) {
            return array(
                'featured_image_id' => 0,
                'gallery_image_ids' => array()
            );
        }

        $featured_image_id = 0;
        $gallery_image_ids = array();

        // Get image base URL
        $image_base = self::get_image_base_url($image_urls, $type);

        if (empty($image_base)) {
            // ASM_Logger::warning('No image base URL found', array(
            //     'product_id' => $product_id,
            //     'type' => $type
            // ));
            return array(
                'featured_image_id' => 0,
                'gallery_image_ids' => array()
            );
        }

        // Get image URLs based on product type
        $image_urls_list = self::get_image_urls_from_api_data($api_data, $image_base, $type);

        if (empty($image_urls_list)) {
            // ASM_Logger::info('No images found in API data', array(
            //     'product_id' => $product_id,
            //     'type' => $type
            // ));
            return array(
                'featured_image_id' => 0,
                'gallery_image_ids' => array()
            );
        }

        // Download and upload featured image (first image)
        if (!empty($image_urls_list['featured'])) {
            $featured_image_id = self::download_image($image_urls_list['featured'], $product_id, $api_data, $type, 'featured');
        }

        // Download and upload gallery images
        if (!empty($image_urls_list['gallery']) && is_array($image_urls_list['gallery'])) {
            foreach ($image_urls_list['gallery'] as $gallery_url) {
                $gallery_image_id = self::download_image($gallery_url, $product_id, $api_data, $type, 'gallery');
                if ($gallery_image_id > 0) {
                    $gallery_image_ids[] = $gallery_image_id;
                }
            }
        }

        // Attach images to product
        if ($featured_image_id > 0 || !empty($gallery_image_ids)) {
            self::attach_images_to_product($product_id, $featured_image_id, $gallery_image_ids);
        }

        return array(
            'featured_image_id' => $featured_image_id,
            'gallery_image_ids' => $gallery_image_ids
        );
    }

    /**
     * Get image base URL from image_urls array
     *
     * @param array $image_urls Image URLs array
     * @param string $type Product type
     * @return string Image base URL
     */
    private static function get_image_base_url($image_urls, $type) {
        if (!empty($image_urls['img'])) {
            return $image_urls['img'];
        }

        // Fallback to default CDN URLs
        if ($type === 'tire') {
            return 'https://storage.googleapis.com/autosync_tires/';
        } else {
            return 'https://wheels.autosyncstudio.com/webp/';
        }
    }

    /**
     * Get image URLs from API data
     *
     * @param object $api_data API product data
     * @param string $image_base Base URL for images
     * @param string $type Product type
     * @return array Array with 'featured' and 'gallery' keys
     */
    private static function get_image_urls_from_api_data($api_data, $image_base, $type) {
        $urls = array(
            'featured' => '',
            'gallery' => array()
        );

        if ($type === 'tire') {
            // Tire featured image (ImgAngle)
            if (!empty($api_data->ImgAngle)) {
                $urls['featured'] = $image_base . $api_data->ImgAngle;
            }

            // Tire gallery images
            if (!empty($api_data->ImgFront)) {
                $urls['gallery'][] = $image_base . $api_data->ImgFront;
            }
            if (!empty($api_data->ImgSide1)) {
                $urls['gallery'][] = $image_base . $api_data->ImgSide1;
            }
            if (!empty($api_data->ImgThumb)) {
                $urls['gallery'][] = $image_base . $api_data->ImgThumb;
            }
            if (!empty($api_data->ImgSide2)) {
                $urls['gallery'][] = $image_base . $api_data->ImgSide2;
            }
        } else {
            // Wheel featured image (Img0001)
            if (!empty($api_data->Img0001)) {
                $urls['featured'] = $image_base . $api_data->Img0001;
            }

            // Wheel gallery images (Img0002 to Img0020)
            for ($i = 2; $i <= 20; $i++) {
                $img_key = 'Img' . str_pad($i, 4, '0', STR_PAD_LEFT);
                if (!empty($api_data->$img_key)) {
                    $urls['gallery'][] = $image_base . $api_data->$img_key;
                }
            }
        }

        return $urls;
    }

    /**
     * Download image from URL and upload to WordPress media library
     *
     * @param string $image_url Full image URL
     * @param int $product_id Product ID (for attachment parent)
     * @param object $api_data API product data (for filename generation)
     * @param string $type Product type
     * @param string $image_type 'featured' or 'gallery'
     * @return int Attachment ID or 0 on failure
     */
    private static function download_image($image_url, $product_id, $api_data, $type, $image_type = 'gallery') {
        if (empty($image_url)) {
            return 0;
        }

        // Check if image already exists in media library (by URL)
        $existing_attachment_id = self::get_attachment_by_url($image_url);
        if ($existing_attachment_id > 0) {
            // ASM_Logger::info('Image already exists in media library', array(
            //     'url' => $image_url,
            //     'attachment_id' => $existing_attachment_id
            // ));
            return $existing_attachment_id;
        }

        // Download image
        $image_data = self::download_image_from_url($image_url);
        if (empty($image_data)) {
            // ASM_Logger::warning('Failed to download image', array(
            //     'url' => $image_url,
            //     'product_id' => $product_id
            // ));
            return 0;
        }

        // Generate filename
        $filename = self::generate_filename($image_url, $api_data, $type, $image_type);

        // Upload to WordPress media library
        $attachment_id = self::upload_to_media_library($image_data, $filename, $product_id);

        if ($attachment_id > 0) {
            // Store original URL in attachment meta for future reference
            update_post_meta($attachment_id, '_asm_original_url', $image_url);
            
            // ASM_Logger::info('Image downloaded and uploaded successfully', array(
            //     'url' => $image_url,
            //     'attachment_id' => $attachment_id,
            //     'product_id' => $product_id
            // ));
        } else {
            // ASM_Logger::error('Failed to upload image to media library', array(
            //     'url' => $image_url,
            //     'product_id' => $product_id
            // ));
        }

        return $attachment_id;
    }

    /**
     * Download image from URL
     *
     * @param string $url Image URL
     * @return string|false Image data or false on failure
     */
    private static function download_image_from_url($url) {
        if (empty($url)) {
            return false;
        }

        // Use wp_remote_get to download image
        // Increased timeout for large images or slow connections
        $response = wp_remote_get($url, array(
            'timeout' => 60, // Increased from 30 to 60 seconds
            'sslverify' => true,
            'redirection' => 5,
            'user-agent' => 'WordPress/AutoSync-Product-Manager'
        ));

        if (is_wp_error($response)) {
            // ASM_Logger::error('Error downloading image', array(
            //     'url' => $url,
            //     'error' => $response->get_error_message()
            // ));
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            // ASM_Logger::error('Invalid response code when downloading image', array(
            //     'url' => $url,
            //     'response_code' => $response_code
            // ));
            return false;
        }

        $image_data = wp_remote_retrieve_body($response);
        
        // Check if we actually got data
        if (empty($image_data)) {
            // ASM_Logger::error('Downloaded image data is empty', array(
            //     'url' => $url,
            //     'response_code' => $response_code
            // ));
            return false;
        }
        
        // Validate that it's actually an image
        $image_info = @getimagesizefromstring($image_data);
        if ($image_info === false) {
            // ASM_Logger::error('Downloaded file is not a valid image', array(
            //     'url' => $url,
            //     'data_length' => strlen($image_data)
            // ));
            return false;
        }
        
        // Log successful download
        // ASM_Logger::info('Image downloaded successfully', array(
        //     'url' => $url,
        //     'size' => strlen($image_data),
        //     'width' => $image_info[0] ?? 0,
        //     'height' => $image_info[1] ?? 0,
        //     'mime_type' => $image_info['mime'] ?? 'unknown'
        // ));

        return $image_data;
    }

    /**
     * Generate filename for uploaded image
     *
     * @param string $image_url Original image URL
     * @param object $api_data API product data
     * @param string $type Product type
     * @param string $image_type 'featured' or 'gallery'
     * @return string Filename
     */
    private static function generate_filename($image_url, $api_data, $type, $image_type) {
        // Get SKU for filename
        $sku = ($type === 'tire') ? ($api_data->PartNumber ?? '') : ($api_data->Pn ?? '');
        $sku = sanitize_file_name($sku);
        
        // Get brand for filename
        $brand = isset($api_data->Brand) ? sanitize_file_name($api_data->Brand) : '';
        
        // Extract extension from URL
        $url_path = parse_url($image_url, PHP_URL_PATH);
        $extension = pathinfo($url_path, PATHINFO_EXTENSION);
        if (empty($extension)) {
            $extension = 'jpg'; // Default extension
        }
        
        // Generate filename: brand-sku-featured/gallery-N.ext
        $prefix = '';
        if (!empty($brand)) {
            $prefix .= $brand . '-';
        }
        if (!empty($sku)) {
            $prefix .= $sku . '-';
        }
        
        if ($image_type === 'featured') {
            $filename = $prefix . 'featured.' . $extension;
        } else {
            // For gallery, add timestamp to avoid conflicts
            $filename = $prefix . 'gallery-' . time() . '-' . rand(1000, 9999) . '.' . $extension;
        }
        
        // Ensure filename is not too long (max 255 chars)
        if (strlen($filename) > 200) {
            $filename = substr($prefix, 0, 150) . '-' . time() . '.' . $extension;
        }
        
        return $filename;
    }

    /**
     * Upload image data to WordPress media library
     *
     * @param string $image_data Image binary data
     * @param string $filename Filename
     * @param int $product_id Product ID (for attachment parent)
     * @return int Attachment ID or 0 on failure
     */
    private static function upload_to_media_library($image_data, $filename, $product_id) {
        if (empty($image_data) || empty($filename)) {
            return 0;
        }

        // Verify image data is not empty
        if (empty($image_data) || strlen($image_data) < 100) {
            // ASM_Logger::error('Image data is empty or too small', array(
            //     'filename' => $filename,
            //     'data_length' => strlen($image_data)
            // ));
            return 0;
        }

        // Use wp_upload_bits which is WordPress's proper function for uploading files
        // This handles all the WordPress-specific requirements
        $upload = wp_upload_bits($filename, null, $image_data);
        
        if ($upload['error']) {
            // ASM_Logger::error('Failed to upload image file', array(
            //     'filename' => $filename,
            //     'error' => $upload['error'],
            //     'data_length' => strlen($image_data)
            // ));
            return 0;
        }

        // Verify file was actually written and has content
        if (!file_exists($upload['file'])) {
            // ASM_Logger::error('Uploaded file does not exist', array(
            //     'file' => $upload['file'],
            //     'url' => $upload['url'] ?? 'not set'
            // ));
            return 0;
        }
        
        $file_size = filesize($upload['file']);
        if ($file_size == 0) {
            // ASM_Logger::error('Uploaded file is empty', array(
            //     'file' => $upload['file'],
            //     'size' => $file_size
            // ));
            @unlink($upload['file']); // Clean up empty file
            return 0;
        }
        
        // ASM_Logger::info('File uploaded successfully', array(
        //     'file' => $upload['file'],
        //     'url' => $upload['url'],
        //     'size' => $file_size
        // ));

        // Get file type
        $file_type = wp_check_filetype(basename($upload['file']), null);
        
        // Ensure we have a valid mime type
        if (empty($file_type['type'])) {
            // Try to detect from file content
            $image_info = @getimagesize($upload['file']);
            if ($image_info && isset($image_info['mime'])) {
                $file_type['type'] = $image_info['mime'];
            } else {
                $file_type['type'] = 'image/jpeg'; // Default fallback
            }
        }
        
        // Prepare attachment data
        $attachment_data = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $product_id
        );

        // Insert attachment
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // wp_insert_attachment can accept absolute path
        // Use the absolute path from wp_upload_bits
        $attachment_id = wp_insert_attachment($attachment_data, $upload['file'], $product_id);
        
        if (is_wp_error($attachment_id)) {
            @unlink($upload['file']); // Clean up file on error
            // ASM_Logger::error('Failed to insert attachment', array(
            //     'error' => $attachment_id->get_error_message(),
            //     'file' => $upload['file'],
            //     'url' => $upload['url'] ?? 'not set'
            // ));
            return 0;
        }

        // Update the attachment's file path in post meta (critical for WordPress to find the file)
        update_attached_file($attachment_id, $upload['file']);

        // Update GUID to ensure it's correct
        wp_update_post(array(
            'ID' => $attachment_id,
            'guid' => $upload['url']
        ));

        // Generate attachment metadata (creates thumbnails, etc.)
        // Use absolute path for metadata generation
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (!empty($attachment_metadata)) {
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
        } else {
            // ASM_Logger::warning('Attachment metadata generation returned empty', array(
            //     'attachment_id' => $attachment_id,
            //     'file' => $upload['file']
            // ));
        }

        // Verify attachment was created correctly
        $attachment_url = wp_get_attachment_url($attachment_id);
        $attachment_file = get_attached_file($attachment_id);
        
        if (empty($attachment_url)) {
            // ASM_Logger::error('Attachment created but URL is empty', array(
            //     'attachment_id' => $attachment_id,
            //     'file' => $upload['file'],
            //     'attachment_file' => $attachment_file,
            //     'guid' => get_post($attachment_id)->guid ?? 'not set'
            // ));
        } else {
            // Verify file exists and is readable
            if (empty($attachment_file)) {
                // ASM_Logger::error('Attachment file path is empty', array(
                //     'attachment_id' => $attachment_id,
                //     'upload_file' => $upload['file']
                // ));
            } elseif (!file_exists($attachment_file)) {
                // ASM_Logger::error('Attachment file does not exist at stored path', array(
                //     'attachment_id' => $attachment_id,
                //     'attachment_file' => $attachment_file,
                //     'upload_file' => $upload['file'],
                //     'files_match' => ($attachment_file === $upload['file'])
                // ));
            } elseif (!is_readable($attachment_file)) {
                // ASM_Logger::error('Attachment file is not readable', array(
                //     'attachment_id' => $attachment_id,
                //     'attachment_file' => $attachment_file,
                //     'permissions' => substr(sprintf('%o', fileperms($attachment_file)), -4)
                // ));
            } else {
                // ASM_Logger::info('Image uploaded and attached successfully', array(
                //     'attachment_id' => $attachment_id,
                //     'url' => $attachment_url,
                //     'file' => $attachment_file,
                //     'file_exists' => true,
                //     'file_size' => filesize($attachment_file)
                // ));
            }
        }

        return $attachment_id;
    }

    /**
     * Check if image already exists in media library by URL
     *
     * @param string $image_url Image URL
     * @return int Attachment ID or 0 if not found
     */
    private static function get_attachment_by_url($image_url) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_asm_original_url' AND meta_value = %s LIMIT 1",
            $image_url
        ));
        
        return $attachment_id ? intval($attachment_id) : 0;
    }

    /**
     * Attach images to WooCommerce product
     *
     * @param int $product_id Product ID
     * @param int $featured_image_id Featured image attachment ID
     * @param array $gallery_image_ids Gallery image attachment IDs
     * @return bool Success
     */
    private static function attach_images_to_product($product_id, $featured_image_id, $gallery_image_ids) {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        // Set featured image
        if ($featured_image_id > 0) {
            $product->set_image_id($featured_image_id);
        }

        // Set gallery images (remove duplicates and featured image from gallery)
        if (!empty($gallery_image_ids)) {
            // Remove featured image from gallery if it's there
            $gallery_image_ids = array_filter($gallery_image_ids, function($id) use ($featured_image_id) {
                return $id != $featured_image_id;
            });
            
            // Remove duplicates
            $gallery_image_ids = array_unique(array_map('intval', $gallery_image_ids));
            
            // Set gallery image IDs
            $product->set_gallery_image_ids($gallery_image_ids);
        }

        // Save product
        $product->save();

        return true;
    }
}

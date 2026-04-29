<?php
/**
 * AutoSync API Client
 *
 * Simple HTTP client for AutoSync Studio API
 *
 * @package AutoSync_Product_Manager
 * @since 0.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ASM_API_Client {

    /**
     * API Key
     */
    private $api_key;

    /**
     * API Base URL
     */
    private $api_url;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key = get_option('asm_api_key', '');
        $this->api_url = get_option('asm_api_url', 'https://api.autosyncstudio.com');
    }

    /**
     * Set API key (for testing without saving)
     */
    public function set_api_key($api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => 'API key is required',
            );
        }

        // Test by fetching a simple wheel product (just 1 item to test connection)
        $response = $this->get('/wheels', array(
            'p-size' => 1,
            'Source' => 'WP',
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Check HTTP status
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'message' => 'API returned HTTP ' . $status_code . ': ' . substr($body, 0, 100),
            );
        }

        $data = json_decode($body);

        if (!$data) {
            return array(
                'success' => false,
                'message' => 'Invalid JSON response. Raw: ' . substr($body, 0, 200),
            );
        }

        // Check if we got wheels data
        if (isset($data->Wheels) && is_array($data->Wheels) && count($data->Wheels) > 0) {
            $first_wheel = $data->Wheels[0];
            $wheel_info = isset($first_wheel->Brand) ? $first_wheel->Brand : 'Unknown';

            return array(
                'success' => true,
                'message' => 'Connected successfully! API is working. Sample wheel: ' . $wheel_info,
                'sample_product' => $first_wheel,
            );
        } elseif (isset($data->Wheels) && is_array($data->Wheels) && count($data->Wheels) === 0) {
            return array(
                'success' => true,
                'message' => 'Connected successfully! API key is valid (no products returned, which is OK for testing).',
            );
        }

        // If we got here, show what we received for debugging
        return array(
            'success' => false,
            'message' => 'Unexpected API response. Keys: ' . implode(', ', array_keys((array)$data)),
        );
    }

    /**
     * Make GET request to API
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|WP_Error Response
     */
    private function get($endpoint, $params = array()) {
        // Always add API key
        $params['key'] = $this->api_key;

        // Build query string
        $query_string = http_build_query($params);
        $url = $this->api_url . $endpoint . '?' . $query_string;

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        );

        return wp_remote_get($url, $args);
    }

    /**
     * Get API key
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Get API URL
     */
    public function get_api_url() {
        return $this->api_url;
    }

    /**
     * Fetch a single wheel product for testing
     * Returns a random product by using a random page number
     *
     * @return array|false Array with product and image URLs or false on failure
     */
    public function get_single_wheel() {
        // Generate random page number between 1-100 to get different products each time
        $random_page = rand(1, 100);

        // Fetch a small page with random offset to get variety
        $params = array(
            'Source' => 'WP',
            'i-cap' => 'true',
            'i-shipping' => 'true',
            'i-inventory' => 'yes',
            'f-imgFormat' => 'webp',
            'i-price' => 'yes',
            'i-img0001' => 'true',
            'i-img0002' => 'true',
            'i-img0003' => 'true',
            'i-tags' => 'true',
            'i-specs' => 'true',
            'p-number' => $random_page,
            'p-size' => 5, // Get 5 products to choose from
            'MoreItems' => 'true',
        );

        $response = $this->get('/wheels', $params);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->Wheels) && is_array($data->Wheels) && count($data->Wheels) > 0) {
            // Get image base URLs from response, fallback to wheels CDN
            $image_urls = array(
                'img' => isset($data->ImgUrlBase) ? $data->ImgUrlBase : 'https://wheels.autosyncstudio.com/webp/',
                'rotation' => isset($data->RotationImgUrlBase) ? $data->RotationImgUrlBase : '',
                'brand_logos' => isset($data->BrandLogoImgUrlBase) ? $data->BrandLogoImgUrlBase : '',
                'type' => 'wheel', // Product type for CDN selection
            );

            // Pick a random product from the returned batch
            $random_index = rand(0, count($data->Wheels) - 1);

            return array(
                'product' => $data->Wheels[$random_index],
                'image_urls' => $image_urls,
            );
        }

        return false;
    }

    /**
     * Fetch multiple wheel products with pagination
     *
     * @param int $page_number Page number (default 1)
     * @param int $page_size Number of products per page (default 10)
     * @param int $brand_id Brand ID filter (optional)
     * @return array|false Array with products and image URLs or false on failure
     */
    public function get_wheels($page_number = 1, $page_size = 10, $brand_id = 0) {
        // Use same parameters as parent plugin for complete data
        $params = array(
            'Source' => 'WP',
            'i-cap' => 'true',
            'i-shipping' => 'true',
            'i-inventory' => 'yes',
            'f-imgFormat' => 'webp',
            'i-price' => 'yes',
            'i-img0001' => 'true',
            'i-img0002' => 'true',
            'i-img0003' => 'true',
            'i-tags' => 'true',
            'i-specs' => 'true',
            'p-number' => $page_number,
            'p-size' => $page_size,
            'MoreItems' => 'true',
        );

        // Add brand ID filter if provided (using f-brandIds parameter)
        if (!empty($brand_id) && is_numeric($brand_id)) {
            $params['f-brandIds'] = intval($brand_id);
        }

        $response = $this->get('/wheels', $params);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->Wheels) && is_array($data->Wheels)) {
            // Get image base URLs from response, fallback to wheels CDN
            $image_urls = array(
                'img' => isset($data->ImgUrlBase) ? $data->ImgUrlBase : 'https://wheels.autosyncstudio.com/webp/',
                'rotation' => isset($data->RotationImgUrlBase) ? $data->RotationImgUrlBase : '',
                'brand_logos' => isset($data->BrandLogoImgUrlBase) ? $data->BrandLogoImgUrlBase : '',
                'type' => 'wheel', // Product type for CDN selection
            );

            return array(
                'products' => $data->Wheels,
                'image_urls' => $image_urls,
                'total_count' => count($data->Wheels),
                'has_more' => isset($data->MoreItems) ? $data->MoreItems : false,
            );
        }

        return false;
    }

    /**
     * Fetch a single tire product for testing
     * Returns a random product by using a random page number
     *
     * @return array|false Array with product and image URLs or false on failure
     */
    public function get_single_tire() {
        // Generate random page number between 1-100 to get different products each time
        $random_page = rand(1, 100);

        // Fetch a small page with random offset to get variety
        $params = array(
            'Source' => 'WP',
            'i-cap' => 'true',
            'i-shipping' => 'true',
            'i-inventory' => 'yes',
            'f-imgFormat' => 'webp',
            'i-price' => 'yes',
            'i-img-angle' => 'true',
            'i-img-front' => 'true',
            'i-img-side1' => 'true',
            'i-img-thumb' => 'true',
            'i-img-side2' => 'true',
            'i-tags' => 'true',
            'i-specs' => 'true',
            'p-number' => $random_page,
            'p-size' => 5, // Get 5 products to choose from
            'MoreItems' => 'true',
        );

        $response = $this->get('/tires', $params);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->Tires) && is_array($data->Tires) && count($data->Tires) > 0) {
            // Get image base URLs from response, fallback to tires CDN
            $image_urls = array(
                'img' => isset($data->ImgUrlBase) ? $data->ImgUrlBase : 'https://storage.googleapis.com/autosync_tires/',
                'rotation' => isset($data->RotationImgUrlBase) ? $data->RotationImgUrlBase : '',
                'brand_logos' => isset($data->BrandLogoImgUrlBase) ? $data->BrandLogoImgUrlBase : '',
                'type' => 'tire', // Product type for CDN selection
            );

            // Pick a random product from the returned batch
            $random_index = rand(0, count($data->Tires) - 1);

            return array(
                'product' => $data->Tires[$random_index],
                'image_urls' => $image_urls,
            );
        }

        return false;
    }

    /**
     * Fetch multiple tire products with pagination
     *
     * @param int $page_number Page number (default 1)
     * @param int $page_size Number of products per page (default 10)
     * @param int $brand_id Brand ID filter (optional)
     * @return array|false Array with products and image URLs or false on failure
     */
    public function get_tires($page_number = 1, $page_size = 10, $brand_id = 0) {
        // Use same parameters as parent plugin for complete data
        $params = array(
            'Source' => 'WP',
            'i-cap' => 'true',
            'i-shipping' => 'true',
            'i-inventory' => 'yes',
            'i-img' => 'true',
            'f-imgFormat' => 'webp',
            'i-price' => 'yes',
            'i-tags' => 'true',
            'i-specs' => 'true',
            'p-number' => $page_number,
            'p-size' => $page_size,
            'MoreItems' => 'true',
        );

        // Add brand ID filter if provided (using f-brandIds parameter)
        if (!empty($brand_id) && is_numeric($brand_id)) {
            $params['f-brandIds'] = intval($brand_id);
        }

        $response = $this->get('/tires', $params);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
      
        if (isset($data->Tires) && is_array($data->Tires)) {
            // Get image base URLs from response, fallback to tires CDN
            $image_urls = array(
                'img' => isset($data->ImgUrlBase) ? $data->ImgUrlBase : 'https://storage.googleapis.com/autosync_tires/',
                'rotation' => isset($data->RotationImgUrlBase) ? $data->RotationImgUrlBase : '',
                'brand_logos' => isset($data->BrandLogoImgUrlBase) ? $data->BrandLogoImgUrlBase : '',
                'type' => 'tire', // Product type for CDN selection
            );

            return array(
                'products' => $data->Tires,
                'image_urls' => $image_urls,
                'total_count' => count($data->Tires),
                'has_more' => isset($data->MoreItems) ? $data->MoreItems : false,
            );
        }

        return false;
    }

    /**
     * Fetch tire brands with pagination
     *
     * @param int $page_number Page number (default 1)
     * @param int $page_size Number of brands per page (default 500)
     * @return array|false Array with brands and has_more flag or false on failure
     */
    public function get_tire_brands($page_number = 1, $page_size = 500) {
        $params = array(
            'Source' => 'WP',
            'p-number' => $page_number,
            'p-size' => $page_size,
        );

        $response = $this->get('/tires/brands', $params);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->Brands) && is_array($data->Brands)) {
            return array(
                'brands' => $data->Brands,
                'has_more' => isset($data->MoreItems) ? $data->MoreItems : false,
            );
        }

        return false;
    }

    /**
     * Fetch wheel brands with pagination
     *
     * @param int $page_number Page number (default 1)
     * @param int $page_size Number of brands per page (default 500)
     * @return array|false Array with brands and has_more flag or false on failure
     */
    public function get_wheel_brands($page_number = 1, $page_size = 500) {
        $params = array(
            'Source' => 'WP',
            'p-number' => $page_number,
            'p-size' => $page_size,
        );

        $response = $this->get('/wheels/brands', $params);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->Brands) && is_array($data->Brands)) {
            return array(
                'brands' => $data->Brands,
                'has_more' => isset($data->MoreItems) ? $data->MoreItems : false,
            );
        }

        return false;
    }

    /**
     * Fetch all tire brands (handles pagination automatically)
     *
     * @return array|false Array of all brands or false on failure
     */
    public function get_all_tire_brands() {
        $all_brands = array();
        $page_number = 1;
        $page_size = 500;

        do {
            $result = $this->get_tire_brands($page_number, $page_size);
            
            if (!$result || empty($result['brands'])) {
                break;
            }

            $all_brands = array_merge($all_brands, $result['brands']);
            $has_more = $result['has_more'];
            $page_number++;

        } while ($has_more === true);

        return !empty($all_brands) ? array('brands' => $all_brands) : false;
    }

    /**
     * Fetch all wheel brands (handles pagination automatically)
     *
     * @return array|false Array of all brands or false on failure
     */
    public function get_all_wheel_brands() {
        $all_brands = array();
        $page_number = 1;
        $page_size = 500;

        do {
            $result = $this->get_wheel_brands($page_number, $page_size);
            
            if (!$result || empty($result['brands'])) {
                break;
            }

            $all_brands = array_merge($all_brands, $result['brands']);
            $has_more = $result['has_more'];
            $page_number++;

        } while ($has_more === true);

        return !empty($all_brands) ? array('brands' => $all_brands) : false;
    }
}

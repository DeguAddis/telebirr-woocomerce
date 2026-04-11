<?php
/**
 * Plugin Name: WooCommerce Telebirr Payment Gateway
 * Plugin URI: https://github.com/yourusername/wc-telebirr-payment
 * Description: Telebirr mobile money payment gateway integration for WooCommerce
 * Version: 1.1.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-telebirr-payment
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.7
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Register the payment gateway with WooCommerce
add_action('plugins_loaded', 'wc_telebirr_init_gateway', 11);
add_filter('woocommerce_payment_gateways', 'wc_telebirr_add_gateway');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_telebirr_plugin_action_links');

/**
 * Initialize Telebirr Payment Gateway
 */
function wc_telebirr_init_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Telebirr extends WC_Payment_Gateway {

        /** @var bool Whether test mode is enabled */
        private $testmode;

        /** @var string Fabric App ID */
        private $fabric_app_id;

        /** @var string App Secret */
        private $app_secret;

        /** @var string Merchant App ID */
        private $merchant_app_id;

        /** @var string Merchant Code */
        private $merchant_code;

        /** @var string RSA Private Key (PEM) */
        private $private_key;

        /** @var string Telebirr Public Key (PEM) */
        private $public_key;

        /** @var string Base API URL */
        private $base_url;

        /** @var string Web payment base URL */
        private $web_base_url;

        /** @var string Allowed webhook IP ranges (comma-separated) */
        private $allowed_ips;

        /** @var WC_Logger Logger instance */
        private $logger;

        /**
         * Constructor for the gateway
         */
        public function __construct() {
            $this->id                 = 'telebirr';
            $this->icon               = apply_filters('woocommerce_telebirr_icon', plugins_url('assets/telebirr-logo.png', __FILE__));
            $this->has_fields         = false;
            $this->method_title       = __('Telebirr', 'wc-telebirr-payment');
            $this->method_description = __('Accept payments via Telebirr mobile money service from Ethio Telecom.', 'wc-telebirr-payment');

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title              = $this->get_option('title');
            $this->description        = $this->get_option('description');
            $this->enabled            = $this->get_option('enabled');
            $this->testmode           = 'yes' === $this->get_option('testmode');
            $this->fabric_app_id      = $this->testmode ? $this->get_option('test_fabric_app_id') : $this->get_option('fabric_app_id');
            $this->app_secret         = $this->testmode ? $this->get_option('test_app_secret') : $this->get_option('app_secret');
            $this->merchant_app_id    = $this->testmode ? $this->get_option('test_merchant_app_id') : $this->get_option('merchant_app_id');
            $this->merchant_code      = $this->testmode ? $this->get_option('test_merchant_code') : $this->get_option('merchant_code');
            $this->allowed_ips        = $this->get_option('allowed_ips', '');

            // Load private key: prefer constant > file path > database setting
            $this->private_key        = $this->load_private_key();
            $this->public_key         = $this->get_option('public_key');

            // Initialize logger
            $this->logger = wc_get_logger();

            // API URLs
            $this->base_url           = $this->testmode
                ? 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway'
                : 'https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway';
            
            $this->web_base_url       = $this->testmode
                ? 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?'
                : 'https://telebirrappcube.ethiomobilemoney.et:38443/payment/web/paygate?';

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_telebirr', array($this, 'receipt_page'));

            // Add webhook listener
            add_action('woocommerce_api_telebirr_callback', array($this, 'handle_callback'));
        }

        /**
         * Load RSA private key with priority: constant > file path > database
         *
         * @return string The PEM-formatted private key
         */
        private function load_private_key() {
            // Priority 1: PHP constant defined in wp-config.php (most secure)
            if (defined('TELEBIRR_PRIVATE_KEY') && !empty(TELEBIRR_PRIVATE_KEY)) {
                return TELEBIRR_PRIVATE_KEY;
            }

            // Priority 2: File path constant defined in wp-config.php
            if (defined('TELEBIRR_PRIVATE_KEY_PATH') && !empty(TELEBIRR_PRIVATE_KEY_PATH)) {
                $path = TELEBIRR_PRIVATE_KEY_PATH;
                if (file_exists($path) && is_readable($path)) {
                    $key = file_get_contents($path);
                    if ($key !== false) {
                        return trim($key);
                    }
                }
                if (isset($this->logger)) {
                    $this->logger->error('Cannot read private key file: ' . $path, array('source' => 'telebirr'));
                }
            }

            // Priority 3: File path from admin settings
            $key_path = $this->get_option('private_key_path', '');
            if (!empty($key_path) && file_exists($key_path) && is_readable($key_path)) {
                $key = file_get_contents($key_path);
                if ($key !== false) {
                    return trim($key);
                }
            }

            // Priority 4: Fallback to database setting (least secure)
            return $this->get_option('private_key', '');
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __('Enable/Disable', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable Telebirr Payment', 'wc-telebirr-payment'),
                    'default'     => 'no',
                ),
                'title' => array(
                    'title'       => __('Title', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc-telebirr-payment'),
                    'default'     => __('Telebirr', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'wc-telebirr-payment'),
                    'default'     => __('Pay securely using your Telebirr mobile money account.', 'wc-telebirr-payment'),
                ),
                'testmode' => array(
                    'title'       => __('Test mode', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable Test Mode', 'wc-telebirr-payment'),
                    'default'     => 'yes',
                    'description' => __('Place the payment gateway in test mode using test API credentials.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),
                'test_credentials' => array(
                    'title'       => __('Test Credentials', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Enter your test API credentials as provided by Telebirr.', 'wc-telebirr-payment'),
                ),
                'test_fabric_app_id' => array(
                    'title'       => __('Test Fabric App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your test Fabric Application ID.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'test_app_secret' => array(
                    'title'       => __('Test App Secret', 'wc-telebirr-payment'),
                    'type'        => 'password',
                    'description' => __('Your test App Secret key.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'test_merchant_app_id' => array(
                    'title'       => __('Test Merchant App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your test Merchant Application ID.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'test_merchant_code' => array(
                    'title'       => __('Test Merchant Code', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your test Merchant Code.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'live_credentials' => array(
                    'title'       => __('Live Credentials', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Enter your live production API credentials.', 'wc-telebirr-payment'),
                ),
                'fabric_app_id' => array(
                    'title'       => __('Live Fabric App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your live Fabric Application ID.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'app_secret' => array(
                    'title'       => __('Live App Secret', 'wc-telebirr-payment'),
                    'type'        => 'password',
                    'description' => __('Your live App Secret key.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'merchant_app_id' => array(
                    'title'       => __('Live Merchant App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your live Merchant Application ID.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'merchant_code' => array(
                    'title'       => __('Live Merchant Code', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your live Merchant Code.', 'wc-telebirr-payment'),
                    'default'     => '',
                ),
                'keys' => array(
                    'title'       => __('Security Keys', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __(
                        'RSA keys for request signing and verification. For maximum security, define TELEBIRR_PRIVATE_KEY or TELEBIRR_PRIVATE_KEY_PATH in wp-config.php instead of storing the private key here.',
                        'wc-telebirr-payment'
                    ),
                ),
                'private_key_path' => array(
                    'title'       => __('Private Key File Path', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Absolute server path to your RSA private key file (e.g., /etc/ssl/telebirr/private.pem). Preferred over pasting key below.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'private_key' => array(
                    'title'       => __('Private Key (Fallback)', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Your RSA private key (PEM format). Only used if no file path or constant is configured. WARNING: Stored in the database.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'css'         => 'font-family: monospace; height: 120px;',
                ),
                'public_key' => array(
                    'title'       => __('Telebirr Public Key', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Telebirr RSA public key for webhook verification.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'css'         => 'font-family: monospace; height: 120px;',
                ),
                'security' => array(
                    'title'       => __('Webhook Security', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Configure additional security for webhook callbacks.', 'wc-telebirr-payment'),
                ),
                'allowed_ips' => array(
                    'title'       => __('Allowed Webhook IPs', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Comma-separated list of IP addresses or CIDR ranges allowed to send webhook callbacks. Leave empty to allow all IPs (signature verification still applies).', 'wc-telebirr-payment'),
                    'default'     => '',
                    'css'         => 'height: 60px;',
                ),
            );
        }

        /**
         * Process the payment and return the result
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            
            try {
                // Get fabric token first (uses cache if available)
                $token = $this->get_fabric_token();
                
                if (!$token) {
                    throw new Exception(__('Failed to authenticate with Telebirr. Please try again later.', 'wc-telebirr-payment'));
                }

                // Create order in Telebirr
                $prepay_id = $this->create_telebirr_order($order, $token);
                
                if (!$prepay_id) {
                    throw new Exception(__('Failed to create payment order. Please try again.', 'wc-telebirr-payment'));
                }

                // Store prepay_id with order (HPOS-compatible)
                $order->update_meta_data('_telebirr_prepay_id', $prepay_id);
                $order->save();

                // Generate checkout URL
                $checkout_url = $this->build_checkout_url($prepay_id);

                // Mark order as pending payment
                $order->update_status('pending', __('Awaiting Telebirr payment confirmation', 'wc-telebirr-payment'));

                $this->logger->info(
                    sprintf('Payment initiated for order #%d, prepay_id: %s', $order_id, $prepay_id),
                    array('source' => 'telebirr')
                );

                // Return success and redirect
                return array(
                    'result'   => 'success',
                    'redirect' => $checkout_url
                );

            } catch (Exception $e) {
                // Log full error details for debugging
                $this->logger->error(
                    sprintf('Payment processing failed for order #%d: %s', $order_id, $e->getMessage()),
                    array('source' => 'telebirr')
                );

                // Show generic message to customer (do not leak internal details)
                wc_add_notice(
                    __('Payment could not be processed. Please try again or contact support.', 'wc-telebirr-payment'),
                    'error'
                );
                return array(
                    'result'   => 'fail',
                    'redirect' => ''
                );
            }
        }

        /**
         * Get Fabric authentication token with transient caching
         *
         * @return string|false The authentication token or false on failure
         */
        private function get_fabric_token() {
            // Check for cached token
            $transient_key = 'telebirr_fabric_token_' . ($this->testmode ? 'test' : 'live');
            $cached_token = get_transient($transient_key);

            if ($cached_token !== false) {
                return $cached_token;
            }

            $url = $this->base_url . '/payment/v1/token';
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-APP-Key'    => $this->fabric_app_id,
                ),
                'body' => wp_json_encode(array(
                    'appSecret' => $this->app_secret,
                )),
                'sslverify' => true,
                'timeout'   => 30,
            ));

            if (is_wp_error($response)) {
                $this->logger->error(
                    'Fabric token request failed: ' . $response->get_error_message(),
                    array('source' => 'telebirr')
                );
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code !== 200) {
                $this->logger->error(
                    sprintf('Fabric token request returned HTTP %d', $status_code),
                    array('source' => 'telebirr')
                );
                return false;
            }
            
            if (isset($body['token']) && !empty($body['token'])) {
                // Cache token for 25 minutes (tokens typically expire in 30 min)
                set_transient($transient_key, $body['token'], 25 * MINUTE_IN_SECONDS);
                return $body['token'];
            }

            $this->logger->error(
                'Fabric token response missing token field',
                array('source' => 'telebirr')
            );
            return false;
        }

        /**
         * Create order in Telebirr system
         *
         * @param WC_Order $order The WooCommerce order
         * @param string $token The Fabric authentication token
         * @return string|false The prepay_id or false on failure
         */
        private function create_telebirr_order($order, $token) {
            $url = $this->base_url . '/payment/v1/merchant/preOrder';
            
            $timestamp = (string) time();
            $nonce = $this->generate_nonce();
            
            $order_id = $order->get_id();
            $amount = number_format($order->get_total(), 2, '.', '');
            
            $biz_content = array(
                'notify_url'      => home_url('/wc-api/telebirr_callback'),
                'appid'           => $this->merchant_app_id,
                'merch_code'      => $this->merchant_code,
                'merch_order_id'  => (string) $order_id,
                'trade_type'      => 'Checkout',
                'title'           => sprintf(__('Order #%s', 'wc-telebirr-payment'), $order_id),
                'total_amount'    => $amount,
                'trans_currency'  => 'ETB',
                'timeout_express' => '120m',
                'redirect_url'    => $this->get_return_url($order),
            );

            $request = array(
                'timestamp'   => $timestamp,
                'nonce_str'   => $nonce,
                'method'      => 'payment.preorder',
                'version'     => '1.0',
                'biz_content' => $biz_content,
            );

            // Sign the request
            $signature = $this->sign_request($request);
            $request['sign'] = $signature;
            $request['sign_type'] = 'SHA256WithRSA';

            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'X-APP-Key'     => $this->fabric_app_id,
                    'Authorization' => $token,
                ),
                'body'      => wp_json_encode($request),
                'sslverify' => true,
                'timeout'   => 30,
            ));

            if (is_wp_error($response)) {
                $this->logger->error(
                    sprintf('Telebirr preOrder request failed for order #%d: %s', $order_id, $response->get_error_message()),
                    array('source' => 'telebirr')
                );
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code !== 200) {
                $this->logger->error(
                    sprintf('Telebirr preOrder returned HTTP %d for order #%d', $status_code, $order_id),
                    array('source' => 'telebirr')
                );
                return false;
            }
            
            if (isset($body['biz_content']['prepay_id']) && !empty($body['biz_content']['prepay_id'])) {
                return $body['biz_content']['prepay_id'];
            }

            $this->logger->error(
                sprintf('Telebirr preOrder response missing prepay_id for order #%d: %s', $order_id, wp_json_encode($body)),
                array('source' => 'telebirr')
            );
            return false;
        }

        /**
         * Build checkout redirect URL
         *
         * @param string $prepay_id The Telebirr prepay ID
         * @return string The full checkout URL
         */
        private function build_checkout_url($prepay_id) {
            $params = array(
                'appid'      => $this->merchant_app_id,
                'merch_code' => $this->merchant_code,
                'nonce_str'  => $this->generate_nonce(),
                'prepay_id'  => $prepay_id,
                'timestamp'  => (string) time(),
            );

            $signature = $this->sign_request($params);
            $params['sign'] = $signature;
            $params['sign_type'] = 'SHA256WithRSA';
            $params['version'] = '1.0';
            $params['trade_type'] = 'Checkout';

            return $this->web_base_url . http_build_query($params);
        }

        /**
         * Generate cryptographically secure random nonce string
         *
         * @return string 32-character hex nonce
         */
        private function generate_nonce() {
            return bin2hex(random_bytes(16));
        }

        /**
         * Sign request using RSA-SHA256
         * Handles both flat and nested (biz_content) parameter structures.
         *
         * @param array $data The request data to sign
         * @return string Base64-encoded signature, or empty string on failure
         */
        private function sign_request($data) {
            // Flatten nested arrays to JSON strings for signing
            $flat_data = array();
            foreach ($data as $key => $value) {
                if ($key === 'sign' || $key === 'sign_type' || $value === '') {
                    continue;
                }
                if (is_array($value)) {
                    $flat_data[$key] = wp_json_encode($value);
                } else {
                    $flat_data[$key] = $value;
                }
            }

            // Sort parameters alphabetically
            ksort($flat_data);
            
            // Build signing string
            $sign_parts = array();
            foreach ($flat_data as $key => $value) {
                $sign_parts[] = $key . '=' . $value;
            }
            $sign_string = implode('&', $sign_parts);

            // Load private key
            $private_key = openssl_pkey_get_private($this->private_key);
            
            if (!$private_key) {
                $this->logger->error(
                    'Failed to load RSA private key for signing',
                    array('source' => 'telebirr')
                );
                return '';
            }

            // Sign with RSA-SHA256
            $result = openssl_sign($sign_string, $signature, $private_key, OPENSSL_ALGO_SHA256);

            if (!$result) {
                $this->logger->error(
                    'RSA signing operation failed: ' . openssl_error_string(),
                    array('source' => 'telebirr')
                );
                return '';
            }

            return base64_encode($signature);
        }

        /**
         * Check if the requesting IP is in the allowed list
         *
         * @return bool True if allowed or no restrictions configured
         */
        private function is_ip_allowed() {
            if (empty($this->allowed_ips)) {
                return true; // No IP restriction configured
            }

            $remote_ip = $this->get_client_ip();
            if (empty($remote_ip)) {
                return false;
            }

            $allowed = array_map('trim', explode(',', $this->allowed_ips));
            
            foreach ($allowed as $allowed_entry) {
                // Check for CIDR notation
                if (strpos($allowed_entry, '/') !== false) {
                    if ($this->ip_in_cidr($remote_ip, $allowed_entry)) {
                        return true;
                    }
                } elseif ($remote_ip === $allowed_entry) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Get the client IP address
         *
         * @return string The client IP
         */
        private function get_client_ip() {
            // Check for trusted proxy headers (only if behind a known proxy)
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
                return trim($ips[0]);
            }
            
            if (!empty($_SERVER['REMOTE_ADDR'])) {
                return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            }

            return '';
        }

        /**
         * Check if an IP is within a CIDR range
         *
         * @param string $ip The IP address to check
         * @param string $cidr The CIDR range (e.g. 192.168.1.0/24)
         * @return bool
         */
        private function ip_in_cidr($ip, $cidr) {
            list($subnet, $mask) = explode('/', $cidr, 2);
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - intval($mask));

            if ($ip_long === false || $subnet_long === false) {
                return false;
            }

            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }

        /**
         * Check if a callback nonce/transaction has already been processed (replay protection)
         *
         * @param string $transaction_id The Telebirr transaction ID
         * @param int $order_id The WooCommerce order ID
         * @return bool True if this is a duplicate/replay
         */
        private function is_duplicate_callback($transaction_id, $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return false;
            }

            $stored_txn = $order->get_meta('_telebirr_transaction_id', true);
            if (!empty($stored_txn) && $stored_txn === $transaction_id && $order->is_paid()) {
                return true;
            }

            return false;
        }

        /**
         * Handle Telebirr webhook callback
         * Security: validates HTTP method, Content-Type, IP, signature, amount, and order ownership.
         */
        public function handle_callback() {
            $this->logger->info('Telebirr callback received', array('source' => 'telebirr'));

            // Validate HTTP method - only accept POST
            if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->logger->warning(
                    'Callback rejected: invalid HTTP method ' . (isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'UNKNOWN'),
                    array('source' => 'telebirr')
                );
                status_header(405);
                header('Allow: POST');
                exit('Method not allowed');
            }

            // Validate Content-Type
            $content_type = isset($_SERVER['CONTENT_TYPE']) ? sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE'])) : '';
            if (strpos($content_type, 'application/json') === false) {
                $this->logger->warning(
                    'Callback rejected: invalid Content-Type: ' . $content_type,
                    array('source' => 'telebirr')
                );
                status_header(415);
                exit('Unsupported media type');
            }

            // IP whitelisting check
            if (!$this->is_ip_allowed()) {
                $client_ip = $this->get_client_ip();
                $this->logger->warning(
                    'Callback rejected: IP not allowed: ' . $client_ip,
                    array('source' => 'telebirr')
                );
                status_header(403);
                exit('Forbidden');
            }

            // Rate limiting using transients (simple per-IP throttle)
            $client_ip = $this->get_client_ip();
            $rate_key = 'telebirr_rate_' . md5($client_ip);
            $request_count = (int) get_transient($rate_key);
            if ($request_count > 60) { // Max 60 requests per minute per IP
                $this->logger->warning(
                    'Callback rate-limited for IP: ' . $client_ip,
                    array('source' => 'telebirr')
                );
                status_header(429);
                exit('Too many requests');
            }
            set_transient($rate_key, $request_count + 1, MINUTE_IN_SECONDS);

            // Get raw POST data
            $raw_data = file_get_contents('php://input');
            $data = json_decode($raw_data, true);

            if (!$data || !isset($data['biz_content'])) {
                $this->logger->warning(
                    'Callback rejected: invalid or missing biz_content in payload',
                    array('source' => 'telebirr')
                );
                status_header(400);
                exit('Invalid request');
            }

            $biz = $data['biz_content'];
            
            // Verify RSA signature
            if (!$this->verify_signature($data)) {
                $this->logger->error(
                    'Callback rejected: signature verification failed',
                    array('source' => 'telebirr')
                );
                status_header(403);
                exit('Invalid signature');
            }

            // Get and validate order
            $order_id = isset($biz['merch_order_id']) ? intval($biz['merch_order_id']) : 0;
            $order = wc_get_order($order_id);

            if (!$order) {
                $this->logger->error(
                    'Callback rejected: order not found for ID: ' . $order_id,
                    array('source' => 'telebirr')
                );
                status_header(404);
                exit('Order not found');
            }

            // Verify order belongs to Telebirr payment gateway
            if ($order->get_payment_method() !== $this->id) {
                $this->logger->error(
                    sprintf(
                        'Callback rejected: order #%d payment method is "%s", not telebirr',
                        $order_id,
                        $order->get_payment_method()
                    ),
                    array('source' => 'telebirr')
                );
                status_header(400);
                exit('Payment method mismatch');
            }

            // Replay protection: check if this transaction was already processed
            $transaction_id = isset($biz['trade_no']) ? sanitize_text_field($biz['trade_no']) : '';
            if (!empty($transaction_id) && $this->is_duplicate_callback($transaction_id, $order_id)) {
                $this->logger->info(
                    sprintf('Duplicate callback ignored for order #%d, txn: %s', $order_id, $transaction_id),
                    array('source' => 'telebirr')
                );
                echo wp_json_encode(array('code' => '00000', 'message' => 'success'));
                exit;
            }

            // Check payment status
            $status = isset($biz['trade_status']) ? intval($biz['trade_status']) : 0;

            switch ($status) {
                case 2: // Payment successful
                    if (!$order->is_paid()) {
                        // Verify payment amount matches order total
                        $paid_amount = isset($biz['total_amount']) ? floatval($biz['total_amount']) : 0;
                        $order_total = floatval($order->get_total());

                        if (abs($paid_amount - $order_total) > 0.01) {
                            $this->logger->error(
                                sprintf(
                                    'Amount mismatch for order #%d: paid=%.2f expected=%.2f',
                                    $order_id,
                                    $paid_amount,
                                    $order_total
                                ),
                                array('source' => 'telebirr')
                            );
                            $order->update_status(
                                'on-hold',
                                sprintf(
                                    __('Telebirr amount mismatch: paid %.2f ETB, expected %.2f ETB. Manual review required.', 'wc-telebirr-payment'),
                                    $paid_amount,
                                    $order_total
                                )
                            );
                            break;
                        }

                        $order->payment_complete($transaction_id);
                        $order->add_order_note(
                            sprintf(
                                __('Telebirr payment completed. Transaction ID: %s, Amount: %.2f ETB', 'wc-telebirr-payment'),
                                $transaction_id,
                                $paid_amount
                            )
                        );
                        $order->update_meta_data('_telebirr_transaction_id', $transaction_id);
                        $order->save();

                        $this->logger->info(
                            sprintf('Payment completed for order #%d, txn: %s, amount: %.2f', $order_id, $transaction_id, $paid_amount),
                            array('source' => 'telebirr')
                        );
                    }
                    break;
                    
                case 1: // Payment pending
                    $order->update_status('pending', __('Telebirr payment pending', 'wc-telebirr-payment'));
                    $this->logger->info(
                        sprintf('Payment pending for order #%d', $order_id),
                        array('source' => 'telebirr')
                    );
                    break;
                    
                case 3: // Payment failed
                    $order->update_status('failed', __('Telebirr payment failed', 'wc-telebirr-payment'));
                    $this->logger->info(
                        sprintf('Payment failed for order #%d', $order_id),
                        array('source' => 'telebirr')
                    );
                    break;
                    
                case 4: // Payment cancelled
                    $order->update_status('cancelled', __('Telebirr payment cancelled by user', 'wc-telebirr-payment'));
                    $this->logger->info(
                        sprintf('Payment cancelled for order #%d', $order_id),
                        array('source' => 'telebirr')
                    );
                    break;

                default:
                    $this->logger->warning(
                        sprintf('Unknown trade_status %d for order #%d', $status, $order_id),
                        array('source' => 'telebirr')
                    );
                    break;
            }

            // Return success response to Telebirr
            header('Content-Type: application/json');
            echo wp_json_encode(array('code' => '00000', 'message' => 'success'));
            exit;
        }

        /**
         * Verify webhook signature from Telebirr
         * Properly handles nested biz_content by JSON-encoding it for the signing string.
         *
         * @param array $data The full webhook payload
         * @return bool True if signature is valid
         */
        private function verify_signature($data) {
            if (!isset($data['sign'])) {
                $this->logger->warning('Signature verification failed: no sign field in payload', array('source' => 'telebirr'));
                return false;
            }

            $received_sign = base64_decode($data['sign']);
            if ($received_sign === false) {
                $this->logger->warning('Signature verification failed: invalid base64 in sign field', array('source' => 'telebirr'));
                return false;
            }

            // Remove sign and sign_type from data before building verification string
            $verify_data = $data;
            unset($verify_data['sign']);
            unset($verify_data['sign_type']);

            // Flatten nested arrays to JSON strings (critical: includes biz_content in verification)
            $flat_data = array();
            foreach ($verify_data as $key => $value) {
                if ($value === '') {
                    continue;
                }
                if (is_array($value)) {
                    $flat_data[$key] = wp_json_encode($value);
                } else {
                    $flat_data[$key] = $value;
                }
            }

            // Sort parameters alphabetically
            ksort($flat_data);
            
            // Build signing string
            $sign_parts = array();
            foreach ($flat_data as $key => $value) {
                $sign_parts[] = $key . '=' . $value;
            }
            $sign_string = implode('&', $sign_parts);

            // Load public key
            $public_key = openssl_pkey_get_public($this->public_key);
            
            if (!$public_key) {
                $this->logger->error(
                    'Signature verification failed: cannot load Telebirr public key',
                    array('source' => 'telebirr')
                );
                return false;
            }

            $result = openssl_verify($sign_string, $received_sign, $public_key, OPENSSL_ALGO_SHA256);

            if ($result === -1) {
                $this->logger->error(
                    'OpenSSL verify error: ' . openssl_error_string(),
                    array('source' => 'telebirr')
                );
                return false;
            }

            return $result === 1;
        }

        /**
         * Receipt page
         *
         * @param int $order_id The WooCommerce order ID
         */
        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return;
            }

            echo '<p>' . esc_html__('Thank you for your order. You will be redirected to Telebirr to complete your payment.', 'wc-telebirr-payment') . '</p>';
            
            // Auto redirect if we have the checkout URL (HPOS-compatible)
            $prepay_id = $order->get_meta('_telebirr_prepay_id', true);
            if ($prepay_id) {
                $checkout_url = $this->build_checkout_url($prepay_id);
                echo '<script>window.location.href = "' . esc_url($checkout_url) . '";</script>';
            }
        }
    }
}

/**
 * Add Telebirr Gateway to WooCommerce
 *
 * @param array $gateways Existing gateway classes
 * @return array Modified gateway classes
 */
function wc_telebirr_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Telebirr';
    return $gateways;
}

/**
 * Add plugin action links
 *
 * @param array $links Existing plugin links
 * @return array Modified plugin links
 */
function wc_telebirr_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=telebirr')) . '">' . esc_html__('Settings', 'wc-telebirr-payment') . '</a>',
    );
    
    return array_merge($plugin_links, $links);
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

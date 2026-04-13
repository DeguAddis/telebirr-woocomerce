<?php
/**
 * Plugin Name: WooCommerce Telebirr Payment Gateway
 * Plugin URI: https://github.com/yourusername/wc-telebirr-payment
 * Description: Telebirr mobile money payment gateway integration for WooCommerce
 * Version: 1.0.5
 * Author: Degu Addis 
 * Author URI: https://blackpixel.et
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
            $this->private_key        = $this->get_option('private_key');
            $this->public_key         = $this->get_option('public_key');

            // API URLs
            $this->base_url           = $this->testmode
                ? 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway'
                : 'https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway';
            
            $this->web_base_url       = $this->testmode
                ? 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?'
                : 'https://telebirrappcube.ethiomobilemoney.et:38443/payment/web/paygate?';

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_telebirr', array($this, 'handle_webhook'));
            add_action('woocommerce_receipt_telebirr', array($this, 'receipt_page'));

            // Add webhook listener
            add_action('woocommerce_api_telebirr_callback', array($this, 'handle_callback'));
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
                    'description' => __('RSA keys for request signing and verification.', 'wc-telebirr-payment'),
                ),
                'private_key' => array(
                    'title'       => __('Private Key', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Your RSA private key (PEM format).', 'wc-telebirr-payment'),
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
            );
        }

        /**
         * Process the payment and return the result
         */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            
            try {
                // Get fabric token first
                $token = $this->get_fabric_token();
                
                if (!$token) {
                    throw new Exception(__('Failed to authenticate with Telebirr. Please try again later.', 'wc-telebirr-payment'));
                }

                // Create order in Telebirr
                $prepay_id = $this->create_telebirr_order($order, $token);
                
                if (!$prepay_id) {
                    throw new Exception(__('Failed to create payment order. Please try again.', 'wc-telebirr-payment'));
                }

                // Store prepay_id with order
                update_post_meta($order_id, '_telebirr_prepay_id', $prepay_id);

                // Generate checkout URL
                $checkout_url = $this->build_checkout_url($prepay_id);

                // Mark order as pending payment
                $order->update_status('pending', __('Awaiting Telebirr payment confirmation', 'wc-telebirr-payment'));

                // Return success and redirect
                return array(
                    'result'   => 'success',
                    'redirect' => $checkout_url
                );

            } catch (Exception $e) {
                wc_add_notice(__('Payment error: ', 'wc-telebirr-payment') . $e->getMessage(), 'error');
                return array(
                    'result'   => 'fail',
                    'redirect' => ''
                );
            }
        }

        /**
         * Get Fabric authentication token
         */
        private function get_fabric_token() {
            $url = $this->base_url . '/payment/v1/token';
            
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-APP-Key'    => $this->fabric_app_id,
                ),
                'body' => json_encode(array(
                    'appSecret' => $this->app_secret,
                )),
                'sslverify' => false,
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                return false;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['token']) && !empty($body['token'])) {
                return $body['token'];
            }

            return false;
        }

        /**
         * Create order in Telebirr system
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
                'timestamp' => $timestamp,
                'nonce_str' => $nonce,
                'method'    => 'payment.preorder',
                'version'   => '1.0',
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
                'body' => json_encode($request),
                'sslverify' => false,
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                return false;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['biz_content']['prepay_id']) && !empty($body['biz_content']['prepay_id'])) {
                return $body['biz_content']['prepay_id'];
            }

            return false;
        }

        /**
         * Build checkout redirect URL
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
         * Generate random nonce string
         */
        private function generate_nonce() {
            return bin2hex(random_bytes(16));
        }

        /**
         * Sign request using RSA-SHA256
         */
        private function sign_request($data) {
            // Sort parameters alphabetically
            ksort($data);
            
            // Build signing string
            $sign_string = '';
            foreach ($data as $key => $value) {
                if ($key === 'sign' || $key === 'sign_type' || $value === '' || is_array($value)) {
                    continue;
                }
                $sign_string .= $key . '=' . $value . '&';
            }
            $sign_string = rtrim($sign_string, '&');

            // Load private key
            $private_key = openssl_pkey_get_private($this->private_key);
            
            if (!$private_key) {
                return '';
            }

            // Sign with RSA-PSS SHA256
            openssl_sign($sign_string, $signature, $private_key, OPENSSL_ALGO_SHA256);
            openssl_free_key($private_key);

            return base64_encode($signature);
        }

        /**
         * Handle Telebirr webhook callback
         */
        public function handle_callback() {
            // Get raw POST data
            $raw_data = file_get_contents('php://input');
            $data = json_decode($raw_data, true);

            if (!$data || !isset($data['biz_content'])) {
                status_header(400);
                exit('Invalid request');
            }

            $biz = $data['biz_content'];
            
            // Verify signature
            if (!$this->verify_signature($data)) {
                status_header(403);
                exit('Invalid signature');
            }

            // Get order
            $order_id = isset($biz['merch_order_id']) ? intval($biz['merch_order_id']) : 0;
            $order = wc_get_order($order_id);

            if (!$order) {
                status_header(404);
                exit('Order not found');
            }

            // Check payment status
            $status = isset($biz['trade_status']) ? intval($biz['trade_status']) : 0;

            switch ($status) {
                case 2: // Payment successful
                    if (!$order->is_paid()) {
                        $order->payment_complete();
                        $order->add_order_note(__('Telebirr payment completed successfully', 'wc-telebirr-payment'));
                        update_post_meta($order_id, '_telebirr_transaction_id', $biz['trade_no']);
                    }
                    break;
                    
                case 1: // Payment pending
                    $order->update_status('pending', __('Telebirr payment pending', 'wc-telebirr-payment'));
                    break;
                    
                case 3: // Payment failed
                    $order->update_status('failed', __('Telebirr payment failed', 'wc-telebirr-payment'));
                    break;
                    
                case 4: // Payment cancelled
                    $order->update_status('cancelled', __('Telebirr payment cancelled by user', 'wc-telebirr-payment'));
                    break;
            }

            // Return success response to Telebirr
            echo json_encode(array('code' => '00000', 'message' => 'success'));
            exit;
        }

        /**
         * Verify webhook signature
         */
        private function verify_signature($data) {
            if (!isset($data['sign'])) {
                return false;
            }

            $received_sign = base64_decode($data['sign']);
            unset($data['sign']);
            unset($data['sign_type']);

            // Sort parameters
            ksort($data);
            
            // Build signing string
            $sign_string = '';
            foreach ($data as $key => $value) {
                if ($value === '' || is_array($value)) {
                    continue;
                }
                $sign_string .= $key . '=' . $value . '&';
            }
            $sign_string = rtrim($sign_string, '&');

            // Load public key
            $public_key = openssl_pkey_get_public($this->public_key);
            
            if (!$public_key) {
                return false;
            }

            $result = openssl_verify($sign_string, $received_sign, $public_key, OPENSSL_ALGO_SHA256);
            openssl_free_key($public_key);

            return $result === 1;
        }

        /**
         * Receipt page
         */
        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            
            echo '<p>' . __('Thank you for your order. You will be redirected to Telebirr to complete your payment.', 'wc-telebirr-payment') . '</p>';
            
            // Auto redirect if we have the checkout URL
            $prepay_id = get_post_meta($order_id, '_telebirr_prepay_id', true);
            if ($prepay_id) {
                $checkout_url = $this->build_checkout_url($prepay_id);
                echo '<script>window.location.href = "' . esc_url($checkout_url) . '";</script>';
            }
        }
    }
}

/**
 * Add Telebirr Gateway to WooCommerce
 */
function wc_telebirr_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Telebirr';
    return $gateways;
}

/**
 * Add plugin action links
 */
function wc_telebirr_plugin_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=telebirr') . '">' . __('Settings', 'wc-telebirr-payment') . '</a>',
    );
    
    return array_merge($plugin_links, $links);
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

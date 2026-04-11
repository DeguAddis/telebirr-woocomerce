<?php
/**
 * Plugin Name: WooCommerce Telebirr Payment Gateway
 * Plugin URI: https://github.com/yourusername/wc-telebirr-payment
 * Description: Telebirr mobile money payment gateway integration for WooCommerce
 * Version: 1.0.0
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

        /**
         * Constructor for the gateway
         */
        public function __construct() {
            $this->id                 = 'telebirr';
            $this->icon               = apply_filters('woocommerce_telebirr_icon', plugins_url('assets/telebirr-logo.png', __FILE__));
            $this->has_fields         = true;
            $this->method_title       = __('Telebirr', 'wc-telebirr-payment');
            $this->method_description = __('Accept payments via Telebirr mobile money service from Ethio Telecom.', 'wc-telebirr-payment');

            // Load checkout display settings
            $this->hide_billing_fields = 'yes' === $this->get_option('hide_billing_fields', 'yes');
            $this->show_order_total    = 'yes' === $this->get_option('show_order_total', 'yes');

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
             
             // Direct phone number payment settings
             $this->enable_direct_phone = 'yes' === $this->get_option('enable_direct_phone');
             $this->direct_phone_number = $this->get_option('direct_phone_number');

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
         * Display payment method fields on checkout
         */
        public function payment_fields() {
            echo '<div class="wc-telebirr-payment-box">';

            // Display description
            echo wpautop(wptexturize($this->description));

            // Show order total if enabled
            if ($this->show_order_total && WC()->cart) {
                $total = WC()->cart->get_total();
                echo '<p class="wc-telebirr-total"><strong>';
                printf(__('Order Total: %s', 'wc-telebirr-payment'), $total);
                echo '</strong></p>';
            }

            // Display payment instructions
            echo '<div class="wc-telebirr-instructions">';
            echo '<p class="form-row form-row-wide">';
            echo '<span class="woocommerce-info">';
            echo esc_html__('After placing your order you will be redirected to the secure Telebirr payment page to complete your transaction.', 'wc-telebirr-payment');
            echo '</span>';
            echo '</p>';
            echo '</div>';

            echo '</div>';
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = array(

                'general_settings_section' => array(
                    'title'       => __('General Settings', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Configure basic gateway settings and display options.', 'wc-telebirr-payment'),
                ),

                'enabled' => array(
                    'title'       => __('Enable/Disable', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable Telebirr Payment Gateway', 'wc-telebirr-payment'),
                    'default'     => 'no',
                    'description' => __('Check this box to enable Telebirr payment method on your store.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),

                'title' => array(
                    'title'       => __('Method Title', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Payment method title displayed to customers during checkout.', 'wc-telebirr-payment'),
                    'default'     => __('Telebirr', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                    'placeholder' => __('Telebirr', 'wc-telebirr-payment'),
                ),

                'description' => array(
                    'title'       => __('Method Description', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description displayed to customers during checkout.', 'wc-telebirr-payment'),
                    'default'     => __('Pay securely using your Telebirr mobile money account. You will be redirected to Telebirr payment page to complete your transaction.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                    'css'         => 'width: 400px; height: 80px;',
                ),

                'testmode' => array(
                    'title'       => __('Test Mode', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable Test Mode', 'wc-telebirr-payment'),
                    'default'     => 'yes',
                    'description' => __('Test mode uses sandbox environment. Disable for live production transactions.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),

                'checkout_display_section' => array(
                    'title'       => __('Checkout Display Options', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Configure how this payment method appears during checkout.', 'wc-telebirr-payment'),
                ),

                'hide_billing_fields' => array(
                    'title'       => __('Simplify Checkout', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Hide billing fields when Telebirr is selected', 'wc-telebirr-payment'),
                    'default'     => 'yes',
                    'description' => __('Automatically hide billing address fields when Telebirr is chosen as payment method for faster checkout.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),

                'show_order_total' => array(
                    'title'       => __('Show Order Total', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Display order total in payment description', 'wc-telebirr-payment'),
                    'default'     => 'yes',
                    'description' => __('Show the order amount in the payment method description during checkout.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),

                'api_credentials_section' => array(
                    'title'       => __('API Credentials', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Configure your Telebirr API credentials obtained from Telebirr developer portal.', 'wc-telebirr-payment'),
                ),

                'test_credentials' => array(
                    'title'       => __('Test Credentials', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Enter your sandbox test API credentials below. These are only used when Test Mode is enabled.', 'wc-telebirr-payment'),
                ),

                'test_fabric_app_id' => array(
                    'title'       => __('Test Fabric App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your Fabric Application ID from Telebirr sandbox portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter Fabric App ID', 'wc-telebirr-payment'),
                ),

                'test_app_secret' => array(
                    'title'       => __('Test App Secret', 'wc-telebirr-payment'),
                    'type'        => 'password',
                    'description' => __('Your Fabric App Secret key from Telebirr sandbox portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter App Secret', 'wc-telebirr-payment'),
                ),

                'test_merchant_app_id' => array(
                    'title'       => __('Test Merchant App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your Merchant Application ID from Telebirr sandbox portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter Merchant App ID', 'wc-telebirr-payment'),
                ),

                'test_merchant_code' => array(
                    'title'       => __('Test Merchant Code', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your Merchant Code from Telebirr sandbox portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter Merchant Code', 'wc-telebirr-payment'),
                ),

                'live_credentials' => array(
                    'title'       => __('Live Production Credentials', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Enter your production API credentials below. These are used when Test Mode is disabled.', 'wc-telebirr-payment'),
                ),

                'fabric_app_id' => array(
                    'title'       => __('Live Fabric App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your Fabric Application ID from Telebirr production portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter Fabric App ID', 'wc-telebirr-payment'),
                ),

                'app_secret' => array(
                    'title'       => __('Live App Secret', 'wc-telebirr-payment'),
                    'type'        => 'password',
                    'description' => __('Your Fabric App Secret key from Telebirr production portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter App Secret', 'wc-telebirr-payment'),
                ),

                'merchant_app_id' => array(
                    'title'       => __('Live Merchant App ID', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your Merchant Application ID from Telebirr production portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter Merchant App ID', 'wc-telebirr-payment'),
                ),

                'merchant_code' => array(
                    'title'       => __('Live Merchant Code', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Your Merchant Code from Telebirr production portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __('Enter Merchant Code', 'wc-telebirr-payment'),
                ),

                'security_section' => array(
                    'title'       => __('Security Settings', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('RSA key configuration for request signing and webhook verification.', 'wc-telebirr-payment'),
                ),

                'private_key' => array(
                    'title'       => __('Merchant Private Key', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Your RSA private key (PEM format) used for signing payment requests. Generate this in your Telebirr merchant portal.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'css'         => 'font-family: monospace; height: 140px; width: 500px;',
                    'desc_tip'    => true,
                    'placeholder' => "-----BEGIN RSA PRIVATE KEY-----\n... your private key here ...\n-----END RSA PRIVATE KEY-----",
                ),

                'public_key' => array(
                    'title'       => __('Telebirr Public Key', 'wc-telebirr-payment'),
                    'type'        => 'textarea',
                    'description' => __('Telebirr RSA public key (PEM format) used for verifying incoming webhook signatures. This is provided by Telebirr.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'css'         => 'font-family: monospace; height: 140px; width: 500px;',
                    'desc_tip'    => true,
                    'placeholder' => "-----BEGIN PUBLIC KEY-----\n... public key content here ...\n-----END PUBLIC KEY-----",
                ),

                'webhook_section' => array(
                    'title'       => __('Webhook Configuration', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Callback URL configuration for payment status notifications.', 'wc-telebirr-payment'),
                ),

                'webhook_info' => array(
                    'title'       => __('Webhook Callback URL', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Copy this URL and paste it into your Telebirr merchant portal as the webhook notification endpoint.', 'wc-telebirr-payment'),
                    'default'     => home_url( '/wc-api/telebirr_callback' ),
                    'custom_attributes' => array(
                        'readonly' => 'readonly',
                        'onclick'  => 'this.select();',
                    ),
                    'desc_tip'    => true,
                ),

                'direct_payment_section' => array(
                    'title'       => __('Direct Phone Payment', 'wc-telebirr-payment'),
                    'type'        => 'title',
                    'description' => __('Optional configuration for direct payments to a specific phone number instead of merchant account.', 'wc-telebirr-payment'),
                ),

                'enable_direct_phone' => array(
                    'title'       => __('Enable Direct Payment', 'wc-telebirr-payment'),
                    'type'        => 'checkbox',
                    'label'       => __('Route payments directly to phone number', 'wc-telebirr-payment'),
                    'default'     => 'no',
                    'description' => __('When enabled, all payments will be transferred directly to the configured phone number instead of being deposited to your merchant account balance.', 'wc-telebirr-payment'),
                    'desc_tip'    => true,
                ),

                'direct_phone_number' => array(
                    'title'       => __('Destination Phone Number', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('Telebirr phone number where payments should be sent. Must be in international format without plus sign.', 'wc-telebirr-payment'),
                    'default'     => '',
                    'placeholder' => '251912345678',
                    'desc_tip'    => true,
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
             
             // Add direct phone number payment if enabled
             if ($this->enable_direct_phone && !empty($this->direct_phone_number)) {
                 $biz_content['payment_mode'] = 'DIRECT_TO_PHONE';
                 $biz_content['receiver_msisdn'] = $this->direct_phone_number;
             }

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

/**
 * Add admin notices
 */
add_action('admin_notices', 'wc_telebirr_admin_notices');
function wc_telebirr_admin_notices() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $screen = get_current_screen();
    if ($screen->id !== 'woocommerce_page_wc-settings' || !isset($_GET['section']) || $_GET['section'] !== 'telebirr') {
        return;
    }

    $gateway = WC()->payment_gateways()->payment_gateways()['telebirr'];

    // Test mode notice
    if ($gateway->testmode) {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>' . esc_html__('Telebirr Test Mode Active', 'wc-telebirr-payment') . '</strong> &ndash; ';
        echo esc_html__('The payment gateway is currently operating in test mode. No live transactions will be processed.', 'wc-telebirr-payment');
        echo '</p></div>';
    }

    // Missing credentials notice
    if (empty($gateway->fabric_app_id) || empty($gateway->app_secret)) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('Telebirr Configuration Required', 'wc-telebirr-payment') . '</strong> &ndash; ';
        echo esc_html__('Please enter your API credentials to enable payment processing.', 'wc-telebirr-payment');
        echo '</p></div>';
    }
}

/**
 * Disable billing fields when Telebirr is selected payment method
 */
add_filter('woocommerce_checkout_fields', 'wc_telebirr_disable_billing_fields', 999, 1);
function wc_telebirr_disable_billing_fields($fields) {
    if (isset(WC()->session) && WC()->session->get('chosen_payment_method') === 'telebirr') {
        $gateway = WC()->payment_gateways()->payment_gateways()['telebirr'];
        
        if (isset($gateway) && $gateway->hide_billing_fields) {
            // Keep only email required for order notifications
            $optional_fields = array(
                'billing_first_name',
                'billing_last_name',
                'billing_company',
                'billing_address_1',
                'billing_address_2',
                'billing_city',
                'billing_postcode',
                'billing_state',
                'billing_phone',
                'billing_country'
            );
            
            foreach ($optional_fields as $field) {
                if (isset($fields['billing'][$field])) {
                    $fields['billing'][$field]['required'] = false;
                    $fields['billing'][$field]['class'][] = 'hidden-if-telebirr';
                }
            }
        }
    }
    return $fields;
}

/**
 * Enqueue checkout scripts and styles
 */
add_action('wp_enqueue_scripts', 'wc_telebirr_enqueue_checkout_assets');
function wc_telebirr_enqueue_checkout_assets() {
    if (is_checkout()) {
        // Add inline CSS styles
        wp_add_inline_style('woocommerce-general', "
            .wc-telebirr-payment-box {
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 4px;
                padding: 16px;
                margin: 12px 0;
            }
            .wc-telebirr-total {
                margin: 12px 0 0 0;
                padding: 8px 12px;
                background: #fff;
                border-left: 4px solid #007cba;
            }
            .wc-telebirr-instructions .woocommerce-info {
                margin-top: 12px;
                margin-bottom: 0;
            }
            .hidden-if-telebirr {
                transition: opacity 0.2s ease;
            }
        ");

        // Enqueue checkout javascript
        wc_enqueue_js("
            jQuery(document).ready(function($) {
                function toggleTelebirrFields() {
                    const isTelebirr = $('input[name=\"payment_method\"]:checked').val() === 'telebirr';
                    const $billingFields = $('.woocommerce-billing-fields__field-wrapper');
                    const $hiddenFields = $('.hidden-if-telebirr').closest('.form-row');

                    if (isTelebirr) {
                        $hiddenFields.slideUp(200);
                        $billingFields.addClass('telebirr-active');
                    } else {
                        $hiddenFields.slideDown(200);
                        $billingFields.removeClass('telebirr-active');
                    }
                }

                // Initial check on page load
                toggleTelebirrFields();

                // Handle payment method selection changes
                $('form.checkout').on('change', 'input[name=\"payment_method\"]', function() {
                    toggleTelebirrFields();
                    $(document.body).trigger('update_checkout');
                });

                // Handle updated checkout fragments
                $(document.body).on('updated_checkout', function() {
                    setTimeout(toggleTelebirrFields, 50);
                });
            });
        ");
    }
}

/**
 * Skip billing address validation for Telebirr payments
 */
add_action('woocommerce_after_checkout_validation', 'wc_telebirr_skip_billing_validation', 10, 2);
function wc_telebirr_skip_billing_validation($data, $errors) {
    if ($data['payment_method'] === 'telebirr') {
        $gateway = WC()->payment_gateways()->payment_gateways()['telebirr'];
        
        if (isset($gateway) && $gateway->hide_billing_fields) {
            // Remove all billing related errors
            foreach ($errors->get_error_codes() as $code) {
                if (str_starts_with($code, 'billing_')) {
                    $errors->remove($code);
                }
            }
        }
    }
}

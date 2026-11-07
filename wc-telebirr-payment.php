<?php
/**
 * Plugin Name: WooCommerce Telebirr Payment Gateway
 * Plugin URI: https://github.com/yourusername/wc-telebirr-payment
 * Description: Telebirr mobile money payment gateway integration for WooCommerce (Fabric Gateway API)
 * Version: 1.4.1
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
 *
 * NOTE: This plugin requires the phpseclib/phpseclib (^3.0) library for RSA-PSS signing
 * as required by the Telebirr H5 C2B specification. Install via:
 *   composer require phpseclib/phpseclib:^3.0
 *
 * This plugin uses the Telebirr Fabric Payment Gateway API (preOrder endpoint with
 * snake_case parameters). If you are using the original H5 C2B Web Payment API
 * (camelCase parameters), the parameter names will differ. Confirm your API version
 * with Telebirr support.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

define('WC_TELEBIRR_VERSION', '1.4.1');

// phpseclib (RSA-PSS) comes from `composer install` in this folder
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
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

    // Verify phpseclib is available
    if (!class_exists('\phpseclib3\Crypt\PublicKeyLoader')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>WooCommerce Telebirr:</strong> '
                . esc_html__('The phpseclib/phpseclib (^3.0) library is required. Please run composer install in the plugin folder.', 'wc-telebirr-payment')
                . '</p></div>';
        });
        // Continue loading so settings are still accessible; signing and verification fail until it is installed
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

        /** @var string Store/business name for receiveName field */
        private $receive_name;

        /** @var WC_Logger Logger instance */
        private $logger;

        /**
         * Constructor for the gateway
         */
        public function __construct() {
            $this->id                 = 'telebirr';
            $this->icon               = apply_filters('woocommerce_telebirr_icon', plugins_url('assets/img/telebirr-logo.png', __FILE__));
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
            $this->receive_name       = $this->get_option('receive_name', get_bloginfo('name'));

            // Load private key: prefer constant > file path > database setting
            $this->private_key        = $this->load_private_key();
            $this->public_key         = $this->get_option('public_key');

            // Initialize logger
            $this->logger = wc_get_logger();

            // API URLs
            $this->base_url           = $this->testmode
                ? 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway'
                : 'https://superapp.ethiomobilemoney.et:38443/apiaccess/payment/gateway';
            
            $this->web_base_url       = $this->testmode
                ? 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?'
                : 'https://superapp.ethiomobilemoney.et:38443/payment/web/paygate?';

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_telebirr', array($this, 'receipt_page'));

            // Add webhook listener
            add_action('woocommerce_api_telebirr_callback', array($this, 'handle_callback'));

            // Enqueue admin styles on gateway settings page
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

            // Enqueue frontend styles on checkout
            add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));
        }

        /**
         * Enqueue admin CSS on the Telebirr settings page
         *
         * @param string $hook The current admin page hook
         */
        public function enqueue_admin_styles($hook) {
            if ($hook !== 'woocommerce_page_wc-settings') {
                return;
            }
            // Only load on the Telebirr section
            if (!isset($_GET['section']) || $_GET['section'] !== 'telebirr') {
                return;
            }
            wp_enqueue_style(
                'telebirr-admin-css',
                plugins_url('assets/css/telebirr-admin.css', __FILE__),
                array(),
                WC_TELEBIRR_VERSION
            );
        }

        /**
         * Enqueue frontend CSS on checkout and order-pay pages
         */
        public function enqueue_checkout_styles() {
            if (!is_checkout() && !is_checkout_pay_page()) {
                return;
            }
            wp_enqueue_style(
                'telebirr-checkout-css',
                plugins_url('assets/css/telebirr-checkout.css', __FILE__),
                array(),
                WC_TELEBIRR_VERSION
            );
        }

        /**
         * Output branded header above the admin settings table
         */
        public function admin_options() {
            $logo_url = plugins_url('assets/img/telebirr-logo.png', __FILE__);
            $mode = $this->testmode ? 'test' : 'live';
            $version = WC_TELEBIRR_VERSION;
            ?>
            <div class="telebirr-admin-header">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Telebirr">
                <div class="telebirr-admin-header-text">
                    <h2><?php esc_html_e('Telebirr Payment Gateway', 'wc-telebirr-payment'); ?></h2>
                    <p><?php esc_html_e('Accept payments via Telebirr mobile money from Ethio Telecom.', 'wc-telebirr-payment'); ?></p>
                    <div class="telebirr-admin-header-badges">
                        <span class="telebirr-badge telebirr-badge--version">v<?php echo esc_html($version); ?></span>
                        <?php if ($this->testmode) : ?>
                            <span class="telebirr-badge telebirr-badge--test"><?php esc_html_e('Test Mode', 'wc-telebirr-payment'); ?></span>
                        <?php else : ?>
                            <span class="telebirr-badge telebirr-badge--live"><?php esc_html_e('Live', 'wc-telebirr-payment'); ?></span>
                        <?php endif; ?>
                        <?php if ($this->enabled === 'yes') : ?>
                            <span class="telebirr-status"><span class="telebirr-status-dot telebirr-status-dot--active"></span> <?php esc_html_e('Enabled', 'wc-telebirr-payment'); ?></span>
                        <?php else : ?>
                            <span class="telebirr-status"><span class="telebirr-status-dot telebirr-status-dot--inactive"></span> <?php esc_html_e('Disabled', 'wc-telebirr-payment'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($this->testmode) : ?>
            <div class="telebirr-test-mode-banner">
                <span class="dashicons dashicons-warning"></span>
                <span>
                    <?php esc_html_e('Test mode is active. Payments will be processed through the Telebirr sandbox environment. Disable test mode before going live.', 'wc-telebirr-payment'); ?>
                </span>
            </div>
            <?php endif; ?>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
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
         * Request timestamp: UTC Unix time in seconds, per the preOrder spec.
         *
         * @return string
         */
        private function get_timestamp() {
            return (string) time();
        }

        /**
         * Telebirr only charges in ETB, so hide the gateway for stores priced in anything else.
         *
         * @return bool
         */
        public function is_available() {
            return get_woocommerce_currency() === 'ETB' && parent::is_available();
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
                'receive_name' => array(
                    'title'       => __('Receiver Name', 'wc-telebirr-payment'),
                    'type'        => 'text',
                    'description' => __('The business/store name shown to the customer during Telebirr payment. Defaults to your site name.', 'wc-telebirr-payment'),
                    'default'     => get_bloginfo('name'),
                    'desc_tip'    => true,
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
                    'description' => __(
                        'Configure additional security for webhook callbacks. IMPORTANT: You must also register your server\'s outbound IP address with Telebirr via the developer portal — Telebirr will reject requests from unregistered IPs.',
                        'wc-telebirr-payment'
                    ),
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
            $transient_key = $this->token_transient_key();
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
                'sslcertificates' => $this->ca_bundle(),
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
         * CA file for Telebirr requests. The sandbox host does not send GlobalSign's intermediate
         * certificate, so test mode trusts a bundled GlobalSign GCC R3 EV intermediate + Root R3.
         * ponytail: pinned to GlobalSign; refresh assets/certs if the sandbox changes CA.
         *
         * @return string
         */
        private function ca_bundle() {
            return $this->testmode
                ? __DIR__ . '/assets/certs/telebirr-sandbox-ca.pem'
                : ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        }

        private function token_transient_key() {
            return 'telebirr_fabric_token_' . ($this->testmode ? 'test' : 'live');
        }

        /**
         * Save settings and drop cached tokens so new credentials take effect immediately
         */
        public function process_admin_options() {
            delete_transient('telebirr_fabric_token_test');
            delete_transient('telebirr_fabric_token_live');
            return parent::process_admin_options();
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
            
            $timestamp = $this->get_timestamp();
            $nonce = $this->generate_nonce();
            
            $order_id = $order->get_id();
            // WooCommerce IDs are integers, so this already meets Telebirr's digits/letters/underscore rule
            $merch_order_id = (string) $order_id;
            $amount = number_format($order->get_total(), 2, '.', '');

            // Enforce HTTPS for notifyUrl as required by Telebirr spec
            // api_request_url() works with plain and pretty permalinks
            $notify_url = WC()->api_request_url('telebirr_callback', true);
            if (strpos($notify_url, 'https://') !== 0) {
                $this->logger->error(
                    'Cannot generate HTTPS notify_url. Telebirr requires HTTPS for callback endpoints.',
                    array('source' => 'telebirr')
                );
                return false;
            }

            // Enforce HTTPS for redirect URL
            $redirect_url = $this->get_return_url($order);
            if (strpos($redirect_url, 'https://') !== 0) {
                // Attempt to force HTTPS
                $redirect_url = preg_replace('/^http:/', 'https:', $redirect_url);
            }
            
            $biz_content = array(
                'notify_url'      => $notify_url,
                'appid'           => $this->merchant_app_id,
                'merch_code'      => $this->merchant_code,
                'merch_order_id'  => $merch_order_id,
                'trade_type'      => 'Checkout',
                'business_type'   => 'BuyGoods',
                // Telebirr rejects ~`!#$%^*()-+=|/<>?;:"[]{}\& in title (sandbox error 49401024995)
                'title'           => preg_replace('/[~`!#$%^*()\-+=|\/<>?;:"\[\]{}\\\\&]/', '', sprintf(__('Order %s', 'wc-telebirr-payment'), $merch_order_id)),
                'total_amount'    => $amount,
                'trans_currency'  => 'ETB',
                'timeout_express' => '120m',
                'redirect_url'    => $redirect_url,
                'receive_name'    => $this->receive_name,
            );

            $request = array(
                'timestamp'   => $timestamp,
                'nonce_str'   => $nonce,
                'method'      => 'payment.preorder',
                'version'     => '1.0',
                'biz_content' => $biz_content,
            );

            // Sign the request using RSA-PSS (SHA256, MGF1-SHA256, salt 32)
            $signature = $this->sign_request($request);
            if (empty($signature)) {
                $this->logger->error(
                    sprintf('Failed to sign preOrder request for order #%d', $order_id),
                    array('source' => 'telebirr')
                );
                return false;
            }
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
                'sslcertificates' => $this->ca_bundle(),
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
                if ($status_code === 401) {
                    delete_transient($this->token_transient_key());
                }
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
                'timestamp'  => $this->get_timestamp(),
            );

            $params['sign'] = $this->sign_request($params);
            $params['sign_type'] = 'SHA256WithRSA';

            // Raw key=value join like the portal's createRawRequest demo, then the fixed otherParams
            $raw = array();
            foreach ($params as $key => $value) {
                $raw[] = $key . '=' . $value;
            }
            return $this->web_base_url . implode('&', $raw) . '&version=1.0&trade_type=Checkout';
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
         * Build the Telebirr signing string (portal "RequestSignatureProcess"): biz_content fields are
         * merged into the top level, excluded fields dropped, keys sorted, joined as key=value&...
         *
         * @param array $data Request or notify payload
         * @return string
         */
        private function build_sign_string($data) {
            $exclude = array('sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request', 'biz_content', 'wallet_reference_data');

            $fields = array();
            $sources = array($data);
            if (isset($data['biz_content']) && is_array($data['biz_content'])) {
                $sources[] = $data['biz_content'];
            }
            foreach ($sources as $source) {
                foreach ($source as $key => $value) {
                    if (!in_array($key, $exclude, true) && is_scalar($value)) {
                        $fields[$key] = (string) $value;
                    }
                }
            }
            ksort($fields, SORT_STRING);

            $sign_parts = array();
            foreach ($fields as $key => $value) {
                $sign_parts[] = $key . '=' . $value;
            }
            return implode('&', $sign_parts);
        }

        /**
         * Load an RSA key configured for Telebirr's RSA-PSS (SHA256, MGF1-SHA256, salt 32).
         * Throws if phpseclib is missing or the key cannot be parsed.
         *
         * @param string $pem PEM-encoded key
         * @return object phpseclib3 key
         */
        private function load_pss_key($pem) {
            if (!class_exists('\phpseclib3\Crypt\PublicKeyLoader')) {
                throw new \Exception('phpseclib3 is not installed; run composer install in the plugin folder');
            }
            return \phpseclib3\Crypt\PublicKeyLoader::load($pem)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->withSaltLength(32);
        }

        /**
         * Sign request using RSA-PSS as required by the Telebirr H5 C2B specification.
         *
         * @param array $data The request data to sign
         * @return string Base64-encoded signature, or empty string on failure
         */
        private function sign_request($data) {
            try {
                return base64_encode($this->load_pss_key($this->private_key)->sign($this->build_sign_string($data)));
            } catch (\Exception $e) {
                $this->logger->error('RSA-PSS signing failed: ' . $e->getMessage(), array('source' => 'telebirr'));
                return '';
            }
        }

        /**
         * Verify webhook signature from Telebirr using RSA-PSS.
         *
         * @param array $data The full webhook payload
         * @return bool True if signature is valid
         */
        private function verify_signature($data) {
            if (!isset($data['sign']) || !is_string($data['sign'])) {
                $this->logger->warning('Signature verification failed: missing or invalid sign field', array('source' => 'telebirr'));
                return false;
            }

            $received_sign = base64_decode($data['sign'], true);
            if ($received_sign === false) {
                $this->logger->warning('Signature verification failed: invalid base64 in sign field', array('source' => 'telebirr'));
                return false;
            }

            try {
                return $this->load_pss_key($this->public_key)->verify($this->build_sign_string($data), $received_sign);
            } catch (\Exception $e) {
                $this->logger->error('RSA-PSS verification failed: ' . $e->getMessage(), array('source' => 'telebirr'));
                return false;
            }
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
            // REMOTE_ADDR only: X-Forwarded-For is set by the caller and would bypass the allowlist
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
         * Map the Notify trade_status word to 2 paid, 1 pending, 3 failed, 0 other (logged only).
         * Paying/Pending mean money moved but is not settled; Expired is "not obvious" per the spec,
         * so neither changes the order beyond pending.
         *
         * @param mixed $raw
         * @return int
         */
        private function map_trade_status($raw) {
            $map = array('Completed' => 2, 'Paying' => 1, 'Pending' => 1, 'Failure' => 3);
            return (is_string($raw) && isset($map[$raw])) ? $map[$raw] : 0;
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
         * Security: validates HTTP method, Content-Type, IP, timestamp freshness,
         * signature, amount, and order ownership.
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

            if (!is_array($data) || empty($data)) {
                $this->logger->warning(
                    'Callback rejected: payload is not a JSON object',
                    array('source' => 'telebirr')
                );
                status_header(400);
                exit('Invalid request');
            }

            // Freshness: notify_time is the (signed) sending time; 5-minute window for clock skew.
            // The spec says seconds but its sample is in milliseconds, so accept both.
            $callback_timestamp = isset($data['notify_time']) && is_numeric($data['notify_time']) ? (int) $data['notify_time'] : 0;
            if ($callback_timestamp > 0 && $callback_timestamp < 100000000000) {
                $callback_timestamp *= 1000; // seconds to milliseconds
            }

            if ($callback_timestamp > 0) {
                $current_time_ms = round(microtime(true) * 1000);
                $time_diff_ms = abs($current_time_ms - $callback_timestamp);
                $max_age_ms = 5 * 60 * 1000; // 5 minutes in milliseconds

                if ($time_diff_ms > $max_age_ms) {
                    $this->logger->warning(
                        sprintf(
                            'Callback rejected: stale timestamp. Callback: %d, Server: %d, Diff: %dms (max: %dms)',
                            $callback_timestamp,
                            (int) $current_time_ms,
                            (int) $time_diff_ms,
                            $max_age_ms
                        ),
                        array('source' => 'telebirr')
                    );
                    status_header(400);
                    exit('Stale timestamp');
                }
            }
            
            // Verify RSA-PSS signature
            if (!$this->verify_signature($data)) {
                $this->logger->error(
                    'Callback rejected: signature verification failed',
                    array('source' => 'telebirr')
                );
                status_header(403);
                exit('Invalid signature');
            }

            // Get and validate order
            $order_id = isset($data['merch_order_id']) ? intval($data['merch_order_id']) : 0;
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
            $transaction_id = '';
            foreach (array('payment_order_id', 'trans_id') as $field) {
                if ($transaction_id === '' && isset($data[$field]) && is_scalar($data[$field])) {
                    $transaction_id = sanitize_text_field((string) $data[$field]);
                }
            }
            if (!empty($transaction_id) && $this->is_duplicate_callback($transaction_id, $order_id)) {
                $this->logger->info(
                    sprintf('Duplicate callback ignored for order #%d, txn: %s', $order_id, $transaction_id),
                    array('source' => 'telebirr')
                );
                echo wp_json_encode(array('code' => '00000', 'message' => 'success'));
                exit;
            }

            // Check payment status
            $status = $this->map_trade_status(isset($data['trade_status']) ? $data['trade_status'] : '');

            // Without a timestamp a signed notice could be replayed later. Completing a payment is safe
            // (amount-checked, ignored once paid); other status changes are skipped.
            if ($status !== 2 && $callback_timestamp === 0) {
                $this->logger->warning(
                    sprintf('Ignored trade_status %d for order #%d: callback has no timestamp', $status, $order_id),
                    array('source' => 'telebirr')
                );
                $status = -1;
            }

            // A late or replayed non-success notice must never undo a paid order
            if ($status !== 2 && $status !== -1 && $order->is_paid()) {
                $this->logger->warning(
                    sprintf('Ignored trade_status %d for already paid order #%d', $status, $order_id),
                    array('source' => 'telebirr')
                );
                $status = -1;
            }

            switch ($status) {
                case -1:
                    break;

                case 2: // Payment successful
                    if (!$order->is_paid()) {
                        // Verify payment amount matches order total
                        $paid_amount = isset($data['total_amount']) ? floatval($data['total_amount']) : 0;
                        $order_total = floatval($order->get_total());

                        // Telebirr always charges ETB, so a non-ETB order total is not comparable
                        if ($order->get_currency() !== 'ETB' || abs($paid_amount - $order_total) > 0.01) {
                            $this->logger->error(
                                sprintf(
                                    'Amount mismatch for order #%d: paid=%.2f ETB expected=%.2f %s',
                                    $order_id,
                                    $paid_amount,
                                    $order_total,
                                    $order->get_currency()
                                ),
                                array('source' => 'telebirr')
                            );
                            $order->update_status(
                                'on-hold',
                                sprintf(
                                    __('Telebirr amount mismatch: paid %.2f ETB, expected %.2f %s. Manual review required.', 'wc-telebirr-payment'),
                                    $paid_amount,
                                    $order_total,
                                    $order->get_currency()
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
                    
                default:
                    $this->logger->warning(
                        sprintf('Unhandled trade_status "%s" for order #%d', isset($data['trade_status']) && is_scalar($data['trade_status']) ? $data['trade_status'] : '', $order_id),
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
         * Receipt page - branded redirect UI with loading animation
         *
         * @param int $order_id The WooCommerce order ID
         */
        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return;
            }

            $logo_url = plugins_url('assets/img/telebirr-logo.png', __FILE__);
            $prepay_id = $order->get_meta('_telebirr_prepay_id', true);
            $checkout_url = $prepay_id ? $this->build_checkout_url($prepay_id) : '';
            $order_total = $order->get_formatted_order_total();
            $order_number = $order->get_order_number();
            ?>
            <div class="telebirr-receipt">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Telebirr" class="telebirr-receipt-logo">

                <div class="telebirr-order-summary">
                    <div class="telebirr-order-summary-row">
                        <span><?php esc_html_e('Order', 'wc-telebirr-payment'); ?></span>
                        <span>#<?php echo esc_html($order_number); ?></span>
                    </div>
                    <div class="telebirr-order-summary-row">
                        <span><?php esc_html_e('Payment Method', 'wc-telebirr-payment'); ?></span>
                        <span><?php esc_html_e('Telebirr', 'wc-telebirr-payment'); ?></span>
                    </div>
                    <div class="telebirr-order-summary-row">
                        <span><?php esc_html_e('Total', 'wc-telebirr-payment'); ?></span>
                        <span><?php echo wp_kses_post($order_total); ?></span>
                    </div>
                </div>

                <div class="telebirr-spinner"></div>

                <h2><?php esc_html_e('Redirecting to Telebirr', 'wc-telebirr-payment'); ?></h2>
                <p><?php esc_html_e('Please wait while we connect you to Telebirr to complete your payment securely.', 'wc-telebirr-payment'); ?></p>

                <div class="telebirr-progress-bar">
                    <div class="telebirr-progress-bar-fill"></div>
                </div>

                <?php if ($checkout_url) : ?>
                    <a href="<?php echo esc_url($checkout_url); ?>" class="telebirr-receipt-manual-link">
                        <?php esc_html_e('Click here if you are not redirected automatically', 'wc-telebirr-payment'); ?>
                    </a>
                <?php endif; ?>

                <div class="telebirr-receipt-secure">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                    <span><?php esc_html_e('Secured by Telebirr', 'wc-telebirr-payment'); ?></span>
                </div>
            </div>
            <?php
            // Auto redirect via JavaScript with a brief delay for UX
            if ($checkout_url) {
                ?>
                <script>
                    (function() {
                        setTimeout(function() {
                            window.location.href = <?php echo wp_json_encode($checkout_url); ?>;
                        }, 2000);
                    })();
                </script>
                <?php
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
        '<a href="' . esc_url(admin_url('admin.php?page=telebirr-dashboard')) . '">' . esc_html__('Dashboard', 'wc-telebirr-payment') . '</a>',
    );
    
    return array_merge($plugin_links, $links);
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// =====================================================================
// Dashboard: Admin Menu, Data Queries, Page Rendering
// =====================================================================

/**
 * Register the Telebirr Dashboard admin submenu under WooCommerce
 */
add_action('admin_menu', 'wc_telebirr_register_dashboard_menu');

function wc_telebirr_register_dashboard_menu() {
    add_submenu_page(
        'woocommerce',
        __('Telebirr Dashboard', 'wc-telebirr-payment'),
        __('Telebirr Dashboard', 'wc-telebirr-payment'),
        'manage_woocommerce',
        'telebirr-dashboard',
        'wc_telebirr_render_dashboard'
    );
}

/**
 * Enqueue dashboard assets
 */
add_action('admin_enqueue_scripts', 'wc_telebirr_dashboard_assets');

function wc_telebirr_dashboard_assets($hook) {
    if ($hook !== 'woocommerce_page_telebirr-dashboard') {
        return;
    }
    wp_enqueue_style(
        'telebirr-dashboard-css',
        plugins_url('assets/css/telebirr-dashboard.css', __FILE__),
        array(),
        WC_TELEBIRR_VERSION
    );
    wp_enqueue_script(
        'telebirr-dashboard-js',
        plugins_url('assets/js/telebirr-dashboard.js', __FILE__),
        array(),
        WC_TELEBIRR_VERSION,
        true
    );
}

/**
 * Query Telebirr order metrics from WooCommerce orders.
 * Uses WC HPOS-compatible queries via wc_get_orders(). Counts come from paginated
 * totals and revenue is summed page by page, so memory stays flat as orders grow.
 *
 * @param string $period 'today', '7days', '30days', '12months', 'all'
 * @return array Metrics data
 */
function wc_telebirr_get_metrics($period = '30days') {
    $offsets = array(
        'today'    => 'today',
        '7days'    => '-7 days',
        '30days'   => '-30 days',
        '12months' => '-12 months',
    );

    $base = array('payment_method' => 'telebirr');
    if (isset($offsets[$period])) {
        $base['date_created'] = '>=' . gmdate('Y-m-d', strtotime($offsets[$period], current_time('timestamp')));
    }

    // Per-status counts without loading orders
    $counts = array();
    foreach (array('completed', 'processing', 'pending', 'failed', 'cancelled', 'refunded', 'on-hold') as $status) {
        $counts[$status] = wc_get_orders($base + array(
            'status'   => $status,
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
        ))->total;
    }
    $total_count = wc_get_orders($base + array('limit' => 1, 'paginate' => true, 'return' => 'ids'))->total;

    // Revenue and daily totals from successful orders, 200 at a time
    $total_revenue = 0;
    $daily_revenue = array();
    $page = 1;
    do {
        $batch = wc_get_orders($base + array(
            'status' => array('completed', 'processing'),
            'limit'  => 200,
            'page'   => $page++,
        ));
        foreach ($batch as $order) {
            $amount = (float) $order->get_total();
            $total_revenue += $amount;
            $day = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : '';
            if ($day) {
                $daily_revenue[$day] = (isset($daily_revenue[$day]) ? $daily_revenue[$day] : 0) + $amount;
            }
        }
    } while (count($batch) === 200);

    // Last 14 days with sales, oldest first
    ksort($daily_revenue);
    $daily_revenue = array_slice($daily_revenue, -14, null, true);
    $daily_labels = array_map(function ($day) {
        return date_i18n('M d', strtotime($day));
    }, array_keys($daily_revenue));

    $recent_orders = array();
    foreach (wc_get_orders($base + array('limit' => 20, 'orderby' => 'date', 'order' => 'DESC')) as $order) {
        $recent_orders[] = array(
            'edit_url'       => $order->get_edit_order_url(),
            'order_number'   => $order->get_order_number(),
            'status'         => $order->get_status(),
            'total'          => (float) $order->get_total(),
            'date'           => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '',
            'customer'       => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'transaction_id' => $order->get_meta('_telebirr_transaction_id', true),
        );
    }

    $success_count = $counts['completed'] + $counts['processing'];

    return array(
        'total_revenue'    => $total_revenue,
        'total_count'      => $total_count,
        'completed'        => $counts['completed'],
        'processing'       => $counts['processing'],
        'pending'          => $counts['pending'],
        'failed'           => $counts['failed'],
        'cancelled'        => $counts['cancelled'],
        'refunded'         => $counts['refunded'],
        'on_hold'          => $counts['on-hold'],
        'success_rate'     => $total_count > 0 ? round(($success_count / $total_count) * 100, 1) : 0,
        'avg_order'        => $success_count > 0 ? $total_revenue / $success_count : 0,
        'daily_revenue'    => array('labels' => $daily_labels, 'values' => array_values($daily_revenue)),
        'recent_orders'    => $recent_orders,
        'status_breakdown' => array(
            array('label' => 'Completed',  'value' => $counts['completed'],  'color' => '#34C759'),
            array('label' => 'Processing', 'value' => $counts['processing'], 'color' => '#0066B3'),
            array('label' => 'Pending',    'value' => $counts['pending'],    'color' => '#F5A623'),
            array('label' => 'Failed',     'value' => $counts['failed'],     'color' => '#EF4444'),
            array('label' => 'Cancelled',  'value' => $counts['cancelled'],  'color' => '#94A3B8'),
            array('label' => 'On Hold',    'value' => $counts['on-hold'],    'color' => '#8B5CF6'),
            array('label' => 'Refunded',   'value' => $counts['refunded'],   'color' => '#6366F1'),
        ),
    );
}

/**
 * Render the Telebirr Dashboard page
 */
function wc_telebirr_render_dashboard() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'wc-telebirr-payment'));
    }

    $period = isset($_GET['period']) ? sanitize_text_field(wp_unslash($_GET['period'])) : '30days';
    $valid_periods = array('today', '7days', '30days', '12months', 'all');
    if (!in_array($period, $valid_periods, true)) {
        $period = '30days';
    }

    $metrics = wc_telebirr_get_metrics($period);
    $logo_url = plugins_url('assets/img/telebirr-logo.png', __FILE__);
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=telebirr');
    $dashboard_url = admin_url('admin.php?page=telebirr-dashboard');
    $currency = get_woocommerce_currency_symbol();

    // Pass data to JS
    wp_localize_script('telebirr-dashboard-js', 'telebirrDashData', array(
        'dailyRevenue'    => $metrics['daily_revenue'],
        'statusBreakdown' => $metrics['status_breakdown'],
    ));

    $period_labels = array(
        'today'    => __('Today', 'wc-telebirr-payment'),
        '7days'    => __('Last 7 Days', 'wc-telebirr-payment'),
        '30days'   => __('Last 30 Days', 'wc-telebirr-payment'),
        '12months' => __('Last 12 Months', 'wc-telebirr-payment'),
        'all'      => __('All Time', 'wc-telebirr-payment'),
    );
    ?>
    <div class="wrap telebirr-dashboard">

        <!-- Header -->
        <div class="telebirr-dash-header">
            <div class="telebirr-dash-header-left">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Telebirr">
                <div>
                    <h1><?php esc_html_e('Telebirr Dashboard', 'wc-telebirr-payment'); ?></h1>
                    <p><?php esc_html_e('Monitor your Telebirr payment activity and performance.', 'wc-telebirr-payment'); ?></p>
                </div>
            </div>
            <div class="telebirr-dash-header-right">
                <a href="<?php echo esc_url($settings_url); ?>">
                    <span class="dashicons dashicons-admin-generic" style="font-size:14px; vertical-align:middle; margin-right:4px;"></span>
                    <?php esc_html_e('Settings', 'wc-telebirr-payment'); ?>
                </a>
            </div>
        </div>

        <!-- Period Filter -->
        <form method="get" class="telebirr-date-filter">
            <input type="hidden" name="page" value="telebirr-dashboard">
            <label for="telebirr-period"><?php esc_html_e('Period:', 'wc-telebirr-payment'); ?></label>
            <select name="period" id="telebirr-period">
                <?php foreach ($period_labels as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($period, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Apply', 'wc-telebirr-payment'); ?></button>
        </form>

        <!-- Metric Cards -->
        <div class="telebirr-metrics">
            <div class="telebirr-metric-card telebirr-metric-card--revenue">
                <div class="telebirr-metric-icon"><span class="dashicons dashicons-chart-area"></span></div>
                <div class="telebirr-metric-label"><?php esc_html_e('Total Revenue', 'wc-telebirr-payment'); ?></div>
                <div class="telebirr-metric-value"><?php echo esc_html($currency . number_format($metrics['total_revenue'], 2)); ?></div>
                <div class="telebirr-metric-sub"><?php echo esc_html(sprintf(__('Avg order: %s%s', 'wc-telebirr-payment'), $currency, number_format($metrics['avg_order'], 2))); ?></div>
            </div>
            <div class="telebirr-metric-card telebirr-metric-card--transactions">
                <div class="telebirr-metric-icon"><span class="dashicons dashicons-list-view"></span></div>
                <div class="telebirr-metric-label"><?php esc_html_e('Total Transactions', 'wc-telebirr-payment'); ?></div>
                <div class="telebirr-metric-value"><?php echo esc_html($metrics['total_count']); ?></div>
                <div class="telebirr-metric-sub"><?php echo esc_html($period_labels[$period]); ?></div>
            </div>
            <div class="telebirr-metric-card telebirr-metric-card--success">
                <div class="telebirr-metric-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="telebirr-metric-label"><?php esc_html_e('Successful', 'wc-telebirr-payment'); ?></div>
                <div class="telebirr-metric-value"><?php echo esc_html($metrics['completed'] + $metrics['processing']); ?></div>
                <div class="telebirr-metric-sub"><?php echo esc_html($metrics['completed'] . ' completed, ' . $metrics['processing'] . ' processing'); ?></div>
            </div>
            <div class="telebirr-metric-card telebirr-metric-card--failed">
                <div class="telebirr-metric-icon"><span class="dashicons dashicons-dismiss"></span></div>
                <div class="telebirr-metric-label"><?php esc_html_e('Failed', 'wc-telebirr-payment'); ?></div>
                <div class="telebirr-metric-value"><?php echo esc_html($metrics['failed']); ?></div>
                <div class="telebirr-metric-sub"><?php echo esc_html($metrics['cancelled'] . ' cancelled'); ?></div>
            </div>
            <div class="telebirr-metric-card telebirr-metric-card--pending">
                <div class="telebirr-metric-icon"><span class="dashicons dashicons-clock"></span></div>
                <div class="telebirr-metric-label"><?php esc_html_e('Pending', 'wc-telebirr-payment'); ?></div>
                <div class="telebirr-metric-value"><?php echo esc_html($metrics['pending']); ?></div>
                <div class="telebirr-metric-sub"><?php echo esc_html($metrics['on_hold'] . ' on hold'); ?></div>
            </div>
            <div class="telebirr-metric-card telebirr-metric-card--rate">
                <div class="telebirr-metric-icon"><span class="dashicons dashicons-performance"></span></div>
                <div class="telebirr-metric-label"><?php esc_html_e('Success Rate', 'wc-telebirr-payment'); ?></div>
                <div class="telebirr-metric-value"><?php echo esc_html($metrics['success_rate'] . '%'); ?></div>
                <div class="telebirr-metric-sub"><?php echo esc_html($metrics['refunded'] . ' refunded'); ?></div>
            </div>
        </div>

        <!-- Charts -->
        <div class="telebirr-chart-row">
            <div class="telebirr-chart-card">
                <h3><?php esc_html_e('Revenue Over Time (ETB)', 'wc-telebirr-payment'); ?></h3>
                <div class="telebirr-chart-canvas-wrap">
                    <canvas id="telebirr-revenue-chart"></canvas>
                </div>
            </div>
            <div class="telebirr-chart-card">
                <h3><?php esc_html_e('Payment Status', 'wc-telebirr-payment'); ?></h3>
                <div class="telebirr-chart-canvas-wrap" style="height:180px;">
                    <canvas id="telebirr-status-chart"></canvas>
                </div>
                <ul class="telebirr-status-list">
                    <?php foreach ($metrics['status_breakdown'] as $seg) : ?>
                        <?php if ($seg['value'] > 0) : ?>
                        <li>
                            <span class="telebirr-sl-label">
                                <span class="telebirr-status-dot-lg" style="background:<?php echo esc_attr($seg['color']); ?>"></span>
                                <?php echo esc_html($seg['label']); ?>
                            </span>
                            <span class="telebirr-sl-count"><?php echo esc_html($seg['value']); ?></span>
                        </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Recent Transactions Table -->
        <div class="telebirr-table-card">
            <h3><?php esc_html_e('Recent Transactions', 'wc-telebirr-payment'); ?></h3>
            <?php if (empty($metrics['recent_orders'])) : ?>
                <div class="telebirr-empty-state">
                    <span class="dashicons dashicons-cart"></span>
                    <p><?php esc_html_e('No Telebirr transactions found for the selected period.', 'wc-telebirr-payment'); ?></p>
                </div>
            <?php else : ?>
            <table class="telebirr-txn-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order', 'wc-telebirr-payment'); ?></th>
                        <th><?php esc_html_e('Date', 'wc-telebirr-payment'); ?></th>
                        <th><?php esc_html_e('Customer', 'wc-telebirr-payment'); ?></th>
                        <th><?php esc_html_e('Amount', 'wc-telebirr-payment'); ?></th>
                        <th><?php esc_html_e('Status', 'wc-telebirr-payment'); ?></th>
                        <th><?php esc_html_e('Transaction ID', 'wc-telebirr-payment'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metrics['recent_orders'] as $txn) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($txn['edit_url']); ?>">
                                <strong>#<?php echo esc_html($txn['order_number']); ?></strong>
                            </a>
                        </td>
                        <td><?php echo esc_html($txn['date']); ?></td>
                        <td><?php echo esc_html(trim($txn['customer'])); ?></td>
                        <td class="telebirr-amount"><?php echo esc_html($currency . number_format($txn['total'], 2)); ?></td>
                        <td>
                            <?php
                            $pill_class = 'telebirr-pill--' . sanitize_html_class($txn['status']);
                            ?>
                            <span class="telebirr-pill <?php echo esc_attr($pill_class); ?>">
                                <?php echo esc_html(ucfirst($txn['status'])); ?>
                            </span>
                        </td>
                        <td><code><?php echo esc_html($txn['transaction_id'] ?: '—'); ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
    <?php
}

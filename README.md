# WooCommerce Telebirr Payment Gateway

A complete payment gateway integration for Telebirr mobile money service with WooCommerce.

## Features

- Full WooCommerce Payment Gateway integration  
- Test and Live environment support  
-  Automatic order status management  
- Secure RSA-SHA256 request signing  
-  Webhook callback verification  
- WooCommerce HPOS (High Performance Order Storage) compatible  
-  Admin settings panel  
-  Seamless checkout flow

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- OpenSSL PHP Extension
- cURL PHP Extension

## Installation

1. Upload the `wc-telebirr-payment.php` file to your WordPress plugins directory (`wp-content/plugins/`)
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Settings → Payments
4. Enable "Telebirr" payment method
5. Click "Manage" to configure your API credentials

## Configuration

### Test Mode (Sandbox)
1. Enable Test Mode checkbox
2. Enter your test credentials provided by Telebirr:
   - Test Fabric App ID
   - Test App Secret
   - Test Merchant App ID
   - Test Merchant Code
3. Add your RSA Private Key and Telebirr Public Key

### Live Mode (Production)
1. Disable Test Mode
2. Enter your production credentials
3. Verify webhook URL is accessible publicly

## Webhook Configuration

Set your notification URL in Telebirr merchant portal to:
```
https://your-domain.com/wc-api/telebirr_callback
```

## Order Flow

1. Customer selects Telebirr at checkout
2. Order is created in WooCommerce with status "Pending Payment"
3. Customer is redirected to Telebirr payment page
4. Customer completes payment on Telebirr platform
5. Telebirr sends webhook notification to your store
6. Order is automatically marked as "Processing" or "Completed"
7. Customer is redirected back to your order received page

## Supported Payment Statuses

| Status Code | Description | WooCommerce Status |
|-------------|-------------|--------------------|
| 1 | Payment Pending | Pending |
| 2 | Payment Successful | Processing/Completed |
| 3 | Payment Failed | Failed |
| 4 | Payment Cancelled | Cancelled |

## Troubleshooting

### Common Issues

1. **Authentication failed**
   - Verify your Fabric App ID and App Secret are correct
   - Ensure you're using the correct environment (test/live)

2. **Signature verification failed**
   - Check that your RSA private key matches the public key registered with Telebirr
   - Verify webhook URL is publicly accessible

3. **Orders not updating after payment**
   - Confirm webhook URL is correctly set in Telebirr portal
   - Check WordPress permalink settings
   - Verify your server accepts incoming POST requests

## Changelog

### 1.0.0
- Initial release
- Full payment processing support
- Webhook callback handling
- Admin configuration interface

## License

GPL v2 or later. See LICENSE file for details.

## Support

For issues and questions, please create an issue on the GitHub repository.

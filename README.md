# WooCommerce Telebirr Payment Gateway

A complete payment gateway integration for Telebirr mobile money service with WooCommerce.

## Features

- Full WooCommerce Payment Gateway integration  
- Test and Live environment support  
- Automatic order status management  
- Secure RSA-SHA256 request signing  
- Webhook callback verification  
- WooCommerce HPOS (High Performance Order Storage) compatible  
- Admin settings panel  
- Seamless checkout flow

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- OpenSSL PHP Extension
- cURL PHP Extension
- Store currency set to ETB (the gateway is hidden for other currencies)
- Composer (installs phpseclib for RSA-PSS signing)

## Installation

1. Copy the whole `wc-telebirr-payment` folder to `wp-content/plugins/`
2. In that folder run `composer install --no-dev` (or upload a copy that already has `vendor/`)
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to WooCommerce → Settings → Payments
5. Enable "Telebirr" payment method
6. Click "Manage" to configure your API credentials

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

### Checkout Options
- **Show Order Total**: shows the order total in the Telebirr payment box at checkout (on by default).
- **Simplify Checkout**: hides billing fields when Telebirr is selected, but only when every cart item is virtual. Email stays required and validated, and orders with physical items keep the full billing form.
- These options apply to the classic (shortcode) checkout. The plugin does not yet integrate with the WooCommerce Checkout block.

Paying directly to a personal phone number is intentionally not supported: Telebirr's createOrder API has no such field (payments go to the merchant short code).

## Webhook Configuration

The plugin sends its notification URL to Telebirr with every order. It is:
```
https://your-domain.com/wc-api/telebirr_callback        (pretty permalinks)
https://your-domain.com/?wc-api=telebirr_callback       (plain permalinks)
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

Telebirr's notify callback sends `trade_status` as a word (portal docs, "Step 7: Notify"):

| trade_status | Meaning (per Telebirr) | WooCommerce result |
|--------------|------------------------|--------------------|
| Completed | Payment completed | Processing/Completed (after amount check) |
| Paying / Pending | Paid, not yet settled | Stays Pending |
| Failure | Payment failed | Failed |
| Expired / other | Not definitive | Logged only |

Notices other than Completed never change an order that is already paid, and are ignored if they carry no `notify_time`.

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

## Self-check

From the WordPress root:
```
wp eval-file "$PWD/wp-content/plugins/wc-telebirr-payment/tests/selfcheck.php"
```

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

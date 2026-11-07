# Telebirr WooCommerce Gateway

WooCommerce payment gateway for Telebirr (Fabric Gateway API / H5 C2B). The plugin is `wc-telebirr-payment.php` (v1.4.1) plus its CSS, JS and logo in `assets/`.

## Code map (`wc-telebirr-payment.php`)

- `wc_telebirr_init_gateway()` on `plugins_loaded` defines `WC_Gateway_Telebirr extends WC_Payment_Gateway` (gateway id `telebirr`).
- Callback endpoint: `woocommerce_api_telebirr_callback` → `handle_callback()`. URL comes from `WC()->api_request_url()` (`?wc-api=telebirr_callback` on plain permalinks, which this local site uses).
- Signing: `build_sign_string()` is shared by `sign_request()` and `verify_signature()`; RSA-PSS via phpseclib3 only (`load_pss_key()`), no PKCS#1 fallback.
- `is_available()` hides the gateway unless the store currency is ETB; the callback also puts non-ETB orders on hold.
- Non-success callbacks (status 1/3/4) are ignored for orders that are already paid.
- HPOS compatibility declared on `before_woocommerce_init`.
- Admin transactions dashboard: `wc_telebirr_register_dashboard_menu`, `wc_telebirr_get_metrics()`, `wc_telebirr_render_dashboard()`.

## Gotchas

- phpseclib comes from `composer install` in the plugin folder (`vendor/` is gitignored, so releases must ship it or run Composer). Without it the plugin shows an admin notice and signing/verification fail.
- Spec source: developer.ethiotelecom.et docs, "H5 C2B Web Payment Integration" (createOrder, Generate Checkout Url, Notify, RequestSignatureProcess). Needs a portal login.
- Sign string: biz_content fields merged into top level; exclude sign, sign_type, header, refund_info, openType, raw_request, biz_content, wallet_reference_data; sort; key=value joined by &; no JSON, empty values kept. RSA-PSS SHA256, salt 32. Self-check asserts the portal's worked example.
- Request `timestamp` is seconds. `business_type: BuyGoods` is mandatory in preOrder. Checkout URL is the raw rawRequest join + `&version=1.0&trade_type=Checkout`.
- Notify is flat (no biz_content): `merch_order_id`, `payment_order_id`, `trans_id`, `total_amount`, `trade_status` word, `notify_time` (spec says seconds, sample is ms; both accepted, 5-minute window).
- Sandbox host (196.188.120.3) does not send GlobalSign's intermediate cert, so WP HTTP fails with cURL error 60. Test mode uses `assets/certs/telebirr-sandbox-ca.pem` (GlobalSign GCC R3 EV TLS CA 2025 + Root R3) via `ca_bundle()`; production sends a full chain and uses WP's bundle.
- preOrder `title` must not contain ~`!#$%^*()-+=|/<>?;:"[]{}\& (sandbox error 49401024995), so the plugin strips them.
- Sandbox-tested 2026-11-07: token OK with the 2022 Postman demo app credentials; preOrder reached business validation but the demo merchant appid 930231098009602 "does not exist" (60200087). Needs the account's own Merchant AppId/ShortCode/private key to finish.
- Production API host is `superapp.ethiomobilemoney.et:38443` (docs); the production paygate path is assumed to mirror the testbed `/payment/web/paygate?`.
- Self-check: `wp eval-file "$PWD/wp-content/plugins/wc-telebirr-payment/tests/selfcheck.php"` from the WP root.

## Local dev

- Site: https://telebirr.test (Yerd, PHP 8.5, HTTPS), WordPress 7.1.3 at `~/Documents/yeard/telebirr`.
- DB: MariaDB (Yerd), database `telebirr`, user `root`, no password, host `127.0.0.1`.
- WooCommerce 11.2.2 active, Telebirr plugin active. Admin user `admin` (password kept outside the repo).
- This repo lives at `~/Documents/yeard/telebirr/wp-content/plugins/wc-telebirr-payment`; `~/Documents/yeard/tele-birr` is a symlink to it. Don't reverse that: Yerd returns 403 for static files reached through a symlink outside the site root.
- WP-CLI: run `wp ...` from `~/Documents/yeard/telebirr`.
- Telebirr cannot reach `.test`; callbacks need a public tunnel to test end to end.
- Gateway not yet configured (needs Telebirr test credentials and keys).

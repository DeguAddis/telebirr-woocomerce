<?php
/**
 * Self-check for signing and availability rules.
 * Run from the WordPress root: wp eval-file "$PWD/wp-content/plugins/wc-telebirr-payment/tests/selfcheck.php"
 */

function telebirr_check($ok, $name) {
    if (!$ok) {
        WP_CLI::error('FAIL: ' . $name);
    }
    WP_CLI::log('ok   ' . $name);
}

function telebirr_call($gateway, $method, ...$args) {
    $m = new ReflectionMethod($gateway, $method);
    $m->setAccessible(true);
    return $m->invoke($gateway, ...$args);
}

function telebirr_set($gateway, $prop, $value) {
    $p = new ReflectionProperty($gateway, $prop);
    $p->setAccessible(true);
    $p->setValue($gateway, $value);
}

// Throwaway key pair: our private key signs, and we pose as Telebirr with the matching public key
$private = \phpseclib3\Crypt\RSA::createKey(2048);
$gateway = new WC_Gateway_Telebirr();
telebirr_set($gateway, 'private_key', $private->toString('PKCS8'));
telebirr_set($gateway, 'public_key', $private->getPublicKey()->toString('PKCS8'));

// Worked example from the portal's RequestSignatureProcess page: biz_content fields merge into the top level
$doc_request = array(
    'timestamp'   => '1755866911',
    'nonce_str'   => 'H5QN4M6EAB2TXXVFK8SVV0RW6UFASICS',
    'method'      => 'payment.preorder',
    'version'     => '1.0',
    'biz_content' => array(
        'notify_url'      => 'https://www.google.com',
        'appid'           => '1227484825753601',
        'merch_code'      => '101011',
        'merch_order_id'  => '1755866910890',
        'trade_type'      => 'Checkout',
        'title'           => 'diamond_1.5',
        'total_amount'    => '1.5',
        'trans_currency'  => 'ETB',
        'timeout_express' => '120m',
    ),
    'sign'        => 'x',
    'sign_type'   => 'SHA256WithRSA',
);
telebirr_check(
    telebirr_call($gateway, 'build_sign_string', $doc_request)
        === 'appid=1227484825753601&merch_code=101011&merch_order_id=1755866910890&method=payment.preorder&nonce_str=H5QN4M6EAB2TXXVFK8SVV0RW6UFASICS&notify_url=https://www.google.com&timeout_express=120m&timestamp=1755866911&title=diamond_1.5&total_amount=1.5&trade_type=Checkout&trans_currency=ETB&version=1.0',
    'sign string matches the portal worked example'
);

// Notify sample from the portal's Notify page (flat payload)
$notify = array(
    'notify_url'       => 'https://shop.example/?wc-api=telebirr_callback',
    'appid'            => '853694808089634',
    'notify_time'      => '1670575472482',
    'merch_code'       => '245445',
    'merch_order_id'   => '1670575560882',
    'payment_order_id' => '00801104C911443200001002',
    'total_amount'     => '10.00',
    'trans_id'         => '49485948475845',
    'trans_currency'   => 'ETB',
    'trade_status'     => 'Completed',
    'trans_end_time'   => '1670575472000',
);
$notify['sign'] = base64_encode(
    $private->withHash('sha256')->withMGFHash('sha256')->withSaltLength(32)
        ->sign(telebirr_call($gateway, 'build_sign_string', $notify))
);
$notify['sign_type'] = 'SHA256WithRSA';
telebirr_check(telebirr_call($gateway, 'verify_signature', $notify), 'notify signed by "Telebirr" verifies');

$tampered = $notify;
$tampered['total_amount'] = '1.00';
telebirr_check(!telebirr_call($gateway, 'verify_signature', $tampered), 'tampered amount is rejected');
telebirr_check(!telebirr_call($gateway, 'verify_signature', array('sign' => array('x')) + $notify), 'non-string sign is rejected');

$own = telebirr_call($gateway, 'sign_request', $doc_request);
telebirr_check($own !== '' && $private->getPublicKey()->withHash('sha256')->withMGFHash('sha256')->withSaltLength(32)
    ->verify(telebirr_call($gateway, 'build_sign_string', $doc_request), base64_decode($own)), 'our RSA-PSS request signature verifies');

foreach (array(array('Completed', 2), array('Paying', 1), array('Pending', 1), array('Failure', 3), array('Expired', 0), array('2', 0), array('', 0), array(array(), 0)) as $case) {
    telebirr_check(telebirr_call($gateway, 'map_trade_status', $case[0]) === $case[1], 'trade_status ' . wp_json_encode($case[0]) . ' maps to ' . $case[1]);
}

$url = telebirr_call($gateway, 'build_checkout_url', 'PREPAY1');
telebirr_check(
    (bool) preg_match('/\?appid=[^&]*&merch_code=[^&]*&nonce_str=\w{32}&prepay_id=PREPAY1&timestamp=\d{10}&sign=[^&]+&sign_type=SHA256WithRSA&version=1\.0&trade_type=Checkout$/', $url),
    'checkout URL follows the portal rawRequest layout'
);

// Simplified checkout: virtual-only carts, email always required
$settings_backup = get_option('woocommerce_telebirr_settings', array());
update_option('woocommerce_telebirr_settings', array_merge($settings_backup, array('hide_billing_fields' => 'yes')));
wc_load_cart();
$virtual = new WC_Product_Simple(); $virtual->set_name('Selfcheck ticket'); $virtual->set_regular_price('10'); $virtual->set_virtual(true); $virtual->save();
$physical = new WC_Product_Simple(); $physical->set_name('Selfcheck shirt'); $physical->set_regular_price('10'); $physical->save();

$errors_for = function ($method) {
    $e = new WP_Error();
    foreach (array('billing_first_name_required', 'billing_address_1_required', 'billing_email_required', 'validation') as $code) {
        $e->add($code, $code);
    }
    wc_telebirr_relax_hidden_field_validation(array('payment_method' => $method), $e);
    return $e->get_error_codes();
};

WC()->cart->empty_cart();
WC()->cart->add_to_cart($virtual->get_id());
telebirr_check(wc_telebirr_cart_is_virtual() && wc_telebirr_simplify_checkout_enabled(), 'virtual-only cart enables simplified checkout');
telebirr_check($errors_for('telebirr') === array('billing_email_required', 'validation'), 'telebirr + virtual cart drops only hidden-field required errors (email and format errors kept)');
telebirr_check(count($errors_for('cod')) === 4, 'other payment methods keep every billing error');

WC()->cart->add_to_cart($physical->get_id());
telebirr_check(!wc_telebirr_cart_is_virtual() && count($errors_for('telebirr')) === 4, 'a physical item restores full billing validation for telebirr');

WC()->cart->empty_cart();
$virtual->delete(true); $physical->delete(true);
update_option('woocommerce_telebirr_settings', $settings_backup);

$currency = function () { return 'USD'; };
add_filter('woocommerce_currency', $currency);
telebirr_check(!$gateway->is_available(), 'gateway hidden when store currency is not ETB');
remove_filter('woocommerce_currency', $currency);

WP_CLI::success('All Telebirr self-checks passed');

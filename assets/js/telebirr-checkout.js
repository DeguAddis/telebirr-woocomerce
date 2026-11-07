// Hide tagged billing rows while Telebirr is the selected payment method (virtual-only carts).
jQuery(function ($) {
    function toggle() {
        var telebirr = $('input[name="payment_method"]:checked').val() === 'telebirr';
        $('.telebirr-hideable').toggle(!telebirr);
    }
    $(document.body).on('change', 'input[name="payment_method"]', toggle);
    $(document.body).on('updated_checkout payment_method_selected', toggle);
    toggle();
});

/*
 * Checkout: no payment method is chosen in advance - the customer picks one (card on file, cheque or Interac
 * e-Transfer). WooCommerce marks the first method chosen when it renders the payment section on the first
 * order review update, so clear that once.
 */
jQuery(function ($) {
    $(document.body).one('updated_checkout', function () {
        $('.wc_payment_method > input').prop('checked', false);
        $('.payment_box').hide();
    });
});

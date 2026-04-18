jQuery( document ).ready( function() {
	jQuery(".shipping").hide();
	jQuery(".cart-subtotal").hide();
	jQuery(".cart-subtotal").hide();
	jQuery(".product-total").hide();
	jQuery(".order-total").hide();
	jQuery(".total").hide();
    var $h2 = jQuery('.cart_totals h2');
    if ($h2.text().indexOf('Cart') > -1) {
        $h2.hide();
    }	
    var $th = jQuery('.order_details tr th');
    if ($th.text().indexOf('Total') > -1) {
    	$th.hide();
    }
});
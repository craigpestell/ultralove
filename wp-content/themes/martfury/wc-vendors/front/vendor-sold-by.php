<?php
/**
 * Vendor sold by Template
 *
 * The template for displaying the vendor sold by on the shop loop
 *
 * This template can be overridden by copying it to yourtheme/wc-vendors/front/vendor-sold-by.php
 *
 * @author        Jamie Madden, WC Vendors
 * @package       WCVendors/Templates/Emails/HTML
 * @version       2.1.17
 *
 *
 * Template Variables available
 * $vendor :            For pulling additional user details from vendor account.  This is an array.
 * $vendor_id  :        current vendor user id number
 * $shop_name :        Store/Shop Name (From Vendor Dashboard Shop Settings)
 * $shop_description : Shop Description (completely sanitized) (From Vendor Dashboard Shop Settings)
 * $seller_info :        Seller Info(From Vendor Dashboard Shop Settings)
 * $vendor_email :        Vendors email address
 * $vendor_login :    Vendors user_login name
 * $vendor_shop_link : URL to the vendors store
 */
?>

<div class="sold-by-meta">
	<span class="sold-by-label">
		<?php echo apply_filters('wcvendors_sold_by_in_loop', $sold_by_label ); ?>:
	</span>
	<?php echo wp_kses_post($sold_by); ?>
</div>
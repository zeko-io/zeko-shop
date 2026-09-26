<?php
/**
 * Order not found template.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="zeko-shop">
	<div class="zeko-shop-warning">
		<p><?php echo esc_html__( 'This order could not be found.', 'zeko-shop' ); ?></p>
		<p><a class="zeko-shop-btn" href="<?php echo esc_url( zeko_shop_page_url( 'my-orders' ) ); ?>"><?php echo esc_html__( 'Back to My Orders', 'zeko-shop' ); ?></a></p>
	</div>
</div>

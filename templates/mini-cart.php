<?php
/**
 * Mini cart template.
 *
 * Variables: $items (array), $subtotal (string), $currency (string),
 *            $cart_url (string), $checkout_url (string), $user_id (int).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="zeko-shop zeko-shop-mini-cart">
	<div class="zeko-shop-mini-cart-header">
		<strong><?php echo esc_html__( 'Cart', 'zeko-shop' ); ?></strong>
		<span class="zeko-shop-cart-badge-count" data-zeko-cart-count><?php echo (int) array_sum( array_map( static fn( $i ) => (int) $i['qty'], $items ) ); ?></span>
	</div>

	<?php if ( empty( $items ) ) : ?>
		<p class="zeko-shop-mini-cart-empty"><?php echo esc_html__( 'Your cart is empty.', 'zeko-shop' ); ?></p>
	<?php else : ?>
		<ul class="zeko-shop-mini-cart-items">
			<?php foreach ( $items as $item ) : ?>
				<li>
					<span class="zeko-shop-mini-cart-title"><?php echo esc_html( $item['product']['title'] ); ?></span>
					<span class="zeko-shop-mini-cart-meta">
						<?php echo (int) $item['qty']; ?> &times; <?php echo wp_kses_post( zeko_shop_format_price( (string) $item['product']['price'], $item['product']['currency'] ) ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="zeko-shop-mini-cart-subtotal">
			<strong><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></strong>
			<span><?php echo wp_kses_post( zeko_shop_format_price( $subtotal, $currency ) ); ?></span>
		</p>
	<?php endif; ?>

	<div class="zeko-shop-mini-cart-actions">
		<a class="zeko-shop-btn" href="<?php echo esc_url( $cart_url ); ?>"><?php echo esc_html__( 'View cart', 'zeko-shop' ); ?></a>
		<?php if ( ! empty( $items ) ) : ?>
			<a class="zeko-shop-btn zeko-shop-btn-primary" href="<?php echo esc_url( $checkout_url ); ?>"><?php echo esc_html__( 'Checkout', 'zeko-shop' ); ?></a>
		<?php endif; ?>
	</div>
</div>

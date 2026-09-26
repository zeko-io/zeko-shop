<?php
/**
 * Cart template.
 *
 * Variables: $items (array), $subtotal (string), $currency (string), $user_id (int).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$checkout_url = zeko_shop_page_url( 'checkout' );
$shop_url     = zeko_shop_page_url( 'shop' );
?>
<div class="zeko-shop">
	<div class="zeko-shop-header">
		<h1><?php echo esc_html__( 'Your Cart', 'zeko-shop' ); ?></h1>
	</div>

	<?php if ( empty( $items ) ) : ?>
		<div class="zeko-shop-note">
			<p><?php echo esc_html__( 'Your cart is empty.', 'zeko-shop' ); ?></p>
			<p><a class="zeko-shop-btn" href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html__( 'Browse products', 'zeko-shop' ); ?></a></p>
		</div>
	<?php else : ?>
		<div class="zeko-shop-cart">
			<table class="zeko-shop-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Product', 'zeko-shop' ); ?></th>
						<th><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></th>
						<th><?php echo esc_html__( 'Qty', 'zeko-shop' ); ?></th>
						<th><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $product = $item['product']; ?>
						<tr data-cart-row="<?php echo (int) $product['product_id']; ?>">
							<td class="zeko-shop-cart-title"><?php echo esc_html( $product['title'] ); ?></td>
							<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?></td>
							<td>
								<input
									type="number"
									min="0"
									class="zeko-shop-qty"
									value="<?php echo (int) $item['qty']; ?>"
									data-product-id="<?php echo (int) $product['product_id']; ?>"
									<?php echo (int) $product['stock'] >= 0 ? 'max="' . (int) $product['stock'] . '"' : ''; ?>>
							</td>
							<td><?php echo wp_kses_post( zeko_shop_format_price( $item['subtotal'], $product['currency'] ) ); ?></td>
							<td>
								<button
									type="button"
									class="zeko-shop-remove"
									data-product-id="<?php echo (int) $product['product_id']; ?>"
									title="<?php echo esc_attr__( 'Remove', 'zeko-shop' ); ?>">
									&times;
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="zeko-shop-cart-summary">
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></span>
					<span><?php echo wp_kses_post( zeko_shop_format_price( $subtotal, $currency ) ); ?></span>
				</div>
				<div class="zeko-shop-summary-row zeko-shop-summary-total">
					<span><?php echo esc_html__( 'Total', 'zeko-shop' ); ?></span>
					<span><?php echo wp_kses_post( zeko_shop_format_price( $subtotal, $currency ) ); ?></span>
				</div>
				<a class="zeko-shop-btn zeko-shop-btn-primary" href="<?php echo esc_url( $checkout_url ); ?>">
					<?php echo esc_html__( 'Proceed to Checkout', 'zeko-shop' ); ?>
				</a>
			</div>
		</div>
	<?php endif; ?>
</div>

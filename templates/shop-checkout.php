<?php
/**
 * Checkout template.
 *
 * Variables: $items (array), $currency (string), $totals (array),
 *            $balance (string), $loyalty (?array), $user_id (int).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cart_url       = zeko_shop_page_url( 'cart' );
$wallet_url     = '';
$wallet_page_id = (int) get_option( 'zeko_pay_wallet_page_id', 0 );
if ( $wallet_page_id ) {
	$wallet_url = get_permalink( $wallet_page_id );
}
if ( ! $wallet_url ) {
	$wallet_query = new WP_Query(
		array(
			'post_type'      => 'page',
			'posts_per_page' => 1,
			'name'           => 'wallet',
			'post_status'    => 'publish',
		)
	);
	if ( $wallet_query->have_posts() ) {
		$wallet_url = get_permalink( $wallet_query->posts[0]->ID );
	}
}

$subtotal = $totals['subtotal'];
$discount = $totals['discount'];
$tax_field      = $totals['tax'];
$fee      = $totals['fee'];
$total    = $totals['total'];
$promo    = $totals['promo'] ?? null;

$insufficient = bccomp( (string) $balance, $total, 2 ) < 0;
?>
<div class="zeko-shop">
	<div class="zeko-shop-header">
		<h1><?php echo esc_html__( 'Checkout', 'zeko-shop' ); ?></h1>
	</div>

	<?php if ( $insufficient ) : ?>
		<div class="zeko-shop-warning">
			<p><?php echo esc_html__( 'Your wallet balance is lower than the order total. Please top up your wallet before checking out.', 'zeko-shop' ); ?></p>
			<?php if ( $wallet_url ) : ?>
				<p><a class="zeko-shop-btn" href="<?php echo esc_url( $wallet_url ); ?>"><?php echo esc_html__( 'Top up wallet', 'zeko-shop' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="zeko-shop-checkout">
		<table class="zeko-shop-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Product', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Qty', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php $product = $item['product']; ?>
					<tr>
						<td><?php echo esc_html( $product['title'] ); ?></td>
						<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?></td>
						<td><?php echo (int) $item['qty']; ?></td>
						<td><?php echo wp_kses_post( zeko_shop_format_price( $item['subtotal'], $product['currency'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="zeko-shop-cart-summary">
			<div class="zeko-shop-summary-row">
				<span><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></span>
				<span><?php echo wp_kses_post( zeko_shop_format_price( $subtotal, $currency ) ); ?></span>
			</div>

			<div class="zeko-shop-promo">
				<?php if ( $promo ) : ?>
					<div class="zeko-shop-promo-applied">
						<span><?php /* translators: %s: promo code */ echo esc_html( sprintf( __( 'Promo %s applied', 'zeko-shop' ), $promo['code'] ) ); ?></span>
						<button type="button" class="zeko-shop-promo-remove"><?php echo esc_html__( 'Remove', 'zeko-shop' ); ?></button>
					</div>
					<div class="zeko-shop-summary-row">
						<span><?php echo esc_html__( 'Discount', 'zeko-shop' ); ?></span>
						<span class="zeko-shop-discount">−<?php echo wp_kses_post( zeko_shop_format_price( $discount, $currency ) ); ?></span>
					</div>
				<?php else : ?>
					<div class="zeko-shop-promo-form">
						<input type="text" id="zeko-shop-promo-code" class="zeko-shop-promo-input" aria-label="<?php echo esc_attr__( 'Promo code', 'zeko-shop' ); ?>" placeholder="<?php echo esc_attr__( 'Promo code', 'zeko-shop' ); ?>">
						<button type="button" class="zeko-shop-btn" id="zeko-shop-promo-apply"><?php echo esc_html__( 'Apply', 'zeko-shop' ); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( (float) $tax_field > 0 ) : ?>
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Tax', 'zeko-shop' ); ?></span>
					<span><?php echo wp_kses_post( zeko_shop_format_price( $tax_field, $currency ) ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( (float) $fee > 0 ) : ?>
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Platform fee', 'zeko-shop' ); ?></span>
					<span><?php echo wp_kses_post( zeko_shop_format_price( $fee, $currency ) ); ?></span>
				</div>
			<?php endif; ?>

			<div class="zeko-shop-summary-row zeko-shop-summary-total">
				<span><?php echo esc_html__( 'Total', 'zeko-shop' ); ?></span>
				<span><?php echo wp_kses_post( zeko_shop_format_price( $total, $currency ) ); ?></span>
			</div>
			<div class="zeko-shop-summary-row zeko-shop-balance">
				<span><?php echo esc_html__( 'Wallet balance', 'zeko-shop' ); ?></span>
				<span><?php echo wp_kses_post( zeko_shop_format_price( $balance, $currency ) ); ?></span>
			</div>

			<?php if ( $loyalty && (int) $loyalty['points'] > 0 ) : ?>
				<div class="zeko-shop-summary-row zeko-shop-loyalty">
					<span><?php echo esc_html__( 'Loyalty', 'zeko-shop' ); ?></span>
					<span><?php /* translators: 1: number of loyalty points. 2: loyalty tier label */ echo esc_html( sprintf( __( '%1$s points · %2$s', 'zeko-shop' ), (int) $loyalty['points'], $loyalty['tier_label'] ?? $loyalty['tier'] ) ); ?></span>
				</div>
			<?php endif; ?>

			<div class="zeko-shop-checkout-actions">
				<a class="zeko-shop-btn" href="<?php echo esc_url( $cart_url ); ?>"><?php echo esc_html__( 'Back to cart', 'zeko-shop' ); ?></a>
				<button
					type="button"
					id="zeko-shop-place-order"
					class="zeko-shop-btn zeko-shop-btn-primary"
					<?php echo $insufficient ? 'disabled' : ''; ?>>
					<?php echo esc_html__( 'Place Order &amp; Pay', 'zeko-shop' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

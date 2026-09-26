<?php
/**
 * Product detail template (rendered on the /shop/product/{id}/ route).
 *
 * Variables: $args (array) — either ['message' => string] or
 *            ['product' => array].
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $args['message'] ) ) :
	?>
	<div class="zeko-shop">
		<div class="zeko-shop-warning"><?php echo esc_html( $args['message'] ); ?></div>
		<p><a class="zeko-shop-btn" href="<?php echo esc_url( zeko_shop_page_url( 'shop' ) ); ?>"><?php echo esc_html__( 'Back to Shop', 'zeko-shop' ); ?></a></p>
	</div>
	<?php
	return;
endif;

$product      = $args['product'];
$is_program   = 'program' === ( $product['external_type'] ?? '' );
$is_session   = 'session' === ( $product['external_type'] ?? '' );
$module       = Zeko_Shop_Frontend::product_module( $product );
$is_logged_in = is_user_logged_in();
$stock        = (int) $product['stock'];
$shop_url     = zeko_shop_page_url( 'shop' );
$cart_url     = zeko_shop_page_url( 'cart' );
?>
<div class="zeko-shop">
	<nav class="zeko-shop-breadcrumbs">
		<a href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html__( 'Shop', 'zeko-shop' ); ?></a>
		<span class="zeko-shop-breadcrumbs-sep">›</span>
		<span class="zeko-shop-breadcrumbs-current"><?php echo esc_html( $product['title'] ); ?></span>
	</nav>

	<div class="zeko-shop-product">
		<?php if ( ! empty( $product['image_url'] ) ) : ?>
			<div class="zeko-shop-product-media">
				<img src="<?php echo esc_url( $product['image_url'] ); ?>" alt="<?php echo esc_attr( $product['title'] ); ?>">
			</div>
		<?php endif; ?>

		<div class="zeko-shop-product-body">
			<div class="zeko-shop-card-tags">
				<span class="zeko-shop-badge <?php echo esc_attr( $module['class'] ); ?>"><?php echo esc_html( $module['label'] ); ?></span>
				<?php if ( $is_program ) : ?>
					<span class="zeko-shop-badge"><?php echo esc_html__( 'Program', 'zeko-shop' ); ?></span>
				<?php endif; ?>
				<?php if ( $is_session ) : ?>
					<span class="zeko-shop-badge"><?php echo esc_html__( 'Mentor Session', 'zeko-shop' ); ?></span>
				<?php endif; ?>
				<?php if ( ! $is_program && ! empty( $product['file_url'] ) ) : ?>
					<span class="zeko-shop-badge zeko-shop-badge-digital"><?php echo esc_html__( 'Instant delivery', 'zeko-shop' ); ?></span>
				<?php endif; ?>
			</div>

			<h1 class="zeko-shop-product-title"><?php echo esc_html( $product['title'] ); ?></h1>
			<div class="zeko-shop-product-price"><?php echo wp_kses_post( zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?></div>

			<?php if ( $stock >= 0 ) : ?>
				<div class="zeko-shop-stock <?php echo $stock > 0 ? 'in-stock' : 'out-of-stock'; ?>">
					<?php /* translators: %d: number of units in stock */ echo $stock > 0 ? esc_html( sprintf( __( '%d in stock', 'zeko-shop' ), $stock ) ) : esc_html__( 'Out of stock', 'zeko-shop' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $product['description'] ) ) : ?>
				<div class="zeko-shop-product-description"><?php echo wp_kses_post( $product['description'] ); ?></div>
			<?php endif; ?>

			<div class="zeko-shop-card-actions">
				<?php if ( $is_program ) : ?>
					<a class="zeko-shop-btn zeko-shop-btn-primary" href="<?php echo esc_url( home_url( '/programs/' . (int) $product['external_id'] . '/' ) ); ?>">
						<?php echo esc_html__( 'View Program', 'zeko-shop' ); ?>
					</a>
				<?php elseif ( 0 === $stock ) : ?>
					<button class="zeko-shop-btn" disabled><?php echo esc_html__( 'Sold out', 'zeko-shop' ); ?></button>
				<?php elseif ( ! $is_logged_in ) : ?>
					<a class="zeko-shop-btn zeko-shop-btn-primary" href="<?php echo esc_url( wp_login_url() ); ?>"><?php echo esc_html__( 'Log in to Buy', 'zeko-shop' ); ?></a>
				<?php else : ?>
					<button
						type="button"
						class="zeko-shop-btn zeko-shop-btn-primary zeko-shop-add-to-cart"
						data-product-id="<?php echo (int) $product['product_id']; ?>"
						data-qty="1">
						<?php echo esc_html__( 'Add to Cart', 'zeko-shop' ); ?>
					</button>
					<a class="zeko-shop-btn" href="<?php echo esc_url( $cart_url ); ?>"><?php echo esc_html__( 'View Cart', 'zeko-shop' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

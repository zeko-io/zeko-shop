<?php
/**
 * Shop catalog template.
 *
 * Variables: $products (array), $this (Zeko_Shop_Frontend).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_logged_in = is_user_logged_in();
?>
<div class="zeko-shop">
	<div class="zeko-shop-header">
		<h1><?php echo esc_html__( 'Shop', 'zeko-shop' ); ?></h1>
		<p class="zeko-shop-subtitle"><?php echo esc_html__( 'Browse products and pay instantly with your Zeko wallet.', 'zeko-shop' ); ?></p>
	</div>

	<?php if ( empty( $products ) ) : ?>
		<div class="zeko-shop-note"><?php echo esc_html__( 'No products available right now.', 'zeko-shop' ); ?></div>
	<?php else : ?>
		<div class="zeko-shop-grid">
			<?php
			foreach ( $products as $product ) :
				$is_program = 'program' === ( $product['external_type'] ?? '' );
				$module     = Zeko_Shop_Frontend::product_module( $product );
				$detail_url = Zeko_Shop_Frontend::product_url( (int) $product['product_id'] );
				?>
				<div class="zeko-shop-card <?php echo $is_program ? 'zeko-shop-card-program' : ''; ?>">
					<a class="zeko-shop-card-link" href="<?php echo esc_url( $detail_url ); ?>">
						<?php if ( ! empty( $product['image_url'] ) ) : ?>
							<div class="zeko-shop-card-image">
								<img src="<?php echo esc_url( $product['image_url'] ); ?>" alt="<?php echo esc_attr( $product['title'] ); ?>" loading="lazy">
							</div>
						<?php endif; ?>

						<div class="zeko-shop-card-body">
							<div class="zeko-shop-card-tags">
								<span class="zeko-shop-badge <?php echo esc_attr( $module['class'] ); ?>"><?php echo esc_html( $module['label'] ); ?></span>
								<?php if ( $is_program ) : ?>
									<span class="zeko-shop-badge"><?php echo esc_html__( 'Program', 'zeko-shop' ); ?></span>
								<?php endif; ?>
								<?php if ( ! $is_program && ! empty( $product['file_url'] ) ) : ?>
									<span class="zeko-shop-badge zeko-shop-badge-digital"><?php echo esc_html__( 'Instant delivery', 'zeko-shop' ); ?></span>
								<?php endif; ?>
							</div>
							<h3 class="zeko-shop-card-title"><?php echo esc_html( $product['title'] ); ?></h3>
							<div class="zeko-shop-card-price"><?php echo wp_kses_post( zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?></div>
							<?php if ( ! empty( $product['description'] ) ) : ?>
								<div class="zeko-shop-card-description"><?php echo wp_kses_post( $product['description'] ); ?></div>
							<?php endif; ?>

							<?php
							$stock = (int) $product['stock'];
							if ( $stock >= 0 ) :
								?>
								<div class="zeko-shop-stock <?php echo $stock > 0 ? 'in-stock' : 'out-of-stock'; ?>">
									<?php /* translators: %d: number of units in stock */ echo $stock > 0 ? esc_html( sprintf( __( '%d in stock', 'zeko-shop' ), $stock ) ) : esc_html__( 'Out of stock', 'zeko-shop' ); ?>
								</div>
							<?php endif; ?>
						</div>
					</a>

					<div class="zeko-shop-card-actions">
						<?php if ( $is_program ) : ?>
							<a class="zeko-shop-btn zeko-shop-btn-primary" href="<?php echo esc_url( home_url( '/programs/' . (int) $product['external_id'] . '/' ) ); ?>">
								<?php echo esc_html__( 'View Program', 'zeko-shop' ); ?>
							</a>
						<?php elseif ( 0 === $stock ) : ?>
							<button class="zeko-shop-btn" disabled><?php echo esc_html__( 'Sold out', 'zeko-shop' ); ?></button>
						<?php elseif ( ! $is_logged_in ) : ?>
							<a class="zeko-shop-btn" href="<?php echo esc_url( wp_login_url() ); ?>"><?php echo esc_html__( 'Log in to Buy', 'zeko-shop' ); ?></a>
						<?php else : ?>
							<button
								type="button"
								class="zeko-shop-btn zeko-shop-btn-primary zeko-shop-add-to-cart"
								data-product-id="<?php echo (int) $product['product_id']; ?>"
								data-qty="1">
								<?php echo esc_html__( 'Add to Cart', 'zeko-shop' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

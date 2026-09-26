<?php
/**
 * Frontend "My Products" dashboard tab.
 *
 * Variables: $user_id, $products (array), $editing (array|null),
 *            $currencies (array), $saved (bool), $deleted (bool),
 *            $dashboard_url (string).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$delete_nonce = wp_create_nonce( 'zeko_shop_my_product' );
?>
<div class="zeko-shop">
	<?php if ( $saved ) : ?>
		<div class="zeko-shop-note zeko-shop-note-success"><?php echo esc_html__( 'Product saved.', 'zeko-shop' ); ?></div>
	<?php endif; ?>
	<?php if ( $deleted ) : ?>
		<div class="zeko-shop-note zeko-shop-note-success"><?php echo esc_html__( 'Product deleted.', 'zeko-shop' ); ?></div>
	<?php endif; ?>

	<h2><?php echo $editing ? esc_html__( 'Edit Product', 'zeko-shop' ) : esc_html__( 'Add New Product', 'zeko-shop' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zeko-shop-form zeko-shop-my-product-form">
		<input type="hidden" name="action" value="zeko_shop_save_my_product">
		<?php wp_nonce_field( 'zeko_shop_my_product' ); ?>
		<?php if ( $editing ) : ?>
			<input type="hidden" name="product_id" value="<?php echo (int) $editing['product_id']; ?>">
		<?php endif; ?>

		<p>
			<label for="zs-my-title"><?php echo esc_html__( 'Title', 'zeko-shop' ); ?> *</label><br>
			<input type="text" id="zs-my-title" name="title" required value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>" class="regular-text">
		</p>
		<p>
			<label for="zs-my-desc"><?php echo esc_html__( 'Description', 'zeko-shop' ); ?></label><br>
			<textarea id="zs-my-desc" name="description" rows="5" class="large-text"><?php echo esc_textarea( $editing['description'] ?? '' ); ?></textarea>
			<?php if ( class_exists( 'Zeko_AI_Writer_UI' ) ) : ?>
				<?php
				echo wp_kses_post(
					(string) Zeko_AI_Writer_UI::button(
						array(
							'preset' => 'product_description',
							'target' => '#zs-my-desc',
						)
					)
				);
				?>
			<?php endif; ?>
		</p>
		<p>
			<label for="zs-my-price"><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></label><br>
			<input type="number" step="0.01" min="0" id="zs-my-price" name="price" value="<?php echo esc_attr( $editing['price'] ?? '0' ); ?>">
			<select name="currency">
				<?php foreach ( $currencies as $code ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $editing['currency'] ?? 'USD', $code ); ?>><?php echo esc_html( $code ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="zs-my-image"><?php echo esc_html__( 'Image URL', 'zeko-shop' ); ?></label><br>
			<input type="url" id="zs-my-image" name="image_url" value="<?php echo esc_attr( $editing['image_url'] ?? '' ); ?>" class="regular-text">
		</p>
		<p>
			<label for="zs-my-file"><?php echo esc_html__( 'Digital File URL', 'zeko-shop' ); ?></label><br>
			<input type="url" id="zs-my-file" name="file_url" value="<?php echo esc_attr( $editing['file_url'] ?? '' ); ?>" class="regular-text">
			<span class="zeko-shop-form-hint"><?php echo esc_html__( 'Optional. Buyers get a download link after purchase.', 'zeko-shop' ); ?></span>
		</p>
		<p>
			<label for="zs-my-stock"><?php echo esc_html__( 'Stock', 'zeko-shop' ); ?></label><br>
			<input type="number" min="-1" id="zs-my-stock" name="stock" value="<?php echo esc_attr( $editing['stock'] ?? '-1' ); ?>">
			<span class="zeko-shop-form-hint"><?php echo esc_html__( 'Use -1 for unlimited (digital products).', 'zeko-shop' ); ?></span>
		</p>
		<p>
			<label><?php echo esc_html__( 'Status', 'zeko-shop' ); ?></label><br>
			<label><input type="radio" name="status" value="active" <?php checked( $editing['status'] ?? 'active', 'active' ); ?>> <?php echo esc_html__( 'Active', 'zeko-shop' ); ?></label>
			<label><input type="radio" name="status" value="inactive" <?php checked( $editing['status'] ?? 'active', 'inactive' ); ?>> <?php echo esc_html__( 'Inactive', 'zeko-shop' ); ?></label>
		</p>
		<p>
			<button type="submit" class="zeko-shop-btn zeko-shop-btn-primary"><?php echo esc_html__( 'Save Product', 'zeko-shop' ); ?></button>
			<?php if ( $editing ) : ?>
				<a class="zeko-shop-btn" href="<?php echo esc_url( $dashboard_url ); ?>"><?php echo esc_html__( 'Cancel', 'zeko-shop' ); ?></a>
			<?php endif; ?>
		</p>
	</form>

	<h2><?php echo esc_html__( 'Your Products', 'zeko-shop' ); ?></h2>
	<?php if ( empty( $products ) ) : ?>
		<p class="zeko-shop-note"><?php echo esc_html__( 'You have no products yet.', 'zeko-shop' ); ?></p>
	<?php else : ?>
		<table class="zeko-shop-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Title', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Stock', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'zeko-shop' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $products as $product ) : ?>
					<tr>
						<td><?php echo esc_html( $product['title'] ); ?></td>
						<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?></td>
						<td><?php echo (int) $product['stock'] >= 0 ? (int) $product['stock'] : esc_html__( 'Unlimited', 'zeko-shop' ); ?></td>
						<td><span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $product['status'] ); ?>"><?php echo esc_html( ucfirst( $product['status'] ) ); ?></span></td>
						<td>
							<a class="zeko-shop-btn" href="<?php echo esc_url( add_query_arg( 'edit', (int) $product['product_id'], $dashboard_url ) ); ?>"><?php echo esc_html__( 'Edit', 'zeko-shop' ); ?></a>
							<a class="zeko-shop-btn zeko-shop-btn-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=zeko_shop_delete_my_product&product_id=' . (int) $product['product_id'] ), 'zeko_shop_my_product' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this product?', 'zeko-shop' ) ); ?>');">
								<?php echo esc_html__( 'Delete', 'zeko-shop' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

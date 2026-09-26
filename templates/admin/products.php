<?php
/**
 * Admin: product list + create/edit form.
 *
 * Variables: $products (array), $currencies (array).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$editing = null;
if ( isset( $_GET['edit'] ) && absint( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	foreach ( $products as $p ) {
		if ( absint( $_GET['edit'] ) === (int) $p['product_id'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$editing = $p;
			break;
		}
	}
}
?>
<div class="wrap zeko-shop-admin">
	<h1><?php echo esc_html__( 'Shop Products', 'zeko-shop' ); ?></h1>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success"><p><?php echo esc_html__( 'Product saved.', 'zeko-shop' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success"><p><?php echo esc_html__( 'Product deleted.', 'zeko-shop' ); ?></p></div>
	<?php endif; ?>

	<h2><?php echo $editing ? esc_html__( 'Edit Product', 'zeko-shop' ) : esc_html__( 'Add New Product', 'zeko-shop' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zeko-shop-admin-form">
		<input type="hidden" name="action" value="zeko_shop_save_product">
		<?php wp_nonce_field( 'zeko_shop_admin_product' ); ?>
		<?php if ( $editing ) : ?>
			<input type="hidden" name="product_id" value="<?php echo (int) $editing['product_id']; ?>">
		<?php endif; ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="zstitle"><?php echo esc_html__( 'Title', 'zeko-shop' ); ?> *</label></th>
				<td><input type="text" id="zstitle" name="title" required value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="zsdesc"><?php echo esc_html__( 'Description', 'zeko-shop' ); ?></label></th>
				<td>
					<textarea id="zsdesc" name="description" rows="5" class="large-text"><?php echo esc_textarea( $editing['description'] ?? '' ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zsprice"><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></label></th>
				<td><input type="number" step="0.01" min="0" id="zsprice" name="price" value="<?php echo esc_attr( $editing['price'] ?? '0' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="zscurrency"><?php echo esc_html__( 'Currency', 'zeko-shop' ); ?></label></th>
				<td>
					<select id="zscurrency" name="currency">
						<?php foreach ( $currencies as $code ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $editing['currency'] ?? 'USD', $code ); ?>><?php echo esc_html( $code ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zsimage"><?php echo esc_html__( 'Image URL', 'zeko-shop' ); ?></label></th>
				<td><input type="url" id="zsimage" name="image_url" value="<?php echo esc_attr( $editing['image_url'] ?? '' ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="zsfile"><?php echo esc_html__( 'Digital File URL', 'zeko-shop' ); ?></label></th>
				<td>
					<input type="url" id="zsfile" name="file_url" value="<?php echo esc_attr( $editing['file_url'] ?? '' ); ?>" class="regular-text">
					<p class="description"><?php echo esc_html__( 'Optional. When set, buyers get a download link on the receipt after purchase. Leave empty for physical products or mentor programs.', 'zeko-shop' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="zsstock"><?php echo esc_html__( 'Stock', 'zeko-shop' ); ?></label></th>
				<td>
					<input type="number" min="-1" id="zsstock" name="stock" value="<?php echo esc_attr( $editing['stock'] ?? '-1' ); ?>">
					<p class="description"><?php echo esc_html__( 'Use -1 for unlimited (digital products).', 'zeko-shop' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Status', 'zeko-shop' ); ?></th>
				<td>
					<label>
						<input type="radio" name="status" value="active" <?php checked( $editing['status'] ?? 'active', 'active' ); ?>>
						<?php echo esc_html__( 'Active', 'zeko-shop' ); ?>
					</label>
					<br>
					<label>
						<input type="radio" name="status" value="inactive" <?php checked( $editing['status'] ?? 'active', 'inactive' ); ?>>
						<?php echo esc_html__( 'Inactive', 'zeko-shop' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Product', 'zeko-shop' ); ?></button>
			<?php if ( $editing ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zeko-shop-products' ) ); ?>"><?php echo esc_html__( 'Cancel', 'zeko-shop' ); ?></a>
			<?php endif; ?>
		</p>
	</form>

	<h2><?php echo esc_html__( 'All Products', 'zeko-shop' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'ID', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Title', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Stock', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Status', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Created', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Actions', 'zeko-shop' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $products ) ) : ?>
				<tr><td colspan="7"><?php echo esc_html__( 'No products yet.', 'zeko-shop' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $products as $product ) : ?>
				<tr>
					<td>#<?php echo (int) $product['product_id']; ?></td>
					<td><?php echo esc_html( $product['title'] ); ?></td>
					<td><?php echo esc_html( zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?></td>
					<td><?php echo (int) $product['stock'] >= 0 ? (int) $product['stock'] : esc_html__( 'Unlimited', 'zeko-shop' ); ?></td>
					<td><span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $product['status'] ); ?>"><?php echo esc_html( ucfirst( $product['status'] ) ); ?></span></td>
					<td><?php echo esc_html( get_date_from_gmt( $product['created_at'], get_option( 'date_format' ) ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=zeko-shop-products&edit=' . (int) $product['product_id'] ) ); ?>"><?php echo esc_html__( 'Edit', 'zeko-shop' ); ?></a>
						|
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=zeko_shop_delete_product&product_id=' . (int) $product['product_id'] ), 'zeko_shop_admin_product' ) ); ?>"
							onclick="return confirm('<?php echo esc_js( __( 'Delete this product?', 'zeko-shop' ) ); ?>');">
							<?php echo esc_html__( 'Delete', 'zeko-shop' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

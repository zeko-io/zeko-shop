<?php
/**
 * Admin: create a manual order for a customer.
 *
 * Variables: $products (array), $users (array).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap zeko-shop-admin">
	<h1><?php echo esc_html__( 'New Order', 'zeko-shop' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zeko-shop-admin-form">
		<input type="hidden" name="action" value="zeko_shop_create_order">
		<?php wp_nonce_field( 'zeko_shop_admin_order' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="zsorderuser"><?php echo esc_html__( 'Customer', 'zeko-shop' ); ?> *</label></th>
				<td>
					<select id="zsorderuser" name="user_id" required>
						<option value=""><?php echo esc_html__( '— Select customer —', 'zeko-shop' ); ?></option>
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo (int) $user->ID; ?>">
								<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ' — ' . $user->user_email . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h2><?php echo esc_html__( 'Items', 'zeko-shop' ); ?></h2>
		<table class="widefat striped zeko-shop-order-lines">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Product', 'zeko-shop' ); ?></th>
					<th style="width:120px;"><?php echo esc_html__( 'Qty', 'zeko-shop' ); ?></th>
					<th style="width:80px;"></th>
				</tr>
			</thead>
			<tbody id="zeko-shop-order-lines-body">
				<tr class="zeko-shop-order-line">
					<td>
						<select name="item_product_id[]">
							<option value=""><?php echo esc_html__( '— Select product —', 'zeko-shop' ); ?></option>
							<?php foreach ( $products as $product ) : ?>
								<option value="<?php echo (int) $product['product_id']; ?>">
									<?php echo esc_html( $product['title'] . ' — ' . zeko_shop_format_price( (string) $product['price'], $product['currency'] ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="number" min="1" step="1" value="1" name="item_qty[]" class="small-text"></td>
					<td><button type="button" class="button zeko-shop-order-line-remove"><?php echo esc_html__( 'Remove', 'zeko-shop' ); ?></button></td>
				</tr>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="zeko-shop-order-line-add"><?php echo esc_html__( 'Add line', 'zeko-shop' ); ?></button>
		</p>

		<p>
			<label>
				<input type="checkbox" name="charge_wallet" checked>
				<?php echo esc_html__( 'Charge the customer\'s wallet for this order.', 'zeko-shop' ); ?>
			</label>
			<span class="description"><?php echo esc_html__( 'Uncheck to record the order without a payment.', 'zeko-shop' ); ?></span>
		</p>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Create Order', 'zeko-shop' ); ?></button>
		</p>
	</form>
</div>

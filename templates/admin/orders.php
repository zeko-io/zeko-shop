<?php
/**
 * Admin: orders list.
 *
 * Variables: $orders (array).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'pending'   => __( 'Pending', 'zeko-shop' ),
	'completed' => __( 'Completed', 'zeko-shop' ),
	'refunded'  => __( 'Refunded', 'zeko-shop' ),
	'failed'    => __( 'Failed', 'zeko-shop' ),
);
?>
<div class="wrap zeko-shop-admin">
	<h1><?php echo esc_html__( 'Shop Orders', 'zeko-shop' ); ?></h1>

	<?php if ( isset( $_GET['refunded'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success"><p><?php echo esc_html__( 'Order refunded.', 'zeko-shop' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success"><p><?php /* translators: %s: order number */ echo esc_html( sprintf( __( 'Order #%s created.', 'zeko-shop' ), absint( wp_unslash( $_GET['created'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only redirect status flag for a display notice, escaped on output. ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['error'] ) && 'refund' === $_GET['error'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-error"><p><?php echo esc_html__( 'Refund failed. Check the transaction and try again.', 'zeko-shop' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Order', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Customer', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Date', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Discount', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Tax', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Total', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Status', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Tx', 'zeko-shop' ); ?></th>
				<th><?php echo esc_html__( 'Actions', 'zeko-shop' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $orders ) ) : ?>
				<tr><td colspan="10"><?php echo esc_html__( 'No orders yet.', 'zeko-shop' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $orders as $shop_order ) :
				$customer = get_userdata( (int) $shop_order['user_id'] );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $shop_order['order_number'] ); ?></strong>
						<br>
						<a href="<?php echo esc_url( Zeko_Shop_Frontend::order_url( (int) $shop_order['order_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'View receipt', 'zeko-shop' ); ?></a>
					</td>
					<td><?php echo $customer ? esc_html( $customer->display_name . ' (' . $customer->user_login . ')' ) : '#' . (int) $shop_order['user_id']; ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $shop_order['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td><?php echo esc_html( zeko_shop_format_price( (string) $shop_order['subtotal'], $shop_order['currency'] ) ); ?></td>
					<td><?php echo (float) $shop_order['discount'] > 0 ? esc_html( '−' . zeko_shop_format_price( (string) $shop_order['discount'], $shop_order['currency'] ) ) : '—'; ?></td>
					<td><?php echo (float) $shop_order['tax'] > 0 ? esc_html( zeko_shop_format_price( (string) $shop_order['tax'], $shop_order['currency'] ) ) : '—'; ?></td>
					<td><?php echo esc_html( zeko_shop_format_price( (string) $shop_order['total'], $shop_order['currency'] ) ); ?></td>
					<td><span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $shop_order['status'] ); ?>"><?php echo esc_html( $status_labels[ $shop_order['status'] ] ?? ucfirst( $shop_order['status'] ) ); ?></span></td>
					<td><?php echo (int) $shop_order['tx_id'] ? '#' . (int) $shop_order['tx_id'] : '—'; ?></td>
					<td>
						<?php if ( 'completed' === $shop_order['status'] && ! empty( $shop_order['tx_id'] ) ) : ?>
							<a class="button"
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=zeko_shop_refund_order&order_id=' . (int) $shop_order['order_id'] ), 'zeko_shop_admin_order' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Refund this order? The amount will be credited back to the customer\'s wallet.', 'zeko-shop' ) ); ?>');">
								<?php echo esc_html__( 'Refund', 'zeko-shop' ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

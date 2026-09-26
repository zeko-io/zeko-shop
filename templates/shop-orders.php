<?php
/**
 * My Orders template.
 *
 * Variables: $orders (array).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$shop_url = zeko_shop_page_url( 'shop' );

$status_labels = array(
	'pending'   => __( 'Pending', 'zeko-shop' ),
	'completed' => __( 'Completed', 'zeko-shop' ),
	'refunded'  => __( 'Refunded', 'zeko-shop' ),
	'failed'    => __( 'Failed', 'zeko-shop' ),
);
?>
<div class="zeko-shop">
	<div class="zeko-shop-header">
		<h1><?php echo esc_html__( 'My Orders', 'zeko-shop' ); ?></h1>
	</div>

	<?php if ( empty( $orders ) ) : ?>
		<div class="zeko-shop-note">
			<p><?php echo esc_html__( 'You have no orders yet.', 'zeko-shop' ); ?></p>
			<p><a class="zeko-shop-btn" href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html__( 'Start shopping', 'zeko-shop' ); ?></a></p>
		</div>
	<?php else : ?>
		<table class="zeko-shop-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Order', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Date', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Total', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'zeko-shop' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $orders as $shop_order ) :
					$item_status = $status_labels[ $shop_order['status'] ] ?? ucfirst( $shop_order['status'] );
					?>
					<tr>
						<td><strong><?php echo esc_html( $shop_order['order_number'] ); ?></strong></td>
						<td><?php echo esc_html( get_date_from_gmt( $shop_order['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $shop_order['total'], $shop_order['currency'] ) ); ?></td>
						<td><span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $shop_order['status'] ); ?>"><?php echo esc_html( $item_status ); ?></span></td>
						<td class="zeko-shop-orders-actions">
							<a class="zeko-shop-btn" href="<?php echo esc_url( Zeko_Shop_Frontend::order_url( (int) $shop_order['order_id'] ) ); ?>">
								<?php echo esc_html__( 'View', 'zeko-shop' ); ?>
							</a>
							<?php if ( 'completed' === $shop_order['status'] ) : ?>
								<?php $downloads = array(); ?>
								<?php foreach ( $this->db->get_order_items( (int) $shop_order['order_id'] ) as $row ) : ?>
									<?php if ( ! empty( $row['file_url'] ) ) : ?>
										<?php $downloads[] = $row; ?>
									<?php endif; ?>
								<?php endforeach; ?>
								<?php if ( ! empty( $downloads ) ) : ?>
									<span class="zeko-shop-order-downloads">
										<?php foreach ( $downloads as $row ) : ?>
											<a href="<?php echo esc_url( Zeko_Shop_Frontend::download_url( (int) $shop_order['order_id'], (int) $row['item_id'] ) ); ?>">
												<?php /* translators: %s: downloadable item title */ echo esc_html( sprintf( __( 'Download %s', 'zeko-shop' ), $row['title'] ) ); ?>
											</a>
										<?php endforeach; ?>
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

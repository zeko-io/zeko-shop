<?php
/**
 * Order receipt template (rendered on the /shop/order/{id}/ route).
 *
 * Variables: $args (array) — either ['message' => string] or
 *            ['order' => array, 'items' => array].
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
	</div>
	<?php
	return;
endif;

$shop_order = $args['order'];
$items = $args['items'];
$user  = get_userdata( (int) $shop_order['user_id'] );

$status_labels = array(
	'pending'   => __( 'Pending', 'zeko-shop' ),
	'completed' => __( 'Completed', 'zeko-shop' ),
	'refunded'  => __( 'Refunded', 'zeko-shop' ),
	'failed'    => __( 'Failed', 'zeko-shop' ),
);

$orders_url = zeko_shop_page_url( 'my-orders' );
?>
<div class="zeko-shop">
	<div class="zeko-shop-receipt">
		<div class="zeko-shop-receipt-header">
			<h1><?php echo esc_html__( 'Order Receipt', 'zeko-shop' ); ?></h1>
			<p class="zeko-shop-receipt-number"><?php echo esc_html( $shop_order['order_number'] ); ?></p>
			<p class="zeko-shop-receipt-date"><?php echo esc_html( get_date_from_gmt( $shop_order['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
			<span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $shop_order['status'] ); ?>">
				<?php echo esc_html( $status_labels[ $shop_order['status'] ] ?? ucfirst( $shop_order['status'] ) ); ?>
			</span>
		</div>

		<?php if ( $user ) : ?>
			<p><strong><?php echo esc_html__( 'Bill To:', 'zeko-shop' ); ?></strong><br>
				<?php echo esc_html( $user->display_name ); ?><br>
				<?php echo esc_html( $user->user_email ); ?></p>
		<?php endif; ?>

		<table class="zeko-shop-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Product', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Price', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Qty', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></th>
					<th><?php echo esc_html__( 'Downloads', 'zeko-shop' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item['title'] ); ?></td>
						<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $item['price'], $shop_order['currency'] ) ); ?></td>
						<td><?php echo (int) $item['qty']; ?></td>
						<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $item['subtotal'], $shop_order['currency'] ) ); ?></td>
						<td>
							<?php if ( 'completed' === $shop_order['status'] && ! empty( $item['file_url'] ) ) : ?>
								<a class="zeko-shop-download-link"
									href="<?php echo esc_url( Zeko_Shop_Frontend::download_url( (int) $shop_order['order_id'], (int) $item['item_id'] ) ); ?>">
									<?php echo esc_html__( 'Download', 'zeko-shop' ); ?>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="zeko-shop-cart-summary">
			<div class="zeko-shop-summary-row">
				<span><?php echo esc_html__( 'Subtotal', 'zeko-shop' ); ?></span>
				<span><?php echo wp_kses_post( zeko_shop_format_price( (string) $shop_order['subtotal'], $shop_order['currency'] ) ); ?></span>
			</div>
			<?php if ( (float) $shop_order['discount'] > 0 ) : ?>
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Discount', 'zeko-shop' ); ?></span>
					<span class="zeko-shop-discount">−<?php echo wp_kses_post( zeko_shop_format_price( (string) $shop_order['discount'], $shop_order['currency'] ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( (float) $shop_order['tax'] > 0 ) : ?>
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Tax', 'zeko-shop' ); ?></span>
					<span><?php echo wp_kses_post( zeko_shop_format_price( (string) $shop_order['tax'], $shop_order['currency'] ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( (float) $shop_order['fee'] > 0 ) : ?>
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Platform fee', 'zeko-shop' ); ?></span>
					<span><?php echo wp_kses_post( zeko_shop_format_price( (string) $shop_order['fee'], $shop_order['currency'] ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="zeko-shop-summary-row zeko-shop-summary-total">
				<span><?php echo esc_html__( 'Total', 'zeko-shop' ); ?></span>
				<span><?php echo wp_kses_post( zeko_shop_format_price( (string) $shop_order['total'], $shop_order['currency'] ) ); ?></span>
			</div>

			<?php if ( ! empty( $shop_order['tx_id'] ) ) : ?>
				<?php
				$tx_code = '';
				if ( class_exists( 'Zeko_Pay_Ledger' ) ) {
					$tx      = Zeko_Pay_Ledger::instance()->get_transaction( (int) $shop_order['tx_id'] );
					$tx_code = (string) ( $tx['code'] ?? '' );
				}
				?>
				<div class="zeko-shop-summary-row">
					<span><?php echo esc_html__( 'Transaction', 'zeko-shop' ); ?></span>
					<span><?php echo $tx_code ? '<code>' . esc_html( $tx_code ) . '</code>' : '#' . (int) $shop_order['tx_id']; ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $shop_order['invoice_id'] ) && class_exists( 'Zeko_Pay_Invoices' ) ) : ?>
				<button
					type="button"
					class="zeko-shop-btn zeko-shop-download-invoice"
					data-order-id="<?php echo (int) $shop_order['order_id']; ?>">
					<?php echo esc_html__( 'Download Invoice', 'zeko-shop' ); ?>
				</button>
			<?php endif; ?>

			<a class="zeko-shop-btn" href="<?php echo esc_url( $orders_url ); ?>"><?php echo esc_html__( 'Back to My Orders', 'zeko-shop' ); ?></a>
		</div>

		<?php
		$timeline = zeko_shop()->get_db()->get_order_timeline( (int) $shop_order['order_id'] );
		if ( ! empty( $timeline ) ) :
			?>
			<div class="zeko-shop-timeline">
				<h2><?php echo esc_html__( 'Order Timeline', 'zeko-shop' ); ?></h2>
				<table class="zeko-shop-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Date', 'zeko-shop' ); ?></th>
							<th><?php echo esc_html__( 'Status Change', 'zeko-shop' ); ?></th>
							<th><?php echo esc_html__( 'Note', 'zeko-shop' ); ?></th>
							<th><?php echo esc_html__( 'Actor', 'zeko-shop' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $timeline as $event ) : ?>
							<?php
							$from_label = $status_labels[ $event['from_status'] ] ?? ucfirst( $event['from_status'] );
							$to_label   = $status_labels[ $event['to_status'] ] ?? ucfirst( $event['to_status'] );
							$actor      = $event['actor_id'] ? get_userdata( (int) $event['actor_id'] ) : null;
							?>
							<tr>
								<td><?php echo esc_html( get_date_from_gmt( $event['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
								<td>
									<span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $event['from_status'] ); ?>"><?php echo esc_html( $from_label ); ?></span>
									&rarr;
									<span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $event['to_status'] ); ?>"><?php echo esc_html( $to_label ); ?></span>
								</td>
								<td><?php echo $event['note'] ? esc_html( $event['note'] ) : '&mdash;'; ?></td>
								<td><?php echo $actor ? esc_html( $actor->display_name ) : '&mdash;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

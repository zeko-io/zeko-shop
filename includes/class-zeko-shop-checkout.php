<?php
/**
 * Shared checkout service used by the AJAX handler, the REST API and the
 * admin manual-order screen. Owns cart validation, totals, the wallet
 * charge, order recording and post-purchase side effects.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Checkout. */
class Zeko_Shop_Checkout {

	/**
	 * Db.
	 *
	 * @var mixed Db.
	 */
	private $db;

	/**
	 * Construct.
	 *
	 * @param Zeko_Shop_DB $db Db.
	 */
	public function __construct( Zeko_Shop_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Validate the user's cart and build order item rows.
	 *
	 * @return array{items?:array,currency?:string,subtotal?:string,error?:string}
	 * @param int $user_id Current user.
	 */
	public function build_cart_items( int $user_id ): array {
		$cart = $this->db->get_cart( $user_id );
		if ( empty( $cart ) ) {
			return array( 'error' => __( 'Your cart is empty.', 'zeko-shop' ) );
		}

		$items    = array();
		$subtotal = '0.00';
		$currency = '';

		foreach ( $cart as $product_id => $qty ) {
			$product = $this->db->get_product( (int) $product_id );
			if ( ! $product || 'active' !== $product['status'] ) {
				/* translators: %s: product title */
				return array( 'error' => sprintf( __( '%s is no longer available.', 'zeko-shop' ), $product['title'] ?? '#' . $product_id ) );
			}

			// A wallet charge is a single transaction in one currency, so the.
			// whole cart must share one currency. Reject mixed carts outright.
			// instead of silently pricing them in the last product's currency.
			if ( '' === $currency ) {
				$currency = $product['currency'];
			} elseif ( $product['currency'] !== $currency ) {
				return array( 'error' => __( 'All items in your cart must use the same currency.', 'zeko-shop' ) );
			}

			$product_qty = max( 1, absint( $qty ) );
			if ( (int) $product['stock'] >= 0 && (int) $product['stock'] < $product_qty ) {
				/* translators: 1: remaining stock. 2: product title */
				return array( 'error' => sprintf( __( 'Only %1$d left in stock for %2$s.', 'zeko-shop' ), (int) $product['stock'], $product['title'] ) );
			}

			$line_total = bcmul( (string) $product['price'], (string) $product_qty, 2 );
			$items[]    = array(
				'product_id' => (int) $product['product_id'],
				'title'      => $product['title'],
				'qty'        => $product_qty,
				'price'      => (string) $product['price'],
				'subtotal'   => $line_total,
				'file_url'   => (string) ( $product['file_url'] ?? '' ),
			);
			$subtotal   = bcadd( $subtotal, $line_total, 2 );
		}

		return array(
			'items'    => $items,
			'currency' => $currency,
			'subtotal' => $subtotal,
		);
	}

	/**
	 * Place an order from the user's cart.
	 * the order without a payment (admin/manual).
	 *
	 * @return array{success:bool,error?:string,order_id?:int,tx_id?:int,items?:array,totals?:array,invoice_id?:int,redirect?:string}
	 * @param int  $user_id Current user.
	 * @param bool $charge_wallet Whether to charge the wallet (true) or record.
	 */
	public function place_order( int $user_id, bool $charge_wallet = true ): array {
		$build = $this->build_cart_items( $user_id );
		if ( isset( $build['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $build['error'],
			);
		}

		$result = $this->place_items_order( $user_id, $build['items'], $build['currency'], $charge_wallet );

		if ( ! empty( $result['success'] ) ) {
			$this->db->clear_cart( $user_id );
		}

		return $result;
	}

	/**
	 * Place an order from an explicit set of validated items. Used by the
	 * cart checkout flow and the admin manual-order screen.
	 *
	 * @return array{success:bool,error?:string,order_id?:int,tx_id?:int,items?:array,totals?:array,invoice_id?:int,redirect?:string}
	 * @param int    $user_id Buyer user ID.
	 * @param array  $items Validated items (product_id, title, qty, price, subtotal, file_url).
	 * @param string $currency Order currency.
	 * @param bool   $charge_wallet Whether to charge the wallet.
	 */
	public function place_items_order( int $user_id, array $items, string $currency, bool $charge_wallet = true ): array {
		// Authoritative totals: promo + tax + fee.
		$totals = Zeko_Shop_Totals::compute( $user_id, $items, $currency );

		if ( ! $totals['promo_valid'] ) {
			Zeko_Shop_Totals::clear_promo( $user_id );
			return array(
				'success' => false,
				'error'   => __( 'Your promo code is no longer valid. Please re-apply or remove it.', 'zeko-shop' ),
			);
		}

		$tx_id = 0;

		// Acquire the per-user lock before the wallet moves so duplicate.
		// submissions cannot double-charge.
		if ( $charge_wallet && ! $this->lock_checkout( $user_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'An order is already being processed. Please try again in a moment.', 'zeko-shop' ),
			);
		}

		// Create the order row BEFORE any money moves. If the request crashes.
		// mid-charge, a pending order always exists on record — a wallet debit.
		// can never be orphaned with no order.
		$order_id = $this->db->create_order(
			array(
				'user_id'  => $user_id,
				'status'   => $charge_wallet ? 'pending' : 'completed',
				'subtotal' => $totals['subtotal'],
				'discount' => $totals['discount'],
				'tax'      => $totals['tax'],
				'fee'      => $totals['fee'],
				'total'    => $totals['total'],
				'currency' => $currency,
				'tx_id'    => 0,
			)
		);

		if ( ! $order_id ) {
			if ( $charge_wallet ) {
				$this->unlock_checkout( $user_id );
			}
			return array(
				'success' => false,
				'error'   => __( 'The order could not be recorded. Please try again.', 'zeko-shop' ),
			);
		}

		if ( $charge_wallet ) {
			if ( ! class_exists( 'Zeko_Pay_SDK' ) || ! class_exists( 'Zeko_Pay_Integrations' ) ) {
				$this->db->update_order( $order_id, array( 'status' => 'failed' ) );
				$this->unlock_checkout( $user_id );
				return array(
					'success' => false,
					'error'   => __( 'Payments are unavailable right now.', 'zeko-shop' ),
				);
			}

			$sdk = new Zeko_Pay_SDK();
			if ( bccomp( $sdk->get_balance( $user_id ), $totals['total'], 2 ) < 0 ) {
				$this->db->update_order( $order_id, array( 'status' => 'failed' ) );
				$this->unlock_checkout( $user_id );
				return array(
					'success' => false,
					'error'   => __( 'Insufficient wallet balance to complete this order.', 'zeko-shop' ),
				);
			}

			// Charge the wallet (fee-aware). The SDK adds the same fee on $totals['amount'].
			$metadata = array(
				'order_id'   => $order_id,
				'cart'       => $this->db->get_cart( $user_id ),
				'items'      => $items,
				'subtotal'   => $totals['subtotal'],
				'discount'   => $totals['discount'],
				'tax'        => $totals['tax'],
				'fee'        => $totals['fee'],
				'currency'   => $currency,
				'promo_code' => $totals['promo']['code'] ?? '',
				'order_note' => sprintf( 'Shop purchase — %d item(s)', count( $items ) ),
			);

			$result = Zeko_Pay_Integrations::instance()->shop_process_order(
				$user_id,
				$order_id,
				(float) $totals['amount'],
				$metadata
			);

			if ( empty( $result['success'] ) ) {
				$this->db->update_order( $order_id, array( 'status' => 'failed' ) );
				$this->unlock_checkout( $user_id );
				return array(
					'success' => false,
					'error'   => $result['message'] ?? __( 'Payment failed. Please try again.', 'zeko-shop' ),
				);
			}

			$tx_id = (int) ( $result['tx_id'] ?? 0 );
			$this->db->update_order(
				$order_id,
				array(
					'status' => 'completed',
					'tx_id'  => $tx_id,
				)
			);
		}

		$this->unlock_checkout( $user_id );

		foreach ( $items as $item ) {
			$this->db->insert_order_item( $order_id, $item );
			$this->db->decrement_stock( $item['product_id'], $item['qty'] );
		}

		// Consume the promo code.
		if ( ! empty( $totals['promo']['promo_id'] ) && class_exists( 'Zeko_Pay_Promos' ) ) {
			Zeko_Pay_Promos::instance()->apply( (int) $totals['promo']['promo_id'] );
		}
		Zeko_Shop_Totals::clear_promo( $user_id );

		// Award loyalty points on paid orders.
		if ( $charge_wallet && $tx_id && class_exists( 'Zeko_Pay_Loyalty' ) ) {
			$loyalty = Zeko_Pay_Loyalty::instance();
			$points  = $loyalty->calculate_points( (string) $totals['total'] );
			if ( $points > 0 ) {
				$loyalty->add_points( $user_id, $points, 'shop-order-' . $order_id );
			}
		}

		// Generate the invoice from the transaction.
		$invoice_id = 0;
		if ( $tx_id && class_exists( 'Zeko_Pay_Invoices' ) ) {
			$invoice = Zeko_Pay_Invoices::instance()->generate( $tx_id );
			if ( ! empty( $invoice['success'] ) && ! empty( $invoice['invoice_id'] ) ) {
				$invoice_id = (int) $invoice['invoice_id'];
			}
		}
		if ( $invoice_id ) {
			$this->db->update_order( $order_id, array( 'invoice_id' => $invoice_id ) );
		}

		// Notifications (own table so the bell can filter a "Shop" module).
		$this->db->insert_notification(
			$user_id,
			'order',
			/* translators: %s: order number */
			sprintf( __( 'Order %s confirmed', 'zeko-shop' ), '#' . $order_id ),
			/* translators: %s: order total amount */
			sprintf( __( 'Your order of %s has been completed. View your receipt.', 'zeko-shop' ), $currency . ' ' . $totals['total'] )
		);

		/**
		 * Fires after a shop order is completed.
		 *
		 * @param int   $order_id  Order ID.
		 * @param int   $user_id   Buyer user ID.
		 * @param int   $tx_id     Zeko Pay transaction ID (0 for manual orders).
		 * @param array $items     Purchased items.
		 */
		do_action( 'zeko_shop_order_completed', $order_id, $user_id, $tx_id, $items );

		if ( class_exists( 'Zeko_Core_Activity' ) ) {
			\Zeko_Core_Activity::get_instance()->log(
				$user_id,
				'order_completed',
				/* translators: %s: order number */
				sprintf( __( 'Purchased order %s', 'zeko-shop' ), '#' . $order_id ),
				$order_id,
				array(
					'module'   => 'shop',
					'total'    => $totals['total'],
					'currency' => $currency,
				)
			);
		}

		return array(
			'success'    => true,
			'order_id'   => $order_id,
			'tx_id'      => $tx_id,
			'tx_code'    => $result['tx_code'] ?? '',
			'items'      => $items,
			'totals'     => $totals,
			'invoice_id' => $invoice_id,
			'redirect'   => Zeko_Shop_Frontend::order_url( $order_id ),
		);
	}

	/**
	 * Acquire a per-user lock so duplicate submissions cannot double-charge
	 * the wallet. Uses a MySQL named lock (atomic compare-and-set on the
	 * shared connection) rather than a check-then-set transient, which two
	 * concurrent requests could both pass.
	 * The lock auto-releases if the connection drops mid-request.
	 *
	 * @return bool True if the lock was acquired.
	 * @param int $user_id Buyer user ID.
	 */
	private function lock_checkout( int $user_id ): bool {
		global $wpdb;
		$got = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'zeko_shop_checkout_' . $user_id )
		);
		return 1 === $got;
	}

	/**
	 * Release the checkout lock.
	 *
	 * @param int $user_id Buyer user ID.
	 */
	private function unlock_checkout( int $user_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'zeko_shop_checkout_' . $user_id )
		);
	}
}

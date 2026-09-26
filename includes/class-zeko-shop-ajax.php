<?php
/**
 * AJAX handlers: cart management and wallet checkout.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Ajax. */
class Zeko_Shop_Ajax {

	/**
	 * Db.
	 *
	 * @var mixed Db.
	 */
	private $db;

	/**
	 * Checkout.
	 *
	 * @var mixed Checkout.
	 */
	private $checkout;

	/**
	 * Construct.
	 *
	 * @param Zeko_Shop_DB $db Db.
	 */
	public function __construct( Zeko_Shop_DB $db ) {
		$this->db       = $db;
		$this->checkout = new Zeko_Shop_Checkout( $db );
	}

	/**
	 * Register AJAX actions.
	 */
	public function init(): void {
		$actions = array(
			'zeko_shop_add_to_cart',
			'zeko_shop_update_cart',
			'zeko_shop_remove_from_cart',
			'zeko_shop_mini_cart_fragment',
			'zeko_shop_checkout',
			'zeko_shop_validate_promo',
			'zeko_shop_remove_promo',
			'zeko_shop_download_invoice',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'handle_' . str_replace( 'zeko_shop_', '', $action ) ) );
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// CART.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle add to cart.
	 */
	public function handle_add_to_cart(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$user_id    = get_current_user_id();
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$qty        = max( 1, absint( $_POST['qty'] ?? 1 ) );

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to add items to your cart.', 'zeko-shop' ) ) );
		}

		$product = $this->db->get_product( $product_id );
		if ( ! $product || 'active' !== $product['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Product not available.', 'zeko-shop' ) ) );
		}

		$cart  = $this->db->get_cart( $user_id );
		$total = (int) ( $cart[ $product_id ] ?? 0 ) + $qty;
		if ( (int) $product['stock'] >= 0 && (int) $product['stock'] < $total ) {
			wp_send_json_error( array( 'message' => __( 'Not enough stock.', 'zeko-shop' ) ) );
		}

		$result = $this->db->add_to_cart( $user_id, $product_id, $qty );
		wp_send_json_success(
			array(
				'message' => __( 'Added to cart.', 'zeko-shop' ),
				'count'   => $result['count'],
			)
		);
	}

	/**
	 * Handle update cart.
	 */
	public function handle_update_cart(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$user_id    = get_current_user_id();
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$qty        = absint( $_POST['qty'] ?? 1 );

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-shop' ) ) );
		}

		$product = $this->db->get_product( $product_id );
		if ( $qty > 0 ) {
			if ( ! $product || 'active' !== $product['status'] ) {
				wp_send_json_error( array( 'message' => __( 'Product not available.', 'zeko-shop' ) ) );
			}
			if ( (int) $product['stock'] >= 0 && (int) $product['stock'] < $qty ) {
				wp_send_json_error( array( 'message' => __( 'Not enough stock.', 'zeko-shop' ) ) );
			}
		}

		$result = $this->db->update_cart_item( $user_id, $product_id, $qty );
		wp_send_json_success(
			array(
				'message' => __( 'Cart updated.', 'zeko-shop' ),
				'count'   => $result['count'],
			)
		);
	}

	/**
	 * Handle remove from cart.
	 */
	public function handle_remove_from_cart(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$user_id    = get_current_user_id();
		$product_id = absint( $_POST['product_id'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-shop' ) ) );
		}

		$result = $this->db->remove_from_cart( $user_id, $product_id );
		wp_send_json_success(
			array(
				'message' => __( 'Item removed from cart.', 'zeko-shop' ),
				'count'   => $result['count'],
			)
		);
	}

	/**
	 * Render the mini-cart panel HTML plus the cart count so the header can
	 * refresh without a full page reload.
	 */
	public function handle_mini_cart_fragment(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-shop' ) ) );
		}

		$frontend = new Zeko_Shop_Frontend( $this->db );

		wp_send_json_success(
			array(
				'html'  => $frontend->shortcode_mini_cart(),
				'count' => array_sum( $this->db->get_cart( $user_id ) ),
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// CHECKOUT.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle checkout.
	 */
	public function handle_checkout(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		if ( class_exists( 'Zeko_Pay_Rate_Limiter' ) ) {
			Zeko_Pay_Rate_Limiter::check_ajax( 'zeko_shop_checkout' );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to check out.', 'zeko-shop' ) ) );
		}

		$result = $this->checkout->place_order( $user_id, true );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ?? __( 'Your order could not be placed. Please try again.', 'zeko-shop' ) ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Order placed successfully.', 'zeko-shop' ),
				'order_id' => $result['order_id'],
				'tx_id'    => $result['tx_id'],
				'tx_code'  => $result['tx_code'] ?? '',
				'redirect' => $result['redirect'],
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════.
	// PROMO.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Validate a promo code against the current cart and apply it.
	 */
	public function handle_validate_promo(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-shop' ) ) );
		}

		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		if ( '' === $code ) {
			wp_send_json_error( array( 'message' => __( 'Enter a promo code.', 'zeko-shop' ) ) );
		}

		$build = $this->checkout->build_cart_items( $user_id );
		if ( isset( $build['error'] ) ) {
			wp_send_json_error( array( 'message' => $build['error'] ) );
		}

		if ( ! class_exists( 'Zeko_Pay_Promos' ) ) {
			wp_send_json_error( array( 'message' => __( 'Promo codes are unavailable right now.', 'zeko-shop' ) ) );
		}

		$result = Zeko_Pay_Promos::instance()->validate( $code, (float) $build['subtotal'], $user_id );

		if ( empty( $result['valid'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Promo code is not valid.', 'zeko-shop' ) ) );
		}

		Zeko_Shop_Totals::set_promo( $user_id, $code, (int) ( $result['promo_id'] ?? 0 ) );

		$totals = Zeko_Shop_Totals::compute( $user_id, $build['items'], $build['currency'] );

		wp_send_json_success(
			array(
				'message' => __( 'Promo code applied.', 'zeko-shop' ),
				'totals'  => $totals,
			)
		);
	}

	/**
	 * Remove the applied promo code.
	 */
	public function handle_remove_promo(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'zeko-shop' ) ) );
		}

		Zeko_Shop_Totals::clear_promo( $user_id );

		wp_send_json_success( array( 'message' => __( 'Promo code removed.', 'zeko-shop' ) ) );
	}

	// ═══════════════════════════════════════════════════════════════.
	// INVOICE DOWNLOAD.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Handle download invoice.
	 */
	public function handle_download_invoice(): void {
		check_ajax_referer( 'zeko_shop_checkout', 'nonce' );

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = $this->db->get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'zeko-shop' ) ) );
		}

		$user_id = get_current_user_id();
		if ( (int) $order['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission for this invoice.', 'zeko-shop' ) ) );
		}

		if ( ! $order['invoice_id'] || ! class_exists( 'Zeko_Pay_Invoices' ) ) {
			wp_send_json_error( array( 'message' => __( 'No invoice is available for this order.', 'zeko-shop' ) ) );
		}

		$html = Zeko_Pay_Invoices::instance()->render_html( (int) $order['invoice_id'] );
		if ( '' === $html ) {
			wp_send_json_error( array( 'message' => __( 'Invoice could not be rendered.', 'zeko-shop' ) ) );
		}

		$filename = 'invoice-' . sanitize_title( $order['order_number'] ) . '.html';

		wp_send_json_success(
			array(
				'filename' => $filename,
				'html'     => $html,
			)
		);
	}
}

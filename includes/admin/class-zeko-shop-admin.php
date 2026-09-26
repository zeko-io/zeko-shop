<?php
/**
 * Admin screens: product management and order management.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Admin. */
class Zeko_Shop_Admin {

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
	 * Register admin hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register submenu pages under Zeko Pay.
	 */
	public function register_menus(): void {
		add_submenu_page(
			'zeko-pay',
			__( 'Shop Products', 'zeko-shop' ),
			__( 'Shop Products', 'zeko-shop' ),
			'manage_options',
			'zeko-shop-products',
			array( $this, 'render_products' )
		);

		add_submenu_page(
			'zeko-pay',
			__( 'Shop Orders', 'zeko-shop' ),
			__( 'Shop Orders', 'zeko-shop' ),
			'manage_options',
			'zeko-shop-orders',
			array( $this, 'render_orders' )
		);

		add_submenu_page(
			'zeko-pay',
			__( 'New Order', 'zeko-shop' ),
			__( 'New Order', 'zeko-shop' ),
			'manage_options',
			'zeko-shop-new-order',
			array( $this, 'render_new_order' )
		);
	}

	/**
	 * Register admin_post handlers.
	 */
	public function register_actions(): void {
		add_action( 'admin_post_zeko_shop_save_product', array( $this, 'handle_save_product' ) );
		add_action( 'admin_post_zeko_shop_delete_product', array( $this, 'handle_delete_product' ) );
		add_action( 'admin_post_zeko_shop_refund_order', array( $this, 'handle_refund_order' ) );
		add_action( 'admin_post_zeko_shop_create_order', array( $this, 'handle_create_order' ) );
	}

	/**
	 * Admin styles.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'zeko-shop' ) ) {
			return;
		}
		wp_enqueue_style(
			'zeko-shop-admin',
			ZEKO_SHOP_PLUGIN_URL . 'assets/css/zeko-shop-admin.css',
			array(),
			ZEKO_SHOP_VERSION
		);

		if ( false !== strpos( $hook, 'zeko-shop-new-order' ) ) {
			wp_enqueue_script(
				'zeko-shop-admin',
				ZEKO_SHOP_PLUGIN_URL . 'assets/js/zeko-shop-admin.js',
				array( 'jquery' ),
				ZEKO_SHOP_VERSION,
				true
			);
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// PRODUCTS SCREEN.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render products.
	 */
	public function render_products(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}

		$products = $this->db->get_products(
			array(
				'limit' => 200,
			)
		);

		$currencies = array( 'USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'CAD', 'AUD', 'INR' );
		if ( class_exists( 'Zeko_Pay_Currency' ) && method_exists( 'Zeko_Pay_Currency', 'instance' ) ) {
			$supported = Zeko_Pay_Currency::instance()->get_supported();
			if ( is_array( $supported ) && ! empty( $supported ) ) {
				$currencies = $supported;
			}
		}

		include ZEKO_SHOP_PLUGIN_PATH . 'templates/admin/products.php';
	}

	/**
	 * Handle save product.
	 */
	public function handle_save_product(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}
		check_admin_referer( 'zeko_shop_admin_product' );

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$data       = array(
			'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description' => wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) ),
			'price'       => (string) sanitize_text_field( wp_unslash( $_POST['price'] ?? '0' ) ),
			'currency'    => strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ) ),
			'image_url'   => esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) ),
			'file_url'    => esc_url_raw( wp_unslash( $_POST['file_url'] ?? '' ) ),
			'stock'       => (int) sanitize_text_field( wp_unslash( $_POST['stock'] ?? -1 ) ),
			'status'      => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'active',
		);

		if ( '' === $data['title'] ) {
			wp_die( esc_html__( 'Product title is required.', 'zeko-shop' ) );
		}

		if ( $product_id ) {
			$this->db->update_product( $product_id, $data );
		} else {
			$this->db->create_product( $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=zeko-shop-products&saved=1' ) );
		exit;
	}

	/**
	 * Handle delete product.
	 */
	public function handle_delete_product(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}
		check_admin_referer( 'zeko_shop_admin_product' );

		$product_id = absint( $_GET['product_id'] ?? 0 );
		if ( $product_id ) {
			$this->db->delete_product( $product_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=zeko-shop-products&deleted=1' ) );
		exit;
	}

	// ═══════════════════════════════════════════════════════════════.
	// ORDERS SCREEN.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render orders.
	 */
	public function render_orders(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}

		$orders = $this->db->get_orders(
			array(
				'limit' => 200,
			)
		);

		include ZEKO_SHOP_PLUGIN_PATH . 'templates/admin/orders.php';
	}

	/**
	 * Handle refund order.
	 */
	public function handle_refund_order(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}
		check_admin_referer( 'zeko_shop_admin_order' );

		$order_id = absint( $_GET['order_id'] ?? 0 );
		$order    = $order_id ? $this->db->get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'zeko-shop' ) );
		}

		if ( 'completed' !== $order['status'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=zeko-shop-orders&error=refund' ) );
			exit;
		}

		$refunded = false;
		if ( ! empty( $order['tx_id'] ) && class_exists( 'Zeko_Pay_Integrations' ) ) {
			$result   = Zeko_Pay_Integrations::instance()->shop_refund_order( (int) $order['tx_id'], 'Shop order refund' );
			$refunded = ! empty( $result['success'] );
		}

		if ( $refunded ) {
			$this->db->update_order( $order_id, array( 'status' => 'refunded' ) );

			$this->db->insert_notification(
				(int) $order['user_id'],
				'refund',
				/* translators: %s: order number */
				sprintf( __( 'Order %s refunded', 'zeko-shop' ), $order['order_number'] ),
				__( 'Your order was refunded. The amount was credited back to your wallet.', 'zeko-shop' )
			);

			do_action( 'zeko_shop_order_refunded', $order_id, (int) $order['user_id'], (int) $order['tx_id'] );

			if ( class_exists( 'Zeko_Core_Activity' ) ) {
				\Zeko_Core_Activity::get_instance()->log(
					(int) $order['user_id'],
					'order_refunded',
					/* translators: %s: order number */
					sprintf( __( 'Order %s refunded', 'zeko-shop' ), $order['order_number'] ),
					$order_id,
					array( 'module' => 'shop' )
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=zeko-shop-orders&refunded=1' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=zeko-shop-orders&error=refund' ) );
		}
		exit;
	}

	// ═══════════════════════════════════════════════════════════════.
	// MANUAL ORDER SCREEN.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Render new order.
	 */
	public function render_new_order(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}

		$products = $this->db->get_products(
			array(
				'status' => 'active',
				'limit'  => 500,
			)
		);

		$users = get_users(
			array(
				'orderby' => 'display_name',
				'number'  => 500,
				'fields'  => array( 'ID', 'user_login', 'display_name', 'user_email' ),
			)
		);

		include ZEKO_SHOP_PLUGIN_PATH . 'templates/admin/new-order.php';
	}

	/**
	 * Handle create order.
	 */
	public function handle_create_order(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}
		check_admin_referer( 'zeko_shop_admin_order' );

		$user_id = absint( $_POST['user_id'] ?? 0 );
		$charge  = ! empty( $_POST['charge_wallet'] );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'Please choose a customer.', 'zeko-shop' ) );
		}

		$product_ids = isset( $_POST['item_product_id'] ) ? array_map( 'absint', (array) $_POST['item_product_id'] ) : array();
		$quantities  = isset( $_POST['item_qty'] ) ? array_map( 'absint', (array) $_POST['item_qty'] ) : array();

		$items    = array();
		$currency = 'USD';

		foreach ( $product_ids as $i => $product_id ) {
			$qty = max( 1, (int) ( $quantities[ $i ] ?? 1 ) );

			if ( $product_id <= 0 ) {
				continue;
			}

			$product = $this->db->get_product( $product_id );
			if ( ! $product || 'active' !== $product['status'] ) {
				wp_die( esc_html__( 'One of the selected products is not available.', 'zeko-shop' ) );
			}
			if ( (int) $product['stock'] >= 0 && (int) $product['stock'] < $qty ) {
				wp_die( esc_html__( 'One of the selected products does not have enough stock.', 'zeko-shop' ) );
			}

			$currency = $product['currency'];
			$items[]  = array(
				'product_id' => (int) $product['product_id'],
				'title'      => $product['title'],
				'qty'        => $qty,
				'price'      => (string) $product['price'],
				'subtotal'   => bcmul( (string) $product['price'], (string) $qty, 2 ),
				'file_url'   => (string) ( $product['file_url'] ?? '' ),
			);
		}

		if ( empty( $items ) ) {
			wp_die( esc_html__( 'Add at least one product line.', 'zeko-shop' ) );
		}

		$checkout = new Zeko_Shop_Checkout( $this->db );
		$result   = $checkout->place_items_order( $user_id, $items, $currency, $charge );

		if ( empty( $result['success'] ) ) {
			wp_die( esc_html( $result['error'] ?? __( 'The order could not be created.', 'zeko-shop' ) ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=zeko-shop-orders&created=' . (int) $result['order_id'] ) );
		exit;
	}
}

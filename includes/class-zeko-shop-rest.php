<?php
/**
 * REST API for Zeko Shop: products, cart, checkout and orders.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Rest. */
class Zeko_Shop_Rest {

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
	 * Initialize hooks.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'zeko-shop/v1',
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							$value = (int) $value;
							return $value >= 1 && $value <= 100;
						},
					),
					'page'   => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ): bool {
							$value = (int) $value;
							return $value >= 1 && $value <= 100000;
						},
					),
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'zeko-shop/v1',
			'/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'zeko-shop/v1',
			'/cart',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cart' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			'zeko-shop/v1',
			'/cart/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_to_cart' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'id'  => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'qty' => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_from_cart' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'zeko-shop/v1',
			'/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'checkout' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			'zeko-shop/v1',
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			'zeko-shop/v1',
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'require_login' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission: logged-in user required.
	 */
	public function require_login(): bool {
		return is_user_logged_in();
	}

	/**
	 * GET /products
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_products( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$page  = max( 1, (int) $request->get_param( 'page' ) );

		$products = $this->db->get_products(
			array(
				'status' => 'active',
				'limit'  => $limit,
				'offset' => ( $page - 1 ) * $limit,
				'search' => (string) $request->get_param( 'search' ),
			)
		);

		$data = array();
		foreach ( $products as $product ) {
			$data[] = $this->prepare_product( $product );
		}

		return new WP_REST_Response( array( 'products' => $data ), 200 );
	}

	/**
	 * GET /products/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_product( WP_REST_Request $request ): WP_REST_Response {
		$product = $this->db->get_product( (int) $request->get_param( 'id' ) );
		if ( ! $product || 'active' !== $product['status'] ) {
			return new WP_REST_Response( array( 'message' => __( 'Product not found.', 'zeko-shop' ) ), 404 );
		}
		return new WP_REST_Response( array( 'product' => $this->prepare_product( $product ) ), 200 );
	}

	/**
	 * GET /cart
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_cart( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$user_id = get_current_user_id();
		$cart    = $this->db->get_cart( $user_id );

		$items = array();
		$total = '0.00';
		foreach ( $cart as $product_id => $qty ) {
			$product = $this->db->get_product( $product_id );
			if ( ! $product || 'active' !== $product['status'] ) {
				continue;
			}
			$items[] = array(
				'product_id' => (int) $product_id,
				'title'      => $product['title'],
				'qty'        => (int) $qty,
				'price'      => (string) $product['price'],
				'subtotal'   => bcmul( (string) $product['price'], (string) $qty, 2 ),
				'currency'   => $product['currency'],
			);
			$total   = bcadd( $total, bcmul( (string) $product['price'], (string) $qty, 2 ), 2 );
		}

		return new WP_REST_Response(
			array(
				'items'      => $items,
				'item_count' => (int) array_sum( array_column( $items, 'qty' ) ),
				'total'      => $total,
			),
			200
		);
	}

	/**
	 * POST /cart/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function add_to_cart( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'id' );
		$qty        = max( 1, (int) $request->get_param( 'qty' ) );

		$product = $this->db->get_product( $product_id );
		if ( ! $product || 'active' !== $product['status'] ) {
			return new WP_REST_Response( array( 'message' => __( 'Product not available.', 'zeko-shop' ) ), 404 );
		}
		$cart  = $this->db->get_cart( get_current_user_id() );
		$total = (int) ( $cart[ $product_id ] ?? 0 ) + $qty;
		if ( (int) $product['stock'] >= 0 && (int) $product['stock'] < $total ) {
			return new WP_REST_Response( array( 'message' => __( 'Not enough stock.', 'zeko-shop' ) ), 409 );
		}

		$result = $this->db->add_to_cart( get_current_user_id(), $product_id, $qty );

		return new WP_REST_Response(
			array(
				'message' => __( 'Added to cart.', 'zeko-shop' ),
				'count'   => $result['count'],
			),
			200
		);
	}

	/**
	 * DELETE /cart/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function remove_from_cart( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->db->remove_from_cart( get_current_user_id(), (int) $request->get_param( 'id' ) );

		return new WP_REST_Response(
			array(
				'message' => __( 'Item removed from cart.', 'zeko-shop' ),
				'count'   => $result['count'],
			),
			200
		);
	}

	/**
	 * POST /checkout
	 *
	 * @param WP_REST_Request $_request request.
	 */
	public function checkout( WP_REST_Request $_request ): WP_REST_Response {
		if ( class_exists( 'Zeko_Pay_Utils' ) && ! Zeko_Pay_Utils::check_rate_limit( 'zeko_shop_checkout', get_current_user_id() ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Too many requests. Please try again later.', 'zeko-shop' ) ), 429 );
		}

		$result = $this->checkout->place_order( get_current_user_id(), true );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response( array( 'message' => $result['error'] ?? __( 'Your order could not be placed.', 'zeko-shop' ) ), 400 );
		}

		return new WP_REST_Response(
			array(
				'message'  => __( 'Order placed successfully.', 'zeko-shop' ),
				'order_id' => $result['order_id'],
				'tx_id'    => $result['tx_id'],
				'tx_code'  => $result['tx_code'] ?? '',
				'redirect' => $result['redirect'],
			),
			200
		);
	}

	/**
	 * GET /orders
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_orders( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$orders = $this->db->get_user_orders( get_current_user_id(), 50 );

		$data = array();
		foreach ( $orders as $order ) {
			$data[] = $this->prepare_order( $order );
		}

		return new WP_REST_Response( array( 'orders' => $data ), 200 );
	}

	/**
	 * GET /orders/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_order( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->db->get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_REST_Response( array( 'message' => __( 'Order not found.', 'zeko-shop' ) ), 404 );
		}

		$user_id = get_current_user_id();
		if ( (int) $order['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'You do not have permission for this order.', 'zeko-shop' ) ), 403 );
		}

		return new WP_REST_Response( array( 'order' => $this->prepare_order( $order, true ) ), 200 );
	}

	/**
	 * Prepare a product for the API. Never expose the file URL publicly.
	 *
	 * @param array $product Product.
	 */
	private function prepare_product( array $product ): array {
		return array(
			'product_id'  => (int) $product['product_id'],
			'title'       => $product['title'],
			'description' => $product['description'],
			'price'       => (string) $product['price'],
			'currency'    => $product['currency'],
			'image_url'   => $product['image_url'],
			'is_digital'  => ! empty( $product['file_url'] ),
			'stock'       => (int) $product['stock'],
			'external'    => array(
				'type' => $product['external_type'],
				'id'   => (int) $product['external_id'],
			),
		);
	}

	/**
	 * Prepare an order for the API.
	 *
	 * @param array $order Order.
	 * @param bool  $with_items With items.
	 */
	private function prepare_order( array $order, bool $with_items = false ): array {
		$data = array(
			'order_id'     => (int) $order['order_id'],
			'order_number' => $order['order_number'],
			'status'       => $order['status'],
			'subtotal'     => (string) $order['subtotal'],
			'discount'     => (string) $order['discount'],
			'tax'          => (string) $order['tax'],
			'fee'          => (string) $order['fee'],
			'total'        => (string) $order['total'],
			'currency'     => $order['currency'],
			'created_at'   => $order['created_at'],
			'receipt_url'  => Zeko_Shop_Frontend::order_url( (int) $order['order_id'] ),
		);

		if ( ! empty( $order['invoice_id'] ) ) {
			$data['invoice_id'] = (int) $order['invoice_id'];
		}
		if ( ! empty( $order['tx_id'] ) ) {
			$data['tx_id'] = (int) $order['tx_id'];
		}

		if ( $with_items ) {
			$data['items'] = array();
			foreach ( $this->db->get_order_items( (int) $order['order_id'] ) as $item ) {
				$row = array(
					'item_id'    => (int) $item['item_id'],
					'product_id' => (int) $item['product_id'],
					'title'      => $item['title'],
					'qty'        => (int) $item['qty'],
					'price'      => (string) $item['price'],
					'subtotal'   => (string) $item['subtotal'],
				);
				if ( ! empty( $item['file_url'] ) && 'completed' === $order['status'] ) {
					$row['download_url'] = Zeko_Shop_Frontend::download_url( (int) $order['order_id'], (int) $item['item_id'] );
				}
				$data['items'][] = $row;
			}
		}

		return $data;
	}
}

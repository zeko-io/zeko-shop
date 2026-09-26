<?php
/**
 * Frontend handlers: shortcodes, order route, assets.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Frontend. */
class Zeko_Shop_Frontend {

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
	 * Initialize hooks.
	 */
	public function init(): void {
		add_shortcode( 'zeko_shop', array( $this, 'shortcode_catalog' ) );
		add_shortcode( 'zeko_shop_cart', array( $this, 'shortcode_cart' ) );
		add_shortcode( 'zeko_shop_checkout', array( $this, 'shortcode_checkout' ) );
		add_shortcode( 'zeko_shop_orders', array( $this, 'shortcode_orders' ) );

		add_action( 'init', array( $this, 'register_order_route' ), 10 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_download' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_render_order_page' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_product_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'zeko_shop_cart_badge', array( $this, 'shortcode_cart_badge' ) );
		add_shortcode( 'zeko_shop_cart_count', array( $this, 'shortcode_cart_count' ) );
		add_shortcode( 'zeko_shop_mini_cart', array( $this, 'shortcode_mini_cart' ) );
		add_filter( 'zeko_nav_items', array( $this, 'register_nav_items' ) );

		// Theme dashboard tab.
		add_filter( 'zeko_dashboard_tabs', array( $this, 'add_dashboard_tab' ) );
		add_action( 'zeko_dashboard_tab_content_shop', array( $this, 'render_dashboard_tab' ) );
		add_action( 'zeko_dashboard_tab_content_shop-products', array( $this, 'render_dashboard_products_tab' ) );

		// Mentor product management.
		add_filter( 'user_has_cap', array( $this, 'grant_manage_products_cap' ), 10, 4 );
		add_action( 'admin_post_zeko_shop_save_my_product', array( $this, 'handle_save_my_product' ) );
		add_action( 'admin_post_zeko_shop_delete_my_product', array( $this, 'handle_delete_my_product' ) );
	}

	/**
	 * Grant the "manage own products" capability to active mentors.
	 * Mentors are a custom-table role (not a WP role), so the capability is
	 * granted dynamically via user_has_cap whenever the current user has an
	 * active mentor profile. Admins keep full control via manage_options.
	 *
	 * @param array   $allcaps Allcaps.
	 * @param array   $caps Caps.
	 * @param array   $args Args.
	 * @param WP_User $user User.
	 */
	public function grant_manage_products_cap( array $allcaps, array $caps, array $args, WP_User $user ): array {
		unset( $user );
		if ( ! in_array( 'zeko_shop_manage_products', $caps, true ) ) {
			return $allcaps;
		}

		$user_id = (int) ( $args[1] ?? 0 );
		if ( $user_id > 0 && class_exists( 'Zeko_Mentor' ) && method_exists( 'Zeko_Mentor', 'instance' ) ) {
			$mentor_db = Zeko_Mentor::instance()->get_db();
			if ( $mentor_db && method_exists( $mentor_db, 'is_mentor' ) && $mentor_db->is_mentor( $user_id ) ) {
				$allcaps['zeko_shop_manage_products'] = true;
			}
		}

		return $allcaps;
	}

	/**
	 * Whether the current user may manage their own shop products.
	 */
	public function user_can_manage_products(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'zeko_shop_manage_products' );
	}

	/**
	 * Supported currencies (falls back to Zeko Pay's list when available).
	 */
	public function supported_currencies(): array {
		$currencies = array( 'USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'CAD', 'AUD', 'INR' );
		if ( class_exists( 'Zeko_Pay_Currency' ) && method_exists( 'Zeko_Pay_Currency', 'instance' ) ) {
			$supported = Zeko_Pay_Currency::instance()->get_supported();
			if ( is_array( $supported ) && ! empty( $supported ) ) {
				$currencies = $supported;
			}
		}
		return $currencies;
	}

	/**
	 * Add "My Shop" tab to the Zeko dashboard. String labels match the
	 * format the theme currently renders for dashboard tabs.
	 *
	 * @param array $tabs Tabs.
	 */
	public function add_dashboard_tab( array $tabs ): array {
		$tabs['shop'] = __( 'My Shop', 'zeko-shop' );

		if ( $this->user_can_manage_products() ) {
			$tabs['shop-products'] = __( 'My Products', 'zeko-shop' );
		}

		return $tabs;
	}

	/**
	 * Render the "My Shop" dashboard tab: cart summary + recent orders.
	 */
	public function render_dashboard_tab(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$cart = $this->db->get_cart( $user_id );
		if ( ! empty( $cart ) ) {
			$cart_items = 0;
			$subtotal   = '0.00';
			$currency   = 'USD';
			foreach ( $cart as $product_id => $qty ) {
				$product = $this->db->get_product( (int) $product_id );
				if ( ! $product || 'active' !== $product['status'] ) {
					continue;
				}
				$currency    = $product['currency'];
				$cart_items += absint( $qty );
				$subtotal    = bcadd( $subtotal, bcmul( (string) $product['price'], (string) $qty, 2 ), 2 );
			}
		} else {
			$cart_items = 0;
			$subtotal   = '0.00';
			$currency   = 'USD';
		}

		$orders = $this->db->get_user_orders( $user_id, 5 );

		$status_labels = array(
			'pending'   => __( 'Pending', 'zeko-shop' ),
			'completed' => __( 'Completed', 'zeko-shop' ),
			'refunded'  => __( 'Refunded', 'zeko-shop' ),
			'failed'    => __( 'Failed', 'zeko-shop' ),
		);

		$shop_url     = zeko_shop_page_url( 'shop' );
		$cart_url     = zeko_shop_page_url( 'cart' );
		$checkout_url = zeko_shop_page_url( 'checkout' );
		$orders_url   = zeko_shop_page_url( 'my-orders' );

		?>
		<div class="zeko-shop">
			<div class="zeko-shop-cart-summary">
				<strong><?php echo esc_html__( 'Cart', 'zeko-shop' ); ?>:</strong>
				<?php if ( $cart_items > 0 ) : ?>
					<a href="<?php echo esc_url( $cart_url ); ?>"><?php /* translators: %d: number of items */ echo esc_html( sprintf( _n( '%d item', '%d items', $cart_items, 'zeko-shop' ), $cart_items ) ); ?></a>
					&mdash; <?php echo wp_kses_post( zeko_shop_format_price( $subtotal, $currency ) ); ?>
					<a class="zeko-shop-btn zeko-shop-btn-primary" href="<?php echo esc_url( $checkout_url ); ?>"><?php echo esc_html__( 'Checkout', 'zeko-shop' ); ?></a>
				<?php else : ?>
					<?php echo esc_html__( 'Your cart is empty.', 'zeko-shop' ); ?>
					<a class="zeko-shop-btn" href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html__( 'Browse products', 'zeko-shop' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( empty( $orders ) ) : ?>
				<p class="zeko-shop-note"><?php echo esc_html__( 'You have no orders yet.', 'zeko-shop' ); ?></p>
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
						foreach ( $orders as $order ) :
							$status = $status_labels[ $order['status'] ] ?? ucfirst( $order['status'] );
							?>
							<tr>
								<td><strong><?php echo esc_html( $order['order_number'] ); ?></strong></td>
								<td><?php echo esc_html( get_date_from_gmt( $order['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
								<td><?php echo wp_kses_post( zeko_shop_format_price( (string) $order['total'], $order['currency'] ) ); ?></td>
								<td><span class="zeko-shop-status zeko-shop-status-<?php echo esc_attr( $order['status'] ); ?>"><?php echo esc_html( $status ); ?></span></td>
								<td>
									<a class="zeko-shop-btn" href="<?php echo esc_url( self::order_url( (int) $order['order_id'] ) ); ?>">
										<?php echo esc_html__( 'View', 'zeko-shop' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><a class="zeko-shop-btn" href="<?php echo esc_url( $orders_url ); ?>"><?php echo esc_html__( 'View all orders', 'zeko-shop' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "My Products" dashboard tab: a list of the user's own
	 * products with create/edit/delete controls.
	 */
	public function render_dashboard_products_tab(): void {
		if ( ! $this->user_can_manage_products() ) {
			return;
		}

		$user_id    = get_current_user_id();
		$currencies = $this->supported_currencies();
		$editing    = null;
		$saved      = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deleted    = isset( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Only products the current user owns (session/program products are.
		// owned by the sync bridges and managed from the mentor dashboard).
		$products = $this->db->get_products_by_external( 'shop', $user_id );

		// Editing? Resolve the product and verify ownership.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_id > 0 ) {
			foreach ( $products as $product ) {
				if ( (int) $product['product_id'] === $edit_id ) {
					$editing = $product;
					break;
				}
			}
		}

		$dashboard_url = home_url( '/dashboard/?tab=shop-products' );

		include ZEKO_SHOP_PLUGIN_PATH . 'templates/my-products.php';
	}

	/**
	 * Save (create or update) one of the current user's products.
	 */
	public function handle_save_my_product(): void {
		if ( ! $this->user_can_manage_products() ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}
		check_admin_referer( 'zeko_shop_my_product' );

		$user_id    = get_current_user_id();
		$product_id = absint( $_POST['product_id'] ?? 0 );

		$data = array(
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

		// Never let a user override a synced product's ownership link.
		if ( $product_id ) {
			$product = $this->db->get_product( $product_id );
			if ( ! $product || 'shop' !== $product['external_type'] || (int) $product['external_id'] !== $user_id ) {
				wp_die( esc_html__( 'You can only edit your own products.', 'zeko-shop' ) );
			}
			$this->db->update_product( $product_id, $data );
		} else {
			$this->db->create_product(
				array_merge(
					$data,
					array(
						'external_type' => 'shop',
						'external_id'   => $user_id,
					)
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'saved', '1', home_url( '/dashboard/?tab=shop-products' ) ) );
		exit;
	}

	/**
	 * Delete one of the current user's products.
	 */
	public function handle_delete_my_product(): void {
		if ( ! $this->user_can_manage_products() ) {
			wp_die( esc_html__( 'Access denied.', 'zeko-shop' ) );
		}
		check_admin_referer( 'zeko_shop_my_product' );

		$user_id    = get_current_user_id();
		$product_id = absint( $_GET['product_id'] ?? 0 );

		$product = $product_id ? $this->db->get_product( $product_id ) : null;
		if ( $product && 'shop' === $product['external_type'] && (int) $product['external_id'] === $user_id ) {
			$this->db->delete_product( $product_id );
		}

		wp_safe_redirect( add_query_arg( 'deleted', '1', home_url( '/dashboard/?tab=shop-products' ) ) );
		exit;
	}

	/**
	 * Register Shop nav items via the core zeko_nav_items registry.
	 *
	 * @param array $locations Locations.
	 */
	public function register_nav_items( array $locations ): array {
		$locations['primary'][] = array(
			'title'    => __( 'Shop', 'zeko-shop' ),
			'url'      => zeko_shop_page_url( 'shop' ),
			'order'    => 8,
			'children' => array(
				array(
					'title' => __( 'Products', 'zeko-shop' ),
					'url'   => zeko_shop_page_url( 'shop' ),
				),
				array(
					'title' => __( 'Cart', 'zeko-shop' ),
					'url'   => zeko_shop_page_url( 'cart' ),
				),
				array(
					'title' => __( 'My Orders', 'zeko-shop' ),
					'url'   => zeko_shop_page_url( 'my-orders' ),
				),
			),
		);
		return $locations;
	}

	/**
	 * Append a "Shop" item with child pages to the primary nav. Follows the
	 * same pattern as the Mentorship menu items in Zeko Mentor.
	 *
	 * @deprecated Use register_nav_items() via the zeko_nav_items filter.
	 */
	public function maybe_create_nav_menu_items(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$done = get_option( 'zeko_shop_primary_menu_done', false );
		if ( $done ) {
			return;
		}

		$locations = get_nav_menu_locations();
		if ( empty( $locations['primary'] ) ) {
			return;
		}

		$menu_id = $locations['primary'];
		$items   = wp_get_nav_menu_items( $menu_id );
		if ( ! $items ) {
			return;
		}

		$shop_parent_id = 0;
		$max_order      = 0;

		foreach ( $items as $item ) {
			$order = (int) $item->menu_order;
			if ( $order > $max_order ) {
				$max_order = $order;
			}
			if ( 'Shop' === $item->title ) {
				$shop_parent_id = $item->ID;
			}
		}

		$children = array(
			'Products'  => 'shop',
			'Cart'      => 'cart',
			'My Orders' => 'my-orders',
		);

		if ( ! $shop_parent_id ) {
			$parent_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => 'Shop',
					'menu-item-url'    => zeko_shop_page_url( 'shop' ),
					'menu-item-status' => 'publish',
					'menu-item-type'   => 'custom',
					'menu-item-order'  => $max_order + 1,
				)
			);

			foreach ( $children as $title => $slug ) {
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => $title,
						'menu-item-url'       => zeko_shop_page_url( $slug ),
						'menu-item-status'    => 'publish',
						'menu-item-type'      => 'custom',
						'menu-item-parent-id' => $parent_id,
					)
				);
			}
		} else {
			$existing_titles = array();
			foreach ( $items as $item ) {
				if ( (int) $item->menu_item_parent === $shop_parent_id ) {
					$existing_titles[ $item->title ] = true;
				}
			}

			foreach ( $children as $title => $slug ) {
				if ( isset( $existing_titles[ $title ] ) ) {
					continue;
				}
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => $title,
						'menu-item-url'       => zeko_shop_page_url( $slug ),
						'menu-item-status'    => 'publish',
						'menu-item-type'      => 'custom',
						'menu-item-parent-id' => $shop_parent_id,
					)
				);
			}
		}

		update_option( 'zeko_shop_primary_menu_done', true );
	}

	/**
	 * Register the /shop/order/{id}/ and /shop/download/.../ rewrite routes.
	 */
	public function register_order_route(): void {
		add_rewrite_rule( '^shop/download/([0-9]+)/([0-9]+)/?$', 'index.php?zeko_shop_download_order=$matches[1]&zeko_shop_download_item=$matches[2]', 'top' );
		add_rewrite_rule( '^shop/order/([0-9]+)/?$', 'index.php?zeko_shop_order=$matches[1]', 'top' );
		add_rewrite_rule( '^shop/product/([0-9]+)/?$', 'index.php?zeko_shop_product=$matches[1]', 'top' );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Vars.
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'zeko_shop_order';
		$vars[] = 'zeko_shop_download_order';
		$vars[] = 'zeko_shop_download_item';
		$vars[] = 'zeko_shop_product';
		return $vars;
	}

	/**
	 * Get the URL for an order receipt.
	 *
	 * @param int $order_id Order id.
	 */
	public static function order_url( int $order_id ): string {
		return home_url( '/shop/order/' . $order_id . '/' );
	}

	/**
	 * Get the URL for a product detail page.
	 *
	 * @param int $product_id Product id.
	 */
	public static function product_url( int $product_id ): string {
		return home_url( '/shop/product/' . $product_id . '/' );
	}

	/**
	 * Map a product to the Zeko module it belongs to.
	 * Products created by the sync bridges are owned by their source module
	 * (mentor sessions/programs). Manually created products belong to the
	 * Shop module itself.
	 *
	 * @return array{slug:string,label:string,class:string} Badge data.
	 * @param array $product Product row.
	 */
	public static function product_module( array $product ): array {
		$type = $product['external_type'] ?? '';

		$modules = array(
			'session' => array(
				'slug'  => 'mentor',
				'label' => __( 'Zeko Mentor', 'zeko-shop' ),
				'class' => 'zeko-shop-badge-module zeko-shop-badge-module-mentor',
			),
			'program' => array(
				'slug'  => 'mentor',
				'label' => __( 'Zeko Mentor', 'zeko-shop' ),
				'class' => 'zeko-shop-badge-module zeko-shop-badge-module-mentor',
			),
			'love'    => array(
				'slug'  => 'love',
				'label' => __( 'Zeko Love', 'zeko-shop' ),
				'class' => 'zeko-shop-badge-module zeko-shop-badge-module-love',
			),
		);

		if ( isset( $modules[ $type ] ) ) {
			$module = $modules[ $type ];
		} else {
			$module = array(
				'slug'  => 'shop',
				'label' => __( 'Zeko Shop', 'zeko-shop' ),
				'class' => 'zeko-shop-badge-module zeko-shop-badge-module-shop',
			);
		}

		/**
		 * Filter the module badge shown on a shop product card.
		 *
		 * @param array $module  Badge data (slug, label, class).
		 * @param array $product Product row.
		 */
		return apply_filters( 'zeko_shop_product_module', $module, $product );
	}

	/**
	 * Get the URL for a digital file download.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id Item id.
	 */
	public static function download_url( int $order_id, int $item_id ): string {
		return home_url( '/shop/download/' . $order_id . '/' . $item_id . '/' );
	}

	/**
	 * True if the current request is the order route.
	 */
	public function is_order_route(): bool {
		return (int) get_query_var( 'zeko_shop_order' ) > 0;
	}

	/**
	 * Render the order receipt page.
	 */
	public function maybe_render_order_page(): void {
		$order_id = (int) get_query_var( 'zeko_shop_order' );
		if ( $order_id <= 0 ) {
			return;
		}

		$order = $this->db->get_order( $order_id );
		if ( ! $order ) {
			status_header( 404 );
			nocache_headers();
			include ZEKO_SHOP_PLUGIN_PATH . 'templates/order-not-found.php';
			get_footer();
			exit;
		}

		$user_id = get_current_user_id();
		if ( ! is_user_logged_in() ) {
			$args = array(
				'message' => __( 'Please log in to view your order.', 'zeko-shop' ),
			);
		} elseif ( (int) $order['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			$args = array(
				'message' => __( 'You do not have permission to view this order.', 'zeko-shop' ),
			);
		} else {
			$items = $this->db->get_order_items( $order_id );
			$args  = array(
				'order' => $order,
				'items' => $items,
			);
		}

		get_header();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/order-receipt.php';
		get_footer();
		exit;
	}

	/**
	 * True if the current request is the product detail route.
	 */
	public function is_product_route(): bool {
		return (int) get_query_var( 'zeko_shop_product' ) > 0;
	}

	/**
	 * Render the product detail page.
	 */
	public function maybe_render_product_page(): void {
		$product_id = (int) get_query_var( 'zeko_shop_product' );
		if ( $product_id <= 0 ) {
			return;
		}

		$product = $this->db->get_product( $product_id );
		if ( ! $product ) {
			status_header( 404 );
			nocache_headers();
			$args = array(
				'message' => __( 'Product not found.', 'zeko-shop' ),
			);
		} elseif ( 'inactive' === $product['status'] ) {
			status_header( 404 );
			nocache_headers();
			$args = array(
				'message' => __( 'This product is not available.', 'zeko-shop' ),
			);
		} else {
			$args = array(
				'product' => $product,
			);
		}

		get_header();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/shop-product.php';
		get_footer();
		exit;
	}

	/**
	 * Serve a digital file download after verifying ownership.
	 */
	public function maybe_handle_download(): void {
		$order_id = (int) get_query_var( 'zeko_shop_download_order' );
		$item_id  = (int) get_query_var( 'zeko_shop_download_item' );
		if ( $order_id <= 0 || $item_id <= 0 ) {
			return;
		}

		$order = $this->db->get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'zeko-shop' ), '', array( 'response' => 404 ) );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ( (int) $order['user_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'zeko-shop' ), '', array( 'response' => 403 ) );
		}

		if ( 'completed' !== $order['status'] ) {
			wp_die( esc_html__( 'This order is not completed, so downloads are not available yet.', 'zeko-shop' ), '', array( 'response' => 403 ) );
		}

		// Rate-limit downloads per user to slow down link enumeration/abuse.
		if ( class_exists( 'Zeko_Pay_Utils' ) && ! \Zeko_Pay_Utils::check_rate_limit( 'shop_download', $user_id, 30 ) ) {
			wp_die( esc_html__( 'Too many download requests. Please try again later.', 'zeko-shop' ), '', array( 'response' => 429 ) );
		}

		$item = null;
		foreach ( $this->db->get_order_items( $order_id ) as $row ) {
			if ( (int) $row['item_id'] === $item_id ) {
				$item = $row;
				break;
			}
		}

		if ( ! $item || empty( $item['file_url'] ) ) {
			wp_die( esc_html__( 'No downloadable file is attached to this item.', 'zeko-shop' ), '', array( 'response' => 404 ) );
		}

		/**
		 * Filter the digital download URL before redirecting.
		 *
		 * @param string $url  Resolved download URL.
		 * @param int    $order_id Order ID.
		 * @param array  $item  Order item row.
		 */
		$url = apply_filters( 'zeko_shop_download_url', $item['file_url'], $order_id, $item );

		wp_safe_redirect( esc_url_raw( $url ), 302 );
		exit;
	}

	/**
	 * Cart item count for the current user.
	 */
	public function cart_count(): int {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		return (int) array_sum( $this->db->get_cart( $user_id ) );
	}

	// ═══════════════════════════════════════════════════════════════.
	// SHORTCODES.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Shortcode catalog.
	 *
	 * @param mixed $atts Atts.
	 */
	public function shortcode_catalog( $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit' => 50,
			),
			$atts,
			'zeko_shop'
		);

		$products = $this->db->get_products(
			array(
				'status' => 'active',
				'limit'  => absint( $atts['limit'] ),
			)
		);

		$output = '';
		if ( shortcode_exists( 'zeko_love_calls_strip' ) ) {
			$output .= do_shortcode( '[zeko_love_calls_strip]' );
		}

		ob_start();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/shop-catalog.php';
		return $output . (string) ob_get_clean();
	}

	/**
	 * Shortcode cart.
	 */
	public function shortcode_cart(): string {
		$user_id  = get_current_user_id();
		$items    = array();
		$subtotal = '0.00';
		$currency = 'USD';

		if ( $user_id ) {
			$cart = $this->db->get_cart( $user_id );
			foreach ( $cart as $product_id => $qty ) {
				$product = $this->db->get_product( $product_id );
				if ( ! $product || 'active' !== $product['status'] ) {
					continue;
				}
				$currency = $product['currency'];
				$items[]  = array(
					'product'  => $product,
					'qty'      => $qty,
					'subtotal' => bcmul( (string) $product['price'], (string) $qty, 2 ),
				);
				$subtotal = bcadd( $subtotal, bcmul( (string) $product['price'], (string) $qty, 2 ), 2 );
			}
		}

		ob_start();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/shop-cart.php';
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode checkout.
	 */
	public function shortcode_checkout(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '<div class="zeko-shop-note">' . esc_html__( 'Please log in to check out.', 'zeko-shop' ) . '</div>';
		}

		$cart = $this->db->get_cart( $user_id );
		if ( empty( $cart ) ) {
			return '<div class="zeko-shop-note">' . esc_html__( 'Your cart is empty.', 'zeko-shop' ) . '</div>';
		}

		$items    = array();
		$currency = 'USD';

		foreach ( $cart as $product_id => $qty ) {
			$product = $this->db->get_product( $product_id );
			if ( ! $product || 'active' !== $product['status'] ) {
				continue;
			}
			$currency = $product['currency'];
			$items[]  = array(
				'product'  => $product,
				'qty'      => $qty,
				'subtotal' => bcmul( (string) $product['price'], (string) $qty, 2 ),
			);
		}

		if ( empty( $items ) ) {
			return '<div class="zeko-shop-note">' . esc_html__( 'Your cart is empty.', 'zeko-shop' ) . '</div>';
		}

		// Build the same item shape used by the totals engine.
		$totals_items = array();
		foreach ( $items as $item ) {
			$totals_items[] = array(
				'product_id' => (int) $item['product']['product_id'],
				'title'      => $item['product']['title'],
				'qty'        => $item['qty'],
				'price'      => (string) $item['product']['price'],
				'subtotal'   => $item['subtotal'],
			);
		}

		$totals = Zeko_Shop_Totals::compute( $user_id, $totals_items, $currency );

		$balance = '0.00';
		if ( class_exists( 'Zeko_Pay_SDK' ) ) {
			$sdk     = new Zeko_Pay_SDK();
			$balance = $sdk->get_balance( $user_id );
		}

		// Loyalty status for display (points are already awarded by zeko-pay).
		$loyalty = null;
		if ( class_exists( 'Zeko_Pay_Loyalty' ) ) {
			$loyalty = Zeko_Pay_Loyalty::instance()->get_status( $user_id );
		}

		ob_start();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/shop-checkout.php';
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode orders.
	 */
	public function shortcode_orders(): string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '<div class="zeko-shop-note">' . esc_html__( 'Please log in to view your orders.', 'zeko-shop' ) . '</div>';
		}

		$orders = $this->db->get_user_orders( $user_id, 50 );

		ob_start();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/shop-orders.php';
		return (string) ob_get_clean();
	}

	/**
	 * Cart badge: "Cart (3)" linking to the cart page.
	 */
	public function shortcode_cart_badge(): string {
		$count    = $this->cart_count();
		$cart_url = zeko_shop_page_url( 'cart' );

		ob_start();
		?>
		<a class="zeko-shop-cart-badge" href="<?php echo esc_url( $cart_url ); ?>">
			<?php echo esc_html__( 'Cart', 'zeko-shop' ); ?>
			<span class="zeko-shop-cart-badge-count" data-zeko-cart-count><?php echo (int) $count; ?></span>
		</a>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Bare cart item count, used by the header mini-cart toggle badge.
	 */
	public function shortcode_cart_count(): string {
		return (string) $this->cart_count();
	}

	/**
	 * Mini cart: current items, subtotal and a checkout button.
	 */
	public function shortcode_mini_cart(): string {
		$user_id  = get_current_user_id();
		$items    = array();
		$subtotal = '0.00';
		$currency = 'USD';

		if ( $user_id ) {
			$cart = $this->db->get_cart( $user_id );
			foreach ( $cart as $product_id => $qty ) {
				$product = $this->db->get_product( $product_id );
				if ( ! $product || 'active' !== $product['status'] ) {
					continue;
				}
				$currency = $product['currency'];
				$items[]  = array(
					'product'  => $product,
					'qty'      => $qty,
					'subtotal' => bcmul( (string) $product['price'], (string) $qty, 2 ),
				);
				$subtotal = bcadd( $subtotal, bcmul( (string) $product['price'], (string) $qty, 2 ), 2 );
			}
		}

		$cart_url     = zeko_shop_page_url( 'cart' );
		$checkout_url = zeko_shop_page_url( 'checkout' );

		ob_start();
		include ZEKO_SHOP_PLUGIN_PATH . 'templates/mini-cart.php';
		return (string) ob_get_clean();
	}

	/**
	 * Append a cart item to primary menus.
	 *
	 * @param string $items Items.
	 * @param mixed  $args Args.
	 */
	public function nav_cart_item( string $items, $args ): string {
		if ( empty( $args->theme_location ) || 'primary' !== $args->theme_location ) {
			return $items;
		}
		$items .= '<li class="menu-item menu-item-type-custom menu-item-object-custom zeko-shop-nav-cart">';
		$items .= $this->shortcode_cart_badge();
		$items .= '</li>';
		return $items;
	}

	// ═══════════════════════════════════════════════════════════════.
	// ASSETS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Determine if the current page needs shop assets.
	 */
	private function needs_assets(): bool {
		if ( is_admin() ) {
			return false;
		}

		// The cart badge is appended to every primary menu, so keep it live.
		// (and the mini-cart interactive) for logged-in users on all pages.
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( $this->is_order_route() || $this->is_product_route() ) {
			return true;
		}

		global $post;
		if ( $post && ! empty( $post->post_content ) && false !== strpos( $post->post_content, 'zeko_shop' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Enqueue assets.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->needs_assets() ) {
			return;
		}

		wp_enqueue_style(
			'zeko-shop',
			ZEKO_SHOP_PLUGIN_URL . 'assets/css/zeko-shop.css',
			array( 'zeko-core' ),
			ZEKO_SHOP_VERSION
		);

		wp_enqueue_script(
			'zeko-shop',
			ZEKO_SHOP_PLUGIN_URL . 'assets/js/zeko-shop.js',
			array( 'jquery' ),
			ZEKO_SHOP_VERSION,
			true
		);

		if ( defined( 'ZEKO_LOVE_PLUGIN_URL' ) && defined( 'ZEKO_LOVE_VERSION' ) && shortcode_exists( 'zeko_love_calls_strip' ) ) {
			wp_enqueue_style(
				'zeko-love-calls',
				ZEKO_LOVE_PLUGIN_URL . 'assets/css/zeko-love-calls.css',
				array(),
				ZEKO_LOVE_VERSION
			);
		}

		wp_localize_script(
			'zeko-shop',
			'zekoShop',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'zeko_shop_checkout' ),
				'i18n'    => array(
					'confirmRemove' => __( 'Remove this item from your cart?', 'zeko-shop' ),
					'addedToCart'   => __( 'Added to cart.', 'zeko-shop' ),
					'cartUpdated'   => __( 'Cart updated.', 'zeko-shop' ),
					'itemRemoved'   => __( 'Item removed from cart.', 'zeko-shop' ),
					'orderPlaced'   => __( 'Order placed!', 'zeko-shop' ),
					'insufficient'  => __( 'Insufficient wallet balance.', 'zeko-shop' ),
					'enterPromo'    => __( 'Enter a promo code.', 'zeko-shop' ),
					'genericError'  => __( 'Something went wrong. Please try again.', 'zeko-shop' ),
				),
			)
		);
	}
}

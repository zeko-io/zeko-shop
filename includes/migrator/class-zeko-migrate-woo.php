<?php
/**
 * WooCommerce → Zeko Shop + Zeko Pay migrator.
 *
 * Imports WooCommerce products, product variations, orders, order items,
 * and optionally creates wallets with credit history.
 *
 * @package Zeko_Shop
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

/** Class Zeko_Migrate_Woo. */
class Zeko_Migrate_Woo extends Zeko_Migrator_Base {

	private const BATCH = 50;

	/**
	 * Shop db.
	 *
	 * @var ?\Zeko_Shop_DB Shop db.
	 */
	private ?\Zeko_Shop_DB $shop_db = null;

	/**
	 * Ledger.
	 *
	 * @var ?\Zeko_Pay_Ledger Ledger.
	 */
	private ?\Zeko_Pay_Ledger $ledger = null;

	/**
	 * Product map.
	 *
	 * @var array Product map.
	 */
	private array $product_map = array();

	/**
	 * Order map.
	 *
	 * @var array Order map.
	 */
	private array $order_map = array();

	/**
	 * Hpos.
	 *
	 * @var bool Hpos.
	 */
	private bool $hpos = false;

	// ─── Detection ──────────────────────────────────────────────────.

	/**
	 * Available.
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' ) && post_type_exists( 'product' );
	}

	/**
	 * Label.
	 */
	public function get_label(): string {
		return __( 'WooCommerce', 'zeko-shop' );
	}

	// ─── Options ────────────────────────────────────────────────────.

	/**
	 * Defaults.
	 */
	protected function get_defaults(): array {
		return array(
			'migrate_products'    => true,
			'migrate_orders'      => true,
			'migrate_order_items' => true,
			'create_wallets'      => false,
			'delete_source'       => false,
		);
	}

	// ─── Shop / Pay singletons ─────────────────────────────────────.

	/**
	 * Shop.
	 */
	private function shop(): \Zeko_Shop_DB {
		if ( null === $this->shop_db ) {
			$this->shop_db = new \Zeko_Shop_DB();
		}
		return $this->shop_db;
	}

	/**
	 * Pay.
	 */
	private function pay(): \Zeko_Pay_Ledger {
		if ( null === $this->ledger ) {
			$this->ledger = \Zeko_Pay_Ledger::get_instance();
		}
		return $this->ledger;
	}

	// ─── Item Counts ────────────────────────────────────────────────.

	/**
	 * Item counts.
	 */
	public function get_item_counts(): array {
		$product_count   = (int) wp_count_posts( 'product' )->publish ?? 0;
		$variation_count = 0;
		if ( post_type_exists( 'product_variation' ) ) {
			$variation_count = (int) wp_count_posts( 'product_variation' )->publish ?? 0;
		}
		$order_count = $this->count_orders();

		return array(
			'products'   => $product_count,
			'orders'     => $order_count,
			'variations' => $variation_count,
		);
	}

	/**
	 * Count orders.
	 */
	private function count_orders(): int {
		if ( $this->is_hpos() ) {
			global $wpdb;
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE type = 'shop_order'" ); // phpcs:ignore
			}
			return 0;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		$count = wp_count_posts( 'shop_order' );
		return (int) ( $count->publish ?? 0 ) + (int) ( $count->wc_order_status_completed ?? 0 ) + (int) ( $count->wc_order_status_processing ?? 0 );
	}

	/**
	 * Hpos.
	 */
	private function is_hpos(): bool {
		if ( ! $this->hpos ) {
			$this->hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		}
		return $this->hpos;
	}

	// ─── Preview ────────────────────────────────────────────────────.

	/**
	 * Preview.
	 *
	 * @param int $limit Limit.
	 */
	public function preview( int $limit = 20 ): array {
		$this->dry_run = true;
		$items         = array();

		// Products.
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_type',
						'value'   => 'variation',
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $products as $product ) {
			$pid          = $product->ID;
			$price        = (float) get_post_meta( $pid, '_regular_price', true );
			$manage_stock = get_post_meta( $pid, '_manage_stock', true );
			$stock        = $manage_stock ? (int) get_post_meta( $pid, '_stock', true ) : -1;
			$type         = get_post_meta( $pid, '_product_type', true ) ?: 'simple';

			$items[] = array(
				'title'  => $product->post_title,
				'price'  => $price,
				'stock'  => $stock >= 0 ? $stock : 'unlimited',
				'type'   => $type,
				'orders' => 0,
			);
		}

		// Count orders.
		$counts = $this->get_item_counts();

		return array(
			'items'            => $items,
			'total_products'   => $counts['products'],
			'total_orders'     => $counts['orders'],
			'total_variations' => $counts['variations'],
		);
	}

	// ─── Run Migration ─────────────────────────────────────────────.

	/**
	 * Run.
	 */
	public function run(): array {
		$this->dry_run     = false;
		$this->log         = array();
		$this->id_map      = array();
		$this->product_map = array();
		$this->order_map   = array();
		$this->hpos        = $this->is_hpos();

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		if ( ! $this->is_available() ) {
			$this->log( 'error', 'source', 'WooCommerce not detected or product post type missing.' );
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 1,
			);
		}

		$this->set_options( $this->options );

		// 1. Products.
		if ( $this->options['migrate_products'] ) {
			$result    = $this->migrate_products();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 2. Variations (as separate products with unique prices).
		if ( $this->options['migrate_products'] ) {
			$result    = $this->migrate_variations();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 3. Orders + Items.
		if ( $this->options['migrate_orders'] ) {
			$result    = $this->migrate_orders();
			$imported += $result['imported'];
			$skipped  += $result['skipped'];
			$errors   += $result['errors'];
		}

		// 4. Wallets (opt-in).
		if ( $this->options['create_wallets'] ) {
			$this->create_wallets();
		}

		$this->log(
			'info',
			'complete',
			sprintf(
				'Migration finished: %d imported, %d skipped, %d errors.',
				$imported,
				$skipped,
				$errors
			)
		);

		/**
		 * Action: After WooCommerce migration completes.
		 *
		 * @param string $source   Source key.
		 * @param int    $imported Items imported.
		 * @param int    $skipped  Items skipped.
		 * @param array  $options  Options used.
		 */
		do_action( 'zbp_migration_complete', 'woo', $imported, $skipped, $this->options );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	// ─── Products ───────────────────────────────────────────────────.

	/**
	 * Migrate products.
	 */
	private function migrate_products(): array {
		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;

		$currency = function_exists( 'get_woocommerce_currency' )
			? get_woocommerce_currency()
			: 'USD';

		do {
			$posts = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => self::BATCH,
					'offset'         => $offset,
					'post_status'    => 'publish',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array(
						array(
							'key'     => '_type',
							'value'   => 'variation',
							'compare' => '!=',
						),
					),
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$result = $this->import_single_product( $post, $currency );
				switch ( $result ) {
					case 'imported':
						++$imported;
						break;
					case 'skipped':
						++$skipped;
						break;
					case 'error':
						++$errors;
						break;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$posts_count = count( $posts );
		} while ( self::BATCH === $posts_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single WooCommerce product post.
	 *
	 * @return string 'imported'|'skipped'|'error'
	 * @param \WP_Post $post Post.
	 * @param string   $currency Currency.
	 */
	private function import_single_product( \WP_Post $post, string $currency ): string {
		$pid = $post->ID;

		// Duplicate check.
		if ( $this->product_exists( $post->post_title ) ) {
			$this->log( 'skipped', $post->post_title, 'Product already exists.' );
			if ( ! empty( $this->options['delete_source'] ) ) {
				wp_delete_post( $pid, true );
			}
			return 'skipped';
		}

		$type     = get_post_meta( $pid, '_product_type', true ) ?: 'simple';
		$ext_type = '';
		$ext_url  = '';

		if ( 'external' === $type ) {
			$ext_type = 'affiliate';
			$ext_url  = get_post_meta( $pid, '_product_url', true ) ?: '';
		}

		$image_url = $this->get_product_image_url( $pid );
		$file_url  = $this->get_downloadable_file_url( $pid );

		$manage_stock = get_post_meta( $pid, '_manage_stock', true );
		$stock        = ( 'yes' === $manage_stock )
			? (int) get_post_meta( $pid, '_stock', true )
			: -1;

		$data = array(
			'title'         => $post->post_title,
			'description'   => $post->post_content,
			'price'         => (float) get_post_meta( $pid, '_regular_price', true ),
			'currency'      => $currency,
			'image_url'     => $image_url,
			'file_url'      => $file_url,
			'stock'         => $stock,
			'status'        => 'publish' === $post->post_status ? 'active' : 'inactive',
			'external_type' => $ext_type,
			'external_id'   => 0,
			'external_ref'  => ! empty( $ext_url ) ? $ext_url : ( get_post_meta( $pid, '_sku', true ) ?: '' ),
		);

		if ( $this->dry_run ) {
			$this->log( 'dry_run', $post->post_title, 'Would create product.' );
			return 'imported';
		}

		$zeko_id = $this->shop()->create_product( $data );

		if ( ! $zeko_id ) {
			$this->log( 'error', $post->post_title, 'Failed to create product.' );
			return 'error';
		}

		$this->product_map[ $pid ]         = $zeko_id;
		$this->id_map[ 'product_' . $pid ] = $zeko_id;

		$this->log( 'imported', $post->post_title, "Product created (zeko ID: {$zeko_id})." );

		if ( ! empty( $this->options['delete_source'] ) ) {
			wp_delete_post( $pid, true );
		}

		/** This action is documented in class-zbp-migrator-base.php */
		do_action( 'zbp_migration_item_complete', 'woo', $pid, $zeko_id );

		return 'imported';
	}

	// ─── Variations ─────────────────────────────────────────────────.

	/**
	 * Migrate variations.
	 */
	private function migrate_variations(): array {
		if ( ! post_type_exists( 'product_variation' ) ) {
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
			);
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;

		$currency = function_exists( 'get_woocommerce_currency' )
			? get_woocommerce_currency()
			: 'USD';

		// Track seen price+parent combos to only import unique prices.
		$seen = array();

		do {
			$variations = get_posts(
				array(
					'post_type'      => 'product_variation',
					'posts_per_page' => self::BATCH,
					'offset'         => $offset,
					'post_status'    => 'publish',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $variations ) ) {
				break;
			}

			foreach ( $variations as $var ) {
				$vid       = $var->ID;
				$parent_id = (int) $var->post_parent;
				$price     = (float) get_post_meta( $vid, '_regular_price', true );
				$price_key = $parent_id . '_' . $price;

				// Skip duplicate prices per parent.
				if ( isset( $seen[ $price_key ] ) ) {
					++$skipped;
					continue;
				}
				$seen[ $price_key ] = true;

				// Build title with attribute names.
				$title = $var->post_title;
				$attrs = array();
				foreach ( $var->post_excerpt as $key => $val ) { // phpcs:ignore
					if ( ! empty( $key ) && ! empty( $val ) ) {
						$attrs[] = $key . ': ' . $val;
					}
				}
				// fallback: gather from meta.
				if ( empty( $attrs ) ) {
					$all_meta = get_post_meta( $vid );
					foreach ( $all_meta as $mkey => $mval ) {
						if ( 0 === strpos( $mkey, 'attribute_' ) ) {
							$attr_name = str_replace( 'attribute_', '', $mkey );
							$attrs[]   = $attr_name . ': ' . maybe_unserialize( $mval[0] ?? '' );
						}
					}
				}
				if ( ! empty( $attrs ) ) {
					$title .= ' — ' . implode( ', ', $attrs );
				}

				// Duplicate check.
				if ( $this->product_exists( $title ) ) {
					$this->log( 'skipped', $title, 'Variation already exists.' );
					continue;
				}

				$image_url = $this->get_product_image_url( $vid );
				$file_url  = $this->get_downloadable_file_url( $vid );

				$manage_stock = get_post_meta( $vid, '_manage_stock', true );
				$stock        = ( 'yes' === $manage_stock )
					? (int) get_post_meta( $vid, '_stock', true )
					: -1;

				$data = array(
					'title'         => $title,
					'description'   => $var->post_content,
					'price'         => $price,
					'currency'      => $currency,
					'image_url'     => $image_url,
					'file_url'      => $file_url,
					'stock'         => $stock,
					'status'        => 'publish' === $var->post_status ? 'active' : 'inactive',
					'external_type' => '',
					'external_id'   => 0,
					'external_ref'  => get_post_meta( $vid, '_sku', true ) ?: '',
				);

				if ( $this->dry_run ) {
					$this->log( 'dry_run', $title, 'Would create variation product.' );
					++$imported;
					continue;
				}

				$zeko_id = $this->shop()->create_product( $data );

				if ( ! $zeko_id ) {
					$this->log( 'error', $title, 'Failed to create variation product.' );
					++$errors;
					continue;
				}

				$this->product_map[ $vid ]           = $zeko_id;
				$this->id_map[ 'variation_' . $vid ] = $zeko_id;

				$this->log( 'imported', $title, "Variation product created (zeko ID: {$zeko_id})." );
				++$imported;

				do_action( 'zbp_migration_item_complete', 'woo', $vid, $zeko_id );
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$variations_count = count( $variations );
		} while ( self::BATCH === $variations_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	// ─── Orders ─────────────────────────────────────────────────────.

	/**
	 * Migrate orders.
	 */
	private function migrate_orders(): array {
		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		if ( $this->hpos ) {
			$result = $this->migrate_hpos_orders();
		} else {
			$result = $this->migrate_legacy_orders();
		}

		return $result;
	}

	/**
	 * Migrate from HPOS wp_wc_orders table.
	 */
	private function migrate_hpos_orders(): array {
		global $wpdb;

		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;

		$table = $wpdb->prefix . 'wc_orders';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore
			$this->log( 'error', 'hpos', 'HPOS table not found.' );
			return array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 1,
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE type = 'shop_order' ORDER BY id ASC LIMIT %d OFFSET %d",
					self::BATCH,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			// phpcs:enable

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$result = $this->import_single_hpos_order( $row );
				switch ( $result ) {
					case 'imported':
						++$imported;
						break;
					case 'skipped':
						++$skipped;
						break;
					case 'error':
						++$errors;
						break;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$rows_count = count( $rows );
		} while ( self::BATCH === $rows_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single HPOS order row.
	 *
	 * @return string 'imported'|'skipped'|'error'
	 * @param object $row Row from wp_wc_orders.
	 */
	private function import_single_hpos_order( object $row ): string {
		$order_number = $row->order_number ?? '';
		$source_id    = (int) $row->id;

		// Duplicate check by WC order number.
		$wc_ref = 'WC-' . $order_number;
		if ( $this->order_exists( $source_id, $wc_ref ) ) {
			$this->log( 'skipped', "Order #{$order_number}", 'Order already migrated.' );
			return 'skipped';
		}

		$currency = $row->currency ?? 'USD';
		$tax      = (float) ( $row->tax_amount ?? 0 );
		$total    = (float) ( $row->total_amount ?? 0 );
		$subtotal = $total - $tax;
		$discount = 0;

		$status  = $this->map_order_status( $row->status ?? 'pending' );
		$user_id = (int) ( $row->customer_id ?? 0 );
		$tx_id   = (int) ( $row->transaction_id ?? 0 );

		if ( $this->dry_run ) {
			$this->log( 'dry_run', "Order #{$order_number}", 'Would create order.' );
			return 'imported';
		}

		$zeko_order_id = $this->shop()->create_order(
			array(
				'user_id'    => $user_id,
				'status'     => $status,
				'subtotal'   => $subtotal,
				'discount'   => $discount,
				'tax'        => $tax,
				'fee'        => 0,
				'total'      => $total,
				'currency'   => $currency,
				'tx_id'      => $tx_id,
				'invoice_id' => 0,
			)
		);

		if ( ! $zeko_order_id ) {
			$this->log( 'error', "Order #{$order_number}", 'Failed to create order.' );
			return 'error';
		}

		// Store WC order reference in the order_number column.
		$wc_ref = 'WC-' . $order_number;
		$this->shop()->update_order( $zeko_order_id, array( 'order_number' => $wc_ref ) );

		$this->order_map[ $source_id ]         = $zeko_order_id;
		$this->id_map[ 'order_' . $source_id ] = $zeko_order_id;

		$this->log( 'imported', "Order #{$order_number}", "Order created (zeko ID: {$zeko_order_id})." );

		// Migrate order items.
		if ( $this->options['migrate_order_items'] ) {
			$this->migrate_order_items( $source_id, $zeko_order_id, $order_number );
		}

		do_action( 'zbp_migration_item_complete', 'woo', $source_id, $zeko_order_id );

		return 'imported';
	}

	/**
	 * Migrate from legacy shop_order CPT.
	 */
	private function migrate_legacy_orders(): array {
		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$offset   = 0;

		do {
			$posts = get_posts(
				array(
					'post_type'      => 'shop_order',
					'posts_per_page' => self::BATCH,
					'offset'         => $offset,
					'post_status'    => 'any',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$result = $this->import_single_legacy_order( $post );
				switch ( $result ) {
					case 'imported':
						++$imported;
						break;
					case 'skipped':
						++$skipped;
						break;
					case 'error':
						++$errors;
						break;
				}
			}

			$offset += self::BATCH;

			if ( $this->dry_run ) {
				break;
			}
			$posts_count = count( $posts );
		} while ( self::BATCH === $posts_count );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * Import a single legacy WC order post.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function import_single_legacy_order( \WP_Post $post ): string {
		$pid          = $post->ID;
		$order_number = get_post_meta( $pid, '_order_number', true ) ?: $post->ID;

		// Duplicate check by WC order number.
		$wc_ref = 'WC-' . $order_number;
		if ( $this->order_exists( $pid, $wc_ref ) ) {
			$this->log( 'skipped', "Order #{$order_number}", 'Order already migrated.' );
			return 'skipped';
		}

		$currency = get_post_meta( $pid, '_order_currency', true ) ?: 'USD';
		$tax      = (float) get_post_meta( $pid, '_order_tax', true );
		$total    = (float) get_post_meta( $pid, '_order_total', true );
		$subtotal = $total - $tax;
		$discount = 0;

		$status  = $this->map_order_status( get_post_meta( $pid, '_order_status', true ) );
		$user_id = (int) get_post_meta( $pid, '_customer_user', true );
		$tx_id   = (int) get_post_meta( $pid, '_transaction_id', true );

		// Try to calculate discount from coupon items.
		$discount = $this->calculate_order_discount( $pid );

		if ( $this->dry_run ) {
			$this->log( 'dry_run', "Order #{$order_number}", 'Would create order.' );
			return 'imported';
		}

		$zeko_order_id = $this->shop()->create_order(
			array(
				'user_id'    => $user_id,
				'status'     => $status,
				'subtotal'   => $subtotal,
				'discount'   => $discount,
				'tax'        => $tax,
				'fee'        => 0,
				'total'      => $total,
				'currency'   => $currency,
				'tx_id'      => $tx_id,
				'invoice_id' => 0,
			)
		);

		if ( ! $zeko_order_id ) {
			$this->log( 'error', "Order #{$order_number}", 'Failed to create order.' );
			return 'error';
		}

		// Store WC order reference in the order_number column.
		$wc_ref = 'WC-' . $order_number;
		$this->shop()->update_order( $zeko_order_id, array( 'order_number' => $wc_ref ) );

		$this->order_map[ $pid ]         = $zeko_order_id;
		$this->id_map[ 'order_' . $pid ] = $zeko_order_id;

		$this->log( 'imported', "Order #{$order_number}", "Order created (zeko ID: {$zeko_order_id})." );

		if ( $this->options['migrate_order_items'] ) {
			$this->migrate_order_items( $pid, $zeko_order_id, $order_number );
		}

		do_action( 'zbp_migration_item_complete', 'woo', $pid, $zeko_order_id );

		return 'imported';
	}

	// ─── Order Items ────────────────────────────────────────────────.

	/**
	 * Migrate order items for a given order.
	 *
	 * @param int    $source_order_id Source order id.
	 * @param int    $zeko_order_id Zeko order id.
	 * @param string $order_number Order number.
	 */
	private function migrate_order_items( int $source_order_id, int $zeko_order_id, string $order_number ): void {
		global $wpdb;

		$items_table = $wpdb->prefix . 'woocommerce_order_items';
		$meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$items_table} WHERE order_id = %d AND order_item_type = 'line_item'",
					$source_order_id
				)
			);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:enable

		if ( empty( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			$item_meta = $this->get_order_item_meta( $item->order_item_id );

			$product_id    = (int) ( $item_meta['_product_id'] ?? 0 );
			$variation_id  = (int) ( $item_meta['_variation_id'] ?? 0 );
			$qty           = (int) ( $item_meta['_qty'] ?? 1 );
			$line_total    = (float) ( $item_meta['_line_total'] ?? 0 );
			$line_subtotal = (float) ( $item_meta['_line_subtotal'] ?? $line_total );

			// Unit price.
			$unit_price = $qty > 0 ? round( $line_total / $qty, 2 ) : $line_total;

			// Resolve Zeko product ID from the map.
			$zeko_product_id = $this->product_map[ $variation_id ]
				?? $this->product_map[ $product_id ]
				?? 0;

			// File URL for downloadable items.
			$file_url = '';
			if ( ! empty( $item_meta['_is_downloadable'] ) && 'yes' === $item_meta['_is_downloadable'] ) {
				$file_url = $this->get_downloadable_file_url( $product_id );
			}

			if ( $this->dry_run ) {
				$this->log( 'dry_run', "Order #{$order_number} item", 'Would create order item.' );
				continue;
			}

			$this->shop()->insert_order_item(
				$zeko_order_id,
				array(
					'product_id' => $zeko_product_id,
					'title'      => $item->order_item_name,
					'qty'        => $qty,
					'price'      => $unit_price,
					'subtotal'   => $line_subtotal,
					'file_url'   => $file_url,
				)
			);
		}
	}

	/**
	 * Get all meta for an order item as key => value.
	 *
	 * @param int $item_id Item id.
	 */
	private function get_order_item_meta( int $item_id ): array {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$meta_table} WHERE order_item_id = %d",
					$item_id
				)
			);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:enable

		$meta = array();
		foreach ( $rows as $row ) {
			$meta[ $row->meta_key ] = $row->meta_value;
		}
		return $meta;
	}

	// ─── Wallets ────────────────────────────────────────────────────.

	/**
	 * Create wallets and credit for users with completed orders.
	 */
	private function create_wallets(): void {
		global $wpdb;

		$orders_table = $this->shop()->table_orders();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Get unique user IDs from migrated completed orders.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$orders_table} WHERE status = 'completed' AND user_id > 0"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:enable

		if ( empty( $user_ids ) ) {
			return;
		}

		$currency = function_exists( 'get_woocommerce_currency' )
			? get_woocommerce_currency()
			: 'USD';

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			// Sum total spent by this user from migrated orders.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(total) FROM {$orders_table} WHERE user_id = %d AND status = 'completed'",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			// phpcs:enable

			if ( $total <= 0 ) {
				continue;
			}

			if ( $this->dry_run ) {
				$this->log( 'dry_run', "User #{$user_id}", sprintf( 'Would create wallet, credit %.2f %s.', $total, $currency ) );
				continue;
			}

			$wallet_id = $this->pay()->get_or_create_wallet( $user_id, $currency );

			if ( ! $wallet_id ) {
				$this->log( 'error', "User #{$user_id}", 'Failed to create wallet.' );
				continue;
			}

			$ref_id = 'woo_migration_user_' . $user_id;

			$result = $this->pay()->credit(
				$wallet_id,
				number_format( $total, 2, '.', '' ),
				$ref_id,
				'deposit',
				'woo_migration',
				array(
					'source'  => 'woocommerce',
					'user_id' => $user_id,
				)
			);

			if ( $result['success'] ) {
				$this->log(
					'imported',
					"User #{$user_id}",
					sprintf(
						'Wallet created (ID: %d), credited %.2f %s.',
						$wallet_id,
						$total,
						$currency
					)
				);
			} else {
				$this->log( 'error', "User #{$user_id}", 'Wallet credit failed: ' . ( $result['message'] ?? 'unknown' ) );
			}
		}
	}

	// ─── Helpers ────────────────────────────────────────────────────.

	/**
	 * Check if a product with the given title already exists in zeko_shop_products.
	 *
	 * @param string $title Title.
	 */
	private function product_exists( string $title ): bool {
		global $wpdb;

		$table = $this->shop()->table_products();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE title = %s LIMIT 1",
					$title
				)
			);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:enable

		return $count > 0;
	}

	/**
	 * Check if a WC order has already been migrated by WC order number.
	 *
	 * @param int    $source_id Source id.
	 * @param string $order_number Order number.
	 */
	private function order_exists( int $source_id, string $order_number = '' ): bool {
		global $wpdb;

		$table = $this->shop()->table_orders();
		if ( empty( $order_number ) ) {
			$order_number = 'WC-' . $source_id;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE order_number = %s LIMIT 1",
					$order_number
				)
			);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:enable

		return $count > 0;
	}

	/**
	 * Map WooCommerce order status to Zeko status.
	 *
	 * @param string $wc_status Wc status.
	 */
	private function map_order_status( string $wc_status ): string {
		$map = array(
			'completed'  => 'completed',
			'processing' => 'processing',
			'refunded'   => 'refunded',
			'cancelled'  => 'cancelled',
			'on-hold'    => 'pending',
		);

		return $map[ $wc_status ] ?? 'pending';
	}

	/**
	 * Calculate total discount from coupon-type order items (legacy).
	 *
	 * @param int $order_id Order id.
	 */
	private function calculate_order_discount( int $order_id ): float {
		global $wpdb;

		$items_table = $wpdb->prefix . 'woocommerce_order_items';
		$meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$coupon_items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT order_item_id FROM {$items_table} WHERE order_id = %d AND order_item_type = 'coupon'",
					$order_id
				)
			);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// phpcs:enable

		if ( empty( $coupon_items ) ) {
			return 0.0;
		}

		$discount = 0.0;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( $coupon_items as $ci ) {
			$amount = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$meta_table} WHERE order_item_id = %d AND meta_key = 'discount_amount'",
					$ci->order_item_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$discount += $amount;
		}

		return round( $discount, 2 );
	}

	/**
	 * Get featured image URL for a product/variation.
	 *
	 * @param int $post_id Post id.
	 */
	private function get_product_image_url( int $post_id ): string {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return '';
		}
		return wp_get_attachment_url( $thumb_id ) ?: '';
	}

	/**
	 * Get the first downloadable file URL for a product.
	 *
	 * @param int $post_id Post id.
	 */
	private function get_downloadable_file_url( int $post_id ): string {
		$files = get_post_meta( $post_id, '_downloadable_files', true );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return '';
		}

		$first = reset( $files );
		if ( ! empty( $first['file'] ) ) {
			return $first['file'];
		}

		return '';
	}
}

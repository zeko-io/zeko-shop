<?php
/**
 * Database layer for Zeko Shop.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_DB. */
class Zeko_Shop_DB {

	/**
	 * Wpdb.
	 *
	 * @var mixed Wpdb.
	 */
	private $wpdb;

	/**
	 * Sanitize rich-text (Quill) content with the narrow ecosystem
	 * allow-list; falls back to wp_kses_post() when zeko-core is absent.
	 *
	 * @param mixed $html Html.
	 */
	public static function sanitize_rich( $html ): string {
		if ( class_exists( 'Zeko_Core_Sanitize' ) ) {
			return Zeko_Core_Sanitize::rich_text( (string) $html );
		}
		return wp_kses_post( (string) $html );
	}

	/**
	 * Construct.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->ensure_product_columns();
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// TABLES.
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Create all shop tables via dbDelta.
	 */
	public function create_tables(): void {
		$charset = $this->wpdb->get_charset_collate();

		$products = $this->table_products();
		$sql1     = "CREATE TABLE {$products} (
			product_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			description longtext DEFAULT NULL,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(3) NOT NULL DEFAULT 'USD',
			image_url varchar(255) DEFAULT '',
			file_url varchar(255) DEFAULT '',
			stock int(11) NOT NULL DEFAULT -1,
			status varchar(20) NOT NULL DEFAULT 'active',
			external_type varchar(20) NOT NULL DEFAULT '',
			external_id bigint(20) unsigned NOT NULL DEFAULT 0,
			external_ref varchar(50) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (product_id),
			KEY status (status),
			KEY external (external_type, external_id, external_ref)
		) {$charset};";

		$orders = $this->table_orders();
		$sql2   = "CREATE TABLE {$orders} (
			order_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_number varchar(50) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
			discount decimal(12,2) NOT NULL DEFAULT 0.00,
			tax decimal(12,2) NOT NULL DEFAULT 0.00,
			fee decimal(12,2) NOT NULL DEFAULT 0.00,
			total decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(3) NOT NULL DEFAULT 'USD',
			tx_id bigint(20) unsigned NOT NULL DEFAULT 0,
			invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (order_id),
			UNIQUE KEY order_number (order_number),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset};";

		$items = $this->table_order_items();
		$sql3  = "CREATE TABLE {$items} (
			item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL,
			qty int(11) NOT NULL DEFAULT 1,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
			file_url varchar(255) DEFAULT '',
			PRIMARY KEY  (item_id),
			KEY order_id (order_id)
		) {$charset};";

		$notif = $this->table_notifications();
		$sql4  = "CREATE TABLE {$notif} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(50) NOT NULL,
			title varchar(255) NOT NULL,
			message text NOT NULL,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY user_unread (user_id, is_read),
			KEY is_read (is_read),
			KEY created_at (created_at)
		) {$charset};";

		$timeline = $this->table_order_timeline();
		$sql5     = "CREATE TABLE {$timeline} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			from_status varchar(20) NOT NULL DEFAULT '',
			to_status varchar(20) NOT NULL DEFAULT '',
			note text DEFAULT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) {$charset};";

		$cats   = $this->table_categories();
		$sql_cat = "CREATE TABLE {$cats} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_slug varchar(100) NOT NULL DEFAULT '',
			name varchar(100) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY key_slug (key_slug)
		) {$charset};";

		$svcs    = $this->table_services();
		$sql_svcs = "CREATE TABLE {$svcs} (
			service_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			external_type varchar(20) NOT NULL DEFAULT 'business',
			external_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL,
			short_description text DEFAULT NULL,
			description longtext DEFAULT NULL,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			price_type varchar(20) NOT NULL DEFAULT 'fixed',
			price_note varchar(255) DEFAULT '',
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			is_featured tinyint(1) NOT NULL DEFAULT 0,
			category varchar(100) DEFAULT '',
			duration varchar(100) DEFAULT '',
			tags varchar(500) DEFAULT '',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (service_id),
			KEY external (external_type, external_id),
			KEY status_active (is_active)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 . $sql2 . $sql3 . $sql4 . $sql5 . $sql_cat . $sql_svcs );

		$this->ensure_product_columns();
		$this->seed_category_defaults();
		$this->maybe_add_indexes();
	}

	/**
	 * Add hot-path indexes to existing tables (existence-checked, idempotent).
	 */
	private function maybe_add_indexes(): void {
		$notif = $this->table_notifications();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$notif,
				'user_unread'
			)
		);
		if ( ! $exists ) {
			$this->wpdb->query( "ALTER TABLE {$notif} ADD KEY user_unread (user_id, is_read)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Add new product columns if missing (safe ALTER via $wpdb->query).
	 */
	private function ensure_product_columns(): void {
		$table   = $this->table_products();
		$columns = $this->wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( empty( $columns ) || ! is_array( $columns ) ) {
			return;
		}

		$additions = array(
			'short_description' => "ALTER TABLE {$table} ADD COLUMN short_description text DEFAULT NULL AFTER description",
			'sale_price'        => "ALTER TABLE {$table} ADD COLUMN sale_price decimal(12,2) DEFAULT NULL AFTER price",
			'category'          => "ALTER TABLE {$table} ADD COLUMN category varchar(100) DEFAULT '' AFTER status",
			'tags'              => "ALTER TABLE {$table} ADD COLUMN tags varchar(500) DEFAULT '' AFTER category",
			'sku'               => "ALTER TABLE {$table} ADD COLUMN sku varchar(100) DEFAULT '' AFTER tags",
			'gallery_urls'      => "ALTER TABLE {$table} ADD COLUMN gallery_urls text DEFAULT NULL AFTER image_url",
			'product_type'      => "ALTER TABLE {$table} ADD COLUMN product_type varchar(20) DEFAULT 'physical' AFTER sku",
			'weight'            => "ALTER TABLE {$table} ADD COLUMN weight decimal(8,2) DEFAULT NULL AFTER product_type",
			'dimensions'        => "ALTER TABLE {$table} ADD COLUMN dimensions varchar(100) DEFAULT NULL AFTER weight",
			'is_featured'       => "ALTER TABLE {$table} ADD COLUMN is_featured tinyint(1) NOT NULL DEFAULT 0 AFTER status",
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( $additions as $col => $sql ) {
			if ( ! in_array( $col, $columns, true ) ) {
				$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Table names.
	 */
	public function table_products(): string {
		return $this->wpdb->prefix . 'zeko_shop_products';
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Table orders.
	 */
	public function table_orders(): string {
		return $this->wpdb->prefix . 'zeko_shop_orders';
	}

	/**
	 * Table order items.
	 */
	public function table_order_items(): string {
		return $this->wpdb->prefix . 'zeko_shop_order_items';
	}

	/**
	 * Table notifications.
	 */
	public function table_notifications(): string {
		return $this->wpdb->prefix . 'zeko_shop_notifications';
	}

	/**
	 * Table order timeline.
	 */
	public function table_order_timeline(): string {
		return $this->wpdb->prefix . 'zeko_shop_order_timeline';
	}

	/**
	 * Table categories.
	 */
	public function table_categories(): string {
		return $this->wpdb->prefix . 'zeko_shop_categories';
	}

	/**
	 * Table services.
	 */
	public function table_services(): string {
		return $this->wpdb->prefix . 'zeko_shop_services';
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// PRODUCTS.
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Get products.
	 *
	 * @param array $args Args.
	 */
	public function get_products( array $args = array() ): array {
		$defaults = array(
			'status' => '',
			'search' => '',
			'limit'  => 50,
			'offset' => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}
		if ( $args['search'] ) {
			$where[]  = 'title LIKE %s';
			$values[] = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
		}

		$values[]  = $args['limit'];
		$values[]  = $args['offset'];
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				"SELECT * FROM {$this->table_products()} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$values
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get a single product.
	 *
	 * @param int $product_id Product id.
	 */
	public function get_product( int $product_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_products()} WHERE product_id = %d LIMIT 1",
				$product_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	/**
	 * Get a product linked to an external entity (e.g. a mentor program).
	 *
	 * @param string $type External type (e.g. 'session').
	 * @param int    $external_id External entity ID.
	 * @param string $ref Optional discriminator (e.g. session type).
	 */
	public function get_product_by_external( string $type, int $external_id, string $ref = '' ): ?array {
		$where = 'external_type = %s AND external_id = %d';
		$args  = array( sanitize_text_field( $type ), $external_id );

		if ( '' !== $ref ) {
			$where .= ' AND external_ref = %s';
			$args[] = sanitize_text_field( $ref );
		}

		$args[] = 1;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_products()} WHERE {$where} LIMIT %d",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get all products linked to an external entity.
	 *
	 * @return array
	 * @param string $type External type (e.g. 'session').
	 * @param int    $external_id External entity ID.
	 */
	public function get_products_by_external( string $type, int $external_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_products()} WHERE external_type = %s AND external_id = %d ORDER BY product_id ASC",
				sanitize_text_field( $type ),
				$external_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Create a product.
	 *
	 * @param array $data Data.
	 */
	public function create_product( array $data ): int {
		$this->wpdb->insert(
			$this->table_products(),
			array(
				'title'             => sanitize_text_field( $data['title'] ?? '' ),
				'short_description' => self::sanitize_rich( $data['short_description'] ?? '' ),
				'description'       => self::sanitize_rich( $data['description'] ?? '' ),
				'price'             => $this->sanitize_decimal( $data['price'] ?? '0' ),
				'sale_price'        => isset( $data['sale_price'] ) && '' !== $data['sale_price'] ? $this->sanitize_decimal( $data['sale_price'] ) : null,
				'currency'          => strtoupper( sanitize_text_field( $data['currency'] ?? 'USD' ) ),
				'image_url'         => esc_url_raw( $data['image_url'] ?? '' ),
				'gallery_urls'      => isset( $data['gallery_urls'] ) ? sanitize_text_field( wp_json_encode( (array) $data['gallery_urls'] ) ) : null,
				'file_url'          => esc_url_raw( $data['file_url'] ?? '' ),
				'stock'             => (int) ( $data['stock'] ?? -1 ),
				'status'            => 'active' === ( $data['status'] ?? 'active' ) ? 'active' : 'inactive',
				'is_featured'       => ! empty( $data['is_featured'] ) ? 1 : 0,
				'category'          => sanitize_text_field( $data['category'] ?? '' ),
				'tags'              => sanitize_text_field( $data['tags'] ?? '' ),
				'sku'               => sanitize_text_field( $data['sku'] ?? '' ),
				'product_type'      => sanitize_text_field( $data['product_type'] ?? 'physical' ),
				'weight'            => isset( $data['weight'] ) && '' !== $data['weight'] ? (float) $data['weight'] : null,
				'dimensions'        => sanitize_text_field( $data['dimensions'] ?? '' ),
				'external_type'     => sanitize_text_field( $data['external_type'] ?? '' ),
				'external_id'       => absint( $data['external_id'] ?? 0 ),
				'external_ref'      => sanitize_text_field( $data['external_ref'] ?? '' ),
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update a product.
	 *
	 * @param int   $product_id Product id.
	 * @param array $data Data.
	 */
	public function update_product( int $product_id, array $data ): bool {
		$fields = array();
		$format = array();

		if ( isset( $data['title'] ) ) {
			$fields['title'] = sanitize_text_field( $data['title'] );
			$format[]        = '%s';
		}
		if ( isset( $data['short_description'] ) ) {
			$fields['short_description'] = self::sanitize_rich( $data['short_description'] );
			$format[]                    = '%s';
		}
		if ( isset( $data['description'] ) ) {
			$fields['description'] = self::sanitize_rich( $data['description'] );
			$format[]              = '%s';
		}
		if ( isset( $data['price'] ) ) {
			$fields['price'] = $this->sanitize_decimal( $data['price'] );
			$format[]        = '%s';
		}
		if ( array_key_exists( 'sale_price', $data ) ) {
			$fields['sale_price'] = ( '' === $data['sale_price'] || null === $data['sale_price'] ) ? null : $this->sanitize_decimal( $data['sale_price'] );
			$format[]             = '%s';
		}
		if ( isset( $data['currency'] ) ) {
			$fields['currency'] = strtoupper( sanitize_text_field( $data['currency'] ) );
			$format[]           = '%s';
		}
		if ( isset( $data['image_url'] ) ) {
			$fields['image_url'] = esc_url_raw( $data['image_url'] );
			$format[]            = '%s';
		}
		if ( array_key_exists( 'gallery_urls', $data ) ) {
			$fields['gallery_urls'] = is_array( $data['gallery_urls'] ) ? wp_json_encode( $data['gallery_urls'] ) : sanitize_text_field( $data['gallery_urls'] );
			$format[]               = '%s';
		}
		if ( isset( $data['file_url'] ) ) {
			$fields['file_url'] = esc_url_raw( $data['file_url'] );
			$format[]           = '%s';
		}
		if ( isset( $data['stock'] ) ) {
			$fields['stock'] = (int) $data['stock'];
			$format[]        = '%d';
		}
		if ( isset( $data['status'] ) ) {
			$fields['status'] = 'active' === $data['status'] ? 'active' : 'inactive';
			$format[]         = '%s';
		}
		if ( array_key_exists( 'is_featured', $data ) ) {
			$fields['is_featured'] = ! empty( $data['is_featured'] ) ? 1 : 0;
			$format[]              = '%d';
		}
		if ( isset( $data['category'] ) ) {
			$fields['category'] = sanitize_text_field( $data['category'] );
			$format[]           = '%s';
		}
		if ( isset( $data['tags'] ) ) {
			$fields['tags'] = sanitize_text_field( $data['tags'] );
			$format[]       = '%s';
		}
		if ( isset( $data['sku'] ) ) {
			$fields['sku'] = sanitize_text_field( $data['sku'] );
			$format[]      = '%s';
		}
		if ( isset( $data['product_type'] ) ) {
			$fields['product_type'] = sanitize_text_field( $data['product_type'] );
			$format[]               = '%s';
		}
		if ( array_key_exists( 'weight', $data ) ) {
			$fields['weight'] = ( '' === $data['weight'] || null === $data['weight'] ) ? null : (float) $data['weight'];
			$format[]         = '%s';
		}
		if ( isset( $data['dimensions'] ) ) {
			$fields['dimensions'] = sanitize_text_field( $data['dimensions'] );
			$format[]             = '%s';
		}
		if ( isset( $data['external_type'] ) ) {
			$fields['external_type'] = sanitize_text_field( $data['external_type'] );
			$format[]                = '%s';
		}
		if ( isset( $data['external_id'] ) ) {
			$fields['external_id'] = absint( $data['external_id'] );
			$format[]              = '%d';
		}
		if ( isset( $data['external_ref'] ) ) {
			$fields['external_ref'] = sanitize_text_field( $data['external_ref'] );
			$format[]               = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$format[]             = '%s';

		return (bool) $this->wpdb->update(
			$this->table_products(),
			$fields,
			array( 'product_id' => $product_id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Delete a product.
	 *
	 * @param int $product_id Product id.
	 */
	public function delete_product( int $product_id ): bool {
		return (bool) $this->wpdb->delete(
			$this->table_products(),
			array( 'product_id' => $product_id ),
			array( '%d' )
		);
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// PRODUCT CATEGORIES.
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Get product categories (for the portal selector and catalog filters).
	 *
	 * @param bool $active_only Active only.
	 */
	public function get_categories( bool $active_only = true ): array {
		$table = $this->table_categories();
		$where = $active_only ? 'WHERE is_active = 1' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		//phpcs:ignore WordPress.DB
		$rows = $this->wpdb->get_results(
			"SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, name ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a single category by its slug/key.
	 *
	 * @param string $key Key.
	 */
	public function get_category_by_key( string $key ): ?array {
		$table = $this->table_categories();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE key_slug = %s LIMIT 1", $key ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ? $row : null;
	}

	/**
	 * Create a product category. Returns the new id or 0 on failure.
	 *
	 * @param array $data Data.
	 */
	public function create_category( array $data ): int {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return 0;
		}
		$key = sanitize_title( $data['key_slug'] ?? $name );
		if ( '' === $key ) {
			$key = 'category-' . gmdate( 'YmdHis' );
		}
		$table = $this->table_categories();

		$exists = $this->get_category_by_key( $key );
		if ( $exists ) {
			return (int) $exists['id'];
		}

		$inserted = $this->wpdb->insert(
			$table,
			array(
				'key_slug'   => $key,
				'name'       => $name,
				'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
				'is_active'  => 1,
			),
			array( '%s', '%s', '%d', '%d' )
		);

		return $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Seed the default product categories if the table is empty.
	 */
	public function seed_category_defaults(): void {
		$table = $this->table_categories();
		//phpcs:ignore WordPress.DB
		$count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$defaults = array(
			array( 'physical', 'Physical Product', 1 ),
			array( 'digital', 'Digital Product', 2 ),
			array( 'service', 'Service-based', 3 ),
			array( 'subscription', 'Subscription', 4 ),
			array( 'downloadable', 'Downloadable', 5 ),
			array( 'equipment', 'Equipment', 6 ),
			array( 'software', 'Software', 7 ),
			array( 'other', 'Other', 99 ),
		);

		foreach ( $defaults as $d ) {
			$this->wpdb->insert(
				$table,
				array(
					'key_slug'   => $d[0],
					'name'       => $d[1],
					'sort_order' => $d[2],
					'is_active'  => 1,
				),
				array( '%s', '%s', '%d', '%d' )
			);
		}
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// SERVICES.
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Get a single service by id.
	 *
	 * @param int $service_id Service id.
	 */
	public function get_service( int $service_id ): ?array {
		if ( $service_id < 1 ) {
			return null;
		}
		$table = $this->table_services();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE service_id = %d LIMIT 1", $service_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ? $row : null;
	}

	/**
	 * Get services for an external entity (e.g. a business).
	 *
	 * @param string $type Type.
	 * @param int    $external_id External id.
	 * @param bool   $active_only Active only.
	 */
	public function get_services_by_external( string $type, int $external_id, bool $active_only = true ): array {
		$table = $this->table_services();
		$where = $active_only ? ' AND is_active = 1' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		//phpcs:ignore WordPress.DB
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE external_type = %s AND external_id = %d{$where} ORDER BY sort_order ASC, service_id ASC",
				$type,
				$external_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count services for an external entity.
	 *
	 * @param string $type Type.
	 * @param int    $external_id External id.
	 */
	public function count_services_by_external( string $type, int $external_id ): int {
		$table = $this->table_services();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		//phpcs:ignore WordPress.DB
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE external_type = %s AND external_id = %d",
				$type,
				$external_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Create a service. Returns the new service id or 0 on failure.
	 *
	 * @param array $data Data.
	 */
	public function create_service( array $data ): int {
		$external_type = isset( $data['external_type'] ) ? sanitize_text_field( $data['external_type'] ) : 'business';
		$external_id   = absint( $data['external_id'] ?? 0 );
		if ( $external_id < 1 ) {
			return 0;
		}

		$table = $this->table_services();
		$now   = current_time( 'mysql', true );

		$fields = array(
			'external_type'     => $external_type,
			'external_id'       => $external_id,
			'title'             => sanitize_text_field( $data['title'] ?? '' ),
			'short_description' => sanitize_textarea_field( $data['short_description'] ?? '' ),
			'description'       => self::sanitize_rich( $data['description'] ?? '' ),
			'price'             => (float) ( $data['price'] ?? 0 ),
			'price_type'        => sanitize_text_field( $data['price_type'] ?? 'fixed' ),
			'price_note'        => sanitize_text_field( $data['price_note'] ?? '' ),
			'image_id'          => absint( $data['image_id'] ?? 0 ),
			'is_active'         => ! empty( $data['is_active'] ) ? 1 : 1,
			'is_featured'       => ! empty( $data['is_featured'] ) ? 1 : 0,
			'category'          => sanitize_text_field( $data['category'] ?? '' ),
			'duration'          => sanitize_text_field( $data['duration'] ?? '' ),
			'tags'              => sanitize_text_field( $data['tags'] ?? '' ),
			'sort_order'        => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$inserted = $this->wpdb->insert( $table, $fields );
		return $inserted ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Import a service preserving its original id (used by migration so
	 * existing public URLs/ids stay valid). Returns true on success.
	 *
	 * @param array $data Data.
	 */
	public function import_service( array $data ): bool {
		$service_id = absint( $data['service_id'] ?? 0 );
		if ( $service_id < 1 ) {
			return false;
		}

		$fields = array(
			'service_id'        => $service_id,
			'external_type'     => isset( $data['external_type'] ) ? sanitize_text_field( $data['external_type'] ) : 'business',
			'external_id'       => absint( $data['external_id'] ?? 0 ),
			'title'             => sanitize_text_field( $data['title'] ?? '' ),
			'short_description' => sanitize_textarea_field( $data['short_description'] ?? '' ),
			'description'       => self::sanitize_rich( $data['description'] ?? '' ),
			'price'             => (float) ( $data['price'] ?? 0 ),
			'price_type'        => sanitize_text_field( $data['price_type'] ?? 'fixed' ),
			'price_note'        => sanitize_text_field( $data['price_note'] ?? '' ),
			'image_id'          => absint( $data['image_id'] ?? 0 ),
			'is_active'         => ! empty( $data['is_active'] ) ? 1 : 1,
			'is_featured'       => ! empty( $data['is_featured'] ) ? 1 : 0,
			'category'          => sanitize_text_field( $data['category'] ?? '' ),
			'duration'          => sanitize_text_field( $data['duration'] ?? '' ),
			'tags'              => sanitize_text_field( $data['tags'] ?? '' ),
			'sort_order'        => (int) ( $data['sort_order'] ?? 0 ),
			'created_at'        => isset( $data['created_at'] ) ? $data['created_at'] : current_time( 'mysql', true ),
			'updated_at'        => isset( $data['created_at'] ) ? $data['created_at'] : current_time( 'mysql', true ),
		);

		return $this->wpdb->insert(
			$this->table_services(),
			$fields,
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		) ? true : false;
	}

	/**
	 * Update a service. Returns true on success.
	 *
	 * @param int   $service_id Service id.
	 * @param array $data Data.
	 */
	public function update_service( int $service_id, array $data ): bool {
		if ( $service_id < 1 ) {
			return false;
		}

		$kit = array(
			'title'             => array( 'sanitize_text_field', '%s' ),
			'short_description' => array( 'sanitize_textarea_field', '%s' ),
			'description'       => array( array( self::class, 'sanitize_rich' ), '%s' ),
			'price'             => array( 'floatval', '%f' ),
			'price_type'        => array( 'sanitize_text_field', '%s' ),
			'price_note'        => array( 'sanitize_text_field', '%s' ),
			'image_id'          => array( 'absint', '%d' ),
			'is_active'         => array( null, '%d' ),
			'is_featured'       => array( null, '%d' ),
			'category'          => array( 'sanitize_text_field', '%s' ),
			'duration'          => array( 'sanitize_text_field', '%s' ),
			'tags'              => array( 'sanitize_text_field', '%s' ),
			'sort_order'        => array( 'intval', '%d' ),
		);

		$fields = array();
		foreach ( $kit as $column => $spec ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			list( $san, $fmt ) = $spec;
			if ( 'is_active' === $column || 'is_featured' === $column ) {
				$fields[ $column ] = ! empty( $data[ $column ] ) ? 1 : 0;
			} else {
				$fields[ $column ] = $san( $data[ $column ] );
			}
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$table                = $this->table_services();

		return (bool) $this->wpdb->update(
			$table,
			$fields,
			array( 'service_id' => $service_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Delete a service. Returns true on success.
	 *
	 * @param int $service_id Service id.
	 */
	public function delete_service( int $service_id ): bool {
		if ( $service_id < 1 ) {
			return false;
		}
		return (bool) $this->wpdb->delete(
			$this->table_services(),
			array( 'service_id' => $service_id ),
			array( '%d' )
		);
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// ORDERS.
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Get orders.
	 *
	 * @param array $args Args.
	 */
	public function get_orders( array $args = array() ): array {
		$defaults = array(
			'status'  => '',
			'user_id' => 0,
			'limit'   => 50,
			'offset'  => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}
		if ( $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$values[] = $args['user_id'];
		}

		$values[]  = $args['limit'];
		$values[]  = $args['offset'];
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				"SELECT * FROM {$this->table_orders()} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$values
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get orders for a user.
	 *
	 * @param int $user_id User id.
	 * @param int $limit Limit.
	 */
	public function get_user_orders( int $user_id, int $limit = 50 ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_orders()} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get a single order.
	 *
	 * @param int $order_id Order id.
	 */
	public function get_order( int $order_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_orders()} WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get an order by order number.
	 *
	 * @param string $number Number.
	 */
	public function get_order_by_number( string $number ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_orders()} WHERE order_number = %s LIMIT 1",
				$number
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $row ?: null;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get order items.
	 *
	 * @param int $order_id Order id.
	 */
	public function get_order_items( int $order_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_order_items()} WHERE order_id = %d ORDER BY item_id ASC",
				$order_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Create an order and return its ID.
	 *
	 * @param array $data Data.
	 */
	public function create_order( array $data ): int {
		$number = $this->generate_order_number();

		$this->wpdb->insert(
			$this->table_orders(),
			array(
				'order_number' => $number,
				'user_id'      => absint( $data['user_id'] ),
				'status'       => sanitize_text_field( $data['status'] ?? 'pending' ),
				'subtotal'     => $this->sanitize_decimal( $data['subtotal'] ?? '0' ),
				'discount'     => $this->sanitize_decimal( $data['discount'] ?? '0' ),
				'tax'          => $this->sanitize_decimal( $data['tax'] ?? '0' ),
				'fee'          => $this->sanitize_decimal( $data['fee'] ?? '0' ),
				'total'        => $this->sanitize_decimal( $data['total'] ?? '0' ),
				'currency'     => strtoupper( sanitize_text_field( $data['currency'] ?? 'USD' ) ),
				'tx_id'        => absint( $data['tx_id'] ?? 0 ),
				'invoice_id'   => absint( $data['invoice_id'] ?? 0 ),
				'created_at'   => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update an order.
	 *
	 * @param int   $order_id Order id.
	 * @param array $data Data.
	 */
	public function update_order( int $order_id, array $data ): bool {
		$fields = array();
		$format = array();

		foreach ( array( 'status', 'order_number' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$fields[ $key ] = sanitize_text_field( $data[ $key ] );
				$format[]       = '%s';
			}
		}
		foreach ( array( 'subtotal', 'discount', 'tax', 'fee', 'total' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$fields[ $key ] = $this->sanitize_decimal( $data[ $key ] );
				$format[]       = '%s';
			}
		}
		if ( isset( $data['currency'] ) ) {
			$fields['currency'] = strtoupper( sanitize_text_field( $data['currency'] ) );
			$format[]           = '%s';
		}
		if ( isset( $data['tx_id'] ) ) {
			$fields['tx_id'] = absint( $data['tx_id'] );
			$format[]        = '%d';
		}
		if ( isset( $data['invoice_id'] ) ) {
			$fields['invoice_id'] = absint( $data['invoice_id'] );
			$format[]             = '%d';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		// Auto-log status changes to the timeline.
		$old_status = '';
		if ( isset( $data['status'] ) ) {
			$order = $this->get_order( $order_id );
			if ( $order ) {
				$old_status = $order['status'];
			}
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$format[]             = '%s';

		$updated = (bool) $this->wpdb->update(
			$this->table_orders(),
			$fields,
			array( 'order_id' => $order_id ),
			$format,
			array( '%d' )
		);

		if ( $updated && isset( $data['status'] ) && '' !== $old_status && $old_status !== $data['status'] ) {
			$note     = $data['status_note'] ?? '';
			$actor_id = $data['actor_id'] ?? 0;
			$this->log_timeline_event( $order_id, $old_status, sanitize_text_field( $data['status'] ), $note, (int) $actor_id );
		}

		return $updated;
	}

	/**
	 * Log a timeline event for an order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from_status From status.
	 * @param string $to_status To status.
	 * @param string $note Note.
	 * @param int    $actor_id Actor id.
	 */
	public function log_timeline_event( int $order_id, string $from_status, string $to_status, string $note = '', int $actor_id = 0 ): int {
		$this->wpdb->insert(
			$this->table_order_timeline(),
			array(
				'order_id'    => $order_id,
				'from_status' => sanitize_text_field( $from_status ),
				'to_status'   => sanitize_text_field( $to_status ),
				'note'        => self::sanitize_rich( $note ),
				'actor_id'    => absint( $actor_id ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Get the timeline for an order.
	 *
	 * @param int $order_id Order id.
	 */
	public function get_order_timeline( int $order_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_order_timeline()} WHERE order_id = %d ORDER BY created_at ASC, id ASC",
				$order_id
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Insert an order item.
	 *
	 * @param int   $order_id Order id.
	 * @param array $item Item.
	 */
	public function insert_order_item( int $order_id, array $item ): int {
		$this->wpdb->insert(
			$this->table_order_items(),
			array(
				'order_id'   => $order_id,
				'product_id' => absint( $item['product_id'] ),
				'title'      => sanitize_text_field( $item['title'] ),
				'qty'        => absint( $item['qty'] ),
				'price'      => $this->sanitize_decimal( $item['price'] ),
				'subtotal'   => $this->sanitize_decimal( $item['subtotal'] ),
				'file_url'   => esc_url_raw( $item['file_url'] ?? '' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $this->wpdb->insert_id;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	/**
	 * Decrement stock for a product (only if limited).
	 *
	 * @param int $product_id Product id.
	 * @param int $qty Qty.
	 */
	public function decrement_stock( int $product_id, int $qty ): bool {
		return (bool) $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table_products()} SET stock = stock - %d WHERE product_id = %d AND stock > 0",
				absint( $qty ),
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Generate a unique order number.
	 * Uses a per-year atomic counter in wp_options instead of COUNT(*)+1,
	 * so concurrent checkouts cannot collide on the UNIQUE order_number column.
	 */
	private function generate_order_number(): string {
		$year   = gmdate( 'Y' );
		$option = 'zeko_shop_order_seq_' . $year;

		if ( false === get_option( $option, false ) ) {
			update_option( $option, 0, false );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Atomic increment â€” InnoDB row-locks the option row so concurrent.
		// requests always receive distinct sequence values.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->wpdb->options} SET option_value = CAST( option_value AS UNSIGNED ) + 1 WHERE option_name = %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$option
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		// Read the value directly to bypass the options cache.
		$seq = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$option
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

		return 'ZK-ORD-' . $year . '-' . str_pad( (string) $seq, 6, '0', STR_PAD_LEFT );
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// SHOP NOTIFICATIONS (dedicated table so the bell can filter them).
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Insert a shop notification.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Type.
	 * @param string $title Title.
	 * @param string $message Message.
	 */
	public function insert_notification( int $user_id, string $type, string $title, string $message ): int {
		$this->wpdb->insert(
			$this->table_notifications(),
			array(
				'user_id'    => $user_id,
				'type'       => sanitize_text_field( $type ),
				'title'      => sanitize_text_field( $title ),
				'message'    => self::sanitize_rich( $message ),
				'is_read'    => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Get a user's shop notifications.
	 *
	 * @param int  $user_id User id.
	 * @param int  $limit Limit.
	 * @param bool $unread_only Unread only.
	 */
	public function get_user_notifications( int $user_id, int $limit = 50, bool $unread_only = false ): array {
		$table = $this->table_notifications();
		$where = 'WHERE user_id = %d';
		$args  = array( $user_id );

		if ( $unread_only ) {
			$where .= ' AND is_read = 0';
		}

		$args[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d",
				...$args
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Get unread count for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function get_notification_unread_count( int $user_id ): int {
		$table = $this->table_notifications();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	/**
	 * Mark a shop notification as read.
	 *
	 * @param int $notification_id Notification id.
	 * @param int $user_id User id.
	 */
	public function mark_notification_read( int $notification_id, int $user_id ): bool {
		$table = $this->table_notifications();

		$updated = $this->wpdb->update(
			$table,
			array( 'is_read' => 1 ),
			array(
				'id'      => $notification_id,
				'user_id' => $user_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $updated && (int) $updated > 0;
	}

	/**
	 * Mark all shop notifications as read for a user.
	 *
	 * @param int $user_id User id.
	 */
	public function mark_all_notifications_read( int $user_id ): bool {
		$table = $this->table_notifications();

		return false !== $this->wpdb->update(
			$table,
			array( 'is_read' => 1 ),
			array(
				'user_id' => $user_id,
				'is_read' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// CART (stored in user meta).
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Get a user's cart as [product_id => qty].
	 *
	 * @param int $user_id User id.
	 */
	public function get_cart( int $user_id ): array {
		$cart  = get_user_meta( $user_id, 'zeko_shop_cart', true );
		$cart  = is_array( $cart ) ? $cart : array();
		$clean = array();
		foreach ( $cart as $product_id => $qty ) {
			$pid = absint( $product_id );
			if ( $pid > 0 && (int) $qty > 0 ) {
				$clean[ $pid ] = (int) $qty;
			}
		}
		return $clean;
	}

	/**
	 * Set a user's cart.
	 *
	 * @param int   $user_id User id.
	 * @param array $cart Cart.
	 */
	public function set_cart( int $user_id, array $cart ): void {
		$clean = array();
		foreach ( $cart as $product_id => $qty ) {
			$pid = absint( $product_id );
			if ( $pid > 0 && (int) $qty > 0 ) {
				$clean[ $pid ] = (int) $qty;
			}
		}
		update_user_meta( $user_id, 'zeko_shop_cart', $clean );
	}

	/**
	 * Add a product to the cart.
	 *
	 * @param int $user_id User id.
	 * @param int $product_id Product id.
	 * @param int $qty Qty.
	 */
	public function add_to_cart( int $user_id, int $product_id, int $qty = 1 ): array {
		$cart                = $this->get_cart( $user_id );
		$cart[ $product_id ] = (int) ( $cart[ $product_id ] ?? 0 ) + max( 1, $qty );
		$this->set_cart( $user_id, $cart );
		return array(
			'success' => true,
			'count'   => array_sum( $cart ),
		);
	}

	/**
	 * Update quantity for a cart item.
	 *
	 * @param int $user_id User id.
	 * @param int $product_id Product id.
	 * @param int $qty Qty.
	 */
	public function update_cart_item( int $user_id, int $product_id, int $qty ): array {
		$cart = $this->get_cart( $user_id );
		if ( $qty <= 0 ) {
			unset( $cart[ $product_id ] );
		} else {
			$cart[ $product_id ] = $qty;
		}
		$this->set_cart( $user_id, $cart );
		return array(
			'success' => true,
			'count'   => array_sum( $cart ),
		);
	}

	/**
	 * Remove a product from the cart.
	 *
	 * @param int $user_id User id.
	 * @param int $product_id Product id.
	 */
	public function remove_from_cart( int $user_id, int $product_id ): array {
		$cart = $this->get_cart( $user_id );
		unset( $cart[ $product_id ] );
		$this->set_cart( $user_id, $cart );
		return array(
			'success' => true,
			'count'   => array_sum( $cart ),
		);
	}

	/**
	 * Clear the cart.
	 *
	 * @param int $user_id User id.
	 */
	public function clear_cart( int $user_id ): void {
		delete_user_meta( $user_id, 'zeko_shop_cart' );
	}

	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.
	// HELPERS.
	// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•.

	/**
	 * Sanitize a decimal amount.
	 *
	 * @param mixed $amount Amount.
	 */
	private function sanitize_decimal( $amount ): string {
		$clean = preg_replace( '/[^0-9.]/', '', (string) $amount );
		return number_format( (float) $clean, 2, '.', '' );
	}
}

<?php
/**
 * Zeko Shop — WordPress personal-data exporter and eraser.
 *
 * Registers with Tools > Export Personal Data / Erase Personal Data so site
 * owners can fulfil data-protection requests for store purchases: orders,
 * their line items, order timeline events and purchase notifications. Order
 * records are strictly per-buyer, so erasure deletes the buyer's orders, their
 * items and their timeline events; timeline events the user triggered on
 * *other* buyers' orders (admin/fulfilment actions) are de-anonymized to
 * actor_id 0 instead of deleted so the audit trail survives.
 *
 * Table schemas byte-verified 2026-09-25 against class-zeko-shop-db.php:
 *   {prefix}zeko_shop_orders          order_id / user_id / created_at
 *   {prefix}zeko_shop_order_items     item_id / order_id / title / price
 *   {prefix}zeko_shop_order_timeline  id / order_id / actor_id / created_at
 *   {prefix}zeko_shop_notifications   id / user_id / type / title / message
 *   {prefix}zeko_shop_cart            stored in user meta 'zeko_shop_cart'
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the exporter and eraser callbacks.
 */
function zeko_shop_privacy_register(): void {
	add_filter( 'wp_privacy_personal_data_exporters', 'zeko_shop_privacy_register_exporter' );
	add_filter( 'wp_privacy_personal_data_erasers', 'zeko_shop_privacy_register_eraser' );
}
add_action( 'init', 'zeko_shop_privacy_register', 11 );

/**
 * Register the personal-data exporter.
 *
 * @param array $exporters Exporters.
 */
function zeko_shop_privacy_register_exporter( array $exporters ): array {
	$exporters['zeko-shop'] = array(
		'exporter_friendly_name' => __( 'Zeko Shop data', 'zeko-shop' ),
		'callback'               => 'zeko_shop_privacy_export',
	);
	return $exporters;
}

/**
 * Register the personal-data eraser.
 *
 * @param array $erasers Erasers.
 */
function zeko_shop_privacy_register_eraser( array $erasers ): array {
	$erasers['zeko-shop'] = array(
		'eraser_friendly_name' => __( 'Zeko Shop data', 'zeko-shop' ),
		'callback'             => 'zeko_shop_privacy_erase',
	);
	return $erasers;
}

/**
 * Get a prepared DB instance (null when the plugin is not active).
 */
function zeko_shop_privacy_db(): ?Zeko_Shop_DB {
	if ( ! class_exists( 'Zeko_Shop_DB' ) ) {
		return null;
	}
	return new Zeko_Shop_DB();
}

/**
 * Whether a table exists (guards every touch of a table).
 *
 * @param string $table Table.
 */
function zeko_shop_privacy_table_exists( string $table ): bool {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/**
 * Export a user's Zeko Shop data, 20 orders per page.
 *
 * @return array{data: array, done: bool}
 * @param string $email_address User who requested the export.
 * @param int    $page Export page (batching).
 */
function zeko_shop_privacy_export( string $email_address, int $page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$db = zeko_shop_privacy_db();
	if ( ! $db ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	global $wpdb;

	$user_id  = (int) $user->ID;
	$per_page = 20;
	$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
	$data     = array();

	if ( ! zeko_shop_privacy_table_exists( $db->table_orders() ) ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT order_id, order_number, status, subtotal, discount, tax, fee, total, currency, created_at FROM {$db->table_orders()} WHERE user_id = %d ORDER BY order_id ASC LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	foreach ( (array) $rows as $row ) {
		$order_data = array(
			array(
				'name'  => __( 'Order number', 'zeko-shop' ),
				'value' => (string) $row->order_number,
			),
			array(
				'name'  => __( 'Status', 'zeko-shop' ),
				'value' => (string) $row->status,
			),
			array(
				'name'  => __( 'Subtotal', 'zeko-shop' ),
				'value' => (string) $row->subtotal . ' ' . $row->currency,
			),
			array(
				'name'  => __( 'Discount', 'zeko-shop' ),
				'value' => (string) $row->discount . ' ' . $row->currency,
			),
			array(
				'name'  => __( 'Tax', 'zeko-shop' ),
				'value' => (string) $row->tax . ' ' . $row->currency,
			),
			array(
				'name'  => __( 'Fee', 'zeko-shop' ),
				'value' => (string) $row->fee . ' ' . $row->currency,
			),
			array(
				'name'  => __( 'Total', 'zeko-shop' ),
				'value' => (string) $row->total . ' ' . $row->currency,
			),
			array(
				'name'  => __( 'Created at', 'zeko-shop' ),
				'value' => (string) $row->created_at,
			),
		);

		// Line items with product titles and quantities.
		if ( zeko_shop_privacy_table_exists( $db->table_order_items() ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT title, qty, price, subtotal FROM {$db->table_order_items()} WHERE order_id = %d ORDER BY item_id ASC",
					(int) $row->order_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			foreach ( (array) $items as $item ) {
				$order_data[] = array(
					'name'  => __( 'Item', 'zeko-shop' ),
					'value' => sprintf( '%s — qty %d × %s = %s', $item->title, (int) $item->qty, $item->price, $item->subtotal ),
				);
			}
		}

		// Timeline events attached to this order.
		if ( zeko_shop_privacy_table_exists( $db->table_order_timeline() ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT from_status, to_status, note, created_at FROM {$db->table_order_timeline()} WHERE order_id = %d ORDER BY created_at ASC, id ASC",
					(int) $row->order_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
			foreach ( (array) $events as $event ) {
				$order_data[] = array(
					'name'  => __( 'Timeline', 'zeko-shop' ),
					'value' => sprintf( '%s → %s — %s (%s)', $event->from_status, $event->to_status, $event->note, $event->created_at ),
				);
			}
		}

		$data[] = array(
			'group_id'    => 'zeko-shop-orders',
			'group_label' => __( 'Zeko Shop — Orders', 'zeko-shop' ),
			'item_id'     => 'zeko-shop-order-' . (int) $row->order_id,
			'data'        => $order_data,
		);
	}

	// Notifications are a second, independent batch series.
	$notifications_done = true;
	if ( zeko_shop_privacy_table_exists( $db->table_notifications() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$notifications = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, title, message, is_read, created_at FROM {$db->table_notifications()} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		foreach ( (array) $notifications as $note ) {
			$data[] = array(
				'group_id'    => 'zeko-shop-notifications',
				'group_label' => __( 'Zeko Shop — Notifications', 'zeko-shop' ),
				'item_id'     => 'zeko-shop-notification-' . (int) $note->id,
				'data'        => array(
					array(
						'name'  => __( 'Type', 'zeko-shop' ),
						'value' => (string) $note->type,
					),
					array(
						'name'  => __( 'Title', 'zeko-shop' ),
						'value' => (string) $note->title,
					),
					array(
						'name'  => __( 'Message', 'zeko-shop' ),
						'value' => (string) $note->message,
					),
					array(
						'name'  => __( 'Read', 'zeko-shop' ),
						'value' => (string) $note->is_read,
					),
					array(
						'name'  => __( 'Created at', 'zeko-shop' ),
						'value' => (string) $note->created_at,
					),
				),
			);
		}
		if ( count( $notifications ) >= $per_page ) {
			$notifications_done = false;
		}
	}

	$orders_done = count( $rows ) < $per_page;

	return array(
		'data' => $data,
		'done' => $orders_done && $notifications_done,
	);
}

/**
 * Erase a user's Zeko Shop data.
 * Orders, their items and their timeline events are deleted; timeline events
 * this user caused on other buyers' orders keep the audit trail but lose the
 * actor attribution (scrubbed to 0). Called repeatedly until done is true.
 *
 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
 * @param string $email_address User who requested erasure.
 * @param int    $_page page.
 */
function zeko_shop_privacy_erase( string $email_address, int $_page = 1 ): array {
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$db = zeko_shop_privacy_db();
	if ( ! $db ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	global $wpdb;

	$user_id = (int) $user->ID;
	$removed = 0;
	$paged   = 20;

	// Resolve one batch of the user's order ids per pass.
	$order_ids = array();
	if ( zeko_shop_privacy_table_exists( $db->table_orders() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$order_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT order_id FROM {$db->table_orders()} WHERE user_id = %d ORDER BY order_id ASC LIMIT %d",
					$user_id,
					$paged
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	if ( $order_ids ) {
		$placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		if ( zeko_shop_privacy_table_exists( $db->table_order_items() ) ) {
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$db->table_order_items()} WHERE order_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$order_ids
				)
			);
		}
		if ( zeko_shop_privacy_table_exists( $db->table_order_timeline() ) ) {
			$removed += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$db->table_order_timeline()} WHERE order_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
					$order_ids
				)
			);
		}
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$db->table_orders()} WHERE order_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				$order_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// De-anonymize timeline events the user triggered on other buyers' orders.
	if ( zeko_shop_privacy_table_exists( $db->table_order_timeline() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$db->table_order_timeline()} SET actor_id = 0 WHERE actor_id = %d AND order_id NOT IN (SELECT order_id FROM {$db->table_orders()} WHERE user_id = %d)",
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Shop notifications are per-user rows.
	if ( zeko_shop_privacy_table_exists( $db->table_notifications() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$db->table_notifications()} WHERE user_id = %d LIMIT %d",
				$user_id,
				$paged
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Remove shop user meta (cart and any checkout preferences).
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	$removed += (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
			$user_id,
			'zeko_shop_%'
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery

	$remaining = 0;
	if ( zeko_shop_privacy_table_exists( $db->table_orders() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$db->table_orders()} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}
	if ( zeko_shop_privacy_table_exists( $db->table_order_timeline() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$db->table_order_timeline()} WHERE actor_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}
	if ( zeko_shop_privacy_table_exists( $db->table_notifications() ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
		$remaining += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$db->table_notifications()} WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	$messages = array();
	if ( $removed > 0 ) {
		$messages[] = __( 'Your Zeko Shop orders, order items, order timeline and shop notifications were removed.', 'zeko-shop' );
	}

	return array(
		'items_removed'  => $removed,
		'items_retained' => 0,
		'messages'       => $messages,
		'done'           => 0 === $remaining,
	);
}

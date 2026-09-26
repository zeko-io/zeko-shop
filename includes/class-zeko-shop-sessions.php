<?php
/**
 * Zeko Mentor <-> Zeko Shop bridge for mentorship sessions.
 *
 * Auto-syncs paid mentor sessions into the product catalog and marks pending
 * sessions as paid when the linked session product is purchased through the
 * shop checkout. Mentor payouts are deferred until the session is completed
 * (see Zeko_Pay_Integrations::mentor_on_session_completed()).
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Sessions. */
class Zeko_Shop_Sessions {

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
		add_action( 'admin_init', array( $this, 'maybe_sync_sessions' ) );
		add_action( 'zeko_shop_order_completed', array( $this, 'link_sessions_on_order_completed' ), 10, 4 );
		add_action( 'zeko_shop_order_refunded', array( $this, 'cancel_sessions_on_order_refund' ), 10, 3 );
		add_action( 'zeko_mentor_session_cancelled', array( $this, 'mark_session_orders_refunded' ), 20, 1 );
		add_action( 'zeko_mentor_rates_updated', array( $this, 'on_mentor_rates_updated' ) );
	}

	// ═══════════════════════════════════════════════════════════════.
	// SYNC.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Sync session products at most once per hour on admin requests.
	 */
	public function maybe_sync_sessions(): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$last = get_transient( 'zeko_shop_session_sync' );
		if ( $last && time() - (int) $last < HOUR_IN_SECONDS ) {
			return;
		}

		$this->sync_sessions();
		set_transient( 'zeko_shop_session_sync', time(), HOUR_IN_SECONDS );
	}

	/**
	 * Re-sync session products when a mentor's session rates change.
	 * Hook: zeko_mentor_rates_updated
	 */
	public function on_mentor_rates_updated(): void {
		delete_transient( 'zeko_shop_session_sync' );
		if ( class_exists( 'Zeko_Mentor' ) ) {
			$this->sync_sessions();
		}
	}

	/**
	 * Reconcile shop products with active paid mentors, one product per
	 * session type using the mentor's per-type rates.
	 */
	public function sync_sessions(): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$mentor_db  = Zeko_Mentor::instance()->get_db();
		$mentors    = $mentor_db->get_mentors(
			array(
				'is_active' => 1,
				'min_rate'  => 0.01,
				'limit'     => 500,
			)
		);
		$types      = method_exists( $mentor_db, 'session_types' ) ? $mentor_db->session_types() : array( 'video' );
		$type_label = array(
			'video' => __( 'Video', 'zeko-shop' ),
			'audio' => __( 'Audio', 'zeko-shop' ),
			'chat'  => __( 'Chat', 'zeko-shop' ),
		);

		$seen = array();

		foreach ( $mentors as $mentor ) {
			$mentor_id = (int) $mentor['user_id'];
			$base      = (float) ( $mentor['hourly_rate'] ?? 0 );

			if ( $base <= 0 ) {
				$this->set_linked_status( $mentor_id, '', 'inactive' );
				continue;
			}

			$rates  = method_exists( $mentor_db, 'get_session_rates' ) ? $mentor_db->get_session_rates( $mentor_id ) : array();
			$avatar = get_avatar_url( $mentor_id, array( 'size' => 256 ) );

			foreach ( $types as $type ) {
				$price = (float) ( $rates[ $type ] ?? $base );

				if ( $price <= 0 ) {
					$this->set_linked_status( $mentor_id, (string) $type, 'inactive' );
					continue;
				}

				$seen[] = $mentor_id . ':' . $type;

				$label = $type_label[ $type ] ?? ucfirst( (string) $type );
				$data  = array(
					'title'       => sprintf(
						/* translators: 1: session type label, 2: mentor display name */
						__( '1-on-1 %1$s Session with %2$s', 'zeko-shop' ),
						$label,
						$mentor['mentor_name'] ?? '#' . $mentor_id
					),
					'description' => wp_trim_words( $mentor['bio'] ?? '', 40 ),
					'price'       => number_format( $price, 2, '.', '' ),
					'currency'    => $mentor['currency'] ?? 'USD',
					'image_url'   => $avatar ? $avatar : '',
					'stock'       => -1,
					'status'      => 'active',
				);

				$product = $this->db->get_product_by_external( 'session', $mentor_id, (string) $type );
				if ( $product ) {
					$this->db->update_product( (int) $product['product_id'], $data );
				} else {
					$this->db->create_product(
						array_merge(
							$data,
							array(
								'external_type' => 'session',
								'external_id'   => $mentor_id,
								'external_ref'  => (string) $type,
							)
						)
					);
				}
			}
		}

		// Deactivate linked products whose (mentor, type) is no longer synced.
		$all = $this->db->get_products( array( 'limit' => 1000 ) );
		foreach ( $all as $product ) {
			if ( 'session' !== $product['external_type'] ) {
				continue;
			}
			$key = (int) $product['external_id'] . ':' . (string) ( $product['external_ref'] ?? '' );
			if ( ! in_array( $key, $seen, true ) ) {
				$this->db->update_product( (int) $product['product_id'], array( 'status' => 'inactive' ) );
			}
		}
	}

	/**
	 * Set the status of session products linked to a mentor.
	 *
	 * @param int    $mentor_id Mentor user ID.
	 * @param string $type Session type ('' = all linked products).
	 * @param string $status Target status.
	 */
	private function set_linked_status( int $mentor_id, string $type, string $status ): void {
		if ( '' === $type ) {
			foreach ( $this->db->get_products_by_external( 'session', $mentor_id ) as $product ) {
				if ( $product['status'] !== $status ) {
					$this->db->update_product( (int) $product['product_id'], array( 'status' => $status ) );
				}
			}
			return;
		}

		$product = $this->db->get_product_by_external( 'session', $mentor_id, $type );
		if ( $product && $product['status'] !== $status ) {
			$this->db->update_product( (int) $product['product_id'], array( 'status' => $status ) );
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// LINK SESSIONS ON PURCHASE.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Mark the buyer's pending sessions as paid after shop checkout.
	 * Hook: zeko_shop_order_completed
	 *
	 * @param int   $order_id Order id.
	 * @param int   $user_id User id.
	 * @param int   $tx_id Tx id.
	 * @param array $items Items.
	 */
	public function link_sessions_on_order_completed( int $order_id, int $user_id, int $tx_id, array $items ): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$mentor_db = Zeko_Mentor::instance()->get_db();

		foreach ( $items as $item ) {
			$product = $this->db->get_product( (int) ( $item['product_id'] ?? 0 ) );
			if ( ! $product || 'session' !== $product['external_type'] ) {
				continue;
			}

			$qty    = max( 1, (int) ( $item['qty'] ?? 1 ) );
			$linked = 0;

			$tx_code = '';
			if ( $tx_id > 0 && class_exists( 'Zeko_Pay_Ledger' ) ) {
				$tx      = Zeko_Pay_Ledger::instance()->get_transaction( $tx_id );
				$tx_code = (string) ( $tx['code'] ?? '' );
			}

			$pending = get_user_meta( $user_id, 'zeko_mentor_pending_sessions', true );
			$pending = is_array( $pending ) ? $pending : array();
			$pid     = (int) $product['product_id'];
			$queue   = isset( $pending[ $pid ] ) && is_array( $pending[ $pid ] ) ? $pending[ $pid ] : array();

			if ( empty( $queue ) ) {
				continue;
			}

			// Link the most recently booked pending sessions for this product.
			// Repeated bookings of the same type merge into a single cart line.
			// (qty), so link up to `qty` sessions. Cancelled, refunded or.
			// otherwise invalid sessions are dropped without consuming qty.
			while ( ! empty( $queue ) && $linked < $qty ) {
				$session_id      = (int) array_pop( $queue );
				$pending[ $pid ] = $queue;
				update_user_meta( $user_id, 'zeko_mentor_pending_sessions', $pending );

				$session = $mentor_db->get_session( $session_id );
				if ( ! $session || (int) $session['mentee_id'] !== $user_id ) {
					continue;
				}
				if ( 'paid' === $session['payment_status'] || 'refunded' === $session['payment_status'] || 'cancelled' === $session['status'] ) {
					continue;
				}

				$mentor_db->update_session(
					$session_id,
					array(
						'payment_status'   => 'paid',
						'payment_ref'      => (string) $tx_id,
						'transaction_code' => $tx_code,
					)
				);

				$buyer = get_userdata( $user_id );
				$url   = home_url( '/mentor-session/?session_id=' . $session_id );

				$mentor_db->create_notification(
					array(
						'user_id' => $user_id,
						'type'    => 'session',
						'title'   => __( 'Session payment confirmed', 'zeko-shop' ),
						'message' => sprintf(
							/* translators: 1: session date, 2: start time */
							__( 'Your mentorship session on %1$s at %2$s is confirmed. Access the meeting link from the session page.', 'zeko-shop' ),
							$session['session_date'],
							$session['start_time']
						),
						'link'    => $url,
					)
				);

				$mentor_db->create_notification(
					array(
						'user_id' => (int) $session['mentor_id'],
						'type'    => 'session',
						'title'   => __( 'New paid session', 'zeko-shop' ),
						'message' => sprintf(
							/* translators: 1: buyer name, 2: session date, 3: start time */
							__( '%1$s booked a paid session with you for %2$s at %3$s.', 'zeko-shop' ),
							$buyer ? $buyer->display_name : '#' . $user_id,
							$session['session_date'],
							$session['start_time']
						),
						'link'    => home_url( '/mentor-dashboard/' ),
					)
				);

				/**
				 * Fires after a mentorship session becomes paid via shop checkout.
				 *
				 * @param int $session_id Session ID.
				 * @param int $order_id   Shop order ID.
				 */
				do_action( 'zeko_mentor_session_paid', $session_id, $order_id );

				++$linked;
			}
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// REFUNDS.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Cancel sessions paid by a shop order when that order is refunded.
	 * Hook: zeko_shop_order_refunded (runs after the wallet refund). Marks the
	 * linked paid sessions as refunded/cancelled so the mentor is not paid out
	 * for a purchase the buyer no longer paid for.
	 *
	 * @param int $order_id Shop order ID.
	 * @param int $user_id Buyer user ID.
	 * @param int $tx_id Zeko Pay transaction ID that paid for the order.
	 */
	public function cancel_sessions_on_order_refund( int $order_id, int $user_id, int $tx_id ): void {
		if ( ! class_exists( 'Zeko_Mentor' ) || $tx_id <= 0 ) {
			return;
		}

		$mentor_db = Zeko_Mentor::instance()->get_db();

		foreach ( $mentor_db->get_sessions_by_payment_ref( $tx_id, $user_id ) as $session ) {
			if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
				continue;
			}

			$session_id = (int) $session['session_id'];
			$this->remove_from_pending_queue( $user_id, $session_id );

			$mentor_db->update_session(
				$session_id,
				array(
					'payment_status' => 'refunded',
					'status'         => 'cancelled',
				)
			);

			$mentor_db->create_notification(
				array(
					'user_id' => $user_id,
					'type'    => 'session',
					'title'   => __( 'Session payment refunded', 'zeko-shop' ),
					'message' => sprintf(
						/* translators: 1: session date, 2: start time */
						__( 'Your mentorship session on %1$s at %2$s was refunded and cancelled.', 'zeko-shop' ),
						$session['session_date'],
						$session['start_time']
					),
					'link'    => home_url( '/mentor-dashboard/' ),
				)
			);
		}
	}

	/**
	 * Mark shop orders refunded when a session cancellation refund succeeds.
	 * Hook: zeko_mentor_session_cancelled (runs after the pay-side refund).
	 *
	 * @param int $session_id Session id.
	 */
	public function mark_session_orders_refunded( int $session_id ): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$mentor_db = Zeko_Mentor::instance()->get_db();
		$session   = $mentor_db->get_session( $session_id );
		if ( ! $session ) {
			return;
		}

		// Drop the cancelled session from the buyer's pending queue so a later.
		// checkout can never mark it paid.
		$this->remove_from_pending_queue( (int) $session['mentee_id'], $session_id );

		if ( empty( $session['payment_ref'] ) ) {
			return;
		}

		$session_type = (string) ( $session['session_type'] ?? '' );
		$product      = $this->db->get_product_by_external( 'session', (int) $session['mentor_id'], $session_type );

		// Fall back to the legacy pre-session-type product for older sessions.
		if ( ! $product && '' !== $session_type ) {
			$product = $this->db->get_product_by_external( 'session', (int) $session['mentor_id'], '' );
		}
		if ( ! $product ) {
			return;
		}

		$tx_id = absint( $session['payment_ref'] );
		if ( $tx_id <= 0 ) {
			return;
		}

		$orders = $this->db->get_user_orders( (int) $session['mentee_id'], 100 );
		foreach ( $orders as $order ) {
			if ( 'completed' !== $order['status'] || (int) $order['tx_id'] !== $tx_id ) {
				continue;
			}
			$items = $this->db->get_order_items( (int) $order['order_id'] );
			foreach ( $items as $item ) {
				if ( (int) $item['product_id'] === (int) $product['product_id'] ) {
					$this->db->update_order( (int) $order['order_id'], array( 'status' => 'refunded' ) );
					break;
				}
			}
		}
	}

	/**
	 * Remove a session ID from the buyer's pending checkout queue.
	 *
	 * @param int $user_id Buyer user ID.
	 * @param int $session_id Session ID to remove.
	 */
	private function remove_from_pending_queue( int $user_id, int $session_id ): void {
		$pending = get_user_meta( $user_id, 'zeko_mentor_pending_sessions', true );
		if ( ! is_array( $pending ) ) {
			return;
		}

		$changed = false;
		foreach ( $pending as $pid => $queue ) {
			if ( ! is_array( $queue ) ) {
				continue;
			}
			$filtered = array_values( array_filter( $queue, static fn( $id ) => (int) $id !== $session_id ) );
			if ( count( $filtered ) !== count( $queue ) ) {
				$pending[ $pid ] = $filtered;
				$changed         = true;
			}
		}

		if ( $changed ) {
			update_user_meta( $user_id, 'zeko_mentor_pending_sessions', $pending );
		}
	}
}

<?php
/**
 * Zeko Mentor <-> Zeko Shop bridge.
 *
 * Auto-syncs paid programs into the product catalog and enrolls members
 * when a linked program is purchased through the shop checkout.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Programs. */
class Zeko_Shop_Programs {

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
		add_action( 'admin_init', array( $this, 'maybe_sync_programs' ) );
		add_action( 'zeko_shop_order_completed', array( $this, 'enroll_on_order_completed' ), 10, 4 );
		add_action( 'zeko_shop_order_refunded', array( $this, 'unenroll_on_order_refunded' ), 10, 3 );
	}

	// ═══════════════════════════════════════════════════════════════.
	// SYNC.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Sync program products at most once per hour on admin requests.
	 */
	public function maybe_sync_programs(): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$last = get_transient( 'zeko_shop_program_sync' );
		if ( $last && time() - (int) $last < HOUR_IN_SECONDS ) {
			return;
		}

		$this->sync_programs();
		set_transient( 'zeko_shop_program_sync', time(), HOUR_IN_SECONDS );
	}

	/**
	 * Reconcile shop products with paid mentor programs.
	 */
	public function sync_programs(): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$mentor_db = Zeko_Mentor::instance()->get_db();
		$programs  = $mentor_db->get_programs(
			array(
				'status' => 'active',
				'limit'  => 500,
			)
		);

		$seen = array();

		foreach ( $programs as $program ) {
			$seen[] = (int) $program['program_id'];
			$price  = (float) ( $program['price'] ?? 0 );

			if ( $price <= 0 ) {
				$this->set_linked_status( (int) $program['program_id'], 'inactive' );
				continue;
			}

			$product = $this->db->get_product_by_external( 'program', (int) $program['program_id'] );
			$data    = array(
				'title'       => $program['title'],
				'description' => $program['description'] ?? '',
				'price'       => number_format( $price, 2, '.', '' ),
				'currency'    => $program['currency'] ?? 'USD',
				'image_url'   => '',
				'stock'       => -1,
				'status'      => 'active',
			);

			// Use the mentor's avatar as the product image when available.
			$avatar = get_avatar_url( (int) $program['mentor_id'], array( 'size' => 256 ) );
			if ( $avatar ) {
				$data['image_url'] = $avatar;
			}

			if ( $product ) {
				$this->db->update_product( (int) $product['product_id'], $data );
			} else {
				$this->db->create_product(
					array_merge(
						$data,
						array(
							'external_type' => 'program',
							'external_id'   => (int) $program['program_id'],
						)
					)
				);
			}
		}

		// Deactivate linked products whose program no longer exists / is not active.
		$all = $this->db->get_products( array( 'limit' => 500 ) );
		foreach ( $all as $product ) {
			if ( 'program' !== $product['external_type'] ) {
				continue;
			}
			$program_id = (int) $product['external_id'];
			if ( ! in_array( $program_id, $seen, true ) ) {
				$this->db->update_product( (int) $product['product_id'], array( 'status' => 'inactive' ) );
			}
		}
	}

	/**
	 * Set the status of all products linked to a program.
	 *
	 * @param int    $program_id Program id.
	 * @param string $status Status.
	 */
	private function set_linked_status( int $program_id, string $status ): void {
		$product = $this->db->get_product_by_external( 'program', $program_id );
		if ( $product && $product['status'] !== $status ) {
			$this->db->update_product( (int) $product['product_id'], array( 'status' => $status ) );
		}
	}

	// ═══════════════════════════════════════════════════════════════.
	// ENROLLMENT ON PURCHASE.
	// ═══════════════════════════════════════════════════════════════.

	/**
	 * Enroll the buyer in every program they purchased via the shop.
	 * Hook: zeko_shop_order_completed
	 *
	 * @param int   $order_id Order id.
	 * @param int   $user_id User id.
	 * @param int   $tx_id Tx id.
	 * @param array $items Items.
	 */
	public function enroll_on_order_completed( int $order_id, int $user_id, int $tx_id, array $items ): void {
		if ( ! class_exists( 'Zeko_Mentor' ) ) {
			return;
		}

		$mentor_db = Zeko_Mentor::instance()->get_db();

		foreach ( $items as $item ) {
			$product = $this->db->get_product( (int) ( $item['product_id'] ?? 0 ) );
			if ( ! $product || 'program' !== $product['external_type'] ) {
				continue;
			}

			$program_id = (int) $product['external_id'];
			$program    = $mentor_db->get_program( $program_id );

			if ( ! $program || 'active' !== $program['status'] ) {
				continue;
			}
			if ( $mentor_db->is_enrolled( $program_id, $user_id ) ) {
				continue;
			}
			if ( (int) $program['current_members'] >= (int) $program['max_members'] ) {
				continue;
			}

			$mentor_db->enroll_program( $program_id, $user_id );
			update_user_meta( $user_id, 'zeko_mentor_program_payment_' . $program_id, $tx_id );

			$program_url = home_url( '/programs/' . $program_id . '/' );

			$mentor_db->create_notification(
				array(
					'user_id' => $user_id,
					'type'    => 'program',
					'title'   => __( 'Enrolled in program', 'zeko-shop' ),
					'message' => sprintf(
						/* translators: %s: program title */
						__( 'Your purchase of "%s" is complete. Access the program member area here.', 'zeko-shop' ),
						$program['title']
					),
					'link'    => $program_url,
				)
			);

			$current_user = wp_get_current_user();
			$mentor_db->create_notification(
				array(
					'user_id' => (int) $program['mentor_id'],
					'type'    => 'program',
					'title'   => __( 'New program member', 'zeko-shop' ),
					'message' => sprintf(
						/* translators: 1: member name, 2: program title */
						__( '%1$s joined your program "%2$s".', 'zeko-shop' ),
						$current_user->display_name,
						$program['title']
					),
					'link'    => $program_url,
				)
			);

			/**
			 * Fire the mentor enrollment hook so other modules stay in sync.
			 *
			 * @param int $program_id Program ID.
			 * @param int $user_id    New member ID.
			 */
			do_action( 'zeko_mentor_program_enrolled', $program_id, $user_id );
		}
	}

	/**
	 * Undo a program enrollment when the shop order that paid for it is
	 * refunded.
	 * Hook: zeko_shop_order_refunded (runs after the wallet refund).
	 *
	 * @param int $order_id Shop order ID.
	 * @param int $user_id Buyer user ID.
	 * @param int $tx_id Zeko Pay transaction ID that paid for the order.
	 */
	public function unenroll_on_order_refunded( int $order_id, int $user_id, int $tx_id ): void {
		if ( ! class_exists( 'Zeko_Mentor' ) || $tx_id <= 0 ) {
			return;
		}

		$mentor_db = Zeko_Mentor::instance()->get_db();

		foreach ( $this->db->get_order_items( $order_id ) as $item ) {
			$product = $this->db->get_product( (int) ( $item['product_id'] ?? 0 ) );
			if ( ! $product || 'program' !== $product['external_type'] ) {
				continue;
			}

			$program_id = (int) $product['external_id'];
			$program    = $mentor_db->get_program( $program_id );
			if ( ! $program ) {
				continue;
			}

			// Only unenroll when THIS order is the one that paid for the.
			// program (each purchase records its tx id in user meta).
			$payment_tx = (int) get_user_meta( $user_id, 'zeko_mentor_program_payment_' . $program_id, true );
			if ( $payment_tx !== $tx_id ) {
				continue;
			}
			if ( ! $mentor_db->is_enrolled( $program_id, $user_id ) ) {
				continue;
			}

			delete_user_meta( $user_id, 'zeko_mentor_program_payment_' . $program_id );
			$mentor_db->remove_program_member( $program_id, $user_id );

			$mentor_db->create_notification(
				array(
					'user_id' => $user_id,
					'type'    => 'program',
					'title'   => __( 'Program purchase refunded', 'zeko-shop' ),
					'message' => sprintf(
						/* translators: %s: program title */
						__( 'Your purchase of "%s" was refunded, so your enrollment has been cancelled.', 'zeko-shop' ),
						$program['title']
					),
					'link'    => home_url( '/programs/' . $program_id . '/' ),
				)
			);

			/**
			 * Keep the ecosystem in sync (same event as leaving a program).
			 *
			 * @param int $program_id Program ID.
			 * @param int $user_id    Former member ID.
			 */
			do_action( 'zeko_mentor_program_left', $program_id, $user_id );
		}
	}

	/**
	 * Mark shop orders refunded when a program leave refund succeeds.
	 *
	 * @param int $program_id Program id.
	 * @param int $user_id User id.
	 */
	public function mark_program_orders_refunded( int $program_id, int $user_id ): void {
		$product = $this->db->get_product_by_external( 'program', $program_id );
		if ( ! $product ) {
			return;
		}

		// Only the order that actually paid for this program may be marked.
		// refunded (tx id is stored per-purchase in user meta).
		$payment_tx = (int) get_user_meta( $user_id, 'zeko_mentor_program_payment_' . $program_id, true );
		if ( $payment_tx <= 0 ) {
			return;
		}

		$orders = $this->db->get_user_orders( $user_id, 100 );
		foreach ( $orders as $order ) {
			if ( 'completed' !== $order['status'] || (int) $order['tx_id'] !== $payment_tx ) {
				continue;
			}
			$items = $this->db->get_order_items( (int) $order['order_id'] );
			foreach ( $items as $item ) {
				if ( (int) $item['product_id'] === (int) $product['product_id'] ) {
					$this->db->update_order( (int) $order['order_id'], array( 'status' => 'refunded' ) );
					break;
				}
			}
			break;
		}
	}
}

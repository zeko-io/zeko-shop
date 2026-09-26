<?php
/**
 * Shared checkout totals engine: promo discount + tax + platform fee.
 *
 * Used by both the checkout page (display) and the checkout AJAX handler
 * (authoritative) so the numbers always match.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Totals. */
class Zeko_Shop_Totals {

	/**
	 * Compute full order totals from validated cart items.
	 * subtotal: string, discount: string, taxable: string, tax: string,
	 * tax_breakdown: array, amount: string, fee: string, total: string,
	 * currency: string, promo: ?array, promo_valid: bool
	 * }
	 *
	 * @return array{
	 * @param int    $user_id Current user.
	 * @param array  $items Validated items: each has qty, price (string), subtotal (string).
	 * @param string $currency Order currency.
	 */
	public static function compute( int $user_id, array $items, string $currency ): array {
		$subtotal = '0.00';
		foreach ( $items as $item ) {
			$subtotal = bcadd( $subtotal, (string) ( $item['subtotal'] ?? '0' ), 2 );
		}

		$promo       = null;
		$promo_valid = true;
		$discount    = '0.00';

		$applied = get_user_meta( $user_id, 'zeko_shop_promo', true );
		if ( is_array( $applied ) && ! empty( $applied['code'] ) ) {
			$code = (string) $applied['code'];

			if ( class_exists( 'Zeko_Pay_Promos' ) ) {
				$result = Zeko_Pay_Promos::instance()->validate( $code, (float) $subtotal, $user_id );

				if ( ! empty( $result['valid'] ) ) {
					$discount = number_format( min( (float) $result['discount'], (float) $subtotal ), 2, '.', '' );
					$promo    = array(
						'code'     => $code,
						'promo_id' => (int) ( $result['promo_id'] ?? 0 ),
						'discount' => $discount,
					);
				} else {
					$promo_valid = false;
				}
			}
		}

		$taxable = bcsub( $subtotal, $discount, 2 );

		// Tax (country derived from the buyer's profile when available).
		$tax           = '0.00';
		$tax_breakdown = array();
		if ( class_exists( 'Zeko_Pay_Tax' ) && (float) $taxable > 0 ) {
			$location      = array(
				'country' => get_user_meta( $user_id, 'billing_country', true )
					?: (string) get_user_meta( $user_id, 'country', true ),
				'state'   => get_user_meta( $user_id, 'billing_state', true ),
			);
			$tax_result    = Zeko_Pay_Tax::instance()->calculate( $taxable, $location );
			$tax           = (string) ( $tax_result['tax'] ?? '0.00' );
			$tax_breakdown = (array) ( $tax_result['breakdown'] ?? array() );
		}

		$amount = bcadd( $taxable, $tax, 2 );

		// Fee is computed on the exact amount passed to the wallet charge.
		// so it always matches Zeko_Pay_SDK::charge().
		$fee = '0.00';
		if ( class_exists( 'Zeko_Pay_Fees' ) ) {
			$fee_result = Zeko_Pay_Fees::instance()->calculate( $amount, 'charge' );
			$fee        = (string) ( $fee_result['fee'] ?? '0.00' );
		}

		$total = bcadd( $amount, $fee, 2 );

		return array(
			'subtotal'      => $subtotal,
			'discount'      => $discount,
			'taxable'       => $taxable,
			'tax'           => $tax,
			'tax_breakdown' => $tax_breakdown,
			'amount'        => $amount,
			'fee'           => $fee,
			'total'         => $total,
			'currency'      => $currency,
			'promo'         => $promo,
			'promo_valid'   => $promo_valid,
		);
	}

	/**
	 * Store an applied promo code for the user.
	 *
	 * @param int    $user_id User id.
	 * @param string $code Code.
	 * @param int    $promo_id Promo id.
	 */
	public static function set_promo( int $user_id, string $code, int $promo_id ): void {
		update_user_meta(
			$user_id,
			'zeko_shop_promo',
			array(
				'code'     => $code,
				'promo_id' => $promo_id,
			)
		);
	}

	/**
	 * Clear the applied promo.
	 *
	 * @param int $user_id User id.
	 */
	public static function clear_promo( int $user_id ): void {
		delete_user_meta( $user_id, 'zeko_shop_promo' );
	}
}

<?php
/**
 * Order emails: a line-item receipt sent after checkout.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Shop_Emails. */
class Zeko_Shop_Emails {

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
		add_action( 'zeko_shop_order_completed', array( $this, 'send_receipt' ), 10, 4 );
		add_action( 'zeko_shop_order_completed', array( $this, 'notify_admin_new_order' ), 20, 4 );
		add_action( 'zeko_shop_order_refunded', array( $this, 'send_refund_email' ), 10, 3 );
	}

	/**
	 * Wrap shop email content in a branded HTML document.
	 * Consistent header (brand wordmark + marketplace tagline), content card,
	 * and quiet footer shared by receipt, refund, and admin-new-order emails.
	 *
	 * @return string Full HTML email.
	 * @param string $site_name Display site name.
	 * @param string $body Inner HTML body.
	 */
	private function wrap_template( string $site_name, string $body ): string {
		$brand      = '#4f46e5';
		$brand_dark = '#4338ca';
		$bg         = '#f1f5f9';
		$muted      = '#64748b';
		$border     = '#e2e8f0';

		return '<!DOCTYPE html>'
			. '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">'
			. '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<title>' . esc_html( $site_name ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $bg . ';font-family:Arial,Helvetica,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';"><tr><td align="center" style="padding:24px 16px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">'
			. '<tr><td style="background:' . $brand . ';height:5px;line-height:5px;font-size:0;">&nbsp;</td></tr>'
			. '<tr><td style="background:' . $brand_dark . ';padding:26px 30px;text-align:center;">'
			. '<h1 style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">' . esc_html( $site_name ) . '</h1>'
			. '<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.9);">' . esc_html__( 'Marketplace — shop from the community', 'zeko-shop' ) . '</p>'
			. '</td></tr>'
			. '<tr><td style="background:#ffffff;padding:32px;">' . $body . '</td></tr>'
			. '<tr><td style="background:#ffffff;border-top:1px solid ' . $border . ';padding:16px 30px;text-align:center;">'
			. '<p style="margin:0;font-size:12px;color:' . $muted . ';">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $site_name ) . ' &middot; <a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . $muted . ';">' . esc_html__( 'Visit site', 'zeko-shop' ) . '</a></p>'
			. '</td></tr>'
			. '</table></td></tr></table>'
			. '</body></html>';
	}

	/**
	 * Notify site administrators when a new order is placed — an in-app
	 * notification for every administrator plus a single admin email.
	 *
	 * @param int   $order_id Order ID.
	 * @param int   $user_id Buyer user ID.
	 * @param int   $tx_id Transaction ID (0 for manual orders).
	 * @param array $items Purchased items.
	 */
	public function notify_admin_new_order( int $order_id, int $user_id, int $tx_id, array $items ): void {
		unset( $items );
		unset( $tx_id );
		$order = $this->db->get_order( $order_id );
		$buyer = get_userdata( $user_id );
		if ( ! $order ) {
			return;
		}

		$admin_ids = get_users(
			array(
				'capability' => 'manage_options',
				'fields'     => 'ID',
				'number'     => 20,
			)
		);

		$title = sprintf(
			/* translators: %s: order number */
			__( 'New order %s', 'zeko-shop' ),
			$order['order_number']
		);
		$message = sprintf(
			/* translators: 1: buyer name, 2: order total */
			__( '%1$s placed a new order for %2$s.', 'zeko-shop' ),
			$buyer ? $buyer->display_name : __( 'A user', 'zeko-shop' ),
			zeko_shop_format_price( (string) $order['total'], $order['currency'] )
		);

		if ( class_exists( 'Zeko_Pay_Notifications' ) ) {
			foreach ( $admin_ids as $admin_id ) {
				Zeko_Pay_Notifications::instance()->create( (int) $admin_id, 'order', $title, $message );
			}
		}

		$settings   = get_option( 'zeko_pay_settings', array() );
		$site_name  = $settings['platform_name'] ?? get_bloginfo( 'name' );
		$from_email = $settings['support_email'] ?? get_option( 'admin_email' );

		$subject = sprintf(
			/* translators: 1: site name, 2: order number */
			__( '[%1$s] New order %2$s', 'zeko-shop' ),
			$site_name,
			$order['order_number']
		);

		$orders_admin_url = admin_url( 'admin.php?page=zeko-shop-orders' );

		$body  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#111827;line-height:1.5;">';
		$body .= '<h2 style="margin:0 0 4px;">' . esc_html__( 'New shop order', 'zeko-shop' ) . '</h2>';
		$body .= '<p style="margin:0 0 20px;color:#6b7280;">' . esc_html( $message ) . '</p>';
		$body .= '<table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0"><tbody>';
		$body .= '<tr><td style="padding:6px 10px;">' . esc_html__( 'Order', 'zeko-shop' ) . '</td><td style="padding:6px 10px;">' . esc_html( $order['order_number'] ) . '</td></tr>';
		$body .= '<tr><td style="padding:6px 10px;">' . esc_html__( 'Buyer', 'zeko-shop' ) . '</td><td style="padding:6px 10px;">' . esc_html( $buyer ? $buyer->display_name . ' (' . $buyer->user_email . ')' : '#' . $user_id ) . '</td></tr>';
		$body .= '<tr><td style="padding:6px 10px;">' . esc_html__( 'Total', 'zeko-shop' ) . '</td><td style="padding:6px 10px;">' . esc_html( zeko_shop_format_price( (string) $order['total'], $order['currency'] ) ) . '</td></tr>';
		$body .= '</tbody></table>';
		$body .= '<p style="margin:24px 0 8px;"><a href="' . esc_url( $orders_admin_url ) . '" style="background:#4f46e5;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;">' . esc_html__( 'Manage orders', 'zeko-shop' ) . '</a></p>';
		$body .= '</div>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $site_name . ' <' . $from_email . '>',
		);

		$headers = apply_filters( 'zeko_shop_email_headers', $headers, get_option( 'admin_email' ), $subject );
		$body    = apply_filters( 'zeko_shop_email_body', $body, $order_id );

		$sent = wp_mail( get_option( 'admin_email' ), $subject, $this->wrap_template( $site_name, $body ), $headers );

		if ( $sent && class_exists( 'Zeko_Pay_Logger' ) ) {
			Zeko_Pay_Logger::info(
				'Shop admin notification email sent',
				array(
					'order_id' => $order_id,
					'to'       => get_option( 'admin_email' ),
				)
			);
		}
	}

	/**
	 * Send the buyer a line-item receipt.
	 *
	 * @param int   $order_id Order ID.
	 * @param int   $user_id Buyer user ID.
	 * @param int   $_tx_id tx id.
	 * @param array $items Purchased items.
	 */
	public function send_receipt( int $order_id, int $user_id, int $_tx_id, array $items ): void {
		$order = $this->db->get_order( $order_id );
		$user  = get_userdata( $user_id );
		if ( ! $order || ! $user || empty( $user->user_email ) ) {
			return;
		}

		if ( empty( $items ) ) {
			$items = $this->db->get_order_items( $order_id );
		}

		$settings   = get_option( 'zeko_pay_settings', array() );
		$site_name  = $settings['platform_name'] ?? get_bloginfo( 'name' );
		$from_email = $settings['support_email'] ?? get_option( 'admin_email' );

		$subject = sprintf(
			/* translators: 1: site name, 2: order number */
			__( '[%1$s] Order receipt %2$s', 'zeko-shop' ),
			$site_name,
			$order['order_number']
		);

		$receipt_url = Zeko_Shop_Frontend::order_url( $order_id );
		$orders_url  = zeko_shop_page_url( 'my-orders' );
		$currency    = $order['currency'];

		$rows = '';
		foreach ( $items as $item ) {
			$rows .= '<tr>';
			$rows .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' . esc_html( $item['title'] ) . '</td>';
			$rows .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' . (int) $item['qty'] . '</td>';
			$rows .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right;">' . esc_html( zeko_shop_format_price( (string) $item['price'], $currency ) ) . '</td>';
			$rows .= '<td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right;">' . esc_html( zeko_shop_format_price( (string) $item['subtotal'], $currency ) ) . '</td>';
			$rows .= '</tr>';
		}

		$summary = '<tr><td style="padding:6px 10px;text-align:right;">' . esc_html__( 'Subtotal', 'zeko-shop' ) . '</td>'
			. '<td style="padding:6px 10px;text-align:right;">' . esc_html( zeko_shop_format_price( (string) $order['subtotal'], $currency ) ) . '</td></tr>';
		if ( (float) $order['discount'] > 0 ) {
			$summary .= '<tr><td style="padding:6px 10px;text-align:right;">' . esc_html__( 'Discount', 'zeko-shop' ) . '</td>'
				. '<td style="padding:6px 10px;text-align:right;">−' . esc_html( zeko_shop_format_price( (string) $order['discount'], $currency ) ) . '</td></tr>';
		}
		if ( (float) $order['tax'] > 0 ) {
			$summary .= '<tr><td style="padding:6px 10px;text-align:right;">' . esc_html__( 'Tax', 'zeko-shop' ) . '</td>'
				. '<td style="padding:6px 10px;text-align:right;">' . esc_html( zeko_shop_format_price( (string) $order['tax'], $currency ) ) . '</td></tr>';
		}
		if ( (float) $order['fee'] > 0 ) {
			$summary .= '<tr><td style="padding:6px 10px;text-align:right;">' . esc_html__( 'Platform fee', 'zeko-shop' ) . '</td>'
				. '<td style="padding:6px 10px;text-align:right;">' . esc_html( zeko_shop_format_price( (string) $order['fee'], $currency ) ) . '</td></tr>';
		}
		$summary .= '<tr><td style="padding:8px 10px;text-align:right;font-weight:bold;">' . esc_html__( 'Total', 'zeko-shop' ) . '</td>'
			. '<td style="padding:8px 10px;text-align:right;font-weight:bold;">' . esc_html( zeko_shop_format_price( (string) $order['total'], $currency ) ) . '</td></tr>';

		$downloads = '';
		foreach ( $items as $item ) {
			if ( ! empty( $item['file_url'] ) && 'completed' === $order['status'] ) {
				$downloads .= '<li><a href="' . esc_url( Zeko_Shop_Frontend::download_url( $order_id, (int) $item['item_id'] ) ) . '">'
					. esc_html( $item['title'] ) . '</a></li>';
			}
		}
		if ( $downloads ) {
			$downloads = '<h3 style="margin:24px 0 8px;">' . esc_html__( 'Your downloads', 'zeko-shop' ) . '</h3>'
				. '<ul>' . $downloads . '</ul>';
		}

		$body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#111827;line-height:1.5;">';
		/* translators: %s: customer display name */
		$body .= '<h2 style="margin:0 0 4px;">' . esc_html( sprintf( __( 'Thank you for your order, %s!', 'zeko-shop' ), $user->display_name ) ) . '</h2>';
		/* translators: %s: order number */
		$body .= '<p style="margin:0 0 20px;color:#6b7280;">' . esc_html( sprintf( __( 'Order %s was placed successfully.', 'zeko-shop' ), $order['order_number'] ) ) . '</p>';
		$body .= '<table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0"><thead><tr>';
		$body .= '<th style="padding:10px;text-align:left;border-bottom:2px solid #4f46e5;">' . esc_html__( 'Product', 'zeko-shop' ) . '</th>';
		$body .= '<th style="padding:10px;text-align:left;border-bottom:2px solid #4f46e5;">' . esc_html__( 'Qty', 'zeko-shop' ) . '</th>';
		$body .= '<th style="padding:10px;text-align:right;border-bottom:2px solid #4f46e5;">' . esc_html__( 'Price', 'zeko-shop' ) . '</th>';
		$body .= '<th style="padding:10px;text-align:right;border-bottom:2px solid #4f46e5;">' . esc_html__( 'Subtotal', 'zeko-shop' ) . '</th>';
		$body .= '</tr></thead><tbody>' . $rows . '</tbody></table>';
		$body .= '<table style="width:100%;border-collapse:collapse;margin-top:16px;" cellpadding="0" cellspacing="0"><tbody>' . $summary . '</tbody></table>';
		$body .= $downloads;
		$body .= '<p style="margin:24px 0 8px;">';
		$body .= '<a href="' . esc_url( $receipt_url ) . '" style="background:#4f46e5;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;">' . esc_html__( 'View Receipt', 'zeko-shop' ) . '</a> ';
		$body .= '<a href="' . esc_url( $orders_url ) . '" style="background:#e5e7eb;color:#111827;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;">' . esc_html__( 'My Orders', 'zeko-shop' ) . '</a>';
		$body .= '</p>';
		$body .= '<p style="margin-top:24px;color:#6b7280;font-size:13px;">' . esc_html__( 'Questions about this order? Reply to this email and our team will help.', 'zeko-shop' ) . '</p>';
		$body .= '</div>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $site_name . ' <' . $from_email . '>',
		);

		/**
		 * Filters the receipt email headers.
		 *
		 * @param array  $headers Email headers.
		 * @param string $to      Recipient email.
		 * @param string $subject Email subject.
		 */
		$headers = apply_filters( 'zeko_shop_email_headers', $headers, $user->user_email, $subject );

		/**
		 * Filters the receipt email body.
		 *
		 * @param string $body    Email body.
		 * @param int    $order_id Order ID.
		 */
		$body = apply_filters( 'zeko_shop_email_body', $body, $order_id );

		$sent = wp_mail( $user->user_email, $subject, $this->wrap_template( $site_name, $body ), $headers );

		if ( $sent && class_exists( 'Zeko_Pay_Logger' ) ) {
			Zeko_Pay_Logger::info(
				'Shop order receipt email sent',
				array(
					'order_id' => $order_id,
					'to'       => $user->user_email,
				)
			);
		}
	}

	/**
	 * Notify the buyer by email when their order is refunded.
	 *
	 * @param int $order_id Order ID.
	 * @param int $user_id Buyer user ID.
	 * @param int $tx_id Refunded transaction ID.
	 */
	public function send_refund_email( int $order_id, int $user_id, int $tx_id ): void {
		unset( $tx_id );
		$order = $this->db->get_order( $order_id );
		$user  = get_userdata( $user_id );
		if ( ! $order || ! $user || empty( $user->user_email ) ) {
			return;
		}

		$settings   = get_option( 'zeko_pay_settings', array() );
		$site_name  = $settings['platform_name'] ?? get_bloginfo( 'name' );
		$from_email = $settings['support_email'] ?? get_option( 'admin_email' );

		$subject = sprintf(
			/* translators: 1: site name, 2: order number */
			__( '[%1$s] Order %2$s refunded', 'zeko-shop' ),
			$site_name,
			$order['order_number']
		);

		$orders_url = zeko_shop_page_url( 'my-orders' );

		$body  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#111827;line-height:1.5;">';
		$body .= '<h2 style="margin:0 0 4px;">' . esc_html__( 'Your order was refunded', 'zeko-shop' ) . '</h2>';
		/* translators: %s: order number */
		$body .= '<p style="margin:0 0 20px;color:#6b7280;">' . esc_html( sprintf( __( 'Order %s has been refunded. The amount has been credited back to your wallet.', 'zeko-shop' ), $order['order_number'] ) ) . '</p>';
		$body .= '<p style="margin:24px 0 8px;">';
		$body .= '<a href="' . esc_url( $orders_url ) . '" style="background:#4f46e5;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;">' . esc_html__( 'My Orders', 'zeko-shop' ) . '</a>';
		$body .= '</p>';
		$body .= '<p style="margin-top:24px;color:#6b7280;font-size:13px;">' . esc_html__( 'Questions about this refund? Reply to this email and our team will help.', 'zeko-shop' ) . '</p>';
		$body .= '</div>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $site_name . ' <' . $from_email . '>',
		);

		$headers = apply_filters( 'zeko_shop_email_headers', $headers, $user->user_email, $subject );
		$body    = apply_filters( 'zeko_shop_email_body', $body, $order_id );

		$sent = wp_mail( $user->user_email, $subject, $this->wrap_template( $site_name, $body ), $headers );

		if ( $sent && class_exists( 'Zeko_Pay_Logger' ) ) {
			Zeko_Pay_Logger::info(
				'Shop refund email sent',
				array(
					'order_id' => $order_id,
					'to'       => $user->user_email,
				)
			);
		}
	}
}

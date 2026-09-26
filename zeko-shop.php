<?php
/**
 * Plugin Name:       Zeko Shop
 * Plugin URI:        https://ozconsultz.com/zeko-shop
 * Description:       Digital shop for the Zeko ecosystem powered by Zeko Pay — product catalog, cart, wallet checkout, orders, invoices, receipts, and notifications.
 * Version:           1.5.0
 * Author:            Zeko Team
 * Author URI:        https://ozconsultz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zeko-shop
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      7.1.2
 * Requires Plugins:  zeko-pay
 *
 * @package Zeko_ZEKO_SHOP
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ZEKO_SHOP_VERSION' ) ) {
	define( 'ZEKO_SHOP_VERSION', '1.5.0' );
}

if ( ! defined( 'ZEKO_SHOP_PLUGIN_PATH' ) ) {
	define( 'ZEKO_SHOP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ZEKO_SHOP_PLUGIN_URL' ) ) {
	define( 'ZEKO_SHOP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ZEKO_SHOP_PLUGIN_BASENAME' ) ) {
	define( 'ZEKO_SHOP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'ZEKO_SHOP_DB_VERSION' ) ) {
	define( 'ZEKO_SHOP_DB_VERSION', '1.8.0' );
}

require_once ZEKO_SHOP_PLUGIN_PATH . 'includes/class-zeko-shop.php';
require_once ZEKO_SHOP_PLUGIN_PATH . 'includes/privacy/class-zeko-shop-privacy.php';

/**
 * Get a page by slug without the deprecated get_page_by_path().
 *
 * @param string $slug Slug.
 * @param string $post_type Post type.
 */
function zeko_shop_get_page_by_slug( string $slug, string $post_type = 'page' ) {
	if ( class_exists( 'Zeko_Core_Helpers' ) && method_exists( 'Zeko_Core_Helpers', 'get_page_by_slug' ) ) {
		return Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug, $post_type );
	}

	$query = new WP_Query(
		array(
			'post_type'      => $post_type,
			'name'           => sanitize_title( $slug ),
			'posts_per_page' => 1,
			'post_status'    => 'publish',
		)
	);
	return $query->have_posts() ? $query->posts[0] : null;
}

/**
 * Get the URL of a shop page by slug.
 *
 * @param string $slug Slug.
 */
function zeko_shop_page_url( string $slug ): string {
	// The generic 'checkout' slug may be owned by another module (e.g. the.
	// Zeko Pay checkout page), so resolve the shop checkout to its own page.
	$lookup = 'checkout' === $slug ? array( 'shop-checkout', 'checkout' ) : array( $slug );

	if ( class_exists( 'Zeko_Core_Helpers' ) && method_exists( 'Zeko_Core_Helpers', 'get_page_url' ) ) {
		return Zeko_Core_Helpers::get_instance()->get_page_url(
			'shop',
			$slug,
			array(
				'aliases'  => $lookup,
				'fallback' => home_url( '/' ),
			)
		);
	}

	foreach ( $lookup as $candidate ) {
		$page = zeko_shop_get_page_by_slug( $candidate );
		if ( $page ) {
			return get_permalink( $page->ID );
		}
	}
	return home_url( '/' );
}

/**
 * Format an amount using Zeko Pay when available.
 *
 * @param string $amount Amount.
 * @param string $currency Currency.
 */
function zeko_shop_format_price( string $amount, string $currency = 'USD' ): string {
	if ( class_exists( 'Zeko_Pay_Utils' ) && method_exists( 'Zeko_Pay_Utils', 'format_currency' ) ) {
		return Zeko_Pay_Utils::format_currency( $amount, $currency );
	}
	return $currency . ' ' . number_format( (float) $amount, 2 );
}

/**
 * Boot the plugin on plugins_loaded.
 */
function zeko_shop_init() {
	load_plugin_textdomain( 'zeko-shop', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	return Zeko_Shop::instance();
}
add_action( 'plugins_loaded', 'zeko_shop_init' );

/**
 * Helper to access the singleton.
 *
 * @return Zeko_Shop
 */
function zeko_shop() {
	return Zeko_Shop::instance();
}

/**
 * Create the default shop shortcode pages.
 */
function zeko_shop_create_pages() {
	$pages = array(
		'shop'          => array(
			'title'   => __( 'Shop', 'zeko-shop' ),
			'content' => '[zeko_shop]',
		),
		'cart'          => array(
			'title'   => __( 'Cart', 'zeko-shop' ),
			'content' => '[zeko_shop_cart]',
		),
		'shop-checkout' => array(
			'title'   => __( 'Checkout', 'zeko-shop' ),
			'content' => '[zeko_shop_checkout]',
		),
		'my-orders'     => array(
			'title'   => __( 'My Orders', 'zeko-shop' ),
			'content' => '[zeko_shop_orders]',
		),
	);

	foreach ( $pages as $slug => $page ) {
		$existing = zeko_shop_get_page_by_slug( $slug );
		if ( ! $existing ) {
			$result = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => $slug,
				)
			);
			if ( is_wp_error( $result ) ) {
				error_log( 'Zeko Shop: Failed to create page "' . $slug . '": ' . $result->get_error_message() );
			} elseif ( function_exists( 'zeko_mark_plugin_page' ) ) {
					zeko_mark_plugin_page( $result, 'shop' );
			}
		}
	}
}

/**
 * Activation: create schema + pages + flush rewrites.
 */
function zeko_shop_activate() {
	require_once ZEKO_SHOP_PLUGIN_PATH . 'includes/class-zeko-shop-db.php';
	$db = new Zeko_Shop_DB();
	$db->create_tables();

	zeko_shop_create_pages();
	flush_rewrite_rules();

	update_option( 'zeko_shop_db_version', ZEKO_SHOP_DB_VERSION );
}

/**
 * Deactivation: flush rewrites.
 */
function zeko_shop_deactivate() {
	flush_rewrite_rules();
}

/**
 * Uninstall: drop tables + delete options + remove pages + menu items.
 */
function zeko_shop_uninstall() {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		return;
	}

	global $wpdb;

	// Drop all shop tables including notifications, categories, and services.
	$tables = array(
		'zeko_shop_order_items',
		'zeko_shop_order_timeline',
		'zeko_shop_orders',
		'zeko_shop_products',
		'zeko_shop_notifications',
		'zeko_shop_categories',
		'zeko_shop_services',
	);
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery
	}

	// Remove shop-created pages — ownership-verified only (marker, the module's.
	// own recorded page IDs, or a legacy page matching the shop slug whose.
	// content carries the shop shortcode). A user's unrelated page that merely.
	// shares a slug is never deleted.
	$shop_page_ids = array();
	$shop_slugs    = array( 'shop', 'cart', 'shop-checkout', 'my-orders' );
	if ( class_exists( 'Zeko_Core_Helpers' ) ) {
		$shop_page_ids = Zeko_Core_Helpers::get_instance()->get_plugin_owned_page_ids( 'shop' );
		foreach ( $shop_slugs as $slug ) {
			$recorded = (int) get_option( 'zeko_shop_' . $slug . '_page_id', 0 );
			if ( $recorded && 'page' === get_post_type( $recorded ) ) {
				$shop_page_ids[] = $recorded;
			}
			$legacy = Zeko_Core_Helpers::get_instance()->get_page_by_slug( $slug );
			if ( $legacy && false !== strpos( (string) get_post_field( 'post_content', $legacy->ID ), '[zeko_shop' ) ) {
				Zeko_Core_Helpers::mark_plugin_page( (int) $legacy->ID, 'shop' );
				$shop_page_ids[] = (int) $legacy->ID;
			}
		}
	}
	$shop_page_ids = array_values( array_unique( array_map( 'intval', array_filter( $shop_page_ids ) ) ) );

	// Remove shop nav menu items before deleting the pages they point to.
	$menu_locations = get_nav_menu_locations();
	foreach ( $menu_locations as $menu_id ) {
		$items = wp_get_nav_menu_items( (int) $menu_id );
		if ( ! $items ) {
			continue;
		}
		foreach ( $items as $item ) {
			$is_shop_page_link = 'post_type' === $item->type && 'page' === $item->object && in_array( (int) $item->object_id, $shop_page_ids, true );
			$is_shop_custom    = 'custom' === $item->type && false !== strpos( (string) $item->url, zeko_shop_page_url( 'shop' ) );
			if ( $is_shop_page_link || $is_shop_custom ) {
				wp_delete_post( (int) $item->ID, true );
			}
		}
	}

	foreach ( $shop_page_ids as $page_id ) {
		if ( function_exists( 'zeko_is_plugin_owned_page' ) && zeko_is_plugin_owned_page( $page_id, 'shop' ) ) {
			wp_delete_post( $page_id, true );
		}
	}

	// Options (including the per-year order sequence counters).
	delete_option( 'zeko_shop_db_version' );
	delete_option( 'zeko_shop_pages_created' );
	delete_option( 'zeko_shop_plugin_version' );
	delete_option( 'zeko_shop_primary_menu_done' );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'zeko_shop_order_seq_%',
			'zeko_shop_order_%'
		)
	);

	// Bridge sync transients.
	delete_transient( 'zeko_shop_program_sync' );
	delete_transient( 'zeko_shop_session_sync' );

	// Buyer data.
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'zeko_shop_cart'" );
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'zeko_shop_promo'" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", 'zeko_mentor_program_payment_%' ) );
}

register_activation_hook( __FILE__, 'zeko_shop_activate' );
register_deactivation_hook( __FILE__, 'zeko_shop_deactivate' );
register_uninstall_hook( __FILE__, 'zeko_shop_uninstall' );

/**
 * Ensure pages exist on admin_init.
 */
function zeko_shop_maybe_create_pages() {
	if ( ! get_option( 'zeko_shop_pages_created', false ) ) {
		zeko_shop_create_pages();
		update_option( 'zeko_shop_pages_created', true );
	}
}
add_action( 'admin_init', 'zeko_shop_maybe_create_pages' );

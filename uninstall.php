<?php
/**
 * Uninstall script for Zeko Shop.
 *
 * Runs when the plugin is deleted via WordPress admin.
 * Cleans up shop-owned tables, user meta, plugin options, and scheduled
 * cron events. Shared ecosystem data (including Zeko Pay wallet records
 * and Mentor program-payment meta) is kept.
 *
 * @package Zeko_Shop
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix;

// Drop shop-owned tables.
$tables = array(
	$prefix . 'zeko_shop_products',
	$prefix . 'zeko_shop_orders',
	$prefix . 'zeko_shop_order_items',
	$prefix . 'zeko_shop_notifications',
	$prefix . 'zeko_shop_order_timeline',
	$prefix . 'zeko_shop_categories',
	$prefix . 'zeko_shop_services',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete shop-owned user meta. Never a bare `zeko_%` wildcard, which would
// wipe other ecosystem modules' meta.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'DELETE FROM ' . $wpdb->usermeta . " WHERE meta_key LIKE 'zeko_shop\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// Delete plugin options.
$options = array(
	'zeko_shop_db_version',
	'zeko_shop_pages_created',
	'zeko_shop_plugin_version',
	'zeko_shop_primary_menu_done',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

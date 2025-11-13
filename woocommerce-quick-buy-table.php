<?php
/**
 * Plugin Name: WooCommerce Quick Buy Table
 * Description: Provides a quick order form that groups products by category and syncs with the WooCommerce cart.
 * Version: 1.0.0
 * Author: OpenAI Assistant
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wc-quick-buy-table
 *
 * @package WooCommerceQuickBuyTable
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WC_QBT_VERSION' ) ) {
    define( 'WC_QBT_VERSION', '1.0.0' );
}

if ( ! defined( 'WC_QBT_PLUGIN_FILE' ) ) {
    define( 'WC_QBT_PLUGIN_FILE', __FILE__ );
}

if ( ! class_exists( 'WC_Quick_Buy_Table' ) ) {
    require_once __DIR__ . '/includes/class-wc-quick-buy-table.php';
}

WC_Quick_Buy_Table::instance();

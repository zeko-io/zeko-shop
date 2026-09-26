=== Zeko Shop ===
Contributors: zeko
Tags: ecommerce, shop, downloads, checkout, orders
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Digital shop for the Zeko ecosystem powered by Zeko Pay — product catalog, cart, wallet checkout, orders, invoices, receipts, downloads, and refunds, with a built-in Mentor bridge.

== Description ==

Zeko Shop is a lightweight digital shop for the Zeko ecosystem, with checkout handled entirely by Zeko Pay. Sell digital products, courses, and mentor services from one catalog with a wallet-based checkout.

= Features =

* **Product Catalog** — Digital goods with images, pricing, and inventory tracking
* **Cart** — Full cart, mini-cart, and badge count widgets
* **Wallet Checkout** — Checkout charged through Zeko Pay with promo codes and tax calculation
* **Orders** — Order management with pending/completed/failed states and refunds
* **Downloads** — Rate-limited digital download delivery; downloads blocked for refunded orders
* **Invoices & Receipts** — Auto-generated on completed orders
* **Refunds** — Full-reversal refund policy including fees
* **Mentor Bridge** — Paid mentor sessions and programs buyable via shop checkout, with two-way sync (order → enrollment/session; refund → unenroll/cancel)
* **Notifications** — In-app order notifications
* **Idempotent Checkout** — MySQL-locked checkout prevents double-charging

= Shortcodes =

* `[zeko_shop]` — Product catalog
* `[zeko_shop_cart]` — Full cart page
* `[zeko_shop_cart_badge]` — Cart badge with count
* `[zeko_shop_cart_count]` — Cart item count
* `[zeko_shop_checkout]` — Checkout page
* `[zeko_shop_mini_cart]` — Mini-cart widget
* `[zeko_shop_orders]` — Order history and downloads

== Installation ==

1. Upload the `zeko-shop` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Activate Zeko Pay — it is required for checkout, orders, and downloads
4. Create pages and add the shortcodes above

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Zeko Shop is a self-contained digital shop for the Zeko ecosystem, charging through Zeko Pay's wallet and ledger. No third-party e-commerce plugin is required.

= What happens when an order is refunded? =

Refunds fully reverse the charge including fees, block the download, and — for mentor-linked products — unenroll programs and cancel linked paid sessions so no payout is left behind.

== Dependencies ==

Zeko Pay is required for checkout, orders, and downloads (the plugin declares `Requires Plugins: zeko-pay`). Zeko Mentor is optional and powers the paid session/program bridge.

== Privacy ==

Data stored includes cart and order records (products, quantities, amounts, order status), invoices and receipts, and download history for your own orders. Payment data itself is held by Zeko Pay, not by Zeko Shop. The plugin's privacy-surface data is created only when a visitor places an order.

== External Services ==

Zeko Shop makes no outbound requests of its own. All payment/channel requests go through Zeko Pay's configured gateways; video or product media are served from your own site/media library.

== Uninstall ==

Deleting the plugin drops Zeko Shop tables (orders, order items, timeline, products, categories, services, notifications), removes only the pages/nav items it created, and deletes its options.

== Troubleshooting ==

* Checkout fails? Verify Zeko Pay is active and the wallet charge succeeded before re-attempting — checkout is locked per order to prevent double-charge.
* Download blocked? Downloads are rate-limited and blocked for refunded or unpaid orders.
* Mentor product sync not working? Re-save the product; the bridge syncs on order completion and refund.

== Screenshots ==

1. Shop dashboard with products and orders


== Changelog ==

= 1.5.0 =
* Module badges on product cards and detail pages (filterable via `zeko_shop_product_module`)
* Mentor product management — active mentors manage their own storefront via a "My Products" dashboard tab (ownership enforced)
* Product detail pages shipped in 1.4.0 fully verified

= 1.4.0 =
* Product detail pages — catalog cards now link to `/shop/product/{id}/` with full description, badges, stock, and add-to-cart
* Breadcrumbs, responsive product grid, 404 handling for missing/inactive products

= 1.3.1 =
* Uninstall cleanup for notifications, options, transients, pages, and nav items
* Refund-to-mentor bridge (unenroll/cancel) on order refunds
* Mixed-currency carts rejected at checkout
* Order row created before wallet charge to prevent orphaned debits
* Checkout idempotency via MySQL lock

= 1.0.0 =
* Initial release
* Product catalog, cart, wallet checkout, orders, downloads
* Invoices, receipts, refunds, tax, promo codes
* Mentor bridge for sessions and programs

== Upgrade Notice ==

= 1.5.0 =
Adds module badges and mentor storefront management.

= 1.3.1 =
Money-flow and cleanup fixes. Please upgrade as soon as possible.

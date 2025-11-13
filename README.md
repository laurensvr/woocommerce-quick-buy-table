# WooCommerce Quick Buy Table

A WordPress plugin that turns a WooCommerce page into a user-friendly quick order form. The table is designed for wholesale customers that need to reorder products quickly based on their wishlist and negotiated price lists.

## Features

- Redirects guests to the WooCommerce "My account" login page and returns them to the order form after authentication.
- Builds a product matrix from the `wishlist_ianenwijn` user meta and optional Advanced Custom Fields (ACF) price list (`prijslijst` &rarr; `prijzen`).
- Groups products by category with modern, responsive styling.
- Keeps quantities in sync with the WooCommerce cart and shows live subtotals as quantities change.
- Enforces ordering rules: products priced under &euro;20 are ordered in steps of six, higher priced items per piece.
- Provides a one-click path to the WooCommerce checkout when the form is submitted.

## Usage

1. Copy the plugin folder into your WordPress installation under `wp-content/plugins/`.
2. Activate the **WooCommerce Quick Buy Table** plugin from the WordPress admin dashboard.
3. Add the `[wc_quick_buy_table]` shortcode to the page you would like to use as the quick order form (for example, `/bestellijst/`).
4. Ensure the wishlist meta (`wishlist_ianenwijn`) and optional price list fields are populated for your users. Only products with a valid SKU/ID will appear.
5. Visit the page while logged in to view and use the quick order experience.

Logged-out visitors will be redirected to the WooCommerce account login and returned to the requested page after signing in.

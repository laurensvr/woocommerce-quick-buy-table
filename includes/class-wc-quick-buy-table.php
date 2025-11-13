<?php
/**
 * Quick buy table core class.
 *
 * @package WooCommerceQuickBuyTable
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Quick_Buy_Table {
    /**
     * Singleton instance.
     *
     * @var WC_Quick_Buy_Table|null
     */
    protected static $instance = null;

    /**
     * Shortcode tag name.
     *
     * @var string
     */
    protected $shortcode = 'wc_quick_buy_table';

    /**
     * Whether assets were enqueued for the current request.
     *
     * @var bool
     */
    protected $assets_enqueued = false;

    /**
     * Retrieve the singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    protected function __construct() {
        add_action( 'init', [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'template_redirect', [ $this, 'maybe_process_form_submission' ] );
    }

    /**
     * Register the shortcode.
     */
    public function register_shortcode() {
        add_shortcode( $this->shortcode, [ $this, 'render_shortcode' ] );
    }

    /**
     * Register plugin assets.
     */
    public function register_assets() {
        wp_register_style(
            'wc-qbt-styles',
            plugins_url( 'assets/css/quick-buy-table.css', WC_QBT_PLUGIN_FILE ),
            [],
            WC_QBT_VERSION
        );

        wp_register_script(
            'wc-qbt-script',
            plugins_url( 'assets/js/quick-buy-table.js', WC_QBT_PLUGIN_FILE ),
            [ 'jquery' ],
            WC_QBT_VERSION,
            true
        );

        $currency_args = [
            'currency_symbol'   => get_woocommerce_currency_symbol(),
            'price_format'      => get_woocommerce_price_format(),
            'decimal_separator' => wc_get_price_decimal_separator(),
            'thousand_separator'=> wc_get_price_thousand_separator(),
            'decimals'          => wc_get_price_decimals(),
            'summaryQuantityLabel' => __( 'Totaal aantal producten', 'wc-quick-buy-table' ),
            'summaryAmountLabel'   => __( 'Totale waarde', 'wc-quick-buy-table' ),
        ];

        wp_localize_script( 'wc-qbt-script', 'WCQBT', $currency_args );
    }

    /**
     * Ensure assets are loaded when needed.
     */
    protected function enqueue_assets() {
        if ( $this->assets_enqueued ) {
            return;
        }

        wp_enqueue_style( 'wc-qbt-styles' );
        wp_enqueue_script( 'wc-qbt-script' );
        $this->assets_enqueued = true;
    }

    /**
     * Process form submission when necessary.
     */
    public function maybe_process_form_submission() {
        if ( empty( $_POST['wc_qbt_action'] ) || 'update_cart' !== $_POST['wc_qbt_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! is_user_logged_in() ) {
            $this->redirect_to_login();
        }

        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }

        check_admin_referer( 'wc_qbt_update_cart', 'wc_qbt_nonce' );

        $quantities = isset( $_POST['quantities'] ) ? (array) wp_unslash( $_POST['quantities'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $quantities = array_map( 'wc_clean', $quantities );

        foreach ( $quantities as $product_id => $quantity ) {
            $product_id = absint( $product_id );

            if ( ! $product_id ) {
                continue;
            }

            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $quantity = $this->normalize_quantity_for_product( $product, $quantity );
            $this->update_cart_quantity( $product, $quantity );
        }

        wc_add_notice( __( 'Je bestellijst is bijgewerkt. Controleer je bestelling en rond afrekenen af.', 'wc-quick-buy-table' ) );

        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    /**
     * Normalize quantity to respect step requirements.
     *
     * @param WC_Product $product  Product object.
     * @param mixed      $quantity Requested quantity.
     *
     * @return int
     */
    protected function normalize_quantity_for_product( $product, $quantity ) {
        $quantity = max( 0, (int) $quantity );
        $step     = $this->get_quantity_step_for_product( $product );

        if ( $quantity > 0 && $step > 1 ) {
            $quantity = max( $step, (int) ceil( $quantity / $step ) * $step );
        }

        return $quantity;
    }

    /**
     * Update cart quantity for a product.
     *
     * @param WC_Product $product  Product object.
     * @param int        $quantity Quantity.
     */
    protected function update_cart_quantity( $product, $quantity ) {
        $cart = WC()->cart;

        if ( ! $cart ) {
            return;
        }

        $product_id   = $product->get_id();
        $variation_id = 0;
        $attributes   = [];

        if ( $product->is_type( 'variation' ) ) {
            $variation_id = $product_id;
            $product_id   = $product->get_parent_id();
            $attributes   = $product->get_variation_attributes();
        }

        $cart_item_key = $this->find_cart_item_key( $product, $cart );

        if ( $cart_item_key ) {
            if ( $quantity > 0 ) {
                $cart->set_quantity( $cart_item_key, $quantity, true );
            } else {
                $cart->remove_cart_item( $cart_item_key );
            }
            return;
        }

        if ( $quantity > 0 ) {
            $cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );
        }
    }

    /**
     * Locate the cart item key for a product or variation.
     *
     * @param WC_Product $product Product instance.
     * @param WC_Cart    $cart    Cart instance.
     *
     * @return string|null
     */
    protected function find_cart_item_key( $product, $cart ) {
        $target_id = $product->is_type( 'variation' ) ? $product->get_id() : $product->get_id();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $cart_product_id = empty( $cart_item['variation_id'] ) ? (int) $cart_item['product_id'] : (int) $cart_item['variation_id'];

            if ( $cart_product_id === $target_id ) {
                return $cart_item_key;
            }
        }

        return null;
    }

    /**
     * Render shortcode output.
     *
     * @return string
     */
    public function render_shortcode() {
        if ( ! is_user_logged_in() ) {
            $this->redirect_to_login();
            return '';
        }

        if ( ! function_exists( 'WC' ) ) {
            return '';
        }

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }

        $products = $this->get_products_for_current_user();

        if ( empty( $products ) ) {
            return $this->get_no_products_markup();
        }

        $this->enqueue_assets();

        $cart_quantities = $this->get_cart_quantities();
        $grouped         = $this->group_products_by_category( $products );

        ob_start();
        ?>
        <form class="wc-qbt-form" method="post">
            <?php wp_nonce_field( 'wc_qbt_update_cart', 'wc_qbt_nonce' ); ?>
            <input type="hidden" name="wc_qbt_action" value="update_cart" />
            <?php foreach ( $grouped as $group ) : ?>
                <section class="wc-qbt-category">
                    <header class="wc-qbt-category__header">
                        <h2><?php echo esc_html( $group['label'] ); ?></h2>
                    </header>
                    <div class="wc-qbt-category__table" role="table">
                        <div class="wc-qbt-table__row wc-qbt-table__row--head" role="row">
                            <div class="wc-qbt-table__cell" role="columnheader"><?php esc_html_e( 'Product', 'wc-quick-buy-table' ); ?></div>
                            <div class="wc-qbt-table__cell" role="columnheader"><?php esc_html_e( 'Prijs', 'wc-quick-buy-table' ); ?></div>
                            <div class="wc-qbt-table__cell" role="columnheader"><?php esc_html_e( 'Aantal', 'wc-quick-buy-table' ); ?></div>
                            <div class="wc-qbt-table__cell" role="columnheader"><?php esc_html_e( 'Subtotaal', 'wc-quick-buy-table' ); ?></div>
                        </div>
                        <?php foreach ( $group['products'] as $product_data ) :
                            $product       = $product_data['product'];
                            $display       = $product_data['display'];
                            $product_id    = $product->get_id();
                            $price         = $product_data['price'];
                            $price_display = $product_data['price_display'];
                            $step          = $product_data['step'];
                            $sku           = $product->get_sku();
                            $cart_qty      = isset( $cart_quantities[ $product_id ] ) ? $cart_quantities[ $product_id ] : 0;
                            $min           = $step > 1 ? 0 : 0;
                            ?>
                            <div class="wc-qbt-table__row" role="row" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-price="<?php echo esc_attr( $price ); ?>">
                                <div class="wc-qbt-table__cell wc-qbt-table__cell--product" role="cell">
                                    <div class="wc-qbt-product">
                                        <div class="wc-qbt-product__thumbnail">
                                            <?php echo $display->get_image( 'full' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </div>
                                        <div class="wc-qbt-product__meta">
                                            <span class="wc-qbt-product__name"><?php echo esc_html( $display->get_name() ); ?></span>
                                            <?php if ( $product->is_type( 'variation' ) ) : ?>
                                                <span class="wc-qbt-product__variation"><?php echo wp_kses_post( wc_get_formatted_variation( $product, true, false, true ) ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $sku ) : ?>
                                                <span class="wc-qbt-product__sku"><?php printf( esc_html__( 'SKU: %s', 'wc-quick-buy-table' ), esc_html( $sku ) ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="wc-qbt-table__cell" role="cell">
                                    <span class="wc-qbt-price" data-price-display="<?php echo esc_attr( $price_display ); ?>"><?php echo wp_kses_post( $price_display ); ?></span>
                                </div>
                                <div class="wc-qbt-table__cell" role="cell">
                                    <div class="wc-qbt-quantity" data-step="<?php echo esc_attr( $step ); ?>">
                                        <button type="button" class="wc-qbt-quantity__button wc-qbt-quantity__button--minus" aria-label="<?php esc_attr_e( 'Minder', 'wc-quick-buy-table' ); ?>">&minus;</button>
                                        <input type="number" inputmode="numeric" min="<?php echo esc_attr( $min ); ?>" step="<?php echo esc_attr( $step ); ?>" name="quantities[<?php echo esc_attr( $product_id ); ?>]" value="<?php echo esc_attr( $cart_qty ); ?>" />
                                        <button type="button" class="wc-qbt-quantity__button wc-qbt-quantity__button--plus" aria-label="<?php esc_attr_e( 'Meer', 'wc-quick-buy-table' ); ?>">+</button>
                                    </div>
                                </div>
                                <div class="wc-qbt-table__cell" role="cell">
                                    <span class="wc-qbt-subtotal" data-price="<?php echo esc_attr( $price ); ?>"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            <footer class="wc-qbt-summary" aria-live="polite">
                <div class="wc-qbt-summary__totals">
                    <span class="wc-qbt-summary__quantity"></span>
                    <span class="wc-qbt-summary__amount"></span>
                </div>
                <button type="submit" class="button button-primary wc-qbt-submit"><?php esc_html_e( 'Naar afrekenen', 'wc-quick-buy-table' ); ?></button>
            </footer>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Redirect users to login page with redirect back to current URL.
     */
    protected function redirect_to_login() {
        $current_url = home_url( '/' );

        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $current_url = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }

        if ( function_exists( 'wc_get_page_permalink' ) ) {
            $login_url = wc_get_page_permalink( 'myaccount' );
        } else {
            $login_url = wp_login_url();
        }

        $login_url = add_query_arg( 'redirect_to', rawurlencode( $current_url ), $login_url );

        wp_safe_redirect( $login_url );
        exit;
    }

    /**
     * Retrieve products available to the current user.
     *
     * @return WC_Product[]
     */
    protected function get_products_for_current_user() {
        $product_ids = $this->get_product_ids_from_current_user();
        $products    = [];

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );

            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }

            $products[ $product->get_id() ] = $product;
        }

        return $products;
    }

    /**
     * Collect product IDs based on wishlist and price list meta.
     *
     * @return int[]
     */
    protected function get_product_ids_from_current_user() {
        $user_id     = get_current_user_id();
        $product_ids = [];

        $wishlist_meta = get_user_meta( $user_id, 'wishlist_ianenwijn', true );

        if ( $wishlist_meta ) {
            foreach ( array_filter( array_map( 'absint', array_map( 'trim', explode( ',', (string) $wishlist_meta ) ) ) ) as $product_id ) {
                if ( $product_id ) {
                    $product_ids[] = $product_id;
                }
            }
        }

        if ( function_exists( 'get_field' ) ) {
            $pricelist = get_field( 'prijslijst', 'user_' . $user_id );

            if ( ! empty( $pricelist ) ) {
                foreach ( (array) $pricelist as $item ) {
                    $prices = get_field( 'prijzen', $item->ID );

                    if ( is_array( $prices ) ) {
                        foreach ( array_keys( $prices ) as $sku ) {
                            $product_id = wc_get_product_id_by_sku( $sku );

                            if ( $product_id ) {
                                $product_ids[] = $product_id;
                            }
                        }
                    }
                }
            }
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
    }

    /**
     * Group products by their primary category.
     *
     * @param WC_Product[] $products Product instances.
     *
     * @return array
     */
    protected function group_products_by_category( $products ) {
        $groups = [];

        foreach ( $products as $product ) {
            $display_product = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

            if ( ! $display_product ) {
                $display_product = $product;
            }

            $terms = get_the_terms( $display_product->get_id(), 'product_cat' );

            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                $term  = (object) [ 'term_id' => 0, 'name' => __( 'Overige producten', 'wc-quick-buy-table' ) ];
                $terms = [ $term ];
            }

            $term = $this->get_primary_term( $terms );

            if ( ! isset( $groups[ $term->term_id ] ) ) {
                $groups[ $term->term_id ] = [
                    'label'    => $term->name,
                    'products' => [],
                ];
            }

            $groups[ $term->term_id ]['products'][] = [
                'product'       => $product,
                'display'       => $display_product,
                'price'         => (float) wc_get_price_to_display( $product ),
                'price_display' => wc_price( wc_get_price_to_display( $product ) ),
                'step'          => $this->get_quantity_step_for_product( $product ),
            ];
        }

        foreach ( $groups as &$group ) {
            usort(
                $group['products'],
                static function ( $a, $b ) {
                    return strcasecmp( $a['display']->get_name(), $b['display']->get_name() );
                }
            );
        }
        unset( $group );

        uasort(
            $groups,
            static function ( $a, $b ) {
                return strcasecmp( $a['label'], $b['label'] );
            }
        );

        return $groups;
    }

    /**
     * Determine primary term.
     *
     * @param array $terms Term list.
     *
     * @return WP_Term
     */
    protected function get_primary_term( $terms ) {
        $term = reset( $terms );

        foreach ( $terms as $candidate ) {
            if ( isset( $candidate->term_group ) && (int) $candidate->term_group === 0 ) {
                $term = $candidate;
                break;
            }
        }

        return $term;
    }

    /**
     * Get cart quantities keyed by product id (variation-aware).
     *
     * @return array
     */
    protected function get_cart_quantities() {
        $quantities = [];

        if ( ! WC()->cart ) {
            return $quantities;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            $key = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];

            if ( ! isset( $quantities[ $key ] ) ) {
                $quantities[ $key ] = 0;
            }

            $quantities[ $key ] += (int) $item['quantity'];
        }

        return $quantities;
    }

    /**
     * Get quantity step based on price.
     *
     * @param WC_Product $product Product instance.
     *
     * @return int
     */
    protected function get_quantity_step_for_product( $product ) {
        $price = (float) wc_get_price_to_display( $product );

        return $price < 20 ? 6 : 1;
    }

    /**
     * Friendly message when no products exist.
     *
     * @return string
     */
    protected function get_no_products_markup() {
        ob_start();
        ?>
        <main id="content">
            <div class="page-content">
                <div class="main-container">
                    <p><?php esc_html_e( 'Er zijn momenteel geen producten beschikbaar in je bestellijst.', 'wc-quick-buy-table' ); ?></p>
                    <p><?php esc_html_e( 'Je kunt zelf producten toevoegen via de productpagina.', 'wc-quick-buy-table' ); ?></p>
                    <p><?php esc_html_e( 'Heb je prijsafspraken? Neem contact op:', 'wc-quick-buy-table' ); ?></p>
                    <p><a href="mailto:info@ianenwijn.nl" class="button">info@ianenwijn.nl</a></p>
                </div>
            </div>
        </main>
        <?php
        return ob_get_clean();
    }
}

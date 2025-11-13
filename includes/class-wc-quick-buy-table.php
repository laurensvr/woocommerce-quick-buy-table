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
        add_action( 'after_setup_theme', [ $this, 'register_image_sizes' ] );
        add_action( 'init', [ $this, 'register_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
        add_action( 'template_redirect', [ $this, 'maybe_process_form_submission' ] );
    }

    /**
     * Register custom image sizes.
     */
    public function register_image_sizes() {
        add_image_size( 'quick-order-thumbnail', 256, 256, false );
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
            'currency_symbol'        => get_woocommerce_currency_symbol(),
            'price_format'           => get_woocommerce_price_format(),
            'decimal_separator'      => wc_get_price_decimal_separator(),
            'thousand_separator'     => wc_get_price_thousand_separator(),
            'decimals'               => wc_get_price_decimals(),
            'summaryQuantityLabel'   => __( 'Totaal aantal producten', 'wc-quick-buy-table' ),
            'summaryAmountLabel'     => __( 'Totale waarde', 'wc-quick-buy-table' ),
            'summaryEmptyText'       => __( 'Nog geen producten geselecteerd.', 'wc-quick-buy-table' ),
            'summaryToggleOpen'      => __( 'Bekijk bestelling', 'wc-quick-buy-table' ),
            'summaryToggleClose'     => __( 'Sluit bestelling', 'wc-quick-buy-table' ),
            'summaryShortLabel'      => __( 'Artikelen', 'wc-quick-buy-table' ),
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

        $initial_state_encoded = isset( $_POST['wc_qbt_cart_state'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_qbt_cart_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $initial_state_hash    = isset( $_POST['wc_qbt_cart_state_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_qbt_cart_state_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $initial_state         = $this->decode_cart_state( $initial_state_encoded, $initial_state_hash );
        $current_state         = $this->get_cart_snapshot_data();

        if ( ! $this->cart_states_match( $initial_state, $current_state ) ) {
            wc_add_notice( __( 'Je winkelwagen is gewijzigd sinds het openen van de bestellijst. We hebben je bestelling bijgewerkt zodat je alle producten opnieuw kunt controleren.', 'wc-quick-buy-table' ), 'notice' );

            wp_safe_redirect( $this->get_current_form_url() );
            exit;
        }

        $quantities            = isset( $_POST['quantities'] ) ? (array) wp_unslash( $_POST['quantities'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $quantities            = array_map( 'wc_clean', $quantities );
        $bestellijst_product_ids = [];

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

            if ( $quantity > 0 ) {
                $bestellijst_product_ids[] = $product->get_id();
            }
        }

        $this->add_products_to_bestellijst( $bestellijst_product_ids );

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

        $products        = $this->get_products_for_current_user();
        $cart_quantities = $this->get_cart_quantities();
        $cart_state_data = $this->get_cart_snapshot_data();
        $cart_state      = $this->encode_cart_state( $cart_state_data );
        $cart_state_hash = $this->hash_cart_state( $cart_state );
        $cart_only_products = $this->get_cart_products_not_in_list( array_keys( $products ), $cart_quantities );

        if ( ! empty( $cart_only_products ) ) {
            $products = $products + $cart_only_products;
        }

        if ( empty( $products ) ) {
            return $this->get_no_products_markup();
        }

        $this->enqueue_assets();

        $grouped         = $this->group_products_by_category( $products, $cart_quantities );
        $cart_label      = __( 'Producten al in je winkelwagen', 'wc-quick-buy-table' );
        $cart_note       = __( 'Deze producten staan al in je winkelwagen (ook als ze niet in je bestellijst staan) en worden bovenaan getoond.', 'wc-quick-buy-table' );
        $empty_text      = __( 'Nog geen producten geselecteerd.', 'wc-quick-buy-table' );
        $summary_title   = __( 'Bestellingsoverzicht', 'wc-quick-buy-table' );
        $toggle_open     = __( 'Bekijk bestelling', 'wc-quick-buy-table' );
        $toggle_close    = __( 'Sluit bestelling', 'wc-quick-buy-table' );
        $total_label     = __( 'Totaal', 'wc-quick-buy-table' );

        $ordered_groups = $this->elevate_cart_products_group( $grouped, $cart_label, $cart_note );

        ob_start();
        ?>
        <?php if ( function_exists( 'wc_print_notices' ) ) { wc_print_notices(); } ?>
        <form class="wc-qbt-form" method="post">
            <?php wp_nonce_field( 'wc_qbt_update_cart', 'wc_qbt_nonce' ); ?>
            <input type="hidden" name="wc_qbt_action" value="update_cart" />
            <input type="hidden" name="wc_qbt_cart_state" value="<?php echo esc_attr( $cart_state ); ?>" />
            <input type="hidden" name="wc_qbt_cart_state_hash" value="<?php echo esc_attr( $cart_state_hash ); ?>" />
            <div class="wc-qbt-layout">
                <div class="wc-qbt-layout__products">
                    <?php foreach ( $ordered_groups as $group ) :
                        if ( empty( $group['products'] ) ) {
                            continue;
                        }
                        ?>
                        <?php
                        $section_classes = 'wc-qbt-category';

                        if ( ! empty( $group['is_cart'] ) ) {
                            $section_classes .= ' wc-qbt-category--cart';
                        }
                        ?>
                        <section class="<?php echo esc_attr( $section_classes ); ?>">
                            <header class="wc-qbt-category__header">
                                <h2><?php echo esc_html( $group['label'] ); ?></h2>
                                <?php if ( ! empty( $group['note'] ) ) : ?>
                                    <p class="wc-qbt-category__note"><?php echo esc_html( $group['note'] ); ?></p>
                                <?php endif; ?>
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
                                    $cart_qty      = isset( $product_data['cart_quantity'] ) ? (int) $product_data['cart_quantity'] : 0;
                                    $min           = $step > 1 ? 0 : 0;
                                    $product_name  = wp_strip_all_tags( $display->get_name() );
                                    ?>
                                    <div class="wc-qbt-table__row" role="row" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-price="<?php echo esc_attr( $price ); ?>" data-product-name="<?php echo esc_attr( $product_name ); ?>">
                                        <div class="wc-qbt-table__cell wc-qbt-table__cell--product" role="cell">
                                            <div class="wc-qbt-product">
                                              <div class="wc-qbt-product__thumbnail">
                                              <?php echo $display->get_image( 'quick-order-thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
                </div>
                <aside class="wc-qbt-layout__summary">
                    <div class="wc-qbt-summary" aria-live="polite">
                        <h2 class="wc-qbt-summary__title"><?php echo esc_html( $summary_title ); ?></h2>
                        <ul class="wc-qbt-summary__items" data-empty-text="<?php echo esc_attr( $empty_text ); ?>"></ul>
                        <div class="wc-qbt-summary__totals">
                            <span class="wc-qbt-summary__quantity"></span>
                            <span class="wc-qbt-summary__amount"></span>
                        </div>
                        <button type="submit" class="button button-primary wc-qbt-submit"><?php esc_html_e( 'Naar afrekenen', 'wc-quick-buy-table' ); ?></button>
                    </div>
                </aside>
            </div>
            <?php
            $floating_panel_id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'wc-qbt-floating-summary-panel-' ) : uniqid( 'wc-qbt-floating-summary-panel-', false );
            ?>
            <div class="wc-qbt-floating-summary" data-empty-text="<?php echo esc_attr( $empty_text ); ?>">
                <div class="wc-qbt-floating-summary__backdrop" aria-hidden="true"></div>
                <button type="button" class="wc-qbt-floating-summary__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $floating_panel_id ); ?>" data-open-label="<?php echo esc_attr( $toggle_open ); ?>" data-close-label="<?php echo esc_attr( $toggle_close ); ?>">
                    <span class="wc-qbt-floating-summary__label"><?php echo esc_html( $total_label ); ?></span>
                    <span class="wc-qbt-floating-summary__short-amount"></span>
                    <span class="wc-qbt-floating-summary__short-quantity"></span>
                    <span class="wc-qbt-floating-summary__toggle-text"><?php echo esc_html( $toggle_open ); ?></span>
                    <span class="wc-qbt-floating-summary__caret" aria-hidden="true"></span>
                </button>
                <div id="<?php echo esc_attr( $floating_panel_id ); ?>" class="wc-qbt-floating-summary__panel" hidden aria-hidden="true">
                    <ul class="wc-qbt-floating-summary__items" data-empty-text="<?php echo esc_attr( $empty_text ); ?>"></ul>
                    <div class="wc-qbt-floating-summary__totals">
                        <span class="wc-qbt-floating-summary__quantity"></span>
                        <span class="wc-qbt-floating-summary__amount"></span>
                    </div>
                    <button type="submit" class="button button-primary wc-qbt-submit wc-qbt-submit--floating"><?php esc_html_e( 'Naar afrekenen', 'wc-quick-buy-table' ); ?></button>
                </div>
            </div>
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
     * Ensure that provided products are stored in the user's bestellijst.
     *
     * @param int[] $product_ids Product IDs to store.
     */
    protected function add_products_to_bestellijst( $product_ids ) {
        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return;
        }

        $product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );

        if ( empty( $product_ids ) ) {
            return;
        }

        $existing_raw = get_user_meta( $user_id, 'wishlist_ianenwijn', true );
        $existing_ids = [];

        if ( $existing_raw ) {
            $existing_ids = array_filter(
                array_map(
                    'absint',
                    array_map( 'trim', explode( ',', (string) $existing_raw ) )
                )
            );
        }

        $merged_ids = $existing_ids;

        foreach ( $product_ids as $product_id ) {
            if ( ! in_array( $product_id, $merged_ids, true ) ) {
                $merged_ids[] = $product_id;
            }
        }

        $updated_value = implode( ',', $merged_ids );

        if ( $updated_value !== (string) $existing_raw ) {
            update_user_meta( $user_id, 'wishlist_ianenwijn', $updated_value );
        }
    }

    /**
     * Group products by their primary category.
     *
     * @param WC_Product[] $products        Product instances.
     * @param array        $cart_quantities Quantities indexed by product/variation id.
     *
     * @return array
     */
    protected function group_products_by_category( $products, $cart_quantities = [] ) {
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
                    'note'     => '',
                    'is_cart'  => false,
                ];
            }

            $groups[ $term->term_id ]['products'][] = [
                'product'       => $product,
                'display'       => $display_product,
                'price'         => (float) wc_get_price_to_display( $product ),
                'price_display' => wc_price( wc_get_price_to_display( $product ) ),
                'step'          => $this->get_quantity_step_for_product( $product ),
                'in_cart'       => $this->product_is_in_cart( $product, $cart_quantities ),
                'cart_quantity' => $this->get_cart_quantity_for_product( $product, $cart_quantities ),
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
     * Create a normalized snapshot of the current cart contents.
     *
     * @return array
     */
    protected function get_cart_snapshot_data() {
        $snapshot = [];

        if ( ! WC()->cart ) {
            return $snapshot;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            $product_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];

            if ( $product_id <= 0 ) {
                continue;
            }

            if ( ! isset( $snapshot[ $product_id ] ) ) {
                $snapshot[ $product_id ] = 0;
            }

            $snapshot[ $product_id ] += (int) $item['quantity'];
        }

        ksort( $snapshot );

        return $snapshot;
    }

    /**
     * Encode cart state for transport.
     *
     * @param array $state Cart snapshot.
     *
     * @return string
     */
    protected function encode_cart_state( $state ) {
        if ( ! is_array( $state ) ) {
            $state = [];
        }

        $normalized = [];

        foreach ( $state as $product_id => $quantity ) {
            $product_id = (int) $product_id;

            if ( $product_id <= 0 ) {
                continue;
            }

            $normalized[ $product_id ] = max( 0, (int) $quantity );
        }

        ksort( $normalized );

        return base64_encode( wp_json_encode( $normalized ) );
    }

    /**
     * Generate a hash for the encoded cart state to prevent tampering.
     *
     * @param string $encoded_state Encoded state string.
     *
     * @return string
     */
    protected function hash_cart_state( $encoded_state ) {
        return wp_hash( (string) $encoded_state . '|' . get_current_user_id() );
    }

    /**
     * Decode the posted cart state once verified.
     *
     * @param string $encoded_state Encoded state string.
     * @param string $hash          Hash included in the request.
     *
     * @return array
     */
    protected function decode_cart_state( $encoded_state, $hash ) {
        if ( empty( $encoded_state ) || empty( $hash ) ) {
            return [];
        }

        $expected = $this->hash_cart_state( $encoded_state );

        if ( ! hash_equals( $expected, (string) $hash ) ) {
            return [];
        }

        $decoded = base64_decode( $encoded_state, true );

        if ( false === $decoded ) {
            return [];
        }

        $data = json_decode( $decoded, true );

        if ( ! is_array( $data ) ) {
            return [];
        }

        $normalized = [];

        foreach ( $data as $product_id => $quantity ) {
            $product_id = (int) $product_id;

            if ( $product_id <= 0 ) {
                continue;
            }

            $normalized[ $product_id ] = max( 0, (int) $quantity );
        }

        ksort( $normalized );

        return $normalized;
    }

    /**
     * Determine whether two cart states match.
     *
     * @param array $initial_state Snapshot from initial render.
     * @param array $current_state Snapshot at submission time.
     *
     * @return bool
     */
    protected function cart_states_match( $initial_state, $current_state ) {
        if ( ! is_array( $initial_state ) ) {
            $initial_state = [];
        }

        if ( ! is_array( $current_state ) ) {
            $current_state = [];
        }

        return $initial_state === $current_state;
    }

    /**
     * Determine the best URL to return the shopper to when the cart changed mid-flow.
     *
     * @return string
     */
    protected function get_current_form_url() {
        if ( isset( $_POST['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $referer_field = trim( wp_unslash( $_POST['_wp_http_referer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( $referer_field ) {
                $validated = wp_validate_redirect( $referer_field, false );

                if ( $validated ) {
                    return $validated;
                }

                if ( '/' === substr( $referer_field, 0, 1 ) ) {
                    return home_url( $referer_field );
                }
            }
        }

        $referer = wp_get_referer();

        if ( $referer ) {
            return $referer;
        }

        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            return home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }

        return home_url( '/' );
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
     * Determine if the product is already present in the cart.
     *
     * @param WC_Product $product         Product instance.
     * @param array      $cart_quantities Quantities indexed by product/variation id.
     *
     * @return bool
     */
    protected function product_is_in_cart( $product, $cart_quantities ) {
        $product_id = (int) $product->get_id();

        if ( isset( $cart_quantities[ $product_id ] ) && $cart_quantities[ $product_id ] > 0 ) {
            return true;
        }

        if ( $product->is_type( 'variable' ) ) {
            foreach ( (array) $product->get_children() as $child_id ) {
                $child_id = (int) $child_id;

                if ( isset( $cart_quantities[ $child_id ] ) && $cart_quantities[ $child_id ] > 0 ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Retrieve the quantity that is currently in the cart for the product.
     *
     * @param WC_Product $product         Product instance.
     * @param array      $cart_quantities Quantities indexed by product/variation id.
     *
     * @return int
     */
    protected function get_cart_quantity_for_product( $product, $cart_quantities ) {
        $product_id = (int) $product->get_id();

        if ( isset( $cart_quantities[ $product_id ] ) ) {
            return (int) $cart_quantities[ $product_id ];
        }

        if ( $product->is_type( 'variable' ) ) {
            $quantity = 0;

            foreach ( (array) $product->get_children() as $child_id ) {
                $child_id = (int) $child_id;

                if ( isset( $cart_quantities[ $child_id ] ) ) {
                    $quantity += (int) $cart_quantities[ $child_id ];
                }
            }

            return $quantity;
        }

        return 0;
    }

    /**
     * Move any cart products to the top of the listing.
     *
     * @param array  $groups     Grouped products.
     * @param string $label      Cart group label.
     * @param string $note       Cart group note.
     *
     * @return array
     */
    protected function elevate_cart_products_group( $groups, $label, $note ) {
        $cart_products = [];

        foreach ( $groups as $group_id => &$group ) {
            if ( empty( $group['products'] ) ) {
                continue;
            }

            $remaining = [];

            foreach ( $group['products'] as $product_data ) {
                if ( ! empty( $product_data['in_cart'] ) ) {
                    $cart_products[] = $product_data;
                } else {
                    $remaining[] = $product_data;
                }
            }

            $group['products'] = $remaining;
        }
        unset( $group );

        $ordered_groups = [];

        if ( ! empty( $cart_products ) ) {
            usort(
                $cart_products,
                static function ( $a, $b ) {
                    return strcasecmp( $a['display']->get_name(), $b['display']->get_name() );
                }
            );

            $ordered_groups[] = [
                'label'    => $label,
                'note'     => $note,
                'products' => $cart_products,
                'is_cart'  => true,
            ];
        }

        foreach ( $groups as $group ) {
            if ( empty( $group['products'] ) ) {
                continue;
            }

            if ( ! isset( $group['note'] ) ) {
                $group['note'] = '';
            }

            if ( ! isset( $group['is_cart'] ) ) {
                $group['is_cart'] = false;
            }

            $ordered_groups[] = $group;
        }

        if ( empty( $ordered_groups ) ) {
            return $groups;
        }

        return $ordered_groups;
    }

    /**
     * Retrieve cart products that are not part of the configured quick order list.
     *
     * @param array $existing_product_ids Product IDs already in the quick order list.
     * @param array $cart_quantities      Quantities indexed by product/variation id.
     *
     * @return WC_Product[]
     */
    protected function get_cart_products_not_in_list( $existing_product_ids, $cart_quantities ) {
        $products        = [];
        $existing_lookup = [];

        foreach ( (array) $existing_product_ids as $product_id ) {
            $existing_lookup[ (int) $product_id ] = true;
        }

        if ( ! WC()->cart ) {
            return $products;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
                continue;
            }

            $product    = $item['data'];
            $product_id = (int) $product->get_id();

            if ( isset( $existing_lookup[ $product_id ] ) ) {
                continue;
            }

            if ( empty( $cart_quantities[ $product_id ] ) ) {
                continue;
            }

            $products[ $product_id ] = $product;
        }

        return $products;
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

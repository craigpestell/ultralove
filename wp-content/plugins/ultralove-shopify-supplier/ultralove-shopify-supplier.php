<?php
/**
 * Plugin Name: UltraLove Shopify Supplier
 * Description: Development supplier controls for the Shopify integration test store.
 * Version: 0.1.0
 * Author: UltraLove
 * Requires Plugins: woocommerce, ultralove-dropshipping
 * Text Domain: ultralove-shopify-supplier
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class UltraLove_Shopify_Supplier {
    const OPTION = 'ultralove_shopify_supplier';

    public static function supported(): bool {
        return wp_get_environment_type() === 'development'
            && home_url() === 'https://dev.ultralove.ca' && DB_NAME === 'wordpress_dev';
    }

    public static function settings(): array {
        return wp_parse_args( get_option( self::OPTION, array() ), array(
            'enabled' => false, 'product_ids' => array(), 'retailer_id' => 0, 'markup' => 0.0,
        ) );
    }

    public static function enabled(): bool {
        return self::supported() && self::settings()['enabled'] === true
            && class_exists( 'UltraLove_Dropshipping' ) && UltraLove_Dropshipping::enabled();
    }

    public static function eligible( $product ): bool {
        return $product instanceof WC_Product && $product->is_type( 'simple' )
            && $product->get_status() === 'publish' && $product->get_sku() !== ''
            && $product->get_manage_stock() && $product->get_stock_quantity() !== null
            && (float) $product->get_price() > 0;
    }

    public static function init(): void {
        add_action( 'admin_menu', static function (): void {
            if ( self::supported() ) {
                add_submenu_page( 'woocommerce', 'Shopify Supplier', 'Shopify Supplier', 'manage_options',
                    'ultralove-shopify-supplier', array( __CLASS__, 'page' ) );
            }
        } );
        add_action( 'admin_post_ultralove_shopify_supplier', array( __CLASS__, 'save' ) );
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'tracking_field' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_tracking' ) );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'ultralove shopify sync', array( __CLASS__, 'sync' ) );
        }
    }

    public static function validate( array $settings ): array {
        foreach ( $settings['product_ids'] as $id ) {
            if ( ! ctype_digit( (string) $id ) || (int) $id < 1 ) {
                throw new InvalidArgumentException( 'Product IDs must be positive integers.' );
            }
        }
        $ids = array_values( array_unique( array_map( 'absint', $settings['product_ids'] ) ) );
        if ( count( $ids ) > 25 || in_array( 0, $ids, true ) ) {
            throw new InvalidArgumentException( 'Select up to 25 valid existing product IDs.' );
        }
        foreach ( $ids as $id ) {
            if ( ! self::eligible( wc_get_product( $id ) ) ) {
                throw new InvalidArgumentException( 'Product ' . $id . ' must be a published, priced simple product with a SKU and managed stock.' );
            }
        }
        $markup = filter_var( $settings['markup'], FILTER_VALIDATE_FLOAT );
        if ( $markup === false || ! is_finite( $markup ) || $markup < 0 || $markup > 1000 ) {
            throw new InvalidArgumentException( 'Initial retail markup must be between 0 and 1000 percent.' );
        }
        $retailer_id = absint( $settings['retailer_id'] );
        if ( ! empty( $settings['enabled'] ) ) {
            if ( ! $ids || ! class_exists( 'UltraLove_Dropshipping' ) || ! UltraLove_Dropshipping::enabled()
                || ! get_user_by( 'id', $retailer_id )
                || get_user_meta( $retailer_id, UltraLove_Dropshipping::APPROVAL, true ) !== 'yes' ) {
                throw new InvalidArgumentException( 'Enable dropshipping and select products plus an approved retailer before enabling this connection.' );
            }
        }
        return array( 'enabled' => ! empty( $settings['enabled'] ), 'product_ids' => $ids, 'retailer_id' => $retailer_id, 'markup' => (float) $markup );
    }

    public static function save(): void {
        if ( ! self::supported() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You cannot configure this development integration.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'ultralove_shopify_supplier' );
        foreach ( array( 'product_ids', 'retailer_id', 'markup' ) as $field ) {
            if ( ! isset( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) {
                wp_die( 'Invalid supplier settings.', '', array( 'response' => 400 ) );
            }
        }
        $raw = trim( wp_unslash( $_POST['product_ids'] ) );
        if ( $raw !== '' && ! preg_match( '/^\d+(?:\s*,\s*\d+)*$/', $raw ) ) {
            wp_die( 'Product IDs must be comma-separated numbers.', '', array( 'response' => 400 ) );
        }
        try {
            $settings = self::validate( array(
                'enabled' => isset( $_POST['enabled'] ),
                'product_ids' => $raw === '' ? array() : explode( ',', $raw ),
                'retailer_id' => wp_unslash( $_POST['retailer_id'] ),
                'markup' => wp_unslash( $_POST['markup'] ),
            ) );
        } catch ( InvalidArgumentException $error ) {
            wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 400 ) );
        }
        update_option( self::OPTION, $settings, false );
        wp_safe_redirect( admin_url( 'admin.php?page=ultralove-shopify-supplier&updated=1' ) );
        exit;
    }

    public static function page(): void {
        if ( ! self::supported() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You cannot view this development integration.', '', array( 'response' => 403 ) );
        }
        $settings = self::settings();
        $status = get_option( 'ul_dev_shopify_status', array() );
        $retailers = get_users( array( 'meta_key' => '_ultralove_dropshipping_approved', 'meta_value' => 'yes', 'number' => 100 ) );
        ?>
        <div class="wrap">
            <h1>Shopify Supplier</h1>
            <p>Development only: existing UltraLove products to <strong>ultralove-integration-lab.myshopify.com</strong>.
                Products import as drafts. Only explicitly tagged Shopify test orders are accepted; no live payment or dispatch.</p>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success"><p>Supplier settings saved. The next scheduled worker uses these settings.</p></div>
            <?php endif; ?>
            <?php if ( ! empty( $status['error'] ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $status['error'] ); ?></p></div>
            <?php endif; ?>
            <p>Last successful sync: <strong><?php echo esc_html( get_option( 'ul_dev_shopify_last_success', 'Not yet run' ) ); ?></strong>.
                The root-only worker runs every five minutes; credentials never appear in WordPress.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ultralove_shopify_supplier">
                <?php wp_nonce_field( 'ultralove_shopify_supplier' ); ?>
                <table class="form-table">
                    <tr><th scope="row"><label for="ul-shopify-enabled">Connection</label></th>
                        <td><label><input id="ul-shopify-enabled" type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>> Enable dev supplier sync</label></td></tr>
                    <tr><th scope="row"><label for="ul-shopify-products">Existing product IDs</label></th>
                        <td><input id="ul-shopify-products" class="regular-text" name="product_ids" value="<?php echo esc_attr( implode( ',', $settings['product_ids'] ) ); ?>">
                            <p class="description">Comma-separated IDs, up to 25. Published simple products with SKUs and managed stock only.
                                Deselection stops forwarding but does not delete the Shopify draft.</p></td></tr>
                    <tr><th scope="row"><label for="ul-shopify-retailer">Approved retailer</label></th>
                        <td><select id="ul-shopify-retailer" name="retailer_id"><option value="0">Select a retailer</option>
                            <?php foreach ( $retailers as $retailer ) : ?>
                                <option value="<?php echo esc_attr( $retailer->ID ); ?>" <?php selected( $settings['retailer_id'], $retailer->ID ); ?>><?php echo esc_html( $retailer->display_name ); ?></option>
                            <?php endforeach; ?>
                        </select><p class="description">Imported orders belong to this approved account. Recipient addresses are not saved to its profile.</p></td></tr>
                    <tr><th scope="row"><label for="ul-shopify-markup">Initial retail markup (%)</label></th>
                        <td><input id="ul-shopify-markup" type="number" min="0" max="1000" step="0.01" name="markup" value="<?php echo esc_attr( $settings['markup'] ); ?>">
                            <p class="description">Applied only when creating a Shopify product. Subsequent stock sync preserves retailer-edited prices.
                                Supplier orders use the WooCommerce product price; test totals exclude shipping and tax.</p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Selected supplier catalog</h2>
            <table class="widefat striped"><thead><tr><th>Product</th><th>SKU</th><th>WooCommerce price</th><th>Stock</th><th>Shopify mapping</th></tr></thead><tbody>
                <?php foreach ( $settings['product_ids'] as $id ) :
                    $product = wc_get_product( $id );
                    if ( ! $product ) {
                        echo '<tr><td colspan="5">Missing product ' . esc_html( $id ) . '</td></tr>';
                        continue;
                    }
                    $mapping = $product->get_meta( '_ul_dev_shopify_product' );
                    ?>
                    <tr><td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $product->get_name() ) ); ?></a></td>
                        <td><?php echo esc_html( $product->get_sku() ); ?></td><td><?php echo wp_kses_post( wc_price( $product->get_price() ) ); ?></td>
                        <td><?php echo esc_html( $product->get_stock_quantity() ); ?></td><td><?php echo esc_html( $mapping ? $mapping['id'] : 'Not imported' ); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    public static function sync(): void {
        if ( ! self::enabled() ) {
            WP_CLI::log( 'Dev Shopify supplier is disabled; no external requests or mutations.' );
            return;
        }
        $previous_user = get_current_user_id();
        try {
            require_once __DIR__ . '/includes/class-test-bridge.php';
            $settings = self::validate( self::settings() );
            wp_set_current_user( $settings['retailer_id'] );
            ( new UltraLove_Shopify_Test_Bridge( 'dev' ) )->run();
            update_option( 'ul_dev_shopify_status', array( 'error' => null ), false );
        } catch ( Throwable $error ) {
            update_option( 'ul_dev_shopify_status', array( 'error' => $error->getMessage(), 'failed_at' => gmdate( 'c' ) ), false );
            error_log( '[ultralove-shopify-supplier] ' . $error->getMessage() );
            WP_CLI::error( $error->getMessage(), false );
            WP_CLI::halt( 1 );
        } finally {
            wp_set_current_user( $previous_user );
        }
    }

    public static function tracking_field( $order ): void {
        if ( ! self::supported() || ! current_user_can( 'manage_woocommerce' ) || ! $order->get_meta( '_ul_dev_shopify_order' ) ) {
            return;
        }
        wp_nonce_field( 'ul_shopify_tracking_' . $order->get_id(), 'ul_shopify_tracking_nonce' );
        woocommerce_wp_text_input( array(
            'id' => 'ul_shopify_test_tracking', 'label' => 'Shopify test tracking',
            'value' => $order->get_meta( '_ul_lab_tracking_number' ),
            'description' => 'Use LAB- followed by a test number. Complete this test order to return tracking for supplier lines only; no email or parcel is sent.',
        ) );
    }

    public static function save_tracking( $id ): void {
        if ( ! isset( $_POST['ul_shopify_tracking_nonce'] ) ) {
            return;
        }
        if ( ! self::supported() || ! current_user_can( 'manage_woocommerce' )
            || ! is_string( $_POST['ul_shopify_tracking_nonce'] )
            || ! wp_verify_nonce( wp_unslash( $_POST['ul_shopify_tracking_nonce'] ), 'ul_shopify_tracking_' . $id ) ) {
            wp_die( 'You cannot update test tracking.', '', array( 'response' => 403 ) );
        }
        $order = wc_get_order( $id );
        if ( ! $order || ! $order->get_meta( '_ul_dev_shopify_order' ) || ! isset( $_POST['ul_shopify_test_tracking'] )
            || ! is_string( $_POST['ul_shopify_test_tracking'] ) ) {
            wp_die( 'Invalid supplier order or tracking input.', '', array( 'response' => 400 ) );
        }
        $tracking = trim( wp_unslash( $_POST['ul_shopify_test_tracking'] ) );
        if ( $tracking !== '' && ! preg_match( '/^LAB-[A-Za-z0-9-]{1,100}$/', $tracking ) ) {
            wp_die( 'Tracking must be a LAB- test number.', '', array( 'response' => 400 ) );
        }
        if ( $order->get_meta( '_ul_lab_fulfillment_id' ) && $tracking !== $order->get_meta( '_ul_lab_tracking_number' ) ) {
            wp_die( 'Tracking has already been forwarded; reconcile the Shopify fulfillment before changing it.', '', array( 'response' => 409 ) );
        }
        $order->update_meta_data( '_ul_lab_tracking_number', $tracking );
        $order->save();
    }
}
UltraLove_Shopify_Supplier::init();

<?php
/** Exercise the real Blocks checkout REST route using disposable products and customers. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_STORE_API_TEST' ) ) {
	exit( 1 );
}
ob_start();

$failures = 0;
$count = 0;
$assert = static function ( $condition, $label ) use ( &$failures, &$count ) {
	++$count;
	$failures += $condition ? 0 : 1;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
};
$saved_options = array();
foreach ( array( 'sdpr_options', 'woocommerce_cod_settings', 'woocommerce_hold_stock_minutes' ) as $name ) {
	$saved_options[ $name ] = get_option( $name, false );
}
$saved_user = get_current_user_id();
$saved_cart = WC()->cart;
$saved_customer = WC()->customer;
$saved_session = WC()->session;
$orders = array();
$products = array();
$holds = array();
$user_id = 0;
$lifecycle = SDPR_Plugin::get_instance()->get_service( 'lifecycle' );
$capture_order = static function ( $id ) use ( &$orders ) { $orders[] = $id; };
$suppress_mail = static function () { return true; };
add_action( 'woocommerce_new_order', $capture_order );
add_filter( 'pre_wp_mail', $suppress_mail );

try {
	update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 100, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 0 ) );
	update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'enable_for_virtual' => 'yes' ) );
	$token = strtolower( wp_generate_password( 12, false ) );
	$user_id = wp_insert_user( array( 'user_login' => 'sdpr-api-' . $token, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $token . '@example.test', 'role' => 'customer' ) );
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Could not create the disposable customer.' );
	}
	wp_set_current_user( $user_id );
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
	WC()->customer = new WC_Customer( $user_id );
	WC()->cart = new WC_Cart();
	WC()->cart->get_cart();
	$gateway = WC()->payment_gateways()->payment_gateways()['cod'];
	$gateway->init_settings();
	$gateway->enabled = 'yes';
	$gateway->enable_for_virtual = true;
	$address = array( 'first_name' => 'Demo', 'last_name' => 'Customer', 'company' => '', 'address_1' => '100 Example Street', 'address_2' => '', 'city' => 'Los Angeles', 'state' => 'CA', 'postcode' => '90210', 'country' => 'US', 'email' => $token . '@example.test', 'phone' => '2025550100' );
	$checkout = static function () use ( $address ) {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'billing_address' => $address, 'shipping_address' => $address, 'payment_method' => 'cod', 'payment_data' => array() ) ) );
		return rest_do_request( $request );
	};

	// A real HTTP request gets a fresh route instance. Run each case in its own PHP process.
	foreach ( array( '1' === getenv( 'SDPR_STORE_API_SHARED' ) ) as $shared ) {
		foreach ( array( (int) getenv( 'SDPR_STORE_API_HOLD_MINUTES' ) ) as $hold_minutes ) {
			update_option( 'woocommerce_hold_stock_minutes', $hold_minutes );
			WC()->cart->empty_cart();
			WC()->session->set( 'store_api_draft_order', 0 );
			WC()->session->set( 'order_awaiting_payment', 0 );
			$parent = new WC_Product_Variable();
			$parent->set_name( 'Store API Headphones ' . $token );
			$parent->set_status( 'publish' );
			$parent->set_manage_stock( $shared );
			if ( $shared ) {
				$parent->set_stock_quantity( 2 );
			}
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( 'Finish' );
			$attribute->set_options( array( 'Midnight', 'Pearl' ) );
			$attribute->set_variation( true );
			$parent->set_attributes( array( $attribute ) );
			$parent_id = $parent->save();
			$products[] = $parent_id;
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_virtual( true );
			$variation->set_regular_price( 89 );
			$variation->set_attributes( array( 'finish' => '' ) );
			$variation->set_manage_stock( ! $shared );
			if ( ! $shared ) {
				$variation->set_stock_quantity( 2 );
			}
			$variation_id = $variation->save();
			$products[] = $variation_id;
			WC_Product_Variable::sync( $parent_id );
			$label = ( $shared ? 'Shared' : 'Independent' ) . ' stock, Woo hold ' . $hold_minutes . ': ';
			$attributes = array( 'attribute_finish' => 'Midnight' );
			$result = $lifecycle->request( $variation_id, $user_id, 1, $attributes );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'Could not create the disposable variation hold.' );
			}
			$id = $result['reservation_id'];
			$holds[] = $id;
			$assert( 1 === SDPR_Reservation_Product::stock_quantity( $variation_id ), $label . 'reservation consumes exactly one unit.' );
			if ( '1' === getenv( 'SDPR_STORE_API_MISMATCH' ) ) {
				wc_update_product_stock( SDPR_Reservation_Product::stock_owner_id( $variation_id ), 0, 'set' );
				$key = WC()->cart->add_to_cart( $parent_id, 1, $variation_id, array( 'attribute_finish' => 'Pearl' ) );
				WC()->cart->calculate_totals();
				$response = $checkout();
				$assert( $key && $response->get_status() >= 400, $label . 'real Store API rejects borrowing a wildcard hold for other options.' );
				$assert( 'active' === SDPR_Reservation_Meta::get( $id, SDPR_Reservation_Meta::STATUS ) && 0 === SDPR_Reservation_Product::stock_quantity( $variation_id ), $label . 'rejected checkout leaves the hold and physical stock unchanged.' );
				continue;
			}
			$key = WC()->cart->add_to_cart( $parent_id, 1, $variation_id, $attributes );
			WC()->cart->calculate_totals();
			$assert( (bool) $key, $label . 'selected held options enter the cart.' );
			$response = $checkout();
			$data = $response->get_data();
			$assert( 200 === $response->get_status() && ! empty( $data['order_id'] ), $label . 'real Store API checkout succeeds (HTTP ' . $response->get_status() . ( isset( $data['code'] ) ? ', ' . sanitize_key( $data['code'] ) : '' ) . ').' );
			$order = empty( $data['order_id'] ) ? false : wc_get_order( $data['order_id'] );
			if ( ! $order ) {
				continue;
			}
			$items = array_values( $order->get_items() );
			$assert( 1 === count( $items ) && $variation_id === $items[0]->get_variation_id() && 'Midnight' === $items[0]->get_meta( 'finish' ), $label . 'order retains the exact variation and wildcard selection.' );
			$assert( 'fulfilled' === SDPR_Reservation_Meta::get( $id, SDPR_Reservation_Meta::STATUS ) && 1 === SDPR_Reservation_Product::stock_quantity( $variation_id ), $label . 'checkout transfers the hold without reducing stock twice.' );
			$order->update_status( 'cancelled' );
			SDPR_Plugin::get_instance()->get_service( 'cart_order' )->restore_transferred_order_stock( $order->get_id() );
			$assert( 2 === SDPR_Reservation_Product::stock_quantity( $variation_id ), $label . 'cancelled order restores the unit exactly once.' );
		}
	}
} catch ( Throwable $error ) {
	$assert( false, 'Store API test exception: ' . $error->getMessage() );
} finally {
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
	remove_action( 'woocommerce_new_order', $capture_order );
	remove_filter( 'pre_wp_mail', $suppress_mail );
	foreach ( array_unique( $orders ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			wc_release_stock_for_order( $order );
			$order->delete( true );
		}
	}
	foreach ( $holds as $id ) {
		$lifecycle->cancel( $id );
		wp_delete_post( $id, true );
	}
	foreach ( array_reverse( $products ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $user_id && ! is_wp_error( $user_id ) ) {
		WC()->session->destroy_session();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
	WC()->cart = $saved_cart;
	WC()->customer = $saved_customer;
	WC()->session = $saved_session;
	wp_set_current_user( $saved_user );
	foreach ( $saved_options as $name => $value ) {
		if ( false === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
}
echo $failures . ' failures in ' . $count . " Store API checkout assertions.\n";
ob_end_flush();
if ( $failures ) {
	exit( 1 );
}

<?php
/** Real WooCommerce product, cart, order and inventory regressions for variations. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_VARIATION_TEST' ) ) {
	exit( 1 );
}

$sdpr_v_failures = array();
$sdpr_v_count = 0;
function sdpr_v_assert( $condition, $label ) {
	global $sdpr_v_failures, $sdpr_v_count;
	++$sdpr_v_count;
	if ( ! $condition ) {
		$sdpr_v_failures[] = $label;
	}
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
}

$sdpr_v_plugin = SDPR_Plugin::get_instance();
$sdpr_v_lifecycle = $sdpr_v_plugin->get_service( 'lifecycle' );
$sdpr_v_orders = array();
$sdpr_v_products = array();
$sdpr_v_holds = array();
$sdpr_v_users = array();
$sdpr_v_options = get_option( 'sdpr_options', false );
$sdpr_v_user = get_current_user_id();
$sdpr_v_cart = WC()->cart;
$GLOBALS['sdpr_v_failures'] =& $sdpr_v_failures;
$GLOBALS['sdpr_v_count'] =& $sdpr_v_count;
$GLOBALS['sdpr_v_products'] =& $sdpr_v_products;
$GLOBALS['sdpr_v_holds'] =& $sdpr_v_holds;
$GLOBALS['sdpr_v_lifecycle'] = $sdpr_v_lifecycle;

function sdpr_v_fixture( $shared = false, $wildcard = false ) {
	global $sdpr_v_products;
	$parent = new WC_Product_Variable();
	$parent->set_name( 'Studio Headphones ' . wp_generate_password( 6, false ) );
	$parent->set_status( 'publish' );
	$parent->set_manage_stock( $shared );
	if ( $shared ) {
		$parent->set_stock_quantity( 5 );
	}
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Finish' );
	$attribute->set_options( array( 'Midnight', 'Pearl' ) );
	$attribute->set_variation( true );
	$parent->set_attributes( array( $attribute ) );
	$parent_id = $parent->save();
	$sdpr_v_products[] = $parent_id;
	$ids = array();
	foreach ( $wildcard ? array( '' ) : array( 'Midnight', 'Pearl' ) as $finish ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( 89 );
		$variation->set_attributes( array( 'finish' => $finish ) );
		$variation->set_manage_stock( ! $shared );
		if ( ! $shared ) {
			$variation->set_stock_quantity( 5 );
		}
		$ids[] = $variation->save();
		$sdpr_v_products[] = $variation->get_id();
	}
	WC_Product_Variable::sync( $parent_id );
	return array( $parent_id, $ids );
}

function sdpr_v_request( $product_id, $attributes = array() ) {
	global $sdpr_v_lifecycle, $sdpr_v_holds;
	$result = $sdpr_v_lifecycle->request( $product_id, get_current_user_id(), 1, $attributes );
	if ( ! is_wp_error( $result ) ) {
		$sdpr_v_holds[] = $result['reservation_id'];
	}
	return $result;
}

try {
	update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 100, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 0 ) );
	foreach ( array( 'owner', 'other' ) as $role ) {
		$token = strtolower( wp_generate_password( 10, false ) );
		$sdpr_v_users[] = wp_insert_user( array( 'user_login' => 'sdpr-variation-' . $token, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $token . '@example.test', 'role' => 'customer' ) );
	}
	wp_set_current_user( $sdpr_v_users[0] );
	WC()->cart = new WC_Cart();
	foreach ( array( false, true ) as $shared ) {
		list( $parent_id, $ids ) = sdpr_v_fixture( $shared );
		list( $first, $second ) = $ids;
		$product = wc_get_product( $first );
		$label = $shared ? 'Shared parent stock: ' : 'Independent variation stock: ';
		sdpr_v_assert( is_wp_error( sdpr_v_request( $parent_id ) ), $label . 'parent cannot be reserved directly.' );
		sdpr_v_assert( $sdpr_v_plugin->reservations->can_show_reservation_form( wc_get_product( $parent_id ) ), $label . 'variable product renders the chooser action.' );
		$res = sdpr_v_request( $first );
		sdpr_v_assert( ! is_wp_error( $res ), $label . 'selected variation can be reserved without extension opt-in.' );
		if ( is_wp_error( $res ) ) {
			continue;
		}
		$id = $res['reservation_id'];
		sdpr_v_assert( 4 === SDPR_Reservation_Product::stock_quantity( $product ), $label . 'reservation decreases physical stock once.' );
		sdpr_v_assert( ( $shared ? 4 : 5 ) === SDPR_Reservation_Product::stock_quantity( $second ), $label . 'sibling stock uses its proper resource.' );
		sdpr_v_assert( is_wp_error( sdpr_v_request( $first ) ), $label . 'duplicate variation hold blocked.' );
		$limited_options = get_option( 'sdpr_options' );
		$limited_options['max_reservations'] = 1;
		update_option( 'sdpr_options', $limited_options );
		$limited = sdpr_v_request( $second );
		sdpr_v_assert( is_wp_error( $limited ) && 'sdpr_limit' === $limited->get_error_code(), $label . 'customer quota also counts holds on sibling variations.' );
		$limited_options['max_reservations'] = 100;
		update_option( 'sdpr_options', $limited_options );
		sdpr_v_assert( array( 'attribute_finish' => 'Midnight' ) === SDPR_Reservation_Product::attributes( $id ), $label . 'selected attributes stored on the reservation.' );
		$url = SDPR_Reservation_Product::cart_url( $id, $product );
		sdpr_v_assert( false !== strpos( $url, 'variation_id=' . $first ) && false !== strpos( $url, 'attribute_finish=Midnight' ), $label . 'customer/email cart link preserves variation and options.' );
		sdpr_v_assert( false !== strpos( wp_strip_all_tags( SDPR_Reservation_Product::name( $id, $product ) ), 'Midnight' ), $label . 'display name includes the actual options.' );
		$stock_owner = SDPR_Reservation_Product::stock_owner_id( $product );
		wc_update_product_stock( $stock_owner, 0, 'set' );
		sdpr_v_assert( 1 === (int) wc_get_product( $first )->get_stock_quantity(), $label . 'owner can purchase the held final unit.' );
		sdpr_v_assert( false === $sdpr_v_lifecycle->create( $first, get_current_user_id() ) && 0 === SDPR_Reservation_Product::stock_quantity( $first ), $label . 'filtered owner allowance cannot hide physical stock underflow.' );
		wp_set_current_user( $sdpr_v_users[1] );
		$other_stock = (int) wc_get_product( $first )->get_stock_quantity();
		sdpr_v_assert( 0 === $other_stock, $label . 'another customer receives no held allowance (actual stock ' . $other_stock . ').' );
		wp_set_current_user( $sdpr_v_users[0] );
		$key = WC()->cart->add_to_cart( $parent_id, 1, $first, array( 'attribute_finish' => 'Midnight' ) );
		$sdpr_v_plugin->get_service( 'cart_order' )->sync_cart_reservations( WC()->cart );
		sdpr_v_assert( $key && $id === (int) WC()->cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ], $label . 'cart receives the correct reservation (cart key ' . ( $key ? 'present' : 'missing' ) . ').' );
		sdpr_v_assert( true === WC()->cart->check_cart_item_stock(), $label . 'WooCommerce cart accepts the held final unit.' );
		$order = wc_create_order( array( 'customer_id' => $sdpr_v_users[0] ) );
		$sdpr_v_orders[] = $order->get_id();
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $first ) );
		$item->set_quantity( 1 );
		$item->set_variation( wc_get_product( $first )->get_variation_attributes() );
		$item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, $id, true );
		$order->add_item( $item );
		$order->save();
		$sdpr_v_plugin->reservations->transfer_holds_to_order( $order );
		$order->update_status( 'processing' );
		sdpr_v_assert( 0 === SDPR_Reservation_Product::stock_quantity( $first ) && 'fulfilled' === SDPR_Reservation_Meta::get( $id, SDPR_Reservation_Meta::STATUS ), $label . 'order transfers the hold without a second decrement.' );
		$order->update_status( 'cancelled' );
		$sdpr_v_plugin->get_service( 'cart_order' )->restore_transferred_order_stock( $order->get_id() );
		sdpr_v_assert( 1 === SDPR_Reservation_Product::stock_quantity( $first ), $label . 'order cancellation restores exactly once.' );
		WC()->cart->empty_cart();
		wc_update_product_stock( $stock_owner, 5, 'set' );
		$options = get_option( 'sdpr_options' );
		$options['require_admin_approval'] = 1;
		update_option( 'sdpr_options', $options );
		$pending = sdpr_v_request( $first );
		sdpr_v_assert( ! is_wp_error( $pending ) && 5 === SDPR_Reservation_Product::stock_quantity( $first ), $label . 'pending approval does not hold stock.' );
		if ( ! is_wp_error( $pending ) ) {
			$id = $pending['reservation_id'];
			sdpr_v_assert( true === $sdpr_v_lifecycle->approve( $id ) && 4 === SDPR_Reservation_Product::stock_quantity( $first ), $label . 'approval holds the stock owner once.' );
			SDPR_Reservation_Meta::update( $id, SDPR_Reservation_Meta::EXPIRES_AT, time() - 1 );
			$sdpr_v_plugin->reservations->expire_old_reservations();
			$sdpr_v_plugin->reservations->expire_old_reservations();
			sdpr_v_assert( 5 === SDPR_Reservation_Product::stock_quantity( $first ), $label . 'expiry restores stock once.' );
		}
		$options['require_admin_approval'] = 0;
		update_option( 'sdpr_options', $options );
		$order_hold = wc_create_order( array( 'customer_id' => $sdpr_v_users[1] ) );
		$sdpr_v_orders[] = $order_hold->get_id();
		$order_hold->add_product( wc_get_product( $first ), 5 );
		$order_hold->save();
		wc_reserve_stock_for_order( $order_hold );
		sdpr_v_assert( 5 === (int) wc_get_held_stock_quantity( wc_get_product( $first ) ), $label . 'fixture holds stock for a separate pending WooCommerce order.' );
		sdpr_v_assert( is_wp_error( sdpr_v_request( $first ) ) && 5 === SDPR_Reservation_Product::stock_quantity( $first ), $label . 'stock held by a pending WooCommerce order cannot be reserved again.' );
		wc_release_stock_for_order( $order_hold );
		$disabled = wc_get_product( $second );
		$disabled->set_status( 'private' );
		$disabled->save();
		sdpr_v_assert( is_wp_error( sdpr_v_request( $second ) ), $label . 'disabled variation rejected.' );
		$parent = wc_get_product( $parent_id );
		$parent->set_status( 'draft' );
		$parent->save();
		sdpr_v_assert( is_wp_error( sdpr_v_request( $first ) ), $label . 'unpublished parent rejected.' );
	}

	list( $shared_parent, $siblings ) = sdpr_v_fixture( true );
	wc_update_product_stock( $shared_parent, 2, 'set' );
	$shared_holds = array( sdpr_v_request( $siblings[0] ), sdpr_v_request( $siblings[1] ) );
	if ( ! is_wp_error( $shared_holds[0] ) && ! is_wp_error( $shared_holds[1] ) ) {
		$first_key = WC()->cart->add_to_cart( $shared_parent, 1, $siblings[0], array( 'attribute_finish' => 'Midnight' ) );
		$second_key = WC()->cart->add_to_cart( $shared_parent, 1, $siblings[1], array( 'attribute_finish' => 'Pearl' ) );
		$sdpr_v_plugin->get_service( 'cart_order' )->sync_cart_reservations( WC()->cart );
		sdpr_v_assert( $first_key && $second_key && true === WC()->cart->check_cart_item_stock(), 'Shared cart: two sibling holds can buy the two held final units together.' );
		WC()->cart->empty_cart();
		foreach ( $shared_holds as $hold ) {
			$sdpr_v_lifecycle->cancel( $hold['reservation_id'] );
		}
	} else {
		sdpr_v_assert( false, 'Shared cart: both siblings can hold the available shared stock.' );
	}

	list( $parent_id, $ids ) = sdpr_v_fixture( true, true );
	$variation_id = $ids[0];
	$midnight = array( 'attribute_finish' => 'Midnight' );
	$pearl = array( 'attribute_finish' => 'Pearl' );
	foreach ( array( array(), array( 'attribute_finish' => 'Invalid' ), array( 'attribute_finish' => array( 'Midnight' ) ), $midnight + array( 'attribute_unknown' => 'Bad' ) ) as $invalid ) {
		sdpr_v_assert( is_wp_error( sdpr_v_request( $variation_id, $invalid ) ), 'Wildcard: missing/invalid/array/unknown attribute selection rejected.' );
	}
	$res = sdpr_v_request( $variation_id, $midnight );
	$id = is_wp_error( $res ) ? 0 : $res['reservation_id'];
	if ( $id ) {
		sdpr_v_assert( is_wp_error( sdpr_v_request( $variation_id, $pearl ) ), 'Wildcard: a different option on the same concrete variation cannot bypass duplicate blocking.' );
		$sdpr_v_plugin->get_service( 'cart_order' )->clear_cache();
		$key = WC()->cart->add_to_cart( $parent_id, 1, $variation_id, $pearl );
		$sdpr_v_plugin->get_service( 'cart_order' )->sync_cart_reservations( WC()->cart );
		sdpr_v_assert( $key && empty( WC()->cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ] ), 'Wildcard: different selected options cannot consume the hold.' );
		WC()->cart->empty_cart();
		$order = wc_create_order( array( 'customer_id' => $sdpr_v_users[0] ) );
		$sdpr_v_orders[] = $order->get_id();
		$item = new WC_Order_Item_Product();
		$item->set_product( wc_get_product( $variation_id ) );
		$item->set_quantity( 1 );
		$item->set_variation( $pearl );
		$item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, $id, true );
		$order->add_item( $item );
		$order->save();
		$rejected = false;
		try {
			$sdpr_v_plugin->reservations->transfer_holds_to_order( $order );
		} catch ( WC_Data_Exception $error ) {
			$rejected = true;
		}
		sdpr_v_assert( $rejected && 'active' === SDPR_Reservation_Meta::get( $id, SDPR_Reservation_Meta::STATUS ) && 4 === SDPR_Reservation_Product::stock_quantity( $variation_id ), 'Wildcard: mismatched order options rejected without stock mutation.' );
		$sdpr_v_lifecycle->cancel( $id );
	} else {
		sdpr_v_assert( false, 'Wildcard: valid complete selection accepted.' );
	}
} catch ( Throwable $error ) {
	sdpr_v_assert( false, 'Unexpected exception: ' . $error->getMessage() );
} finally {
	WC()->cart = $sdpr_v_cart;
	foreach ( $sdpr_v_orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( $sdpr_v_holds as $id ) {
		$sdpr_v_lifecycle->cancel( $id );
		wp_delete_post( $id, true );
	}
	foreach ( array_reverse( $sdpr_v_products ) as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $sdpr_v_users as $id ) {
		wp_delete_user( $id );
	}
	wp_set_current_user( $sdpr_v_user );
	if ( false === $sdpr_v_options ) {
		delete_option( 'sdpr_options' );
	} else {
		update_option( 'sdpr_options', $sdpr_v_options );
	}
}
echo count( $sdpr_v_failures ) . ' failures in ' . $sdpr_v_count . " variation assertions.\n";
if ( $sdpr_v_failures ) {
	exit( 1 );
}

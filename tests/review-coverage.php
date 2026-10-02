<?php
/** Additional first-release cart, refund, privacy and search regression coverage. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_REVIEW_TEST' ) ) {
	exit( 1 );
}

$sdpr_review_failures = array();
$sdpr_review_count    = 0;
$GLOBALS['sdpr_review_failures'] =& $sdpr_review_failures;
$GLOBALS['sdpr_review_count'] =& $sdpr_review_count;
function sdpr_review_assert( $condition, $label ) {
	global $sdpr_review_failures, $sdpr_review_count;
	++$sdpr_review_count;
	if ( ! $condition ) {
		$sdpr_review_failures[] = $label;
	}
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
}

$sdpr_review_plugin  = SDPR_Plugin::get_instance();
$sdpr_review_service = $sdpr_review_plugin->reservations;
$sdpr_review_cart    = $sdpr_review_plugin->get_service( 'cart_order' );
$sdpr_review_options = get_option( 'sdpr_options', false );
$sdpr_review_user    = get_current_user_id();
$sdpr_review_objects = array( 'products' => array(), 'reservations' => array(), 'orders' => array(), 'users' => array() );
$GLOBALS['sdpr_review_service'] = $sdpr_review_service;
$GLOBALS['sdpr_review_cart'] = $sdpr_review_cart;
$GLOBALS['sdpr_review_objects'] =& $sdpr_review_objects;

function sdpr_review_product( $stock = 5 ) {
	global $sdpr_review_objects;
	$product = new WC_Product_Simple();
	$product->set_name( 'SDPR Review Coverage ' . wp_generate_password( 8, false ) );
	$product->set_status( 'publish' );
	$product->set_regular_price( 10 );
	$product->set_virtual( true );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $stock );
	$id = $product->save();
	$sdpr_review_objects['products'][] = $id;
	return $id;
}
function sdpr_review_hold( $product_id, $user_id ) {
	global $sdpr_review_objects, $sdpr_review_service;
	$id = $sdpr_review_service->create_reservation( $product_id, $user_id );
	$sdpr_review_objects['reservations'][] = $id;
	return $id;
}
function sdpr_review_stock( $product_id ) {
	return (int) wc_get_product( $product_id )->get_stock_quantity( 'edit' );
}
function sdpr_review_order( $product_id, $reservation_id, $user_id, $quantity = 1 ) {
	global $sdpr_review_objects, $sdpr_review_cart;
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	$sdpr_review_objects['orders'][] = $order->get_id();
	$item_id = $order->add_product( wc_get_product( $product_id ), $quantity );
	$item = $order->get_item( $item_id );
	$item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, $reservation_id, true );
	$item->save();
	$order->calculate_totals();
	$sdpr_review_cart->transfer_holds_to_order( $order );
	return array( $order, $item_id );
}

try {
	update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 100, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 0 ) );
	$token = strtolower( wp_generate_password( 8, false ) );
	$user_id = wp_insert_user( array( 'user_login' => 'sdpr-review-' . $token, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'sdpr-review-' . $token . '@example.test', 'display_name' => 'Review Display ' . $token, 'role' => 'customer' ) );
	$other_id = wp_insert_user( array( 'user_login' => 'sdpr-review-other-' . $token, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'sdpr-review-other-' . $token . '@example.test', 'role' => 'customer' ) );
	$sdpr_review_objects['users'] = array( $user_id, $other_id );
	wp_set_current_user( $user_id );

	// Cart mutations do not release independent reservation ownership.
	$product_id = sdpr_review_product();
	$cart = new WC_Cart();
	$key = $cart->add_to_cart( $product_id, 2 );
	sdpr_review_assert( $key && empty( $cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ] ), 'Cart before hold starts unlinked.' );
	$hold = sdpr_review_hold( $product_id, $user_id );
	$sdpr_review_cart->sync_cart_reservations( $cart );
	sdpr_review_assert( $hold === (int) $cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ], 'Cart before hold links to the newly created hold.' );
	$cart->set_quantity( $key, 3, false );
	$sdpr_review_cart->sync_cart_reservations( $cart );
	sdpr_review_assert( 4 === sdpr_review_stock( $product_id ) && 3 === $cart->cart_contents[ $key ]['quantity'], 'Editing cart quantity does not mutate held stock.' );
	$cart->remove_cart_item( $key );
	sdpr_review_assert( 4 === sdpr_review_stock( $product_id ) && SDPR_Reservation_Status::ACTIVE === SDPR_Reservation_Meta::get( $hold, SDPR_Reservation_Meta::STATUS ), 'Cart removal leaves the independent reservation active.' );
	$key = $cart->add_to_cart( $product_id, 2 );
	sdpr_review_assert( $hold === (int) $cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ], 'Hold before cart attaches at add-to-cart.' );
	$sdpr_review_service->cancel_reservation( $hold );
	$sdpr_review_cart->sync_cart_reservations( $cart );
	sdpr_review_assert( empty( $cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ] ) && 5 === sdpr_review_stock( $product_id ), 'Cancellation removes stale cart linkage and restores stock once.' );
	$hold = sdpr_review_hold( $product_id, $user_id );
	$sdpr_review_cart->sync_cart_reservations( $cart );
	SDPR_Reservation_Meta::update( $hold, SDPR_Reservation_Meta::EXPIRES_AT, time() - 1 );
	$sdpr_review_service->expire_old_reservations();
	$sdpr_review_cart->sync_cart_reservations( $cart );
	sdpr_review_assert( empty( $cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ] ) && 5 === sdpr_review_stock( $product_id ), 'Abandoned cart hold expires and unlinks without duplicate stock restoration.' );
	$cart->empty_cart();

	foreach ( array( 'cancelled', 'failed' ) as $status ) {
		$product_id = sdpr_review_product();
		$hold = sdpr_review_hold( $product_id, $user_id );
		list( $order ) = sdpr_review_order( $product_id, $hold, $user_id, 3 );
		$order->update_status( 'processing' );
		sdpr_review_assert( 2 === sdpr_review_stock( $product_id ), $status . ': only the two extra unreserved units reduce at transfer.' );
		$order->update_status( $status );
		sdpr_review_assert( 5 === sdpr_review_stock( $product_id ), $status . ': full held plus extra order quantity restores once.' );
		$sdpr_review_cart->restore_transferred_order_stock( $order->get_id() );
		sdpr_review_assert( 5 === sdpr_review_stock( $product_id ), $status . ': repeated restoration is idempotent.' );
	}
	foreach ( array( 'partial-restock', 'full-restock', 'no-restock' ) as $mode ) {
		$product_id = sdpr_review_product();
		$hold = sdpr_review_hold( $product_id, $user_id );
		list( $order, $item_id ) = sdpr_review_order( $product_id, $hold, $user_id, 2 );
		$order->update_status( 'processing' );
		$qty = 'partial-restock' === $mode ? 1 : 2;
		$restock = 'no-restock' !== $mode;
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 10 * $qty, 'line_items' => array( $item_id => array( 'qty' => $qty, 'refund_total' => 10 * $qty, 'refund_tax' => array() ) ), 'refund_payment' => false, 'restock_items' => $restock ) );
		sdpr_review_assert( ! is_wp_error( $refund ), $mode . ': WooCommerce creates the real local refund.' );
		if ( ! is_wp_error( $refund ) ) {
			$sdpr_review_objects['orders'][] = $refund->get_id();
		}
		sdpr_review_assert( ( $restock ? 3 + $qty : 3 ) === sdpr_review_stock( $product_id ), $mode . ': WooCommerce alone controls requested refund restocking.' );
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );
		sdpr_review_assert( 5 === sdpr_review_stock( $product_id ), $mode . ': later cancellation never double-restores refunded units.' );
		$sdpr_review_cart->restore_transferred_order_stock( $order->get_id() );
		sdpr_review_assert( 5 === sdpr_review_stock( $product_id ), $mode . ': repeated cancellation restoration remains idempotent.' );
	}

	// The privacy eraser works on a shrinking set while retaining live holds.
	$privacy = $sdpr_review_plugin->get_service( 'privacy' );
	$email = get_userdata( $user_id )->user_email;
	$closed = array();
	$product_id = sdpr_review_product();
	for ( $i = 0; $i < 102; ++$i ) {
		$id = wp_insert_post( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'post_author' => $user_id, 'post_title' => 'SDPR privacy fixture' ) );
		$closed[] = $id;
		$sdpr_review_objects['reservations'][] = $id;
		foreach ( array( SDPR_Reservation_Meta::PRODUCT_ID => $product_id, SDPR_Reservation_Meta::STATUS => 'expired', SDPR_Reservation_Meta::EMAIL => $email, SDPR_Reservation_Meta::NAME => 'Review', SDPR_Reservation_Meta::DENIAL_REASON => 'Private reason' ) as $meta => $value ) {
			SDPR_Reservation_Meta::update( $id, $meta, $value );
		}
	}
	$active = sdpr_review_hold( $product_id, $user_id );
	$foreign = sdpr_review_hold( sdpr_review_product(), $other_id );
	$page1 = $privacy->export_personal_data( $email, 1 );
	$page2 = $privacy->export_personal_data( $email, 2 );
	sdpr_review_assert( 100 === count( $page1['data'] ) && ! $page1['done'] && ! empty( $page2['data'] ) && $page2['done'], 'Privacy export paginates past 100 records.' );
	$exported = array_merge( wp_list_pluck( $page1['data'], 'item_id' ), wp_list_pluck( $page2['data'], 'item_id' ) );
	sdpr_review_assert( ! in_array( 'sdpr-reservation-' . $foreign, $exported, true ), 'Privacy export excludes another customer.' );
	$erase1 = $privacy->erase_personal_data( $email, 1 );
	$erase2 = $privacy->erase_personal_data( $email, 2 );
	sdpr_review_assert( ! $erase1['done'] && $erase2['done'] && $erase2['items_retained'], 'Privacy erasure completes shrinking batches and reports retained open holds.' );
	sdpr_review_assert( $user_id === (int) get_post_field( 'post_author', $active ) && 4 === sdpr_review_stock( $product_id ), 'Privacy erasure preserves open inventory obligation and its owner.' );
	sdpr_review_assert( $other_id === (int) get_post_field( 'post_author', $foreign ), 'Privacy erasure does not change another customer.' );
	sdpr_review_assert( 0 === (int) get_post_field( 'post_author', $closed[101] ) && '' === SDPR_Reservation_Meta::get( $closed[101], SDPR_Reservation_Meta::NAME ) && '' === SDPR_Reservation_Meta::get( $closed[101], SDPR_Reservation_Meta::DENIAL_REASON ), 'Last privacy batch anonymizes author and free text.' );
	$sdpr_review_service->cancel_reservation( $active );
	$privacy->erase_personal_data( $email, 3 );
	sdpr_review_assert( 0 === (int) get_post_field( 'post_author', $active ) && 5 === sdpr_review_stock( $product_id ), 'Closed former hold becomes erasable without losing stock.' );

	// Customer-mode and email-mode queries use the same paginated list backend.
	require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-reservations.php';
	$admin = new SDPR_Admin_Reservations( $sdpr_review_service );
	$query = new ReflectionMethod( $admin, 'get_filtered_reservations' );
	$query->setAccessible( true );
	$search_hold = sdpr_review_hold( sdpr_review_product(), $user_id );
	foreach ( array( 'sdpr-review-' . $token, 'Review Display ' . $token, $email ) as $needle ) {
		$found = $query->invoke( $admin, 'all', $needle, 'customer_name', 1 );
		sdpr_review_assert( in_array( $search_hold, wp_list_pluck( $found->posts, 'ID' ), true ), 'Customer search matches ' . $needle . '.' );
		sdpr_review_assert( ! in_array( $foreign, wp_list_pluck( $found->posts, 'ID' ), true ), 'Customer search excludes unrelated customer for ' . $needle . '.' );
	}
	$guest = wp_insert_post( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'post_author' => 0, 'post_title' => 'SDPR guest fixture' ) );
	$sdpr_review_objects['reservations'][] = $guest;
	foreach ( array( SDPR_Reservation_Meta::STATUS => 'denied', SDPR_Reservation_Meta::NAME => 'GuestName' . $token, SDPR_Reservation_Meta::SURNAME => 'GuestSurname' . $token, SDPR_Reservation_Meta::EMAIL => 'guest-' . $token . '@example.test' ) as $meta => $value ) {
		SDPR_Reservation_Meta::update( $guest, $meta, $value );
	}
	foreach ( array( 'GuestName' . $token, 'GuestSurname' . $token ) as $needle ) {
		$found = $query->invoke( $admin, 'denied', $needle, 'customer_name', 1 );
		sdpr_review_assert( in_array( $guest, wp_list_pluck( $found->posts, 'ID' ), true ), 'Customer search matches guest ' . $needle . '.' );
	}
	$found = $query->invoke( $admin, 'denied', 'guest-' . $token . '@example.test', 'email', 1 );
	sdpr_review_assert( in_array( $guest, wp_list_pluck( $found->posts, 'ID' ), true ), 'Email search matches guest records.' );
	$found = $query->invoke( $admin, 'active', 'guest-' . $token . '@example.test', 'email', 1 );
	sdpr_review_assert( 0 === (int) $found->found_posts, 'Status filter excludes mismatching guest records.' );
	$guest_export = $privacy->export_personal_data( 'guest-' . $token . '@example.test' );
	sdpr_review_assert( 1 === count( $guest_export['data'] ) && 'sdpr-reservation-' . $guest === $guest_export['data'][0]['item_id'], 'Guest privacy export is scoped to its email identity.' );
	$privacy->erase_personal_data( 'guest-' . $token . '@example.test' );
	sdpr_review_assert( '' === SDPR_Reservation_Meta::get( $guest, SDPR_Reservation_Meta::NAME ) && 'guest-' . $token . '@example.test' !== SDPR_Reservation_Meta::get( $guest, SDPR_Reservation_Meta::EMAIL ), 'Guest erasure removes its name and anonymizes its email.' );
	wp_update_user( array( 'ID' => $other_id, 'display_name' => 'Pagination ' . $token ) );
	for ( $i = 0; $i < 30; ++$i ) {
		$id = wp_insert_post( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'post_author' => $other_id, 'post_title' => 'SDPR pagination fixture' ) );
		$sdpr_review_objects['reservations'][] = $id;
		SDPR_Reservation_Meta::update( $id, SDPR_Reservation_Meta::STATUS, 'denied' );
	}
	$first = $query->invoke( $admin, 'denied', 'Pagination ' . $token, 'customer_name', 1 );
	$second = $query->invoke( $admin, 'denied', 'Pagination ' . $token, 'customer_name', 2 );
	sdpr_review_assert( 30 === (int) $first->found_posts && 25 === count( $first->posts ) && 5 === count( $second->posts ) && 2 === (int) $first->max_num_pages, 'Admin search pagination reports correct totals and page sizes.' );
	sdpr_review_assert( ! array_intersect( wp_list_pluck( $first->posts, 'ID' ), wp_list_pluck( $second->posts, 'ID' ) ), 'Admin search pages do not duplicate records.' );

	$lifecycle = $sdpr_review_plugin->get_service( 'lifecycle' );
	$product_id = sdpr_review_product();
	sdpr_review_assert( is_wp_error( $lifecycle->request( $product_id, 0 ) ), 'Account request rejects logged-out identity.' );
	foreach ( array( 'unmanaged', 'out-of-stock', 'draft', 'unpriced' ) as $case ) {
		$product = wc_get_product( sdpr_review_product() );
		if ( 'unmanaged' === $case ) { $product->set_manage_stock( false ); }
		if ( 'out-of-stock' === $case ) { $product->set_stock_quantity( 0 ); }
		if ( 'draft' === $case ) { $product->set_status( 'draft' ); }
		if ( 'unpriced' === $case ) { $product->set_regular_price( '' ); }
		$product->save();
		sdpr_review_assert( is_wp_error( $lifecycle->request( $product->get_id(), $user_id ) ), $case . ' products cannot receive reservations.' );
	}
	$hold = sdpr_review_hold( $product_id, $user_id );
	sdpr_review_assert( is_wp_error( $lifecycle->request( $product_id, $user_id ) ) && 4 === sdpr_review_stock( $product_id ), 'Duplicate request is rejected without another stock hold.' );
	wp_set_current_user( $other_id );
	sdpr_review_assert( 4 === (int) wc_get_product( $product_id )->get_stock_quantity(), 'Another customer does not receive owner stock allowance.' );
	$options = get_option( 'sdpr_options' );
	$options['max_reservations'] = 1;
	update_option( 'sdpr_options', $options );
	sdpr_review_assert( is_wp_error( $lifecycle->request( sdpr_review_product(), $other_id ) ), 'Open-reservation limit cannot be bypassed with another product.' );
	$variable = new WC_Product_Variable();
	$variable->set_name( 'SDPR unsupported variable fixture' );
	$variable->set_status( 'publish' );
	$sdpr_review_objects['products'][] = $variable->save();
	sdpr_review_assert( ! $sdpr_review_plugin->get_service( 'rules' )->supports_inventory( $variable ), 'Free inventory rules reject unsupported variable parents.' );
} finally {
	wp_set_current_user( 0 );
	foreach ( array_reverse( $sdpr_review_objects['orders'] ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) { $order->delete( true ); }
	}
	foreach ( $sdpr_review_objects['reservations'] as $id ) { wp_delete_post( $id, true ); }
	foreach ( $sdpr_review_objects['products'] as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $sdpr_review_objects['users'] as $id ) { wp_delete_user( $id ); }
	if ( false === $sdpr_review_options ) { delete_option( 'sdpr_options' ); } else { update_option( 'sdpr_options', $sdpr_review_options ); }
	wp_set_current_user( $sdpr_review_user );
}
if ( $sdpr_review_failures ) { exit( 1 ); }
echo 'All ' . $sdpr_review_count . " review coverage assertions passed.\n";

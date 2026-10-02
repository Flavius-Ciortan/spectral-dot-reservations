<?php
/** Two independent WP-CLI processes share explicit barriers, never a mocked transition. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_TRANSITION_RACE_TEST' ) ) { exit( 1 ); }
$phase = getenv( 'SDPR_RACE_PHASE' );
$case = getenv( 'SDPR_RACE_CASE' );
$pairs = array(
	'approve-cancel' => array( 'approve', 'cancel', 'active', 4 ),
	'cancel-approve' => array( 'cancel', 'approve', 'cancelled', 5 ),
	'approve-expire' => array( 'approve', 'expire', 'expired', 5 ),
	'cancel-expire' => array( 'cancel', 'expire', 'expired', 5 ),
	'transfer-expire' => array( 'transfer', 'expire', 'expired', 5 ),
	'transfer-cancel' => array( 'transfer', 'cancel', 'cancelled', 5 ),
	'expire-transfer' => array( 'expire', 'transfer', 'fulfilled', 4 ),
	'transfer-transfer' => array( 'transfer', 'transfer', 'fulfilled', 4 ),
);
if ( ! isset( $pairs[ $case ] ) ) { exit( 1 ); }
$plugin = SDPR_Plugin::get_instance();
$context = get_option( 'sdpr_transition_race_context', array() );
if ( 'setup' === $phase ) {
	$options = get_option( 'sdpr_options', false );
	update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 5, 'reservation_duration' => 24, 'require_admin_approval' => false !== strpos( $case, 'approve' ) ? 1 : 0, 'pending_duration' => 1, 'enable_email_notifications' => 0 ) );
	$user = wp_insert_user( array( 'user_login' => 'sdpr-race-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'SDPR transition race' );
	$product->set_status( 'publish' );
	$product->set_regular_price( 10 );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 5 );
	$product_id = $product->save();
	$hold = $plugin->reservations->create_reservation( $product_id, $user );
	$orders = array();
	foreach ( array( 'holder', 'contender' ) as $role ) {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item_id = $order->add_product( wc_get_product( $product_id ), 1 );
		$item = $order->get_item( $item_id );
		$item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, $hold, true );
		$item->save();
		$order->save();
		$orders[ $role ] = $order->get_id();
	}
	update_option( 'sdpr_transition_race_context', array( 'case' => $case, 'options' => $options, 'user' => $user, 'product' => $product_id, 'hold' => $hold, 'orders' => $orders ), false );
	foreach ( array( 'ready', 'done', 'holder', 'contender' ) as $key ) { delete_option( 'sdpr_transition_race_' . $key ); }
	echo "PASS: Race setup $case.\n";
	exit;
}
if ( empty( $context['hold'] ) || $context['case'] !== $case ) { exit( 1 ); }
if ( 'holder' === $phase || 'contender' === $phase ) {
	wp_set_current_user( $context['user'] );
	if ( 'holder' === $phase ) {
		add_filter( 'sdpr_reservation_transition_allowed', static function ( $allowed, $id ) use ( $context ) {
			if ( (int) $id !== (int) $context['hold'] ) { return $allowed; }
			update_option( 'sdpr_transition_race_ready', 'yes', false );
			global $wpdb;
			$deadline = microtime( true ) + 30;
			while ( microtime( true ) < $deadline ) {
				$done = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sdpr_transition_race_done' ) );
				if ( 'yes' === $done ) { return $allowed; }
				usleep( 50000 );
			}
			throw new RuntimeException( 'Race barrier timed out.' );
		}, 10, 2 );
	}
	$action = $pairs[ $case ][ 'holder' === $phase ? 0 : 1 ];
	try {
		if ( 'approve' === $action ) { $result = $plugin->reservations->approve_reservation( $context['hold'] ); }
		if ( 'cancel' === $action ) { $result = $plugin->reservations->cancel_reservation( $context['hold'] ); }
		if ( 'expire' === $action ) { $result = $plugin->get_service( 'expiration' )->expire_reservation( $context['hold'] ); }
		if ( 'transfer' === $action ) { $plugin->get_service( 'cart_order' )->transfer_holds_to_order( wc_get_order( $context['orders'][ $phase ] ) ); $result = true; }
		$success = true === $result;
	} catch ( Throwable $error ) {
		$success = false;
	}
	update_option( 'sdpr_transition_race_' . $phase, $success ? 'yes' : 'no', false );
	if ( 'contender' === $phase ) { update_option( 'sdpr_transition_race_done', 'yes', false ); }
	echo "PASS: $phase $action completed (" . ( $success ? 'applied' : 'safely rejected' ) . ").\n";
	exit;
}
if ( 'verify' === $phase ) {
	$status = SDPR_Reservation_Meta::get( $context['hold'], SDPR_Reservation_Meta::STATUS );
	$stock = (int) wc_get_product( $context['product'] )->get_stock_quantity( 'edit' );
	$applied = ( 'yes' === get_option( 'sdpr_transition_race_holder' ) ? 1 : 0 ) + ( 'yes' === get_option( 'sdpr_transition_race_contender' ) ? 1 : 0 );
	$valid = $pairs[ $case ][2] === $status && $pairs[ $case ][3] === $stock && 1 === $applied;
	foreach ( array( 'product_' . $context['product'], 'user_' . $context['user'] ) as $key ) { $valid = $valid && false === get_option( 'sdpr_lock_' . $key, false ); }
	echo ( $valid ? 'PASS: ' : 'FAIL: ' ) . "$case: status=$status, stock=$stock, successful transitions=$applied, locks released.\n";
	if ( ! $valid ) { exit( 1 ); }
}
if ( 'cleanup' === $phase ) {
	foreach ( $context['orders'] as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	wp_delete_post( $context['hold'], true );
	wp_delete_post( $context['product'], true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $context['user'] );
	if ( false === $context['options'] ) { delete_option( 'sdpr_options' ); } else { update_option( 'sdpr_options', $context['options'] ); }
	foreach ( array( 'context', 'ready', 'done', 'holder', 'contender' ) as $key ) { delete_option( 'sdpr_transition_race_' . $key ); }
}

<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST' ) ) {
	exit( 1 );
}

update_option(
	'sdpr_options',
	array(
		'enable_reservation'         => 1,
		'max_reservations'           => 5,
		'reservation_duration'       => 24,
		'pending_duration'           => 1,
		'require_admin_approval'     => 0,
		'enable_email_notifications' => 0,
	),
	false
);

$user_id = wp_insert_user(
	array(
		'user_login' => 'sdpr-release-lifecycle-' . wp_generate_password( 8, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'sdpr-release-lifecycle@example.test',
		'role'       => 'customer',
	)
);
$product = new WC_Product_Simple();
$product->set_name( 'SDPR release lifecycle product' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 2 );
$product_id = $product->save();
$reserve_product_id = $product_id;
if ( '1' === getenv( 'SDPR_RELEASE_VARIATION' ) ) {
	wp_delete_post( $product_id, true );
	$product = new WC_Product_Variable();
	$product->set_name( 'Lifecycle shared-stock variation parent' );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 2 );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Finish' );
	$attribute->set_options( array( 'Midnight' ) );
	$attribute->set_variation( true );
	$product->set_attributes( array( $attribute ) );
	$product_id = $product->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $product_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( 10 );
	$variation->set_manage_stock( false );
	$variation->set_attributes( array( 'finish' => 'Midnight' ) );
	$reserve_product_id = $variation->save();
	WC_Product_Variable::sync( $product_id );
}

if ( is_wp_error( $user_id ) || ! $product_id ) {
	exit( 1 );
}

wp_set_current_user( $user_id );
$reservation_id = SDPR_Plugin::get_instance()->get_service( 'lifecycle' )->create( $reserve_product_id, $user_id );
if ( ! $reservation_id || 1 !== (int) wc_get_product( $product_id )->get_stock_quantity( 'edit' ) ) {
	exit( 1 );
}

echo 'PRODUCT_ID=' . (int) $product_id . "\n";
echo 'USER_ID=' . (int) $user_id . "\n";
echo 'RESERVATION_ID=' . (int) $reservation_id . "\n";
echo 'VARIATION_ID=' . ( $reserve_product_id !== $product_id ? (int) $reserve_product_id : 0 ) . "\n";

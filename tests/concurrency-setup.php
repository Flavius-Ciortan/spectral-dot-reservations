<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

delete_option( 'sdpr_stress_context' );
delete_option( 'sdpr_stress_result_holder' );
delete_option( 'sdpr_stress_result_contender' );
$original_options = get_option( 'sdpr_options', '__sdpr_missing_option__' );

$product = new WC_Product_Simple();
$product->set_name( 'SDPR concurrency stress product' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 1 );
$product_id = $product->save();
$variation_ids = array();
if ( '1' === getenv( 'SDPR_STRESS_VARIATIONS' ) ) {
	wp_delete_post( $product_id, true );
	$product = new WC_Product_Variable();
	$product->set_name( 'Shared inventory race' );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 1 );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Finish' );
	$attribute->set_options( array( 'Blue', 'White' ) );
	$attribute->set_variation( true );
	$product->set_attributes( array( $attribute ) );
	$product_id = $product->save();
	foreach ( array( 'Blue', 'White' ) as $finish ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( 10 );
		$variation->set_attributes( array( 'finish' => $finish ) );
		$variation->set_manage_stock( false );
		$variation_ids[] = $variation->save();
	}
	WC_Product_Variable::sync( $product_id );
}

$holder_id = wp_insert_user(
	array(
		'user_login' => 'sdpr-stress-holder-' . wp_generate_password( 8, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'sdpr-stress-holder@example.test',
		'role'       => 'customer',
	)
);
$contender_id = wp_insert_user(
	array(
		'user_login' => 'sdpr-stress-contender-' . wp_generate_password( 8, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'sdpr-stress-contender@example.test',
		'role'       => 'customer',
	)
);

if ( ! $product_id || is_wp_error( $holder_id ) || is_wp_error( $contender_id ) ) {
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
	)
);
update_option(
	'sdpr_stress_context',
	array(
		'product_id'   => $product_id,
		'holder_id'    => $holder_id,
		'contender_id' => $contender_id,
		'options'      => $original_options,
		'variations'   => $variation_ids,
	),
	false
);

echo 'PRODUCT_ID=' . (int) $product_id . "\n";

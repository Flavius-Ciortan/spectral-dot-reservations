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
	),
	false
);

echo 'PRODUCT_ID=' . (int) $product_id . "\n";

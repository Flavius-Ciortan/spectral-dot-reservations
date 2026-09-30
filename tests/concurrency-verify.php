<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$context   = get_option( 'sdpr_stress_context', array() );
$holder    = get_option( 'sdpr_stress_result_holder', array() );
$contender = get_option( 'sdpr_stress_result_contender', array() );
$product   = ! empty( $context['product_id'] ) ? wc_get_product( (int) $context['product_id'] ) : false;
$active    = get_posts(
	array(
		'post_type'      => 'sdpr_reservation',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'   => SDPR_Reservation_Meta::PRODUCT_ID,
				'value' => (int) $context['product_id'],
			),
			array(
				'key'   => SDPR_Reservation_Meta::STATUS,
				'value' => SDPR_Reservation_Status::ACTIVE,
			),
		),
	)
);

$valid = ! empty( $holder['success'] )
	&& empty( $contender['success'] )
	&& 'sdpr_busy' === ( $contender['error_code'] ?? '' )
	&& 1 === count( $active )
	&& $product
	&& 0 === (int) $product->get_stock_quantity( 'edit' );

if ( ! $valid ) {
	echo 'FAIL: ' . wp_json_encode(
		array(
			'holder'      => $holder,
			'contender'   => $contender,
			'active_count' => count( $active ),
			'stock'        => $product ? $product->get_stock_quantity( 'edit' ) : null,
		)
	) . "\n";
	exit( 1 );
}

echo "PASS: Concurrent last-unit requests create one hold and decrement stock once.\n";

<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_BROWSER_CHECKOUT_TEST' ) ) {
	exit( 1 );
}

$mode       = sanitize_key( (string) getenv( 'SDPR_CHECKOUT_MODE' ) );
$context    = get_option( 'sdpr_browser_checkout_context', array() );
$product_id = ! empty( $context[ $mode . '_product' ] ) ? (int) $context[ $mode . '_product' ] : 0;
$product    = wc_get_product( $product_id );
$ids        = get_posts(
	array(
		'post_type'      => 'sdpr_reservation',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => array(
			array(
				'key'   => SDPR_Reservation_Meta::PRODUCT_ID,
				'value' => $product_id,
			),
		),
	)
);
$reservation_id = $ids ? (int) $ids[0] : 0;
$order_id        = $reservation_id ? (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::ORDER_ID ) : 0;
$order           = $order_id ? wc_get_order( $order_id ) : false;
$valid           = $product
	&& $reservation_id
	&& SDPR_Reservation_Status::FULFILLED === SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS )
	&& SDPR_Inventory_Manager::STATE_TRANSFERRED === SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::INVENTORY_STATE )
	&& 1 === (int) $product->get_stock_quantity( 'edit' )
	&& $order
	&& (int) $context['user_id'] === (int) $order->get_customer_id();

if ( ! $valid ) {
	exit( 1 );
}

echo 'PASS: ' . $mode . " checkout fulfilled its reservation without a second stock decrement.\n";

<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST' ) ) {
	exit( 1 );
}

$product_id     = (int) getenv( 'SDPR_RELEASE_PRODUCT_ID' );
$reservation_id = (int) getenv( 'SDPR_RELEASE_RESERVATION_ID' );
$product        = wc_get_product( $product_id );
$valid          = $product
	&& 'sdpr_reservation' === get_post_type( $reservation_id )
	&& 'active' === get_post_meta( $reservation_id, '_sdpr_status', true )
	&& 1 === (int) $product->get_stock_quantity( 'edit' )
	&& ! wp_next_scheduled( 'sdpr_expire_reservations' );

if ( ! $valid ) {
	exit( 1 );
}

echo "PASS: Deactivation preserves held inventory data and clears scheduling.\n";

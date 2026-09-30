<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST' ) ) {
	exit( 1 );
}

$product_id     = (int) getenv( 'SDPR_RELEASE_PRODUCT_ID' );
$reservation_id = (int) getenv( 'SDPR_RELEASE_RESERVATION_ID' );
$product        = wc_get_product( $product_id );
$valid          = $product
	&& 2 === (int) $product->get_stock_quantity( 'edit' )
	&& ! get_post( $reservation_id )
	&& false === get_option( 'sdpr_options', false )
	&& false === get_option( 'sdpr_version', false )
	&& false === get_option( 'sdpr_inventory_state_version', false )
	&& ! wp_next_scheduled( 'sdpr_expire_reservations' );

if ( ! $valid ) {
	exit( 1 );
}

echo "PASS: Uninstall restores held stock once and removes all Free-owned state.\n";

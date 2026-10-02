<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST' ) ) {
	exit( 1 );
}

$product_id     = (int) getenv( 'SDPR_RELEASE_PRODUCT_ID' );
$reservation_id = (int) getenv( 'SDPR_RELEASE_RESERVATION_ID' );
$product        = wc_get_product( $product_id );
$plugin         = SDPR_Plugin::get_instance();
$valid          = $plugin->get_service( 'lifecycle' ) instanceof SDPR_Reservation_Lifecycle_Interface
	&& $product
	&& 'sdpr_reservation' === get_post_type( $reservation_id )
	&& 1 === (int) $product->get_stock_quantity( 'edit' )
	&& (bool) wp_next_scheduled( SDPR_Reservations::CRON_HOOK );

if ( ! $valid ) {
	exit( 1 );
}

$original_timezone = get_option( 'timezone_string', '' );
$original_offset   = get_option( 'gmt_offset', 0 );
$expires = (int) get_post_meta( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT, true );
try {
	update_option( 'timezone_string', 'Europe/Bucharest' );
	update_option( 'gmt_offset', 3 );
	$valid = 'utc' === get_post_meta( $reservation_id, SDPR_Reservation_Meta::TIMESTAMP_MODEL, true )
		&& SDPR_VERSION === get_option( 'sdpr_version' )
		&& $expires > time()
		&& $expires === (int) get_post_meta( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT, true )
		&& SDPR_Inventory_Manager::STATE_HELD === get_post_meta( $reservation_id, SDPR_Reservation_Meta::INVENTORY_STATE, true )
		&& 1 === (int) wc_get_product( $product_id )->get_stock_quantity( 'edit' );
} finally {
	update_option( 'timezone_string', $original_timezone );
	update_option( 'gmt_offset', $original_offset );
}
if ( ! $valid ) {
	exit( 1 );
}

echo "PASS: Reactivation preserves UTC deadlines and explicit stock ownership across timezone changes.\n";

<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stable reservation transition contract for compatible add-ons. */
interface SDPR_Reservation_Lifecycle_Interface {
	public function request( $product_id, $user_id, $requested_quantity = 1 );

	public function request_guest( $product_id, $guest_email, $requested_quantity = 1 );

	public function create( $product_id, $user_id = 0, $guest_email = '', $quantity = 1 );

	public function approve( $reservation_id );

	public function deny( $reservation_id, $reason = '' );

	public function cancel( $reservation_id );

	public function extend( $reservation_id, $additional_hours, $source = 'extension' );
}

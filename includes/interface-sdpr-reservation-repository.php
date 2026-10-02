<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stable persistence query contract for reservation extensions. */
interface SDPR_Reservation_Repository_Interface {
	public function find_active( $product_id, $user_id );

	public function count_active( $user_id = 0, $email = '' );

	public function has_active( $product_id, $user_id = 0, $email = '' );

	public function count_open( $user_id, $email = '' );

	public function user_has_open_for_product( $product_id, $user_id, $email = '' );

	public function get_status_counts();
}

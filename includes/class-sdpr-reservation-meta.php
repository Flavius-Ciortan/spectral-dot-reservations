<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical reservation metadata keys and accessors. */
final class SDPR_Reservation_Meta {
	const PRODUCT_ID         = '_sdpr_product_id';
	const STATUS             = '_sdpr_status';
	const EXPIRES_AT         = '_sdpr_expires_at';
	const QUANTITY           = '_sdpr_qty';
	const EMAIL              = '_sdpr_email';
	const NAME               = '_sdpr_name';
	const SURNAME            = '_sdpr_surname';
	const TIMESTAMP_MODEL    = '_sdpr_timestamp_model';
	const INVENTORY_STATE    = '_sdpr_inventory_state';
	const ORDER_ID           = '_sdpr_order_id';
	const EXPIRED_FROM       = '_sdpr_expired_from';
	const DENIAL_REASON      = '_sdpr_denial_reason';
	const CANCELLED_BY_ADMIN = '_sdpr_cancelled_by_admin';
	const CANCELLED_BY_USER  = '_sdpr_cancelled_by_user';
	const LINKED_RESERVATION = '_sdpr_reservation_id';
	const HOLDS_TRANSFERRED  = '_sdpr_holds_transferred';

	public static function get( $reservation_id, $key, $single = true ) {
		return get_post_meta( absint( $reservation_id ), $key, $single );
	}

	public static function update( $reservation_id, $key, $value, $previous_value = '' ) {
		return update_post_meta( absint( $reservation_id ), $key, $value, $previous_value );
	}

	public static function delete( $reservation_id, $key ) {
		return delete_post_meta( absint( $reservation_id ), $key );
	}
}

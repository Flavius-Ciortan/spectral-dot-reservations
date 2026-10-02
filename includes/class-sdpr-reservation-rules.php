<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves Free defaults through stable filters that paid modules can extend. */
final class SDPR_Reservation_Rules {
	public function supports_inventory( $product ) {
		$supported = $product instanceof WC_Product
			&& $product->is_type( 'simple' )
			&& $product->managing_stock();

		return (bool) apply_filters( 'sdpr_product_supports_reservation_inventory', $supported, $product );
	}

	public function is_product_reservable( $product_id, $user_id = 0, $guest_email = '' ) {
		if ( ! $this->are_reservations_globally_enabled() ) {
			return false;
		}

		$guest_email = sanitize_email( $guest_email );
		$user_id     = absint( $guest_email ? $user_id : ( $user_id ? $user_id : get_current_user_id() ) );
		$guest       = ! $user_id && $guest_email && is_email( $guest_email );
		$guest_ui    = ! $user_id && ! $guest && apply_filters( 'sdpr_guest_reservation_frontend_enabled', false );
		if ( ! $user_id && ! $guest_ui && ( ! $guest || ! apply_filters( 'sdpr_allow_guest_reservations', false, $product_id, $guest_email ) ) ) {
			return false;
		}

		$product    = wc_get_product( absint( $product_id ) );
		$reservable = $product instanceof WC_Product
			&& $this->supports_inventory( $product )
			&& $product->is_purchasable()
			&& 'publish' === get_post_status( $product_id )
			&& (int) $product->get_stock_quantity( 'edit' ) > 0;

		return (bool) apply_filters( 'sdpr_product_is_reservable', $reservable, $product, $user_id, $guest_email );
	}

	public function get_quantity( $requested_quantity, $product_id, $user_id ) {
		$quantity = apply_filters(
			'sdpr_reservation_quantity',
			1,
			absint( $requested_quantity ),
			absint( $product_id ),
			absint( $user_id )
		);
		return max( 1, min( 10000, absint( $quantity ) ) );
	}

	public function are_reservations_globally_enabled() {
		$options = $this->get_options();
		return ! empty( $options['enable_reservation'] );
	}

	public function get_max_reservations_per_user( $user_id = 0, $product_id = 0 ) {
		$options = $this->get_options();
		$limit   = max( 1, min( 100, absint( $options['max_reservations'] ) ) );
		return max( 1, absint( apply_filters( 'sdpr_customer_reservation_limit', $limit, absint( $user_id ? $user_id : get_current_user_id() ), absint( $product_id ) ) ) );
	}

	public function requires_approval( $product_id, $user_id ) {
		$options  = $this->get_options();
		$required = ! empty( $options['require_admin_approval'] );
		return (bool) apply_filters( 'sdpr_reservation_requires_approval', $required, absint( $product_id ), absint( $user_id ) );
	}

	public function get_duration_hours( $context, $product_id = 0, $user_id = 0 ) {
		$options    = $this->get_options();
		$context    = 'pending' === $context ? 'pending' : 'active';
		$option_key = 'pending' === $context ? 'pending_duration' : 'reservation_duration';
		$duration   = max( 1, min( 168, absint( $options[ $option_key ] ) ) );
		return max( 1, absint( apply_filters( 'sdpr_reservation_duration_hours', $duration, $context, absint( $product_id ), absint( $user_id ) ) ) );
	}

	private function get_options() {
		$options = get_option( 'sdpr_options', array() );
		$options = is_array( $options ) ? $options : array();
		return wp_parse_args(
			$options,
			array(
				'enable_reservation'         => 0,
				'max_reservations'           => 1,
				'reservation_duration'       => 24,
				'pending_duration'           => 24,
				'require_admin_approval'     => 0,
				'enable_email_notifications' => 0,
			)
		);
	}
}

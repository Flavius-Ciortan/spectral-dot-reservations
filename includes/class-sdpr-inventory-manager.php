<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomically coordinates reservation status and WooCommerce stock ownership.
 *
 * WordPress post meta and WooCommerce product stock share the WordPress database
 * connection. Keeping both writes in one transaction prevents a terminal status
 * from being committed when its required stock operation fails.
 */
final class SDPR_Inventory_Manager {
	const META_STATE = SDPR_Reservation_Meta::INVENTORY_STATE;

	const STATE_NONE        = 'none';
	const STATE_HELD        = 'held';
	const STATE_TRANSFERRED = 'transferred';
	const STATE_RELEASED    = 'released';

	public function initialize( $reservation_id, $state = self::STATE_NONE ) {
		return update_post_meta( absint( $reservation_id ), self::META_STATE, sanitize_key( $state ) );
	}

	public function activate( $reservation_id, $from_status, $product, $quantity = 1, $meta_updates = array(), $source = 'activate' ) {
		return $this->transition(
			$reservation_id,
			$from_status,
			SDPR_Reservation_Status::ACTIVE,
			$product,
			max( 1, absint( $quantity ) ),
			'decrease',
			self::STATE_NONE,
			self::STATE_HELD,
			$meta_updates,
			$source
		);
	}

	public function release( $reservation_id, $from_status, $to_status, $product = null, $quantity = 1, $source = 'release' ) {
		$state = $this->get_state( $reservation_id, $from_status );
		if ( self::STATE_NONE === $state ) {
			return $this->transition( $reservation_id, $from_status, $to_status, null, 0, '', self::STATE_NONE, self::STATE_RELEASED, array(), $source );
		}

		return $this->transition(
			$reservation_id,
			$from_status,
			$to_status,
			$product,
			max( 1, absint( $quantity ) ),
			'increase',
			self::STATE_HELD,
			self::STATE_RELEASED,
			array(),
			$source
		);
	}

	public function transfer_to_order( $reservation_id, $order_id, $product = null, $additional_quantity = 0 ) {
		$additional_quantity = absint( $additional_quantity );
		return $this->transition(
			$reservation_id,
			SDPR_Reservation_Status::ACTIVE,
			SDPR_Reservation_Status::FULFILLED,
			$additional_quantity ? $product : null,
			$additional_quantity,
			$additional_quantity ? 'decrease' : '',
			self::STATE_HELD,
			self::STATE_TRANSFERRED,
			array( SDPR_Reservation_Meta::ORDER_ID => absint( $order_id ) ),
			'order_transfer'
		);
	}

	public function rollback_order_transfer( $reservation_id, $product = null, $additional_quantity = 0 ) {
		$additional_quantity = absint( $additional_quantity );
		return $this->transition(
			$reservation_id,
			SDPR_Reservation_Status::FULFILLED,
			SDPR_Reservation_Status::ACTIVE,
			$additional_quantity ? $product : null,
			$additional_quantity,
			$additional_quantity ? 'increase' : '',
			self::STATE_TRANSFERRED,
			self::STATE_HELD,
			array( SDPR_Reservation_Meta::ORDER_ID => null ),
			'order_transfer_rollback'
		);
	}

	public function restore_cancelled_order( $reservation_id, $product, $quantity ) {
		$quantity = max( 0, (int) $quantity );
		return $this->transition(
			$reservation_id,
			SDPR_Reservation_Status::FULFILLED,
			SDPR_Reservation_Status::ORDER_CANCELLED,
			$quantity ? $product : null,
			$quantity,
			$quantity ? 'increase' : '',
			self::STATE_TRANSFERRED,
			self::STATE_RELEASED,
			array(),
			'order_cancelled'
		);
	}

	public function get_state( $reservation_id, $status = '' ) {
		$state = (string) get_post_meta( absint( $reservation_id ), self::META_STATE, true );
		if ( $state ) {
			return $state;
		}

		$status = $status ? $status : (string) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
		if ( SDPR_Reservation_Status::ACTIVE === $status ) {
			return self::STATE_HELD;
		}
		if ( SDPR_Reservation_Status::FULFILLED === $status ) {
			return self::STATE_TRANSFERRED;
		}
		if ( in_array( $status, array( SDPR_Reservation_Status::PENDING, SDPR_Reservation_Status::INITIALIZING ), true ) ) {
			return self::STATE_NONE;
		}
		return self::STATE_RELEASED;
	}

	/** Backfill explicit ownership for legacy records without changing stock. */
	public function backfill_missing_states( $limit = 500 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => max( 1, absint( $limit ) ),
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META_STATE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $reservation_id ) {
			$status = (string) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
			add_post_meta( $reservation_id, self::META_STATE, $this->get_state( $reservation_id, $status ), true );
		}
		return count( $ids );
	}

	/** Return records whose lifecycle status disagrees with inventory ownership. */
	public function find_inconsistent_states( $limit = 100 ) {
		$ids     = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => max( 1, absint( $limit ) ),
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => SDPR_Reservation_Meta::STATUS,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_STATE,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$invalid = array();
		foreach ( $ids as $reservation_id ) {
			$status = (string) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
			$state  = (string) get_post_meta( $reservation_id, self::META_STATE, true );
			if ( $state !== $this->expected_state( $status ) ) {
				$invalid[] = (int) $reservation_id;
			}
		}
		return $invalid;
	}

	private function expected_state( $status ) {
		if ( SDPR_Reservation_Status::ACTIVE === $status ) {
			return self::STATE_HELD;
		}
		if ( SDPR_Reservation_Status::FULFILLED === $status ) {
			return self::STATE_TRANSFERRED;
		}
		if ( in_array( $status, array( SDPR_Reservation_Status::PENDING, SDPR_Reservation_Status::INITIALIZING ), true ) ) {
			return self::STATE_NONE;
		}
		return self::STATE_RELEASED;
	}

	private function transition( $reservation_id, $from_status, $to_status, $product, $quantity, $stock_operation, $from_state, $to_state, $meta_updates = array(), $source = 'inventory' ) {
		global $wpdb;

		$reservation_id = absint( $reservation_id );
		if ( ! $reservation_id || 'sdpr_reservation' !== get_post_type( $reservation_id ) || ! SDPR_Reservation_Status::can_transition( $from_status, $to_status ) ) {
			return new WP_Error( 'sdpr_invalid_inventory_transition', __( 'Invalid reservation inventory transition.', 'spectral-dot-reservations' ) );
		}
		if ( ! apply_filters( 'sdpr_reservation_transition_allowed', true, $reservation_id, $from_status, $to_status, sanitize_key( $source ) ) ) {
			return new WP_Error( 'sdpr_transition_blocked', __( 'The reservation transition was blocked.', 'spectral-dot-reservations' ) );
		}
		if ( $stock_operation && ( ! $product instanceof WC_Product || ! $product->managing_stock() ) ) {
			return new WP_Error( 'sdpr_invalid_inventory_product', __( 'Product stock is not available for this reservation transition.', 'spectral-dot-reservations' ) );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		try {
			$current_status = (string) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
			$current_state  = $this->get_state( $reservation_id, $current_status );
			if ( $current_status !== $from_status || $current_state !== $from_state ) {
				throw new RuntimeException( __( 'Reservation changed during its inventory transition.', 'spectral-dot-reservations' ) );
			}

			if ( ! metadata_exists( 'post', $reservation_id, self::META_STATE ) ) {
				add_post_meta( $reservation_id, self::META_STATE, $from_state, true );
			}
			if ( ! SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::STATUS, $to_status, $from_status ) ) {
				throw new RuntimeException( __( 'Reservation status could not be updated.', 'spectral-dot-reservations' ) );
			}

			if ( $stock_operation ) {
				$new_stock = wc_update_product_stock( $product, $quantity, $stock_operation );
				if ( null === $new_stock || ( 'decrease' === $stock_operation && (int) $new_stock < 0 ) ) {
					throw new RuntimeException( __( 'Product stock changed during the reservation transition.', 'spectral-dot-reservations' ) );
				}
			}

			if ( ! update_post_meta( $reservation_id, self::META_STATE, $to_state, $from_state ) ) {
				throw new RuntimeException( __( 'Reservation inventory ownership could not be updated.', 'spectral-dot-reservations' ) );
			}
			foreach ( $meta_updates as $meta_key => $meta_value ) {
				if ( null === $meta_value ) {
					delete_post_meta( $reservation_id, $meta_key );
				} else {
					update_post_meta( $reservation_id, $meta_key, $meta_value );
				}
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				throw new RuntimeException( __( 'Reservation inventory transaction could not be committed.', 'spectral-dot-reservations' ) );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			clean_post_cache( $reservation_id );
			if ( $product instanceof WC_Product ) {
				clean_post_cache( $product->get_id() );
				wc_delete_product_transients( $product->get_id() );
			}
			return new WP_Error( 'sdpr_inventory_transition_failed', $error->getMessage() );
		}

		clean_post_cache( $reservation_id );
		do_action(
			'sdpr_reservation_transitioned',
			array(
				'reservation_id' => $reservation_id,
				'from'           => $from_status,
				'to'             => $to_status,
				'source'         => sanitize_key( $source ),
				'occurred_at'    => time(),
			)
		);
		return true;
	}
}

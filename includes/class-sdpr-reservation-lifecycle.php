<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns reservation creation and all customer/admin lifecycle transitions. */
final class SDPR_Reservation_Lifecycle implements SDPR_Reservation_Lifecycle_Interface {
	private $inventory;
	private $notifications;
	private $repository;
	private $cart_order;
	private $rules;
	private $locks;

	public function __construct(
		SDPR_Inventory_Manager $inventory,
		SDPR_Notification_Dispatcher $notifications,
		SDPR_Reservation_Repository_Interface $repository,
		SDPR_Cart_Order_Service $cart_order,
		SDPR_Reservation_Rules $rules,
		SDPR_Lock_Manager $locks
	) {
		$this->inventory     = $inventory;
		$this->notifications = $notifications;
		$this->repository    = $repository;
		$this->cart_order    = $cart_order;
		$this->rules         = $rules;
		$this->locks         = $locks;
	}

	/** Validate and create a customer reservation inside product and user locks. */
	public function request( $product_id, $user_id, $requested_quantity = 1 ) {
		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return new WP_Error( 'sdpr_invalid_identity', __( 'A valid customer account is required.', 'spectral-dot-reservations' ) );
		}
		return $this->request_for_identity( $product_id, $user_id, $user->user_email, $requested_quantity );
	}

	/** Accept a guest only after an extension has independently verified the email identity. */
	public function request_guest( $product_id, $guest_email, $requested_quantity = 1 ) {
		$guest_email = sanitize_email( $guest_email );
		if ( ! $guest_email || ! is_email( $guest_email ) ) {
			return new WP_Error( 'sdpr_invalid_identity', __( 'A valid verified email address is required.', 'spectral-dot-reservations' ) );
		}
		$user = get_user_by( 'email', $guest_email );
		if ( $user ) {
			return $this->request( $product_id, $user->ID, $requested_quantity );
		}
		return $this->request_for_identity( $product_id, 0, $guest_email, $requested_quantity );
	}

	private function request_for_identity( $product_id, $user_id, $guest_email, $requested_quantity ) {
		$product_id         = absint( $product_id );
		$user_id            = absint( $user_id );
		$guest_email        = sanitize_email( $guest_email );
		$requested_quantity = absint( $requested_quantity );
		$product            = wc_get_product( $product_id );
		if ( ! $product || ! $this->rules->is_product_reservable( $product_id, $user_id, $user_id ? '' : $guest_email ) ) {
			return new WP_Error( 'sdpr_not_reservable', __( 'Reservations are not available for this product.', 'spectral-dot-reservations' ) );
		}

		$identity_lock = $user_id ? 'user_' . $user_id : 'email_' . hash( 'sha256', strtolower( $guest_email ) );
		$locks         = $this->locks->acquire( array( 'product_' . $product_id, $identity_lock ) );
		if ( is_wp_error( $locks ) ) {
			return $locks;
		}

		try {
			/**
			 * Fires after the product and customer request locks are acquired.
			 *
			 * Observers must not mutate reservation or inventory state here.
			 *
			 * @param int $product_id Product ID.
			 * @param int $user_id Customer user ID.
			 */
			do_action( 'sdpr_reservation_request_locked', $product_id, $user_id );

			$requires_approval = $this->rules->requires_approval( $product_id, $user_id );
			$quantity          = $this->rules->get_quantity( $requested_quantity, $product_id, $user_id );
			$limit             = $this->rules->get_max_reservations_per_user( $user_id, $product_id );
			if ( $this->repository->count_open( $user_id, $guest_email ) >= $limit ) {
				/* translators: %d: maximum number of open reservations allowed. */
				return new WP_Error( 'sdpr_limit', sprintf( __( 'You have reached the maximum of %d open reservations.', 'spectral-dot-reservations' ), $limit ) );
			}
			if ( $this->repository->user_has_open_for_product( $product_id, $user_id, $guest_email ) ) {
				return new WP_Error( 'sdpr_duplicate', __( 'You already have a pending or active reservation for this product.', 'spectral-dot-reservations' ) );
			}
			if ( (int) $product->get_stock_quantity( 'edit' ) < $quantity ) {
				return new WP_Error( 'sdpr_no_stock', __( 'There is not enough stock available for this reservation.', 'spectral-dot-reservations' ) );
			}

			$reservation_id = $this->create( $product_id, $user_id, $guest_email, $quantity );
			if ( ! $reservation_id ) {
				return new WP_Error( 'sdpr_create_failed', __( 'Could not create reservation.', 'spectral-dot-reservations' ) );
			}
			return array(
				'reservation_id'    => $reservation_id,
				'requires_approval' => $requires_approval,
				'quantity'          => $quantity,
			);
		} finally {
			$this->locks->release( $locks );
		}
	}

	public function create( $product_id, $user_id = 0, $guest_email = '', $quantity = 1 ) {
		$product_id        = absint( $product_id );
		$user_id           = absint( $user_id );
		$guest_email       = sanitize_email( $guest_email );
		$quantity          = max( 1, min( 10000, absint( $quantity ) ) );
		$requires_approval = $this->rules->requires_approval( $product_id, $user_id );
		$duration_hours    = $this->rules->get_duration_hours( $requires_approval ? 'pending' : 'active', $product_id, $user_id );
		$expires_at        = time() + ( $duration_hours * HOUR_IN_SECONDS );

		$reservation_id = wp_insert_post(
			array(
				'post_type'   => 'sdpr_reservation',
				'post_title'  => 'Reservation for product ' . $product_id,
				'post_status' => 'publish',
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $reservation_id ) ) {
			return false;
		}

		$initial_status     = $requires_approval ? SDPR_Reservation_Status::PENDING : SDPR_Reservation_Status::INITIALIZING;
		$meta_data          = array(
			SDPR_Reservation_Meta::PRODUCT_ID      => $product_id,
			SDPR_Reservation_Meta::STATUS          => $initial_status,
			SDPR_Reservation_Meta::EXPIRES_AT      => $expires_at,
			SDPR_Reservation_Meta::QUANTITY        => $quantity,
			SDPR_Reservation_Meta::TIMESTAMP_MODEL => 'utc',
			SDPR_Reservation_Meta::INVENTORY_STATE => SDPR_Inventory_Manager::STATE_NONE,
		);
		$notification_email = '';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$notification_email                        = $user->user_email;
				$meta_data[ SDPR_Reservation_Meta::EMAIL ] = $notification_email;
			}
		} elseif ( $guest_email && is_email( $guest_email ) ) {
			$notification_email                        = $guest_email;
			$meta_data[ SDPR_Reservation_Meta::EMAIL ] = $guest_email;
		}

		foreach ( $meta_data as $key => $value ) {
			if ( false === SDPR_Reservation_Meta::update( $reservation_id, $key, $value ) ) {
				wp_delete_post( $reservation_id, true );
				return false;
			}
		}

		if ( ! $requires_approval ) {
			$activated = $this->inventory->activate( $reservation_id, SDPR_Reservation_Status::INITIALIZING, wc_get_product( $product_id ), $quantity, array(), 'create' );
			if ( is_wp_error( $activated ) ) {
				wp_delete_post( $reservation_id, true );
				return false;
			}
		}

		if ( $notification_email ) {
			$this->notifications->dispatch( $requires_approval ? 'pending' : 'created', $reservation_id, $notification_email );
		}
		if ( $requires_approval ) {
			$this->announce_transition( $reservation_id, '', SDPR_Reservation_Status::PENDING, 'create' );
		}
		$this->cart_order->clear_cache();
		return $reservation_id;
	}

	public function approve( $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		if ( 'sdpr_reservation' !== get_post_type( $reservation_id ) ) {
			return new WP_Error( 'sdpr_invalid_reservation', __( 'Invalid reservation.', 'spectral-dot-reservations' ) );
		}

		$product_id = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID );
		$user_id    = (int) get_post_field( 'post_author', $reservation_id );
		$locks      = $this->locks->acquire( array( 'product_' . $product_id, 'user_' . $user_id ) );
		if ( is_wp_error( $locks ) ) {
			return $locks;
		}

		try {
			if ( SDPR_Reservation_Status::PENDING !== SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS ) ) {
				return new WP_Error( 'sdpr_not_pending', __( 'Reservation is not pending approval.', 'spectral-dot-reservations' ) );
			}
			if ( ! $product_id ) {
				return new WP_Error( 'sdpr_missing_product', __( 'Reservation is missing product data.', 'spectral-dot-reservations' ) );
			}
			$product  = wc_get_product( $product_id );
			$quantity = max( 1, (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ) );
			if ( ! $product || ! $this->rules->supports_inventory( $product ) ) {
				return new WP_Error( 'sdpr_stock_unmanaged', __( 'Product stock is not managed.', 'spectral-dot-reservations' ) );
			}
			if ( (int) $product->get_stock_quantity( 'edit' ) < $quantity ) {
				return new WP_Error( 'sdpr_no_stock', __( 'There is not enough stock available to approve this reservation.', 'spectral-dot-reservations' ) );
			}
			$expires_at = time() + ( $this->rules->get_duration_hours( 'active', $product_id, $user_id ) * HOUR_IN_SECONDS );
			$activation = $this->inventory->activate(
				$reservation_id,
				SDPR_Reservation_Status::PENDING,
				$product,
				$quantity,
				array( SDPR_Reservation_Meta::EXPIRES_AT => $expires_at ),
				'approve'
			);
			if ( is_wp_error( $activation ) ) {
				return $activation;
			}
		} finally {
			$this->locks->release( $locks );
		}

		$email = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EMAIL );
		if ( $email ) {
			$this->notifications->dispatch( 'approved', $reservation_id, $email );
		}
		$this->cart_order->clear_cache();
		return true;
	}

	public function deny( $reservation_id, $reason = '' ) {
		$reservation_id = absint( $reservation_id );
		if ( 'sdpr_reservation' !== get_post_type( $reservation_id ) ) {
			return false;
		}

		$product_id = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID );
		$user_id    = (int) get_post_field( 'post_author', $reservation_id );
		$locks      = $this->locks->acquire( array( 'product_' . $product_id, 'user_' . $user_id ) );
		if ( is_wp_error( $locks ) ) {
			return $locks;
		}

		try {
			if ( SDPR_Reservation_Status::PENDING !== SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS ) ) {
				return false;
			}
			$result = $this->inventory->release(
				$reservation_id,
				SDPR_Reservation_Status::PENDING,
				SDPR_Reservation_Status::DENIED,
				null,
				(int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ),
				'deny'
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $reason ) {
				SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::DENIAL_REASON, sanitize_text_field( $reason ) );
			}
		} finally {
			$this->locks->release( $locks );
		}

		$email = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EMAIL );
		if ( $email ) {
			$this->notifications->dispatch( 'denied', $reservation_id, $email, array( 'reason' => $reason ) );
		}
		return true;
	}

	public function cancel( $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		if ( 'sdpr_reservation' !== get_post_type( $reservation_id ) ) {
			return false;
		}

		$product_id = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID );
		$user_id    = (int) get_post_field( 'post_author', $reservation_id );
		$locks      = $this->locks->acquire( array( 'product_' . $product_id, 'user_' . $user_id ) );
		if ( is_wp_error( $locks ) ) {
			return $locks;
		}

		try {
			$previous_status = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
			if ( ! in_array( $previous_status, SDPR_Reservation_Status::open(), true ) ) {
				return false;
			}
			$product = SDPR_Reservation_Status::ACTIVE === $previous_status ? wc_get_product( $product_id ) : null;
			$result  = $this->inventory->release(
				$reservation_id,
				$previous_status,
				SDPR_Reservation_Status::CANCELLED,
				$product,
				(int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ),
				'cancel'
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} finally {
			$this->locks->release( $locks );
		}

		$this->cart_order->clear_cache();
		return true;
	}

	public function extend( $reservation_id, $additional_hours, $source = 'extension' ) {
		$reservation_id   = absint( $reservation_id );
		$additional_hours = max( 1, min( 168, absint( $additional_hours ) ) );
		if ( 'sdpr_reservation' !== get_post_type( $reservation_id ) ) {
			return new WP_Error( 'sdpr_invalid_reservation', __( 'Invalid reservation.', 'spectral-dot-reservations' ) );
		}

		$product_id = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID );
		$user_id    = (int) get_post_field( 'post_author', $reservation_id );
		$locks      = $this->locks->acquire( array( 'product_' . $product_id, 'user_' . $user_id ) );
		if ( is_wp_error( $locks ) ) {
			return $locks;
		}

		try {
			$status     = (string) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
			$expires_at = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT );
			if ( ! in_array( $status, SDPR_Reservation_Status::open(), true ) || $expires_at <= time() ) {
				return new WP_Error( 'sdpr_not_extendable', __( 'This reservation can no longer be extended.', 'spectral-dot-reservations' ) );
			}
			$new_expires_at = $expires_at + ( $additional_hours * HOUR_IN_SECONDS );
			if ( ! SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT, $new_expires_at, $expires_at ) ) {
				return new WP_Error( 'sdpr_extension_conflict', __( 'The reservation deadline changed before it could be extended.', 'spectral-dot-reservations' ) );
			}
		} finally {
			$this->locks->release( $locks );
		}

		do_action(
			'sdpr_reservation_extended',
			array(
				'reservation_id'   => $reservation_id,
				'previous_expiry'  => $expires_at,
				'new_expiry'       => $new_expires_at,
				'additional_hours' => $additional_hours,
				'source'           => sanitize_key( $source ),
				'occurred_at'      => time(),
			)
		);
		return $new_expires_at;
	}

	private function announce_transition( $reservation_id, $from, $to, $source ) {
		do_action(
			'sdpr_reservation_transitioned',
			array(
				'reservation_id' => absint( $reservation_id ),
				'from'           => $from,
				'to'             => $to,
				'source'         => sanitize_key( $source ),
				'occurred_at'    => time(),
			)
		);
	}
}

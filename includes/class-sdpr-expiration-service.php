<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Schedules, processes, and reports reservation expiration health. */
final class SDPR_Expiration_Service {
	const CRON_HOOK = 'sdpr_expire_reservations';

	private $inventory;
	private $notifications;
	private $cart_order;

	public function __construct( SDPR_Inventory_Manager $inventory, SDPR_Notification_Dispatcher $notifications, SDPR_Cart_Order_Service $cart_order ) {
		$this->inventory     = $inventory;
		$this->notifications = $notifications;
		$this->cart_order    = $cart_order;
	}

	public function add_cron_schedule( $schedules ) {
		$schedules['sdpr_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'spectral-dot-reservations' ),
		);
		return $schedules;
	}

	public function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sdpr_five_minutes', self::CRON_HOOK );
		}
	}

	public function expire_old_reservations() {
		$ids = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => SDPR_Reservation_Meta::STATUS,
						'value'   => SDPR_Reservation_Status::open(),
						'compare' => 'IN',
					),
					array(
						'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
						'value'   => time(),
						'type'    => 'NUMERIC',
						'compare' => '<=',
					),
				),
			)
		);
		foreach ( $ids as $reservation_id ) {
			$this->expire_reservation( $reservation_id );
		}
	}

	public function expire_reservation( $reservation_id ) {
		$previous_status = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
		if ( ! in_array( $previous_status, SDPR_Reservation_Status::open(), true ) ) {
			return false;
		}
		$product = SDPR_Reservation_Status::ACTIVE === $previous_status ? wc_get_product( (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ) ) : null;
		$result  = $this->inventory->release( $reservation_id, $previous_status, SDPR_Reservation_Status::EXPIRED, $product, (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ), 'expire' );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::EXPIRED_FROM, $previous_status );
		$email = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EMAIL );
		if ( $email ) {
			$this->notifications->dispatch( 'expired', $reservation_id, $email );
		}
		$this->cart_order->clear_cache();
		return true;
	}

	public function register_site_health_tests( $tests ) {
		$tests['direct']['sdpr_operations'] = array(
			'label' => __( 'Spectral Dot Reservations operations', 'spectral-dot-reservations' ),
			'test'  => array( $this, 'get_site_health_result' ),
		);
		return $tests;
	}

	public function get_site_health_result() {
		$cron_missing = ! wp_next_scheduled( self::CRON_HOOK );
		$inconsistent = $this->inventory->find_inconsistent_states( 100 );
		$result       = array(
			'label'       => __( 'Reservation expiration and inventory ownership are healthy', 'spectral-dot-reservations' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Spectral Dot Reservations', 'spectral-dot-reservations' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The expiration schedule is active and sampled reservation inventory states are consistent.', 'spectral-dot-reservations' ) . '</p>',
			'actions'     => '',
			'test'        => 'sdpr_operations',
		);
		if ( $cron_missing || $inconsistent ) {
			$result['label']  = __( 'Reservation operations need attention', 'spectral-dot-reservations' );
			$result['status'] = 'critical';
			$messages         = array();
			if ( $cron_missing ) {
				$messages[] = __( 'The reservation expiration schedule is missing.', 'spectral-dot-reservations' );
			}
			if ( $inconsistent ) {
				/* translators: %d: number of inconsistent reservations found in the health sample. */
				$messages[] = sprintf( _n( '%d reservation has inconsistent inventory ownership.', '%d reservations have inconsistent inventory ownership.', count( $inconsistent ), 'spectral-dot-reservations' ), count( $inconsistent ) );
			}
			$result['description'] = '<p>' . esc_html( implode( ' ', $messages ) ) . '</p>';
		}
		return $result;
	}
}

<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dispatches lifecycle notifications through specific and generic event contracts. */
final class SDPR_Notification_Dispatcher {
	private $event_hooks = array(
		'created'  => 'reservation_created',
		'pending'  => 'reservation_pending_approval',
		'approved' => 'reservation_approved',
		'expired'  => 'reservation_expired',
		'denied'   => 'reservation_denied',
	);

	public function dispatch( $event, $reservation_id, $email, $context = array() ) {
		$event          = sanitize_key( $event );
		$reservation_id = absint( $reservation_id );
		$email          = sanitize_email( $email );
		$context        = is_array( $context ) ? $context : array();
		if ( ! $reservation_id || ! $email || ! isset( $this->event_hooks[ $event ] ) ) {
			return false;
		}

		if ( 'denied' === $event ) {
			do_action( 'sdpr_' . $this->event_hooks[ $event ], $reservation_id, $email, isset( $context['reason'] ) ? $context['reason'] : '' );
		} else {
			do_action( 'sdpr_' . $this->event_hooks[ $event ], $reservation_id, $email );
		}
		do_action( 'sdpr_reservation_event', $event, $reservation_id, $email, $context );
		return true;
	}
}

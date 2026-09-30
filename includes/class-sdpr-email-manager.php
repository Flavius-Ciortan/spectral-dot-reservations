<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email notifications for reservations.
 */
class SDPR_Email_Manager {

	public function __construct() {
		$this->init();
	}

	private function init() {
		add_action( 'sdpr_reservation_created', array( $this, 'send_confirmation_email' ), 10, 2 );
		add_action( 'sdpr_reservation_expired', array( $this, 'send_expiration_email' ), 10, 2 );
		add_action( 'sdpr_reservation_pending_approval', array( $this, 'send_pending_approval_email' ), 10, 2 );
		add_action( 'sdpr_reservation_approved', array( $this, 'send_approval_confirmation_email' ), 10, 2 );
		add_action( 'sdpr_reservation_denied', array( $this, 'send_denial_email' ), 10, 3 );
	}

	private function are_email_notifications_enabled() {
		$options = get_option( 'sdpr_options', array() );
		return is_array( $options ) && ! empty( $options['enable_email_notifications'] );
	}

	private function reservation_product( $reservation_id ) {
		return wc_get_product( (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ) );
	}

	private function send( $event, $reservation_id, $email, $subject, $message ) {
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}
		$content = apply_filters(
			'sdpr_email_content',
			array(
				'to'      => $email,
				'subject' => wp_strip_all_tags( $subject ),
				'body'    => nl2br( esc_html( $message ) ),
				'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
			),
			sanitize_key( $event ),
			absint( $reservation_id )
		);
		if ( ! is_array( $content ) || empty( $content['to'] ) || ! is_email( $content['to'] ) ) {
			return false;
		}
		$sent = wp_mail( $content['to'], wp_strip_all_tags( $content['subject'] ?? '' ), wp_kses_post( $content['body'] ?? '' ), $content['headers'] ?? array() );
		do_action( 'sdpr_email_sent', (bool) $sent, sanitize_key( $event ), absint( $reservation_id ), $content );
		return (bool) $sent;
	}

	public function send_confirmation_email( $reservation_id, $email ) {
		if ( ! $this->are_email_notifications_enabled() ) {
			return;
		}
		$product = $this->reservation_product( $reservation_id );
		if ( ! $product ) {
			return;
		}
		$name    = wp_strip_all_tags( $product->get_name() );
		$expires = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT ) );
		$this->send(
			'created',
			$reservation_id,
			$email,
			/* translators: %s: product name. */
			sprintf( __( 'Reservation Confirmed: %s', 'spectral-dot-reservations' ), $name ),
			sprintf(
				/* translators: 1: product name, 2: expiration date, 3: product URL, 4: add-to-cart URL. */
				__( "Hello,\n\nYour reservation for %1\$s has been confirmed.\n\nExpires: %2\$s\n\nView Product: %3\$s\n\nAdd to Cart: %4\$s\n\nThank you!", 'spectral-dot-reservations' ),
				$name,
				$expires,
				esc_url_raw( get_permalink( $product->get_id() ) ),
				esc_url_raw( add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() ) )
			)
		);
	}

	public function send_expiration_email( $reservation_id, $email ) {
		if ( ! $this->are_email_notifications_enabled() ) {
			return;
		}
		$product = $this->reservation_product( $reservation_id );
		if ( ! $product ) {
			return;
		}
		$name         = wp_strip_all_tags( $product->get_name() );
		$expired_from = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRED_FROM );
		$message      = SDPR_Reservation_Status::PENDING === $expired_from
			/* translators: 1: product name, 2: product URL. */
			? sprintf( __( "Hello,\n\nYour reservation request for %1\$s expired before it was approved.\n\nView Product: %2\$s", 'spectral-dot-reservations' ), $name, esc_url_raw( get_permalink( $product->get_id() ) ) )
			/* translators: 1: product name, 2: product URL. */
			: sprintf( __( "Hello,\n\nYour reservation for %1\$s has expired and the product is now available to other customers.\n\nYou can still purchase it if available: %2\$s\n\nThank you!", 'spectral-dot-reservations' ), $name, esc_url_raw( get_permalink( $product->get_id() ) ) );
		$this->send(
			'expired',
			$reservation_id,
			$email,
			/* translators: %s: product name. */
			sprintf( __( 'Reservation Expired: %s', 'spectral-dot-reservations' ), $name ),
			$message
		);
	}

	public function send_pending_approval_email( $reservation_id, $email ) {
		if ( ! $this->are_email_notifications_enabled() ) {
			return;
		}
		$product = $this->reservation_product( $reservation_id );
		if ( ! $product ) {
			return;
		}
		$name = wp_strip_all_tags( $product->get_name() );
		$this->send(
			'pending',
			$reservation_id,
			$email,
			/* translators: %s: product name. */
			sprintf( __( 'Reservation Pending Approval: %s', 'spectral-dot-reservations' ), $name ),
			/* translators: 1: product name, 2: product URL. */
			sprintf( __( "Hello,\n\nThank you for your reservation request for %1\$s.\n\nYour reservation is pending approval. You will receive another email after it is reviewed.\n\nView Product: %2\$s", 'spectral-dot-reservations' ), $name, esc_url_raw( get_permalink( $product->get_id() ) ) )
		);
	}

	public function send_approval_confirmation_email( $reservation_id, $email ) {
		if ( ! $this->are_email_notifications_enabled() ) {
			return;
		}
		$product = $this->reservation_product( $reservation_id );
		if ( ! $product ) {
			return;
		}
		$name    = wp_strip_all_tags( $product->get_name() );
		$expires = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT ) );
		$this->send(
			'approved',
			$reservation_id,
			$email,
			/* translators: %s: product name. */
			sprintf( __( 'Reservation Approved: %s', 'spectral-dot-reservations' ), $name ),
			/* translators: 1: product name, 2: expiration date, 3: product URL, 4: add-to-cart URL. */
			sprintf( __( "Hello,\n\nYour reservation for %1\$s has been approved and is now active.\n\nExpires: %2\$s\n\nView Product: %3\$s\n\nAdd to Cart: %4\$s", 'spectral-dot-reservations' ), $name, $expires, esc_url_raw( get_permalink( $product->get_id() ) ), esc_url_raw( add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() ) ) )
		);
	}

	public function send_denial_email( $reservation_id, $email, $reason = '' ) {
		if ( ! $this->are_email_notifications_enabled() ) {
			return;
		}
		$product = $this->reservation_product( $reservation_id );
		if ( ! $product ) {
			return;
		}
		$name   = wp_strip_all_tags( $product->get_name() );
		$reason = sanitize_text_field( $reason );
		/* translators: %s: denial reason. */
		$reason_text = $reason ? sprintf( __( "Reason: %s\n\n", 'spectral-dot-reservations' ), $reason ) : '';
		$this->send(
			'denied',
			$reservation_id,
			$email,
			/* translators: %s: product name. */
			sprintf( __( 'Reservation Not Approved: %s', 'spectral-dot-reservations' ), $name ),
			/* translators: 1: product name, 2: denial reason, 3: product URL. */
			sprintf( __( "Hello,\n\nYour reservation request for %1\$s could not be approved.\n\n%2\$sView Product: %3\$s", 'spectral-dot-reservations' ), $name, $reason_text, esc_url_raw( get_permalink( $product->get_id() ) ) )
		);
	}
}

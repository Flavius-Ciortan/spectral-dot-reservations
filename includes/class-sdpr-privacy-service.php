<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress privacy export and erasure for reservation records. */
final class SDPR_Privacy_Service {
	public function register_exporter( $exporters ) {
		$exporters['spectral-dot-reservations'] = array(
			'exporter_friendly_name' => __( 'Spectral Dot Reservations reservations', 'spectral-dot-reservations' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['spectral-dot-reservations'] = array(
			'eraser_friendly_name' => __( 'Spectral Dot Reservations reservations', 'spectral-dot-reservations' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public function export_personal_data( $email_address, $page = 1 ) {
		$ids  = $this->find_reservations( $email_address, $page );
		$data = array();
		foreach ( $ids as $reservation_id ) {
			$created_at = get_post_timestamp( $reservation_id );
			$expires_at = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT );
			$data[]     = array(
				'group_id'    => 'spectral-dot-reservations-reservations',
				'group_label' => __( 'Product reservations', 'spectral-dot-reservations' ),
				'item_id'     => 'sdpr-reservation-' . $reservation_id,
				'data'        => array(
					array(
						'name'  => __( 'User ID', 'spectral-dot-reservations' ),
						'value' => (int) get_post_field( 'post_author', $reservation_id ),
					),
					array(
						'name'  => __( 'First name', 'spectral-dot-reservations' ),
						'value' => sanitize_text_field( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::NAME ) ),
					),
					array(
						'name'  => __( 'Last name', 'spectral-dot-reservations' ),
						'value' => sanitize_text_field( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::SURNAME ) ),
					),
					array(
						'name'  => __( 'Product ID', 'spectral-dot-reservations' ),
						'value' => (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ),
					),
					array(
						'name'  => __( 'Quantity', 'spectral-dot-reservations' ),
						'value' => max( 1, (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ) ),
					),
					array(
						'name'  => __( 'Status', 'spectral-dot-reservations' ),
						'value' => sanitize_text_field( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS ) ),
					),
					array(
						'name'  => __( 'Created', 'spectral-dot-reservations' ),
						'value' => $created_at ? wp_date( DATE_ATOM, $created_at ) : '',
					),
					array(
						'name'  => __( 'Email', 'spectral-dot-reservations' ),
						'value' => sanitize_email( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EMAIL ) ),
					),
					array(
						'name'  => __( 'Expires', 'spectral-dot-reservations' ),
						'value' => $expires_at ? wp_date( DATE_ATOM, $expires_at ) : '',
					),
					array(
						'name'  => __( 'Inventory state', 'spectral-dot-reservations' ),
						'value' => sanitize_key( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::INVENTORY_STATE ) ),
					),
					array(
						'name'  => __( 'Related order ID', 'spectral-dot-reservations' ),
						'value' => absint( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::ORDER_ID ) ),
					),
					array(
						'name'  => __( 'Denial reason', 'spectral-dot-reservations' ),
						'value' => sanitize_text_field( SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::DENIAL_REASON ) ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < 100,
		);
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		unset( $page );
		$ids      = $this->find_erasable_reservations( $email_address );
		$removed  = false;
		$retained = $this->has_retained_reservations( $email_address );
		foreach ( $ids as $reservation_id ) {
			SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::EMAIL, wp_privacy_anonymize_data( 'email', $email_address ) );
			SDPR_Reservation_Meta::delete( $reservation_id, SDPR_Reservation_Meta::NAME );
			SDPR_Reservation_Meta::delete( $reservation_id, SDPR_Reservation_Meta::SURNAME );
			SDPR_Reservation_Meta::delete( $reservation_id, SDPR_Reservation_Meta::DENIAL_REASON );
			wp_update_post(
				array(
					'ID'          => $reservation_id,
					'post_author' => 0,
				)
			);
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Open reservations were retained until their inventory obligation ends.', 'spectral-dot-reservations' ) ) : array(),
			'done'           => count( $ids ) < 100,
		);
	}

	private function identity_args( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( $user ) {
			return array( 'author' => $user->ID );
		}
		return array(
			'meta_query' => array(
				array(
					'key'   => SDPR_Reservation_Meta::EMAIL,
					'value' => sanitize_email( $email_address ),
				),
			),
		);
	}

	private function find_reservations( $email_address, $page ) {
		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'paged'          => max( 1, absint( $page ) ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		// Export consumes both post fields and metadata; prime the bounded batch together.
		return array_map( 'absint', wp_list_pluck( get_posts( array_merge( $args, $this->identity_args( $email_address ) ) ), 'ID' ) );
	}

	private function find_erasable_reservations( $email_address ) {
		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 100,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => SDPR_Reservation_Meta::STATUS,
					'value'   => SDPR_Reservation_Status::open(),
					'compare' => 'NOT IN',
				),
			),
		);
		return get_posts( $this->merge_identity_meta_query( $args, $email_address ) );
	}

	private function has_retained_reservations( $email_address ) {
		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => SDPR_Reservation_Meta::STATUS,
					'value'   => SDPR_Reservation_Status::open(),
					'compare' => 'IN',
				),
			),
		);
		return ! empty( get_posts( $this->merge_identity_meta_query( $args, $email_address ) ) );
	}

	private function merge_identity_meta_query( $args, $email_address ) {
		$identity = $this->identity_args( $email_address );
		if ( isset( $identity['author'] ) ) {
			$args['author'] = $identity['author'];
		} else {
			$args['meta_query'] = array_merge( $identity['meta_query'], $args['meta_query'] );
		}
		return $args;
	}
}

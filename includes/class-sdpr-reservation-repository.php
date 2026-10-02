<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reservation persistence queries shared by Free and compatible add-ons. */
final class SDPR_Reservation_Repository implements SDPR_Reservation_Repository_Interface {
	const STATUS_COUNTS_CACHE_KEY = 'reservation_status_counts';

	public function __construct() {
		add_action( 'sdpr_reservation_transitioned', array( $this, 'invalidate_status_counts' ) );
		add_action( 'deleted_post', array( $this, 'invalidate_status_counts_for_deleted_post' ), 10, 2 );
	}

	public function find_active( $product_id, $user_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'author'         => absint( $user_id ),
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => SDPR_Reservation_Meta::PRODUCT_ID,
						'value' => absint( $product_id ),
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => SDPR_Reservation_Meta::STATUS,
						'value' => SDPR_Reservation_Status::ACTIVE,
					),
					array(
						'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
						'value'   => time(),
						'type'    => 'NUMERIC',
						'compare' => '>',
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	public function count_active( $user_id = 0, $email = '' ) {
		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => SDPR_Reservation_Meta::STATUS,
					'value' => SDPR_Reservation_Status::ACTIVE,
				),
				array(
					'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
					'value'   => time(),
					'type'    => 'NUMERIC',
					'compare' => '>',
				),
			),
		);
		if ( $user_id > 0 ) {
			$args['author'] = absint( $user_id );
		} elseif ( $email ) {
			$args['meta_query'][] = array(
				'key'   => SDPR_Reservation_Meta::EMAIL,
				'value' => sanitize_email( $email ),
			);
		} else {
			return 0;
		}
		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	public function has_active( $product_id, $user_id = 0, $email = '' ) {
		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => SDPR_Reservation_Meta::STATUS,
					'value' => SDPR_Reservation_Status::ACTIVE,
				),
				array(
					'key'   => SDPR_Reservation_Meta::PRODUCT_ID,
					'value' => absint( $product_id ),
					'type'  => 'NUMERIC',
				),
				array(
					'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
					'value'   => time(),
					'type'    => 'NUMERIC',
					'compare' => '>',
				),
			),
		);
		if ( $user_id > 0 ) {
			$args['author'] = absint( $user_id );
		} elseif ( $email ) {
			$args['meta_query'][] = array(
				'key'   => SDPR_Reservation_Meta::EMAIL,
				'value' => sanitize_email( $email ),
			);
		} else {
			return false;
		}
		return ! empty( get_posts( $args ) );
	}

	public function count_open( $user_id, $email = '' ) {
		return count( $this->find_open_for_identity( $user_id, $email ) );
	}

	public function user_has_open_for_product( $product_id, $user_id, $email = '' ) {
		return ! empty( $this->find_open_for_identity( $user_id, $email, $product_id ) );
	}

	/** Return unique, unexpired open reservations for an account/email identity. */
	private function find_open_for_identity( $user_id, $email = '', $product_id = 0 ) {
		$user_id = absint( $user_id );
		$email   = sanitize_email( $email );
		if ( ! $email && $user_id ) {
			$user  = get_userdata( $user_id );
			$email = $user ? sanitize_email( $user->user_email ) : '';
		}
		if ( ! $user_id && $email ) {
			$user    = get_user_by( 'email', $email );
			$user_id = $user ? (int) $user->ID : 0;
		}
		if ( ! $user_id && ! $email ) {
			return array();
		}

		$base_meta = array(
			array(
				'key'     => SDPR_Reservation_Meta::STATUS,
				'value'   => SDPR_Reservation_Status::open(),
				'compare' => 'IN',
			),
			array(
				'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
				'value'   => time(),
				'type'    => 'NUMERIC',
				'compare' => '>',
			),
		);
		if ( $product_id ) {
			$base_meta[] = array(
				'key'   => SDPR_Reservation_Meta::PRODUCT_ID,
				'value' => absint( $product_id ),
				'type'  => 'NUMERIC',
			);
		}
		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => $base_meta,
		);
		$ids  = array();
		if ( $user_id ) {
			$args['author'] = $user_id;
			$ids            = get_posts( $args );
			unset( $args['author'] );
		}
		if ( $email ) {
			$args['meta_query'][] = array(
				'key'   => SDPR_Reservation_Meta::EMAIL,
				'value' => $email,
			);
			$ids                  = array_merge( $ids, get_posts( $args ) );
		}
		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	/** Return cached counts for every known reservation status and the total. */
	public function get_status_counts() {
		$counts = wp_cache_get( self::STATUS_COUNTS_CACHE_KEY, 'sdpr' );
		if ( false !== $counts ) {
			return $counts;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A single cached aggregate is more efficient than one WP_Query per status.
		$rows            = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS reservation_status, COUNT(*) AS reservation_count
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = 'sdpr_reservation' AND p.post_status = 'publish'
				GROUP BY pm.meta_value",
				SDPR_Reservation_Meta::STATUS
			),
			OBJECT_K
		);
		$counts          = array_fill_keys( SDPR_Reservation_Status::all(), 0 );
		$counts['total'] = 0;
		foreach ( (array) $rows as $status => $row ) {
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row->reservation_count;
				$counts['total']  += (int) $row->reservation_count;
			}
		}

		wp_cache_set( self::STATUS_COUNTS_CACHE_KEY, $counts, 'sdpr', MINUTE_IN_SECONDS );
		return $counts;
	}

	/** Clear aggregate counts after a completed lifecycle transition. */
	public function invalidate_status_counts() {
		wp_cache_delete( self::STATUS_COUNTS_CACHE_KEY, 'sdpr' );
	}

	/** Clear aggregate counts when a reservation record is deleted directly. */
	public function invalidate_status_counts_for_deleted_post( $post_id, $post ) {
		unset( $post_id );
		if ( $post instanceof WP_Post && 'sdpr_reservation' === $post->post_type ) {
			$this->invalidate_status_counts();
		}
	}
}

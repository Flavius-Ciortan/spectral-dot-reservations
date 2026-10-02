<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Internal, live reservation reads with prepared predicates and native pagination. */
final class SDPR_Reservation_Query {
	/** Callers must prepare every dynamic value in the SQL predicate. */
	public static function run( $args, $predicate ) {
		$query  = new WP_Query();
		$filter = static function ( $where, $current ) use ( $query, $predicate ) {
			return $current === $query ? $where . ' AND (' . $predicate . ')' : $where;
		};
		$args   = array_merge(
			array(
				'post_type'     => 'sdpr_reservation',
				'post_status'   => 'publish',
				'no_found_rows' => true,
			),
			$args,
			array(
				// Metadata-only lifecycle changes must be visible to quota and privacy reads.
				'cache_results'    => false,
				'suppress_filters' => false,
			)
		);
		add_filter( 'posts_where', $filter, 10, 2 );
		try {
			$query->query( $args );
		} finally {
			remove_filter( 'posts_where', $filter, 10 );
		}
		if ( ! $query->get( 'fields' ) && $query->posts ) {
			// Live query results still need a single metadata batch for admin row rendering.
			update_post_caches( $query->posts, 'sdpr_reservation', false, true );
		}
		return $query;
	}
}

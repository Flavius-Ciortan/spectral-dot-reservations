<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'SDPR_PERFORMANCE_TEST' ) ) {
	return;
}

global $wpdb;

if ( ! class_exists( 'SDPR_Admin_Reservations' ) ) {
	require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-reservations.php';
}

$fixture_prefix = 'SDPR performance fixture ';
$fixture_total  = max( 1000, min( 50000, (int) ( getenv( 'SDPR_PERFORMANCE_RECORDS' ) ?: 5000 ) ) );
$statuses       = SDPR_Reservation_Status::all();
$future         = time() + DAY_IN_SECONDS;
$past           = time() - DAY_IN_SECONDS;

$cleanup = static function () use ( $wpdb, $fixture_prefix ) {
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
			'sdpr_reservation',
			$wpdb->esc_like( $fixture_prefix ) . '%'
		)
	);

	foreach ( array_chunk( array_map( 'absint', $ids ), 500 ) as $chunk ) {
		$id_list = implode( ',', $chunk );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are normalized with absint immediately above.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list})" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are normalized with absint immediately above.
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$id_list})" );
	}

	clean_post_cache( 0 );
	wp_cache_delete( SDPR_Reservation_Repository::STATUS_COUNTS_CACHE_KEY, 'sdpr' );
};

register_shutdown_function( $cleanup );

$measure = static function ( $name, $callback ) use ( $wpdb ) {
	if ( 'status_counts_warm' !== $name ) {
		wp_cache_flush();
	}
	$query_start = (int) $wpdb->num_queries;
	$time_start  = microtime( true );
	$result      = $callback();
	$elapsed     = round( ( microtime( true ) - $time_start ) * 1000, 2 );

	return array(
		'name'    => $name,
		'ms'      => $elapsed,
		'queries' => (int) $wpdb->num_queries - $query_start,
		'result'  => is_scalar( $result ) ? $result : count( (array) $result ),
	);
};

$cleanup();
WP_CLI::line( sprintf( 'Seeding %d reservation records...', $fixture_total ) );

wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

for ( $i = 1; $i <= $fixture_total; $i++ ) {
	$author_id  = 900000 + ( $i % 500 );
	$status     = $statuses[ $i % count( $statuses ) ];
	$product_id = 1000 + ( $i % 250 );
	if ( 1 === $i ) {
		$status = SDPR_Reservation_Status::ACTIVE;
	}
	$expires_at = in_array( $status, SDPR_Reservation_Status::open(), true ) ? $future : $past;
	$post_date  = gmdate( 'Y-m-d H:i:s', time() - $i );

	$wpdb->insert(
		$wpdb->posts,
		array(
			'post_author'       => $author_id,
			'post_date'         => $post_date,
			'post_date_gmt'     => $post_date,
			'post_content'      => '',
			'post_title'        => $fixture_prefix . $i,
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_password'     => '',
			'post_name'         => 'sdpr-performance-fixture-' . $i,
			'to_ping'           => '',
			'pinged'            => '',
			'post_modified'     => $post_date,
			'post_modified_gmt' => $post_date,
			'post_content_filtered' => '',
			'post_parent'       => 0,
			'guid'              => '',
			'menu_order'        => 0,
			'post_type'         => 'sdpr_reservation',
			'post_mime_type'    => '',
			'comment_count'     => 0,
		)
	);

	$post_id = (int) $wpdb->insert_id;
	$meta    = array(
		SDPR_Reservation_Meta::PRODUCT_ID => $product_id,
		SDPR_Reservation_Meta::STATUS     => $status,
		SDPR_Reservation_Meta::EXPIRES_AT => $expires_at,
		SDPR_Reservation_Meta::EMAIL      => 'performance-' . ( $i % 500 ) . '@example.invalid',
		SDPR_Reservation_Meta::INVENTORY_STATE => SDPR_Plugin::get_instance()->get_service( 'inventory' )->get_state( 0, $status ),
		SDPR_Reservation_Meta::TIMESTAMP_MODEL => 'utc',
	);

	foreach ( $meta as $key => $value ) {
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $key,
				'meta_value' => $value,
			),
			array( '%d', '%s', '%s' )
		);
	}
}

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );

$inserted = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
		'sdpr_reservation',
		$wpdb->esc_like( $fixture_prefix ) . '%'
	)
);
if ( $fixture_total !== $inserted ) {
	WP_CLI::error( sprintf( 'Expected %d fixtures, but inserted %d.', $fixture_total, $inserted ) );
}

$repository = new SDPR_Reservation_Repository();
$target_id  = 900001;
$target_email = 'performance-1@example.invalid';
$target_product = 1001;

$results   = array();
$results[] = $measure( 'status_counts_cold', static function () use ( $repository ) {
	wp_cache_delete( SDPR_Reservation_Repository::STATUS_COUNTS_CACHE_KEY, 'sdpr' );
	return $repository->get_status_counts();
} );
$results[] = $measure( 'status_counts_warm', static function () use ( $repository ) {
	return $repository->get_status_counts();
} );
$results[] = $measure( 'count_open', static function () use ( $repository, $target_id, $target_email ) {
	return $repository->count_open( $target_id, $target_email );
} );
$results[] = $measure( 'open_for_product', static function () use ( $repository, $target_id, $target_email, $target_product ) {
	return $repository->user_has_open_for_product( $target_product, $target_id, $target_email );
} );
$results[] = $measure( 'has_active', static function () use ( $repository, $target_id, $target_email, $target_product ) {
	return $repository->has_active( $target_product, $target_id, $target_email );
} );

$admin   = new SDPR_Admin_Reservations( new SDPR_Reservations() );
$method  = new ReflectionMethod( $admin, 'get_filtered_reservations' );
$method->setAccessible( true );
$results[] = $measure( 'admin_active_page', static function () use ( $method, $admin ) {
	return $method->invoke( $admin, SDPR_Reservation_Status::ACTIVE, '', 'email', 1 )->posts;
} );
$results[] = $measure( 'admin_email_search', static function () use ( $method, $admin, $target_email ) {
	return $method->invoke( $admin, 'all', $target_email, 'email', 1 )->posts;
} );
$results[] = $measure( 'inventory_health_sample', static function () {
	return SDPR_Plugin::get_instance()->get_service( 'inventory' )->find_inconsistent_states( 100 );
} );
$results[] = $measure( 'privacy_export_batch', static function () use ( $target_email ) {
	$result = SDPR_Plugin::get_instance()->get_service( 'privacy' )->export_personal_data( $target_email );
	return count( $result['data'] );
} );
$results[] = $measure( 'privacy_erasable_lookup', static function () use ( $target_email ) {
	$privacy = SDPR_Plugin::get_instance()->get_service( 'privacy' );
	$method = new ReflectionMethod( $privacy, 'find_erasable_reservations' );
	$method->setAccessible( true );
	return $method->invoke( $privacy, $target_email );
} );
$results[] = $measure( 'privacy_retained_lookup', static function () use ( $target_email ) {
	$privacy = SDPR_Plugin::get_instance()->get_service( 'privacy' );
	$method = new ReflectionMethod( $privacy, 'has_retained_reservations' );
	$method->setAccessible( true );
	return $method->invoke( $privacy, $target_email );
} );
$results[] = $measure( 'admin_customer_search', static function () use ( $method, $admin ) {
	return $method->invoke( $admin, 'all', 'NoMatchingGuest', 'customer_name', 1 )->posts;
} );
$results[] = $measure( 'expiration_due_lookup', static function () {
	return get_posts( array(
		'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true,
		'meta_query' => array(
			array( 'key' => SDPR_Reservation_Meta::STATUS, 'value' => SDPR_Reservation_Status::open(), 'compare' => 'IN' ),
			array( 'key' => SDPR_Reservation_Meta::EXPIRES_AT, 'value' => time(), 'type' => 'NUMERIC', 'compare' => '<=' ),
		),
	) );
} );

$cleanup();

$slow = array_filter(
	$results,
	static function ( $result ) {
		return $result['ms'] > 500;
	}
);

foreach ( $results as $result ) {
	WP_CLI::line(
		sprintf(
			'%-24s %8.2f ms  %3d queries  result=%s',
			$result['name'],
			$result['ms'],
			$result['queries'],
			(string) $result['result']
		)
	);
}

if ( $slow ) {
	WP_CLI::error( 'One or more profiled operations exceeded 500 ms.' );
}

WP_CLI::success( 'All reservation metadata query profiles completed within 500 ms.' );

<?php
/** Bounded-read and identity regression checks; fixtures do not hold real stock. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_QUERY_TEST' ) ) { exit( 1 ); }
global $wpdb;
$failures = array();
$checks = 0;
$assert = static function ( $condition, $label ) use ( &$failures, &$checks ) {
	++$checks;
	if ( ! $condition ) { $failures[] = $label; }
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
};
$ids = array();
$users = array();
$token = strtolower( wp_generate_password( 8, false ) );
$email = 'sdpr-query-' . $token . '@example.test';
$user_id = wp_insert_user( array( 'user_login' => 'sdpr-query-' . $token, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
$other_id = wp_insert_user( array( 'user_login' => 'sdpr-query-other-' . $token, 'user_email' => 'other-' . $email, 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
if ( is_wp_error( $user_id ) || is_wp_error( $other_id ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( $user_id, $other_id ) as $id ) { if ( ! is_wp_error( $id ) ) { wp_delete_user( $id ); } }
	WP_CLI::error( 'Unable to create query-efficiency fixture accounts.' );
}
$users = array( $user_id, $other_id );
$make = static function ( $author, $identity_email, $product, $status = 'denied', $expired = false ) use ( &$ids ) {
	$id = wp_insert_post( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'SDPR query fixture', 'post_date' => 'denied' === $status ? '2000-01-01 00:00:00' : current_time( 'mysql' ) ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Unable to create query-efficiency reservation fixture.' ); }
	$ids[] = $id;
	$inventory = SDPR_Plugin::get_instance()->get_service( 'inventory' );
	foreach ( array(
		SDPR_Reservation_Meta::EMAIL => $identity_email,
		SDPR_Reservation_Meta::PRODUCT_ID => $product,
		SDPR_Reservation_Meta::STATUS => $status,
		SDPR_Reservation_Meta::EXPIRES_AT => time() + ( $expired ? -3600 : 3600 ),
		SDPR_Reservation_Meta::INVENTORY_STATE => $inventory->get_state( 0, $status ),
	) as $key => $value ) { SDPR_Reservation_Meta::update( $id, $key, $value ); }
	return $id;
};
try {
	for ( $i = 0; $i < 120; ++$i ) { $make( $user_id, $email, 910000 + $i ); }
	$account = $make( $user_id, $email, 920001, SDPR_Reservation_Status::ACTIVE );
	$guest = $make( 0, $email, 920002, SDPR_Reservation_Status::PENDING );
	$shared = $make( $other_id, $email, 920003, SDPR_Reservation_Status::PENDING );
	$expired = $make( $user_id, $email, 920004, SDPR_Reservation_Status::PENDING, true );
	$foreign = $make( $other_id, 'other-' . $email, 920005 );
	$repository = SDPR_Plugin::get_instance()->get_service( 'repository' );
	$privacy = SDPR_Plugin::get_instance()->get_service( 'privacy' );
	$inventory = SDPR_Plugin::get_instance()->get_service( 'inventory' );
	wp_cache_flush();
	$start = $wpdb->num_queries;
	$inventory->find_inconsistent_states( 100 );
	$assert( $wpdb->num_queries - $start <= 5, 'Health metadata reads remain bounded instead of one query per record.' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::INVENTORY_STATE, 'released' );
	$assert( in_array( $account, $inventory->find_inconsistent_states( 100 ), true ), 'Health checks observe metadata updates after batch priming.' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::INVENTORY_STATE, 'held' );
	wp_cache_flush();
	$start = $wpdb->num_queries;
	$first = $privacy->export_personal_data( $email, 1 );
	$assert( $wpdb->num_queries - $start <= 8, 'Privacy export primes a whole batch in bounded SQL queries.' );
	$second = $privacy->export_personal_data( $email, 2 );
	$exported = array_merge( wp_list_pluck( $first['data'], 'item_id' ), wp_list_pluck( $second['data'], 'item_id' ) );
	$assert( 100 === count( $first['data'] ) && ! $first['done'] && $second['done'] && 122 === count( $exported ), 'Account privacy export retains exact pagination and ownership.' );
	$assert( ! in_array( 'sdpr-reservation-' . $foreign, $exported, true ) && ! in_array( 'sdpr-reservation-' . $shared, $exported, true ), 'Privacy export excludes records authored by another account.' );
	$observed = array();
	$observe = static function ( $query ) use ( &$observed ) {
		if ( 'sdpr_reservation' === $query->get( 'post_type' ) ) { $observed[] = $query->get( 'posts_per_page' ); }
	};
	add_action( 'pre_get_posts', $observe );
	$matched = $repository->user_has_open_for_product( 920001, $user_id, $email );
	remove_action( 'pre_get_posts', $observe );
	$assert( $matched && array( 1 ) === $observed, 'Author duplicate check uses one limited query and skips redundant email lookup.' );
	$assert( $repository->user_has_open_for_product( 920002, $user_id, $email ), 'Email fallback still detects an authorless guest hold.' );
	$assert( $repository->user_has_open_for_product( 920003, $user_id, $email ), 'Email fallback preserves the existing shared-email identity contract.' );
	$assert( ! $repository->user_has_open_for_product( 920004, $user_id, $email ), 'Expired holds do not become duplicate matches.' );
	$assert( ! $repository->user_has_open_for_product( 920001, 0 ), 'An empty identity cannot match another customer.' );
	$assert( 3 === $repository->count_open( $user_id, $email ) && 3 === $repository->count_open( 0, $email ), 'Quota counting deduplicates account/email overlap and includes guest holds.' );
	SDPR_Reservation_Meta::update( $guest, SDPR_Reservation_Meta::STATUS, 'denied' );
	$assert( ! $repository->user_has_open_for_product( 920002, $user_id, $email ), 'Duplicate queries observe lifecycle metadata changes without stale custom caches.' );
	$assert( 2 === $repository->count_open( $user_id, $email ), 'Quota aggregate immediately observes a metadata-only status change.' );
	add_post_meta( $shared, SDPR_Reservation_Meta::EMAIL, $email );
	add_post_meta( $shared, SDPR_Reservation_Meta::STATUS, SDPR_Reservation_Status::PENDING );
	$assert( 2 === $repository->count_open( $user_id, $email ), 'Duplicate metadata rows cannot multiply the quota count.' );
	$observed = array();
	add_action( 'pre_get_posts', $observe );
	$repository->count_open( $user_id, $email );
	remove_action( 'pre_get_posts', $observe );
	$assert( array( 1, 1 ) === $observed, 'Disjoint quota aggregates each return at most one ID, not an unbounded PHP collection.' );
	delete_post_meta( $account, SDPR_Reservation_Meta::INVENTORY_STATE );
	$invalid = $inventory->find_inconsistent_states( 100 );
	$assert( in_array( $account, $invalid, true ) && ! metadata_exists( 'post', $account, SDPR_Reservation_Meta::INVENTORY_STATE ), 'Health reports missing ownership without silently backfilling it.' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::INVENTORY_STATE, 'held' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::STATUS, 'unknown-status' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::INVENTORY_STATE, 'released' );
	$assert( in_array( $account, $inventory->find_inconsistent_states( 100 ), true ), 'Unknown lifecycle states are unhealthy even when ownership looks released.' );
	delete_post_meta( $account, SDPR_Reservation_Meta::STATUS );
	$assert( in_array( $account, $inventory->find_inconsistent_states( 100 ), true ), 'Health includes records with missing status metadata.' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::STATUS, 'active' );
	SDPR_Reservation_Meta::update( $account, SDPR_Reservation_Meta::INVENTORY_STATE, 'held' );
	$assert( ! in_array( $account, $inventory->find_inconsistent_states( 100 ), true ), 'Canonical status and ownership are healthy after repair.' );

	$guest_email = 'guest-' . $email;
	$closed_guest = $make( 0, $guest_email, 930001 );
	$open_guest = $make( 0, $guest_email, 930002, SDPR_Reservation_Status::PENDING );
	$incomplete_guest = $make( 0, $guest_email, 930003 );
	delete_post_meta( $incomplete_guest, SDPR_Reservation_Meta::STATUS );
	$closed_lookup = new ReflectionMethod( $privacy, 'find_erasable_reservations' );
	$closed_lookup->setAccessible( true );
	$assert( array( $closed_guest ) === array_map( 'intval', $closed_lookup->invoke( $privacy, $guest_email ) ), 'Guest erasure lookup excludes open, unidentified and other-identity records.' );
	$erased = $privacy->erase_personal_data( $guest_email );
	$assert( $erased['items_removed'] && $erased['items_retained'] && $erased['done'], 'Guest erasure reports both removed closed data and retained open obligations.' );
	$assert( $guest_email === SDPR_Reservation_Meta::get( $open_guest, SDPR_Reservation_Meta::EMAIL ) && 'none' === SDPR_Reservation_Meta::get( $open_guest, SDPR_Reservation_Meta::INVENTORY_STATE ), 'Guest erasure does not mutate open identity or inventory ownership.' );
	$again = $privacy->erase_personal_data( $guest_email );
	$assert( ! $again['items_removed'] && $again['items_retained'] && $again['done'], 'Repeated guest erasure sees changed identity without stale query results.' );

	if ( ! class_exists( 'SDPR_Admin_Reservations' ) ) { require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-reservations.php'; }
	$admin = new SDPR_Admin_Reservations( new SDPR_Reservations() );
	$search = new ReflectionMethod( $admin, 'get_filtered_reservations' );
	$search->setAccessible( true );
	$keyword = 'WideSearch' . $token;
	for ( $i = 0; $i < 105; ++$i ) {
		$id = wp_insert_user( array( 'user_login' => 'wide-' . $token . '-' . $i, 'user_email' => 'wide-' . $token . '-' . $i . '@example.test', 'user_pass' => wp_generate_password( 24 ), 'display_name' => $keyword, 'role' => 'customer' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Unable to create wide-search account.' ); }
		$users[] = $id;
		$make( $id, '', 940000 + $i );
	}
	$last = $search->invoke( $admin, 'all', $keyword, 'customer_name', 5 );
	$assert( 105 === (int) $last->found_posts && 5 === (int) $last->max_num_pages && 5 === count( $last->posts ), 'Customer search includes more than 100 matching accounts with native pagination.' );
	$literal = "Literal%_'" . $token;
	SDPR_Reservation_Meta::update( $closed_guest, SDPR_Reservation_Meta::NAME, $literal );
	$hit = $search->invoke( $admin, 'all', $literal, 'customer_name', 1 );
	$assert( array( $closed_guest ) === array_map( 'intval', wp_list_pluck( $hit->posts, 'ID' ) ), 'Guest name search safely treats percent, underscore and quotes as literal text.' );
	SDPR_Reservation_Meta::update( $shared, SDPR_Reservation_Meta::NAME, $literal );
	$hit = $search->invoke( $admin, 'all', $literal, 'customer_name', 1 );
	$assert( 1 === (int) $hit->found_posts, 'Guest metadata cannot impersonate account display-name search.' );
	$filtered = $search->invoke( $admin, 'active', $keyword, 'customer_name', 1 );
	$assert( 0 === (int) $filtered->found_posts, 'Status filters still restrict database-side customer matching.' );

	$nested = null;
	$hook = static function ( $query ) use ( &$nested, $account ) {
		if ( null === $nested && 'sdpr_reservation' === $query->get( 'post_type' ) ) {
			$nested = false;
			$nested = get_posts( array( 'post_type' => 'sdpr_reservation', 'post__in' => array( $account ), 'fields' => 'ids', 'suppress_filters' => false ) );
		}
	};
	add_action( 'pre_get_posts', $hook );
	$empty = SDPR_Reservation_Query::run( array( 'fields' => 'ids', 'posts_per_page' => 1 ), '1 = 0' );
	remove_action( 'pre_get_posts', $hook );
	$assert( ! $empty->posts && array( $account ) === array_map( 'intval', $nested ), 'Prepared predicates are scoped to one query and do not leak into nested queries.' );
	$throw = static function ( $where ) { throw new RuntimeException( 'SDPR expected query exception' ); };
	$filter_count = static function () {
		global $wp_filter;
		return isset( $wp_filter['posts_where'] ) ? array_sum( array_map( 'count', $wp_filter['posts_where']->callbacks ) ) : 0;
	};
	$before_filters = $filter_count();
	add_filter( 'posts_where', $throw, 20 );
	try { SDPR_Reservation_Query::run( array(), '1 = 0' ); } catch ( RuntimeException $error ) { }
	remove_filter( 'posts_where', $throw, 20 );
	$after = get_posts( array( 'post_type' => 'sdpr_reservation', 'post__in' => array( $account ), 'fields' => 'ids', 'suppress_filters' => false ) );
	$assert( $before_filters === $filter_count() && array( $account ) === array_map( 'intval', $after ), 'Query filters are removed even when another query hook throws.' );
} finally {
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { wp_delete_user( $id ); }
}
if ( $failures ) { WP_CLI::error( implode( '; ', $failures ) ); }
WP_CLI::success( 'All ' . $checks . ' query-efficiency assertions passed.' );

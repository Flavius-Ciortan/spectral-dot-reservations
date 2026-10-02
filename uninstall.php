<?php
/** Remove plugin data and release any outstanding stock holds. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-sdpr-reservation-status.php';
require_once __DIR__ . '/includes/class-sdpr-reservation-meta.php';

function sdpr_uninstall_site_data() {
	do {
		$ids = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $reservation_id ) {
			$status          = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
			$inventory_state = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::INVENTORY_STATE );
			$owns_stock      = 'held' === $inventory_state || ( ! $inventory_state && SDPR_Reservation_Status::ACTIVE === $status );
			if ( $owns_stock && function_exists( 'wc_update_product_stock' ) ) {
				$product = wc_get_product( (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ) );
				if ( $product ) {
					$quantity = max( 1, (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ) );
					wc_update_product_stock( $product, $quantity, 'increase' );
				}
			}
			wp_delete_post( $reservation_id, true );
		}
	} while ( count( $ids ) === 200 );
	delete_option( 'sdpr_options' );
	delete_option( 'sdpr_version' );
	delete_option( 'sdpr_inventory_state_version' );

	global $wpdb;
	delete_metadata( 'user', 0, $wpdb->prefix . 'sdpr_dismissed_notices', '', true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove unknown dynamic lock keys and does not run during a cached request.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'sdpr_lock_' ) . '%' ) );
	wp_clear_scheduled_hook( 'sdpr_expire_reservations' );
}

if ( is_multisite() ) {
	$sdpr_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sdpr_site_ids as $sdpr_site_id ) {
		switch_to_blog( $sdpr_site_id );
		sdpr_uninstall_site_data();
		restore_current_blog();
	}
} else {
	sdpr_uninstall_site_data();
}

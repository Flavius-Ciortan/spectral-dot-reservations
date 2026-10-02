<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_BROWSER_CHECKOUT_TEST' ) ) {
	exit( 1 );
}

$context = get_option( 'sdpr_browser_checkout_context', array() );
foreach ( array( 'classic_product', 'blocks_product' ) as $product_key ) {
	if ( empty( $context[ $product_key ] ) ) {
		continue;
	}
	$reservation_ids = get_posts(
		array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'meta_key'       => SDPR_Reservation_Meta::PRODUCT_ID,
			'meta_value'     => (int) $context[ $product_key ],
		)
	);
	foreach ( $reservation_ids as $reservation_id ) {
		$order_id = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::ORDER_ID );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			$order->delete( true );
		}
		wp_delete_post( $reservation_id, true );
	}
	wp_delete_post( (int) $context[ $product_key ], true );
}

if ( ! empty( $context['classic_page'] ) ) {
	wp_delete_post( (int) $context['classic_page'], true );
}
foreach ( array( 'user_id', 'shop_manager_id', 'administrator_id' ) as $user_key ) {
	if ( ! empty( $context[ $user_key ] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $context[ $user_key ] );
	}
}

if ( '__sdpr_missing_option__' === ( $context['original_options'] ?? '__sdpr_missing_option__' ) ) {
	delete_option( 'sdpr_options' );
} else {
	update_option( 'sdpr_options', $context['original_options'] );
}
update_option( 'woocommerce_checkout_page_id', (int) ( $context['original_checkout'] ?? 0 ) );
if ( '__sdpr_missing_option__' === ( $context['original_cod'] ?? '__sdpr_missing_option__' ) ) {
	delete_option( 'woocommerce_cod_settings' );
} else {
	update_option( 'woocommerce_cod_settings', $context['original_cod'] );
}
if ( '__sdpr_missing_option__' === ( $context['original_coming_soon'] ?? '__sdpr_missing_option__' ) ) {
	delete_option( 'woocommerce_coming_soon' );
} else {
	update_option( 'woocommerce_coming_soon', $context['original_coming_soon'] );
}
if ( '__sdpr_missing_option__' === ( $context['original_store_pages'] ?? '__sdpr_missing_option__' ) ) {
	delete_option( 'woocommerce_store_pages_only' );
} else {
	update_option( 'woocommerce_store_pages_only', $context['original_store_pages'] );
}
delete_option( 'sdpr_browser_checkout_context' );

echo "PASS: Browser checkout fixtures cleaned.\n";

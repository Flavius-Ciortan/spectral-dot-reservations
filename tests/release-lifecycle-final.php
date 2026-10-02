<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_ALLOW_DESTRUCTIVE_RELEASE_TEST' ) ) {
	exit( 1 );
}

$product_id = (int) getenv( 'SDPR_RELEASE_PRODUCT_ID' );
$user_id    = (int) getenv( 'SDPR_RELEASE_USER_ID' );
$plugin     = SDPR_Plugin::get_instance();
$valid      = $plugin->get_service( 'lifecycle' ) instanceof SDPR_Reservation_Lifecycle_Interface
	&& (bool) wp_next_scheduled( SDPR_Reservations::CRON_HOOK );

wp_delete_post( $product_id, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user_id );

if ( ! $valid ) {
	exit( 1 );
}

echo "PASS: Plugin reactivates cleanly after complete uninstall.\n";

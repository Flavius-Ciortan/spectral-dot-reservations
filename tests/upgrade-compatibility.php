<?php
/** Run before and after replacing the published 1.0.0 ZIP in a disposable site. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_UPGRADE_TEST' ) ) {
	exit( 1 );
}
$phase = getenv( 'SDPR_UPGRADE_PHASE' );
$plugin = SDPR_Plugin::get_instance();
if ( 'before' === $phase ) {
	if ( '1.0.0' !== SDPR_VERSION || get_option( 'sdpr_upgrade_fixture' ) ) {
		WP_CLI::error( 'Expected published 1.0.0 and no existing upgrade fixture.' );
	}
	$context = array( 'options' => get_option( 'sdpr_options', false ), 'products' => array(), 'holds' => array(), 'snapshots' => array() );
	$user = wp_insert_user( array( 'user_login' => 'sdpr-upgrade-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => wp_generate_password( 10, false ) . '@example.test', 'role' => 'customer' ) );
	if ( is_wp_error( $user ) ) {
		WP_CLI::error( 'Could not prepare the upgrade test customer.' );
	}
	$context['user'] = $user;
	wp_set_current_user( $user );
	foreach ( array( 'active', 'pending' ) as $state ) {
		$options = array( 'enable_reservation' => 1, 'max_reservations' => 7, 'reservation_duration' => 36, 'pending_duration' => 12, 'require_admin_approval' => 'pending' === $state ? 1 : 0, 'enable_email_notifications' => 0 );
		update_option( 'sdpr_options', $options );
		$product = new WC_Product_Simple();
		$product->set_name( 'Published-version upgrade fixture ' . $state );
		$product->set_status( 'publish' );
		$product->set_regular_price( 15 );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$context['products'][ $state ] = $product->save();
		$result = $plugin->get_service( 'lifecycle' )->request( $product->get_id(), $user );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$id = $result['reservation_id'];
		$context['holds'][ $state ] = $id;
		$context['snapshots'][ $state ] = get_post_meta( $id );
	}
	$context['expected_options'] = get_option( 'sdpr_options' );
	update_option( 'sdpr_upgrade_fixture', $context, false );
	WP_CLI::success( 'Published 1.0.0 active and pending reservations prepared with canonical data snapshots.' );
	return;
}
if ( 'after' !== $phase || '1.1.0' !== SDPR_VERSION ) {
	WP_CLI::error( 'Expected the 1.1.0 package for post-upgrade verification.' );
}
$context = get_option( 'sdpr_upgrade_fixture', array() );
if ( empty( $context['holds'] ) ) {
	WP_CLI::error( 'Missing published-version upgrade fixtures.' );
}
$failures = array();
try {
	if ( $context['expected_options'] !== get_option( 'sdpr_options' ) ) {
		$failures[] = 'Existing merchant settings changed during upgrade.';
	}
	foreach ( $context['holds'] as $state => $id ) {
		if ( $context['snapshots'][ $state ] !== get_post_meta( $id ) ) {
			$failures[] = 'Existing reservation metadata changed during upgrade: ' . $state;
		}
		$expected_stock = 'active' === $state ? 4 : 5;
		if ( $expected_stock !== SDPR_Reservation_Product::stock_quantity( $context['products'][ $state ] ) ) {
			$failures[] = 'Upgrade changed existing inventory: ' . $state;
		}
	}
	wp_set_current_user( $context['user'] );
	$duplicate = $plugin->get_service( 'lifecycle' )->request( $context['products']['active'], $context['user'] );
	if ( ! is_wp_error( $duplicate ) || 'sdpr_duplicate' !== $duplicate->get_error_code() ) {
		$failures[] = 'Upgrade lost duplicate protection for the published active hold.';
	}
	if ( true !== $plugin->reservations->approve_reservation( $context['holds']['pending'] ) ) {
		$failures[] = 'Published pending request could not be approved after upgrade.';
	}
	foreach ( $context['holds'] as $state => $id ) {
		$plugin->reservations->cancel_reservation( $id );
		$plugin->reservations->cancel_reservation( $id );
		if ( 5 !== SDPR_Reservation_Product::stock_quantity( $context['products'][ $state ] ) ) {
			$failures[] = 'Published hold did not restore stock exactly once: ' . $state;
		}
	}
} finally {
	foreach ( $context['holds'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $context['products'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $context['user'] );
	if ( false === $context['options'] ) {
		delete_option( 'sdpr_options' );
	} else {
		update_option( 'sdpr_options', $context['options'] );
	}
	delete_option( 'sdpr_upgrade_fixture' );
}
if ( $failures ) {
	WP_CLI::error( implode( ' ', $failures ) );
}
WP_CLI::success( 'Exact 1.0.0 to 1.1.0 upgrade preserves settings, reservation metadata, inventory and lifecycle behavior.' );

<?php
/** Run only in a disposable WordPress test site using WP-CLI eval-file. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_ADMIN_LIST_TEST' ) ) {
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-view.php';
require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-reservations.php';
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
class SDPR_Admin_List_Test_Exit extends RuntimeException {}
$failures = array();
$assert = static function ( $condition, $message ) use ( &$failures ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$die_filter = static function () {
	return static function () { throw new SDPR_Admin_List_Test_Exit(); };
};
$original_options = get_option( 'sdpr_options', null );
$original_user = get_current_user_id();
$original_post = $_POST;
$original_request = $_REQUEST;
$product_id = 0;
$reservation_ids = array();
$user_id = wp_insert_user( array(
	'user_login' => 'sdpr-list-' . wp_generate_password( 10, false ),
	'user_pass' => wp_generate_password( 24 ),
	'role' => 'administrator',
) );
if ( is_wp_error( $user_id ) ) {
	WP_CLI::error( $user_id->get_error_message() );
}
add_filter( 'wp_die_ajax_handler', $die_filter );
$lifecycle = SDPR_Plugin::get_instance()->get_service( 'lifecycle' );
$admin = new SDPR_Admin_Reservations();
$call = static function ( $method, $nonce_action, $id, $filters = array() ) use ( $admin ) {
	$_POST = array_merge( array( 'reservation_id' => $id, 'reason' => 'Test denial' ), $filters );
	$_REQUEST['nonce'] = wp_create_nonce( $nonce_action );
	ob_start();
	try {
		$admin->$method();
	} catch ( SDPR_Admin_List_Test_Exit $exit ) {
		// Capture the actual handler response instead of terminating WP-CLI.
	}
	return json_decode( ob_get_clean(), true );
};
try {
	wp_set_current_user( $user_id );
	update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'require_admin_approval' => 1, 'reservation_duration' => 24, 'pending_duration' => 1, 'enable_email_notifications' => 0, 'max_reservations' => 5 ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'Admin List Test Product' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 5 );
	$product_id = $product->save();
	$result = $lifecycle->request( $product_id, $user_id );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$id = $result['reservation_id'];
	$reservation_ids[] = $id;
	$old_expiry = SDPR_Reservation_Meta::get( $id, SDPR_Reservation_Meta::EXPIRES_AT );
	ob_start();
	$admin->render_page();
	$before = ob_get_clean();
	$assert( false !== strpos( $before, '<span class="sdpr-time-left time-left-critical">' ), 'Pending expiry uses a compact badge inside its table cell.' );
	$assert( ! preg_match( '/<td[^>]*class="[^"]*time-left-(critical|warning)/', $before ), 'Urgency styling never transforms or borders the entire table cell.' );
	preg_match( '/<strong>Active:<\/strong>\s*(\d+)/', $before, $active_before );
	preg_match( '/<strong>Pending Approval:<\/strong>\s*(\d+)/', $before, $pending_before );
	preg_match( '/<strong>Total:<\/strong>\s*(\d+)/', $before, $total_before );
	$filters = array( 'list_search_type' => 'product_id', 'list_search' => (string) $product_id );
	$response = $call( 'handle_approve_reservation', 'sdpr_admin_approve', $id, $filters );
	$assert( ! empty( $response['success'] ) && 'Reservation approved successfully.' === $response['data']['message'], 'Approval returns its translated success message.' );
	$content = $response['data']['content'];
	preg_match( '/<strong>Active:<\/strong>\s*(\d+)/', $content, $active_after );
	preg_match( '/<strong>Pending Approval:<\/strong>\s*(\d+)/', $content, $pending_after );
	$assert( (int) $active_after[1] === (int) $active_before[1] + 1 && (int) $pending_after[1] === (int) $pending_before[1] - 1, 'The refreshed summary updates both Active and Pending counts.' );
	$new_expiry = SDPR_Reservation_Meta::get( $id, SDPR_Reservation_Meta::EXPIRES_AT );
	$assert( $new_expiry > $old_expiry + 22 * HOUR_IN_SECONDS, 'Approval resets the expiry using the active reservation duration.' );
	$assert( false !== strpos( $content, wp_date( 'M j, Y @ H:i', $new_expiry ) ), 'The action response displays the new expiry, not the pending expiry.' );
	$assert( false !== strpos( $content, 'status-active' ) && false !== strpos( $content, 'sdpr-cancel-reservation' ), 'The refreshed list shows Active with the canonical Cancel action.' );
	$assert( false !== strpos( $content, 'class="sdpr-reservations-filters"' ) && false === strpos( $content, 'class="tablenav top"' ), 'The filter toolbar avoids the WordPress class that hides actions on mobile.' );
	$assert( false !== strpos( $content, 'tabindex="0" role="region" aria-label="Reservation details"' ), 'The reservation table has a named keyboard-focusable scroll region.' );
	$assert( 4 === (int) wc_get_product( $product_id )->get_stock_quantity( 'edit' ), 'Approval reduces stock once.' );
	$response = $call( 'handle_admin_cancel_reservation', 'sdpr_admin_cancel', $id, array_merge( $filters, array( 'list_status' => SDPR_Reservation_Status::ACTIVE ) ) );
	$assert( ! empty( $response['success'] ) && false !== strpos( $response['data']['content'], 'No reservations found matching your criteria.' ), 'Cancellation removes the row from an Active-filtered view.' );
	$assert( 5 === (int) wc_get_product( $product_id )->get_stock_quantity( 'edit' ), 'Cancellation restores stock once.' );
	$response = $call( 'handle_admin_delete_reservation', 'sdpr_admin_delete', $id, $filters );
	$assert( ! empty( $response['success'] ) && false !== strpos( $response['data']['content'], '0 reservations' ), 'Deletion refreshes the result count and empty state.' );
	preg_match( '/<strong>Total:<\/strong>\s*(\d+)/', $response['data']['content'], $total_after );
	$assert( (int) $total_after[1] === (int) $total_before[1] - 1, 'Deletion also refreshes the global reservation total.' );
	$result = $lifecycle->request( $product_id, $user_id );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$id = $result['reservation_id'];
	$reservation_ids[] = $id;
	$response = $call( 'handle_deny_reservation', 'sdpr_admin_deny', $id, array_merge( $filters, array( 'list_status' => SDPR_Reservation_Status::PENDING ) ) );
	$assert( ! empty( $response['success'] ) && false !== strpos( $response['data']['content'], 'No reservations found matching your criteria.' ), 'Denial removes the row from a Pending-filtered view.' );
	$assert( 5 === (int) wc_get_product( $product_id )->get_stock_quantity( 'edit' ), 'Denial does not change stock.' );
	for ( $index = 0; $index < 26; $index++ ) {
		$record = wp_insert_post( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'post_title' => 'Admin List Test Record' ) );
		SDPR_Reservation_Meta::update( $record, SDPR_Reservation_Meta::PRODUCT_ID, $product_id );
		SDPR_Reservation_Meta::update( $record, SDPR_Reservation_Meta::STATUS, SDPR_Reservation_Status::DENIED );
		$reservation_ids[] = $record;
	}
	$response = $call( 'handle_admin_delete_reservation', 'sdpr_admin_delete', $id, array_merge( $filters, array( 'list_paged' => 2 ) ) );
	$assert( ! empty( $response['success'] ) && false !== strpos( $response['data']['content'], 'page=sdpr-manage-reservations' ) && false === strpos( $response['data']['content'], 'admin-ajax.php' ), 'Pagination in an AJAX response points to the reservation admin page.' );
	$response = $call( 'handle_admin_delete_reservation', 'sdpr_admin_delete', end( $reservation_ids ), array_merge( $filters, array( 'list_paged' => 2 ) ) );
	$content = $response['data']['content'];
	$assert( ! empty( $response['success'] ) && false !== strpos( $content, '25 reservations' ) && false !== strpos( $content, 'id="sdpr-list-page" value="1"' ), 'Deleting the last item on page two clamps to the remaining page.' );
	$assert( 25 === substr_count( $content, 'data-reservation-id=' ), 'The clamped page contains all remaining result rows.' );
	$response = $call( 'handle_admin_delete_reservation', 'sdpr_admin_delete', $reservation_ids[ count( $reservation_ids ) - 2 ], array_merge( $filters, array( 'list_status' => 'invalid', 'list_search_type' => 'invalid', 'list_search' => '<script>alert(1)</script>', 'list_paged' => 999 ) ) );
	$assert( ! empty( $response['success'] ) && false === strpos( $response['data']['content'], '<script>' ), 'Posted filters are normalized and cannot inject executable markup.' );
} finally {
	foreach ( $reservation_ids as $reservation_id ) {
		$status = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
		if ( in_array( $status, array( SDPR_Reservation_Status::ACTIVE, SDPR_Reservation_Status::PENDING ), true ) ) {
			$lifecycle->cancel( $reservation_id );
		}
		wp_delete_post( $reservation_id, true );
	}
	if ( $product_id ) {
		wp_delete_post( $product_id, true );
	}
	if ( null === $original_options ) {
		delete_option( 'sdpr_options' );
	} else {
		update_option( 'sdpr_options', $original_options );
	}
	$_POST = $original_post;
	$_REQUEST = $original_request;
	remove_filter( 'wp_die_ajax_handler', $die_filter );
	wp_set_current_user( $original_user );
	wp_delete_user( $user_id );
}
if ( $failures ) {
	WP_CLI::error( count( $failures ) . ' admin list assertions failed.' );
}
WP_CLI::success( 'All admin list action assertions passed.' );

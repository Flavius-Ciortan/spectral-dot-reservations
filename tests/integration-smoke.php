<?php
if ( ! defined( 'SDPR_INTEGRATION_TEST' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		$wp_load = '/wordpress/wp-load.php';
	}
	require $wp_load;
}

$GLOBALS['sdpr_failures'] = array();
function sdpr_assert( $condition, $message ) {
	global $sdpr_failures;
	if ( ! $condition ) {
		$sdpr_failures[] = $message;
		echo esc_html( "FAIL: {$message}\n" );
	} else {
		echo esc_html( "PASS: {$message}\n" );
	}
}

$sdpr_plugin = SDPR_Plugin::get_instance();
$sdpr_original_options = get_option( 'sdpr_options', false );
sdpr_assert( '1.0.0' === SDPR_VERSION, 'Runtime version matches the initial public release.' );
sdpr_assert( $sdpr_plugin->reservations instanceof SDPR_Reservations, 'Reservation service initialized.' );
sdpr_assert( $sdpr_plugin->get_service( 'repository' ) instanceof SDPR_Reservation_Repository, 'Reservation repository is registered.' );
sdpr_assert( $sdpr_plugin->get_service( 'cart_order' ) instanceof SDPR_Cart_Order_Service, 'Cart and order service is registered.' );
sdpr_assert( $sdpr_plugin->get_service( 'expiration' ) instanceof SDPR_Expiration_Service, 'Expiration service is registered.' );
sdpr_assert( $sdpr_plugin->get_service( 'repository' ) instanceof SDPR_Reservation_Repository_Interface, 'Repository implements its extension contract.' );
sdpr_assert( $sdpr_plugin->get_service( 'lifecycle' ) instanceof SDPR_Reservation_Lifecycle_Interface, 'Lifecycle implements its extension contract.' );
sdpr_assert( $sdpr_plugin->get_service( 'rules' ) instanceof SDPR_Reservation_Rules, 'Reservation rules service is registered.' );
sdpr_assert( $sdpr_plugin->get_service( 'locks' ) instanceof SDPR_Lock_Manager, 'Reservation lock service is registered.' );
$sdpr_lock_name = 'integration_exclusivity_' . wp_generate_password( 8, false );
$sdpr_locks     = $sdpr_plugin->get_service( 'locks' );
$sdpr_first     = $sdpr_locks->acquire( array( $sdpr_lock_name ) );
$sdpr_second    = $sdpr_locks->acquire( array( $sdpr_lock_name ) );
sdpr_assert( is_array( $sdpr_first ) && is_wp_error( $sdpr_second ) && 'sdpr_busy' === $sdpr_second->get_error_code(), 'Reservation locks remain exclusive on current WordPress versions.' );
$sdpr_locks->release( array( 'sdpr_lock_' . sanitize_key( $sdpr_lock_name ) => 'not-the-owner' ) );
$sdpr_third = $sdpr_locks->acquire( array( $sdpr_lock_name ) );
sdpr_assert( is_wp_error( $sdpr_third ), 'A non-owner cannot release a reservation lock.' );
$sdpr_locks->release( $sdpr_first );
$sdpr_reacquired = $sdpr_locks->acquire( array( $sdpr_lock_name ) );
sdpr_assert( is_array( $sdpr_reacquired ), 'A reservation lock can be acquired after its owner releases it.' );
$sdpr_locks->release( $sdpr_reacquired );
sdpr_assert( false !== has_action( 'woocommerce_single_product_summary', array( $sdpr_plugin->frontend, 'display_reservation_fallback' ) ), 'Frontend has a dedicated sold-out product fallback for add-on waitlists.' );
sdpr_assert( false !== has_filter( 'render_block_woocommerce/add-to-cart-form', array( $sdpr_plugin->frontend, 'add_reservation_block_classes' ) ), 'Frontend registers block Add to Cart layout integration.' );
$sdpr_block_markup = '<div class="wp-block-add-to-cart-form wc-block-add-to-cart-form"><form class="cart"><button id="sdpr_reserve_product" type="button">Reserve</button></form></div>';
$sdpr_block_markup = $sdpr_plugin->frontend->add_reservation_block_classes( $sdpr_block_markup, array() );
sdpr_assert( false !== strpos( $sdpr_block_markup, 'sdpr-has-reserve-action' ) && false !== strpos( $sdpr_block_markup, 'sdpr-cart-actions' ), 'Reservation-enabled Add to Cart blocks receive stable layout classes.' );
$sdpr_dependency_notices = $sdpr_plugin->get_service( 'dependency_notices' );
sdpr_assert( $sdpr_dependency_notices instanceof SDPR_Dependency_Notices, 'Add-on dependency notice service is registered.' );
sdpr_assert( $sdpr_dependency_notices->add( 'sdpr-contract-test', 'Dependency contract test.', 'warning' ) && isset( $sdpr_dependency_notices->all()['sdpr-contract-test'] ), 'Add-ons can register a dependency notice through the shared contract.' );
$sdpr_dependency_notices->remove( 'sdpr-contract-test' );
sdpr_assert( post_type_exists( 'sdpr_reservation' ), 'Reservation post type is registered during normal bootstrap.' );

wp_clear_scheduled_hook( SDPR_Reservations::CRON_HOOK );
sdpr_assert( ! wp_next_scheduled( SDPR_Reservations::CRON_HOOK ), 'Expiration schedule can be removed for recovery test.' );
$sdpr_plugin->reservations->schedule_expiration();
sdpr_assert( (bool) wp_next_scheduled( SDPR_Reservations::CRON_HOOK ), 'Missing expiration schedule is recreated.' );

require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-view.php';
require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-reservations.php';
require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin.php';
$sdpr_admin = new SDPR_Admin( $sdpr_plugin->reservations );
$sdpr_admin_reservations = new SDPR_Admin_Reservations( $sdpr_plugin->reservations );
$sdpr_admin_reservations->enqueue_assets();
sdpr_assert( wp_script_is( 'sdpr-admin-reservations', 'enqueued' ), 'Reservation admin actions use a versioned external asset.' );
sdpr_assert( false !== strpos( (string) wp_scripts()->get_data( 'sdpr-admin-reservations', 'data' ), 'sdprReservationsAdmin' ), 'Reservation admin asset receives localized nonces and messages.' );
sdpr_assert( false !== strpos( (string) wp_scripts()->get_data( 'sdpr-admin-reservations', 'data' ), 'Reason for denial (optional)' ), 'Inline denial reason has a translated accessible label.' );
sdpr_assert( false !== strpos( (string) wp_scripts()->get_data( 'sdpr-admin-reservations', 'data' ), 'Deny request' ) && false !== strpos( (string) wp_scripts()->get_data( 'sdpr-admin-reservations', 'data' ), 'Keep request' ), 'Inline denial provides translated submit and non-destructive cancel controls.' );
$sdpr_admin->enqueue_admin_scripts( 'toplevel_page_sdpr-settings' );
sdpr_assert( wp_script_is( 'sdpr-admin-settings', 'enqueued' ), 'Settings interactions use a versioned external asset.' );
$sdpr_filtered_method = new ReflectionMethod( $sdpr_admin_reservations, 'get_filtered_reservations' );
$sdpr_filtered_method->setAccessible( true );
$sdpr_invalid_product_query = $sdpr_filtered_method->invoke( $sdpr_admin_reservations, 'all', 'not-a-number', 'product_id', 1 );
$sdpr_missing_product_query = $sdpr_filtered_method->invoke( $sdpr_admin_reservations, 'all', 'SDPR product that cannot exist 19f546ef', 'product', 1 );
sdpr_assert( $sdpr_invalid_product_query instanceof WP_Query && 0 === (int) $sdpr_invalid_product_query->found_posts, 'Invalid product ID search returns an empty WP_Query.' );
sdpr_assert( $sdpr_missing_product_query instanceof WP_Query && 0 === (int) $sdpr_missing_product_query->found_posts, 'Missing product search returns an empty WP_Query.' );
sdpr_assert( get_role( 'shop_manager' ) && get_role( 'shop_manager' )->has_cap( sdpr_get_manage_capability() ), 'Shop Managers have the reservation management capability.' );
sdpr_assert( sdpr_get_manage_capability() === apply_filters( 'option_page_capability_sdpr_options_group', 'manage_options' ), 'Settings saves use the same merchant capability as the reservation menu.' );
sdpr_assert( 'manage_options' === apply_filters( 'option_page_capability_options', 'manage_options' ), 'Reservation settings do not broaden unrelated WordPress option access.' );
sdpr_assert( ! get_role( 'customer' )->has_cap( apply_filters( 'option_page_capability_sdpr_options_group', 'manage_options' ) ), 'Customers cannot save reservation settings.' );
sdpr_assert( ! get_role( 'subscriber' )->has_cap( apply_filters( 'option_page_capability_sdpr_options_group', 'manage_options' ) ), 'Subscribers cannot save reservation settings.' );
$sdpr_sanitized = $sdpr_admin->sanitize_options( array( 'max_reservations' => 999, 'reservation_duration' => -2, 'popup_customization_logged_in' => array( 'font_family' => 'Arial;background:url(x)', 'background_color' => 'bad' ) ) );
sdpr_assert( 100 === $sdpr_sanitized['max_reservations'], 'Reservation limit is bounded.' );
sdpr_assert( 1 === $sdpr_sanitized['reservation_duration'], 'Duration is bounded.' );
sdpr_assert( 'Arial, Helvetica, sans-serif' === $sdpr_sanitized['popup_customization_logged_in']['font_family'], 'Font value is allowlisted.' );
$sdpr_sanitized = $sdpr_admin->sanitize_options( array(
	'max_reservations' => 0,
	'reservation_duration' => 999,
	'popup_customization_logged_in' => array(
		'border_radius' => -10,
		'font_size' => 999,
		'background_color' => 'invalid',
		'text_color' => '#123456',
	),
) );
sdpr_assert( 1 === $sdpr_sanitized['max_reservations'], 'Zero reservation limit is raised to one.' );
sdpr_assert( 168 === $sdpr_sanitized['reservation_duration'], 'Excessive duration is capped.' );
sdpr_assert( 0 === $sdpr_sanitized['popup_customization_logged_in']['border_radius'], 'Negative border radius is raised to zero.' );
sdpr_assert( 40 === $sdpr_sanitized['popup_customization_logged_in']['font_size'], 'Excessive font size is capped.' );
sdpr_assert( '#ffffff' === $sdpr_sanitized['popup_customization_logged_in']['background_color'], 'Invalid popup color uses the default.' );
sdpr_assert( ! empty( get_settings_errors( 'sdpr_options' ) ), 'Corrected settings produce validation feedback.' );

update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 1, 'enable_email_notifications' => 0 ) );
$sdpr_preserved = $sdpr_admin->sanitize_options( array( 'max_reservations' => 3, 'reservation_duration' => 12 ) );
sdpr_assert( 1 === $sdpr_preserved['pending_duration'], 'Hidden pending duration is preserved when settings are saved.' );
$sdpr_user_id = wp_insert_user( array( 'user_login' => 'sdpr-test-user', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'sdpr@example.test', 'role' => 'customer' ) );
wp_set_current_user( $sdpr_user_id );
$sdpr_product = new WC_Product_Simple();
$sdpr_product->set_name( 'Reservation test product' );
$sdpr_product->set_status( 'publish' );
$sdpr_product->set_regular_price( '10' );
$sdpr_product->set_manage_stock( true );
$sdpr_product->set_stock_quantity( 1 );
$sdpr_product_id = $sdpr_product->save();

$sdpr_immediate_product = new WC_Product_Simple();
$sdpr_immediate_product->set_name( 'Immediate reservation test product' );
$sdpr_immediate_product->set_status( 'publish' );
$sdpr_immediate_product->set_regular_price( '10' );
$sdpr_immediate_product->set_manage_stock( true );
$sdpr_immediate_product->set_stock_quantity( 2 );
$sdpr_immediate_product_id = $sdpr_immediate_product->save();
$sdpr_original_product       = $GLOBALS['product'] ?? null;
$sdpr_original_query_object  = $GLOBALS['wp_query']->queried_object;
$sdpr_original_query_id      = $GLOBALS['wp_query']->queried_object_id;
$sdpr_original_is_singular   = $GLOBALS['wp_query']->is_singular;
$GLOBALS['product']         = wc_get_product( $sdpr_immediate_product_id );
$GLOBALS['wp_query']->queried_object    = get_post( $sdpr_immediate_product_id );
$GLOBALS['wp_query']->queried_object_id = $sdpr_immediate_product_id;
$GLOBALS['wp_query']->is_singular       = true;
ob_start();
$sdpr_plugin->frontend->display_reservation_fallback();
$sdpr_in_stock_fallback_markup = ob_get_clean();
sdpr_assert( '' === $sdpr_in_stock_fallback_markup, 'In-stock products wait for the standard Add to Cart button hook.' );
ob_start();
( static function () { include SDPR_PLUGIN_PATH . 'templates/form-template.php'; } )();
$sdpr_reserve_button_markup = ob_get_clean();
sdpr_assert( 1 === preg_match( '/<svg[^>]*aria-hidden="true"[^>]*>.*<\/svg>\s*<span>Reserve<\/span>\s*<\/button>/s', $sdpr_reserve_button_markup ), 'The product action pairs a decorative stopwatch with the concise Reserve label.' );
$sdpr_waitlist_product = new WC_Product_Simple();
$sdpr_waitlist_product->set_name( 'Waitlist fallback test product' );
$sdpr_waitlist_product->set_status( 'publish' );
$sdpr_waitlist_product->set_regular_price( '10' );
$sdpr_waitlist_product->set_manage_stock( true );
$sdpr_waitlist_product->set_stock_quantity( 0 );
$sdpr_waitlist_product->set_stock_status( 'outofstock' );
$sdpr_waitlist_product_id = $sdpr_waitlist_product->save();
$sdpr_waitlist_eligibility = static function ( $reservable, $product ) use ( $sdpr_waitlist_product_id ) {
	return $product instanceof WC_Product && $sdpr_waitlist_product_id === $product->get_id() ? true : $reservable;
};
add_filter( 'sdpr_product_is_reservable', $sdpr_waitlist_eligibility, 10, 2 );
$GLOBALS['product'] = wc_get_product( $sdpr_waitlist_product_id );
$GLOBALS['wp_query']->queried_object    = get_post( $sdpr_waitlist_product_id );
$GLOBALS['wp_query']->queried_object_id = $sdpr_waitlist_product_id;
ob_start();
$sdpr_plugin->frontend->display_reservation_fallback();
$sdpr_waitlist_fallback_markup = ob_get_clean();
remove_filter( 'sdpr_product_is_reservable', $sdpr_waitlist_eligibility, 10 );
sdpr_assert( 1 === preg_match( '/<svg[^>]*aria-hidden="true"[^>]*>.*<\/svg>\s*<span>Reserve<\/span>\s*<\/button>/s', $sdpr_waitlist_fallback_markup ), 'Extension-enabled sold-out products retain the dedicated fallback action.' );
$GLOBALS['product'] = wc_get_product( $sdpr_immediate_product_id );
ob_start();
( static function () { include SDPR_PLUGIN_PATH . 'templates/modal-template.php'; } )();
$sdpr_modal_markup   = ob_get_clean();
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'up to 1 hour.' ) && false !== strpos( $sdpr_modal_markup, 'held for 24 hours.' ), 'Approval dialog substitutes and pluralizes both translated durations.' );
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'data-result-title="Request submitted"' ), 'Pending reservation modal provides its translated result heading.' );
sdpr_assert( strpos( $sdpr_modal_markup, 'class="sdpr-modal-header"' ) < strpos( $sdpr_modal_markup, '<form id="sdpr-reservation-form"' ) && 1 === preg_match( '/class="sdpr-modal-header">.*?class="modal-close".*?<\/div>\s*<form/s', $sdpr_modal_markup ), 'The modal heading and close control occupy a separate header before notices.' );
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'id="sdpr-reservation-result"' ) && false !== strpos( $sdpr_modal_markup, 'class="sdpr-reservation-prompt"' ), 'Modal result and confirmation content have separate accessible targets.' );
$sdpr_modal_options = get_option( 'sdpr_options' );
$sdpr_active_modal_options = $sdpr_modal_options;
$sdpr_active_modal_options['require_admin_approval'] = 0;
update_option( 'sdpr_options', $sdpr_active_modal_options );
ob_start();
( static function () { include SDPR_PLUGIN_PATH . 'templates/modal-template.php'; } )();
$sdpr_active_modal_markup = ob_get_clean();
update_option( 'sdpr_options', $sdpr_modal_options );
sdpr_assert( false !== strpos( $sdpr_active_modal_markup, 'data-result-title="Reservation confirmed"' ), 'Immediate reservation modal provides its translated result heading.' );
$GLOBALS['product']                     = $sdpr_original_product;
$GLOBALS['wp_query']->queried_object    = $sdpr_original_query_object;
$GLOBALS['wp_query']->queried_object_id = $sdpr_original_query_id;
$GLOBALS['wp_query']->is_singular       = $sdpr_original_is_singular;
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'aria-labelledby="sdpr-reservation-dialog-title"' ), 'Reservation dialog is associated with its visible heading.' );
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'aria-describedby="sdpr-reservation-dialog-description"' ), 'Reservation dialog is associated with its explanatory text.' );
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'class="modal-close"' ) && false !== strpos( $sdpr_modal_markup, 'aria-label="Close reservation dialog"' ), 'Reservation dialog has a keyboard-focusable named close control.' );
sdpr_assert( false !== strpos( $sdpr_modal_markup, 'aria-atomic="true"' ), 'Reservation result notice is exposed as an atomic live region.' );
$sdpr_original_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$GLOBALS['post'] = get_post( $sdpr_immediate_product_id );
$sdpr_admin->enqueue_admin_scripts( 'post.php' );
sdpr_assert( wp_script_is( 'sdpr-admin-product', 'enqueued' ), 'Product reservation actions use a versioned external asset.' );
sdpr_assert( false !== strpos( (string) wp_scripts()->get_data( 'sdpr-admin-product', 'data' ), 'sdprProductReservations' ), 'Product admin asset receives its localized nonce and messages.' );
$GLOBALS['post'] = $sdpr_original_post;
update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 0 ) );
$sdpr_eligibility_passthrough = static function ( $reservable ) {
	return $reservable;
};
$sdpr_reservable_before_filter = $sdpr_plugin->reservations->is_product_reservable( $sdpr_immediate_product_id );
add_filter( 'sdpr_product_is_reservable', $sdpr_eligibility_passthrough );
sdpr_assert( $sdpr_reservable_before_filter === $sdpr_plugin->reservations->is_product_reservable( $sdpr_immediate_product_id ), 'A no-op eligibility extension does not change Free behavior.' );
remove_filter( 'sdpr_product_is_reservable', $sdpr_eligibility_passthrough );
$sdpr_transitions = array();
$sdpr_transition_listener = static function ( $transition ) use ( &$sdpr_transitions ) {
	$sdpr_transitions[] = $transition;
};
add_action( 'sdpr_reservation_transitioned', $sdpr_transition_listener );
$sdpr_counts_before_immediate = $sdpr_plugin->reservations->get_status_counts();
$sdpr_immediate_id = $sdpr_plugin->reservations->create_reservation( $sdpr_immediate_product_id, $sdpr_user_id );
sdpr_assert( $sdpr_immediate_id && 'active' === get_post_meta( $sdpr_immediate_id, '_sdpr_status', true ), 'Immediate reservation activates through the inventory transaction.' );
ob_start();
( static function ( $sdpr_reservations ) { include SDPR_PLUGIN_PATH . 'templates/myaccount/my-reservations.php'; } )( array( get_post( $sdpr_immediate_id ) ) );
$sdpr_account_markup = ob_get_clean();
sdpr_assert( (bool) preg_match( '/<div class="sdpr-account-actions">.*?class="woocommerce-button button add-to-cart".*?class="woocommerce-button button cancel-reservation".*?<\/div>/s', $sdpr_account_markup ), 'Customer action buttons share a wrapping, gap-controlled container.' );
$sdpr_previous_post = $GLOBALS['post'] ?? null;
$GLOBALS['post'] = get_post( $sdpr_immediate_product_id );
ob_start();
$sdpr_admin->add_product_reservations_list();
$sdpr_product_reservations_markup = ob_get_clean();
$sdpr_admin->enqueue_admin_scripts( 'post.php' );
$GLOBALS['post'] = $sdpr_previous_post;
sdpr_assert( false !== strpos( $sdpr_product_reservations_markup, 'sdpr-product-reservation-feedback' ) && false !== strpos( (string) wp_scripts()->get_data( 'sdpr-admin-product', 'data' ), 'Dismiss this notice.' ), 'Product reservation actions provide local, dismissible and translated feedback.' );
$sdpr_product_name_query = $sdpr_filtered_method->invoke( $sdpr_admin_reservations, 'all', 'Immediate reservation test', 'product', 1 );
sdpr_assert( in_array( $sdpr_immediate_id, wp_list_pluck( $sdpr_product_name_query->posts, 'ID' ), true ), 'Product-name search uses the WordPress query API and returns matching reservations.' );
$sdpr_admin_render_deprecations = array();
set_error_handler(
	static function ( $severity, $message ) use ( &$sdpr_admin_render_deprecations ) {
		if ( E_DEPRECATED === $severity ) {
			$sdpr_admin_render_deprecations[] = $message;
		}
		return false;
	},
	E_DEPRECATED
);
ob_start();
$sdpr_admin_reservations->render_page();
$sdpr_admin_reservations_markup = ob_get_clean();
restore_error_handler();
sdpr_assert( empty( $sdpr_admin_render_deprecations ), 'Single-page reservation management renders without PHP deprecations.' );
sdpr_assert( false !== strpos( $sdpr_admin_reservations_markup, 'for="status-filter"' ) && false !== strpos( $sdpr_admin_reservations_markup, 'for="search-type"' ) && false !== strpos( $sdpr_admin_reservations_markup, 'for="reservation-search"' ), 'Reservation management filters expose programmatic labels.' );
$sdpr_counts_after_immediate = $sdpr_plugin->reservations->get_status_counts();
sdpr_assert( $sdpr_counts_before_immediate[ SDPR_Reservation_Status::ACTIVE ] + 1 === $sdpr_counts_after_immediate[ SDPR_Reservation_Status::ACTIVE ], 'Status-count cache is invalidated when a reservation becomes active.' );
sdpr_assert( $sdpr_immediate_product_id === (int) SDPR_Reservation_Meta::get( $sdpr_immediate_id, SDPR_Reservation_Meta::PRODUCT_ID ), 'Canonical metadata accessor reads reservation product data.' );
sdpr_assert( ! empty( $sdpr_transitions ) && SDPR_Reservation_Status::ACTIVE === $sdpr_transitions[0]['to'], 'Lifecycle transition action receives a stable transition payload.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_HELD === get_post_meta( $sdpr_immediate_id, SDPR_Inventory_Manager::META_STATE, true ), 'Immediate reservation records held inventory ownership.' );
sdpr_assert( 1 === (int) wc_get_product( $sdpr_immediate_product_id )->get_stock_quantity( 'edit' ), 'Immediate reservation decreases stock once.' );
$sdpr_expiry_before_extension = (int) SDPR_Reservation_Meta::get( $sdpr_immediate_id, SDPR_Reservation_Meta::EXPIRES_AT );
$sdpr_extension_event = array();
$sdpr_extension_listener = static function ( $event ) use ( &$sdpr_extension_event ) {
	$sdpr_extension_event = $event;
};
add_action( 'sdpr_reservation_extended', $sdpr_extension_listener );
$sdpr_extended_expiry = $sdpr_plugin->get_service( 'lifecycle' )->extend( $sdpr_immediate_id, 2, 'contract_test' );
sdpr_assert( $sdpr_expiry_before_extension + ( 2 * HOUR_IN_SECONDS ) === $sdpr_extended_expiry, 'Lifecycle extends an open deadline by the exact requested duration.' );
sdpr_assert( $sdpr_immediate_id === $sdpr_extension_event['reservation_id'] && 'contract_test' === $sdpr_extension_event['source'], 'Deadline extension emits its stable result contract.' );
$sdpr_email_content_seen = array();
$sdpr_email_result_seen = array();
$sdpr_email_filter = static function ( $content, $event, $reservation_id ) use ( &$sdpr_email_content_seen, $sdpr_immediate_id ) {
	if ( $sdpr_immediate_id === $reservation_id && 'created' === $event ) {
		$content['subject']     = 'Contract-filtered subject';
		$sdpr_email_content_seen = $content;
	}
	return $content;
};
$sdpr_mail_short_circuit = static function () {
	return true;
};
$sdpr_email_result_listener = static function ( $sent, $event, $reservation_id ) use ( &$sdpr_email_result_seen, $sdpr_immediate_id ) {
	if ( $sdpr_immediate_id === $reservation_id && 'created' === $event ) {
		$sdpr_email_result_seen = array( $sent, $event, $reservation_id );
	}
};
add_filter( 'sdpr_email_content', $sdpr_email_filter, 10, 3 );
add_filter( 'pre_wp_mail', $sdpr_mail_short_circuit );
add_action( 'sdpr_email_sent', $sdpr_email_result_listener, 10, 3 );
update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 1 ) );
$sdpr_plugin->get_service( 'notifications' )->dispatch( 'created', $sdpr_immediate_id, 'sdpr@example.test' );
sdpr_assert( 'Contract-filtered subject' === $sdpr_email_content_seen['subject'], 'Transactional email content is filtered once before delivery.' );
sdpr_assert( true === $sdpr_email_result_seen[0], 'Transactional email result event reports the mail transport result.' );
remove_filter( 'sdpr_email_content', $sdpr_email_filter, 10 );
remove_filter( 'pre_wp_mail', $sdpr_mail_short_circuit );
remove_action( 'sdpr_email_sent', $sdpr_email_result_listener, 10 );
update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 0 ) );
sdpr_assert( $sdpr_plugin->reservations->cancel_reservation( $sdpr_immediate_id ), 'Immediate reservation can be cancelled.' );
sdpr_assert( is_wp_error( $sdpr_plugin->get_service( 'lifecycle' )->extend( $sdpr_immediate_id, 2 ) ), 'Terminal reservations cannot be extended.' );
$sdpr_counts_after_cancel = $sdpr_plugin->reservations->get_status_counts();
sdpr_assert( $sdpr_counts_before_immediate[ SDPR_Reservation_Status::ACTIVE ] === $sdpr_counts_after_cancel[ SDPR_Reservation_Status::ACTIVE ], 'Status-count cache is invalidated when an active reservation is cancelled.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_RELEASED === get_post_meta( $sdpr_immediate_id, SDPR_Inventory_Manager::META_STATE, true ), 'Cancellation records released inventory ownership.' );
sdpr_assert( 2 === (int) wc_get_product( $sdpr_immediate_product_id )->get_stock_quantity( 'edit' ), 'Transactional cancellation restores immediate reservation stock.' );

$sdpr_quantity_filter = static function ( $quantity, $requested ) {
	return max( 1, min( 3, $requested ) );
};
add_filter( 'sdpr_reservation_quantity', $sdpr_quantity_filter, 10, 2 );
$sdpr_quantity_product = new WC_Product_Simple();
$sdpr_quantity_product->set_name( 'Quantity reservation test product' );
$sdpr_quantity_product->set_status( 'publish' );
$sdpr_quantity_product->set_regular_price( '10' );
$sdpr_quantity_product->set_manage_stock( true );
$sdpr_quantity_product->set_stock_quantity( 5 );
$sdpr_quantity_product_id = $sdpr_quantity_product->save();
$sdpr_quantity_result     = $sdpr_plugin->get_service( 'lifecycle' )->request( $sdpr_quantity_product_id, $sdpr_user_id, 2 );
$sdpr_quantity_id         = is_wp_error( $sdpr_quantity_result ) ? 0 : $sdpr_quantity_result['reservation_id'];
sdpr_assert( $sdpr_quantity_id && 2 === (int) SDPR_Reservation_Meta::get( $sdpr_quantity_id, SDPR_Reservation_Meta::QUANTITY ), 'Filtered request quantity is stored canonically.' );
sdpr_assert( 3 === (int) wc_get_product( $sdpr_quantity_product_id )->get_stock_quantity( 'edit' ), 'Multi-unit reservation decreases the exact held quantity.' );
sdpr_assert( 5 === (int) wc_get_product( $sdpr_quantity_product_id )->get_stock_quantity(), 'Owner stock allowance includes the exact held quantity.' );
$sdpr_quantity_order = wc_create_order( array( 'customer_id' => $sdpr_user_id ) );
$sdpr_quantity_item_id = $sdpr_quantity_order->add_product( wc_get_product( $sdpr_quantity_product_id ), 3 );
$sdpr_quantity_item = $sdpr_quantity_order->get_item( $sdpr_quantity_item_id );
$sdpr_quantity_item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, $sdpr_quantity_id, true );
$sdpr_quantity_item->save();
$sdpr_quantity_order->save();
$sdpr_plugin->reservations->transfer_holds_to_order( $sdpr_quantity_order );
sdpr_assert( 2 === (int) wc_get_product( $sdpr_quantity_product_id )->get_stock_quantity( 'edit' ), 'Transfer reduces only the order quantity beyond the multi-unit hold.' );
$sdpr_quantity_order->update_status( 'cancelled' );
sdpr_assert( 5 === (int) wc_get_product( $sdpr_quantity_product_id )->get_stock_quantity( 'edit' ), 'Cancelling a multi-unit order restores the complete order quantity once.' );

update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 1, 'enable_email_notifications' => 0 ) );
$sdpr_pending_quantity = $sdpr_plugin->get_service( 'lifecycle' )->request( $sdpr_quantity_product_id, $sdpr_user_id, 3 );
$sdpr_pending_quantity_id = is_wp_error( $sdpr_pending_quantity ) ? 0 : $sdpr_pending_quantity['reservation_id'];
wc_update_product_stock( wc_get_product( $sdpr_quantity_product_id ), 2, 'set' );
$sdpr_pending_quantity_approval = $sdpr_plugin->reservations->approve_reservation( $sdpr_pending_quantity_id );
sdpr_assert( is_wp_error( $sdpr_pending_quantity_approval ) && 'sdpr_no_stock' === $sdpr_pending_quantity_approval->get_error_code(), 'Approval rejects a multi-unit request when its full quantity is unavailable.' );
sdpr_assert( 2 === (int) wc_get_product( $sdpr_quantity_product_id )->get_stock_quantity( 'edit' ), 'Rejected multi-unit approval leaves stock unchanged.' );
sdpr_assert( true === $sdpr_plugin->reservations->cancel_reservation( $sdpr_pending_quantity_id ), 'Pending multi-unit request can be cancelled without changing stock.' );

$sdpr_variable = new WC_Product_Variable();
$sdpr_variable->set_name( 'Variation reservation parent' );
$sdpr_variable->set_status( 'publish' );
$sdpr_variable_id = $sdpr_variable->save();
$sdpr_variation = new WC_Product_Variation();
$sdpr_variation->set_parent_id( $sdpr_variable_id );
$sdpr_variation->set_status( 'publish' );
$sdpr_variation->set_regular_price( '10' );
$sdpr_variation->set_manage_stock( true );
$sdpr_variation->set_stock_quantity( 4 );
$sdpr_variation_id = $sdpr_variation->save();
$sdpr_variation_inventory_filter = static function ( $supported, $product ) {
	return $supported || $product instanceof WC_Product_Variation;
};
add_filter( 'sdpr_product_supports_reservation_inventory', $sdpr_variation_inventory_filter, 10, 2 );
update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 0, 'enable_email_notifications' => 0 ) );
$sdpr_variation_result = $sdpr_plugin->get_service( 'lifecycle' )->request( $sdpr_variation_id, $sdpr_user_id, 2 );
$sdpr_variation_reservation_id = is_wp_error( $sdpr_variation_result ) ? 0 : $sdpr_variation_result['reservation_id'];
sdpr_assert( $sdpr_variation_reservation_id && $sdpr_variation_id === (int) SDPR_Reservation_Meta::get( $sdpr_variation_reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ), 'Variation reservation stores the concrete variation identity.' );
sdpr_assert( 2 === (int) wc_get_product( $sdpr_variation_id )->get_stock_quantity( 'edit' ), 'Variation reservation holds stock from the variation.' );
$sdpr_variation_order = wc_create_order( array( 'customer_id' => $sdpr_user_id ) );
$sdpr_variation_item_id = $sdpr_variation_order->add_product( wc_get_product( $sdpr_variation_id ), 2 );
$sdpr_variation_item = $sdpr_variation_order->get_item( $sdpr_variation_item_id );
$sdpr_variation_item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, $sdpr_variation_reservation_id, true );
$sdpr_variation_item->save();
$sdpr_variation_order->save();
$sdpr_plugin->reservations->transfer_holds_to_order( $sdpr_variation_order );
sdpr_assert( SDPR_Reservation_Status::FULFILLED === SDPR_Reservation_Meta::get( $sdpr_variation_reservation_id, SDPR_Reservation_Meta::STATUS ), 'Variation order transfers the exact linked reservation.' );
sdpr_assert( 2 === (int) wc_get_product( $sdpr_variation_id )->get_stock_quantity( 'edit' ), 'Variation fulfillment does not reduce held stock twice.' );
$sdpr_variation_order->update_status( 'cancelled' );
sdpr_assert( 4 === (int) wc_get_product( $sdpr_variation_id )->get_stock_quantity( 'edit' ), 'Variation order cancellation restores its stock once.' );
remove_filter( 'sdpr_product_supports_reservation_inventory', $sdpr_variation_inventory_filter, 10 );
remove_filter( 'sdpr_reservation_quantity', $sdpr_quantity_filter, 10 );

$sdpr_guest_product = new WC_Product_Simple();
$sdpr_guest_product->set_name( 'Verified guest reservation product' );
$sdpr_guest_product->set_status( 'publish' );
$sdpr_guest_product->set_regular_price( '10' );
$sdpr_guest_product->set_manage_stock( true );
$sdpr_guest_product->set_stock_quantity( 2 );
$sdpr_guest_product_id = $sdpr_guest_product->save();
$sdpr_guest_key        = strtolower( wp_generate_password( 8, false ) );
$sdpr_guest_email      = 'sdpr-verified-guest-' . $sdpr_guest_key . '@example.test';
$sdpr_guest_frontend = static function () {
	return true;
};
wp_set_current_user( 0 );
add_filter( 'sdpr_guest_reservation_frontend_enabled', $sdpr_guest_frontend );
sdpr_assert( $sdpr_plugin->reservations->is_product_reservable( $sdpr_guest_product_id ), 'An add-on can expose anonymous reservation UI without inventing a verified identity.' );
remove_filter( 'sdpr_guest_reservation_frontend_enabled', $sdpr_guest_frontend );
wp_set_current_user( $sdpr_user_id );
$sdpr_guest_disabled   = $sdpr_plugin->get_service( 'lifecycle' )->request_guest( $sdpr_guest_product_id, $sdpr_guest_email );
sdpr_assert( is_wp_error( $sdpr_guest_disabled ) && 'sdpr_not_reservable' === $sdpr_guest_disabled->get_error_code(), 'Free guest lifecycle remains disabled without an explicit extension opt-in.' );
$sdpr_guest_opt_in = static function () {
	return true;
};
add_filter( 'sdpr_allow_guest_reservations', $sdpr_guest_opt_in );
$sdpr_guest_result         = $sdpr_plugin->get_service( 'lifecycle' )->request_guest( $sdpr_guest_product_id, $sdpr_guest_email );
$sdpr_guest_reservation_id = is_wp_error( $sdpr_guest_result ) ? 0 : $sdpr_guest_result['reservation_id'];
sdpr_assert( $sdpr_guest_reservation_id && 0 === (int) get_post_field( 'post_author', $sdpr_guest_reservation_id ), 'Verified guest reservation uses the canonical authorless Free record.' );
sdpr_assert( $sdpr_guest_email === SDPR_Reservation_Meta::get( $sdpr_guest_reservation_id, SDPR_Reservation_Meta::EMAIL ), 'Verified guest identity is stored on the canonical reservation.' );
sdpr_assert( 1 === $sdpr_plugin->reservations->count_open_reservations( 0, $sdpr_guest_email ), 'Guest email identity consumes the normal open-reservation quota.' );
sdpr_assert( 1 === (int) wc_get_product( $sdpr_guest_product_id )->get_stock_quantity( 'edit' ), 'Verified guest reservation holds stock through the Free inventory transaction.' );
$sdpr_guest_duplicate = $sdpr_plugin->get_service( 'lifecycle' )->request_guest( $sdpr_guest_product_id, $sdpr_guest_email );
sdpr_assert( is_wp_error( $sdpr_guest_duplicate ) && 'sdpr_duplicate' === $sdpr_guest_duplicate->get_error_code(), 'Repeated verified guest identity cannot create a duplicate hold.' );
$sdpr_guest_user_id = wp_insert_user(
	array(
		'user_login' => 'sdpr-verified-guest-' . $sdpr_guest_key,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $sdpr_guest_email,
		'role'       => 'customer',
	)
);
$sdpr_account_duplicate = $sdpr_plugin->get_service( 'lifecycle' )->request( $sdpr_guest_product_id, $sdpr_guest_user_id );
sdpr_assert( is_wp_error( $sdpr_account_duplicate ) && 'sdpr_duplicate' === $sdpr_account_duplicate->get_error_code(), 'Creating an account with a guest email cannot bypass duplicate protection.' );
sdpr_assert( true === $sdpr_plugin->reservations->cancel_reservation( $sdpr_guest_reservation_id ), 'Verified guest reservation cancels through the Free lifecycle.' );
sdpr_assert( 2 === (int) wc_get_product( $sdpr_guest_product_id )->get_stock_quantity( 'edit' ), 'Guest cancellation restores stock exactly once.' );
remove_filter( 'sdpr_allow_guest_reservations', $sdpr_guest_opt_in );
update_option( 'sdpr_options', array( 'enable_reservation' => 1, 'max_reservations' => 3, 'reservation_duration' => 24, 'pending_duration' => 1, 'require_admin_approval' => 1, 'enable_email_notifications' => 0 ) );

function sdpr_test_reservation( $product_id, $user_id, $status, $expires ) {
	$id = wp_insert_post( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'post_author' => $user_id, 'post_title' => 'Test reservation' ) );
	update_post_meta( $id, '_sdpr_product_id', $product_id );
	update_post_meta( $id, '_sdpr_status', $status );
	update_post_meta( $id, '_sdpr_expires_at', $expires );
	update_post_meta( $id, '_sdpr_qty', 1 );
	update_post_meta( $id, '_sdpr_email', 'sdpr@example.test' );
	return $id;
}

$sdpr_pending_id = sdpr_test_reservation( $sdpr_product_id, $sdpr_user_id, 'pending_approval', time() + HOUR_IN_SECONDS );
$sdpr_vetoed_id = sdpr_test_reservation( $sdpr_product_id, $sdpr_user_id, 'pending_approval', time() + HOUR_IN_SECONDS );
$sdpr_approval_veto = static function ( $allowed, $reservation_id, $from, $to, $source ) use ( $sdpr_vetoed_id ) {
	return $sdpr_vetoed_id === $reservation_id && 'approve' === $source ? false : $allowed;
};
add_filter( 'sdpr_reservation_transition_allowed', $sdpr_approval_veto, 10, 5 );
$sdpr_veto_result = $sdpr_plugin->reservations->approve_reservation( $sdpr_vetoed_id );
sdpr_assert( is_wp_error( $sdpr_veto_result ) && SDPR_Reservation_Status::PENDING === SDPR_Reservation_Meta::get( $sdpr_vetoed_id, SDPR_Reservation_Meta::STATUS ), 'Transition filters can safely veto approval before inventory changes.' );
sdpr_assert( 1 === (int) wc_get_product( $sdpr_product_id )->get_stock_quantity( 'edit' ), 'A vetoed approval does not change stock.' );
remove_filter( 'sdpr_reservation_transition_allowed', $sdpr_approval_veto, 10 );
sdpr_assert( true === $sdpr_plugin->reservations->deny_reservation( $sdpr_vetoed_id, 'Contract test' ), 'Pending reservation denial uses the lifecycle service.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_RELEASED === SDPR_Reservation_Meta::get( $sdpr_vetoed_id, SDPR_Reservation_Meta::INVENTORY_STATE ), 'Denial records terminal inventory ownership consistently.' );
sdpr_assert( true === $sdpr_plugin->reservations->approve_reservation( $sdpr_pending_id ), 'Pending reservation approves.' );
$sdpr_product = wc_get_product( $sdpr_product_id );
sdpr_assert( 0 === (int) $sdpr_product->get_stock_quantity( 'edit' ), 'Approval holds physical stock once.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_HELD === get_post_meta( $sdpr_pending_id, SDPR_Inventory_Manager::META_STATE, true ), 'Approval records held inventory ownership.' );
sdpr_assert( 1 === (int) $sdpr_product->get_stock_quantity(), 'Owner can purchase the held last unit.' );
sdpr_assert( is_wp_error( $sdpr_plugin->reservations->approve_reservation( $sdpr_pending_id ) ), 'Repeated approval is rejected.' );
sdpr_assert( true === $sdpr_plugin->reservations->cancel_reservation( $sdpr_pending_id ), 'Active reservation cancels.' );
sdpr_assert( false === $sdpr_plugin->reservations->cancel_reservation( $sdpr_pending_id ), 'Repeated cancellation is rejected.' );
$sdpr_product = wc_get_product( $sdpr_product_id );
sdpr_assert( 1 === (int) $sdpr_product->get_stock_quantity( 'edit' ), 'Cancellation restores stock exactly once.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_RELEASED === get_post_meta( $sdpr_pending_id, SDPR_Inventory_Manager::META_STATE, true ), 'Cancellation records released inventory ownership.' );

$sdpr_expired_pending = sdpr_test_reservation( $sdpr_product_id, $sdpr_user_id, 'pending_approval', time() - 1 );
sdpr_assert( 0 === $sdpr_plugin->reservations->count_open_reservations( $sdpr_user_id ), 'Expired pending requests do not consume reservation quota.' );
$sdpr_plugin->reservations->expire_old_reservations();
sdpr_assert( 'expired' === get_post_meta( $sdpr_expired_pending, '_sdpr_status', true ), 'Pending requests expire.' );
sdpr_assert( 1 === (int) wc_get_product( $sdpr_product_id )->get_stock_quantity( 'edit' ), 'Pending expiry does not change stock.' );

$sdpr_cart = new WC_Cart();
$sdpr_cart_item_key = $sdpr_cart->add_to_cart( $sdpr_product_id, 1 );
sdpr_assert( $sdpr_cart_item_key && empty( $sdpr_cart->cart_contents[ $sdpr_cart_item_key ]['_sdpr_reservation_id'] ), 'Cart item added before reservation starts unlinked.' );
$sdpr_active_id = sdpr_test_reservation( $sdpr_product_id, $sdpr_user_id, 'active', time() + HOUR_IN_SECONDS );
wc_update_product_stock( wc_get_product( $sdpr_product_id ), 1, 'decrease' );
$sdpr_plugin->get_service( 'cart_order' )->clear_cache();
$sdpr_plugin->reservations->sync_cart_reservations( $sdpr_cart );
sdpr_assert( $sdpr_active_id === (int) $sdpr_cart->cart_contents[ $sdpr_cart_item_key ]['_sdpr_reservation_id'], 'Existing cart item is linked after the reservation is created.' );
sdpr_assert( $sdpr_active_id === $sdpr_plugin->get_service( 'repository' )->find_active( $sdpr_product_id, $sdpr_user_id ), 'Synthetic checkout fixture resolves through the canonical active-reservation query.' );
sdpr_assert( wc_get_product( $sdpr_product_id )->is_in_stock(), 'The reservation owner passes WooCommerce stock availability for the held last unit.' );
$sdpr_order = wc_create_order( array( 'customer_id' => $sdpr_user_id ) );
$sdpr_item_id = $sdpr_order->add_product( wc_get_product( $sdpr_product_id ), 1 );
$sdpr_item = $sdpr_order->get_item( $sdpr_item_id );
$sdpr_item->add_meta_data( '_sdpr_reservation_id', $sdpr_active_id, true );
$sdpr_item->save();
$sdpr_order->save();
$sdpr_reserve_stock_succeeded = true;
$sdpr_reserve_stock_message = '';
try {
	wc_reserve_stock_for_order( $sdpr_order );
} catch ( Throwable $sdpr_stock_error ) {
	$sdpr_reserve_stock_succeeded = false;
	$sdpr_reserve_stock_message = $sdpr_stock_error->getMessage();
}
sdpr_assert( $sdpr_reserve_stock_succeeded, 'WooCommerce checkout can reserve stock when the last unit is already held. ' . $sdpr_reserve_stock_message );
do_action( 'woocommerce_store_api_checkout_order_processed', $sdpr_order );
sdpr_assert( 'fulfilled' === get_post_meta( $sdpr_active_id, '_sdpr_status', true ), 'Order fulfills the exact linked reservation.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_TRANSFERRED === get_post_meta( $sdpr_active_id, SDPR_Inventory_Manager::META_STATE, true ), 'Fulfillment transfers inventory ownership to the order.' );
sdpr_assert( 0 === (int) wc_get_product( $sdpr_product_id )->get_stock_quantity( 'edit' ), 'Checkout does not decrement the held unit twice.' );
$sdpr_order->update_status( 'cancelled' );
sdpr_assert( 1 === (int) wc_get_product( $sdpr_product_id )->get_stock_quantity( 'edit' ), 'Cancelled order restores stock exactly once.' );
sdpr_assert( 'order_cancelled' === get_post_meta( $sdpr_active_id, '_sdpr_status', true ), 'Cancelled order has an explicit reservation status.' );
sdpr_assert( SDPR_Inventory_Manager::STATE_RELEASED === get_post_meta( $sdpr_active_id, SDPR_Inventory_Manager::META_STATE, true ), 'Cancelled order records released inventory ownership.' );
$sdpr_plugin->reservations->restore_transferred_order_stock( $sdpr_order->get_id() );
sdpr_assert( 1 === (int) wc_get_product( $sdpr_product_id )->get_stock_quantity( 'edit' ), 'Repeated order restoration is idempotent.' );

require_once ABSPATH . 'wp-admin/includes/user.php';
$sdpr_privacy_user_id = wp_insert_user( array( 'user_login' => 'sdpr-privacy-user', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'sdpr-privacy@example.test', 'role' => 'customer' ) );
$sdpr_privacy_ids = array();
for ( $sdpr_i = 0; $sdpr_i < 101; $sdpr_i++ ) {
	$sdpr_privacy_ids[] = sdpr_test_reservation( $sdpr_product_id, $sdpr_privacy_user_id, 'expired', time() - HOUR_IN_SECONDS );
}
SDPR_Reservation_Meta::update( $sdpr_privacy_ids[0], SDPR_Reservation_Meta::NAME, 'Privacy' );
SDPR_Reservation_Meta::update( $sdpr_privacy_ids[0], SDPR_Reservation_Meta::SURNAME, 'Customer' );
SDPR_Reservation_Meta::update( $sdpr_privacy_ids[0], SDPR_Reservation_Meta::DENIAL_REASON, 'Contains personal context' );
$sdpr_export       = $sdpr_plugin->reservations->export_personal_data( 'sdpr-privacy@example.test', 1 );
$sdpr_export_names = wp_list_pluck( $sdpr_export['data'][0]['data'], 'name' );
sdpr_assert( in_array( 'User ID', $sdpr_export_names, true ) && in_array( 'First name', $sdpr_export_names, true ) && in_array( 'Created', $sdpr_export_names, true ) && in_array( 'Inventory state', $sdpr_export_names, true ) && in_array( 'Related order ID', $sdpr_export_names, true ), 'Privacy exporter includes all customer and reservation identifiers.' );
$sdpr_erase_first = $sdpr_plugin->reservations->erase_personal_data( 'sdpr-privacy@example.test', 1 );
$sdpr_erase_second = $sdpr_plugin->reservations->erase_personal_data( 'sdpr-privacy@example.test', 2 );
$sdpr_privacy_remaining = get_posts( array( 'post_type' => 'sdpr_reservation', 'post_status' => 'publish', 'author' => $sdpr_privacy_user_id, 'fields' => 'ids', 'posts_per_page' => -1 ) );
sdpr_assert( ! $sdpr_erase_first['done'] && $sdpr_erase_second['done'] && empty( $sdpr_privacy_remaining ), 'Privacy eraser processes a shrinking result set without skipping records.' );
sdpr_assert( '' === SDPR_Reservation_Meta::get( $sdpr_privacy_ids[0], SDPR_Reservation_Meta::NAME ) && '' === SDPR_Reservation_Meta::get( $sdpr_privacy_ids[0], SDPR_Reservation_Meta::SURNAME ) && '' === SDPR_Reservation_Meta::get( $sdpr_privacy_ids[0], SDPR_Reservation_Meta::DENIAL_REASON ), 'Privacy eraser removes customer names and free-text denial details.' );

$sdpr_delete_user_id = wp_insert_user( array( 'user_login' => 'sdpr-delete-user', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'sdpr-delete@example.test', 'role' => 'customer' ) );
$sdpr_delete_reservation_id = sdpr_test_reservation( $sdpr_product_id, $sdpr_delete_user_id, 'active', time() + HOUR_IN_SECONDS );
wp_delete_user( $sdpr_delete_user_id );
sdpr_assert( 'sdpr_reservation' === get_post_type( $sdpr_delete_reservation_id ), 'Deleting a customer does not delete a reservation with an inventory obligation.' );

wp_delete_post( $sdpr_pending_id, true );
wp_delete_post( $sdpr_vetoed_id, true );
wp_delete_post( $sdpr_expired_pending, true );
wp_delete_post( $sdpr_active_id, true );
wp_delete_post( $sdpr_immediate_id, true );
foreach ( $sdpr_privacy_ids as $sdpr_privacy_id ) {
	wp_delete_post( $sdpr_privacy_id, true );
}
wp_delete_post( $sdpr_delete_reservation_id, true );
wp_delete_post( $sdpr_product_id, true );
wp_delete_post( $sdpr_immediate_product_id, true );
wp_delete_post( $sdpr_waitlist_product_id, true );
$sdpr_quantity_order->delete( true );
$sdpr_variation_order->delete( true );
wp_delete_post( $sdpr_quantity_id, true );
wp_delete_post( $sdpr_pending_quantity_id, true );
wp_delete_post( $sdpr_variation_reservation_id, true );
wp_delete_post( $sdpr_variation_id, true );
wp_delete_post( $sdpr_variable_id, true );
wp_delete_post( $sdpr_quantity_product_id, true );
wp_delete_post( $sdpr_guest_reservation_id, true );
wp_delete_post( $sdpr_guest_product_id, true );
$sdpr_order->delete( true );
wp_delete_user( $sdpr_privacy_user_id );
wp_delete_user( $sdpr_guest_user_id );
wp_delete_user( $sdpr_user_id );
remove_action( 'sdpr_reservation_transitioned', $sdpr_transition_listener );
remove_action( 'sdpr_reservation_extended', $sdpr_extension_listener );
if ( false === $sdpr_original_options ) {
	delete_option( 'sdpr_options' );
} else {
	update_option( 'sdpr_options', $sdpr_original_options );
}
if ( ! empty( $GLOBALS['sdpr_failures'] ) ) exit( 1 );
echo esc_html( "All integration assertions passed.\n" );

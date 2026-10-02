<?php
/** Run only in a disposable WordPress test site using WP-CLI eval-file. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_REBRAND_TEST' ) ) {
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-view.php';
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
$sdpr_test_failures = array();
function sdpr_rebrand_assert( $condition, $message ) {
	global $sdpr_test_failures;
	if ( ! $condition ) {
		$sdpr_test_failures[] = $message;
	}
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
}
class SDPR_Test_Ajax_Exit extends RuntimeException {}
$sdpr_die_filter = static function () {
	return static function ( $message = '' ) {
		throw new SDPR_Test_Ajax_Exit( (string) $message );
	};
};
add_filter( 'wp_die_ajax_handler', $sdpr_die_filter );
$sdpr_original_user = get_current_user_id();
$sdpr_original_screen = get_current_screen();
$sdpr_original_page_hooks = isset( $GLOBALS['admin_page_hooks'] ) ? $GLOBALS['admin_page_hooks'] : array();
$sdpr_user = wp_insert_user( array(
	'user_login' => 'sdpr-notice-test-' . wp_generate_password( 10, false ),
	'user_pass' => wp_generate_password( 24 ),
	'role' => 'administrator',
) );
if ( is_wp_error( $sdpr_user ) ) {
	WP_CLI::error( $sdpr_user->get_error_message() );
}
$sdpr_notices = SDPR_Plugin::get_instance()->get_service( 'dependency_notices' );
try {
	wp_set_current_user( $sdpr_user );
	sdpr_rebrand_assert( post_type_exists( 'sdpr_reservation' ), 'Canonical reservation post type registered.' );
	$sdpr_header = get_plugin_data( SDPR_PLUGIN_PATH . 'spectral-dot-reservations.php', false, false );
	sdpr_rebrand_assert( 'Spectral Dot - Product Reservations for WooCommerce' === $sdpr_header['Name'] && 'spectral-dot-reservations' === $sdpr_header['TextDomain'] && SDPR_VERSION === $sdpr_header['Version'], 'Canonical metadata matches the runtime identity.' );
	require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin.php';
	$sdpr_settings_admin = new SDPR_Admin();
	$sdpr_menu_order = array( 'woocommerce', 'edit.php?post_type=product', 'wc-admin', 'sdpr-settings', 'tools.php' );
	sdpr_rebrand_assert( array( 'woocommerce', 'edit.php?post_type=product', 'sdpr-settings', 'wc-admin', 'tools.php' ) === $sdpr_settings_admin->place_menu_after_products( $sdpr_menu_order ), 'Reservations remains top-level directly below Products.' );
	sdpr_rebrand_assert( array( 'woocommerce', 'edit.php?post_type=product', 'sdpr-settings', 'wc-admin', 'tools.php' ) === $sdpr_settings_admin->place_menu_after_products( $sdpr_settings_admin->place_menu_after_products( $sdpr_menu_order ) ), 'Menu placement remains stable when applied twice.' );
	$sdpr_wc_menu_order = array( 'edit.php?post_type=product', 'separator-woocommerce', 'woocommerce', 'edit.php?post_type=product', 'wc-admin', 'sdpr-settings' );
	sdpr_rebrand_assert( array( 'edit.php?post_type=product', 'separator-woocommerce', 'woocommerce', 'edit.php?post_type=product', 'sdpr-settings', 'wc-admin' ) === $sdpr_settings_admin->place_menu_after_products( $sdpr_wc_menu_order ), 'Reservations follows the final Products entry when WooCommerce duplicates it in the menu order.' );
	sdpr_rebrand_assert( array( 'woocommerce', 'sdpr-settings', 'tools.php' ) === $sdpr_settings_admin->place_menu_after_products( array( 'woocommerce', 'sdpr-settings', 'tools.php' ) ), 'Menu order is unchanged when Products is unavailable.' );
	ob_start();
	SDPR_Admin_View::render_header( 'Heading <test>', 'Description & details' );
	$sdpr_header_markup = ob_get_clean();
	sdpr_rebrand_assert( false !== strpos( $sdpr_header_markup, 'Heading &lt;test&gt;' ) && false !== strpos( $sdpr_header_markup, 'Description &amp; details' ), 'Shared header escapes its heading and description.' );
	sdpr_rebrand_assert( 1 === substr_count( $sdpr_header_markup, '<h1 ' ) && false !== strpos( $sdpr_header_markup, 'spectral-dot-logo-inline.svg' ) && false !== strpos( $sdpr_header_markup, 'alt="Spectral Dot"' ), 'Shared header has one heading and an accessible bundled SVG logo.' );
	$sdpr_menu_icon = SDPR_Admin_View::menu_icon();
	$sdpr_menu_svg = base64_decode( substr( $sdpr_menu_icon, strlen( 'data:image/svg+xml;base64,' ) ), true );
	sdpr_rebrand_assert( 0 === strpos( $sdpr_menu_icon, 'data:image/svg+xml;base64,' ) && false !== strpos( $sdpr_menu_svg, 'width="20"' ) && false === strpos( $sdpr_menu_svg, '<linearGradient' ), 'Menu icon is a compact, recolorable monochrome SVG data URI.' );
	sdpr_rebrand_assert( false === strpos( $sdpr_menu_svg, 'fill="none"' ) && false === strpos( $sdpr_menu_svg, 'stroke=' ) && false !== strpos( $sdpr_menu_svg, 'fill-rule="evenodd"' ), 'Menu icon stays hollow when WordPress recolors its fills.' );
	set_current_screen( 'dashboard' );
	sdpr_rebrand_assert( 'existing' === SDPR_Admin_View::admin_body_class( 'existing' ), 'Shared admin layout does not affect unrelated screens.' );
	foreach ( array( 'reservations', 'reservari-produse' ) as $sdpr_menu_title ) {
		$GLOBALS['admin_page_hooks']['sdpr-settings'] = $sdpr_menu_title;
		foreach ( array( 'sdpr-settings', 'sdpr-manage-reservations', 'sdpr-analytics' ) as $sdpr_page ) {
			$sdpr_screen = get_plugin_page_hookname( $sdpr_page, 'sdpr-settings' );
			set_current_screen( $sdpr_screen );
			sdpr_rebrand_assert( 'existing sdpr-admin-page' === SDPR_Admin_View::admin_body_class( 'existing' ), 'Shared layout preserves existing classes on ' . $sdpr_screen . '.' );
		}
	}
	set_current_screen( 'reservations_page_unrelated-settings' );
	sdpr_rebrand_assert( 'existing' === SDPR_Admin_View::admin_body_class( 'existing' ), 'Shared layout excludes unrelated submenu screens.' );
	$sdpr_original_errors = isset( $GLOBALS['wp_settings_errors'] ) ? $GLOBALS['wp_settings_errors'] : array();
	$GLOBALS['wp_settings_errors'] = array();
	add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );
	add_settings_error( 'unrelated_plugin', 'unrelated', 'Unrelated feedback.', 'error' );
	ob_start();
	$sdpr_settings_admin->render_settings_notices();
	$sdpr_settings_markup = ob_get_clean();
	$GLOBALS['wp_settings_errors'] = $sdpr_original_errors;
	sdpr_rebrand_assert( false !== strpos( $sdpr_settings_markup, 'Settings saved.' ) && false !== strpos( $sdpr_settings_markup, 'is-dismissible' ), 'Core settings-save confirmation remains visible and dismissible.' );
	sdpr_rebrand_assert( false === strpos( $sdpr_settings_markup, 'Unrelated feedback.' ), 'Settings page excludes unrelated plugin feedback.' );
	$sdpr_notices->add( 'test-local', 'Local test notice.', 'success' );
	$sdpr_notices->add( 'test-dependency', 'Dependency test notice.', 'error', 'plugins' );
	sdpr_rebrand_assert( ! $sdpr_notices->add( 'test-invalid', 'Invalid scope.', 'error', 'global' ), 'Unrestricted dashboard scope is rejected.' );
	foreach ( array( 'dashboard', 'edit-post', 'tools', 'profile' ) as $sdpr_screen ) {
		set_current_screen( $sdpr_screen );
		sdpr_rebrand_assert( ! $sdpr_notices->visible_notices(), 'No plugin notices on ' . $sdpr_screen . '.' );
	}
	set_current_screen( 'plugins' );
	sdpr_rebrand_assert( array( 'test-dependency' ) === array_keys( $sdpr_notices->visible_notices() ), 'Plugins screen receives only explicit dependency notices.' );
	foreach ( array( 'toplevel_page_sdpr-settings', 'reservations_page_sdpr-manage-reservations', 'reservations_page_sdpr-analytics' ) as $sdpr_screen ) {
		set_current_screen( $sdpr_screen );
		sdpr_rebrand_assert( 2 === count( $sdpr_notices->visible_notices() ), 'Authorized plugin screen receives notices: ' . $sdpr_screen );
	}
	ob_start();
	$sdpr_notices->render();
	$sdpr_markup = ob_get_clean();
	sdpr_rebrand_assert( 2 === substr_count( $sdpr_markup, 'is-dismissible' ) && false !== strpos( $sdpr_markup, 'data-sdpr-nonce' ), 'Every operational notice is dismissible and nonce protected.' );
	$_POST['notice_id'] = 'test-local';
	$_REQUEST['nonce'] = wp_create_nonce( 'sdpr_dismiss_notice' );
	ob_start();
	try {
		$sdpr_notices->dismiss();
	} catch ( SDPR_Test_Ajax_Exit $sdpr_exit ) {
		// Expected termination after the JSON response.
	}
	$sdpr_response = json_decode( ob_get_clean(), true );
	sdpr_rebrand_assert( ! empty( $sdpr_response['success'] ), 'Authorized dismissal succeeds through the AJAX handler.' );
	sdpr_rebrand_assert( ! isset( $sdpr_notices->visible_notices()['test-local'] ), 'Dismissal persists for the current user.' );
	$sdpr_saved = get_user_option( 'sdpr_dismissed_notices' );
	sdpr_rebrand_assert( $sdpr_saved['test-local']['until'] <= time() + DAY_IN_SECONDS, 'An unresolved operational issue is not hidden indefinitely.' );
	$sdpr_notices->add( 'test-local', 'Changed local test notice.', 'success' );
	sdpr_rebrand_assert( isset( $sdpr_notices->visible_notices()['test-local'] ), 'Changed notice content invalidates prior dismissal.' );
	$sdpr_notices->add( 'test-local', 'Local test notice.', 'success' );
	$sdpr_saved['test-local']['until'] = time() - 1;
	update_user_option( $sdpr_user, 'sdpr_dismissed_notices', $sdpr_saved, false );
	sdpr_rebrand_assert( isset( $sdpr_notices->visible_notices()['test-local'] ), 'Expired dismissal allows an unresolved notice to reappear.' );
	$_REQUEST['nonce'] = 'invalid';
	ob_start();
	try {
		$sdpr_notices->dismiss();
		sdpr_rebrand_assert( false, 'Invalid nonce must terminate.' );
	} catch ( SDPR_Test_Ajax_Exit $sdpr_exit ) {
		$sdpr_invalid_nonce = '-1' === $sdpr_exit->getMessage();
	}
	ob_end_clean();
	sdpr_rebrand_assert( ! empty( $sdpr_invalid_nonce ), 'Invalid nonce is rejected.' );
	wp_set_current_user( 0 );
	sdpr_rebrand_assert( ! $sdpr_notices->visible_notices(), 'Unauthorized users cannot see operational notices.' );
	$_REQUEST['nonce'] = wp_create_nonce( 'sdpr_dismiss_notice' );
	ob_start();
	try {
		$sdpr_notices->dismiss();
	} catch ( SDPR_Test_Ajax_Exit $sdpr_exit ) {
		// Capture the forbidden response without terminating the test.
	}
	$sdpr_response = json_decode( ob_get_clean(), true );
	sdpr_rebrand_assert( isset( $sdpr_response['success'] ) && false === $sdpr_response['success'], 'A valid nonce does not bypass capability checks.' );
	$sdpr_notices->remove( 'test-local' );
	$sdpr_notices->remove( 'test-dependency' );
} finally {
	unset( $_POST['notice_id'], $_REQUEST['nonce'] );
	remove_filter( 'wp_die_ajax_handler', $sdpr_die_filter );
	wp_set_current_user( $sdpr_original_user );
	$GLOBALS['current_screen'] = $sdpr_original_screen;
	$GLOBALS['admin_page_hooks'] = $sdpr_original_page_hooks;
	wp_delete_user( $sdpr_user );
}
if ( $sdpr_test_failures ) {
	WP_CLI::error( count( $sdpr_test_failures ) . ' rebranding assertions failed.' );
}
WP_CLI::success( 'All rebranding and scoped-notice assertions passed.' );

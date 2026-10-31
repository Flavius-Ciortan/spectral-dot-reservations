<?php
/** Run only in a disposable WordPress site using WP-CLI eval-file. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_MODAL_TEST' ) ) {
	exit( 1 );
}

$sdpr_modal_failures = 0;
function sdpr_modal_assert( $condition, $message ) {
	global $sdpr_modal_failures;
	if ( ! $condition ) {
		++$sdpr_modal_failures;
	}
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
}

function sdpr_modal_markup( $product ) {
	$GLOBALS['product'] = $product;
	ob_start();
	include SDPR_PLUGIN_PATH . 'templates/modal-template.php';
	return ob_get_clean();
}

$sdpr_had_product = array_key_exists( 'product', $GLOBALS );
$sdpr_original_product = $GLOBALS['product'] ?? null;
$sdpr_missing_options = 'sdpr-modal-missing-' . wp_generate_uuid4();
$sdpr_original_options = get_option( 'sdpr_options', $sdpr_missing_options );

try {
	update_option( 'sdpr_options', array( 'reservation_duration' => 24 ) );
	foreach ( array( 'simple', 'variable' ) as $sdpr_type ) {
		$sdpr_product = 'variable' === $sdpr_type ? new WC_Product_Variable() : new WC_Product_Simple();
		$sdpr_product->set_name( 'Studio Headphones <special> & Sound' );
		$sdpr_markup = sdpr_modal_markup( $sdpr_product );
		sdpr_modal_assert( false !== strpos( $sdpr_markup, '<strong class="sdpr-reservation-product-name">Studio Headphones &lt;special&gt; &amp; Sound</strong>' ), $sdpr_type . ': confirmation includes the escaped product name.' );
		sdpr_modal_assert( false === strpos( $sdpr_markup, '<special>' ), $sdpr_type . ': product name is not rendered as HTML.' );
		sdpr_modal_assert( 1 === substr_count( $sdpr_markup, 'id="sdpr-reservation-product-summary"' ), $sdpr_type . ': confirmation has one product-summary identifier.' );
		sdpr_modal_assert( false !== strpos( $sdpr_markup, 'aria-describedby="sdpr-reservation-product-summary sdpr-reservation-dialog-description"' ), $sdpr_type . ': dialog description includes the product context and duration.' );
		sdpr_modal_assert( false !== strpos( $sdpr_markup, '<span class="sdpr-selected-variation" hidden></span>' ), $sdpr_type . ': dynamic selected options remain separate and hidden until populated.' );
	}
	update_option( 'sdpr_options', array( 'require_admin_approval' => 1, 'pending_duration' => 2, 'reservation_duration' => 24 ) );
	$sdpr_markup = sdpr_modal_markup( $sdpr_product );
	sdpr_modal_assert( false !== strpos( $sdpr_markup, 'sdpr-reservation-product-name' ) && false !== strpos( $sdpr_markup, 'Your request will remain open for up to 2 hours.' ), 'Approval confirmation retains product context and the pending deadline.' );
	sdpr_modal_assert( false !== strpos( $sdpr_markup, 'If approved, the product will then be held for 24 hours.' ), 'Approval confirmation retains the separate active duration.' );
} finally {
	if ( $sdpr_had_product ) {
		$GLOBALS['product'] = $sdpr_original_product;
	} else {
		unset( $GLOBALS['product'] );
	}
	if ( $sdpr_missing_options === $sdpr_original_options ) {
		delete_option( 'sdpr_options' );
	} else {
		update_option( 'sdpr_options', $sdpr_original_options );
	}
}

if ( $sdpr_modal_failures ) {
	WP_CLI::error( $sdpr_modal_failures . ' modal product-context assertions failed.' );
}
WP_CLI::success( 'All modal product-context assertions passed.' );

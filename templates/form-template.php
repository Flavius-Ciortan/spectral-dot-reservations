<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sdpr_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;

// If $sdpr_product is not set, resolve it
if ( ! $sdpr_product instanceof WC_Product ) {
	$sdpr_product_id = get_the_ID();
	if ( ! $sdpr_product_id ) {
		$sdpr_product_id = get_queried_object_id();
	}
	if ( $sdpr_product_id ) {
		$sdpr_product = wc_get_product( $sdpr_product_id );
	}
}

// If we still don't have a product, stop
if ( ! $sdpr_product instanceof WC_Product ) {
	return;
}

// Get settings
$sdpr_pid         = $sdpr_product->get_id();
$sdpr_options     = get_option( 'sdpr_options' );
$sdpr_globally_on = ! empty( $sdpr_options['enable_reservation'] );

// Free remains account-only; compatible add-ons may enable a verified guest flow.
$sdpr_show_button = $sdpr_globally_on && ( is_user_logged_in() || apply_filters( 'sdpr_guest_reservation_form_visible', false, $sdpr_product ) );
?>

<?php if ( $sdpr_show_button ) : ?>
	<button
		type="button"
		id="sdpr_reserve_product"
		class="single_add_to_cart_button button alt wp-element-button"
		data-productid="<?php echo esc_attr( $sdpr_pid ); ?>"
		data-product-type="<?php echo esc_attr( $sdpr_product->get_type() ); ?>"
	>
		<svg class="sdpr-reserve-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<path d="M10 2.75h4M12 2.75v3.5M18.8 6.2l1.25 1.25" />
			<circle cx="12" cy="14" r="7.5" />
			<path d="M12 9.75V14l2.5 1.5" />
		</svg>
		<span><?php esc_html_e( 'Reserve', 'spectral-dot-reservations' ); ?></span>
	</button>
<?php endif; ?>

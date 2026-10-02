<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sdpr_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;

// If $sdpr_product is not set, resolve it.
if ( ! $sdpr_product instanceof WC_Product ) {
	$sdpr_product_id = get_the_ID();
	if ( ! $sdpr_product_id ) {
		$sdpr_product_id = get_queried_object_id();
	}
	if ( $sdpr_product_id ) {
		$sdpr_product = wc_get_product( $sdpr_product_id );
	}
}

if ( ! $sdpr_product instanceof WC_Product ) {
	return;
}

$sdpr_pid     = $sdpr_product->get_id();
$sdpr_options = get_option( 'sdpr_options', array() );
$sdpr_options = is_array( $sdpr_options ) ? $sdpr_options : array();

// Reservation duration (hours)
$sdpr_duration_hours = isset( $sdpr_options['reservation_duration'] ) ? absint( $sdpr_options['reservation_duration'] ) : 24;
if ( $sdpr_duration_hours < 1 ) {
	$sdpr_duration_hours = 1;
} elseif ( $sdpr_duration_hours > 168 ) {
	$sdpr_duration_hours = 168;
}
$sdpr_requires_approval      = ! empty( $sdpr_options['require_admin_approval'] );
$sdpr_pending_duration_hours = isset( $sdpr_options['pending_duration'] ) ? absint( $sdpr_options['pending_duration'] ) : $sdpr_duration_hours;
$sdpr_pending_duration_hours = max( 1, min( 168, $sdpr_pending_duration_hours ) );

// Popup customization settings (logged-in only).
$sdpr_enable_popup_customization = ! empty( $sdpr_options['enable_popup_customization_logged_in'] );
$sdpr_popup_settings             = $sdpr_options['popup_customization_logged_in'] ?? array();

// Defaults
$sdpr_border_radius    = isset( $sdpr_popup_settings['border_radius'] ) ? (int) $sdpr_popup_settings['border_radius'] : 8;
$sdpr_background_color = isset( $sdpr_popup_settings['background_color'] ) ? $sdpr_popup_settings['background_color'] : '#ffffff';
$sdpr_font_family      = isset( $sdpr_popup_settings['font_family'] ) ? $sdpr_popup_settings['font_family'] : 'Arial, Helvetica, sans-serif';
$sdpr_font_size        = isset( $sdpr_popup_settings['font_size'] ) ? (int) $sdpr_popup_settings['font_size'] : 16;
$sdpr_text_color       = isset( $sdpr_popup_settings['text_color'] ) ? $sdpr_popup_settings['text_color'] : '#222222';

$sdpr_allowed_fonts        = array( 'Arial, Helvetica, sans-serif', 'Verdana, Geneva, sans-serif', 'Georgia, serif', 'Times New Roman, Times, serif', 'Tahoma, Geneva, sans-serif', 'Trebuchet MS, Helvetica, sans-serif', 'Courier New, Courier, monospace', 'Roboto, sans-serif', 'Open Sans, sans-serif', 'Lato, sans-serif', 'Montserrat, sans-serif' );
$sdpr_font_family          = in_array( $sdpr_font_family, $sdpr_allowed_fonts, true ) ? $sdpr_font_family : 'Arial, Helvetica, sans-serif';
$sdpr_sanitized_background = sanitize_hex_color( $sdpr_background_color );
$sdpr_sanitized_text       = sanitize_hex_color( $sdpr_text_color );
$sdpr_background_color     = $sdpr_sanitized_background ? $sdpr_sanitized_background : '#ffffff';
$sdpr_text_color           = $sdpr_sanitized_text ? $sdpr_sanitized_text : '#222222';
$sdpr_border_radius        = max( 0, min( 50, $sdpr_border_radius ) );
$sdpr_font_size            = max( 10, min( 40, $sdpr_font_size ) );

// Build inline style for modal box - only apply if customization is enabled.
$sdpr_modal_box_style = '';
if ( $sdpr_enable_popup_customization ) {
	$sdpr_modal_box_style = sprintf(
		'background-color: %s !important; border-radius: %dpx !important; font-family: %s !important; font-size: %dpx !important; color: %s !important;',
		esc_attr( $sdpr_background_color ),
		esc_attr( $sdpr_border_radius ),
		esc_attr( $sdpr_font_family ),
		esc_attr( $sdpr_font_size ),
		esc_attr( $sdpr_text_color )
	);
}
?>

<div id="sdpr-reservation-modal" class="modal-overlay sdpr-modal-overlay" aria-hidden="true" style="display: none;">
	<div class="modal-box sdpr-modal-box<?php echo $sdpr_enable_popup_customization ? ' sdpr-modal-box--custom' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="sdpr-reservation-dialog-title" aria-describedby="sdpr-reservation-dialog-description" tabindex="-1" style="<?php echo esc_attr( $sdpr_modal_box_style ); ?>">
		<div class="sdpr-modal-header">
			<h2 id="sdpr-reservation-dialog-title" data-result-title="<?php echo esc_attr( $sdpr_requires_approval ? __( 'Request submitted', 'spectral-dot-reservations' ) : __( 'Reservation confirmed', 'spectral-dot-reservations' ) ); ?>"><?php esc_html_e( 'Reserve this product', 'spectral-dot-reservations' ); ?></h2>
			<button type="button" class="modal-close" aria-label="<?php esc_attr_e( 'Close reservation dialog', 'spectral-dot-reservations' ); ?>">&times;</button>
		</div>
		<form id="sdpr-reservation-form">
			<input type="hidden" name="action" value="sdpr_reserve">
			<input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( 'sdpr_nonce' ) ); ?>">
			<input type="hidden" name="product_id" value="<?php echo esc_attr( $sdpr_pid ); ?>">

			<div id="sdpr-reservation-result" class="sdpr-reservation-notice" role="status" aria-live="polite" aria-atomic="true" style="display: none;"></div>
			<div class="sdpr-reservation-prompt">
				<?php do_action( 'sdpr_reservation_form_fields', $sdpr_product ); ?>

				<p id="sdpr-reservation-dialog-description">
					<?php
					if ( $sdpr_requires_approval ) {
						printf(
							/* translators: %d: approval request duration in hours. */
							esc_html( _n( 'Your request will remain open for up to %d hour.', 'Your request will remain open for up to %d hours.', $sdpr_pending_duration_hours, 'spectral-dot-reservations' ) ),
							(int) $sdpr_pending_duration_hours
						);
						echo ' ';
						printf(
							/* translators: %d: active reservation duration in hours. */
							esc_html( _n( 'If approved, the product will then be held for %d hour.', 'If approved, the product will then be held for %d hours.', $sdpr_duration_hours, 'spectral-dot-reservations' ) ),
							(int) $sdpr_duration_hours
						);
					} else {
						printf(
							/* translators: %d: reservation duration in hours. */
							esc_html( _n( 'Are you sure you want to reserve this product for %d hour?', 'Are you sure you want to reserve this product for %d hours?', $sdpr_duration_hours, 'spectral-dot-reservations' ) ),
							(int) $sdpr_duration_hours
						);
					}
					?>
				</p>
			</div>

			<button type="submit" class="submit-btn sdpr-button-primary"><?php esc_html_e( 'Yes, Reserve', 'spectral-dot-reservations' ); ?></button>
		</form>
	</div>
</div>

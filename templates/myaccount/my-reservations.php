<?php
/**
 * My Account - Reservations
 *
 * @package SDPR_Plugin
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<div class="sdpr-reservations-container">
	<div class="sdpr-reservations-wrapper">
		<div class="sdpr-reservations-header">
			<h2><?php esc_html_e( 'My Reserved Products', 'spectral-dot-reservations' ); ?></h2>
			<p><?php esc_html_e( 'View your reservation history and manage active reservations.', 'spectral-dot-reservations' ); ?></p>
		</div>

		<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table sdpr-reservations-table">
	<thead>
		<tr>
			<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-product">
				<span class="nobr"><?php esc_html_e( 'Product', 'spectral-dot-reservations' ); ?></span>
			</th>
			<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-status">
				<span class="nobr"><?php esc_html_e( 'Status', 'spectral-dot-reservations' ); ?></span>
			</th>
			<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-expires">
				<span class="nobr"><?php esc_html_e( 'Expires', 'spectral-dot-reservations' ); ?></span>
			</th>
			<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-time-left">
				<span class="nobr"><?php esc_html_e( 'Time Left', 'spectral-dot-reservations' ); ?></span>
			</th>
			<th class="woocommerce-orders-table__header woocommerce-orders-table__header-order-actions">
				<span class="nobr"><?php esc_html_e( 'Actions', 'spectral-dot-reservations' ); ?></span>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $sdpr_reservations as $sdpr_reservation ) : ?>
			<?php
			$sdpr_product_id = (int) SDPR_Reservation_Meta::get( $sdpr_reservation->ID, SDPR_Reservation_Meta::PRODUCT_ID );
			$sdpr_quantity   = max( 1, (int) SDPR_Reservation_Meta::get( $sdpr_reservation->ID, SDPR_Reservation_Meta::QUANTITY ) );
			$sdpr_status     = (string) SDPR_Reservation_Meta::get( $sdpr_reservation->ID, SDPR_Reservation_Meta::STATUS );
			$sdpr_expires_ts = (int) SDPR_Reservation_Meta::get( $sdpr_reservation->ID, SDPR_Reservation_Meta::EXPIRES_AT );
			$sdpr_product    = wc_get_product( $sdpr_product_id );

			if ( ! $sdpr_product ) {
				continue;
			}

			$sdpr_is_pending = ( SDPR_Reservation_Status::PENDING === $sdpr_status );
			$sdpr_is_active  = ( SDPR_Reservation_Status::ACTIVE === $sdpr_status );
			$sdpr_is_expired = ( SDPR_Reservation_Status::EXPIRED === $sdpr_status );

				$sdpr_expires_disp = ( ( $sdpr_is_active || $sdpr_is_pending || $sdpr_is_expired ) && $sdpr_expires_ts )
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sdpr_expires_ts )
				: '—';

			// Calculate time left
			$sdpr_time_left     = '';
			$sdpr_urgency_class = '';
			if ( ( $sdpr_is_active || $sdpr_is_pending ) && $sdpr_expires_ts ) {
				$sdpr_diff = $sdpr_expires_ts - time();
				if ( $sdpr_diff > 0 ) {
					$sdpr_days    = floor( $sdpr_diff / DAY_IN_SECONDS );
					$sdpr_hours   = floor( ( $sdpr_diff % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
					$sdpr_minutes = floor( ( $sdpr_diff % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

					if ( $sdpr_days > 0 ) {
						/* translators: %d: number of days remaining. */
						$sdpr_time_left = sprintf( _n( '%d day', '%d days', $sdpr_days, 'spectral-dot-reservations' ), $sdpr_days );
						if ( $sdpr_hours > 0 ) {
							/* translators: %d: number of hours remaining. */
							$sdpr_time_left .= ', ' . sprintf( _n( '%d hour', '%d hours', $sdpr_hours, 'spectral-dot-reservations' ), $sdpr_hours );
						}
					} elseif ( $sdpr_hours > 0 ) {
						/* translators: %d: number of hours remaining. */
						$sdpr_time_left = sprintf( _n( '%d hour', '%d hours', $sdpr_hours, 'spectral-dot-reservations' ), $sdpr_hours );
						if ( $sdpr_minutes > 0 ) {
							/* translators: %d: number of minutes remaining. */
							$sdpr_time_left .= ', ' . sprintf( _n( '%d minute', '%d minutes', $sdpr_minutes, 'spectral-dot-reservations' ), $sdpr_minutes );
						}
					} else {
						/* translators: %d: number of minutes remaining. */
						$sdpr_time_left = sprintf( _n( '%d minute', '%d minutes', $sdpr_minutes, 'spectral-dot-reservations' ), $sdpr_minutes );
					}

					// Add urgency class for styling
					if ( $sdpr_diff < 2 * HOUR_IN_SECONDS ) {
						$sdpr_urgency_class = 'urgent';
					} elseif ( $sdpr_diff < 6 * HOUR_IN_SECONDS ) {
						$sdpr_urgency_class = 'warning';
					}
				} else {
					$sdpr_time_left     = esc_html__( 'Expired', 'spectral-dot-reservations' );
					$sdpr_urgency_class = 'expired';
				}
			}

			if ( $sdpr_is_pending ) {
				$sdpr_urgency_class = 'pending';
			} elseif ( SDPR_Reservation_Status::FULFILLED === $sdpr_status ) {
				$sdpr_time_left     = esc_html__( 'Purchased', 'spectral-dot-reservations' );
				$sdpr_urgency_class = 'fulfilled';
			} elseif ( SDPR_Reservation_Status::DENIED === $sdpr_status ) {
				$sdpr_time_left     = esc_html__( 'Denied', 'spectral-dot-reservations' );
				$sdpr_urgency_class = 'denied';
			} elseif ( SDPR_Reservation_Status::CANCELLED === $sdpr_status ) {
				$sdpr_time_left     = esc_html__( 'Cancelled', 'spectral-dot-reservations' );
				$sdpr_urgency_class = 'cancelled';
			} elseif ( SDPR_Reservation_Status::ORDER_CANCELLED === $sdpr_status ) {
				$sdpr_time_left     = esc_html__( 'Order cancelled', 'spectral-dot-reservations' );
				$sdpr_urgency_class = 'cancelled';
			} elseif ( $sdpr_is_expired ) {
				$sdpr_time_left     = esc_html__( 'Expired', 'spectral-dot-reservations' );
				$sdpr_urgency_class = 'expired';
			}

			$sdpr_add_to_cart_url = add_query_arg( 'quantity', $sdpr_quantity, $sdpr_product->add_to_cart_url() );
			$sdpr_cancel_nonce    = wp_create_nonce( 'sdpr_cancel_res_' . $sdpr_reservation->ID );

			$sdpr_status_label = SDPR_Reservation_Status::label( $sdpr_status );

			switch ( $sdpr_status ) {
				case SDPR_Reservation_Status::ACTIVE:
					$sdpr_badge_variant = 'active';
					break;
				case SDPR_Reservation_Status::PENDING:
					$sdpr_badge_variant = 'pending';
					break;
				case SDPR_Reservation_Status::FULFILLED:
					$sdpr_badge_variant = 'fulfilled';
					break;
				case SDPR_Reservation_Status::DENIED:
					$sdpr_badge_variant = 'denied';
					break;
				case SDPR_Reservation_Status::CANCELLED:
				case SDPR_Reservation_Status::ORDER_CANCELLED:
					$sdpr_badge_variant = 'cancelled';
					break;
				case SDPR_Reservation_Status::EXPIRED:
					$sdpr_badge_variant = 'expired';
					break;
				default:
					$sdpr_badge_variant = 'unknown';
					break;
			}

			if ( '' === $sdpr_time_left ) {
				$sdpr_time_left = '—';
			}
			?>

				<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $sdpr_status ? $sdpr_status : 'unknown' ); ?> order">
				<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-product" data-title="<?php esc_attr_e( 'Product', 'spectral-dot-reservations' ); ?>">
					<a href="<?php echo esc_url( get_permalink( $sdpr_product_id ) ); ?>" class="woocommerce-LoopProduct-link">
						<?php echo esc_html( $sdpr_product->get_name() ); ?>
					</a>
					<span class="sdpr-reservation-quantity"><?php echo esc_html( sprintf( '×%d', $sdpr_quantity ) ); ?></span>
				</td>
				<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-status" data-title="<?php esc_attr_e( 'Status', 'spectral-dot-reservations' ); ?>">
					<span class="sdpr-status-badge sdpr-status-badge--<?php echo esc_attr( $sdpr_badge_variant ); ?>">
						<?php echo esc_html( $sdpr_status_label ); ?>
					</span>
					<?php do_action( 'sdpr_myaccount_reservation_details', $sdpr_reservation->ID, $sdpr_status ); ?>
				</td>
				<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-expires" data-title="<?php esc_attr_e( 'Expires', 'spectral-dot-reservations' ); ?>">
						<?php if ( ( $sdpr_is_active || $sdpr_is_pending || $sdpr_is_expired ) && $sdpr_expires_ts ) : ?>
						<time datetime="<?php echo esc_attr( gmdate( DATE_ATOM, $sdpr_expires_ts ) ); ?>">
							<?php echo esc_html( $sdpr_expires_disp ); ?>
						</time>
					<?php else : ?>
						<?php echo esc_html( $sdpr_expires_disp ); ?>
					<?php endif; ?>
				</td>
				<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-time-left <?php echo esc_attr( $sdpr_urgency_class ); ?>" data-title="<?php esc_attr_e( 'Time Left', 'spectral-dot-reservations' ); ?>">
					<span class="time-left <?php echo esc_attr( $sdpr_urgency_class ); ?>">
						<?php echo esc_html( $sdpr_time_left ); ?>
					</span>
				</td>
				<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-order-actions" data-title="<?php esc_attr_e( 'Actions', 'spectral-dot-reservations' ); ?>">
					<div class="sdpr-account-actions">
					<?php if ( $sdpr_is_active ) : ?>
						<a href="<?php echo esc_url( $sdpr_add_to_cart_url ); ?>" class="woocommerce-button button add-to-cart">
							<?php esc_html_e( 'Add to Cart', 'spectral-dot-reservations' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $sdpr_is_active || $sdpr_is_pending ) : ?>
						<a href="#" class="woocommerce-button button cancel-reservation" data-reservation-id="<?php echo esc_attr( $sdpr_reservation->ID ); ?>" data-cancel-nonce="<?php echo esc_attr( $sdpr_cancel_nonce ); ?>" data-confirm="<?php esc_attr_e( 'Are you sure you want to cancel this reservation?', 'spectral-dot-reservations' ); ?>">
							<?php esc_html_e( 'Cancel', 'spectral-dot-reservations' ); ?>
						</a>
					<?php else : ?>
						—
					<?php endif; ?>
					</div>
					</td>
				</tr>

			<?php endforeach; ?>
	</tbody>
</table>
	</div>
	<?php if ( isset( $sdpr_total_pages, $sdpr_current_page ) && $sdpr_total_pages > 1 ) : ?>
		<nav class="woocommerce-pagination" aria-label="<?php esc_attr_e( 'Reservation history pagination', 'spectral-dot-reservations' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'reservation-page', '%#%', wc_get_account_endpoint_url( 'sdpr-reservations' ) ),
						'current' => $sdpr_current_page,
						'total'   => $sdpr_total_pages,
					)
				)
			);
			?>
		</nav>
	<?php endif; ?>
</div>

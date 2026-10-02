<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reservation analytics and reporting
 */
class SDPR_Analytics {
	private $reservations;

	/**
	 * Constructor
	 */
	public function __construct( $reservations = null ) {
		$this->reservations = $reservations instanceof SDPR_Reservations ? $reservations : null;
		$this->init();
	}

	/**
	 * Initialize hooks
	 */
	private function init() {
		add_action( 'admin_menu', array( $this, 'add_analytics_submenu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_analytics_scripts' ) );
	}

	/**
	 * Add analytics submenu
	 */
	public function add_analytics_submenu() {
		add_submenu_page(
			'sdpr-settings',
			__( 'Reservation Analytics', 'spectral-dot-reservations' ),
			__( 'Analytics', 'spectral-dot-reservations' ),
			sdpr_get_manage_capability(),
			'sdpr-analytics',
			array( $this, 'analytics_page' )
		);
	}

	/**
	 * Enqueue analytics page scripts and styles
	 */
	public function enqueue_analytics_scripts( $hook ) {
		// Some WP setups generate different hook suffixes for submenu pages.
		// Prefer checking the page slug as a reliable fallback.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'sdpr_page_sdpr-analytics' === $hook || 'sdpr-analytics' === $page ) {
			wp_enqueue_style(
				'sdpr-admin-style',
				SDPR_PLUGIN_URL . 'assets/css/admin-style.css',
				array(),
				SDPR_VERSION
			);
		}
	}

	/**
	 * Display analytics page
	 */
	public function analytics_page() {
		// First, expire old reservations to get accurate stats
		$this->expire_old_reservations_for_analytics();

		$stats = $this->get_reservation_stats();
		?>
		<div class="sdpr-admin-wrapper sdpr-admin-wrapper--wide">
			<?php
			SDPR_Admin_View::render_header(
				__( 'Reservation Analytics', 'spectral-dot-reservations' ),
				__( 'Monitor reservation activity, outcomes and conversion to purchases.', 'spectral-dot-reservations' )
			);
			?>
			<div class="sdpr-admin-content">

			<div class="sdpr-stats-grid">
				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Total Reservations', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value"><?php echo esc_html( $stats['total'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Active Reservations', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-active"><?php echo esc_html( $stats['active'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Pending Approval', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-pending-approval"><?php echo esc_html( $stats['pending_approval'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Expired Reservations', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-expired"><?php echo esc_html( $stats['expired'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Cancelled Reservations', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-cancelled"><?php echo esc_html( $stats['cancelled'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Fulfilled Reservations', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-fulfilled"><?php echo esc_html( $stats['fulfilled'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Denied Reservations', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-denied"><?php echo esc_html( $stats['denied'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Cancelled Orders', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value status-order-cancelled"><?php echo esc_html( $stats['order_cancelled'] ); ?></p>
				</div>

				<div class="sdpr-stat-card">
					<h3><?php esc_html_e( 'Conversion Rate', 'spectral-dot-reservations' ); ?></h3>
					<p class="sdpr-stat-value"><?php echo esc_html( $stats['conversion_rate'] ); ?>%</p>
				</div>
			</div>

			<h2><?php esc_html_e( 'Recent Reservations', 'spectral-dot-reservations' ); ?></h2>
			<?php $this->display_recent_reservations(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Expire old reservations for analytics accuracy
	 */
	private function expire_old_reservations_for_analytics() {
		if ( $this->reservations ) {
			$this->reservations->expire_old_reservations();
		}
	}

	/**
	 * Get reservation statistics
	 */
	private function get_reservation_stats() {
		$stats = $this->reservations ? $this->reservations->get_status_counts() : array_fill_keys( SDPR_Reservation_Status::all(), 0 );
		if ( ! isset( $stats['total'] ) ) {
			$stats['total'] = array_sum( $stats );
		}
		$stats['conversion_rate'] = $stats['total'] ? round( ( $stats[ SDPR_Reservation_Status::FULFILLED ] / $stats['total'] ) * 100, 1 ) : 0;
		return $stats;
	}

	/**
	 * Display recent reservations table
	 */
	private function display_recent_reservations() {
		$reservations = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $reservations ) ) {
			echo '<p>' . esc_html__( 'No reservations found.', 'spectral-dot-reservations' ) . '</p>';
			return;
		}

		echo '<p class="sdpr-table-hint">' . esc_html__( 'Scroll horizontally to see all reservation details.', 'spectral-dot-reservations' ) . '</p>';
		echo '<div class="sdpr-table-scroll sdpr-table-scroll--analytics" tabindex="0" role="region" aria-label="' . esc_attr__( 'Recent reservation details', 'spectral-dot-reservations' ) . '">';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>' . esc_html__( 'Product', 'spectral-dot-reservations' ) . '</th><th>' . esc_html__( 'Customer', 'spectral-dot-reservations' ) . '</th><th>' . esc_html__( 'Status', 'spectral-dot-reservations' ) . '</th><th>' . esc_html__( 'Created', 'spectral-dot-reservations' ) . '</th><th>' . esc_html__( 'Expires', 'spectral-dot-reservations' ) . '</th></tr></thead>';
		echo '<tbody>';

		foreach ( $reservations as $reservation ) {
			$product_id = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::PRODUCT_ID );
			$status     = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::STATUS );
			$email      = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::EMAIL );
			$expires_ts = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::EXPIRES_AT );

			$product      = wc_get_product( $product_id );
			$product_name = $product ? $product->get_name() : __( 'Unknown Product', 'spectral-dot-reservations' );

			// Determine customer display name
			if ( $reservation->post_author ) {
				$user     = get_userdata( $reservation->post_author );
				$customer = $user ? $user->display_name : $email;
			} else {
				$name      = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::NAME );
				$surname   = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::SURNAME );
				$full_name = trim( $name . ' ' . $surname );
				$customer  = ! empty( $full_name ) ? $full_name : $email;
			}

			$expires = $expires_ts ? wp_date( 'Y-m-d H:i', $expires_ts ) : '—';

			// Add CSS class for status styling with proper fallback
			// Mirror the reservations admin view: use hyphens for CSS class names.
			$status_slug    = $status ? str_replace( '_', '-', $status ) : 'unknown';
			$status_class   = 'status-' . $status_slug;
			$status_labels  = SDPR_Reservation_Status::labels();
			$status_display = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : __( 'Unknown', 'spectral-dot-reservations' );

			echo '<tr>';
			echo '<td>' . esc_html( $product_name ) . '</td>';
			echo '<td>' . esc_html( $customer ) . '</td>';
			echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_display ) . '</span></td>';
			echo '<td>' . esc_html( get_the_date( 'Y-m-d H:i', $reservation ) ) . '</td>';
			echo '<td>' . esc_html( $expires ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}

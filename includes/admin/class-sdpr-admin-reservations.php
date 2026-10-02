<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin reservations management (list, filters, actions, AJAX).
 */
class SDPR_Admin_Reservations {
	private $reservations;

	public function __construct( $reservations = null ) {
		$this->reservations = $reservations instanceof SDPR_Reservations ? $reservations : null;
		add_action( 'wp_ajax_sdpr_cancel_admin_reservation', array( $this, 'handle_admin_cancel_reservation' ) );
		add_action( 'wp_ajax_sdpr_delete_admin_reservation', array( $this, 'handle_admin_delete_reservation' ) );
		add_action( 'wp_ajax_sdpr_approve_reservation', array( $this, 'handle_approve_reservation' ) );
		add_action( 'wp_ajax_sdpr_deny_reservation', array( $this, 'handle_deny_reservation' ) );
	}

	private function get_reservations_handler() {
		if ( ! $this->reservations ) {
			$this->reservations = new SDPR_Reservations();
		}
		return $this->reservations;
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'sdpr-admin-style', SDPR_PLUGIN_URL . 'assets/css/admin-style.css', array(), SDPR_VERSION );
		wp_enqueue_script( 'sdpr-admin-reservations', SDPR_PLUGIN_URL . 'assets/js/admin-reservations.js', array( 'jquery' ), SDPR_VERSION, true );
		wp_localize_script(
			'sdpr-admin-reservations',
			'sdprReservationsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'delete'  => wp_create_nonce( 'sdpr_admin_delete' ),
					'approve' => wp_create_nonce( 'sdpr_admin_approve' ),
					'deny'    => wp_create_nonce( 'sdpr_admin_deny' ),
					'cancel'  => wp_create_nonce( 'sdpr_admin_cancel' ),
				),
				'strings' => array(
					'dismiss'         => __( 'Dismiss this notice.', 'spectral-dot-reservations' ),
					'thisProduct'     => __( 'this product', 'spectral-dot-reservations' ),
					'delete'          => __( 'Delete', 'spectral-dot-reservations' ),
					'deleting'        => __( 'Deleting...', 'spectral-dot-reservations' ),
					'deleted'         => __( 'Reservation deleted successfully.', 'spectral-dot-reservations' ),
					'deleteFailed'    => __( 'Reservation could not be deleted.', 'spectral-dot-reservations' ),
					'approve'         => __( 'Approve', 'spectral-dot-reservations' ),
					'approving'       => __( 'Approving...', 'spectral-dot-reservations' ),
					'approved'        => __( 'Reservation approved successfully.', 'spectral-dot-reservations' ),
					'approveFailed'   => __( 'Reservation could not be approved.', 'spectral-dot-reservations' ),
					'active'          => __( 'Active', 'spectral-dot-reservations' ),
					'deny'            => __( 'Deny', 'spectral-dot-reservations' ),
					'denying'         => __( 'Denying...', 'spectral-dot-reservations' ),
					'denied'          => __( 'Reservation denied successfully.', 'spectral-dot-reservations' ),
					'deniedStatus'    => __( 'Denied', 'spectral-dot-reservations' ),
					'denyFailed'      => __( 'Reservation could not be denied.', 'spectral-dot-reservations' ),
					'denyReason'      => __( 'Please provide a reason for denying this reservation (optional):', 'spectral-dot-reservations' ),
					'cancel'          => __( 'Cancel', 'spectral-dot-reservations' ),
					'cancelling'      => __( 'Cancelling...', 'spectral-dot-reservations' ),
					'cancelled'       => __( 'Reservation cancelled successfully.', 'spectral-dot-reservations' ),
					'cancelledStatus' => __( 'Cancelled', 'spectral-dot-reservations' ),
					'cancelFailed'    => __( 'Reservation could not be cancelled.', 'spectral-dot-reservations' ),
					'missingId'       => __( 'Missing reservation ID.', 'spectral-dot-reservations' ),
					'requestFailed'   => __( 'Request failed. Please try again.', 'spectral-dot-reservations' ),
					/* translators: 1: customer, 2: product. */
					'confirmDelete'   => __( 'Permanently delete the reservation for %1$s on %2$s? This action cannot be undone.', 'spectral-dot-reservations' ),
					/* translators: 1: customer, 2: product. */
					'confirmApprove'  => __( 'Approve the reservation for %1$s on %2$s?', 'spectral-dot-reservations' ),
					/* translators: 1: customer, 2: product. */
					'confirmCancel'   => __( 'Cancel the reservation for %1$s on %2$s?', 'spectral-dot-reservations' ),
				),
			)
		);
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search_query  = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$search_type   = isset( $_GET['search_type'] ) ? sanitize_key( wp_unslash( $_GET['search_type'] ) ) : 'email';
		$page          = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="sdpr-admin-wrapper sdpr-admin-wrapper--wide sdpr-reservations-admin">
			<?php
			SDPR_Admin_View::render_header(
				__( 'Manage Reservations', 'spectral-dot-reservations' ),
				__( 'Review customer requests, track active holds and manage reservation statuses.', 'spectral-dot-reservations' )
			);
			?>
			<div class="sdpr-admin-content">
				<?php $this->render_content( $status_filter, $search_query, $search_type, $page ); ?>
			</div>
		</div>
		<?php
	}

	private function render_content( $status_filter, $search_query, $search_type, $page ) {
		$status_filter = in_array( $status_filter, array_merge( array( 'all' ), SDPR_Reservation_Status::all() ), true ) ? $status_filter : 'all';
		$search_type   = in_array( $search_type, array( 'email', 'product', 'product_id', 'customer_name' ), true ) ? $search_type : 'email';
		$query         = $this->get_filtered_reservations( $status_filter, $search_query, $search_type, $page );
		$last_page     = max( 1, (int) $query->max_num_pages );
		if ( $page > $last_page ) {
			$page  = $last_page;
			$query = $this->get_filtered_reservations( $status_filter, $search_query, $search_type, $page );
		}
		$reservations = $query->posts;
		$stats        = $this->get_reservations_summary();
		?>
			<input type="hidden" id="sdpr-list-page" value="<?php echo esc_attr( $page ); ?>">

			<div class="sdpr-reservations-stats">
				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
					<div><strong><?php esc_html_e( 'Pending Approval:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::PENDING ] ); ?></div>
					<div><strong><?php esc_html_e( 'Active:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::ACTIVE ] ); ?></div>
					<div><strong><?php esc_html_e( 'Expired:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::EXPIRED ] ); ?></div>
					<div><strong><?php esc_html_e( 'Cancelled:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::CANCELLED ] ); ?></div>
					<div><strong><?php esc_html_e( 'Fulfilled:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::FULFILLED ] ); ?></div>
					<div><strong><?php esc_html_e( 'Denied:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::DENIED ] ); ?></div>
					<div><strong><?php esc_html_e( 'Order Cancelled:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats[ SDPR_Reservation_Status::ORDER_CANCELLED ] ); ?></div>
					<div><strong><?php esc_html_e( 'Total:', 'spectral-dot-reservations' ); ?></strong> <?php echo esc_html( $stats['total'] ); ?></div>
				</div>
			</div>

			<div class="sdpr-reservations-filters">
				<div class="alignleft actions">
					<label class="screen-reader-text" for="status-filter"><?php esc_html_e( 'Filter reservations by status', 'spectral-dot-reservations' ); ?></label>
					<select name="status_filter" id="status-filter">
						<option value="all" <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'All Statuses', 'spectral-dot-reservations' ); ?></option>
						<?php foreach ( SDPR_Reservation_Status::labels() as $status_value => $status_label ) : ?>
							<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $status_filter, $status_value ); ?>><?php echo esc_html( $status_label ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="search-type"><?php esc_html_e( 'Search reservations by', 'spectral-dot-reservations' ); ?></label>
					<select name="search_type" id="search-type">
						<option value="email" <?php selected( $search_type, 'email' ); ?>><?php esc_html_e( 'Email', 'spectral-dot-reservations' ); ?></option>
						<option value="product" <?php selected( $search_type, 'product' ); ?>><?php esc_html_e( 'Product Name', 'spectral-dot-reservations' ); ?></option>
						<option value="product_id" <?php selected( $search_type, 'product_id' ); ?>><?php esc_html_e( 'Product ID', 'spectral-dot-reservations' ); ?></option>
						<option value="customer_name" <?php selected( $search_type, 'customer_name' ); ?>><?php esc_html_e( 'Customer', 'spectral-dot-reservations' ); ?></option>
					</select>

					<label class="screen-reader-text" for="reservation-search"><?php esc_html_e( 'Search reservations', 'spectral-dot-reservations' ); ?></label>
					<input type="search" id="reservation-search" placeholder="<?php esc_attr_e( 'Search reservations...', 'spectral-dot-reservations' ); ?>" value="<?php echo esc_attr( $search_query ); ?>" style="width: 200px;">

					<button type="button" class="button" id="filter-reservations"><?php esc_html_e( 'Filter', 'spectral-dot-reservations' ); ?></button>
					<button type="button" class="button" id="clear-filters"><?php esc_html_e( 'Clear', 'spectral-dot-reservations' ); ?></button>
				</div>

				<div class="alignright">
					<span class="displaying-num"><?php /* translators: %d: number of reservations. */ printf( esc_html__( '%d reservations', 'spectral-dot-reservations' ), esc_html( number_format_i18n( $query->found_posts ) ) ); ?></span>
				</div>
			</div>

			<?php if ( empty( $reservations ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'No reservations found matching your criteria.', 'spectral-dot-reservations' ); ?></p>
				</div>
			<?php else : ?>
				<p class="sdpr-table-hint"><?php esc_html_e( 'Scroll horizontally to see all reservation details.', 'spectral-dot-reservations' ); ?></p>
				<div class="sdpr-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Reservation details', 'spectral-dot-reservations' ); ?>">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 20%;"><?php esc_html_e( 'Product', 'spectral-dot-reservations' ); ?></th>
							<th style="width: 20%;"><?php esc_html_e( 'Customer', 'spectral-dot-reservations' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Status', 'spectral-dot-reservations' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Reserved', 'spectral-dot-reservations' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Expires', 'spectral-dot-reservations' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Time Left', 'spectral-dot-reservations' ); ?></th>
							<th style="width: 12%;"><?php esc_html_e( 'Actions', 'spectral-dot-reservations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $reservations as $reservation ) : ?>
							<?php $this->render_row( $reservation ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php
				$pagination = paginate_links(
					array(
						'base'    => add_query_arg(
							array(
								'paged'       => '%#%',
								'status'      => $status_filter,
								'search_type' => $search_type,
								'search'      => $search_query,
							),
							admin_url( 'admin.php?page=sdpr-manage-reservations' )
						),
						'current' => $page,
						'total'   => max( 1, (int) $query->max_num_pages ),
					)
				);
				if ( is_string( $pagination ) && '' !== $pagination ) {
					echo wp_kses_post( $pagination );
				}
				?>
			<?php endif; ?>
		<?php
	}

	private function send_action_success( $message ) {
		// The same renderer owns the initial page and the complete post-action list.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each action verifies its nonce before reaching this helper.
		$status = isset( $_POST['list_status'] ) ? sanitize_key( wp_unslash( $_POST['list_status'] ) ) : 'all';
		$search = isset( $_POST['list_search'] ) ? sanitize_text_field( wp_unslash( $_POST['list_search'] ) ) : '';
		$type   = isset( $_POST['list_search_type'] ) ? sanitize_key( wp_unslash( $_POST['list_search_type'] ) ) : 'email';
		$page   = isset( $_POST['list_paged'] ) ? max( 1, absint( wp_unslash( $_POST['list_paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		ob_start();
		$this->render_content( $status, $search, $type, $page );
		$content = ob_get_clean();
		wp_send_json_success(
			array(
				'message' => $message,
				'content' => $content,
			)
		);
	}

	public function handle_admin_cancel_reservation() {
		if ( ! current_user_can( sdpr_get_manage_capability() ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		check_ajax_referer( 'sdpr_admin_cancel', 'nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		if ( ! $reservation_id || 'sdpr_reservation' !== get_post_type( $reservation_id ) ) {
			wp_send_json_error( 'Invalid reservation ID.' );
		}

		$status = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
		if ( SDPR_Reservation_Status::ACTIVE !== $status ) {
			wp_send_json_error( 'Reservation is not active.' );
		}

		$result = $this->get_reservations_handler()->cancel_reservation( $reservation_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		if ( ! $result ) {
			wp_send_json_error( 'Reservation changed before it could be cancelled.' );
		}

		SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::CANCELLED_BY_ADMIN, time() );
		SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::CANCELLED_BY_USER, get_current_user_id() );

		$this->send_action_success( __( 'Reservation cancelled successfully.', 'spectral-dot-reservations' ) );
	}

	public function handle_admin_delete_reservation() {
		if ( ! current_user_can( sdpr_get_manage_capability() ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		check_ajax_referer( 'sdpr_admin_delete', 'nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		if ( ! $reservation_id || 'sdpr_reservation' !== get_post_type( $reservation_id ) ) {
			wp_send_json_error( 'Invalid reservation ID.' );
		}

		$status = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
		if ( SDPR_Reservation_Status::ACTIVE === $status ) {
			wp_send_json_error( 'Cannot delete active reservations. Cancel them first.' );
		}

		$result = wp_delete_post( $reservation_id, true );
		if ( $result ) {
			$this->send_action_success( __( 'Reservation deleted successfully.', 'spectral-dot-reservations' ) );
		}

		wp_send_json_error( 'Failed to delete reservation.' );
	}

	public function handle_approve_reservation() {
		if ( ! current_user_can( sdpr_get_manage_capability() ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		check_ajax_referer( 'sdpr_admin_approve', 'nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		if ( ! $reservation_id ) {
			wp_send_json_error( 'Invalid reservation ID.' );
		}

		$post = get_post( $reservation_id );
		if ( ! $post || 'sdpr_reservation' !== $post->post_type ) {
			wp_send_json_error( 'Invalid reservation.' );
		}

		$result = $this->get_reservations_handler()->approve_reservation( $reservation_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} elseif ( $result ) {
			$this->send_action_success( __( 'Reservation approved successfully.', 'spectral-dot-reservations' ) );
		}

		wp_send_json_error( 'Failed to approve reservation.' );
	}

	public function handle_deny_reservation() {
		if ( ! current_user_can( sdpr_get_manage_capability() ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		check_ajax_referer( 'sdpr_admin_deny', 'nonce' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( wp_unslash( $_POST['reservation_id'] ) ) : 0;
		$reason         = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $reservation_id ) {
			wp_send_json_error( 'Invalid reservation ID.' );
		}

		$post = get_post( $reservation_id );
		if ( ! $post || 'sdpr_reservation' !== $post->post_type ) {
			wp_send_json_error( 'Invalid reservation.' );
		}

		$result = $this->get_reservations_handler()->deny_reservation( $reservation_id, $reason );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} elseif ( $result ) {
			$this->send_action_success( __( 'Reservation denied successfully.', 'spectral-dot-reservations' ) );
		}

		wp_send_json_error( 'Failed to deny reservation.' );
	}

	private function get_filtered_reservations( $status_filter = 'all', $search_query = '', $search_type = 'email', $page = 1 ) {
		$meta_query               = array();
		$customer_reservation_ids = null;
		$force_empty              = false;

		if ( 'all' !== $status_filter ) {
			$meta_query[] = array(
				'key'     => SDPR_Reservation_Meta::STATUS,
				'value'   => $status_filter,
				'compare' => '=',
			);
		}

		if ( '' !== $search_query ) {
			switch ( $search_type ) {
				case 'email':
					$meta_query[] = array(
						'key'     => SDPR_Reservation_Meta::EMAIL,
						'value'   => $search_query,
						'compare' => 'LIKE',
					);
					break;

				case 'product':
					$product_ids = get_posts(
						array(
							'post_type'      => 'product',
							'post_status'    => 'any',
							'fields'         => 'ids',
							'posts_per_page' => 100,
							'no_found_rows'  => true,
							's'              => $search_query,
						)
					);

					if ( empty( $product_ids ) ) {
						$force_empty = true;
						break;
					}

					$meta_query[] = array(
						'key'     => SDPR_Reservation_Meta::PRODUCT_ID,
						'value'   => $product_ids,
						'compare' => 'IN',
					);
					break;

				case 'product_id':
					if ( ! is_numeric( $search_query ) ) {
						$force_empty = true;
						break;
					}
					$meta_query[] = array(
						'key'     => SDPR_Reservation_Meta::PRODUCT_ID,
						'value'   => absint( $search_query ),
						'compare' => '=',
					);
					break;

				case 'customer_name':
					$customer_reservation_ids = $this->get_customer_match_ids( $search_query );
					break;
			}
		}

		$args = array(
			'post_type'      => 'sdpr_reservation',
			'post_status'    => 'publish',
			'posts_per_page' => 25,
			'paged'          => max( 1, absint( $page ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}
		if ( null !== $customer_reservation_ids ) {
			$args['post__in'] = $customer_reservation_ids ? $customer_reservation_ids : array( 0 );
		}
		if ( $force_empty ) {
			$args['post__in'] = array( 0 );
		}

		return new WP_Query( $args );
	}

	/**
	 * Get reservation IDs matching logged-in or guest customer data.
	 *
	 * @param string $search_query Search query.
	 * @return int[]
	 */
	private function get_customer_match_ids( $search_query ) {
		$search_query = trim( $search_query );
		if ( '' === $search_query ) {
			return array();
		}

		$user_query = new WP_User_Query(
			array(
				'search'         => '*' . $search_query . '*',
				'search_columns' => array( 'display_name', 'user_email', 'user_login' ),
				'fields'         => 'ID',
				'number'         => 100,
			)
		);

		$author_ids = array_map( 'absint', $user_query->get_results() );
		$matches    = array();

		if ( $author_ids ) {
			$matches = get_posts(
				array(
					'post_type'      => 'sdpr_reservation',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'author__in'     => $author_ids,
				)
			);
		}

		$guest_matches = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'author__in'     => array( 0 ),
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => SDPR_Reservation_Meta::NAME,
						'value'   => $search_query,
						'compare' => 'LIKE',
					),
					array(
						'key'     => SDPR_Reservation_Meta::SURNAME,
						'value'   => $search_query,
						'compare' => 'LIKE',
					),
				),
			)
		);

		return array_values( array_unique( array_map( 'absint', array_merge( $matches, $guest_matches ) ) ) );
	}

	private function get_reservations_summary() {
		return $this->get_reservations_handler()->get_status_counts();
	}

	private function render_row( $reservation ) {
		$product_id = (int) SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::PRODUCT_ID );
		$email      = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::EMAIL );
		$name       = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::NAME );
		$surname    = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::SURNAME );
		$expires_ts = (int) SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::EXPIRES_AT );
		$status     = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::STATUS );
		$quantity   = max( 1, (int) SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::QUANTITY ) );

		$product          = wc_get_product( $product_id );
		$product_name     = $product ? $product->get_name() : 'Unknown Product (ID: ' . $product_id . ')';
		$product_edit_url = $product ? admin_url( 'post.php?post=' . $product_id . '&action=edit' ) : '#';

		if ( $reservation->post_author ) {
			$user           = get_userdata( $reservation->post_author );
			$customer       = $user ? $user->display_name . ' (' . $user->user_email . ')' : 'Unknown User';
			$customer_short = $user ? $user->display_name : 'Unknown User';
		} else {
			$customer_full = trim( $name . ' ' . $surname );
			if ( '' === $customer_full ) {
				$customer       = $email ? $email : __( 'No email', 'spectral-dot-reservations' );
				$customer_short = $email ? $email : __( 'No email', 'spectral-dot-reservations' );
			} else {
				$customer       = $customer_full . ' (' . $email . ')';
				$customer_short = $customer_full;
			}
		}

		$reserved_date = get_the_date( 'M j, Y @ H:i', $reservation );
		$expires_disp  = $expires_ts ? wp_date( 'M j, Y @ H:i', $expires_ts ) : '—';

		$time_left  = '—';
		$time_class = '';
		if ( $expires_ts && in_array( $status, SDPR_Reservation_Status::open(), true ) ) {
			$diff = $expires_ts - time();
			if ( $diff > 0 ) {
				$days    = floor( $diff / DAY_IN_SECONDS );
				$hours   = floor( ( $diff % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
				$minutes = floor( ( $diff % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

				if ( $days > 0 ) {
					$time_left = sprintf( '%dd %dh', $days, $hours );
				} elseif ( $hours > 0 ) {
					$time_left = sprintf( '%dh %dm', $hours, $minutes );
				} else {
					$time_left = sprintf( '%dm', $minutes );
				}

				if ( $diff < 2 * HOUR_IN_SECONDS ) {
					$time_class = 'time-left-critical';
				} elseif ( $diff < 6 * HOUR_IN_SECONDS ) {
					$time_class = 'time-left-warning';
				}
			} else {
				$time_left  = 'Expired';
				$time_class = 'time-left-critical';
			}
		}

		$status_class   = 'status-' . str_replace( '_', '-', $status );
		$status_labels  = SDPR_Reservation_Status::labels();
		$status_display = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : __( 'Unknown', 'spectral-dot-reservations' );

		echo '<tr>';
		echo '<td>';
		if ( $product ) {
			echo '<a href="' . esc_url( $product_edit_url ) . '" target="_blank">' . esc_html( $product_name ) . '</a>';
		} else {
			echo esc_html( $product_name );
		}
		echo ' <span aria-label="' . esc_attr( sprintf( /* translators: %d: reserved product quantity. */ __( 'Quantity %d', 'spectral-dot-reservations' ), $quantity ) ) . '">' . esc_html( sprintf( '×%d', $quantity ) ) . '</span>';
		echo '</td>';
		echo '<td title="' . esc_attr( $customer ) . '">' . esc_html( $customer ) . '</td>';
		echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_display ) . '</span>';
		do_action( 'sdpr_admin_reservation_row_details', $reservation->ID, $status );
		echo '</td>';
		echo '<td>' . esc_html( $reserved_date ) . '</td>';
		echo '<td>' . esc_html( $expires_disp ) . '</td>';
		echo '<td class="' . esc_attr( $time_class ) . '">' . esc_html( $time_left ) . '</td>';
		echo '<td><div class="sdpr-row-actions">';

		if ( SDPR_Reservation_Status::PENDING === $status ) {
			echo '<button type="button" class="button button-small sdpr-approve-reservation" ';
			echo 'data-reservation-id="' . esc_attr( $reservation->ID ) . '" ';
			echo 'data-customer="' . esc_attr( $customer_short ) . '" ';
			echo 'data-product="' . esc_attr( $product_name ) . '">';
			echo esc_html__( 'Approve', 'spectral-dot-reservations' );
			echo '</button>';

			echo '<button type="button" class="button button-small button-link-delete sdpr-deny-reservation" ';
			echo 'data-reservation-id="' . esc_attr( $reservation->ID ) . '" ';
			echo 'data-customer="' . esc_attr( $customer_short ) . '" ';
			echo 'data-product="' . esc_attr( $product_name ) . '">';
			echo esc_html__( 'Deny', 'spectral-dot-reservations' );
			echo '</button>';
		} elseif ( SDPR_Reservation_Status::ACTIVE === $status ) {
			echo '<button type="button" class="button button-small sdpr-cancel-reservation" ';
			echo 'data-reservation-id="' . esc_attr( $reservation->ID ) . '" ';
			echo 'data-customer="' . esc_attr( $customer_short ) . '" ';
			echo 'data-product="' . esc_attr( $product_name ) . '">';
			echo esc_html__( 'Cancel', 'spectral-dot-reservations' );
			echo '</button>';
		} else {
			echo '<button type="button" class="button button-small button-link-delete sdpr-delete-reservation" ';
			echo 'data-reservation-id="' . esc_attr( $reservation->ID ) . '" ';
			echo 'data-customer="' . esc_attr( $customer_short ) . '" ';
			echo 'data-product="' . esc_attr( $product_name ) . '">';
			echo esc_html__( 'Delete', 'spectral-dot-reservations' );
			echo '</button>';
		}

		echo '</div></td>';
		echo '</tr>';
	}
}

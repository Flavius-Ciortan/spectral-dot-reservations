<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core reservations functionality
 */
class SDPR_Reservations {

	const CRON_HOOK = 'sdpr_expire_reservations';

	private $inventory;
	private $notifications;
	private $privacy;
	private $repository;
	private $cart_order;
	private $expiration;
	private $rules;
	private $lifecycle;

	/**
	 * Constructor
	 */
	public function __construct( $inventory = null, $notifications = null, $privacy = null, $repository = null, $cart_order = null, $expiration = null, $rules = null, $lifecycle = null ) {
		$this->inventory     = $inventory instanceof SDPR_Inventory_Manager ? $inventory : new SDPR_Inventory_Manager();
		$this->notifications = $notifications instanceof SDPR_Notification_Dispatcher ? $notifications : new SDPR_Notification_Dispatcher();
		$this->privacy       = $privacy instanceof SDPR_Privacy_Service ? $privacy : new SDPR_Privacy_Service();
		$this->repository    = $repository instanceof SDPR_Reservation_Repository ? $repository : new SDPR_Reservation_Repository();
		$this->cart_order    = $cart_order instanceof SDPR_Cart_Order_Service ? $cart_order : new SDPR_Cart_Order_Service( $this->inventory, $this->repository );
		$this->expiration    = $expiration instanceof SDPR_Expiration_Service ? $expiration : new SDPR_Expiration_Service( $this->inventory, $this->notifications, $this->cart_order );
		$this->rules         = $rules instanceof SDPR_Reservation_Rules ? $rules : new SDPR_Reservation_Rules();
		$this->lifecycle     = $lifecycle instanceof SDPR_Reservation_Lifecycle_Interface ? $lifecycle : new SDPR_Reservation_Lifecycle( $this->inventory, $this->notifications, $this->repository, $this->cart_order, $this->rules, new SDPR_Lock_Manager() );
		$this->init();
	}

	/**
	 * Initialize hooks
	 */
	private function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_endpoints' ) );
		if ( did_action( 'init' ) ) {
			$this->register_cron_schedule();
		} else {
			add_action( 'init', array( $this, 'register_cron_schedule' ), 5 );
		}
		add_action( self::CRON_HOOK, array( $this, 'expire_old_reservations' ) );
		add_action( 'init', array( $this, 'schedule_expiration' ), 20 );

		// WooCommerce account integration
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_item' ) );
		add_action( 'woocommerce_account_sdpr-reservations_endpoint', array( $this, 'reservations_endpoint_content' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_reservation_actions' ) );
		add_filter( 'woocommerce_endpoint_sdpr-reservations_title', array( $this, 'reservations_endpoint_title' ) );
		add_filter( 'woocommerce_page_title', array( $this, 'change_reservations_page_title' ) );

		// AJAX handlers
		add_action( 'wp_ajax_sdpr_reserve', array( $this, 'handle_reservation_ajax' ) );

		add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'include_owned_hold_in_stock' ), 20, 2 );
		add_filter( 'woocommerce_product_get_stock_status', array( $this, 'include_owned_hold_in_stock_status' ), 20, 2 );
		add_filter( 'woocommerce_product_is_in_stock', array( $this, 'include_owned_hold_in_availability' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'attach_reservation_to_cart_item' ), 10, 4 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'sync_cart_reservations' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_cart_reservations' ), 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_reservation_to_order_item' ), 10, 4 );
		add_filter( 'woocommerce_order_item_quantity', array( $this, 'exclude_linked_hold_from_order_quantity' ), 10, 3 );
		add_filter( 'woocommerce_order_hold_stock_minutes', array( $this, 'skip_redundant_order_stock_hold' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'transfer_holds_to_order' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'transfer_holds_to_order' ), 5 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_reservation_order_item_meta' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_transferred_order_stock' ), 5 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_transferred_order_stock' ), 5 );

		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
		add_filter( 'site_status_tests', array( $this, 'register_site_health_tests' ) );
	}

	public function register_cron_schedule() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
	}

	public function add_cron_schedule( $schedules ) {
		return $this->expiration->add_cron_schedule( $schedules );
	}

	public function schedule_expiration() {
		$this->expiration->schedule();
	}

	public function register_site_health_tests( $tests ) {
		return $this->expiration->register_site_health_tests( $tests );
	}

	public function get_site_health_result() {
		return $this->expiration->get_site_health_result();
	}

	/** Return reservation counts for admin summaries and extensions. */
	public function get_status_counts() {
		return $this->repository->get_status_counts();
	}

	/**
	 * Register custom post type for reservations
	 */
	public function register_post_type() {
		register_post_type(
			'sdpr_reservation',
			array(
				'labels'           => array( 'name' => __( 'Reservations', 'spectral-dot-reservations' ) ),
				'public'           => false,
				'show_ui'          => false,
				'supports'         => array( 'title', 'author' ),
				'capability_type'  => 'post',
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Register WooCommerce endpoints
	 */
	public function register_endpoints() {
		add_rewrite_endpoint( 'sdpr-reservations', EP_ROOT | EP_PAGES );
	}

	/**
	 * Add query vars for WooCommerce
	 */
	public function add_query_vars( $vars ) {
		$vars['sdpr-reservations'] = 'sdpr-reservations';
		return $vars;
	}

	/**
	 * Flush rewrite rules (call this on plugin activation)
	 */
	public function flush_rewrite_rules() {
		$this->register_post_type();
		$this->register_endpoints();
		flush_rewrite_rules();
	}

	/**
	 * Handle reservation AJAX request
	 */
	public function handle_reservation_ajax() {
		check_ajax_referer( 'sdpr_nonce', 'security' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'You must be logged in to reserve products.', 'spectral-dot-reservations' ), 401 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;
		if ( ! $product_id ) {
			wp_send_json_error( __( 'Invalid product ID.', 'spectral-dot-reservations' ), 400 );
		}

		$user_id = get_current_user_id();
		$result  = $this->lifecycle->request( $product_id, $user_id, $quantity );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 400 );
		}
		wp_send_json_success( $result['requires_approval'] ? __( 'Reservation request submitted for approval.', 'spectral-dot-reservations' ) : __( 'Reservation created successfully.', 'spectral-dot-reservations' ) );
	}

	/**
	 * Create a new reservation
	 */
	public function create_reservation( $product_id, $user_id = 0, $guest_email = '', $quantity = 1 ) {
		return $this->lifecycle->create( $product_id, $user_id, $guest_email, $quantity );
	}

	/**
	 * Check if product is reservable
	 */
	public function is_product_reservable( $product_id ) {
		return $this->rules->is_product_reservable( $product_id, get_current_user_id() );
	}

	/**
	 * Check if reservations are globally enabled
	 */
	public function are_reservations_globally_enabled() {
		return $this->rules->are_reservations_globally_enabled();
	}

	/**
	 * Get max reservations per user
	 */
	public function get_max_reservations_per_user( $product_id = 0 ) {
		return $this->rules->get_max_reservations_per_user( get_current_user_id(), $product_id );
	}

	public function requires_approval( $product_id, $user_id ) {
		return $this->rules->requires_approval( $product_id, $user_id );
	}

	public function get_duration_hours( $context, $product_id = 0, $user_id = 0 ) {
		return $this->rules->get_duration_hours( $context, $product_id, $user_id );
	}

	/**
	 * Count active reservations for user
	 */
	public function count_active_reservations( $user_id = 0, $email = '' ) {
		return $this->repository->count_active( $user_id, $email );
	}

	/**
	 * Check if user has active reservation for specific product
	 */
	public function user_has_active_reservation_for_product( $product_id, $user_id = 0, $email = '' ) {
		return $this->repository->has_active( $product_id, $user_id, $email );
	}

	/**
	 * Expire old reservations
	 */
	public function expire_old_reservations() {
		$this->expiration->expire_old_reservations();
	}

	/**
	 * Expire a single reservation
	 */
	public function expire_reservation( $reservation_id ) {
		return $this->expiration->expire_reservation( $reservation_id );
	}

	/**
	 * Cancel a reservation
	 */
	public function cancel_reservation( $reservation_id ) {
		return $this->lifecycle->cancel( $reservation_id );
	}

	public function extend_reservation( $reservation_id, $additional_hours, $source = 'extension' ) {
		return $this->lifecycle->extend( $reservation_id, $additional_hours, $source );
	}

	/**
	 * Add reservations to WooCommerce account menu
	 */
	public function add_account_menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new['sdpr-reservations'] = __( 'Reserved products', 'spectral-dot-reservations' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['sdpr-reservations'] ) ) {
			$new['sdpr-reservations'] = __( 'Reserved products', 'spectral-dot-reservations' );
		}
		return $new;
	}

	/**
	 * Change endpoint title for reservations
	 */
	public function reservations_endpoint_title( $title ) {
		return __( 'Reserved products', 'spectral-dot-reservations' );
	}

	/**
	 * Change page title when on reservations page
	 */
	public function change_reservations_page_title( $title ) {
		global $wp_query;

		if ( ! is_admin() && is_main_query() && in_the_loop() && is_account_page() ) {
			// Check if we're on the reservations endpoint
			if ( isset( $wp_query->query_vars['sdpr-reservations'] ) ||
				( function_exists( 'wc_get_page_id' ) && is_wc_endpoint_url( 'sdpr-reservations' ) ) ) {
				return __( 'Reservations', 'spectral-dot-reservations' );
			}
		}

		return $title;
	}

	/**
	 * Display reservations in My Account
	 */
	public function reservations_endpoint_content() {
		// Prevent double rendering
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		if ( ! is_user_logged_in() ) {
			wc_print_notice( __( 'Please log in to see your reservations.', 'spectral-dot-reservations' ), 'notice' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$current_page = isset( $_GET['reservation-page'] ) ? max( 1, absint( wp_unslash( $_GET['reservation-page'] ) ) ) : 1;
		$query        = new WP_Query(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'author'         => get_current_user_id(),
				'posts_per_page' => 20,
				'paged'          => $current_page,
				'meta_query'     => array(
					array(
						'key'     => SDPR_Reservation_Meta::STATUS,
						'compare' => 'EXISTS',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$reservations = $query->posts;

		if ( empty( $reservations ) ) {
			wc_print_notice( __( 'You have no reservations.', 'spectral-dot-reservations' ), 'notice' );
			return;
		}

		// Use WooCommerce's built-in table structure for consistency
		wc_get_template(
			'myaccount/my-reservations.php',
			array(
				'sdpr_reservations' => $reservations,
				'sdpr_current_page' => $current_page,
				'sdpr_total_pages'  => (int) $query->max_num_pages,
			),
			'',
			SDPR_PLUGIN_PATH . 'templates/'
		);
	}

	/**
	 * Handle reservation actions (cancel, etc.)
	 */
	public function handle_reservation_actions() {
		if ( ! is_user_logged_in() || 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! isset( $_POST['sdpr_cancel_res'] ) ) {
			return;
		}

		$reservation_id = absint( wp_unslash( $_POST['sdpr_cancel_res'] ) );
		$nonce          = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! $reservation_id || ! wp_verify_nonce( $nonce, 'sdpr_cancel_res_' . $reservation_id ) ) {
			return;
		}

		$post = get_post( $reservation_id );
		if ( ! $post || 'sdpr_reservation' !== $post->post_type || get_current_user_id() !== (int) $post->post_author ) {
			return;
		}

		$this->cancel_reservation( $reservation_id );
		wp_safe_redirect( wc_get_account_endpoint_url( 'sdpr-reservations' ) );
		exit;
	}

	/** Give the reservation owner access to the physical unit already held for them. */
	public function include_owned_hold_in_stock( $stock, $product ) {
		return $this->cart_order->include_owned_hold_in_stock( $stock, $product );
	}

	public function include_owned_hold_in_stock_status( $status, $product ) {
		return $this->cart_order->include_owned_hold_in_stock_status( $status, $product );
	}

	public function include_owned_hold_in_availability( $in_stock, $product ) {
		return $this->cart_order->include_owned_hold_in_availability( $in_stock, $product );
	}

	public function attach_reservation_to_cart_item( $cart_item_data, $product_id, $variation_id, $quantity ) {
		return $this->cart_order->attach_reservation_to_cart_item( $cart_item_data, $product_id, $variation_id, $quantity );
	}

	/**
	 * Reconcile existing cart lines after a reservation is created or a session is restored.
	 *
	 * The add-to-cart filter cannot link a hold created after the product entered the
	 * cart. Checkout must use current reservation ownership, not historical hook order.
	 */
	public function sync_cart_reservations( $cart ) {
		$this->cart_order->sync_cart_reservations( $cart );
	}

	public function copy_reservation_to_order_item( $item, $cart_item_key, $values, $order ) {
		$this->cart_order->copy_reservation_to_order_item( $item, $cart_item_key, $values, $order );
	}

	/**
	 * Exclude the unit already held by this plugin from WooCommerce's order-level
	 * stock reservation and reduction calculations.
	 */
	public function exclude_linked_hold_from_order_quantity( $quantity, $order, $item ) {
		return $this->cart_order->exclude_linked_hold_from_order_quantity( $quantity, $order, $item );
	}

	/** Skip WooCommerce's DB hold when this plugin already holds every stock-managed unit. */
	public function skip_redundant_order_stock_hold( $minutes, $order ) {
		return $this->cart_order->skip_redundant_order_stock_hold( $minutes, $order );
	}

	/** Check an active hold, or the same hold after it was transferred to this order. */
	public function transfer_holds_to_order( $order ) {
		$this->cart_order->transfer_holds_to_order( $order );
	}

	public function restore_transferred_order_stock( $order_id ) {
		$this->cart_order->restore_transferred_order_stock( $order_id );
	}

	public function hide_reservation_order_item_meta( $keys ) {
		return $this->cart_order->hide_reservation_order_item_meta( $keys );
	}

	/** Auto-fulfill reservations when order is completed (legacy callback). */
	public function fulfill_reservation_on_purchase( $order_id ) {
		$this->cart_order->fulfill_reservation_on_purchase( $order_id );
	}

	/**
	 * Approve a pending reservation
	 */
	public function approve_reservation( $reservation_id ) {
		return $this->lifecycle->approve( $reservation_id );
	}

	/**
	 * Deny a pending reservation
	 */
	public function deny_reservation( $reservation_id, $reason = '' ) {
		return $this->lifecycle->deny( $reservation_id, $reason );
	}

	/**
	 * Count "open" reservations for a user (active + pending approval).
	 *
	 * Pending approvals count towards limits to prevent spamming requests.
	 */
	public function count_open_reservations( $user_id = 0, $email = '' ) {
		return $this->repository->count_open( $user_id, $email );
	}

	/**
	 * Check if a user already has an open reservation request for a product (active + pending approval).
	 */
	public function user_has_open_reservation_for_product( $product_id, $user_id = 0, $email = '' ) {
		return $this->repository->user_has_open_for_product( $product_id, $user_id, $email );
	}

	public function register_privacy_exporter( $exporters ) {
		return $this->privacy->register_exporter( $exporters );
	}

	public function register_privacy_eraser( $erasers ) {
		return $this->privacy->register_eraser( $erasers );
	}

	public function export_personal_data( $email_address, $page = 1 ) {
		return $this->privacy->export_personal_data( $email_address, $page );
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		return $this->privacy->erase_personal_data( $email_address, $page );
	}
}

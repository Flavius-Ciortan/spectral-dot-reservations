<?php

/**
 * Plugin Name:       Spectral Dot - Product Reservations for WooCommerce
 * Description:       Allows WooCommerce customers to reserve products for a limited time before purchase.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 * Author:            Spectral Dot
 * Text Domain:       spectral-dot-reservations
 * Domain Path:       /languages
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'SDPR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SDPR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SDPR_VERSION', '1.0.0' );

/** Capability required to configure and operate reservations. */
function sdpr_get_manage_capability() {
	return (string) apply_filters( 'sdpr_manage_reservations_capability', 'manage_woocommerce' );
}

/**
 * Main plugin class
 */
class SDPR_Plugin {

	/**
	 * Single instance of the plugin
	 */
	private static $instance = null;

	/**
	 * Plugin components
	 */
	public $admin;
	public $frontend;
	public $reservations;
	private $services;
	private $dependency_notices;

	/**
	 * Get single instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the plugin
	 */
	private function init() {
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-dependency-notices.php';
		$this->dependency_notices = new SDPR_Dependency_Notices();
		add_action( 'admin_init', array( $this, 'queue_dependency_notice' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate_inventory_states' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		// WooCommerce has loaded by this point, while WordPress init has not yet run.
		add_action( 'plugins_loaded', array( $this, 'bootstrap_plugin' ), 20 );

		// Activation and deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );
	}

	/**
	 * Check plugin dependencies
	 */
	public function check_dependencies() {
		return class_exists( 'WooCommerce' );
	}

	/** Translate dependency messages only after WordPress has initialized. */
	public function queue_dependency_notice() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->dependency_notices->add(
				'woocommerce-required',
				__( 'Spectral Dot Reservations requires WooCommerce to be installed and active.', 'spectral-dot-reservations' ),
				'error',
				'plugins'
			);
			return;
		}
		$this->dependency_notices->remove( 'woocommerce-required' );
	}

	/**
	 * Load and initialize services before WordPress fires init.
	 *
	 * Core services register post types and rewrite endpoints on init, so creating
	 * them from an init callback would register those callbacks one request late.
	 */
	public function bootstrap_plugin() {
		if ( ! $this->check_dependencies() ) {
			return;
		}

		$this->load_classes();
		$this->services = new SDPR_Service_Container();
		$this->init_plugin();
	}

	/**
	 * Load required classes
	 */
	public function load_classes() {
		// Core classes
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-service-container.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-dependency-notices.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-status.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-meta.php';
		require_once SDPR_PLUGIN_PATH . 'includes/interface-sdpr-reservation-repository.php';
		require_once SDPR_PLUGIN_PATH . 'includes/interface-sdpr-reservation-lifecycle.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-repository.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-rules.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-lock-manager.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-inventory-manager.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-cart-order-service.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-expiration-service.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-notification-dispatcher.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-lifecycle.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-privacy-service.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservations.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-email-manager.php';

		// Admin classes
		if ( is_admin() ) {
			require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin.php';
			require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-admin-reservations.php';
			require_once SDPR_PLUGIN_PATH . 'includes/admin/class-sdpr-analytics.php';
		}

		// Frontend classes
		if ( ! is_admin() ) {
			require_once SDPR_PLUGIN_PATH . 'includes/frontend/class-sdpr-frontend.php';
		}
	}

	/**
	 * Initialize plugin components
	 */
	public function init_plugin() {
		if ( $this->reservations instanceof SDPR_Reservations ) {
			return;
		}

		// Initialize core
		$inventory = $this->services->set( 'inventory', new SDPR_Inventory_Manager() );
		$this->services->set( 'dependency_notices', $this->dependency_notices );
		$repository         = $this->services->set( 'repository', new SDPR_Reservation_Repository() );
		$notifications      = $this->services->set( 'notifications', new SDPR_Notification_Dispatcher() );
		$privacy            = $this->services->set( 'privacy', new SDPR_Privacy_Service() );
		$rules              = $this->services->set( 'rules', new SDPR_Reservation_Rules() );
		$locks              = $this->services->set( 'locks', new SDPR_Lock_Manager() );
		$cart_order         = $this->services->set( 'cart_order', new SDPR_Cart_Order_Service( $inventory, $repository ) );
		$expiration         = $this->services->set( 'expiration', new SDPR_Expiration_Service( $inventory, $notifications, $cart_order ) );
		$lifecycle          = $this->services->set( 'lifecycle', new SDPR_Reservation_Lifecycle( $inventory, $notifications, $repository, $cart_order, $rules, $locks ) );
		$this->reservations = $this->services->set( 'reservations', new SDPR_Reservations( $inventory, $notifications, $privacy, $repository, $cart_order, $expiration, $rules, $lifecycle ) );
		$this->services->set( 'email_manager', new SDPR_Email_Manager() );

		// Initialize admin
		if ( is_admin() ) {
			$this->admin = new SDPR_Admin( $this->reservations );
			new SDPR_Analytics( $this->reservations );
		}

		// Initialize frontend
		if ( ! is_admin() ) {
			$this->frontend = new SDPR_Frontend( $this->reservations );
		}

		do_action( 'sdpr_plugin_loaded', $this, $this->services );
	}

	/** Retrieve a documented core service for compatible add-ons. */
	public function get_service( $id ) {
		return $this->services instanceof SDPR_Service_Container ? $this->services->get( $id ) : null;
	}

	/**
	 * Plugin activation
	 */
	public function activate_plugin() {
		if ( ! $this->check_dependencies() ) {
			return;
		}

		// Load reservations class to register endpoints
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-service-container.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-dependency-notices.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-status.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-meta.php';
		require_once SDPR_PLUGIN_PATH . 'includes/interface-sdpr-reservation-repository.php';
		require_once SDPR_PLUGIN_PATH . 'includes/interface-sdpr-reservation-lifecycle.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-repository.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-rules.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-lock-manager.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-inventory-manager.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-cart-order-service.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-expiration-service.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-notification-dispatcher.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservation-lifecycle.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-privacy-service.php';
		require_once SDPR_PLUGIN_PATH . 'includes/class-sdpr-reservations.php';
		$reservations = new SDPR_Reservations();

		// Flush rewrite rules to register the new endpoint
		$reservations->flush_rewrite_rules();
		$reservations->schedule_expiration();
		update_option( 'sdpr_version', SDPR_VERSION, false );
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate_plugin() {
		wp_clear_scheduled_hook( 'sdpr_expire_reservations' );
		// Flush rewrite rules on deactivation to clean up
		flush_rewrite_rules();
	}

	/**
	 * Declare tested WooCommerce feature compatibility.
	 */
	public function declare_woocommerce_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	/** Normalize legacy local-offset timestamps in bounded upgrade batches. */
	public function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'sdpr_version', '0' ), SDPR_VERSION, '>=' ) || ! $this->reservations instanceof SDPR_Reservations ) {
			return;
		}
		$ids    = get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => SDPR_Reservation_Meta::TIMESTAMP_MODEL,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$offset = current_datetime()->getOffset();
		foreach ( $ids as $reservation_id ) {
			$expires = (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT );
			SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT, max( 0, $expires - $offset ) );
			SDPR_Reservation_Meta::update( $reservation_id, SDPR_Reservation_Meta::TIMESTAMP_MODEL, 'utc' );
		}
		$this->reservations->schedule_expiration();
		if ( count( $ids ) < 500 ) {
			update_option( 'sdpr_version', SDPR_VERSION, false );
		}
	}

	public function maybe_migrate_inventory_states() {
		if ( $this->reservations instanceof SDPR_Reservations ) {
			$this->reservations->migrate_inventory_states();
		}
	}

	public function add_privacy_policy_content() {
		if ( ! is_admin() ) {
			return;
		}

		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			$content  = '<p>' . esc_html__( 'Spectral Dot Reservations stores reservation records on this website. These records can include the customer user ID, first and last name, email address, product ID, quantity, reservation status, creation and expiry times, inventory state, denial details, and a related WooCommerce order ID.', 'spectral-dot-reservations' ) . '</p>';
			$content .= '<p>' . esc_html__( 'Reservation data is not sent to Spectral Dot Reservations or any other service by the plugin. When email notifications are enabled, reservation details are passed to the mail delivery system configured for this WordPress website, which may be operated by a third party selected by the site owner.', 'spectral-dot-reservations' ) . '</p>';
			$content .= '<p>' . esc_html__( 'Reservation records remain in the website database until the plugin is uninstalled. For closed reservations, the WordPress personal data eraser anonymizes the customer identity and free-text denial details while retaining the operational reservation, order, and inventory data. Open reservations are retained during erasure until their inventory obligation ends; the customer can submit another erasure request after the reservation closes.', 'spectral-dot-reservations' ) . '</p>';
			wp_add_privacy_policy_content( __( 'Spectral Dot Reservations', 'spectral-dot-reservations' ), wp_kses_post( $content ) );
		}
	}
}

// Initialize the plugin
SDPR_Plugin::get_instance();

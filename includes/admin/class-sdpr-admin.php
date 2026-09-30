<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functionality
 */
class SDPR_Admin {

	private $reservations_admin;
	private $reservations;

	/**
	 * Constructor
	 */
	public function __construct( $reservations = null ) {
		$this->reservations       = $reservations instanceof SDPR_Reservations ? $reservations : null;
		$this->reservations_admin = class_exists( 'SDPR_Admin_Reservations' ) ? new SDPR_Admin_Reservations( $this->reservations ) : null;
		$this->init();
	}

	/**
	 * Initialize admin hooks
	 */
	private function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'init_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_product_reservations_list' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Spectral Dot Reservations Settings', 'spectral-dot-reservations' ),
			__( 'Spectral Dot Reservations', 'spectral-dot-reservations' ),
			sdpr_get_manage_capability(),
			'sdpr-settings',
			array( $this, 'settings_page' ),
			'dashicons-clock',
			80
		);

		// Add Settings submenu (points to the same page as the main menu)
		add_submenu_page(
			'sdpr-settings',
			__( 'Settings', 'spectral-dot-reservations' ),
			__( 'Settings', 'spectral-dot-reservations' ),
			sdpr_get_manage_capability(),
			'sdpr-settings',
			array( $this, 'settings_page' )
		);

		// Add reservations management submenu
		add_submenu_page(
			'sdpr-settings',
			__( 'Reservations', 'spectral-dot-reservations' ),
			__( 'Reservations', 'spectral-dot-reservations' ),
			sdpr_get_manage_capability(),
			'sdpr-manage-reservations',
			$this->reservations_admin ? array( $this->reservations_admin, 'render_page' ) : '__return_null'
		);
	}

	/**
	 * Initialize settings
	 */
	public function init_settings() {
		register_setting(
			'sdpr_options_group',
			'sdpr_options',
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'sdpr_settings_section',
			'',
			'__return_false',
			'sdpr-settings'
		);

		$fields = array(
			'sdpr_enable_reservation'         => __( 'Enable Reservation', 'spectral-dot-reservations' ),
			'sdpr_max_reservations'           => __( 'Max Reservations Per User', 'spectral-dot-reservations' ),
			'sdpr_reservation_duration'       => __( 'Reservation Duration (hours)', 'spectral-dot-reservations' ),
			'sdpr_pending_duration'           => __( 'Approval Request Duration (hours)', 'spectral-dot-reservations' ),
			'sdpr_enable_email_notifications' => __( 'Enable Email Notifications', 'spectral-dot-reservations' ),
			'sdpr_require_admin_approval'     => __( 'Require Admin Approval for Reservations', 'spectral-dot-reservations' ),
		);

		foreach ( $fields as $id => $title ) {
			add_settings_field(
				$id,
				$title,
				array( $this, $id . '_callback' ),
				'sdpr-settings',
				'sdpr_settings_section',
				array( 'label_for' => $id )
			);
		}
	}

	/**
	 * Sanitize plugin options before saving.
	 *
	 * @param array $input Raw option input.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$current   = get_option( 'sdpr_options', array() );
		$current   = is_array( $current ) ? $current : array();
		$sanitized = array();

		$sanitized['enable_reservation']                   = ! empty( $input['enable_reservation'] ) ? 1 : 0;
		$sanitized['enable_email_notifications']           = ! empty( $input['enable_email_notifications'] ) ? 1 : 0;
		$sanitized['require_admin_approval']               = ! empty( $input['require_admin_approval'] ) ? 1 : 0;
		$sanitized['enable_popup_customization_logged_in'] = ! empty( $input['enable_popup_customization_logged_in'] ) ? 1 : 0;

		$max_reservations = isset( $input['max_reservations'] ) ? (int) $input['max_reservations'] : 1;
		if ( $max_reservations < 1 ) {
			$max_reservations = 1;
			add_settings_error( 'sdpr_options', 'sdpr_max_reservations', __( 'Max reservations must be at least 1.', 'spectral-dot-reservations' ) );
		} elseif ( $max_reservations > 100 ) {
			$max_reservations = 100;
			add_settings_error( 'sdpr_options', 'sdpr_max_reservations_max', __( 'Max reservations cannot exceed 100.', 'spectral-dot-reservations' ) );
		}
		$sanitized['max_reservations'] = $max_reservations;

		$reservation_duration = isset( $input['reservation_duration'] ) ? (int) $input['reservation_duration'] : 24;
		if ( $reservation_duration < 1 ) {
			$reservation_duration = 1;
			add_settings_error( 'sdpr_options', 'sdpr_duration_min', __( 'Reservation duration must be at least 1 hour.', 'spectral-dot-reservations' ) );
		} elseif ( $reservation_duration > 168 ) {
			$reservation_duration = 168;
			add_settings_error( 'sdpr_options', 'sdpr_duration_max', __( 'Reservation duration cannot exceed 168 hours.', 'spectral-dot-reservations' ) );
		}
		$sanitized['reservation_duration'] = $reservation_duration;

			$pending_duration = isset( $input['pending_duration'] )
				? (int) $input['pending_duration']
				: ( isset( $current['pending_duration'] ) ? (int) $current['pending_duration'] : $reservation_duration );
		if ( $pending_duration < 1 ) {
			$pending_duration = 1;
			add_settings_error( 'sdpr_options', 'sdpr_pending_duration_min', __( 'Approval request duration must be at least 1 hour.', 'spectral-dot-reservations' ) );
		} elseif ( $pending_duration > 168 ) {
			$pending_duration = 168;
			add_settings_error( 'sdpr_options', 'sdpr_pending_duration_max', __( 'Approval request duration cannot exceed 168 hours.', 'spectral-dot-reservations' ) );
		}
			$sanitized['pending_duration'] = $pending_duration;

		$sanitized['popup_customization_logged_in'] = $this->sanitize_popup_customization(
			isset( $input['popup_customization_logged_in'] ) ? $input['popup_customization_logged_in'] : array()
		);

		return $sanitized;
	}

	/**
	 * Sanitize popup customization settings.
	 *
	 * @param array $settings Raw popup settings.
	 * @return array
	 */
	private function sanitize_popup_customization( $settings ) {
		$settings      = is_array( $settings ) ? $settings : array();
		$allowed_fonts = $this->get_popup_font_choices();

		$raw_border_radius = isset( $settings['border_radius'] ) ? (int) $settings['border_radius'] : 12;
		$raw_font_size     = isset( $settings['font_size'] ) ? (int) $settings['font_size'] : 16;
		$border_radius     = isset( $settings['border_radius'] ) ? (int) $settings['border_radius'] : 12;
		$font_size         = isset( $settings['font_size'] ) ? (int) $settings['font_size'] : 16;
		$border_radius     = max( 0, min( 50, $border_radius ) );
		$font_size         = max( 10, min( 40, $font_size ) );

		if ( $raw_border_radius !== $border_radius ) {
			add_settings_error( 'sdpr_options', 'sdpr_popup_border_radius', __( 'Popup border radius must be between 0 and 50 pixels.', 'spectral-dot-reservations' ) );
		}

		if ( $raw_font_size !== $font_size ) {
			add_settings_error( 'sdpr_options', 'sdpr_popup_font_size', __( 'Popup font size must be between 10 and 40 pixels.', 'spectral-dot-reservations' ) );
		}

		$background_color = $this->sanitize_hex_color_or_default( $settings['background_color'] ?? '', '#ffffff' );
		$text_color       = $this->sanitize_hex_color_or_default( $settings['text_color'] ?? '', '#222222' );
		$font_family      = isset( $settings['font_family'] ) ? sanitize_text_field( $settings['font_family'] ) : '';

		if ( isset( $settings['background_color'] ) && $background_color !== $settings['background_color'] ) {
			add_settings_error( 'sdpr_options', 'sdpr_popup_background_color', __( 'Popup background color was invalid and has been reset to the default.', 'spectral-dot-reservations' ) );
		}

		if ( isset( $settings['text_color'] ) && $text_color !== $settings['text_color'] ) {
			add_settings_error( 'sdpr_options', 'sdpr_popup_text_color', __( 'Popup text color was invalid and has been reset to the default.', 'spectral-dot-reservations' ) );
		}

		if ( '' !== $font_family && ! isset( $allowed_fonts[ $font_family ] ) ) {
			add_settings_error( 'sdpr_options', 'sdpr_popup_font_family', __( 'Popup font family was invalid and has been reset to the default.', 'spectral-dot-reservations' ) );
		}

		return array(
			'border_radius'    => $border_radius,
			'background_color' => $background_color,
			'font_family'      => isset( $allowed_fonts[ $font_family ] ) ? $font_family : 'Arial, Helvetica, sans-serif',
			'font_size'        => $font_size,
			'text_color'       => $text_color,
		);
	}

	/**
	 * Sanitize a color value with fallback.
	 *
	 * @param string $value Raw color.
	 * @param string $fallback Default color.
	 * @return string
	 */
	private function sanitize_hex_color_or_default( $value, $fallback ) {
		$sanitized = sanitize_hex_color( $value );
		return $sanitized ? $sanitized : $fallback;
	}

	/**
	 * Get supported popup font choices.
	 *
	 * @return array<string,string>
	 */
	private function get_popup_font_choices() {
		return array(
			'Arial, Helvetica, sans-serif'        => 'Arial',
			'Verdana, Geneva, sans-serif'         => 'Verdana',
			'Georgia, serif'                      => 'Georgia',
			'Times New Roman, Times, serif'       => 'Times New Roman',
			'Tahoma, Geneva, sans-serif'          => 'Tahoma',
			'Trebuchet MS, Helvetica, sans-serif' => 'Trebuchet MS',
			'Courier New, Courier, monospace'     => 'Courier New',
			'Roboto, sans-serif'                  => 'Roboto (Google)',
			'Open Sans, sans-serif'               => 'Open Sans (Google)',
			'Lato, sans-serif'                    => 'Lato (Google)',
			'Montserrat, sans-serif'              => 'Montserrat (Google)',
		);
	}

	/**
	 * Enable reservation field callback
	 */
	public function sdpr_enable_reservation_callback() {
		$options = get_option( 'sdpr_options' );
		$checked = ! empty( $options['enable_reservation'] ) ? 'checked' : '';
		?>
		<div class="sdpr-setting-field">
			<div class="sdpr-setting-control">
				<label class="toggle-switch">
					<input type="checkbox" id="sdpr_enable_reservation" name="sdpr_options[enable_reservation]" value="1" <?php echo esc_attr( $checked ); ?>>
					<span class="slider"></span>
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'Enable product reservations across your store.', 'spectral-dot-reservations' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Max reservations field callback
	 */
	public function sdpr_max_reservations_callback() {
		$options = get_option( 'sdpr_options' );
		$value   = isset( $options['max_reservations'] ) ? absint( $options['max_reservations'] ) : 1;
		?>
		<div class="sdpr-setting-field">
			<div class="sdpr-setting-control">
				<input type="number" id="sdpr_max_reservations" name="sdpr_options[max_reservations]" value="<?php echo esc_attr( $value ); ?>" class="sdpr-small-input" />
			</div>
			<p class="description"><?php esc_html_e( 'Limit how many active reservations a user can have at once.', 'spectral-dot-reservations' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Reservation duration field callback
	 */
	public function sdpr_reservation_duration_callback() {
		$options = get_option( 'sdpr_options' );
		$value   = isset( $options['reservation_duration'] ) ? absint( $options['reservation_duration'] ) : 24;
		?>
		<div class="sdpr-setting-field">
			<div class="sdpr-setting-control">
				<div class="sdpr-input-right-align">
					<input type="number" id="sdpr_reservation_duration" name="sdpr_options[reservation_duration]" value="<?php echo esc_attr( $value ); ?>" class="sdpr-small-input" />
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'How long reservations last (1-168 hours, default: 24).', 'spectral-dot-reservations' ); ?></p>
		</div>
		<?php
	}

	/** Approval request duration field callback. */
	public function sdpr_pending_duration_callback() {
		$options = get_option( 'sdpr_options', array() );
		$value   = isset( $options['pending_duration'] ) ? absint( $options['pending_duration'] ) : 24;
		?>
		<div class="sdpr-setting-field">
			<div class="sdpr-setting-control">
				<div class="sdpr-input-right-align">
					<input type="number" id="sdpr_pending_duration" min="1" max="168" name="sdpr_options[pending_duration]" value="<?php echo esc_attr( $value ); ?>" class="sdpr-small-input" />
				</div>
			</div>
			<p class="description"><?php esc_html_e( 'How long an approval request remains open. The active reservation duration starts when it is approved.', 'spectral-dot-reservations' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enable email notifications field callback
	 */
	public function sdpr_enable_email_notifications_callback() {
		$options = get_option( 'sdpr_options' );
		$checked = ! empty( $options['enable_email_notifications'] ) ? 'checked' : '';
		?>
		<div class="sdpr-setting-field">
			<div class="sdpr-setting-control">
				<label class="toggle-switch">
					<input type="checkbox" id="sdpr_enable_email_notifications" name="sdpr_options[enable_email_notifications]" value="1" <?php echo esc_attr( $checked ); ?>>
					<span class="slider"></span>
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'Send email confirmations and status updates to customers.', 'spectral-dot-reservations' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Require admin approval field callback
	 */
	public function sdpr_require_admin_approval_callback() {
		$options = get_option( 'sdpr_options' );
		$checked = ! empty( $options['require_admin_approval'] ) ? 'checked' : '';
		?>
		<div class="sdpr-setting-field">
			<div class="sdpr-setting-control">
				<label class="toggle-switch">
					<input type="checkbox" id="sdpr_require_admin_approval" name="sdpr_options[require_admin_approval]" value="1" <?php echo esc_attr( $checked ); ?>>
					<span class="slider"></span>
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'Reservations require admin approval before becoming active.', 'spectral-dot-reservations' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render this form's validation feedback and WordPress's save result only.
	 */
	public function render_settings_notices() {
		foreach ( get_settings_errors() as $notice ) {
			if ( 'sdpr_options' !== $notice['setting'] && ! ( 'general' === $notice['setting'] && 'settings_updated' === $notice['code'] ) ) {
				continue;
			}
			$type = 'updated' === $notice['type'] ? 'success' : $notice['type'];
			$type = in_array( $type, array( 'error', 'success', 'warning', 'info' ), true ) ? $type : 'error';
			printf(
				'<div class="notice notice-%1$s is-dismissible sdpr-settings-notice"><p><strong>%2$s</strong></p></div>',
				esc_attr( $type ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Settings page HTML
	 */
	public function settings_page() {
		?>
		<div class="sdpr-admin-wrapper">
			<!-- Header with Logo -->
			<div class="sdpr-admin-header">
				<div class="sdpr-header-content">
					<div class="sdpr-title-section">
						<h1 class="sdpr-main-title"><?php esc_html_e( 'Spectral Dot Reservations Settings', 'spectral-dot-reservations' ); ?></h1>
						<p class="sdpr-subtitle"><?php esc_html_e( 'Manage your product reservation system', 'spectral-dot-reservations' ); ?></p>
					</div>
					<div class="sdpr-logo-section">
						<span class="sdpr-brand-name"><?php esc_html_e( 'Spectral Dot', 'spectral-dot-reservations' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Main Content -->
			<div class="sdpr-admin-content">
				<?php $this->render_settings_notices(); ?>
				<!-- Navigation Tabs -->
				<div class="sdpr-nav-wrapper">
					<div class="sdpr-nav-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'spectral-dot-reservations' ); ?>">
						<button type="button" id="sdpr-tab-general" class="sdpr-nav-tab sdpr-nav-tab-active" data-target="general" role="tab" aria-controls="sdpr-general" aria-selected="true" tabindex="0">
							<span class="sdpr-tab-icon" aria-hidden="true">⚙️</span>
							<span class="sdpr-tab-text"><?php esc_html_e( 'General Settings', 'spectral-dot-reservations' ); ?></span>
						</button>
						<button type="button" id="sdpr-tab-logged-in" class="sdpr-nav-tab" data-target="logged-in" role="tab" aria-controls="sdpr-logged-in" aria-selected="false" tabindex="-1">
							<span class="sdpr-tab-icon" aria-hidden="true">🎨</span>
							<span class="sdpr-tab-text"><?php esc_html_e( 'Pop-up Customization', 'spectral-dot-reservations' ); ?></span>
						</button>
					</div>
				</div>

				<!-- Tab Content -->
				<form method="post" action="options.php" class="sdpr-settings-form">
					<?php settings_fields( 'sdpr_options_group' ); ?>
					<div class="sdpr-tab-container">
						<!-- General Settings Tab -->
						<div id="sdpr-general" class="sdpr-tab-content sdpr-tab-active" role="tabpanel" aria-labelledby="sdpr-tab-general" tabindex="0">
							<div class="sdpr-settings-card">
								<div class="sdpr-card-header">
									<h3><?php esc_html_e( 'Configuration', 'spectral-dot-reservations' ); ?></h3>
									<p><?php esc_html_e( 'Configure the basic settings for your reservation system', 'spectral-dot-reservations' ); ?></p>
								</div>
								<div class="sdpr-card-body">
									<?php do_settings_sections( 'sdpr-settings' ); ?>
								</div>
							</div>
						</div>

						<!-- Pop-up Customization Tab -->
						<div id="sdpr-logged-in" class="sdpr-tab-content" role="tabpanel" aria-labelledby="sdpr-tab-logged-in" tabindex="0" hidden>
							<div class="sdpr-settings-card">
								<div class="sdpr-card-header">
									<h3><?php esc_html_e( 'Pop-up Customization', 'spectral-dot-reservations' ); ?></h3>
									<p><?php esc_html_e( 'Customize the appearance of the reservation pop-up modal', 'spectral-dot-reservations' ); ?></p>
								</div>
								<div class="sdpr-card-body">
									<?php
									$options                              = get_option( 'sdpr_options' );
									$options                              = is_array( $options ) ? $options : array();
									$enable_popup_customization_logged_in = isset( $options['enable_popup_customization_logged_in'] ) ? (bool) $options['enable_popup_customization_logged_in'] : false;
									$popup_settings_logged_in             = isset( $options['popup_customization_logged_in'] ) ? $options['popup_customization_logged_in'] : array();
									?>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="sdpr_enable_popup_customization_logged_in"><?php esc_html_e( 'Enable Pop-up Customization', 'spectral-dot-reservations' ); ?></label></th>
												<td>
													<div class="sdpr-setting-field">
														<div class="sdpr-setting-control">
															<label class="toggle-switch">
														<input type="checkbox" id="sdpr_enable_popup_customization_logged_in" name="sdpr_options[enable_popup_customization_logged_in]" value="1" <?php checked( $enable_popup_customization_logged_in ); ?>>
																<span class="slider"></span>
															</label>
														</div>
														<p class="description"><?php esc_html_e( 'Enable custom styling for the reservation pop-up modal.', 'spectral-dot-reservations' ); ?></p>
													</div>
												</td>
											</tr>
										</table>
									<div class="sdpr-popup-customization-fields-logged-in" style="display:<?php echo $enable_popup_customization_logged_in ? 'block' : 'none'; ?>;margin-top:1rem;">
										<table class="form-table">
											<tr>
												<th scope="row"><label for="sdpr_popup_border_radius"><?php esc_html_e( 'Border Radius (px)', 'spectral-dot-reservations' ); ?></label></th>
												<td><input type="number" id="sdpr_popup_border_radius" name="sdpr_options[popup_customization_logged_in][border_radius]" value="<?php echo esc_attr( $popup_settings_logged_in['border_radius'] ?? '12' ); ?>" class="sdpr-input-right-align"></td>
											</tr>
											<tr>
												<th scope="row"><label for="sdpr_popup_background_color"><?php esc_html_e( 'Background Color', 'spectral-dot-reservations' ); ?></label></th>
												<td><input type="color" id="sdpr_popup_background_color" name="sdpr_options[popup_customization_logged_in][background_color]" value="<?php echo esc_attr( $popup_settings_logged_in['background_color'] ?? '#ffffff' ); ?>" class="sdpr-input-right-align"></td>
											</tr>
											<tr>
												<th scope="row"><label for="sdpr_popup_font_family"><?php esc_html_e( 'Font Family', 'spectral-dot-reservations' ); ?></label></th>
												<td>
													<select id="sdpr_popup_font_family" name="sdpr_options[popup_customization_logged_in][font_family]" class="sdpr-input-right-align">
														<?php
														$fonts         = $this->get_popup_font_choices();
														$selected_font = $popup_settings_logged_in['font_family'] ?? 'Arial, Helvetica, sans-serif';
														foreach ( $fonts as $value => $label ) {
															echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected_font, $value, false ) . '>' . esc_html( $label ) . '</option>';
														}
														?>
													</select>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="sdpr_popup_font_size"><?php esc_html_e( 'Font Size (px)', 'spectral-dot-reservations' ); ?></label></th>
												<td><input type="number" id="sdpr_popup_font_size" name="sdpr_options[popup_customization_logged_in][font_size]" value="<?php echo esc_attr( $popup_settings_logged_in['font_size'] ?? '16' ); ?>" class="sdpr-input-right-align"></td>
											</tr>
											<tr>
												<th scope="row"><label for="sdpr_popup_text_color"><?php esc_html_e( 'Text Color', 'spectral-dot-reservations' ); ?></label></th>
												<td><input type="color" id="sdpr_popup_text_color" name="sdpr_options[popup_customization_logged_in][text_color]" value="<?php echo esc_attr( $popup_settings_logged_in['text_color'] ?? '#222222' ); ?>" class="sdpr-input-right-align"></td>
											</tr>
										</table>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="sdpr-form-actions">
						<?php submit_button( __( 'Save Settings', 'spectral-dot-reservations' ), 'primary sdpr-save-btn', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</div>

		<?php
	}

	/**
	 * Add product reservations list in inventory tab
	 */
	public function add_product_reservations_list() {
		global $post;

		if ( ! $post ) {
			return;
		}

		$reservations = $this->get_product_reservations( $post->ID );

		echo '<div class="options_group">';
		echo '<h4 style="padding-left: 12px;">' . esc_html__( 'Active Reservations', 'spectral-dot-reservations' ) . '</h4>';

		if ( empty( $reservations ) ) {
			echo '<p>' . esc_html__( 'No active reservations for this product.', 'spectral-dot-reservations' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="margin-top: 10px;">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Customer', 'spectral-dot-reservations' ) . '</th>';
			echo '<th>' . esc_html__( 'Expires', 'spectral-dot-reservations' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'spectral-dot-reservations' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $reservations as $reservation ) {
				$this->display_product_reservation_row( $reservation );
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Get active reservations for a specific product
	 */
	private function get_product_reservations( $product_id ) {
		return get_posts(
			array(
				'post_type'      => 'sdpr_reservation',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'   => SDPR_Reservation_Meta::STATUS,
						'value' => SDPR_Reservation_Status::ACTIVE,
					),
					array(
						'key'   => SDPR_Reservation_Meta::PRODUCT_ID,
						'value' => $product_id,
					),
					array(
						'key'     => SDPR_Reservation_Meta::EXPIRES_AT,
						'value'   => time(),
						'type'    => 'NUMERIC',
						'compare' => '>',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Display single product reservation row
	 */
	private function display_product_reservation_row( $reservation ) {
		$email      = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::EMAIL );
		$name       = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::NAME );
		$surname    = SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::SURNAME );
		$expires_ts = (int) SDPR_Reservation_Meta::get( $reservation->ID, SDPR_Reservation_Meta::EXPIRES_AT );

		// Determine customer display name
		if ( $reservation->post_author ) {
			$user     = get_userdata( $reservation->post_author );
			$customer = $user ? $user->display_name : 'Unknown User';
		} else {
			$customer = trim( $name . ' ' . $surname );
			if ( empty( $customer ) ) {
				$customer = $email;
			} else {
				$customer .= ' (' . $email . ')';
			}
		}

		$expires_disp = $expires_ts ? wp_date( 'M j, Y @ H:i', $expires_ts ) : '—';

		echo '<tr>';
		echo '<td>' . esc_html( $customer ) . '</td>';
		echo '<td>' . esc_html( $expires_disp ) . '</td>';
		echo '<td>';
		echo '<button type="button" class="button sdpr-cancel-reservation" ';
		echo 'data-reservation-id="' . esc_attr( $reservation->ID ) . '" ';
		echo 'data-customer="' . esc_attr( $customer ) . '">';
		echo esc_html__( 'Cancel', 'spectral-dot-reservations' );
		echo '</button>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Enqueue admin scripts
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Hook suffix can vary depending on menu nesting; `page` is stable.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'toplevel_page_sdpr-settings' === $hook || 'sdpr-settings' === $page ) {
			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style( 'sdpr-admin-style', SDPR_PLUGIN_URL . 'assets/css/admin-style.css', array(), SDPR_VERSION );
			wp_enqueue_script( 'sdpr-admin-settings', SDPR_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'jquery' ), SDPR_VERSION, true );
		}

		if (
			'sdpr_page_sdpr-manage-reservations' === $hook
			|| 'sdpr-settings_page_sdpr-manage-reservations' === $hook
			|| 'sdpr-manage-reservations' === $page
		) {
			if ( $this->reservations_admin ) {
				$this->reservations_admin->enqueue_assets();
			} else {
				wp_enqueue_script( 'jquery' );
				wp_enqueue_style( 'sdpr-admin-style', SDPR_PLUGIN_URL . 'assets/css/admin-style.css', array(), SDPR_VERSION );
			}
		}

		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			global $post;
			if ( $post && 'product' === $post->post_type ) {
				wp_enqueue_script( 'sdpr-admin-product', SDPR_PLUGIN_URL . 'assets/js/admin-product.js', array( 'jquery' ), SDPR_VERSION, true );
				wp_localize_script(
					'sdpr-admin-product',
					'sdprProductReservations',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'sdpr_admin_cancel' ),
						'strings' => array(
							/* translators: %s: customer name. */
							'confirmCancel' => __( 'Cancel the reservation for %s?', 'spectral-dot-reservations' ),
							'cancelling'    => __( 'Cancelling...', 'spectral-dot-reservations' ),
							'cancelled'     => __( 'Reservation cancelled successfully.', 'spectral-dot-reservations' ),
							'cancel'        => __( 'Cancel', 'spectral-dot-reservations' ),
							'failed'        => __( 'Reservation could not be cancelled.', 'spectral-dot-reservations' ),
							'requestFailed' => __( 'Request failed. Please try again.', 'spectral-dot-reservations' ),
						),
					)
				);
			}
		}
	}
}

<?php
/**
 * Shared presentation for the plugin's admin screens.
 *
 * @package SpectralDotReservations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDPR_Admin_View {

	/**
	 * Render a consistent page header with the bundled brand logo.
	 *
	 * @param string $title       Translated page heading.
	 * @param string $description Translated description of the page.
	 */
	public static function render_header( $title, $description ) {
		?>
		<header class="sdpr-admin-header">
			<div class="sdpr-header-content">
				<div class="sdpr-title-section">
					<h1 class="sdpr-main-title"><?php echo esc_html( $title ); ?></h1>
					<p class="sdpr-subtitle"><?php echo esc_html( $description ); ?></p>
				</div>
				<div class="sdpr-logo-section">
					<img class="sdpr-logo" src="<?php echo esc_url( SDPR_PLUGIN_URL . 'assets/images/spectral-dot-logo-inline.svg' ); ?>" width="2486" height="522" alt="<?php esc_attr_e( 'Spectral Dot', 'spectral-dot-reservations' ); ?>">
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Provide a monochrome data URI that WordPress can recolor.
	 *
	 * @return string Menu icon URI or a fallback Dashicon.
	 */
	public static function menu_icon() {
		$path = SDPR_PLUGIN_PATH . 'assets/images/reservations-stopwatch-menu.svg';
		if ( ! is_readable( $path ) ) {
			return 'dashicons-clock';
		}
		$svg = file_get_contents( $path );
		return false !== $svg ? 'data:image/svg+xml;base64,' . base64_encode( $svg ) : 'dashicons-clock';
	}

	/**
	 * Scope the full-width layout to this plugin's three screens.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public static function admin_body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && in_array(
			$screen->id,
			array(
				'toplevel_page_sdpr-settings',
				'spectral-dot-reservations_page_sdpr-manage-reservations',
				'spectral-dot-reservations_page_sdpr-analytics',
			),
			true
		) ) {
			$classes .= ' sdpr-admin-page';
		}
		return $classes;
	}
}

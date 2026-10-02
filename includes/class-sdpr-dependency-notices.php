<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Scoped, dismissible operational notices shared with compatible add-ons. */
final class SDPR_Dependency_Notices {
	private $notices = array();

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sdpr_dismiss_notice', array( $this, 'dismiss' ) );
	}

	/** The plugins scope additionally permits an actionable dependency notice on Plugins. */
	public function add( $id, $message, $type = 'error', $scope = 'plugin' ) {
		$id      = sanitize_key( $id );
		$message = sanitize_text_field( $message );
		if ( ! $id || ! $message || ! in_array( $scope, array( 'plugin', 'plugins' ), true ) ) {
			return false;
		}
		$this->notices[ $id ] = array(
			'message' => $message,
			'type'    => in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'error',
			'scope'   => $scope,
		);
		return true;
	}

	public function all() {
		return $this->notices;
	}

	public function remove( $id ) {
		unset( $this->notices[ sanitize_key( $id ) ] );
	}

	private function can_manage() {
		return current_user_can( sdpr_get_manage_capability() ) || current_user_can( 'activate_plugins' );
	}

	public function visible_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $this->can_manage() ) {
			return array();
		}
		$is_plugin_screen = (bool) preg_match( '/(?:^|_page_)sdpr-(settings|manage-reservations|analytics)$/', $screen->id );
		$dismissed        = get_user_option( 'sdpr_dismissed_notices' );
		$dismissed        = is_array( $dismissed ) ? $dismissed : array();
		$visible          = array();
		foreach ( $this->notices as $id => $notice ) {
			if ( ! $is_plugin_screen && ! ( 'plugins' === $notice['scope'] && 'plugins' === $screen->id ) ) {
				continue;
			}
			$fingerprint = hash( 'sha256', wp_json_encode( $notice ) );
			if ( isset( $dismissed[ $id ]['fingerprint'], $dismissed[ $id ]['until'] ) && $fingerprint === $dismissed[ $id ]['fingerprint'] && time() < (int) $dismissed[ $id ]['until'] ) {
				continue;
			}
			$visible[ $id ] = $notice;
		}
		return $visible;
	}

	public function enqueue_assets() {
		if ( ! $this->visible_notices() ) {
			return;
		}
		wp_enqueue_script( 'sdpr-admin-notices', SDPR_PLUGIN_URL . 'assets/js/admin-notices.js', array( 'jquery', 'common' ), SDPR_VERSION, true );
		wp_localize_script( 'sdpr-admin-notices', 'sdprNotices', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
	}

	public function dismiss() {
		check_ajax_referer( 'sdpr_dismiss_notice', 'nonce' );
		if ( ! $this->can_manage() ) {
			wp_send_json_error( null, 403 );
		}
		$id = isset( $_POST['notice_id'] ) ? sanitize_key( wp_unslash( $_POST['notice_id'] ) ) : '';
		if ( ! isset( $this->notices[ $id ] ) ) {
			wp_send_json_error( null, 400 );
		}
		$dismissed = get_user_option( 'sdpr_dismissed_notices' );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();
		foreach ( $dismissed as $key => $entry ) {
			if ( empty( $entry['until'] ) || time() >= (int) $entry['until'] ) {
				unset( $dismissed[ $key ] );
			}
		}
		// An unresolved dependency must not stay hidden indefinitely.
		$dismissed[ $id ] = array(
			'fingerprint' => hash( 'sha256', wp_json_encode( $this->notices[ $id ] ) ),
			'until'       => time() + DAY_IN_SECONDS,
		);
		update_user_option( get_current_user_id(), 'sdpr_dismissed_notices', $dismissed, false );
		wp_send_json_success();
	}

	public function render() {
		foreach ( $this->visible_notices() as $id => $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible sdpr-dependency-notice" data-sdpr-notice="%2$s" data-sdpr-nonce="%3$s"><p>%4$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_attr( $id ),
				esc_attr( wp_create_nonce( 'sdpr_dismiss_notice' ) ),
				esc_html( $notice['message'] )
			);
		}
	}
}

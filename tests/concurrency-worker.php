<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$role    = sanitize_key( (string) getenv( 'SDPR_STRESS_ROLE' ) );
$context = get_option( 'sdpr_stress_context', array() );
if ( ! in_array( $role, array( 'holder', 'contender' ), true ) || empty( $context['product_id'] ) || empty( $context[ $role . '_id' ] ) ) {
	exit( 1 );
}

if ( 'holder' === $role ) {
	add_action(
		'sdpr_reservation_request_locked',
		static function () {
			usleep( 10000000 );
		}
	);
}

wp_set_current_user( (int) $context[ $role . '_id' ] );
$result = SDPR_Plugin::get_instance()->get_service( 'lifecycle' )->request( (int) $context['product_id'], get_current_user_id() );
$record = array(
	'success'        => ! is_wp_error( $result ),
	'error_code'     => is_wp_error( $result ) ? $result->get_error_code() : '',
	'reservation_id' => is_wp_error( $result ) ? 0 : (int) $result['reservation_id'],
);
update_option( 'sdpr_stress_result_' . $role, $record, false );
echo wp_json_encode( $record ) . "\n";

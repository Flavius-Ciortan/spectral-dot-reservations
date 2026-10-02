<?php

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_BROWSER_CHECKOUT_TEST' ) ) {
	exit( 1 );
}

$mode    = sanitize_key( (string) getenv( 'SDPR_CHECKOUT_MODE' ) );
$context = get_option( 'sdpr_browser_checkout_context', array() );
if ( ! in_array( $mode, array( 'classic', 'blocks' ), true ) || empty( $context[ $mode . '_page' ] ) ) {
	exit( 1 );
}

update_option( 'woocommerce_checkout_page_id', (int) $context[ $mode . '_page' ] );
echo 'PASS: ' . $mode . " checkout selected.\n";

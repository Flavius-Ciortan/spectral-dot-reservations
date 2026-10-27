<?php
/** Local-only illustrative store fixtures; excluded from release archives. */
if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SDPR_DOCUMENTATION_DEMO' ) ) {
	exit( 1 );
}
if ( get_option( 'sdpr_documentation_demo' ) ) {
	WP_CLI::error( 'Documentation demo already exists; reuse it instead of overwriting fixtures.' );
}
$context = get_option( 'sdpr_browser_checkout_context', array() );
if ( empty( $context['user_id'] ) ) {
	WP_CLI::error( 'Prepare the browser checkout fixtures first.' );
}
$product = new WC_Product_Variable();
$product->set_name( 'Studio Wireless Headphones' );
$product->set_sku( 'STUDIO-WH01' );
$product->set_status( 'publish' );
$category = term_exists( 'Audio', 'product_cat' );
if ( ! $category ) {
	$category = wp_insert_term( 'Audio', 'product_cat' );
}
if ( ! is_wp_error( $category ) ) {
	$product->set_category_ids( array( (int) $category['term_id'] ) );
}
$product->set_description( '<p>Find your focus with a comfortable over-ear design, soft cushions and a refined matte finish. Choose the color that suits your space.</p><ul><li>Comfortable, adjustable fit</li><li>Wireless listening with a wired option</li><li>Two understated finishes</li></ul>' );
$product->set_short_description( '<p>A quieter moment, a clearer sound. Comfortable wireless headphones for everyday listening, available in Midnight and Pearl.</p>' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 12 );
$product->set_default_attributes( array( 'finish' => 'Midnight' ) );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Finish' );
$attribute->set_options( array( 'Midnight', 'Pearl' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$product->set_attributes( array( $attribute ) );
$product_id = $product->save();
$variation_ids = array();
foreach ( array( 'Midnight', 'Pearl' ) as $finish ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $product_id );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( 89 );
	$variation->set_virtual( true );
	$variation->set_manage_stock( false );
	$variation->set_attributes( array( 'finish' => $finish ) );
	$variation_ids[] = $variation->save();
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$source = dirname( __DIR__ ) . '/.wordpress-org/source/studio-headphones.png';
$temporary = wp_tempnam( 'studio-headphones.png' );
copy( $source, $temporary );
$image_id = media_handle_sideload( array( 'name' => 'studio-wireless-headphones.png', 'tmp_name' => $temporary ), $product_id, 'Studio Wireless Headphones in Midnight' );
if ( ! is_wp_error( $image_id ) ) {
	$product->set_image_id( $image_id );
	update_post_meta( $image_id, '_wp_attachment_image_alt', 'Midnight-blue over-ear wireless headphones on a light studio background' );
	$product->save();
}
WC_Product_Variable::sync( $product_id );
wp_update_user( array( 'ID' => $context['user_id'], 'display_name' => 'Demo Customer' ) );
update_user_meta( $context['user_id'], 'show_admin_bar_front', 'false' );
update_option( 'sdpr_documentation_demo', array( 'product' => $product_id, 'variations' => $variation_ids, 'image' => is_wp_error( $image_id ) ? 0 : $image_id ), false );
WP_CLI::success( 'Demo product: ' . get_permalink( $product_id ) );

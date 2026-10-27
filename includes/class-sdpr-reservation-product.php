<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Concrete product identity, selected attributes and the physical stock resource. */
final class SDPR_Reservation_Product {
	public static function stock_owner_id( $product ) {
		$product = $product instanceof WC_Product ? $product : wc_get_product( absint( $product ) );
		return $product ? (int) $product->get_stock_managed_by_id() : 0;
	}

	public static function stock_quantity( $product ) {
		$owner = wc_get_product( self::stock_owner_id( $product ) );
		return $owner ? (int) $owner->get_stock_quantity( 'edit' ) : 0;
	}

	public static function available_quantity( $product ) {
		return max( 0, self::stock_quantity( $product ) - (int) wc_get_held_stock_quantity( $product ) );
	}

	public static function lock_name( $product_id ) {
		$owner_id = self::stock_owner_id( $product_id );
		return 'product_' . ( $owner_id ? $owner_id : absint( $product_id ) );
	}

	/** Validate a complete selection against the parent's declared options and WooCommerce matcher. */
	public static function selection( $product, $attributes = array() ) {
		if ( ! $product instanceof WC_Product_Variation ) {
			return array();
		}
		$parent = wc_get_product( $product->get_parent_id() );
		if ( ! $parent instanceof WC_Product_Variable || ! is_array( $attributes ) ) {
			return self::invalid_selection();
		}
		$fixed     = $product->get_variation_attributes();
		$selection = array();
		foreach ( $parent->get_variation_attributes() as $name => $options ) {
			$key   = wc_variation_attribute_name( $name );
			$value = $attributes[ $key ] ?? ( $fixed[ $key ] ?? '' );
			if ( ! is_scalar( $value ) ) {
				return self::invalid_selection();
			}
			$value = taxonomy_exists( $name ) ? sanitize_title( $value ) : html_entity_decode( wc_clean( $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
			if ( '' === $value || ! in_array( $value, $options, true ) || ( ! empty( $fixed[ $key ] ) && $fixed[ $key ] !== $value ) ) {
				return self::invalid_selection();
			}
			$selection[ $key ] = $value;
		}
		if ( array_diff_key( $attributes, $selection ) ) {
			return self::invalid_selection();
		}
		$matched_id = WC_Data_Store::load( 'product' )->find_matching_product_variation( $parent, $selection );
		return (int) $matched_id === $product->get_id() ? $selection : self::invalid_selection();
	}

	private static function invalid_selection() {
		return new WP_Error( 'sdpr_invalid_variation', __( 'Please choose a valid, complete product variation before reserving.', 'spectral-dot-reservations' ) );
	}

	public static function attributes( $reservation_id ) {
		$attributes = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::VARIATION_ATTRIBUTES );
		return is_array( $attributes ) ? $attributes : array();
	}

	public static function matches_selection( $reservation_id, $attributes ) {
		$expected = self::attributes( $reservation_id );
		return ! $expected || ( is_array( $attributes ) && ! array_diff_assoc( $expected, $attributes ) );
	}

	public static function name( $reservation_id, $product ) {
		$attributes = self::attributes( $reservation_id );
		if ( $product instanceof WC_Product_Variation && $attributes ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return ( $parent ? $parent->get_name() : $product->get_name() ) . ' - ' . wc_get_formatted_variation( $attributes, true, true );
		}
		return $product->get_name();
	}

	public static function cart_url( $reservation_id, $product ) {
		$args = array(
			'add-to-cart' => $product->get_id(),
			'quantity'    => max( 1, (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ) ),
		);
		if ( $product instanceof WC_Product_Variation ) {
			$args['add-to-cart']  = $product->get_parent_id();
			$args['variation_id'] = $product->get_id();
			$attributes           = self::attributes( $reservation_id );
			$args                 = array_merge( $args, $attributes ? $attributes : $product->get_variation_attributes() );
		}
		return add_query_arg( $args, wc_get_cart_url() );
	}
}

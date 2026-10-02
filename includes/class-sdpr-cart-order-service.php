<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates reservation ownership with WooCommerce carts and orders. */
final class SDPR_Cart_Order_Service {
	private $inventory;
	private $repository;
	private $allowance_cache = array();

	public function __construct( SDPR_Inventory_Manager $inventory, SDPR_Reservation_Repository $repository ) {
		$this->inventory  = $inventory;
		$this->repository = $repository;
	}

	public function clear_cache() {
		$this->allowance_cache = array();
	}

	public function include_owned_hold_in_stock( $stock, $product ) {
		if ( ! is_user_logged_in() || ! $product instanceof WC_Product || null === $stock ) {
			return $stock;
		}
		return (int) $stock + $this->get_owned_hold_allowance( $product->get_id() );
	}

	public function include_owned_hold_in_stock_status( $status, $product ) {
		if ( 'outofstock' === $status && is_user_logged_in() && $product instanceof WC_Product && $this->get_owned_hold_allowance( $product->get_id() ) > 0 ) {
			return 'instock';
		}
		return $status;
	}

	public function include_owned_hold_in_availability( $in_stock, $product ) {
		if ( ! $in_stock && is_user_logged_in() && $product instanceof WC_Product && $this->get_owned_hold_allowance( $product->get_id() ) > 0 ) {
			return true;
		}
		return $in_stock;
	}

	public function attach_reservation_to_cart_item( $cart_item_data, $product_id, $variation_id, $quantity ) {
		unset( $quantity );
		$product_id = absint( $variation_id ? $variation_id : $product_id );
		if ( is_user_logged_in() ) {
			$reservation_id = $this->repository->find_active( $product_id, get_current_user_id() );
			if ( $reservation_id ) {
				$cart_item_data[ SDPR_Reservation_Meta::LINKED_RESERVATION ] = $reservation_id;
			}
		}
		return $cart_item_data;
	}

	public function sync_cart_reservations( $cart ) {
		if ( ! is_user_logged_in() || ! $cart instanceof WC_Cart ) {
			return;
		}
		$user_id = get_current_user_id();
		$seen    = array();
		foreach ( $cart->cart_contents as $key => $cart_item ) {
			$product_id     = absint( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 ) );
			$reservation_id = $product_id ? $this->repository->find_active( $product_id, $user_id ) : 0;
			if ( $reservation_id && ! isset( $seen[ $reservation_id ] ) ) {
				$cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ] = $reservation_id;
				$seen[ $reservation_id ] = true;
			} else {
				unset( $cart->cart_contents[ $key ][ SDPR_Reservation_Meta::LINKED_RESERVATION ] );
			}
		}
	}

	public function copy_reservation_to_order_item( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );
		if ( ! empty( $values[ SDPR_Reservation_Meta::LINKED_RESERVATION ] ) ) {
			$item->add_meta_data( SDPR_Reservation_Meta::LINKED_RESERVATION, absint( $values[ SDPR_Reservation_Meta::LINKED_RESERVATION ] ), true );
		}
	}

	public function exclude_linked_hold_from_order_quantity( $quantity, $order, $item ) {
		if ( ! $order instanceof WC_Order || ! $item instanceof WC_Order_Item_Product ) {
			return $quantity;
		}
		$reservation_id = absint( $item->get_meta( SDPR_Reservation_Meta::LINKED_RESERVATION, true ) );
		$held_quantity  = $reservation_id ? $this->get_reservation_quantity( $reservation_id ) : 0;
		return $reservation_id && $this->reservation_is_available_to_order( $reservation_id, $order, $item ) ? max( 0, (float) $quantity - $held_quantity ) : $quantity;
	}

	public function skip_redundant_order_stock_hold( $minutes, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $minutes;
		}
		$has_linked_hold = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() || $product->backorders_allowed() ) {
				continue;
			}
			$quantity  = (float) $item->get_quantity();
			$remaining = (float) $this->exclude_linked_hold_from_order_quantity( $quantity, $order, $item );
			if ( $remaining >= $quantity || $remaining > 0 ) {
				return $minutes;
			}
			$has_linked_hold = true;
		}
		return $has_linked_hold ? 0 : $minutes;
	}

	public function transfer_holds_to_order( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( SDPR_Reservation_Meta::HOLDS_TRANSFERRED, true ) ) {
			return;
		}
		$seen = array();
		foreach ( $order->get_items() as $item ) {
			$reservation_id = absint( $item->get_meta( SDPR_Reservation_Meta::LINKED_RESERVATION, true ) );
			if ( ! $reservation_id ) {
				continue;
			}
			if ( isset( $seen[ $reservation_id ] ) || ! $this->reservation_matches_order_item( $reservation_id, $order->get_customer_id(), $this->get_order_item_product_id( $item ), $item->get_quantity() ) ) {
				throw new WC_Data_Exception( 'sdpr_invalid_order_hold', esc_html__( 'A reservation could not be transferred to the order.', 'spectral-dot-reservations' ) );
			}
			$seen[ $reservation_id ] = true;
		}

		$applied = array();
		try {
			foreach ( $order->get_items() as $item ) {
				$reservation_id = absint( $item->get_meta( SDPR_Reservation_Meta::LINKED_RESERVATION, true ) );
				if ( ! $reservation_id ) {
					continue;
				}
				$quantity          = max( 1, (int) $item->get_quantity() );
				$reserved_quantity = $this->get_reservation_quantity( $reservation_id );
				$remainder         = max( 0, $quantity - $reserved_quantity );
				$item->update_meta_data( '_reduced_stock', $quantity );
				$item->save();
				$result = $this->inventory->transfer_to_order( $reservation_id, $order->get_id(), $item->get_product(), $remainder );
				if ( is_wp_error( $result ) ) {
					$item->delete_meta_data( '_reduced_stock' );
					$item->save();
					throw new WC_Data_Exception( 'sdpr_transfer_race', $result->get_error_message() );
				}
				$applied[] = array( $reservation_id, $item, $remainder );
			}
		} catch ( Throwable $error ) {
			foreach ( array_reverse( $applied ) as $entry ) {
				list( $reservation_id, $item, $remainder ) = $entry;
				$this->inventory->rollback_order_transfer( $reservation_id, $item->get_product(), $remainder );
				$item->delete_meta_data( '_reduced_stock' );
				$item->save();
			}
			throw $error;
		}
		if ( $seen ) {
			$order->update_meta_data( SDPR_Reservation_Meta::HOLDS_TRANSFERRED, 'yes' );
			$order->save();
		}
		$this->clear_cache();
	}

	public function restore_transferred_order_stock( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( SDPR_Reservation_Meta::HOLDS_TRANSFERRED, true ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$reservation_id = absint( $item->get_meta( SDPR_Reservation_Meta::LINKED_RESERVATION, true ) );
			if ( ! $reservation_id ) {
				continue;
			}
			// WooCommerce refunds may already have restored some or all of this quantity.
			$result = $this->inventory->restore_cancelled_order( $reservation_id, $item->get_product(), max( 0, (int) $item->get_meta( '_reduced_stock', true ) ) );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$item->delete_meta_data( '_reduced_stock' );
			$item->save();
		}
		$this->clear_cache();
	}

	public function hide_reservation_order_item_meta( $keys ) {
		$keys[] = SDPR_Reservation_Meta::LINKED_RESERVATION;
		return array_unique( $keys );
	}

	public function fulfill_reservation_on_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->transfer_holds_to_order( $order );
		}
	}

	private function get_owned_hold_allowance( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! array_key_exists( $product_id, $this->allowance_cache ) ) {
			$reservation_id                       = $this->repository->find_active( $product_id, get_current_user_id() );
			$this->allowance_cache[ $product_id ] = $reservation_id ? $this->get_reservation_quantity( $reservation_id ) : 0;
		}
		return $this->allowance_cache[ $product_id ];
	}

	private function reservation_is_available_to_order( $reservation_id, $order, $item ) {
		if ( 'sdpr_reservation' !== get_post_type( $reservation_id ) || (int) get_post_field( 'post_author', $reservation_id ) !== (int) $order->get_customer_id() || (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ) !== $this->get_order_item_product_id( $item ) || $this->get_reservation_quantity( $reservation_id ) > (int) $item->get_quantity() ) {
			return false;
		}
		$status = SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS );
		if ( SDPR_Reservation_Status::ACTIVE === $status ) {
			return (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT ) > time();
		}
		return SDPR_Reservation_Status::FULFILLED === $status && (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::ORDER_ID ) === (int) $order->get_id();
	}

	private function reservation_matches_order_item( $reservation_id, $user_id, $product_id, $order_quantity ) {
		return 'sdpr_reservation' === get_post_type( $reservation_id )
			&& (int) get_post_field( 'post_author', $reservation_id ) === (int) $user_id
			&& (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::PRODUCT_ID ) === (int) $product_id
			&& $this->get_reservation_quantity( $reservation_id ) <= (int) $order_quantity
			&& SDPR_Reservation_Status::ACTIVE === SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::STATUS )
			&& (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::EXPIRES_AT ) > time();
	}

	private function get_reservation_quantity( $reservation_id ) {
		return max( 1, (int) SDPR_Reservation_Meta::get( $reservation_id, SDPR_Reservation_Meta::QUANTITY ) );
	}

	private function get_order_item_product_id( $item ) {
		return absint( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
	}
}

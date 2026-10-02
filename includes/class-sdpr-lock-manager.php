<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates short reservation critical sections across web requests. */
final class SDPR_Lock_Manager {
	const STALE_AFTER_SECONDS = 30;

	public function acquire( $names ) {
		global $wpdb;

		$locks = array();
		sort( $names, SORT_STRING );
		foreach ( array_unique( $names ) as $name ) {
			$key   = 'sdpr_lock_' . sanitize_key( $name );
			$token = wp_generate_uuid4() . '|' . time();
			if ( ! $this->insert( $key, $token ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock ownership must bypass the option cache.
				$stored = (string) $wpdb->get_var(
					$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key )
				);
				$parts  = explode( '|', $stored );
				if ( isset( $parts[1] ) && time() - (int) $parts[1] > self::STALE_AFTER_SECONDS ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The observed stale token prevents deleting a replacement lock.
					$wpdb->query(
						$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $key, $stored )
					);
				}
				if ( ! $this->insert( $key, $token ) ) {
					$this->release( $locks );
					return new WP_Error( 'sdpr_busy', __( 'Another reservation is being processed. Please try again.', 'spectral-dot-reservations' ) );
				}
			}
			$locks[ $key ] = $token;
		}
		return $locks;
	}

	public function release( $locks ) {
		global $wpdb;

		foreach ( (array) $locks as $key => $token ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Only the owning token may release the lock.
			$wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $key, $token )
			);
			wp_cache_delete( $key, 'options' );
		}
	}

	/** Atomically create a non-autoloaded lock row. */
	private function insert( $key, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The unique option-name index provides cross-request exclusion.
		$inserted = $wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $key, $token, 'no' )
		);
		if ( 1 === $inserted ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		return false;
	}
}

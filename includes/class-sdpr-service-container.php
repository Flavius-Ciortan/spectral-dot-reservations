<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Small explicit service registry shared with compatible add-ons. */
final class SDPR_Service_Container {
	private $services = array();

	public function set( $id, $service ) {
		$this->services[ sanitize_key( $id ) ] = $service;
		return $service;
	}

	public function has( $id ) {
		return array_key_exists( sanitize_key( $id ), $this->services );
	}

	public function get( $id ) {
		$id = sanitize_key( $id );
		return isset( $this->services[ $id ] ) ? $this->services[ $id ] : null;
	}
}

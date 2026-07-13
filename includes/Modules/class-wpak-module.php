<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WPAK_Module {

	protected string $slug;

	public function __construct( string $slug ) {
		$this->slug = $slug;
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function boot(): void {
		if ( $this->is_active() ) {
			$this->register_hooks();
		}
	}

	public function is_active(): bool {
		static $active_modules = null;

		if ( null === $active_modules ) {
			$active_modules = get_option( 'wpaikits99_active_modules', array( 'archivist' ) );
			if ( ! is_array( $active_modules ) ) {
				$active_modules = array();
			}
		}

		return in_array( $this->slug, $active_modules, true );
	}

	abstract public function register_hooks(): void;
}

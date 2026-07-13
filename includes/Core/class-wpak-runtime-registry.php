<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Runtime_Registry {

	private static array $services = array();
	private static array $modules = array();

	public static function register_service( object $service ): void {
		if ( ! method_exists( $service, 'register_hooks' ) ) {
			return;
		}

		self::$services[ get_class( $service ) ] = $service;
	}

	public static function register_module( WPAK_Module $module ): void {
		self::$modules[ $module->get_slug() ] = $module;
	}

	public static function boot_services(): void {
		foreach ( self::$services as $service ) {
			$service->register_hooks();
		}
	}

	public static function boot_modules(): void {
		foreach ( self::$modules as $module ) {
			$module->boot();
		}
	}
}

function wpaikits99_register_service( object $service ): void {
	WPAK_Runtime_Registry::register_service( $service );
}

function wpaikits99_register_module( WPAK_Module $module ): void {
	WPAK_Runtime_Registry::register_module( $module );
}

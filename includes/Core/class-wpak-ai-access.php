<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_AI_Access {

	public const OPTION = 'wpaikits99_access_capability';

	public static function choices(): array {
		return array(
			'manage_options' => 'Administrators only',
			'edit_pages'     => 'Editors and above',
			'publish_posts'  => 'Authors and above',
		);
	}

	public static function sanitize( $capability ): string {
		$capability = sanitize_key( (string) $capability );
		if ( 'edit_posts' === $capability ) {
			$capability = 'publish_posts'; // Legacy value also matched Contributors.
		}
		return array_key_exists( $capability, self::choices() ) ? $capability : 'manage_options';
	}

	public static function selected(): string {
		return self::sanitize( get_option( self::OPTION, 'manage_options' ) );
	}

	public static function can_use_ai(): bool {
		return current_user_can( self::selected() );
	}
}

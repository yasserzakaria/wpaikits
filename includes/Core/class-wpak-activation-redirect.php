<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Activation_Redirect {

	private const OPTION = 'wpaikits99_activation_redirect';

	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	public static function flag(): void {
		update_option( self::OPTION, '1', false );
	}

	public function maybe_redirect(): void {
		if ( '1' !== get_option( self::OPTION, '0' ) ) {
			return;
		}

		delete_option( self::OPTION );

		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() || is_network_admin() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpaikits' ) );
		exit;
	}
}

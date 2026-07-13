<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Media_Key_Settings {

	private const FIELDS = array(
		'unsplash' => 'wpaikits99_unsplash_key',
		'pexels'   => 'wpaikits99_pexels_key',
	);

	public static function ajax_save(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$payload = json_decode( stripslashes( (string) ( $_POST['media_keys'] ?? '{}' ) ), true );
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( 'Invalid media key payload.', 400 );
		}

		foreach ( self::FIELDS as $field => $option ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( wp_unslash( $payload[ $field ] ) ) );
			if ( '' !== $value ) {
				update_option( $option, $value, false );
			}
		}

		wp_send_json_success(
			array(
				'message'   => 'Media API keys saved.',
				'mediaKeys' => self::payload(),
			)
		);
	}

	public static function payload(): array {
		return array(
			'unsplashConfigured' => '' !== (string) get_option( 'wpaikits99_unsplash_key', '' ),
			'pexelsConfigured'   => '' !== (string) get_option( 'wpaikits99_pexels_key', '' ),
		);
	}
}

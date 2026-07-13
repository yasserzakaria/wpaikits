<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Onboarding {

	public const OPTION = 'wpaikits99_onboarding_complete';

	public function register_hooks(): void {
		add_action( 'wp_ajax_wpaikits99_onboarding_free_setup', array( $this, 'ajax_free_setup' ) );
		add_action( 'wp_ajax_wpaikits99_onboarding_paid_setup', array( $this, 'ajax_paid_setup' ) );
		add_action( 'wp_ajax_wpaikits99_onboarding_skip', array( $this, 'ajax_skip' ) );
	}

	public static function should_show(): bool {
		return '1' !== get_option( self::OPTION, '0' ) && ! WPAK_AI_Profiles::has_saved_profiles();
	}

	public static function payload(): array {
		return array(
			'show' => self::should_show(),
		);
	}

	public function ajax_free_setup(): void {
		$this->authorize();

		$groq_key   = sanitize_text_field( wp_unslash( $_POST['groq_key'] ?? '' ) );
		$gemini_key = sanitize_text_field( wp_unslash( $_POST['gemini_key'] ?? '' ) );

		if ( '' === $groq_key ) {
			wp_send_json_error( 'Paste your Groq API key to continue.', 400 );
		}

		$groq_valid = WPAK_LLM_Proxy::validate_groq_key( $groq_key );
		if ( is_wp_error( $groq_valid ) ) {
			wp_send_json_error( 'Groq key: ' . $groq_valid->get_error_message(), 400 );
		}

		// Gemini is optional. Only validate it when the user actually pasted one.
		if ( '' !== $gemini_key ) {
			$gemini_valid = WPAK_LLM_Proxy::validate_gemini_key( $gemini_key );
			if ( is_wp_error( $gemini_valid ) ) {
				wp_send_json_error( 'Gemini key: ' . $gemini_valid->get_error_message(), 400 );
			}
		}

		$profiles = WPAK_AI_Profiles::free_groq_cascade( $groq_key, $gemini_key );
		update_option( WPAK_AI_Profiles::OPTION, $profiles, false );
		update_option( WPAK_AI_Profiles::MEDIA_OPTION, $profiles[0]['id'], false );
		update_option( WPAK_AI_Profiles::ARCHITECT_OPTION, $profiles[0]['id'], false );

		update_option( self::OPTION, '1', false );
		wp_send_json_success(
			array(
				'message'          => 'Smart Route configured.',
				'aiProfiles'       => WPAK_AI_Profiles::public_profiles(),
				'architectProfile' => WPAK_AI_Profiles::architect_profile_id(),
			)
		);
	}

	public function ajax_paid_setup(): void {
		$this->authorize();

		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$model    = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );

		// Media metadata needs a vision-capable model; these four all qualify.
		if ( ! in_array( $provider, array( 'gemini', 'groq', 'openai', 'openrouter' ), true ) ) {
			wp_send_json_error( 'Pick a supported vision provider.', 400 );
		}
		if ( '' === $api_key ) {
			wp_send_json_error( 'Paste your API key to continue.', 400 );
		}

		// Validate the providers we have a cheap key check for. OpenAI and
		// OpenRouter keys are accepted as-is and verified on first use.
		if ( 'groq' === $provider ) {
			$valid = WPAK_LLM_Proxy::validate_groq_key( $api_key );
		} elseif ( 'gemini' === $provider ) {
			$valid = WPAK_LLM_Proxy::validate_gemini_key( $api_key );
		} else {
			$valid = true;
		}
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( ucfirst( $provider ) . ' key: ' . $valid->get_error_message(), 400 );
		}

		$profiles = WPAK_AI_Profiles::single_route_profile( $provider, $api_key, $model );
		update_option( WPAK_AI_Profiles::OPTION, $profiles, false );
		update_option( WPAK_AI_Profiles::MEDIA_OPTION, $profiles[0]['id'], false );
		update_option( WPAK_AI_Profiles::ARCHITECT_OPTION, $profiles[0]['id'], false );

		// A paid key is not daily-capped, so process at full speed by default.
		update_option( 'wpaikits99_archivist_full_speed', '1', false );

		update_option( self::OPTION, '1', false );
		wp_send_json_success(
			array(
				'message'          => 'Your AI route is ready.',
				'aiProfiles'       => WPAK_AI_Profiles::public_profiles(),
				'architectProfile' => WPAK_AI_Profiles::architect_profile_id(),
			)
		);
	}

	public function ajax_skip(): void {
		$this->authorize();
		update_option( self::OPTION, '1', false );
		wp_send_json_success( array( 'message' => 'Advanced setup unlocked.' ) );
	}

	private function authorize(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
	}
}

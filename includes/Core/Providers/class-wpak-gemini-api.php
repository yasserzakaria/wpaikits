<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Gemini_API {

	public const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
	public const MODEL_ALT_TEXT = 'gemini-2.5-flash';
	public const MODEL_EMBEDDING = 'gemini-embedding-2-preview';
	public const VECTOR_DIMENSIONS = 768;

	public static function get_api_key(): string {
		return (string) get_option( 'wpaikits99_gemini_api_key', '' );
	}

	public static function is_available( string $api_key = '', bool $use_global_cooldown = true ) {
		$api_key = '' !== $api_key ? $api_key : self::get_api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'no_api_key', 'Gemini API key is not configured.' );
		}

		if ( $use_global_cooldown && get_transient( 'wpaikits99_global_cooldown' ) ) {
			return new WP_Error( 'rate_limited', 'Gemini is in global cooldown.' );
		}

		return true;
	}

	public static function post( string $endpoint, array $body, int $timeout = 60, string $api_key = '', bool $use_global_cooldown = true ) {
		$api_key = '' !== $api_key ? $api_key : self::get_api_key();
		$available = self::is_available( $api_key, $use_global_cooldown );
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		WPAK_LLM_Debug::request( 'gemini-media', $endpoint, $body );
		$response = wp_remote_post(
			self::API_BASE . $endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WPAK_LLM_Debug::http_error( 'gemini-media', $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		WPAK_LLM_Debug::response( 'gemini-media', $endpoint, $code, $raw_body );
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			if ( $use_global_cooldown ) {
				set_transient( 'wpaikits99_global_cooldown', true, 60 );
			}
			return new WP_Error( 'rate_limited', 'Gemini rate limit hit. Cooldown active for 60 seconds.', array( 'status' => $code ) );
		}

		if ( 200 !== $code ) {
			$decoded = json_decode( $raw_body, true );
			$message = $decoded['error']['message'] ?? 'Gemini returned HTTP ' . $code;
			return new WP_Error( 'gemini_api_error', $message, array( 'status' => $code ) );
		}

		$decoded = json_decode( $raw_body, true );
		if ( null === $decoded ) {
			return new WP_Error( 'gemini_json_error', 'Gemini returned invalid JSON.' );
		}

		return $decoded;
	}

	public static function validate_key( string $api_key ) {
		$response = wp_remote_post(
			self::API_BASE . self::MODEL_EMBEDDING . ':embedContent',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'model'                => 'models/' . self::MODEL_EMBEDDING,
						'content'              => array( 'parts' => array( array( 'text' => 'test' ) ) ),
						'output_dimensionality' => 32,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			set_transient( 'wpaikits99_global_cooldown', true, 60 );
			return new WP_Error( 'rate_limited', 'Gemini is rate limited right now. The key was not rejected - try again in a minute.' );
		}

		return 200 === $code ? true : new WP_Error( 'invalid_key', 'Gemini rejected this key.' );
	}
}

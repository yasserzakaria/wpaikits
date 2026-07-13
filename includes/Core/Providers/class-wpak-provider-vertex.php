<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-vertex-schema.php';

class WPAK_Provider_Vertex {

	const DEFAULT_LOCATION = 'us-central1';
	const TOKEN_TRANSIENT_PREFIX = 'wpaikits99_vertex_token_';

	public static function route_secret( array $route ): string {
		return wp_json_encode(
			array(
				'credentials' => $route['apiKey'] ?? '',
				'location'    => $route['location'] ?? self::DEFAULT_LOCATION,
			)
		);
	}

	public static function normalize_credentials( string $raw ) {
		$data = json_decode( trim( $raw ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_vertex_json', 'Vertex service account JSON is invalid.', array( 'status' => 400 ) );
		}

		$required = array( 'project_id', 'private_key', 'client_email' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error( 'invalid_vertex_json', "Vertex JSON is missing {$field}.", array( 'status' => 400 ) );
			}
		}

		return wp_json_encode(
			array(
				'type'           => sanitize_text_field( $data['type'] ?? 'service_account' ),
				'project_id'     => sanitize_text_field( $data['project_id'] ),
				'private_key_id' => sanitize_text_field( $data['private_key_id'] ?? '' ),
				'private_key'    => (string) $data['private_key'],
				'client_email'   => sanitize_email( $data['client_email'] ),
				'token_uri'      => esc_url_raw( $data['token_uri'] ?? 'https://oauth2.googleapis.com/token' ),
			)
		);
	}

	public static function generate( $model_name, $messages, $tools = array(), $tool_choice = 'AUTO', $api_key = '' ) {
		$config = self::parse_secret( $api_key );
		if ( is_wp_error( $config ) ) return $config;

		$token = self::access_token( $config['credentials'] );
		if ( is_wp_error( $token ) ) return $token;

		$body = self::gemini_body( $messages, WPAK_Vertex_Schema::tools( $tools ), $tool_choice );
		$url = self::endpoint( $config['credentials']['project_id'], $config['location'], $model_name );

		WPAK_LLM_Debug::request( 'vertex', $model_name, $body );
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 75,
			)
		);

		if ( is_wp_error( $response ) ) {
			WPAK_LLM_Debug::http_error( 'vertex', $response->get_error_message() );
			return new WP_Error( 'http_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$resp_body = json_decode( $raw_body, true );
		WPAK_LLM_Debug::response( 'vertex', $model_name, $code, $raw_body );

		if ( 200 !== $code ) {
			$error_msg = $resp_body['error']['message'] ?? 'Unknown Vertex AI error';
			return new WP_Error( 'vertex_api_error', 'Vertex AI Error: ' . $error_msg, array( 'status' => $code ) );
		}

		return self::format_response( is_array( $resp_body ) ? $resp_body : array() );
	}

	private static function parse_secret( string $secret ) {
		$config = json_decode( $secret, true );
		$raw_credentials = is_array( $config ) ? ( $config['credentials'] ?? '' ) : $secret;
		$credentials = json_decode( (string) $raw_credentials, true );
		if ( ! is_array( $credentials ) ) {
			return new WP_Error( 'missing_vertex_credentials', 'Vertex service account JSON is missing.', array( 'status' => 400 ) );
		}
		return array(
			'credentials' => $credentials,
			'location'    => sanitize_text_field( $config['location'] ?? self::DEFAULT_LOCATION ),
		);
	}

	private static function gemini_body( array $messages, array $tools, string $tool_choice ): array {
		$contents = array();
		$system_instruction = null;

		foreach ( $messages as $msg ) {
			if ( 'system' === ( $msg['role'] ?? '' ) ) {
				$system_instruction['parts'][] = array( 'text' => ( $msg['content'] ?? '' ) . "\n\n" );
				continue;
			}
			$contents[] = array(
				'role'  => 'assistant' === ( $msg['role'] ?? '' ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $msg['content'] ?? '' ) ),
			);
		}

		$body = array( 'contents' => $contents, 'generationConfig' => array( 'temperature' => 0.4 ) );
		if ( $system_instruction ) $body['systemInstruction'] = $system_instruction;
		if ( ! empty( $tools ) ) {
			$body['tools'] = array( array( 'functionDeclarations' => $tools ) );
			$body['toolConfig'] = array( 'functionCallingConfig' => self::tool_config( $tool_choice ) );
		}
		return $body;
	}

	private static function tool_config( string $tool_choice ): array {
		return 'AUTO' === $tool_choice
			? array( 'mode' => 'AUTO' )
			: array( 'mode' => 'ANY', 'allowedFunctionNames' => array( $tool_choice ) );
	}

	private static function endpoint( string $project, string $location, string $model ): string {
		$host = 'global' === strtolower( $location )
			? 'aiplatform.googleapis.com'
			: rawurlencode( $location ) . '-aiplatform.googleapis.com';
		$api_version = 'v1beta1';

		return sprintf(
			'https://%s/%s/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
			$host,
			$api_version,
			rawurlencode( $project ),
			rawurlencode( $location ),
			rawurlencode( $model )
		);
	}

	private static function access_token( array $credentials ) {
		$key = self::TOKEN_TRANSIENT_PREFIX . md5( ( $credentials['client_email'] ?? '' ) . ( $credentials['private_key_id'] ?? '' ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) return $cached;

		$jwt = self::jwt( $credentials );
		if ( is_wp_error( $jwt ) ) return $jwt;

		$response = wp_remote_post(
			$credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) return $response;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'vertex_auth_failed', $body['error_description'] ?? 'Vertex authentication failed.', array( 'status' => 401 ) );
		}

		set_transient( $key, $body['access_token'], max( 60, absint( $body['expires_in'] ?? 3600 ) - 120 ) );
		return $body['access_token'];
	}

	private static function jwt( array $credentials ) {
		$now = time();
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
		$claim = array(
			'iss'   => $credentials['client_email'],
			'scope' => 'https://www.googleapis.com/auth/cloud-platform',
			'aud'   => $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
		$signing_input = self::b64( wp_json_encode( $header ) ) . '.' . self::b64( wp_json_encode( $claim ) );
		if ( ! openssl_sign( $signing_input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'vertex_sign_failed', 'Could not sign Vertex service account JWT.', array( 'status' => 500 ) );
		}
		return $signing_input . '.' . self::b64( $signature );
	}

	private static function b64( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function format_response( array $resp_body ): array {
		$formatted = array( 'role' => 'assistant', 'content' => '', 'tool_calls' => array() );
		$candidate = $resp_body['candidates'][0] ?? array();
		$finish_reason = (string) ( $candidate['finishReason'] ?? '' );
		$finish_message = (string) ( $candidate['finishMessage'] ?? '' );

		if ( in_array( $finish_reason, array( 'MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL' ), true ) ) {
			$formatted['content'] = $finish_message ?: $finish_reason;
			$formatted['_wpaikits99_meta'] = self::response_meta( $resp_body, $finish_reason );
			return $formatted;
		}

		foreach ( $candidate['content']['parts'] ?? array() as $part ) {
			if ( ! empty( $part['thought'] ) ) continue;
			if ( isset( $part['text'] ) ) $formatted['content'] .= $part['text'];
			if ( isset( $part['functionCall'] ) ) {
				$formatted['tool_calls'][] = array(
					'name' => $part['functionCall']['name'],
					'args' => wp_json_encode( $part['functionCall']['args'] ?? array() ),
				);
			}
		}
		$formatted['_wpaikits99_meta'] = self::response_meta( $resp_body, $finish_reason );
		return $formatted;
	}

	private static function response_meta( array $resp_body, string $finish_reason = '' ): array {
		return array(
			'usage'        => $resp_body['usageMetadata'] ?? array(),
			'modelVersion' => $resp_body['modelVersion'] ?? '',
			'responseId'   => $resp_body['responseId'] ?? '',
			'finishReason' => $finish_reason,
		);
	}
}

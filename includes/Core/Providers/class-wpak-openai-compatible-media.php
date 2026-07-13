<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vision metadata via OpenAI-compatible chat endpoints (Groq, OpenAI,
 * OpenRouter). Sends the image inline as a base64 data URL in JSON mode and
 * parses the alt/title/description payload. On malformed output it retries
 * once on the provider's reliable default model before the router moves on.
 */
class WPAK_OpenAI_Compatible_Media {

	private const CONFIG = array(
		'groq'       => array(
			'base'          => 'https://api.groq.com/openai/v1/chat/completions',
			'label'         => 'Groq',
			'default_model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
			'vision_models' => array(
				'meta-llama/llama-4-scout-17b-16e-instruct',
				'meta-llama/llama-4-maverick-17b-128e-instruct',
				'qwen/qwen3.6-27b',
			),
		),
		'openai'     => array(
			'base'          => 'https://api.openai.com/v1/chat/completions',
			'label'         => 'OpenAI',
			'default_model' => 'gpt-4o-mini',
			'vision_models' => array(),
		),
		'openrouter' => array(
			'base'          => 'https://openrouter.ai/api/v1/chat/completions',
			'label'         => 'OpenRouter',
			'default_model' => '',
			'vision_models' => array(),
		),
	);

	public static function supports( string $provider ): bool {
		return isset( self::CONFIG[ $provider ] );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function generate_media_metadata( string $file_path, array $route = array() ) {
		$provider = (string) ( $route['provider'] ?? '' );
		if ( ! self::supports( $provider ) ) {
			return new WP_Error( 'unsupported_provider', 'Unsupported vision provider.', array( 'status' => 400 ) );
		}

		$config  = self::CONFIG[ $provider ];
		$api_key = (string) ( $route['apiKey'] ?? '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_profile_key', 'This ' . $config['label'] . ' route does not have an API key saved.', array( 'status' => 400 ) );
		}

		$image = WPAK_Gemini_Media::image_inline_data( $file_path );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$model  = self::vision_model( $config, $route );
		$result = self::request( $provider, $config, $model, $api_key, $image );

		// Fall back once to the provider's reliable default before handing the
		// image off to the next route in the profile.
		if ( is_wp_error( $result ) && '' !== $config['default_model'] && $model !== $config['default_model'] && self::is_bad_output_error( $result ) ) {
			$result = self::request( $provider, $config, $config['default_model'], $api_key, $image );
		}

		return $result;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function request( string $provider, array $config, string $model, string $api_key, array $image ) {
		$body = array(
			'model'                 => $model,
			'temperature'           => 0.4,
			'max_completion_tokens' => 512,
			'response_format'       => array( 'type' => 'json_object' ),
			'messages'              => array(
				array(
					'role'    => 'user',
					'content' => array(
						array( 'type' => 'text', 'text' => WPAK_Gemini_Media::metadata_prompt() ),
						array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => 'data:' . $image['mime_type'] . ';base64,' . $image['data'] ),
						),
					),
				),
			),
		);

		$slug    = $provider . '-media';
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);
		if ( 'openrouter' === $provider ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = 'WP AI Kits';
		}

		WPAK_LLM_Debug::request( $slug, $model, $body );
		$response = wp_remote_post(
			$config['base'],
			array(
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WPAK_LLM_Debug::http_error( $slug, $response->get_error_message() );
			return new WP_Error( 'http_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		WPAK_LLM_Debug::response( $slug, $model, $code, $raw_body );

		if ( in_array( $code, array( 429, 503 ), true ) ) {
			// Free windows reset within ~60s; pause the queue like Gemini does.
			set_transient( 'wpaikits99_global_cooldown', true, 60 );
			return new WP_Error( 'rate_limited', $config['label'] . ' rate limit hit. Cooldown active for 60 seconds.', array( 'status' => $code ) );
		}

		$decoded = json_decode( $raw_body, true );
		if ( 200 !== $code ) {
			$message = $decoded['error']['message'] ?? ( $config['label'] . ' returned HTTP ' . $code );
			return new WP_Error( 'api_error', $config['label'] . ' Error: ' . $message, array( 'status' => $code ) );
		}

		$content = (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
		$data    = WPAK_Gemini_Response::parse_json( $content );
		// Require a non-empty alt_text: a blank/partial payload that only passes
		// isset() would otherwise be "saved" as nothing and marked done for good.
		if ( is_array( $data ) && isset( $data['alt_text'], $data['title'], $data['description'] )
			&& '' !== trim( (string) $data['alt_text'] ) ) {
			return $data;
		}

		return new WP_Error( 'metadata_parse_failed', $config['label'] . ' did not return valid media metadata.', array( 'status' => 500 ) );
	}

	private static function is_bad_output_error( WP_Error $error ): bool {
		$data    = $error->get_error_data();
		$status  = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
		$message = strtolower( $error->get_error_message() );

		return in_array( $error->get_error_code(), array( 'metadata_parse_failed', 'api_error' ), true )
			&& ( 400 === $status || 500 === $status
				|| false !== strpos( $message, 'json' )
				|| false !== strpos( $message, 'failed_generation' )
				|| false !== strpos( $message, 'tool' ) );
	}

	private static function vision_model( array $config, array $route ): string {
		$model = (string) ( $route['model'] ?? '' );
		if ( empty( $config['vision_models'] ) ) {
			return '' !== $model ? $model : $config['default_model'];
		}
		return in_array( $model, $config['vision_models'], true ) ? $model : $config['default_model'];
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-gemini-api.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-gemini-response.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-gemini-media.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-llm-debug.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-openai-compatible-media.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-gemini.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-openai-compatible.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-groq.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-openai.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-openrouter.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-mistral.php';
require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-vertex.php';

class WPAK_LLM_Proxy {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'wpak/v1',
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_generate_request' ),
				'permission_callback' => static function () {
					return WPAK_AI_Access::can_use_ai();
				},
			)
		);
	}

	public function handle_generate_request( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$messages = is_array( $params['messages'] ?? null ) ? $params['messages'] : array();
		$tools = is_array( $params['tools'] ?? null ) ? $params['tools'] : array();
		$tool_choice = sanitize_text_field( $params['tool_choice'] ?? 'AUTO' );
		$profile_id = sanitize_key( $params['profile_id'] ?? '' );
		$route_id = sanitize_key( $params['route_id'] ?? '' );
		$legacy_provider = sanitize_text_field( $params['provider'] ?? 'gemini' );
		$legacy_model = sanitize_text_field( $params['model'] ?? 'gemini-2.5-flash' );

		$profile = $profile_id ? WPAK_AI_Profiles::get( $profile_id ) : null;
		if ( $profile_id && ! $profile ) {
			return new WP_Error( 'invalid_profile', 'Unknown AI profile.', array( 'status' => 400 ) );
		}

		$route = $route_id ? WPAK_AI_Profiles::find_route( $route_id, $profile_id ) : null;
		$route = ! $route && $profile ? WPAK_AI_Profiles::first_active_route( $profile ) : $route;
		if ( $route && $profile && empty( $route['profileId'] ) ) {
			$route['profileId'] = $profile['id'];
		}
		if ( $route_id && ! $route ) {
			return new WP_Error( 'invalid_route', 'Unknown AI route.', array( 'status' => 400 ) );
		}

		$response = $route
			? self::generate_with_route( $route, $messages, $tools, $tool_choice )
			: self::generate(
				$legacy_provider,
				$legacy_model,
				$messages,
				$tools,
				$tool_choice
			);

		self::log_generation( $route ?: array( 'id' => 'legacy', 'provider' => $legacy_provider, 'model' => $legacy_model ), $messages, $tools, $tool_choice, $response );

		return is_wp_error( $response ) ? $response : rest_ensure_response( $response );
	}

	public static function generate(
		string $provider,
		string $model,
		array $messages,
		array $tools = array(),
		string $tool_choice = 'AUTO',
		string $api_key = ''
	) {
		switch ( strtolower( $provider ) ) {
			case 'gemini':
				return WPAK_Provider_Gemini::generate( $model, $messages, $tools, $tool_choice, $api_key );
			case 'groq':
				return WPAK_Provider_Groq::generate( $model, $messages, $tools, $tool_choice, $api_key );
			case 'openai':
				return WPAK_Provider_OpenAI::generate( $model, $messages, $tools, $tool_choice, $api_key );
			case 'openrouter':
				return WPAK_Provider_OpenRouter::generate( $model, $messages, $tools, $tool_choice, $api_key );
			case 'mistral':
				return WPAK_Provider_Mistral::generate( $model, $messages, $tools, $tool_choice, $api_key );
			case 'vertex':
				return WPAK_Provider_Vertex::generate( $model, $messages, $tools, $tool_choice, $api_key );
			default:
				return new WP_Error( 'invalid_provider', 'Unknown LLM provider.', array( 'status' => 400 ) );
		}
	}

	public static function generate_media_metadata( string $file_path ) {
		return self::run_media_routes(
			WPAK_AI_Profiles::media_routes(),
			static function ( array $route ) use ( $file_path ) {
				$provider = (string) ( $route['provider'] ?? '' );
				return WPAK_OpenAI_Compatible_Media::supports( $provider )
					? WPAK_OpenAI_Compatible_Media::generate_media_metadata( $file_path, $route )
					: WPAK_Gemini_Media::generate_media_metadata( $file_path, $route );
			},
			'Select a Media AI profile with a vision route (Gemini, Groq, OpenAI, or OpenRouter).'
		);
	}

	public static function embed_image( string $file_path ) {
		return self::run_media_routes(
			WPAK_AI_Profiles::embedding_routes(),
			static fn( array $route ) => WPAK_Gemini_Media::embed_image( $file_path, $route ),
			'Embeddings require a Media AI profile with a Gemini route.'
		);
	}

	public static function embed_text( string $text ) {
		return self::run_media_routes(
			WPAK_AI_Profiles::embedding_routes(),
			static fn( array $route ) => WPAK_Gemini_Media::embed_text( $text, $route ),
			'Embeddings require a Media AI profile with a Gemini route.'
		);
	}

	public static function validate_gemini_key( string $api_key ) {
		return WPAK_Gemini_API::validate_key( $api_key );
	}

	/**
	 * Cheap Groq key check: list models (no token usage). 200 = valid.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_groq_key( string $api_key ) {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', 'No Groq API key provided.', array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			'https://api.groq.com/openai/v1/models',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = $body['error']['message'] ?? ( 'Groq rejected the key (HTTP ' . $code . ').' );
		return new WP_Error( 'invalid_key', $message, array( 'status' => $code > 0 ? $code : 400 ) );
	}

	public static function rank_media_candidates( string $query, array $candidates ) {
		return self::run_media_routes(
			WPAK_AI_Profiles::embedding_routes(),
			static fn( array $route ) => WPAK_Gemini_Media::rank_media_candidates( $query, $candidates, $route ),
			'Visual ranking requires a Media AI profile with a Gemini route.'
		);
	}

	/**
	 * Real vision test: send a small generated image and confirm the model can
	 * actually see it and return alt/title/description — not just answer text.
	 *
	 * @return true|WP_Error
	 */
	public static function test_vision_route( array $route ) {
		$provider = (string) ( $route['provider'] ?? '' );
		$sample   = self::sample_vision_image();
		if ( is_wp_error( $sample ) ) {
			return $sample;
		}

		$result = WPAK_OpenAI_Compatible_Media::supports( $provider )
			? WPAK_OpenAI_Compatible_Media::generate_media_metadata( $sample, $route )
			: WPAK_Gemini_Media::generate_media_metadata( $sample, $route );

		if ( file_exists( $sample ) ) {
			wp_delete_file( $sample );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) && '' !== trim( (string) ( $result['alt_text'] ?? '' ) ) ) {
			return true;
		}
		return new WP_Error( 'vision_test_failed', 'The model replied but returned no image metadata. Choose a model that supports image input.', array( 'status' => 422 ) );
	}

	private static function sample_vision_image() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return new WP_Error( 'no_gd', 'This server has no PHP image support (GD), so the vision test cannot run. The key itself may still work.', array( 'status' => 500 ) );
		}

		$size  = 128;
		$image = imagecreatetruecolor( $size, $size );
		$bg    = imagecolorallocate( $image, 245, 244, 240 );
		$blue  = imagecolorallocate( $image, 43, 91, 199 );
		imagefilledrectangle( $image, 0, 0, $size, $size, $bg );
		imagefilledellipse( $image, (int) ( $size / 2 ), (int) ( $size / 2 ), 82, 82, $blue );

		$path = wp_tempnam( 'wpak-vision-test.png' );
		imagepng( $image, $path );
		imagedestroy( $image );
		return $path;
	}

	private static function generate_with_route( array $route, array $messages, array $tools, string $tool_choice ) {
		if ( false === ( $route['active'] ?? true ) || '0' === (string) ( $route['active'] ?? '1' ) ) {
			return new WP_Error( 'inactive_route', 'This AI route is inactive.', array( 'status' => 400 ) );
		}

		if ( empty( $route['apiKey'] ) ) {
			return new WP_Error( 'no_profile_key', 'This AI route does not have an API key saved.', array( 'status' => 400 ) );
		}

		$secret = 'vertex' === ( $route['provider'] ?? '' )
			? WPAK_Provider_Vertex::route_secret( $route )
			: $route['apiKey'];

		return self::generate( $route['provider'], $route['model'], $messages, $tools, $tool_choice, $secret );
	}

	private static function run_media_routes( array $routes, callable $callback, string $missing_message ) {
		if ( empty( $routes ) ) {
			return new WP_Error( 'missing_media_profile', $missing_message, array( 'status' => 400 ) );
		}

		// Try every configured route. If one provider is down, rate-limited, or
		// returns malformed output, the next provider gets a chance to succeed.
		$last_error = null;
		foreach ( $routes as $route ) {
			$result = $callback( $route );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;
		}

		return $last_error ?: new WP_Error( 'media_profile_failed', 'All Media AI routes failed.', array( 'status' => 500 ) );
	}

	private static function log_generation( ?array $profile, array $messages, array $tools, string $tool_choice, $response ): void {
		$error_data = is_wp_error( $response ) ? $response->get_error_data() : array();
		$status = is_wp_error( $response ) ? 500 : 200;
		if ( is_wp_error( $response ) && is_array( $error_data ) ) {
			$status = absint( $error_data['status'] ?? 500 );
		}
		WPAK_AI_Logger::queue(
			array(
				'profile_id'       => $profile['profileId'] ?? ( $profile['id'] ?? '' ),
				'provider'         => $profile['provider'] ?? '',
				'model'            => $profile['model'] ?? '',
				'prompt_payload'   => array( 'messages' => $messages, 'tools' => $tools, 'tool_choice' => $tool_choice ),
				'response_payload' => is_wp_error( $response ) ? array( 'error' => $response->get_error_message(), 'code' => $response->get_error_code() ) : $response,
				'status_code'      => $status,
			)
		);
	}
}

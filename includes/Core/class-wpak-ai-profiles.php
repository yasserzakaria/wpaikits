<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WPAK_PATH . 'includes/Core/Providers/class-wpak-provider-vertex.php';

class WPAK_AI_Profiles {

	public const OPTION = 'wpaikits99_ai_profiles';
	public const MEDIA_OPTION = 'wpaikits99_media_ai_profile';
	public const ARCHITECT_OPTION = 'wpaikits99_architect_ai_profile';

	private const DEFAULT_MODELS = array(
		'gemini'     => 'gemini-2.5-flash-lite',
		'groq'       => 'openai/gpt-oss-120b',
		'mistral'    => 'mistral-small-latest',
		'openai'     => 'gpt-4o-mini',
		'openrouter' => 'openai/gpt-4o-mini',
		'vertex'     => 'gemini-2.5-flash',
	);

	private const VISION_PROVIDERS = array( 'gemini', 'groq', 'openai', 'openrouter' );

	public static function all(): array {
		$saved = get_option( self::OPTION, null );
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			if ( self::is_flat_collection( $saved ) ) {
				$migrated = self::wrap_routes( $saved, 'Default Profile' );
				update_option( self::OPTION, $migrated, false );
				return self::normalize_profiles( $migrated );
			}
			return self::normalize_profiles( $saved );
		}

		$legacy = self::legacy_routes();
		return empty( $legacy ) ? array() : self::normalize_profiles( self::wrap_routes( $legacy, 'Default Profile' ) );
	}

	public static function has_saved_profiles(): bool {
		$saved = get_option( self::OPTION, null );
		return is_array( $saved ) && ! empty( $saved );
	}

	public static function free_gemini_cascade( string $api_key ): array {
		$models = array(
			array( 'fast_free', 'Fast & Free', 'gemini-2.5-flash-lite' ),
			array( 'lite', 'Gemini Lite', 'gemini-flash-lite-latest' ),
			array( 'preview', 'Preview Route', 'gemini-3.1-flash-lite-preview' ),
			array( 'flash', 'Flash Backup', 'gemini-flash-latest' ),
			array( 'classic_lite', 'Classic Lite', 'gemini-2.0-flash-lite' ),
			array( 'gemma_31b', 'Gemma 31B', 'gemma-4-31b-it' ),
			array( 'gemma_26b', 'Gemma 26B', 'gemma-4-26b-a4b-it' ),
		);

		return array(
			array(
				'id'     => 'prof_gemini_free_route',
				'name'   => 'Gemini Free Route',
				'active' => true,
				'routes' => array_map(
					static fn( array $item ): array => array(
						'id'       => 'route_gemini_' . $item[0],
						'name'     => $item[1],
						'provider' => 'gemini',
						'model'    => $item[2],
						'apiKey'   => $api_key,
						'active'   => true,
					),
					$models
				),
			),
		);
	}

	/**
	 * Groq-first free route. Groq vision models run first (they do not train on
	 * request data); an optional Gemini key is appended as a fallback.
	 *
	 * Groq gives each vision model its own free daily quota (~1K requests/day
	 * for Scout and Qwen alike), so chaining both roughly doubles the free
	 * daily throughput to ~2K images. The optional Gemini models add ~3.5K more.
	 */
	public static function free_groq_cascade( string $groq_key, string $gemini_key = '' ): array {
		$routes = array(
			array(
				'id'       => 'route_groq_scout',
				'name'     => 'Groq Llama 4 Scout (Vision)',
				'provider' => 'groq',
				'model'    => 'meta-llama/llama-4-scout-17b-16e-instruct',
				'apiKey'   => $groq_key,
				'active'   => true,
			),
			array(
				'id'       => 'route_groq_qwen',
				'name'     => 'Groq Qwen 3 (Vision)',
				'provider' => 'groq',
				'model'    => 'qwen/qwen3.6-27b',
				'apiKey'   => $groq_key,
				'active'   => true,
			),
		);

		if ( '' !== $gemini_key ) {
			$gemini_models = array(
				array( 'flash_lite', 'Gemini 3.1 Flash Lite', 'gemini-3.1-flash-lite-preview' ),
				array( 'gemma_31b', 'Gemma 4 31B', 'gemma-4-31b-it' ),
				array( 'gemma_26b', 'Gemma 4 26B', 'gemma-4-26b-a4b-it' ),
			);
			foreach ( $gemini_models as $item ) {
				$routes[] = array(
					'id'       => 'route_gemini_' . $item[0],
					'name'     => $item[1],
					'provider' => 'gemini',
					'model'    => $item[2],
					'apiKey'   => $gemini_key,
					'active'   => true,
				);
			}
		}

		return array(
			array(
				'id'     => 'prof_free_ai_route',
				'name'   => 'Free AI Route',
				'active' => true,
				'routes' => $routes,
			),
		);
	}

	/**
	 * Single-route profile for a user's own (usually paid) key. No cascade —
	 * one strong model, run at full speed.
	 */
	public static function single_route_profile( string $provider, string $api_key, string $model = '' ): array {
		$provider = sanitize_key( $provider );
		if ( ! isset( self::DEFAULT_MODELS[ $provider ] ) ) {
			$provider = 'gemini';
		}
		$model = '' !== trim( $model ) ? trim( $model ) : self::DEFAULT_MODELS[ $provider ];

		return array(
			array(
				'id'     => 'prof_my_key',
				'name'   => 'My AI Route',
				'active' => true,
				'routes' => array(
					array(
						'id'       => 'route_my_' . $provider,
						'name'     => ucfirst( $provider ),
						'provider' => $provider,
						'model'    => $model,
						'apiKey'   => $api_key,
						'active'   => true,
					),
				),
			),
		);
	}

	public static function public_profiles(): array {
		return array_map( array( __CLASS__, 'public_profile' ), self::all() );
	}

	public static function get( string $id ): ?array {
		foreach ( self::all() as $profile ) {
			if ( $profile['id'] === $id ) {
				return $profile;
			}
		}
		return null;
	}

	public static function find_route( string $route_id, string $profile_id = '' ): ?array {
		foreach ( self::all() as $profile ) {
			if ( $profile_id && $profile['id'] !== $profile_id ) {
				continue;
			}
			foreach ( $profile['routes'] as $route ) {
				if ( $route['id'] === $route_id ) {
					return array_merge( $route, array( 'profileId' => $profile['id'] ) );
				}
			}
		}
		return null;
	}

	public static function first_active_route( array $profile, string $provider = '' ): ?array {
		foreach ( $profile['routes'] ?? array() as $route ) {
			if ( self::is_active( $route ) && ( '' === $provider || $provider === $route['provider'] ) ) {
				return $route;
			}
		}
		return null;
	}

	public static function default_profile_id(): string {
		$profile = self::all()[0] ?? array();
		return (string) ( $profile['id'] ?? '' );
	}

	public static function architect_profile_id(): string {
		return self::valid_profile_id( get_option( self::ARCHITECT_OPTION, '' ) );
	}

	public static function media_profile_id(): string {
		$profile = self::media_profile();
		return (string) ( $profile['id'] ?? '' );
	}

	public static function media_profile(): ?array {
		$saved = self::valid_profile_id( get_option( self::MEDIA_OPTION, '' ), true );
		if ( $saved ) {
			return self::get( $saved );
		}
		foreach ( self::all() as $profile ) {
			if ( self::has_vision_route( $profile ) ) {
				return $profile;
			}
		}
		return null;
	}

	/**
	 * Routes usable for vision metadata generation (Gemini or Groq vision models).
	 */
	public static function media_routes(): array {
		return self::filter_media_routes( self::VISION_PROVIDERS );
	}

	/**
	 * Routes usable for embeddings. Only Gemini exposes an embedding API.
	 */
	public static function embedding_routes(): array {
		return self::filter_media_routes( array( 'gemini' ) );
	}

	private static function filter_media_routes( array $providers ): array {
		$profile = self::media_profile();
		if ( ! $profile ) {
			return array();
		}
		return array_values(
			array_filter(
				$profile['routes'],
				static fn( array $route ): bool => in_array( $route['provider'], $providers, true ) && self::is_active( $route ) && '' !== (string) $route['apiKey']
			)
		);
	}

	public static function sanitize_profiles( $profiles ): array {
		$profiles = is_array( $profiles ) ? $profiles : array();
		if ( self::is_flat_collection( $profiles ) ) {
			$profiles = self::wrap_routes( $profiles, 'Default Profile' );
		}

		$existing = self::route_index( self::all() );
		$clean = array();
		foreach ( $profiles as $profile ) {
			$profile = is_array( $profile ) ? $profile : array();
			$id = self::safe_id( $profile['id'] ?? '', 'prof_' );
			$routes = self::sanitize_routes( $profile['routes'] ?? array(), $existing );
			$clean[] = array(
				'id'     => $id,
				'name'   => sanitize_text_field( $profile['name'] ?? 'Routing Profile' ),
				'active' => self::is_active( $profile ),
				'routes' => $routes,
			);
		}
		return $clean;
	}

	public static function sanitize_media_profile_id( $profile_id ): string {
		return self::valid_profile_id( $profile_id, true );
	}

	public static function sanitize_architect_profile_id( $profile_id ): string { return self::valid_profile_id( $profile_id ); }

	private static function sanitize_routes( array $routes, array $existing ): array {
		$clean = array();
		foreach ( $routes as $route ) {
			if ( ! is_array( $route ) ) {
				continue;
			}
			$provider = sanitize_key( $route['provider'] ?? 'gemini' );
			if ( ! isset( self::DEFAULT_MODELS[ $provider ] ) ) {
				continue;
			}
			$id = self::safe_id( $route['id'] ?? '', 'route_' );
			$key = trim( sanitize_text_field( $route['apiKey'] ?? '' ) );
			$key = '' === $key && isset( $existing[ $id ] ) ? (string) $existing[ $id ]['apiKey'] : $key;
			if ( 'vertex' === $provider ) {
				$key = self::sanitize_vertex_key( (string) ( $route['apiKey'] ?? '' ), $existing[ $id ]['apiKey'] ?? '' );
			}
			$clean[] = array(
				'id' => $id, 'name' => sanitize_text_field( $route['name'] ?? ucfirst( $provider ) ), 'provider' => $provider,
				'model' => sanitize_text_field( $route['model'] ?? self::DEFAULT_MODELS[ $provider ] ), 'apiKey' => $key,
				'location' => sanitize_text_field( $route['location'] ?? 'us-central1' ), 'active' => self::is_active( $route ),
			);
		}
		return $clean;
	}

	private static function normalize_profiles( array $profiles ): array { return self::sanitize_profiles_without_existing( $profiles ); }

	private static function sanitize_profiles_without_existing( array $profiles ): array {
		$clean = array();
		foreach ( $profiles as $profile ) {
			$profile = is_array( $profile ) ? $profile : array();
			$routes = self::sanitize_routes( $profile['routes'] ?? array(), array() );
			$clean[] = array(
				'id' => self::safe_id( $profile['id'] ?? '', 'prof_' ), 'name' => sanitize_text_field( $profile['name'] ?? 'Routing Profile' ),
				'active' => self::is_active( $profile ), 'routes' => $routes,
			);
		}
		return $clean;
	}

	private static function public_profile( array $profile ): array {
		$routes = array_map(
			static fn( array $route ): array => array(
				'id' => $route['id'], 'name' => $route['name'], 'provider' => $route['provider'], 'model' => $route['model'],
				'location' => $route['location'] ?? '', 'active' => self::is_active( $route ), 'hasApiKey' => '' !== (string) ( $route['apiKey'] ?? '' ),
			),
			$profile['routes']
		);
		return array(
			'id' => $profile['id'], 'name' => $profile['name'], 'active' => self::is_active( $profile ),
			'routes' => $routes, 'routeCount' => count( $routes ), 'hasGemini' => self::has_gemini_route( $profile ),
			'hasVision' => self::has_vision_route( $profile ),
		);
	}

	private static function has_gemini_route( array $profile ): bool { return (bool) self::first_active_route( $profile, 'gemini' ); }

	private static function has_vision_route( array $profile ): bool {
		foreach ( self::VISION_PROVIDERS as $provider ) {
			if ( self::first_active_route( $profile, $provider ) ) {
				return true;
			}
		}
		return false;
	}

	private static function sanitize_vertex_key( string $raw, string $existing ): string {
		if ( '' === trim( $raw ) ) return $existing;
		return class_exists( 'WPAK_Provider_Vertex' )
			? ( is_wp_error( $normalized = WPAK_Provider_Vertex::normalize_credentials( $raw ) ) ? '' : $normalized )
			: $raw;
	}

	private static function valid_profile_id( $profile_id, bool $requires_vision = false ): string {
		$profile_id = sanitize_key( (string) $profile_id );
		$profile = $profile_id ? self::get( $profile_id ) : null;
		if ( $profile && ( ! $requires_vision || self::has_vision_route( $profile ) ) ) {
			return $profile_id;
		}
		foreach ( self::all() as $candidate ) {
			if ( ! $requires_vision || self::has_vision_route( $candidate ) ) {
				return $candidate['id'];
			}
		}
		return '';
	}

	private static function is_flat_collection( array $items ): bool {
		$first = reset( $items );
		return is_array( $first ) && isset( $first['provider'], $first['model'] ) && ! isset( $first['routes'] );
	}

	private static function wrap_routes( array $routes, string $name ): array {
		return array( array( 'id' => 'prof_default', 'name' => $name, 'active' => true, 'routes' => $routes ) );
	}

	private static function legacy_routes(): array {
		$legacy = array(
			array( 'gemini', 'Gemini Free', 'gemini-2.5-flash', get_option( 'wpaikits99_gemini_api_key', '' ) ),
			array( 'groq', 'Groq', 'openai/gpt-oss-120b', get_option( 'wpaikits99_groq_api_key', '' ) ),
			array( 'mistral', 'Mistral', 'mistral-small-latest', get_option( 'wpaikits99_mistral_api_key', '' ) ),
			array( 'openai', 'OpenAI', 'gpt-4o-mini', get_option( 'wpaikits99_openai_api_key', '' ) ),
		);
		$routes = array();
		foreach ( $legacy as $item ) {
			if ( '' !== $item[3] ) {
				$routes[] = array( 'id' => 'route_' . $item[0] . '_legacy', 'name' => $item[1], 'provider' => $item[0], 'model' => $item[2], 'apiKey' => (string) $item[3], 'active' => true );
			}
		}
		return $routes;
	}

	private static function route_index( array $profiles ): array {
		$indexed = array();
		foreach ( $profiles as $profile ) {
			foreach ( $profile['routes'] as $route ) {
				$indexed[ $route['id'] ] = $route;
			}
		}
		return $indexed;
	}

	private static function safe_id( string $id, string $prefix ): string {
		$id = sanitize_key( $id );
		return '' === $id ? $prefix . substr( md5( wp_rand() . microtime() ), 0, 10 ) : $id;
	}

	private static function is_active( array $item ): bool {
		return ! in_array( $item['active'] ?? true, array( false, '0', 0, 'false' ), true );
	}
}

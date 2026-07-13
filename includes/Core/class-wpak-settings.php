<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WPAK_PATH . 'includes/Core/Settings/class-wpak-media-key-settings.php';
require_once WPAK_PATH . 'includes/Core/Settings/class-wpak-settings-renderer.php';

class WPAK_Settings {

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_icon_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpaikits99_test_gemini_key', array( $this, 'ajax_test_key' ) );
		add_action( 'wp_ajax_wpaikits99_test_ai_profile', array( $this, 'ajax_test_ai_profile' ) );
		add_action( 'wp_ajax_wpaikits99_save_core_settings', array( $this, 'ajax_save_core_settings' ) );
		add_action( 'wp_ajax_wpaikits99_save_media_keys', array( 'WPAK_Media_Key_Settings', 'ajax_save' ) );
	}

	public function add_admin_page(): void {
		add_menu_page(
			'WP AI Kits',
			'WP AI Kits',
			'manage_options',
			'wpaikits',
			array( $this, 'render_page' ),
			$this->admin_menu_icon(),
			30
		);
	}

	public function register_settings(): void {
		$text_options = array( 'wpaikits99_gemini_api_key', 'wpaikits99_groq_api_key', 'wpaikits99_openai_api_key', 'wpaikits99_mistral_api_key', 'wpaikits99_unsplash_key', 'wpaikits99_pexels_key' );

		foreach ( $text_options as $option ) {
			register_setting(
				'wpaikits99_api_settings_group',
				$option,
				array(
					'sanitize_callback' => static fn( $value ): string => self::sanitize_secret_option( $option, $value ),
				)
			);
		}

		register_setting( 'wpaikits99_api_settings_group', 'wpaikits99_ai_profiles', array( 'sanitize_callback' => array( 'WPAK_AI_Profiles', 'sanitize_profiles' ) ) );
		register_setting( 'wpaikits99_modules_group', 'wpaikits99_access_capability', array( 'sanitize_callback' => array( 'WPAK_AI_Access', 'sanitize' ), 'default' => 'manage_options' ) );
		register_setting( 'wpaikits99_modules_group', 'wpaikits99_architect_ai_profile', array( 'sanitize_callback' => array( 'WPAK_AI_Profiles', 'sanitize_architect_profile_id' ) ) );
		register_setting( 'wpaikits99_api_settings_group', 'wpaikits99_meta_router_enabled', array( 'default' => '0' ) );
		register_setting(
			'wpaikits99_modules_group',
			'wpaikits99_active_modules',
			array(
				'default'           => array( 'archivist' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_active_modules' ),
			)
		);
	}

	public static function sanitize_secret_option( string $option, $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return '' === $value ? (string) get_option( $option, '' ) : $value;
	}

	public static function sanitize_active_modules( $modules ): array {
		$allowed = apply_filters( 'wpaikits99_available_module_slugs', array( 'archivist' ) );
		$modules = is_array( $modules ) ? $modules : array();

		return array_values(
			array_intersect(
				array_map( 'sanitize_key', $modules ),
				$allowed
			)
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_wpaikits' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpak-google-fonts', 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Newsreader:ital,opsz,wght@0,6..72,400..700;1,6..72,400..700&family=Outfit:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'wpak-phosphor-icons', 'https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css', array(), '2.1.1' );
		wp_enqueue_style( 'wpak-phosphor-fill-icons', 'https://unpkg.com/@phosphor-icons/web@2.1.1/src/fill/style.css', array( 'wpak-phosphor-icons' ), '2.1.1' );
		wp_enqueue_style( 'wpak-variables', WPAK_URL . 'assets/css/wpak-variables.css', array(), self::asset_version( 'assets/css/wpak-variables.css' ) );
		wp_enqueue_style( 'wpak-admin-css', WPAK_URL . 'assets/css/wpak-admin.css', array( 'wpak-variables', 'wpak-phosphor-icons' ), self::asset_version( 'assets/css/wpak-admin.css' ) );
		wp_enqueue_style( 'wpak-admin-routes', WPAK_URL . 'assets/css/wpak-admin-routes.css', array( 'wpak-admin-css' ), self::asset_version( 'assets/css/wpak-admin-routes.css' ) );
		wp_enqueue_script( 'wpak-admin-js', WPAK_URL . 'assets/js/wpak-admin.js', array( 'jquery' ), self::asset_version( 'assets/js/wpak-admin.js' ), true );

		$this->enqueue_react_settings();
		wp_localize_script( 'wpak-admin-js', 'wpakSettings', $this->settings_payload() );
	}

	public function enqueue_menu_icon_styles(): void {
		wp_enqueue_style( 'wpak-admin-menu-icon', WPAK_URL . 'assets/css/wpak-admin-menu-icon.css', array(), self::asset_version( 'assets/css/wpak-admin-menu-icon.css' ) );
	}

	private static function asset_version( string $relative_path ): string {
		$path = WPAK_PATH . $relative_path;
		return file_exists( $path ) ? (string) filemtime( $path ) : WPAK_VERSION;
	}

	public function render_page(): void {
		WPAK_Settings_Renderer::render( array(
			'api_key' => get_option( 'wpaikits99_gemini_api_key', '' ),
			'meta_router' => get_option( 'wpaikits99_meta_router_enabled', '0' ),
			'active_modules' => get_option( 'wpaikits99_active_modules', array( 'archivist' ) ),
			'is_pro' => (bool) apply_filters( 'wpaikits99_is_pro', false ),
			'hub_stats' => $this->hub_stats(),
		) );
	}

	private function hub_stats(): array {
		$last_processed = WPAK_DB::get_last_processed_at();
		$last_run       = '';
		if ( '' !== $last_processed ) {
			$timestamp = strtotime( $last_processed );
			$last_run  = $timestamp ? human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ago' : '';
		}

		return array(
			'processed' => WPAK_DB::get_processed_count(),
			'in_queue'  => class_exists( 'WPAK_Queue' ) ? WPAK_Queue::active_count() : 0,
			'last_run'  => $last_run,
		);
	}

	public function ajax_test_key(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( '' === $api_key ) {
			wp_send_json_error( 'No key provided.' );
		}
		$result = WPAK_LLM_Proxy::validate_gemini_key( $api_key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( 'API key is valid.' );
	}

	public function ajax_test_ai_profile(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$profile = $this->posted_test_profile();
		if ( empty( $profile['apiKey'] ) ) {
			wp_send_json_error( 'Add or save an API key before testing.', 400 );
		}
		// Real vision test: the Media AI Kit only uses vision models, so confirm
		// the model can actually see an image — not just answer text.
		$response = WPAK_LLM_Proxy::test_vision_route(
			array(
				'provider' => $profile['provider'],
				'model'    => $profile['model'],
				'apiKey'   => $profile['apiKey'],
				'active'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 500 );
		}
		wp_send_json_success( 'Vision test passed — the model returned alt text, a title, and a description.' );
	}

	public function ajax_save_core_settings(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$profiles = WPAK_AI_Profiles::sanitize_profiles(
			json_decode( stripslashes( (string) ( $_POST['ai_profiles'] ?? '[]' ) ), true )
		);
		update_option( 'wpaikits99_ai_profiles', $profiles, false );
		update_option( 'wpaikits99_access_capability', WPAK_AI_Access::sanitize( $_POST['access_capability'] ?? 'manage_options' ), false );
		update_option( 'wpaikits99_architect_ai_profile', WPAK_AI_Profiles::sanitize_architect_profile_id( $_POST['architect_profile'] ?? '' ), false );

		$current_media_profile = get_option( 'wpaikits99_media_ai_profile', '' );
		if ( $current_media_profile && ! WPAK_AI_Profiles::get( (string) $current_media_profile ) ) {
			update_option( 'wpaikits99_media_ai_profile', WPAK_AI_Profiles::media_profile_id(), false );
		}

		wp_send_json_success(
			array(
				'message'          => 'Core AI settings saved.',
				'aiProfiles'       => WPAK_AI_Profiles::public_profiles(),
				'architectProfile' => WPAK_AI_Profiles::architect_profile_id(),
				'accessCapability' => WPAK_AI_Access::selected(),
			)
		);
	}

	private function enqueue_react_settings(): void {
		$asset_file = WPAK_PATH . 'build/settings/prompts-manager.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$assets = require $asset_file;
		wp_enqueue_script( 'wpak-prompts-react', WPAK_URL . 'build/settings/prompts-manager.js', $assets['dependencies'], $assets['version'], true );

		$css_file = WPAK_PATH . 'build/settings/prompts-manager.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style( 'wpak-prompts-react-css', WPAK_URL . 'build/settings/prompts-manager.css', array(), $assets['version'] );
		}
	}

	private function settings_payload(): array {
		$payload = array(
			'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
			'nonce'                     => wp_create_nonce( 'wpaikits99_admin_nonce' ),
			'logoUrl'                   => WPAK_URL . 'logo.svg',
			'pluginUrl'                 => WPAK_URL,
			'activeModules'             => get_option( 'wpaikits99_active_modules', array( 'archivist' ) ),
			'aiProfiles'                => WPAK_AI_Profiles::public_profiles(),
			'architectProfile'          => WPAK_AI_Profiles::architect_profile_id(),
			'isPro'                     => (bool) apply_filters( 'wpaikits99_is_pro', false ),
			'accessCapability'          => WPAK_AI_Access::selected(),
			'accessChoices'             => WPAK_AI_Access::choices(),
			'mediaKeys'                 => WPAK_Media_Key_Settings::payload(),
			'onboarding'                => WPAK_Onboarding::payload(),
			'archivist'                 => $this->archivist_payload(),
		);

		return apply_filters( 'wpaikits99_settings_payload', $payload );
	}

	private function posted_test_profile(): array {
		$profile_id = sanitize_key( $_POST['profile_id'] ?? '' );
		$route_id = sanitize_key( $_POST['route_id'] ?? '' );
		$route = $route_id ? WPAK_AI_Profiles::find_route( $route_id, $profile_id ) : null;
		$provider = sanitize_key( $_POST['provider'] ?? ( $route['provider'] ?? 'gemini' ) );
		$api_key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		// An existing route sends an empty key (saved keys are never returned to
		// the browser), so fall back to the stored key — otherwise "Test" on a
		// saved row wrongly reports a missing key.
		if ( '' === $api_key && is_array( $route ) && ! empty( $route['apiKey'] ) ) {
			$api_key = (string) $route['apiKey'];
		}
		if ( 'vertex' === $provider ) {
			$normalized = WPAK_Provider_Vertex::normalize_credentials( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
			$api_key = $route ? WPAK_Provider_Vertex::route_secret( $route ) : wp_json_encode( array(
				'credentials' => is_wp_error( $normalized ) ? '' : $normalized,
				'location'    => sanitize_text_field( wp_unslash( $_POST['location'] ?? 'us-central1' ) ),
			) );
		}
		return array( 'provider' => $provider, 'model' => sanitize_text_field( wp_unslash( $_POST['model'] ?? ( $route['model'] ?? 'gemini-2.5-flash' ) ) ), 'apiKey' => $api_key );
	}

	private function archivist_payload(): array {
		return array(
			'autoAlt' => get_option( 'wpaikits99_archivist_auto_alt', '1' ),
			'fullSpeed' => get_option( 'wpaikits99_archivist_full_speed', '0' ),
			'autoIndex' => get_option( 'wpaikits99_archivist_auto_index', '1' ), 'overrideAlt' => get_option( 'wpaikits99_archivist_override_alt', '0' ),
			'overrideTitle'=> get_option( 'wpaikits99_archivist_override_title', '0' ),
			'overrideDesc' => get_option( 'wpaikits99_archivist_override_description', '0' ), 'threshold' => get_option( 'wpaikits99_archivist_threshold', '0.25' ),
			'metadataPrompt' => WPAK_Gemini_Media::get_metadata_instructions(),
			'pineconeKey' => '', 'pineconeHost' => get_option( 'wpaikits99_pinecone_host', '' ),
			'pineconeIndex'=> WPAK_Pinecone::index_name(), 'mediaProfile' => WPAK_AI_Profiles::media_profile_id(),
		);
	}

	private function admin_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><g fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.45"><circle cx="10" cy="10" r="3.7"/><path d="M10 2.5v3M10 14.5v3M2.5 10h3M14.5 10h3M4.7 4.7l2.1 2.1M13.2 13.2l2.1 2.1M15.3 4.7l-2.1 2.1M6.8 13.2l-2.1 2.1"/></g><circle cx="10" cy="10" r="1.55" fill="#fff"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}

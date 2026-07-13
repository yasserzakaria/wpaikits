<?php
/**
 * Plugin Name: WP AI Kits
 * Plugin URI:  https://wpaikits.site
 * Description: AI-written media metadata with rate-limit-aware background processing for WordPress.
 * Version:     1.0.0
 * Author:      WP AI Kits
 * Text Domain: wp-agentkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wpa_fs' ) ) {
	// Create a helper function for easy SDK access.
	function wpa_fs() {
		global $wpa_fs;

		if ( ! isset( $wpa_fs ) ) {
			// Include Freemius SDK.
			require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

			$wpa_fs = fs_dynamic_init( array(
				'id'               => '33752',
				'slug'             => 'wpaikits',
				'type'             => 'plugin',
				'public_key'       => 'pk_ef644da6f7766a748423c6fd10eef',
				'is_premium'       => false,
				'has_addons'       => false,
				'has_paid_plans'   => false,
				// Not distributed on WordPress.org; free updates come from GitHub releases.
				'is_org_compliant' => false,
				// Optional opt-in: users can Skip. We still collect the email
				// from everyone who connects, without walling off the plugin.
				'enable_anonymous' => true,
				'menu'             => array(
					'slug'    => 'wpaikits',
					'account' => false,
					'contact' => false,
					'support' => false,
				),
			) );

			// We only want the email/account — not the site's plugin/theme
			// list or server diagnostics. Turn that tracking off entirely.
			$wpa_fs->add_filter( 'is_extensions_tracking_allowed', '__return_false' );
			$wpa_fs->add_filter( 'is_diagnostic_tracking_allowed', '__return_false' );
		}

		return $wpa_fs;
	}

	// Init Freemius.
	wpa_fs();
	// Signal that SDK was initiated.
	do_action( 'wpa_fs_loaded' );
}

define( 'WPAK_VERSION', '1.0.0' );
define( 'WPAK_FILE', __FILE__ );
define( 'WPAK_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPAK_URL', plugin_dir_url( __FILE__ ) );

$wpaikits99_action_scheduler = WPAK_PATH . 'lib/action-scheduler/action-scheduler.php';
if ( file_exists( $wpaikits99_action_scheduler ) ) {
	require_once $wpaikits99_action_scheduler;
}

require_once WPAK_PATH . 'includes/Core/class-wpak-db.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-ai-access.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-ai-profiles.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-ai-logger.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-ai-log-summarizer.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-ai-log-rounds.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-ai-log-admin.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-onboarding.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-activation-redirect.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-uninstaller.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-update-checker.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-queue.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-pinecone.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-llm-proxy.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-settings.php';
require_once WPAK_PATH . 'includes/Modules/class-wpak-module.php';
require_once WPAK_PATH . 'includes/Core/class-wpak-runtime-registry.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist.php';

register_activation_hook( __FILE__, 'wpaikits99_activate' );

WPAK_Update_Checker::register();

add_action( 'plugins_loaded', 'wpaikits99_init' );
WPAK_Uninstaller::register_hooks();

function wpaikits99_init() {
	WPAK_DB::maybe_upgrade();

	$services = array(
		new WPAK_Settings(),
		new WPAK_Onboarding(),
		new WPAK_AI_Logger(),
		new WPAK_Activation_Redirect(),
		new WPAK_LLM_Proxy(),
		new WPAK_Pinecone(),
	);

	foreach ( $services as $service ) {
		wpaikits99_register_service( $service );
	}
	do_action( 'wpaikits99_register_services' );
	WPAK_Runtime_Registry::boot_services();

	wpaikits99_register_free_modules();
	do_action( 'wpaikits99_register_modules' );
	WPAK_Runtime_Registry::boot_modules();
	do_action( 'wpaikits99_loaded' );
}

function wpaikits99_register_free_modules(): void {
	wpaikits99_register_module( new WPAK_Module_Archivist() );
}

function wpaikits99_activate(): void {
	WPAK_DB::activate();
	WPAK_Activation_Redirect::flag();
}

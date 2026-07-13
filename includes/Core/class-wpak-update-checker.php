<?php
/**
 * GitHub release updates for the free WP AI Kits plugin.
 *
 * @package WPAK
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Update_Checker {
	/**
	 * Register the checker early enough for WordPress and management tools.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WPAK_DISABLE_GITHUB_UPDATES' ) && WPAK_DISABLE_GITHUB_UPDATES ) {
			return;
		}

		$library = WPAK_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

		if ( ! file_exists( $library ) ) {
			return;
		}

		require_once $library;

		if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/yasserzakaria/wpaikits/',
			WPAK_FILE,
			'wpaikits'
		);

		$checker->getVcsApi()->enableReleaseAssets( '/^wpaikits\\.zip$/i' );
	}
}

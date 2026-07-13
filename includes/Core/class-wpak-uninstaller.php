<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Uninstaller {

	public static function register_hooks(): void {
		if ( function_exists( 'wpa_fs' ) ) {
			wpa_fs()->add_action( 'after_uninstall', array( __CLASS__, 'cleanup' ) );
		}
	}

	public static function cleanup(): void {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpaikits99_sync_tracking" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpaikits99_ai_logs" );

		$wpdb->delete(
			$wpdb->postmeta,
			array( 'meta_key' => '_wpaikits99_archivist_processed_at' ),
			array( '%s' )
		);

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpaikits99\_%'" );
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_wpaikits99\_%'
				OR option_name LIKE '\_transient\_timeout\_wpaikits99\_%'"
		);

		wp_clear_scheduled_hook( 'wpaikits99_prune_ai_logs' );
		self::unschedule_actions();
	}

	private static function unschedule_actions(): void {
		$action_scheduler = WPAK_PATH . 'lib/action-scheduler/action-scheduler.php';
		if ( ! function_exists( 'as_unschedule_all_actions' ) && file_exists( $action_scheduler ) ) {
			require_once $action_scheduler;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'wpaikits99' );
		}
	}
}

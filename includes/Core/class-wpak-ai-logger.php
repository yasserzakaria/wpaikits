<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_AI_Logger {

	public const INSERT_HOOK = 'wpaikits99_insert_ai_log';
	public const PRUNE_HOOK = 'wpaikits99_prune_ai_logs';

	public function register_hooks(): void {
		add_action( self::INSERT_HOOK, array( __CLASS__, 'insert' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
	}

	public static function queue( array $entry ): void {
		self::insert( $entry );
	}

	public static function insert( array $entry ): void {
		global $wpdb;

		$wpdb->insert(
			WPAK_DB::ai_logs_table_name(),
			array(
				'time'             => current_time( 'mysql' ),
				'profile_id'       => sanitize_key( $entry['profile_id'] ?? '' ),
				'provider'         => sanitize_key( $entry['provider'] ?? '' ),
				'model'            => sanitize_text_field( $entry['model'] ?? '' ),
				'prompt_payload'   => wp_json_encode( $entry['prompt_payload'] ?? array() ),
				'response_payload' => wp_json_encode( $entry['response_payload'] ?? array() ),
				'status_code'      => absint( $entry['status_code'] ?? 0 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	public static function latest( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . WPAK_DB::ai_logs_table_name() . ' ORDER BY time DESC LIMIT %d',
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);
	}

	public static function prune(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WPAK_DB::ai_logs_table_name() . ' WHERE time < DATE_SUB(NOW(), INTERVAL 7 DAY)' );
	}
}

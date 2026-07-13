<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_DB {
	private const PROCESSED_META_KEY = '_wpaikits99_archivist_processed_at';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpaikits99_sync_tracking';
	}

	public static function ai_logs_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpaikits99_ai_logs';
	}

	public static function activate(): void {
		global $wpdb;

		$table = self::table_name();
		$sql   = "CREATE TABLE {$table} (
			object_id BIGINT(20) UNSIGNED NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT 'attachment',
			indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (object_id)
		) {$wpdb->get_charset_collate()};";

		$logs_table = self::ai_logs_table_name();
		$logs_sql   = "CREATE TABLE {$logs_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			time DATETIME NOT NULL,
			profile_id VARCHAR(80) NOT NULL DEFAULT '',
			provider VARCHAR(40) NOT NULL DEFAULT '',
			model VARCHAR(120) NOT NULL DEFAULT '',
			prompt_payload LONGTEXT NULL,
			response_payload LONGTEXT NULL,
			status_code SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY time (time),
			KEY profile_id (profile_id)
		) {$wpdb->get_charset_collate()};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $logs_sql );

		if ( false === get_option( 'wpaikits99_active_modules', false ) ) {
			update_option( 'wpaikits99_active_modules', array( 'archivist' ), false );
		}

		if ( false === get_option( 'wpaikits99_access_capability', false ) ) {
			update_option( 'wpaikits99_access_capability', 'manage_options', false );
		}

		if ( ! wp_next_scheduled( 'wpaikits99_prune_ai_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpaikits99_prune_ai_logs' );
		}

		update_option( 'wpaikits99_db_version', WPAK_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( WPAK_VERSION !== get_option( 'wpaikits99_db_version', '' ) || ! self::table_exists( self::ai_logs_table_name() ) ) {
			self::activate();
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public static function is_indexed( int $object_id, string $object_type = 'attachment' ): bool {
		global $wpdb;

		$table = self::table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE object_id = %d AND object_type = %s",
				$object_id,
				$object_type
			)
		);

		return (int) $count > 0;
	}

	public static function mark_indexed( int $object_id, string $object_type = 'attachment' ): bool {
		global $wpdb;

		$result = $wpdb->replace(
			self::table_name(),
			array(
				'object_id'   => absint( $object_id ),
				'object_type' => sanitize_key( $object_type ),
				'indexed_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	public static function delete_tracking( int $object_id ): bool {
		global $wpdb;
		delete_post_meta( $object_id, self::PROCESSED_META_KEY );

		return (bool) $wpdb->delete(
			self::table_name(),
			array( 'object_id' => absint( $object_id ) ),
			array( '%d' )
		);
	}

	public static function save_alt_text( int $attachment_id, string $alt_text ): bool {
		return update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			sanitize_text_field( $alt_text )
		);
	}

	public static function get_indexed_count(): int {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE object_type = 'attachment'"
		);
	}

	public static function mark_processed( int $attachment_id ): bool {
		return false !== update_post_meta(
			$attachment_id,
			self::PROCESSED_META_KEY,
			current_time( 'mysql' )
		);
	}

	public static function is_processed( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::PROCESSED_META_KEY, true );
	}

	public static function get_processed_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'attachment'
					AND p.post_mime_type LIKE 'image/%%'
					AND pm.meta_key = %s",
				self::PROCESSED_META_KEY
			)
		);
	}

	public static function get_last_processed_at(): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::PROCESSED_META_KEY
			)
		);
	}

	public static function get_total_image_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);
	}

	public static function get_unindexed_image_ids( int $limit = 100 ): array {
		global $wpdb;

		$table = self::table_name();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$table} s ON p.ID = s.object_id
					AND s.object_type = 'attachment'
				WHERE p.post_type = 'attachment'
					AND p.post_mime_type LIKE 'image/%%'
					AND s.object_id IS NULL
				LIMIT %d",
				absint( $limit )
			)
		);

		return array_map( 'absint', $ids );
	}

	public static function get_unprocessed_image_ids( int $limit = 5000 ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
					AND p.post_mime_type LIKE 'image/%%'
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} pm
						WHERE pm.post_id = p.ID AND pm.meta_key = %s
					)
				ORDER BY p.ID DESC
				LIMIT %d",
				self::PROCESSED_META_KEY,
				absint( $limit )
			)
		);

		return array_map( 'absint', $ids );
	}

	public static function get_image_ids( int $limit = 5000 ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
					AND post_mime_type LIKE 'image/%%'
				ORDER BY ID DESC
				LIMIT %d",
				absint( $limit )
			)
		);

		return array_map( 'absint', $ids );
	}
}

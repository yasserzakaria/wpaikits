<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Logger {

	private const OPTION_NAME = 'wpaikits99_archivist_logs';
	private const MAX_LOGS = 50;

	public static function log( string $message, string $status = 'info', int $attachment_id = 0 ): void {
		$logs = get_option( self::OPTION_NAME, array() );
		$logs = is_array( $logs ) ? $logs : array();
		$edit_link = $attachment_id ? get_edit_post_link( $attachment_id, '' ) : '';

		array_unshift(
			$logs,
			array(
				'id'        => uniqid( 'wpaikits99_log_', true ),
				'time'      => current_time( 'Y-m-d H:i:s' ),
				'message'   => sanitize_text_field( $message ),
				'status'    => sanitize_key( $status ),
				'attach_id' => absint( $attachment_id ),
				'thumbnail' => self::thumbnail_url( $attachment_id ),
				'edit_link' => is_string( $edit_link ) ? esc_url_raw( $edit_link ) : '',
			)
		);

		update_option( self::OPTION_NAME, array_slice( $logs, 0, self::MAX_LOGS ), false );
	}

	public static function get_logs(): array {
		$logs = get_option( self::OPTION_NAME, array() );
		return is_array( $logs ) ? $logs : array();
	}

	public static function clear(): void {
		update_option( self::OPTION_NAME, array(), false );
	}

	private static function thumbnail_url( int $attachment_id ): string {
		if ( ! $attachment_id ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		return $src ? esc_url_raw( $src[0] ) : '';
	}
}

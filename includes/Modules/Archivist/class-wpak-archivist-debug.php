<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Debug {

	public static function log( string $message, array $context = array() ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$line = sprintf(
			"[%s] %s%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$message,
			empty( $context ) ? '' : ' ' . wp_json_encode( self::sanitize_context( $context ) )
		);

		error_log( '[WPAK Media Sync] ' . trim( $line ) );

		if ( defined( 'WPAK_SYNC_DEBUG' ) && true === WPAK_SYNC_DEBUG ) {
			error_log( $line, 3, self::path() );
		}
	}

	public static function path(): string {
		return WP_CONTENT_DIR . '/wpak-sync-debug.log';
	}

	private static function enabled(): bool {
		return ( defined( 'WP_DEBUG' ) && true === WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && false !== WP_DEBUG_LOG )
			|| ( defined( 'WPAK_SYNC_DEBUG' ) && true === WPAK_SYNC_DEBUG );
	}

	private static function sanitize_context( array $context ): array {
		return array_map(
			static function ( $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					return sanitize_text_field( (string) $value );
				}

				return sanitize_text_field( wp_json_encode( $value ) );
			},
			$context
		);
	}
}

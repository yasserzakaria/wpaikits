<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_LLM_Debug {

	private const MAX_LOG_LENGTH = 12000;

	public static function request( string $provider, string $model, array $body ): void {
		if ( ! self::enabled() ) {
			return;
		}

		self::write( self::heading( $provider, 'REQUEST', $model ) );
		self::write( wp_json_encode( self::redact( $body ) ) );
	}

	public static function response( string $provider, string $model, int $code, string $raw_body ): void {
		if ( ! self::enabled() ) {
			return;
		}

		self::write( self::heading( $provider, 'RESPONSE', $model, $code ) );
		self::write( self::truncate( $raw_body ) );
	}

	public static function http_error( string $provider, string $message ): void {
		if ( self::enabled() ) {
			self::write( 'HTTP ERROR (' . ucfirst( strtolower( $provider ) ) . '): ' . $message );
		}
	}

	private static function enabled(): bool {
		if ( defined( 'WPAK_DEBUG_RAW_LLM' ) ) {
			return true === WPAK_DEBUG_RAW_LLM;
		}

		return defined( 'WP_DEBUG' ) && true === WP_DEBUG
			&& defined( 'WP_DEBUG_LOG' ) && false !== WP_DEBUG_LOG;
	}

	private static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), array( 'api_key', 'apikey', 'x-goog-api-key', 'authorization', 'credentials' ), true ) ) {
				$data[ $key ] = '[redacted secret]';
			} elseif ( 'data' === $key && is_string( $value ) ) {
				$data[ $key ] = '[redacted image data: ' . strlen( $value ) . ' chars]';
			} elseif ( is_string( $value ) && 0 === strpos( $value, 'data:' ) ) {
				$data[ $key ] = '[redacted inline image: ' . strlen( $value ) . ' chars]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}

		return $data;
	}

	private static function write( string $message ): void {
		error_log( '[WPAK LLM] ' . self::truncate( $message ) );
	}

	private static function truncate( string $message ): string {
		return strlen( $message ) > self::MAX_LOG_LENGTH
			? substr( $message, 0, self::MAX_LOG_LENGTH ) . ' [truncated]'
			: $message;
	}

	private static function heading( string $provider, string $type, string $model = '', int $code = 0 ): string {
		$suffix = $model ? " ({$model}" . ( $code ? ", {$code}" : '' ) . ')' : ( $code ? " ({$code})" : '' );
		return strtoupper( $provider ) . ' ' . $type . $suffix;
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Gemini_Response {

	public static function extract_text( array $response ): string {
		$text = '';

		if ( isset( $response[0]['candidates'][0]['content']['parts'] ) ) {
			foreach ( $response as $chunk ) {
				$text .= self::parts_text( $chunk['candidates'][0]['content']['parts'] ?? array() );
			}
			return $text;
		}

		return self::parts_text( $response['candidates'][0]['content']['parts'] ?? array() );
	}

	public static function parse_json( string $raw_text ): ?array {
		$text = trim( $raw_text );
		$direct = json_decode( $text, true );
		if ( is_array( $direct ) ) {
			return self::sanitize_nulls( $direct );
		}

		$clean = preg_replace( '/```(?:json)?\s*/i', '', $text );
		$clean = trim( preg_replace( '/```\s*$/', '', (string) $clean ) );
		$fenced = json_decode( $clean, true );
		if ( is_array( $fenced ) ) {
			return self::sanitize_nulls( $fenced );
		}

		$start = strpos( $clean, '{' );
		$end = strrpos( $clean, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$extracted = json_decode( substr( $clean, $start, $end - $start + 1 ), true );
		return is_array( $extracted ) ? self::sanitize_nulls( $extracted ) : null;
	}

	private static function parts_text( array $parts ): string {
		$text = '';
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}
		return $text;
	}

	private static function sanitize_nulls( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( null === $value ) {
				$data[ $key ] = '';
			}
		}
		return $data;
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_AI_Log_Summarizer {

	public static function summarize( array $log ): array {
		$prompt = self::decode( $log['prompt_payload'] ?? '' );
		$response = self::decode( $log['response_payload'] ?? '' );
		$status = absint( $log['status_code'] ?? 0 );
		$available_tools = self::available_tools( $prompt );
		$tool_calls = self::tool_calls( $response );
		$error = (string) ( $response['error'] ?? '' );

		return array(
			'time' => (string) ( $log['time'] ?? '' ),
			'profile' => (string) ( $log['profile_id'] ?? '' ),
			'provider' => (string) ( $log['provider'] ?? '' ),
			'model' => (string) ( $log['model'] ?? '' ),
			'status' => $status ?: 'n/a',
			'tone' => self::tone( $status ),
			'title' => self::title( $status, $error, $tool_calls, $response ),
			'request' => self::request_text( $prompt ),
			'outcome' => $error ? self::clean( $error ) : self::outcome_text( $response ),
			'available_tools' => $available_tools,
			'tool_calls' => $tool_calls,
			'tokens' => self::tokens( $response ),
			'messages' => self::messages( $prompt ),
			'tool_details' => self::tool_details( $response ),
		);
	}

	private static function title( int $status, string $error, array $tool_calls, array $response ): string {
		if ( 429 === $status ) {
			return 'Rate limit or quota hit';
		}
		if ( $status >= 400 || $error ) {
			return 'Provider request failed';
		}
		if ( ! empty( $tool_calls ) ) {
			return 'Agent requested tools';
		}
		if ( self::json_content( $response ) ) {
			return 'Pre-flight decision';
		}
		return 'AI request completed';
	}

	private static function request_text( array $prompt ): string {
		$messages = array_reverse( $prompt['messages'] ?? array() );
		foreach ( $messages as $message ) {
			if ( 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}
			$content = (string) ( $message['content'] ?? '' );
			if ( 0 === strpos( trim( $content ), '[' ) ) {
				continue;
			}
			return self::extract_request( $content );
		}
		return 'Background AI request';
	}

	private static function extract_request( string $content ): string {
		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			foreach ( array( 'expanded_prompt', 'prompt', 'request', 'user_request', 'message' ) as $key ) {
				if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					return self::clean( $decoded[ $key ] );
				}
			}
		}

		if ( preg_match( '/Their request is:\s*[\'"](.+?)[\'"]\./s', $content, $match ) ) {
			return self::clean( $match[1] );
		}

		return self::clean( $content );
	}

	private static function outcome_text( array $response ): string {
		$json = self::json_content( $response );
		if ( $json ) {
			if ( array_key_exists( 'requires_plan', $json ) ) {
				return self::decision( 'Planning check', (bool) $json['requires_plan'], $json['reason'] ?? '' );
			}
			if ( array_key_exists( 'requires_layout_expansion', $json ) ) {
				return self::decision( 'Scope check', (bool) $json['requires_layout_expansion'], $json['reason'] ?? '' );
			}
			if ( isset( $json['reason'] ) ) {
				return self::clean( (string) $json['reason'] );
			}
		}

		$tool_calls = self::tool_calls( $response );
		if ( ! empty( $tool_calls ) ) {
			return 'Requested: ' . implode( ', ', $tool_calls );
		}

		$content = self::clean( (string) ( $response['content'] ?? '' ) );
		return '' === $content ? 'Completed with no visible text.' : $content;
	}

	private static function decision( string $label, bool $value, string $reason ): string {
		$result = $value ? 'yes' : 'no';
		$reason = self::clean( $reason );
		return $reason ? "{$label}: {$result}. {$reason}" : "{$label}: {$result}.";
	}

	private static function available_tools( array $prompt ): array {
		$tools = array();
		foreach ( $prompt['tools'] ?? array() as $tool ) {
			if ( ! empty( $tool['name'] ) ) {
				$tools[] = (string) $tool['name'];
			}
		}
		return array_values( array_unique( $tools ) );
	}

	private static function tool_calls( array $response ): array {
		$names = array();
		foreach ( $response['tool_calls'] ?? array() as $call ) {
			if ( ! empty( $call['name'] ) ) {
				$names[] = (string) $call['name'];
			}
		}
		return array_values( array_unique( $names ) );
	}

	private static function tool_details( array $response ): array {
		$details = array();
		foreach ( $response['tool_calls'] ?? array() as $call ) {
			$args = is_string( $call['args'] ?? null ) ? $call['args'] : wp_json_encode( $call['args'] ?? array() );
			$details[] = array(
				'name' => (string) ( $call['name'] ?? 'tool' ),
				'args' => self::clip( self::clean( $args ), 700 ),
			);
		}
		return $details;
	}

	private static function messages( array $prompt ): array {
		$messages = array();
		foreach ( array_slice( $prompt['messages'] ?? array(), -8 ) as $message ) {
			$messages[] = array(
				'role' => (string) ( $message['role'] ?? 'message' ),
				'content' => self::clip( self::extract_request( (string) ( $message['content'] ?? '' ) ), 700 ),
			);
		}
		return $messages;
	}

	private static function tokens( array $response ): string {
		$usage = $response['_wpaikits99_meta']['usage'] ?? array();
		$total = $usage['totalTokenCount'] ?? $usage['total_tokens'] ?? 0;
		return $total ? number_format_i18n( (int) $total ) . ' tokens' : 'Tokens n/a';
	}

	private static function json_content( array $response ): array {
		$content = trim( (string) ( $response['content'] ?? '' ) );
		$json = json_decode( $content, true );
		return is_array( $json ) ? $json : array();
	}

	private static function tone( int $status ): string {
		if ( 429 === $status ) {
			return 'warn';
		}
		return $status >= 200 && $status < 300 ? 'ok' : 'bad';
	}

	private static function decode( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function clean( string $text ): string {
		$text = preg_replace( '/\[(SCOPE CONTEXT|TARGET BLOCK JSON)\].*?\[\/\1\]/s', '', $text );
		$text = preg_replace( '/https?:\/\/\S+/i', '[url]', $text );
		$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) );
		return trim( $text );
	}

	private static function clip( string $text, int $length ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			return mb_substr( $text, 0, $length - 1 ) . '...';
		}
		return strlen( $text ) > $length ? substr( $text, 0, $length - 1 ) . '...' : $text;
	}
}

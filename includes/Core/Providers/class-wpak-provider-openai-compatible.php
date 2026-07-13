<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared implementation for OpenAI-compatible chat providers.
 *
 * OpenAI, Groq, and OpenRouter all speak the same /chat/completions dialect,
 * so they differ only by base URL, label, and a couple of headers. Each of
 * those providers is a thin wrapper that calls chat() with its own config.
 */
class WPAK_Provider_OpenAI_Compatible {

	/**
	 * @param array  $config      slug, label, base_url, key_option, headers.
	 * @return array|WP_Error
	 */
	public static function chat( array $config, string $model_name, array $messages, array $tools = array(), string $tool_choice = 'AUTO', string $api_key = '' ) {
		$label = $config['label'] ?? 'AI';
		$slug  = $config['slug'] ?? strtolower( $label );

		if ( '' === $api_key ) {
			$api_key = (string) get_option( $config['key_option'] ?? '', '' );
		}
		if ( '' === $api_key ) {
			return new WP_Error( 'no_key', $label . ' API key is missing.', array( 'status' => 400 ) );
		}

		$body = array(
			'model'       => $model_name,
			'messages'    => self::format_messages( $messages ),
			'temperature' => 0.4,
		);

		if ( ! empty( $tools ) ) {
			$body['tools'] = self::format_tools( $tools );
			if ( '' !== $tool_choice ) {
				$body['tool_choice'] = 'AUTO' === $tool_choice
					? 'auto'
					: array( 'type' => 'function', 'function' => array( 'name' => $tool_choice ) );
			}
		}

		WPAK_LLM_Debug::request( $slug, $model_name, $body );

		$headers = array_merge(
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			$config['headers'] ?? array()
		);

		$response = wp_remote_post(
			$config['base_url'],
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			WPAK_LLM_Debug::http_error( $slug, $response->get_error_message() );
			return new WP_Error( 'http_error', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$resp_body = json_decode( $raw_body, true );

		WPAK_LLM_Debug::response( $slug, $model_name, $code, $raw_body );

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limit', $label . ' rate limit exceeded.', array( 'status' => 429 ) );
		}
		if ( 200 !== $code ) {
			$error_msg = $resp_body['error']['message'] ?? 'Unknown API error';
			return new WP_Error( 'api_error', $label . ' Error: ' . $error_msg, array( 'status' => $code ) );
		}

		return self::format_response( $resp_body );
	}

	private static function format_messages( array $messages ): array {
		$formatted         = array();
		$tool_call_mapping = array();

		foreach ( $messages as $msg ) {
			$formatted_msg = array(
				'role'    => $msg['role'] ?? 'user',
				'content' => $msg['content'] ?? '',
			);

			if ( 'assistant' === ( $msg['role'] ?? '' ) && ! empty( $msg['tool_calls'] ) ) {
				$formatted_msg['tool_calls'] = array();
				foreach ( $msg['tool_calls'] as $tc ) {
					$call_id             = 'call_' . substr( md5( uniqid() ), 0, 8 );
					$tool_call_mapping[] = $call_id;
					$formatted_msg['tool_calls'][] = array(
						'id'       => $call_id,
						'type'     => 'function',
						'function' => array(
							'name'      => $tc['name'],
							'arguments' => is_string( $tc['args'] ) ? $tc['args'] : wp_json_encode( $tc['args'] ),
						),
					);
				}
			}

			// A "user" message that follows tool_calls and looks like a tool
			// result becomes an OpenAI "tool" message.
			if ( 'user' === ( $msg['role'] ?? '' ) && ! empty( $tool_call_mapping ) && 0 === strpos( (string) ( $msg['content'] ?? '' ), '[' ) ) {
				$call_id       = array_shift( $tool_call_mapping );
				$formatted_msg = array(
					'role'         => 'tool',
					'tool_call_id' => $call_id,
					'content'      => $msg['content'],
				);
			}

			$formatted[] = $formatted_msg;
		}

		return $formatted;
	}

	private static function format_tools( array $tools ): array {
		$openai_tools = array();
		foreach ( $tools as $t ) {
			$parameters = $t['parameters'] ?? new stdClass();
			self::lowercase_types( $parameters );
			$openai_tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $t['name'],
					'description' => $t['description'] ?? '',
					'parameters'  => $parameters,
				),
			);
		}
		return $openai_tools;
	}

	private static function format_response( array $resp_body ): array {
		$choice    = $resp_body['choices'][0]['message'] ?? array();
		$formatted = array(
			'role'       => 'assistant',
			'content'    => $choice['content'] ?? '',
			'tool_calls' => array(),
		);

		if ( ! empty( $choice['tool_calls'] ) ) {
			foreach ( $choice['tool_calls'] as $tc ) {
				// Some providers omit 'type', so we accept any call with a function.
				if ( ( isset( $tc['type'] ) && 'function' === $tc['type'] ) || isset( $tc['function'] ) ) {
					$formatted['tool_calls'][] = array(
						'name' => $tc['function']['name'],
						'args' => $tc['function']['arguments'],
					);
				}
			}
		}

		$formatted['_wpaikits99_meta'] = array(
			'finishReason' => $resp_body['choices'][0]['finish_reason'] ?? '',
			'usage'        => $resp_body['usage'] ?? array(),
		);

		return $formatted;
	}

	public static function lowercase_types( &$node ) {
		if ( is_array( $node ) ) {
			if ( isset( $node['type'] ) && is_string( $node['type'] ) ) {
				$node['type'] = strtolower( $node['type'] );
			}
			foreach ( $node as &$value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					self::lowercase_types( $value );
				}
			}
		} elseif ( is_object( $node ) ) {
			if ( isset( $node->type ) && is_string( $node->type ) ) {
				$node->type = strtolower( $node->type );
			}
			foreach ( get_object_vars( $node ) as $key => $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					self::lowercase_types( $node->$key );
				}
			}
		}
	}
}

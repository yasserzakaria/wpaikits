<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenRouter — one key, hundreds of models across every major provider. Speaks
 * the OpenAI dialect, so it rides the shared base. The referer/title headers
 * are OpenRouter's optional attribution fields.
 */
class WPAK_Provider_OpenRouter {

	const API_BASE = 'https://openrouter.ai/api/v1/chat/completions';

	public static function generate( $model_name, $messages, $tools = array(), $tool_choice = 'AUTO', $api_key = '' ) {
		return WPAK_Provider_OpenAI_Compatible::chat(
			array(
				'slug'       => 'openrouter',
				'label'      => 'OpenRouter',
				'base_url'   => self::API_BASE,
				'key_option' => 'wpaikits99_openrouter_api_key',
				'headers'    => array(
					'HTTP-Referer' => home_url(),
					'X-Title'      => 'WP AI Kits',
				),
			),
			(string) $model_name,
			(array) $messages,
			(array) $tools,
			(string) $tool_choice,
			(string) $api_key
		);
	}
}

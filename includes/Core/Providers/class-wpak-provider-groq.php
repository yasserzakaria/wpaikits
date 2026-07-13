<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Provider_Groq {

	const API_BASE = 'https://api.groq.com/openai/v1/chat/completions';

	public static function generate( $model_name, $messages, $tools = array(), $tool_choice = 'AUTO', $api_key = '' ) {
		return WPAK_Provider_OpenAI_Compatible::chat(
			array(
				'slug'       => 'groq',
				'label'      => 'Groq',
				'base_url'   => self::API_BASE,
				'key_option' => 'wpaikits99_groq_api_key',
			),
			(string) $model_name,
			(array) $messages,
			(array) $tools,
			(string) $tool_choice,
			(string) $api_key
		);
	}
}

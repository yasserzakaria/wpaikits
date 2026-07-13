<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Provider_OpenAI {

	const API_BASE = 'https://api.openai.com/v1/chat/completions';

	public static function generate( $model_name, $messages, $tools = array(), $tool_choice = 'AUTO', $api_key = '' ) {
		return WPAK_Provider_OpenAI_Compatible::chat(
			array(
				'slug'       => 'openai',
				'label'      => 'OpenAI',
				'base_url'   => self::API_BASE,
				'key_option' => 'wpaikits99_openai_api_key',
			),
			(string) $model_name,
			(array) $messages,
			(array) $tools,
			(string) $tool_choice,
			(string) $api_key
		);
	}
}

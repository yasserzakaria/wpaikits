<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAK_Provider_Mistral {

    const API_BASE = 'https://api.mistral.ai/v1/chat/completions';

    public static function lowercase_types( &$array ) {
        if ( is_array( $array ) ) {
            if ( isset( $array['type'] ) && is_string( $array['type'] ) ) {
                $array['type'] = strtolower( $array['type'] );
            }
            foreach ( $array as $key => &$value ) {
                if ( is_array( $value ) || is_object( $value ) ) {
                    self::lowercase_types( $value );
                }
            }
        } elseif ( is_object( $array ) ) {
            if ( isset( $array->type ) && is_string( $array->type ) ) {
                $array->type = strtolower( $array->type );
            }
            foreach ( get_object_vars( $array ) as $key => $value ) {
                if ( is_array( $value ) || is_object( $value ) ) {
                    self::lowercase_types( $array->$key );
                }
            }
        }
    }

    public static function generate( $model_name, $messages, $tools = array(), $tool_choice = 'AUTO', $api_key = '' ) {
        $api_key = '' !== $api_key ? $api_key : get_option( 'wpaikits99_mistral_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_key', 'Mistral API key is missing.', array( 'status' => 400 ) );
        }

        // Format messages for OpenAI standard
        $formatted_messages = array();
        $tool_call_mapping = array();

        foreach ( $messages as $idx => $msg ) {
            $formatted_msg = array(
                'role' => $msg['role'],
                'content' => $msg['content'] ?? ''
            );

            if ( $msg['role'] === 'assistant' && !empty( $msg['tool_calls'] ) ) {
                $formatted_msg['tool_calls'] = array();
                foreach ( $msg['tool_calls'] as $tc_idx => $tc ) {
                    $call_id = 'call_' . substr(md5(uniqid()), 0, 8);
                    $tool_call_mapping[] = $call_id; // Store to match next user message
                    
                    $formatted_msg['tool_calls'][] = array(
                        'id' => $call_id,
                        'type' => 'function',
                        'function' => array(
                            'name' => $tc['name'],
                            'arguments' => is_string($tc['args']) ? $tc['args'] : wp_json_encode($tc['args'])
                        )
                    );
                }
            }

            // Convert WPAK's "user" message after tool_calls into OpenAI's "tool" message
            if ( $msg['role'] === 'user' && !empty( $tool_call_mapping ) ) {
                if ( strpos( $msg['content'], '[' ) === 0 ) {
                    $call_id = array_shift( $tool_call_mapping );
                    $formatted_msg = array(
                        'role' => 'tool',
                        'tool_call_id' => $call_id,
                        'content' => $msg['content']
                    );
                }
            }

            $formatted_messages[] = $formatted_msg;
        }

        $body = array(
            'model' => $model_name,
            'messages' => $formatted_messages,
            'temperature' => 0.4
        );

        if ( !empty( $tools ) ) {
            $openai_tools = array();
            foreach ( $tools as $t ) {
                $parameters = $t['parameters'] ?? new stdClass();
                self::lowercase_types( $parameters );
                
                $openai_tools[] = array(
                    'type' => 'function',
                    'function' => array(
                        'name' => $t['name'],
                        'description' => $t['description'] ?? '',
                        'parameters' => $parameters
                    )
                );
            }
            $body['tools'] = $openai_tools;

            if ( !empty( $tool_choice ) ) {
                if ( $tool_choice === 'AUTO' ) {
                    $body['tool_choice'] = 'auto';
                } else {
                    $body['tool_choice'] = array(
                        'type' => 'function',
                        'function' => array( 'name' => $tool_choice )
                    );
                }
            }
        }

        WPAK_LLM_Debug::request( 'mistral', '', $body );

        $response = wp_remote_post( self::API_BASE, array(
            'headers' => array( 
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            WPAK_LLM_Debug::http_error( 'mistral', $response->get_error_message() );
            return new WP_Error( 'http_error', $response->get_error_message(), array('status'=>500) );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $resp_body = json_decode( $raw_body, true );

        WPAK_LLM_Debug::response( 'mistral', '', $code, $raw_body );

        if ( 429 === $code ) {
            return new WP_Error( 'rate_limit', 'Mistral Rate Limit Exceeded', array('status' => 429) );
        }

        if ( 200 !== $code ) {
            $error_msg = $resp_body['error']['message'] ?? 'Unknown API Error';
            return new WP_Error( 'api_error', "Mistral Error: " . $error_msg, array('status' => $code) );
        }

        $choice = $resp_body['choices'][0]['message'] ?? array();
        
        $formatted_response = array(
            'role' => 'assistant',
            'content' => $choice['content'] ?? '',
            'tool_calls' => array()
        );

        if ( !empty( $choice['tool_calls'] ) ) {
            foreach ( $choice['tool_calls'] as $tc ) {
                // Fallback: Mistral occasionally omits 'type', so we just ensure 'function' exists.
                if ( (isset($tc['type']) && $tc['type'] === 'function') || isset($tc['function']) ) {
                    $formatted_response['tool_calls'][] = array(
                        'name' => $tc['function']['name'],
                        'args' => $tc['function']['arguments']
                    );
                }
            }
        }

        $formatted_response['_wpaikits99_meta'] = array(
            'finishReason' => $resp_body['choices'][0]['finish_reason'] ?? '',
            'usage'        => $resp_body['usage'] ?? array(),
        );

        return $formatted_response;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAK_Provider_Gemini {

    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private static function cooldown_key( $model_name ) {
        return 'wpaikits99_gemini_model_cooldown_' . md5( (string) $model_name );
    }

    public static function generate( $model_name, $messages, $tools = array(), $tool_choice = 'AUTO', $api_key = '' ) {
        if ( get_transient( self::cooldown_key( $model_name ) ) ) {
            return new WP_Error(
                'rate_limited',
                sprintf( 'Gemini model %s is cooling down.', $model_name ),
                array( 'status' => 429 )
            );
        }

        $api_key = '' !== $api_key ? $api_key : get_option( 'wpaikits99_gemini_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_key', 'Gemini API key is missing.', array( 'status' => 400 ) );
        }

        $gemini_contents = array();
        $system_instruction = null;

        foreach ( $messages as $msg ) {
            $role = $msg['role'];
            $content = $msg['content'];

            if ( $role === 'system' ) {
                if ( ! $system_instruction ) {
                    $system_instruction = array( 'parts' => array() );
                }
                $system_instruction['parts'][] = array( 'text' => $content . "\n\n" );
            } else {
                $gemini_role = ( $role === 'assistant' ) ? 'model' : 'user';
                $gemini_contents[] = array(
                    'role'  => $gemini_role,
                    'parts' => array( array( 'text' => $content ) )
                );
            }
        }

        $body = array(
            'contents' => $gemini_contents,
            'generationConfig' => array( 
                'temperature' => 0.4
            )
        );

        // System Instruction
        if ( $system_instruction ) {
            $body['systemInstruction'] = $system_instruction;
        }

        // Tools
        if ( !empty( $tools ) ) {
            $body['tools'] = array( array( 'functionDeclarations' => $tools ) );
            if ( !empty( $tool_choice ) ) {
                if ( $tool_choice === 'AUTO' ) {
                    $body['toolConfig'] = array(
                        'functionCallingConfig' => array( 'mode' => 'AUTO' )
                    );
                } else {
                    $body['toolConfig'] = array(
                        'functionCallingConfig' => array(
                            'mode' => 'ANY',
                            'allowedFunctionNames' => array( $tool_choice )
                        )
                    );
                }
            }
        }

        $url = self::API_BASE . $model_name . ':streamGenerateContent?key=' . rawurlencode( $api_key );
        
        WPAK_LLM_Debug::request( 'gemini', $model_name, $body );

        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            WPAK_LLM_Debug::http_error( 'gemini', $response->get_error_message() );
            return new WP_Error( 'http_error', $response->get_error_message(), array('status'=>500) );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $resp_body = json_decode( $raw_body, true );

        WPAK_LLM_Debug::response( 'gemini', $model_name, $code, $raw_body );

        if ( in_array( $code, array( 429, 503 ), true ) ) {
            set_transient( self::cooldown_key( $model_name ), true, 60 );
            $error_msg = $resp_body['error']['message'] ?? 'Gemini Rate Limit Exceeded';
            return new WP_Error( 'rate_limit', "Gemini {$model_name}: " . $error_msg, array('status' => 429) );
        }

        if ( 200 !== $code ) {
            $error_msg = $resp_body['error']['message'] ?? 'Unknown API Error';
            return new WP_Error( 'api_error', "Gemini Error: " . $error_msg, array('status' => $code) );
        }

        if ( self::has_failed_finish_reason( $resp_body ) ) {
            return new WP_Error(
                'malformed_response',
                'Gemini returned an empty or malformed response.',
                array( 'status' => 500 )
            );
        }

        $formatted_response = array( 'role' => 'assistant', 'content' => '', 'tool_calls' => array() );

        if ( is_array( $resp_body ) && isset( $resp_body[0] ) ) {
            foreach ( $resp_body as $chunk ) {
                $parts = $chunk['candidates'][0]['content']['parts'] ?? array();
                foreach ( $parts as $part ) {
                    if ( ! empty( $part['thought'] ) ) {
                        continue;
                    }
                    if ( isset( $part['text'] ) ) {
                        $formatted_response['content'] .= $part['text'];
                    }
                    if ( isset( $part['functionCall'] ) ) {
                        $formatted_response['tool_calls'][] = array(
                            'name' => $part['functionCall']['name'],
                            'args' => wp_json_encode($part['functionCall']['args'])
                        );
                    }
                }
            }
        } else {
            $parts = $resp_body['candidates'][0]['content']['parts'] ?? array();
            foreach ( $parts as $part ) {
                if ( ! empty( $part['thought'] ) ) {
                    continue;
                }
                if ( isset( $part['text'] ) ) $formatted_response['content'] .= $part['text'];
                if ( isset( $part['functionCall'] ) ) {
                    $formatted_response['tool_calls'][] = array(
                        'name' => $part['functionCall']['name'],
                        'args' => wp_json_encode($part['functionCall']['args'])
                    );
                }
            }
        }

        $formatted_response['_wpaikits99_meta'] = self::response_meta( $resp_body );
        return $formatted_response;
    }

    private static function has_failed_finish_reason( $resp_body ) {
        $chunks = is_array( $resp_body ) && isset( $resp_body[0] ) ? $resp_body : array( $resp_body );
        $failed = array( 'MALFORMED_RESPONSE', 'SAFETY' );

        foreach ( $chunks as $chunk ) {
            $reason = $chunk['candidates'][0]['finishReason'] ?? '';
            if ( in_array( $reason, $failed, true ) ) {
                return true;
            }
        }

        return false;
    }

    private static function response_meta( $resp_body ) {
        $meta = array();
        $chunks = is_array( $resp_body ) && isset( $resp_body[0] ) ? $resp_body : array( $resp_body );

        foreach ( $chunks as $chunk ) {
            if ( ! is_array( $chunk ) ) {
                continue;
            }
            if ( isset( $chunk['usageMetadata'] ) ) {
                $meta['usage'] = $chunk['usageMetadata'];
            }
            if ( isset( $chunk['modelVersion'] ) ) {
                $meta['modelVersion'] = $chunk['modelVersion'];
            }
            if ( isset( $chunk['responseId'] ) ) {
                $meta['responseId'] = $chunk['responseId'];
            }
        }

        return $meta;
    }
}

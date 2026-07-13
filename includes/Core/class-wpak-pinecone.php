<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Pinecone {

	private const DEFAULT_INDEX = 'wpak-media-ai';
	private const DIMENSION = 768;

	public function register_hooks(): void {
		add_action( 'wp_ajax_wpaikits99_configure_pinecone', array( $this, 'ajax_configure_pinecone' ) );
	}

	public static function index_name(): string {
		return sanitize_key( get_option( 'wpaikits99_pinecone_index', self::DEFAULT_INDEX ) ?: self::DEFAULT_INDEX );
	}

	public static function credentials(): array {
		return array(
			'key'  => get_option( 'wpaikits99_pinecone_api_key', '' ),
			'host' => get_option( 'wpaikits99_pinecone_host', '' ),
		);
	}

	public static function upsert_attachment( int $attachment_id, array $vector ) {
		$config = self::credentials();
		if ( empty( $config['key'] ) || empty( $config['host'] ) ) {
			return new WP_Error( 'pinecone_missing_credentials', 'Pinecone API key or host is missing.' );
		}

		$response = wp_remote_post(
			rtrim( $config['host'], '/' ) . '/vectors/upsert',
			array(
				'headers' => self::headers( $config['key'] ),
				'body'    => wp_json_encode(
					array(
						'vectors' => array(
							array(
								'id'       => (string) $attachment_id,
								'values'   => array_values( $vector ),
								'metadata' => array( 'object_type' => 'attachment' ),
							),
						),
					)
				),
				'timeout' => 20,
			)
		);

		return self::assert_success( $response, 'Pinecone upsert failed.' );
	}

	public static function query( array $vector, int $top_k = 20 ) {
		$config = self::credentials();
		if ( empty( $config['key'] ) || empty( $config['host'] ) ) {
			return new WP_Error( 'pinecone_missing_credentials', 'Pinecone API key or host is missing.' );
		}

		$response = wp_remote_post(
			rtrim( $config['host'], '/' ) . '/query',
			array(
				'headers' => self::headers( $config['key'] ),
				'body'    => wp_json_encode(
					array(
						'vector'          => array_values( $vector ),
						'topK'            => max( 1, min( 50, $top_k ) ),
						'includeValues'   => false,
						'includeMetadata' => false,
					)
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'pinecone_error', 'Pinecone query returned HTTP ' . $code );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$matches = array();
		foreach ( $body['matches'] ?? array() as $match ) {
			$matches[ absint( $match['id'] ) ] = (float) $match['score'];
		}

		return $matches;
	}

	public static function delete_attachment( int $attachment_id ): void {
		$config = self::credentials();
		if ( empty( $config['key'] ) || empty( $config['host'] ) ) {
			return;
		}

		wp_remote_post(
			rtrim( $config['host'], '/' ) . '/vectors/delete',
			array(
				'headers' => self::headers( $config['key'] ),
				'body'    => wp_json_encode( array( 'ids' => array( (string) $attachment_id ) ) ),
				'timeout' => 15,
			)
		);
	}

	public function ajax_configure_pinecone(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$index = sanitize_key( wp_unslash( $_POST['index'] ?? self::DEFAULT_INDEX ) ) ?: self::DEFAULT_INDEX;
		if ( '' === $api_key ) {
			wp_send_json_error( 'Please provide a Pinecone API key.' );
		}

		update_option( 'wpaikits99_pinecone_api_key', $api_key, false );
		update_option( 'wpaikits99_pinecone_index', $index, false );

		$created = $this->ensure_index( $api_key, $index );
		if ( is_wp_error( $created ) ) {
			wp_send_json_error( $created->get_error_message() );
		}

		update_option( 'wpaikits99_pinecone_host', $created, false );
		wp_send_json_success(
			array(
				'message' => 'Pinecone database configured successfully.',
				'host'    => $created,
				'index'   => $index,
			)
		);
	}

	private function ensure_index( string $api_key, string $index ) {
		$response = wp_remote_post(
			'https://api.pinecone.io/indexes',
			array(
				'headers' => self::headers( $api_key ),
				'body'    => wp_json_encode(
					array(
						'name'      => $index,
						'dimension' => self::DIMENSION,
						'metric'    => 'cosine',
						'spec'      => array(
							'serverless' => array( 'cloud' => 'aws', 'region' => 'us-east-1' ),
						),
					)
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, array( 201, 409 ), true ) ) {
			return new WP_Error( 'pinecone_create_failed', 'Pinecone returned HTTP ' . $code );
		}

		return $this->fetch_host( $api_key, $index );
	}

	private function fetch_host( string $api_key, string $index ) {
		$response = wp_remote_get(
			'https://api.pinecone.io/indexes/' . rawurlencode( $index ),
			array( 'headers' => array( 'Api-Key' => $api_key ), 'timeout' => 20 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['host'] ) ) {
			return new WP_Error( 'pinecone_host_missing', 'Pinecone did not return a host URL.' );
		}

		return esc_url_raw( 0 === strpos( $body['host'], 'http' ) ? $body['host'] : 'https://' . $body['host'] );
	}

	private static function headers( string $api_key ): array {
		return array( 'Api-Key' => $api_key, 'Content-Type' => 'application/json' );
	}

	private static function assert_success( $response, string $message ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'pinecone_error', $message . ' HTTP ' . $code );
		}

		return true;
	}
}

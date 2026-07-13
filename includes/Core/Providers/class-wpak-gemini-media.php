<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Gemini_Media {

	private const MAX_IMAGE_SIZE = 1048576;
	private const RESIZE_WIDTH = 500;

	public static function generate_media_metadata( string $file_path, array $profile = array() ) {
		$image = self::image_part( $file_path );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => self::metadata_prompt() ),
						array( 'inline_data' => $image ),
					),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'object',
					'properties' => array(
						'alt_text'    => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'alt_text', 'title', 'description' ),
				),
			),
		);

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$response = WPAK_Gemini_API::post( self::profile_model( $profile ) . ':generateContent', $body, 90, self::profile_key( $profile ), false );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = WPAK_Gemini_Response::parse_json( WPAK_Gemini_Response::extract_text( $response ) );
			if ( is_array( $data ) && isset( $data['alt_text'], $data['title'], $data['description'] )
				&& '' !== trim( (string) $data['alt_text'] ) ) {
				return $data;
			}
		}

		return new WP_Error( 'metadata_parse_failed', 'Gemini did not return valid media metadata.' );
	}

	public static function embed_image( string $file_path, array $profile = array() ) {
		$image = self::image_part( $file_path );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		return self::embedding_from_parts( array( array( 'inline_data' => $image ) ), $profile );
	}

	public static function embed_text( string $text, array $profile = array() ) {
		return self::embedding_from_parts(
			array( array( 'text' => sanitize_text_field( $text ) ) ),
			$profile
		);
	}

	public static function rank_media_candidates( string $query, array $candidates, array $profile = array() ) {
		$parts = array(
			array(
				'text' => self::ranking_prompt( $query, $candidates ),
			),
		);

		foreach ( array_slice( $candidates, 0, 6 ) as $candidate ) {
			$image = self::image_part( (string) ( $candidate['file_path'] ?? '' ) );
			if ( is_wp_error( $image ) ) {
				continue;
			}

			$parts[] = array( 'text' => 'Candidate ID: ' . absint( $candidate['id'] ) );
			$parts[] = array( 'inline_data' => $image );
		}

		$response = WPAK_Gemini_API::post(
			self::profile_model( $profile ) . ':generateContent',
			array(
				'contents'         => array( array( 'parts' => $parts ) ),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
					'responseSchema'   => self::ranking_schema(),
				),
			),
			90,
			self::profile_key( $profile ),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = WPAK_Gemini_Response::parse_json( WPAK_Gemini_Response::extract_text( $response ) );
		return is_array( $data ) ? $data : new WP_Error( 'ranking_parse_failed', 'Could not parse Gemini visual ranking.' );
	}

	private static function embedding_from_parts( array $parts, array $profile = array() ) {
		$response = WPAK_Gemini_API::post(
			WPAK_Gemini_API::MODEL_EMBEDDING . ':embedContent',
			array(
				'model'                => 'models/' . WPAK_Gemini_API::MODEL_EMBEDDING,
				'content'              => array( 'parts' => $parts ),
				'output_dimensionality' => WPAK_Gemini_API::VECTOR_DIMENSIONS,
			),
			60,
			self::profile_key( $profile ),
			false
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['embedding']['values'] ) || ! is_array( $response['embedding']['values'] ) ) {
			return new WP_Error( 'embedding_parse_failed', 'Could not parse Gemini embedding.' );
		}

		return array_map( 'floatval', $response['embedding']['values'] );
	}

	public static function default_metadata_instructions(): string {
		return 'Alt text: Write concise, objective screen-reader text under 125 characters. Describe the main subject, action, and meaningful details. Do not start with "Image of" or "Photo of". Do not add keywords, opinions, or details that are not clearly visible.

Title: Write a clear 2-5 word descriptive title in title case. Use the main subject or scene; avoid generic titles such as "Image" or "Photo".

Description: Write a factual 2-3 sentence description for the WordPress media library. Include notable subjects, actions, setting, and visible details that make the image easier to find later. Do not invent information or repeat the title word-for-word.';
	}

	public static function default_metadata_prompt(): string {
		return self::default_metadata_instructions();
	}

	public static function get_metadata_instructions(): string {
		return self::normalize_metadata_instructions(
			(string) get_option( 'wpaikits99_archivist_metadata_prompt', '' )
		);
	}

	public static function normalize_metadata_instructions( string $instructions ): string {
		$instructions = trim( $instructions );
		return '' === $instructions || self::is_legacy_full_prompt( $instructions ) || self::is_previous_default_instructions( $instructions )
			? self::default_metadata_instructions()
			: $instructions;
	}

	public static function metadata_prompt(): string {
		$instructions = self::get_metadata_instructions();

		return 'Analyze this image for a WordPress media library.

Use these writing instructions:
' . $instructions . '

Return valid JSON only with exactly these keys: alt_text, title, description.
Do not include folders, categories, tags, markdown, comments, or extra keys.';
	}

	/**
	 * Read and base64-encode an image for vision providers.
	 *
	 * @return array|WP_Error array( 'mime_type' => string, 'data' => string )
	 */
	public static function image_inline_data( string $file_path ) {
		return self::image_part( $file_path );
	}

	private static function is_legacy_full_prompt( string $instructions ): bool {
		return false !== strpos( $instructions, 'Analyze this image for a WordPress media library' )
			|| false !== strpos( $instructions, 'Return JSON only' )
			|| false !== strpos( $instructions, 'Do not include folders' );
	}

	private static function is_previous_default_instructions( string $instructions ): bool {
		return 'Alt text: concise, objective screen-reader text under 125 characters.
Title: a short 2-4 word descriptive title.
Description: a detailed visual description in 2-3 sentences.' === $instructions;
	}

	private static function ranking_prompt( string $query, array $candidates ): string {
		$lines = array_map(
			static function ( array $candidate ): string {
				return sprintf(
					'ID %d: title="%s", alt="%s", description="%s", semantic_score=%s',
					absint( $candidate['id'] ?? 0 ),
					sanitize_text_field( $candidate['title'] ?? '' ),
					sanitize_text_field( $candidate['alt'] ?? '' ),
					sanitize_text_field( $candidate['description'] ?? '' ),
					sanitize_text_field( (string) ( $candidate['score'] ?? '' ) )
				);
			},
			array_slice( $candidates, 0, 6 )
		);

		return 'Rank these WordPress media images for this use: "' . sanitize_text_field( $query ) . '".
Use both the visual image content and metadata. Return JSON only.
Candidates:
' . implode( "\n", $lines );
	}

	private static function ranking_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'rankings' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'         => array( 'type' => 'integer' ),
							'rank'       => array( 'type' => 'integer' ),
							'suitable'   => array( 'type' => 'boolean' ),
							'fit_reason' => array( 'type' => 'string' ),
						),
						'required'   => array( 'id', 'rank', 'suitable', 'fit_reason' ),
					),
				),
			),
			'required'   => array( 'rankings' ),
		);
	}

	private static function image_part( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'Image file not found.' );
		}

		$data = filesize( $file_path ) > self::MAX_IMAGE_SIZE
			? self::resized_image_data( $file_path )
			: file_get_contents( $file_path );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( false === $data ) {
			return new WP_Error( 'read_error', 'Could not read image file.' );
		}

		$type = wp_check_filetype( $file_path )['type'] ?? 'image/jpeg';
		return array( 'mime_type' => $type ?: 'image/jpeg', 'data' => base64_encode( $data ) );
	}

	private static function profile_key( array $profile ): string {
		return (string) ( $profile['apiKey'] ?? '' );
	}

	private static function profile_model( array $profile ): string {
		return (string) ( $profile['model'] ?? WPAK_Gemini_API::MODEL_ALT_TEXT );
	}

	private static function resized_image_data( string $file_path ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->resize( self::RESIZE_WIDTH, null, false );
		$temp = wp_tempnam( wp_basename( $file_path ) );
		if ( ! $temp ) {
			return new WP_Error( 'temp_file_error', 'Could not create a temporary image file.' );
		}
		$saved = $editor->save( $temp, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $temp );
			return $saved;
		}

		$data = file_get_contents( $saved['path'] );
		wp_delete_file( $saved['path'] );
		wp_delete_file( $temp );

		return $data;
	}
}

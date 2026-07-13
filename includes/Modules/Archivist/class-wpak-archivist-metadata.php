<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Metadata {

	/**
	 * @return array|WP_Error
	 */
	public static function process( int $attachment_id, string $file_path ) {
		$fields = self::fields_to_write( $attachment_id );
		if ( empty( $fields ) ) {
			return array( 'Alt' => 'Already Filled', 'Title' => 'Already Filled', 'Desc' => 'Already Filled' );
		}

		$metadata = WPAK_LLM_Proxy::generate_media_metadata( $file_path );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$saved = self::save_fields( $attachment_id, $metadata, $fields );
		return self::status_labels( $fields, $saved );
	}

	private static function fields_to_write( int $attachment_id ): array {
		$post = get_post( $attachment_id );

		return array_filter(
			array(
				'Alt'   => self::field_policy(
					'wpaikits99_archivist_override_alt',
					(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
				),
				'Title' => self::field_policy( 'wpaikits99_archivist_override_title', (string) ( $post->post_title ?? '' ) ),
				'Desc'  => self::field_policy( 'wpaikits99_archivist_override_description', (string) ( $post->post_content ?? '' ) ),
			)
		);
	}

	private static function field_policy( string $override_option, string $current_value ): string {
		$has_value = '' !== trim( wp_strip_all_tags( $current_value ) );
		if ( ! $has_value ) {
			return 'empty';
		}

		return '1' === get_option( $override_option, '0' ) ? 'override' : '';
	}

	/**
	 * Writes each intended field only when the AI actually returned a value, and
	 * reports back which fields truly persisted so the log tells the truth.
	 *
	 * @return array<string,bool> label => saved
	 */
	private static function save_fields( int $attachment_id, array $metadata, array $fields ): array {
		$saved = array();

		$alt = trim( (string) ( $metadata['alt_text'] ?? '' ) );
		if ( isset( $fields['Alt'] ) && '' !== $alt ) {
			WPAK_DB::save_alt_text( $attachment_id, $alt );
			$saved['Alt'] = ( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) === sanitize_text_field( $alt ) );
		}

		$update = array( 'ID' => $attachment_id );
		$title  = trim( (string) ( $metadata['title'] ?? '' ) );
		$desc   = trim( (string) ( $metadata['description'] ?? '' ) );
		if ( isset( $fields['Title'] ) && '' !== $title ) {
			$update['post_title'] = sanitize_text_field( $title );
		}
		if ( isset( $fields['Desc'] ) && '' !== $desc ) {
			$update['post_content'] = sanitize_textarea_field( $desc );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			$ok     = ! is_wp_error( $result ) && $result;
			if ( isset( $update['post_title'] ) ) {
				$saved['Title'] = $ok;
			}
			if ( isset( $update['post_content'] ) ) {
				$saved['Desc'] = $ok;
			}
		}

		return $saved;
	}

	private static function status_labels( array $fields, array $saved ): array {
		$labels = array( 'Alt' => 'Already Filled', 'Title' => 'Already Filled', 'Desc' => 'Already Filled' );
		foreach ( $fields as $label => $reason ) {
			if ( empty( $saved[ $label ] ) ) {
				$labels[ $label ] = 'No AI Value';
				continue;
			}
			$labels[ $label ] = 'override' === $reason ? 'Overwritten' : 'Generated';
		}
		return $labels;
	}

}

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Image {

	private const PREFERRED_SIZES = array( 'large', 'medium_large', 'medium' );

	public static function ai_source( int $attachment_id, string $original_path ): string {
		$uploads = wp_get_upload_dir();
		$base_dir = (string) ( $uploads['basedir'] ?? '' );
		if ( '' === $base_dir ) {
			return $original_path;
		}

		foreach ( self::PREFERRED_SIZES as $size ) {
			$image = image_get_intermediate_size( $attachment_id, $size );
			$relative = is_array( $image ) ? (string) ( $image['path'] ?? $image['file'] ?? '' ) : '';
			if ( '' === $relative ) {
				continue;
			}

			$path = trailingslashit( $base_dir ) . ltrim( $relative, '/\\' );
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return $original_path;
	}
}

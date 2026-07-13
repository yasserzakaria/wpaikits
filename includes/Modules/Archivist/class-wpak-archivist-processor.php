<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Processor {

	private const RETRY_DELAY = 90;

	public function process( int $attachment_id, $priority = false ): void {
		$priority = WPAK_Queue::is_priority_arg( $priority );
		try {
			if ( ! $priority && WPAK_Queue::is_paused() ) {
				WPAK_Archivist_Debug::log( 'Skipped a claimed image job because sync is paused.', array( 'attachment_id' => $attachment_id ) );
				return;
			}

			WPAK_Queue::guard_global_cooldown( $attachment_id );
			$this->process_attachment( $attachment_id );
		} catch ( Throwable $error ) {
			WPAK_Archivist_Debug::log(
				'Unhandled processing failure.',
				array( 'attachment_id' => $attachment_id, 'error' => $error->getMessage(), 'type' => get_class( $error ) )
			);
			WPAK_Archivist_Logger::log( 'Processing failed: ' . $error->getMessage(), 'error', $attachment_id );
			WPAK_Queue::retry_attachment( $attachment_id, self::RETRY_DELAY );
		}
	}

	private function process_attachment( int $attachment_id ): void {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			WPAK_Archivist_Logger::log( 'Skipped missing attachment file.', 'error', $attachment_id );
			return;
		}
		$ai_file_path = WPAK_Archivist_Image::ai_source( $attachment_id, $file_path );
		WPAK_Archivist_Debug::log( 'Selected image source.', array( 'attachment_id' => $attachment_id, 'path' => $ai_file_path ) );

		$indexing_available = (bool) apply_filters( 'wpaikits99_is_pro', false );
		$status = array( 'Alt' => 'Skipped', 'Title' => 'Skipped', 'Desc' => 'Skipped' );
		if ( $indexing_available ) {
			$status['Vector'] = 'Skipped';
		}
		$retry = false;
		$had_error = false;

		if ( '1' === get_option( 'wpaikits99_archivist_auto_alt', '1' ) ) {
			$metadata = WPAK_Archivist_Metadata::process( $attachment_id, $ai_file_path );
			if ( is_wp_error( $metadata ) ) {
				$status['Alt'] = $status['Title'] = $status['Desc'] = 'Failed';
				$had_error = true;
				$retry = $this->record_error( 'Metadata failed', $metadata, $attachment_id ) || $retry;
			} else {
				$status = array_merge( $status, $metadata );
			}
		}

		if ( $indexing_available && '1' === get_option( 'wpaikits99_archivist_auto_index', '1' ) ) {
			$index = $this->index_attachment( $attachment_id, $ai_file_path );
			if ( is_wp_error( $index ) ) {
				$status['Vector'] = 'Failed';
				$had_error = true;
				$retry = $this->record_error( 'Indexing failed', $index, $attachment_id ) || $retry;
			} else {
				$status['Vector'] = $index;
			}
		}

		if ( $retry ) {
			WPAK_Queue::retry_attachment( $attachment_id, self::RETRY_DELAY );
		}
		if ( ! $had_error ) {
			WPAK_DB::mark_processed( $attachment_id );
		}

		WPAK_Archivist_Logger::log( $this->format_status( $status ), $had_error ? 'error' : 'success', $attachment_id );
	}

	private function index_attachment( int $attachment_id, string $file_path ) {
		if ( WPAK_DB::is_indexed( $attachment_id ) ) {
			return 'Already Indexed';
		}

		$pinecone = WPAK_Pinecone::credentials();
		if ( empty( $pinecone['key'] ) || empty( $pinecone['host'] ) ) {
			return 'Skipped (No Pinecone Setup)';
		}

		$vector = WPAK_LLM_Proxy::embed_image( $file_path );
		if ( is_wp_error( $vector ) ) {
			return $vector;
		}

		$saved = WPAK_Pinecone::upsert_attachment( $attachment_id, $vector );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WPAK_DB::mark_indexed( $attachment_id );
		return 'Processed';
	}

	private function record_error( string $label, WP_Error $error, int $attachment_id ): bool {
		WPAK_Archivist_Debug::log(
			$label,
			array(
				'attachment_id' => $attachment_id,
				'code'          => $error->get_error_code(),
				'message'       => $error->get_error_message(),
				'data'          => $error->get_error_data(),
			)
		);
		WPAK_Archivist_Logger::log( self::humanize_error( $error ), 'error', $attachment_id );
		return $this->is_retryable( $error );
	}

	private function is_retryable( WP_Error $error ): bool {
		$data = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
		$permanent = array( 'missing_media_profile', 'no_profile_key', 'invalid_key', 'invalid_key_file' );

		return ! in_array( $error->get_error_code(), $permanent, true )
			&& ( 0 === $status || in_array( $status, array( 408, 429, 500, 502, 503, 504 ), true ) );
	}

	private function format_status( array $status ): string {
		$names     = array( 'Alt' => 'alt text', 'Title' => 'title', 'Desc' => 'description', 'Vector' => 'search index' );
		$written   = array();
		$protected = array();

		foreach ( $status as $label => $value ) {
			$name = $names[ $label ] ?? strtolower( (string) $label );
			if ( in_array( $value, array( 'Generated', 'Overwritten', 'Processed' ), true ) ) {
				$written[] = $name;
			} elseif ( 'Already Filled' === $value ) {
				$protected[] = $name;
			}
		}

		if ( ! empty( $written ) ) {
			$message = 'Added ' . self::humanize_list( $written );
			if ( ! empty( $protected ) ) {
				$message .= ', kept your existing ' . self::humanize_list( $protected );
			}
			return $message;
		}

		if ( ! empty( $protected ) ) {
			return 'Existing metadata kept — nothing needed writing';
		}

		return 'No changes were needed';
	}

	private static function humanize_list( array $items ): string {
		$items = array_values( $items );
		$count = count( $items );
		if ( $count <= 1 ) {
			return (string) ( $items[0] ?? '' );
		}
		if ( 2 === $count ) {
			return $items[0] . ' and ' . $items[1];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ', and ' . $last;
	}

	private static function humanize_error( WP_Error $error ): string {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
		$code   = $error->get_error_code();

		if ( in_array( $code, array( 'no_profile_key', 'invalid_key', 'invalid_key_file', 'no_key' ), true ) ) {
			return 'API key needs attention';
		}
		if ( 'missing_media_profile' === $code ) {
			return 'No AI provider is connected yet';
		}
		if ( in_array( $status, array( 429, 503 ), true ) || in_array( $code, array( 'rate_limit', 'rate_limited' ), true ) ) {
			return 'Waiting for free provider capacity — will try again automatically';
		}
		return 'Temporary provider error — retrying';
	}
}

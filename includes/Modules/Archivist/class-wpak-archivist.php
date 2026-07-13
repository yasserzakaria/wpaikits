<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-logger.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-debug.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-image.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-metadata.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-processor.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-rest.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-search.php';
require_once WPAK_PATH . 'includes/Modules/Archivist/class-wpak-archivist-sync.php';

class WPAK_Module_Archivist extends WPAK_Module {

	public function __construct() {
		parent::__construct( 'archivist' );
	}

	public function register_hooks(): void {
		add_action( 'add_attachment', array( $this, 'queue_new_attachment' ) );
		add_action( 'delete_attachment', array( $this, 'delete_attachment' ) );
		$processor = new WPAK_Archivist_Processor();
		add_action( WPAK_Queue::HOOK_PROCESS_IMAGE, array( $processor, 'process' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_modal' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_attachment_status', array( $this, 'ajax_attachment_status' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_generate_single', array( $this, 'ajax_generate_single' ) );
		$sync = new WPAK_Archivist_Sync();
		$sync->register_hooks();

		$search = new WPAK_Archivist_Search();
		$search->register_hooks();

		$rest = new WPAK_Archivist_REST();
		$rest->register_hooks();
	}

	public function queue_new_attachment( int $attachment_id ): void {
		if ( ! wp_attachment_is_image( $attachment_id ) || ! $this->is_processing_enabled() ) {
			return;
		}

		if ( WPAK_Queue::enqueue_attachment( $attachment_id ) ) {
			WPAK_Archivist_Logger::log( 'Queued new upload for Media AI Kit processing.', 'processing', $attachment_id );
		}
	}

	public function delete_attachment( int $attachment_id ): void {
		WPAK_DB::delete_tracking( $attachment_id );
		WPAK_Pinecone::delete_attachment( $attachment_id );
	}

	public function enqueue_media_modal( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'upload.php', 'toplevel_page_wpaikits' ), true ) ) {
			return;
		}

		if ( empty( WPAK_AI_Profiles::media_routes() ) ) {
			return;
		}

		wp_enqueue_media();

		// Watch new uploads for live metadata, and add the per-image
		// "Generate with AI" button in Attachment Details.
		wp_enqueue_script(
			'wpak-archivist-media-uploads',
			WPAK_URL . 'includes/Modules/Archivist/assets/wpak-media-uploads.js',
			array( 'jquery', 'media-views' ),
			self::asset_version( 'includes/Modules/Archivist/assets/wpak-media-uploads.js' ),
			true
		);
		wp_localize_script(
			'wpak-archivist-media-uploads',
			'wpakArchivistUploads',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wpaikits99_archivist_uploads' ),
				// Auto-watching uploads only matters when auto-describe is on;
				// the manual per-image button works either way.
				'autoWatch' => get_option( 'wpaikits99_archivist_auto_alt', '1' ),
			)
		);

		// Pro: semantic search patch inside the media modal.
		if ( WPAK_Archivist_Search::semantic_search_allowed() ) {
			wp_enqueue_script(
				'wpak-archivist-media-modal',
				WPAK_URL . 'includes/Modules/Archivist/assets/wpak-media-modal.js',
				array( 'jquery', 'media-views' ),
				WPAK_VERSION,
				true
			);
			wp_localize_script(
				'wpak-archivist-media-modal',
				'wpakArchivistMediaSearch',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wpaikits99_archivist_media_search' ),
				)
			);
		}
	}

	private static function asset_version( string $relative_path ): string {
		$path = WPAK_PATH . $relative_path;
		return file_exists( $path ) ? (string) filemtime( $path ) : WPAK_VERSION;
	}

	public function ajax_attachment_status(): void {
		check_ajax_referer( 'wpaikits99_archivist_uploads', 'nonce' );
		if ( ! WPAK_AI_Access::can_use_ai() ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_filter( array_unique( $ids ) ), 0, 20 );

		$items = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$items[ $id ] = array(
				'processed'   => WPAK_DB::is_processed( $id ),
				'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title'       => get_the_title( $id ),
				'caption'     => (string) wp_get_attachment_caption( $id ),
				'description' => wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Generate metadata for one image on demand, synchronously. Uses the same
	 * pipeline as the queue, so overwrite rules and provider fallbacks apply.
	 */
	public function ajax_generate_single(): void {
		check_ajax_referer( 'wpaikits99_archivist_uploads', 'nonce' );
		if ( ! WPAK_AI_Access::can_use_ai() ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$attachment_id = absint( $_POST['id'] ?? 0 );
		if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_send_json_error( 'Only images can get AI metadata.', 400 );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_send_json_error( 'The image file could not be found on the server.', 404 );
		}

		$ai_path = WPAK_Archivist_Image::ai_source( $attachment_id, $file_path );
		$result  = WPAK_Archivist_Metadata::process( $attachment_id, $ai_path );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 500 );
		}

		WPAK_DB::mark_processed( $attachment_id );

		$wrote = (bool) array_intersect( $result, array( 'Generated', 'Overwritten' ) );
		WPAK_Archivist_Logger::log(
			$wrote ? 'Metadata generated on demand.' : 'Existing metadata kept — nothing needed writing.',
			'success',
			$attachment_id
		);

		wp_send_json_success(
			array(
				'wrote'       => $wrote,
				'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'title'       => get_the_title( $attachment_id ),
				'caption'     => (string) wp_get_attachment_caption( $attachment_id ),
				'description' => wp_strip_all_tags( (string) get_post_field( 'post_content', $attachment_id ) ),
			)
		);
	}

	public function ajax_save_settings(): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		update_option( 'wpaikits99_archivist_auto_alt', $this->posted_bool( 'auto_alt' ), false );
		update_option( 'wpaikits99_archivist_auto_index', $this->posted_bool( 'auto_index' ), false );
		update_option( 'wpaikits99_archivist_full_speed', $this->posted_bool( 'full_speed' ), false );
		update_option( 'wpaikits99_archivist_override_alt', $this->posted_bool( 'override_alt' ), false );
		update_option( 'wpaikits99_archivist_override_title', $this->posted_bool( 'override_title' ), false );
		update_option( 'wpaikits99_archivist_override_description', $this->posted_bool( 'override_description' ), false );
		update_option( 'wpaikits99_archivist_threshold', $this->posted_threshold(), false );
		update_option( 'wpaikits99_archivist_metadata_prompt', $this->posted_metadata_prompt(), false );
		update_option( 'wpaikits99_media_ai_profile', WPAK_AI_Profiles::sanitize_media_profile_id( $_POST['media_ai_profile'] ?? '' ), false );
		update_option( 'wpaikits99_pinecone_api_key', $this->posted_secret( 'pinecone_key', 'wpaikits99_pinecone_api_key' ), false );
		update_option( 'wpaikits99_pinecone_host', esc_url_raw( wp_unslash( $_POST['pinecone_host'] ?? '' ) ), false );
		update_option( 'wpaikits99_pinecone_index', sanitize_key( wp_unslash( $_POST['pinecone_index'] ?? 'wpak-media-ai' ) ), false );

		wp_send_json_success( 'Media AI Kit settings saved.' );
	}

	private function is_processing_enabled(): bool {
		return '1' === get_option( 'wpaikits99_archivist_auto_index', '1' ) || '1' === get_option( 'wpaikits99_archivist_auto_alt', '1' );
	}

	private function posted_bool( string $key ): string {
		return '1' === sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '0' ) ) ? '1' : '0';
	}

	private function posted_threshold(): string {
		$value = (float) sanitize_text_field( wp_unslash( $_POST['threshold'] ?? '0.25' ) );
		return (string) min( 1, max( 0.05, $value ) );
	}

	private function posted_metadata_prompt(): string {
		$prompt = sanitize_textarea_field( wp_unslash( $_POST['metadata_prompt'] ?? '' ) );
		return WPAK_Gemini_Media::normalize_metadata_instructions( $prompt );
	}

	private function posted_secret( string $key, string $option ): string {
		$value = trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		return '' === $value ? (string) get_option( $option, '' ) : $value;
	}

}

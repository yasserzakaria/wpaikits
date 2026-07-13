<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Sync {

	public function register_hooks(): void {
		add_action( 'wp_ajax_wpaikits99_archivist_start_bulk_sync', array( $this, 'start' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_reprocess_metadata', array( $this, 'reprocess' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_pause_sync', array( $this, 'pause' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_resume_sync', array( $this, 'resume' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_sync_status', array( $this, 'status' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_clear_logs', array( $this, 'clear_logs' ) );
	}

	public function start(): void {
		$this->respond( 'start', static function (): array {
			delete_option( 'wpaikits99_archivist_sync_paused' );
			$count = WPAK_Queue::schedule_bulk_sync();
			$pending = WPAK_Queue::pending_count();
			$message = $count ? sprintf( 'Scheduled %d images for background processing.', $count ) : 'All images are already indexed.';
			if ( 0 === $count && $pending > 0 ) {
				$message = 'The Media AI Kit queue is already running.';
			}

			return array( 'message' => $message, 'count' => $count );
		} );
	}

	public function reprocess(): void {
		$this->respond( 'reprocess', static function (): array {
			delete_option( 'wpaikits99_archivist_sync_paused' );
			$count = WPAK_Queue::schedule_metadata_refresh();
			$message = $count ? sprintf( 'Scheduled %d images for metadata reprocessing.', $count ) : 'No additional images were queued.';

			return array( 'message' => $message, 'count' => $count );
		} );
	}

	public function pause(): void {
		$this->respond( 'pause', static function (): array {
			$cancelled = WPAK_Queue::pause_sync();
			WPAK_Archivist_Logger::log( sprintf( 'Sync paused. Cancelled %d pending jobs.', $cancelled ), 'info' );

			return array( 'message' => 'Sync paused. You can resume it later.', 'count' => $cancelled );
		} );
	}

	public function resume(): void {
		$this->respond( 'resume', static function (): array {
			$count = WPAK_Queue::resume_sync();
			WPAK_Archivist_Logger::log( sprintf( 'Sync resumed with %d queued jobs.', $count ), 'processing' );

			return array( 'message' => sprintf( 'Sync resumed with %d queued jobs.', $count ), 'count' => $count );
		} );
	}

	public function status(): void {
		$this->respond( 'status', static function (): array {
			self::backfill_processed_count();
			$processed = WPAK_DB::get_processed_count();
			$total = WPAK_DB::get_total_image_count();
			$paused = WPAK_Queue::is_paused();
			$pending = $paused ? 0 : WPAK_Queue::pending_count();
			$active = $paused ? 0 : WPAK_Queue::active_count();
			$remaining = max( 0, $total - $processed );

			// Overdue pending work means WP-Cron is not firing. This admin
			// visit is itself the fix: kick the runner so the queue resumes,
			// and tell the UI so we can explain what happened.
			$cron_stalled = ! $paused && $pending > 0 && WPAK_Queue::is_stalled();
			if ( $cron_stalled ) {
				WPAK_Queue::kick();
			}

			return array(
				'cronStalled' => $cron_stalled,
				'processed' => $processed,
				'indexed'  => WPAK_DB::get_indexed_count(),
				'total'    => $total,
				'pending'  => $pending,
				'active'   => $active,
				'syncing'  => ! $paused && $active > 0,
				'paused'   => $paused,
				'incomplete' => ! $paused && 0 === $active && $remaining > 0,
				'mode'     => WPAK_Queue::mode(),
				'cooldown' => (bool) get_transient( 'wpaikits99_global_cooldown' ),
				'percent'  => $total > 0 ? round( ( $processed / $total ) * 100 ) : 0,
				'logs'     => WPAK_Archivist_Logger::get_logs(),
			);
		} );
	}

	private static function backfill_processed_count(): void {
		if ( get_option( 'wpaikits99_archivist_processed_backfilled', false ) ) {
			return;
		}

		foreach ( WPAK_Archivist_Logger::get_logs() as $log ) {
			if ( 'success' === ( $log['status'] ?? '' ) && ! empty( $log['attach_id'] ) ) {
				WPAK_DB::mark_processed( absint( $log['attach_id'] ) );
			}
		}

		update_option( 'wpaikits99_archivist_processed_backfilled', '1', false );
	}

	public function clear_logs(): void {
		$this->respond( 'clear_logs', static function (): string {
			WPAK_Archivist_Logger::clear();
			return 'Logs cleared.';
		} );
	}

	private function respond( string $operation, callable $callback ): void {
		check_ajax_referer( 'wpaikits99_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		try {
			WPAK_Archivist_Debug::log( 'Sync request started.', array( 'operation' => $operation ) );
			$data = $callback();
			WPAK_Archivist_Debug::log( 'Sync request completed.', array( 'operation' => $operation ) );
			wp_send_json_success( $data );
		} catch ( Throwable $error ) {
			WPAK_Archivist_Debug::log( 'Sync request failed.', array( 'operation' => $operation, 'error' => $error->getMessage() ) );
			WPAK_Archivist_Logger::log( 'Sync error: ' . $error->getMessage(), 'error' );
			wp_send_json_error( 'Media sync failed: ' . $error->getMessage(), 500 );
		}
	}
}

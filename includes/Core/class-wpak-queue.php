<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Queue {

	public const GROUP = 'wpaikits99';
	public const HOOK_PROCESS_IMAGE = 'wpaikits99_archivist_process_image';
	private const IMAGE_DELAY_SECONDS = 9;
	private const MAX_PENDING_LOOKUP = 5000;
	private const OPTION_MODE = 'wpaikits99_archivist_sync_mode';
	private const OPTION_PAUSED = 'wpaikits99_archivist_sync_paused';

	public static function enqueue_attachment( int $attachment_id ): bool {
		// Interactive uploads jump ahead of the bulk backlog and trigger the
		// queue runner right away so metadata appears without a page refresh.
		// A paused bulk sync does not hold back a brand-new upload.
		$scheduled = self::schedule_attachment( $attachment_id, 0, false, true );
		if ( $scheduled ) {
			self::kick();
		}
		return $scheduled;
	}

	public static function is_priority_arg( $priority ): bool {
		return true === $priority || '1' === $priority || 1 === $priority;
	}

	public static function kick(): void {
		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			try {
				ActionScheduler_QueueRunner::instance()->maybe_dispatch_async_request();
				return;
			} catch ( Throwable $error ) {
				WPAK_Archivist_Debug::log( 'Async queue dispatch failed.', array( 'error' => $error->getMessage() ) );
			}
		}

		if ( function_exists( 'spawn_cron' ) ) {
			if ( ! wp_next_scheduled( 'action_scheduler_run_queue' ) ) {
				wp_schedule_single_event( time(), 'action_scheduler_run_queue' );
			}
			spawn_cron( time() );
		}
	}

	public static function schedule_bulk_sync( int $limit = 5000 ): int {
		self::set_mode( 'bulk' );
		$scheduled = self::schedule_ids( WPAK_DB::get_unprocessed_image_ids( $limit ) );

		update_option( 'wpaikits99_archivist_bulk_total', $scheduled, false );
		update_option( 'wpaikits99_archivist_bulk_started', time(), false );

		return $scheduled;
	}

	public static function schedule_metadata_refresh( int $limit = 5000 ): int {
		self::set_mode( 'metadata' );
		return self::schedule_ids( WPAK_DB::get_image_ids( $limit ) );
	}

	public static function resume_sync(): int {
		delete_option( self::OPTION_PAUSED );

		return 'metadata' === self::mode()
			? self::schedule_metadata_refresh()
			: self::schedule_bulk_sync();
	}

	public static function pause_sync(): int {
		update_option( self::OPTION_PAUSED, '1', false );
		$cancelled = self::pending_count();

		if ( class_exists( 'ActionScheduler' ) ) {
			try {
				ActionScheduler::store()->cancel_actions_by_hook( self::HOOK_PROCESS_IMAGE );
			} catch ( Throwable $error ) {
				WPAK_Archivist_Debug::log( 'Unable to cancel the Media sync queue.', array( 'error' => $error->getMessage() ) );
			}
		}

		WPAK_Archivist_Debug::log(
			'Sync pause requested.',
			array( 'pending_before' => $cancelled, 'pending_after' => self::pending_count() )
		);

		return $cancelled;
	}

	public static function is_paused(): bool {
		return '1' === get_option( self::OPTION_PAUSED, '0' );
	}

	public static function mode(): string {
		return 'metadata' === get_option( self::OPTION_MODE, 'bulk' ) ? 'metadata' : 'bulk';
	}

	public static function pending_count(): int {
		return count( self::actions_by_status( 'pending' ) );
	}

	public static function active_count(): int {
		return count( self::actions_by_status( array( 'pending', 'in-progress' ) ) );
	}

	/**
	 * True when a queued image was due more than five minutes ago but is still
	 * pending — the classic sign that WP-Cron is not firing (no site traffic,
	 * no real server cron).
	 */
	public static function is_stalled(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		$overdue = as_get_scheduled_actions(
			array(
				'hook'          => self::HOOK_PROCESS_IMAGE,
				'status'        => ActionScheduler_Store::STATUS_PENDING,
				'group'         => self::GROUP,
				'date'          => gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ),
				'date_compare'  => '<=',
				'per_page'      => 1,
				'return_format' => 'ids',
			)
		);

		return ! empty( $overdue );
	}

	public static function retry_attachment( int $attachment_id, int $delay = 60 ): void {
		$delay = max( 30, $delay );
		$scheduled = self::schedule_attachment( $attachment_id, $delay, true );
		WPAK_Archivist_Debug::log(
			'Processing retry requested.',
			array( 'attachment_id' => $attachment_id, 'delay' => $delay, 'scheduled' => $scheduled )
		);
	}

	public static function guard_global_cooldown( int $attachment_id = 0 ): void {
		if ( get_transient( 'wpaikits99_global_cooldown' ) ) {
			throw new Exception( 'The AI provider is in global cooldown. Task aborted to protect rate limits.' );
		}
	}

	private static function schedule_attachment( int $attachment_id, int $delay = 0, bool $is_retry = false, bool $priority = false ): bool {
		$attachment_id = absint( $attachment_id );
		if ( ( ! $priority && self::is_paused() ) || ! function_exists( 'as_schedule_single_action' ) || $attachment_id <= 0 ) {
			return false;
		}

		$already_scheduled = $is_retry
			? self::is_attachment_pending( $attachment_id )
			: self::is_attachment_scheduled( $attachment_id );
		if ( ! $priority && $already_scheduled ) {
			return false;
		}

		$action_id = as_schedule_single_action(
			time() + max( 0, $delay ),
			self::HOOK_PROCESS_IMAGE,
			$priority ? array( $attachment_id, true ) : array( $attachment_id ),
			self::GROUP
		);

		if ( ! $action_id ) {
			WPAK_Archivist_Debug::log( 'Action Scheduler rejected an image job.', array( 'attachment_id' => $attachment_id ) );
		}

		return (bool) $action_id;
	}

	private static function schedule_ids( array $ids ): int {
		$scheduled = 0;
		$step      = self::image_delay();
		$delay     = self::next_delay();

		foreach ( $ids as $attachment_id ) {
			if ( self::schedule_attachment( $attachment_id, $delay ) ) {
				$scheduled++;
				$delay += $step;
			}
		}

		return $scheduled;
	}

	/**
	 * Paid keys are not daily-capped, so we skip the pacing that free tiers
	 * need. Free keys stay spaced out to respect their limits.
	 */
	public static function is_full_speed(): bool {
		return '1' === get_option( 'wpaikits99_archivist_full_speed', '0' );
	}

	private static function image_delay(): int {
		return self::is_full_speed() ? 0 : self::IMAGE_DELAY_SECONDS;
	}

	private static function is_attachment_scheduled( int $attachment_id ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		return false !== as_next_scheduled_action(
			self::HOOK_PROCESS_IMAGE,
			array( absint( $attachment_id ) ),
			self::GROUP
		);
	}

	private static function is_attachment_pending( int $attachment_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return false;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'          => self::HOOK_PROCESS_IMAGE,
				'args'          => array( absint( $attachment_id ) ),
				'status'        => ActionScheduler_Store::STATUS_PENDING,
				'group'         => self::GROUP,
				'per_page'      => 1,
				'return_format' => 'ids',
			)
		);

		return ! empty( $actions );
	}

	private static function next_delay(): int {
		return self::pending_count() * self::image_delay();
	}

	private static function set_mode( string $mode ): void {
		update_option( self::OPTION_MODE, 'metadata' === $mode ? 'metadata' : 'bulk', false );
	}

	private static function actions_by_status( $status ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return array();
		}

		return as_get_scheduled_actions(
			array(
				'hook'          => self::HOOK_PROCESS_IMAGE,
				'status'        => $status,
				'group'         => self::GROUP,
				'per_page'      => self::MAX_PENDING_LOOKUP,
				'return_format' => 'ids',
			)
		);
	}
}

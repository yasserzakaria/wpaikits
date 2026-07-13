<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_Search {

	private const CACHE_DURATION = 2592000;
	private const TOP_K = 20;

	public function register_hooks(): void {
		add_filter( 'ajax_query_attachments_args', array( $this, 'intercept_media_search' ) );
		add_action( 'pre_get_posts', array( $this, 'intercept_list_table_search' ) );
		add_action( 'wp_ajax_wpaikits99_archivist_media_search', array( $this, 'ajax_media_search' ) );
	}

	public function intercept_media_search( array $query ): array {
		if ( empty( $_REQUEST['query']['wpaikits99_ai_search'] ) || ! self::semantic_search_allowed() ) {
			return $query;
		}

		$request_query = (array) wp_unslash( $_REQUEST['query'] );
		if ( self::has_empty_native_search( $request_query ) ) {
			return $query;
		}

		$search_text = self::request_search_text( $request_query );
		if ( '' === $search_text ) {
			return $query;
		}

		$matches = $this->semantic_matches( $search_text );
		if ( is_wp_error( $matches ) ) {
			WPAK_Archivist_Logger::log( 'AI search failed: ' . $matches->get_error_message(), 'error' );
			return $query;
		}

		return $this->apply_matches_to_query_args( $query, $matches, $search_text );
	}

	public function intercept_list_table_search( WP_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		if ( empty( $_GET['wpaikits99_ai_search'] ) || ! self::semantic_search_allowed() ) {
			return;
		}

		if ( array_key_exists( 's', $_GET ) && '' === trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) ) {
			return;
		}

		$search_text = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? $_GET['wpaikits99_ai_query'] ?? '' ) ) );
		if ( '' === $search_text ) {
			return;
		}

		$matches = $this->semantic_matches( $search_text );
		if ( is_wp_error( $matches ) ) {
			WPAK_Archivist_Logger::log( 'List AI search failed: ' . $matches->get_error_message(), 'error' );
			return;
		}

		$query->set( 's', '' );
		$query->set( 'post__in', empty( $matches ) ? array( 0 ) : array_keys( $matches ) );
		$query->set( 'orderby', 'post__in' );
	}

	public function ajax_media_search(): void {
		check_ajax_referer( 'wpaikits99_archivist_media_search', 'nonce' );
		if ( ! self::semantic_search_allowed() ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$search_text = trim( sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ) );
		if ( '' === $search_text ) {
			wp_send_json_error( 'Search query is required.', 400 );
		}

		$matches = $this->semantic_matches( $search_text );
		if ( is_wp_error( $matches ) ) {
			WPAK_Archivist_Logger::log( 'AI search failed: ' . $matches->get_error_message(), 'error' );
			wp_send_json_error( $matches->get_error_message(), 500 );
		}

		wp_send_json_success(
			array(
				'ids'   => array_map( 'absint', array_keys( $matches ) ),
				'count' => count( $matches ),
				'query' => $search_text,
			)
		);
	}

	public static function semantic_search_allowed(): bool {
		return (bool) apply_filters( 'wpaikits99_is_pro', false )
			&& WPAK_AI_Access::can_use_ai()
			&& '' !== (string) get_option( 'wpaikits99_pinecone_host', '' );
	}

	private function apply_matches_to_query_args( array $query, array $matches, string $search_text ): array {
		if ( empty( $matches ) ) {
			$query['post__in'] = array( 0 );
			unset( $query['s'] );
			WPAK_Archivist_Logger::log( 'Search for "' . $search_text . '" returned no matches.', 'search' );
			return $query;
		}

		$query['post__in'] = array_keys( $matches );
		$query['orderby'] = 'post__in';
		unset( $query['s'] );

		set_transient( 'wpaikits99_last_search_scores', $matches, 300 );
		WPAK_Archivist_Logger::log( 'Search for "' . $search_text . '" returned ' . count( $matches ) . ' matches.', 'search' );

		return $query;
	}

	public function semantic_matches( string $search_text, int $top_k = self::TOP_K ) {
		$vector = $this->query_vector( $search_text );
		if ( is_wp_error( $vector ) ) {
			return $vector;
		}

		$matches = WPAK_Pinecone::query( $vector, max( 1, min( 50, $top_k ) ) );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}

		return $this->filter_matches( $matches );
	}

	private function query_vector( string $search_text ) {
		$cache_key = 'wpaikits99_search_' . md5( strtolower( $search_text ) );
		$vector = get_transient( $cache_key );
		if ( false !== $vector ) {
			return $vector;
		}

		$vector = WPAK_LLM_Proxy::embed_text( $search_text );
		if ( ! is_wp_error( $vector ) ) {
			set_transient( $cache_key, $vector, self::CACHE_DURATION );
		}

		return $vector;
	}

	private function filter_matches( array $matches ): array {
		if ( empty( $matches ) ) {
			return array();
		}

		arsort( $matches );
		$threshold = (float) get_option( 'wpaikits99_archivist_threshold', '0.25' );
		$top_score = (float) reset( $matches );
		$dynamic_threshold = max( $threshold, $top_score - 0.10 );

		return array_filter(
			$matches,
			static fn( float $score ): bool => $score >= $dynamic_threshold
		);
	}

	private static function request_search_text( array $query ): string {
		$native = trim( sanitize_text_field( $query['s'] ?? $query['search'] ?? '' ) );
		return '' !== $native
			? $native
			: trim( sanitize_text_field( $query['wpaikits99_ai_query'] ?? '' ) );
	}

	private static function has_empty_native_search( array $query ): bool {
		return ( array_key_exists( 's', $query ) && '' === trim( sanitize_text_field( $query['s'] ) ) )
			|| ( array_key_exists( 'search', $query ) && '' === trim( sanitize_text_field( $query['search'] ) ) );
	}
}

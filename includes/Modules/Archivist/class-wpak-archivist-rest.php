<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Archivist_REST {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'wpak/v1',
			'/media-search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_media_search' ),
				'permission_callback' => static function () {
					return WPAK_AI_Access::can_use_ai();
				},
			)
		);
	}

	public function handle_media_search( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$query = sanitize_text_field( $params['query'] ?? '' );
		$limit = max( 1, min( 6, absint( $params['limit'] ?? 3 ) ) );
		$visual_check = ! empty( $params['visual_check'] );

		if ( '' === $query ) {
			return new WP_Error( 'missing_query', 'Media search query is required.', array( 'status' => 400 ) );
		}

		$search = new WPAK_Archivist_Search();
		$matches = $search->semantic_matches( $query, max( $limit, 3 ) );

		if ( is_wp_error( $matches ) ) {
			$results = $this->fallback_keyword_search( $query, $limit );
		} else {
			$results = $this->attachment_cards( array_slice( $matches, 0, $limit, true ) );
		}

		if ( $visual_check && count( $results ) > 1 && ! is_wp_error( $matches ) ) {
			$results = $this->apply_visual_ranking( $query, $results );
		} else {
			$results = $this->public_results( $results );
		}

		return rest_ensure_response(
			array(
				'query'          => $query,
				'visual_checked' => $visual_check && ! is_wp_error( $matches ),
				'results'        => $results,
				'count'          => count( $results ),
			)
		);
	}

	private function fallback_keyword_search( string $query, int $limit ): array {
		$query_obj = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				's'              => $query,
			)
		);

		$cards = array();
		foreach ( $query_obj->posts as $post ) {
			$cards[] = $this->attachment_card( $post, 1.0 );
		}

		return $cards;
	}

	private function attachment_cards( array $matches ): array {
		$cards = array();
		foreach ( $matches as $id => $score ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $id ) ) {
				continue;
			}

			$cards[] = $this->attachment_card( $post, (float) $score );
		}
		return $cards;
	}

	private function attachment_card( WP_Post $post, float $score ): array {
		$metadata = wp_get_attachment_metadata( $post->ID );
		return array(
			'id'          => $post->ID,
			'title'       => get_the_title( $post ),
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'     => wp_strip_all_tags( $post->post_excerpt ),
			'description' => wp_strip_all_tags( $post->post_content ),
			'url'         => wp_get_attachment_url( $post->ID ),
			'thumbnail'   => wp_get_attachment_image_url( $post->ID, 'medium' ),
			'mime'        => get_post_mime_type( $post ),
			'width'       => absint( $metadata['width'] ?? 0 ),
			'height'      => absint( $metadata['height'] ?? 0 ),
			'score'       => round( $score, 4 ),
			'source'      => 'media_library',
			'file_path'   => get_attached_file( $post->ID ),
		);
	}

	private function apply_visual_ranking( string $query, array $results ): array {
		$ranking = WPAK_LLM_Proxy::rank_media_candidates( $query, $results );
		if ( is_wp_error( $ranking ) || empty( $ranking['rankings'] ) ) {
			return $this->public_results( $results );
		}

		$rankings = array();
		foreach ( $ranking['rankings'] as $item ) {
			$rankings[ absint( $item['id'] ?? 0 ) ] = $item;
		}

		foreach ( $results as &$result ) {
			$visual = $rankings[ $result['id'] ] ?? array();
			$result['visual_rank'] = absint( $visual['rank'] ?? 999 );
			$result['suitable'] = (bool) ( $visual['suitable'] ?? false );
			$result['fit_reason'] = sanitize_text_field( $visual['fit_reason'] ?? '' );
		}

		usort(
			$results,
			static fn( array $a, array $b ): int => ( $a['visual_rank'] <=> $b['visual_rank'] )
		);

		return $this->public_results( $results );
	}

	private function public_results( array $results ): array {
		return array_map(
			static function ( array $result ): array {
				unset( $result['file_path'] );
				return $result;
			},
			$results
		);
	}
}

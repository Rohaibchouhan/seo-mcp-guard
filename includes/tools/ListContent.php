<?php
/**
 * ListContent MCP tool — list posts with Rank Math SEO metadata.
 *
 * @package SEOMCPGuard\Tools
 */

declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List published content with their Rank Math SEO fields.
 */
class ListContent extends BaseTool {

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'list_content';
	}

	/**
	 * Execute the list_content tool.
	 *
	 * Expected $args keys:
	 *  - post_type  (string,  required)   — must be in allowed list
	 *  - page       (int,     default 1)
	 *  - per_page   (int,     default 20, max 100)
	 *  - search     (string,  optional)
	 *
	 * @param array<string,mixed> $args
	 *
	 * @return array{posts: list<array<string,mixed>>, total: int, pages: int, page: int}
	 *
	 * @throws MCPException On validation failure.
	 */
	public function execute( array $args ): array {

		// ------------------------------------------------------------------
		// 1. Validate & coerce arguments
		// ------------------------------------------------------------------
		if ( empty( $args['post_type'] ) || ! is_string( $args['post_type'] ) ) {
			throw MCPException::validation( 'The "post_type" argument is required and must be a string.' );
		}

		$post_type = sanitize_key( $args['post_type'] );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$search    = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		// ------------------------------------------------------------------
		// 2. Verify the requested post type is allowed
		// ------------------------------------------------------------------
		$allowed_post_types = $this->settings->get_allowed_post_types();

		if ( ! post_type_exists( $post_type ) ) {
			throw MCPException::validation(
				sprintf( 'Post type "%s" is not registered in WordPress.', $post_type )
			);
		}

		if ( ! empty( $allowed_post_types ) && ! in_array( $post_type, $allowed_post_types, true ) ) {
			throw MCPException::not_allowed(
				sprintf( 'Post type "%s" is not in the allowed post types list.', $post_type )
			);
		}

		// ------------------------------------------------------------------
		// 3. Run WP_Query
		// ------------------------------------------------------------------
		$query_args = [
			'post_type'              => $post_type,
			'post_status'            => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true, // we need meta
		];

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		// Restrict to allowed post IDs if configured.
		$allowed_post_ids = $this->settings->get_allowed_post_ids();
		if ( ! empty( $allowed_post_ids ) ) {
			$query_args['post__in'] = $allowed_post_ids;
		}

		$query = new WP_Query( $query_args );

		// ------------------------------------------------------------------
		// 4. Build result
		// ------------------------------------------------------------------
		$posts = [];

		/** @var \WP_Post $post */
		foreach ( $query->posts as $post ) {
			$post_id = (int) $post->ID;

			$posts[] = [
				'ID'                         => $post_id,
				'title'                      => get_the_title( $post ),
				'url'                        => get_permalink( $post ),
				'status'                     => $post->post_status,
				'excerpt'                    => has_excerpt( $post )
					? wp_strip_all_tags( get_the_excerpt( $post ) )
					: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' ),
				// Rank Math meta fields
				'rank_math_title'            => (string) get_post_meta( $post_id, 'rank_math_title', true ),
				'rank_math_description'      => (string) get_post_meta( $post_id, 'rank_math_description', true ),
				'rank_math_focus_keyword'    => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
				'rank_math_seo_score'        => (string) get_post_meta( $post_id, 'rank_math_seo_score', true ),
			];
		}

		wp_reset_postdata();

		return [
			'posts'    => $posts,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}
}

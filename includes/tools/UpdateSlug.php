<?php
/**
 * UpdateSlug MCP tool — change a post slug with automatic 301 redirect.
 *
 * @package SEOMCPGuard\Tools
 */

declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update a post's slug (post_name) and automatically create a Rank Math 301
 * redirect from the old URL to the new URL, preventing broken links.
 *
 * Collision and redirect-loop detection are performed before any write.
 */
class UpdateSlug extends BaseTool {

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'update_slug';
	}

	/**
	 * Execute the update_slug tool.
	 *
	 * Expected $args keys:
	 *  - post_id  (int,    required)
	 *  - new_slug (string, required)
	 *  - dry_run  (bool,   default true)
	 *
	 * @param array<string,mixed> $args
	 *
	 * @return array<string,mixed>
	 *
	 * @throws MCPException On validation or permission failure.
	 */
	public function execute( array $args ): array {

		// ------------------------------------------------------------------
		// 1. Validate arguments
		// ------------------------------------------------------------------
		if ( ! isset( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			throw MCPException::validation( 'The "post_id" argument is required and must be an integer.' );
		}

		if ( empty( $args['new_slug'] ) || ! is_string( $args['new_slug'] ) ) {
			throw MCPException::validation( 'The "new_slug" argument is required and must be a non-empty string.' );
		}

		$post_id  = (int) $args['post_id'];
		$dry_run  = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : true;

		// ------------------------------------------------------------------
		// 2. Verify post access
		// ------------------------------------------------------------------
		$post = $this->get_post_or_fail( $post_id );

		// ------------------------------------------------------------------
		// 3. Sanitise the new slug
		// ------------------------------------------------------------------
		$new_slug = sanitize_title( $args['new_slug'] );

		if ( '' === $new_slug ) {
			throw MCPException::validation( 'After sanitization the new slug is empty. Provide a valid slug.' );
		}

		$old_slug = $post->post_name;

		if ( $new_slug === $old_slug ) {
			throw MCPException::validation(
				sprintf( 'The new slug "%s" is identical to the current slug. No change needed.', $new_slug )
			);
		}

		// ------------------------------------------------------------------
		// 4. Derive old and new URLs
		// ------------------------------------------------------------------
		$old_url = (string) get_permalink( $post );

		// Build prospective new URL by temporarily swapping the slug.
		$new_url = $this->build_new_permalink( $post, $new_slug );

		// ------------------------------------------------------------------
		// 5. Check for slug collision
		// ------------------------------------------------------------------
		$this->check_slug_collision( $new_slug, $post_id, $post->post_type );

		// ------------------------------------------------------------------
		// 6. Check for redirect loop / chain issues
		// ------------------------------------------------------------------
		$this->check_redirect_loop( $old_url, $new_url );

		// ------------------------------------------------------------------
		// 7. Check production-write guard
		// ------------------------------------------------------------------
		if ( ! $this->settings->allow_production_writes() && ! $dry_run ) {
			throw MCPException::not_allowed(
				'Production writes are disabled in SEO MCP Guard settings. Enable them or use dry_run=true.'
			);
		}

		// ------------------------------------------------------------------
		// 8. Tracking IDs
		// ------------------------------------------------------------------
		$batch_id        = $this->generate_batch_id();
		$idempotency_key = $this->build_idempotency_key( $this->get_name(), $args );

		// ------------------------------------------------------------------
		// 9. Capture whether Rank Math redirections are available
		// ------------------------------------------------------------------
		$rankmath_active      = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath\RankMath', false );
		$redirections_active  = $rankmath_active && class_exists( 'RankMath\Redirections\Redirection', false );

		// ------------------------------------------------------------------
		// 10. Execute via SafetyEngine
		// ------------------------------------------------------------------
		$redirect_created = false;
		$write_fn         = function () use (
			$post_id,
			$new_slug,
			$old_url,
			$new_url,
			$redirections_active,
			&$redirect_created
		): void {
			// a. Update the post slug.
			$updated = wp_update_post(
				[
					'ID'        => $post_id,
					'post_name' => $new_slug,
				],
				true
			);

			if ( is_wp_error( $updated ) ) {
				throw MCPException::internal(
					'wp_update_post failed: ' . $updated->get_error_message()
				);
			}

			// b. Create Rank Math 301 redirect.
			if ( $redirections_active ) {
				$redirect_created = $this->create_rankmath_redirect( $old_url, $new_url );
			}
		};

		$result = $this->safety->execute_write(
			tool: $this->get_name(),
			post_id: $post_id,
			old_values: [ 'post_name' => $old_slug ],
			new_values: [ 'post_name' => $new_slug ],
			write_fn: $write_fn,
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		// Enrich result with URL and redirect info.
		$result['old_slug']          = $old_slug;
		$result['new_slug']          = $new_slug;
		$result['old_url']           = $old_url;
		$result['new_url']           = $new_url;
		$result['redirect_created']  = $redirect_created;
		$result['redirect_type']     = $redirect_created ? '301' : null;
		$result['rankmath_active']   = $rankmath_active;
		$result['redirections_available'] = $redirections_active;

		if ( ! $redirections_active && ! $dry_run ) {
			$result['warning'] = 'Rank Math Redirections module is not available; no automatic redirect was created.';
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the prospective new permalink by filtering the post object with
	 * a temporary post_name override.
	 *
	 * @param WP_Post $post     Original post.
	 * @param string  $new_slug New sanitized slug.
	 *
	 * @return string The new URL (may be relative-absolute depending on site config).
	 */
	private function build_new_permalink( WP_Post $post, string $new_slug ): string {
		// Clone the post and override post_name so get_permalink computes correctly.
		$clone            = clone $post;
		$clone->post_name = $new_slug;

		return (string) get_permalink( $clone );
	}

	/**
	 * Throw MCPException if $new_slug is already used by another post of the
	 * same post type.
	 *
	 * @param string $new_slug  Proposed slug.
	 * @param int    $post_id   Current post ID (excluded from search).
	 * @param string $post_type Post type to scope the collision check.
	 *
	 * @return void
	 *
	 * @throws MCPException VALIDATION on collision.
	 */
	private function check_slug_collision( string $new_slug, int $post_id, string $post_type ): void {
		$collision_query = new WP_Query(
			[
				'name'           => $new_slug,
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'post__not_in'   => [ $post_id ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( ! empty( $collision_query->posts ) ) {
			$first_post     = $collision_query->posts[0];
			$conflicting_id = is_object( $first_post ) ? (int) ( $first_post->ID ?? 0 ) : (int) $first_post;
			throw MCPException::validation(
				sprintf(
					'Slug "%s" is already in use by post ID %d.',
					$new_slug,
					$conflicting_id
				)
			);
		}
	}

	/**
	 * Detect whether the proposed slug change would create a redirect loop or
	 * chain via Rank Math's redirections table.
	 *
	 * Checks:
	 *  (a) Is $new_url already a redirect source? (i.e., something will redirect
	 *      visitors away from the new URL immediately after they arrive.)
	 *  (b) Does $old_url redirect to something that would eventually point back
	 *      to $new_url, forming a chain/loop?
	 *
	 * @param string $old_url URL the post currently lives at.
	 * @param string $new_url URL the post will live at after the slug change.
	 *
	 * @return void
	 *
	 * @throws MCPException VALIDATION when a loop/chain is detected.
	 */
	private function check_redirect_loop( string $old_url, string $new_url ): void {
		// Rank Math redirections table may not exist; skip check gracefully.
		if ( ! class_exists( 'RankMath\Redirections\DB', false ) ) {
			return;
		}

		// Normalise URLs to relative paths for matching (Rank Math stores paths).
		$old_path = $this->url_to_path( $old_url );
		$new_path = $this->url_to_path( $new_url );

		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// (a) Does $new_path already exist as a redirect source?
		// If so, visiting the new URL would immediately redirect elsewhere → loop risk.
		$existing_source = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE sources LIKE %s AND status = 'active' LIMIT 1",
				'%' . $wpdb->esc_like( $new_path ) . '%'
			)
		);

		if ( $existing_source ) {
			throw MCPException::validation(
				sprintf(
					'Changing the slug would create a redirect chain: "%s" is already a redirect source in Rank Math. Remove that redirect first.',
					$new_path
				)
			);
		}

		// (b) Does the current $old_url redirect to $new_url (or a path containing it)?
		// That would mean old → new and (after the change) new IS old, creating a loop.
		$destination_loop = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE sources LIKE %s AND url_to LIKE %s AND status = 'active' LIMIT 1",
				'%' . $wpdb->esc_like( $old_path ) . '%',
				'%' . $wpdb->esc_like( $new_path ) . '%'
			)
		);

		// phpcs:enable

		if ( $destination_loop ) {
			throw MCPException::validation(
				sprintf(
					'Changing the slug would create a redirect loop: an existing redirect already points from "%s" to "%s".',
					$old_path,
					$new_path
				)
			);
		}
	}

	/**
	 * Convert an absolute URL to its site-relative path for redirect matching.
	 *
	 * @param string $url Absolute URL.
	 *
	 * @return string Relative path, e.g. "/my-post-slug/".
	 */
	private function url_to_path( string $url ): string {
		$home = rtrim( home_url(), '/' );

		if ( str_starts_with( $url, $home ) ) {
			return '/' . ltrim( substr( $url, strlen( $home ) ), '/' );
		}

		$parsed = parse_url( $url );
		return isset( $parsed['path'] ) ? '/' . ltrim( $parsed['path'], '/' ) : $url;
	}

	/**
	 * Create a Rank Math 301 redirect from $old_url → $new_url.
	 *
	 * Tries the Rank Math PHP API first; falls back to a direct DB insert
	 * if the class interface has changed.
	 *
	 * @param string $old_url Source URL (old permalink).
	 * @param string $new_url Destination URL (new permalink).
	 *
	 * @return bool True on success, false on failure.
	 */
	private function create_rankmath_redirect( string $old_url, string $new_url ): bool {

		$old_path = $this->url_to_path( $old_url );
		$new_path = $this->url_to_path( $new_url );

		// ------------------------------------------------------------------
		// Attempt 1: Rank Math Redirection::create() static factory
		// ------------------------------------------------------------------
		if ( class_exists( 'RankMath\Redirections\Redirection', false )
			&& method_exists( 'RankMath\Redirections\Redirection', 'create' )
		) {
			try {
				$redirection = \RankMath\Redirections\Redirection::create(
					[
						'url_to'      => $new_path,
						'header_code' => 301,
						'sources'     => [
							[
								'pattern'    => $old_path,
								'comparison' => 'exact',
							],
						],
					]
				);

				if ( $redirection && ! is_wp_error( $redirection ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// Fall through to DB insert.
			}
		}

		// ------------------------------------------------------------------
		// Attempt 2: Direct DB insert into rank_math_redirections
		// ------------------------------------------------------------------
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';

		// Verify the table exists before inserting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return false;
		}

		$sources_json = wp_json_encode(
			[
				[
					'pattern'    => $old_path,
					'comparison' => 'exact',
				],
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'sources'     => $sources_json,
				'url_to'      => $new_path,
				'header_code' => 301,
				'hits'        => 0,
				'status'      => 'active',
				'created'     => current_time( 'mysql' ),
				'updated'     => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);

		return ( false !== $inserted && $wpdb->insert_id > 0 );
	}
}

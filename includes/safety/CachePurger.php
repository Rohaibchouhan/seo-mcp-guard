<?php
/**
 * Cache Purger – clears page-cache entries after write operations.
 *
 * @package SEOMCPGuard\Safety
 */

declare(strict_types=1);

namespace SEOMCPGuard\Safety;

/**
 * Class CachePurger
 *
 * Detects which caching plugins are active and invokes their purge APIs
 * in the correct order.  Falls back to WordPress's own object/post cache
 * so purging is always attempted regardless of what is installed.
 *
 * Supported cache plugins (auto-detected via function/class/option checks):
 *   – WP Rocket
 *   – LiteSpeed Cache
 *   – W3 Total Cache
 *   – WP Super Cache
 *   – Autoptimize
 *   – SiteGround Optimizer
 *   – Cloudflare (via WordPress plugin)
 *   – WP Fastest Cache
 */
class CachePurger {

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Purge all known caches for a specific post.
	 *
	 * The method is intentionally defensive: each cache plugin integration is
	 * wrapped so that a failure in one does not prevent the others from running.
	 *
	 * @param int $post_id The post whose caches should be purged.
	 */
	public function purge_post( int $post_id ): void {
		// Always start with WordPress's built-in object cache.
		$this->purge_wp_native( $post_id );

		// Then attempt each third-party cache integration.
		$this->purge_wp_rocket( $post_id );
		$this->purge_litespeed( $post_id );
		$this->purge_w3_total_cache( $post_id );
		$this->purge_wp_super_cache( $post_id );
		$this->purge_autoptimize( $post_id );
		$this->purge_sg_optimizer( $post_id );
		$this->purge_cloudflare( $post_id );
		$this->purge_wp_fastest_cache( $post_id );
		$this->purge_nginx_helper( $post_id );

		/**
		 * Fires after all known caches have been purged for a post.
		 *
		 * Third-party integrations can hook here to perform additional purges.
		 *
		 * @param int $post_id The post whose caches were purged.
		 */
		do_action( 'seo_mcp_guard_cache_purged', $post_id );
	}

	// -----------------------------------------------------------------------
	// Per-plugin integrations (private)
	// -----------------------------------------------------------------------

	/**
	 * WordPress native cache.
	 *
	 * Cleans the in-memory object cache for the post and resets the
	 * post's "last modified" permalink cache.
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_wp_native( int $post_id ): void {
		clean_post_cache( $post_id );

		// Also invalidate the term cache in case meta changes affected taxonomy.
		$taxonomies = get_object_taxonomies( get_post_type( $post_id ) ?: '' );
		if ( ! empty( $taxonomies ) ) {
			$term_ids = wp_get_object_terms( $post_id, $taxonomies, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $term_ids ) ) {
				foreach ( $term_ids as $term_id ) {
					clean_term_cache( (int) $term_id );
				}
			}
		}
	}

	/**
	 * WP Rocket.
	 *
	 * @see https://docs.wp-rocket.me/article/39-clear-cache-programmatically
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_wp_rocket( int $post_id ): void {
		if ( ! function_exists( 'rocket_clean_post' ) ) {
			return;
		}

		try {
			rocket_clean_post( $post_id );
		} catch ( \Throwable $e ) {
			$this->log_error( 'WP Rocket', $post_id, $e );
		}
	}

	/**
	 * LiteSpeed Cache.
	 *
	 * @see https://docs.litespeedtech.com/lscache/lscwp/api/
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_litespeed( int $post_id ): void {
		try {
			// Primary method: dedicated post-purge action.
			do_action( 'litespeed_purge_post', $post_id );

			// Also try the ESI/tag-based purge if the class is available.
			if ( class_exists( '\LiteSpeed\Purge' ) ) {
				do_action( 'litespeed_purge_url', get_permalink( $post_id ) );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( 'LiteSpeed Cache', $post_id, $e );
		}
	}

	/**
	 * W3 Total Cache.
	 *
	 * @see https://api.w3-edge.com/
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_w3_total_cache( int $post_id ): void {
		if ( ! function_exists( 'w3tc_flush_post' ) ) {
			return;
		}

		try {
			w3tc_flush_post( $post_id );
		} catch ( \Throwable $e ) {
			$this->log_error( 'W3 Total Cache', $post_id, $e );
		}
	}

	/**
	 * WP Super Cache.
	 *
	 * @see https://github.com/Automattic/wp-super-cache
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_wp_super_cache( int $post_id ): void {
		if ( ! function_exists( 'wpsc_delete_post_cache' ) ) {
			return;
		}

		try {
			wpsc_delete_post_cache( $post_id );
		} catch ( \Throwable $e ) {
			$this->log_error( 'WP Super Cache', $post_id, $e );
		}
	}

	/**
	 * Autoptimize.
	 *
	 * Autoptimize caches JS/CSS assets rather than pages, but a content change
	 * may affect inlined critical CSS, so we trigger a full cache flush.
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_autoptimize( int $post_id ): void {
		if ( ! class_exists( 'autoptimizeCache' ) ) {
			return;
		}

		try {
			// Autoptimize does not support per-post purges; flush everything.
			do_action( 'autoptimize_action_cachepurged' );
		} catch ( \Throwable $e ) {
			$this->log_error( 'Autoptimize', $post_id, $e );
		}
	}

	/**
	 * SiteGround Optimizer.
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_sg_optimizer( int $post_id ): void {
		if ( ! function_exists( 'sg_cachepress_purge_cache' ) ) {
			return;
		}

		try {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				sg_cachepress_purge_cache( $permalink );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( 'SiteGround Optimizer', $post_id, $e );
		}
	}

	/**
	 * Cloudflare (via the official WordPress plugin).
	 *
	 * @see https://github.com/cloudflare/Cloudflare-WordPress
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_cloudflare( int $post_id ): void {
		// Cloudflare plugin exposes this action since version 4.x.
		if ( ! has_action( 'cloudflare_purge_by_url' ) ) {
			return;
		}

		try {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				do_action( 'cloudflare_purge_by_url', [ $permalink ] );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( 'Cloudflare', $post_id, $e );
		}
	}

	/**
	 * WP Fastest Cache.
	 *
	 * @see https://www.wpfastestcache.com/
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_wp_fastest_cache( int $post_id ): void {
		if ( ! function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			return;
		}

		try {
			wpfc_clear_post_cache_by_id( $post_id );
		} catch ( \Throwable $e ) {
			$this->log_error( 'WP Fastest Cache', $post_id, $e );
		}
	}

	/**
	 * Nginx Helper (Redis / Nginx FastCGI cache purging).
	 *
	 * @see https://wordpress.org/plugins/nginx-helper/
	 *
	 * @param int $post_id Post ID.
	 */
	private function purge_nginx_helper( int $post_id ): void {
		if ( ! class_exists( '\Nginx_Helper' ) && ! function_exists( 'rt_nginx_helper_purge_url' ) ) {
			return;
		}

		try {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				do_action( 'rt_nginx_helper_purge_url', $permalink );
			}
		} catch ( \Throwable $e ) {
			$this->log_error( 'Nginx Helper', $post_id, $e );
		}
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	/**
	 * Log a cache-purge error without throwing so other purges can still run.
	 *
	 * @param string     $plugin  Human-readable plugin name.
	 * @param int        $post_id Post that was being purged.
	 * @param \Throwable $e       The caught exception.
	 */
	private function log_error( string $plugin, int $post_id, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[SEO MCP Guard] CachePurger: %s purge failed for post %d – %s',
					$plugin,
					$post_id,
					$e->getMessage()
				)
			);
		}
	}
}

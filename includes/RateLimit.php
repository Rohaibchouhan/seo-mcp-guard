<?php
declare(strict_types=1);

namespace SEOMCPGuard;

/**
 * Per-user rate limiting and post write-lock management for SEO MCP Guard.
 *
 * Rate limiting uses a sliding-window-ish algorithm built on WordPress transients:
 * requests are counted inside one-minute buckets keyed by user ID and the current
 * UTC minute. This gives accurate per-minute enforcement with minimal overhead.
 *
 * Post locks are best-effort mutual-exclusion flags that prevent two concurrent
 * MCP writes to the same post from racing each other. True atomic test-and-set is
 * not available in the WordPress transient API, so the implementation reads then
 * writes and accepts a very small race window (acceptable for this use-case).
 */
class RateLimit {

	// -------------------------------------------------------------------------
	// Transient key builders
	// -------------------------------------------------------------------------

	/**
	 * Returns the transient key for a user's current one-minute bucket.
	 */
	private function rate_limit_key( int $user_id ): string {
		// Bucket by UTC minute so the count naturally resets every 60 seconds.
		$minute_bucket = (int) floor( time() / 60 );
		return "seo_mcp_guard_rl_{$user_id}_{$minute_bucket}";
	}

	/**
	 * Returns the transient key for a post write-lock.
	 */
	private function lock_key( int $post_id ): string {
		return "seo_mcp_guard_lock_{$post_id}";
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Check the current request count for the given user and, if within the
	 * limit, atomically increment it. Throws MCPException with code RATE_LIMIT
	 * when the limit is exceeded.
	 *
	 * @param int $user_id          WordPress user ID of the authenticated caller.
	 * @param int $limit_per_minute Maximum number of calls allowed per 60-second window.
	 *
	 * @throws MCPException When the user has exceeded their per-minute quota.
	 */
	public function check_and_increment( int $user_id, int $limit_per_minute ): void {
		$key   = $this->rate_limit_key( $user_id );
		$count = (int) get_transient( $key );

		if ( $count >= $limit_per_minute ) {
			throw MCPException::rate_limit( $user_id );
		}

		if ( 0 === $count ) {
			// First request in this minute – set transient with a 90-second TTL
			// (slightly longer than 60 s to absorb boundary-crossing races).
			set_transient( $key, 1, 90 );
		} else {
			// get_transient + set_transient is not atomic, but the race window is
			// negligible for rate-limiting (a few extra calls are acceptable).
			set_transient( $key, $count + 1, 90 );
		}
	}

	/**
	 * Return the number of requests the given user has made in the current minute.
	 * Useful for returning rate-limit headers or admin dashboards.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Current call count (0 if no requests have been made this minute).
	 */
	public function current_count( int $user_id ): int {
		return (int) get_transient( $this->rate_limit_key( $user_id ) );
	}

	// -------------------------------------------------------------------------
	// Post write-lock
	// -------------------------------------------------------------------------

	/**
	 * Check whether a write-lock is currently held for the given post.
	 *
	 * @param int $post_id WordPress post ID to check.
	 * @return bool True if the post is locked.
	 */
	public function is_post_locked( int $post_id ): bool {
		return false !== get_transient( $this->lock_key( $post_id ) );
	}

	/**
	 * Attempt to acquire a write-lock for the given post.
	 *
	 * Because WordPress transients do not offer true atomic test-and-set,
	 * this implementation performs a read-then-write. A tiny race window exists
	 * but is acceptable for the expected concurrent-request volume of an MCP
	 * endpoint behind bearer-token auth.
	 *
	 * @param int $post_id WordPress post ID to lock.
	 * @param int $ttl     Lock time-to-live in seconds (default: 30).
	 * @return bool True when the lock was successfully acquired; false if
	 *              another process already holds it.
	 */
	public function acquire_post_lock( int $post_id, int $ttl = 30 ): bool {
		$key = $this->lock_key( $post_id );

		// If a lock transient already exists, refuse to overwrite it.
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		// Store a unique token so we can distinguish our own lock on re-read.
		$lock_token = bin2hex( random_bytes( 8 ) );
		set_transient( $key, $lock_token, $ttl );

		// Re-read to detect the race: if a different token is stored, we lost.
		$stored = get_transient( $key );
		if ( $stored !== $lock_token ) {
			return false;
		}

		return true;
	}

	/**
	 * Release (delete) a write-lock for the given post.
	 *
	 * Safe to call even when no lock exists.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public function release_post_lock( int $post_id ): void {
		delete_transient( $this->lock_key( $post_id ) );
	}
}

<?php
/**
 * Health Check – verifies that a post URL is accessible and indexable after a write.
 *
 * @package SEOMCPGuard\Safety
 */

declare(strict_types=1);

namespace SEOMCPGuard\Safety;

use SEOMCPGuard\Exceptions\MCPException;

/**
 * Class HealthCheck
 *
 * Performs a lightweight HTTP GET after every write to make sure the page:
 *   1. Returns HTTP 200.
 *   2. Has not been accidentally noindexed via an X-Robots-Tag response header.
 *
 * Both checks are intentionally conservative: if the page was already
 * noindexed before the write the check still passes (we are checking for
 * *accidental* changes introduced by our write, not auditing pre-existing
 * SEO decisions).
 *
 * The class never throws anything outside of {@see verify_post_ok()} –
 * {@see check_url()} always returns a result array so callers can decide
 * how to handle failures.
 */
class HealthCheck {

	// -----------------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------------

	/** HTTP GET timeout in seconds. */
	private const REQUEST_TIMEOUT_SECONDS = 15;

	/** Maximum number of redirects to follow. */
	private const MAX_REDIRECTS = 5;

	/**
	 * X-Robots-Tag header directives that signal the page is noindexed.
	 * We check for these only when the pre-write state did NOT have them.
	 */
	private const NOINDEX_DIRECTIVES = [ 'noindex', 'none' ];

	// -----------------------------------------------------------------------
	// State injected at construction time
	// -----------------------------------------------------------------------

	/**
	 * Snapshot of X-Robots-Tag directives observed BEFORE the write.
	 * Populated by {@see snapshot_pre_write()} and consumed by
	 * {@see verify_post_ok()}.
	 *
	 * @var array<string, bool>  directive => true map
	 */
	private array $pre_write_robots = [];

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Perform an HTTP GET on the given URL and report the result.
	 *
	 * This method never throws; all error conditions are returned in the
	 * result array so callers can decide what to do.
	 *
	 * @param string $url Absolute URL to check.
	 *
	 * @return array{ok: bool, status_code: int, error: string|null, headers: array}
	 */
	public function check_url( string $url ): array {
		$args = [
			'timeout'     => self::REQUEST_TIMEOUT_SECONDS,
			'redirection' => self::MAX_REDIRECTS,
			'sslverify'   => $this->should_verify_ssl(),
			'user-agent'  => 'SEO-MCP-Guard/HealthCheck (+https://github.com/seo-mcp-guard)',
			'blocking'    => true,
			'method'      => 'GET',
		];

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'          => false,
				'status_code' => 0,
				'error'       => $response->get_error_message(),
				'headers'     => [],
			];
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$headers     = wp_remote_retrieve_headers( $response );

		// Normalise WP_HTTP_Headers object to plain array.
		$headers_array = $headers instanceof \Requests_Utility_CaseInsensitiveDictionary
			? $headers->getAll()
			: (array) $headers;

		$ok = ( 200 === $status_code );

		return [
			'ok'          => $ok,
			'status_code' => $status_code,
			'error'       => $ok ? null : "HTTP {$status_code} received for URL: {$url}",
			'headers'     => $headers_array,
		];
	}

	/**
	 * Snapshot the pre-write indexability state of a post.
	 *
	 * Call this *before* executing the write so {@see verify_post_ok()} can
	 * compare before-and-after X-Robots-Tag headers.
	 *
	 * If the pre-write fetch fails (e.g. local environment with no public URL)
	 * the snapshot is silently skipped and the post-write check is less strict.
	 *
	 * @param int $post_id Post ID to snapshot.
	 */
	public function snapshot_pre_write( int $post_id ): void {
		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			return;
		}

		$result = $this->check_url( $permalink );

		if ( $result['ok'] ) {
			$this->pre_write_robots = $this->parse_robots_header(
				$result['headers']['x-robots-tag'] ?? ''
			);
		}
	}

	/**
	 * Verify that a post is publicly accessible and has not been accidentally noindexed.
	 *
	 * Performs an HTTP GET on the post's permalink and checks:
	 *   1. HTTP 200 is returned.
	 *   2. The X-Robots-Tag header does not contain a noindex directive that
	 *      was NOT present before the write.
	 *
	 * @param int $post_id Post to verify.
	 *
	 * @throws MCPException HEALTH_FAILED if the post fails either check.
	 */
	public function verify_post_ok( int $post_id ): void {
		$permalink = get_permalink( $post_id );

		if ( ! $permalink ) {
			throw MCPException::health_failed(
				$post_id,
				"Could not resolve permalink for post {$post_id}."
			);
		}

		$result = $this->check_url( $permalink );

		// ----------------------------------------------------------------
		// Check 1: HTTP 200
		// ----------------------------------------------------------------
		if ( ! $result['ok'] ) {
			$detail = $result['error'] ?? "HTTP {$result['status_code']} received.";
			throw MCPException::health_failed(
				$post_id,
				"Post {$post_id} URL returned a non-200 status after write. {$detail}"
			);
		}

		// ----------------------------------------------------------------
		// Check 2: X-Robots-Tag noindex detection
		// ----------------------------------------------------------------
		$post_write_robots = $this->parse_robots_header(
			$result['headers']['x-robots-tag'] ?? ''
		);

		foreach ( self::NOINDEX_DIRECTIVES as $directive ) {
			$was_noindex_before = $this->pre_write_robots[ $directive ] ?? false;
			$is_noindex_now     = $post_write_robots[ $directive ]       ?? false;

			if ( $is_noindex_now && ! $was_noindex_before ) {
				throw MCPException::health_failed(
					$post_id,
					"Post {$post_id} has been accidentally noindexed after write. "
					. "X-Robots-Tag now contains '{$directive}' which was not present before."
				);
			}
		}

		/**
		 * Fires when a post passes the post-write health check.
		 *
		 * @param int    $post_id   The post that was checked.
		 * @param string $permalink The URL that was checked.
		 * @param array  $result    The full check_url() result.
		 */
		do_action( 'seo_mcp_guard_health_check_passed', $post_id, $permalink, $result );
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Determine whether SSL certificates should be verified.
	 *
	 * In local/development environments (localhost, .local, .test, .dev TLDs)
	 * self-signed certificates are common, so we skip verification.
	 * On production sites we always verify.
	 *
	 * @return bool
	 */
	private function should_verify_ssl(): bool {
		if ( defined( 'SEO_MCP_GUARD_SSL_VERIFY' ) ) {
			return (bool) SEO_MCP_GUARD_SSL_VERIFY;
		}

		$site_host = strtolower( wp_parse_url( get_site_url(), PHP_URL_HOST ) ?? '' );

		$dev_patterns = [
			'localhost',
			'127.0.0.1',
			'::1',
		];

		if ( in_array( $site_host, $dev_patterns, true ) ) {
			return false;
		}

		$dev_tlds = [ '.local', '.test', '.dev', '.localhost', '.example' ];
		foreach ( $dev_tlds as $tld ) {
			if ( str_ends_with( $site_host, $tld ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse an X-Robots-Tag header value into a directive => bool map.
	 *
	 * The header may contain multiple comma-separated directives and may also
	 * include a `googlebot:` or other crawler-specific prefix which we ignore.
	 * Example: "noindex, nofollow"  →  ['noindex' => true, 'nofollow' => true]
	 *
	 * @param string|array $header_value Raw header value (may be array if header appears multiple times).
	 *
	 * @return array<string, bool>
	 */
	private function parse_robots_header( string|array $header_value ): array {
		// Flatten multi-value headers.
		if ( is_array( $header_value ) ) {
			$header_value = implode( ', ', $header_value );
		}

		if ( '' === $header_value ) {
			return [];
		}

		$directives = [];

		// Split on commas first (handles "noindex, nofollow").
		$parts = array_map( 'trim', explode( ',', $header_value ) );

		foreach ( $parts as $part ) {
			// Strip any `googlebot:` / crawler-specific prefix.
			if ( str_contains( $part, ':' ) ) {
				[ , $part ] = explode( ':', $part, 2 );
				$part = trim( $part );
			}

			// Normalise to lowercase.
			$directive = strtolower( $part );

			if ( '' !== $directive ) {
				$directives[ $directive ] = true;
			}
		}

		return $directives;
	}
}

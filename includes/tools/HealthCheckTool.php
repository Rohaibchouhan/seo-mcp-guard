<?php
declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;

/**
 * HealthCheckTool
 *
 * Fetches the live rendered page for a given post and verifies:
 * - HTTP 200 response
 * - No X-Robots-Tag: noindex header
 * - Post title present in <title> tag
 * - Rank Math meta description present in <head>
 * - Rank Math robots meta tag is not noindex
 *
 * @package SEOMCPGuard\Tools
 */
class HealthCheckTool extends BaseTool {

	/** Request timeout in seconds for the health-check fetch. */
	private const REQUEST_TIMEOUT = 15;

	/** Maximum response body size to download (bytes) for head analysis. */
	private const MAX_BODY_BYTES = 65536; // 64 KB — enough to cover <head>.

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'health_check';
	}

	/**
	 * Execute the health check.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int $post_id ID of the post/page to check.
	 * }
	 * @return array<string,mixed>
	 * @throws MCPException If post does not exist or is not publicly accessible.
	 */
	public function execute( array $args ): array {
		$post_id = $this->require_int( $args, 'post_id' );
		$post    = $this->get_post_or_fail( $post_id );

		$url = (string) get_permalink( $post_id );

		if ( $url === '' || $url === false ) {
			throw new MCPException( 'VALIDATION', "Could not determine permalink for post {$post_id}." );
		}

		// --- Make the HTTP GET request ----------------------------------------
		$start_time = microtime( true );

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => 'SEO-MCP-Guard/1.0 (health check)',
				'sslverify'  => apply_filters( 'seo_mcp_guard_health_check_sslverify', false ),
				'headers'    => [
					'Accept'          => 'text/html,application/xhtml+xml',
					'Accept-Encoding' => 'identity', // Avoid compressed responses for simple parsing.
				],
			]
		);

		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		// --- Handle WP_Error --------------------------------------------------
		if ( is_wp_error( $response ) ) {
			return [
				'url'              => $url,
				'status_code'      => 0,
				'accessible'       => false,
				'noindexed'        => null,
				'has_title'        => null,
				'has_description'  => null,
				'response_time_ms' => $elapsed_ms,
				'checks'           => [],
				'ok'               => false,
				'error'            => $response->get_error_message(),
			];
		}

		// --- Extract response parts ------------------------------------------
		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$headers      = wp_remote_retrieve_headers( $response );
		$body         = substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_BODY_BYTES );

		// --- Perform individual checks ----------------------------------------
		$checks = [];

		// 1. Status code.
		$checks['http_status'] = $this->check_http_status( $status_code );

		// 2. X-Robots-Tag header.
		$x_robots_header    = $this->get_header( $headers, 'x-robots-tag' );
		$checks['x_robots'] = $this->check_x_robots( $x_robots_header );

		// 3. Meta robots in <head>.
		$meta_robots            = $this->extract_meta_robots( $body );
		$checks['meta_robots']  = $this->check_meta_robots( $meta_robots );

		// 4. Title tag present.
		$page_title            = $this->extract_title_tag( $body );
		$checks['title_tag']   = $this->check_title_tag( $page_title, get_the_title( $post_id ) );

		// 5. Meta description in <head>.
		$meta_desc                 = $this->extract_meta_description( $body );
		$rm_description            = (string) get_post_meta( $post_id, 'rank_math_description', true );
		$checks['meta_description'] = $this->check_meta_description( $meta_desc, $rm_description, $post_id );

		// 6. Canonical URL in <head>.
		$canonical_in_head     = $this->extract_canonical( $body );
		$checks['canonical']   = $this->check_canonical_tag( $canonical_in_head, $url );

		// --- Aggregate noindex flag (header OR meta) --------------------------
		$is_noindexed = $checks['x_robots']['is_noindex'] || $checks['meta_robots']['is_noindex'];

		// --- Overall ok flag --------------------------------------------------
		$all_ok = $status_code === 200
			&& ! $is_noindexed
			&& $checks['title_tag']['has_title']
			&& $checks['meta_description']['has_description'];

		return [
			'url'              => $url,
			'status_code'      => $status_code,
			'accessible'       => $status_code >= 200 && $status_code < 400,
			'noindexed'        => $is_noindexed,
			'has_title'        => $checks['title_tag']['has_title'],
			'has_description'  => $checks['meta_description']['has_description'],
			'response_time_ms' => $elapsed_ms,
			'checks'           => $checks,
			'ok'               => $all_ok,
		];
	}

	// -------------------------------------------------------------------------
	// Individual check methods
	// -------------------------------------------------------------------------

	/**
	 * @param int $code HTTP status code.
	 * @return array<string,mixed>
	 */
	private function check_http_status( int $code ): array {
		$ok      = $code === 200;
		$status  = $ok ? 'ok' : ( $code >= 500 ? 'error' : 'warning' );
		$message = $ok
			? "HTTP {$code}: Page is accessible."
			: "HTTP {$code}: Unexpected response code.";

		return [
			'code'    => $code,
			'status'  => $status,
			'message' => $message,
		];
	}

	/**
	 * @param string $header_value X-Robots-Tag header value (may be empty).
	 * @return array<string,mixed>
	 */
	private function check_x_robots( string $header_value ): array {
		$is_noindex = $header_value !== '' && str_contains( strtolower( $header_value ), 'noindex' );

		return [
			'value'      => $header_value !== '' ? $header_value : null,
			'is_noindex' => $is_noindex,
			'status'     => $is_noindex ? 'error' : 'ok',
			'message'    => $is_noindex
				? "X-Robots-Tag header contains 'noindex' — page will not be indexed."
				: ( $header_value !== '' ? "X-Robots-Tag: {$header_value}" : 'No X-Robots-Tag header (good).' ),
		];
	}

	/**
	 * @param string $meta_robots Meta robots content value.
	 * @return array<string,mixed>
	 */
	private function check_meta_robots( string $meta_robots ): array {
		$is_noindex = $meta_robots !== '' && str_contains( strtolower( $meta_robots ), 'noindex' );

		return [
			'value'      => $meta_robots !== '' ? $meta_robots : null,
			'is_noindex' => $is_noindex,
			'status'     => $is_noindex ? 'error' : 'ok',
			'message'    => $is_noindex
				? "Meta robots contains 'noindex' — page will not be indexed."
				: ( $meta_robots !== '' ? "Meta robots: {$meta_robots}" : 'No meta robots tag found (defaults to index, follow).' ),
		];
	}

	/**
	 * @param string $page_title   Extracted <title> text.
	 * @param string $post_title   Post title for basic presence check.
	 * @return array<string,mixed>
	 */
	private function check_title_tag( string $page_title, string $post_title ): array {
		$has_title      = $page_title !== '';
		$contains_title = $post_title !== '' && str_contains( $page_title, $post_title );

		return [
			'value'          => $page_title !== '' ? $page_title : null,
			'has_title'      => $has_title,
			'contains_post_title' => $contains_title,
			'status'         => $has_title ? 'ok' : 'error',
			'message'        => $has_title
				? "Title tag found: \"{$page_title}\"."
				: 'No <title> tag found in the page <head>.',
		];
	}

	/**
	 * @param string $meta_desc    Meta description found in the live page.
	 * @param string $rm_desc      Rank Math configured meta description.
	 * @param int    $post_id
	 * @return array<string,mixed>
	 */
	private function check_meta_description( string $meta_desc, string $rm_desc, int $post_id ): array {
		$has_description = $meta_desc !== '';
		$matches_rm      = false;

		if ( $has_description && $rm_desc !== '' ) {
			// Resolve RM variables for comparison.
			$resolved = $this->resolve_rm_variables( $rm_desc, $post_id );
			$matches_rm = trim( $meta_desc ) === trim( $resolved );
		}

		$status = 'ok';
		if ( ! $has_description ) {
			$status = 'error';
		} elseif ( $rm_desc !== '' && ! $matches_rm ) {
			$status = 'warning';
		}

		return [
			'value'           => $meta_desc !== '' ? $meta_desc : null,
			'has_description' => $has_description,
			'matches_rank_math' => $rm_desc !== '' ? $matches_rm : null,
			'status'          => $status,
			'message'         => ! $has_description
				? 'No meta description found in the page <head>.'
				: ( ( $rm_desc !== '' && ! $matches_rm )
					? 'Meta description found but does not match Rank Math setting — check for caching or plugin conflicts.'
					: 'Meta description present.' ),
		];
	}

	/**
	 * @param string $canonical_in_head Canonical href found in live page.
	 * @param string $permalink         Expected permalink.
	 * @return array<string,mixed>
	 */
	private function check_canonical_tag( string $canonical_in_head, string $permalink ): array {
		$has_canonical = $canonical_in_head !== '';

		if ( ! $has_canonical ) {
			return [
				'value'   => null,
				'status'  => 'warning',
				'message' => 'No canonical <link> tag found in <head>.',
			];
		}

		$matches = rtrim( $canonical_in_head, '/' ) === rtrim( $permalink, '/' );

		return [
			'value'   => $canonical_in_head,
			'status'  => $matches ? 'ok' : 'warning',
			'message' => $matches
				? "Canonical tag matches permalink."
				: "Canonical tag ({$canonical_in_head}) does not match permalink ({$permalink}) — verify intentionality.",
		];
	}

	// -------------------------------------------------------------------------
	// HTML extraction helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract the content of the <title> tag.
	 *
	 * @param string $body Raw HTML body (head portion).
	 * @return string
	 */
	private function extract_title_tag( string $body ): string {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $m ) ) {
			return html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Extract the content attribute of <meta name="description">.
	 *
	 * @param string $body
	 * @return string
	 */
	private function extract_meta_description( string $body ): string {
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $body, $m ) ) {
			return html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		// Also try reversed attribute order.
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>/is', $body, $m ) ) {
			return html_entity_decode( trim( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return '';
	}

	/**
	 * Extract the content attribute of <meta name="robots">.
	 *
	 * @param string $body
	 * @return string
	 */
	private function extract_meta_robots( string $body ): string {
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $body, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']robots["\'][^>]*>/is', $body, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Extract the href of <link rel="canonical">.
	 *
	 * @param string $body
	 * @return string
	 */
	private function extract_canonical( string $body ): string {
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/is', $body, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\'][^>]*>/is', $body, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Safely retrieve a header value from a Requests_Utility_CaseInsensitiveDictionary
	 * or array of headers.
	 *
	 * @param mixed  $headers
	 * @param string $key     Header name (lowercase).
	 * @return string
	 */
	private function get_header( mixed $headers, string $key ): string {
		if ( is_array( $headers ) ) {
			$value = $headers[ $key ] ?? $headers[ strtolower( $key ) ] ?? '';
			return is_string( $value ) ? $value : ( is_array( $value ) ? implode( ', ', $value ) : '' );
		}

		if ( $headers instanceof \Requests_Utility_CaseInsensitiveDictionary || method_exists( $headers, 'offsetGet' ) ) {
			try {
				$value = $headers[ $key ] ?? '';
				return is_string( $value ) ? $value : '';
			} catch ( \Throwable ) {
				return '';
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Rank Math variable resolver (lightweight)
	// -------------------------------------------------------------------------

	/**
	 * @param string $template
	 * @param int    $post_id
	 * @return string
	 */
	private function resolve_rm_variables( string $template, int $post_id ): string {
		$replacements = [
			'%title%'    => get_the_title( $post_id ),
			'%sitename%' => get_bloginfo( 'name' ),
			'%sitedesc%' => get_bloginfo( 'description' ),
			'%sep%'      => '-',
		];
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	// -------------------------------------------------------------------------
	// Argument helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $args
	 * @param string              $key
	 * @return int
	 * @throws MCPException
	 */
	private function require_int( array $args, string $key ): int {
		if ( ! isset( $args[ $key ] ) ) {
			throw new MCPException( 'VALIDATION', "Missing required argument: {$key}" );
		}
		$value = (int) $args[ $key ];
		if ( $value <= 0 ) {
			throw new MCPException( 'VALIDATION', "Argument {$key} must be a positive integer." );
		}
		return $value;
	}
}

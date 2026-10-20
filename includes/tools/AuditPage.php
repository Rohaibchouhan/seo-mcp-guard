<?php
declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;

/**
 * AuditPage Tool
 *
 * Performs a comprehensive, read-only SEO audit of a given post/page using
 * Rank Math meta fields, DOM analysis, and live HTTP checks for internal links.
 *
 * @package SEOMCPGuard\Tools
 */
class AuditPage extends BaseTool {

	/** Optimal title length range (inclusive). */
	private const TITLE_MIN = 30;
	private const TITLE_MAX = 60;

	/** Optimal description length range (inclusive). */
	private const DESC_MIN = 120;
	private const DESC_MAX = 160;

	/** Minimum acceptable Rank Math SEO score. */
	private const SCORE_GOOD = 80;
	private const SCORE_WARN = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'audit_page';
	}

	/**
	 * Execute the SEO audit.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int $post_id ID of the post/page to audit.
	 * }
	 * @return array<string,mixed>
	 * @throws MCPException If post does not exist.
	 */
	public function execute( array $args ): array {
		$post_id = $this->require_int( $args, 'post_id' );
		$post    = $this->get_post_or_fail( $post_id );

		$url     = (string) get_permalink( $post_id );
		$content = (string) $post->post_content;

		// --- Rank Math meta --------------------------------------------------
		$rm_title       = (string) get_post_meta( $post_id, 'rank_math_title', true );
		$rm_description = (string) get_post_meta( $post_id, 'rank_math_description', true );
		$rm_keyword     = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		$rm_canonical   = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
		$rm_robots      = (string) get_post_meta( $post_id, 'rank_math_robots', true );
		$rm_score       = (int) get_post_meta( $post_id, 'rank_math_seo_score', true );
		$rm_schema      = get_post_meta( $post_id, 'rank_math_schema', true );
		$rm_schema_type = '';

		// Resolve dynamic title tokens (e.g. %title%, %sep%, %sitename%).
		if ( $rm_title === '' ) {
			$rm_title = get_the_title( $post_id );
		}
		if ( $rm_description === '' ) {
			$rm_description = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}

		// Resolve schema type.
		if ( is_array( $rm_schema ) && ! empty( $rm_schema ) ) {
			$first_schema   = reset( $rm_schema );
			$rm_schema_type = isset( $first_schema['@type'] ) ? (string) $first_schema['@type'] : 'unknown';
		} elseif ( is_string( $rm_schema ) && $rm_schema !== '' ) {
			$rm_schema_type = $rm_schema;
		}

		// Expand %variables% in RM title / description for analysis.
		$resolved_title = $this->resolve_rank_math_variables( $rm_title, $post_id );
		$resolved_desc  = $this->resolve_rank_math_variables( $rm_description, $post_id );

		// --- Parse content DOM -----------------------------------------------
		$dom = $this->parse_html( $content );

		// --- Run individual checks -------------------------------------------
		$checks = [];

		$checks['title']           = $this->check_title( $resolved_title );
		$checks['description']     = $this->check_description( $resolved_desc );
		$checks['focus_keyword']   = $this->check_focus_keyword( $rm_keyword, $resolved_title, $resolved_desc, $content, $dom );
		$checks['h1_count']        = $this->check_h1_count( $dom, $post );
		$checks['images_alt']      = $this->check_images_alt( $dom );
		$checks['canonical']       = $this->check_canonical( $rm_canonical, $url );
		$checks['noindex']         = $this->check_noindex( $rm_robots );
		$checks['broken_internal_links'] = $this->check_broken_internal_links( $dom );
		$checks['schema']          = $this->check_schema( $rm_schema_type );
		$checks['seo_score']       = $this->check_seo_score( $rm_score );

		// --- Summary ---------------------------------------------------------
		$summary = [ 'ok' => 0, 'warnings' => 0, 'errors' => 0 ];
		foreach ( $checks as $check ) {
			$status = $check['status'] ?? 'ok';
			if ( $status === 'ok' ) {
				$summary['ok']++;
			} elseif ( $status === 'warning' ) {
				$summary['warnings']++;
			} elseif ( $status === 'error' ) {
				$summary['errors']++;
			}
		}

		return [
			'post_id'          => $post_id,
			'url'              => $url,
			'checks'           => $checks,
			'needs_developer'  => $this->developer_items(),
			'summary'          => $summary,
		];
	}

	// -------------------------------------------------------------------------
	// Individual checks
	// -------------------------------------------------------------------------

	/**
	 * @param string $title Resolved SEO title.
	 * @return array<string,mixed>
	 */
	private function check_title( string $title ): array {
		$len = mb_strlen( $title );

		if ( $title === '' ) {
			return [
				'value'   => '',
				'length'  => 0,
				'status'  => 'error',
				'message' => 'No SEO title set.',
			];
		}

		if ( $len < self::TITLE_MIN ) {
			$status  = 'warning';
			$message = sprintf( 'Title is too short (%d chars). Aim for %d–%d characters.', $len, self::TITLE_MIN, self::TITLE_MAX );
		} elseif ( $len > self::TITLE_MAX ) {
			$status  = 'warning';
			$message = sprintf( 'Title is too long (%d chars). Aim for %d–%d characters.', $len, self::TITLE_MIN, self::TITLE_MAX );
		} else {
			$status  = 'ok';
			$message = sprintf( 'Title length is optimal (%d chars).', $len );
		}

		return [
			'value'   => $title,
			'length'  => $len,
			'status'  => $status,
			'message' => $message,
		];
	}

	/**
	 * @param string $desc Resolved meta description.
	 * @return array<string,mixed>
	 */
	private function check_description( string $desc ): array {
		$len = mb_strlen( $desc );

		if ( $desc === '' ) {
			return [
				'value'   => '',
				'length'  => 0,
				'status'  => 'error',
				'message' => 'No meta description set.',
			];
		}

		if ( $len < self::DESC_MIN ) {
			$status  = 'warning';
			$message = sprintf( 'Description too short (%d chars). Aim for %d–%d.', $len, self::DESC_MIN, self::DESC_MAX );
		} elseif ( $len > self::DESC_MAX ) {
			$status  = 'warning';
			$message = sprintf( 'Description too long (%d chars). Aim for %d–%d.', $len, self::DESC_MIN, self::DESC_MAX );
		} else {
			$status  = 'ok';
			$message = sprintf( 'Description length is optimal (%d chars).', $len );
		}

		return [
			'value'   => $desc,
			'length'  => $len,
			'status'  => $status,
			'message' => $message,
		];
	}

	/**
	 * @param string       $keyword
	 * @param string       $title
	 * @param string       $desc
	 * @param string       $content Raw post content.
	 * @param \DOMDocument $dom
	 * @return array<string,mixed>
	 */
	private function check_focus_keyword( string $keyword, string $title, string $desc, string $content, \DOMDocument $dom ): array {
		if ( $keyword === '' ) {
			return [
				'value'          => '',
				'in_title'       => false,
				'in_description' => false,
				'in_content'     => false,
				'in_h1'          => false,
				'status'         => 'error',
				'message'        => 'No focus keyword set.',
			];
		}

		$kw_lower    = mb_strtolower( $keyword );
		$in_title    = mb_stripos( $title, $kw_lower ) !== false;
		$in_desc     = mb_stripos( $desc, $kw_lower ) !== false;
		$in_content  = mb_stripos( wp_strip_all_tags( $content ), $kw_lower ) !== false;

		// Check in H1.
		$in_h1 = false;
		foreach ( $dom->getElementsByTagName( 'h1' ) as $h1 ) {
			if ( mb_stripos( $h1->textContent ?? '', $kw_lower ) !== false ) {
				$in_h1 = true;
				break;
			}
		}

		$missing = [];
		if ( ! $in_title ) {
			$missing[] = 'title';
		}
		if ( ! $in_desc ) {
			$missing[] = 'description';
		}
		if ( ! $in_content ) {
			$missing[] = 'content';
		}

		if ( empty( $missing ) ) {
			$status  = 'ok';
			$message = 'Focus keyword found in title, description, and content.';
		} else {
			$status  = 'warning';
			$message = 'Focus keyword missing from: ' . implode( ', ', $missing ) . '.';
		}

		return [
			'value'          => $keyword,
			'in_title'       => $in_title,
			'in_description' => $in_desc,
			'in_content'     => $in_content,
			'in_h1'          => $in_h1,
			'status'         => $status,
			'message'        => $message,
		];
	}

	/**
	 * @param \DOMDocument $dom
	 * @param \WP_Post     $post
	 * @return array<string,mixed>
	 */
	private function check_h1_count( \DOMDocument $dom, \WP_Post $post ): array {
		$h1_nodes = $dom->getElementsByTagName( 'h1' );
		$count    = $h1_nodes->length;

		// The post title is typically rendered as H1 outside the content.
		// If content has 0 H1s (expected), that's fine.
		// If content has 1+ H1s, it may conflict with the post title's H1.
		if ( $count === 0 ) {
			$status  = 'ok';
			$message = 'No H1 inside content body (post title renders as H1).';
		} elseif ( $count === 1 ) {
			$status  = 'warning';
			$message = 'One H1 found inside content — ensure it does not duplicate the post title H1.';
		} else {
			$status  = 'error';
			$message = "Multiple H1s ({$count}) found inside content. Only one H1 per page is recommended.";
		}

		return [
			'count'   => $count,
			'status'  => $status,
			'message' => $message,
		];
	}

	/**
	 * @param \DOMDocument $dom
	 * @return array<string,mixed>
	 */
	private function check_images_alt( \DOMDocument $dom ): array {
		$images      = $dom->getElementsByTagName( 'img' );
		$total       = $images->length;
		$missing     = [];
		$missing_src = [];

		foreach ( $images as $img ) {
			/** @var \DOMElement $img */
			$alt = $img->getAttribute( 'alt' );
			$src = $img->getAttribute( 'src' );

			if ( trim( $alt ) === '' ) {
				$missing_src[] = $src !== '' ? $src : '(no src)';
				$missing[]     = true;
			}
		}

		$missing_count = count( $missing );

		if ( $missing_count === 0 ) {
			$status  = 'ok';
			$message = "All {$total} image(s) have alt text.";
		} else {
			$status  = 'warning';
			$message = "{$missing_count} of {$total} image(s) are missing alt text.";
		}

		return [
			'total_images' => $total,
			'missing_alt'  => $missing_count,
			'missing_srcs' => $missing_src,
			'status'       => $status,
			'message'      => $message,
		];
	}

	/**
	 * @param string $canonical RM canonical URL.
	 * @param string $permalink Post permalink.
	 * @return array<string,mixed>
	 */
	private function check_canonical( string $canonical, string $permalink ): array {
		if ( $canonical === '' ) {
			// No explicit canonical set — will default to permalink (acceptable).
			return [
				'value'   => $permalink,
				'status'  => 'ok',
				'message' => 'Canonical defaults to permalink (no override set).',
			];
		}

		// Warn if canonical is explicitly set to something different.
		$same = rtrim( $canonical, '/' ) === rtrim( $permalink, '/' );

		return [
			'value'   => $canonical,
			'status'  => $same ? 'ok' : 'warning',
			'message' => $same
				? 'Canonical matches permalink.'
				: 'Canonical is set to a different URL than the permalink — verify this is intentional.',
		];
	}

	/**
	 * @param string $robots RM robots meta value.
	 * @return array<string,mixed>
	 */
	private function check_noindex( string $robots ): array {
		$robots_lower = strtolower( $robots );
		$is_noindex   = str_contains( $robots_lower, 'noindex' );

		return [
			'is_noindexed' => $is_noindex,
			'robots_value' => $robots !== '' ? $robots : 'index, follow (default)',
			'status'       => $is_noindex ? 'error' : 'ok',
			'message'      => $is_noindex
				? 'Page is set to noindex — it will not appear in search results.'
				: 'Page is indexable.',
		];
	}

	/**
	 * Find all internal links in content and perform HEAD requests to check reachability.
	 *
	 * @param \DOMDocument $dom
	 * @return array<string,mixed>
	 */
	private function check_broken_internal_links( \DOMDocument $dom ): array {
		$home_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$checked_urls = [];
		$broken       = [];

		$anchors = $dom->getElementsByTagName( 'a' );
		$seen    = [];

		foreach ( $anchors as $a ) {
			/** @var \DOMElement $a */
			$href = trim( $a->getAttribute( 'href' ) );
			if ( $href === '' || str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
				continue;
			}

			// Normalise relative URLs.
			if ( str_starts_with( $href, '/' ) ) {
				$href = home_url( $href );
			}

			if ( ! filter_var( $href, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			// Only check internal links.
			$href_host = (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( $href_host !== $home_host ) {
				continue;
			}

			if ( isset( $seen[ $href ] ) ) {
				continue;
			}
			$seen[ $href ] = true;

			$checked_urls[] = $href;

			$response = wp_remote_head(
				$href,
				[
					'timeout'    => 8,
					'user-agent' => 'SEO-MCP-Guard/1.0 (internal link audit)',
					'sslverify'  => false,
				]
			);

			if ( is_wp_error( $response ) ) {
				$broken[] = [
					'url'    => $href,
					'reason' => $response->get_error_message(),
				];
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code === 0 || ( $code >= 400 && $code !== 401 && $code !== 403 ) ) {
				$broken[] = [
					'url'    => $href,
					'code'   => $code,
					'reason' => wp_remote_retrieve_response_message( $response ),
				];
			}
		}

		$broken_count = count( $broken );

		return [
			'checked' => $checked_urls,
			'broken'  => $broken,
			'status'  => $broken_count === 0 ? 'ok' : 'error',
			'message' => $broken_count === 0
				? sprintf( 'All %d internal link(s) are reachable.', count( $checked_urls ) )
				: sprintf( '%d broken internal link(s) found.', $broken_count ),
		];
	}

	/**
	 * @param string $schema_type
	 * @return array<string,mixed>
	 */
	private function check_schema( string $schema_type ): array {
		$has_schema = $schema_type !== '';

		return [
			'has_schema' => $has_schema,
			'type'       => $has_schema ? $schema_type : null,
			'status'     => $has_schema ? 'ok' : 'warning',
			'message'    => $has_schema
				? "Schema markup present: {$schema_type}."
				: 'No schema markup detected. Consider adding structured data.',
		];
	}

	/**
	 * @param int $score Rank Math SEO score (0–100).
	 * @return array<string,mixed>
	 */
	private function check_seo_score( int $score ): array {
		if ( $score === 0 ) {
			$status  = 'warning';
			$message = 'SEO score not calculated yet (run Rank Math analysis).';
		} elseif ( $score >= self::SCORE_GOOD ) {
			$status  = 'ok';
			$message = "Good SEO score ({$score}/100).";
		} elseif ( $score >= self::SCORE_WARN ) {
			$status  = 'warning';
			$message = "Moderate SEO score ({$score}/100). There is room for improvement.";
		} else {
			$status  = 'error';
			$message = "Poor SEO score ({$score}/100). Significant improvements needed.";
		}

		return [
			'score'   => $score,
			'status'  => $status,
			'message' => $message,
		];
	}

	// -------------------------------------------------------------------------
	// Developer items (issues this tool cannot fix)
	// -------------------------------------------------------------------------

	/**
	 * Return a list of SEO concerns that require a developer.
	 *
	 * @return list<string>
	 */
	private function developer_items(): array {
		return [
			'Core Web Vitals (LCP, FID/INP, CLS) — requires performance engineering.',
			'Page speed optimisation (image compression, caching, CDN configuration).',
			'Structured data errors requiring code changes (invalid schema markup).',
			'Hreflang configuration for multilingual/multiregional sites.',
			'XML sitemap generation issues (requires server-level or plugin configuration).',
			'Server-side redirects (htaccess / Nginx config outside of Rank Math).',
			'Canonical tag conflicts caused by theme or third-party plugins.',
		];
	}

	// -------------------------------------------------------------------------
	// Utility helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse raw HTML/Gutenberg content into a DOMDocument for querying.
	 *
	 * @param string $content
	 * @return \DOMDocument
	 */
	private function parse_html( string $content ): \DOMDocument {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Resolve common Rank Math title/description variable placeholders.
	 *
	 * This is a lightweight resolver for the most common tokens — for full
	 * resolution, Rank Math's own API should be used when available.
	 *
	 * @param string $template  Raw template string (e.g. "%title% %sep% %sitename%").
	 * @param int    $post_id
	 * @return string
	 */
	private function resolve_rank_math_variables( string $template, int $post_id ): string {
		if ( $template === '' ) {
			return '';
		}

		$replacements = [
			'%title%'        => get_the_title( $post_id ),
			'%sitename%'     => get_bloginfo( 'name' ),
			'%sitedesc%'     => get_bloginfo( 'description' ),
			'%sep%'          => '-',
			'%page%'         => '',
			'%pagenumber%'   => '',
			'%pagetotal%'    => '',
			'%currentyear%'  => (string) gmdate( 'Y' ),
			'%currentmonth%' => (string) gmdate( 'F' ),
			'%currentday%'   => (string) gmdate( 'j' ),
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

<?php
/**
 * GetSEO MCP tool — retrieve all Rank Math SEO data plus content analysis.
 *
 * @package SEOMCPGuard\Tools
 */

declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve comprehensive SEO metadata and content analysis for a single post.
 */
class GetSEO extends BaseTool {

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'get_seo';
	}

	/**
	 * Execute the get_seo tool.
	 *
	 * Expected $args keys:
	 *  - post_id (int, required)
	 *
	 * @param array<string,mixed> $args
	 *
	 * @return array<string,mixed>
	 *
	 * @throws MCPException On validation or permission failure.
	 */
	public function execute( array $args ): array {

		// ------------------------------------------------------------------
		// 1. Validate post_id
		// ------------------------------------------------------------------
		if ( ! isset( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			throw MCPException::validation( 'The "post_id" argument is required and must be an integer.' );
		}

		$post_id = (int) $args['post_id'];
		$post    = $this->get_post_or_fail( $post_id );

		// ------------------------------------------------------------------
		// 2. Rank Math meta fields
		// ------------------------------------------------------------------
		$rank_math_fields = [
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
			'rank_math_canonical_url',
			'rank_math_robots',
			'rank_math_facebook_title',
			'rank_math_facebook_description',
			'rank_math_twitter_title',
			'rank_math_twitter_description',
			'rank_math_rich_snippet',
			'rank_math_seo_score',
			'rank_math_content_ai_score',
		];

		$seo_meta = [];
		foreach ( $rank_math_fields as $field ) {
			$seo_meta[ $field ] = get_post_meta( $post_id, $field, true );
		}

		// ------------------------------------------------------------------
		// 3. Content analysis helpers
		// ------------------------------------------------------------------
		$content = $post->post_content;

		// Word count — strip shortcodes and HTML tags first.
		$plain_content = strip_tags( strip_shortcodes( $content ) );
		$plain_content = html_entity_decode( $plain_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$word_count    = (int) str_word_count( $plain_content );

		// Headings outline — extract h1–h6 with their text.
		$headings_outline = $this->extract_headings_outline( $content );

		// Images missing alt — find <img> where alt="" or alt attribute absent.
		$images_missing_alt = $this->extract_images_missing_alt( $content );

		// Title and description lengths (Rank Math title first, fall back to post title).
		$seo_title       = ! empty( $seo_meta['rank_math_title'] )
			? (string) $seo_meta['rank_math_title']
			: get_the_title( $post );
		$seo_description = (string) ( $seo_meta['rank_math_description'] ?? '' );
		$title_length    = mb_strlen( $seo_title, 'UTF-8' );
		$desc_length     = mb_strlen( $seo_description, 'UTF-8' );

		// H1 count.
		$h1_count = $this->count_heading( $content, 1 );

		// ------------------------------------------------------------------
		// 4. Return structured result
		// ------------------------------------------------------------------
		return array_merge(
			[
				'post_id'     => $post_id,
				'post_title'  => $post->post_title,
				'post_status' => $post->post_status,
				'post_type'   => $post->post_type,
				'permalink'   => get_permalink( $post ),
			],
			$seo_meta,
			[
				'content_analysis' => [
					'word_count'          => $word_count,
					'headings_outline'    => $headings_outline,
					'images_missing_alt'  => $images_missing_alt,
					'title_length'        => $title_length,
					'description_length'  => $desc_length,
					'h1_count'            => $h1_count,
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse post_content and extract all heading tags (h1–h6) with level and text.
	 *
	 * @param string $content Raw post content HTML.
	 *
	 * @return list<array{level: int, text: string}>
	 */
	private function extract_headings_outline( string $content ): array {
		$headings = [];

		if ( '' === $content ) {
			return $headings;
		}

		$pattern = '/<h([1-6])[^>]*>(.*?)<\/h\1>/is';

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$headings[] = [
					'level' => (int) $match[1],
					'text'  => wp_strip_all_tags( $match[2] ),
				];
			}
		}

		return $headings;
	}

	/**
	 * Find all <img> elements in content that are missing an alt attribute
	 * or have an empty alt attribute. Returns a list of src values.
	 *
	 * @param string $content Raw post content HTML.
	 *
	 * @return list<string> src attribute values of offending images.
	 */
	private function extract_images_missing_alt( string $content ): array {
		$missing = [];

		if ( '' === $content ) {
			return $missing;
		}

		// Match all <img ...> tags.
		if ( ! preg_match_all( '/<img\s[^>]*>/is', $content, $img_matches ) ) {
			return $missing;
		}

		foreach ( $img_matches[0] as $img_tag ) {
			// Extract the src attribute.
			$src = '';
			if ( preg_match( '/\bsrc=["\']([^"\']*)["\']/', $img_tag, $src_match ) ) {
				$src = $src_match[1];
			}

			// Check for missing alt or empty alt.
			$has_alt   = (bool) preg_match( '/\balt=/', $img_tag );
			$empty_alt = false;

			if ( $has_alt ) {
				// alt="" or alt=''
				if ( preg_match( '/\balt=["\']["\']/', $img_tag ) ) {
					$empty_alt = true;
				}
			}

			if ( ! $has_alt || $empty_alt ) {
				$missing[] = $src;
			}
		}

		return $missing;
	}

	/**
	 * Count occurrences of a specific heading level in content.
	 *
	 * @param string $content Raw post content HTML.
	 * @param int    $level   Heading level 1–6.
	 *
	 * @return int
	 */
	private function count_heading( string $content, int $level ): int {
		if ( '' === $content ) {
			return 0;
		}
		$pattern = sprintf( '/<h%1$d[^>]*>.*?<\/h%1$d>/is', $level );
		return (int) preg_match_all( $pattern, $content );
	}
}

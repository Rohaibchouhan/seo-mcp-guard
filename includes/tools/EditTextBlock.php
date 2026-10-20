<?php
declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;
use SEOMCPGuard\Safety\SafetyEngine;

/**
 * EditTextBlock Tool
 *
 * Safely replaces text within a specific Gutenberg block, enforcing a strict
 * structural-fingerprint check so that only text content — never markup,
 * classes, hrefs, shortcodes, or block markers — can change.
 *
 * @package SEOMCPGuard\Tools
 */
class EditTextBlock extends BaseTool {

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'edit_text_block';
	}

	/**
	 * Execute the edit_text_block operation.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $post_id     ID of the post to modify.
	 *     @type int    $block_index 0-based index of the Gutenberg block to edit.
	 *     @type string $old_text    Exact text to find inside the block innerHTML.
	 *     @type string $new_text    Replacement text.
	 *     @type bool   $dry_run     Default true. Set false to actually save.
	 * }
	 * @return array<string,mixed>
	 * @throws MCPException On validation or structural-safety failure.
	 */
	public function execute( array $args ): array {
		$post_id     = $this->require_int( $args, 'post_id' );
		$block_index = $this->require_non_negative_int( $args, 'block_index' );
		$old_text    = $this->require_string( $args, 'old_text' );
		$new_text    = isset( $args['new_text'] ) ? (string) $args['new_text'] : '';
		$dry_run     = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

		// --- Validate post ---------------------------------------------------
		$post = $this->get_post_or_fail( $post_id );

		// --- Refuse page-builder pages ----------------------------------------
		$this->check_page_builder( $post_id );

		$original_content = (string) $post->post_content;

		// --- Parse into Gutenberg blocks -------------------------------------
		$blocks = parse_blocks( $original_content );

		// Remove empty filler blocks (whitespace-only, no block name).
		$indexed_blocks = array_values(
			array_filter(
				$blocks,
				static fn( array $b ) => ! ( $b['blockName'] === null && trim( $b['innerHTML'] ) === '' )
			)
		);

		$block_count = count( $indexed_blocks );

		if ( $block_index >= $block_count ) {
			throw new MCPException(
				'VALIDATION',
				sprintf(
					'block_index %d is out of range. Post has %d content block(s) (0-based).',
					$block_index,
					$block_count
				)
			);
		}

		$block        = $indexed_blocks[ $block_index ];
		$block_html   = (string) $block['innerHTML'];
		$block_name   = (string) ( $block['blockName'] ?? 'unknown' );

		// --- Verify old_text exists in block innerHTML -----------------------
		if ( strpos( $block_html, $old_text ) === false ) {
			throw new MCPException(
				'VALIDATION',
				sprintf(
					'old_text not found in block %d (%s).',
					$block_index,
					$block_name
				)
			);
		}

		// --- Structural fingerprint BEFORE -----------------------------------
		$before_fp = $this->fingerprint( $block_html );

		// --- Perform text replacement ----------------------------------------
		// Replace only plain text, never inside HTML attributes.
		$new_block_html = $this->replace_text_only( $block_html, $old_text, $new_text );

		// --- Structural fingerprint AFTER ------------------------------------
		$after_fp = $this->fingerprint( $new_block_html );

		// --- Assert no structural changes ------------------------------------
		$this->assert_no_structural_change( $before_fp, $after_fp );

		// --- Reconstruct post content ----------------------------------------
		// Update the block's innerHTML and innerContent in the parsed structure.
		$modified_block                  = $block;
		$modified_block['innerHTML']     = $new_block_html;
		$modified_block['innerContent']  = $this->rebuild_inner_content(
			$block['innerContent'] ?? [],
			$old_text,
			$new_text
		);

		// Splice modified block back.
		$indexed_blocks[ $block_index ] = $modified_block;
		$new_content                    = serialize_blocks( $indexed_blocks );

		// --- Context snippet -------------------------------------------------
		$context = $this->extract_context( $new_block_html, $new_text );

		if ( $dry_run ) {
			return [
				'dry_run'      => true,
				'post_id'      => $post_id,
				'block_index'  => $block_index,
				'block_name'   => $block_name,
				'old_text'     => $old_text,
				'new_text'     => $new_text,
				'context'      => $context,
				'message'      => 'Dry run: text block edit is valid and would be saved.',
			];
		}

		// --- Persist via SafetyEngine ----------------------------------------
		$batch_id        = $this->generate_batch_id();
		$idempotency_key = $this->build_idempotency_key( 'edit_text_block', $args );

		$write_fn = function () use ( $post_id, $new_content ) {
			$result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $new_content,
				],
				true
			);

			if ( is_wp_error( $result ) ) {
				throw new MCPException(
					'INTERNAL',
					'wp_update_post failed: ' . $result->get_error_message()
				);
			}
		};

		$safety_res = $this->safety->execute_write(
			post_id: $post_id,
			tool: 'edit_text_block',
			write_fn: $write_fn,
			old_values: [ 'post_content' => $original_content ],
			new_values: [ 'post_content' => $new_content ],
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		return [
			'dry_run'      => false,
			'post_id'      => $post_id,
			'block_index'  => $block_index,
			'block_name'   => $block_name,
			'old_text'     => $old_text,
			'new_text'     => $new_text,
			'context'      => $context,
			'change_id'    => $safety_res['change_id'] ?? '',
			'batch_id'     => $batch_id,
			'message'      => 'Text block updated successfully.',
		];
	}

	// -------------------------------------------------------------------------
	// Text replacement (text nodes only, not in attributes)
	// -------------------------------------------------------------------------

	/**
	 * Replace the first occurrence of $old_text in plain text nodes of $html,
	 * never touching attribute values.
	 *
	 * Strategy: split by HTML tags, replace in non-tag segments.
	 *
	 * @param string $html     Block innerHTML.
	 * @param string $old_text Exact text to replace.
	 * @param string $new_text Replacement text.
	 * @return string Modified HTML.
	 */
	private function replace_text_only( string $html, string $old_text, string $new_text ): string {
		// Split by HTML tags (keep delimiters).
		$parts    = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$replaced = false;
		$result   = '';

		foreach ( (array) $parts as $part ) {
			if ( ! $replaced && ! str_starts_with( $part, '<' ) ) {
				// This is a text node — safe to replace.
				if ( strpos( $part, $old_text ) !== false ) {
					$part     = $this->str_replace_first( $old_text, $new_text, $part );
					$replaced = true;
				}
			}
			$result .= $part;
		}

		return $result;
	}

	/**
	 * Replace only the first occurrence of $search with $replace in $subject.
	 *
	 * @param string $search
	 * @param string $replace
	 * @param string $subject
	 * @return string
	 */
	private function str_replace_first( string $search, string $replace, string $subject ): string {
		$pos = strpos( $subject, $search );
		if ( $pos === false ) {
			return $subject;
		}
		return substr( $subject, 0, $pos ) . $replace . substr( $subject, $pos + strlen( $search ) );
	}

	/**
	 * Rebuild innerContent array with text replaced (mirrors innerHTML change).
	 *
	 * @param list<string|null> $inner_content Original innerContent.
	 * @param string            $old_text
	 * @param string            $new_text
	 * @return list<string|null>
	 */
	private function rebuild_inner_content( array $inner_content, string $old_text, string $new_text ): array {
		$replaced = false;
		$result   = [];

		foreach ( $inner_content as $item ) {
			if ( ! $replaced && is_string( $item ) && strpos( $item, $old_text ) !== false ) {
				$item     = $this->str_replace_first( $old_text, $new_text, $item );
				$replaced = true;
			}
			$result[] = $item;
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Structural fingerprinting
	// -------------------------------------------------------------------------

	/**
	 * Compute a structural fingerprint of block innerHTML.
	 *
	 * @param string $html
	 * @return array{
	 *     tag_counts: array<string,int>,
	 *     shortcodes: list<string>,
	 *     block_markers: list<string>,
	 *     classes: list<string>,
	 *     link_hrefs: list<string>
	 * }
	 */
	private function fingerprint( string $html ): array {
		return [
			'tag_counts'    => $this->count_tags( $html ),
			'shortcodes'    => $this->extract_shortcodes( $html ),
			'block_markers' => $this->extract_block_markers( $html ),
			'classes'       => $this->extract_css_classes( $html ),
			'link_hrefs'    => $this->extract_link_hrefs( $html ),
		];
	}

	/**
	 * Count occurrences of each HTML tag in the string.
	 *
	 * @param string $html
	 * @return array<string,int>
	 */
	private function count_tags( string $html ): array {
		preg_match_all( '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/i', $html, $matches );
		$counts = [];
		foreach ( $matches[1] as $tag ) {
			$tag            = strtolower( $tag );
			$counts[ $tag ] = ( $counts[ $tag ] ?? 0 ) + 1;
		}
		ksort( $counts );
		return $counts;
	}

	/**
	 * Extract shortcode names from HTML.
	 *
	 * @param string $html
	 * @return list<string>
	 */
	private function extract_shortcodes( string $html ): array {
		preg_match_all( '/\[([\w_-]+)/', $html, $matches );
		$sc = array_unique( $matches[1] );
		sort( $sc );
		return array_values( $sc );
	}

	/**
	 * Extract Gutenberg block comment marker names.
	 *
	 * @param string $html
	 * @return list<string>
	 */
	private function extract_block_markers( string $html ): array {
		preg_match_all( '/<!--\s*(\/?wp:[^\s>]+)/', $html, $matches );
		$markers = array_unique( $matches[1] );
		sort( $markers );
		return array_values( $markers );
	}

	/**
	 * Extract sorted list of unique CSS class names.
	 *
	 * @param string $html
	 * @return list<string>
	 */
	private function extract_css_classes( string $html ): array {
		preg_match_all( '/class=["\']([^"\']+)["\']/', $html, $matches );
		$classes = [];
		foreach ( $matches[1] as $cls_string ) {
			foreach ( explode( ' ', $cls_string ) as $cls ) {
				$cls = trim( $cls );
				if ( $cls !== '' ) {
					$classes[] = $cls;
				}
			}
		}
		$classes = array_unique( $classes );
		sort( $classes );
		return array_values( $classes );
	}

	/**
	 * Extract sorted list of unique href attribute values.
	 *
	 * @param string $html
	 * @return list<string>
	 */
	private function extract_link_hrefs( string $html ): array {
		preg_match_all( '/href=["\']([^"\']+)["\']/', $html, $matches );
		$hrefs = array_unique( $matches[1] );
		sort( $hrefs );
		return array_values( $hrefs );
	}

	/**
	 * Assert that no structural element changed between before and after fingerprints.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @throws MCPException On any structural mismatch.
	 */
	private function assert_no_structural_change( array $before, array $after ): void {
		$checks = [
			'tag_counts'    => 'HTML tag counts',
			'shortcodes'    => 'shortcodes',
			'block_markers' => 'Gutenberg block markers',
			'classes'       => 'CSS classes',
			'link_hrefs'    => 'link href values',
		];

		foreach ( $checks as $key => $label ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				throw new MCPException(
					'VALIDATION',
					"Structure changed: only text content may be modified. Difference detected in {$label}."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Context snippet
	// -------------------------------------------------------------------------

	/**
	 * Extract a short context snippet around the replacement text.
	 *
	 * @param string $html     Modified block innerHTML.
	 * @param string $new_text Replacement text.
	 * @return string
	 */
	private function extract_context( string $html, string $new_text ): string {
		if ( $new_text === '' ) {
			return '';
		}
		$pos = strpos( $html, $new_text );
		if ( $pos === false ) {
			return '';
		}
		$start = max( 0, $pos - 80 );
		$end   = min( strlen( $html ), $pos + strlen( $new_text ) + 80 );
		return '...' . substr( $html, $start, $end - $start ) . '...';
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

	/**
	 * @param array<string,mixed> $args
	 * @param string              $key
	 * @return int  (>= 0)
	 * @throws MCPException
	 */
	private function require_non_negative_int( array $args, string $key ): int {
		if ( ! isset( $args[ $key ] ) ) {
			throw new MCPException( 'VALIDATION', "Missing required argument: {$key}" );
		}
		$value = (int) $args[ $key ];
		if ( $value < 0 ) {
			throw new MCPException( 'VALIDATION', "Argument {$key} must be >= 0." );
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $args
	 * @param string              $key
	 * @return string
	 * @throws MCPException
	 */
	private function require_string( array $args, string $key ): string {
		if ( ! isset( $args[ $key ] ) || (string) $args[ $key ] === '' ) {
			throw new MCPException( 'VALIDATION', "Missing required argument: {$key}" );
		}
		return (string) $args[ $key ];
	}
}

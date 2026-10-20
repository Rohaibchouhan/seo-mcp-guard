<?php
declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;
use SEOMCPGuard\Safety\SafetyEngine;

/**
 * AddInternalLink Tool
 *
 * Safely wraps the first plain-text occurrence of anchor_text with an <a> link,
 * enforcing strict structural-fingerprint validation to prevent content corruption.
 *
 * @package SEOMCPGuard\Tools
 */
class AddInternalLink extends BaseTool {

	/** Maximum allowed anchor text length. */
	private const MAX_ANCHOR_LENGTH = 200;

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'add_internal_link';
	}

	/**
	 * Execute the add_internal_link operation.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $post_id     ID of the post to modify.
	 *     @type string $anchor_text Exact text to find and wrap as a link.
	 *     @type string $target_url  Absolute URL the link should point to.
	 *     @type bool   $dry_run     Default true. Set false to actually save.
	 * }
	 * @return array<string,mixed>
	 * @throws MCPException On validation or structural-safety failure.
	 */
	public function execute( array $args ): array {
		$post_id     = $this->require_int( $args, 'post_id' );
		$anchor_text = $this->require_string( $args, 'anchor_text' );
		$target_url  = $this->require_string( $args, 'target_url' );
		$dry_run     = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

		// --- Validate post ----------------------------------------------------
		$post = $this->get_post_or_fail( $post_id );

		// --- Refuse page-builder pages ----------------------------------------
		$this->check_page_builder( $post_id );

		// --- Validate target URL ---------------------------------------------
		if ( ! filter_var( $target_url, FILTER_VALIDATE_URL ) ) {
			throw new MCPException( 'VALIDATION', 'target_url must be a valid absolute URL.' );
		}

		// --- Validate anchor text --------------------------------------------
		if ( mb_strlen( $anchor_text ) > self::MAX_ANCHOR_LENGTH ) {
			throw new MCPException(
				'VALIDATION',
				sprintf( 'anchor_text must be fewer than %d characters.', self::MAX_ANCHOR_LENGTH )
			);
		}

		$original_content = (string) $post->post_content;

		// --- Fingerprint before modification ----------------------------------
		$before_fp = $this->fingerprint( $original_content );

		// --- Find and replace the first eligible occurrence ------------------
		$new_content = $this->insert_link( $original_content, $anchor_text, $target_url );

		// --- Fingerprint after modification -----------------------------------
		$after_fp = $this->fingerprint( $new_content );

		// --- Structural-safety assertion -------------------------------------
		$this->assert_only_one_new_anchor( $before_fp, $after_fp );

		// --- Build context snippet -------------------------------------------
		$context = $this->extract_context( $new_content, $anchor_text, $target_url );

		if ( $dry_run ) {
			return [
				'dry_run'     => true,
				'post_id'     => $post_id,
				'anchor_text' => $anchor_text,
				'target_url'  => $target_url,
				'context'     => $context,
				'message'     => 'Dry run: link would be inserted safely.',
			];
		}

		// --- Persist via SafetyEngine ----------------------------------------
		$batch_id        = $this->generate_batch_id();
		$idempotency_key = $this->build_idempotency_key( 'add_internal_link', $args );

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
			tool: 'add_internal_link',
			write_fn: $write_fn,
			old_values: [ 'post_content' => $original_content ],
			new_values: [ 'post_content' => $new_content ],
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		return [
			'dry_run'     => false,
			'post_id'     => $post_id,
			'anchor_text' => $anchor_text,
			'target_url'  => $target_url,
			'context'     => $context,
			'change_id'   => $safety_res['change_id'] ?? '',
			'batch_id'    => $batch_id,
			'message'     => 'Internal link inserted successfully.',
		];
	}

	// -------------------------------------------------------------------------
	// Core link-insertion logic
	// -------------------------------------------------------------------------

	/**
	 * Find the first eligible plain-text occurrence of $anchor_text and wrap it.
	 *
	 * Strategy:
	 * 1. Split the content into segments around Gutenberg block comments.
	 * 2. For each non-block-comment text segment, search only inside plain
	 *    paragraph/text nodes (not in HTML tags, attributes, or headings).
	 * 3. Replace only the very first eligible match across the whole document.
	 *
	 * @param string $content     Original post content.
	 * @param string $anchor_text Text to find.
	 * @param string $target_url  Href for the new link.
	 * @return string Modified content.
	 * @throws MCPException If anchor_text is not found in eligible positions.
	 */
	private function insert_link( string $content, string $anchor_text, string $target_url ): string {
		// Split on Gutenberg block comments so we never touch them.
		// Pattern matches <!-- wp:xxx --> and <!-- /wp:xxx -->
		$block_comment_pattern = '/<!--\s*\/?wp:[^>]*-->/';
		$parts                 = preg_split( $block_comment_pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		// We need the delimiters too — use a combined approach.
		$segments = $this->split_preserving_block_comments( $content );

		$replaced = false;
		$result   = '';

		foreach ( $segments as $segment ) {
			if ( $replaced ) {
				$result .= $segment;
				continue;
			}

			// Skip Gutenberg block comment segments entirely.
			if ( preg_match( '/^<!--\s*\/?wp:/i', $segment ) ) {
				$result .= $segment;
				continue;
			}

			// Skip shortcode segments — anything that IS a shortcode body.
			// (shortcodes are preserved within their surrounding text)

			// Try to replace the first eligible occurrence in this segment.
			$modified = $this->replace_first_eligible( $segment, $anchor_text, $target_url, $replaced );
			$result  .= $modified;
		}

		if ( ! $replaced ) {
			throw new MCPException(
				'VALIDATION',
				"anchor_text '{$anchor_text}' not found in plain text content (may already be inside a link, heading, or shortcode)."
			);
		}

		return $result;
	}

	/**
	 * Split content into segments, preserving Gutenberg block comments as discrete items.
	 *
	 * @param string $content
	 * @return list<string>
	 */
	private function split_preserving_block_comments( string $content ): array {
		$pattern  = '/(<!--\s*\/?wp:[^>]*-->)/';
		$segments = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		return is_array( $segments ) ? $segments : [ $content ];
	}

	/**
	 * Within a single non-block-comment segment, find and replace the first
	 * occurrence of $anchor_text that is not inside an <a>, heading, or shortcode.
	 *
	 * @param string $segment     HTML segment to search.
	 * @param string $anchor_text Text to find.
	 * @param string $target_url  Href value.
	 * @param bool   &$replaced   Pass-by-reference flag; set to true when replacement occurs.
	 * @return string Possibly modified segment.
	 */
	private function replace_first_eligible( string $segment, string $anchor_text, string $target_url, bool &$replaced ): string {
		// Quick check — does the segment even contain the anchor text?
		if ( strpos( $segment, $anchor_text ) === false ) {
			return $segment;
		}

		// Use DOMDocument for safe parsing.
		$html_wrapper = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $segment . '</body></html>';

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html_wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$body       = $dom->getElementsByTagName( 'body' )->item( 0 );
		$text_nodes = [];

		if ( $body === null ) {
			return $segment;
		}

		// Collect all text nodes that are eligible.
		$this->collect_eligible_text_nodes( $body, $text_nodes );

		foreach ( $text_nodes as $text_node ) {
			/** @var \DOMText $text_node */
			$node_value = $text_node->nodeValue;
			$pos        = strpos( $node_value ?? '', $anchor_text );

			if ( $pos === false ) {
				continue;
			}

			// Split the text node around the anchor_text and insert an <a> element.
			$before_text = substr( $node_value, 0, $pos );
			$after_text  = substr( $node_value, $pos + strlen( $anchor_text ) );

			$parent = $text_node->parentNode;
			if ( $parent === null ) {
				continue;
			}

			$ref_node = $text_node->nextSibling;

			// Create replacement nodes.
			if ( $before_text !== '' ) {
				$before_node = $dom->createTextNode( $before_text );
				$parent->insertBefore( $before_node, $text_node );
			}

			$anchor = $dom->createElement( 'a' );
			$anchor->setAttribute( 'href', esc_url( $target_url ) );
			$anchor->appendChild( $dom->createTextNode( $anchor_text ) );
			$parent->insertBefore( $anchor, $text_node );

			if ( $after_text !== '' ) {
				$after_node = $dom->createTextNode( $after_text );
				$parent->insertBefore( $after_node, $text_node );
			}

			$parent->removeChild( $text_node );

			$replaced = true;
			break;
		}

		if ( ! $replaced ) {
			return $segment;
		}

		// Extract modified body innerHTML.
		return $this->extract_inner_html( $body );
	}

	/**
	 * Recursively collect eligible text nodes from a DOM node.
	 *
	 * Eligible means: not inside <a>, not inside <h1>–<h6>, not inside shortcodes.
	 *
	 * @param \DOMNode       $node
	 * @param list<\DOMText> &$text_nodes
	 */
	private function collect_eligible_text_nodes( \DOMNode $node, array &$text_nodes ): void {
		$ineligible_tags = [ 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre' ];

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				// Check that this text node does not look like a shortcode body.
				$value = trim( $child->nodeValue ?? '' );
				if ( $value !== '' && ! $this->text_is_shortcode( $value ) ) {
					$text_nodes[] = $child;
				}
			} elseif ( $child instanceof \DOMElement ) {
				$tag = strtolower( $child->tagName );
				if ( ! in_array( $tag, $ineligible_tags, true ) ) {
					$this->collect_eligible_text_nodes( $child, $text_nodes );
				}
			}
		}
	}

	/**
	 * Check whether a string looks like a WordPress shortcode.
	 *
	 * @param string $text
	 * @return bool
	 */
	private function text_is_shortcode( string $text ): bool {
		return (bool) preg_match( '/^\[[\w_-]+/', $text );
	}

	/**
	 * Extract innerHTML of a DOMElement as a string.
	 *
	 * @param \DOMElement $element
	 * @return string
	 */
	private function extract_inner_html( \DOMElement $element ): string {
		$html = '';
		foreach ( $element->childNodes as $child ) {
			$html .= $element->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	// -------------------------------------------------------------------------
	// Structural fingerprinting
	// -------------------------------------------------------------------------

	/**
	 * Compute a structural fingerprint of an HTML string.
	 *
	 * @param string $html
	 * @return array<string,mixed>
	 */
	private function fingerprint( string $html ): array {
		return [
			'tag_counts'     => $this->count_tags( $html ),
			'shortcodes'     => $this->extract_shortcodes( $html ),
			'block_markers'  => $this->extract_block_markers( $html ),
			'css_classes'    => $this->extract_css_classes( $html ),
		];
	}

	/**
	 * Count occurrences of each HTML tag.
	 *
	 * @param string $html
	 * @return array<string,int>
	 */
	private function count_tags( string $html ): array {
		preg_match_all( '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/i', $html, $matches );
		$counts = [];
		foreach ( $matches[1] as $tag ) {
			$tag = strtolower( $tag );
			$counts[ $tag ] = ( $counts[ $tag ] ?? 0 ) + 1;
		}
		ksort( $counts );
		return $counts;
	}

	/**
	 * Extract all shortcode names from HTML content.
	 *
	 * @param string $html
	 * @return list<string>
	 */
	private function extract_shortcodes( string $html ): array {
		preg_match_all( '/\[([\w_-]+)/', $html, $matches );
		$shortcodes = array_unique( $matches[1] );
		sort( $shortcodes );
		return array_values( $shortcodes );
	}

	/**
	 * Extract Gutenberg block comment markers.
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
	 * Extract all unique CSS classes from HTML.
	 *
	 * @param string $html
	 * @return list<string>
	 */
	private function extract_css_classes( string $html ): array {
		preg_match_all( '/class=["\']([^"\']+)["\']/', $html, $matches );
		$classes = [];
		foreach ( $matches[1] as $class_string ) {
			foreach ( explode( ' ', $class_string ) as $cls ) {
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
	 * Assert that the only structural change between two fingerprints is
	 * exactly one additional <a> tag.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @throws MCPException If unexpected structural changes are detected.
	 */
	private function assert_only_one_new_anchor( array $before, array $after ): void {
		// Shortcodes, block markers, and CSS classes must be identical.
		if ( $before['shortcodes'] !== $after['shortcodes'] ) {
			throw new MCPException( 'VALIDATION', 'Structure changed: shortcodes were modified.' );
		}
		if ( $before['block_markers'] !== $after['block_markers'] ) {
			throw new MCPException( 'VALIDATION', 'Structure changed: Gutenberg block markers were modified.' );
		}
		if ( $before['css_classes'] !== $after['css_classes'] ) {
			throw new MCPException( 'VALIDATION', 'Structure changed: CSS classes were modified.' );
		}

		// Tag counts: the ONLY permitted difference is exactly +1 <a>.
		$before_tags = $before['tag_counts'];
		$after_tags  = $after['tag_counts'];

		$before_a = $before_tags['a'] ?? 0;
		$after_a  = $after_tags['a'] ?? 0;

		if ( $after_a !== $before_a + 1 ) {
			throw new MCPException( 'VALIDATION', 'Structure changed: expected exactly one new <a> tag.' );
		}

		// All other tag counts must be equal.
		$before_without_a = $before_tags;
		$after_without_a  = $after_tags;
		unset( $before_without_a['a'], $after_without_a['a'] );

		if ( $before_without_a !== $after_without_a ) {
			throw new MCPException( 'VALIDATION', 'Structure changed: HTML tag counts were unexpectedly modified.' );
		}
	}

	/**
	 * Extract a short context snippet around the inserted link.
	 *
	 * @param string $content    Modified post content.
	 * @param string $anchor     Anchor text.
	 * @param string $target_url Href value.
	 * @return string
	 */
	private function extract_context( string $content, string $anchor, string $target_url ): string {
		$link    = '<a href="' . esc_url( $target_url ) . '">' . $anchor . '</a>';
		$pos     = strpos( $content, $link );
		if ( $pos === false ) {
			return '';
		}
		$start = max( 0, $pos - 80 );
		$end   = min( strlen( $content ), $pos + strlen( $link ) + 80 );
		return '...' . substr( $content, $start, $end - $start ) . '...';
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
	 * @return string
	 * @throws MCPException
	 */
	private function require_string( array $args, string $key ): string {
		if ( ! isset( $args[ $key ] ) || trim( (string) $args[ $key ] ) === '' ) {
			throw new MCPException( 'VALIDATION', "Missing required argument: {$key}" );
		}
		return (string) $args[ $key ];
	}
}

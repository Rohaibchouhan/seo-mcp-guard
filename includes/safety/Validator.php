<?php
/**
 * Validator – static helpers for all field-level validation.
 *
 * @package SEOMCPGuard\Safety
 */

declare(strict_types=1);

namespace SEOMCPGuard\Safety;

use SEOMCPGuard\Exceptions\MCPException;

/**
 * Class Validator
 *
 * All methods are static so they can be called without instantiation.
 * Every method either returns the sanitized/validated value or throws
 * {@see MCPException} with code VALIDATION.
 */
class Validator {

	// -----------------------------------------------------------------------
	// Constants
	// -----------------------------------------------------------------------

	/**
	 * Schema types supported by Rank Math and broadly by Google.
	 *
	 * @var string[]
	 */
	public const ALLOWED_SCHEMA_TYPES = [
		'Article',
		'BlogPosting',
		'WebPage',
		'Product',
		'Recipe',
		'Review',
		'Event',
		'Person',
		'Organization',
		'LocalBusiness',
		'FAQPage',
		'HowTo',
		'VideoObject',
		'Course',
		'JobPosting',
		'Book',
		'Movie',
		'MusicRecording',
		'SoftwareApplication',
		'Service',
	];

	/**
	 * Robots meta directives allowed by Google.
	 *
	 * @var string[]
	 */
	public const ALLOWED_ROBOTS = [
		'index',
		'noindex',
		'follow',
		'nofollow',
		'noarchive',
		'nosnippet',
		'noimageindex',
		'none',
	];

	// Maximum field lengths (in characters, mb-safe).
	private const MAX_TITLE_LENGTH       = 60;
	private const MAX_DESC_LENGTH        = 160;
	private const MAX_FB_TITLE_LENGTH    = 95;
	private const MAX_FB_DESC_LENGTH     = 200;
	private const MAX_TW_TITLE_LENGTH    = 70;
	private const MAX_TW_DESC_LENGTH     = 200;
	private const MAX_ALT_TEXT_LENGTH    = 125;

	// -----------------------------------------------------------------------
	// SEO field validators
	// -----------------------------------------------------------------------

	/**
	 * Validate and sanitize an SEO title.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if the value exceeds 60 characters.
	 */
	public static function title( string $v ): string {
		$v = sanitize_text_field( $v );

		if ( mb_strlen( $v ) > self::MAX_TITLE_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'SEO title must be %d characters or fewer; %d given.',
					self::MAX_TITLE_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}

	/**
	 * Validate and sanitize an SEO meta description.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if the value exceeds 160 characters.
	 */
	public static function description( string $v ): string {
		// Descriptions may contain encoded HTML entities but no real tags.
		$v = sanitize_textarea_field( $v );

		if ( mb_strlen( $v ) > self::MAX_DESC_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'SEO description must be %d characters or fewer; %d given.',
					self::MAX_DESC_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}

	/**
	 * Validate and sanitize a focus keyword.
	 *
	 * Rules:
	 *   – Must be a single phrase (no pipe `|` separator used by some plugins).
	 *   – Commas are allowed (long-tail phrases), but multiple comma-separated
	 *     phrases intended as separate keywords are not.
	 *   – Leading/trailing whitespace is stripped.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if a pipe separator is detected (multiple keywords).
	 */
	public static function focus_keyword( string $v ): string {
		$v = sanitize_text_field( $v );

		if ( str_contains( $v, '|' ) ) {
			throw MCPException::validation(
				"focus_keyword must be a single phrase; pipe-separated multiple keywords are not allowed."
			);
		}

		// Warn about suspiciously many comma-separated segments (heuristic: > 3).
		$segments = array_filter( array_map( 'trim', explode( ',', $v ) ) );
		if ( count( $segments ) > 3 ) {
			throw MCPException::validation(
				"focus_keyword appears to contain multiple comma-separated phrases. "
				. "Supply a single keyword or short phrase."
			);
		}

		return $v;
	}

	/**
	 * Validate a canonical URL.
	 *
	 * Requirements:
	 *   – Must be an absolute URL (has scheme + host).
	 *   – Scheme must be http or https.
	 *   – Host must match the site's own domain (prevents canonicalising to an
	 *     external site, which would be an SEO disaster).
	 *
	 * @param string $v        Raw URL.
	 * @param string $site_url The site's own URL (e.g. `get_site_url()`).
	 *
	 * @return string Sanitized URL.
	 *
	 * @throws MCPException VALIDATION on any violation.
	 */
	public static function canonical_url( string $v, string $site_url ): string {
		$v = esc_url_raw( trim( $v ) );

		if ( empty( $v ) ) {
			throw MCPException::validation( "canonical_url is empty after sanitisation." );
		}

		$parsed      = wp_parse_url( $v );
		$site_parsed = wp_parse_url( $site_url );

		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			throw MCPException::validation(
				"canonical_url must be an absolute URL with a scheme and host; '{$v}' given."
			);
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			throw MCPException::validation(
				"canonical_url scheme must be http or https; '{$parsed['scheme']}' given."
			);
		}

		// Normalise hosts for comparison (strip www. prefix for leniency).
		$url_host  = strtolower( $parsed['host'] );
		$site_host = strtolower( $site_parsed['host'] ?? '' );

		$normalise_host = static fn( string $h ): string => preg_replace( '/^www\./', '', $h );

		if ( $normalise_host( $url_host ) !== $normalise_host( $site_host ) ) {
			throw MCPException::validation(
				"canonical_url host '{$url_host}' does not match site host '{$site_host}'. "
				. "Cross-domain canonicals are not permitted."
			);
		}

		return $v;
	}

	/**
	 * Validate a robots meta directives array.
	 *
	 * Each element must be one of {@see ALLOWED_ROBOTS}.
	 *
	 * @param array $v Array of directive strings.
	 *
	 * @return array Validated array (values are lowercased).
	 *
	 * @throws MCPException VALIDATION on unknown directives or conflicting pairs.
	 */
	public static function robots( array $v ): array {
		$sanitized = [];

		foreach ( $v as $directive ) {
			$d = strtolower( sanitize_text_field( (string) $directive ) );

			if ( ! in_array( $d, self::ALLOWED_ROBOTS, true ) ) {
				throw MCPException::validation(
					"Unknown robots directive '{$d}'. "
					. "Allowed: " . implode( ', ', self::ALLOWED_ROBOTS ) . "."
				);
			}

			$sanitized[] = $d;
		}

		$sanitized = array_unique( $sanitized );

		// Detect logically contradictory pairs.
		$pairs = [
			[ 'index', 'noindex' ],
			[ 'follow', 'nofollow' ],
		];

		foreach ( $pairs as [ $a, $b ] ) {
			if ( in_array( $a, $sanitized, true ) && in_array( $b, $sanitized, true ) ) {
				throw MCPException::validation(
					"Conflicting robots directives: cannot have both '{$a}' and '{$b}'."
				);
			}
		}

		return array_values( $sanitized );
	}

	/**
	 * Validate a schema/rich-snippet type.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Validated value (original casing preserved if valid).
	 *
	 * @throws MCPException VALIDATION if not in the allow-list.
	 */
	public static function schema_type( string $v ): string {
		$v = sanitize_text_field( $v );

		// Case-insensitive check but return the canonical casing from the list.
		foreach ( self::ALLOWED_SCHEMA_TYPES as $allowed ) {
			if ( strcasecmp( $v, $allowed ) === 0 ) {
				return $allowed;
			}
		}

		throw MCPException::validation(
			"'{$v}' is not an allowed schema type. "
			. "Allowed: " . implode( ', ', self::ALLOWED_SCHEMA_TYPES ) . "."
		);
	}

	// -----------------------------------------------------------------------
	// General-purpose validators
	// -----------------------------------------------------------------------

	/**
	 * Sanitize a URL slug.
	 *
	 * @param string $v Raw slug value.
	 *
	 * @return string Sanitized slug.
	 *
	 * @throws MCPException VALIDATION if the result is empty.
	 */
	public static function slug( string $v ): string {
		$v = sanitize_title( trim( $v ) );

		if ( '' === $v ) {
			throw MCPException::validation( "Slug is empty after sanitisation." );
		}

		return $v;
	}

	/**
	 * Validate and sanitize image alt text.
	 *
	 * @param string $v Raw alt text.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if exceeds 125 characters.
	 */
	public static function alt_text( string $v ): string {
		$v = sanitize_text_field( $v );

		if ( mb_strlen( $v ) > self::MAX_ALT_TEXT_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'Alt text must be %d characters or fewer; %d given.',
					self::MAX_ALT_TEXT_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}

	/**
	 * Validate that a string is an absolute URL.
	 *
	 * @param string $v Raw URL.
	 *
	 * @return string Sanitized absolute URL.
	 *
	 * @throws MCPException VALIDATION if the URL is not absolute or is invalid.
	 */
	public static function absolute_url( string $v ): string {
		$v = esc_url_raw( trim( $v ) );

		if ( empty( $v ) ) {
			throw MCPException::validation( "URL is empty after sanitisation." );
		}

		$parsed = wp_parse_url( $v );

		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			throw MCPException::validation(
				"URL must be absolute (include scheme and host); '{$v}' given."
			);
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true ) ) {
			throw MCPException::validation(
				"URL scheme must be http or https; '{$parsed['scheme']}' given."
			);
		}

		return $v;
	}

	/**
	 * Validate that a value is a positive integer.
	 *
	 * @param mixed  $v     The value to validate.
	 * @param string $field Field name used in error messages.
	 *
	 * @return int Validated positive integer.
	 *
	 * @throws MCPException VALIDATION if the value is not a positive integer.
	 */
	public static function positive_int( mixed $v, string $field ): int {
		if ( ! is_numeric( $v ) ) {
			throw MCPException::validation(
				"'{$field}' must be an integer; " . gettype( $v ) . " given."
			);
		}

		$int = (int) $v;

		if ( $int <= 0 ) {
			throw MCPException::validation(
				"'{$field}' must be a positive integer (> 0); {$int} given."
			);
		}

		return $int;
	}

	/**
	 * Validate and sanitize a Facebook Open Graph title.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if value exceeds 95 characters.
	 */
	public static function facebook_title( string $v ): string {
		$v = sanitize_text_field( $v );

		if ( mb_strlen( $v ) > self::MAX_FB_TITLE_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'Facebook title must be %d characters or fewer; %d given.',
					self::MAX_FB_TITLE_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}

	/**
	 * Validate and sanitize a Facebook Open Graph description.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if value exceeds 200 characters.
	 */
	public static function facebook_description( string $v ): string {
		$v = sanitize_textarea_field( $v );

		if ( mb_strlen( $v ) > self::MAX_FB_DESC_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'Facebook description must be %d characters or fewer; %d given.',
					self::MAX_FB_DESC_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}

	/**
	 * Validate and sanitize a Twitter Card title.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if value exceeds 70 characters.
	 */
	public static function twitter_title( string $v ): string {
		$v = sanitize_text_field( $v );

		if ( mb_strlen( $v ) > self::MAX_TW_TITLE_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'Twitter title must be %d characters or fewer; %d given.',
					self::MAX_TW_TITLE_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}

	/**
	 * Validate and sanitize a Twitter Card description.
	 *
	 * @param string $v Raw value.
	 *
	 * @return string Sanitized value.
	 *
	 * @throws MCPException VALIDATION if value exceeds 200 characters.
	 */
	public static function twitter_description( string $v ): string {
		$v = sanitize_textarea_field( $v );

		if ( mb_strlen( $v ) > self::MAX_TW_DESC_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'Twitter description must be %d characters or fewer; %d given.',
					self::MAX_TW_DESC_LENGTH,
					mb_strlen( $v )
				)
			);
		}

		return $v;
	}
}

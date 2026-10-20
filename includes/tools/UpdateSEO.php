<?php
/**
 * UpdateSEO MCP tool — write Rank Math SEO metadata for a post.
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
 * Write (or dry-run) Rank Math SEO field updates for a single post.
 *
 * Only fields in ALLOWED_FIELDS are accepted; any others cause an immediate
 * NOT_ALLOWED error so the tool cannot be used as a meta-injection vector.
 */
class UpdateSEO extends BaseTool {

	/**
	 * Exhaustive whitelist of Rank Math meta keys this tool may write.
	 */
	private const ALLOWED_FIELDS = [
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
	];

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'update_seo';
	}

	/**
	 * Execute the update_seo tool.
	 *
	 * Expected $args keys:
	 *  - post_id  (int,          required)
	 *  - fields   (array|object, required) — map of SEO field => value
	 *  - dry_run  (bool,         default true)
	 *
	 * @param array<string,mixed> $args
	 *
	 * @return array<string,mixed>
	 *
	 * @throws MCPException On validation or permission failure.
	 */
	public function execute( array $args ): array {

		// ------------------------------------------------------------------
		// 1. Rank Math must be active for any write operation.
		// ------------------------------------------------------------------
		$this->require_rankmath();

		// ------------------------------------------------------------------
		// 2. Validate arguments
		// ------------------------------------------------------------------
		if ( ! isset( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			throw MCPException::validation( 'The "post_id" argument is required and must be an integer.' );
		}

		if ( empty( $args['fields'] ) || ! is_array( $args['fields'] ) ) {
			throw MCPException::validation( 'The "fields" argument is required and must be an object/array of SEO field => value pairs.' );
		}

		$post_id = (int) $args['post_id'];
		$fields  = $args['fields'];
		$dry_run = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : true;

		// ------------------------------------------------------------------
		// 3. Verify the post
		// ------------------------------------------------------------------
		$post = $this->get_post_or_fail( $post_id );

		// ------------------------------------------------------------------
		// 4. Check production-write guard
		// ------------------------------------------------------------------
		if ( ! $this->settings->allow_production_writes() && ! $dry_run ) {
			throw MCPException::not_allowed(
				'Production writes are disabled in SEO MCP Guard settings. Enable them or use dry_run=true.'
			);
		}

		// ------------------------------------------------------------------
		// 5. Reject any field not in the whitelist
		// ------------------------------------------------------------------
		$disallowed = array_diff( array_keys( $fields ), self::ALLOWED_FIELDS );

		if ( ! empty( $disallowed ) ) {
			throw MCPException::not_allowed(
				sprintf(
					'The following fields are not allowed: %s. Allowed fields: %s.',
					implode( ', ', $disallowed ),
					implode( ', ', self::ALLOWED_FIELDS )
				)
			);
		}

		// ------------------------------------------------------------------
		// 6. Generate tracking IDs
		// ------------------------------------------------------------------
		$batch_id         = $this->generate_batch_id();
		$idempotency_key  = $this->build_idempotency_key( $this->get_name(), $args );

		// ------------------------------------------------------------------
		// 7. Validate fields through SafetyEngine
		// ------------------------------------------------------------------
		$this->safety->validate_seo_fields( $fields );

		// ------------------------------------------------------------------
		// 8. Snapshot old values
		// ------------------------------------------------------------------
		$old_values = [];
		foreach ( array_keys( $fields ) as $field_key ) {
			$old_values[ $field_key ] = get_post_meta( $post_id, $field_key, true );
		}

		// ------------------------------------------------------------------
		// 9. Execute via SafetyEngine (handles dry-run internally)
		// ------------------------------------------------------------------
		$write_fn = static function () use ( $post_id, $fields ): void {
			foreach ( $fields as $field_key => $field_value ) {
				update_post_meta( $post_id, $field_key, $field_value );
			}
		};

		$result = $this->safety->execute_write(
			tool: $this->get_name(),
			post_id: $post_id,
			old_values: $old_values,
			new_values: $fields,
			write_fn: $write_fn,
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		return $result;
	}
}

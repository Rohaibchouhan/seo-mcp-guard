<?php
/**
 * UpdateImageAlt MCP tool — update the alt text of a media attachment.
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
 * Update the `_wp_attachment_image_alt` meta of a WordPress media attachment.
 *
 * Accessibility-correct alt text should be ≤ 125 characters.
 */
class UpdateImageAlt extends BaseTool {

	/**
	 * Maximum recommended alt text length (characters).
	 */
	private const MAX_ALT_LENGTH = 125;

	/**
	 * WordPress attachment meta key for image alt text.
	 */
	private const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'update_image_alt';
	}

	/**
	 * Execute the update_image_alt tool.
	 *
	 * Expected $args keys:
	 *  - attachment_id (int,    required)
	 *  - alt           (string, required)
	 *  - dry_run       (bool,   default true)
	 *
	 * @param array<string,mixed> $args
	 *
	 * @return array<string,mixed>
	 *
	 * @throws MCPException On validation or permission failure.
	 */
	public function execute( array $args ): array {

		// ------------------------------------------------------------------
		// 1. Validate arguments
		// ------------------------------------------------------------------
		if ( ! isset( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			throw MCPException::validation( 'The "attachment_id" argument is required and must be an integer.' );
		}

		if ( ! array_key_exists( 'alt', $args ) ) {
			throw MCPException::validation( 'The "alt" argument is required (pass an empty string to clear alt text).' );
		}

		$attachment_id = (int) $args['attachment_id'];
		$dry_run       = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : true;

		// Sanitise and validate the alt text.
		$alt = sanitize_text_field( (string) $args['alt'] );

		if ( mb_strlen( $alt, 'UTF-8' ) > self::MAX_ALT_LENGTH ) {
			throw MCPException::validation(
				sprintf(
					'Alt text exceeds the maximum length of %d characters (provided: %d).',
					self::MAX_ALT_LENGTH,
					mb_strlen( $alt, 'UTF-8' )
				)
			);
		}

		// ------------------------------------------------------------------
		// 2. Verify the attachment exists
		// ------------------------------------------------------------------
		$attachment = get_post( $attachment_id );

		if ( ! ( $attachment instanceof WP_Post ) || 'attachment' !== $attachment->post_type ) {
			throw MCPException::not_allowed(
				sprintf( 'Attachment ID %d does not exist or is not a media attachment.', $attachment_id )
			);
		}

		if ( 'trash' === $attachment->post_status ) {
			throw MCPException::not_allowed(
				sprintf( 'Attachment ID %d is in the trash and cannot be modified.', $attachment_id )
			);
		}

		// ------------------------------------------------------------------
		// 3. Check current user capability
		// ------------------------------------------------------------------
		if ( ! user_can( $this->current_user, 'edit_post', $attachment_id ) ) {
			throw MCPException::not_allowed(
				sprintf(
					'User %d does not have permission to edit attachment %d.',
					$this->current_user->ID,
					$attachment_id
				)
			);
		}

		// ------------------------------------------------------------------
		// 4. Check production-write guard
		// ------------------------------------------------------------------
		if ( ! $this->settings->allow_production_writes() && ! $dry_run ) {
			throw MCPException::not_allowed(
				'Production writes are disabled in SEO MCP Guard settings. Enable them or use dry_run=true.'
			);
		}

		// ------------------------------------------------------------------
		// 5. Snapshot old value
		// ------------------------------------------------------------------
		$old_alt = (string) get_post_meta( $attachment_id, self::ALT_META_KEY, true );

		$batch_id        = $this->generate_batch_id();
		$idempotency_key = $this->build_idempotency_key( $this->get_name(), $args );

		// ------------------------------------------------------------------
		// 6. Execute via SafetyEngine
		// ------------------------------------------------------------------
		$write_fn = static function () use ( $attachment_id, $alt ): void {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		};

		$result = $this->safety->execute_write(
			tool: $this->get_name(),
			post_id: $attachment_id,
			old_values: [ self::ALT_META_KEY => $old_alt ],
			new_values: [ self::ALT_META_KEY => $alt ],
			write_fn: $write_fn,
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		// Enrich the result with human-readable context.
		$result['attachment_id']  = $attachment_id;
		$result['attachment_url'] = wp_get_attachment_url( $attachment_id );
		$result['old_alt']        = $old_alt;
		$result['new_alt']        = $alt;

		return $result;
	}
}

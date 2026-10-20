<?php
/**
 * Abstract base class for all MCP tools.
 *
 * @package SEOMCPGuard\Tools
 */

declare( strict_types=1 );

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Settings;
use SEOMCPGuard\Safety\SafetyEngine;
use SEOMCPGuard\Safety\ChangeLog;
use SEOMCPGuard\Exceptions\MCPException;
use WP_Post;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract BaseTool
 *
 * Every MCP tool extends this class and must implement execute() and get_name().
 */
abstract class BaseTool {

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	protected Settings $settings;

	/**
	 * Safety engine instance.
	 *
	 * @var SafetyEngine
	 */
	protected SafetyEngine $safety;

	/**
	 * Change-log helper.
	 *
	 * @var ChangeLog
	 */
	protected ChangeLog $change_log;

	/**
	 * The authenticated WordPress user executing this tool.
	 *
	 * @var WP_User
	 */
	protected WP_User $current_user;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings    Plugin settings.
	 * @param SafetyEngine $safety      Safety/write engine.
	 * @param ChangeLog    $change_log  Change-log service.
	 * @param WP_User      $current_user Authenticated user.
	 */
	public function __construct(
		Settings $settings,
		SafetyEngine $safety,
		ChangeLog $change_log,
		WP_User $current_user
	) {
		$this->settings     = $settings;
		$this->safety       = $safety;
		$this->change_log   = $change_log;
		$this->current_user = $current_user;
	}

	// -------------------------------------------------------------------------
	// Abstract interface
	// -------------------------------------------------------------------------

	/**
	 * Execute the tool with the given arguments.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 *
	 * @return array<string,mixed> Tool result.
	 *
	 * @throws MCPException On validation or permission failure.
	 */
	abstract public function execute( array $args ): array;

	/**
	 * Return the machine-readable name of this tool (used as MCP method name).
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a post, verifying it exists, is not trashed, belongs to an
	 * allowed post type, and is in the allowed post-ID list (if configured).
	 *
	 * @param int $post_id WordPress post ID.
	 *
	 * @return WP_Post Verified post object.
	 *
	 * @throws MCPException NOT_ALLOWED when any check fails.
	 */
	protected function get_post_or_fail( int $post_id ): WP_Post {

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			throw MCPException::not_allowed(
				sprintf( 'Post %d does not exist.', $post_id )
			);
		}

		if ( 'trash' === $post->post_status ) {
			throw MCPException::not_allowed(
				sprintf( 'Post %d is in the trash and cannot be modified.', $post_id )
			);
		}

		// Verify post type is in the allowed list.
		$allowed_post_types = $this->settings->get_allowed_post_types();

		if ( ! empty( $allowed_post_types ) && ! in_array( $post->post_type, $allowed_post_types, true ) ) {
			throw MCPException::not_allowed(
				sprintf(
					'Post type "%s" is not in the allowed post types list.',
					$post->post_type
				)
			);
		}

		// Verify this specific post ID is in the allowed list (if a list is configured).
		$allowed_post_ids = $this->settings->get_allowed_post_ids();

		if ( ! empty( $allowed_post_ids ) && ! in_array( $post_id, $allowed_post_ids, true ) ) {
			throw MCPException::not_allowed(
				sprintf( 'Post ID %d is not in the allowed post IDs list.', $post_id )
			);
		}

		return $post;
	}

	/**
	 * Refuse content-modifying tools on page builder pages.
	 *
	 * @param int $post_id
	 * @throws MCPException If page builder is detected.
	 */
	protected function check_page_builder( int $post_id ): void {
		$this->safety->check_page_builder( $post_id );
	}

	/**
	 * Assert that Rank Math SEO is active.
	 *
	 * @return void
	 *
	 * @throws MCPException NOT_ALLOWED when Rank Math is not active.
	 */
	protected function require_rankmath(): void {
		if (
			! defined( 'RANK_MATH_VERSION' ) &&
			! class_exists( 'RankMath\RankMath', false )
		) {
			throw MCPException::not_allowed(
				'Rank Math is not active. Write operations are disabled.'
			);
		}
	}

	/**
	 * Generate a unique batch identifier for grouping related changes.
	 *
	 * @return string e.g. "batch_a1b2c3d4e5f60708"
	 */
	protected function generate_batch_id(): string {
		return 'batch_' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Build a deterministic idempotency key from a tool name and its arguments.
	 *
	 * Two identical invocations will always produce the same key, allowing
	 * callers to detect and skip duplicate operations.
	 *
	 * @param string              $tool Tool name.
	 * @param array<string,mixed> $args Tool arguments (order-independent).
	 *
	 * @return string SHA-256 hex digest.
	 */
	protected function build_idempotency_key( string $tool, array $args ): string {
		ksort( $args );
		return hash(
			'sha256',
			$tool . json_encode( $args )
		);
	}
}

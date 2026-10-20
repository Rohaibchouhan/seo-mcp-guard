<?php
/**
 * Safety Engine – central gatekeeper for all write operations.
 *
 * @package SEOMCPGuard\Safety
 */

declare( strict_types=1 );

namespace SEOMCPGuard\Safety;

use SEOMCPGuard\Settings;
use SEOMCPGuard\RateLimit;
use SEOMCPGuard\Exceptions\MCPException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SafetyEngine
 *
 * ALL write operations performed by the plugin must be routed through
 * {@see SafetyEngine::execute_write()}.
 */
class SafetyEngine {

	private const LOCK_OPTION_PREFIX = 'seo_mcp_guard_lock_';
	private const LOCK_TTL_SECONDS   = 30;

	private static ?self $instance = null;

	private Settings    $settings;
	private ChangeLog   $change_log;
	private RateLimit   $rate_limit;
	private CachePurger $cache_purger;
	private HealthCheck $health_check;

	public function __construct(
		Settings    $settings,
		ChangeLog   $change_log,
		RateLimit   $rate_limit,
		CachePurger $cache_purger,
		HealthCheck $health_check
	) {
		$this->settings     = $settings;
		$this->change_log   = $change_log;
		$this->rate_limit   = $rate_limit;
		$this->cache_purger = $cache_purger;
		$this->health_check = $health_check;
		self::$instance     = $this;
	}

	public static function get_instance(): ?self {
		return self::$instance;
	}

	/**
	 * Static fallback for execute_write to support static tool invocations.
	 */
	public static function execute_write_static( ...$args ): array {
		if ( null !== self::$instance ) {
			return self::$instance->execute_write( ...$args );
		}
		throw MCPException::internal( 'SafetyEngine instance is not initialized.' );
	}

	/**
	 * Magic static call to handle SafetyEngine::execute_write(...) seamlessly.
	 */
	public static function __callStatic( string $name, array $arguments ) {
		if ( 'execute_write' === $name ) {
			return self::execute_write_static( ...$arguments );
		}
		throw new \BadMethodCallException( "Method {$name} does not exist." );
	}

	/**
	 * Execute a write operation through the full safety pipeline.
	 *
	 * Flexible signature supporting all named argument styles from tools.
	 *
	 * @return array
	 * @throws MCPException
	 */
	public function execute_write(
		int $post_id = 0,
		string $tool = '',
		?callable $write_fn = null,
		array $old_values = [],
		array $new_values = [],
		bool $dry_run = true,
		string $batch_id = '',
		string $idempotency_key = '',
		int $user_id = 0,
		string $tool_name = '',
		array $fields_before = [],
		array $fields_after = [],
		...$extra
	): array {
		// Resolve aliases:
		$resolved_tool   = ! empty( $tool ) ? $tool : ( ! empty( $tool_name ) ? $tool_name : (string) ( $extra['tool_name'] ?? 'unknown_tool' ) );
		$resolved_before = ! empty( $old_values ) ? $old_values : ( ! empty( $fields_before ) ? $fields_before : (array) ( $extra['snapshot'] ?? [] ) );
		$resolved_after  = ! empty( $new_values ) ? $new_values : $fields_after;

		// If positional call: execute_write($tool_name, $data, $callable):
		if ( is_string( $post_id ) && is_array( $tool ) && is_callable( $write_fn ) ) {
			$actual_tool   = (string) $post_id;
			$actual_data   = (array) $tool;
			$actual_fn     = $write_fn;
			$actual_post   = (int) ( $actual_data['post_id'] ?? 0 );
			$actual_dry    = isset( $actual_data['dry_run'] ) ? (bool) $actual_data['dry_run'] : false;
			$actual_batch  = (string) ( $actual_data['batch_id'] ?? ( 'batch_' . bin2hex( random_bytes( 8 ) ) ) );
			$actual_idem   = (string) ( $actual_data['idempotency_key'] ?? hash( 'sha256', $actual_tool . wp_json_encode( $actual_data ) ) );
			$actual_before = (array) ( $actual_data['snapshot'] ?? [] );

			return $this->execute_write(
				post_id: $actual_post,
				tool: $actual_tool,
				write_fn: $actual_fn,
				old_values: $actual_before,
				new_values: $actual_data,
				dry_run: $actual_dry,
				batch_id: $actual_batch,
				idempotency_key: $actual_idem,
				user_id: get_current_user_id()
			);
		}

		if ( empty( $idempotency_key ) ) {
			$idempotency_key = hash( 'sha256', $resolved_tool . $post_id . wp_json_encode( $resolved_after ) );
		}
		if ( empty( $batch_id ) ) {
			$batch_id = 'batch_' . bin2hex( random_bytes( 8 ) );
		}

		// 1. Idempotency guard:
		$existing = $this->change_log->find_by_idempotency( $idempotency_key );
		if ( null !== $existing && 'applied' === $existing['status'] ) {
			return [
				'applied'    => true,
				'dry_run'    => false,
				'cached'     => true,
				'changes'    => $this->build_diff( $resolved_before, $resolved_after ),
				'change_id'  => $existing['change_id'],
				'batch_id'   => $batch_id,
			];
		}

		// 2. Batch-halt check:
		if ( ! empty( $batch_id ) && $this->change_log->is_batch_halted( $batch_id ) ) {
			throw MCPException::batch_halted( $batch_id );
		}

		// 3. Post lock:
		if ( $post_id > 0 ) {
			$lock_acquired = $this->acquire_post_lock( $post_id );
			if ( ! $lock_acquired ) {
				throw MCPException::locked( $post_id );
			}
		}

		$change_id = '';

		try {
			// 4. Compute diff:
			$diff = $this->build_diff( $resolved_before, $resolved_after );

			// 5. Dry-run:
			if ( $dry_run ) {
				$change_id = $this->change_log->snapshot( [
					'batch_id'        => $batch_id,
					'idempotency_key' => $idempotency_key,
					'post_id'         => $post_id,
					'tool_name'       => $resolved_tool,
					'fields_before'   => $resolved_before,
					'fields_after'    => $resolved_after,
					'user_id'         => $user_id,
					'status'          => ChangeLog::STATUS_DRY_RUN,
				] );

				return [
					'applied'   => false,
					'dry_run'   => true,
					'changes'   => $diff,
					'change_id' => $change_id,
					'batch_id'  => $batch_id,
					'message'   => 'Dry run: no changes were written.',
				];
			}

			// 6a. Snapshot BEFORE values:
			$change_id = $this->change_log->snapshot( [
				'batch_id'        => $batch_id,
				'idempotency_key' => $idempotency_key,
				'post_id'         => $post_id,
				'tool_name'       => $resolved_tool,
				'fields_before'   => $resolved_before,
				'fields_after'    => $resolved_after,
				'user_id'         => $user_id,
				'status'          => ChangeLog::STATUS_PENDING,
			] );

			// 6b. Execute the actual write:
			if ( is_callable( $write_fn ) ) {
				( $write_fn )();
			}

			// 6c. Purge caches:
			if ( $post_id > 0 ) {
				$this->cache_purger->purge_post( $post_id );
			}

			// 6d. Health check (if public post):
			if ( $post_id > 0 && get_post_type( $post_id ) !== 'attachment' ) {
				try {
					$this->health_check->verify_post_ok( $post_id );
				} catch ( MCPException $health_ex ) {
					// Auto-rollback:
					$this->perform_emergency_rollback( $post_id, $resolved_before, $change_id );
					$this->cache_purger->purge_post( $post_id );

					if ( ! empty( $batch_id ) ) {
						$this->change_log->mark_batch_halted( $batch_id );
					}

					throw $health_ex;
				}
			}

			// 6e. Mark change applied:
			$this->change_log->mark_applied( $change_id );

		} finally {
			if ( $post_id > 0 ) {
				$this->release_post_lock( $post_id );
			}
		}

		return [
			'applied'   => true,
			'dry_run'   => false,
			'changes'   => $diff,
			'change_id' => $change_id,
			'batch_id'  => $batch_id,
			'tool'      => $resolved_tool,
			'post_id'   => $post_id,
		];
	}

	/**
	 * Validate SEO fields against Rank Math allowlist.
	 */
	public function validate_seo_fields( array $fields ): array {
		$site_url  = get_site_url();
		$sanitized = [];

		$allowed_keys = [
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

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				throw MCPException::not_allowed(
					sprintf( 'Field "%s" is not in the allowed SEO fields list.', $key )
				);
			}

			$sanitized[ $key ] = match ( $key ) {
				'rank_math_title'                => Validator::title( (string) $value ),
				'rank_math_description'          => Validator::description( (string) $value ),
				'rank_math_focus_keyword'        => Validator::focus_keyword( (string) $value ),
				'rank_math_canonical_url'        => Validator::canonical_url( (string) $value, $site_url ),
				'rank_math_robots'               => Validator::robots( (array) $value ),
				'rank_math_facebook_title'       => Validator::facebook_title( (string) $value ),
				'rank_math_facebook_description' => Validator::facebook_description( (string) $value ),
				'rank_math_twitter_title'        => Validator::twitter_title( (string) $value ),
				'rank_math_twitter_description'  => Validator::twitter_description( (string) $value ),
				'rank_math_rich_snippet'         => Validator::schema_type( (string) $value ),
				default                          => throw MCPException::validation( "Unexpected field key: {$key}" ),
			};
		}

		return $sanitized;
	}

	/**
	 * Refuse content-modifying tools on page-builder built posts.
	 */
	public function check_page_builder( int $post_id ): void {
		$builder_meta_flags = [
			'_elementor_data',
			'_elementor_edit_mode',
			'et_pb_use_builder',
			'_fl_builder_enabled',
			'_wpb_vc_js_status',
			'_bricks_page_content_2',
			'ct_builder_shortcodes',
		];

		foreach ( $builder_meta_flags as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $value ) && '0' !== $value && 'false' !== $value ) {
				throw MCPException::not_allowed( 'Page-builder page: SEO fields only.' );
			}
		}
	}

	public function is_post_type_allowed( string $post_type ): bool {
		$allowed = $this->settings->get_allowed_post_types();
		return empty( $allowed ) || in_array( $post_type, $allowed, true );
	}

	public function is_post_id_allowed( int $post_id ): bool {
		$allowed = $this->settings->get_allowed_post_ids();
		return empty( $allowed ) || in_array( $post_id, $allowed, true );
	}

	private function build_diff( array $before, array $after ): array {
		$diff = [];
		foreach ( $after as $field => $new_val ) {
			$old_val = $before[ $field ] ?? null;
			if ( $old_val !== $new_val ) {
				$diff[ $field ] = [
					'before' => $old_val,
					'after'  => $new_val,
				];
			}
		}
		return $diff;
	}

	private function acquire_post_lock( int $post_id ): bool {
		return $this->rate_limit->acquire_post_lock( $post_id, self::LOCK_TTL_SECONDS );
	}

	private function release_post_lock( int $post_id ): void {
		$this->rate_limit->release_post_lock( $post_id );
	}

	private function perform_emergency_rollback( int $post_id, array $fields_before, string $change_id ): void {
		foreach ( $fields_before as $field => $old_value ) {
			if ( 'post_name' === $field ) {
				wp_update_post( [ 'ID' => $post_id, 'post_name' => (string) $old_value ] );
			} elseif ( 'post_content' === $field ) {
				wp_update_post( [ 'ID' => $post_id, 'post_content' => (string) $old_value ] );
			} elseif ( '_wp_attachment_image_alt' === $field ) {
				update_post_meta( $post_id, '_wp_attachment_image_alt', (string) $old_value );
			} else {
				if ( null === $old_value || '' === $old_value ) {
					delete_post_meta( $post_id, $field );
				} else {
					update_post_meta( $post_id, $field, $old_value );
				}
			}
		}

		if ( ! empty( $change_id ) ) {
			$this->change_log->mark_rolled_back( $change_id );
		}

		do_action( 'seo_mcp_guard_emergency_rollback', $post_id, $fields_before, $change_id );
	}
}

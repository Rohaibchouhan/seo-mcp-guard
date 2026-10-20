<?php
/**
 * Changes Tool
 *
 * Exposes the plugin's ChangeLog to the MCP layer. Supports listing change
 * records, rolling back individual changes, and rolling back entire batches
 * in reverse order.
 *
 * @package SEOMCPGuard\Tools
 */

declare( strict_types=1 );

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Changes
 */
class Changes extends BaseTool {

	private const DEFAULT_LIMIT  = 50;
	private const MAX_LIMIT      = 200;
	private const MAX_BATCH_SIZE = 50;

	public function get_name(): string {
		return 'changes';
	}

	public function execute( array $args ): array {
		return $this->execute_list( $args );
	}

	/**
	 * List change records, optionally filtered by post.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function execute_list( array $args ): array {
		$limit   = min( self::MAX_LIMIT, max( 1, (int) ( $args['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : null;

		$changes = $this->change_log->list_changes( $limit, $post_id );

		return [
			'changes' => array_map( [ $this, 'format_change' ], $changes ),
			'count'   => count( $changes ),
			'limit'   => $limit,
			'post_id' => $post_id,
		];
	}

	/**
	 * Roll back a single change record.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 * @throws MCPException
	 */
	public function execute_rollback( array $args ): array {
		$change_id = $this->require_string( $args, 'change_id' );
		$dry_run   = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

		$change = $this->change_log->get_change( $change_id );
		if ( $change === null ) {
			throw new MCPException( 'VALIDATION', "Change ID '{$change_id}' not found." );
		}

		if ( $dry_run ) {
			return [
				'dry_run'       => true,
				'change'        => $this->format_change( $change ),
				'would_restore' => [
					'field'     => $change['field'] ?? '',
					'old_value' => $change['old_value'] ?? '',
				],
				'message'       => 'Dry run: this change would be rolled back.',
			];
		}

		// Execute the rollback.
		$res = $this->change_log->rollback( $change_id );

		// Purge caches:
		$post_id = (int) ( $change['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			clean_post_cache( $post_id );
			do_action( 'seo_mcp_guard_cache_purged', $post_id );
		}

		return [
			'dry_run'     => false,
			'change_id'   => $change_id,
			'rolled_back' => true,
			'change'      => $this->format_change( $change ),
			'restored'    => $res,
			'message'     => "Change '{$change_id}' rolled back successfully.",
		];
	}

	/**
	 * Roll back all changes belonging to a batch in reverse chronological order.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 * @throws MCPException
	 */
	public function execute_rollback_batch( array $args ): array {
		$batch_id = $this->require_string( $args, 'batch_id' );
		$dry_run  = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

		$changes = $this->change_log->get_entries( [ 'batch_id' => $batch_id ] );

		if ( empty( $changes ) ) {
			throw new MCPException( 'VALIDATION', "Batch ID '{$batch_id}' not found or contains no changes." );
		}

		if ( count( $changes ) > self::MAX_BATCH_SIZE ) {
			throw new MCPException(
				'VALIDATION',
				sprintf(
					'Batch contains %d changes, which exceeds the maximum rollback batch size of %d.',
					count( $changes ),
					self::MAX_BATCH_SIZE
				)
			);
		}

		if ( $dry_run ) {
			return [
				'dry_run'       => true,
				'batch_id'      => $batch_id,
				'changes_count' => count( $changes ),
				'changes'       => array_map( [ $this, 'format_change' ], $changes ),
				'message'       => sprintf( 'Dry run: %d change(s) would be rolled back.', count( $changes ) ),
			];
		}

		$result = $this->change_log->rollback_batch( $batch_id );

		// Purge caches for affected posts:
		$seen = [];
		foreach ( $changes as $c ) {
			$pid = (int) ( $c['post_id'] ?? 0 );
			if ( $pid > 0 && ! isset( $seen[ $pid ] ) ) {
				$seen[ $pid ] = true;
				clean_post_cache( $pid );
				do_action( 'seo_mcp_guard_cache_purged', $pid );
			}
		}

		return [
			'dry_run'     => false,
			'batch_id'    => $batch_id,
			'result'      => $result,
			'message'     => sprintf( 'Rolled back %d change(s) in batch "%s".', $result['rolled_back_count'] ?? 0, $batch_id ),
		];
	}

	/**
	 * Format a raw ChangeLog record for API output.
	 *
	 * @param array<string,mixed> $change
	 * @return array<string,mixed>
	 */
	private function format_change( array $change ): array {
		return [
			'change_id'   => (string) ( $change['change_id'] ?? '' ),
			'batch_id'    => (string) ( $change['batch_id'] ?? '' ),
			'post_id'     => isset( $change['post_id'] ) ? (int) $change['post_id'] : null,
			'tool'        => (string) ( $change['tool_name'] ?? ( $change['tool'] ?? '' ) ),
			'field'       => (string) ( $change['field'] ?? '' ),
			'old_value'   => (string) ( $change['old_value'] ?? '' ),
			'new_value'   => (string) ( $change['new_value'] ?? '' ),
			'status'      => (string) ( $change['status'] ?? '' ),
			'created_at'  => (string) ( $change['created_at'] ?? '' ),
			'rolled_back' => 'rolled_back' === ( $change['status'] ?? '' ),
		];
	}

	private function require_string( array $args, string $key ): string {
		if ( ! isset( $args[ $key ] ) || trim( (string) $args[ $key ] ) === '' ) {
			throw new MCPException( 'VALIDATION', "Missing required argument: {$key}" );
		}
		return trim( (string) $args[ $key ] );
	}
}

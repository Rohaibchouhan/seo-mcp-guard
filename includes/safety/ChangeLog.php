<?php
/**
 * Change Log – audit trail and rollback manager.
 *
 * @package SEOMCPGuard\Safety
 */

declare( strict_types=1 );

namespace SEOMCPGuard\Safety;

use SEOMCPGuard\Exceptions\MCPException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ChangeLog
 *
 * Manages the `{$wpdb->prefix}seo_mcp_guard_changes` custom table.
 */
class ChangeLog {

	/** Batch-halted flag stored as WP option. Prefix + batch_id. */
	private const BATCH_HALTED_OPTION_PREFIX = 'seo_mcp_guard_batch_halted_';

	public const STATUS_PENDING     = 'pending';
	public const STATUS_APPLIED     = 'applied';
	public const STATUS_DRY_RUN     = 'dry_run';
	public const STATUS_ROLLED_BACK = 'rolled_back';

	private \wpdb  $wpdb;
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'seo_mcp_guard_changes';
	}

	/**
	 * Insert change record(s) and return the primary change UUID.
	 *
	 * Accepts single-field change format or multi-field before/after arrays.
	 *
	 * @param array $data Change data.
	 * @return string The change_id (UUID v4).
	 * @throws MCPException On DB error.
	 */
	public function snapshot( array $data ): string {
		$change_id = $this->generate_uuid4();
		$now       = current_time( 'mysql', true );

		$batch_id        = (string) ( $data['batch_id'] ?? '' );
		$idempotency_key = (string) ( $data['idempotency_key'] ?? '' );
		$post_id         = (int) ( $data['post_id'] ?? 0 );
		$tool_name       = (string) ( $data['tool_name'] ?? '' );
		$user_id         = (int) ( $data['user_id'] ?? get_current_user_id() );
		$status          = (string) ( $data['status'] ?? self::STATUS_PENDING );

		// If a single field was provided:
		if ( isset( $data['field'] ) ) {
			$field     = (string) $data['field'];
			$old_value = is_array( $data['old_value'] ?? null ) ? wp_json_encode( $data['old_value'] ) : (string) ( $data['old_value'] ?? '' );
			$new_value = is_array( $data['new_value'] ?? null ) ? wp_json_encode( $data['new_value'] ) : (string) ( $data['new_value'] ?? '' );

			$inserted = $this->wpdb->insert(
				$this->table,
				[
					'change_id'       => $change_id,
					'batch_id'        => $batch_id,
					'idempotency_key' => $idempotency_key,
					'post_id'         => $post_id,
					'tool_name'       => $tool_name,
					'field'           => $field,
					'old_value'       => $old_value,
					'new_value'       => $new_value,
					'user_id'         => $user_id,
					'status'          => $status,
					'created_at'      => $now,
					'updated_at'      => $now,
				],
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);

			if ( false === $inserted ) {
				throw MCPException::internal( 'ChangeLog: DB insert failed – ' . $this->wpdb->last_error );
			}

			return $change_id;
		}

		// If fields_before and fields_after are provided:
		$fields_before = isset( $data['fields_before'] ) && is_array( $data['fields_before'] ) ? $data['fields_before'] : [];
		$fields_after  = isset( $data['fields_after'] ) && is_array( $data['fields_after'] ) ? $data['fields_after'] : [];

		if ( empty( $fields_after ) && ! empty( $fields_before ) ) {
			$fields_after = $fields_before;
		}

		$primary_id = $change_id;
		$first      = true;

		foreach ( $fields_after as $f_key => $new_val ) {
			$old_val = $fields_before[ $f_key ] ?? null;
			$cid     = $first ? $primary_id : $this->generate_uuid4();
			$first   = false;

			$old_str = is_array( $old_val ) ? wp_json_encode( $old_val ) : (string) ( $old_val ?? '' );
			$new_str = is_array( $new_val ) ? wp_json_encode( $new_val ) : (string) ( $new_val ?? '' );

			$inserted = $this->wpdb->insert(
				$this->table,
				[
					'change_id'       => $cid,
					'batch_id'        => $batch_id,
					'idempotency_key' => $idempotency_key,
					'post_id'         => $post_id,
					'tool_name'       => $tool_name,
					'field'           => (string) $f_key,
					'old_value'       => $old_str,
					'new_value'       => $new_str,
					'user_id'         => $user_id,
					'status'          => $status,
					'created_at'      => $now,
					'updated_at'      => $now,
				],
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);

			if ( false === $inserted ) {
				throw MCPException::internal( 'ChangeLog: DB insert failed – ' . $this->wpdb->last_error );
			}
		}

		// Fallback for empty fields:
		if ( $first ) {
			$this->wpdb->insert(
				$this->table,
				[
					'change_id'       => $primary_id,
					'batch_id'        => $batch_id,
					'idempotency_key' => $idempotency_key,
					'post_id'         => $post_id,
					'tool_name'       => $tool_name,
					'field'           => 'general',
					'old_value'       => '',
					'new_value'       => '',
					'user_id'         => $user_id,
					'status'          => $status,
					'created_at'      => $now,
					'updated_at'      => $now,
				],
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);
		}

		return $primary_id;
	}

	/**
	 * Mark a change record (and all sibling field records in the same batch) as successfully applied.
	 */
	public function mark_applied( string $change_id ): void {
		$record = $this->get_change( $change_id );
		if ( $record && ! empty( $record['batch_id'] ) ) {
			$this->wpdb->update(
				$this->table,
				[
					'status'     => self::STATUS_APPLIED,
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'batch_id' => $record['batch_id'] ],
				[ '%s', '%s' ],
				[ '%s' ]
			);
		} else {
			$this->update_status( $change_id, self::STATUS_APPLIED );
		}
	}

	/**
	 * Mark a change record as rolled back.
	 */
	public function mark_rolled_back( string $change_id ): void {
		$this->update_status( $change_id, self::STATUS_ROLLED_BACK );
	}

	/**
	 * Roll back a single applied change record.
	 *
	 * @param string $change_id UUID of the change to roll back.
	 * @return array
	 * @throws MCPException NOT_FOUND or NOT_ALLOWED.
	 */
	public function rollback( string $change_id ): array {
		$record = $this->get_change( $change_id );

		if ( null === $record ) {
			throw MCPException::not_found( "Change record '{$change_id}' not found." );
		}

		if ( self::STATUS_APPLIED !== $record['status'] ) {
			throw MCPException::not_allowed(
				"Cannot roll back change '{$change_id}': status is '{$record['status']}', expected 'applied'."
			);
		}

		$post_id   = (int) $record['post_id'];
		$field     = (string) $record['field'];
		$old_value = (string) ( $record['old_value'] ?? '' );

		// Decode if JSON array:
		$decoded = json_decode( $old_value, true );
		$is_json = json_last_error() === JSON_ERROR_NONE && is_array( $decoded );
		$val_to_restore = $is_json ? $decoded : $old_value;

		if ( 'post_name' === $field ) {
			wp_update_post( [ 'ID' => $post_id, 'post_name' => $old_value ] );
		} elseif ( 'post_content' === $field ) {
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $old_value ] );
		} elseif ( '_wp_attachment_image_alt' === $field ) {
			update_post_meta( $post_id, '_wp_attachment_image_alt', $old_value );
		} else {
			if ( '' === $old_value || null === $val_to_restore ) {
				delete_post_meta( $post_id, $field );
			} else {
				update_post_meta( $post_id, $field, $val_to_restore );
			}
		}

		$this->mark_rolled_back( $change_id );

		do_action( 'seo_mcp_guard_change_rolled_back', $change_id, $post_id, [ $field => $val_to_restore ] );

		return [
			'rolled_back' => true,
			'change_id'   => $change_id,
			'post_id'     => $post_id,
			'field'       => $field,
			'restored_to' => $old_value,
		];
	}

	/**
	 * Roll back all applied changes in a batch in reverse order.
	 *
	 * @param string $batch_id Batch UUID.
	 * @return array
	 */
	public function rollback_batch( string $batch_id ): array {
		$records = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT change_id FROM {$this->table}
				 WHERE batch_id = %s AND status = %s
				 ORDER BY id DESC",
				$batch_id,
				self::STATUS_APPLIED
			),
			ARRAY_A
		);

		if ( $this->wpdb->last_error ) {
			throw MCPException::internal( 'ChangeLog: rollback_batch query failed – ' . $this->wpdb->last_error );
		}

		$results = [];

		foreach ( (array) $records as $row ) {
			try {
				$results[] = $this->rollback( $row['change_id'] );
			} catch ( MCPException $e ) {
				$results[] = [
					'rolled_back' => false,
					'change_id'   => $row['change_id'],
					'error'       => $e->getMessage(),
				];
			}
		}

		// Clear halted flag if all were rolled back:
		delete_transient( self::BATCH_HALTED_OPTION_PREFIX . $batch_id );

		return [
			'batch_id'          => $batch_id,
			'rolled_back_count' => count( array_filter( $results, fn( $r ) => ! empty( $r['rolled_back'] ) ) ),
			'total_count'       => count( $results ),
			'results'           => $results,
		];
	}

	/**
	 * Find a change record by its idempotency key.
	 */
	public function find_by_idempotency( string $key ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE idempotency_key = %s LIMIT 1",
				$key
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Get a single change record by change_id.
	 */
	public function get_change( string $change_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE change_id = %s LIMIT 1",
				$change_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * List changes with optional limit and post_id filter.
	 */
	public function list_changes( int $limit = 50, ?int $post_id = null ): array {
		$limit = max( 1, min( 200, $limit ) );

		if ( null !== $post_id && $post_id > 0 ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE post_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
					$post_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table} ORDER BY created_at DESC, id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return $rows ?: [];
	}

	/**
	 * Query entries matching filters for Audit Log page and export.
	 *
	 * @param array $filters Filter conditions.
	 * @return array
	 */
	public function get_entries( array $filters = [] ): array {
		[ $where_sql, $params ] = $this->build_where_clause( $filters );

		$limit  = isset( $filters['limit'] ) ? max( 1, min( 500, (int) $filters['limit'] ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$sql = "SELECT * FROM {$this->table} {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Count total entries matching filters.
	 *
	 * @param array $filters Filter conditions.
	 * @return int
	 */
	public function count_entries( array $filters = [] ): int {
		[ $where_sql, $params ] = $this->build_where_clause( $filters );

		$sql = "SELECT COUNT(*) FROM {$this->table} {$where_sql}";
		if ( ! empty( $params ) ) {
			$count = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $params ) );
		} else {
			$count = $this->wpdb->get_var( $sql );
		}

		return (int) $count;
	}

	/**
	 * Build SQL WHERE clause from filters array.
	 *
	 * @param array $filters
	 * @return array{0: string, 1: array}
	 */
	private function build_where_clause( array $filters ): array {
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $filters['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $filters['post_id'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}

		if ( ! empty( $filters['batch_id'] ) ) {
			$where[]  = 'batch_id = %s';
			$params[] = (string) $filters['batch_id'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		return [ 'WHERE ' . implode( ' AND ', $where ), $params ];
	}

	/**
	 * Check if a batch is halted.
	 */
	public function is_batch_halted( string $batch_id ): bool {
		return false !== get_transient( self::BATCH_HALTED_OPTION_PREFIX . $batch_id );
	}

	/**
	 * Mark a batch as halted.
	 */
	public function mark_batch_halted( string $batch_id ): void {
		set_transient( self::BATCH_HALTED_OPTION_PREFIX . $batch_id, 1, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Export CSV string of entries.
	 */
	public function export_csv( array $filters = [] ): string {
		$entries = $this->get_entries( array_merge( $filters, [ 'limit' => 500, 'offset' => 0 ] ) );

		$fh = fopen( 'php://memory', 'w+' );
		if ( false === $fh ) {
			return '';
		}

		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, [ 'Change ID', 'Batch ID', 'Post ID', 'Field', 'Old Value', 'New Value', 'User ID', 'Status', 'Timestamp' ] );

		foreach ( $entries as $row ) {
			fputcsv( $fh, [
				$row['change_id']  ?? '',
				$row['batch_id']   ?? '',
				$row['post_id']    ?? '',
				$row['field']      ?? '',
				$row['old_value']  ?? '',
				$row['new_value']  ?? '',
				$row['user_id']    ?? '',
				$row['status']     ?? '',
				$row['created_at'] ?? '',
			] );
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return is_string( $csv ) ? $csv : '';
	}

	private function update_status( string $change_id, string $status ): void {
		$this->wpdb->update(
			$this->table,
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'change_id' => $change_id ],
			[ '%s', '%s' ],
			[ '%s' ]
		);
	}

	private function generate_uuid4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}

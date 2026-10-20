<?php
declare(strict_types=1);

namespace SEOMCPGuard\Tools;

use SEOMCPGuard\Exceptions\MCPException;
use SEOMCPGuard\Safety\SafetyEngine;

/**
 * Redirects Tool
 *
 * Handles create_redirect, list_redirects, and delete_redirect operations
 * against Rank Math's redirection tables.
 *
 * @package SEOMCPGuard\Tools
 */
class Redirects extends BaseTool {

	/**
	 * Allowed redirect HTTP status codes.
	 */
	private const ALLOWED_CODES = [ 301, 302, 307 ];

	/**
	 * Maximum redirects to traverse when checking for loops.
	 */
	private const LOOP_MAX_DEPTH = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'redirects';
	}

	/**
	 * Default execute — not used directly; dispatch is handled externally.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function execute( array $args ): array {
		return $this->execute_list( $args );
	}

	// -------------------------------------------------------------------------
	// Public operations
	// -------------------------------------------------------------------------

	/**
	 * Create a new redirect.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $source_url    The URL to redirect from (must be a valid path or absolute URL on this site).
	 *     @type string $target_url    The URL to redirect to (relative or absolute).
	 *     @type int    $redirect_type HTTP status code: 301 (default), 302, 307.
	 *     @type bool   $dry_run       If true, validate only — do not write to DB.
	 * }
	 * @return array<string,mixed>
	 * @throws MCPException On validation failure.
	 */
	public function execute_create( array $args ): array {
		global $wpdb;

		$source_url    = $this->require_string( $args, 'source_url' );
		$target_url    = $this->require_string( $args, 'target_url' );
		$redirect_type = isset( $args['redirect_type'] ) ? (int) $args['redirect_type'] : 301;
		$dry_run       = isset( $args['dry_run'] ) && (bool) $args['dry_run'];

		// --- Validate redirect type -------------------------------------------
		if ( ! in_array( $redirect_type, self::ALLOWED_CODES, true ) ) {
			throw new MCPException(
				'VALIDATION',
				sprintf( 'redirect_type must be one of: %s', implode( ', ', self::ALLOWED_CODES ) )
			);
		}

		// --- Validate source URL -----------------------------------------------
		$source_path = $this->normalise_source( $source_url );
		if ( $source_path === null ) {
			throw new MCPException( 'VALIDATION', 'source_url is not a valid URL or path for this site.' );
		}

		// --- Validate target URL -----------------------------------------------
		if ( ! $this->is_valid_target( $target_url ) ) {
			throw new MCPException( 'VALIDATION', 'target_url is not a valid absolute or relative URL.' );
		}

		// --- Duplicate source check --------------------------------------------
		$existing = $this->get_redirect_by_source( $source_path );
		if ( $existing !== null ) {
			throw new MCPException(
				'VALIDATION',
				sprintf( 'source_url already has a redirect (ID %d). Delete it first.', $existing['id'] )
			);
		}

		// --- Loop detection ----------------------------------------------------
		if ( $this->detect_loop( $source_path, $target_url ) ) {
			throw new MCPException( 'VALIDATION', 'Would create a redirect loop.' );
		}

		if ( $dry_run ) {
			return [
				'dry_run'       => true,
				'source_url'    => $source_path,
				'target_url'    => $target_url,
				'redirect_type' => $redirect_type,
				'message'       => 'Dry run: redirect is valid and would be created.',
			];
		}

		// --- Write via SafetyEngine -------------------------------------------
		$now              = current_time( 'mysql' );
		$sources_value    = serialize( [ [ 'pattern' => $source_path, 'comparison' => 'exact' ] ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$new_redirect_id  = null;
		$batch_id         = $this->generate_batch_id();
		$idempotency_key  = $this->build_idempotency_key( 'create_redirect', $args );

		$write_fn = function () use ( $wpdb, $sources_value, $source_path, $target_url, $redirect_type, $now, &$new_redirect_id ) {
			// Insert into main redirections table.
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'rank_math_redirections',
				[
					'sources'      => $sources_value,
					'url_to'       => $target_url,
					'header_code'  => $redirect_type,
					'status'       => 'active',
					'created'      => $now,
					'updated'      => $now,
				],
				[ '%s', '%s', '%d', '%s', '%s', '%s' ]
			);

			if ( $inserted === false ) {
				throw new MCPException(
					'DATABASE',
					'Failed to insert redirect: ' . $wpdb->last_error
				);
			}

			$new_redirect_id = (int) $wpdb->insert_id;

			// Insert into cache table.
			$wpdb->insert(
				$wpdb->prefix . 'rank_math_redirections_cache',
				[
					'from_url'       => $source_path,
					'redirection_id' => $new_redirect_id,
					'object_id'      => 0,
					'object_type'    => 'none',
				],
				[ '%s', '%d', '%d', '%s' ]
			);

			return $new_redirect_id;
		};

		$safety_res = $this->safety->execute_write(
			post_id: 0,
			tool: 'create_redirect',
			write_fn: $write_fn,
			old_values: [],
			new_values: [
				'source_url'    => $source_path,
				'target_url'    => $target_url,
				'redirect_type' => $redirect_type,
			],
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		return [
			'dry_run'       => false,
			'redirect_id'   => $new_redirect_id,
			'source_url'    => $source_path,
			'target_url'    => $target_url,
			'redirect_type' => $redirect_type,
			'status'        => 'active',
			'created'       => $now,
			'change_id'     => $safety_res['change_id'] ?? '',
			'batch_id'      => $batch_id,
			'message'       => 'Redirect created successfully.',
		];
	}

	/**
	 * List existing redirects.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $page     Page number (default 1).
	 *     @type int    $per_page Items per page (default 20, max 100).
	 *     @type string $search   Optional search term matched against source and target.
	 * }
	 * @return array<string,mixed>
	 */
	public function execute_list( array $args ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$search   = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		$offset   = ( $page - 1 ) * $per_page;

		$table = $wpdb->prefix . 'rank_math_redirections';

		if ( $search !== '' ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE `sources` LIKE %s OR `url_to` LIKE %s ORDER BY `id` DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like,
					$like,
					$per_page,
					$offset
				),
				ARRAY_A
			);
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE `sources` LIKE %s OR `url_to` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$like,
					$like
				)
			);
		} else {
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` ORDER BY `id` DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
				),
				ARRAY_A
			);
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$redirects = [];
		foreach ( (array) $rows as $row ) {
			$redirects[] = $this->format_redirect_row( $row );
		}

		return [
			'redirects'  => $redirects,
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Delete a redirect by ID.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int  $redirect_id The numeric redirect row ID.
	 *     @type bool $dry_run     If true, only show what would be deleted.
	 * }
	 * @return array<string,mixed>
	 * @throws MCPException If redirect not found.
	 */
	public function execute_delete( array $args ): array {
		global $wpdb;

		$redirect_id = $this->require_int( $args, 'redirect_id' );
		$dry_run     = isset( $args['dry_run'] ) && (bool) $args['dry_run'];

		$table = $wpdb->prefix . 'rank_math_redirections';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE `id` = %d", $redirect_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( $row === null ) {
			throw new MCPException( 'VALIDATION', "Redirect ID {$redirect_id} not found." );
		}

		$formatted = $this->format_redirect_row( $row );

		if ( $dry_run ) {
			return [
				'dry_run'         => true,
				'redirect'        => $formatted,
				'message'         => 'Dry run: this redirect would be deleted.',
			];
		}

		$batch_id        = $this->generate_batch_id();
		$idempotency_key = $this->build_idempotency_key( 'delete_redirect', $args );

		$write_fn = function () use ( $wpdb, $redirect_id ) {
			$wpdb->delete(
				$wpdb->prefix . 'rank_math_redirections',
				[ 'id' => $redirect_id ],
				[ '%d' ]
			);
			$wpdb->delete(
				$wpdb->prefix . 'rank_math_redirections_cache',
				[ 'redirection_id' => $redirect_id ],
				[ '%d' ]
			);
		};

		$safety_res = $this->safety->execute_write(
			post_id: 0,
			tool: 'delete_redirect',
			write_fn: $write_fn,
			old_values: $formatted,
			new_values: [],
			dry_run: $dry_run,
			batch_id: $batch_id,
			idempotency_key: $idempotency_key,
			user_id: $this->current_user->ID
		);

		return [
			'dry_run'   => false,
			'deleted'   => $formatted,
			'change_id' => $safety_res['change_id'] ?? '',
			'batch_id'  => $batch_id,
			'message'   => "Redirect ID {$redirect_id} deleted successfully.",
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Clean a URL or path to a normalised slug path for comparison.
	 */
	private function clean_path( string $url ): string {
		$path = $this->normalise_target_to_path( $url );
		return trim( $path, '/' );
	}

	/**
	 * Detect if adding source→target would create a redirect loop or chain cycle.
	 *
	 * @param string $source Normalised source path.
	 * @param string $target Raw target URL.
	 * @return bool True if a loop would result.
	 */
	private function detect_loop( string $source, string $target ): bool {
		$clean_source = $this->clean_path( $source );
		$clean_target = $this->clean_path( $target );

		if ( $clean_source === $clean_target ) {
			return true;
		}

		$all_redirects = $this->get_existing_redirects();

		$map = [];
		foreach ( $all_redirects as $r ) {
			$src = $this->clean_path( $r['source'] );
			$tgt = $this->clean_path( $r['target'] );
			if ( $src !== '' && $tgt !== '' ) {
				$map[ $src ] = $tgt;
			}
		}

		$map[ $clean_source ] = $clean_target;

		$visited = [];
		$current = $clean_target;
		$depth   = 0;

		while ( $depth < 20 ) {
			if ( $current === $clean_source ) {
				return true;
			}
			if ( isset( $visited[ $current ] ) ) {
				return true;
			}
			$visited[ $current ] = true;

			if ( ! isset( $map[ $current ] ) ) {
				break;
			}
			$current = $map[ $current ];
			$depth++;
		}

		return false;
	}

	/**
	 * Retrieve all active redirects from the database.
	 *
	 * @return array<int,array{id:int,source:string,target:string,type:int,status:string}>
	 */
	private function get_existing_redirects(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		$rows  = $wpdb->get_results(
			"SELECT `id`, `sources`, `url_to`, `header_code`, `status` FROM `{$table}` WHERE `status` = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$result = [];
		foreach ( (array) $rows as $row ) {
			$source_path = $this->extract_source_path( $row['sources'] );
			if ( $source_path === '' ) {
				continue;
			}
			$result[] = [
				'id'     => (int) $row['id'],
				'source' => $source_path,
				'target' => (string) $row['url_to'],
				'type'   => (int) $row['header_code'],
				'status' => (string) $row['status'],
			];
		}

		return $result;
	}

	/**
	 * Look up a redirect whose source matches the given path.
	 *
	 * @param string $source_path Normalised source path.
	 * @return array<string,mixed>|null
	 */
	private function get_redirect_by_source( string $source_path ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections_cache';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT `redirection_id` FROM `{$table}` WHERE `from_url` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source_path
			),
			ARRAY_A
		);

		if ( $row === null ) {
			return null;
		}

		$redir_table = $wpdb->prefix . 'rank_math_redirections';
		$redirect    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$redir_table}` WHERE `id` = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $row['redirection_id']
			),
			ARRAY_A
		);

		return $redirect ? $this->format_redirect_row( $redirect ) : null;
	}

	/**
	 * Normalise a source URL or path to a site-relative path string.
	 *
	 * Returns null if the URL is external or invalid.
	 *
	 * @param string $url Raw source URL.
	 * @return string|null
	 */
	private function normalise_source( string $url ): ?string {
		$url = trim( $url );

		// Already a relative path.
		if ( str_starts_with( $url, '/' ) ) {
			return '/' . ltrim( $url, '/' );
		}

		// Absolute URL — must be on this site.
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$home      = home_url();
			$home_host = (string) wp_parse_url( $home, PHP_URL_HOST );
			$url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );

			if ( $url_host !== $home_host ) {
				return null;
			}

			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query = wp_parse_url( $url, PHP_URL_QUERY );

			return $path . ( $query ? '?' . $query : '' );
		}

		return null;
	}

	/**
	 * Validate a redirect target URL.
	 *
	 * Accepts absolute URLs (any domain) or root-relative paths.
	 *
	 * @param string $url Target URL.
	 * @return bool
	 */
	private function is_valid_target( string $url ): bool {
		$url = trim( $url );
		if ( str_starts_with( $url, '/' ) ) {
			return true;
		}
		return (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * Normalise a target URL to a path for loop-detection comparison.
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	private function normalise_target_to_path( string $url ): string {
		$url = trim( $url );
		if ( str_starts_with( $url, '/' ) ) {
			return $url;
		}
		if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			return $path . ( $query ? '?' . $query : '' );
		}
		return $url;
	}

	/**
	 * Extract the primary source path from Rank Math's serialised sources field.
	 *
	 * @param string $serialised Serialised sources value from DB.
	 * @return string
	 */
	private function extract_source_path( string $serialised ): string {
		$data = @unserialize( $serialised ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '';
		}

		$first = reset( $data );
		return isset( $first['pattern'] ) ? (string) $first['pattern'] : '';
	}

	/**
	 * Format a raw DB redirect row into a consistent array.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array<string,mixed>
	 */
	private function format_redirect_row( array $row ): array {
		return [
			'id'      => (int) $row['id'],
			'source'  => $this->extract_source_path( (string) $row['sources'] ),
			'target'  => (string) $row['url_to'],
			'type'    => (int) $row['header_code'],
			'status'  => (string) $row['status'],
			'created' => (string) ( $row['created'] ?? '' ),
			'updated' => (string) ( $row['updated'] ?? '' ),
		];
	}

	// -------------------------------------------------------------------------
	// Argument helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a required string argument.
	 *
	 * @param array<string,mixed> $args
	 * @param string              $key
	 * @return string
	 * @throws MCPException If key is missing or empty.
	 */
	private function require_string( array $args, string $key ): string {
		if ( ! isset( $args[ $key ] ) || trim( (string) $args[ $key ] ) === '' ) {
			throw new MCPException( 'VALIDATION', "Missing required argument: {$key}" );
		}
		return trim( (string) $args[ $key ] );
	}

	/**
	 * Retrieve a required integer argument.
	 *
	 * @param array<string,mixed> $args
	 * @param string              $key
	 * @return int
	 * @throws MCPException If key is missing or not a positive integer.
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
}

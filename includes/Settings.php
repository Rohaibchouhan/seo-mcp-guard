<?php
/**
 * Plugin Settings Manager
 *
 * @package SEOMCPGuard
 */

declare( strict_types=1 );

namespace SEOMCPGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin settings and bearer token generation.
 */
class Settings {

	public const OPTION_KEY = 'seo_mcp_guard_settings';
	public const PENDING_TOKEN_TRANSIENT = 'seo_mcp_guard_pending_display_token_';

	/**
	 * Default settings values.
	 */
	private const DEFAULTS = [
		'enabled'                  => true,
		'read_only_mode'           => false,
		'dry_run_by_default'       => true,
		'allowed_post_types'       => [ 'post', 'page' ],
		'allowed_post_ids'         => [],
		'rate_limit_per_minute'    => 60,
		'max_batch_size'           => 20,
		'allow_production_writes'  => false,
		'delete_data_on_uninstall' => false,
		'bearer_tokens'            => [],
		'audit_log_retention_days' => 90,
		'log_retention_days'       => 90,
	];

	private ?array $cache = null;

	public function __construct() {
		$this->load();
	}

	/**
	 * Load settings from database.
	 */
	private function load(): void {
		$stored      = get_option( self::OPTION_KEY, [] );
		$stored      = is_array( $stored ) ? $stored : [];
		$this->cache = array_merge( self::DEFAULTS, $stored );
	}

	/**
	 * Get a specific setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback default.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		if ( null === $this->cache ) {
			$this->load();
		}
		return $this->cache ?? self::DEFAULTS;
	}

	/**
	 * Alias for get_all().
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->get_all();
	}

	/**
	 * Update settings (merges with existing).
	 *
	 * @param array<string, mixed> $data Settings to update.
	 * @return bool
	 */
	public function update( array $data ): bool {
		$current     = $this->get_all();
		$new         = array_merge( $current, $this->sanitize( $data ) );
		$this->cache = $new;
		return update_option( self::OPTION_KEY, $new, false );
	}

	/**
	 * Convenience getters.
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled', true );
	}

	public function is_read_only(): bool {
		return (bool) $this->get( 'read_only_mode', false );
	}

	public function is_dry_run_default(): bool {
		return (bool) $this->get( 'dry_run_by_default', true );
	}

	public function allow_production_writes(): bool {
		return (bool) $this->get( 'allow_production_writes', false );
	}

	public function get_allowed_post_types(): array {
		$types = $this->get( 'allowed_post_types', [ 'post', 'page' ] );
		return is_array( $types ) ? array_values( $types ) : [ 'post', 'page' ];
	}

	public function get_allowed_post_ids(): array {
		$ids = $this->get( 'allowed_post_ids', [] );
		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
	}

	public function get_rate_limit(): int {
		return (int) $this->get( 'rate_limit_per_minute', 60 );
	}

	public function get_max_batch_size(): int {
		return (int) $this->get( 'max_batch_size', 20 );
	}

	/**
	 * Sanitize settings data before saving.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $data ): array {
		$clean = [];

		if ( isset( $data['enabled'] ) ) {
			$clean['enabled'] = ! empty( $data['enabled'] );
		}
		if ( isset( $data['read_only_mode'] ) ) {
			$clean['read_only_mode'] = ! empty( $data['read_only_mode'] );
		}
		if ( isset( $data['dry_run_by_default'] ) ) {
			$clean['dry_run_by_default'] = ! empty( $data['dry_run_by_default'] );
		}
		if ( isset( $data['allow_production_writes'] ) ) {
			$clean['allow_production_writes'] = ! empty( $data['allow_production_writes'] );
		}
		if ( isset( $data['delete_data_on_uninstall'] ) ) {
			$clean['delete_data_on_uninstall'] = ! empty( $data['delete_data_on_uninstall'] );
		}
		if ( isset( $data['allowed_post_types'] ) && is_array( $data['allowed_post_types'] ) ) {
			$valid_types                 = get_post_types( [ 'public' => true ] );
			$clean['allowed_post_types'] = array_values(
				array_filter(
					array_map( 'sanitize_key', $data['allowed_post_types'] ),
					fn( $pt ) => in_array( $pt, $valid_types, true )
				)
			);
		}
		if ( isset( $data['allowed_post_ids'] ) ) {
			$ids = is_array( $data['allowed_post_ids'] ) ? $data['allowed_post_ids'] : explode( ',', (string) $data['allowed_post_ids'] );
			$clean['allowed_post_ids'] = array_values(
				array_filter( array_map( 'absint', $ids ) )
			);
		}
		if ( isset( $data['rate_limit_per_minute'] ) ) {
			$clean['rate_limit_per_minute'] = max( 1, min( 1000, (int) $data['rate_limit_per_minute'] ) );
		}
		if ( isset( $data['max_batch_size'] ) ) {
			$clean['max_batch_size'] = max( 1, min( 100, (int) $data['max_batch_size'] ) );
		}
		if ( isset( $data['audit_log_retention_days'] ) ) {
			$clean['audit_log_retention_days'] = max( 30, min( 365, (int) $data['audit_log_retention_days'] ) );
			$clean['log_retention_days']       = $clean['audit_log_retention_days'];
		} elseif ( isset( $data['log_retention_days'] ) ) {
			$clean['log_retention_days']       = max( 30, min( 365, (int) $data['log_retention_days'] ) );
			$clean['audit_log_retention_days'] = $clean['log_retention_days'];
		}

		return $clean;
	}

	/**
	 * Generate a new bearer token.
	 * Returns the plaintext token (shown only once) and stores only the hash.
	 *
	 * @param string $label Human-readable label for the token.
	 * @return array{token: string, hash: string, label: string, id: string, created_at: string}
	 */
	public function generate_bearer_token( string $label ): array {
		$label     = sanitize_text_field( $label );
		$plaintext = 'smcp_' . bin2hex( random_bytes( 24 ) );
		$hash      = wp_hash_password( $plaintext );

		$tokens   = $this->get( 'bearer_tokens', [] );
		$tokens   = is_array( $tokens ) ? $tokens : [];
		$token_id = (string) ( count( $tokens ) + 1 ); // Or hex ID
		$created  = current_time( 'mysql' );

		$token_data = [
			'id'         => $token_id,
			'label'      => $label,
			'hash'       => $hash,
			'token_prefix' => substr( $plaintext, 0, 8 ) . '…',
			'created_at' => $created,
			'last_used'  => null,
		];

		$tokens[] = $token_data;
		$this->update( [ 'bearer_tokens' => $tokens ] );

		// Set pending transient so TokenManagerPage can show plaintext once.
		$user_id = get_current_user_id();
		set_transient(
			self::PENDING_TOKEN_TRANSIENT . $user_id,
			[
				'token'      => $plaintext,
				'label'      => $label,
				'id'         => $token_id,
				'created_at' => $created,
			],
			60
		);

		return [
			'token'      => $plaintext,
			'hash'       => $hash,
			'label'      => $label,
			'id'         => $token_id,
			'created_at' => $created,
		];
	}

	/**
	 * Alias for generate_bearer_token().
	 */
	public function generate_token( string $label ): array {
		return $this->generate_bearer_token( $label );
	}

	/**
	 * Retrieve and immediately clear any pending display token for the current user.
	 *
	 * @return array|null
	 */
	public function consume_pending_display_token(): ?array {
		$user_id = get_current_user_id();
		$key     = self::PENDING_TOKEN_TRANSIENT . $user_id;
		$data    = get_transient( $key );
		if ( false !== $data && is_array( $data ) ) {
			delete_transient( $key );
			return $data;
		}
		return null;
	}

	/**
	 * Revoke a bearer token by its ID.
	 *
	 * @param string|int $token_id Token ID to revoke.
	 * @return bool
	 */
	public function revoke_bearer_token( string|int $token_id ): bool {
		$tokens  = $this->get( 'bearer_tokens', [] );
		$tokens  = is_array( $tokens ) ? $tokens : [];
		$token_id_str = (string) $token_id;

		$updated = array_values(
			array_filter( $tokens, fn( $t ) => (string) ( $t['id'] ?? '' ) !== $token_id_str )
		);

		if ( count( $updated ) === count( $tokens ) ) {
			return false; // Not found.
		}

		return $this->update( [ 'bearer_tokens' => $updated ] );
	}

	/**
	 * Alias for revoke_bearer_token().
	 */
	public function revoke_token( string|int $token_id ): bool {
		return $this->revoke_bearer_token( $token_id );
	}

	/**
	 * Get bearer tokens list (without the plaintext token, for display).
	 *
	 * @return array<int, array{id: string, label: string, created_at: string, last_used: ?string}>
	 */
	public function get_bearer_tokens(): array {
		$tokens = $this->get( 'bearer_tokens', [] );
		$tokens = is_array( $tokens ) ? $tokens : [];
		return array_map(
			fn( $t ) => [
				'id'           => (string) ( $t['id'] ?? '' ),
				'label'        => (string) ( $t['label'] ?? '' ),
				'token_prefix' => (string) ( $t['token_prefix'] ?? 'smcp_…' ),
				'created_at'   => (string) ( $t['created_at'] ?? '' ),
				'last_used'    => $t['last_used'] ?? null,
			],
			$tokens
		);
	}

	/**
	 * Alias for get_bearer_tokens().
	 */
	public function get_tokens(): array {
		return $this->get_bearer_tokens();
	}

	/**
	 * Verify a bearer token against stored hashes.
	 * Updates last_used timestamp on match.
	 *
	 * @param string $token Plaintext bearer token.
	 * @return bool
	 */
	public function verify_bearer_token( string $token ): bool {
		$tokens  = $this->get( 'bearer_tokens', [] );
		$tokens  = is_array( $tokens ) ? $tokens : [];
		$matched = false;

		foreach ( $tokens as $idx => $stored ) {
			if ( isset( $stored['hash'] ) && wp_check_password( $token, $stored['hash'] ) ) {
				$tokens[ $idx ]['last_used'] = current_time( 'mysql' );
				$matched = true;
				break;
			}
		}

		if ( $matched ) {
			$this->update( [ 'bearer_tokens' => $tokens ] );
		}

		return $matched;
	}
}

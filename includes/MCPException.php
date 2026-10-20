<?php
/**
 * Domain exception for all MCP-layer errors in SEO MCP Guard.
 *
 * @package SEOMCPGuard
 */

declare( strict_types=1 );

namespace SEOMCPGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain exception for all MCP-layer errors in SEO MCP Guard.
 *
 * Carries an application-level error code and an HTTP status code so that
 * the router can translate it into a well-formed JSON-RPC error response
 * without knowing the details of what went wrong.
 *
 * Error codes (strictly matching prompt contract):
 *   NOT_ALLOWED   – Lacks capability, disallowed post type/ID, or plugin disabled.
 *   VALIDATION    – Failed schema, content, or business rule validation.
 *   LOCKED        – Post-level lock held or batch halted.
 *   RATE_LIMIT    – Calls-per-minute quota exceeded.
 *   HEALTH_FAILED – URL returned non-200, noindex detected, or body corrupted.
 */
class MCPException extends \RuntimeException {

	public const NOT_ALLOWED   = 'NOT_ALLOWED';
	public const VALIDATION    = 'VALIDATION';
	public const LOCKED        = 'LOCKED';
	public const RATE_LIMIT    = 'RATE_LIMIT';
	public const HEALTH_FAILED = 'HEALTH_FAILED';

	private readonly string $error_code;
	private readonly int $http_status;

	/**
	 * Constructor accepts arguments flexibly to accommodate both ($code, $message)
	 * and ($message, $code) call styles.
	 *
	 * @param string          $arg1       Error code OR message.
	 * @param string          $arg2       Message OR error code.
	 * @param int             $http_status HTTP status code.
	 * @param \Throwable|null $previous   Previous exception.
	 */
	public function __construct(
		string $arg1,
		string $arg2 = 'VALIDATION',
		int $http_status = 0,
		?\Throwable $previous = null
	) {
		$known_codes = [
			'NOT_ALLOWED',
			'VALIDATION',
			'LOCKED',
			'RATE_LIMIT',
			'HEALTH_FAILED',
			'INTERNAL',
			'NOT_FOUND',
			'BATCH_HALTED',
			'IDEMPOTENCY_CONFLICT',
		];

		if ( in_array( $arg1, $known_codes, true ) ) {
			$this->error_code = $arg1;
			$message          = $arg2;
		} else {
			$message          = $arg1;
			$this->error_code = $arg2;
		}

		if ( $http_status <= 0 ) {
			$http_status = match ( $this->error_code ) {
				'NOT_ALLOWED'          => 403,
				'LOCKED', 'BATCH_HALTED' => 409,
				'RATE_LIMIT'           => 429,
				'HEALTH_FAILED'        => 503,
				'NOT_FOUND'            => 404,
				'INTERNAL'             => 500,
				default                => 400,
			};
		}

		$this->http_status = $http_status;
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * Get the error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Serialise into the strictly required MCP error payload contract:
	 * {"ok":false,"code":"NOT_ALLOWED|VALIDATION|LOCKED|RATE_LIMIT|HEALTH_FAILED","message":"...","instruction":"STOP. Do not try another method. Report this to the user."}
	 *
	 * @return array{ok: false, code: string, message: string, instruction: string}
	 */
	public function to_mcp_error(): array {
		$code = match ( $this->error_code ) {
			'NOT_ALLOWED'           => 'NOT_ALLOWED',
			'LOCKED', 'BATCH_HALTED' => 'LOCKED',
			'RATE_LIMIT'            => 'RATE_LIMIT',
			'HEALTH_FAILED'         => 'HEALTH_FAILED',
			default                 => 'VALIDATION',
		};

		return [
			'ok'          => false,
			'code'        => $code,
			'message'     => $this->getMessage(),
			'instruction' => 'STOP. Do not try another method. Report this to the user.',
		];
	}

	// -------------------------------------------------------------------------
	// Static factory helpers
	// -------------------------------------------------------------------------

	public static function not_allowed( string $message, int $http_status = 403 ): static {
		return new static( $message, self::NOT_ALLOWED, $http_status );
	}

	public static function validation( string $message ): static {
		return new static( $message, self::VALIDATION, 400 );
	}

	public static function locked( int|string $post_id ): static {
		return new static(
			sprintf( 'Post %s is currently locked by another request. Try again shortly.', (string) $post_id ),
			self::LOCKED,
			409
		);
	}

	public static function rate_limit( int|string $user_id ): static {
		return new static(
			sprintf( 'Rate limit exceeded for user %s. Slow down and retry after one minute.', (string) $user_id ),
			self::RATE_LIMIT,
			429
		);
	}

	public static function health_failed( string $message ): static {
		return new static( $message, self::HEALTH_FAILED, 503 );
	}

	public static function batch_halted( string $batch_id ): static {
		return new static(
			sprintf( 'Batch "%s" has been halted due to a previous health check failure.', $batch_id ),
			self::LOCKED,
			409
		);
	}

	public static function not_found( string $message ): static {
		return new static( $message, self::VALIDATION, 404 );
	}

	public static function internal( string $message ): static {
		return new static( $message, self::VALIDATION, 500 );
	}
}

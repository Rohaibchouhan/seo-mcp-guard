<?php
/**
 * MCP HTTP Router (JSON-RPC 2.0)
 *
 * @package SEOMCPGuard
 */

declare( strict_types=1 );

namespace SEOMCPGuard;

use SEOMCPGuard\Safety\SafetyEngine;
use SEOMCPGuard\Safety\ChangeLog;
use SEOMCPGuard\Tools\ListContent;
use SEOMCPGuard\Tools\GetSEO;
use SEOMCPGuard\Tools\UpdateSEO;
use SEOMCPGuard\Tools\UpdateImageAlt;
use SEOMCPGuard\Tools\UpdateSlug;
use SEOMCPGuard\Tools\Redirects;
use SEOMCPGuard\Tools\AddInternalLink;
use SEOMCPGuard\Tools\EditTextBlock;
use SEOMCPGuard\Tools\AuditPage;
use SEOMCPGuard\Tools\Changes;
use SEOMCPGuard\Tools\HealthCheckTool;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the MCP HTTP endpoint.
 * Implements the Model Context Protocol over Streamable HTTP (JSON-RPC 2.0).
 */
final class MCPRouter {

	private const NAMESPACE = 'seo-mcp/v1';
	private const ROUTE     = '/mcp';

	public function __construct(
		private readonly Settings $settings,
		private readonly Auth $auth,
		private readonly SafetyEngine $safety,
		private readonly ChangeLog $change_log
	) {}

	/**
	 * Register the REST API route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => [ \WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE ],
				'callback'            => [ $this, 'handle_request' ],
				'permission_callback' => '__return_true', // Auth is handled inside.
				'args'                => [],
			]
		);
	}

	/**
	 * Handle an incoming MCP request.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		// Kill switch check.
		if ( ! $this->settings->get( 'enabled', true ) ) {
			return $this->error_response(
				'NOT_ALLOWED',
				__( 'SEO MCP Guard is currently disabled.', 'seo-mcp-guard' ),
				503
			);
		}

		// Authenticate.
		try {
			$user = $this->auth->require_capability( 'edit_posts' );
		} catch ( MCPException $e ) {
			return $this->error_response( $e->get_error_code(), $e->getMessage(), $e->get_http_status() );
		}

		// Rate limit check.
		try {
			$rate_limit = $this->settings->get( 'rate_limit_per_minute', 60 );
			( new RateLimit() )->check_and_increment( $user->ID, (int) $rate_limit );
		} catch ( MCPException $e ) {
			return $this->error_response( $e->get_error_code(), $e->getMessage(), $e->get_http_status() );
		}

		// Parse JSON-RPC 2.0 body.
		$body = $request->get_body();
		if ( empty( $body ) ) {
			return $this->jsonrpc_error_response( -32600, 'Invalid Request: empty body', null );
		}

		$payload = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $payload ) ) {
			return $this->jsonrpc_error_response( -32700, 'Parse error: invalid JSON', null );
		}

		// Validate JSON-RPC envelope.
		if ( ( $payload['jsonrpc'] ?? '' ) !== '2.0' ) {
			return $this->jsonrpc_error_response( -32600, 'Invalid Request: jsonrpc must be "2.0"', $payload['id'] ?? null );
		}

		$method = $payload['method'] ?? '';
		$params = $payload['params'] ?? [];
		$id     = $payload['id'] ?? null;

		if ( ! is_string( $method ) || empty( $method ) ) {
			return $this->jsonrpc_error_response( -32600, 'Invalid Request: method is required', $id );
		}
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		// Dispatch.
		try {
			$result = match ( $method ) {
				'initialize'   => $this->handle_initialize( $params, $id ),
				'tools/list'   => $this->handle_tools_list( $params, $id ),
				'tools/call'   => $this->handle_tools_call( $params, $id, $user ),
				default        => throw new \RuntimeException( "Method not found: {$method}", -32601 ),
			};
		} catch ( MCPException $e ) {
			$this->log_audit( $user->ID, 'error', $method, $params, $e->to_mcp_error() );
			return new \WP_REST_Response(
				[
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => $e->to_mcp_error(),
				],
				$e->get_http_status()
			);
		} catch ( \RuntimeException $e ) {
			$code = $e->getCode() ?: -32603;
			return $this->jsonrpc_error_response( $code, $e->getMessage(), $id );
		} catch ( \Throwable $e ) {
			// Never expose internal errors.
			return $this->jsonrpc_error_response( -32603, 'Internal server error.', $id );
		}

		$this->log_audit( $user->ID, 'success', $method, $params, $result );

		$response = new \WP_REST_Response(
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			],
			200
		);
		$response->header( 'Content-Type', 'application/json' );
		return $response;
	}

	/**
	 * Handle MCP initialize method.
	 *
	 * @param array            $params Request params.
	 * @param string|int|null  $id     Request ID.
	 * @return array
	 */
	private function handle_initialize( array $params, string|int|null $id ): array {
		return [
			'protocolVersion' => '2024-11-05',
			'capabilities'    => [
				'tools' => [
					'listChanged' => false,
				],
			],
			'serverInfo' => [
				'name'    => 'SEO MCP Guard',
				'version' => SEO_MCP_GUARD_VERSION,
			],
		];
	}

	/**
	 * Handle MCP tools/list method.
	 *
	 * @param array            $params Request params.
	 * @param string|int|null  $id     Request ID.
	 * @return array
	 */
	private function handle_tools_list( array $params, string|int|null $id ): array {
		return [
			'tools' => $this->tool_definitions(),
		];
	}

	/**
	 * Handle MCP tools/call method.
	 *
	 * @param array            $params Request params.
	 * @param string|int|null  $id     Request ID.
	 * @param \WP_User         $user   Authenticated user.
	 * @return array
	 * @throws MCPException On tool execution error.
	 */
	private function handle_tools_call( array $params, string|int|null $id, \WP_User $user ): array {
		$tool_name  = $params['name'] ?? '';
		$tool_input = $params['arguments'] ?? [];

		if ( empty( $tool_name ) ) {
			throw new MCPException( 'tools/call requires a name parameter.', 'VALIDATION', 400 );
		}

		if ( ! is_array( $tool_input ) ) {
			$tool_input = [];
		}

		$write_tools = [
			'update_seo', 'update_image_alt', 'update_slug',
			'create_redirect', 'delete_redirect', 'add_internal_link',
			'edit_text_block', 'rollback', 'rollback_batch',
		];

		// Rank Math active guard: all write tools require Rank Math.
		if ( in_array( $tool_name, $write_tools, true ) ) {
			if ( ! defined( 'RANK_MATH_VERSION' ) && ! class_exists( 'RankMath\RankMath', false ) ) {
				throw new MCPException(
					__( 'Rank Math is not active. Write operations are disabled.', 'seo-mcp-guard' ),
					'NOT_ALLOWED',
					403
				);
			}
		}

		// Read-only mode: block write tools.
		if ( $this->settings->get( 'read_only_mode', false ) ) {
			if ( in_array( $tool_name, $write_tools, true ) ) {
				throw new MCPException(
					__( 'Plugin is in read-only mode. Write operations are not permitted.', 'seo-mcp-guard' ),
					'NOT_ALLOWED',
					403
				);
			}
		}

		// Production write guard.
		$env = defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'production';
		if ( $env === 'production' && ! $this->settings->get( 'allow_production_writes', false ) ) {
			if ( in_array( $tool_name, $write_tools, true ) ) {
				throw new MCPException(
					__( 'Production writes are not enabled. Enable "Allow Production Writes" in settings or use dry_run=true.', 'seo-mcp-guard' ),
					'NOT_ALLOWED',
					403
				);
			}
		}

		$result = $this->dispatch_tool( $tool_name, $tool_input, $user );

		// Wrap in MCP content format.
		return [
			'content' => [
				[
					'type' => 'text',
					'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				],
			],
			'isError' => isset( $result['ok'] ) && $result['ok'] === false,
		];
	}

	/**
	 * Dispatch a tool call to the appropriate tool class.
	 *
	 * @param string   $tool_name  Tool name.
	 * @param array    $args       Tool arguments.
	 * @param \WP_User $user       Authenticated user.
	 * @return array
	 * @throws MCPException If tool not found or execution fails.
	 */
	private function dispatch_tool( string $tool_name, array $args, \WP_User $user ): array {
		$tool_args = [ $this->settings, $this->safety, $this->change_log, $user ];

		return match ( $tool_name ) {
			'list_content'     => ( new ListContent( ...$tool_args ) )->execute( $args ),
			'get_seo'          => ( new GetSEO( ...$tool_args ) )->execute( $args ),
			'update_seo'       => ( new UpdateSEO( ...$tool_args ) )->execute( $args ),
			'update_image_alt' => ( new UpdateImageAlt( ...$tool_args ) )->execute( $args ),
			'update_slug'      => ( new UpdateSlug( ...$tool_args ) )->execute( $args ),
			'create_redirect'  => ( new Redirects( ...$tool_args ) )->execute_create( $args ),
			'list_redirects'   => ( new Redirects( ...$tool_args ) )->execute_list( $args ),
			'delete_redirect'  => ( new Redirects( ...$tool_args ) )->execute_delete( $args ),
			'add_internal_link'=> ( new AddInternalLink( ...$tool_args ) )->execute( $args ),
			'edit_text_block'  => ( new EditTextBlock( ...$tool_args ) )->execute( $args ),
			'audit_page'       => ( new AuditPage( ...$tool_args ) )->execute( $args ),
			'list_changes'     => ( new Changes( ...$tool_args ) )->execute_list( $args ),
			'rollback'         => ( new Changes( ...$tool_args ) )->execute_rollback( $args ),
			'rollback_batch'   => ( new Changes( ...$tool_args ) )->execute_rollback_batch( $args ),
			'health_check'     => ( new HealthCheckTool( ...$tool_args ) )->execute( $args ),
			default            => throw new MCPException(
				sprintf( __( 'Unknown tool: "%s". Only whitelisted tools are available.', 'seo-mcp-guard' ), $tool_name ),
				'NOT_ALLOWED',
				404
			),
		};
	}

	/**
	 * Full definitions for all MCP tools (name, description, inputSchema).
	 *
	 * @return array
	 */
	private function tool_definitions(): array {
		return [
			[
				'name'        => 'list_content',
				'description' => 'List WordPress posts/pages with their current SEO fields from Rank Math.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_type' => [ 'type' => 'string', 'description' => 'Post type (e.g. post, page). Must be in the allowed list.' ],
						'page'      => [ 'type' => 'integer', 'description' => 'Page number (default: 1)', 'default' => 1, 'minimum' => 1 ],
						'per_page'  => [ 'type' => 'integer', 'description' => 'Items per page (default: 20, max: 100)', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
						'search'    => [ 'type' => 'string', 'description' => 'Search term to filter posts' ],
					],
					'required' => [ 'post_type' ],
				],
			],
			[
				'name'        => 'get_seo',
				'description' => 'Get all Rank Math SEO fields, SEO score, word count, headings outline, and images missing alt for a post.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'WordPress post ID' ],
					],
					'required' => [ 'post_id' ],
				],
			],
			[
				'name'        => 'update_seo',
				'description' => 'Update Rank Math SEO meta fields for a post. Only whitelisted SEO fields are allowed. Default dry_run=true.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer', 'description' => 'WordPress post ID' ],
						'fields'  => [
							'type'        => 'object',
							'description' => 'SEO fields to update. Allowed keys: rank_math_title, rank_math_description, rank_math_focus_keyword, rank_math_canonical_url, rank_math_robots, rank_math_facebook_title, rank_math_facebook_description, rank_math_twitter_title, rank_math_twitter_description, rank_math_rich_snippet',
							'properties'  => [
								'rank_math_title'               => [ 'type' => 'string', 'maxLength' => 60, 'description' => 'SEO title (≤60 chars)' ],
								'rank_math_description'         => [ 'type' => 'string', 'maxLength' => 160, 'description' => 'Meta description (≤160 chars)' ],
								'rank_math_focus_keyword'       => [ 'type' => 'string', 'description' => 'Single focus keyword phrase' ],
								'rank_math_canonical_url'       => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Canonical URL (absolute, same domain)' ],
								'rank_math_robots'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Robots directives' ],
								'rank_math_facebook_title'      => [ 'type' => 'string', 'maxLength' => 95 ],
								'rank_math_facebook_description'=> [ 'type' => 'string', 'maxLength' => 200 ],
								'rank_math_twitter_title'       => [ 'type' => 'string', 'maxLength' => 70 ],
								'rank_math_twitter_description' => [ 'type' => 'string', 'maxLength' => 200 ],
								'rank_math_rich_snippet'        => [ 'type' => 'string', 'description' => 'Schema type (e.g. Article, Product, FAQPage)' ],
							],
						],
						'dry_run' => [ 'type' => 'boolean', 'description' => 'If true (default), return a diff without applying changes.', 'default' => true ],
					],
					'required' => [ 'post_id', 'fields' ],
				],
			],
			[
				'name'        => 'update_image_alt',
				'description' => 'Update the alt text of a media attachment.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'attachment_id' => [ 'type' => 'integer', 'description' => 'WordPress attachment (image) ID' ],
						'alt'           => [ 'type' => 'string', 'description' => 'New alt text (≤125 chars)', 'maxLength' => 125 ],
						'dry_run'       => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'attachment_id', 'alt' ],
				],
			],
			[
				'name'        => 'update_slug',
				'description' => 'Update a post slug and automatically create a Rank Math 301 redirect from the old URL. Refuses if it would create a loop or slug collision.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'  => [ 'type' => 'integer', 'description' => 'WordPress post ID' ],
						'new_slug' => [ 'type' => 'string', 'description' => 'New URL slug (will be sanitized)' ],
						'dry_run'  => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'post_id', 'new_slug' ],
				],
			],
			[
				'name'        => 'create_redirect',
				'description' => 'Create a Rank Math redirect. Detects loops and chains.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'source_url'    => [ 'type' => 'string', 'description' => 'Source URL (the URL to redirect FROM)' ],
						'target_url'    => [ 'type' => 'string', 'description' => 'Target URL (the URL to redirect TO)' ],
						'redirect_type' => [ 'type' => 'integer', 'description' => 'HTTP redirect code (301, 302, 307)', 'default' => 301, 'enum' => [ 301, 302, 307 ] ],
						'dry_run'       => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'source_url', 'target_url' ],
				],
			],
			[
				'name'        => 'list_redirects',
				'description' => 'List Rank Math redirects.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
						'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
						'search'   => [ 'type' => 'string' ],
					],
				],
			],
			[
				'name'        => 'delete_redirect',
				'description' => 'Delete a Rank Math redirect by ID.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'redirect_id' => [ 'type' => 'integer', 'description' => 'Rank Math redirect ID' ],
						'dry_run'     => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'redirect_id' ],
				],
			],
			[
				'name'        => 'add_internal_link',
				'description' => 'Add ONE internal link by wrapping the first occurrence of anchor_text in the post content. Never inside headings, existing links, shortcodes, or block comments. Max 1 per call.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer' ],
						'anchor_text' => [ 'type' => 'string', 'description' => 'Exact text to wrap with a link', 'maxLength' => 200 ],
						'target_url'  => [ 'type' => 'string', 'format' => 'uri', 'description' => 'URL the link should point to' ],
						'dry_run'     => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'post_id', 'anchor_text', 'target_url' ],
				],
			],
			[
				'name'        => 'edit_text_block',
				'description' => 'Make a text-only edit inside one Gutenberg block. Verifies structure fingerprint before saving; refuses if any structural element (tags, classes, shortcodes) changed.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'     => [ 'type' => 'integer' ],
						'block_index' => [ 'type' => 'integer', 'description' => '0-based index of the Gutenberg block to edit', 'minimum' => 0 ],
						'old_text'    => [ 'type' => 'string', 'description' => 'Exact text to replace (must exist verbatim in the block)' ],
						'new_text'    => [ 'type' => 'string', 'description' => 'Replacement text' ],
						'dry_run'     => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'post_id', 'block_index', 'old_text', 'new_text' ],
				],
			],
			[
				'name'        => 'audit_page',
				'description' => 'Read-only SEO audit report: title/description length, keyword usage, H1 count, alt text, canonical, noindex, broken internal links, schema presence. Includes "needs_developer" items.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
					],
					'required' => [ 'post_id' ],
				],
			],
			[
				'name'        => 'list_changes',
				'description' => 'List recent changes made through SEO MCP Guard.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'limit'   => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
						'post_id' => [ 'type' => 'integer', 'description' => 'Filter by post ID (optional)' ],
					],
				],
			],
			[
				'name'        => 'rollback',
				'description' => 'Roll back a single change by change_id.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'change_id' => [ 'type' => 'string', 'description' => 'UUID of the change to roll back' ],
						'dry_run'   => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'change_id' ],
				],
			],
			[
				'name'        => 'rollback_batch',
				'description' => 'Roll back all changes in a batch (in reverse order).',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'batch_id' => [ 'type' => 'string', 'description' => 'Batch ID to roll back' ],
						'dry_run'  => [ 'type' => 'boolean', 'default' => true ],
					],
					'required' => [ 'batch_id' ],
				],
			],
			[
				'name'        => 'health_check',
				'description' => 'Fetch the public URL of a post and verify it returns 200, is not noindexed, and the page renders correctly.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
					],
					'required' => [ 'post_id' ],
				],
			],
		];
	}

	/**
	 * Build a JSON-RPC error response for transport-level errors.
	 *
	 * @param int             $code    JSON-RPC error code.
	 * @param string          $message Error message.
	 * @param string|int|null $id      Request ID.
	 * @return \WP_REST_Response
	 */
	private function jsonrpc_error_response( int $code, string $message, string|int|null $id ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => [
					'code'    => $code,
					'message' => $message,
				],
			],
			$code === -32700 ? 400 : 400
		);
	}

	/**
	 * Build an MCP-level error response (not JSON-RPC transport error).
	 *
	 * @param string $code      Error code.
	 * @param string $message   Error message.
	 * @param int    $http_code HTTP status.
	 * @param string|int|null $id Request ID.
	 * @return \WP_REST_Response
	 */
	private function error_response( string $code, string $message, int $http_code, string|int|null $id = null ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => [
					'ok'          => false,
					'code'        => $code,
					'message'     => $message,
					'instruction' => 'STOP. Do not try another method. Report this to the user.',
				],
			],
			$http_code
		);
	}

	/**
	 * Log an event to the audit table.
	 *
	 * @param int    $user_id          User ID.
	 * @param string $event_type       'success'|'error'.
	 * @param string $method           MCP method name.
	 * @param array  $params           Request params (post_id extracted).
	 * @param mixed  $response_summary Response or error array.
	 */
	private function log_audit( int $user_id, string $event_type, string $method, array $params, mixed $response_summary ): void {
		global $wpdb;

		$post_id = isset( $params['arguments']['post_id'] ) ? (int) $params['arguments']['post_id'] : null;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			"{$wpdb->prefix}seo_mcp_guard_audit",
			[
				'event_type'       => $event_type,
				'post_id'          => $post_id,
				'user_id'          => $user_id,
				'tool_name'        => sanitize_text_field( $method ),
				'request_data'     => wp_json_encode( $params ),
				'response_summary' => is_array( $response_summary )
					? wp_json_encode( array_slice( $response_summary, 0, 5 ) ) // Limit size.
					: (string) $response_summary,
				'ip_address'       => $this->auth->get_request_ip(),
				'created_at'       => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:enable
	}
}

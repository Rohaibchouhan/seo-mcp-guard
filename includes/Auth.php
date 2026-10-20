<?php
declare(strict_types=1);

namespace SEOMCPGuard;

/**
 * Authenticates incoming MCP requests for SEO MCP Guard.
 *
 * Two authentication schemes are supported:
 *
 *  1. WordPress Application Passwords
 *     Header: Authorization: Basic base64("username:application_password")
 *     The user and password are extracted, verified via wp_authenticate_application_password(),
 *     and resolved to a WP_User.
 *
 *  2. Plugin-generated bearer tokens
 *     Header: Authorization: Bearer <64-char-hex-token>
 *     The token is verified against the hashed list stored in Settings.
 *     When valid, the request is treated as the special "mcp-bot" user role
 *     defined by the plugin, or falls back to the first admin user if no
 *     dedicated service account is configured.
 */
class Auth {

	public function __construct(
		private readonly Settings $settings,
	) {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Try to authenticate the current request.
	 *
	 * Attempts Application Password (Basic) auth first, then Bearer token auth.
	 *
	 * @return \WP_User|false Authenticated user on success, false on failure.
	 */
	public function authenticate(): \WP_User|false {
		$auth_header = $this->get_authorization_header();

		if ( null === $auth_header ) {
			return false;
		}

		if ( str_starts_with( $auth_header, 'Basic ' ) ) {
			return $this->authenticate_basic( $auth_header );
		}

		if ( str_starts_with( $auth_header, 'Bearer ' ) ) {
			return $this->authenticate_bearer( $auth_header );
		}

		return false;
	}

	/**
	 * Require authentication and a specific capability.
	 *
	 * @param string $cap WordPress capability slug (default: 'edit_posts').
	 * @return \WP_User The authenticated, authorised user.
	 *
	 * @throws MCPException 401 if not authenticated, 403 if unauthorised.
	 */
	public function require_capability( string $cap = 'edit_posts' ): \WP_User {
		$user = $this->authenticate();

		if ( false === $user ) {
			throw MCPException::not_allowed(
				'Authentication required. Provide a valid Authorization header.',
				401,
			);
		}

		if ( ! $user->has_cap( $cap ) ) {
			throw MCPException::not_allowed(
				sprintf(
					'User "%s" does not have the required capability: %s.',
					esc_html( $user->user_login ),
					esc_html( $cap ),
				),
				403,
			);
		}

		return $user;
	}

	/**
	 * Return the best-guess IP address of the current request.
	 *
	 * Checks common proxy headers before falling back to REMOTE_ADDR.
	 * The result is sanitised and truncated to fit the audit table's varchar(45).
	 *
	 * @return string IPv4 or IPv6 address string, or '0.0.0.0' as fallback.
	 */
	public function get_request_ip(): string {
		// Proxy headers are not trusted by default; they can be spoofed.
		// Only use them if you know your infrastructure sets them reliably.
		$candidates = [
			'HTTP_CF_CONNECTING_IP',  // Cloudflare
			'HTTP_X_REAL_IP',         // nginx proxy
			'HTTP_X_FORWARDED_FOR',   // standard proxy header (may be a CSV list)
			'REMOTE_ADDR',
		];

		foreach ( $candidates as $key ) {
			$value = $_SERVER[ $key ] ?? '';

			if ( '' === $value ) {
				continue;
			}

			// HTTP_X_FORWARDED_FOR may contain a comma-separated list; take the first.
			$ip = trim( explode( ',', $value )[0] );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return substr( $ip, 0, 45 );
			}
		}

		return '0.0.0.0';
	}

	// -------------------------------------------------------------------------
	// Private authentication strategies
	// -------------------------------------------------------------------------

	/**
	 * Authenticate using WordPress Application Passwords (HTTP Basic auth).
	 *
	 * @param string $header Full Authorization header value.
	 * @return \WP_User|false
	 */
	private function authenticate_basic( string $header ): \WP_User|false {
		$encoded = substr( $header, strlen( 'Basic ' ) );
		$decoded = base64_decode( $encoded, strict: true );

		if ( false === $decoded ) {
			return false;
		}

		$colon_pos = strpos( $decoded, ':' );

		if ( false === $colon_pos ) {
			return false;
		}

		$username = substr( $decoded, 0, $colon_pos );
		$password = substr( $decoded, $colon_pos + 1 );

		if ( '' === $username || '' === $password ) {
			return false;
		}

		// wp_authenticate_application_password() is available since WP 5.6.
		// It returns a WP_User on success or a WP_Error on failure.
		$user = get_user_by( 'login', $username );

		if ( ! $user instanceof \WP_User ) {
			// Also try by email in case the client sends an email address.
			$user = get_user_by( 'email', $username );
		}

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		// Delegate to the Application Passwords API.
		$result = wp_authenticate_application_password( null, $user->user_login, $password );

		if ( is_wp_error( $result ) || ! $result instanceof \WP_User ) {
			return false;
		}

		return $result;
	}

	/**
	 * Authenticate using a plugin-generated bearer token.
	 *
	 * On success, returns the WordPress user associated with the token.
	 * If no dedicated MCP service account exists, falls back to the first
	 * administrator in the database.
	 *
	 * @param string $header Full Authorization header value.
	 * @return \WP_User|false
	 */
	private function authenticate_bearer( string $header ): \WP_User|false {
		$token = trim( substr( $header, strlen( 'Bearer ' ) ) );

		if ( '' === $token ) {
			return false;
		}

		if ( ! $this->settings->verify_bearer_token( $token ) ) {
			return false;
		}

		// Resolve to a WordPress user for capability checking.
		// Prefer a dedicated "mcp-service" login, fall back to any administrator.
		$service_user = get_user_by( 'login', 'mcp-service' );

		if ( $service_user instanceof \WP_User ) {
			return $service_user;
		}

		// Fall back to the first administrator.
		$admins = get_users( [
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'all',
		] );

		if ( ! empty( $admins ) && $admins[0] instanceof \WP_User ) {
			return $admins[0];
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Header helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the Authorization header value from the current request.
	 *
	 * Handles both Apache (HTTP_AUTHORIZATION) and CGI/nginx environments where
	 * the header may only be available via a RewriteRule pass-through.
	 *
	 * @return string|null The raw header value, or null if not present.
	 */
	private function get_authorization_header(): ?string {
		// Standard PHP superglobal (works on most setups).
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( (string) $_SERVER['HTTP_AUTHORIZATION'] );
		}

		// Some Apache + CGI configurations use this key instead.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		// WP REST API helper (available since WP 4.4).
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();

			// Headers are case-insensitive per RFC 7230.
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
					return trim( $value );
				}
			}
		}

		return null;
	}
}

<?php
/**
 * Test Bootstrap & Mock WordPress Environment
 *
 * Provides mock WP functions and classes so tests can run in any PHP environment.
 *
 * @package SEOMCPGuard\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '1.0.220' );
}

define( 'SEO_MCP_GUARD_VERSION', '1.0.0' );
define( 'SEO_MCP_GUARD_PLUGIN_FILE', dirname( __DIR__ ) . '/seo-mcp-guard.php' );
define( 'SEO_MCP_GUARD_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'SEO_MCP_GUARD_PLUGIN_URL', 'http://example.com/wp-content/plugins/seo-mcp-guard/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

// Mock In-Memory DB and State Store
class TestMockState {
	public static array $options = [];
	public static array $transients = [];
	public static array $posts = [];
	public static array $post_meta = [];
	public static array $redirections = [];
	public static array $audit_log = [];
	public static array $changes_table = [];

	public static function reset(): void {
		self::$options       = [];
		self::$transients    = [];
		self::$posts         = [];
		self::$post_meta     = [];
		self::$redirections  = [];
		self::$audit_log     = [];
		self::$changes_table = [];
	}
}

// Mock wpdb
class wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 1;

	public function insert( string $table, array $data, array $format = [] ): int|false {
		$table_name = str_replace( $this->prefix, '', $table );
		if ( $table_name === 'seo_mcp_guard_changes' ) {
			$data['id'] = count( TestMockState::$changes_table ) + 1;
			TestMockState::$changes_table[] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		if ( $table_name === 'rank_math_redirections' ) {
			$data['id'] = count( TestMockState::$redirections ) + 1;
			TestMockState::$redirections[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		if ( $table_name === 'seo_mcp_guard_audit' ) {
			TestMockState::$audit_log[] = $data;
			return 1;
		}
		return 1;
	}

	public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
		$table_name = str_replace( $this->prefix, '', $table );
		if ( $table_name === 'seo_mcp_guard_changes' ) {
			foreach ( TestMockState::$changes_table as $idx => $row ) {
				$match = true;
				foreach ( $where as $wk => $wv ) {
					if ( ( $row[ $wk ] ?? null ) !== $wv ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					TestMockState::$changes_table[ $idx ] = array_merge( $row, $data );
				}
			}
			return 1;
		}
		return 1;
	}

	public function delete( string $table, array $where, array $format = [] ): int|false {
		$table_name = str_replace( $this->prefix, '', $table );
		if ( $table_name === 'rank_math_redirections' ) {
			$id = $where['id'] ?? null;
			if ( $id && isset( TestMockState::$redirections[ $id ] ) ) {
				unset( TestMockState::$redirections[ $id ] );
				return 1;
			}
		}
		return 1;
	}

	public function get_row( string $query, string $output = 'OBJECT' ): ?array {
		if ( str_contains( $query, 'seo_mcp_guard_changes' ) && str_contains( $query, 'idempotency_key' ) ) {
			preg_match( "/idempotency_key = '([^']+)'/", $query, $m );
			$key = $m[1] ?? '';
			foreach ( TestMockState::$changes_table as $row ) {
				if ( ( $row['idempotency_key'] ?? '' ) === $key ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $query, 'seo_mcp_guard_changes' ) && str_contains( $query, 'change_id' ) ) {
			preg_match( "/change_id = '([^']+)'/", $query, $m );
			$cid = $m[1] ?? '';
			foreach ( TestMockState::$changes_table as $row ) {
				if ( ( $row['change_id'] ?? '' ) === $cid ) {
					return $row;
				}
			}
			return null;
		}
		return null;
	}

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		if ( str_contains( $query, 'seo_mcp_guard_changes' ) ) {
			if ( str_contains( $query, 'batch_id' ) ) {
				preg_match( "/batch_id = '([^']+)'/", $query, $m );
				$bid = $m[1] ?? '';
				$res = [];
				foreach ( TestMockState::$changes_table as $row ) {
					if ( ( $row['batch_id'] ?? '' ) === $bid && ( $row['status'] ?? '' ) === 'applied' ) {
						$res[] = $row;
					}
				}
				return $res;
			}
			return TestMockState::$changes_table;
		}
		if ( str_contains( $query, 'rank_math_redirections' ) ) {
			return array_values( TestMockState::$redirections );
		}
		return [];
	}

	public function get_var( string $query ): mixed {
		if ( str_contains( $query, 'COUNT(*)' ) ) {
			return count( TestMockState::$changes_table );
		}
		return null;
	}

	public function prepare( string $query, ...$args ): string {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$val = is_numeric( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sdf]/', (string) $val, $query, 1 );
		}
		return $query;
	}
}

$GLOBALS['wpdb'] = new wpdb();

// WordPress core functions mocks
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return TestMockState::$options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		TestMockState::$options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $key ): bool {
		unset( TestMockState::$options[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		return TestMockState::$transients[ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		TestMockState::$transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( TestMockState::$transients[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url(): string {
		return 'https://example.com';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int|object $post = 0 ): string {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		$slug = TestMockState::$posts[ $id ]->post_name ?? "sample-post-{$id}";
		return "https://example.com/{$slug}/";
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int|object $post = null ): ?object {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return TestMockState::$posts[ $id ] ?? null;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array|object $postarr = [], bool $wp_error = false ): int|object {
		$arr = (array) $postarr;
		$id = $arr['ID'] ?? 0;
		if ( isset( TestMockState::$posts[ $id ] ) ) {
			foreach ( $arr as $k => $v ) {
				TestMockState::$posts[ $id ]->$k = $v;
			}
			return $id;
		}
		return 0;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$val = TestMockState::$post_meta[ $post_id ][ $key ] ?? ( $single ? '' : [] );
		return $val;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		if ( ! isset( TestMockState::$post_meta[ $post_id ] ) ) {
			TestMockState::$post_meta[ $post_id ] = [];
		}
		TestMockState::$post_meta[ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key ): bool {
		unset( TestMockState::$post_meta[ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: $url;
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( string|object $object ): array {
		return [ 'category', 'post_tag' ];
	}
}

if ( ! function_exists( 'clean_term_cache' ) ) {
	function clean_term_cache( mixed $ids, string $taxonomy = '' ): void {}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( int|array $object_ids, string|array $taxonomies, array|string $args = [] ): array {
		return [];
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $id ): string {
		return "https://example.com/wp-content/uploads/image-{$id}.jpg";
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9\-]/', '-', $title );
		return trim( preg_replace( '/-+/', '-', $title ), '-' );
	}
}

if ( ! function_exists( 'wp_hash_password' ) ) {
	function wp_hash_password( string $password ): string {
		return password_hash( $password, PASSWORD_BCRYPT );
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	function wp_check_password( string $password, string $hash ): bool {
		return password_verify( $password, $hash );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0 ): string {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( int $id ): void {}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook, mixed $callback = false ): bool {
		return false;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook, mixed $callback = false ): bool {
		return false;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return is_object( $thing ) && is_a( $thing, 'WP_Error' );
	}
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
	public function get_error_code(): string { return $this->code; }
}

class WP_Post {
	public int $ID = 0;
	public string $post_title = '';
	public string $post_name = '';
	public string $post_content = '';
	public string $post_status = 'publish';
	public string $post_type = 'post';
}

class WP_Query {
	public array $posts = [];
	public int $found_posts = 0;
	public int $max_num_pages = 1;

	public function __construct( array $args = [] ) {
		$name = $args['name'] ?? null;
		$exclude = $args['post__not_in'] ?? [];
		$this->posts = [];

		if ( $name ) {
			foreach ( TestMockState::$posts as $p ) {
				if ( $p->post_name === $name && ! in_array( $p->ID, $exclude, true ) ) {
					$this->posts[] = $p;
				}
			}
		} else {
			$this->posts = array_values( TestMockState::$posts );
		}
		$this->found_posts = count( $this->posts );
	}

	public function have_posts(): bool {
		return ! empty( $this->posts );
	}
}

class WP_User {
	public int $ID = 1;
	public string $user_login = 'admin';
	public array $roles = [ 'administrator' ];
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( mixed $user, string $capability, ...$args ): bool {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int|object $post = null ): string|false {
		if ( is_object( $post ) ) {
			return $post->post_type ?? 'post';
		}
		$id = (int) $post;
		return TestMockState::$posts[ $id ]->post_type ?? 'post';
	}
}

if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( string $url, array $args = [] ): array {
		return [ 'response' => [ 'code' => 200 ] ];
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular(): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ): array {
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [ 'content-type' => 'text/html' ],
			'body'     => '<!DOCTYPE html><html><head><title>Sample Post</title><meta name="description" content="Sample description"></head><body><h1>Sample Post</h1><p>Content</p></body></html>',
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['response']['code'] ?? 200;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( array $response ): array {
		return $response['headers'] ?? [];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		// Simple Gutenberg block parser mock
		$pattern = '/<!--\s+wp:([a-z0-9\/-]+)\s*(\{[^}]*\})?\s+-->(.*?)<!--\s+\/wp:\1\s+-->/s';
		preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) {
			return [
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [],
					'innerHTML'    => $content,
					'innerContent' => [ $content ],
				],
			];
		}
		$blocks = [];
		foreach ( $matches as $m ) {
			$blocks[] = [
				'blockName'    => $m[1],
				'attrs'        => ! empty( $m[2] ) ? json_decode( $m[2], true ) : [],
				'innerHTML'    => $m[3],
				'innerContent' => [ $m[3] ],
			];
		}
		return $blocks;
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		$out = '';
		foreach ( $blocks as $b ) {
			$name = $b['blockName'] ?? 'core/paragraph';
			$attrs = ! empty( $b['attrs'] ) ? ' ' . json_encode( $b['attrs'] ) : '';
			$html = $b['innerHTML'] ?? '';
			$out .= "<!-- wp:{$name}{$attrs} -->{$html}<!-- /wp:{$name} -->\n";
		}
		return trim( $out );
	}
}

// Autoloader for plugin classes
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'SEOMCPGuard\\' ) !== 0 ) {
		return;
	}
	$relative = str_replace( 'SEOMCPGuard\\', '', $class );
	$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
	$file     = __DIR__ . '/../includes/' . $relative . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

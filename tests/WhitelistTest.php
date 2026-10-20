<?php
/**
 * Whitelist Enforcement & Hard "NEVER" List Tests
 *
 * @package SEOMCPGuard\Tests
 */

declare( strict_types=1 );

namespace SEOMCPGuard\Tests;

use SEOMCPGuard\Settings;
use SEOMCPGuard\Safety\SafetyEngine;
use SEOMCPGuard\Safety\ChangeLog;
use SEOMCPGuard\RateLimit;
use SEOMCPGuard\Safety\CachePurger;
use SEOMCPGuard\Safety\HealthCheck;
use SEOMCPGuard\Tools\UpdateSEO;
use SEOMCPGuard\Exceptions\MCPException;
use WP_User;
use WP_Post;
use TestMockState;

class WhitelistTest {

	public function run(): array {
		$results = [];

		$results[] = $this->test_allowed_fields_pass();
		$results[] = $this->test_forbidden_fields_rejected();
		$results[] = $this->test_field_length_validation();
		$results[] = $this->test_page_builder_rejection();

		return $results;
	}

	private function setup_env(): array {
		TestMockState::reset();

		$settings   = new Settings();
		$changelog  = new ChangeLog();
		$ratelimit  = new RateLimit();
		$purger     = new CachePurger();
		$health     = new HealthCheck();
		$safety     = new SafetyEngine( $settings, $changelog, $ratelimit, $purger, $health );
		$user       = new WP_User();

		// Mock a test post
		$post              = new WP_Post();
		$post->ID          = 101;
		$post->post_title  = 'Test Post';
		$post->post_name   = 'test-post';
		$post->post_content= '<p>Hello world.</p>';
		$post->post_status = 'publish';
		$post->post_type   = 'post';
		TestMockState::$posts[101] = $post;

		return [ $settings, $safety, $changelog, $user ];
	}

	public function test_allowed_fields_pass(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new UpdateSEO( $settings, $safety, $changelog, $user );

		$allowed_payload = [
			'post_id' => 101,
			'fields'  => [
				'rank_math_title'               => 'Valid SEO Title Under 60 Chars',
				'rank_math_description'         => 'Valid meta description under 160 characters length for search snippets.',
				'rank_math_focus_keyword'       => 'seo optimization',
				'rank_math_canonical_url'       => 'https://example.com/test-post/',
				'rank_math_robots'              => [ 'index', 'follow' ],
				'rank_math_facebook_title'      => 'Facebook Share Title',
				'rank_math_facebook_description'=> 'Facebook Share Description',
				'rank_math_twitter_title'       => 'Twitter Card Title',
				'rank_math_twitter_description' => 'Twitter Card Description',
				'rank_math_rich_snippet'        => 'Article',
			],
			'dry_run' => true,
		];

		try {
			$res = $tool->execute( $allowed_payload );
			$pass = isset( $res['dry_run'] ) && $res['dry_run'] === true;
			return [
				'name'    => 'Whitelist: All 10 allowed Rank Math fields accepted',
				'success' => $pass,
				'message' => $pass ? 'Passed' : 'Dry run response failed',
			];
		} catch ( \Throwable $e ) {
			return [
				'name'    => 'Whitelist: All 10 allowed Rank Math fields accepted',
				'success' => false,
				'message' => 'Unexpected exception: ' . $e->getMessage(),
			];
		}
	}

	public function test_forbidden_fields_rejected(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new UpdateSEO( $settings, $safety, $changelog, $user );

		$forbidden_payload = [
			'post_id' => 101,
			'fields'  => [
				'rank_math_title' => 'Valid Title',
				'post_content'    => 'Hacked content injection',
				'eval_code'       => 'phpinfo();',
				'wp_capabilities' => 'administrator',
			],
			'dry_run' => true,
		];

		try {
			$tool->execute( $forbidden_payload );
			return [
				'name'    => 'Whitelist: Non-whitelisted fields rejected with NOT_ALLOWED',
				'success' => false,
				'message' => 'Failed: Forbidden fields were not rejected!',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'NOT_ALLOWED';
			return [
				'name'    => 'Whitelist: Non-whitelisted fields rejected with NOT_ALLOWED',
				'success' => $pass,
				'message' => $pass ? 'Passed: Correctly rejected non-whitelisted fields with NOT_ALLOWED' : "Wrong code: {$e->get_error_code()}",
			];
		}
	}

	public function test_field_length_validation(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new UpdateSEO( $settings, $safety, $changelog, $user );

		// Title > 60 chars
		$invalid_title = [
			'post_id' => 101,
			'fields'  => [
				'rank_math_title' => str_repeat( 'A', 65 ),
			],
			'dry_run' => true,
		];

		try {
			$tool->execute( $invalid_title );
			return [
				'name'    => 'Validation: Title length > 60 chars rejected',
				'success' => false,
				'message' => 'Failed: Title > 60 chars was accepted',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'VALIDATION';
			return [
				'name'    => 'Validation: Title length > 60 chars rejected',
				'success' => $pass,
				'message' => $pass ? 'Passed' : "Expected VALIDATION, got {$e->get_error_code()}",
			];
		}
	}

	public function test_page_builder_rejection(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();

		// Mark post 101 as Elementor built
		TestMockState::$post_meta[101]['_elementor_data'] = '[{"id":"elem1"}]';

		try {
			$safety->check_page_builder( 101 );
			return [
				'name'    => 'Hard NEVER: Page builder pages rejected for content editing',
				'success' => false,
				'message' => 'Failed: Elementor page builder was not detected',
			];
		} catch ( MCPException $e ) {
			$expected_msg = 'Page-builder page: SEO fields only.';
			$pass = $e->get_error_code() === 'NOT_ALLOWED' && str_contains( $e->getMessage(), $expected_msg );
			return [
				'name'    => 'Hard NEVER: Page builder pages rejected for content editing',
				'success' => $pass,
				'message' => $pass ? 'Passed: Correctly refused with "Page-builder page: SEO fields only."' : "Wrong message/code: {$e->getMessage()}",
			];
		}
	}
}

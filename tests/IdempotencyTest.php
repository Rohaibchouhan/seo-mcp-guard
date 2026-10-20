<?php
/**
 * Idempotency Enforcement Tests
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
use WP_User;
use WP_Post;
use TestMockState;

class IdempotencyTest {

	public function run(): array {
		$results = [];
		$results[] = $this->test_repeated_call_with_same_key_is_noop();
		return $results;
	}

	public function test_repeated_call_with_same_key_is_noop(): array {
		TestMockState::reset();

		$settings  = new Settings();
		$settings->update( [ 'allow_production_writes' => true ] );
		$changelog = new ChangeLog();
		$ratelimit = new RateLimit();
		$purger    = new CachePurger();
		$health    = new HealthCheck();
		$safety    = new SafetyEngine( $settings, $changelog, $ratelimit, $purger, $health );
		$user      = new WP_User();

		// Create post
		$post              = new WP_Post();
		$post->ID          = 501;
		$post->post_title  = 'Idempotency Post';
		$post->post_name   = 'idempotency-post';
		$post->post_content= '<p>Content</p>';
		$post->post_status = 'publish';
		$post->post_type   = 'post';
		TestMockState::$posts[501] = $post;

		$tool = new UpdateSEO( $settings, $safety, $changelog, $user );

		$payload = [
			'post_id' => 501,
			'fields'  => [
				'rank_math_title' => 'Idempotent Title Value',
			],
			'dry_run' => false,
		];

		// First call
		$res1 = $tool->execute( $payload );
		$initial_db_rows = count( TestMockState::$changes_table );

		// Second identical call
		$res2 = $tool->execute( $payload );
		$second_db_rows = count( TestMockState::$changes_table );

		// Check cached flag or same change_id
		$pass = $initial_db_rows === $second_db_rows && ( ! empty( $res2['cached'] ) || $res1['change_id'] === $res2['change_id'] );

		return [
			'name'    => 'Idempotency: Repeating the exact same write changes nothing and returns cached result',
			'success' => $pass,
			'message' => $pass ? 'Passed: Replay produced identical outcome without duplicate row' : "Failed: Rows changed from {$initial_db_rows} to {$second_db_rows}",
		];
	}
}

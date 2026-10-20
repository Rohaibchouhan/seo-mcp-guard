<?php
/**
 * Redirect Loop & Slug Collision Detection Tests
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
use SEOMCPGuard\Tools\Redirects;
use SEOMCPGuard\Tools\UpdateSlug;
use SEOMCPGuard\Exceptions\MCPException;
use WP_User;
use WP_Post;
use TestMockState;

class LoopDetectionTest {

	public function run(): array {
		$results = [];

		$results[] = $this->test_direct_redirect_loop_rejected();
		$results[] = $this->test_chained_redirect_loop_rejected();
		$results[] = $this->test_slug_collision_rejected();

		return $results;
	}

	private function setup_env(): array {
		TestMockState::reset();

		$settings  = new Settings();
		$settings->update( [ 'allow_production_writes' => true ] );
		$changelog = new ChangeLog();
		$ratelimit = new RateLimit();
		$purger    = new CachePurger();
		$health    = new HealthCheck();
		$safety    = new SafetyEngine( $settings, $changelog, $ratelimit, $purger, $health );
		$user      = new WP_User();

		// Create two test posts
		$p1              = new WP_Post();
		$p1->ID          = 401;
		$p1->post_title  = 'Post One';
		$p1->post_name   = 'post-one';
		$p1->post_status = 'publish';
		$p1->post_type   = 'post';
		TestMockState::$posts[401] = $p1;

		$p2              = new WP_Post();
		$p2->ID          = 402;
		$p2->post_title  = 'Post Two';
		$p2->post_name   = 'existing-target-slug';
		$p2->post_status = 'publish';
		$p2->post_type   = 'post';
		TestMockState::$posts[402] = $p2;

		return [ $settings, $safety, $changelog, $user ];
	}

	public function test_direct_redirect_loop_rejected(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new Redirects( $settings, $safety, $changelog, $user );

		try {
			// Direct loop: /same-page/ -> /same-page/
			$tool->execute_create( [
				'source_url' => 'https://example.com/same-page/',
				'target_url' => 'https://example.com/same-page/',
				'dry_run'    => true,
			] );

			return [
				'name'    => 'Redirect Loop: Direct self-redirect loop rejected',
				'success' => false,
				'message' => 'Failed: Self-redirect was not caught',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'VALIDATION' && str_contains( $e->getMessage(), 'loop' );
			return [
				'name'    => 'Redirect Loop: Direct self-redirect loop rejected',
				'success' => $pass,
				'message' => $pass ? 'Passed: Correctly rejected with loop validation error' : "Unexpected error: {$e->getMessage()}",
			];
		}
	}

	public function test_chained_redirect_loop_rejected(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new Redirects( $settings, $safety, $changelog, $user );

		// Seed existing redirects: /page-a -> /page-b, and /page-b -> /page-c
		TestMockState::$redirections[1] = [
			'id'          => 1,
			'sources'     => serialize( [ [ 'pattern' => 'page-a', 'comparison' => 'exact' ] ] ),
			'url_to'      => 'https://example.com/page-b/',
			'header_code' => 301,
			'status'      => 'active',
		];
		TestMockState::$redirections[2] = [
			'id'          => 2,
			'sources'     => serialize( [ [ 'pattern' => 'page-b', 'comparison' => 'exact' ] ] ),
			'url_to'      => 'https://example.com/page-c/',
			'header_code' => 301,
			'status'      => 'active',
		];

		try {
			// Attempt to add /page-c -> /page-a (completing the loop)
			$tool->execute_create( [
				'source_url' => 'https://example.com/page-c/',
				'target_url' => 'https://example.com/page-a/',
				'dry_run'    => true,
			] );

			return [
				'name'    => 'Redirect Loop: Chained circular redirect (A->B->C->A) rejected',
				'success' => false,
				'message' => 'Failed: Chain loop was not detected',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'VALIDATION' && str_contains( $e->getMessage(), 'loop' );
			return [
				'name'    => 'Redirect Loop: Chained circular redirect (A->B->C->A) rejected',
				'success' => $pass,
				'message' => $pass ? 'Passed: Correctly detected multi-hop redirect chain loop' : "Unexpected: {$e->getMessage()}",
			];
		}
	}

	public function test_slug_collision_rejected(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new UpdateSlug( $settings, $safety, $changelog, $user );

		try {
			// Attempt to rename post 401 to 'existing-target-slug' (already used by post 402)
			$tool->execute( [
				'post_id'  => 401,
				'new_slug' => 'existing-target-slug',
				'dry_run'  => true,
			] );

			return [
				'name'    => 'Slug Collision: Changing slug to an existing post slug is refused',
				'success' => false,
				'message' => 'Failed: Collision was not detected',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'VALIDATION' && ( str_contains( $e->getMessage(), 'already' ) || str_contains( $e->getMessage(), 'collision' ) || str_contains( $e->getMessage(), 'used' ) );
			return [
				'name'    => 'Slug Collision: Changing slug to an existing post slug is refused',
				'success' => $pass,
				'message' => $pass ? 'Passed: Refused slug collision with VALIDATION' : "Unexpected: {$e->getMessage()}",
			];
		}
	}
}

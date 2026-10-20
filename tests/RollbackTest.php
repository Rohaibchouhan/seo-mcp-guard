<?php
/**
 * Rollback Engine Tests
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
use SEOMCPGuard\Tools\Changes;
use WP_User;
use WP_Post;
use TestMockState;

class RollbackTest {

	public function run(): array {
		$results = [];

		$results[] = $this->test_single_change_rollback();
		$results[] = $this->test_batch_rollback_in_reverse_order();

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

		// Create test post
		$post              = new WP_Post();
		$post->ID          = 301;
		$post->post_title  = 'Rollback Test Post';
		$post->post_name   = 'rollback-test-post';
		$post->post_content= '<p>Sample content</p>';
		$post->post_status = 'publish';
		$post->post_type   = 'post';
		TestMockState::$posts[301] = $post;

		// Set initial meta
		TestMockState::$post_meta[301]['rank_math_title'] = 'Original Title';

		return [ $settings, $safety, $changelog, $user ];
	}

	public function test_single_change_rollback(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool        = new UpdateSEO( $settings, $safety, $changelog, $user );
		$changes_tool= new Changes( $settings, $safety, $changelog, $user );

		// 1. Apply a write
		$write_res = $tool->execute( [
			'post_id' => 301,
			'fields'  => [ 'rank_math_title' => 'Updated Title' ],
			'dry_run' => false,
		] );

		$change_id = $write_res['change_id'] ?? '';
		if ( empty( $change_id ) ) {
			return [
				'name'    => 'Rollback: Single field rollback restores previous value',
				'success' => false,
				'message' => 'Write did not produce change_id',
			];
		}

		// Verify meta was updated
		$curr_title = TestMockState::$post_meta[301]['rank_math_title'] ?? '';
		if ( $curr_title !== 'Updated Title' ) {
			return [
				'name'    => 'Rollback: Single field rollback restores previous value',
				'success' => false,
				'message' => "Meta not updated, was: '{$curr_title}'",
			];
		}

		// 2. Perform rollback
		$rb_res = $changes_tool->execute_rollback( [
			'change_id' => $change_id,
			'dry_run'   => false,
		] );

		$restored_title = TestMockState::$post_meta[301]['rank_math_title'] ?? '';
		$pass = $restored_title === 'Original Title' && ! empty( $rb_res['rolled_back'] );

		return [
			'name'    => 'Rollback: Single field rollback restores previous value',
			'success' => $pass,
			'message' => $pass ? 'Passed: Original title successfully restored' : "Failed: Restored title is '{$restored_title}'",
		];
	}

	public function test_batch_rollback_in_reverse_order(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool        = new UpdateSEO( $settings, $safety, $changelog, $user );
		$changes_tool= new Changes( $settings, $safety, $changelog, $user );

		// Set initial values
		TestMockState::$post_meta[301]['rank_math_title']       = 'Initial Title';
		TestMockState::$post_meta[301]['rank_math_description'] = 'Initial Description';

		// Apply write
		$res = $tool->execute( [
			'post_id' => 301,
			'fields'  => [
				'rank_math_title'       => 'New Batch Title',
				'rank_math_description' => 'New Batch Description',
			],
			'dry_run' => false,
		] );

		$batch_id = $res['batch_id'] ?? '';

		// Verify new values applied
		if ( ( TestMockState::$post_meta[301]['rank_math_title'] ?? '' ) !== 'New Batch Title' ) {
			return [
				'name'    => 'Rollback: Batch rollback restores all changes in batch',
				'success' => false,
				'message' => 'Batch write not applied',
			];
		}

		// Roll back batch
		$rb_res = $changes_tool->execute_rollback_batch( [
			'batch_id' => $batch_id,
			'dry_run'  => false,
		] );

		$restored_t = TestMockState::$post_meta[301]['rank_math_title'] ?? '';
		$restored_d = TestMockState::$post_meta[301]['rank_math_description'] ?? '';

		$pass = $restored_t === 'Initial Title' && $restored_d === 'Initial Description';

		return [
			'name'    => 'Rollback: Batch rollback restores all changes in batch',
			'success' => $pass,
			'message' => $pass ? 'Passed: All batch fields restored' : "Failed: Title='{$restored_t}', Desc='{$restored_d}'",
		];
	}
}

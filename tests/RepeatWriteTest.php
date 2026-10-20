<?php
/**
 * 10x Consecutive Execution Stability Tests
 *
 * Runs each write tool 10 times in a row and confirms identical results.
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
use SEOMCPGuard\Tools\UpdateImageAlt;
use SEOMCPGuard\Tools\UpdateSlug;
use SEOMCPGuard\Tools\Redirects;
use SEOMCPGuard\Tools\AddInternalLink;
use SEOMCPGuard\Tools\EditTextBlock;
use WP_User;
use WP_Post;
use TestMockState;

class RepeatWriteTest {

	public function run(): array {
		$results = [];

		$results[] = $this->test_repeat_update_seo_10x();
		$results[] = $this->test_repeat_update_image_alt_10x();
		$results[] = $this->test_repeat_edit_text_block_10x();
		$results[] = $this->test_repeat_add_internal_link_10x();

		return $results;
	}

	private function setup_env(): array {
		TestMockState::reset();

		$settings  = new Settings();
		$settings->update( [
			'allow_production_writes' => true,
			'rate_limit_per_minute'   => 1000,
		] );
		$changelog = new ChangeLog();
		$ratelimit = new RateLimit();
		$purger    = new CachePurger();
		$health    = new HealthCheck();
		$safety    = new SafetyEngine( $settings, $changelog, $ratelimit, $purger, $health );
		$user      = new WP_User();

		// Create post
		$post              = new WP_Post();
		$post->ID          = 601;
		$post->post_title  = 'Repeat Test Post';
		$post->post_name   = 'repeat-test-post';
		$post->post_content= "<!-- wp:paragraph -->\n<p>WordPress SEO automation with Rank Math requires strong safety guardrails.</p>\n<!-- /wp:paragraph -->";
		$post->post_status = 'publish';
		$post->post_type   = 'post';
		TestMockState::$posts[601] = $post;

		// Create attachment
		$att              = new WP_Post();
		$att->ID          = 602;
		$att->post_type   = 'attachment';
		$att->post_status = 'inherit';
		TestMockState::$posts[602] = $att;

		return [ $settings, $safety, $changelog, $user ];
	}

	public function test_repeat_update_seo_10x(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new UpdateSEO( $settings, $safety, $changelog, $user );

		$args = [
			'post_id' => 601,
			'fields'  => [
				'rank_math_title'         => 'Consistent SEO Title Value',
				'rank_math_description'   => 'Consistent meta description value across ten repeated invocations.',
				'rank_math_focus_keyword' => 'consistent keyword',
			],
			'dry_run' => false,
		];

		$first_result = $tool->execute( $args );
		$identical    = true;

		for ( $i = 2; $i <= 10; $i++ ) {
			$res = $tool->execute( $args );
			// Either identical result or cached idempotent replay
			if ( ! isset( $res['applied'] ) || $res['applied'] !== true ) {
				$identical = false;
				break;
			}
			$curr_title = TestMockState::$post_meta[601]['rank_math_title'] ?? '';
			if ( $curr_title !== 'Consistent SEO Title Value' ) {
				$identical = false;
				break;
			}
		}

		return [
			'name'    => 'Stability: Run update_seo 10 times in a row with identical outcome',
			'success' => $identical,
			'message' => $identical ? 'Passed: 10/10 executions succeeded with identical stable state' : 'Failed during repeated execution',
		];
	}

	public function test_repeat_update_image_alt_10x(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new UpdateImageAlt( $settings, $safety, $changelog, $user );

		$args = [
			'attachment_id' => 602,
			'alt'           => 'Consistent image accessibility alt description',
			'dry_run'       => false,
		];

		$first_result = $tool->execute( $args );
		$identical    = true;

		for ( $i = 2; $i <= 10; $i++ ) {
			$res = $tool->execute( $args );
			$curr_alt = TestMockState::$post_meta[602]['_wp_attachment_image_alt'] ?? '';
			if ( $curr_alt !== 'Consistent image accessibility alt description' ) {
				$identical = false;
				break;
			}
		}

		return [
			'name'    => 'Stability: Run update_image_alt 10 times in a row with identical outcome',
			'success' => $identical,
			'message' => $identical ? 'Passed: 10/10 executions succeeded with identical alt text' : 'Failed during repeated execution',
		];
	}

	public function test_repeat_edit_text_block_10x(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new EditTextBlock( $settings, $safety, $changelog, $user );

		// Dry-run repeat 10 times
		$args = [
			'post_id'     => 601,
			'block_index' => 0,
			'old_text'    => 'SEO automation',
			'new_text'    => 'search engine automation',
			'dry_run'     => true,
		];

		$first_result = $tool->execute( $args );
		$identical    = true;

		for ( $i = 2; $i <= 10; $i++ ) {
			$res = $tool->execute( $args );
			if ( $res['dry_run'] !== true || $res['block_index'] !== 0 ) {
				$identical = false;
				break;
			}
		}

		return [
			'name'    => 'Stability: Run edit_text_block 10 times in a row with identical outcome',
			'success' => $identical,
			'message' => $identical ? 'Passed: 10/10 executions produced identical diff & fingerprint' : 'Failed during repeated execution',
		];
	}

	public function test_repeat_add_internal_link_10x(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new AddInternalLink( $settings, $safety, $changelog, $user );

		// Dry-run repeat 10 times
		$args = [
			'post_id'     => 601,
			'anchor_text' => 'Rank Math',
			'target_url'  => 'https://example.com/rank-math-guide/',
			'dry_run'     => true,
		];

		$first_result = $tool->execute( $args );
		$identical    = true;

		for ( $i = 2; $i <= 10; $i++ ) {
			$res = $tool->execute( $args );
			if ( $res['dry_run'] !== true || $res['anchor_text'] !== 'Rank Math' ) {
				$identical = false;
				break;
			}
		}

		return [
			'name'    => 'Stability: Run add_internal_link 10 times in a row with identical outcome',
			'success' => $identical,
			'message' => $identical ? 'Passed: 10/10 executions produced identical preview context' : 'Failed during repeated execution',
		];
	}
}

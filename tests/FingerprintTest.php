<?php
/**
 * Structure Fingerprint Assertion Tests
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
use SEOMCPGuard\Tools\EditTextBlock;
use SEOMCPGuard\Tools\AddInternalLink;
use SEOMCPGuard\Exceptions\MCPException;
use WP_User;
use WP_Post;
use TestMockState;

class FingerprintTest {

	public function run(): array {
		$results = [];

		$results[] = $this->test_valid_text_edit_preserves_fingerprint();
		$results[] = $this->test_tag_injection_rejected_by_fingerprint();
		$results[] = $this->test_class_modification_rejected_by_fingerprint();
		$results[] = $this->test_internal_link_structural_safety();

		return $results;
	}

	private function setup_env(): array {
		TestMockState::reset();

		$settings  = new Settings();
		$changelog = new ChangeLog();
		$ratelimit = new RateLimit();
		$purger    = new CachePurger();
		$health    = new HealthCheck();
		$safety    = new SafetyEngine( $settings, $changelog, $ratelimit, $purger, $health );
		$user      = new WP_User();

		// Create test Gutenberg post
		$post              = new WP_Post();
		$post->ID          = 201;
		$post->post_title  = 'Fingerprint Test Post';
		$post->post_name   = 'fingerprint-test-post';
		$post->post_content= "<!-- wp:paragraph {\"className\":\"intro-text\"} -->\n<p class=\"intro-text\">This is original paragraph content with important SEO information.</p>\n<!-- /wp:paragraph -->";
		$post->post_status = 'publish';
		$post->post_type   = 'post';
		TestMockState::$posts[201] = $post;

		return [ $settings, $safety, $changelog, $user ];
	}

	public function test_valid_text_edit_preserves_fingerprint(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new EditTextBlock( $settings, $safety, $changelog, $user );

		$args = [
			'post_id'     => 201,
			'block_index' => 0,
			'old_text'    => 'important SEO information',
			'new_text'    => 'valuable search optimization guidance',
			'dry_run'     => true,
		];

		try {
			$res = $tool->execute( $args );
			$pass = isset( $res['dry_run'] ) && $res['dry_run'] === true;
			return [
				'name'    => 'Fingerprint: Pure text modification preserves structure and passes',
				'success' => $pass,
				'message' => $pass ? 'Passed' : 'Execution failed',
			];
		} catch ( \Throwable $e ) {
			return [
				'name'    => 'Fingerprint: Pure text modification preserves structure and passes',
				'success' => false,
				'message' => 'Exception: ' . $e->getMessage(),
			];
		}
	}

	public function test_tag_injection_rejected_by_fingerprint(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new EditTextBlock( $settings, $safety, $changelog, $user );

		// Attempt to inject an extra <b> or <script> tag into text
		$args = [
			'post_id'     => 201,
			'block_index' => 0,
			'old_text'    => 'important SEO information',
			'new_text'    => '<b>important SEO information</b><script>alert(1)</script>',
			'dry_run'     => true,
		];

		try {
			$tool->execute( $args );
			return [
				'name'    => 'Fingerprint: HTML tag injection rejected',
				'success' => false,
				'message' => 'Failed: Tag injection was not caught by fingerprint check',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'VALIDATION' && str_contains( $e->getMessage(), 'Structure changed' );
			return [
				'name'    => 'Fingerprint: HTML tag injection rejected',
				'success' => $pass,
				'message' => $pass ? 'Passed: Refused with structural fingerprint error' : "Unexpected error: {$e->getMessage()}",
			];
		}
	}

	public function test_class_modification_rejected_by_fingerprint(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new EditTextBlock( $settings, $safety, $changelog, $user );

		// Attempt to inject a new CSS class via HTML tag into text node
		$args = [
			'post_id'     => 201,
			'block_index' => 0,
			'old_text'    => 'important SEO information',
			'new_text'    => '<span class="new-injected-class">important SEO information</span>',
			'dry_run'     => true,
		];

		try {
			$tool->execute( $args );
			return [
				'name'    => 'Fingerprint: CSS class alteration rejected',
				'success' => false,
				'message' => 'Failed: CSS class change was not caught',
			];
		} catch ( MCPException $e ) {
			$pass = $e->get_error_code() === 'VALIDATION' && str_contains( $e->getMessage(), 'Structure changed' );
			return [
				'name'    => 'Fingerprint: CSS class alteration rejected',
				'success' => $pass,
				'message' => $pass ? 'Passed: Correctly refused structural class alteration' : "Unexpected error: {$e->getMessage()}",
			];
		}
	}

	public function test_internal_link_structural_safety(): array {
		[ $settings, $safety, $changelog, $user ] = $this->setup_env();
		$tool = new AddInternalLink( $settings, $safety, $changelog, $user );

		$args = [
			'post_id'     => 201,
			'anchor_text' => 'SEO information',
			'target_url'  => 'https://example.com/seo-guide/',
			'dry_run'     => true,
		];

		try {
			$res = $tool->execute( $args );
			$pass = isset( $res['dry_run'] ) && $res['dry_run'] === true;
			return [
				'name'    => 'Fingerprint: AddInternalLink creates exactly one anchor tag and validates structure',
				'success' => $pass,
				'message' => $pass ? 'Passed' : 'Execution failed',
			];
		} catch ( \Throwable $e ) {
			return [
				'name'    => 'Fingerprint: AddInternalLink creates exactly one anchor tag and validates structure',
				'success' => false,
				'message' => 'Exception: ' . $e->getMessage(),
			];
		}
	}
}

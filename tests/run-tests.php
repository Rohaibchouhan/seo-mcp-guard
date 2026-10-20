<?php
/**
 * Automated Test Runner for SEO MCP Guard
 *
 * Runs all unit and integration test suites:
 * 1. Whitelist enforcement
 * 2. Structure-fingerprint check
 * 3. Rollback mechanism
 * 4. Loop & collision detection
 * 5. Idempotency verification
 * 6. 10x Consecutive Execution Stability
 *
 * @package SEOMCPGuard\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/WhitelistTest.php';
require_once __DIR__ . '/FingerprintTest.php';
require_once __DIR__ . '/RollbackTest.php';
require_once __DIR__ . '/LoopDetectionTest.php';
require_once __DIR__ . '/IdempotencyTest.php';
require_once __DIR__ . '/RepeatWriteTest.php';

use SEOMCPGuard\Tests\WhitelistTest;
use SEOMCPGuard\Tests\FingerprintTest;
use SEOMCPGuard\Tests\RollbackTest;
use SEOMCPGuard\Tests\LoopDetectionTest;
use SEOMCPGuard\Tests\IdempotencyTest;
use SEOMCPGuard\Tests\RepeatWriteTest;

echo "======================================================================\n";
echo "              SEO MCP Guard — Automated Test Suite                    \n";
echo "======================================================================\n\n";

$suites = [
	'Whitelist & Security Constraints' => new WhitelistTest(),
	'Structure Fingerprint Protection' => new FingerprintTest(),
	'Rollback & Change Log'           => new RollbackTest(),
	'Loop & Slug Collision Detection'  => new LoopDetectionTest(),
	'Idempotency Engine'               => new IdempotencyTest(),
	'10x Consecutive Stability'        => new RepeatWriteTest(),
];

$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

foreach ( $suites as $suite_name => $suite_instance ) {
	echo "Suite: {$suite_name}\n";
	echo str_repeat( '-', 70 ) . "\n";

	$results = $suite_instance->run();

	foreach ( $results as $result ) {
		$total_tests++;
		$status_tag = $result['success'] ? '[PASS]' : '[FAIL]';
		$color_code = $result['success'] ? "\033[32m" : "\033[31m";
		$reset_code = "\033[0m";

		echo sprintf( "  %-6s %s\n", $status_tag, $result['name'] );
		echo sprintf( "         %s\n\n", $result['message'] );

		if ( $result['success'] ) {
			$passed_tests++;
		} else {
			$failed_tests++;
		}
	}
}

echo "======================================================================\n";
echo sprintf( "Test Summary: %d Total | %d Passed | %d Failed\n", $total_tests, $passed_tests, $failed_tests );
echo "======================================================================\n";

if ( $failed_tests > 0 ) {
	exit( 1 );
}

echo "All test suites passed successfully!\n";
exit( 0 );

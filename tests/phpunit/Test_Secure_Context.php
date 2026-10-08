<?php
/**
 * Tests for browser secure-context detection.
 *
 * @package WebMCP
 */

namespace WebMCP\Tests;

use WebMCP\Secure_Context;
use WP_UnitTestCase;

/**
 * Tests for Secure_Context.
 */
class Test_Secure_Context extends WP_UnitTestCase {

	/**
	 * Verifies loopback hosts are recognized as trustworthy.
	 *
	 * @dataProvider loopback_hosts
	 *
	 * @param string $host Host value to test.
	 */
	public function test_recognizes_loopback_hosts( string $host ): void {
		$this->assertTrue( Secure_Context::is_loopback_host( $host ) );
	}

	/**
	 * Data provider for loopback host strings.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function loopback_hosts(): array {
		return [
			'localhost'           => [ 'localhost' ],
			'localhost with port' => [ 'localhost:5400' ],
			'localhost subdomain' => [ 'site.localhost:5400' ],
			'IPv4 loopback'       => [ '127.0.0.1:5400' ],
			'IPv4 loopback range' => [ '127.42.0.8' ],
			'IPv6 loopback'       => [ '[::1]:5400' ],
		];
	}

	/**
	 * Verifies non-loopback hosts are rejected over plain HTTP.
	 *
	 * @dataProvider non_loopback_hosts
	 *
	 * @param string $host Host value to test.
	 */
	public function test_rejects_non_loopback_hosts( string $host ): void {
		$this->assertFalse( Secure_Context::is_loopback_host( $host ) );
	}

	/**
	 * Data provider for non-loopback host strings.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function non_loopback_hosts(): array {
		return [
			'public domain'     => [ 'example.com' ],
			'localhost suffix'  => [ 'notlocalhost' ],
			'private network'   => [ '192.168.1.20:5400' ],
			'lookalike address' => [ '127.example.com' ],
		];
	}
}

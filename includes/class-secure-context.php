<?php
/**
 * Browser secure-context detection.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether the current origin is potentially trustworthy.
 */
class Secure_Context {

	/**
	 * Whether the current request can use secure-context browser APIs.
	 *
	 * Browsers treat loopback origins as potentially trustworthy even over HTTP.
	 * WordPress' is_ssl() does not account for that development exception.
	 */
	public static function is_available(): bool {
		if ( is_ssl() ) {
			return true;
		}

		$host = isset( $_SERVER['HTTP_HOST'] )
			? wp_unslash( $_SERVER['HTTP_HOST'] )
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return self::is_loopback_host( $host );
	}

	/**
	 * Whether a host is a browser-trusted loopback name or address.
	 *
	 * @param string $host Host, optionally including a port or IPv6 brackets.
	 */
	public static function is_loopback_host( string $host ): bool {
		$parsed = wp_parse_url( 'http://' . $host, PHP_URL_HOST );
		$host   = is_string( $parsed ) ? strtolower( rtrim( $parsed, '.' ) ) : '';

		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return true;
		}

		if ( '::1' === $host ) {
			return true;
		}

		return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
			&& str_starts_with( $host, '127.' );
	}
}

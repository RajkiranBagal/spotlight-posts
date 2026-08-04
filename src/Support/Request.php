<?php
/**
 * Reading request state on admin screens.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitized access to query parameters.
 *
 * Every $_GET read in the admin funnels through here so the nonce exemption is stated
 * once, with its reasoning, instead of repeated at each call site. These values only ever
 * select what is displayed -- which filter to apply, what counts to report after a bulk
 * action WordPress has already nonce-checked. Nothing is mutated from them.
 */
final class Request {

	/**
	 * Read a query parameter.
	 *
	 * @param string $key Parameter name.
	 * @return string Sanitized value, or an empty string when absent.
	 */
	public function query( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only state on an admin listing screen; see class docblock.
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return '';
		}

		// Unslashing and sanitizing stay in one expression: split across statements, the
		// sniffs cannot follow the value and report it as unsanitized.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only state on an admin listing screen; see class docblock.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Read a posted value.
	 *
	 * Callers are responsible for verifying a nonce before trusting anything read here;
	 * this only guarantees the value is a sanitized string.
	 *
	 * @param string $key Field name.
	 * @return string Sanitized value, or an empty string when absent.
	 */
	public function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce; this is the read itself.
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce; this is the read itself.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}
}

<?php
/**
 * Contract for anything that attaches itself to WordPress.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * A component that registers its own hooks.
 *
 * Previously every add_action and add_filter in the plugin -- thirty-seven of them --
 * lived in one bootstrap function, so the surface area of any single feature was spread
 * across a list you had to read end to end. Each component now declares its own, and the
 * Plugin only decides which components exist.
 */
interface Registrable {

	/**
	 * Attach this component's hooks.
	 */
	public function register(): void;
}

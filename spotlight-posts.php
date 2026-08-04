<?php
/**
 * Plugin Name:       Spotlight Posts
 * Plugin URI:        https://github.com/RajkiranBagal/spotlight-posts
 * Description:       Lets editors flag posts as featured, then surfaces them through a Query Loop variation, a dynamic block, and a public REST endpoint.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rajkiran Bagal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spotlight-posts
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 autoloader for this plugin's classes.
 *
 * Hand-rolled rather than Composer's. The plugin has no runtime dependencies -- composer
 * is used only for PHPCS and PHPUnit -- so shipping vendor/ purely to carry an autoloader
 * would mean committing thousands of files for fifteen lines of work.
 *
 * @param string $class_name Fully qualified class name.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		// realpath() plus a prefix check, so a crafted class name cannot traverse out of
		// src/ and include an arbitrary file.
		$real = realpath( $path );
		$root = realpath( __DIR__ . '/src' );

		if ( false !== $real && false !== $root && 0 === strpos( $real, $root ) ) {
			require_once $real;
		}
	}
);

/**
 * Post meta key holding the featured flag.
 *
 * Underscore-prefixed so it is treated as protected meta and never surfaces in
 * the default custom fields UI.
 *
 * @deprecated Superseded by Featured\Index::META_KEY. Kept while the remaining
 *             procedural modules are converted.
 */
const META_KEY = '_spotlight_featured';

/**
 * Plugin version, also used to bust the built block asset cache.
 */
const VERSION = '1.0.0';

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'SPOTLIGHT_POSTS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Absolute path to this file, for building asset URLs.
 */
define( 'SPOTLIGHT_POSTS_FILE', __FILE__ );

Plugin::boot();

/*
 * Build the index once at activation, so a site whose posts were already flagged
 * surfaces them immediately rather than waiting for the first read to notice the
 * option is missing.
 */
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );

<?php
/**
 * Plugin Name:       VIP Featured Posts
 * Plugin URI:        https://github.com/RajkiranBagal/vip-featured-posts
 * Description:       Lets editors flag posts as featured, then surfaces them through a dynamic block and a public REST endpoint.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rajkiran Bagal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vip-featured-posts
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta key holding the featured flag.
 *
 * Underscore-prefixed so it is treated as protected meta and never surfaces in
 * the default custom fields UI.
 */
const META_KEY = '_vip_featured';

/**
 * Plugin version, also used to bust the built block asset cache.
 */
const VERSION = '1.0.0';

/**
 * Absolute path to the plugin directory, with a trailing slash.
 */
define( 'VIP_FEATURED_POSTS_DIR', plugin_dir_path( __FILE__ ) );

require_once VIP_FEATURED_POSTS_DIR . 'includes/query.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/meta-box.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/block.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/rest.php';

/**
 * Wire up every hook the plugin owns.
 *
 * Registration is grouped here so a reviewer can see the plugin's entire
 * surface area in one place rather than hunting through includes.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\Meta_Box\\register_meta' );
	add_action( 'init', __NAMESPACE__ . '\\Block\\register' );

	add_action( 'add_meta_boxes_post', __NAMESPACE__ . '\\Meta_Box\\add_meta_box' );
	add_action( 'save_post_post', __NAMESPACE__ . '\\Meta_Box\\save' );

	add_action( 'rest_api_init', __NAMESPACE__ . '\\REST\\register_routes' );

	// Any post write invalidates every cached featured list.
	add_action( 'save_post_post', __NAMESPACE__ . '\\Query\\bump_cache_version' );
	add_action( 'deleted_post', __NAMESPACE__ . '\\Query\\bump_cache_version' );
}

bootstrap();

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

/**
 * Absolute path to this file, for building asset URLs.
 */
define( 'VIP_FEATURED_POSTS_FILE', __FILE__ );

require_once VIP_FEATURED_POSTS_DIR . 'includes/index.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/query.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/meta-box.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/block.php';
require_once VIP_FEATURED_POSTS_DIR . 'includes/rest.php';

if ( is_admin() ) {
	require_once VIP_FEATURED_POSTS_DIR . 'includes/admin/list-table.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once VIP_FEATURED_POSTS_DIR . 'includes/cli.php';
}

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

	if ( is_admin() ) {
		$list_table = __NAMESPACE__ . '\\Admin\\List_Table\\';

		add_filter( 'manage_post_posts_columns', $list_table . 'add_column' );
		add_action( 'manage_post_posts_custom_column', $list_table . 'render_column', 10, 2 );

		add_filter( 'bulk_actions-edit-post', $list_table . 'register_bulk_actions' );
		add_filter( 'handle_bulk_actions-edit-post', $list_table . 'handle_bulk_action', 10, 3 );
		add_action( 'admin_notices', $list_table . 'bulk_action_notice' );

		add_action( 'quick_edit_custom_box', $list_table . 'quick_edit_field', 10, 2 );

		add_action( 'restrict_manage_posts', $list_table . 'filter_dropdown' );
		add_action( 'pre_get_posts', $list_table . 'apply_filter' );

		add_action( 'admin_enqueue_scripts', $list_table . 'enqueue_assets' );
		add_action( 'wp_ajax_' . Admin\List_Table\AJAX_ACTION, $list_table . 'ajax_toggle' );
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		CLI\register();
	}

	/*
	 * Index maintenance. Every mutation routes through Index\set_ids(), which also
	 * invalidates the cached lists -- so these hooks keep the index correct and the
	 * cache fresh in one step.
	 *
	 * The meta hooks catch the flag being written outside any editing flow, which
	 * save_post never sees: WP-CLI, the REST meta endpoints, an admin-ajax toggle, or
	 * another plugin calling update_post_meta() directly.
	 */
	add_action( 'added_post_meta', __NAMESPACE__ . '\\Index\\sync_on_write', 10, 4 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\Index\\sync_on_write', 10, 4 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\Index\\sync_on_delete', 10, 3 );
	add_action( 'deleted_post', __NAMESPACE__ . '\\Index\\sync_on_post_delete' );

	/*
	 * A save can change what the list renders without touching the flag or the index
	 * -- a retitled post, a new excerpt, a draft going live. The index is already
	 * correct in those cases; only the cached payload is stale.
	 */
	add_action( 'save_post_post', __NAMESPACE__ . '\\Query\\bump_cache_version' );
}

bootstrap();

/*
 * Build the index once at activation, so a site whose posts were already flagged
 * surfaces them immediately rather than waiting for the first read to notice the
 * option is missing.
 */
register_activation_hook( __FILE__, __NAMESPACE__ . '\\Index\\rebuild' );

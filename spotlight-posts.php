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
 * Post meta key holding the featured flag.
 *
 * Underscore-prefixed so it is treated as protected meta and never surfaces in
 * the default custom fields UI.
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

/**
 * Post types this plugin operates on.
 *
 * Deliberately not named get_post_types(): inside this namespace an unqualified call to
 * that would resolve to ours rather than WordPress's, shadowing core in a way that is
 * hard to spot when reading a single line.
 *
 * Results are filtered through post_type_exists(), so a filter naming a type that was
 * never registered cannot produce meta bound to a type that does not exist.
 *
 * @return string[] Supported post type slugs.
 */
function supported_post_types(): array {
	/**
	 * Filters the post types that can be spotlighted.
	 *
	 * @param string[] $post_types Post type slugs. Defaults to just 'post'.
	 */
	$types = (array) apply_filters( 'spotlight_posts_post_types', array( 'post' ) );

	$types = array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $types ),
				static function ( string $type ): bool {
					return '' !== $type && post_type_exists( $type );
				}
			)
		)
	);

	/*
	 * Falls back to 'post' rather than returning empty. An empty post_type is ignored by
	 * WP_Query, which would silently widen every query instead of narrowing it -- the
	 * same trap as an empty post__in.
	 */
	return empty( $types ) ? array( 'post' ) : $types;
}

require_once SPOTLIGHT_POSTS_DIR . 'includes/schedule.php';
require_once SPOTLIGHT_POSTS_DIR . 'includes/index.php';
require_once SPOTLIGHT_POSTS_DIR . 'includes/query.php';
require_once SPOTLIGHT_POSTS_DIR . 'includes/meta-box.php';
require_once SPOTLIGHT_POSTS_DIR . 'includes/block.php';
require_once SPOTLIGHT_POSTS_DIR . 'includes/rest.php';
require_once SPOTLIGHT_POSTS_DIR . 'includes/query-loop.php';

if ( is_admin() ) {
	require_once SPOTLIGHT_POSTS_DIR . 'includes/admin/list-table.php';
	require_once SPOTLIGHT_POSTS_DIR . 'includes/admin/order-screen.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SPOTLIGHT_POSTS_DIR . 'includes/cli.php';
}

/**
 * Wire up every hook the plugin owns.
 *
 * Registration is grouped here so a reviewer can see the plugin's entire
 * surface area in one place rather than hunting through includes.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\Meta_Box\\register_meta' );
	add_action( 'init', __NAMESPACE__ . '\\Schedule\\register_meta' );
	add_action( 'init', __NAMESPACE__ . '\\Block\\register' );

	/*
	 * Scheduled expiry. The cron event clears the flag at the expiry moment, which
	 * routes through the same index sync as any other unfeature -- so the cached lists
	 * are invalidated then, rather than waiting for a TTL to lapse.
	 */
	add_action( Schedule\CRON_HOOK, __NAMESPACE__ . '\\Schedule\\handle_cron' );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\Schedule\\clear_on_unfeature', 10, 3 );
	add_action( 'deleted_post', __NAMESPACE__ . '\\Schedule\\clear_on_delete' );

	// An expiry change alters what the lists will contain, so it invalidates them too.
	add_action( 'added_post_meta', __NAMESPACE__ . '\\Schedule\\invalidate_on_expiry_change', 10, 3 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\Schedule\\invalidate_on_expiry_change', 10, 3 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\Schedule\\invalidate_on_expiry_change', 10, 3 );

	/*
	 * Per-type hooks. These are dynamic hook names, so a plugin supporting several post
	 * types has to register once per type rather than once overall -- there is no
	 * `save_post_any` to hook. Registered on init so a filter can name a custom post
	 * type that is itself registered on init.
	 */
	add_action( 'init', __NAMESPACE__ . '\\register_post_type_hooks', 20 );

	add_action( 'rest_api_init', __NAMESPACE__ . '\\REST\\register_routes' );

	/*
	 * Query Loop variation. The front-end filter narrows core's own query; the REST
	 * pair exists because the editor previews a Query Loop by calling the posts
	 * collection directly, and core does not forward a variation's namespace there.
	 */
	add_filter( 'query_loop_block_query_vars', __NAMESPACE__ . '\\Query_Loop\\filter_query_vars', 10, 3 );
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\Query_Loop\\enqueue_editor_assets' );
	add_filter( 'rest_post_collection_params', __NAMESPACE__ . '\\Query_Loop\\add_rest_collection_param' );
	add_filter( 'rest_post_query', __NAMESPACE__ . '\\Query_Loop\\filter_rest_query', 10, 2 );

	if ( is_admin() ) {
		$list_table = __NAMESPACE__ . '\\Admin\\List_Table\\';

		add_action( 'admin_notices', $list_table . 'bulk_action_notice' );

		add_action( 'quick_edit_custom_box', $list_table . 'quick_edit_field', 10, 2 );

		add_action( 'restrict_manage_posts', $list_table . 'filter_dropdown' );
		add_action( 'pre_get_posts', $list_table . 'apply_filter' );

		add_action( 'admin_enqueue_scripts', $list_table . 'enqueue_assets' );
		add_action( 'wp_ajax_' . Admin\List_Table\AJAX_ACTION, $list_table . 'ajax_toggle' );

		$order_screen = __NAMESPACE__ . '\\Admin\\Order_Screen\\';

		add_action( 'admin_menu', $order_screen . 'register_menu' );
		add_action( 'admin_enqueue_scripts', $order_screen . 'enqueue_assets' );
		add_action( 'wp_ajax_' . Admin\Order_Screen\AJAX_ACTION, $order_screen . 'ajax_save_order' );
		add_action( 'wp_ajax_' . Admin\Order_Screen\AJAX_REMOVE_ACTION, $order_screen . 'ajax_remove' );
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
	add_action( 'save_post', __NAMESPACE__ . '\\Query\\bump_cache_version' );
}

/**
 * Register the hooks whose names embed a post type.
 *
 * Run on init at priority 20 so a site can register a custom post type on init and still
 * have it picked up by the spotlight_posts_post_types filter.
 */
function register_post_type_hooks(): void {
	$list_table = __NAMESPACE__ . '\\Admin\\List_Table\\';

	foreach ( supported_post_types() as $post_type ) {
		add_action( "add_meta_boxes_{$post_type}", __NAMESPACE__ . '\\Meta_Box\\add_meta_box' );
		add_action( "save_post_{$post_type}", __NAMESPACE__ . '\\Meta_Box\\save' );

		if ( ! is_admin() ) {
			continue;
		}

		add_filter( "manage_{$post_type}_posts_columns", $list_table . 'add_column' );
		add_action( "manage_{$post_type}_posts_custom_column", $list_table . 'render_column', 10, 2 );

		add_filter( "bulk_actions-edit-{$post_type}", $list_table . 'register_bulk_actions' );
		add_filter( "handle_bulk_actions-edit-{$post_type}", $list_table . 'handle_bulk_action', 10, 3 );
	}
}

bootstrap();

/*
 * Build the index once at activation, so a site whose posts were already flagged
 * surfaces them immediately rather than waiting for the first read to notice the
 * option is missing.
 */
register_activation_hook( __FILE__, __NAMESPACE__ . '\\Index\\rebuild' );

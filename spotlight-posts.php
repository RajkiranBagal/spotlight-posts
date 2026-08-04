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

/**
 * Post types this plugin operates on.
 *
 * Delegates to the PostTypes service. Kept as a function while the remaining procedural
 * modules are converted; each becomes a class that receives the service directly.
 *
 * @deprecated Superseded by Support\PostTypes::all().
 *
 * @return string[] Supported post type slugs.
 */
function supported_post_types(): array {
	return Plugin::instance()->get( Support\PostTypes::class )->all();
}

/**
 * The featured posts repository.
 *
 * @deprecated Transitional. Modules that become classes receive this through their
 *             constructor instead, and these accessors go away with the last of them.
 *
 * @return Featured\Repository Repository service.
 */
function repository(): Featured\Repository {
	return Plugin::instance()->get( Featured\Repository::class );
}

/**
 * The ordered ID index.
 *
 * @deprecated Transitional. See repository().
 *
 * @return Featured\Index Index service.
 */
function index(): Featured\Index {
	return Plugin::instance()->get( Featured\Index::class );
}

/**
 * The expiry scheduler.
 *
 * @deprecated Transitional. See repository().
 *
 * @return Featured\Schedule Schedule service.
 */
function schedule(): Featured\Schedule {
	return Plugin::instance()->get( Featured\Schedule::class );
}

/*
 * Schedule, Index and Query now live in src/ as classes and load through the autoloader.
 * What remains here is procedural and is converted in the following pull requests.
 */
require_once SPOTLIGHT_POSTS_DIR . 'includes/meta-box.php';

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
	/*
	 * Loaded on init rather than earlier. Since WordPress 6.7, loading a text domain
	 * before init triggers a _doing_it_wrong notice, because translations cannot be
	 * resolved until the locale is settled.
	 */
	add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

	add_action( 'init', __NAMESPACE__ . '\\Meta_Box\\register_meta' );

	/*
	 * Per-type hooks. These are dynamic hook names, so a plugin supporting several post
	 * types has to register once per type rather than once overall -- there is no
	 * `save_post_any` to hook. Registered on init so a filter can name a custom post
	 * type that is itself registered on init.
	 */
	add_action( 'init', __NAMESPACE__ . '\\register_post_type_hooks', 20 );


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

}

/**
 * Load translations for the plugin's own text domain.
 *
 * Needed because this is not distributed through wordpress.org, where translations are
 * fetched and loaded automatically.
 */
function load_textdomain(): void {
	load_plugin_textdomain(
		'spotlight-posts',
		false,
		dirname( plugin_basename( SPOTLIGHT_POSTS_FILE ) ) . '/languages'
	);
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

Plugin::boot();
bootstrap();

/*
 * Build the index once at activation, so a site whose posts were already flagged
 * surfaces them immediately rather than waiting for the first read to notice the
 * option is missing.
 */
register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );

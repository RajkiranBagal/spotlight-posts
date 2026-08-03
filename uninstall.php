<?php
/**
 * Runs when the plugin is deleted through the admin.
 *
 * Deleting a plugin should not leave its data behind, but it also must not be
 * destructive beyond its own footprint. Only keys this plugin created are removed:
 * its two meta keys, its index option and any expiry events still scheduled.
 *
 * Post content is never touched. A site that deletes the plugin keeps its posts; it
 * simply stops treating any of them as featured.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

// Set by WordPress when it invokes this file. Its absence means the file was reached
// some other way, which is exactly when it must not run.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Meta key holding the featured flag.
 *
 * Duplicated as a literal rather than pulled from the plugin: WordPress does not load
 * the plugin before running this file, so its constants do not exist here.
 */
const SPOTLIGHT_POSTS_UNINSTALL_META_KEY = '_spotlight_featured';

/**
 * Meta key holding the scheduled expiry.
 */
const SPOTLIGHT_POSTS_UNINSTALL_EXPIRY_KEY = '_spotlight_featured_until';

/**
 * Option holding the ordered index.
 */
const SPOTLIGHT_POSTS_UNINSTALL_OPTION = 'spotlight_featured_post_ids';

/**
 * Cron hook used for scheduled expiry.
 */
const SPOTLIGHT_POSTS_UNINSTALL_CRON_HOOK = 'spotlight_posts_expire';

/**
 * Remove every trace of the plugin from a single site.
 */
function spotlight_posts_uninstall_site(): void {
	// delete_post_meta_by_key() handles the whole table in one call, so this does not
	// have to enumerate posts -- which matters on a site with a large wp_postmeta.
	delete_post_meta_by_key( SPOTLIGHT_POSTS_UNINSTALL_META_KEY );
	delete_post_meta_by_key( SPOTLIGHT_POSTS_UNINSTALL_EXPIRY_KEY );

	/*
	 * The option is removed *after* the meta, not before. delete_post_meta_by_key()
	 * fires deleted_post_meta for every row, and if this plugin's hooks happen to still
	 * be attached, the index sync sees a missing option and rebuilds it -- leaving the
	 * option behind. WordPress does not load the plugin when running uninstall.php, so
	 * that should not occur, but the ordering costs nothing and does not rely on it.
	 */
	delete_option( SPOTLIGHT_POSTS_UNINSTALL_OPTION );

	/*
	 * Expiry events are scheduled per post, so each carries its own arguments and
	 * wp_clear_scheduled_hook() with no args would not match them. wp_unschedule_hook()
	 * removes every event for the hook regardless of arguments.
	 */
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( SPOTLIGHT_POSTS_UNINSTALL_CRON_HOOK );
	}

	spotlight_posts_uninstall_flush_cache_group( 'spotlight_posts' );
}

/**
 * Drop the plugin's object cache group where the backend supports it.
 *
 * wp_cache_flush_group() only exists on WordPress 6.1+ and only does anything when the
 * object cache backend implements it, so this degrades to a no-op rather than falling
 * back to wp_cache_flush() -- flushing the entire cache on uninstall would evict every
 * other plugin's data too.
 *
 * @param string $group Cache group to drop.
 */
function spotlight_posts_uninstall_flush_cache_group( string $group ): void {
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( $group );
	}
}

if ( is_multisite() ) {
	$spotlight_posts_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $spotlight_posts_site_ids as $spotlight_posts_site_id ) {
		switch_to_blog( (int) $spotlight_posts_site_id );
		spotlight_posts_uninstall_site();
		restore_current_blog();
	}
} else {
	spotlight_posts_uninstall_site();
}

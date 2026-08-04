<?php
/**
 * Ordered index of featured post IDs.
 *
 * The post meta remains the source of truth for whether an individual post is
 * featured. This index is a derived, ordered projection of that meta, maintained on
 * write so that reads never have to search for it.
 *
 * The trade it makes: an unindexed scan of wp_postmeta on every read becomes a
 * primary-key lookup against a list we already hold. Because the index is derived it
 * is also disposable -- if it is ever wrong, `wp spotlight rebuild` regenerates it
 * from the meta.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Index;

use Spotlight_Posts;
use Spotlight_Posts\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Option holding the ordered list of featured post IDs.
 */
const OPTION = 'spotlight_featured_post_ids';

/**
 * Hard ceiling on how many IDs the index will hold.
 *
 * This is a platform constraint, not a product one. The option is autoloaded, so it
 * lands in alloptions on every request -- VIP runs an `alloptions-limit` mu-plugin
 * precisely because oversized autoloaded options degrade every page load. At this cap
 * the serialized value stays under roughly a kilobyte, which is comfortably safe.
 */
const MAX_IDS = 100;

/**
 * Read the index, building it once if it has never been written.
 *
 * The distinction between `false` and an empty array matters here. `false` means the
 * option has never been set -- the plugin was activated on a site whose posts were
 * already flagged -- and warrants a one-time rebuild. An empty array means the index
 * is current and genuinely holds nothing, so returning early is correct.
 *
 * @return int[] Ordered featured post IDs.
 */
function get_ids(): array {
	$ids = get_option( OPTION, false );

	if ( false === $ids ) {
		return rebuild();
	}

	return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Overwrite the index and invalidate every cached list.
 *
 * Every mutation funnels through here, so cache invalidation lives in exactly one
 * place rather than being repeated at each call site.
 *
 * @param int[] $ids Post IDs, in the order they should be displayed.
 */
function set_ids( array $ids ): void {
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	$ids = array_slice( $ids, 0, MAX_IDS );

	update_option( OPTION, $ids );

	Query\bump_cache_version();
}

/**
 * Add a post to the front of the index.
 *
 * New entries lead because "most recently featured first" is the useful default until
 * an editor imposes their own order.
 *
 * @param int $post_id Post to feature.
 */
function add( int $post_id ): void {
	$ids = get_ids();

	if ( in_array( $post_id, $ids, true ) ) {
		return;
	}

	array_unshift( $ids, $post_id );

	set_ids( $ids );
}

/**
 * Drop a post from the index.
 *
 * @param int $post_id Post to unfeature.
 */
function remove( int $post_id ): void {
	$ids = get_ids();

	$remaining = array_values(
		array_filter(
			$ids,
			static function ( int $id ) use ( $post_id ): bool {
				return $id !== $post_id;
			}
		)
	);

	if ( count( $remaining ) === count( $ids ) ) {
		return;
	}

	set_ids( $remaining );
}

/**
 * Regenerate the index from post meta.
 *
 * This is the only place the plugin still searches by meta, and the only place it
 * should. It runs on activation, on first read, and on demand via WP-CLI -- never on
 * a front-end request.
 *
 * Posts in any non-trashed status are indexed, not just published ones. The flag
 * belongs to the post regardless of where it sits in an editorial workflow;
 * @see \Spotlight_Posts\Query\get_featured_posts() applies the `publish` filter at
 * read time. Indexing only published posts would silently drop the flag whenever
 * something went back to draft.
 *
 * @return int[] The rebuilt index.
 */
function rebuild(): array {
	/*
	 * The meta lookup below is the slow query this index exists to eliminate from the
	 * read path. It is acceptable here because this function runs on activation and on
	 * demand, not per request, and is bounded by MAX_IDS.
	 */
	$query = new \WP_Query(
		array(
			'post_type'              => Spotlight_Posts\supported_post_types(),
			'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page'         => MAX_IDS,
			'fields'                 => 'ids',
			'meta_key'               => Spotlight_Posts\META_KEY,
			'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Maintenance path only; never runs on a front-end request. See docblock.
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'orderby'                => 'date',
			'order'                  => 'DESC',
		)
	);

	$ids = array_map( 'intval', $query->posts );

	set_ids( $ids );

	return $ids;
}

/**
 * Keep the index in step with a meta write.
 *
 * Bound to `added_post_meta` and `updated_post_meta`, where the fourth argument is the
 * value now stored.
 *
 * @param int|int[] $meta_id    Meta row ID. Unused.
 * @param int       $object_id  Post the meta belongs to.
 * @param mixed     $meta_key   Meta key that was written.
 * @param mixed     $meta_value Value that was written.
 */
function sync_on_write( $meta_id, $object_id, $meta_key, $meta_value ): void {
	if ( Spotlight_Posts\META_KEY !== $meta_key ) {
		return;
	}

	if ( '1' === (string) $meta_value ) {
		add( (int) $object_id );
	} else {
		remove( (int) $object_id );
	}
}

/**
 * Drop a post from the index when its featured meta is deleted.
 *
 * Deliberately separate from sync_on_write(). `deleted_post_meta` passes the value
 * that was just removed as its fourth argument, so sharing a callback would read the
 * outgoing '1' and re-add the post it was meant to drop.
 *
 * @param int[] $meta_ids  Meta row IDs. Unused.
 * @param int   $object_id Post the meta belonged to.
 * @param mixed $meta_key  Meta key that was deleted.
 */
function sync_on_delete( $meta_ids, $object_id, $meta_key ): void {
	if ( Spotlight_Posts\META_KEY !== $meta_key ) {
		return;
	}

	remove( (int) $object_id );
}

/**
 * Drop a permanently deleted post from the index.
 *
 * Trashing is not handled here on purpose: a trashed post fails the `publish` filter
 * at read time, and keeping it indexed means restoring it from the trash restores its
 * position too.
 *
 * @param int $post_id Post that was deleted.
 */
function sync_on_post_delete( int $post_id ): void {
	remove( $post_id );
}

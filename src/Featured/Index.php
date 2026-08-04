<?php
/**
 * Ordered index of spotlighted post IDs.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Featured;

use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\Cache;
use Spotlight_Posts\Support\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * A derived, ordered projection of the featured post meta.
 *
 * Post meta remains the source of truth for whether an individual post is featured. This
 * index is maintained on write so reads never have to search for it, turning an unindexed
 * scan of wp_postmeta into a primary-key lookup against a list already in hand.
 *
 * Because it is derived it is also disposable: if it is ever wrong, rebuild() regenerates
 * it from the meta.
 */
final class Index implements Registrable {

	/**
	 * Meta key holding the featured flag.
	 */
	public const META_KEY = '_spotlight_featured';

	/**
	 * Option holding the ordered list of IDs.
	 */
	public const OPTION = 'spotlight_featured_post_ids';

	/**
	 * Hard ceiling on how many IDs the index will hold.
	 *
	 * A platform constraint, not a product one. The option is autoloaded, so it lands in
	 * alloptions on every request -- VIP runs an alloptions-limit mu-plugin precisely
	 * because oversized autoloaded options degrade every page load. At this cap the
	 * serialized value stays under roughly a kilobyte.
	 */
	public const MAX_IDS = 100;

	/**
	 * Cache used to invalidate dependent lists.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

	/**
	 * @param Cache     $cache      Cache collaborator.
	 * @param PostTypes $post_types Supported post types.
	 */
	public function __construct( Cache $cache, PostTypes $post_types ) {
		$this->cache      = $cache;
		$this->post_types = $post_types;
	}

	/**
	 * Keep the index in step with writes to the featured flag.
	 */
	public function register(): void {
		add_action( 'added_post_meta', array( $this, 'sync_on_write' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'sync_on_write' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'sync_on_delete' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'sync_on_post_delete' ) );
	}

	/**
	 * Read the index, building it once if it has never been written.
	 *
	 * The distinction between false and an empty array matters: false means the option has
	 * never been set -- the plugin was activated on a site whose posts were already
	 * flagged -- and warrants a one-time rebuild. An empty array means the index is current
	 * and genuinely holds nothing.
	 *
	 * @return int[] Ordered post IDs.
	 */
	public function ids(): array {
		$ids = get_option( self::OPTION, false );

		if ( false === $ids ) {
			return $this->rebuild();
		}

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Overwrite the index and invalidate every cached list.
	 *
	 * Every mutation funnels through here, so invalidation lives in one place rather than
	 * being repeated at each call site.
	 *
	 * @param int[] $ids Post IDs, in display order.
	 */
	public function set( array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$ids = array_slice( $ids, 0, self::MAX_IDS );

		update_option( self::OPTION, $ids );

		$this->cache->flush();
	}

	/**
	 * Add a post to the front of the index.
	 *
	 * New entries lead because "most recently featured first" is the useful default until
	 * an editor imposes their own order.
	 *
	 * @param int $post_id Post to feature.
	 */
	public function add( int $post_id ): void {
		$ids = $this->ids();

		if ( in_array( $post_id, $ids, true ) ) {
			return;
		}

		array_unshift( $ids, $post_id );

		$this->set( $ids );
	}

	/**
	 * Drop a post from the index.
	 *
	 * @param int $post_id Post to unfeature.
	 */
	public function remove( int $post_id ): void {
		$ids = $this->ids();

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

		$this->set( $remaining );
	}

	/**
	 * Apply a submitted order.
	 *
	 * The IDs are an ordering instruction, never a membership list. They are intersected
	 * with what is already indexed, so this cannot be used to feature an arbitrary post --
	 * that still requires the meta write and its own per-post capability check. Anything
	 * indexed but absent is appended rather than dropped, so a stale tab cannot silently
	 * unfeature posts it never knew about.
	 *
	 * @param int[] $submitted Post IDs in the requested order.
	 * @return int[] Resulting index.
	 */
	public function reorder( array $submitted ): array {
		$submitted = array_map( 'absint', $submitted );
		$current   = $this->ids();

		$ordered = array_values( array_intersect( $submitted, $current ) );
		$missing = array_values( array_diff( $current, $ordered ) );

		$this->set( array_merge( $ordered, $missing ) );

		return $this->ids();
	}

	/**
	 * Regenerate the index from post meta.
	 *
	 * The only place the plugin still searches by meta, and the only place it should. Runs
	 * on activation, on first read, and on demand via WP-CLI -- never on a front-end
	 * request.
	 *
	 * Posts in any non-trashed status are indexed, not only published ones: the flag
	 * belongs to the post regardless of where it sits in an editorial workflow, and the
	 * read path applies the publish filter.
	 *
	 * @return int[] The rebuilt index.
	 */
	public function rebuild(): array {
		$query = new \WP_Query(
			array(
				'post_type'              => $this->post_types->all(),
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'         => self::MAX_IDS,
				'fields'                 => 'ids',
				'meta_key'               => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Maintenance path only; never runs on a front-end request. See docblock.
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

		$this->set( $ids );

		return $ids;
	}

	/**
	 * Keep the index in step with a meta write.
	 *
	 * @param int|int[] $meta_id    Meta row ID. Unused.
	 * @param int       $object_id  Post the meta belongs to.
	 * @param mixed     $meta_key   Meta key that was written.
	 * @param mixed     $meta_value Value that was written.
	 */
	public function sync_on_write( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		if ( '1' === (string) $meta_value ) {
			$this->add( (int) $object_id );
		} else {
			$this->remove( (int) $object_id );
		}
	}

	/**
	 * Drop a post when its featured meta is deleted.
	 *
	 * Deliberately separate from sync_on_write(). deleted_post_meta passes the value that
	 * was just removed as its fourth argument, so sharing a callback would read the
	 * outgoing '1' and re-add the post it was meant to drop.
	 *
	 * @param int[] $meta_ids  Meta row IDs. Unused.
	 * @param int   $object_id Post the meta belonged to.
	 * @param mixed $meta_key  Meta key that was deleted.
	 */
	public function sync_on_delete( $meta_ids, $object_id, $meta_key ): void {
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		$this->remove( (int) $object_id );
	}

	/**
	 * Drop a permanently deleted post.
	 *
	 * Trashing is not handled here on purpose: a trashed post fails the publish filter at
	 * read time, and keeping it indexed means restoring it from the trash restores its
	 * position too.
	 *
	 * @param int $post_id Post that was deleted.
	 */
	public function sync_on_post_delete( int $post_id ): void {
		$this->remove( $post_id );
	}
}

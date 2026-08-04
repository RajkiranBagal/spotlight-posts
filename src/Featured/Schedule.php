<?php
/**
 * Scheduled expiry for spotlighted posts.
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
 * Expires a post's featured flag at a chosen moment.
 *
 * "Feature this until Friday" sounds like a UI feature. It is really a question about how
 * long a cached list may disagree with reality, and it takes three mechanisms:
 *
 *  1. A cron event fires at the expiry moment and clears the flag. Because it clears it
 *     through delete_post_meta(), it takes the same path as any other unfeature -- the
 *     index sync removes the post and invalidates the cached lists then, rather than
 *     whenever a TTL happens to lapse.
 *  2. A read-time check filters expired posts before the payload is cached. WP-Cron is
 *     request-driven on stock WordPress, so an event can fire late on a quiet site.
 *  3. Writing an expiry invalidates the cached lists, because the read-time check only
 *     runs on a cache *miss* -- without this a cache hit returns before it is reached.
 *
 * The residual window: if a post expires just after a list is cached and cron never
 * fires, it can remain visible for up to one TTL.
 */
final class Schedule implements Registrable {

	/**
	 * Meta key holding the expiry as a UTC Unix timestamp.
	 */
	public const META_KEY = '_spotlight_featured_until';

	/**
	 * Cron hook fired when a post reaches its expiry.
	 */
	public const CRON_HOOK = 'spotlight_posts_expire';

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
	 * Wire up meta registration, the cron callback and invalidation.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );

		add_action( self::CRON_HOOK, array( $this, 'handle_cron' ) );
		add_action( 'deleted_post_meta', array( $this, 'clear_on_unfeature' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'clear_on_delete' ) );

		// An expiry change alters what the lists will contain, so it invalidates them too.
		add_action( 'added_post_meta', array( $this, 'invalidate_on_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_on_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_on_change' ), 10, 3 );
	}

	/**
	 * Register the expiry as post meta on every supported type.
	 */
	public function register_meta(): void {
		foreach ( $this->post_types->all() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type'              => 'integer',
					'single'            => true,
					'default'           => 0,
					'show_in_rest'      => false,
					'sanitize_callback' => array( $this, 'sanitize_meta' ),
					'auth_callback'     => array( $this, 'auth_meta' ),
				)
			);
		}
	}

	/**
	 * Normalise the stored value to a non-negative timestamp.
	 *
	 * @param mixed $value Incoming value.
	 * @return int Sanitized timestamp, or 0 for "no expiry".
	 */
	public function sanitize_meta( $value ): int {
		return max( 0, (int) $value );
	}

	/**
	 * Authorise writes to the expiry.
	 *
	 * @param bool  $allowed   Whether the user can add the meta. Unused.
	 * @param mixed $meta_key  Meta key being written. Unused.
	 * @param mixed $object_id Post being written to.
	 * @return bool Whether the current user may write this meta.
	 */
	public function auth_meta( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Read a post's expiry.
	 *
	 * @param int $post_id Post to inspect.
	 * @return int UTC timestamp, or 0 when the post does not expire.
	 */
	public function expiry_for( int $post_id ): int {
		return max( 0, (int) get_post_meta( $post_id, self::META_KEY, true ) );
	}

	/**
	 * Has this post's expiry passed?
	 *
	 * A post with no expiry never expires, so 0 is not "expired at the epoch".
	 *
	 * @param int $post_id Post to inspect.
	 * @return bool Whether the post has expired.
	 */
	public function is_expired( int $post_id ): bool {
		$expiry = $this->expiry_for( $post_id );

		return $expiry > 0 && $expiry <= time();
	}

	/**
	 * Set or clear a post's expiry, keeping the cron event in step.
	 *
	 * @param int $post_id   Post to schedule.
	 * @param int $timestamp UTC timestamp, or 0 to clear.
	 */
	public function set_expiry( int $post_id, int $timestamp ): void {
		$this->unschedule( $post_id );

		if ( $timestamp <= 0 ) {
			delete_post_meta( $post_id, self::META_KEY );

			return;
		}

		update_post_meta( $post_id, self::META_KEY, $timestamp );

		// An expiry already in the past is applied now rather than scheduled for a moment
		// that will never arrive.
		if ( $timestamp <= time() ) {
			$this->expire( $post_id );

			return;
		}

		wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $post_id ) );
	}

	/**
	 * Cancel any pending expiry event for a post.
	 *
	 * @param int $post_id Post to unschedule.
	 */
	public function unschedule( int $post_id ): void {
		$next = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );

		while ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK, array( $post_id ) );

			$next = wp_next_scheduled( self::CRON_HOOK, array( $post_id ) );
		}
	}

	/**
	 * Unfeature a post whose expiry has arrived.
	 *
	 * Clears the flag through delete_post_meta() rather than editing the index, so this
	 * takes the same path as any other unfeature.
	 *
	 * @param int $post_id Post to expire.
	 */
	public function expire( int $post_id ): void {
		delete_post_meta( $post_id, Index::META_KEY );
		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Cron callback.
	 *
	 * Untyped, because a scheduled event's arguments come back out of the database and a
	 * malformed one should fall through rather than raise a TypeError inside cron.
	 *
	 * @param mixed $post_id Post to expire.
	 */
	public function handle_cron( $post_id = 0 ): void {
		$post_id = (int) $post_id;

		if ( $post_id > 0 ) {
			$this->expire( $post_id );
		}
	}

	/**
	 * Drop a pending expiry when a post stops being featured.
	 *
	 * Without this, unfeaturing and re-featuring inside the original window would leave
	 * the old event armed and the post would silently expire early.
	 *
	 * @param int[] $meta_ids  Meta row IDs. Unused.
	 * @param int   $object_id Post the meta belonged to.
	 * @param mixed $meta_key  Meta key that was deleted.
	 */
	public function clear_on_unfeature( $meta_ids, $object_id, $meta_key ): void {
		if ( Index::META_KEY !== $meta_key ) {
			return;
		}

		$post_id = (int) $object_id;

		$this->unschedule( $post_id );
		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Drop a pending expiry when a post is deleted.
	 *
	 * @param int $post_id Post that was deleted.
	 */
	public function clear_on_delete( int $post_id ): void {
		$this->unschedule( $post_id );
	}

	/**
	 * Invalidate the cached lists when an expiry is written.
	 *
	 * @param int|int[] $meta_id   Meta row ID. Unused.
	 * @param int       $object_id Post the meta belongs to. Unused.
	 * @param mixed     $meta_key  Meta key that was written.
	 */
	public function invalidate_on_change( $meta_id, $object_id, $meta_key ): void {
		if ( self::META_KEY !== $meta_key ) {
			return;
		}

		$this->cache->flush();
	}
}

<?php
/**
 * Reads the spotlighted posts.
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
 * The cached, bounded read path.
 *
 * Depends on the index, the schedule and the cache -- and none of them depend back on it.
 * That one-directional flow is the point of the restructure: previously the index and the
 * scheduler both called into this module while it called into them.
 */
final class Repository implements Registrable {

	/**
	 * Lower bound for the number of posts a caller may request.
	 */
	public const MIN_POSTS = 1;

	/**
	 * Upper bound for the number of posts a caller may request.
	 */
	public const MAX_POSTS = 10;

	/**
	 * Ordered ID index.
	 *
	 * @var Index
	 */
	private Index $index;

	/**
	 * Expiry rules.
	 *
	 * @var Schedule
	 */
	private Schedule $schedule;

	/**
	 * Object cache.
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
	 * @param Index     $index      Ordered ID index.
	 * @param Schedule  $schedule   Expiry rules.
	 * @param Cache     $cache      Object cache.
	 * @param PostTypes $post_types Supported post types.
	 */
	public function __construct( Index $index, Schedule $schedule, Cache $cache, PostTypes $post_types ) {
		$this->index      = $index;
		$this->schedule   = $schedule;
		$this->cache      = $cache;
		$this->post_types = $post_types;
	}

	/**
	 * Invalidate cached payloads when a save changes how a post renders.
	 *
	 * The index is already correct for a retitle or a new excerpt -- only the cached
	 * payload is stale, so this is the one case the index hooks do not cover.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this->cache, 'flush' ) );
	}

	/**
	 * Fetch the spotlighted posts as a lightweight array.
	 *
	 * @param int $number_of_posts How many to return. Clamped to MIN_POSTS..MAX_POSTS.
	 * @return FeaturedPost[] Spotlighted posts, in index order.
	 */
	public function find( int $number_of_posts = 5 ): array {
		$number_of_posts = max( self::MIN_POSTS, min( self::MAX_POSTS, $number_of_posts ) );

		$key    = $this->cache->key( 'featured', array( 'n' => $number_of_posts ) );
		$cached = $this->cache->get( $key );

		if ( null !== $cached ) {
			return array_map(
				static function ( array $data ): FeaturedPost {
					return FeaturedPost::from_array( $data );
				},
				$cached
			);
		}

		$ids = $this->index->ids();

		if ( empty( $ids ) ) {
			$this->cache->set( $key, array() );

			return array();
		}

		/*
		 * A primary-key lookup, not a meta search. The index already knows which posts are
		 * featured and in what order, so this only has to fetch them.
		 *
		 * Filtering by status happens here rather than in the index because the index
		 * tracks the flag, not publication state -- a draft keeps its position and simply
		 * does not surface until it is published.
		 */
		$query = new \WP_Query(
			array(
				'post_type'              => $this->post_types->all(),
				'post_status'            => 'publish',
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => $number_of_posts,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$posts = array();

		foreach ( $query->posts as $post ) {
			// Cron normally clears the flag at the expiry moment. This is the safety net
			// for a run that has not happened yet, checked before caching so an expired
			// post cannot be baked into the payload.
			if ( $this->schedule->is_expired( (int) $post->ID ) ) {
				continue;
			}

			$posts[] = FeaturedPost::from_post( $post );
		}

		/*
		 * Plain arrays go into the cache, not serialized objects. A serialized class
		 * breaks the moment its shape changes, and every entry written before a deploy
		 * would fail to unserialize after it.
		 */
		$this->cache->set(
			$key,
			array_map(
				static function ( FeaturedPost $post ): array {
					return $post->to_array();
				},
				$posts
			)
		);

		return $posts;
	}

	/**
	 * Post IDs eligible for display, in index order.
	 *
	 * Shares the expiry rule with find() rather than reimplementing it, so a scheduled
	 * post leaves every display path at the same moment.
	 *
	 * @return int[] Eligible IDs, or a sentinel that matches nothing.
	 */
	public function eligible_ids(): array {
		$ids = array();

		foreach ( $this->index->ids() as $post_id ) {
			if ( ! $this->schedule->is_expired( $post_id ) ) {
				$ids[] = $post_id;
			}
		}

		/*
		 * post__in ignores an empty array, so an empty list would silently widen a query
		 * to every post. A sentinel that cannot match any ID forces an empty result.
		 */
		return empty( $ids ) ? array( 0 ) : $ids;
	}
}

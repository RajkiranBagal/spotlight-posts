<?php
/**
 * Cached lookup for featured posts.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Query;

use Spotlight_Posts;
use Spotlight_Posts\Index;
use Spotlight_Posts\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Object cache group for everything this plugin stores.
 */
const CACHE_GROUP = 'spotlight_posts';

/**
 * Cache key holding the current cache version number.
 */
const VERSION_KEY = 'cache_version';

/**
 * Lower bound for the number of posts a caller may request.
 */
const MIN_POSTS = 1;

/**
 * Upper bound for the number of posts a caller may request.
 *
 * Caps the meta query's result set so an untrusted caller cannot ask for an
 * unbounded scan.
 */
const MAX_POSTS = 10;

/**
 * Read the current cache version, seeding it when absent.
 *
 * Versioning the cache key means invalidation is a single integer increment
 * rather than an enumerate-and-delete over every cached permutation, which
 * matters on VIP where the object cache is shared and remote.
 *
 * @return int Current cache version.
 */
function get_cache_version(): int {
	$version = wp_cache_get( VERSION_KEY, CACHE_GROUP );

	if ( false === $version ) {
		$version = 1;
		wp_cache_set( VERSION_KEY, $version, CACHE_GROUP );
	}

	return (int) $version;
}

/**
 * Invalidate every cached featured list by moving the version forward.
 *
 * wp_cache_incr() returns false when the key is missing, in which case we seed
 * it instead. Hooked to post writes, so it takes no meaningful arguments.
 */
function bump_cache_version(): void {
	if ( false === wp_cache_incr( VERSION_KEY, 1, CACHE_GROUP ) ) {
		wp_cache_set( VERSION_KEY, 1, CACHE_GROUP );
	}
}

/**
 * Fetch the featured posts as a lightweight array.
 *
 * Returns only the fields a consumer actually renders, so we cache a small
 * payload rather than a set of full WP_Post objects.
 *
 * @param int $number_of_posts How many posts to return. Clamped to MIN_POSTS..MAX_POSTS.
 * @return array<int, array{id:int, title:string, url:string, excerpt:string}> Featured posts.
 */
function get_featured_posts( int $number_of_posts = 5 ): array {
	$number_of_posts = max( MIN_POSTS, min( MAX_POSTS, $number_of_posts ) );

	$cache_key = sprintf( 'featured_v%d_n%d', get_cache_version(), $number_of_posts );
	$cached    = wp_cache_get( $cache_key, CACHE_GROUP );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids = Index\get_ids();

	if ( empty( $ids ) ) {
		wp_cache_set( $cache_key, array(), CACHE_GROUP, 300 );

		return array();
	}

	/*
	 * A primary-key lookup, not a meta search. The index already knows which posts are
	 * featured and in what order, so this only has to fetch them.
	 *
	 * post__in carries at most MAX_IDS (100) entries and posts_per_page caps the result
	 * at MAX_POSTS (10). Filtering happens here rather than in the index because the
	 * index tracks the flag, not publication state -- a draft keeps its position and
	 * simply does not surface until it is published.
	 */
	$query = new \WP_Query(
		array(
			'post_type'              => Spotlight_Posts\supported_post_types(),
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
		/*
		 * Cron normally clears the flag at the expiry moment, which invalidates these
		 * lists. This is the safety net for a cron run that has not happened yet --
		 * checked here, before caching, so an expired post cannot be baked into the
		 * cached payload. The meta cache was primed by the query above, so it costs no
		 * additional round trip.
		 */
		if ( Schedule\is_expired( (int) $post->ID ) ) {
			continue;
		}

		$posts[] = array(
			'id'      => (int) $post->ID,
			'title'   => get_the_title( $post ),
			'url'     => (string) get_permalink( $post ),
			'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
		);
	}

	/*
	 * The TTL is written as a literal rather than a named constant so the
	 * LowExpiryCacheTime sniff can statically verify it clears VIP's 300s
	 * floor. It is only a backstop: the versioned cache key already gives us
	 * immediate invalidation whenever a post is written.
	 */
	wp_cache_set( $cache_key, $posts, CACHE_GROUP, 300 );

	return $posts;
}

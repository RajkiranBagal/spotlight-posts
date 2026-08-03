<?php
/**
 * Scheduled expiry for featured posts.
 *
 * "Feature this until Friday" is an editorial request, but it is really a caching
 * problem. Two mechanisms cover it, and each covers the other's weakness:
 *
 *   1. A single cron event fires at the expiry moment and clears the flag. That is what
 *      makes the change take effect promptly -- clearing the flag runs the same index
 *      sync as any other unfeature, so the cached lists are invalidated at the moment
 *      the post expires rather than whenever the TTL happens to lapse.
 *
 *   2. A read-time check filters expired posts out before the payload is cached. This
 *      is the safety net. WP-Cron is request-driven on stock WordPress, so on a quiet
 *      site the event can fire late; on VIP it is backed by real cron and this rarely
 *      matters. Without the read-time check a late cron would mean an expired post
 *      staying visible indefinitely.
 *
 * The residual window is bounded and worth stating plainly: if a post expires just
 * after a list is cached, and cron does not fire, it can remain visible until the
 * 300-second TTL lapses. Cron closes that in practice; the read-time check guarantees
 * it cannot outlive one TTL.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Schedule;

use VIP_Featured_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Meta key holding the expiry as a Unix timestamp.
 *
 * Stored in UTC. The editor UI converts to and from the site's timezone, because a
 * timestamp is unambiguous and a local datetime string is not.
 */
const META_KEY = '_vip_featured_until';

/**
 * Cron hook fired when a featured post reaches its expiry.
 */
const CRON_HOOK = 'vip_featured_posts_expire';

/**
 * Register the expiry as post meta.
 */
function register_meta(): void {
	register_post_meta(
		'post',
		META_KEY,
		array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => false,
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_meta',
			'auth_callback'     => __NAMESPACE__ . '\\auth_meta',
		)
	);
}

/**
 * Normalise the stored value to a non-negative timestamp.
 *
 * @param mixed $value Incoming value.
 * @return int Sanitized timestamp, or 0 for "no expiry".
 */
function sanitize_meta( $value ): int {
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
function auth_meta( $allowed, $meta_key, $object_id ): bool {
	return current_user_can( 'edit_post', (int) $object_id );
}

/**
 * Read a post's expiry.
 *
 * @param int $post_id Post to inspect.
 * @return int Unix timestamp, or 0 when the post does not expire.
 */
function get_expiry( int $post_id ): int {
	return max( 0, (int) get_post_meta( $post_id, META_KEY, true ) );
}

/**
 * Has this post's expiry passed?
 *
 * A post with no expiry never expires, so 0 is not "expired at the epoch".
 *
 * @param int $post_id Post to inspect.
 * @return bool Whether the post has expired.
 */
function is_expired( int $post_id ): bool {
	$expiry = get_expiry( $post_id );

	return $expiry > 0 && $expiry <= time();
}

/**
 * Set or clear a post's expiry, keeping the cron event in step.
 *
 * @param int $post_id   Post to schedule.
 * @param int $timestamp Unix timestamp, or 0 to clear.
 */
function set_expiry( int $post_id, int $timestamp ): void {
	unschedule( $post_id );

	if ( $timestamp <= 0 ) {
		delete_post_meta( $post_id, META_KEY );

		return;
	}

	update_post_meta( $post_id, META_KEY, $timestamp );

	// An expiry already in the past is applied immediately rather than scheduled for a
	// moment that will never arrive.
	if ( $timestamp <= time() ) {
		expire( $post_id );

		return;
	}

	wp_schedule_single_event( $timestamp, CRON_HOOK, array( $post_id ) );
}

/**
 * Cancel any pending expiry event for a post.
 *
 * @param int $post_id Post to unschedule.
 */
function unschedule( int $post_id ): void {
	$next = wp_next_scheduled( CRON_HOOK, array( $post_id ) );

	while ( false !== $next ) {
		wp_unschedule_event( $next, CRON_HOOK, array( $post_id ) );

		$next = wp_next_scheduled( CRON_HOOK, array( $post_id ) );
	}
}

/**
 * Unfeature a post whose expiry has arrived.
 *
 * The flag is cleared through delete_post_meta rather than by editing the index, so
 * this takes exactly the same path as any other unfeature: the index sync removes it
 * and invalidates the cached lists.
 *
 * @param int $post_id Post to expire.
 */
function expire( int $post_id ): void {
	delete_post_meta( $post_id, VIP_Featured_Posts\META_KEY );
	delete_post_meta( $post_id, META_KEY );
}

/**
 * Cron callback.
 *
 * Untyped, because a scheduled event's arguments come back out of the database and a
 * malformed one should fall through rather than raise a TypeError inside cron.
 *
 * @param mixed $post_id Post to expire.
 */
function handle_cron( $post_id = 0 ): void {
	$post_id = (int) $post_id;

	if ( $post_id > 0 ) {
		expire( $post_id );
	}
}

/**
 * Drop a pending expiry when a post stops being featured.
 *
 * Without this, unfeaturing and re-featuring a post inside the original window would
 * leave the old event armed, and the post would silently expire early.
 *
 * @param int[] $meta_ids  Meta row IDs. Unused.
 * @param int   $object_id Post the meta belonged to.
 * @param mixed $meta_key  Meta key that was deleted.
 */
function clear_on_unfeature( $meta_ids, $object_id, $meta_key ): void {
	if ( VIP_Featured_Posts\META_KEY !== $meta_key ) {
		return;
	}

	$post_id = (int) $object_id;

	unschedule( $post_id );
	delete_post_meta( $post_id, META_KEY );
}

/**
 * Drop a pending expiry when a post is deleted.
 *
 * @param int $post_id Post that was deleted.
 */
function clear_on_delete( int $post_id ): void {
	unschedule( $post_id );
}

/**
 * Invalidate the cached lists when an expiry is written.
 *
 * Changing when a post expires changes what the lists will contain, so it has to
 * invalidate exactly like changing the flag itself does. Without this the read-time
 * expiry check never runs, because a cache hit returns before reaching it -- an expiry
 * set to a moment already past would leave the post visible until the TTL lapsed.
 *
 * @param int|int[] $meta_id   Meta row ID, or IDs when deleting. Unused.
 * @param int       $object_id Post the meta belongs to. Unused.
 * @param mixed     $meta_key  Meta key that was written.
 */
function invalidate_on_expiry_change( $meta_id, $object_id, $meta_key ): void {
	if ( META_KEY !== $meta_key ) {
		return;
	}

	VIP_Featured_Posts\Query\bump_cache_version();
}

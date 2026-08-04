<?php
/**
 * Tests for scheduled expiry.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts;
use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Featured\Repository;
use Spotlight_Posts\Featured\Schedule;

/**
 * @covers \Spotlight_Posts\Schedule
 */
class ScheduleTest extends TestCase {

	/**
	 * Clear any events left armed by a test.
	 */
	public function tear_down(): void {
		_set_cron_array( array() );

		parent::tear_down();
	}

	/**
	 * A post with no expiry never expires. 0 is "no expiry", not "the epoch".
	 */
	public function test_a_post_without_an_expiry_never_expires(): void {
		$post_id = $this->create_featured_post();

		$this->assertSame( 0, \Spotlight_Posts\schedule()->expiry_for( $post_id ) );
		$this->assertFalse( \Spotlight_Posts\schedule()->is_expired( $post_id ) );
	}

	/**
	 * A future expiry is stored and armed.
	 */
	public function test_future_expiry_is_stored_and_scheduled(): void {
		$post_id = $this->create_featured_post();
		$when    = time() + HOUR_IN_SECONDS;

		\Spotlight_Posts\schedule()->set_expiry( $post_id, $when );

		$this->assertSame( $when, \Spotlight_Posts\schedule()->expiry_for( $post_id ) );
		$this->assertFalse( \Spotlight_Posts\schedule()->is_expired( $post_id ) );
		$this->assertSame( $when, wp_next_scheduled( Schedule::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * Clearing the expiry removes both the value and the pending event.
	 */
	public function test_clearing_the_expiry_unschedules_it(): void {
		$post_id = $this->create_featured_post();

		\Spotlight_Posts\schedule()->set_expiry( $post_id, time() + HOUR_IN_SECONDS );
		\Spotlight_Posts\schedule()->set_expiry( $post_id, 0 );

		$this->assertSame( 0, \Spotlight_Posts\schedule()->expiry_for( $post_id ) );
		$this->assertFalse( wp_next_scheduled( Schedule::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * Changing the expiry must not leave the previous event armed, or the post would
	 * expire at the old time.
	 */
	public function test_rescheduling_replaces_the_previous_event(): void {
		$post_id = $this->create_featured_post();

		$first  = time() + HOUR_IN_SECONDS;
		$second = time() + ( 2 * HOUR_IN_SECONDS );

		\Spotlight_Posts\schedule()->set_expiry( $post_id, $first );
		\Spotlight_Posts\schedule()->set_expiry( $post_id, $second );

		$this->assertSame( $second, wp_next_scheduled( Schedule::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * An expiry already in the past is applied immediately rather than scheduled for a
	 * moment that will never arrive.
	 */
	public function test_past_expiry_is_applied_immediately(): void {
		$post_id = $this->create_featured_post();

		\Spotlight_Posts\schedule()->set_expiry( $post_id, time() - HOUR_IN_SECONDS );

		$this->assertSame( '', get_post_meta( $post_id, Index::META_KEY, true ) );
		$this->assertNotContains( $post_id, \Spotlight_Posts\index()->ids() );
		$this->assertFalse( wp_next_scheduled( Schedule::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * The cron callback unfeatures the post and clears its expiry.
	 */
	public function test_cron_callback_unfeatures_the_post(): void {
		$post_id = $this->create_featured_post();

		\Spotlight_Posts\schedule()->set_expiry( $post_id, time() + HOUR_IN_SECONDS );

		$this->assertContains( $post_id, \Spotlight_Posts\index()->ids() );

		\Spotlight_Posts\schedule()->handle_cron( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Index::META_KEY, true ) );
		$this->assertSame( 0, \Spotlight_Posts\schedule()->expiry_for( $post_id ) );
		$this->assertNotContains( $post_id, \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Expiring invalidates the cached lists, because it routes through the same meta
	 * delete as any other unfeature.
	 */
	public function test_expiring_invalidates_the_cache(): void {
		$keeper  = $this->create_featured_post( 'Keeper' );
		$expirer = $this->create_featured_post( 'Expirer' );

		$this->assertCount( 2, \Spotlight_Posts\repository()->find( 5 ) );

		\Spotlight_Posts\schedule()->handle_cron( $expirer );

		$ids = wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' );

		$this->assertSame( array( $keeper ), $ids );
	}

	/**
	 * A malformed cron argument must not fatal inside cron.
	 */
	public function test_cron_callback_tolerates_bad_arguments(): void {
		\Spotlight_Posts\schedule()->handle_cron( 0 );
		\Spotlight_Posts\schedule()->handle_cron( 'not-an-id' );

		$this->assertTrue( true, 'Reached without a TypeError.' );
	}

	/**
	 * Unfeaturing by hand clears a pending expiry.
	 *
	 * Otherwise unfeaturing and re-featuring inside the original window would leave the
	 * old event armed, and the post would silently expire early.
	 */
	public function test_unfeaturing_clears_a_pending_expiry(): void {
		$post_id = $this->create_featured_post();

		\Spotlight_Posts\schedule()->set_expiry( $post_id, time() + HOUR_IN_SECONDS );

		delete_post_meta( $post_id, Index::META_KEY );

		$this->assertFalse( wp_next_scheduled( Schedule::CRON_HOOK, array( $post_id ) ) );
		$this->assertSame( 0, \Spotlight_Posts\schedule()->expiry_for( $post_id ) );
	}

	/**
	 * The read path filters expired posts even if cron has not run.
	 *
	 * This is the safety net: the meta is written directly so no scheduling happens,
	 * simulating an event that fired late or not at all.
	 */
	public function test_read_path_hides_expired_posts_without_cron(): void {
		$live    = $this->create_featured_post( 'Live' );
		$expired = $this->create_featured_post( 'Expired' );

		update_post_meta( $expired, Schedule::META_KEY, time() - 60 );

		$ids = wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' );

		$this->assertContains( $live, $ids );
		$this->assertNotContains( $expired, $ids, 'An expired post must not surface even before cron runs.' );
	}

	/**
	 * An expired post must never be baked into the cached payload.
	 */
	public function test_expired_posts_are_filtered_before_caching(): void {
		$expired = $this->create_featured_post( 'Expired' );

		update_post_meta( $expired, Schedule::META_KEY, time() - 60 );

		// Populate the cache, then read it back.
		\Spotlight_Posts\repository()->find( 5 );

		$this->assertSame( array(), \Spotlight_Posts\repository()->find( 5 ) );
	}

	/**
	 * Writing an expiry invalidates the cached lists.
	 *
	 * Regression guard. The read-time expiry check only runs on a cache miss, so
	 * without this the flow was: cache the list, set an expiry in the past, and the
	 * post stayed visible until the TTL lapsed because the cache hit returned before
	 * the check was ever reached. Found against the running site, not in a unit test.
	 */
	public function test_writing_an_expiry_invalidates_the_cache(): void {
		$keeper  = $this->create_featured_post( 'Keeper' );
		$expirer = $this->create_featured_post( 'Expirer' );

		// Populate the cache while both are live.
		$this->assertCount( 2, \Spotlight_Posts\repository()->find( 5 ) );

		update_post_meta( $expirer, Schedule::META_KEY, time() - 60 );

		$this->assertSame(
			array( $keeper ),
			wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' ),
			'An expiry written after the list was cached must take effect immediately.'
		);
	}

	/**
	 * Clearing an expiry brings the post back without waiting for the TTL.
	 */
	public function test_clearing_an_expiry_invalidates_the_cache(): void {
		$post_id = $this->create_featured_post();

		update_post_meta( $post_id, Schedule::META_KEY, time() - 60 );

		$this->assertSame( array(), \Spotlight_Posts\repository()->find( 5 ) );

		\Spotlight_Posts\schedule()->set_expiry( $post_id, 0 );

		$this->assertSame(
			array( $post_id ),
			wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' )
		);
	}

	/**
	 * A post that expires in the future still surfaces.
	 */
	public function test_future_expiry_does_not_hide_the_post(): void {
		$post_id = $this->create_featured_post();

		\Spotlight_Posts\schedule()->set_expiry( $post_id, time() + DAY_IN_SECONDS );

		$this->assertSame(
			array( $post_id ),
			wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' )
		);
	}

	/**
	 * Deleting a post cancels its pending expiry.
	 */
	public function test_deleting_a_post_unschedules_its_expiry(): void {
		$post_id = $this->create_featured_post();

		\Spotlight_Posts\schedule()->set_expiry( $post_id, time() + HOUR_IN_SECONDS );

		wp_delete_post( $post_id, true );

		$this->assertFalse( wp_next_scheduled( Schedule::CRON_HOOK, array( $post_id ) ) );
	}

	/**
	 * The stored value is always a non-negative integer.
	 */
	public function test_sanitize_normalises_the_stored_value(): void {
		$this->assertSame( 0, \Spotlight_Posts\schedule()->sanitize_meta( -5 ) );
		$this->assertSame( 0, \Spotlight_Posts\schedule()->sanitize_meta( 'nonsense' ) );
		$this->assertSame( 1234567890, \Spotlight_Posts\schedule()->sanitize_meta( '1234567890' ) );
	}
}

<?php
/**
 * Tests for topping up a short spotlight list with recent posts.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts\Featured\Index;

/**
 * @covers \Spotlight_Posts\Featured\Repository
 */
class FallbackFillTest extends TestCase {

	/**
	 * Filling is off unless asked for, so existing content is unaffected.
	 */
	public function test_filling_is_opt_in(): void {
		$featured = $this->create_featured_post( 'Featured' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame(
			array( $featured ),
			wp_list_pluck( $this->repository()->find( 5 ), 'id' )
		);
	}

	/**
	 * A short list is topped up to the requested count.
	 */
	public function test_short_list_is_topped_up(): void {
		$featured = $this->create_featured_post( 'Featured' );

		for ( $i = 0; $i < 5; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}

		$ids = wp_list_pluck( $this->repository()->find( 3, true ), 'id' );

		$this->assertCount( 3, $ids );
		$this->assertSame( $featured, $ids[0], 'Spotlighted posts still lead.' );
	}

	/**
	 * Top-ups never duplicate a post already on show.
	 */
	public function test_fill_excludes_posts_already_listed(): void {
		$featured = $this->create_featured_post( 'Featured' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$ids = wp_list_pluck( $this->repository()->find( 5, true ), 'id' );

		$this->assertSame( array_unique( $ids ), $ids, 'No post should appear twice.' );
		$this->assertContains( $featured, $ids );
	}

	/**
	 * A full list is left alone.
	 */
	public function test_full_list_is_not_topped_up(): void {
		$first  = $this->create_featured_post( 'One' );
		$second = $this->create_featured_post( 'Two' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame(
			array( $second, $first ),
			wp_list_pluck( $this->repository()->find( 2, true ), 'id' )
		);
	}

	/**
	 * With nothing spotlighted at all, filling still produces a list.
	 *
	 * The empty-index path returns early, so it needs the top-up too or a site that has
	 * featured nothing yet renders an empty section rather than recent posts.
	 */
	public function test_empty_index_is_filled(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->post->create( array( 'post_status' => 'publish' ) );
		}

		$this->assertCount( 3, $this->repository()->find( 3, true ) );
		$this->assertSame( array(), $this->repository()->find( 3 ) );
	}

	/**
	 * Drafts are never used as filler.
	 */
	public function test_fill_uses_published_posts_only(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$ids = wp_list_pluck( $this->repository()->find( 5, true ), 'id' );

		$this->assertNotContains( $draft, $ids );
	}

	/**
	 * Filled and unfilled results are cached separately.
	 *
	 * Sharing a key would serve one for the other, and which you got would depend on
	 * whichever block rendered first.
	 */
	public function test_filled_and_unfilled_results_do_not_share_a_cache_key(): void {
		$featured = $this->create_featured_post( 'Featured' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$unfilled = wp_list_pluck( $this->repository()->find( 3 ), 'id' );
		$filled   = wp_list_pluck( $this->repository()->find( 3, true ), 'id' );

		$this->assertSame( array( $featured ), $unfilled );
		$this->assertCount( 2, $filled );
	}

	/**
	 * Filler respects the supported post types.
	 */
	public function test_fill_respects_supported_post_types(): void {
		register_post_type( 'guide', array( 'public' => true, 'label' => 'Guides' ) );

		$guide = self::factory()->post->create(
			array(
				'post_type'   => 'guide',
				'post_status' => 'publish',
			)
		);

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$ids = wp_list_pluck( $this->repository()->find( 5, true ), 'id' );

		unregister_post_type( 'guide' );

		$this->assertNotContains( $guide, $ids, 'An unsupported type must not be used as filler.' );
	}

	/**
	 * Publishing a post invalidates a filled list.
	 *
	 * Filling depends on what is recent, so a new post has to be able to appear without
	 * waiting for the TTL. No new hook is needed -- save_post already flushes.
	 */
	public function test_publishing_invalidates_a_filled_list(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertCount( 1, $this->repository()->find( 3, true ) );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertCount( 2, $this->repository()->find( 3, true ) );
	}

	/**
	 * Expired spotlighted posts are replaced rather than leaving a gap.
	 */
	public function test_expired_posts_are_replaced_by_filler(): void {
		$expired = $this->create_featured_post( 'Expired' );
		update_post_meta( $expired, \Spotlight_Posts\Featured\Schedule::META_KEY, time() - 60 );

		$recent = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$ids = wp_list_pluck( $this->repository()->find( 1, true ), 'id' );

		$this->assertNotContains( $expired, $ids );
		$this->assertSame( array( $recent ), $ids );
	}

	/**
	 * The index option is untouched by filling.
	 *
	 * Filler is a display concern. Writing it into the index would make recent posts
	 * indistinguishable from curated ones the next time anyone looked.
	 */
	public function test_filling_does_not_write_to_the_index(): void {
		$featured = $this->create_featured_post( 'Featured' );

		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->repository()->find( 5, true );

		$this->assertSame( array( $featured ), $this->index()->ids() );
		$this->assertSame( array( $featured ), get_option( Index::OPTION ) );
	}
}

<?php
/**
 * Tests for the cached featured-posts query.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Tests;

use VIP_Featured_Posts;
use VIP_Featured_Posts\Index;
use VIP_Featured_Posts\Query;

/**
 * @covers \VIP_Featured_Posts\Query
 */
class QueryTest extends TestCase {

	/**
	 * Requests below the floor are raised to it rather than returning nothing.
	 */
	public function test_count_is_clamped_to_the_floor(): void {
		$this->create_featured_post( 'One' );
		$this->create_featured_post( 'Two' );

		$this->assertCount( Query\MIN_POSTS, Query\get_featured_posts( 0 ) );
		$this->assertCount( Query\MIN_POSTS, Query\get_featured_posts( -10 ) );
	}

	/**
	 * Requests above the ceiling are capped, so no caller can widen the query.
	 */
	public function test_count_is_clamped_to_the_ceiling(): void {
		for ( $i = 0; $i < Query\MAX_POSTS + 3; $i++ ) {
			$this->create_featured_post( 'Post ' . $i );
		}

		$this->assertCount( Query\MAX_POSTS, Query\get_featured_posts( 500 ) );
	}

	/**
	 * The result follows index order, not post date.
	 */
	public function test_results_follow_index_order(): void {
		$a = $this->create_featured_post( 'A' );
		$b = $this->create_featured_post( 'B' );
		$c = $this->create_featured_post( 'C' );

		Index\set_ids( array( $b, $c, $a ) );

		$ids = wp_list_pluck( Query\get_featured_posts( 5 ), 'id' );

		$this->assertSame( array( $b, $c, $a ), $ids );
	}

	/**
	 * Only published posts surface, even though drafts stay indexed.
	 */
	public function test_only_published_posts_are_returned(): void {
		$published = $this->create_featured_post( 'Published' );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $draft, VIP_Featured_Posts\META_KEY, '1' );

		$ids = wp_list_pluck( Query\get_featured_posts( 5 ), 'id' );

		$this->assertContains( $published, $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertContains( $draft, Index\get_ids(), 'The draft should keep its index position.' );
	}

	/**
	 * A drafted post returns to its original position when republished.
	 */
	public function test_a_republished_post_keeps_its_position(): void {
		$a = $this->create_featured_post( 'A' );
		$b = $this->create_featured_post( 'B' );

		Index\set_ids( array( $a, $b ) );

		wp_update_post(
			array(
				'ID'          => $a,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array( $b ), wp_list_pluck( Query\get_featured_posts( 5 ), 'id' ) );

		wp_update_post(
			array(
				'ID'          => $a,
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			array( $a, $b ),
			wp_list_pluck( Query\get_featured_posts( 5 ), 'id' ),
			'The republished post should return to the front, where it was indexed.'
		);
	}

	/**
	 * A second identical call must be served from cache without touching the database.
	 */
	public function test_second_call_is_served_from_cache(): void {
		$this->create_featured_post( 'Cached' );

		Query\get_featured_posts( 5 );

		$queries_before = get_num_queries();
		Query\get_featured_posts( 5 );

		$this->assertSame(
			$queries_before,
			get_num_queries(),
			'A cache hit should issue no queries.'
		);
	}

	/**
	 * Featuring a post through a bare meta write must invalidate the cache.
	 *
	 * This is the regression guard for the original bug: save_post never fired, so the
	 * cached list stayed stale.
	 */
	public function test_meta_write_invalidates_the_cache(): void {
		$first = $this->create_featured_post( 'First' );

		$this->assertSame( array( $first ), wp_list_pluck( Query\get_featured_posts( 5 ), 'id' ) );

		$second = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $second, VIP_Featured_Posts\META_KEY, '1' );

		$this->assertSame(
			array( $second, $first ),
			wp_list_pluck( Query\get_featured_posts( 5 ), 'id' ),
			'The newly featured post should appear immediately.'
		);
	}

	/**
	 * Unfeaturing must take effect immediately too.
	 */
	public function test_meta_delete_invalidates_the_cache(): void {
		$post_id = $this->create_featured_post();

		$this->assertCount( 1, Query\get_featured_posts( 5 ) );

		delete_post_meta( $post_id, VIP_Featured_Posts\META_KEY );

		$this->assertSame( array(), Query\get_featured_posts( 5 ) );
	}

	/**
	 * Editing a featured post refreshes the cached payload.
	 */
	public function test_editing_a_post_refreshes_the_cached_payload(): void {
		$post_id = $this->create_featured_post( 'Before' );

		$this->assertSame( 'Before', Query\get_featured_posts( 5 )[0]['title'] );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'After',
			)
		);

		$this->assertSame( 'After', Query\get_featured_posts( 5 )[0]['title'] );
	}

	/**
	 * An empty index short-circuits without querying for posts.
	 */
	public function test_empty_index_returns_an_empty_array(): void {
		$this->assertSame( array(), Query\get_featured_posts( 5 ) );
	}

	/**
	 * The returned shape is the light array the block and REST route both rely on.
	 */
	public function test_returned_shape(): void {
		$this->create_featured_post( 'Shape' );

		$posts = Query\get_featured_posts( 5 );

		$this->assertArrayHasKey( 'id', $posts[0] );
		$this->assertArrayHasKey( 'title', $posts[0] );
		$this->assertArrayHasKey( 'url', $posts[0] );
		$this->assertArrayHasKey( 'excerpt', $posts[0] );
		$this->assertIsInt( $posts[0]['id'] );
	}
}

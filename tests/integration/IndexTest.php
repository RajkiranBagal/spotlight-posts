<?php
/**
 * Tests for the ordered ID index.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts;
use Spotlight_Posts\Featured\Index;

/**
 * @covers \Spotlight_Posts\Index
 */
class IndexTest extends TestCase {

	/**
	 * Newly featured posts lead, so the default order is most-recently-featured first.
	 */
	public function test_add_prepends(): void {
		$first  = self::factory()->post->create();
		$second = self::factory()->post->create();

		\Spotlight_Posts\index()->add( $first );
		\Spotlight_Posts\index()->add( $second );

		$this->assertSame( array( $second, $first ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Adding a post already in the index must not duplicate it or move it.
	 */
	public function test_add_is_idempotent(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();

		\Spotlight_Posts\index()->add( $a );
		\Spotlight_Posts\index()->add( $b );
		\Spotlight_Posts\index()->add( $a );

		$this->assertSame( array( $b, $a ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Removing takes the post out and leaves the rest in order.
	 */
	public function test_remove(): void {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();
		$c = self::factory()->post->create();

		\Spotlight_Posts\index()->set( array( $a, $b, $c ) );
		\Spotlight_Posts\index()->remove( $b );

		$this->assertSame( array( $a, $c ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Removing something that was never indexed changes nothing.
	 */
	public function test_remove_unknown_post_is_a_noop(): void {
		$a = self::factory()->post->create();

		\Spotlight_Posts\index()->set( array( $a ) );

		$version_before = \Spotlight_Posts\Plugin::instance()->get( \Spotlight_Posts\Support\Cache::class )->version();
		\Spotlight_Posts\index()->remove( 999999 );

		$this->assertSame( array( $a ), \Spotlight_Posts\index()->ids() );
		$this->assertSame(
			$version_before,
			\Spotlight_Posts\Plugin::instance()->get( \Spotlight_Posts\Support\Cache::class )->version(),
			'A no-op removal must not invalidate the cache.'
		);
	}

	/**
	 * The index is capped, because it is autoloaded into alloptions on every request.
	 */
	public function test_index_is_capped(): void {
		\Spotlight_Posts\index()->set( range( 1, Index::MAX_IDS + 50 ) );

		$this->assertCount( Index::MAX_IDS, \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Duplicate IDs are collapsed on write.
	 */
	public function test_duplicates_are_collapsed(): void {
		\Spotlight_Posts\index()->set( array( 5, 7, 5, 7, 9 ) );

		$this->assertSame( array( 5, 7, 9 ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * A meta write puts the post into the index without any save_post involvement.
	 */
	public function test_meta_write_adds_to_index(): void {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, Index::META_KEY, '1' );

		$this->assertSame( array( $post_id ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Clearing the flag by value removes the post.
	 */
	public function test_meta_write_of_empty_value_removes_from_index(): void {
		$post_id = $this->create_featured_post();

		$this->assertSame( array( $post_id ), \Spotlight_Posts\index()->ids() );

		update_post_meta( $post_id, Index::META_KEY, '' );

		$this->assertSame( array(), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Deleting the meta removes the post.
	 *
	 * Regression guard: deleted_post_meta passes the outgoing value as its fourth
	 * argument, so sharing a callback with the write hooks would read '1' and re-add
	 * the post it was meant to drop.
	 */
	public function test_meta_delete_removes_from_index(): void {
		$post_id = $this->create_featured_post();

		delete_post_meta( $post_id, Index::META_KEY );

		$this->assertSame( array(), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Writes to other meta keys must be ignored, or the cache would be shredded by
	 * every unrelated meta update on the site.
	 */
	public function test_unrelated_meta_key_is_ignored(): void {
		$post_id = self::factory()->post->create();

		$version_before = \Spotlight_Posts\Plugin::instance()->get( \Spotlight_Posts\Support\Cache::class )->version();

		update_post_meta( $post_id, '_something_else', 'value' );

		$this->assertSame( array(), \Spotlight_Posts\index()->ids() );
		$this->assertSame( $version_before, \Spotlight_Posts\Plugin::instance()->get( \Spotlight_Posts\Support\Cache::class )->version() );
	}

	/**
	 * Permanently deleting a post drops it from the index.
	 */
	public function test_deleting_a_post_removes_it_from_the_index(): void {
		$post_id = $this->create_featured_post();

		wp_delete_post( $post_id, true );

		$this->assertNotContains( $post_id, \Spotlight_Posts\index()->ids() );
	}

	/**
	 * Rebuilding recovers the correct index from meta, whatever state it was in.
	 */
	public function test_rebuild_repairs_a_corrupt_index(): void {
		$post_id = $this->create_featured_post();

		update_option( Index::OPTION, array( 999998, 999999 ) );

		$rebuilt = \Spotlight_Posts\index()->rebuild();

		$this->assertSame( array( $post_id ), $rebuilt );
		$this->assertSame( array( $post_id ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * An absent option means "never built" and triggers a one-time rebuild, which is
	 * what lets the plugin be activated on a site whose posts are already flagged.
	 */
	public function test_absent_option_triggers_a_rebuild(): void {
		$post_id = $this->create_featured_post();

		delete_option( Index::OPTION );

		$this->assertSame( array( $post_id ), \Spotlight_Posts\index()->ids() );
	}

	/**
	 * An empty array means "built, and genuinely empty" — it must not rebuild.
	 */
	public function test_empty_index_does_not_trigger_a_rebuild(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Flagged in meta, but deliberately absent from the index.
		add_post_meta( $post_id, Index::META_KEY, '1' );
		update_option( Index::OPTION, array() );

		$this->assertSame(
			array(),
			\Spotlight_Posts\index()->ids(),
			'An empty index is a valid state and must be trusted, not rebuilt.'
		);
	}

	/**
	 * Posts are indexed regardless of status, so a draft keeps its place.
	 */
	public function test_drafts_are_indexed(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		update_post_meta( $post_id, Index::META_KEY, '1' );
		\Spotlight_Posts\index()->rebuild();

		$this->assertContains( $post_id, \Spotlight_Posts\index()->ids() );
	}
}

<?php
/**
 * Shared base for the integration tests.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts\Featured\Index;

/**
 * Resets the plugin's persistent state between tests.
 *
 * The object cache and the index option both outlive a single test method, so without
 * this a passing test could be leaning on state a previous one left behind.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset the cache and index before every test.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_cache_flush();

		// Written directly rather than through set_ids(), so the starting state is a
		// known-empty index rather than one rebuilt from whatever meta exists.
		update_option( Index::OPTION, array() );
	}

	/**
	 * Create a published post and mark it featured.
	 *
	 * @param string $title Post title.
	 * @return int Post ID.
	 */
	protected function create_featured_post( string $title = 'Featured' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, Index::META_KEY, '1' );

		return $post_id;
	}
}

<?php
/**
 * Tests for the Query Loop variation.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Frontend\QueryLoopVariation;
use Spotlight_Posts\Featured\Schedule;

/**
 * @covers \Spotlight_Posts\Query_Loop
 */
class QueryLoopTest extends TestCase {

	/**
	 * Build a Post Template block carrying the given query context.
	 *
	 * This mirrors what core hands to query_loop_block_query_vars: the filter receives
	 * the Post Template, whose context comes from the parent Query block.
	 *
	 * @param array $query Query context.
	 * @return \WP_Block Block with that context.
	 */
	/**
	 * The registered variation service.
	 *
	 * @return QueryLoopVariation Variation service.
	 */
	private function variation(): QueryLoopVariation {
		return \Spotlight_Posts\Plugin::instance()->get( QueryLoopVariation::class );
	}

	private function template_block( array $query ): \WP_Block {
		return new \WP_Block(
			array(
				'blockName' => 'core/post-template',
				'attrs'     => array(),
			),
			array( 'query' => $query )
		);
	}

	/**
	 * A block belonging to our variation.
	 */
	private function featured_block(): \WP_Block {
		return $this->template_block( array( 'namespace' => QueryLoopVariation::VARIATION ) );
	}

	/**
	 * Eligible IDs follow the index order.
	 */
	public function test_eligible_ids_follow_index_order(): void {
		$a = $this->create_featured_post( 'A' );
		$b = $this->create_featured_post( 'B' );

		\Spotlight_Posts\index()->set( array( $b, $a ) );

		$this->assertSame( array( $b, $a ), \Spotlight_Posts\repository()->eligible_ids() );
	}

	/**
	 * Expired posts are excluded, so the Query Loop and the dedicated block cannot
	 * disagree about what is featured.
	 */
	public function test_expired_posts_are_excluded(): void {
		$live    = $this->create_featured_post( 'Live' );
		$expired = $this->create_featured_post( 'Expired' );

		update_post_meta( $expired, Schedule::META_KEY, time() - 60 );

		$ids = \Spotlight_Posts\repository()->eligible_ids();

		$this->assertContains( $live, $ids );
		$this->assertNotContains( $expired, $ids );
	}

	/**
	 * An empty featured list must match nothing, not everything.
	 *
	 * post__in ignores an empty array, so without a sentinel the variation would widen
	 * to every post on the site.
	 */
	public function test_empty_index_yields_a_sentinel(): void {
		$this->assertSame( array( 0 ), \Spotlight_Posts\repository()->eligible_ids() );
	}

	/**
	 * The variation is recognised from the query context.
	 */
	public function test_variation_is_detected(): void {
		$this->assertTrue( $this->variation()->is_featured_variation( $this->featured_block() ) );
	}

	/**
	 * Any other Query Loop is not ours.
	 */
	public function test_other_query_loops_are_not_detected(): void {
		$this->assertFalse( $this->variation()->is_featured_variation( $this->template_block( array() ) ) );
		$this->assertFalse( $this->variation()->is_featured_variation( $this->template_block( array( 'namespace' => 'someone/else' ) ) ) );
		$this->assertFalse( $this->variation()->is_featured_variation( null ) );
	}

	/**
	 * Our variation is constrained to the featured posts, in index order.
	 */
	public function test_query_vars_are_constrained(): void {
		$a = $this->create_featured_post( 'A' );
		$b = $this->create_featured_post( 'B' );

		\Spotlight_Posts\index()->set( array( $b, $a ) );

		$vars = $this->variation()->filter_query_vars(
			array( 'post_type' => 'post', 'order' => 'DESC' ),
			$this->featured_block(),
			1
		);

		$this->assertSame( array( $b, $a ), $vars['post__in'] );
		$this->assertSame( 'post__in', $vars['orderby'] );
		$this->assertArrayNotHasKey( 'order', $vars, 'A stale direction makes post__in ordering look arbitrary.' );
	}

	/**
	 * Core's own controls survive: only the post set and order are touched.
	 */
	public function test_other_query_vars_are_preserved(): void {
		$this->create_featured_post();

		$vars = $this->variation()->filter_query_vars(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 3,
				'offset'         => 6,
			),
			$this->featured_block(),
			2
		);

		$this->assertSame( 3, $vars['posts_per_page'] );
		$this->assertSame( 6, $vars['offset'] );
	}

	/**
	 * A Query Loop that is not ours passes through untouched.
	 */
	public function test_unrelated_query_loops_are_untouched(): void {
		$this->create_featured_post();

		$original = array( 'post_type' => 'post', 'order' => 'DESC' );
		$vars     = $this->variation()->filter_query_vars( $original, $this->template_block( array() ), 1 );

		$this->assertSame( $original, $vars );
	}

	/**
	 * The editor's REST request is constrained when it asks for featured posts.
	 */
	public function test_rest_query_is_constrained_when_requested(): void {
		$post_id = $this->create_featured_post();

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'spotlight_featured', true );

		$args = $this->variation()->filter_rest_query( array( 'post_type' => 'post' ), $request );

		$this->assertSame( array( $post_id ), $args['post__in'] );
		$this->assertSame( 'post__in', $args['orderby'] );
	}

	/**
	 * Without the parameter, ordinary editor requests are untouched.
	 */
	public function test_rest_query_is_untouched_without_the_param(): void {
		$this->create_featured_post();

		$request  = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$original = array( 'post_type' => 'post' );

		$this->assertSame( $original, $this->variation()->filter_rest_query( $original, $request ) );
	}

	/**
	 * The collection parameter is advertised, so the editor may send it.
	 */
	public function test_rest_collection_param_is_registered(): void {
		$params = $this->variation()->add_rest_collection_param( array() );

		$this->assertArrayHasKey( 'spotlight_featured', $params );
		$this->assertSame( 'boolean', $params['spotlight_featured']['type'] );
		$this->assertFalse( $params['spotlight_featured']['default'] );
	}
}

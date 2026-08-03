<?php
/**
 * Tests for the public REST route.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Tests;

use VIP_Featured_Posts;
use VIP_Featured_Posts\Query;
use VIP_Featured_Posts\REST;

/**
 * @covers \VIP_Featured_Posts\REST
 */
class RestTest extends TestCase {

	/**
	 * Route under test.
	 *
	 * @var string
	 */
	private string $route;

	/**
	 * Boot the REST server so routes register.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->route = '/' . REST\REST_NAMESPACE . REST\ROUTE;

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Dispatch a GET against the route.
	 *
	 * @param array $params Query parameters.
	 * @return \WP_REST_Response Response.
	 */
	private function get( array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', $this->route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The route is registered where consumers expect it.
	 */
	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( $this->route, rest_get_server()->get_routes() );
	}

	/**
	 * Anonymous callers are allowed, because the data is already public.
	 */
	public function test_route_is_publicly_readable(): void {
		wp_set_current_user( 0 );

		$this->create_featured_post( 'Public' );

		$response = $this->get();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	/**
	 * Only published posts are exposed — the guarantee that makes __return_true safe.
	 */
	public function test_unpublished_posts_are_never_exposed(): void {
		$published = $this->create_featured_post( 'Published' );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $draft, VIP_Featured_Posts\META_KEY, '1' );

		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		update_post_meta( $private, VIP_Featured_Posts\META_KEY, '1' );

		$ids = wp_list_pluck( $this->get()->get_data(), 'id' );

		$this->assertSame( array( $published ), $ids );
	}

	/**
	 * The default count applies when the parameter is omitted.
	 */
	public function test_default_count(): void {
		for ( $i = 0; $i < 8; $i++ ) {
			$this->create_featured_post( 'Post ' . $i );
		}

		$this->assertCount( 5, $this->get()->get_data() );
	}

	/**
	 * A valid count is honoured.
	 */
	public function test_valid_count_is_honoured(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_featured_post( 'Post ' . $i );
		}

		$this->assertCount( 2, $this->get( array( 'count' => 2 ) )->get_data() );
	}

	/**
	 * Out-of-range and non-numeric counts are rejected with a 400.
	 *
	 * Note that -3 is rejected rather than coerced to 3: validate_callback runs before
	 * sanitize_callback, so absint() never gets the chance to paper over it.
	 *
	 * @dataProvider data_invalid_counts
	 *
	 * @param mixed $count Value to send.
	 */
	public function test_invalid_counts_are_rejected( $count ): void {
		$response = $this->get( array( 'count' => $count ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Counts that must be refused.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_invalid_counts(): array {
		return array(
			'zero'            => array( 0 ),
			'above ceiling'   => array( Query\MAX_POSTS + 1 ),
			'far too large'   => array( 99999 ),
			'negative'        => array( -3 ),
			'non-numeric'     => array( 'abc' ),
		);
	}

	/**
	 * Boundary values on both ends are accepted.
	 */
	public function test_boundaries_are_accepted(): void {
		$this->create_featured_post();

		$this->assertSame( 200, $this->get( array( 'count' => Query\MIN_POSTS ) )->get_status() );
		$this->assertSame( 200, $this->get( array( 'count' => Query\MAX_POSTS ) )->get_status() );
	}
}

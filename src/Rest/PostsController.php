<?php
/**
 * Public REST endpoint exposing the spotlighted posts.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Rest;

use Spotlight_Posts\Featured\Repository;
use Spotlight_Posts\Registrable;

defined( 'ABSPATH' ) || exit;

/**
 * Serves GET /wp-json/spotlight/v1/posts.
 */
final class PostsController implements Registrable {

	/**
	 * REST namespace for this plugin.
	 */
	public const REST_NAMESPACE = 'spotlight/v1';

	/**
	 * Route exposing the collection.
	 */
	public const ROUTE = '/posts';

	/**
	 * Source of the spotlighted posts.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * @param Repository $repository Source of the spotlighted posts.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declare the route and its arguments.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'  => \WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_items' ),
				/*
				 * __return_true is correct here rather than an oversight. The endpoint is
				 * read-only and returns nothing that is not already public: the query is
				 * pinned to post_status 'publish', and each row carries only the title,
				 * permalink and excerpt any anonymous visitor can read on the front end.
				 * A capability check would gate data the site already publishes.
				 */
				'permission_callback' => '__return_true',
				'args'                => array(
					'count' => array(
						'description'       => __( 'Number of spotlighted posts to return.', 'spotlight-posts' ),
						'type'              => 'integer',
						'default'           => 5,
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_count' ),
					),
				),
			)
		);
	}

	/**
	 * Constrain the count argument to the range the cache layer supports.
	 *
	 * @param mixed $value Raw value supplied by the caller.
	 * @return bool|\WP_Error True when valid, WP_Error describing the range otherwise.
	 */
	public function validate_count( $value ) {
		if ( ! is_numeric( $value ) ) {
			return new \WP_Error(
				'spotlight_posts_invalid_count',
				__( 'The count parameter must be a number.', 'spotlight-posts' ),
				array( 'status' => 400 )
			);
		}

		$count = (int) $value;

		if ( $count < Repository::MIN_POSTS || $count > Repository::MAX_POSTS ) {
			return new \WP_Error(
				'spotlight_posts_invalid_count',
				sprintf(
					/* translators: 1: minimum allowed count, 2: maximum allowed count. */
					__( 'The count parameter must be between %1$d and %2$d.', 'spotlight-posts' ),
					Repository::MIN_POSTS,
					Repository::MAX_POSTS
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Return the spotlighted posts.
	 *
	 * Mapped to arrays explicitly rather than relying on JsonSerializable, so the response
	 * shape is visible here and cannot change because a DTO gained a property.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response Collection of spotlighted posts.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$posts = $this->repository->find( (int) $request->get_param( 'count' ) );

		return rest_ensure_response(
			array_map(
				static function ( $post ): array {
					return $post->to_array();
				},
				$posts
			)
		);
	}
}

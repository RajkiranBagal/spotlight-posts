<?php
/**
 * Public REST endpoint exposing the featured posts.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\REST;

use VIP_Featured_Posts\Query;

defined( 'ABSPATH' ) || exit;

/**
 * REST namespace for this plugin.
 */
const REST_NAMESPACE = 'vip-featured/v1';

/**
 * Route exposing the featured posts collection.
 */
const ROUTE = '/posts';

/**
 * Register the featured posts route.
 */
function register_routes(): void {
	register_rest_route(
		REST_NAMESPACE,
		ROUTE,
		array(
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => __NAMESPACE__ . '\\get_items',
			/*
			 * __return_true is the correct permission_callback here rather than
			 * an oversight. The endpoint is read-only and returns nothing that
			 * is not already public: the query is pinned to post_status
			 * 'publish', and each row carries only the title, permalink and
			 * excerpt that any anonymous visitor can read on the front end.
			 * A capability check would gate data the site already publishes.
			 */
			'permission_callback' => '__return_true',
			'args'                => array(
				'count' => array(
					'description'       => __( 'Number of featured posts to return.', 'vip-featured-posts' ),
					'type'              => 'integer',
					'default'           => 5,
					'required'          => false,
					'sanitize_callback' => 'absint',
					'validate_callback' => __NAMESPACE__ . '\\validate_count',
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
function validate_count( $value ) {
	if ( ! is_numeric( $value ) ) {
		return new \WP_Error(
			'vip_featured_posts_invalid_count',
			__( 'The count parameter must be a number.', 'vip-featured-posts' ),
			array( 'status' => 400 )
		);
	}

	$count = (int) $value;

	if ( $count < Query\MIN_POSTS || $count > Query\MAX_POSTS ) {
		return new \WP_Error(
			'vip_featured_posts_invalid_count',
			sprintf(
				/* translators: 1: minimum allowed count, 2: maximum allowed count. */
				__( 'The count parameter must be between %1$d and %2$d.', 'vip-featured-posts' ),
				Query\MIN_POSTS,
				Query\MAX_POSTS
			),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Return the featured posts.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return \WP_REST_Response Collection of featured posts.
 */
function get_items( \WP_REST_Request $request ): \WP_REST_Response {
	$count = (int) $request->get_param( 'count' );

	return rest_ensure_response( Query\get_featured_posts( $count ) );
}

<?php
/**
 * The post types this plugin operates on.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which post types can be spotlighted.
 */
final class PostTypes {

	/**
	 * Supported post type slugs.
	 *
	 * Results are filtered through post_type_exists(), so a filter naming a type that was
	 * never registered cannot leave meta bound to a type that does not exist.
	 *
	 * @return string[] Post type slugs.
	 */
	public function all(): array {
		/**
		 * Filters the post types that can be spotlighted.
		 *
		 * @param string[] $post_types Post type slugs. Defaults to just 'post'.
		 */
		$types = (array) apply_filters( 'spotlight_posts_post_types', array( 'post' ) );

		$types = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $types ),
					static function ( string $type ): bool {
						return '' !== $type && post_type_exists( $type );
					}
				)
			)
		);

		/*
		 * Falls back to 'post' rather than returning empty. An empty post_type is ignored
		 * by WP_Query, which would silently widen every query instead of narrowing it --
		 * the same trap as an empty post__in.
		 */
		return empty( $types ) ? array( 'post' ) : $types;
	}

	/**
	 * Is this post type spotlightable?
	 *
	 * @param string $post_type Post type slug.
	 * @return bool Whether the type is supported.
	 */
	public function supports( string $post_type ): bool {
		return in_array( $post_type, $this->all(), true );
	}
}

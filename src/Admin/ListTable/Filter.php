<?php
/**
 * The Featured / Not featured filter above the posts list table.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin\ListTable;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\PostTypes;
use Spotlight_Posts\Support\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Narrows the listing to spotlighted posts, or to everything else.
 *
 * Reads the index rather than querying meta, so narrowing costs a primary-key comparison
 * instead of an unindexed scan.
 */
final class Filter implements Registrable {

	/**
	 * Query parameter carrying the filter.
	 */
	public const PARAM = 'spotlight_filter';

	/**
	 * Ordered ID index.
	 *
	 * @var Index
	 */
	private Index $index;

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

	/**
	 * Request reader.
	 *
	 * @var Request
	 */
	private Request $request;

	/**
	 * @param Index     $index      Ordered ID index.
	 * @param PostTypes $post_types Supported post types.
	 * @param Request   $request    Request reader.
	 */
	public function __construct( Index $index, PostTypes $post_types, Request $request ) {
		$this->index      = $index;
		$this->post_types = $post_types;
		$this->request    = $request;
	}

	/**
	 * Register the dropdown and the query filter.
	 */
	public function register(): void {
		add_action( 'restrict_manage_posts', array( $this, 'render' ) );
		add_action( 'pre_get_posts', array( $this, 'apply' ) );
	}

	/**
	 * Render the dropdown.
	 *
	 * @param string $post_type Post type being listed.
	 */
	public function render( string $post_type ): void {
		if ( ! $this->post_types->supports( $post_type ) ) {
			return;
		}

		$current = sanitize_key( $this->request->query( self::PARAM ) );

		$options = array(
			''             => __( 'All posts', 'spotlight-posts' ),
			'featured'     => __( 'Featured only', 'spotlight-posts' ),
			'not_featured' => __( 'Not featured', 'spotlight-posts' ),
		);

		echo '<select name="' . esc_attr( self::PARAM ) . '">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply the filter to the listing query.
	 *
	 * @param \WP_Query $query Query being prepared.
	 */
	public function apply( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $this->post_types->supports( (string) $query->get( 'post_type' ) ) ) {
			return;
		}

		$filter = sanitize_key( $this->request->query( self::PARAM ) );

		if ( 'featured' !== $filter && 'not_featured' !== $filter ) {
			return;
		}

		$ids = $this->index->ids();

		if ( 'featured' === $filter ) {
			// An empty index means nothing matches. post__in ignores an empty array, so a
			// sentinel that cannot match any post ID forces an empty result.
			$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );

			return;
		}

		if ( ! empty( $ids ) ) {
			$query->set( 'post__not_in', $ids );
		}
	}
}

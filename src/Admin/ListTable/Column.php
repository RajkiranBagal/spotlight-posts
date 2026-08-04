<?php
/**
 * The Featured column in the posts list table.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin\ListTable;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the column and renders its per-row toggle.
 *
 * Registering the column this way also puts it in Screen Options automatically, so
 * editors who do not curate can hide it without any extra work here.
 */
final class Column implements Registrable {

	/**
	 * Identifier for the custom column.
	 */
	public const COLUMN_ID = 'spotlight_featured';

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

	/**
	 * @param PostTypes $post_types Supported post types.
	 */
	public function __construct( PostTypes $post_types ) {
		$this->post_types = $post_types;
	}

	/**
	 * Register the column on every supported post type.
	 *
	 * These are dynamic hook names, so there is no single hook covering all types.
	 * Registered on init at priority 20, late enough for a site to register a custom post
	 * type on init and still be picked up.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_per_type' ), 20 );
	}

	/**
	 * Attach the per-type column hooks.
	 */
	public function register_per_type(): void {
		foreach ( $this->post_types->all() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render' ), 10, 2 );
		}
	}

	/**
	 * Insert the column ahead of the date.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Columns with ours inserted.
	 */
	public function add( array $columns ): array {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated[ self::COLUMN_ID ] = __( 'Featured', 'spotlight-posts' );
			}

			$updated[ $key ] = $label;
		}

		// No date column on this screen, so fall back to appending.
		if ( ! isset( $updated[ self::COLUMN_ID ] ) ) {
			$updated[ self::COLUMN_ID ] = __( 'Featured', 'spotlight-posts' );
		}

		return $updated;
	}

	/**
	 * Render the toggle for a row.
	 *
	 * The list table has already primed the meta cache for every post on the page, so
	 * get_post_meta() here costs no additional queries.
	 *
	 * Users who cannot edit the post see the state but get no control. The check is
	 * repeated server-side in AjaxToggle, since hiding a button is presentation, not
	 * authorisation.
	 *
	 * @param string $column  Column being rendered.
	 * @param int    $post_id Post for this row.
	 */
	public function render( string $column, int $post_id ): void {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}

		$is_featured = '1' === get_post_meta( $post_id, Index::META_KEY, true );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			printf(
				'<span class="spotlight-state">%s</span>',
				esc_html( $is_featured ? __( 'Featured', 'spotlight-posts' ) : __( 'Not featured', 'spotlight-posts' ) )
			);

			return;
		}

		printf(
			'<button type="button" class="button-link spotlight-toggle%1$s" data-post-id="%2$d" aria-pressed="%3$s"><span class="screen-reader-text">%4$s</span><span aria-hidden="true" class="spotlight-icon dashicons %5$s"></span></button>',
			$is_featured ? ' is-featured' : '',
			(int) $post_id,
			$is_featured ? 'true' : 'false',
			esc_attr(
				$is_featured
					/* translators: %s: post title. */
					? sprintf( __( 'Remove %s from featured posts', 'spotlight-posts' ), get_the_title( $post_id ) )
					/* translators: %s: post title. */
					: sprintf( __( 'Add %s to featured posts', 'spotlight-posts' ), get_the_title( $post_id ) )
			),
			$is_featured ? 'dashicons-star-filled' : 'dashicons-star-empty'
		);
	}
}

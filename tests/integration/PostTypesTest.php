<?php
/**
 * Tests for multi-post-type support.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts;
use Spotlight_Posts\Admin\List_Table;
use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Featured\Repository;

/**
 * @covers \Spotlight_Posts\supported_post_types
 */
class PostTypesTest extends TestCase {

	/**
	 * Register a custom type to spotlight alongside posts.
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'guide',
			array(
				'public'  => true,
				'label'   => 'Guides',
				'supports' => array( 'title', 'editor', 'custom-fields' ),
			)
		);
	}

	/**
	 * Remove the type and any filters so tests stay isolated.
	 */
	public function tear_down(): void {
		remove_all_filters( 'spotlight_posts_post_types' );
		unregister_post_type( 'guide' );

		parent::tear_down();
	}

	/**
	 * Support 'guide' in addition to 'post'.
	 */
	private function support_guides(): void {
		add_filter(
			'spotlight_posts_post_types',
			static function (): array {
				return array( 'post', 'guide' );
			}
		);
	}

	/**
	 * Out of the box only posts are supported, so an existing site sees no change.
	 */
	public function test_defaults_to_posts_only(): void {
		$this->assertSame( array( 'post' ), \Spotlight_Posts\supported_post_types() );
	}

	/**
	 * A site can opt additional types in.
	 */
	public function test_filter_adds_a_type(): void {
		$this->support_guides();

		$this->assertSame( array( 'post', 'guide' ), \Spotlight_Posts\supported_post_types() );
	}

	/**
	 * Types that were never registered are dropped rather than trusted.
	 */
	public function test_unregistered_types_are_discarded(): void {
		add_filter(
			'spotlight_posts_post_types',
			static function (): array {
				return array( 'post', 'no_such_type' );
			}
		);

		$this->assertSame( array( 'post' ), \Spotlight_Posts\supported_post_types() );
	}

	/**
	 * Duplicates collapse.
	 */
	public function test_duplicates_are_collapsed(): void {
		add_filter(
			'spotlight_posts_post_types',
			static function (): array {
				return array( 'post', 'post', 'guide' );
			}
		);

		$this->assertSame( array( 'post', 'guide' ), \Spotlight_Posts\supported_post_types() );
	}

	/**
	 * An empty filter result falls back to 'post'.
	 *
	 * Returning an empty array would be worse than useless: WP_Query ignores an empty
	 * post_type, so every query would silently widen to all types instead of narrowing.
	 */
	public function test_empty_filter_falls_back_to_post(): void {
		add_filter(
			'spotlight_posts_post_types',
			static function (): array {
				return array();
			}
		);

		$this->assertSame( array( 'post' ), \Spotlight_Posts\supported_post_types() );
	}

	/**
	 * Junk values are discarded without fataling.
	 */
	public function test_non_string_values_are_discarded(): void {
		add_filter(
			'spotlight_posts_post_types',
			static function (): array {
				return array( 'post', '', 'guide' );
			}
		);

		$this->assertSame( array( 'post', 'guide' ), \Spotlight_Posts\supported_post_types() );
	}

	/**
	 * A supported custom type can be spotlighted and surfaces in the query.
	 */
	public function test_a_custom_type_can_be_featured(): void {
		$this->support_guides();

		$guide = self::factory()->post->create(
			array(
				'post_type'   => 'guide',
				'post_status' => 'publish',
				'post_title'  => 'A Guide',
			)
		);

		update_post_meta( $guide, Index::META_KEY, '1' );

		$this->assertContains( $guide, \Spotlight_Posts\index()->ids() );
		$this->assertContains( $guide, wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' ) );
	}

	/**
	 * Posts and a custom type can be spotlighted together, in one ordered list.
	 */
	public function test_posts_and_custom_types_share_one_list(): void {
		$this->support_guides();

		$post  = $this->create_featured_post( 'A Post' );
		$guide = self::factory()->post->create(
			array(
				'post_type'   => 'guide',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $guide, Index::META_KEY, '1' );

		\Spotlight_Posts\index()->set( array( $guide, $post ) );

		$this->assertSame(
			array( $guide, $post ),
			wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' ),
			'Ordering spans post types rather than grouping by them.'
		);
	}

	/**
	 * An unsupported type is ignored even if its meta is set by hand.
	 */
	public function test_unsupported_types_do_not_surface(): void {
		// 'guide' is registered but deliberately not opted in.
		$guide = self::factory()->post->create(
			array(
				'post_type'   => 'guide',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $guide, Index::META_KEY, '1' );

		\Spotlight_Posts\index()->rebuild();

		$this->assertNotContains( $guide, \Spotlight_Posts\index()->ids() );
		$this->assertNotContains( $guide, wp_list_pluck( \Spotlight_Posts\repository()->find( 5 ), 'id' ) );
	}

	/**
	 * The list-table column is offered for supported types only.
	 */
	public function test_list_table_column_is_scoped_to_supported_types(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/admin/list-table.php';

		$this->support_guides();

		ob_start();
		List_Table\quick_edit_field( List_Table\COLUMN_ID, 'guide' );
		$supported = (string) ob_get_clean();

		ob_start();
		List_Table\quick_edit_field( List_Table\COLUMN_ID, 'page' );
		$unsupported = (string) ob_get_clean();

		$this->assertNotSame( '', $supported, 'A supported type should get the Quick Edit field.' );
		$this->assertSame( '', $unsupported, 'An unsupported type should not.' );
	}

	/**
	 * Meta is registered against each supported type, so sanitize and auth callbacks
	 * apply to custom types too rather than only to posts.
	 */
	public function test_meta_is_registered_for_each_supported_type(): void {
		$this->support_guides();

		Spotlight_Posts\Meta_Box\register_meta();

		$registered = get_registered_meta_keys( 'post', 'guide' );

		$this->assertArrayHasKey( Index::META_KEY, $registered );
	}
}

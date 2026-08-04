<?php
/**
 * Tests for the featured ordering screen.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts;

use Spotlight_Posts\Featured\Index;

/**
 * @covers \Spotlight_Posts\Admin\Order_Screen
 */
class OrderScreenTest extends TestCase {

	/**
	 * Load the admin-only module and act as an editor.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST = array();
	}

	/**
	 * Clear request state between tests.
	 */
	public function tear_down(): void {
		$_POST = array();

		remove_all_filters( 'spotlight_posts_manage_capability' );

		parent::tear_down();
	}

	/**
	 * Reordering defaults to an editor-level capability.
	 */
	public function test_default_capability(): void {
		$this->assertSame( 'edit_others_posts', $this->screen()->capability() );
	}

	/**
	 * Sites can map curation onto their own role.
	 */
	public function test_capability_is_filterable(): void {
		add_filter(
			'spotlight_posts_manage_capability',
			static function (): string {
				return 'manage_options';
			}
		);

		$this->assertSame( 'manage_options', $this->screen()->capability() );
	}

	/**
	 * An editor can curate; a contributor cannot.
	 */
	public function test_capability_maps_to_the_expected_roles(): void {
		$this->assertTrue( user_can( self::factory()->user->create( array( 'role' => 'editor' ) ), $this->screen()->capability() ) );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'contributor' ) ), $this->screen()->capability() ) );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'subscriber' ) ), $this->screen()->capability() ) );
	}

	/**
	 * The screen lists the featured posts in index order.
	 */
	public function test_page_renders_items_in_index_order(): void {
		$a = $this->create_featured_post( 'Alpha' );
		$b = $this->create_featured_post( 'Beta' );

		$this->index()->set( array( $b, $a ) );

		ob_start();
		$this->screen()->render_page();
		$html = (string) ob_get_clean();

		$this->assertLessThan(
			strpos( $html, 'data-post-id="' . $a . '"' ),
			strpos( $html, 'data-post-id="' . $b . '"' ),
			'Beta was indexed first and should render first.'
		);
	}

	/**
	 * With nothing featured, the screen explains what to do instead of rendering an
	 * empty list with a save button that would do nothing.
	 */
	public function test_page_renders_an_empty_state(): void {
		ob_start();
		$this->screen()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No posts are featured yet', $html );
		$this->assertStringNotContainsString( 'spotlight-order-save', $html );
	}

	/**
	 * Keyboard move controls are rendered, not just the drag handle.
	 */
	public function test_page_renders_keyboard_reorder_controls(): void {
		$this->create_featured_post( 'Alpha' );

		ob_start();
		$this->screen()->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-direction="up"', $html );
		$this->assertStringContainsString( 'data-direction="down"', $html );
	}

	/**
	 * A submitted order is applied.
	 */
	public function test_save_applies_the_submitted_order(): void {
		$a = $this->create_featured_post( 'Alpha' );
		$b = $this->create_featured_post( 'Beta' );
		$c = $this->create_featured_post( 'Gamma' );

		$this->index()->set( array( $a, $b, $c ) );

		$this->assertSame(
			array( $c, $a, $b ),
			$this->save_order( array( $c, $a, $b ) )
		);
	}

	/**
	 * IDs that are not already featured are ignored.
	 *
	 * The endpoint takes an ordering instruction, never a membership list. Featuring a
	 * post has its own per-post capability check, and this must not become a way around
	 * it.
	 */
	public function test_save_cannot_introduce_unfeatured_posts(): void {
		$featured   = $this->create_featured_post( 'Featured' );
		$unfeatured = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->index()->set( array( $featured ) );

		$result = $this->save_order( array( $unfeatured, $featured ) );

		$this->assertSame( array( $featured ), $result );
		$this->assertNotContains( $unfeatured, $result );
	}

	/**
	 * Indexed posts missing from the submission are kept, not dropped.
	 *
	 * A tab opened before another post was featured would otherwise silently unfeature
	 * it on save.
	 */
	public function test_save_appends_ids_missing_from_the_submission(): void {
		$a = $this->create_featured_post( 'Alpha' );
		$b = $this->create_featured_post( 'Beta' );

		$this->index()->set( array( $a, $b ) );

		$result = $this->save_order( array( $b ) );

		$this->assertContains( $a, $result, 'The omitted post must survive.' );
		$this->assertSame( array( $b, $a ), $result );
	}

	/**
	 * An empty submission leaves the index intact.
	 */
	public function test_save_with_an_empty_order_changes_nothing(): void {
		$a = $this->create_featured_post( 'Alpha' );

		$this->index()->set( array( $a ) );

		$this->assertSame( array( $a ), $this->save_order( array() ) );
	}

	/**
	 * Saving invalidates the cached lists, since ordering is what they return.
	 */
	public function test_save_invalidates_the_cache(): void {
		$a = $this->create_featured_post( 'Alpha' );
		$b = $this->create_featured_post( 'Beta' );

		$this->index()->set( array( $a, $b ) );

		$this->assertSame(
			array( $a, $b ),
			wp_list_pluck( $this->repository()->find( 5 ), 'id' )
		);

		$this->save_order( array( $b, $a ) );

		$this->assertSame(
			array( $b, $a ),
			wp_list_pluck( $this->repository()->find( 5 ), 'id' ),
			'The reordered list should be visible immediately.'
		);
	}

	/**
	 * Removing goes through the meta, so every downstream hook behaves normally.
	 */
	public function test_remove_clears_the_flag_and_the_index(): void {
		$a = $this->create_featured_post( 'Alpha' );
		$b = $this->create_featured_post( 'Beta' );

		$this->index()->set( array( $a, $b ) );

		delete_post_meta( $a, Index::META_KEY );

		$this->assertSame( array( $b ), $this->index()->ids() );
	}

	/**
	 * Apply an order through the production code the AJAX handler calls.
	 *
	 * @param int[] $submitted Submitted order.
	 * @return int[] Resulting index.
	 */
	private function save_order( array $submitted ): array {
		return $this->screen()->apply_order( $submitted );
	}
}

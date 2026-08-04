<?php
/**
 * Tests for the posts list table controls.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Admin\ListTable\Column;
use Spotlight_Posts\Admin\ListTable\BulkActions;
use Spotlight_Posts\Admin\ListTable\QuickEdit;
use Spotlight_Posts\Admin\ListTable\Filter;
use Spotlight_Posts\Admin\MetaBox;

/**
 * @covers \Spotlight_Posts\Admin\List_Table
 */
class ListTableTest extends TestCase {

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Load the admin-only module and act as an editor.
	 */
	public function set_up(): void {
		parent::set_up();

		// The module is only required when is_admin() is true, which it is not under
		// PHPUnit. Loading it directly keeps the test honest about what it exercises.
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Clear request state between tests.
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Is the post under test flagged?
	 */
	private function is_featured(): bool {
		return '1' === get_post_meta( $this->post_id, Index::META_KEY, true );
	}

	/**
	 * Build a query that genuinely satisfies is_main_query() on an admin screen.
	 *
	 * WP_Query::is_main_query() compares identity against the $wp_the_query global, so
	 * assigning the is_main_query property does nothing at all -- the query has to be
	 * installed as the global to be recognised.
	 *
	 * @return \WP_Query Query positioned as the main admin query.
	 */
	private function main_admin_query(): \WP_Query {
		set_current_screen( 'edit-post' );

		$query = new \WP_Query();
		$query->set( 'post_type', 'post' );

		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}

	/**
	 * The column is inserted before the date column.
	 */
	public function test_column_is_added_before_date(): void {
		$columns = $this->column()->add(
			array(
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'title', Column::COLUMN_ID, 'date' ),
			array_keys( $columns )
		);
	}

	/**
	 * A screen with no date column still gets the column.
	 */
	public function test_column_is_appended_when_there_is_no_date_column(): void {
		$columns = $this->column()->add( array( 'title' => 'Title' ) );

		$this->assertArrayHasKey( Column::COLUMN_ID, $columns );
	}

	/**
	 * Editors get an interactive toggle.
	 */
	public function test_column_renders_a_toggle_for_authorised_users(): void {
		ob_start();
		$this->column()->render( Column::COLUMN_ID, $this->post_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'spotlight-toggle', $html );
		$this->assertStringContainsString( 'aria-pressed="false"', $html );
	}

	/**
	 * The pressed state reflects the stored flag.
	 */
	public function test_toggle_reflects_featured_state(): void {
		update_post_meta( $this->post_id, Index::META_KEY, '1' );

		ob_start();
		$this->column()->render( Column::COLUMN_ID, $this->post_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-pressed="true"', $html );
		$this->assertStringContainsString( 'dashicons-star-filled', $html );
	}

	/**
	 * Users who cannot edit the post get no control at all.
	 */
	public function test_column_renders_no_control_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->column()->render( Column::COLUMN_ID, $this->post_id );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringContainsString( 'spotlight-state', $html );
	}

	/**
	 * Other columns are left alone.
	 */
	public function test_other_columns_are_untouched(): void {
		ob_start();
		$this->column()->render( 'title', $this->post_id );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Both bulk actions are offered.
	 */
	public function test_bulk_actions_are_registered(): void {
		$actions = $this->bulk()->add( array() );

		$this->assertArrayHasKey( BulkActions::FEATURE, $actions );
		$this->assertArrayHasKey( BulkActions::UNFEATURE, $actions );
	}

	/**
	 * Bulk featuring flags every selected post.
	 */
	public function test_bulk_feature_flags_the_selection(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->bulk()->handle( 'http://example.org/', BulkActions::FEATURE, array( $this->post_id, $other ) );

		$this->assertTrue( $this->is_featured() );
		$this->assertSame( '1', get_post_meta( $other, Index::META_KEY, true ) );
	}

	/**
	 * Bulk unfeaturing clears the flag.
	 */
	public function test_bulk_unfeature_clears_the_selection(): void {
		update_post_meta( $this->post_id, Index::META_KEY, '1' );

		$this->bulk()->handle( 'http://example.org/', BulkActions::UNFEATURE, array( $this->post_id ) );

		$this->assertFalse( $this->is_featured() );
	}

	/**
	 * Posts the user cannot edit are skipped, not silently applied.
	 *
	 * A bulk selection can span posts with different ownership, so the capability is
	 * checked per post rather than once for the request.
	 */
	public function test_bulk_action_skips_posts_the_user_cannot_edit(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$redirect = $this->bulk()->handle( 'http://example.org/', BulkActions::FEATURE, array( $this->post_id ) );

		$this->assertFalse( $this->is_featured() );
		$this->assertStringContainsString( 'spotlight_denied=1', $redirect );
		$this->assertStringContainsString( 'spotlight_changed=0', $redirect );
	}

	/**
	 * Unrelated bulk actions pass straight through.
	 */
	public function test_unrelated_bulk_action_is_ignored(): void {
		$redirect = $this->bulk()->handle( 'http://example.org/', 'trash', array( $this->post_id ) );

		$this->assertSame( 'http://example.org/', $redirect );
		$this->assertFalse( $this->is_featured() );
	}

	/**
	 * Filtering to featured posts reads the index rather than querying meta.
	 */
	public function test_filter_narrows_to_indexed_posts(): void {
		update_post_meta( $this->post_id, Index::META_KEY, '1' );

		$_GET[ Filter::PARAM ] = 'featured';

		$query = $this->main_admin_query();

		$this->filter()->apply( $query );

		$this->assertSame( \Spotlight_Posts\index()->ids(), $query->get( 'post__in' ) );
	}

	/**
	 * The inverse filter excludes the indexed posts.
	 */
	public function test_not_featured_filter_excludes_indexed_posts(): void {
		update_post_meta( $this->post_id, Index::META_KEY, '1' );

		$_GET[ Filter::PARAM ] = 'not_featured';

		$query = $this->main_admin_query();

		$this->filter()->apply( $query );

		$this->assertSame( \Spotlight_Posts\index()->ids(), $query->get( 'post__not_in' ) );
	}

	/**
	 * Filtering to featured with an empty index must return nothing, not everything.
	 *
	 * post__in ignores an empty array, so without a sentinel the filter would silently
	 * widen to every post -- the opposite of what was asked for.
	 */
	public function test_featured_filter_with_an_empty_index_matches_nothing(): void {
		$_GET[ Filter::PARAM ] = 'featured';

		$query = $this->main_admin_query();

		$this->filter()->apply( $query );

		$this->assertSame( array( 0 ), $query->get( 'post__in' ) );
	}

	/**
	 * No filter parameter leaves the query untouched.
	 */
	public function test_absent_filter_leaves_the_query_alone(): void {
		$query = $this->main_admin_query();

		$this->filter()->apply( $query );

		$this->assertSame( '', $query->get( 'post__in' ) );
	}

	/**
	 * Quick Edit reuses the meta box's field and nonce names, so the existing save
	 * handler picks it up with no second code path.
	 */
	public function test_quick_edit_field_reuses_the_meta_box_contract(): void {
		ob_start();
		$this->quickEdit()->render( Column::COLUMN_ID, 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( MetaBox::FIELD_NAME, $html );
		$this->assertStringContainsString( MetaBox::NONCE_NAME, $html );
	}

	/**
	 * Quick Edit renders nothing for other post types.
	 */
	public function test_quick_edit_field_is_scoped_to_posts(): void {
		ob_start();
		$this->quickEdit()->render( Column::COLUMN_ID, 'page' );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}

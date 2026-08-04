<?php
/**
 * Shared base for the integration tests.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts\Featured\Index;

/**
 * Resets the plugin's persistent state between tests.
 *
 * The object cache and the index option both outlive a single test method, so without
 * this a passing test could be leaning on state a previous one left behind.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset the cache and index before every test.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_cache_flush();

		// Written directly rather than through set_ids(), so the starting state is a
		// known-empty index rather than one rebuilt from whatever meta exists.
		update_option( Index::OPTION, array() );
	}

	/**
	 * Create a published post and mark it featured.
	 *
	 * @param string $title Post title.
	 * @return int Post ID.
	 */
	protected function create_featured_post( string $title = 'Featured' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, Index::META_KEY, '1' );

		return $post_id;
	}

	/**
	 * Admin services, built on demand.
	 *
	 * The Plugin only constructs these when is_admin() is true, which it is not under
	 * PHPUnit, so tests build them directly with the same dependencies.
	 */
	private function svc( string $class_name ) {
		static $built = array();

		if ( isset( $built[ $class_name ] ) ) {
			return $built[ $class_name ];
		}

		$plugin     = \Spotlight_Posts\Plugin::instance();
		$post_types = $plugin->get( \Spotlight_Posts\Support\PostTypes::class );
		$index      = $plugin->get( \Spotlight_Posts\Featured\Index::class );
		$schedule   = $plugin->get( \Spotlight_Posts\Featured\Schedule::class );
		$request    = new \Spotlight_Posts\Support\Request();

		switch ( $class_name ) {
			case \Spotlight_Posts\Admin\ListTable\Column::class:
				$built[ $class_name ] = new \Spotlight_Posts\Admin\ListTable\Column( $post_types );
				break;
			case \Spotlight_Posts\Admin\ListTable\BulkActions::class:
				$built[ $class_name ] = new \Spotlight_Posts\Admin\ListTable\BulkActions( $post_types, $request );
				break;
			case \Spotlight_Posts\Admin\ListTable\QuickEdit::class:
				$built[ $class_name ] = new \Spotlight_Posts\Admin\ListTable\QuickEdit( $post_types );
				break;
			case \Spotlight_Posts\Admin\ListTable\Filter::class:
				$built[ $class_name ] = new \Spotlight_Posts\Admin\ListTable\Filter( $index, $post_types, $request );
				break;
			case \Spotlight_Posts\Admin\MetaBox::class:
				$built[ $class_name ] = new \Spotlight_Posts\Admin\MetaBox( $schedule, $post_types, $request );
				break;
			case \Spotlight_Posts\Admin\OrderScreen::class:
				$built[ $class_name ] = new \Spotlight_Posts\Admin\OrderScreen( $index, SPOTLIGHT_POSTS_FILE, \Spotlight_Posts\VERSION );
				break;
		}

		return $built[ $class_name ];
	}

	protected function column(): \Spotlight_Posts\Admin\ListTable\Column {
		return $this->svc( \Spotlight_Posts\Admin\ListTable\Column::class );
	}

	protected function bulk(): \Spotlight_Posts\Admin\ListTable\BulkActions {
		return $this->svc( \Spotlight_Posts\Admin\ListTable\BulkActions::class );
	}

	protected function quickEdit(): \Spotlight_Posts\Admin\ListTable\QuickEdit {
		return $this->svc( \Spotlight_Posts\Admin\ListTable\QuickEdit::class );
	}

	protected function filter(): \Spotlight_Posts\Admin\ListTable\Filter {
		return $this->svc( \Spotlight_Posts\Admin\ListTable\Filter::class );
	}

	protected function metaBox(): \Spotlight_Posts\Admin\MetaBox {
		return $this->svc( \Spotlight_Posts\Admin\MetaBox::class );
	}

	protected function screen(): \Spotlight_Posts\Admin\OrderScreen {
		return $this->svc( \Spotlight_Posts\Admin\OrderScreen::class );
	}

}

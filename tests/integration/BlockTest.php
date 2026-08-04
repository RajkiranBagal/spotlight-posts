<?php
/**
 * Tests for the dynamic block's rendering.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts\Frontend\Block;
use Spotlight_Posts\Featured\Index;

/**
 * @covers \Spotlight_Posts\Block
 */
class BlockTest extends TestCase {

	/**
	 * Render the block the way core would.
	 *
	 * get_block_wrapper_attributes() reads WP_Block_Supports::$block_to_render, which
	 * core populates during a real render pass. Calling the render callback directly
	 * leaves it null, so it is set up here rather than the callback being changed to
	 * tolerate a state that never occurs in production.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render( array $attributes = array() ): string {
		$previous = \WP_Block_Supports::$block_to_render;

		\WP_Block_Supports::$block_to_render = array(
			'blockName' => 'spotlight-posts/featured-list',
			'attrs'     => $attributes,
		);

		try {
			return \Spotlight_Posts\Plugin::instance()->get( Block::class )->render( $attributes );
		} finally {
			\WP_Block_Supports::$block_to_render = $previous;
		}
	}

	/**
	 * The default heading level is h2.
	 */
	public function test_default_heading_level(): void {
		$this->create_featured_post();

		$html = $this->render( array( 'heading' => 'Picks' ) );

		$this->assertStringContainsString( '<h2', $html );
		$this->assertStringContainsString( '</h2>', $html );
	}

	/**
	 * The level is configurable, because a fixed h2 breaks the document outline wherever
	 * the block is not actually the second level on the page.
	 */
	public function test_heading_level_is_configurable(): void {
		$this->create_featured_post();

		$html = $this->render(
			array(
				'heading'      => 'Picks',
				'headingLevel' => 4,
			)
		);

		$this->assertStringContainsString( '<h4', $html );
		$this->assertStringNotContainsString( '<h2', $html );
	}

	/**
	 * Levels outside h2-h6 are clamped rather than trusted.
	 *
	 * h1 belongs to the page title, and anything beyond h6 is not a heading element at
	 * all -- rendering it would emit invalid markup.
	 *
	 * @dataProvider data_out_of_range_levels
	 *
	 * @param mixed  $level    Requested level.
	 * @param string $expected Tag that should be rendered.
	 */
	public function test_heading_level_is_clamped( $level, string $expected ): void {
		$this->create_featured_post();

		$html = $this->render(
			array(
				'heading'      => 'Picks',
				'headingLevel' => $level,
			)
		);

		$this->assertStringContainsString( '<' . $expected, $html );
	}

	/**
	 * Levels that must be corrected.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function data_out_of_range_levels(): array {
		return array(
			'h1 is reserved for the page title' => array( 1, 'h2' ),
			'zero'                              => array( 0, 'h2' ),
			'negative'                          => array( -3, 'h2' ),
			'beyond h6'                         => array( 7, 'h6' ),
			'absurd'                            => array( 99, 'h6' ),
		);
	}

	/**
	 * No heading attribute means no heading element at all, rather than an empty one.
	 */
	public function test_no_heading_renders_no_heading_element(): void {
		$this->create_featured_post();

		$html = $this->render();

		$this->assertStringNotContainsString( '<h2', $html );
		$this->assertStringNotContainsString( '__heading', $html );
	}

	/**
	 * Titles are escaped at output.
	 *
	 * Acting as an administrator on purpose. For anyone without unfiltered_html,
	 * WordPress's kses strips a script tag when the post is *saved*, so the markup never
	 * reaches the render path and the test would pass without proving anything about
	 * escaping. An administrator's title is stored verbatim, which is exactly the case
	 * esc_html() has to handle.
	 */
	public function test_output_is_escaped(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Bad <script>alert(1)</script>',
			)
		);
		update_post_meta( $post_id, Index::META_KEY, '1' );

		$this->assertStringContainsString(
			'<script>',
			get_post_field( 'post_title', $post_id ),
			'The title must be stored raw, or this test proves nothing.'
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * With nothing featured the block says so rather than rendering an empty list.
	 */
	public function test_empty_state(): void {
		$html = $this->render();

		$this->assertStringContainsString( '__empty', $html );
	}
}

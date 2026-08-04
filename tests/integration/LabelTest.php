<?php
/**
 * Tests for editorial labels and the meta registration that backs them.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Tests;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Featured\Meta;

/**
 * @covers \Spotlight_Posts\Featured\Meta
 */
class LabelTest extends TestCase {

	/**
	 * The meta service.
	 */
		/**
	 * Both keys are registered, and outside the admin.
	 *
	 * Regression guard. Registration lived in the meta box, which is only constructed
	 * when is_admin() is true -- so on a WP-CLI or REST request the featured flag had no
	 * sanitize or auth callback at all, while the expiry key did.
	 */
	public function test_meta_is_registered_regardless_of_context(): void {
		$this->meta()->register_meta();

		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( Index::META_KEY, $registered );
		$this->assertArrayHasKey( Meta::LABEL_KEY, $registered );
		$this->assertFalse( is_admin(), 'This test must run outside the admin to be meaningful.' );
	}

	/**
	 * The featured flag only ever stores '1' or an empty string.
	 */
	public function test_featured_flag_is_sanitized(): void {
		$this->assertSame( '1', $this->meta()->sanitize_featured( '1' ) );
		$this->assertSame( '', $this->meta()->sanitize_featured( 'definitely-not-one' ) );
		$this->assertSame( '', $this->meta()->sanitize_featured( '<script>alert(1)</script>' ) );
	}

	/**
	 * Labels are reduced to plain text.
	 */
	public function test_label_is_sanitized(): void {
		$this->assertSame( 'Editor', $this->meta()->sanitize_label( '  Editor  ' ) );
		$this->assertSame( '', $this->meta()->sanitize_label( array( 'not', 'scalar' ) ) );

		// sanitize_text_field() removes a script element's *contents* as well as its
		// tags, so nothing survives at all -- stricter than merely stripping markup.
		$this->assertSame( '', $this->meta()->sanitize_label( '<script>alert(1)</script>' ) );

		// Characters that are legitimate in a label survive sanitization and are dealt
		// with by escaping at output instead.
		$this->assertSame( 'Editor\'s "pick" & more', $this->meta()->sanitize_label( 'Editor\'s "pick" & more' ) );
	}

	/**
	 * Long labels are truncated on write.
	 *
	 * Truncating here rather than at render keeps the constraint in one place and stops a
	 * pasted paragraph from breaking a card layout.
	 */
	public function test_label_is_truncated(): void {
		$label = $this->meta()->sanitize_label( str_repeat( 'a', 200 ) );

		$this->assertSame( Meta::LABEL_MAX_LENGTH, strlen( $label ) );
	}

	/**
	 * Truncation does not cut a multibyte character in half.
	 */
	public function test_truncation_preserves_valid_utf8(): void {
		$label = $this->meta()->sanitize_label( str_repeat( 'é', 100 ) );

		$this->assertSame( $label, wp_check_invalid_utf8( $label ), 'Truncated label must remain valid UTF-8.' );
	}

	/**
	 * A label reaches the rendered payload.
	 */
	public function test_label_appears_on_the_dto(): void {
		$post_id = $this->create_featured_post( 'Labelled' );
		update_post_meta( $post_id, Meta::LABEL_KEY, 'Editor pick' );

		$posts = $this->repository()->find( 5 );

		$this->assertSame( 'Editor pick', $posts[0]->label );
	}

	/**
	 * A post without a label carries an empty string, not null.
	 */
	public function test_missing_label_is_an_empty_string(): void {
		$this->create_featured_post( 'Plain' );

		$this->assertSame( '', $this->repository()->find( 5 )[0]->label );
	}

	/**
	 * Changing a label invalidates the cached lists.
	 *
	 * The label is part of the cached payload, so without this a badge added after a list
	 * was cached would not appear until the TTL lapsed.
	 */
	public function test_label_change_invalidates_the_cache(): void {
		$post_id = $this->create_featured_post( 'Labelled' );

		$this->assertSame( '', $this->repository()->find( 5 )[0]->label );

		update_post_meta( $post_id, Meta::LABEL_KEY, 'Trending' );

		$this->assertSame( 'Trending', $this->repository()->find( 5 )[0]->label );
	}

	/**
	 * Removing a label takes effect immediately too.
	 */
	public function test_label_removal_invalidates_the_cache(): void {
		$post_id = $this->create_featured_post( 'Labelled' );
		update_post_meta( $post_id, Meta::LABEL_KEY, 'Trending' );

		$this->assertSame( 'Trending', $this->repository()->find( 5 )[0]->label );

		delete_post_meta( $post_id, Meta::LABEL_KEY );

		$this->assertSame( '', $this->repository()->find( 5 )[0]->label );
	}

	/**
	 * An unrelated meta key must not flush the cache.
	 */
	public function test_unrelated_meta_does_not_invalidate(): void {
		$post_id = $this->create_featured_post();

		$before = \Spotlight_Posts\Plugin::instance()
			->get( \Spotlight_Posts\Support\Cache::class )->version();

		update_post_meta( $post_id, '_something_else', 'value' );

		$this->assertSame(
			$before,
			\Spotlight_Posts\Plugin::instance()->get( \Spotlight_Posts\Support\Cache::class )->version()
		);
	}

	/**
	 * The label survives the cache round trip.
	 */
	public function test_label_survives_caching(): void {
		$post_id = $this->create_featured_post( 'Labelled' );
		update_post_meta( $post_id, Meta::LABEL_KEY, 'Editor pick' );

		// First call populates the cache, second reads it back.
		$this->repository()->find( 5 );

		$this->assertSame( 'Editor pick', $this->repository()->find( 5 )[0]->label );
	}

	/**
	 * Labels are escaped at output.
	 *
	 * Uses characters that survive sanitization rather than a script tag, which the
	 * sanitizer removes outright -- otherwise the assertion would pass without the
	 * escaping layer ever being exercised.
	 */
	public function test_label_is_escaped_when_rendered(): void {
		$post_id = $this->create_featured_post( 'Labelled' );
		update_post_meta( $post_id, Meta::LABEL_KEY, 'Ben & Jerry\'s "pick"' );

		\WP_Block_Supports::$block_to_render = array(
			'blockName' => 'spotlight-posts/featured-list',
			'attrs'     => array(),
		);

		$html = \Spotlight_Posts\Plugin::instance()
			->get( \Spotlight_Posts\Frontend\Block::class )->render( array() );

		\WP_Block_Supports::$block_to_render = null;

		$this->assertStringContainsString( '&amp;', $html );
		$this->assertStringNotContainsString( 'Ben & Jerry', $html );
	}
}

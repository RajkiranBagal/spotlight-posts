<?php
/**
 * Dynamic block registration and server-side rendering.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Block;

use Spotlight_Posts\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Register the featured-list block from its compiled metadata.
 *
 * The block is rendered on the server so the markup always reflects current
 * cache state rather than whatever was serialized into post content at save
 * time. Registration no-ops when build/ is absent, which keeps a freshly
 * cloned checkout from fataling before `npm run build` has run.
 */
function register(): void {
	$block_path = SPOTLIGHT_POSTS_DIR . 'build/featured-list';

	if ( ! file_exists( $block_path . '/block.json' ) ) {
		return;
	}

	register_block_type(
		$block_path,
		array(
			'render_callback' => __NAMESPACE__ . '\\render',
		)
	);
}

/**
 * Render the featured posts list.
 *
 * Every dynamic value is escaped at the point of output with the escaper that
 * matches its context: esc_url() for hrefs, esc_html() for text nodes.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Rendered HTML.
 */
function render( array $attributes ): string {
	$heading         = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
	$number_of_posts = isset( $attributes['numberOfPosts'] ) ? (int) $attributes['numberOfPosts'] : 5;

	$posts = Query\get_featured_posts( $number_of_posts );

	ob_start();
	?>
	<div <?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>>
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-spotlight-posts-featured-list__heading">
				<?php echo esc_html( $heading ); ?>
			</h2>
		<?php endif; ?>

		<?php if ( empty( $posts ) ) : ?>
			<p class="wp-block-spotlight-posts-featured-list__empty">
				<?php esc_html_e( 'No featured posts yet.', 'spotlight-posts' ); ?>
			</p>
		<?php else : ?>
			<ul class="wp-block-spotlight-posts-featured-list__list">
				<?php foreach ( $posts as $post ) : ?>
					<li class="wp-block-spotlight-posts-featured-list__item">
						<a href="<?php echo esc_url( $post['url'] ); ?>">
							<?php echo esc_html( $post['title'] ); ?>
						</a>
						<?php if ( '' !== $post['excerpt'] ) : ?>
							<p class="wp-block-spotlight-posts-featured-list__excerpt">
								<?php echo esc_html( $post['excerpt'] ); ?>
							</p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}

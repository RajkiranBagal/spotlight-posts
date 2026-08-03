<?php
/**
 * Drag-to-reorder screen for featured posts.
 *
 * The index is already an ordered array, so ordering is presentation over data that
 * exists rather than a second storage mechanism bolted on.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Admin\Order_Screen;

use VIP_Featured_Posts;
use VIP_Featured_Posts\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Slug for the admin page.
 */
const PAGE_SLUG = 'vip-featured-order';

/**
 * Nonce action for saving an order.
 */
const NONCE_ACTION = 'vip_featured_save_order';

/**
 * admin-ajax action for saving an order.
 */
const AJAX_ACTION = 'vip_featured_save_order';

/**
 * admin-ajax action for removing a post from the list.
 */
const AJAX_REMOVE_ACTION = 'vip_featured_remove';

/**
 * Capability required to curate the featured list.
 *
 * Curating and authoring are different jobs: someone may be trusted to decide what the
 * homepage promotes without being able to edit every post, or the reverse. This
 * defaults to edit_others_posts -- roughly "editor" -- and is filterable so a site can
 * map it onto a dedicated role.
 *
 * @return string Capability name.
 */
function get_capability(): string {
	/**
	 * Filters the capability required to reorder featured posts.
	 *
	 * @param string $capability Capability name.
	 */
	return (string) apply_filters( 'vip_featured_posts_manage_capability', 'edit_others_posts' );
}

/**
 * Register the ordering screen under Posts.
 */
function register_menu(): void {
	add_submenu_page(
		'edit.php',
		__( 'Featured Posts Order', 'vip-featured-posts' ),
		__( 'Featured Order', 'vip-featured-posts' ),
		get_capability(),
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page'
	);
}

/**
 * Render the ordering screen.
 */
function render_page(): void {
	if ( ! current_user_can( get_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to manage featured posts.', 'vip-featured-posts' ), 403 );
	}

	$ids = Index\get_ids();
	?>
	<div class="wrap vip-featured-order">
		<h1><?php esc_html_e( 'Featured Posts Order', 'vip-featured-posts' ); ?></h1>

		<?php if ( empty( $ids ) ) : ?>
			<p><?php esc_html_e( 'No posts are featured yet. Mark some as featured from the posts list, then come back to arrange them.', 'vip-featured-posts' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<?php esc_html_e( 'Go to Posts', 'vip-featured-posts' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Drag to reorder. This is the order the block and the REST endpoint return.', 'vip-featured-posts' ); ?>
			</p>

			<ul id="vip-featured-order-list" class="vip-featured-order-list">
				<?php foreach ( $ids as $post_id ) : ?>
					<?php
					$post_object = get_post( $post_id );

					if ( ! $post_object instanceof \WP_Post ) {
						continue;
					}
					?>
					<li class="vip-featured-order-item" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
						<span class="vip-featured-order-handle dashicons dashicons-menu" aria-hidden="true"></span>

						<span class="vip-featured-order-title">
							<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>">
								<?php echo esc_html( get_the_title( $post_object ) ); ?>
							</a>
						</span>

						<span class="vip-featured-order-status">
							<?php
							$status = get_post_status_object( (string) get_post_status( $post_object ) );
							echo esc_html( $status instanceof \stdClass ? $status->label : (string) get_post_status( $post_object ) );
							?>
						</span>

						<span class="vip-featured-order-controls">
							<?php
							/*
							 * Dragging is not reachable from a keyboard, so the same
							 * reordering is offered as buttons. They are the accessible
							 * path, not a fallback for browsers without JavaScript.
							 */
							?>
							<button
								type="button"
								class="button-link vip-featured-order-move"
								data-direction="up"
							>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: %s: post title. */
										esc_html__( 'Move %s up', 'vip-featured-posts' ),
										esc_html( get_the_title( $post_object ) )
									);
									?>
								</span>
								<span aria-hidden="true" class="dashicons dashicons-arrow-up-alt2"></span>
							</button>

							<button
								type="button"
								class="button-link vip-featured-order-move"
								data-direction="down"
							>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: %s: post title. */
										esc_html__( 'Move %s down', 'vip-featured-posts' ),
										esc_html( get_the_title( $post_object ) )
									);
									?>
								</span>
								<span aria-hidden="true" class="dashicons dashicons-arrow-down-alt2"></span>
							</button>

							<button
								type="button"
								class="button-link vip-featured-order-remove"
								data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
							>
								<?php esc_html_e( 'Remove', 'vip-featured-posts' ); ?>
							</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="vip-featured-order-actions">
				<button type="button" class="button button-primary" id="vip-featured-order-save">
					<?php esc_html_e( 'Save order', 'vip-featured-posts' ); ?>
				</button>
				<span class="vip-featured-order-feedback" role="status" aria-live="polite"></span>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Load the ordering assets on this screen only.
 *
 * @param string $hook Current admin page.
 */
function enqueue_assets( string $hook ): void {
	if ( 'posts_page_' . PAGE_SLUG !== $hook ) {
		return;
	}

	wp_enqueue_style( 'dashicons' );

	wp_enqueue_style(
		'vip-featured-posts-order',
		plugins_url( 'assets/order.css', VIP_FEATURED_POSTS_FILE ),
		array(),
		VIP_Featured_Posts\VERSION
	);

	wp_enqueue_script(
		'vip-featured-posts-order',
		plugins_url( 'assets/order.js', VIP_FEATURED_POSTS_FILE ),
		array( 'jquery-ui-sortable' ),
		VIP_Featured_Posts\VERSION,
		true
	);

	wp_localize_script(
		'vip-featured-posts-order',
		'vipFeaturedOrder',
		array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'action'       => AJAX_ACTION,
			'removeAction' => AJAX_REMOVE_ACTION,
			'nonce'        => wp_create_nonce( NONCE_ACTION ),
			'i18n'         => array(
				'saved'    => __( 'Order saved.', 'vip-featured-posts' ),
				'saving'   => __( 'Saving…', 'vip-featured-posts' ),
				'failed'   => __( 'Could not save the order. Please reload and try again.', 'vip-featured-posts' ),
				'removed'  => __( 'Removed from featured.', 'vip-featured-posts' ),
				'unsaved'  => __( 'You have unsaved changes.', 'vip-featured-posts' ),
				'moved'    => __( 'Moved.', 'vip-featured-posts' ),
			),
		)
	);
}

/**
 * Apply a submitted order to the index.
 *
 * The submitted IDs are an ordering instruction, never a membership list. They are
 * intersected with what is already indexed, so this cannot be used to feature an
 * arbitrary post -- that still requires the meta write and its own per-post capability
 * check. Anything indexed but absent from the submission is appended rather than
 * dropped, so a tab opened before another post was featured cannot silently unfeature
 * it on save.
 *
 * Separated from the request handler so the rule can be tested directly: the handler
 * itself ends in wp_send_json_*, which halts execution.
 *
 * @param int[] $submitted Post IDs in the requested order.
 * @return int[] The resulting index.
 */
function apply_order( array $submitted ): array {
	$submitted = array_map( 'absint', $submitted );
	$current   = Index\get_ids();

	$ordered = array_values( array_intersect( $submitted, $current ) );
	$missing = array_values( array_diff( $current, $ordered ) );

	Index\set_ids( array_merge( $ordered, $missing ) );

	return Index\get_ids();
}

/**
 * Persist a reordered list.
 */
function ajax_save_order(): void {
	check_ajax_referer( NONCE_ACTION, 'nonce' );

	if ( ! current_user_can( get_capability() ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to manage featured posts.', 'vip-featured-posts' ) ), 403 );
	}

	$submitted = isset( $_POST['order'] ) && is_array( $_POST['order'] )
		? array_map( 'absint', wp_unslash( $_POST['order'] ) )
		: array();

	wp_send_json_success( array( 'order' => apply_order( $submitted ) ) );
}

/**
 * Remove a post from the featured list.
 *
 * Routed through the meta rather than the index directly, so the per-post capability
 * check and every downstream hook behave exactly as they would anywhere else.
 */
function ajax_remove(): void {
	check_ajax_referer( NONCE_ACTION, 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

	if ( ! $post_id || ! get_post( $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'That post no longer exists.', 'vip-featured-posts' ) ), 404 );
	}

	if ( ! current_user_can( get_capability() ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'vip-featured-posts' ) ), 403 );
	}

	delete_post_meta( $post_id, VIP_Featured_Posts\META_KEY );

	wp_send_json_success( array( 'postId' => $post_id ) );
}

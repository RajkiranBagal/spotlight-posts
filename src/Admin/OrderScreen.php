<?php
/**
 * Drag-to-reorder screen for spotlighted posts.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Registrable;

defined( 'ABSPATH' ) || exit;

/**
 * Arranges the featured list.
 *
 * Ordering is presentation over data that already exists: the index has been an ordered
 * array since it replaced the meta search, so this arranges it rather than introducing a
 * second storage mechanism.
 */
final class OrderScreen implements Registrable {

	/**
	 * Slug for the admin page.
	 */
	public const PAGE_SLUG = 'spotlight-order';

	/**
	 * Nonce action for saving an order.
	 */
	public const NONCE_ACTION = 'spotlight_save_order';

	/**
	 * admin-ajax action for saving an order.
	 */
	public const AJAX_ACTION = 'spotlight_save_order';

	/**
	 * admin-ajax action for removing a post from the list.
	 */
	public const AJAX_REMOVE_ACTION = 'spotlight_remove';

	/**
	 * Ordered ID index.
	 *
	 * @var Index
	 */
	private Index $index;

	/**
	 * Plugin main file, for building asset URLs.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * @param Index  $index       Ordered ID index.
	 * @param string $plugin_file Plugin main file.
	 * @param string $version     Plugin version.
	 */
	public function __construct( Index $index, string $plugin_file, string $version ) {
		$this->index       = $index;
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Register the menu, assets and ajax handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_' . self::AJAX_REMOVE_ACTION, array( $this, 'ajax_remove' ) );
	}

	/**
	 * Capability required to curate the featured list.
	 *
	 * Curating and authoring are different jobs: someone may be trusted to decide what the
	 * homepage promotes without being able to edit every post, or the reverse. Defaults to
	 * edit_others_posts -- roughly "editor" -- and is filterable so a site can map it onto
	 * a dedicated role.
	 *
	 * @return string Capability name.
	 */
	public function capability(): string {
		/**
		 * Filters the capability required to reorder spotlighted posts.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'spotlight_posts_manage_capability', 'edit_others_posts' );
	}

	/**
	 * Register the ordering screen under Posts.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php',
			__( 'Featured Posts Order', 'spotlight-posts' ),
			__( 'Featured Order', 'spotlight-posts' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the ordering screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage featured posts.', 'spotlight-posts' ), 403 );
		}

		$ids = $this->index->ids();
		?>
		<div class="wrap spotlight-order">
			<h1><?php esc_html_e( 'Featured Posts Order', 'spotlight-posts' ); ?></h1>

			<?php if ( empty( $ids ) ) : ?>
				<p><?php esc_html_e( 'No posts are featured yet. Mark some as featured from the posts list, then come back to arrange them.', 'spotlight-posts' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
						<?php esc_html_e( 'Go to Posts', 'spotlight-posts' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Drag to reorder. This is the order the block and the REST endpoint return.', 'spotlight-posts' ); ?>
				</p>

				<ul id="spotlight-order-list" class="spotlight-order-list">
					<?php foreach ( $ids as $post_id ) : ?>
						<?php
						$post_object = get_post( $post_id );

						if ( ! $post_object instanceof \WP_Post ) {
							continue;
						}
						?>
						<li class="spotlight-order-item" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
							<span class="spotlight-order-handle dashicons dashicons-menu" aria-hidden="true"></span>

							<span class="spotlight-order-title">
								<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>">
									<?php echo esc_html( get_the_title( $post_object ) ); ?>
								</a>
							</span>

							<span class="spotlight-order-status">
								<?php
								$status = get_post_status_object( (string) get_post_status( $post_object ) );
								echo esc_html( $status instanceof \stdClass ? $status->label : (string) get_post_status( $post_object ) );
								?>
							</span>

							<span class="spotlight-order-controls">
								<?php
								/*
								 * Dragging is not reachable from a keyboard, so the same
								 * reordering is offered as buttons. They are the accessible
								 * path, not a fallback for browsers without JavaScript.
								 */
								?>
								<button
									type="button"
									class="button-link spotlight-order-move"
									data-direction="up"
								>
									<span class="screen-reader-text">
										<?php
										printf(
											/* translators: %s: post title. */
											esc_html__( 'Move %s up', 'spotlight-posts' ),
											esc_html( get_the_title( $post_object ) )
										);
										?>
									</span>
									<span aria-hidden="true" class="dashicons dashicons-arrow-up-alt2"></span>
								</button>

								<button
									type="button"
									class="button-link spotlight-order-move"
									data-direction="down"
								>
									<span class="screen-reader-text">
										<?php
										printf(
											/* translators: %s: post title. */
											esc_html__( 'Move %s down', 'spotlight-posts' ),
											esc_html( get_the_title( $post_object ) )
										);
										?>
									</span>
									<span aria-hidden="true" class="dashicons dashicons-arrow-down-alt2"></span>
								</button>

								<button
									type="button"
									class="button-link spotlight-order-remove"
									data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
								>
									<?php esc_html_e( 'Remove', 'spotlight-posts' ); ?>
								</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<p class="spotlight-order-actions">
					<button type="button" class="button button-primary" id="spotlight-order-save">
						<?php esc_html_e( 'Save order', 'spotlight-posts' ); ?>
					</button>
					<span class="spotlight-order-feedback" role="status" aria-live="polite"></span>
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
	public function enqueue_assets( string $hook ): void {
		if ( 'posts_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'spotlight-posts-order',
			plugins_url( 'assets/order.css', $this->plugin_file ),
			array(),
			$this->version
		);

		wp_enqueue_script(
			'spotlight-posts-order',
			plugins_url( 'assets/order.js', $this->plugin_file ),
			array( 'jquery-ui-sortable' ),
			$this->version,
			true
		);

		wp_localize_script(
			'spotlight-posts-order',
			'spotlightOrder',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => self::AJAX_ACTION,
				'removeAction' => self::AJAX_REMOVE_ACTION,
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'         => array(
					'saved'    => __( 'Order saved.', 'spotlight-posts' ),
					'saving'   => __( 'Saving…', 'spotlight-posts' ),
					'failed'   => __( 'Could not save the order. Please reload and try again.', 'spotlight-posts' ),
					'removed'  => __( 'Removed from featured.', 'spotlight-posts' ),
					'unsaved'  => __( 'You have unsaved changes.', 'spotlight-posts' ),
					'moved'    => __( 'Moved.', 'spotlight-posts' ),
				),
			)
		);
	}

	/**
	 * Apply a submitted order to the index.
	 *
	 * Separated from the request handler so the rule can be tested directly: the handler
	 * itself ends in wp_send_json_*, which halts execution.
	 *
	 * @param int[] $submitted Post IDs in the requested order.
	 * @return int[] The resulting index.
	 */
	public function apply_order( array $submitted ): array {
		return $this->index->reorder( $submitted );
	}

	/**
	 * Persist a reordered list.
	 */
	public function ajax_save_order(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage featured posts.', 'spotlight-posts' ) ), 403 );
		}

		$submitted = isset( $_POST['order'] ) && is_array( $_POST['order'] )
			? array_map( 'absint', wp_unslash( $_POST['order'] ) )
			: array();

		wp_send_json_success( array( 'order' => $this->apply_order( $submitted ) ) );
	}

	/**
	 * Remove a post from the featured list.
	 *
	 * Routed through the meta rather than the index directly, so the per-post capability
	 * check and every downstream hook behave exactly as they would anywhere else.
	 */
	public function ajax_remove(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That post no longer exists.', 'spotlight-posts' ) ), 404 );
		}

		if ( ! current_user_can( $this->capability() ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'spotlight-posts' ) ), 403 );
		}

		delete_post_meta( $post_id, Index::META_KEY );

		wp_send_json_success( array( 'postId' => $post_id ) );
	}
}

<?php
/**
 * The per-row featured toggle, and the assets that drive it.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin\ListTable;

use Spotlight_Posts\Admin\MetaBox;
use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Flips the featured flag for a single post over admin-ajax.
 */
final class AjaxToggle implements Registrable {

	/**
	 * Nonce action for the toggle.
	 */
	public const NONCE_ACTION = 'spotlight_toggle';

	/**
	 * admin-ajax action name.
	 */
	public const AJAX_ACTION = 'spotlight_toggle';

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

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
	 * @param PostTypes $post_types  Supported post types.
	 * @param string    $plugin_file Plugin main file.
	 * @param string    $version     Plugin version.
	 */
	public function __construct( PostTypes $post_types, string $plugin_file, string $version ) {
		$this->post_types  = $post_types;
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Register the assets and the ajax handler.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Load the toggle script on the posts list screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || ! $this->post_types->supports( (string) $screen->post_type ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'spotlight-posts-admin',
			plugins_url( 'assets/admin.css', $this->plugin_file ),
			array(),
			$this->version
		);

		wp_enqueue_script(
			'spotlight-posts-admin',
			plugins_url( 'assets/admin.js', $this->plugin_file ),
			array(),
			$this->version,
			true
		);

		wp_localize_script(
			'spotlight-posts-admin',
			'spotlightPosts',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'fieldName' => MetaBox::FIELD_NAME,
				'i18n'      => array(
					'feature'   => __( 'Add to featured posts', 'spotlight-posts' ),
					'unfeature' => __( 'Remove from featured posts', 'spotlight-posts' ),
					'failed'    => __( 'Could not update the featured flag. Please reload and try again.', 'spotlight-posts' ),
				),
			)
		);
	}

	/**
	 * Toggle the flag.
	 *
	 * Authorisation is checked here regardless of what the UI offered: the button being
	 * absent from a row does not stop anyone posting to admin-ajax directly.
	 */
	public function handle(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That post no longer exists.', 'spotlight-posts' ) ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'spotlight-posts' ) ), 403 );
		}

		$featured = '1' === get_post_meta( $post_id, Index::META_KEY, true );

		if ( $featured ) {
			delete_post_meta( $post_id, Index::META_KEY );
		} else {
			update_post_meta( $post_id, Index::META_KEY, '1' );
		}

		wp_send_json_success(
			array(
				'postId'   => $post_id,
				'featured' => ! $featured,
			)
		);
	}
}

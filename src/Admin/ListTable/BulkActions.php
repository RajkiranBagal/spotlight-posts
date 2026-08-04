<?php
/**
 * Bulk featuring and unfeaturing from the posts list table.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin\ListTable;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\PostTypes;
use Spotlight_Posts\Support\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a featured change across a selection.
 *
 * Curating many posts at once is what editors actually reach for, more than a per-row
 * toggle.
 */
final class BulkActions implements Registrable {

	/**
	 * Action that features the selected posts.
	 */
	public const FEATURE = 'spotlight_feature';

	/**
	 * Action that unfeatures the selected posts.
	 */
	public const UNFEATURE = 'spotlight_unfeature';

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

	/**
	 * Request reader.
	 *
	 * @var Request
	 */
	private Request $request;

	/**
	 * @param PostTypes $post_types Supported post types.
	 * @param Request   $request    Request reader.
	 */
	public function __construct( PostTypes $post_types, Request $request ) {
		$this->post_types = $post_types;
		$this->request    = $request;
	}

	/**
	 * Register the actions and the result notice.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_per_type' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Attach the per-type bulk action hooks.
	 */
	public function register_per_type(): void {
		foreach ( $this->post_types->all() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle' ), 10, 3 );
		}
	}

	/**
	 * Offer the two actions.
	 *
	 * @param array<string, string> $actions Registered bulk actions.
	 * @return array<string, string> Actions with ours added.
	 */
	public function add( array $actions ): array {
		$actions[ self::FEATURE ]   = __( 'Mark as featured', 'spotlight-posts' );
		$actions[ self::UNFEATURE ] = __( 'Remove from featured', 'spotlight-posts' );

		return $actions;
	}

	/**
	 * Apply the action.
	 *
	 * WordPress verifies the bulk-action nonce before this filter runs, so what is left is
	 * authorisation -- checked per post rather than once, because a selection can span
	 * posts the user has different rights over.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action being applied.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string Redirect URL carrying the result.
	 */
	public function handle( string $redirect_to, string $action, array $post_ids ): string {
		if ( self::FEATURE !== $action && self::UNFEATURE !== $action ) {
			return $redirect_to;
		}

		$changed = 0;
		$denied  = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$denied;

				continue;
			}

			if ( self::FEATURE === $action ) {
				update_post_meta( $post_id, Index::META_KEY, '1' );
			} else {
				delete_post_meta( $post_id, Index::META_KEY );
			}

			++$changed;
		}

		return add_query_arg(
			array(
				'spotlight_changed' => $changed,
				'spotlight_denied'  => $denied,
				'spotlight_action'  => $action,
			),
			$redirect_to
		);
	}

	/**
	 * Report what the action did.
	 */
	public function notice(): void {
		$raw_changed = $this->request->query( 'spotlight_changed' );

		if ( '' === $raw_changed ) {
			return;
		}

		$changed = absint( $raw_changed );
		$denied  = absint( $this->request->query( 'spotlight_denied' ) );
		$action  = sanitize_key( $this->request->query( 'spotlight_action' ) );

		if ( $changed > 0 ) {
			$message = self::UNFEATURE === $action
				/* translators: %d: number of posts. */
				? sprintf( _n( '%d post removed from featured.', '%d posts removed from featured.', $changed, 'spotlight-posts' ), $changed )
				/* translators: %d: number of posts. */
				: sprintf( _n( '%d post marked as featured.', '%d posts marked as featured.', $changed, 'spotlight-posts' ), $changed );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		if ( $denied > 0 ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of posts. */
						_n(
							'%d post was skipped because you cannot edit it.',
							'%d posts were skipped because you cannot edit them.',
							$denied,
							'spotlight-posts'
						),
						$denied
					)
				)
			);
		}
	}
}

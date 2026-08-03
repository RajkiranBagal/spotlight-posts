<?php
/**
 * Featured controls in the posts list table.
 *
 * Four ways to reach the same flag, because editors reach for different ones:
 * a per-row toggle for a single change, bulk actions for many at once, Quick Edit
 * alongside the other fields, and a filter to see only what is featured.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Admin\List_Table;

use VIP_Featured_Posts;
use VIP_Featured_Posts\Index;
use VIP_Featured_Posts\Meta_Box;

defined( 'ABSPATH' ) || exit;

/**
 * Identifier for the custom column.
 *
 * Registering a column this way also puts it in Screen Options automatically, so
 * editors who do not curate can hide it without any extra work here.
 */
const COLUMN_ID = 'vip_featured';

/**
 * Nonce action shared by the row toggle and the bulk handler.
 */
const NONCE_ACTION = 'vip_featured_toggle';

/**
 * admin-ajax action name for the row toggle.
 */
const AJAX_ACTION = 'vip_featured_toggle';

/**
 * Bulk action that features the selected posts.
 */
const BULK_FEATURE = 'vip_feature';

/**
 * Bulk action that unfeatures the selected posts.
 */
const BULK_UNFEATURE = 'vip_unfeature';

/**
 * Query parameter carrying the featured filter.
 */
const FILTER_PARAM = 'vip_featured_filter';

/**
 * Add the Featured column, ahead of the date.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string> Columns with ours inserted.
 */
function add_column( array $columns ): array {
	$updated = array();

	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			$updated[ COLUMN_ID ] = __( 'Featured', 'vip-featured-posts' );
		}

		$updated[ $key ] = $label;
	}

	// No date column on this screen, so fall back to appending.
	if ( ! isset( $updated[ COLUMN_ID ] ) ) {
		$updated[ COLUMN_ID ] = __( 'Featured', 'vip-featured-posts' );
	}

	return $updated;
}

/**
 * Render the toggle for a row.
 *
 * The list table has already primed the meta cache for every post on the page, so
 * get_post_meta() here costs no additional queries.
 *
 * Users who cannot edit the post see the state but get no control -- the check is
 * repeated server-side in ajax_toggle(), since hiding a button is presentation, not
 * authorisation.
 *
 * @param string $column  Column being rendered.
 * @param int    $post_id Post for this row.
 */
function render_column( string $column, int $post_id ): void {
	if ( COLUMN_ID !== $column ) {
		return;
	}

	$is_featured = '1' === get_post_meta( $post_id, VIP_Featured_Posts\META_KEY, true );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		printf(
			'<span class="vip-featured-state">%s</span>',
			esc_html( $is_featured ? __( 'Featured', 'vip-featured-posts' ) : __( 'Not featured', 'vip-featured-posts' ) )
		);

		return;
	}

	printf(
		'<button type="button" class="button-link vip-featured-toggle%1$s" data-post-id="%2$d" aria-pressed="%3$s"><span class="screen-reader-text">%4$s</span><span aria-hidden="true" class="vip-featured-icon dashicons %5$s"></span></button>',
		$is_featured ? ' is-featured' : '',
		(int) $post_id,
		$is_featured ? 'true' : 'false',
		esc_attr(
			$is_featured
				/* translators: %s: post title. */
				? sprintf( __( 'Remove %s from featured posts', 'vip-featured-posts' ), get_the_title( $post_id ) )
				/* translators: %s: post title. */
				: sprintf( __( 'Add %s to featured posts', 'vip-featured-posts' ), get_the_title( $post_id ) )
		),
		$is_featured ? 'dashicons-star-filled' : 'dashicons-star-empty'
	);
}

/**
 * Offer bulk featuring and unfeaturing.
 *
 * @param array<string, string> $actions Registered bulk actions.
 * @return array<string, string> Actions with ours added.
 */
function register_bulk_actions( array $actions ): array {
	$actions[ BULK_FEATURE ]   = __( 'Mark as featured', 'vip-featured-posts' );
	$actions[ BULK_UNFEATURE ] = __( 'Remove from featured', 'vip-featured-posts' );

	return $actions;
}

/**
 * Apply a bulk action.
 *
 * WordPress verifies the bulk-action nonce before this filter runs, so what is left
 * to check is authorisation -- and that is done per post rather than once, because a
 * selection can span posts the user has different rights over.
 *
 * @param string $redirect_to Redirect URL.
 * @param string $action      Action being applied.
 * @param int[]  $post_ids    Selected post IDs.
 * @return string Redirect URL carrying the result.
 */
function handle_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
	if ( BULK_FEATURE !== $action && BULK_UNFEATURE !== $action ) {
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

		if ( BULK_FEATURE === $action ) {
			update_post_meta( $post_id, VIP_Featured_Posts\META_KEY, '1' );
		} else {
			delete_post_meta( $post_id, VIP_Featured_Posts\META_KEY );
		}

		++$changed;
	}

	return add_query_arg(
		array(
			'vip_featured_changed' => $changed,
			'vip_featured_denied'  => $denied,
			'vip_featured_action'  => $action,
		),
		$redirect_to
	);
}

/**
 * Read a query parameter from an admin listing screen.
 *
 * Every $_GET read in this file funnels through here, so the nonce exemption is stated
 * once with its reasoning rather than repeated at each call site. These values only
 * ever select what is displayed -- the filter to apply, the counts to report back
 * after a bulk action WordPress already nonce-checked. Nothing is mutated from them.
 *
 * @param string $key Parameter name.
 * @return string Sanitized value, or an empty string when absent.
 */
function read_query_param( string $key ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only state on an admin listing screen; see docblock.
	if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
		return '';
	}

	// Unslashing and sanitizing stay in one expression: split across statements, the
	// sniffs cannot follow the value and report it as unsanitized.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only state on an admin listing screen; see docblock.
	return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
}

/**
 * Report what a bulk action did.
 */
function bulk_action_notice(): void {
	$raw_changed = read_query_param( 'vip_featured_changed' );

	if ( '' === $raw_changed ) {
		return;
	}

	$changed = absint( $raw_changed );
	$denied  = absint( read_query_param( 'vip_featured_denied' ) );
	$action  = sanitize_key( read_query_param( 'vip_featured_action' ) );

	if ( $changed > 0 ) {
		$message = BULK_UNFEATURE === $action
			/* translators: %d: number of posts. */
			? sprintf( _n( '%d post removed from featured.', '%d posts removed from featured.', $changed, 'vip-featured-posts' ), $changed )
			/* translators: %d: number of posts. */
			: sprintf( _n( '%d post marked as featured.', '%d posts marked as featured.', $changed, 'vip-featured-posts' ), $changed );

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
						'vip-featured-posts'
					),
					$denied
				)
			)
		);
	}
}

/**
 * Add the Featured checkbox to Quick Edit.
 *
 * The field deliberately reuses the meta box's nonce and field names. Quick Edit
 * submits through WordPress's inline-save, which fires save_post -- so the existing,
 * already-tested save handler picks this up with no second code path to keep correct.
 *
 * @param string $column    Column the field belongs to.
 * @param string $post_type Post type being edited.
 */
function quick_edit_field( string $column, string $post_type ): void {
	if ( COLUMN_ID !== $column || 'post' !== $post_type ) {
		return;
	}

	wp_nonce_field( Meta_Box\NONCE_ACTION, Meta_Box\NONCE_NAME );
	?>
	<fieldset class="inline-edit-col-right">
		<div class="inline-edit-col">
			<label class="alignleft">
				<input type="checkbox" name="<?php echo esc_attr( Meta_Box\FIELD_NAME ); ?>" value="1" />
				<span class="checkbox-title"><?php esc_html_e( 'Featured', 'vip-featured-posts' ); ?></span>
			</label>
		</div>
	</fieldset>
	<?php
}

/**
 * Render the featured filter above the list table.
 *
 * @param string $post_type Post type being listed.
 */
function filter_dropdown( string $post_type ): void {
	if ( 'post' !== $post_type ) {
		return;
	}

	$current = sanitize_key( read_query_param( FILTER_PARAM ) );

	$options = array(
		''             => __( 'All posts', 'vip-featured-posts' ),
		'featured'     => __( 'Featured only', 'vip-featured-posts' ),
		'not_featured' => __( 'Not featured', 'vip-featured-posts' ),
	);

	echo '<select name="' . esc_attr( FILTER_PARAM ) . '">';

	foreach ( $options as $value => $label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	echo '</select>';
}

/**
 * Apply the featured filter to the listing query.
 *
 * Filtering reads the index rather than querying meta, so narrowing the list costs a
 * primary-key comparison instead of an unindexed scan.
 *
 * @param \WP_Query $query Query being prepared.
 */
function apply_filter( \WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'post' !== $query->get( 'post_type' ) ) {
		return;
	}

	$filter = sanitize_key( read_query_param( FILTER_PARAM ) );

	if ( 'featured' !== $filter && 'not_featured' !== $filter ) {
		return;
	}

	$ids = Index\get_ids();

	if ( 'featured' === $filter ) {
		// An empty index means nothing matches. post__in ignores an empty array, so a
		// sentinel that cannot match any post ID is used to force an empty result.
		$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );

		return;
	}

	if ( ! empty( $ids ) ) {
		$query->set( 'post__not_in', $ids );
	}
}

/**
 * Load the toggle script on the posts list screen only.
 *
 * @param string $hook Current admin page.
 */
function enqueue_assets( string $hook ): void {
	if ( 'edit.php' !== $hook ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->post_type ) {
		return;
	}

	wp_enqueue_style( 'dashicons' );

	wp_enqueue_style(
		'vip-featured-posts-admin',
		plugins_url( 'assets/admin.css', VIP_FEATURED_POSTS_FILE ),
		array(),
		VIP_Featured_Posts\VERSION
	);

	wp_enqueue_script(
		'vip-featured-posts-admin',
		plugins_url( 'assets/admin.js', VIP_FEATURED_POSTS_FILE ),
		array(),
		VIP_Featured_Posts\VERSION,
		true
	);

	wp_localize_script(
		'vip-featured-posts-admin',
		'vipFeaturedPosts',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'action'    => AJAX_ACTION,
			'nonce'     => wp_create_nonce( NONCE_ACTION ),
			'fieldName' => Meta_Box\FIELD_NAME,
			'i18n'      => array(
				'feature'   => __( 'Add to featured posts', 'vip-featured-posts' ),
				'unfeature' => __( 'Remove from featured posts', 'vip-featured-posts' ),
				'failed'    => __( 'Could not update the featured flag. Please reload and try again.', 'vip-featured-posts' ),
			),
		)
	);
}

/**
 * Toggle the featured flag for a single post.
 *
 * Authorisation is checked here regardless of what the UI offered: the button being
 * absent from a row does not stop anyone from posting to admin-ajax directly.
 */
function ajax_toggle(): void {
	check_ajax_referer( NONCE_ACTION, 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

	if ( ! $post_id || ! get_post( $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'That post no longer exists.', 'vip-featured-posts' ) ), 404 );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this post.', 'vip-featured-posts' ) ), 403 );
	}

	$featured = '1' === get_post_meta( $post_id, VIP_Featured_Posts\META_KEY, true );

	if ( $featured ) {
		delete_post_meta( $post_id, VIP_Featured_Posts\META_KEY );
	} else {
		update_post_meta( $post_id, VIP_Featured_Posts\META_KEY, '1' );
	}

	wp_send_json_success(
		array(
			'postId'   => $post_id,
			'featured' => ! $featured,
		)
	);
}

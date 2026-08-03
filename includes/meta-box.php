<?php
/**
 * Editor UI for flagging a post as featured.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Meta_Box;

use VIP_Featured_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action for the featured checkbox.
 */
const NONCE_ACTION = 'vip_featured_posts_save';

/**
 * Nonce field name posted alongside the checkbox.
 */
const NONCE_NAME = 'vip_featured_posts_nonce';

/**
 * Name of the checkbox input.
 */
const FIELD_NAME = 'vip_featured_posts_featured';

/**
 * Register the featured flag as post meta.
 *
 * Registering rather than writing raw meta gives us a sanitize_callback on
 * every write path and an auth_callback that gates the protected key.
 */
function register_meta(): void {
	register_post_meta(
		'post',
		VIP_Featured_Posts\META_KEY,
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			// Protected meta stays out of the REST post object; the plugin
			// exposes its own read-only endpoint instead.
			'show_in_rest'      => false,
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_meta',
			'auth_callback'     => __NAMESPACE__ . '\\auth_meta',
		)
	);
}

/**
 * Normalise the stored value to either '1' or an empty string.
 *
 * @param mixed $value Incoming meta value.
 * @return string Sanitized meta value.
 */
function sanitize_meta( $value ): string {
	return '1' === (string) $value ? '1' : '';
}

/**
 * Authorise writes to the protected featured meta key.
 *
 * @param bool  $allowed   Whether the user can add the meta. Unused; we decide from the capability.
 * @param mixed $meta_key  Meta key being written. Unused.
 * @param mixed $object_id ID of the post being written to.
 * @return bool Whether the current user may write this meta.
 */
function auth_meta( $allowed, $meta_key, $object_id ): bool {
	return current_user_can( 'edit_post', (int) $object_id );
}

/**
 * Add the Featured meta box to the post editor.
 */
function add_meta_box(): void {
	\add_meta_box(
		'vip-featured-posts',
		__( 'Featured', 'vip-featured-posts' ),
		__NAMESPACE__ . '\\render',
		'post',
		'side',
		'default'
	);
}

/**
 * Render the meta box contents.
 *
 * @param \WP_Post $post Post being edited.
 */
function render( \WP_Post $post ): void {
	$is_featured = '1' === get_post_meta( $post->ID, VIP_Featured_Posts\META_KEY, true );

	wp_nonce_field( NONCE_ACTION, NONCE_NAME );
	?>
	<p>
		<label for="<?php echo esc_attr( FIELD_NAME ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( FIELD_NAME ); ?>"
				name="<?php echo esc_attr( FIELD_NAME ); ?>"
				value="1"
				<?php checked( $is_featured ); ?>
			/>
			<?php esc_html_e( 'Mark this post as featured', 'vip-featured-posts' ); ?>
		</label>
	</p>
	<p class="description">
		<?php esc_html_e( 'Featured posts appear in the Featured Posts block and the public REST endpoint.', 'vip-featured-posts' ); ?>
	</p>
	<?php
}

/**
 * Persist the featured flag.
 *
 * Guards in order: skip autosaves, verify the nonce, then confirm the current
 * user may edit this specific post. Every superglobal read is unslashed and
 * sanitized before use.
 *
 * @param int $post_id Post being saved.
 */
function save( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$nonce = isset( $_POST[ NONCE_NAME ] )
		? sanitize_text_field( wp_unslash( $_POST[ NONCE_NAME ] ) )
		: '';

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, NONCE_ACTION ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$submitted = isset( $_POST[ FIELD_NAME ] )
		? sanitize_text_field( wp_unslash( $_POST[ FIELD_NAME ] ) )
		: '';

	if ( '1' === $submitted ) {
		update_post_meta( $post_id, VIP_Featured_Posts\META_KEY, '1' );
	} else {
		delete_post_meta( $post_id, VIP_Featured_Posts\META_KEY );
	}
}

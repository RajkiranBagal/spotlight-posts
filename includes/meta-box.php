<?php
/**
 * Editor UI for flagging a post as featured.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Meta_Box;

use Spotlight_Posts;
use Spotlight_Posts\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action for the featured checkbox.
 */
const NONCE_ACTION = 'spotlight_posts_save';

/**
 * Nonce field name posted alongside the checkbox.
 */
const NONCE_NAME = 'spotlight_posts_nonce';

/**
 * Name of the checkbox input.
 */
const FIELD_NAME = 'spotlight_posts_featured';

/**
 * Name of the "featured until" input.
 */
const UNTIL_FIELD_NAME = 'spotlight_posts_until';

/**
 * Register the featured flag as post meta.
 *
 * Registering rather than writing raw meta gives us a sanitize_callback on
 * every write path and an auth_callback that gates the protected key.
 */
function register_meta(): void {
	register_post_meta(
		'post',
		Spotlight_Posts\META_KEY,
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
		'spotlight-posts',
		__( 'Featured', 'spotlight-posts' ),
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
	$is_featured = '1' === get_post_meta( $post->ID, Spotlight_Posts\META_KEY, true );

	$expiry = Schedule\get_expiry( $post->ID );

	// datetime-local speaks local wall-clock time, so the stored UTC timestamp is
	// converted into the site's timezone for display and back again on save.
	$until_value = $expiry > 0
		? wp_date( 'Y-m-d\TH:i', $expiry )
		: '';

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
			<?php esc_html_e( 'Mark this post as featured', 'spotlight-posts' ); ?>
		</label>
	</p>
	<p>
		<label for="<?php echo esc_attr( UNTIL_FIELD_NAME ); ?>">
			<?php esc_html_e( 'Featured until', 'spotlight-posts' ); ?>
		</label>
		<input
			type="datetime-local"
			id="<?php echo esc_attr( UNTIL_FIELD_NAME ); ?>"
			name="<?php echo esc_attr( UNTIL_FIELD_NAME ); ?>"
			value="<?php echo esc_attr( $until_value ); ?>"
			class="widefat"
		/>
	</p>
	<p class="description">
		<?php esc_html_e( 'Leave blank to keep the post featured until it is removed. Times are in the site timezone.', 'spotlight-posts' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Featured posts appear in the Featured Posts block and the public REST endpoint.', 'spotlight-posts' ); ?>
	</p>
	<?php
}

/**
 * Is this request an autosave?
 *
 * WordPress has wp_doing_ajax() and wp_doing_cron() but no autosave equivalent, so this
 * follows the same shape: read the constant, expose it through a filter. That keeps the
 * guard verifiable without a test having to define( 'DOING_AUTOSAVE' ) -- a constant,
 * once defined, persists for the whole process and would silently disable saving for
 * every test that ran afterwards.
 *
 * @return bool Whether WordPress is performing an autosave.
 */
function is_autosave(): bool {
	/**
	 * Filters whether the current request is treated as an autosave.
	 *
	 * @param bool $is_autosave Whether this is an autosave.
	 */
	return (bool) apply_filters(
		'spotlight_posts_is_autosave',
		defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE
	);
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
	if ( is_autosave() ) {
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

	if ( '1' !== $submitted ) {
		// Deleting the flag also clears any pending expiry, via the hook in
		// Schedule\clear_on_unfeature(). Nothing to do here.
		delete_post_meta( $post_id, Spotlight_Posts\META_KEY );

		return;
	}

	update_post_meta( $post_id, Spotlight_Posts\META_KEY, '1' );

	$until = isset( $_POST[ UNTIL_FIELD_NAME ] )
		? sanitize_text_field( wp_unslash( $_POST[ UNTIL_FIELD_NAME ] ) )
		: '';

	Schedule\set_expiry( $post_id, parse_until( $until ) );
}

/**
 * Convert a datetime-local value into a UTC timestamp.
 *
 * The browser sends wall-clock time with no offset, so it has to be interpreted in the
 * site's timezone rather than the server's -- otherwise "featured until 5pm" means
 * something different depending on where the server happens to sit.
 *
 * @param string $value Raw datetime-local value, e.g. "2026-08-07T17:00".
 * @return int UTC timestamp, or 0 when empty or unparseable.
 */
function parse_until( string $value ): int {
	$value = trim( $value );

	if ( '' === $value ) {
		return 0;
	}

	try {
		$date = new \DateTimeImmutable( $value, wp_timezone() );
	} catch ( \Exception $e ) {
		// An unparseable value means no expiry rather than an arbitrary one. The field
		// is a native datetime input, so this is a malformed or hand-crafted request.
		return 0;
	}

	return $date->getTimestamp();
}

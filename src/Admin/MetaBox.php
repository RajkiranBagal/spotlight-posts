<?php
/**
 * Editor UI for flagging a post as featured.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Featured\Meta;
use Spotlight_Posts\Featured\Schedule;
use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\PostTypes;
use Spotlight_Posts\Support\Request;

defined( 'ABSPATH' ) || exit;

/**
 * The Featured checkbox and expiry field in the post sidebar.
 */
final class MetaBox implements Registrable {

	/**
	 * Nonce action for the featured checkbox.
	 */
	public const NONCE_ACTION = 'spotlight_posts_save';

	/**
	 * Nonce field name posted alongside the checkbox.
	 */
	public const NONCE_NAME = 'spotlight_posts_nonce';

	/**
	 * Name of the checkbox input.
	 */
	public const FIELD_NAME = 'spotlight_posts_featured';

	/**
	 * Name of the "featured until" input.
	 */
	public const UNTIL_FIELD_NAME = 'spotlight_posts_until';

	/**
	 * Name of the editorial label input.
	 */
	public const LABEL_FIELD_NAME = 'spotlight_posts_label';

	/**
	 * Expiry scheduler.
	 *
	 * @var Schedule
	 */
	private Schedule $schedule;

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
	 * @param Schedule  $schedule   Expiry scheduler.
	 * @param PostTypes $post_types Supported post types.
	 * @param Request   $request    Request reader.
	 */
	public function __construct( Schedule $schedule, PostTypes $post_types, Request $request ) {
		$this->schedule   = $schedule;
		$this->post_types = $post_types;
		$this->request    = $request;
	}

	/**
	 * Register meta, the box itself and the save handler.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_per_type' ), 20 );
	}

	/**
	 * Attach the per-type box and save hooks.
	 */
	public function register_per_type(): void {
		foreach ( $this->post_types->all() as $post_type ) {
			add_action( "add_meta_boxes_{$post_type}", array( $this, 'add' ) );
			add_action( "save_post_{$post_type}", array( $this, 'save' ) );
		}
	}

	/**
	 * Add the box to the editor.
	 */
	public function add(): void {
		add_meta_box(
			'spotlight-posts',
			__( 'Featured', 'spotlight-posts' ),
			array( $this, 'render' ),
			$this->post_types->all(),
			'side',
			'default'
		);
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render( \WP_Post $post ): void {
		$is_featured = '1' === get_post_meta( $post->ID, Index::META_KEY, true );

		$expiry = $this->schedule->expiry_for( $post->ID );

		// datetime-local speaks local wall-clock time, so the stored UTC timestamp is
		// converted into the site's timezone for display and back again on save.
		$until_value = $expiry > 0 ? wp_date( 'Y-m-d\TH:i', $expiry ) : '';

		$label = (string) get_post_meta( $post->ID, Meta::LABEL_KEY, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label for="<?php echo esc_attr( self::FIELD_NAME ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( self::FIELD_NAME ); ?>"
					name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
					value="1"
					<?php checked( $is_featured ); ?>
				/>
				<?php esc_html_e( 'Mark this post as featured', 'spotlight-posts' ); ?>
			</label>
		</p>
		<p>
			<label for="<?php echo esc_attr( self::UNTIL_FIELD_NAME ); ?>">
				<?php esc_html_e( 'Featured until', 'spotlight-posts' ); ?>
			</label>
			<input
				type="datetime-local"
				id="<?php echo esc_attr( self::UNTIL_FIELD_NAME ); ?>"
				name="<?php echo esc_attr( self::UNTIL_FIELD_NAME ); ?>"
				value="<?php echo esc_attr( $until_value ); ?>"
				class="widefat"
			/>
		</p>
		<p class="description">
			<?php esc_html_e( 'Leave blank to keep the post featured until it is removed. Times are in the site timezone.', 'spotlight-posts' ); ?>
		</p>
		<p>
			<label for="<?php echo esc_attr( self::LABEL_FIELD_NAME ); ?>">
				<?php esc_html_e( 'Label', 'spotlight-posts' ); ?>
			</label>
			<input
				type="text"
				id="<?php echo esc_attr( self::LABEL_FIELD_NAME ); ?>"
				name="<?php echo esc_attr( self::LABEL_FIELD_NAME ); ?>"
				value="<?php echo esc_attr( $label ); ?>"
				maxlength="<?php echo esc_attr( (string) Meta::LABEL_MAX_LENGTH ); ?>"
				class="widefat"
				placeholder="<?php esc_attr_e( 'Editor&#8217;s pick', 'spotlight-posts' ); ?>"
			/>
		</p>
		<p class="description">
			<?php esc_html_e( 'Optional badge shown above the title wherever this post is featured.', 'spotlight-posts' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Featured posts appear in the Featured Posts block and the public REST endpoint.', 'spotlight-posts' ); ?>
		</p>
		<?php
	}

	/**
	 * Is this request an autosave?
	 *
	 * WordPress has wp_doing_ajax() and wp_doing_cron() but no autosave equivalent, so
	 * this follows the same shape: read the constant, expose it through a filter. That
	 * keeps the guard verifiable without a test having to define( 'DOING_AUTOSAVE' ) --
	 * a constant, once defined, persists for the whole process and would silently disable
	 * saving for every test that ran afterwards.
	 *
	 * @return bool Whether WordPress is performing an autosave.
	 */
	public function is_autosave(): bool {
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
	 * Guards in order, cheapest and most likely to bail first: skip autosaves, verify the
	 * nonce, then confirm the current user may edit this specific post.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save( int $post_id ): void {
		if ( $this->is_autosave() ) {
			return;
		}

		$nonce = $this->request->post( self::NONCE_NAME );

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( '1' !== $this->request->post( self::FIELD_NAME ) ) {
			// Deleting the flag also clears any pending expiry, via Schedule's hook.
			delete_post_meta( $post_id, Index::META_KEY );

			return;
		}

		update_post_meta( $post_id, Index::META_KEY, '1' );

		$this->schedule->set_expiry(
			$post_id,
			$this->parse_until( $this->request->post( self::UNTIL_FIELD_NAME ) )
		);

		// Sanitizing and truncating happen in the registered sanitize_callback, so this
		// deliberately hands over the raw submitted value rather than pre-trimming it in
		// a second place that could drift.
		$label = $this->request->post( self::LABEL_FIELD_NAME );

		if ( '' === $label ) {
			delete_post_meta( $post_id, Meta::LABEL_KEY );
		} else {
			update_post_meta( $post_id, Meta::LABEL_KEY, $label );
		}
	}

	/**
	 * Convert a datetime-local value into a UTC timestamp.
	 *
	 * The browser sends wall-clock time with no offset, so it has to be interpreted in the
	 * site's timezone rather than the server's -- otherwise "featured until 5pm" means a
	 * different moment depending on where the server sits.
	 *
	 * @param string $value Raw datetime-local value, e.g. "2026-08-07T17:00".
	 * @return int UTC timestamp, or 0 when empty or unparseable.
	 */
	public function parse_until( string $value ): int {
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
}

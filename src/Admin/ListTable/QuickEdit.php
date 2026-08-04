<?php
/**
 * The Featured checkbox in Quick Edit.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Admin\ListTable;

use Spotlight_Posts\Admin\MetaBox;
use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Offers the featured flag alongside the other inline fields.
 *
 * The field deliberately reuses the meta box's nonce and field names. Quick Edit submits
 * through WordPress's inline-save, which fires save_post -- so the existing, already
 * tested save handler picks this up with no second write path to keep correct.
 */
final class QuickEdit implements Registrable {

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

	/**
	 * @param PostTypes $post_types Supported post types.
	 */
	public function __construct( PostTypes $post_types ) {
		$this->post_types = $post_types;
	}

	/**
	 * Register the inline field.
	 */
	public function register(): void {
		add_action( 'quick_edit_custom_box', array( $this, 'render' ), 10, 2 );
	}

	/**
	 * Render the checkbox.
	 *
	 * @param string $column    Column the field belongs to.
	 * @param string $post_type Post type being edited.
	 */
	public function render( string $column, string $post_type ): void {
		if ( Column::COLUMN_ID !== $column || ! $this->post_types->supports( $post_type ) ) {
			return;
		}

		wp_nonce_field( MetaBox::NONCE_ACTION, MetaBox::NONCE_NAME );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<input type="checkbox" name="<?php echo esc_attr( MetaBox::FIELD_NAME ); ?>" value="1" />
					<span class="checkbox-title"><?php esc_html_e( 'Featured', 'spotlight-posts' ); ?></span>
				</label>
			</div>
		</fieldset>
		<?php
	}
}

<?php
/**
 * Featured variation of core's Query Loop block.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Frontend;

use Spotlight_Posts\Featured\Repository;
use Spotlight_Posts\Registrable;

defined( 'ABSPATH' ) || exit;

/**
 * Narrows core's Query Loop to the spotlighted posts.
 *
 * Core already ships the "cards with toggleable parts" system people try to rebuild:
 * Query Loop, Post Template, Post Title, Post Featured Image, Post Excerpt, Post Date.
 * This contributes the one thing core cannot know -- which posts are featured, and in
 * what order -- and lets core own the rest.
 */
final class QueryLoopVariation implements Registrable {

	/**
	 * Namespace identifying this variation.
	 */
	public const VARIATION = 'spotlight-posts/featured-query';

	/**
	 * Built editor script, relative to the plugin root.
	 */
	private const BUILD_PATH = 'build/query-loop';

	/**
	 * Source of the eligible post IDs.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Absolute path to the plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Plugin main file, for building asset URLs.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin version, used to bust asset caches.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * @param Repository $repository  Source of the eligible post IDs.
	 * @param string     $plugin_dir  Absolute path to the plugin directory.
	 * @param string     $plugin_file Plugin main file.
	 * @param string     $version     Plugin version.
	 */
	public function __construct( Repository $repository, string $plugin_dir, string $plugin_file, string $version ) {
		$this->repository  = $repository;
		$this->plugin_dir  = rtrim( $plugin_dir, '/' ) . '/';
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Wire up the front-end filter, the editor script and the REST pair.
	 */
	public function register(): void {
		add_filter( 'query_loop_block_query_vars', array( $this, 'filter_query_vars' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'rest_post_collection_params', array( $this, 'add_rest_collection_param' ) );
		add_filter( 'rest_post_query', array( $this, 'filter_rest_query' ), 10, 2 );
	}

	/**
	 * Is this block our variation?
	 *
	 * The documented way to identify a variation server-side is a top-level `namespace`
	 * attribute, and it does not work here. core/query provides only `query` and
	 * `enhancedPagination` as block context, and query_loop_block_query_vars receives the
	 * *Post Template*, not the Query block -- so a top-level attribute never arrives. The
	 * variation therefore also writes the namespace inside `query`, which is what this
	 * reads. Verified against WordPress 6.7.
	 *
	 * @param \WP_Block|null $block Block being rendered.
	 * @return bool Whether the block is the featured variation.
	 */
	public function is_featured_variation( $block ): bool {
		if ( ! $block instanceof \WP_Block ) {
			return false;
		}

		return self::VARIATION === ( $block->context['query']['namespace'] ?? '' );
	}

	/**
	 * Constrain a Query Loop to the spotlighted posts.
	 *
	 * Applied to the query vars core has already assembled, so pagination, the post type
	 * and every other editor control keep working -- this only narrows which posts are
	 * eligible and fixes their order.
	 *
	 * @param array     $query Query vars core built.
	 * @param \WP_Block $block Block being rendered.
	 * @param int       $page  Page number. Unused.
	 * @return array Query vars.
	 */
	public function filter_query_vars( array $query, $block, $page ): array {
		if ( ! $this->is_featured_variation( $block ) ) {
			return $query;
		}

		$query['post__in'] = $this->repository->eligible_ids();
		$query['orderby']  = 'post__in';

		// orderby post__in has no meaningful direction, and a stale ASC/DESC makes the
		// result order look arbitrary.
		unset( $query['order'] );

		return $query;
	}

	/**
	 * Load the editor script that registers the variation.
	 *
	 * core/query variations have no PHP equivalent, so the variation itself is JavaScript.
	 */
	public function enqueue_editor_assets(): void {
		$asset_file = $this->plugin_dir . self::BUILD_PATH . '/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'spotlight-posts-query-loop',
			plugins_url( self::BUILD_PATH . '/index.js', $this->plugin_file ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'spotlight-posts-query-loop', 'spotlight-posts' );
	}

	/**
	 * Advertise a collection parameter the editor can send.
	 *
	 * The editor previews a Query Loop by calling the REST posts collection directly, and
	 * core does not forward a variation's namespace there.
	 *
	 * @param array $args Collection parameters.
	 * @return array Parameters with ours added.
	 */
	public function add_rest_collection_param( array $args ): array {
		$args['spotlight_featured'] = array(
			'description' => __( 'Limit results to spotlighted posts.', 'spotlight-posts' ),
			'type'        => 'boolean',
			'default'     => false,
		);

		return $args;
	}

	/**
	 * Apply the constraint to an editor REST request.
	 *
	 * @param array            $args    Query args core assembled.
	 * @param \WP_REST_Request $request Incoming request.
	 * @return array Query args.
	 */
	public function filter_rest_query( array $args, $request ): array {
		if ( ! $request instanceof \WP_REST_Request || ! $request->get_param( 'spotlight_featured' ) ) {
			return $args;
		}

		$args['post__in'] = $this->repository->eligible_ids();
		$args['orderby']  = 'post__in';

		unset( $args['order'] );

		return $args;
	}
}

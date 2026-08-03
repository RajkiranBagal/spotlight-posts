<?php
/**
 * Featured variation of core's Query Loop block.
 *
 * Core already ships the "cards with toggleable parts" system people usually try to
 * rebuild: Query Loop, Post Template, Post Title, Post Featured Image, Post Excerpt,
 * Post Date. Rebuilding it means owning a card layout engine, a styling UI, theme.json
 * integration, and missing every future core improvement.
 *
 * So this contributes the one thing core cannot know -- which posts are featured, and in
 * what order -- and lets core own the rest.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Query_Loop;

use VIP_Featured_Posts;
use VIP_Featured_Posts\Index;
use VIP_Featured_Posts\Query;
use VIP_Featured_Posts\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace identifying our Query Loop variation.
 *
 * Core stores this on the block's query attribute and exposes it through block context,
 * which is how the filters below recognise our variation rather than every Query Loop on
 * the site.
 */
const VARIATION = 'vip-featured-posts/featured-query';

/**
 * Post IDs eligible for display, in index order.
 *
 * Shares the expiry rule with the dedicated block rather than reimplementing it, so a
 * scheduled post disappears from both display paths at the same moment. Reading the
 * index is a single autoloaded option; the expiry check reads meta that the subsequent
 * post query primes anyway.
 *
 * @return int[] Featured post IDs, or a sentinel that matches nothing.
 */
function get_eligible_ids(): array {
	$ids = array();

	foreach ( Index\get_ids() as $post_id ) {
		if ( ! Schedule\is_expired( $post_id ) ) {
			$ids[] = $post_id;
		}
	}

	/*
	 * post__in ignores an empty array, so an empty featured list would silently widen
	 * the Query Loop to every post -- the opposite of what the variation asks for. A
	 * sentinel that cannot match any post ID forces an empty result instead.
	 */
	return empty( $ids ) ? array( 0 ) : $ids;
}

/**
 * Is this block our variation?
 *
 * @param \WP_Block|null $block Block being rendered.
 * @return bool Whether the block is the featured variation.
 */
function is_featured_variation( $block ): bool {
	if ( ! $block instanceof \WP_Block ) {
		return false;
	}

	$namespace = $block->context['query']['namespace'] ?? '';

	return VARIATION === $namespace;
}

/**
 * Constrain a Query Loop to the featured posts.
 *
 * Applied to the query vars core has already assembled, so pagination, the post type and
 * every other control the editor exposes keep working -- this only narrows which posts
 * are eligible and fixes their order.
 *
 * @param array         $query Query vars core built for this block.
 * @param \WP_Block     $block Block being rendered.
 * @param int           $page  Page number. Unused.
 * @return array Query vars.
 */
function filter_query_vars( array $query, $block, $page ): array {
	if ( ! is_featured_variation( $block ) ) {
		return $query;
	}

	$query['post__in'] = get_eligible_ids();
	$query['orderby']  = 'post__in';

	// orderby post__in has no meaningful direction, and leaving a stale ASC/DESC in
	// place makes the result order look arbitrary.
	unset( $query['order'] );

	return $query;
}

/**
 * Register the block bindings the editor script needs.
 *
 * The variation itself is registered in JavaScript -- core/query variations have no PHP
 * equivalent -- so this only enqueues that script.
 */
function enqueue_editor_assets(): void {
	$asset_file = VIP_FEATURED_POSTS_DIR . 'build/query-loop/index.asset.php';

	// Registration no-ops without a build, matching how the dedicated block behaves.
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'vip-featured-posts-query-loop',
		plugins_url( 'build/query-loop/index.js', VIP_FEATURED_POSTS_FILE ),
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_set_script_translations( 'vip-featured-posts-query-loop', 'vip-featured-posts' );
}

/**
 * Expose the featured IDs to the editor.
 *
 * The editor previews a Query Loop by calling the REST posts collection directly, and
 * core does not forward a variation's namespace to that request -- so the preview would
 * otherwise show every post while the front end showed only featured ones.
 *
 * Registering an explicit `vip_featured` collection parameter closes that gap: the
 * variation's edit component sends it, and the filter below applies the same constraint
 * the front end uses.
 *
 * @param array $args Collection parameters.
 * @return array Parameters with ours added.
 */
function add_rest_collection_param( array $args ): array {
	$args['vip_featured'] = array(
		'description' => __( 'Limit results to posts flagged as featured.', 'vip-featured-posts' ),
		'type'        => 'boolean',
		'default'     => false,
	);

	return $args;
}

/**
 * Apply the featured constraint to an editor REST request.
 *
 * @param array            $args    Query args core assembled.
 * @param \WP_REST_Request $request Incoming request.
 * @return array Query args.
 */
function filter_rest_query( array $args, $request ): array {
	if ( ! $request instanceof \WP_REST_Request || ! $request->get_param( 'vip_featured' ) ) {
		return $args;
	}

	$args['post__in'] = get_eligible_ids();
	$args['orderby']  = 'post__in';

	unset( $args['order'] );

	return $args;
}

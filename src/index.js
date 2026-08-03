/**
 * A "Featured Posts" variation of core's Query Loop block.
 *
 * The variation contributes the one thing core cannot know — which posts are featured
 * and in what order — and leaves everything else to core: Post Template, Post Title,
 * Post Featured Image, Post Excerpt, theme.json styling, and every future improvement
 * to those blocks.
 *
 * The `namespace` attribute is what the PHP side keys off. Core stores it on the query
 * attribute and exposes it through block context, so the server can recognise this
 * variation without affecting any other Query Loop on the site.
 */

import { registerBlockVariation } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

const VARIATION = 'spotlight-posts/featured-query';

registerBlockVariation( 'core/query', {
	name: VARIATION,
	title: __( 'Featured Posts', 'spotlight-posts' ),
	description: __(
		'Displays posts flagged as featured, in the order set under Posts → Featured Order.',
		'spotlight-posts'
	),
	icon: 'star-filled',
	category: 'theme',
	keywords: [
		__( 'featured', 'spotlight-posts' ),
		__( 'promoted', 'spotlight-posts' ),
	],

	attributes: {
		// Deliberately set in two places, and both are load-bearing.
		//
		// The top-level `namespace` is what core matches variations on, so `isActive`
		// and the editor's variation UI need it there.
		//
		// The copy inside `query` is what the server can actually see. core/query
		// provides only `query` and `enhancedPagination` as block context, so a
		// top-level attribute never reaches the Post Template — and it is the Post
		// Template that `query_loop_block_query_vars` receives. Verified against
		// WordPress 6.7: reading `context['query']['namespace']` returns the value,
		// reading a top-level namespace returns nothing at all.
		namespace: VARIATION,
		query: {
			namespace: VARIATION,
			perPage: 5,
			pages: 0,
			offset: 0,
			postType: 'post',
			// Ordering is imposed server-side from the featured index, so the editor's
			// order controls would be misleading if they appeared to do anything.
			order: 'desc',
			orderBy: 'date',
			author: '',
			search: '',
			exclude: [],
			sticky: '',
			inherit: false,
		},
	},

	// Without this the variation is indistinguishable from a plain Query Loop once
	// inserted, and the editor would offer the generic block's controls instead.
	isActive: ( blockAttributes ) => blockAttributes.namespace === VARIATION,

	scope: [ 'inserter' ],

	innerBlocks: [
		[
			'core/post-template',
			{},
			[
				[ 'core/post-featured-image', { isLink: true } ],
				[ 'core/post-title', { isLink: true, level: 3 } ],
				[ 'core/post-excerpt', {} ],
			],
		],
	],
} );

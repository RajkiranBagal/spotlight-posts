/**
 * Editor entry point for the Featured Posts block.
 *
 * The block is dynamic: save() returns null and PHP renders the markup, so the
 * editor previews it through ServerSideRender rather than duplicating the
 * template in JavaScript.
 */

import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: ( { attributes, setAttributes } ) => {
		const blockProps = useBlockProps();
		const { heading, numberOfPosts } = attributes;

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __( 'Featured Posts settings', 'vip-featured-posts' ) }
					>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Heading', 'vip-featured-posts' ) }
							help={ __(
								'Optional heading shown above the list.',
								'vip-featured-posts'
							) }
							value={ heading }
							onChange={ ( value ) =>
								setAttributes( { heading: value } )
							}
						/>
						<RangeControl
							__nextHasNoMarginBottom
							label={ __(
								'Number of posts',
								'vip-featured-posts'
							) }
							value={ numberOfPosts }
							onChange={ ( value ) =>
								setAttributes( { numberOfPosts: value } )
							}
							min={ 1 }
							max={ 10 }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
					/>
				</div>
			</>
		);
	},

	// Dynamic block: the server owns the markup.
	save: () => null,
} );

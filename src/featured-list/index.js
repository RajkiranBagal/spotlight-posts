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
		const { heading, numberOfPosts, headingLevel } = attributes;

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __( 'Featured Posts settings', 'spotlight-posts' ) }
					>
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Heading', 'spotlight-posts' ) }
							help={ __(
								'Optional heading shown above the list.',
								'spotlight-posts'
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
								'spotlight-posts'
							) }
							value={ numberOfPosts }
							onChange={ ( value ) =>
								setAttributes( { numberOfPosts: value } )
							}
							min={ 1 }
							max={ 10 }
						/>
						{ /*
						 * A fixed h2 breaks the document outline wherever this block is
						 * not actually the second level on the page — which is how
						 * screen reader users navigate. h1 is deliberately not offered:
						 * it belongs to the page title.
						 */ }
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Heading level', 'spotlight-posts' ) }
							help={ __(
								'Match the surrounding page so the heading order stays correct.',
								'spotlight-posts'
							) }
							value={ headingLevel }
							onChange={ ( value ) =>
								setAttributes( { headingLevel: value } )
							}
							min={ 2 }
							max={ 6 }
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

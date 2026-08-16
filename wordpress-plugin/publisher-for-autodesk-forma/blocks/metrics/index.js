/**
 * Editor UI for the Forma metrics block.
 *
 * Written without JSX so the plugin ships without a build step.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'forma-publisher/metrics', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			function update( key ) {
				return function ( value ) {
					var next = {};
					next[ key ] = value;
					setAttributes( next );
				};
			}

			var body = attributes.projectId
				? el( ServerSideRender, {
						block: 'forma-publisher/metrics',
						attributes: attributes
				  } )
				: el(
						Placeholder,
						{
							label: __( 'Forma Metrics', 'publisher-for-autodesk-forma' ),
							instructions: __(
								'Enter a project post ID or an Autodesk Forma source ID to display its metrics.',
								'publisher-for-autodesk-forma'
							)
						},
						el( TextControl, {
							label: __( 'Project', 'publisher-for-autodesk-forma' ),
							value: attributes.projectId,
							onChange: update( 'projectId' )
						} )
				  );

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Metrics', 'publisher-for-autodesk-forma' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Post ID or source ID', 'publisher-for-autodesk-forma' ),
							value: attributes.projectId,
							onChange: update( 'projectId' )
						} ),
						el( SelectControl, {
							label: __( 'Layout', 'publisher-for-autodesk-forma' ),
							value: attributes.layout,
							options: [
								{ label: __( 'Table', 'publisher-for-autodesk-forma' ), value: 'table' },
								{ label: __( 'Cards', 'publisher-for-autodesk-forma' ), value: 'cards' }
							],
							onChange: update( 'layout' )
						} ),
						el( TextControl, {
							label: __( 'Filter by category', 'publisher-for-autodesk-forma' ),
							help: __( 'Comma separated list of metric categories.', 'publisher-for-autodesk-forma' ),
							value: attributes.category,
							onChange: update( 'category' )
						} ),
						el( TextControl, {
							label: __( 'Filter by metric keys', 'publisher-for-autodesk-forma' ),
							help: __( 'Comma separated list of metric keys.', 'publisher-for-autodesk-forma' ),
							value: attributes.keys,
							onChange: update( 'keys' )
						} )
					)
				),
				body
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );

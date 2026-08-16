/**
 * Editor UI for the Forma assets block.
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
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'forma-publisher/assets', {
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
						block: 'forma-publisher/assets',
						attributes: attributes
				  } )
				: el(
						Placeholder,
						{
							label: __( 'Forma Assets', 'publisher-for-autodesk-forma' ),
							instructions: __(
								'Enter a project post ID or an Autodesk Forma source ID to list its assets.',
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
						{ title: __( 'Assets', 'publisher-for-autodesk-forma' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Post ID or source ID', 'publisher-for-autodesk-forma' ),
							value: attributes.projectId,
							onChange: update( 'projectId' )
						} ),
						el( SelectControl, {
							label: __( 'Only show', 'publisher-for-autodesk-forma' ),
							value: attributes.kind,
							options: [
								{ label: __( 'All kinds', 'publisher-for-autodesk-forma' ), value: '' },
								{ label: __( 'Images', 'publisher-for-autodesk-forma' ), value: 'image' },
								{ label: __( 'Documents', 'publisher-for-autodesk-forma' ), value: 'document' },
								{ label: __( 'Models', 'publisher-for-autodesk-forma' ), value: 'model' },
								{ label: __( 'Datasets', 'publisher-for-autodesk-forma' ), value: 'dataset' },
								{ label: __( 'Links', 'publisher-for-autodesk-forma' ), value: 'link' }
							],
							onChange: update( 'kind' )
						} ),
						el( RangeControl, {
							label: __( 'Maximum assets', 'publisher-for-autodesk-forma' ),
							value: attributes.limit,
							onChange: update( 'limit' ),
							min: 1,
							max: 200
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

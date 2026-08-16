/**
 * Editor UI for the Forma project block.
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
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'forma-publisher/project', {
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
						block: 'forma-publisher/project',
						attributes: attributes
				  } )
				: el(
						Placeholder,
						{
							label: __( 'Forma Project', 'publisher-for-autodesk-forma' ),
							instructions: __(
								'Enter a project post ID or an Autodesk Forma source ID to display.',
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
						{ title: __( 'Project', 'publisher-for-autodesk-forma' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Post ID or source ID', 'publisher-for-autodesk-forma' ),
							value: attributes.projectId,
							onChange: update( 'projectId' )
						} ),
						el( ToggleControl, {
							label: __( 'Show thumbnail', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showThumbnail,
							onChange: update( 'showThumbnail' )
						} ),
						el( ToggleControl, {
							label: __( 'Show description', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showContent,
							onChange: update( 'showContent' )
						} ),
						el( ToggleControl, {
							label: __( 'Show metrics', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showMetrics,
							onChange: update( 'showMetrics' )
						} ),
						el( ToggleControl, {
							label: __( 'Show assets', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showAssets,
							onChange: update( 'showAssets' )
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

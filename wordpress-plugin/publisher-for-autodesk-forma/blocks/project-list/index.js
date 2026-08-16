/**
 * Editor UI for the Forma project list block.
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
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'forma-publisher/project-list', {
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

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Query', 'publisher-for-autodesk-forma' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Number of projects', 'publisher-for-autodesk-forma' ),
							value: attributes.limit,
							onChange: update( 'limit' ),
							min: 1,
							max: 50
						} ),
						el( RangeControl, {
							label: __( 'Columns', 'publisher-for-autodesk-forma' ),
							value: attributes.columns,
							onChange: update( 'columns' ),
							min: 1,
							max: 6
						} ),
						el( SelectControl, {
							label: __( 'Order by', 'publisher-for-autodesk-forma' ),
							value: attributes.orderby,
							options: [
								{ label: __( 'Date', 'publisher-for-autodesk-forma' ), value: 'date' },
								{ label: __( 'Title', 'publisher-for-autodesk-forma' ), value: 'title' },
								{ label: __( 'Last modified', 'publisher-for-autodesk-forma' ), value: 'modified' },
								{ label: __( 'Menu order', 'publisher-for-autodesk-forma' ), value: 'menu_order' },
								{ label: __( 'Random', 'publisher-for-autodesk-forma' ), value: 'rand' }
							],
							onChange: update( 'orderby' )
						} ),
						el( SelectControl, {
							label: __( 'Order', 'publisher-for-autodesk-forma' ),
							value: attributes.order,
							options: [
								{ label: __( 'Descending', 'publisher-for-autodesk-forma' ), value: 'DESC' },
								{ label: __( 'Ascending', 'publisher-for-autodesk-forma' ), value: 'ASC' }
							],
							onChange: update( 'order' )
						} ),
						el( TextControl, {
							label: __( 'Filter by tag slugs', 'publisher-for-autodesk-forma' ),
							help: __( 'Comma separated list of Forma tag slugs.', 'publisher-for-autodesk-forma' ),
							value: attributes.tag,
							onChange: update( 'tag' )
						} ),
						el( TextControl, {
							label: __( 'Filter by status slugs', 'publisher-for-autodesk-forma' ),
							help: __( 'Comma separated list of Forma status slugs.', 'publisher-for-autodesk-forma' ),
							value: attributes.status,
							onChange: update( 'status' )
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Display', 'publisher-for-autodesk-forma' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Show thumbnails', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showThumbnail,
							onChange: update( 'showThumbnail' )
						} ),
						el( ToggleControl, {
							label: __( 'Show excerpts', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showExcerpt,
							onChange: update( 'showExcerpt' )
						} ),
						el( ToggleControl, {
							label: __( 'Show top metrics', 'publisher-for-autodesk-forma' ),
							checked: !! attributes.showMetrics,
							onChange: update( 'showMetrics' )
						} )
					)
				),
				el( ServerSideRender, {
					block: 'forma-publisher/project-list',
					attributes: attributes
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );

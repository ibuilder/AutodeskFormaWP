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
						{ title: __( 'Query', 'forma-publisher' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Number of projects', 'forma-publisher' ),
							value: attributes.limit,
							onChange: update( 'limit' ),
							min: 1,
							max: 50
						} ),
						el( RangeControl, {
							label: __( 'Columns', 'forma-publisher' ),
							value: attributes.columns,
							onChange: update( 'columns' ),
							min: 1,
							max: 6
						} ),
						el( SelectControl, {
							label: __( 'Order by', 'forma-publisher' ),
							value: attributes.orderby,
							options: [
								{ label: __( 'Date', 'forma-publisher' ), value: 'date' },
								{ label: __( 'Title', 'forma-publisher' ), value: 'title' },
								{ label: __( 'Last modified', 'forma-publisher' ), value: 'modified' },
								{ label: __( 'Menu order', 'forma-publisher' ), value: 'menu_order' },
								{ label: __( 'Random', 'forma-publisher' ), value: 'rand' }
							],
							onChange: update( 'orderby' )
						} ),
						el( SelectControl, {
							label: __( 'Order', 'forma-publisher' ),
							value: attributes.order,
							options: [
								{ label: __( 'Descending', 'forma-publisher' ), value: 'DESC' },
								{ label: __( 'Ascending', 'forma-publisher' ), value: 'ASC' }
							],
							onChange: update( 'order' )
						} ),
						el( TextControl, {
							label: __( 'Filter by tag slugs', 'forma-publisher' ),
							help: __( 'Comma separated list of Forma tag slugs.', 'forma-publisher' ),
							value: attributes.tag,
							onChange: update( 'tag' )
						} ),
						el( TextControl, {
							label: __( 'Filter by status slugs', 'forma-publisher' ),
							help: __( 'Comma separated list of Forma status slugs.', 'forma-publisher' ),
							value: attributes.status,
							onChange: update( 'status' )
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Display', 'forma-publisher' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Show thumbnails', 'forma-publisher' ),
							checked: !! attributes.showThumbnail,
							onChange: update( 'showThumbnail' )
						} ),
						el( ToggleControl, {
							label: __( 'Show excerpts', 'forma-publisher' ),
							checked: !! attributes.showExcerpt,
							onChange: update( 'showExcerpt' )
						} ),
						el( ToggleControl, {
							label: __( 'Show top metrics', 'forma-publisher' ),
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

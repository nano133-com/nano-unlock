/**
 * The "Nano Unlock (paid part)" block: a box whose inner blocks are the paid
 * part. The server renders it (see Nano_Unlock_Render::render_block), so the
 * inner blocks reach a reader only after payment.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var InnerBlocks = blockEditor.InnerBlocks;

	blocks.registerBlockType( 'nano-unlock/paywall', {
		edit: function ( props ) {
			var price = props.attributes.price;
			var blockProps = blockEditor.useBlockProps();
			return el(
				'div',
				blockProps,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Price', 'nano-unlock' ) },
						el( components.TextControl, {
							label: __( 'Price in USD', 'nano-unlock' ),
							help: __( 'Leave empty for the default price on the settings page.', 'nano-unlock' ),
							value: price,
							inputMode: 'decimal',
							onChange: function ( v ) {
								props.setAttributes( { price: v.replace( /[^0-9.]/g, '' ) } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Item ID (optional)', 'nano-unlock' ),
							help: __( 'Keeps buyers unlocked if you reorder paid parts. Letters, digits, dashes.', 'nano-unlock' ),
							value: props.attributes.itemId,
							onChange: function ( v ) {
								props.setAttributes( { itemId: v.toLowerCase().replace( /[^a-z0-9_-]/g, '' ) } );
							},
						} )
					)
				),
				el(
					'p',
					{ className: 'nano-unlock-editor__label' },
					price
						? sprintf( __( 'Paid part: readers pay $%s in Nano to read what is inside this box.', 'nano-unlock' ), price )
						: __( 'Paid part: readers pay the default price in Nano to read what is inside this box.', 'nano-unlock' )
				),
				el( InnerBlocks, { templateLock: false, template: [ [ 'core/paragraph', { placeholder: __( 'The paid part…', 'nano-unlock' ) } ] ] } )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );

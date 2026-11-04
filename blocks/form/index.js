/**
 * Editor side of the mailtonic/form block: a form picker over the form list.
 * The block saves only the form uid; the front end is rendered by the server.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { SelectControl, Notice, Placeholder, PanelBody } = wp.components;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { __ } = wp.i18n;
	const data = window.mailtonicForms || { connected: false, forms: [], settingsUrl: '' };

	registerBlockType( 'mailtonic/form', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			const form = data.forms.find( ( f ) => f.uid === attributes.formUid );
			const options = [ { value: '', label: __( 'Choose a form', 'mailtonic' ) } ].concat(
				data.forms.map( ( f ) => ( { value: f.uid, label: f.name } ) )
			);
			const picker = el( SelectControl, {
				label: __( 'mailtonic form', 'mailtonic' ),
				value: attributes.formUid,
				options,
				onChange: ( formUid ) => setAttributes( { formUid } ),
			} );

			if ( ! data.connected ) {
				return el( 'div', blockProps, el( Placeholder, { label: __( 'mailtonic form', 'mailtonic' ) },
					el( 'a', { href: data.settingsUrl }, __( 'Connect mailtonic to choose a form.', 'mailtonic' ) ) ) );
			}

			return el( Fragment, null,
				el( InspectorControls, null, el( PanelBody, { title: __( 'Form', 'mailtonic' ) }, picker ) ),
				el( 'div', blockProps,
					el( Placeholder, {
						label: form ? form.name : __( 'mailtonic form', 'mailtonic' ),
						instructions: form ? form.kind + ' · ' + form.status : null,
					},
						form ? null : picker,
						form && form.notice ? el( Notice, { status: 'info', isDismissible: false }, form.notice ) : null,
						form && form.warning ? el( Notice, { status: 'warning', isDismissible: false }, form.warning ) : null
					)
				)
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );

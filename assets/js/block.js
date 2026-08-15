/**
 * The block editor's side of the Form block.
 *
 * Deliberately not built by Vite and deliberately not JSX. It is a handful of
 * `createElement` calls against globals WordPress already loads, so it needs no
 * transpile step, no `wp-scripts`, and no second toolchain in a plugin that
 * already owns one. Adding a build target for eighty lines that would still be
 * eighty lines afterwards buys nothing.
 *
 * The block is dynamic: it stores a form id and renders on the server, so
 * editing a form updates every page that embeds it. What the editor shows is a
 * picker and a server-rendered preview, fetched through the same REST route the
 * builder previews with.
 *
 * @package AllTerrain_Forms
 */

( function ( blocks, element, components, blockEditor, i18n, apiFetch ) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var __ = i18n.__;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var Placeholder = components.Placeholder;
	var Spinner = components.Spinner;

	/**
	 * Fetches the site's forms once and shares the result.
	 *
	 * A module-level promise rather than per-block state: a page with six form
	 * blocks on it would otherwise make six identical requests, and they would
	 * all land while the editor is still painting.
	 */
	var formsPromise = null;

	function loadForms() {
		if ( ! formsPromise ) {
			formsPromise = apiFetch( { path: '/allterrain-forms/v1/forms' } ).catch( function () {
				// A failure here leaves the picker empty rather than breaking
				// the editor. It is also cached, so a temporary outage does not
				// retry once per block.
				return [];
			} );
		}

		return formsPromise;
	}

	blocks.registerBlockType( 'allterrain-forms/form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var formsState = useState( null );
			var forms = formsState[ 0 ];
			var setForms = formsState[ 1 ];

			var previewState = useState( '' );
			var preview = previewState[ 0 ];
			var setPreview = previewState[ 1 ];

			useEffect( function () {
				var cancelled = false;

				loadForms().then( function ( list ) {
					if ( ! cancelled ) {
						setForms( list );
					}
				} );

				return function () {
					cancelled = true;
				};
			}, [] );

			useEffect(
				function () {
					if ( ! attributes.formId ) {
						setPreview( '' );

						return;
					}

					var cancelled = false;

					apiFetch( {
						path: '/allterrain-forms/v1/forms/' + attributes.formId + '/preview',
						method: 'POST',
						data: { theme: attributes.theme || '' },
					} )
						.then( function ( response ) {
							if ( ! cancelled ) {
								setPreview( response.html || '' );
							}
						} )
						.catch( function () {
							if ( ! cancelled ) {
								setPreview( '' );
							}
						} );

					return function () {
						cancelled = true;
					};
				},
				[ attributes.formId, attributes.theme ]
			);

			var options = [ { value: 0, label: __( 'Choose a form…', 'allterrain-forms' ) } ].concat(
				( forms || [] ).map( function ( form ) {
					return { value: form.id, label: form.title || __( '(untitled)', 'allterrain-forms' ) };
				} )
			);

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Form', 'allterrain-forms' ) },
					el( SelectControl, {
						label: __( 'Which form', 'allterrain-forms' ),
						value: attributes.formId,
						options: options,
						onChange: function ( value ) {
							setAttributes( { formId: parseInt( value, 10 ) || 0 } );
						},
					} ),
					el( SelectControl, {
						label: __( 'Theme', 'allterrain-forms' ),
						help: __( "Leave as the form's own theme unless this placement needs a different one.", 'allterrain-forms' ),
						value: attributes.theme,
						options: [
							{ value: '', label: __( "The form's own theme", 'allterrain-forms' ) },
							{ value: 'clean', label: 'Clean' },
							{ value: 'midnight', label: 'Midnight' },
							{ value: 'glass', label: 'Glass' },
							{ value: 'brutal', label: 'Brutal' },
							{ value: 'paper', label: 'Paper' },
							{ value: 'neon', label: 'Neon' },
							{ value: 'terminal', label: 'Terminal' },
							{ value: 'soft', label: 'Soft' },
							{ value: 'editorial', label: 'Editorial' },
							{ value: 'holo', label: 'Holo' },
						],
						onChange: function ( value ) {
							setAttributes( { theme: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( "Show the form's title", 'allterrain-forms' ),
						checked: !! attributes.showTitle,
						onChange: function ( value ) {
							setAttributes( { showTitle: !! value } );
						},
					} )
				)
			);

			var body;

			if ( ! attributes.formId ) {
				body = el(
					Placeholder,
					{
						icon: 'feedback',
						label: __( 'AllTerrain Form', 'allterrain-forms' ),
						instructions: __( 'Pick a form to place here.', 'allterrain-forms' ),
					},
					forms === null
						? el( Spinner )
						: el( SelectControl, {
								value: attributes.formId,
								options: options,
								onChange: function ( value ) {
									setAttributes( { formId: parseInt( value, 10 ) || 0 } );
								},
						  } )
				);
			} else if ( ! preview ) {
				body = el( Placeholder, { icon: 'feedback' }, el( Spinner ) );
			} else {
				// The preview is this plugin's own server-rendered markup, and
				// `disabled` stops the editor's click handling fighting with a
				// form nobody is meant to fill in here.
				body = el( 'div', {
					className: 'atf-block-preview',
					inert: '',
					dangerouslySetInnerHTML: { __html: preview },
				} );
			}

			return el( Fragment, null, inspector, el( 'div', useBlockProps(), body ) );
		},

		// Nothing is saved: the block is dynamic, and its markup comes from
		// `atf_render_block()` at request time.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.i18n,
	window.wp.apiFetch
);

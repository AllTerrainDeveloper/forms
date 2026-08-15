/**
 * The field, as it will look, edited where it sits.
 *
 * The builder's canvas used to be a list of *descriptions* of fields — an icon,
 * the label as static text, the type name underneath — and everything you could
 * actually change lived in a pane on the right. So the two things you do most,
 * writing the question and writing what goes in the box, happened at arm's
 * length from the thing they belong to, and the canvas never answered the one
 * question you look at a form builder to answer: **what will this form look
 * like?**
 *
 * Now the card renders the control. Click the question to rewrite it, click the
 * box to set its placeholder, click an option to rename it. Logic, validation,
 * prefill and the rest stay in the inspector, because those are properties of a
 * field rather than parts of it.
 *
 * # Why this is not a second renderer
 *
 * A form builder that draws its own approximation of a control is a builder that
 * drifts from what it ships. This avoids that in two ways, and both matter.
 *
 * **The stylesheet is the real one.** `builder.css` is registered with
 * `form.css` as a dependency, so every class the front end uses — `.atf-input`,
 * `.atf-choice__input`, `.atf-label` — is already in the document. Emitting those
 * class names inside an `.atf-form` means the theme's own radius, borders,
 * spacing, label position and colours apply here exactly as they do on the page.
 * Nothing about the look is reimplemented; it is the same CSS.
 *
 * **The shapes are a small closed set.** Thirty-seven field types share about a
 * dozen visual shapes — a text box, a taller text box, a dropdown, a list of
 * options, a switch. This maps a type to a shape rather than to markup, and
 * `field-preview.test.ts` asserts that *every registered type* has one, so a type
 * added later cannot silently render as an empty card.
 *
 * What this deliberately does not attempt is pixel fidelity for the elaborate
 * types — a Likert matrix, a signature pad. Those render as a labelled
 * placeholder saying what they are. Claiming to be a faithful preview and then
 * being subtly wrong is worse than being visibly a summary.
 */

import { el } from './ui';

import type { Field, FieldType } from './types';

/** The visual families a field can be drawn as. */
export type PreviewShape =
	| 'text'
	| 'textarea'
	| 'select'
	| 'options'
	| 'toggle'
	| 'file'
	| 'range'
	| 'composite'
	| 'static'
	| 'summary';

/**
 * Which shape a field type is drawn as.
 *
 * Keyed by type rather than derived from `supports`, because two types can
 * support the same settings and still look nothing alike — `select` and `radio`
 * both have choices, and one is a dropdown.
 */
const SHAPES: Record< string, PreviewShape > = {
	text: 'text',
	email: 'text',
	url: 'text',
	tel: 'text',
	number: 'text',
	password: 'text',
	date: 'text',
	time: 'text',
	datetime: 'text',
	date_range: 'composite',
	textarea: 'textarea',
	select: 'select',
	country: 'select',
	multiselect: 'options',
	radio: 'options',
	checkboxes: 'options',
	image_choice: 'options',
	quiz: 'options',
	switch: 'toggle',
	consent: 'toggle',
	file: 'file',
	range: 'range',
	rating: 'summary',
	scale: 'summary',
	likert: 'summary',
	signature: 'summary',
	repeater: 'summary',
	color: 'text',
	name: 'composite',
	address: 'composite',
	total: 'text',
	hidden: 'static',
	heading: 'static',
	html: 'static',
	divider: 'static',
	spacer: 'static',
	page_break: 'static',
};

/** The shape a type is drawn as, defaulting to a plain box. */
export function shapeFor( type: string ): PreviewShape {
	return SHAPES[ type ] ?? 'text';
}

/** Whether a field's control takes a placeholder worth editing inline. */
export function hasPlaceholder( type: string ): boolean {
	return [ 'text', 'textarea', 'select' ].includes( shapeFor( type ) );
}

/**
 * How the preview writes a change back.
 *
 * Both hand the caller the **live** field rather than letting it keep the one it
 * was rendered from, and that indirection is load-bearing. A save replaces
 * `this.schema` wholesale with the server's normalised copy, so a card that
 * closed over its own `field` object is writing to a detached one the moment an
 * autosave lands — and an autosave lands 2.5 seconds after the last keystroke,
 * which is exactly when somebody pauses mid-sentence and then carries on typing.
 * The text went into an object nothing serialises and vanished at the next
 * render, with no error anywhere.
 *
 * Resolving by id at write time makes the number of times the schema has been
 * replaced irrelevant.
 */
export interface PreviewHandlers {
	/** A value changed but nothing moved — update the model, do not re-render. */
	edit: ( apply: ( field: Field ) => void ) => void;
	/** The field's shape changed — snapshot, update, re-render. */
	restructure: ( apply: ( field: Field ) => void ) => void;
}

/**
 * An editable piece of text that looks like the text it replaces.
 *
 * A real `contenteditable` rather than an `<input>` styled to look like one: the
 * label has to wrap, sit on the theme's own line-height and inherit the theme's
 * font, and an input does none of those without being told each one — at which
 * point it is an input pretending to be text, and the pretence shows the moment a
 * label is long enough to wrap.
 *
 * `plaintext-only` keeps a paste from bringing markup with it.
 */
function editableText(
	value: string,
	placeholder: string,
	className: string,
	onInput: ( value: string ) => void,
	onCommit?: () => void
): HTMLElement {
	const node = el( 'span', {
		class: `${ className } atfb-editable`,
		text: value,
		attrs: {
			contenteditable: 'plaintext-only',
			role: 'textbox',
			spellcheck: 'false',
			'data-placeholder': placeholder,
		},
	} );

	// Dragging the card must not start from inside the text somebody is editing,
	// and a click has to place the caret rather than being swallowed as a
	// selection gesture.
	node.addEventListener( 'pointerdown', ( event ) => event.stopPropagation() );

	node.addEventListener( 'input', () => onInput( node.textContent ?? '' ) );

	node.addEventListener( 'keydown', ( event ) => {
		// Enter commits rather than inserting a newline: these are single-line
		// values, and a label containing a line break is a label that renders
		// differently everywhere it is shown.
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			node.blur();
		}

		if ( 'Escape' === event.key ) {
			node.textContent = value;
			node.blur();
		}

		// The card handles Delete and Backspace as "remove this field", which
		// would be an unpleasant surprise while editing its label.
		event.stopPropagation();
	} );

	node.addEventListener( 'blur', () => onCommit?.() );

	return node;
}

/** The glyph an options list uses, so a radio reads as a radio. */
function optionInputFor( type: string ): HTMLElement {
	const input = el( 'input', {
		class: 'atf-choice__input',
		type: 'checkboxes' === type || 'multiselect' === type ? 'checkbox' : 'radio',
	} );

	// A preview control is never operated: clicking it should put the caret in
	// the option's name, which is the only editable thing on that row.
	input.disabled = true;

	return input;
}

/**
 * Draws one field as it will appear, with its text editable in place.
 *
 * @param field    The field.
 * @param type     Its registered type, when the config knows it.
 * @param handlers How to write changes back.
 * @return The preview element.
 */
export function renderFieldPreview( field: Field, type: FieldType | undefined, handlers: PreviewHandlers ): HTMLElement {
	const shape = shapeFor( field.type );

	const label = editableText(
		field.label,
		'Write the question…',
		'atf-label',
		( value ) => handlers.edit( ( live ) => { live.label = value; } ),
		// Committed on blur rather than per keystroke: the label appears in other
		// cards' condition chips and in the merge-tag picker, and repainting the
		// canvas on every character would take the caret with it.
		() => handlers.restructure( () => {} )
	);

	const parts: Array< HTMLElement | null > = [
		'static' === shape ? null : label,
		field.hint ? el( 'p', { class: 'atf-hint', text: field.hint } ) : null,
		control( field, type, shape, handlers ),
	];

	return el( 'div', {
		// `.atf-form` is what the theme's custom properties are scoped to, and
		// `.atf-field` is what gives the control its spacing. Both are the real
		// front-end classes: the look here is the stylesheet, not a copy of it.
		class: 'atfb-preview atf-form',
		children: [
			el( 'div', {
				class: `atf-field atf-field--${ field.type }`,
				children: parts,
			} ),
		],
	} );
}

/** The control itself, per shape. */
function control(
	field: Field,
	type: FieldType | undefined,
	shape: PreviewShape,
	handlers: PreviewHandlers
): HTMLElement | null {
	switch ( shape ) {
		case 'text':
			return placeholderBox( field, 'atf-input', handlers );

		case 'textarea':
			return placeholderBox( field, 'atf-input atf-textarea', handlers, true );

		case 'select':
			return el( 'div', {
				class: 'atfb-preview__select',
				children: [ placeholderBox( field, 'atf-input atf-select', handlers ) ],
			} );

		case 'options':
			return optionList( field, handlers );

		case 'toggle':
			return el( 'div', {
				class: 'atf-toggle',
				children: [
					( () => {
						const box = el( 'input', { class: 'atf-toggle__input', type: 'checkbox' } );

						box.disabled = true;

						return box;
					} )(),
					editableText(
						field.label || '',
						'What are they agreeing to?…',
						'atf-toggle__label',
						( value ) => handlers.edit( ( live ) => { live.label = value; } ),
						() => handlers.restructure( () => {} )
					),
				],
			} );

		case 'file':
			return el( 'div', { class: 'atf-file', children: [ el( 'input', { class: 'atf-file__input', type: 'file' } ) ] } );

		case 'range':
			return el( 'input', { class: 'atf-range__input', type: 'range' } );

		case 'composite':
			return el( 'div', {
				class: 'atf-composite__parts',
				children: [
					el( 'span', { class: 'atf-input atfb-preview__ghost', text: '' } ),
					el( 'span', { class: 'atf-input atfb-preview__ghost', text: '' } ),
				],
			} );

		case 'static':
			return staticBlock( field, type, handlers );

		default:
			// The elaborate controls — a Likert matrix, a signature pad — say what
			// they are rather than pretending. A preview that is subtly wrong is
			// worse than one that is honestly a summary.
			return el( 'p', {
				class: 'atfb-preview__summary',
				text: `${ type?.label ?? field.type } — set this up in the panel on the right.`,
			} );
	}
}

/** A box whose placeholder is edited by typing into it. */
function placeholderBox(
	field: Field,
	className: string,
	handlers: PreviewHandlers,
	tall = false
): HTMLElement {
	const box = editableText(
		field.placeholder ?? '',
		'select' === field.type || 'country' === field.type ? 'Choose…' : 'Placeholder…',
		`${ className } atfb-preview__box${ tall ? ' atfb-preview__box--tall' : '' }`,
		( value ) => handlers.edit( ( live ) => { live.placeholder = value; } )
	);

	box.setAttribute( 'aria-label', 'Placeholder' );

	return box;
}

/**
 * A list of options, each renameable, with add and remove.
 *
 * This is the part that makes the canvas answer "what will this form contain?".
 * A choice field's *content* is its options, and having them behind a panel
 * meant the canvas showed a card labelled "Radio buttons" that could have been
 * two options or twenty.
 */
function optionList( field: Field, handlers: PreviewHandlers ): HTMLElement {
	const list = el( 'div', { class: 'atf-choices__list' } );

	( field.choices ?? [] ).forEach( ( choice, index ) => {
		list.append(
			el( 'div', {
				class: 'atf-choice atfb-preview__option',
				children: [
					optionInputFor( field.type ),
					editableText(
						choice.label,
						'Option…',
						'atf-choice__label',
						( value ) => {
							handlers.edit( ( live ) => {
								// By index into the *live* field, not the choice object
								// this row was rendered from — same reason as the field
								// itself: a save replaces them all.
								const target = live.choices?.[ index ];

								if ( ! target ) {
									return;
								}

								// The stored value follows the label while it still
								// looks generated. Once somebody sets a value of their
								// own — an id their CRM expects — renaming the label
								// must not silently change what gets submitted.
								if ( ! target.value || target.value === slug( target.label ) ) {
									target.value = slug( value );
								}

								target.label = value;
							} );
						}
					),
					el( 'button', {
						class: 'atfb-preview__remove',
						type: 'button',
						title: 'Remove this option',
						attrs: { 'aria-label': `Remove ${ choice.label || 'this option' }` },
						on: {
							pointerdown: ( event: Event ) => event.stopPropagation(),
							click: ( event: Event ) => {
								event.stopPropagation();
								handlers.restructure( ( live ) => {
									live.choices.splice( index, 1 );
								} );
							},
						},
						children: [ el( 'span', { text: '×' } ) ],
					} ),
				],
			} )
		);
	} );

	list.append(
		el( 'button', {
			class: 'atfb-preview__add',
			type: 'button',
			on: {
				pointerdown: ( event: Event ) => event.stopPropagation(),
				click: ( event: Event ) => {
					event.stopPropagation();
					handlers.restructure( ( live ) => {
						// Named rather than blank, and that is not cosmetic:
						// `atf_normalize_choices()` drops a choice whose label and
						// value are both empty, and the schema is normalised on every
						// save. A blank new option therefore looked fine, and then
						// disappeared a couple of seconds later when the autosave
						// came back — with nothing to tell anybody why.
						const next = live.choices.length + 1;

						live.choices.push( { label: `Option ${ next }`, value: `option-${ next }` } );
					} );
				},
			},
			children: [ el( 'span', { text: '+ Add option' } ) ],
		} )
	);

	return el( 'fieldset', { class: 'atf-choices', children: [ list ] } );
}

/** The layout blocks, which are their own content. */
function staticBlock( field: Field, type: FieldType | undefined, handlers: PreviewHandlers ): HTMLElement | null {
	if ( 'heading' === field.type ) {
		return editableText(
			field.label,
			'Section heading…',
			'atf-heading',
			( value ) => handlers.edit( ( live ) => { live.label = value; } ),
			() => handlers.restructure( () => {} )
		);
	}

	if ( 'divider' === field.type ) {
		return el( 'hr', { class: 'atf-divider' } );
	}

	if ( 'page_break' === field.type ) {
		return el( 'p', { class: 'atfb-preview__summary', text: 'Everything after this is a new page.' } );
	}

	return el( 'p', {
		class: 'atfb-preview__summary',
		text: `${ type?.label ?? field.type } — nothing is shown to the visitor here.`,
	} );
}

/** A stored value derived from a label. */
function slug( label: string ): string {
	return label
		.toLowerCase()
		.trim()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

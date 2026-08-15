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

/** What an inline-editable piece of text needs to know about itself. */
interface Editable {
	/** The current text. */
	value: string;
	/** Shown, greyed, while the text is empty. */
	placeholder: string;
	/** The front-end class, so the theme styles it. */
	class: string;
	/**
	 * Which field property this edits, if the inspector edits it too.
	 *
	 * The two panes show the same value, so both have to move when either does.
	 * Naming the property here rather than in a list somewhere else is what keeps
	 * that true: `syncCanvas()` and `syncInspector()` walk `[data-atfb-bind]` and
	 * resolve the key through {@link boundValue}, so a new editable is mirrored in
	 * both directions by virtue of existing. The alternative — a hand-kept list of
	 * keys in each sync function — was two lists to forget instead of none.
	 */
	bind?: string;
	/** Called on every keystroke. */
	onInput: ( value: string ) => void;
	/** Called on blur, when a change needs the rest of the builder repainted. */
	onCommit?: () => void;
}

/**
 * The value a bound key currently holds on a field.
 *
 * The one place that knows how a `data-atfb-bind` key maps onto the schema, used
 * by both sync directions so they cannot disagree about what `hint` means.
 *
 * @param field The field.
 * @param key   The bind key, e.g. `label` or `choice:2:value`.
 * @return The value, or an empty string when the field has nothing there.
 */
export function boundValue( field: Field, key: string ): string {
	const choice = /^choice:(\d+):(label|value)$/.exec( key );

	if ( choice ) {
		return String( field.choices?.[ Number( choice[ 1 ] ) ]?.[ choice[ 2 ] as 'label' | 'value' ] ?? '' );
	}

	return String( field[ key ] ?? '' );
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
function editableText( options: Editable ): HTMLElement {
	const { value, onInput, onCommit } = options;

	const node = el( 'span', {
		class: `${ options.class } atfb-editable`,
		text: value,
		attrs: {
			contenteditable: 'plaintext-only',
			role: 'textbox',
			spellcheck: 'false',
			'data-placeholder': options.placeholder,
		},
	} );

	if ( options.bind ) {
		node.dataset.atfbBind = options.bind;
	}

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

	const label = editableText( {
		value: field.label,
		placeholder: 'Write the question…',
		class: 'atf-label',
		bind: 'label',
		onInput: ( value ) => handlers.edit( ( live ) => { live.label = value; } ),
		// Committed on blur rather than per keystroke: the label appears in other
		// cards' condition chips and in the merge-tag picker, and repainting the
		// canvas on every character would take the caret with it.
		onCommit: () => handlers.restructure( () => {} ),
	} );

	const parts: Array< HTMLElement | null > = [
		// A toggle draws its own label beside the switch, exactly as the front end
		// does — a second one above it was the same words twice.
		'static' === shape || 'toggle' === shape ? null : label,
		control( field, type, shape, handlers ),
		hint( field, type, handlers ),
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

/**
 * The hint line, editable wherever the type offers one.
 *
 * Offered whenever the *type* supports a hint rather than only when the field
 * already has one, which is the difference between a feature you can find and a
 * feature you have to already know about: an empty hint rendered nothing, so the
 * only way to discover hints was to notice the row in the inspector.
 *
 * Below the control, because that is where the front end puts it — above would
 * be a preview that quietly disagrees with the page.
 */
function hint( field: Field, type: FieldType | undefined, handlers: PreviewHandlers ): HTMLElement | null {
	// No registered type means a third-party field the config has not described.
	// Offering it a hint it may not render is worse than leaving it alone.
	if ( ! type?.supports.includes( 'hint' ) ) {
		return null;
	}

	const node = editableText( {
		value: field.hint ?? '',
		placeholder: 'Add a hint…',
		class: 'atf-hint',
		bind: 'hint',
		onInput: ( value ) => handlers.edit( ( live ) => { live.hint = value; } ),
	} );

	node.setAttribute( 'aria-label', 'Hint' );

	return el( 'p', { class: 'atfb-preview__hint', children: [ node ] } );
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
				// The modifier matters: a switch and a consent tick box are the same
				// shape here but not the same control on the page, and the front end
				// tells them apart by exactly this class.
				class: 'switch' === field.type ? 'atf-toggle atf-toggle--switch' : 'atf-toggle',
				children: [
					( () => {
						const box = el( 'input', { class: 'atf-toggle__input', type: 'checkbox' } );

						box.disabled = true;

						return box;
					} )(),
					editableText( {
						value: field.label || '',
						placeholder: 'consent' === field.type ? 'What are they agreeing to?…' : 'What does this turn on?…',
						class: 'atf-toggle__label',
						bind: 'label',
						onInput: ( value ) => handlers.edit( ( live ) => { live.label = value; } ),
						onCommit: () => handlers.restructure( () => {} ),
					} ),
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
			return el( 'div', {
				class: 'atfb-preview__stack',
				children: [
					el( 'p', {
						class: 'atfb-preview__summary',
						text: `${ type?.label ?? field.type } — set this up in the panel on the right.`,
					} ),
					// A repeater's one visible piece of chrome is the button that adds
					// another row, and its wording is already a setting. Drawing the
					// real button is both the honest preview and the only place the
					// wording was reachable.
					'repeater' === field.type ? repeatButton( field, handlers ) : null,
				],
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
	const box = editableText( {
		value: field.placeholder ?? '',
		placeholder: 'select' === field.type || 'country' === field.type ? 'Choose…' : 'Placeholder…',
		class: `${ className } atfb-preview__box${ tall ? ' atfb-preview__box--tall' : '' }`,
		bind: 'placeholder',
		onInput: ( value ) => handlers.edit( ( live ) => { live.placeholder = value; } ),
	} );

	box.setAttribute( 'aria-label', 'Placeholder' );

	return box;
}

/**
 * A button whose own wording is the editable part.
 *
 * Used for the page break's Next and Back and the repeater's Add another. All
 * three are already settings on the schema and all three had no control anywhere
 * — the form shipped a button saying "Next" and the only way to change it was to
 * edit the JSON. Drawing the real button and letting the words be typed makes the
 * setting both visible and reachable in one move.
 *
 * The placeholder carries the default the renderer falls back to, so an empty
 * value reads as "this will say Next" rather than as a blank button.
 */
function labelledButton(
	text: string,
	fallback: string,
	className: string,
	bind: string,
	handlers: PreviewHandlers,
	write: ( live: Field, value: string ) => void
): HTMLElement {
	return el( 'span', {
		class: `atf-button ${ className } atfb-preview__button`,
		children: [
			editableText( {
				value: text,
				placeholder: fallback,
				class: 'atfb-preview__button-text',
				bind,
				onInput: ( value ) => handlers.edit( ( live ) => write( live, value ) ),
			} ),
		],
	} );
}

/** The repeater's "Add another" button, with its wording editable. */
function repeatButton( field: Field, handlers: PreviewHandlers ): HTMLElement {
	return labelledButton(
		String( field.addLabel ?? '' ),
		'Add another',
		'atf-button--secondary',
		'addLabel',
		handlers,
		( live, value ) => {
			live.addLabel = value;
		}
	);
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
					editableText( {
						value: choice.label,
						placeholder: 'Option…',
						class: 'atf-choice__label',
						bind: `choice:${ index }:label`,
						onInput: ( value ) => {
							handlers.edit( ( live ) => {
								// By index into the *live* field, not the choice object
								// this row was rendered from — same reason as the field
								// itself: a save replaces them all.
								const target = live.choices?.[ index ];

								if ( ! target ) {
									return;
								}

								// The stored value follows the label while it is still
								// a mirror of it. Once somebody sets a value of their
								// own — an id their CRM expects — renaming the label
								// must not silently change what gets submitted, because
								// entries are stored against the value.
								//
								// Mirrors verbatim rather than slugifying, which is the
								// convention the inspector and `atf_normalize_choices()`
								// already use; two spellings of "the value follows the
								// label" would disagree the moment you used both panes.
								const mirroring = ! target.value || target.value === target.label;

								target.label = value;

								if ( mirroring ) {
									target.value = value;
								}
							} );
						},
					} ),
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

						live.choices.push( { label: `Option ${ next }`, value: `Option ${ next }` } );
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
		return editableText( {
			value: field.label,
			placeholder: 'Section heading…',
			class: 'atf-heading',
			bind: 'label',
			onInput: ( value ) => handlers.edit( ( live ) => { live.label = value; } ),
			onCommit: () => handlers.restructure( () => {} ),
		} );
	}

	if ( 'divider' === field.type ) {
		return el( 'hr', { class: 'atf-divider' } );
	}

	if ( 'page_break' === field.type ) {
		// The break's visible consequences are the name of the step in the
		// progress indicator and the pair of buttons that closes the page. All
		// three words are settings, and two of them could not be reached from
		// anywhere: a form in any language other than English shipped a button
		// saying "Next".
		return el( 'div', {
			class: 'atfb-preview__stack',
			children: [
				el( 'p', {
					class: 'atfb-progress-name',
					children: [
						editableText( {
							value: field.label,
							placeholder: 'Name this step…',
							class: 'atf-progress__label',
							bind: 'label',
							onInput: ( value ) => handlers.edit( ( live ) => { live.label = value; } ),
							// The name appears in the step indicator on every page of
							// the form, so the preview has to be repainted once the
							// wording settles.
							onCommit: () => handlers.restructure( () => {} ),
						} ),
					],
				} ),
				el( 'p', { class: 'atfb-preview__summary', text: 'Everything after this is a new page.' } ),
				el( 'div', {
					class: 'atf-nav atfb-preview__nav',
					children: [
						labelledButton(
							String( field.prevLabel ?? '' ),
							'Back',
							'atf-button--secondary',
							'prevLabel',
							handlers,
							( live, value ) => {
								live.prevLabel = value;
							}
						),
						labelledButton(
							String( field.nextLabel ?? '' ),
							'Next',
							'',
							'nextLabel',
							handlers,
							( live, value ) => {
								live.nextLabel = value;
							}
						),
					],
				} ),
			],
		} );
	}

	return el( 'p', {
		class: 'atfb-preview__summary',
		text: `${ type?.label ?? field.type } — nothing is shown to the visitor here.`,
	} );
}

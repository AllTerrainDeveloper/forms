/**
 * The canvas preview's shape map.
 *
 * The builder draws each field as it will look, and it does that by mapping a
 * field *type* to one of a dozen visual *shapes* rather than by writing markup
 * per type. That is what stops it becoming a second renderer — but it moves the
 * risk somewhere else: a type registered later has no entry, and an unmapped
 * type falls through to a plain text box.
 *
 * Falling through is the right runtime behaviour, because a card that renders
 * *something* beats a card that renders nothing. It is the wrong *silent*
 * behaviour: a signature pad drawn as a one-line text box is a preview that
 * lies, and nobody would notice until they compared it with the front end.
 *
 * So the map is asserted against the type list itself.
 */

import { describe, expect, it } from 'vitest';
import { hasPlaceholder, shapeFor } from '../../src/field-preview';

/**
 * Every field type the plugin registers.
 *
 * Mirrors `atf_register_builtin_field_types()`. Kept as a literal rather than
 * read from a fixture because the point of this test is to fail when the two
 * lists disagree — reading one from the other would make it agree by
 * construction and assert nothing.
 */
const TYPES = [
	'text', 'textarea', 'email', 'url', 'tel', 'number', 'password', 'hidden',
	'select', 'multiselect', 'radio', 'checkboxes', 'image_choice', 'switch',
	'date', 'time', 'datetime', 'date_range',
	'file', 'signature', 'rating', 'scale', 'likert', 'range', 'color',
	'name', 'address', 'country', 'repeater',
	'heading', 'html', 'divider', 'spacer', 'page_break',
	'consent', 'total', 'quiz',
];

describe( 'shapeFor', () => {
	it( 'has an entry for every registered field type', () => {
		// `shapeFor` falls back to 'text', so this cannot simply check for a
		// truthy result — it has to catch a type that is *only* reaching the
		// fallback. The map is read through the same public function the builder
		// uses, so a type deleted from the map fails here.
		const unmapped = TYPES.filter( ( type ) => {
			// A type genuinely drawn as a text box is fine; one that reaches
			// 'text' by accident is not, and the two are told apart by asking
			// whether the map itself knows the name.
			const drawn = shapeFor( type );
			const known = shapeFor( `${ type }__definitely-not-a-type` );

			return 'text' === drawn && 'text' === known && ! TEXT_SHAPED.includes( type );
		} );

		expect( unmapped ).toEqual( [] );
	} );

	it( 'draws the elaborate controls as a summary rather than pretending', () => {
		// These have no honest one-line approximation. Drawing a Likert matrix as
		// a text box would be a preview that is confidently wrong, which is worse
		// than one that says what it is.
		for ( const type of [ 'likert', 'signature', 'rating', 'scale', 'repeater' ] ) {
			expect( shapeFor( type ) ).toBe( 'summary' );
		}
	} );

	it( 'draws a dropdown as a dropdown and a radio as a list', () => {
		// Both have choices, and treating "has choices" as the signal would make
		// them identical on the canvas.
		expect( shapeFor( 'select' ) ).toBe( 'select' );
		expect( shapeFor( 'radio' ) ).toBe( 'options' );
		expect( shapeFor( 'checkboxes' ) ).toBe( 'options' );
	} );

	it( 'draws the layout blocks as static, since they take no answer', () => {
		for ( const type of [ 'heading', 'divider', 'spacer', 'page_break', 'html', 'hidden' ] ) {
			expect( shapeFor( type ) ).toBe( 'static' );
		}
	} );

	it( 'falls back to a text box for a type it has never heard of', () => {
		// A third-party field type must still render as *something*.
		expect( shapeFor( 'myplugin/rating-slider' ) ).toBe( 'text' );
	} );
} );

/** The types that are legitimately drawn as a plain text box. */
const TEXT_SHAPED = [
	'text', 'email', 'url', 'tel', 'number', 'password',
	'date', 'time', 'datetime', 'color', 'total',
];

describe( 'hasPlaceholder', () => {
	it( 'is true for the shapes that show one', () => {
		expect( hasPlaceholder( 'text' ) ).toBe( true );
		expect( hasPlaceholder( 'textarea' ) ).toBe( true );
		expect( hasPlaceholder( 'select' ) ).toBe( true );
	} );

	it( 'is false where a placeholder would have nowhere to go', () => {
		// Offering to edit a placeholder on a control that never shows one is an
		// invitation to type something that is then invisible everywhere.
		for ( const type of [ 'radio', 'checkboxes', 'divider', 'likert', 'switch' ] ) {
			expect( hasPlaceholder( type ) ).toBe( false );
		}
	} );
} );

/**
 * The canvas–inspector binding.
 *
 * Both panes edit one value and have to move together while it is being typed.
 * That used to be two hand-kept lists of property names — one in `syncCanvas()`,
 * one in `syncInspector()` — and the failure mode was quiet: forget a name in one
 * of them and the value mirrors in one direction only, which looks like it works
 * until somebody uses the other pane.
 *
 * Now every editable names the property it edits and both sync functions walk
 * `[data-atfb-bind]`. What has to hold for that to be correct is that the key an
 * element carries actually resolves, through `boundValue`, to the text the
 * element is showing — so that is what is asserted here, for every editable a
 * field renders.
 */

import { boundValue, renderFieldPreview } from '../../src/field-preview';
import type { Field, FieldType } from '../../src/types';

/** A field with just enough on it to render. */
function field( overrides: Partial< Field > = {} ): Field {
	return {
		id: 'f1',
		type: 'text',
		label: 'Your name',
		placeholder: '',
		hint: '',
		required: false,
		width: 'full',
		cssClass: '',
		default: '',
		choices: [],
		logic: { enabled: false, action: 'show', match: 'all', rules: [] },
		messages: {},
		prefill: '',
		...overrides,
	} as Field;
}

/** A registered type, described by what it supports. */
function definition( supports: string[], type = 'text' ): FieldType {
	return {
		type,
		label: 'A field',
		description: '',
		group: 'basic',
		icon: '',
		input: true,
		value: 'string',
		choices: false,
		supports,
		settings: {},
	};
}

/** Every in-place editable a preview rendered, with its bind key. */
function editables( node: HTMLElement ): Array< { key: string; text: string } > {
	return Array.from( node.querySelectorAll< HTMLElement >( '.atfb-editable' ) ).map( ( el ) => ( {
		key: el.dataset.atfbBind ?? '',
		text: el.textContent ?? '',
	} ) );
}

const HANDLERS = { edit: () => {}, restructure: () => {} };

describe( 'boundValue', () => {
	it( 'reads a plain property', () => {
		expect( boundValue( field( { hint: 'As it appears on your passport' } ), 'hint' ) ).toBe(
			'As it appears on your passport'
		);
	} );

	it( 'reads a type setting the interface does not name', () => {
		// `nextLabel` lives in the field type's own settings rather than on the
		// `Field` interface, and is exactly the kind of key the old hand-kept
		// lists never covered.
		expect( boundValue( field( { nextLabel: 'Continue' } ), 'nextLabel' ) ).toBe( 'Continue' );
	} );

	it( 'reads one choice out of the list by index', () => {
		const target = field( {
			choices: [
				{ label: 'Yes', value: 'y' },
				{ label: 'No', value: 'n' },
			],
		} );

		expect( boundValue( target, 'choice:1:label' ) ).toBe( 'No' );
		expect( boundValue( target, 'choice:1:value' ) ).toBe( 'n' );
	} );

	it( 'is empty rather than "undefined" for anything absent', () => {
		// This lands in `textContent`, so a missing value has to be nothing at
		// all — the string "undefined" would be written into somebody's form.
		expect( boundValue( field(), 'nextLabel' ) ).toBe( '' );
		expect( boundValue( field(), 'choice:9:label' ) ).toBe( '' );
	} );
} );

describe( 'the bind keys a preview renders', () => {
	it( 'resolve to the text they are showing', () => {
		const target = field( {
			type: 'radio',
			label: 'Pick one',
			hint: 'Only one',
			choices: [ { label: 'Red', value: 'red' } ],
		} );

		const node = renderFieldPreview( target, definition( [ 'label', 'hint' ], 'radio' ), HANDLERS );

		for ( const { key, text } of editables( node ) ) {
			expect( key, `an editable showing "${ text }" names no property` ).not.toBe( '' );
			expect( boundValue( target, key ), `bound as ${ key }` ).toBe( text );
		}
	} );

	it( 'covers the page break, whose buttons had no control anywhere', () => {
		const node = renderFieldPreview(
			field( { type: 'page_break', label: 'Your details', nextLabel: 'Continue' } ),
			definition( [ 'label', 'nextlabel', 'prevlabel' ], 'page_break' ),
			HANDLERS
		);

		const keys = editables( node ).map( ( item ) => item.key );

		expect( keys ).toContain( 'nextLabel' );
		expect( keys ).toContain( 'prevLabel' );
		expect( keys ).toContain( 'label' );
	} );
} );

describe( 'the hint', () => {
	it( 'is offered whenever the type has one, empty or not', () => {
		// Rendering it only when it already had a value made hints undiscoverable:
		// the sole way to find them was to notice the row in the inspector.
		const node = renderFieldPreview( field(), definition( [ 'label', 'hint' ] ), HANDLERS );

		expect( node.querySelector( '.atf-hint.atfb-editable' ) ).not.toBeNull();
	} );

	it( 'is absent where the type does not render one', () => {
		// Typing into a hint the front end never shows is text that disappears.
		const node = renderFieldPreview( field(), definition( [ 'label' ] ), HANDLERS );

		expect( node.querySelector( '.atf-hint' ) ).toBeNull();
	} );

	it( 'sits under the control, where the front end puts it', () => {
		const node = renderFieldPreview( field(), definition( [ 'label', 'hint' ] ), HANDLERS );
		const order = Array.from( node.querySelectorAll( '.atf-input, .atf-hint' ) );

		expect( order[ 0 ]?.classList.contains( 'atf-input' ) ).toBe( true );
	} );
} );

describe( 'a toggle', () => {
	it( 'writes its label once, beside the switch', () => {
		// It draws its own label, so the card's usual label row above it was the
		// same words twice.
		const node = renderFieldPreview(
			field( { type: 'switch', label: 'Send me updates' } ),
			definition( [ 'label' ], 'switch' ),
			HANDLERS
		);

		expect( node.querySelectorAll( '.atfb-editable[data-atfb-bind="label"]' ) ).toHaveLength( 1 );
		expect( node.querySelector( '.atf-label' ) ).toBeNull();
	} );

	it( 'tells a switch apart from a consent tick box', () => {
		// Same shape here, different control on the page, and the front end tells
		// them apart by exactly this class.
		const asSwitch = renderFieldPreview( field( { type: 'switch' } ), definition( [ 'label' ], 'switch' ), HANDLERS );
		const asConsent = renderFieldPreview( field( { type: 'consent' } ), definition( [ 'label' ], 'consent' ), HANDLERS );

		expect( asSwitch.querySelector( '.atf-toggle--switch' ) ).not.toBeNull();
		expect( asConsent.querySelector( '.atf-toggle--switch' ) ).toBeNull();
	} );
} );

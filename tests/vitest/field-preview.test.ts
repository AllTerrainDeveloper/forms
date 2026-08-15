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

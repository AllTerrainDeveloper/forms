/**
 * The formula editor: what it offers, what it previews, what it saves.
 */

import { describe, expect, it, vi } from 'vitest';
import { formulaSampleValues, formulaTargets, openFormulaEditor, repeaterReferences } from '../../src/formula-editor';
import { calculate } from '../../src/shared/calc';
import type { Field } from '../../src/types';

const field = ( id: string, type: string, label = '' ): Field =>
	( { id, type, label, choices: [], logic: { enabled: false, rules: [] } } ) as unknown as Field;

const FIELDS = [
	field( 'f1', 'number', 'Quantity' ),
	field( 'f2', 'textarea', 'Message' ),
	field( 'f3', 'radio', 'Room' ),
	field( 'f4', 'total', 'Total' ),
];

describe( 'formulaTargets', () => {
	it( 'offers number-shaped questions and not prose', () => {
		const ids = formulaTargets( FIELDS, 'f4' ).map( ( f ) => f.id );

		expect( ids ).toEqual( [ 'f1', 'f3' ] );
	} );

	it( 'never offers the field being edited to itself', () => {
		expect( formulaTargets( FIELDS, 'f1' ).map( ( f ) => f.id ) ).toEqual( [ 'f3', 'f4' ] );
	} );
} );

describe( 'formulaSampleValues', () => {
	it( 'counts up from one so a sum is visibly not zero', () => {
		expect( formulaSampleValues( FIELDS, 'f4' ) ).toEqual( { f1: 1, f3: 2 } );
	} );
} );

describe( 'repeaterReferences', () => {
	const attendees = {
		...field( 'att', 'repeater', 'Attendees' ),
		fields: [ field( 'age', 'number', 'Age' ), field( 'notes', 'textarea', 'Notes' ) ],
	} as unknown as Field;

	it( 'offers the row count and every number-shaped sub-field, never prose', () => {
		const refs = repeaterReferences( [ ...FIELDS, attendees ] );

		expect( refs ).toEqual( [
			{ label: 'Attendees (how many)', insert: '{att}' },
			{ label: 'Attendees · Age', insert: '{att.age}' },
		] );
	} );

	it( 'previews against two sample rows, so an aggregate is visibly an aggregate', () => {
		const fields = [ ...FIELDS, attendees ];
		const samples = formulaSampleValues( fields, 'f4' );

		// Two rows of Age = 1 and Age = 2: the sum is 3 and the count is 2,
		// which no single-row sample could tell apart from a plain reference.
		expect( calculate( 'sum( {att.age} )', samples, fields ) ).toBe( 3 );
		expect( calculate( '{att}', samples, fields ) ).toBe( 2 );
	} );
} );

describe( 'openFormulaEditor', () => {
	const open = ( formula = '' ) => {
		const root = document.createElement( 'div' );
		document.body.append( root );

		const target = { ...field( 'f4', 'total', 'Total' ), formula } as Field & { formula: string };
		const onSave = vi.fn();

		openFormulaEditor( { root, fields: FIELDS, field: target, onSave } );

		return { root, onSave };
	};

	it( 'clicking a question inserts its reference and the preview computes', () => {
		const { root } = open();
		const input = root.querySelector< HTMLTextAreaElement >( '.atfb-formula__input' )!;

		const quantity = [ ...root.querySelectorAll< HTMLButtonElement >( '.atfb-formula__chip' ) ].find(
			( chip ) => chip.textContent === 'Quantity'
		)!;

		quantity.click();
		quantity.click();

		expect( input.value ).toBe( '{f1}{f1}' );

		input.value = '{f1} + {f3} * 10';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( root.querySelector( '.atfb-formula__result' )?.textContent ).toContain( ': 21' );
	} );

	it( 'a formula that does not parse says so instead of a number', () => {
		const { root } = open( '{f1} +' );
		const result = root.querySelector( '.atfb-formula__result' )!;

		expect( result.classList.contains( 'is-error' ) ).toBe( true );
	} );

	it( 'Save hands the trimmed formula back and closes', () => {
		const { root, onSave } = open( '  {f1} * 2  ' );

		[ ...root.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent === 'Save formula' )!.click();

		expect( onSave ).toHaveBeenCalledWith( '{f1} * 2' );
		expect( root.querySelector( '.atfb-formula' ) ).toBeNull();
	} );
} );

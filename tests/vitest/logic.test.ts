/**
 * Conditional logic, browser side.
 *
 * Every case comes from `tests/fixtures/logic-cases.json`, which
 * `tests/phpunit/tests/logic.php` reads too. The two engines have to agree: the
 * browser hides and shows fields as the visitor types, and the server decides
 * which fields were actually required. A disagreement shows somebody a form they
 * cannot submit, with an error about a field they cannot see.
 */

import { describe, expect, it } from 'vitest';
import cases from '../fixtures/logic-cases.json';
import { compare, isEmptyValue, logicPasses, rulePasses, visibleFields } from '../../src/shared/logic';
import type { Field, FieldValue, LogicOperator, LogicRule, Values } from '../../src/types';

describe( 'compare', () => {
	for ( const testCase of cases.compare ) {
		const label = `${ testCase.operator }( ${ JSON.stringify( testCase.actual ) }, ${ JSON.stringify(
			testCase.expected
		) } ) === ${ testCase.result }`;

		it( label, () => {
			expect(
				compare( testCase.operator as LogicOperator, testCase.actual as FieldValue, testCase.expected )
			).toBe( testCase.result );
		} );
	}
} );

describe( 'rulePasses', () => {
	for ( const [ index, testCase ] of cases.rule.entries() ) {
		it( `case ${ index }: ${ testCase.rule.operator } over ${ JSON.stringify( testCase.value ) }`, () => {
			expect( rulePasses( testCase.rule as LogicRule, testCase.value as FieldValue ) ).toBe( testCase.result );
		} );
	}
} );

describe( 'visibleFields', () => {
	for ( const [ index, testCase ] of cases.visibility.entries() ) {
		it( `case ${ index }: ${ testCase._why ?? '' }`, () => {
			const fields = testCase.fields as unknown as Field[];
			const values = testCase.values as Values;

			const visible = visibleFields( fields, values );

			// The cycle case asserts only that the resolver terminates. There is
			// no correct answer to "A shows B, B hides A" — the requirement is
			// that it stops rather than spinning.
			if ( 'terminates' in testCase ) {
				expect( Object.keys( visible ) ).toHaveLength( fields.length );

				return;
			}

			expect( visible ).toEqual( testCase.visible );
		} );
	}
} );

describe( 'logicPasses', () => {
	it( 'passes when logic is disabled, whatever the rules say', () => {
		const logic = {
			enabled: false,
			action: 'show' as const,
			match: 'all' as const,
			rules: [ { field: 'f1', operator: 'is' as const, value: 'never' } ],
		};

		expect( logicPasses( logic, {} ) ).toBe( true );
	} );

	it( 'passes when enabled with no rules at all', () => {
		expect( logicPasses( { enabled: true, action: 'show', match: 'all', rules: [] }, {} ) ).toBe( true );
	} );

	it( 'inverts for a hide action', () => {
		const rules = [ { field: 'f1', operator: 'is' as const, value: 'yes' } ];

		expect( logicPasses( { enabled: true, action: 'hide', match: 'all', rules }, { f1: 'yes' } ) ).toBe( false );
		expect( logicPasses( { enabled: true, action: 'hide', match: 'all', rules }, { f1: 'no' } ) ).toBe( true );
	} );

	it( 'treats a rule naming a field that does not exist as unanswered', () => {
		const logic = {
			enabled: true,
			action: 'show' as const,
			match: 'all' as const,
			rules: [ { field: 'gone', operator: 'empty' as const, value: '' } ],
		};

		expect( logicPasses( logic, {} ) ).toBe( true );
	} );
} );

describe( 'isEmptyValue', () => {
	// Zero is the case every plugin gets wrong: a rating of zero and a scale
	// answer of zero are deliberate choices, and a falsy check rejects the
	// people who gave the lowest score.
	it( 'does not treat zero as empty', () => {
		expect( isEmptyValue( 0 ) ).toBe( false );
		expect( isEmptyValue( '0' ) ).toBe( false );
	} );

	it( 'treats an empty string, null and undefined as empty', () => {
		expect( isEmptyValue( '' ) ).toBe( true );
		expect( isEmptyValue( null ) ).toBe( true );
		expect( isEmptyValue( undefined as unknown as FieldValue ) ).toBe( true );
	} );

	it( 'treats false as empty and true as answered', () => {
		expect( isEmptyValue( false ) ).toBe( true );
		expect( isEmptyValue( true ) ).toBe( false );
	} );

	it( 'looks inside arrays and objects', () => {
		expect( isEmptyValue( [] ) ).toBe( true );
		expect( isEmptyValue( [ '', '' ] ) ).toBe( true );
		expect( isEmptyValue( [ '', 'x' ] ) ).toBe( false );
		expect( isEmptyValue( { first: '', last: '' } ) ).toBe( true );
		expect( isEmptyValue( { first: 'Ada', last: '' } ) ).toBe( false );
	} );
} );

/**
 * Calculations, browser side.
 *
 * Every case comes from `tests/fixtures/calc-cases.json`, which
 * `tests/phpunit/tests/calc.php` reads too. The browser shows a running total as
 * the visitor types and the server recomputes it on submit; a disagreement means
 * the number somebody was shown is not the number that was stored, which on an
 * order form is a charge dispute.
 */

import { describe, expect, it } from 'vitest';
import cases from '../fixtures/calc-cases.json';
import { applyCalculations, calculate, numericValue } from '../../src/shared/calc';
import type { Field, Values } from '../../src/types';

describe( 'calculate', () => {
	for ( const testCase of cases.cases ) {
		const label = `${ testCase.formula || '(empty)' } → ${ testCase.result }`;

		it( label, () => {
			const result = calculate(
				testCase.formula,
				testCase.values as Values,
				( testCase.fields ?? [] ) as unknown as Field[]
			);

			if ( testCase.result === null ) {
				expect( result ).toBeNull();

				return;
			}

			// Floating point: 0.1 + 0.2 is not 0.3 in either language, and the
			// point of the parity is that both are wrong in exactly the same
			// way rather than that either is exact.
			expect( result ).toBeCloseTo( testCase.result as number, 9 );
		} );
	}
} );

describe( 'security', () => {
	// The evaluator is a shunting-yard over a whitelist, not `eval()`. These are
	// the shapes an injection attempt takes, and every one of them has to come
	// back null rather than doing anything at all.
	const attacks = [
		'constructor.constructor("return 1")()',
		'this.alert(1)',
		'window.location',
		'globalThis',
		'process.exit(1)',
		'require("fs")',
		'1;alert(1)',
		'1`+`2',
		'__proto__',
		'fetch("//evil")',
	];

	for ( const attack of attacks ) {
		it( `refuses ${ attack }`, () => {
			expect( calculate( attack, {} ) ).toBeNull();
		} );
	}

	it( 'refuses a formula long enough to be a denial of service', () => {
		expect( calculate( '1+'.repeat( 1200 ) + '1', {} ) ).toBeNull();
	} );
} );

describe( 'numericValue', () => {
	it( 'reads a choice price', () => {
		const field = {
			id: 'f1',
			type: 'select',
			choices: [ { label: 'Big', value: 'big', price: 50 } ],
		} as unknown as Field;

		expect( numericValue( 'big', field ) ).toBe( 50 );
	} );

	it( 'falls back to points when a choice has no price', () => {
		const field = {
			id: 'f1',
			type: 'quiz',
			choices: [ { label: 'Right', value: 'right', points: 3 } ],
		} as unknown as Field;

		expect( numericValue( 'right', field ) ).toBe( 3 );
	} );

	it( 'gives an unanswered field zero', () => {
		expect( numericValue( '', null ) ).toBe( 0 );
		expect( numericValue( null, null ) ).toBe( 0 );
	} );
} );

describe( 'applyCalculations', () => {
	it( 'fills in a total from other fields', () => {
		const fields = [
			{ id: 'f1', type: 'number', choices: [] },
			{ id: 'f2', type: 'number', choices: [] },
			{ id: 'f3', type: 'total', choices: [], formula: '{f1} * {f2}', decimals: 2 },
		] as unknown as Field[];

		const result = applyCalculations( fields, { f1: 4, f2: 2.5 } );

		expect( result.f3 ).toBe( 10 );
	} );

	it( 'lets one total build on another defined before it', () => {
		const fields = [
			{ id: 'f1', type: 'number', choices: [] },
			{ id: 'sub', type: 'total', choices: [], formula: '{f1} * 2', decimals: 2 },
			{ id: 'vat', type: 'total', choices: [], formula: '{sub} * 0.2', decimals: 2 },
			{ id: 'all', type: 'total', choices: [], formula: '{sub} + {vat}', decimals: 2 },
		] as unknown as Field[];

		const result = applyCalculations( fields, { f1: 100 } );

		expect( result.sub ).toBe( 200 );
		expect( result.vat ).toBe( 40 );
		expect( result.all ).toBe( 240 );
	} );

	it( 'rounds to the field’s decimals', () => {
		const fields = [
			{ id: 'f1', type: 'number', choices: [] },
			{ id: 'f2', type: 'total', choices: [], formula: '{f1} / 3', decimals: 2 },
		] as unknown as Field[];

		expect( applyCalculations( fields, { f1: 10 } ).f2 ).toBe( 3.33 );
	} );

	it( 'empties a field when its formula cannot be evaluated, never trusting the posted value', () => {
		const fields = [
			{ id: 'f1', type: 'total', choices: [], formula: '( 1 + ', decimals: 2 },
		] as unknown as Field[];

		// Keeping the incoming value here would let a tampered client total
		// survive a formula failure on the server twin.
		expect( applyCalculations( fields, { f1: '999.99' } ).f1 ).toBe( '' );
	} );
} );

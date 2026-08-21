/**
 * The custom-rule builder's compiler.
 *
 * The editor's promise is that a person who fills in "starts with AT-" never
 * has to read `^(?=AT\-).*$` — which means the compiler is the part that has
 * to be right. These tests pin the compiled behaviour (what passes, what
 * fails) rather than the exact pattern text, because the pattern is an
 * implementation the compiler is free to improve.
 */

import { describe, expect, it } from 'vitest';
import {
	compileRecipe,
	describeRecipe,
	emptyRecipe,
	escapeRegex,
	parseRecipe,
	recipePasses,
	type ValidationRecipe,
} from '../../src/validation-editor';

/** A recipe with the given blocks filled in. */
function recipe( extra: Partial< ValidationRecipe > ): ValidationRecipe {
	return { ...emptyRecipe(), ...extra };
}

describe( 'compileRecipe', () => {
	it( 'compiles to nothing when no block says anything', () => {
		expect( compileRecipe( emptyRecipe() ) ).toBe( '' );
	} );

	it( 'enforces starts, ends, contains and not-contains together', () => {
		const rule = recipe( { starts: 'AT-', ends: 'X', contains: '7', notContains: ' ' } );

		expect( recipePasses( rule, 'AT-77X' ) ).toBe( true );
		expect( recipePasses( rule, 'AT-88X' ) ).toBe( false );
		expect( recipePasses( rule, 'AT-7 7X' ) ).toBe( false );
		expect( recipePasses( rule, 'GT-77X' ) ).toBe( false );
		expect( recipePasses( rule, 'AT-77Y' ) ).toBe( false );
	} );

	it( 'treats user text as text, not as syntax', () => {
		// The person typing "3.50 (approx)" means those characters literally;
		// the dot must not become "any character" on the way through.
		const rule = recipe( { contains: '3.50 (approx)' } );

		expect( recipePasses( rule, 'about 3.50 (approx) total' ) ).toBe( true );
		expect( recipePasses( rule, 'about 3x50 approx total' ) ).toBe( false );
	} );

	it( 'restricts the alphabet when character groups are ticked', () => {
		const rule = recipe( { chars: [ 'letters', 'numbers' ] } );

		expect( recipePasses( rule, 'abc123' ) ).toBe( true );
		expect( recipePasses( rule, 'olá123' ) ).toBe( true );
		expect( recipePasses( rule, 'abc 123' ) ).toBe( false );
		expect( recipePasses( rule, 'abc@123' ) ).toBe( false );
	} );

	it( 'bounds the length from either or both ends', () => {
		expect( recipePasses( recipe( { minLen: '3', maxLen: '5' } ), 'abcd' ) ).toBe( true );
		expect( recipePasses( recipe( { minLen: '3', maxLen: '5' } ), 'ab' ) ).toBe( false );
		expect( recipePasses( recipe( { minLen: '3', maxLen: '5' } ), 'abcdef' ) ).toBe( false );
		expect( recipePasses( recipe( { minLen: '3', maxLen: '' } ), 'abcdefgh' ) ).toBe( true );
		expect( recipePasses( recipe( { minLen: '', maxLen: '2' } ), 'ab' ) ).toBe( true );
	} );

	it( 'passes a raw expression through untouched in expert mode', () => {
		const rule = recipe( { mode: 'regex', regex: '^AT-[0-9]{4}$' } );

		expect( compileRecipe( rule ) ).toBe( '^AT-[0-9]{4}$' );
		expect( recipePasses( rule, 'AT-2026' ) ).toBe( true );
		expect( recipePasses( rule, 'AT-26' ) ).toBe( false );
	} );

	it( 'gives no verdict for an expression that does not compile', () => {
		expect( recipePasses( recipe( { mode: 'regex', regex: '[' } ), 'anything' ) ).toBeNull();
	} );
} );

describe( 'describeRecipe', () => {
	it( 'says the rule in one plain sentence', () => {
		const rule = recipe( { starts: 'AT-', contains: '@', chars: [ 'letters', 'numbers' ], minLen: '4', maxLen: '20' } );

		expect( describeRecipe( rule ) ).toBe(
			'The answer starts with “AT-”, contains “@”, uses only letters and numbers, and is 4–20 characters long.'
		);
	} );

	it( 'says nothing about an empty recipe', () => {
		expect( describeRecipe( emptyRecipe() ) ).toBe( '' );
	} );
} );

describe( 'parseRecipe', () => {
	it( 'round-trips what the editor stores', () => {
		const stored = recipe( { starts: 'AT-', chars: [ 'numbers' ], tests: [ 'AT-1', 'GT-2' ] } );

		expect( parseRecipe( JSON.stringify( stored ) ) ).toEqual( stored );
	} );

	it( 'degrades garbage to a blank editor rather than a crashed one', () => {
		expect( parseRecipe( 'not json' ) ).toEqual( emptyRecipe() );
		expect( parseRecipe( '"a string"' ) ).toEqual( emptyRecipe() );
	} );

	it( 'drops character groups it does not know', () => {
		const parsed = parseRecipe( JSON.stringify( { chars: [ 'numbers', 'emoji' ] } ) );

		expect( parsed.chars ).toEqual( [ 'numbers' ] );
	} );
} );

describe( 'escapeRegex', () => {
	it( 'neutralises every character with regex meaning', () => {
		const special = '.*+?^${}()|[]\\/';

		expect( new RegExp( `^${ escapeRegex( special ) }$` ).test( special ) ).toBe( true );
	} );
} );

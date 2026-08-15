/**
 * Every setting a field type declares can be reached.
 *
 * A field type says what it supports; the inspector decides what to draw. Nothing
 * joined those two, and the result was not a bug anybody could see: thirty-two
 * supported settings had no control anywhere in the builder. The heading level on
 * a heading. The markup in an HTML block. The file types a file field accepts. How
 * many rows a repeater allows. The words on the ends of a scale. The button at the
 * bottom of a page break — which is where this started, because a form in any
 * language other than English shipped a button saying "Next" and there was nothing
 * to be done about it.
 *
 * Every one of them worked perfectly once set. That is exactly why nobody noticed:
 * the renderer honoured them, the schema stored them, the tests that existed
 * passed, and the only way to set one was to export the form as JSON, edit it, and
 * import it back.
 *
 * So the invariant is asserted directly, against the PHP that registers the types
 * rather than against a copy of it. A list of flags kept by hand here would agree
 * with itself forever while disagreeing with the plugin.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { SETTING_CONTROLS, SETTINGS_HANDLED_ELSEWHERE } from '../../src/builder';

/**
 * Every `supports` flag any built-in type declares.
 *
 * Read out of the source because that is the thing this has to disagree with. The
 * alternative — asking a running WordPress — would be a truer answer and an
 * untestable one from here, and the built-in registrations are a plain literal, so
 * reading them is unambiguous.
 */
function declaredFlags(): string[] {
	// Resolved from the working directory rather than from `import.meta.url`,
	// which under the jsdom environment is not a file URL.
	const php = readFileSync( resolve( process.cwd(), 'includes/field-types.php' ), 'utf8' );
	const flags = new Set< string >();

	// `atf_input_supports()` contributes its common set to almost every type, so
	// its own array is scanned as well as each type's extras.
	for ( const block of php.matchAll( /'supports'\s*=>\s*(?:atf_input_supports\(\s*)?array\(([^)]*)\)/g ) ) {
		for ( const flag of block[ 1 ].matchAll( /'([a-z]+)'/g ) ) {
			flags.add( flag[ 1 ] );
		}
	}

	for ( const flag of [ 'label', 'placeholder', 'hint', 'required', 'default', 'width', 'css', 'prefill', 'logic' ] ) {
		flags.add( flag );
	}

	return [ ...flags ].sort();
}

describe( 'the settings a field type declares', () => {
	it( 'were read out of the registrations at all', () => {
		// Guards the regex above rather than the plugin: if it stopped matching,
		// every other assertion here would pass over an empty list and this file
		// would be a test that cannot fail.
		const flags = declaredFlags();

		expect( flags.length ).toBeGreaterThan( 30 );
		expect( flags ).toContain( 'nextlabel' );
		expect( flags ).toContain( 'consenttext' );
	} );

	it( 'can every one of them be reached from the builder', () => {
		const unreachable = declaredFlags().filter(
			( flag ) => ! ( flag in SETTING_CONTROLS ) && ! ( flag in SETTINGS_HANDLED_ELSEWHERE )
		);

		expect(
			unreachable,
			'These settings are honoured by the renderer but no control writes them. ' +
				'Add a row to SETTING_CONTROLS, or — if it is edited somewhere else — say ' +
				'where in SETTINGS_HANDLED_ELSEWHERE.'
		).toEqual( [] );
	} );

	it( 'and nothing is claimed to be reachable that no type declares', () => {
		// The other direction, which is what goes stale after a flag is removed: a
		// control for a setting nothing has is a row that never appears, and an
		// entry in the "handled elsewhere" list is a note about nothing.
		const declared = new Set( declaredFlags() );
		const orphaned = [ ...Object.keys( SETTING_CONTROLS ), ...Object.keys( SETTINGS_HANDLED_ELSEWHERE ) ].filter(
			( flag ) => ! declared.has( flag )
		);

		expect( orphaned ).toEqual( [] );
	} );
} );

describe( 'the table itself', () => {
	it( 'gives a select something to select from', () => {
		for ( const [ flag, setting ] of Object.entries( SETTING_CONTROLS ) ) {
			if ( 'select' === setting.control ) {
				expect( setting.options?.length, `${ flag } is a dropdown with no options` ).toBeGreaterThan( 0 );
			}
		}
	} );

	it( 'names a field property for every row, and for every paired row', () => {
		// The label is what somebody reads; the key is what gets written. A row
		// with the wrong key edits nothing and looks like it worked.
		for ( const [ flag, setting ] of Object.entries( SETTING_CONTROLS ) ) {
			expect( setting.key, `${ flag } writes nothing` ).toBeTruthy();
			expect( setting.label, `${ flag } is unlabelled` ).toBeTruthy();

			if ( setting.also ) {
				expect( setting.also.key, `${ flag }'s companion writes nothing` ).toBeTruthy();
				expect( setting.also.key ).not.toBe( setting.key );
			}
		}
	} );
} );

/**
 * Rewriting a Likert matrix's statements.
 *
 * The statements are edited as a block of text, one per line, which is the right
 * control for a handful of short sentences pasted in from somewhere else. What it
 * hides is that a row is not a string: it is `{ key, label }`, and the key is what
 * every answer is stored against.
 *
 * So the dangerous edit is the harmless-looking one — fixing a typo in a
 * statement. If that minted a new key, every answer already given to that
 * statement would detach from it, in entries collected months ago that nobody
 * looks at again until an export. Nothing would error. The matrix would simply
 * start counting from zero.
 */

import { restatement } from '../../src/builder';

describe( 'restatement', () => {
	const rows = [
		{ key: 'r1', label: 'The room was clean' },
		{ key: 'r2', label: 'The staff were helpful' },
	];

	it( 'keeps a statement’s key when its wording changes', () => {
		const next = restatement( rows, 'The room was clean\nThe staff were friendly' );

		expect( next.map( ( r ) => r.key ) ).toEqual( [ 'r1', 'r2' ] );
		expect( next[ 1 ].label ).toBe( 'The staff were friendly' );
	} );

	it( 'gives a new statement a key that has never been used', () => {
		const next = restatement( rows, 'The room was clean\nThe staff were helpful\nI would come back' );

		expect( next[ 2 ].key ).not.toBe( 'r1' );
		expect( next[ 2 ].key ).not.toBe( 'r2' );
		expect( new Set( next.map( ( r ) => r.key ) ).size ).toBe( 3 );
	} );

	it( 'does not reuse the key of a statement that was deleted earlier', () => {
		// Answers to the old r3 may still be sitting in entries. A new statement
		// that took its key would inherit them.
		const sparse = [
			{ key: 'r1', label: 'One' },
			{ key: 'r3', label: 'Three' },
		];

		const next = restatement( sparse, 'One\nThree\nFour' );

		expect( next.map( ( r ) => r.key ) ).toEqual( [ 'r1', 'r3', 'r4' ] );
	} );

	it( 'drops blank lines rather than making an unnamed row', () => {
		expect( restatement( rows, 'One\n\n   \nTwo' ) ).toHaveLength( 2 );
	} );

	it( 'returns nothing for an empty box', () => {
		expect( restatement( rows, '   ' ) ).toEqual( [] );
	} );
} );

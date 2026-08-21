/**
 * Validation presets, browser side.
 *
 * Every case comes from `tests/fixtures/validation-cases.json`, which
 * `tests/phpunit/tests/validationPresets.php` reads too. The preset tables in
 * `src/shared/validation.ts` and `includes/validation.php` are twins and have
 * to agree: the browser tells the visitor "that does not look like an email
 * address" as they type, and the server decides whether the submission is
 * accepted. A disagreement rejects an answer the form itself said was fine.
 */

import { describe, expect, it } from 'vitest';
import cases from '../fixtures/validation-cases.json';
import {
	VALIDATION_GROUPS,
	VALIDATION_PRESETS,
	luhnPasses,
	presetPasses,
	validationPreset,
} from '../../src/shared/validation';

describe( 'presetPasses', () => {
	for ( const testCase of cases.presets ) {
		const label = `${ testCase.preset }( ${ JSON.stringify( testCase.value ) } ) === ${ testCase.valid }${
			testCase._why ? ` — ${ testCase._why }` : ''
		}`;

		it( label, () => {
			expect( presetPasses( testCase.preset, testCase.value ) ).toBe( testCase.valid );
		} );
	}

	it( 'answers null for a preset this build does not know, leaving the server to decide', () => {
		expect( presetPasses( 'from_the_future', 'anything' ) ).toBeNull();
	} );
} );

describe( 'the preset table', () => {
	it( 'covers every fixture slug', () => {
		const slugs = new Set( cases.presets.map( ( testCase ) => testCase.preset ) );

		for ( const slug of slugs ) {
			expect( validationPreset( slug ), slug ).not.toBeNull();
		}
	} );

	it( 'has a fixture case for every preset, so no preset ships untested', () => {
		const tested = new Set( cases.presets.map( ( testCase ) => testCase.preset ) );

		for ( const preset of VALIDATION_PRESETS ) {
			expect( tested.has( preset.slug ), preset.slug ).toBe( true );
		}
	} );

	it( 'files every preset under a group the picker knows', () => {
		for ( const preset of VALIDATION_PRESETS ) {
			expect( VALIDATION_GROUPS, preset.slug ).toContain( preset.group );
		}
	} );

	it( 'gives every preset an example that passes its own rule', () => {
		// The example is shown as "e.g. …" beside the picker; an example the
		// preset itself rejects would teach people the wrong format.
		for ( const preset of VALIDATION_PRESETS ) {
			expect( presetPasses( preset.slug, preset.example ), `${ preset.slug }: ${ preset.example }` ).toBe( true );
		}
	} );
} );

describe( 'luhnPasses', () => {
	it( 'accepts a checksum-valid card number however it is grouped', () => {
		expect( luhnPasses( '4242424242424242' ) ).toBe( true );
		expect( luhnPasses( '4242 4242 4242 4242' ) ).toBe( true );
		expect( luhnPasses( '4242-4242-4242-4242' ) ).toBe( true );
	} );

	it( 'rejects a single-digit typo', () => {
		expect( luhnPasses( '4242424242424241' ) ).toBe( false );
	} );

	it( 'rejects a value with too few digits to be a card at all', () => {
		expect( luhnPasses( '42' ) ).toBe( false );
	} );
} );

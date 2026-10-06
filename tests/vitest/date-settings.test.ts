/**
 * Date, time and colour settings in the builder are picked, not typed.
 *
 * The feedback that started this: nobody knew what to type into "Earliest
 * date", or in what format. The controls now store the ISO shape the server
 * compares against while showing a calendar — and a value somebody typed by
 * hand before this existed is named, never silently thrown away.
 */

import { describe, expect, it } from 'vitest';
import { colorInput, dateInput, isDarkColor } from '../../src/ui';

describe( 'dateInput', () => {
	it.each( [
		[ 'date', '2026-10-31' ],
		[ 'time', '09:30' ],
		[ 'datetime-local', '2026-10-31T09:30' ],
	] as const )( 'is a native %s box holding the stored value', ( kind, value ) => {
		const control = dateInput( value, () => {}, kind );
		const input = control.querySelector( 'input' )!;

		expect( input.type ).toBe( kind );
		expect( input.value ).toBe( value );
		expect( control.querySelector< HTMLElement >( '.atfb-datefield__note' )!.hidden ).toBe( true );
	} );

	it( 'reports a picked date', () => {
		const seen: string[] = [];
		const control = dateInput( '', ( value ) => seen.push( value ) );
		const input = control.querySelector( 'input' )!;

		input.value = '2026-12-01';
		input.dispatchEvent( new Event( 'change' ) );

		expect( seen ).toEqual( [ '2026-12-01' ] );
		expect( control.querySelector< HTMLElement >( '.atfb-datefield__clear' )!.hidden ).toBe( false );
	} );

	it( 'clears back to no limit', () => {
		const seen: string[] = [];
		const control = dateInput( '2026-10-31', ( value ) => seen.push( value ) );
		const clear = control.querySelector< HTMLButtonElement >( '.atfb-datefield__clear' )!;

		expect( clear.hidden ).toBe( false );

		clear.click();

		expect( seen ).toEqual( [ '' ] );
		expect( control.querySelector( 'input' )!.value ).toBe( '' );
		expect( clear.hidden ).toBe( true );
	} );

	it( 'names a hand-typed value the calendar cannot show instead of hiding it', () => {
		const control = dateInput( '31/10/2026', () => {} );
		const note = control.querySelector< HTMLElement >( '.atfb-datefield__note' )!;

		expect( note.hidden ).toBe( false );
		expect( note.textContent ).toContain( '31/10/2026' );
	} );

	it( 'binds a row label to the box itself', () => {
		const control = dateInput( '', () => {} );

		// `row()` labels the first real control inside a wrapper.
		expect( control.querySelector( 'input, select, textarea' ) ).toBe( control.querySelector( '.atfb-datefield__input' ) );
	} );
} );

describe( 'isDarkColor', () => {
	it.each( [
		[ 'rgb(30, 30, 30)', true ],
		[ 'rgb(246, 247, 247)', false ],
		[ 'rgba(0, 0, 0, 0)', false ],
		[ 'rgba(20, 20, 20, 0.9)', true ],
		[ 'transparent', false ],
	] )( '%s is dark: %s', ( color, dark ) => {
		expect( isDarkColor( color ) ).toBe( dark );
	} );
} );

describe( 'colorInput', () => {
	it( 'saves a normalised code and allows none', () => {
		const seen: string[] = [];
		const control = colorInput( '', ( value ) => seen.push( value ) );
		const text = control.querySelector< HTMLInputElement >( '.atfb-colorfield__hex' )!;
		const swatch = control.querySelector< HTMLInputElement >( '.atfb-colorfield__swatch' )!;

		expect( swatch.classList.contains( 'is-empty' ) ).toBe( true );

		text.value = '#AB';
		text.dispatchEvent( new Event( 'input' ) );
		expect( seen ).toEqual( [] );

		text.value = '#ABC';
		text.dispatchEvent( new Event( 'input' ) );
		text.dispatchEvent( new Event( 'change' ) );
		expect( seen[ seen.length - 1 ] ).toBe( '#aabbcc' );
		expect( text.value ).toBe( '#aabbcc' );

		text.value = '';
		text.dispatchEvent( new Event( 'input' ) );
		expect( seen[ seen.length - 1 ] ).toBe( '' );
	} );
} );

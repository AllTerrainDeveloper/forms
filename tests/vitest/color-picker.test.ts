/**
 * The front-end colour picker.
 *
 * The colour maths is pinned against known values, and the enhancement is
 * driven the way a visitor drives it: open, pick, type, close. The one rule
 * everything else hangs off is that the hex text box stays the only named
 * input — the panel writes into it, and never adds a control of its own that
 * the form would read or post.
 */

import { afterEach, describe, expect, it } from 'vitest';
import { enhanceColorField, hexToHsv, hsvToHex, normalizeHex } from '../../src/color-picker';

const t = ( _key: string, fallback: string ): string => fallback;

/** Prints the server's markup for one colour field and enhances it. */
function mount( { value = '', swatches = '', required = false } = {} ): {
	wrapper: HTMLElement;
	input: HTMLInputElement;
	toggle: () => HTMLButtonElement;
	panel: () => HTMLElement;
} {
	document.body.innerHTML = `
		<form>
			<div class="atf-color" data-atf-color${ swatches ? ` data-atf-swatches="${ swatches }"` : '' }>
				<span class="atf-color__chip${ value ? '' : ' is-empty' }" aria-hidden="true"></span>
				<input type="text" class="atf-input atf-color__input" id="atf-f1" name="atf[f1]" value="${ value }"${ required ? ' required' : '' }>
			</div>
			<button type="button" id="elsewhere">Elsewhere</button>
		</form>
	`;

	const wrapper = document.querySelector< HTMLElement >( '[data-atf-color]' )!;

	enhanceColorField( wrapper, t );

	return {
		wrapper,
		input: wrapper.querySelector< HTMLInputElement >( '.atf-color__input' )!,
		toggle: () => wrapper.querySelector< HTMLButtonElement >( '.atf-color__swatch' )!,
		panel: () => wrapper.querySelector< HTMLElement >( '.atf-color__panel' )!,
	};
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'normalizeHex', () => {
	// Kept in step with `data_hex_colors()` in tests/phpunit/tests/colorField.php.
	it.each( [
		[ '#3366ff', '#3366ff' ],
		[ '#3366FF', '#3366ff' ],
		[ '3366ff', '#3366ff' ],
		[ '#36f', '#3366ff' ],
		[ 'ABC', '#aabbcc' ],
		[ '  #abcdef ', '#abcdef' ],
		[ '', '' ],
		[ 'red', '' ],
		[ '#1234567', '' ],
		[ '#1234', '' ],
		[ '#ggg', '' ],
		[ '#fff;background:url(x)', '' ],
	] )( '%j becomes %j', ( raw, expected ) => {
		expect( normalizeHex( raw ) ).toBe( expected );
	} );
} );

describe( 'HSV conversion', () => {
	it.each( [ '#ff0000', '#00ff00', '#0000ff', '#ffffff', '#000000', '#3366ff', '#f59e0b', '#808080' ] )(
		'round-trips %s',
		( hex ) => {
			expect( hsvToHex( hexToHsv( hex ) ) ).toBe( hex );
		}
	);

	it( 'reads pure colours at the right hues', () => {
		expect( hexToHsv( '#ff0000' ) ).toEqual( { h: 0, s: 1, v: 1 } );
		expect( hexToHsv( '#00ff00' ).h ).toBe( 120 );
		expect( hexToHsv( '#0000ff' ).h ).toBe( 240 );
	} );

	it( 'gives greys no saturation', () => {
		expect( hexToHsv( '#808080' ).s ).toBe( 0 );
	} );
} );

describe( 'enhanceColorField', () => {
	it( 'turns the chip into a button and adds a hidden panel', () => {
		const { toggle, panel } = mount();

		expect( toggle().getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( toggle().getAttribute( 'aria-controls' ) ).toBe( panel().id );
		expect( toggle().querySelector( '.atf-color__chip' ) ).not.toBeNull();
		expect( panel().hidden ).toBe( true );
	} );

	it( 'adds no named input and no input at all besides the hex box', () => {
		const { wrapper } = mount( { swatches: '#1d4ed8,#f59e0b' } );

		expect( wrapper.querySelectorAll( 'input' ) ).toHaveLength( 1 );
		expect( wrapper.querySelectorAll( '[name]' ) ).toHaveLength( 1 );
	} );

	it( 'is idempotent', () => {
		const { wrapper } = mount();

		enhanceColorField( wrapper, t );

		expect( wrapper.querySelectorAll( '.atf-color__swatch' ) ).toHaveLength( 1 );
		expect( wrapper.querySelectorAll( '.atf-color__panel' ) ).toHaveLength( 1 );
	} );

	it( 'opens and focuses the area, and Escape closes back onto the button', () => {
		const { toggle, panel } = mount();

		toggle().click();

		expect( panel().hidden ).toBe( false );
		expect( toggle().getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( document.activeElement?.classList.contains( 'atf-color__area' ) ).toBe( true );

		panel().dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

		expect( panel().hidden ).toBe( true );
		expect( document.activeElement ).toBe( toggle() );
	} );

	it( 'writes a picked swatch into the box and fires input', () => {
		const { input, toggle, wrapper } = mount( { swatches: '#1D4ED8,f59e0b' } );
		const seen: string[] = [];

		input.addEventListener( 'input', () => seen.push( input.value ) );
		toggle().click();

		const presets = wrapper.querySelectorAll< HTMLButtonElement >( '.atf-color__preset' );

		expect( Array.from( presets ).map( ( preset ) => preset.value ) ).toEqual( [ '#1d4ed8', '#f59e0b' ] );

		presets[ 1 ].click();

		expect( input.value ).toBe( '#f59e0b' );
		expect( seen ).toEqual( [ '#f59e0b' ] );
		expect( presets[ 1 ].getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( wrapper.querySelector( '.atf-color__chip' )!.classList.contains( 'is-empty' ) ).toBe( false );
	} );

	it( 'moves through the colour space from the keyboard', () => {
		const { input, toggle, wrapper } = mount( { value: '#ff0000' } );

		toggle().click();

		const hue = wrapper.querySelector< HTMLElement >( '.atf-color__hue' )!;

		hue.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'End', bubbles: true } ) );
		expect( hexToHsv( input.value ).h ).toBeGreaterThan( 350 );

		hue.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Home', bubbles: true } ) );
		expect( input.value ).toBe( '#ff0000' );

		const area = wrapper.querySelector< HTMLElement >( '.atf-color__area' )!;

		area.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', shiftKey: true, bubbles: true } ) );
		expect( hexToHsv( input.value ).v ).toBeCloseTo( 0.9, 1 );
		expect( hue.getAttribute( 'aria-valuenow' ) ).toBe( '0' );
	} );

	it( 'tidies a typed code when the box is left, and paints the chip as it is typed', () => {
		const { input, wrapper } = mount();
		const chip = wrapper.querySelector< HTMLElement >( '.atf-color__chip' )!;

		input.value = '36F';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( input.value ).toBe( '36F' );
		expect( chip.classList.contains( 'is-empty' ) ).toBe( false );

		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( input.value ).toBe( '#3366ff' );
	} );

	it( 'offers "No colour" on an optional field, and clears it', () => {
		const { input, toggle, wrapper } = mount( { value: '#3366ff' } );

		toggle().click();

		const none = Array.from( wrapper.querySelectorAll< HTMLButtonElement >( '.atf-color__action' ) ).find(
			( button ) => button.textContent === 'No colour'
		);

		none!.click();

		expect( input.value ).toBe( '' );
		expect( wrapper.querySelector( '.atf-color__chip' )!.classList.contains( 'is-empty' ) ).toBe( true );
	} );

	it( 'does not offer "No colour" on a required field', () => {
		const { wrapper } = mount( { required: true } );

		expect(
			Array.from( wrapper.querySelectorAll( '.atf-color__action' ) ).some(
				( button ) => button.textContent === 'No colour'
			)
		).toBe( false );
	} );

	it( 'fires change on close when the colour moved', () => {
		const { input, toggle, wrapper, panel } = mount( { swatches: '#1d4ed8' } );
		let changes = 0;

		input.addEventListener( 'change', () => changes++ );
		toggle().click();
		wrapper.querySelector< HTMLButtonElement >( '.atf-color__preset' )!.click();
		panel().dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );

		expect( changes ).toBe( 1 );
	} );

	it( 'closes when somebody presses elsewhere', () => {
		const { toggle, panel } = mount();

		toggle().click();
		document.getElementById( 'elsewhere' )!.dispatchEvent( new Event( 'pointerdown', { bubbles: true } ) );

		expect( panel().hidden ).toBe( true );
	} );

} );

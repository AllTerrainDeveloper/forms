import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { boot } from '../../src/form';
import { renderFieldPreview } from '../../src/field-preview';
import type { Field } from '../../src/types';

beforeAll( () => {
	Element.prototype.scrollIntoView = () => {};
	( globalThis as { CSS?: { escape: ( value: string ) => string } } ).CSS ??= { escape: ( value ) => value };
} );
afterEach( () => document.body.replaceChildren() );

describe.each( [ 'input', 'output' ] )( 'total display: %s', ( display ) => {
	it( 'updates live, submits the value, and keeps display inputs disabled after hide/show', () => {
		const total = display === 'output' ? '<output data-atf-total></output>' : '<input data-atf-total disabled>';
		const fields = [
			{ id: 'qty', type: 'number' },
			{ id: 'total', type: 'total', formula: '{qty} * 2.5', decimals: 2, display, logic: {
				enabled: true, action: 'show', match: 'all', rules: [ { field: 'qty', operator: 'greater', value: '0' } ],
			} },
			{ id: 'grand', type: 'total', formula: '{total} + 1', decimals: 2 },
		];
		document.body.innerHTML = `<form data-atf-form="1" data-atf-instance="totals">
			<div data-atf-field="qty"><input name="atf[qty]" value="2"></div>
			<div data-atf-field="total">${ total }<input type="hidden" name="atf[total]" data-atf-total-value></div>
			<div data-atf-field="grand"><output data-atf-total></output><input type="hidden" name="atf[grand]" data-atf-total-value></div>
		</form><script type="application/json" id="totals-schema">${ JSON.stringify( { fields, settings: { ajax: false } } ) }</script>`;
		boot();
		const form = document.querySelector( 'form' )!;
		const output = form.querySelector< HTMLInputElement | HTMLOutputElement >( '[data-atf-total]' )!;
		expect( output.value ).toBe( '5.00' );
		expect( new FormData( form ).getAll( 'atf[total]' ) ).toEqual( [ '5.00' ] );
		expect( new FormData( form ).get( 'atf[grand]' ) ).toBe( '6.00' );
		const qty = form.querySelector< HTMLInputElement >( '[name="atf[qty]"]' )!;
		qty.value = '0';
		qty.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( new FormData( form ).has( 'atf[total]' ) ).toBe( false );
		qty.value = '3';
		qty.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( output.value ).toBe( '7.50' );
		expect( new FormData( form ).getAll( 'atf[total]' ) ).toEqual( [ '7.50' ] );
		expect( new FormData( form ).get( 'atf[grand]' ) ).toBe( '8.50' );
		if ( output instanceof HTMLInputElement ) expect( output.disabled ).toBe( true );
		else expect( output.textContent ).toBe( '7.50' );
	} );

	it( 'reflects the display and currency on the builder canvas', () => {
		const field: Field = {
			id: 'total', type: 'total', label: 'Total', currency: '€', display,
			placeholder: '', hint: '', required: false, width: 'full', cssClass: '', default: '', choices: [],
			logic: { enabled: false, action: 'show', match: 'all', rules: [] }, messages: {}, prefill: '',
		};
		const preview = renderFieldPreview( field, undefined, { edit: () => {}, restructure: () => {} } );
		expect( preview.querySelector( '.atf-total__currency' )!.textContent ).toBe( '€' );
		expect( preview.querySelector( display === 'output' ? 'output.atf-total__output' : 'input.atf-total__input:disabled' ) ).not.toBeNull();
		expect( preview.querySelector( '[data-atfb-bind="placeholder"]' ) ).toBeNull();
	} );
} );

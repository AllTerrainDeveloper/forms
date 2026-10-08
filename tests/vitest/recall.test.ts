/**
 * Answer recall: `{field:name}` in a label shows what the visitor answered.
 */

import { beforeAll, describe, expect, it } from 'vitest';
import { readableLabel, recallGroups, recallText, recallable } from '../../src/shared/recall';
import { boot } from '../../src/form';
import type { Field } from '../../src/types';

const field = ( id: string, type: string, label = '', extra: Partial< Field > = {} ): Field =>
	( { id, type, label, choices: [], logic: { enabled: false, rules: [] }, ...extra } ) as unknown as Field;

const size = field( 'size', 'radio', 'Size', {
	choices: [ { value: 's', label: 'Small' }, { value: 'l', label: 'Large (£12)' } ],
} );

describe( 'recallText', () => {
	it( 'reads a choice as the label the visitor picked, not its stored value', () => {
		expect( recallText( size, 'l' ) ).toBe( 'Large (£12)' );
		expect( recallText( { ...size, type: 'checkboxes' } as Field, [ 's', 'l' ] ) ).toBe( 'Small, Large (£12)' );
	} );

	it( 'joins a composite by its non-empty parts', () => {
		expect( recallText( field( 'n', 'name' ), { first: 'Ada', middle: '', last: 'Lovelace' } ) ).toBe( 'Ada Lovelace' );
		expect( recallText( field( 'a', 'address' ), { line1: '1 High St', city: 'London' } ) ).toBe( '1 High St, London' );
	} );

	it( 'is blank until answered, and says yes or no for a switch', () => {
		expect( recallText( field( 'x', 'text' ), '' ) ).toBe( '' );
		expect( recallText( field( 'x', 'text' ), null ) ).toBe( '' );
		expect( recallText( field( 's', 'switch' ), true, { yes: 'Sí', no: 'No' } ) ).toBe( 'Sí' );
	} );
} );

describe( 'readableLabel', () => {
	it( 'names the question a label recalls instead of printing the tag', () => {
		const name = field( 'name', 'text', 'Your name' );
		const brand = field( 'brand', 'color', '{field:name} choose the brand colour' );

		expect( readableLabel( brand, [ name, brand ] ) ).toBe( '‹Your name› choose the brand colour' );
		expect( readableLabel( field( 'x', 'text', 'Hi {field:gone}' ), [] ) ).toBe( 'Hi ‹gone›' );
		expect( readableLabel( field( 'x', 'text' ), [] ) ).toBe( 'x' );
	} );
} );

describe( 'recallGroups', () => {
	const fields = [
		field( 'name', 'text', 'Your name' ),
		field( 'pw', 'password', 'Password' ),
		field( 'age', 'number', 'Age' ),
		field( 'intro', 'heading', 'About you' ),
		field( 'size', 'radio', 'Size' ),
	];

	it( 'offers earlier answers, then this question, then later ones', () => {
		const [ earlier, self, later ] = recallGroups( fields, 'age' );

		expect( earlier.items.map( ( item ) => item.tag ) ).toEqual( [ '{field:name}' ] );
		expect( self.label ).toBe( 'This question' );
		expect( self.items.map( ( item ) => item.tag ) ).toEqual( [ '{field:age}' ] );
		expect( later.items.map( ( item ) => item.tag ) ).toEqual( [ '{field:size}' ] );
		expect( later.items[ 0 ].hint ).toMatch( /later in the form/ );
	} );

	it( 'leaves the field itself out of its own placeholder', () => {
		const tags = recallGroups( fields, 'name', false ).flatMap( ( group ) => group.items.map( ( item ) => item.tag ) );

		expect( tags ).toEqual( [ '{field:age}', '{field:size}' ] );
	} );

	it( 'never offers a password, a file or a layout block', () => {
		expect( recallable( field( 'p', 'password' ) ) ).toBe( false );
		expect( recallable( field( 'f', 'file' ) ) ).toBe( false );
		expect( recallable( field( 'h', 'heading' ) ) ).toBe( false );
		const tags = recallGroups( fields, 'nothing' ).flatMap( ( group ) => group.items.map( ( item ) => item.tag ) );
		expect( tags ).toEqual( [ '{field:name}', '{field:age}', '{field:size}' ] );
	} );

	it( 'explains an empty list instead of showing nothing', () => {
		const [ earlier ] = recallGroups( [ field( 'only', 'text', 'Only' ) ], 'only', false );

		expect( earlier.items ).toEqual( [] );
		expect( earlier.empty ).toMatch( /Add another question/ );
	} );
} );

describe( 'the form fills recalled answers live', () => {
	beforeAll( () => {
		Element.prototype.scrollIntoView = () => {};
		( globalThis as { CSS?: { escape: ( value: string ) => string } } ).CSS ??= { escape: ( value: string ) => value };
	} );

	it( 'writes the answer into every slot as the visitor types, and keeps a typo visible', () => {
		const schema = { fields: [ field( 'name', 'text', 'Your name' ), size ], settings: { ajax: false, progressBar: 'none' } };

		document.body.innerHTML = `
			<form data-atf-form="9" data-atf-instance="atf-9" method="post" action="#">
				<div data-atf-field="name"><input type="text" name="atf[name]"></div>
				<div data-atf-field="size">
					<label class="atf-label">Which size, <span class="atf-recall" data-atf-recall="name"></span>?</label>
					<input type="radio" name="atf[size]" value="s"><input type="radio" name="atf[size]" value="l">
				</div>
				<input type="text" placeholder="Your email" data-atf-recall-template="{field:name}’s email" data-atf-recall-attr="placeholder">
				<p class="atf-hint">You chose <span class="atf-recall" data-atf-recall="size"></span>, <span class="atf-recall" data-atf-recall="nope"></span></p>
			</form>
			<script type="application/json" id="atf-9-schema">${ JSON.stringify( schema ) }</script>
		`;
		boot();

		const [ nameSlot, sizeSlot, typo ] = document.querySelectorAll< HTMLElement >( '[data-atf-recall]' );
		expect( nameSlot.textContent ).toBe( '' );
		expect( typo.textContent ).toBe( '{field:nope}' );
		// Before any answer the tag reads as nothing, as it does in a label.
		expect( document.querySelector( '[data-atf-recall-template]' )!.getAttribute( 'placeholder' ) ).toBe( '’s email' );

		const input = document.querySelector< HTMLInputElement >( 'input[name="atf[name]"]' )!;
		input.value = 'Ada';
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( nameSlot.textContent ).toBe( 'Ada' );
		expect( document.querySelector( '[data-atf-recall-template]' )!.getAttribute( 'placeholder' ) ).toBe( 'Ada’s email' );

		const large = document.querySelector< HTMLInputElement >( 'input[value="l"]' )!;
		large.checked = true;
		large.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		expect( sizeSlot.textContent ).toBe( 'Large (£12)' );
	} );
} );

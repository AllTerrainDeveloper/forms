/**
 * The front-end enhancements around multi-page forms and repeaters.
 *
 * Two regressions are pinned here, both of the kind that survives a demo and
 * bites a real visitor:
 *
 * - Enter in a text field fires the browser's *implicit submission* even when
 *   the submit button sits on a later, hidden page — hiding a button does not
 *   disable it. Mid-form, that keypress has to mean "next step", never "send
 *   the half I have not seen yet".
 *
 * - A repeater that numbers new rows by counting the existing ones collides
 *   after a middle row is deleted: rows 0 and 2 plus a "new row 2" post into
 *   the same array slot, and one row's answers silently vanish on submit.
 */

import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { boot, nextRepeaterIndex } from '../../src/form';

beforeAll( () => {
	// jsdom draws nothing, so it does not implement scrolling; the step
	// navigation scrolls the new page into view and would throw without this.
	Element.prototype.scrollIntoView = () => {};

	// jsdom has no CSS object either, and `fieldElement()` escapes field ids
	// through it. The ids in these tests are plain words; identity will do.
	( globalThis as { CSS?: { escape: ( value: string ) => string } } ).CSS ??= {
		escape: ( value: string ) => value,
	};
} );

/** Prints a form plus the schema blob the renderer would put beside it. */
function mount( instance: string, formId: string, inner: string, settings = { ajax: false, progressBar: 'none' } ): HTMLFormElement {
	document.body.innerHTML = `
		<form data-atf-form="${ formId }" data-atf-instance="${ instance }" method="post" action="#">
			${ inner }
		</form>
		<script type="application/json" id="${ instance }-schema">${ JSON.stringify( { fields: [], settings } ) }</script>
	`;

	boot();

	return document.querySelector< HTMLFormElement >( 'form' )!;
}

/** Dispatches what the browser would on Enter: a cancelable submit event. */
function pressEnter( form: HTMLFormElement ): boolean {
	const event = new Event( 'submit', { bubbles: true, cancelable: true } );

	form.dispatchEvent( event );

	return event.defaultPrevented;
}

describe( 'implicit submission on a multi-page form', () => {
	let form: HTMLFormElement;
	let pages: HTMLElement[];

	beforeEach( () => {
		form = mount(
			'atf-7',
			'7',
			`
			<div data-atf-page>
				<input type="text" name="atf[name]" />
			</div>
			<div data-atf-page hidden data-atf-page-hidden>
				<input type="text" name="atf[email]" />
				<button type="submit" data-atf-submit>Send</button>
			</div>
			`
		);
		pages = Array.from( form.querySelectorAll< HTMLElement >( '[data-atf-page]' ) );
	} );

	it( 'turns Enter on an earlier page into "next", not "submit"', () => {
		expect( pressEnter( form ) ).toBe( true );

		expect( pages[ 0 ].hidden ).toBe( true );
		expect( pages[ 1 ].hidden ).toBe( false );
	} );

	it( 'still lets the last page submit', () => {
		pressEnter( form ); // Onto the last page.

		// Not prevented: this form is non-AJAX, so the last page's submit is
		// deliberately left to the browser.
		expect( pressEnter( form ) ).toBe( false );
		expect( pages[ 1 ].hidden ).toBe( false );
	} );
} );

describe( 'repeater row numbering', () => {
	const repeater = `
		<div data-atf-page>
			<div class="atf-repeater" data-atf-repeater="people" data-atf-min="1" data-atf-max="5">
				<div class="atf-repeater__rows">
					<div class="atf-repeater__row" data-atf-repeater-row>
						<input name="atf[people][0][first]" />
						<button type="button" data-atf-repeater-remove>&times;</button>
					</div>
				</div>
				<button type="button" data-atf-repeater-add>Add another</button>
				<template data-atf-repeater-template>
					<div class="atf-repeater__row" data-atf-repeater-row>
						<input name="atf[people][__INDEX__][first]" />
						<button type="button" data-atf-repeater-remove>&times;</button>
					</div>
				</template>
			</div>
		</div>
	`;

	/** The row indexes currently in the DOM, read from the names that submit. */
	function indexes( form: HTMLFormElement ): string[] {
		return Array.from( form.querySelectorAll( '.atf-repeater__rows [name]' ) ).map(
			( input ) => /\[(\d+)\]/.exec( input.getAttribute( 'name' ) ?? '' )?.[ 1 ] ?? ''
		);
	}

	it( 'never reuses a live index after a middle row is deleted', () => {
		const form = mount( 'atf-8', '8', repeater );
		const add = form.querySelector< HTMLButtonElement >( '[data-atf-repeater-add]' )!;

		add.click();
		add.click();
		expect( indexes( form ) ).toEqual( [ '0', '1', '2' ] );

		// Delete the middle row, then add: the new row must take 3, because a
		// second "row 2" would submit into the same slot as the survivor.
		form.querySelectorAll< HTMLButtonElement >( '[data-atf-repeater-remove]' )[ 1 ].click();
		add.click();

		const seen = indexes( form );

		expect( seen ).toEqual( [ '0', '2', '3' ] );
		expect( new Set( seen ).size ).toBe( seen.length );
	} );

	it( 'still respects the row cap', () => {
		const form = mount( 'atf-9', '9', repeater );
		const add = form.querySelector< HTMLButtonElement >( '[data-atf-repeater-add]' )!;

		for ( let clicks = 0; clicks < 10; clicks++ ) {
			add.click();
		}

		expect( form.querySelectorAll( '.atf-repeater__rows [data-atf-repeater-row]' ) ).toHaveLength( 5 );
	} );

	const titled = `
		<div data-atf-page>
			<div class="atf-repeater" data-atf-repeater="people" data-atf-min="1" data-atf-max="5" data-atf-item-label="Attendee">
				<div class="atf-repeater__rows">
					<div class="atf-repeater__row" data-atf-repeater-row>
						<span data-atf-repeater-title>Attendee 1</span>
						<input name="atf[people][0][first]" />
						<button type="button" data-atf-repeater-remove>&times;</button>
					</div>
				</div>
				<button type="button" data-atf-repeater-add>Add another</button>
				<template data-atf-repeater-template>
					<div class="atf-repeater__row" data-atf-repeater-row>
						<span data-atf-repeater-title></span>
						<input name="atf[people][__INDEX__][first]" />
						<button type="button" data-atf-repeater-remove>&times;</button>
					</div>
				</template>
			</div>
		</div>
	`;

	/** The visible card titles, in DOM order. */
	function titles( form: HTMLFormElement ): string[] {
		return Array.from( form.querySelectorAll( '.atf-repeater__rows [data-atf-repeater-title]' ) ).map(
			( node ) => node.textContent ?? ''
		);
	}

	it( 'titles every card by position, whatever its posted index', () => {
		const form = mount( 'atf-10', '10', titled );
		const add = form.querySelector< HTMLButtonElement >( '[data-atf-repeater-add]' )!;

		add.click();
		add.click();
		expect( titles( form ) ).toEqual( [ 'Attendee 1', 'Attendee 2', 'Attendee 3' ] );

		// Remove the middle card: the survivors read 1 and 2 even though the
		// second one still *posts* as index 2 — the names must never collide,
		// and the titles must never show the gap.
		form.querySelectorAll< HTMLButtonElement >( '[data-atf-repeater-remove]' )[ 1 ].click();

		expect( titles( form ) ).toEqual( [ 'Attendee 1', 'Attendee 2' ] );
	} );
} );

describe( 'nextRepeaterIndex', () => {
	/** A rows container holding the given names. */
	function rowsWith( names: string[] ): HTMLElement {
		const rows = document.createElement( 'div' );

		rows.innerHTML = names
			.map( ( name ) => `<div data-atf-repeater-row><input name="${ name }" /></div>` )
			.join( '' );

		return rows;
	}

	it( 'is zero for an empty repeater', () => {
		expect( nextRepeaterIndex( rowsWith( [] ) ) ).toBe( 0 );
	} );

	it( 'is one past the highest index, not the row count', () => {
		expect( nextRepeaterIndex( rowsWith( [ 'atf[people][0][a]', 'atf[people][7][a]' ] ) ) ).toBe( 8 );
	} );

	it( 'ignores names that are not repeater slots', () => {
		expect( nextRepeaterIndex( rowsWith( [ 'atf[notes]', 'unrelated[3]' ] ) ) ).toBe( 0 );
	} );
} );
describe( 'repeater validation marks the exact control', () => {
	/** A repeater of two rows: name (optional) and age (required). */
	function mountRepeater( rows: Array< { name: string; age: string } > ): HTMLFormElement {
		const schema = {
			fields: [
				{
					id: 'att',
					type: 'repeater',
					label: 'Attendees',
					fields: [
						{ id: 'name', type: 'text', label: 'Name' },
						{ id: 'age', type: 'number', label: 'Age', required: true },
					],
				},
			],
			settings: { ajax: false, progressBar: 'none' },
		};

		const rowHtml = rows
			.map(
				( row, index ) => `
				<div class="atf-repeater__row" data-atf-repeater-row>
					<div class="atf-field atf-repeater__field" data-atf-sub="name">
						<input class="atf-input" type="text" name="atf[att][${ index }][name]" value="${ row.name }" />
						<p class="atf-error" hidden></p>
					</div>
					<div class="atf-field atf-repeater__field" data-atf-sub="age">
						<input class="atf-input" type="number" name="atf[att][${ index }][age]" value="${ row.age }" />
						<p class="atf-error" hidden></p>
					</div>
				</div>`
			)
			.join( '' );

		document.body.innerHTML = `
			<form data-atf-form="9" data-atf-instance="atf-9" method="post" action="#">
				<div class="atf-errors" id="atf-9-errors" role="alert" tabindex="-1" hidden></div>
				<div data-atf-page>
					<div class="atf-field atf-field--repeater" data-atf-field="att" data-atf-type="repeater">
						<span class="atf-label">Attendees</span>
						<div class="atf-repeater" data-atf-repeater="att" data-atf-min="1" data-atf-max="10" data-atf-item-label="Attendee">
							<div class="atf-repeater__rows">${ rowHtml }</div>
						</div>
						<p class="atf-error" hidden></p>
					</div>
					<button type="submit" data-atf-submit>Send</button>
				</div>
			</form>
			<script type="application/json" id="atf-9-schema">${ JSON.stringify( schema ) }</script>
		`;

		boot();

		return document.querySelector< HTMLFormElement >( 'form' )!;
	}

	it( 'marks the failing box and its row, and no one else', () => {
		const form = mountRepeater( [
			{ name: 'Ana', age: '30' },
			{ name: 'Luz', age: '' },
		] );

		expect( pressEnter( form ) ).toBe( true );

		const field = form.querySelector< HTMLElement >( '[data-atf-field="att"]' )!;
		const rows = Array.from( form.querySelectorAll< HTMLElement >( '[data-atf-repeater-row]' ) );

		// The container wears the summary, named the way the server names it.
		expect( field.classList.contains( 'has-error' ) ).toBe( true );
		expect( field.querySelector< HTMLElement >( ':scope > .atf-error' )!.textContent ).toBe(
			'Attendee 2: This is required.'
		);

		// The failing row and the failing box — and only those.
		expect( rows[ 0 ].classList.contains( 'has-error' ) ).toBe( false );
		expect( rows[ 1 ].classList.contains( 'has-error' ) ).toBe( true );

		const badBox = rows[ 1 ].querySelector< HTMLElement >( '[data-atf-sub="age"]' )!;
		const goodBox = rows[ 1 ].querySelector< HTMLElement >( '[data-atf-sub="name"]' )!;

		expect( badBox.classList.contains( 'has-error' ) ).toBe( true );
		expect( badBox.querySelector( 'input' )!.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( goodBox.classList.contains( 'has-error' ) ).toBe( false );
		expect( rows[ 0 ].querySelector( '[data-atf-sub="age"] input' )!.hasAttribute( 'aria-invalid' ) ).toBe(
			false
		);
	} );

	it( 'indents the failing boxes under the repeater in the summary', () => {
		const form = mountRepeater( [
			{ name: 'Ana', age: '30' },
			{ name: 'Luz', age: '' },
		] );

		pressEnter( form );

		const summary = form.querySelector< HTMLElement >( '.atf-errors' )!;

		expect( summary.hidden ).toBe( false );

		// One top-level entry — the repeater's failing box is nested under it,
		// not listed beside it.
		const topLevel = summary.querySelectorAll( ':scope > ul > li' );

		expect( topLevel.length ).toBe( 1 );
		expect( topLevel[ 0 ].querySelector( ':scope > a' )!.textContent ).toBe( 'Attendees' );

		const nested = topLevel[ 0 ].querySelectorAll( '.atf-errors__sub > li' );

		expect( nested.length ).toBe( 1 );
		expect( nested[ 0 ].textContent ).toContain( 'This is required.' );
	} );

	it( 'lets an all-empty row pass, exactly as the server drops it', () => {
		const form = mountRepeater( [
			{ name: 'Ana', age: '30' },
			{ name: '', age: '' },
		] );

		expect( pressEnter( form ) ).toBe( false );

		const field = form.querySelector< HTMLElement >( '[data-atf-field="att"]' )!;

		expect( field.classList.contains( 'has-error' ) ).toBe( false );
	} );

	it( 'clears the marks once the answer arrives', () => {
		const form = mountRepeater( [ { name: 'Luz', age: '' } ] );

		pressEnter( form );

		const rows = form.querySelector< HTMLElement >( '[data-atf-repeater-row]' )!;
		const age = rows.querySelector< HTMLInputElement >( '[data-atf-sub="age"] input' )!;

		expect( rows.classList.contains( 'has-error' ) ).toBe( true );

		age.value = '41';
		age.dispatchEvent( new FocusEvent( 'blur', { bubbles: true } ) );

		expect( rows.classList.contains( 'has-error' ) ).toBe( false );
		expect( age.hasAttribute( 'aria-invalid' ) ).toBe( false );
		expect(
			form.querySelector< HTMLElement >( '[data-atf-field="att"]' )!.classList.contains( 'has-error' )
		).toBe( false );
	} );
} );

import { afterEach, describe, expect, it, vi } from 'vitest';
import { resolvePreview, taggable } from '../../src/merge-tags';
import { formulaInput } from '../../src/formula-editor';
import type { Field, MergeTagGroup } from '../../src/types';

const groups: MergeTagGroup[] = [ { id: 'answers', label: 'Answers', items: [
	{ tag: '{field:score}', label: 'Opinion scale', sample: 'Their answer', hint: '' },
	{ tag: '{field:name}', label: 'Your name', sample: 'Ada', hint: '' },
] } ];
const settle = async () => { await Promise.resolve(); await Promise.resolve(); };
function mount( value = '', catalogue = () => Promise.resolve( groups ) ) {
	const input = document.createElement( 'textarea' );
	input.value = value;
	const change = vi.fn();
	input.addEventListener( 'input', change );
	const wrapper = taggable( input, { groups: catalogue } );
	document.body.append( wrapper );
	input.focus();
	return { input, wrapper, change };
}
function typeBrace( input: HTMLTextAreaElement ) {
	const at = input.selectionStart;
	input.setRangeText( '{', at, input.selectionEnd, 'end' );
	input.dispatchEvent( new InputEvent( 'input', { bubbles: true, data: '{', inputType: 'insertText' } ) );
}
afterEach( () => {
	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	document.body.replaceChildren();
} );

describe( 'value previews', () => {
	it( 'explains each value by label instead of inventing an answer', () => {
		expect( resolvePreview( 'Hello {field:name}: {field:score}', groups ) ).toBe( 'Hello {the value of Your name}: {the value of Opinion scale}' );
	} );
	it( 'leaves unknown tags and ordinary text visible', () => {
		expect( resolvePreview( 'Hello {unknown}, plain text', groups ) ).toBe( 'Hello {unknown}, plain text' );
	} );
} );

describe( 'brace picker', () => {
	it( 'inserts in the middle, replaces the trigger, restores focus and notifies the host', async () => {
		const { input, wrapper, change } = mount( 'Score:  today' );
		input.setSelectionRange( 7, 7 );
		typeBrace( input );
		await settle();
		expect( wrapper.querySelector( '[role="dialog"]' ) ).not.toBeNull();
		wrapper.querySelector< HTMLButtonElement >( '.atfb-tagpick__item' )!.click();
		expect( input.value ).toBe( 'Score: {field:score} today' );
		expect( input.selectionStart ).toBe( 20 );
		expect( document.activeElement ).toBe( input );
		expect( change ).toHaveBeenCalledTimes( 2 );
	} );
	it( 'supports search and Enter without doubling an existing closing brace', async () => {
		const { input, wrapper } = mount( '}' );
		input.setSelectionRange( 0, 0 );
		typeBrace( input );
		await settle();
		const search = wrapper.querySelector< HTMLInputElement >( '.atfb-tagpick__search' )!;
		search.value = 'name';
		search.dispatchEvent( new Event( 'input' ) );
		search.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } ) );
		expect( input.value ).toBe( '{field:name}' );
	} );
	it( 'Escape keeps the typed brace and returns to the input', async () => {
		const { input, wrapper } = mount();
		typeBrace( input );
		await settle();
		document.activeElement!.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		expect( wrapper.querySelector( '.atfb-tagpick' ) ).toBeNull();
		expect( input.value ).toBe( '{' );
		expect( document.activeElement ).toBe( input );
	} );
	it( 'owns navigation before document capture shortcuts, and releases it on close', async () => {
		const shellShortcut = vi.fn();
		document.addEventListener( 'keydown', shellShortcut, true );
		try {
			const { input, wrapper } = mount();
			typeBrace( input );
			await settle();
			const search = wrapper.querySelector< HTMLInputElement >( '.atfb-tagpick__search' )!;
			const items = wrapper.querySelectorAll< HTMLButtonElement >( '.atfb-tagpick__item' );
			const press = ( key: string ) => {
				const event = new KeyboardEvent( 'keydown', { key, code: key, bubbles: true, cancelable: true } );
				document.activeElement!.dispatchEvent( event );
				return event;
			};
			expect( document.activeElement ).toBe( search );
			expect( press( 'ArrowDown' ).defaultPrevented ).toBe( true );
			expect( document.activeElement ).toBe( items[ 0 ] );
			press( 'ArrowDown' );
			expect( document.activeElement ).toBe( items[ 1 ] );
			press( 'ArrowUp' );
			expect( document.activeElement ).toBe( items[ 0 ] );
			press( 'ArrowLeft' );
			press( 'ArrowRight' );
			press( 'End' );
			expect( document.activeElement ).toBe( items[ 1 ] );
			press( 'Home' );
			expect( document.activeElement ).toBe( items[ 0 ] );
			search.focus();
			for ( const key of [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ] ) {
				expect( press( key ).defaultPrevented ).toBe( false );
			}
			expect( shellShortcut ).not.toHaveBeenCalled();
			press( 'Escape' );
			expect( document.activeElement ).toBe( input );
			expect( wrapper.querySelector( '.atfb-tagpick' ) ).toBeNull();
			expect( shellShortcut ).not.toHaveBeenCalled();
			press( 'ArrowDown' );
			expect( shellShortcut ).toHaveBeenCalledTimes( 1 );
		} finally {
			document.removeEventListener( 'keydown', shellShortcut, true );
		}
	} );
	it( 'does not intercept arrows outside an open picker', async () => {
		const { input } = mount();
		typeBrace( input );
		await settle();
		const outside = document.createElement( 'button' );
		document.body.append( outside );
		outside.focus();
		const shellShortcut = vi.fn();
		document.addEventListener( 'keydown', shellShortcut, true );
		try {
			outside.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', code: 'ArrowDown', bubbles: true } ) );
			expect( shellShortcut ).toHaveBeenCalledTimes( 1 );
		} finally {
			document.removeEventListener( 'keydown', shellShortcut, true );
		}
	} );
	it( 'does not open for pasted formulas or composition', async () => {
		const { input, wrapper } = mount( '{field:score}' );
		input.dispatchEvent( new InputEvent( 'input', { data: null, inputType: 'insertFromPaste' } ) );
		input.dispatchEvent( new InputEvent( 'input', { data: '{', isComposing: true } ) );
		await settle();
		expect( wrapper.querySelector( '.atfb-tagpick' ) ).toBeNull();
	} );
	it( 'does not reopen after Escape while the catalogue is loading', async () => {
		let resolve!: ( value: MergeTagGroup[] ) => void;
		const pending = new Promise< MergeTagGroup[] >( ( done ) => { resolve = done; } );
		const { input, wrapper } = mount( '', () => pending );
		typeBrace( input );
		document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) );
		resolve( groups );
		await settle();
		expect( wrapper.querySelector( '.atfb-tagpick' ) ).toBeNull();
	} );
	it( 'the Insert button still replaces the selected text', async () => {
		const { input, wrapper } = mount( 'Replace this' );
		input.setSelectionRange( 8, 12 );
		wrapper.querySelector< HTMLButtonElement >( '.atfb-tagpick__open' )!.click();
		await settle();
		wrapper.querySelector< HTMLButtonElement >( '.atfb-tagpick__item' )!.click();
		expect( input.value ).toBe( 'Replace {field:score}' );
	} );
	it( 'offers numeric and repeater references in calculation syntax, excluding self', async () => {
		const input = document.createElement( 'textarea' );
		const fields = [
			{ id: 'score', label: 'Opinion scale', type: 'scale' },
			{ id: 'name', label: 'Name', type: 'text' },
			{ id: 'total', label: 'Total', type: 'total' },
			{ id: 'attendees', label: 'Attendees', type: 'repeater', fields: [ { id: 'age', label: 'Age', type: 'number' } ] },
		] as Field[];
		const wrapper = formulaInput( input, fields, 'total' );
		document.body.append( wrapper );
		typeBrace( input );
		await settle();
		const tags = [ ...wrapper.querySelectorAll( '.atfb-tagpick__tag' ) ].map( ( item ) => item.textContent );
		expect( tags ).toEqual( [ '{score}', '{attendees}', '{attendees.age}' ] );
		wrapper.querySelector< HTMLButtonElement >( '.atfb-tagpick__item' )!.click();
		expect( input.value ).toBe( '{score}' );
	} );
} );

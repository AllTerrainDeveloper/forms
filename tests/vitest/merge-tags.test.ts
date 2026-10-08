import { afterEach, describe, expect, it, vi } from 'vitest';
import { isPickingFor, resolvePreview, taggable, taggableText } from '../../src/merge-tags';
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

describe( 'explained values', () => {
	it( 'shows what each value is and what it looks like', async () => {
		const explained: MergeTagGroup[] = [ { id: 'site', label: 'Your site', items: [
			{ tag: '{admin_email}', label: 'The site administrator’s email', hint: 'Set in Settings → General.', sample: 'admin@example.com' },
		] } ];
		const { input, wrapper } = mount( '', () => Promise.resolve( explained ) );
		typeBrace( input );
		await settle();
		const item = wrapper.querySelector( '.atfb-tagpick__item' )!;
		expect( item.querySelector( '.atfb-tagpick__meta' )!.textContent ).toBe( 'Set in Settings → General.' );
		expect( item.querySelector( '.atfb-tagpick__sample' )!.textContent ).toBe( 'e.g.admin@example.com' );
		expect( wrapper.querySelector( '.atfb-tagpick__tip' )!.textContent ).toContain( 'type {' );
	} );

	it( 'finds a value by what it does, not only by its name', async () => {
		const { input, wrapper } = mount( '', () => Promise.resolve( [ { id: 'x', label: 'X', items: [
			{ tag: '{entry:id}', label: 'The reference number', hint: 'Worth putting in a subject line.', sample: '' },
		] } ] ) );
		typeBrace( input );
		await settle();
		const search = wrapper.querySelector< HTMLInputElement >( '.atfb-tagpick__search' )!;
		search.value = 'subject';
		search.dispatchEvent( new Event( 'input' ) );
		expect( wrapper.querySelectorAll( '.atfb-tagpick__item' ) ).toHaveLength( 1 );
	} );

	it( 'takes its own intro and button wording', async () => {
		const input = document.createElement( 'input' );
		const wrapper = taggable( input, { groups: () => groups, intro: 'Show one of their answers.', button: 'Insert an answer' } );
		document.body.append( wrapper );
		expect( wrapper.querySelector( '.atfb-tagpick__open' )!.textContent ).toBe( 'Insert an answer' );
		wrapper.querySelector< HTMLButtonElement >( '.atfb-tagpick__open' )!.click();
		await settle();
		expect( wrapper.querySelector( '.atfb-tagpick__intro' )!.textContent ).toBe( 'Show one of their answers.' );
	} );
} );

describe( 'brace picker on canvas text', () => {
	function editable( text: string ) {
		const root = document.createElement( 'div' );
		root.className = 'atfb';
		const node = document.createElement( 'span' );
		node.contentEditable = 'true';
		// jsdom only focuses a contenteditable that is also tabbable; browsers
		// focus it either way.
		node.tabIndex = 0;
		node.textContent = text;
		root.append( node );
		document.body.append( root );
		const inputs = vi.fn();
		const blurs = vi.fn();
		node.addEventListener( 'input', () => inputs( node.textContent ) );
		node.addEventListener( 'blur', () => {
			if ( ! isPickingFor( node ) ) {
				blurs();
			}
		} );
		taggableText( node, { groups: () => groups } );
		node.focus();
		return { root, node, inputs, blurs };
	}
	function typeBraceAt( node: HTMLElement, offset: number ) {
		const text = node.textContent ?? '';
		node.textContent = text.slice( 0, offset ) + '{' + text.slice( offset );
		const range = document.createRange();
		range.setStart( node.firstChild!, offset + 1 );
		range.collapse( true );
		getSelection()!.removeAllRanges();
		getSelection()!.addRange( range );
		node.dispatchEvent( new InputEvent( 'input', { bubbles: true, data: '{', inputType: 'insertText' } ) );
	}

	it( 'floats a picker beside the text and swaps the brace for the tag', async () => {
		const { root, node, inputs, blurs } = editable( 'Thanks, !' );
		typeBraceAt( node, 8 );
		await settle();
		const picker = root.querySelector( '.atfb-tagpick--floating' );
		expect( picker ).not.toBeNull();
		expect( isPickingFor( node ) ).toBe( true );
		root.querySelectorAll< HTMLButtonElement >( '.atfb-tagpick__item' )[ 1 ].click();
		expect( node.textContent ).toBe( 'Thanks, {field:name}!' );
		expect( inputs ).toHaveBeenLastCalledWith( 'Thanks, {field:name}!' );
		expect( document.activeElement ).toBe( node );
		expect( root.querySelector( '.atfb-tagpick' ) ).toBeNull();
		// Focus came straight back, so there is nothing to commit yet.
		expect( blurs ).not.toHaveBeenCalled();
	} );

	it( 'commits the text it held back once the picker is dismissed elsewhere', async () => {
		const { node, blurs } = editable( 'Hi ' );
		typeBraceAt( node, 3 );
		await settle();
		const elsewhere = document.createElement( 'button' );
		document.body.append( elsewhere );
		elsewhere.focus();
		elsewhere.dispatchEvent( new MouseEvent( 'pointerdown', { bubbles: true } ) );
		expect( isPickingFor( node ) ).toBe( false );
		expect( blurs ).toHaveBeenCalled();
		expect( node.textContent ).toBe( 'Hi {' );
	} );
} );

describe( 'picker ownership', () => {
	it( 'names the text an open picker is writing into, so a host can hold a repaint', async () => {
		const { pickerOwner } = await import( '../../src/merge-tags' );
		const { input } = mount();
		expect( pickerOwner() ).toBeNull();
		typeBrace( input );
		expect( pickerOwner() ).toBe( input );
		document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) );
		expect( pickerOwner() ).toBeNull();
	} );
} );

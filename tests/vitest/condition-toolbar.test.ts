import { afterEach, describe, expect, it, vi } from 'vitest';
import { Builder } from '../../src/builder';
import { logicTokens } from '../../src/logic-map';
import type { Field, FormSchema } from '../../src/types';

function setup( rules = false ) {
	vi.useFakeTimers();
	vi.stubGlobal( 'CSS', { escape: ( value: string ) => value } );
	const root = document.createElement( 'div' );
	root.innerHTML = '<div data-atfb-bar></div><div data-atfb-canvas></div>';
	document.body.append( root );
	const field = { id: 'recommend', type: 'radio', label: 'Recommend?', logic: {
		enabled: false, action: 'show', match: 'all', rules: rules ? [ { field: 'score', operator: 'greater_equal', value: '6' } ] : [],
	} } as Field;
	const schema = { fields: [ { id: 'score', type: 'scale', label: 'Score' }, field ], notifications: [], confirmations: [] } as unknown as FormSchema;
	const builder = new Builder( root );
	Reflect.set( builder, 'schema', schema );
	Reflect.set( builder, 'config', { operators: { is: 'is', greater_equal: 'is at least', less: 'is less than' } } );
	const render = () => root.querySelector( '[data-atfb-canvas]' )!.replaceChildren(
		Reflect.get( builder, 'conditionToolbar' ).call( builder, field ),
		...( field.logic.enabled ? [ Reflect.get( builder, 'renderCondition' ).call( builder, field, logicTokens( field, schema.fields ) ) ] : [] )
	);
	Reflect.set( builder, 'renderCanvas', render );
	Reflect.set( builder, 'renderInspector', vi.fn() );
	render();
	return { root, field, builder };
}
afterEach( () => { vi.clearAllTimers(); vi.useRealTimers(); vi.unstubAllGlobals(); document.body.replaceChildren(); } );

function clickButton( root: HTMLElement, label: string ) {
	const target = [ ...root.querySelectorAll< HTMLButtonElement >( 'button' ) ].find( ( item ) => item.textContent === label );
	expect( target ).toBeDefined();
	target!.click();
}
function open( root: HTMLElement ) {
	root.querySelector< HTMLButtonElement >( '.atfb-condition-toggle' )!.click();
	return root.querySelector< HTMLElement >( '[aria-label="Conditional logic"]' )!;
}
describe( 'card conditional dialog', () => {
	it( 'keeps the header compact and applies changes only on Save', () => {
		const { root, field } = setup();
		const dialog = open( root );
		expect( root.querySelector( '[data-atfb-canvas] select' ) ).toBeNull();
		clickButton( dialog, 'Add rule' );
		expect( field.logic.enabled ).toBe( false );
		expect( field.logic.rules ).toHaveLength( 0 );
		clickButton( dialog, 'Save conditions' );
		expect( field.logic.enabled ).toBe( true );
		expect( field.logic.rules[ 0 ].field ).toBe( 'score' );
		expect( root.querySelector( '[aria-label="Conditional logic"]' ) ).toBeNull();
		expect( root.querySelector( '.atfb-condition-toggle.is-on' ) ).not.toBeNull();
	} );
	it( 'opens ready to edit, offers Clear instead of Cancel for a new condition', () => {
		const { root, field } = setup();
		const dialog = open( root );
		expect( dialog.textContent ).not.toContain( 'Enable conditions' );
		expect( dialog.textContent ).not.toContain( 'Cancel' );
		expect( dialog.querySelector( '[aria-label="Conditional action"]' ) ).not.toBeNull();
		expect( dialog.querySelector( '.atfb-button--primary' )!.hasAttribute( 'disabled' ) ).toBe( true );
		clickButton( dialog, 'Clear' );
		expect( field.logic.enabled ).toBe( false );
		expect( field.logic.rules ).toEqual( [] );
		expect( root.querySelector( '[aria-label="Conditional logic"]' ) ).toBeNull();
	} );
	it( 'clears existing conditions with undo history and restores focus', () => {
		const { root, field, builder } = setup( true );
		field.logic.enabled = true;
		const dialog = open( root );
		clickButton( dialog, 'Clear' );
		expect( field.logic ).toEqual( { enabled: false, action: 'show', match: 'all', rules: [] } );
		expect( document.activeElement ).toBe( root.querySelector( '.atfb-condition-toggle' ) );
		const history = Reflect.get( builder, 'history' ) as string[];
		expect( JSON.parse( history[ 0 ] ).fields[ 1 ].logic.rules ).toHaveLength( 1 );
		expect( JSON.parse( history[ 1 ] ).fields[ 1 ].logic.rules ).toHaveLength( 0 );
	} );
	it( 'discards draft edits on Cancel and Escape without triggering card gestures', () => {
		const { root, field } = setup( true );
		const click = vi.fn();
		root.addEventListener( 'click', click );
		let dialog = open( root );
		expect( click ).not.toHaveBeenCalled();
		dialog.querySelector< HTMLButtonElement >( '[aria-label="Remove this rule"]' )!.click();
		clickButton( dialog, 'Cancel' );
		expect( field.logic.enabled ).toBe( false );
		expect( field.logic.rules ).toEqual( [ { field: 'score', operator: 'greater_equal', value: '6' } ] );
		dialog = open( root );
		dialog.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		expect( field.logic.enabled ).toBe( false );
		expect( document.activeElement ).toBe( root.querySelector( '.atfb-condition-toggle' ) );
	} );
	it( 'copies into a draft without changing the live condition on Cancel', () => {
		const { root, field, builder } = setup( true );
		const schema = Reflect.get( builder, 'schema' ) as FormSchema;
		schema.fields[ 0 ].logic = { enabled: false, action: 'show', match: 'all', rules: [] };
		schema.fields.push( { id: 'uninstall', type: 'radio', label: 'Uninstall?', logic: {
			enabled: true, action: 'show', match: 'all', rules: [ { field: 'score', operator: 'less', value: '6' } ],
		} } as Field );
		const dialog = open( root );
		clickButton( dialog, 'Copy condition' );
		const copy = root.querySelector< HTMLElement >( '[aria-label="Copy condition"]' )!;
		const source = copy.querySelector< HTMLSelectElement >( '[aria-label="Copy from"]' )!;
		source.value = 'field:uninstall';
		source.dispatchEvent( new Event( 'change' ) );
		clickButton( copy, 'Copy condition' );
		expect( dialog.querySelector< HTMLSelectElement >( '[aria-label="How the answer is compared"]' )!.value ).toBe( 'less' );
		expect( field.logic.rules[ 0 ].operator ).toBe( 'greater_equal' );
		clickButton( dialog, 'Cancel' );
		expect( field.logic.rules[ 0 ].operator ).toBe( 'greater_equal' );
		expect( field.logic.enabled ).toBe( false );
	} );
	it( 'adds, edits, deletes and clears rules directly on the card', () => {
		const { root, field } = setup( true );
		const dialog = open( root );
		clickButton( dialog, 'Save conditions' );
		const operator = root.querySelector< HTMLSelectElement >( '[aria-label="How the answer is compared"]' )!;
		operator.value = 'greater_equal';
		operator.dispatchEvent( new Event( 'change' ) );
		const value = root.querySelector< HTMLSelectElement >( '[aria-label="The answer that triggers this"]' )!;
		value.value = '7';
		value.dispatchEvent( new Event( 'change' ) );
		expect( field.logic.rules[ 0 ] ).toEqual( { field: 'score', operator: 'greater_equal', value: '7' } );
		root.querySelector< HTMLButtonElement >( '[aria-label="Add rule"]' )!.click();
		expect( root.querySelectorAll( '[aria-label="Question used by this rule"]' ) ).toHaveLength( 2 );
		root.querySelector< HTMLButtonElement >( '[aria-label="Delete rule 2"]' )!.click();
		expect( field.logic.rules ).toHaveLength( 1 );
		root.querySelector< HTMLButtonElement >( '[aria-label="Clear all rules"]' )!.click();
		expect( field.logic.rules ).toHaveLength( 0 );
		expect( field.logic.enabled ).toBe( true );
		expect( root.textContent ).toContain( 'No rules yet' );
		root.querySelector< HTMLButtonElement >( '[aria-label="Add rule"]' )!.click();
		expect( field.logic.rules ).toHaveLength( 1 );
	} );
	it( 'saves show/hide and all/any with an undo snapshot', () => {
		const { root, field, builder } = setup( true );
		const dialog = open( root );
		const action = dialog.querySelector< HTMLSelectElement >( '[aria-label="Conditional action"]' )!;
		action.value = 'hide';
		action.dispatchEvent( new Event( 'change' ) );
		const match = dialog.querySelector< HTMLSelectElement >( '[aria-label="Match rules"]' )!;
		match.value = 'any';
		match.dispatchEvent( new Event( 'change' ) );
		clickButton( dialog, 'Save conditions' );
		expect( field.logic.action ).toBe( 'hide' );
		expect( field.logic.match ).toBe( 'any' );
		const history = Reflect.get( builder, 'history' ) as string[];
		expect( JSON.parse( history[ 0 ] ).fields[ 1 ].logic.enabled ).toBe( false );
		expect( JSON.parse( history[ 1 ] ).fields[ 1 ].logic.enabled ).toBe( true );
	} );
} );

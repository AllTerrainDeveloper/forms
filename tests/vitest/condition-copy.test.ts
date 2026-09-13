import { afterEach, describe, expect, it, vi } from 'vitest';
import { conditionSections, copyCondition, openConditionCopy } from '../../src/condition-copy';
import type { FormSchema, Logic } from '../../src/types';

const empty = (): Logic => ( { enabled: false, action: 'show', match: 'all', rules: [] } );
function fixture(): FormSchema {
	return {
		fields: [
			{ id: 'score', label: 'Opinion scale', type: 'scale', logic: empty() },
			{ id: 'uninstall', label: 'Will you uninstall it?', type: 'radio', logic: {
				enabled: true, action: 'hide', match: 'any', rules: [
					{ field: 'score', operator: 'less', value: '6' },
					{ field: 'score', operator: 'empty', value: '' },
				],
			} },
			{ id: 'recommend', label: 'Will you recommend us?', type: 'radio', logic: empty() },
		],
		notifications: [ { id: 'mail', name: 'Admin email', logic: empty() } ],
		confirmations: [ { id: 'thanks', name: 'Thank you', logic: empty() } ],
	} as FormSchema;
}
afterEach( () => document.body.replaceChildren() );
describe( 'copying conditions', () => {
	it( 'copies the entire condition and keeps edits independent', () => {
		const schema = fixture();
		expect( copyCondition( schema, 'field:uninstall', 'field:recommend' ) ).toBe( true );
		const source = schema.fields[ 1 ].logic;
		const copied = schema.fields[ 2 ].logic;
		expect( copied ).toEqual( source );
		copied.rules[ 0 ].operator = 'greater_equal';
		copied.rules.splice( 1, 1 );
		expect( source.rules[ 0 ].operator ).toBe( 'less' );
		expect( source.rules ).toHaveLength( 2 );
	} );
	it( 'supports fields, notifications and confirmations', () => {
		const schema = fixture();
		expect( conditionSections( schema ) ).toHaveLength( 5 );
		expect( copyCondition( schema, 'field:uninstall', 'notification:mail' ) ).toBe( true );
		expect( copyCondition( schema, 'notification:mail', 'confirmation:thanks' ) ).toBe( true );
		expect( schema.confirmations[ 0 ].logic ).toEqual( schema.fields[ 1 ].logic );
	} );
	it( 'refuses missing sections, empty rules, self-copy and self-dependency', () => {
		const schema = fixture();
		for ( const [ from, to ] of [ [ 'field:missing', 'field:recommend' ], [ 'field:uninstall', 'field:missing' ],
			[ 'field:score', 'field:recommend' ], [ 'field:uninstall', 'field:uninstall' ], [ 'field:uninstall', 'field:score' ] ] ) {
			expect( copyCondition( schema, from, to ) ).toBe( false );
		}
		schema.fields[ 1 ].logic.rules[ 0 ].field = 'deleted';
		expect( copyCondition( schema, 'field:uninstall', 'field:recommend' ) ).toBe( false );
	} );
	it( 'chooses only a source and applies to the section that opened it', () => {
		const schema = fixture();
		const onCopy = vi.fn();
		openConditionCopy( { root: document.body, schema: () => schema, to: 'field:recommend', onCopy } );
		expect( document.querySelector( '[aria-label="Copy to"]' ) ).toBeNull();
		const source = document.querySelector< HTMLSelectElement >( '[aria-label="Copy from"]' )!;
		expect( [ ...source.options ].map( ( option ) => option.value ) ).not.toContain( 'field:recommend' );
		source.value = 'field:uninstall';
		source.dispatchEvent( new Event( 'change' ) );
		expect( document.querySelector( '.atfb-condition-copy__preview' )?.textContent ).toContain( 'is less than 6 or Opinion scale is empty' );
		expect( schema.fields[ 2 ].logic.enabled ).toBe( false );
		[ ...document.querySelectorAll( 'button' ) ].find( ( item ) => item.textContent === 'Copy condition' )!.click();
		expect( schema.fields[ 2 ].logic ).toEqual( schema.fields[ 1 ].logic );
		expect( onCopy ).toHaveBeenCalledOnce();
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	} );
	it( 'Cancel leaves the destination untouched', () => {
		const schema = fixture();
		openConditionCopy( { root: document.body, schema: () => schema, to: 'field:recommend', onCopy: vi.fn() } );
		[ ...document.querySelectorAll( 'button' ) ].find( ( item ) => item.textContent === 'Cancel' )!.click();
		expect( schema.fields[ 2 ].logic ).toEqual( empty() );
	} );
	it( 'uses the current schema at apply time after a save replaces it', () => {
		let schema = fixture();
		openConditionCopy( { root: document.body, schema: () => schema, to: 'field:recommend', onCopy: vi.fn() } );
		const source = document.querySelector< HTMLSelectElement >( '[aria-label="Copy from"]' )!;
		source.value = 'field:uninstall';
		source.dispatchEvent( new Event( 'change' ) );
		const previous = schema;
		schema = fixture();
		schema.fields[ 1 ].logic.rules[ 0 ].value = '7';
		[ ...document.querySelectorAll( 'button' ) ].find( ( item ) => item.textContent === 'Copy condition' )!.click();
		expect( schema.fields[ 2 ].logic.rules[ 0 ].value ).toBe( '7' );
		expect( previous.fields[ 2 ].logic.rules ).toHaveLength( 0 );
	} );
} );

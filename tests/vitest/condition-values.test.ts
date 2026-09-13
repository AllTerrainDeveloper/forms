import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { conditionValueOptions } from '../../src/condition-values';
import { rulePasses } from '../../src/shared/logic';
import { Builder } from '../../src/builder';
import type { Field, FormSchema, Logic } from '../../src/types';

const field = ( type: string, settings = {} ) => ( { id: 'source', type, label: 'Question', ...settings } as Field );
const available = ( source: Field ) => conditionValueOptions( source, '' )!.filter( ( option ) => ! option.disabled ).map( ( option ) => option.value );

describe( 'condition value domains', () => {
	it( 'uses the actual opinion-scale bounds, including renderer corrections', () => {
		expect( available( field( 'scale' ) ) ).toEqual( Array.from( { length: 11 }, ( _, i ) => String( i ) ) );
		expect( available( field( 'scale', { min: -2, max: 2 } ) ) ).toEqual( [ '-2', '-1', '0', '1', '2' ] );
		expect( available( field( 'scale', { min: 3, max: 3 } ) ) ).toHaveLength( 11 );
		expect( available( field( 'scale', { min: 1, max: 100 } ) ) ).toHaveLength( 21 );
	} );
	it( 'matches star-rating limits', () => {
		expect( available( field( 'rating' ) ) ).toEqual( [ '1', '2', '3', '4', '5' ] );
		expect( available( field( 'rating', { max: 1 } ) ) ).toEqual( [ '1', '2' ] );
		expect( available( field( 'rating', { max: 100 } ) ) ).toHaveLength( 10 );
	} );
	it( 'uses choice labels while storing values, including checkbox membership', () => {
		const source = field( 'checkboxes', { choices: [ { label: 'Send updates', value: 'updates' }, { label: 'Offers', value: 'offers' } ] } );
		expect( conditionValueOptions( source, 'updates' ) ).toContainEqual( { label: 'Send updates', value: 'updates' } );
		expect( rulePasses( { field: 'source', operator: 'is', value: available( source )[ 0 ] }, [ 'updates', 'offers' ] ) ).toBe( true );
		expect( available( field( 'checkboxes', { choices: [] } ) ) ).toEqual( [] );
	} );
	it.each( [ 'switch', 'consent' ] )( 'represents checked and unchecked %s values using engine semantics', ( type ) => {
		const values = available( field( type ) );
		expect( values ).toEqual( [ '1', '' ] );
		expect( rulePasses( { field: 'source', operator: 'is', value: values[ 0 ] }, true ) ).toBe( true );
		expect( rulePasses( { field: 'source', operator: 'is', value: values[ 1 ] }, false ) ).toBe( true );
	} );
	it( 'uses country codes from the server catalogue', () => {
		expect( conditionValueOptions( field( 'country' ), 'ES', { ES: 'Spain', FR: 'France' } ) ).toContainEqual( { value: 'ES', label: 'Spain' } );
	} );
	it( 'offers discrete slider steps without floating-point artifacts', () => {
		expect( available( field( 'range', { min: 0.1, max: 0.5, step: 0.1 } ) ) ).toEqual( [ '0.1', '0.2', '0.3', '0.4', '0.5' ] );
		expect( conditionValueOptions( field( 'range', { step: 'any' } ), '' ) ).toBeNull();
		expect( conditionValueOptions( field( 'range', { max: 1e9 } ), '' ) ).toBeNull();
	} );
	it( 'retains obsolete values visibly but makes them unavailable for selection', () => {
		expect( conditionValueOptions( field( 'scale' ), '99' ) ).toContainEqual( { value: '99', label: '99 (stored value; unavailable)', disabled: true } );
		expect( conditionValueOptions( field( 'text' ), 'anything' ) ).toBeNull();
		expect( conditionValueOptions( undefined, '' ) ).toBeNull();
	} );
} );

describe( 'condition os-select controls', () => {
	beforeAll( () => {
		customElements.define( 'os-select', class extends HTMLElement {} );
		customElements.define( 'os-option', class extends HTMLElement {} );
	} );
	afterEach( () => { document.body.replaceChildren(); vi.restoreAllMocks(); } );
	it( 'uses os-select in the shared rule editor and writes the picked value', () => {
		const root = document.createElement( 'div' );
		const builder = new Builder( root );
		const schema = { fields: [ field( 'scale' ) ] } as FormSchema;
		Reflect.set( builder, 'schema', schema );
		const logic: Logic = { enabled: true, action: 'show', match: 'all', rules: [ { field: 'source', operator: 'less', value: '6' } ] };
		const write = ( mutate: ( live: Logic ) => void ) => mutate( logic );
		root.append( ...Reflect.get( builder, 'logicRulesEditor' ).call( builder, logic, write ) );
		const picker = root.querySelector( '[aria-label="The answer that triggers this"]' )!;
		expect( picker.tagName ).toBe( 'OS-SELECT' );
		expect( picker.getAttribute( 'value' ) ).toBe( '6' );
		expect( picker.querySelectorAll( 'os-option:not([disabled])' ) ).toHaveLength( 11 );
		picker.dispatchEvent( new CustomEvent( 'os-pick', { detail: { value: '7' } } ) );
		expect( logic.rules[ 0 ].value ).toBe( '7' );
	} );
	it( 'uses the same allowed values in the inline editor', () => {
		const builder = new Builder( document.createElement( 'div' ) );
		Reflect.set( builder, 'schema', { fields: [ field( 'scale', { min: 1, max: 5 } ) ] } );
		const edit = vi.fn();
		Reflect.set( builder, 'editCondition', edit );
		const picker = Reflect.get( builder, 'renderConditionValue' ).call( builder, { id: 'target' }, { kind: 'value', sourceId: 'source', ruleIndex: 0, raw: '3', text: '3' } ) as HTMLElement;
		expect( picker.tagName ).toBe( 'OS-SELECT' );
		expect( picker.classList.contains( 'atfb-cond__value-select' ) ).toBe( true );
		expect( picker.hasAttribute( 'plain' ) ).toBe( false );
		expect( [ ...picker.querySelectorAll( 'os-option:not([disabled])' ) ].map( ( option ) => option.getAttribute( 'value' ) ) ).toEqual( [ '1', '2', '3', '4', '5' ] );
		picker.dispatchEvent( new CustomEvent( 'os-pick', { detail: { value: '4' } } ) );
		const logic = { rules: [ { value: '3' } ] };
		edit.mock.calls[ 0 ][ 1 ]( logic );
		expect( logic.rules[ 0 ].value ).toBe( '4' );
	} );
} );

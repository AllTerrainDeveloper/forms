import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { Builder } from '../../src/builder';
import { api } from '../../src/api';
import { parsePackage } from '../../src/shared/form-package.mjs';
import type { Form } from '../../src/types';

const yaml = readFileSync( 'docs/examples/contact-form.yaml', 'utf8' );
const model = parsePackage( yaml ) as { form: { title: string; schema: Form['schema'] } };
const imported = { ...model.form, id: 9, status: 'draft', importWarnings: [] } as unknown as Form & { importWarnings: string[] };

function builder() {
	const root = document.createElement( 'div' );
	const instance = new Builder( root );
	Reflect.set( instance, 'form', { ...model.form, id: 1 } );
	Reflect.set( instance, 'schema', structuredClone( model.form.schema ) );
	for ( const method of [ 'renderBar', 'renderCanvas', 'renderInspector', 'announceIdentity', 'showPackageResult' ] ) {
		Reflect.set( instance, method, vi.fn() );
	}
	return instance;
}

async function selectFile( instance: Builder, validateOnly = false, content = yaml ) {
	let picker: HTMLInputElement | undefined;
	vi.spyOn( HTMLInputElement.prototype, 'click' ).mockImplementation( function ( this: HTMLInputElement ) { picker = this; } );
	await Reflect.get( instance, 'importForm' ).call( instance, validateOnly );
	Object.defineProperty( picker!, 'files', { value: [ { name: 'form.yaml', size: content.length, text: async () => content } ] } );
	picker!.dispatchEvent( new Event( 'change' ) );
}

afterEach( () => { vi.restoreAllMocks(); document.body.replaceChildren(); } );

describe( 'builder portability flow', () => {
	it( 'dry-run validation never imports or changes the current form', async () => {
		const instance = builder();
		const current = Reflect.get( instance, 'form' );
		vi.spyOn( api, 'validateFormPackage' ).mockResolvedValue( { valid: true, warnings: [] } );
		const create = vi.spyOn( api, 'importFormPackage' );
		await selectFile( instance, true );
		await vi.waitFor( () => expect( Reflect.get( instance, 'showPackageResult' ) ).toHaveBeenCalledWith( 'Valid form package', expect.any( String ) ) );
		expect( create ).not.toHaveBeenCalled();
		expect( Reflect.get( instance, 'form' ) ).toBe( current );
	} );

	it( 'rejects invalid YAML before any server request', async () => {
		const instance = builder();
		const validate = vi.spyOn( api, 'validateFormPackage' );
		await selectFile( instance, false, 'format: broken\nformat: duplicate' );
		await vi.waitFor( () => expect( Reflect.get( instance, 'showPackageResult' ) ).toHaveBeenCalledWith( 'Could not import that file', expect.stringContaining( 'unique' ) ) );
		expect( validate ).not.toHaveBeenCalled();
	} );

	it( 'keeps the current edits if they change while import is in flight', async () => {
		const instance = builder();
		const current = Reflect.get( instance, 'form' );
		vi.spyOn( api, 'validateFormPackage' ).mockResolvedValue( { valid: true, warnings: [] } );
		let resolve: ( form: typeof imported ) => void;
		const create = vi.spyOn( api, 'importFormPackage' ).mockReturnValue( new Promise( ( done ) => { resolve = done; } ) );
		vi.spyOn( api, 'listThemes' ).mockResolvedValue( [] );
		await selectFile( instance );
		await vi.waitFor( () => expect( create ).toHaveBeenCalled() );
		Reflect.set( instance, 'editGeneration', 1 );
		Reflect.set( instance, 'dirty', true );
		resolve!( imported );
		await vi.waitFor( () => expect( Reflect.get( instance, 'forms' ) ).toHaveLength( 1 ) );
		expect( Reflect.get( instance, 'form' ) ).toBe( current );
		expect( Reflect.get( instance, 'dirty' ) ).toBe( true );
	} );

	it( 'adopts a successful import even if refreshing the theme list fails', async () => {
		const instance = builder();
		vi.spyOn( api, 'validateFormPackage' ).mockResolvedValue( { valid: true, warnings: [] } );
		vi.spyOn( api, 'importFormPackage' ).mockResolvedValue( imported );
		vi.spyOn( api, 'listThemes' ).mockRejectedValue( new Error( 'Offline' ) );
		await selectFile( instance );
		await vi.waitFor( () => expect( Reflect.get( instance, 'form' ) ).toBe( imported ) );
		expect( Reflect.get( instance, 'showPackageResult' ) ).toHaveBeenCalledWith( 'Form imported as a draft', expect.any( String ) );
	} );
} );

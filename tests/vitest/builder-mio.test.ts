import { afterEach, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { Builder } from '../../src/builder';
import { api } from '../../src/api';
import { parseFormDraft } from '../../src/mio/validation';
import type { Form } from '../../src/types';

function fixture() {
	const root = document.createElement( 'div' ); document.body.append( root );
	const builder = new Builder( root );
	const draft = parseFormDraft( readFileSync( 'docs/examples/mio-conditional-contact.yaml', 'utf8' ) );
	const original = { ...draft, id: 3, status: 'publish', entries: 4, previewUrl: '', modified: '', shortcode: '[allterrain_form id="3"]' } as Form;
	Reflect.set( builder, 'form', original ); Reflect.set( builder, 'schema', original.schema );
	for ( const method of [ 'renderBar', 'renderCanvas', 'renderInspector', 'announceIdentity' ] ) Reflect.set( builder, method, vi.fn() );
	const snapshot = Reflect.get( builder, 'assistantSnapshot' ).call( builder );
	const apply = Reflect.get( builder, 'applyAssistant' );
	return { root, builder, original, draft, snapshot, apply };
}
afterEach( () => { vi.restoreAllMocks(); document.body.replaceChildren(); } );

it( 'serializes assistant saves with autosave and adopts the saved schema', async () => {
	const { builder, root, original, draft, snapshot, apply } = fixture();
	let finish: ( form: Form ) => void;
	vi.spyOn( api, 'assistantApply' ).mockReturnValue( new Promise( ( resolve ) => { finish = resolve; } ) );
	const autosave = vi.spyOn( api, 'updateForm' );
	const saving = apply( draft, 'update', snapshot, 'revision', new AbortController().signal );
	expect( root.inert ).toBe( true );
	await Reflect.get( builder, 'save' ).call( builder, true ); expect( autosave ).not.toHaveBeenCalled();
	const saved = { ...original, title: 'Updated by MIO' }; finish!( saved );
	await saving;
	expect( Reflect.get( builder, 'form' ) ).toBe( saved ); expect( Reflect.get( builder, 'dirty' ) ).toBe( false );
	expect( root.inert ).toBeFalsy(); expect( Reflect.get( builder, 'assistantSaving' ) ).toBe( false );
} );

it( 'does not put a late save response into a changed editor', async () => {
	const { builder, original, draft, snapshot, apply } = fixture();
	vi.spyOn( api, 'assistantApply' ).mockImplementation( async () => { Reflect.set( builder, 'editGeneration', 1 ); return { ...original, title: 'Stale response' }; } );
	await expect( apply( draft, 'update', snapshot, 'revision', new AbortController().signal ) ).resolves.toMatchObject( { title: 'Stale response' } );
	expect( Reflect.get( builder, 'form' ) ).toBe( original ); expect( Reflect.get( builder, 'assistantSaving' ) ).toBe( false );
} );

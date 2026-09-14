import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { posix } from 'node:path';
import { api, ApiError } from '../../src/api';
import { createFormMioContext, mountFormMio } from '../../src/mio/assistant';
import { parseFormDraft, DraftValidationError } from '../../src/mio/validation';
import { mioDocuments } from '../../src/mio/documents';
import type { FormAssistantHost } from '../../src/mio/types';
import type { Form } from '../../src/types';

const yaml = readFileSync( 'docs/examples/mio-conditional-contact.yaml', 'utf8' );
function fixture() {
	const root = document.createElement( 'div' ); document.body.append( root );
	let fingerprint = 'initial';
	const draft = parseFormDraft( yaml );
	const saved = { ...draft, id: 12, status: 'draft', shortcode: '[allterrain_form id="12"]' } as Form;
	const apply = vi.fn().mockResolvedValue( saved );
	const host: FormAssistantHost = { root, read: () => ( { formId: 7, fingerprint, draft, busy: false } ), options: () => ( { config: null, themes: [] } ), apply };
	vi.spyOn( api, 'assistantRevision' ).mockResolvedValue( { revision: 'server-revision' } );
	const validate = vi.spyOn( api, 'assistantValidate' ).mockResolvedValue( { valid: true } );
	const context = createFormMioContext( host );
	const call = async ( name: string, args: Record<string, unknown>, signal = new AbortController().signal ): Promise<any> => {
		const ability = context.abilities().find( ( item ) => item.name === name )!;
		expect( ability.validate( args ) ).toBe( true );
		return ability.run( args, signal );
	};
	return { root, host, context, call, apply, validate, change: () => { fingerprint = 'edited'; } };
}
afterEach( () => { vi.restoreAllMocks(); document.body.replaceChildren(); Reflect.deleteProperty( window, 'wp' ); } );

describe( 'MIO repair and save workflow', () => {
	it( 'returns precise errors, permits two corrections, then saves the exact validated candidate once', async () => {
		const { call, apply, validate } = fixture();
		const { editId } = await call( 'begin_form_edit', { mode: 'create' } );
		const invalid = yaml.replace( 'required: true', 'required: "true"' );
		const first = await call( 'validate_form_yaml', { editId, yaml: invalid } );
		expect( first ).toMatchObject( { ok: false, saved: false, retriesRemaining: 2, attempt: 1 } );
		expect( first.errors[ 0 ] ).toMatchObject( { path: '/schema/fields/0/required', code: 'type' } );
		expect( validate ).not.toHaveBeenCalled();
		validate.mockRejectedValueOnce( new ApiError( 'Missing controlling field', 400, 'invalid', { errors: [ { path: 'form.schema.fields.details', message: 'missing logic field heard' } ] } ) );
		const second = await call( 'validate_form_yaml', { editId, yaml } );
		expect( second ).toMatchObject( { ok: false, retriesRemaining: 1, attempt: 2 } );
		expect( apply ).not.toHaveBeenCalled();
		const third = await call( 'validate_form_yaml', { editId, yaml } );
		expect( third ).toMatchObject( { valid: true, saved: false } );
		const args = { editId, receipt: third.receipt };
		expect( await call( 'apply_form_edit', args ) ).toMatchObject( { saved: true, formId: 12, status: 'draft' } );
		expect( apply ).toHaveBeenCalledWith( parseFormDraft( yaml ), 'create', expect.anything(), 'server-revision', expect.any( AbortSignal ), undefined );
		expect( await call( 'apply_form_edit', args ) ).toMatchObject( { saved: false, retryable: false } );
		expect( apply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops after three invalid attempts and never writes', async () => {
		const { call, apply } = fixture(); const { editId } = await call( 'begin_form_edit', { mode: 'update' } );
		for ( let attempt = 1; attempt <= 3; attempt++ ) expect( await call( 'validate_form_yaml', { editId, yaml: 'title: [broken' } ) ).toMatchObject( { attempt, retriesRemaining: 3 - attempt } );
		expect( await call( 'validate_form_yaml', { editId, yaml } ) ).toMatchObject( { retryable: false, retriesRemaining: 0 } );
		expect( apply ).not.toHaveBeenCalled();
	} );

	it( 'cannot apply an unvalidated or invalidated receipt', async () => {
		const { call, apply } = fixture(); const { editId } = await call( 'begin_form_edit', { mode: 'update' } );
		expect( await call( 'apply_form_edit', { editId, receipt: 'invented' } ) ).toMatchObject( { saved: false } );
		const result = await call( 'validate_form_yaml', { editId, yaml } );
		await call( 'validate_form_yaml', { editId, yaml: 'bad: true' } );
		expect( await call( 'apply_form_edit', { editId, receipt: result.receipt } ) ).toMatchObject( { saved: false } );
		expect( apply ).not.toHaveBeenCalled();
	} );

	it( 'rejects changes to the editor made after validation', async () => {
		const { call, change, apply } = fixture(); const { editId } = await call( 'begin_form_edit', { mode: 'update' } );
		const result = await call( 'validate_form_yaml', { editId, yaml } ); change();
		expect( await call( 'apply_form_edit', { editId, receipt: result.receipt } ) ).toMatchObject( { saved: false } );
		expect( apply ).not.toHaveBeenCalled();
	} );

	it( 'does not issue a receipt if the editor changes during validation', async () => {
		const { call, change, validate } = fixture(); const { editId } = await call( 'begin_form_edit', { mode: 'update' } );
		validate.mockImplementationOnce( async () => { change(); return { valid: true }; } );
		expect( await call( 'validate_form_yaml', { editId, yaml } ) ).toMatchObject( { ok: false, retryable: false } );
	} );

	it( 'does not replay an uncertain write', async () => {
		const { call, apply } = fixture(); const { editId } = await call( 'begin_form_edit', { mode: 'update' } );
		const result = await call( 'validate_form_yaml', { editId, yaml } );
		apply.mockRejectedValueOnce( new Error( 'Network lost' ) );
		const args = { editId, receipt: result.receipt };
		expect( await call( 'apply_form_edit', args ) ).toMatchObject( { outcome: 'unknown', retryable: false } );
		await call( 'apply_form_edit', args ); expect( apply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'cancels before validation and before a write', async () => {
		const { call, validate, apply } = fixture(); const { editId } = await call( 'begin_form_edit', { mode: 'update' } );
		const controller = new AbortController(); controller.abort();
		await expect( call( 'validate_form_yaml', { editId, yaml }, controller.signal ) ).rejects.toMatchObject( { name: 'AbortError' } );
		const result = await call( 'validate_form_yaml', { editId, yaml } );
		await expect( call( 'apply_form_edit', { editId, receipt: result.receipt }, controller.signal ) ).rejects.toMatchObject( { name: 'AbortError' } );
		expect( validate ).toHaveBeenCalledTimes( 1 ); expect( apply ).not.toHaveBeenCalled();
	} );

	it( 'isolates edits between contexts', async () => {
		const a = fixture(); const b = fixture(); const { editId } = await a.call( 'begin_form_edit', { mode: 'update' } );
		expect( await b.call( 'validate_form_yaml', { editId, yaml } ) ).toMatchObject( { ok: false } );
	} );

	it( 'mounts using the native instance ID and disposes when the editor disconnects', async () => {
		const { host, root } = fixture(); const dispose = vi.fn(); const registerWindow = vi.fn().mockReturnValue( { dispose } );
		Reflect.set( window, 'wp', { os: { mio: { registerWindow }, windowManager: { getAll: () => [ { id: 'forms-instance-2', element: root } ] } } } );
		mountFormMio( host ); expect( registerWindow ).toHaveBeenCalledWith( 'forms-instance-2', expect.objectContaining( { host: root } ) );
		root.remove(); await vi.waitFor( () => expect( dispose ).toHaveBeenCalledTimes( 1 ) );
	} );

	it( 'retains manual mode without a compatible shell', () => {
		const { host } = fixture(); expect( () => mountFormMio( host )() ).not.toThrow();
	} );
} );

describe( 'MIO YAML and knowledge contract', () => {
	it.each( [ 'title: x\ntitle: y', 'title: !unsafe hi', 'a: &a [1]\nb: *a', '---\na: 1\n---\nb: 2', '```yaml\ntitle: x\n```' ] )( 'rejects unsafe or malformed documents: %s', ( input ) => {
		expect( () => parseFormDraft( input ) ).toThrow( DraftValidationError );
	} );
	it( 'gives syntax positions and a size-limit explanation', () => {
		try { parseFormDraft( 'title: [broken' ); } catch ( error ) { expect( ( error as DraftValidationError ).issues[ 0 ] ).toMatchObject( { code: 'yaml_syntax', line: 1 } ); }
		expect( () => parseFormDraft( 'x'.repeat( 16001 ) ) ).toThrow( '16 KB' );
	} );
	it( 'bundles linked, retrievable documentation with component and token coverage', () => {
		const ids = new Set( mioDocuments.map( ( doc ) => doc.id ) );
		expect( ids.has( 'index.md' ) ).toBe( true ); expect( ids.has( 'recipes/conditional-contact.md' ) ).toBe( true );
		expect( mioDocuments.filter( ( doc ) => doc.id.startsWith( 'components/' ) ) ).toHaveLength( 38 );
		for ( const doc of mioDocuments ) {
			expect( doc.markdown.length, doc.id ).toBeLessThanOrEqual( 12000 );
			for ( const match of doc.markdown.matchAll( /\]\(([^)#]+\.md)(?:#[^)]*)?\)/g ) ) expect( ids.has( posix.normalize( posix.join( posix.dirname( doc.id ), match[ 1 ] ) ) ), `${ doc.id } → ${ match[ 1 ] }` ).toBe( true );
		}
	} );
} );

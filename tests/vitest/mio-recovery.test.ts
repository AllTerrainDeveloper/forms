import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { api } from '../../src/api';
import { createFormMioContext } from '../../src/mio/assistant';
import { compactFormHistory } from '../../src/mio/history';
import * as preview from '../../src/preview-button';
import { parseFormDraft } from '../../src/mio/validation';
import type { MioCallContext, MioHistoryEntry, FormAssistantHost } from '../../src/mio/types';

const yaml = readFileSync( 'docs/examples/mio-conditional-contact.yaml', 'utf8' );
const limits = { rounds: 8, calls: 16, validationFailures: 3, repeatedReads: 4 };
function fixture() {
	const root = document.createElement( 'div' ); document.body.append( root );
	const draft = parseFormDraft( yaml );
	const apply = vi.fn().mockResolvedValue( { ...draft, id: 8, status: 'draft', operation: { receipt: 'durable-receipt' } } );
	const host: FormAssistantHost = { root, read: () => ( { formId: 7, draft, fingerprint: 'private content', busy: false } ), options: () => ( { config: null, themes: [] } ), apply };
	vi.spyOn( api, 'assistantRevision' ).mockResolvedValue( { revision: 'stored-revision' } );
	vi.spyOn( api, 'assistantValidate' ).mockResolvedValue( { valid: true } );
	const context = createFormMioContext( host );
	let index = 0;
	const callContext = ( turnId = 'turn-1', failures = 0 ): MioCallContext => ( { turnId, callId: `call-${ ++index }`, idempotencyKey: `operation-${ index }`, effect: 'read', signal: new AbortController().signal, limits, validationFailures: failures, validationRemaining: 3 - failures } );
	const turn = callContext(); context.onTurnBegin!( turn );
	const call = async ( name: string, args: Record<string, unknown>, meta = callContext() ) => {
		const ability = context.abilities().find( ( item ) => item.name === name )!;
		const result: any = await ability.run( args, meta.signal, meta );
		const entry: MioHistoryEntry = { name, args, result, callId: meta.callId };
		return { result, history: ability.history?.( entry, meta ) ?? { result }, meta };
	};
	return { root, host, context, call, callContext, apply };
}
afterEach( () => { vi.restoreAllMocks(); document.body.replaceChildren(); } );

describe( 'MIO recovery API', () => {
	it( 'declares effects and returns detailed envelope errors without leaking form content in revisions', () => {
		const { context, callContext } = fixture();
		expect( context.abilities().map( ( ability ) => ability.effect ) ).toEqual( [ 'read', 'read', 'validate', 'write' ] );
		const ability = context.abilities()[ 0 ];
		expect( ability.validate( { mode: 7 }, callContext() ) ).toMatchObject( { ok: false, retryable: true, errors: [ { code: 'argument_shape', path: '$' } ] } );
		expect( context.revision!() ).not.toContain( 'private content' );
		expect( context.revision!() ).toBe( context.revision!() );
	} );

	it( 'does not reset retries when a new edit is started in the same user turn', async () => {
		const { call } = fixture();
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			const begin = await call( 'begin_form_edit', { mode: 'update' } );
			const invalid = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml: 'title: [broken' } );
			expect( invalid.result ).toMatchObject( { effect: 'none', status: 'rejected', attempt, retriesRemaining: 3 - attempt } );
		}
		expect( ( await call( 'begin_form_edit', { mode: 'update' } ) ).result ).toMatchObject( { ok: false, retryable: false } );
	} );

	it( 'accounts for the shell argument failures and rejects cross-turn receipts', async () => {
		const { call, callContext } = fixture();
		const begin = await call( 'begin_form_edit', { mode: 'update' } );
		const invalid = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml: 'bad: true' }, callContext( 'turn-1', 2 ) );
		expect( invalid.result ).toMatchObject( { attempt: 3, retryable: false } );
		const next = await call( 'begin_form_edit', { mode: 'update' }, callContext( 'turn-2' ) );
		expect( next.result.ok ).toBe( true );
		expect( ( await call( 'validate_form_yaml', { editId: next.result.editId, yaml }, callContext( 'turn-1' ) ) ).result.ok ).toBe( false );
	} );

	it( 'retains only the latest full candidate and every validation error/receipt', async () => {
		const { call } = fixture();
		const begin = await call( 'begin_form_edit', { mode: 'update' } );
		const bad = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml: yaml.replace( 'required: true', 'required: "true"' ) } );
		const good = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml } );
		// These are the two nesting shapes emitted by the upstream session.
		const input = { messages: [], help: [], outcomes: [ begin.history, { result: { errors: bad.result.errors, data: bad.history } }, good.history ] };
		const compact = compactFormHistory( input );
		const serialized = JSON.stringify( compact );
		expect( serialized.match( /"yaml":/g ) ).toHaveLength( 1 );
		expect( serialized ).toContain( 'must be boolean' );
		expect( serialized ).toContain( good.result.receipt );
		expect( ( compact.outcomes[ 2 ] as any ).document.yaml ).toBe( yaml );
		expect( JSON.stringify( input ).match( /"yaml":/g ) ).toHaveLength( 3 );
	} );

	it( 'confirms the server receipt and uses the read-only status endpoint', async () => {
		const { context, call, apply } = fixture();
		const begin = await call( 'begin_form_edit', { mode: 'create' } );
		const valid = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml } );
		const saved = await call( 'apply_form_edit', { editId: begin.result.editId, receipt: valid.result.receipt } );
		expect( saved.result ).toMatchObject( { effect: 'write', status: 'confirmed', receipt: 'durable-receipt', data: { formId: 8, saved: true } } );
		expect( apply.mock.calls[ 0 ][ 5 ] ).toBe( saved.meta.idempotencyKey );
		const inspect = vi.spyOn( api, 'assistantOperation' ).mockResolvedValue( { effect: 'write', status: 'confirmed', receipt: 'durable-receipt' } );
		await context.operationStatus!( { ...saved.meta, ability: 'apply_form_edit', status: 'unknown' }, saved.meta.signal );
		expect( inspect ).toHaveBeenCalledWith( saved.meta.idempotencyKey, saved.meta.signal );
		expect( apply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps an authoritative save receipt when cancellation clears the edit mid-flight', async () => {
		const { context, call, apply, callContext } = fixture();
		const begin = await call( 'begin_form_edit', { mode: 'create' } );
		const valid = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml } );
		const meta = callContext();
		apply.mockImplementationOnce( async () => { context.onTurnAbort!( meta ); return { id: 8, status: 'draft', operation: { receipt: 'late-receipt' } }; } );
		expect( ( await call( 'apply_form_edit', { editId: begin.result.editId, receipt: valid.result.receipt }, meta ) ).result ).toMatchObject( { status: 'confirmed', receipt: 'late-receipt' } );
	} );

	it( 'offers Preview only for a confirmed save and keeps its original form after another turn', async () => {
		const { context, call, callContext, host, apply } = fixture();
		const meta = callContext();
		const response = { messageId: 'reply', summary: { ...meta, status: 'completed' as const, unknownWrites: 0 }, operations: [] as any[] };
		expect( context.responseActions!( response ) ).toEqual( [] );
		const begin = await call( 'begin_form_edit', { mode: 'create' } );
		const valid = await call( 'validate_form_yaml', { editId: begin.result.editId, yaml } );
		const saved = await call( 'apply_form_edit', { editId: begin.result.editId, receipt: valid.result.receipt } );
		response.operations = [ { ...saved.meta, ability: 'apply_form_edit', effect: 'write', status: 'confirmed', receipt: saved.result.receipt } ];
		expect( context.responseActions!( { ...response, summary: { ...response.summary, unknownWrites: 1 } } ) ).toEqual( [] );
		expect( context.responseActions!( { ...response, operations: [ { ...response.operations[ 0 ], receipt: 'invented' } ] } ) ).toEqual( [] );
		const [ action ] = context.responseActions!( response );
		expect( action ).toMatchObject( { label: 'Preview', effect: 'navigate', emphasis: 'primary' } );
		context.onTurnBegin!( callContext( 'next-turn' ) );
		const get = vi.spyOn( api, 'getForm' ).mockResolvedValue( { id: 8, title: 'Saved', previewUrl: '/preview?fresh=1' } as any );
		const open = vi.spyOn( preview, 'openPreviewWindow' ).mockImplementation( () => {} );
		await action.run( { signal: meta.signal, messageId: 'reply', turnId: meta.turnId } );
		expect( get ).toHaveBeenCalledWith( 8, meta.signal );
		expect( open ).toHaveBeenCalledWith( 8, 'Saved', '/preview?fresh=1' );
		expect( apply ).toHaveBeenCalledTimes( 1 );
		get.mockRejectedValueOnce( new Error( 'Access revoked' ) );
		await expect( action.run( { signal: meta.signal, messageId: 'reply', turnId: meta.turnId } ) ).rejects.toThrow( 'Access revoked' );
		expect( open ).toHaveBeenCalledTimes( 1 );
		host.root.remove();
		expect( action.allowed!() ).toBe( false );
	} );
} );

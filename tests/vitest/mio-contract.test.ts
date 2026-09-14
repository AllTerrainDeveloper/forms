/** Run explicitly against a local copy of the unmerged MIO API. */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { resolve } from 'node:path';
import { api } from '../../src/api';
import { createFormMioContext } from '../../src/mio/assistant';
import { parseFormDraft, MAX_COMPACT_ASSISTANT_BYTES } from '../../src/mio/validation';
import { stringifyPackage } from '../../src/shared/form-package.mjs';
import * as preview from '../../src/preview-button';
import type { FormAssistantHost } from '../../src/mio/types';

const source = process.env.ATF_MIO_SOURCE;
let MioSession: any;
afterEach( () => { vi.restoreAllMocks(); document.body.replaceChildren(); } );

describe.skipIf( ! source )( 'integration with the live MIO session contract', () => {
	async function fixture() {
		// Computed import makes the sibling optional, not a shipped dependency.
		const path = resolve( source!, 'src/mio/assistant/session.ts' );
		( { MioSession } = await import( /* @vite-ignore */ path ) );
		const root = document.createElement( 'div' ); document.body.append( root );
		const yaml = stringifyPackage( { title: 'Large form', schema: { version: 1, fields: [ { id: 'details', type: 'textarea', hint: 'A'.repeat( 35000 ) } ], settings: { theme: 'clean', themeOverrides: {} }, notifications: [], confirmations: [], actions: [] } } );
		const draft = parseFormDraft( yaml, MAX_COMPACT_ASSISTANT_BYTES );
		const apply = vi.fn().mockResolvedValue( { id: 77, title: 'Saved', status: 'draft', operation: { receipt: 'server-receipt' } } );
		const host: FormAssistantHost = { root, read: () => ( { formId: 7, draft, fingerprint: 'current', busy: false } ), options: () => ( { config: null, themes: [] } ), apply };
		vi.spyOn( api, 'assistantRevision' ).mockResolvedValue( { revision: 'server-revision' } );
		vi.spyOn( api, 'assistantValidate' ).mockResolvedValue( { valid: true } );
		return { context: createFormMioContext( host ), apply, yaml };
	}
	const answer = ( name: string, args: unknown ) => ( { message: '', calls: [ { name, arguments: JSON.stringify( args ) } ] } );

	it( 'repairs a 35 KB candidate twice, preserves it completely and records exactly one confirmed write', async () => {
		const { context, apply, yaml } = await fixture();
		const summary = vi.fn(); const end = context.onTurnEnd;
		context.onTurnEnd = ( turn ) => { summary( turn ); end?.( turn ); };
		let round = 0;
		const transport = vi.fn( async ( request: any ) => {
			const history = JSON.parse( request.transcript );
			expect( new TextEncoder().encode( request.transcript ).length ).toBeLessThan( 96000 );
			expect( request.transcript.match( /"yaml":/g )?.length ?? 0 ).toBeLessThanOrEqual( 1 );
			const begin = history.outcomes.find( ( item: any ) => item.name === 'begin_form_edit' );
			const editId = begin?.result.editId;
			switch ( round++ ) {
				case 0: return answer( 'begin_form_edit', { mode: 'update' } );
				case 1: return answer( 'validate_form_yaml', { editId, yaml: yaml.replace( 'type: textarea', 'type: 7' ) } );
				case 2:
					expect( history.outcomes.at( -1 ).result.errors[ 0 ].path ).toBe( '/schema/fields/0/type' );
					return answer( 'validate_form_yaml', { editId, yaml: yaml.replace( 'theme: clean', 'theme: 9' ) } );
				case 3: return answer( 'validate_form_yaml', { editId, yaml } );
				case 4:
					expect( history.outcomes.at( -1 ).document.yaml ).toBe( yaml );
					return answer( 'apply_form_edit', { editId, receipt: history.outcomes.at( -1 ).result.receipt } );
				default: return { message: 'Saved your form.', calls: [] };
			}
		} );
		const session = new MioSession( context, transport, () => true );
		await expect( session.ask( 'Update this form, preserving all instructions.' ) ).resolves.toBe( 'Saved your form.' );
		expect( apply ).toHaveBeenCalledTimes( 1 );
		expect( apply.mock.calls[ 0 ][ 0 ].schema.fields[ 0 ].hint ).toHaveLength( 35000 );
		expect( summary ).toHaveBeenCalledWith( expect.objectContaining( { rejected: 2, confirmedWrites: 1, unknownWrites: 0 } ) );
		expect( session.operations.list().find( ( item: any ) => item.ability === 'apply_form_edit' ) ).toMatchObject( { status: 'confirmed', receipt: 'server-receipt' } );
		// Exercise the developer's action registry against the Forms consumer.
		const message = session.conversation.read().at( -1 );
		const [ action ] = session.responseActions.list( message );
		expect( action.label ).toBe( 'Preview' );
		const get = vi.spyOn( api, 'getForm' ).mockResolvedValue( { id: 77, title: 'Saved', previewUrl: '/preview' } as any );
		const open = vi.spyOn( preview, 'openPreviewWindow' ).mockImplementation( () => {} );
		await session.responseActions.run( message.id, action.id );
		expect( get ).toHaveBeenCalledWith( 77, expect.any( AbortSignal ) );
		expect( open ).toHaveBeenCalledWith( 77, 'Saved', '/preview' );
		expect( transport ).toHaveBeenCalledTimes( 6 );
		expect( apply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lets the real session repair malformed outer arguments without classifying reads as writes', async () => {
		const { context, apply } = await fixture(); let round = 0;
		const session = new MioSession( context, async ( request: any ) => {
			if ( round++ === 0 ) return answer( 'begin_form_edit', { mode: 7 } );
			if ( round === 2 ) {
				expect( JSON.parse( request.transcript ).outcomes[ 0 ].result.errors[ 0 ].code ).toBe( 'argument_shape' );
				return answer( 'begin_form_edit', { mode: 'update' } );
			}
			return { message: 'Read the form.', calls: [] };
		}, () => true );
		await expect( session.ask( 'Read this form.' ) ).resolves.toBe( 'Read the form.' );
		expect( apply ).not.toHaveBeenCalled();
		expect( session.operations.list().every( ( entry: any ) => entry.effect === 'read' ) ).toBe( true );
	} );
} );

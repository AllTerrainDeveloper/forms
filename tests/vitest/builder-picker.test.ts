import { afterEach, describe, expect, it, vi } from 'vitest';
import { Builder } from '../../src/builder';
import { api } from '../../src/api';
import type { Form, FormSchema } from '../../src/types';

/** A tag selection must survive an autosave that finishes while the picker is open. */
describe( 'picker across autosave', () => {
	afterEach( () => {
		vi.clearAllTimers();
		vi.useRealTimers();
		vi.restoreAllMocks();
		document.body.replaceChildren();
	} );

	it( 'writes the selected tag to the adopted schema and keeps the input mounted', async () => {
		vi.useFakeTimers();
		const root = document.createElement( 'div' );
		root.innerHTML = '<div data-atfb-bar></div><div data-atfb-canvas></div>';
		document.body.append( root );
		const schema = {
			fields: [], confirmations: [], notifications: [ {
				id: 'mail', name: 'Mail', enabled: false, to: '{admin_email}', replyTo: '',
				subject: 'Score: ', message: '', attachFiles: false,
				logic: { enabled: false, action: 'show', match: 'all', rules: [] },
			} ],
		} as unknown as FormSchema;
		const builder = new Builder( root );
		Reflect.set( builder, 'schema', schema );
		Reflect.set( builder, 'form', { id: 9876, title: 'QA', schema } );
		Reflect.set( builder, 'tab', 'notify' );
		const pane = Reflect.get( builder, 'renderNotificationsPane' ).call( builder );
		root.querySelector( '[data-atfb-canvas]' )!.append( pane );
		root.querySelector( 'details' )!.open = true;
		vi.spyOn( api, 'mergeTags' ).mockResolvedValue( [ { id: 'answers', label: 'Answers', items: [ {
			tag: '{field:score}', label: 'Opinion scale', hint: '', sample: 'Their answer',
		} ] } ] );
		const input = [ ...root.querySelectorAll( 'input' ) ].find( ( item ) => item.value === 'Score: ' )!;
		input.focus();
		input.value += '{';
		input.setSelectionRange( input.value.length, input.value.length );
		input.dispatchEvent( new InputEvent( 'input', { data: '{', bubbles: true } ) );
		await Promise.resolve();
		await Promise.resolve();
		const saved = JSON.parse( JSON.stringify( { id: 9876, title: 'QA', schema } ) ) as Form;
		vi.spyOn( api, 'updateForm' ).mockResolvedValue( saved );
		await Reflect.get( builder, 'save' ).call( builder, true );
		root.querySelector< HTMLButtonElement >( '.atfb-tagpick__item' )!.click();
		await Promise.resolve();
		expect( saved.schema.notifications[ 0 ].subject ).toBe( 'Score: {field:score}' );
		expect( input.value ).toBe( 'Score: {field:score}' );
		expect( input.isConnected ).toBe( true );
		expect( document.activeElement ).toBe( input );
	} );
} );

import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

async function call( page: Page, name: string, args: Record<string, unknown> ): Promise<any> {
	return page.evaluate( async ( { name, args } ) => {
		const ability = ( window as any ).__formMio.abilities().find( ( item: any ) => item.name === name );
		if ( ! ability || ! ability.validate( args ) ) throw new Error( `Invalid tool ${ name }` );
		const signal = new AbortController().signal;
		if ( ! ( window as any ).__mioRecovery ) return ability.run( args, signal );
		const state = ( window as any ).__mioTurn;
		const meta = { ...state, signal, callId: crypto.randomUUID(), idempotencyKey: crypto.randomUUID(), effect: ability.effect };
		const result = await ability.run( args, signal, meta );
		const history = ability.history?.( { name, args, result, callId: meta.callId }, meta );
		if ( result.status === 'rejected' ) { state.validationFailures++; state.validationRemaining--; }
		const operation = { ...meta, ability: name, status: result.status, receipt: result.receipt };
		( window as any ).__lastMioOperation = operation;
		return { ...result, ...result.data, ...( history?.document ? { yaml: history.document.yaml } : {} ), operation };
	}, { name, args } );
}

for ( const recovery of [ false, true ] ) test( `native MIO ${ recovery ? 'recovery' : 'original' } API repairs YAML, creates and updates with WordPress validation`, async ( { page } ) => {
	// Capture the app's real registration while preserving the real shell lease.
	// The model is simulated; this test never calls a paid AI provider.
	await page.addInitScript( ( recovery ) => {
		( window as any ).__mioRecovery = recovery;
		const timer = setInterval( () => {
			const mio = ( window as any ).wp?.os?.mio;
			if ( ! mio?.registerWindow ) return;
			const register = mio.registerWindow.bind( mio );
			mio.registerWindow = ( id: string, context: any ) => {
				if ( context.title === 'AllTerrain Forms' ) ( window as any ).__formMio = context;
				return register( id, context );
			};
			clearInterval( timer );
		}, 0 );
	}, recovery );
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( process.env.ATF_E2E_USER || 'admin' );
	await page.getByLabel( 'Password', { exact: true } ).fill( process.env.ATF_E2E_PASSWORD || 'password' );
	await page.getByRole( 'button', { name: 'Log In', exact: true } ).click();
	await page.waitForURL( '**/wp-admin/**' );
	await page.goto( '/wp-admin/admin.php?page=allterrain-forms' );
	await page.waitForFunction( () => Boolean( ( window as any ).__formMio ) );
	const beginTurn = async () => {
		if ( ! recovery ) return;
		await page.evaluate( () => {
			const state = { turnId: crypto.randomUUID(), limits: { rounds: 8, calls: 16, validationFailures: 3, repeatedReads: 4 }, validationFailures: 0, validationRemaining: 3 };
			( window as any ).__mioTurn = state;
			( window as any ).__formMio.onTurnBegin( { ...state, signal: new AbortController().signal } );
		} );
	};
	await beginTurn();
	let createdId = 0;
	const title = `MIO E2E ${ Date.now() }`;
	const yaml = readFileSync( 'docs/examples/mio-conditional-contact.yaml', 'utf8' ).replace( /^title:.*$/m, `title: ${ title }` );
	try {
		const { editId } = await call( page, 'begin_form_edit', { mode: 'create' } );
		const first = await call( page, 'validate_form_yaml', { editId, yaml: yaml.replace( 'required: true', 'required: "true"' ) } );
		expect( first ).toMatchObject( { saved: false, retriesRemaining: 2 } );
		expect( first.errors[ 0 ].path ).toBe( '/schema/fields/0/required' );
		const second = await call( page, 'validate_form_yaml', { editId, yaml: yaml.replace( 'field: heard', 'field: nonexistent' ) } );
		expect( second ).toMatchObject( { saved: false, retriesRemaining: 1 } );
		expect( second.errors[ 0 ].message ).toContain( 'missing logic field' );
		const valid = await call( page, 'validate_form_yaml', { editId, yaml } );
		expect( valid.valid ).toBe( true );
		const created = await call( page, 'apply_form_edit', { editId, receipt: valid.receipt } );
		createdId = created.formId;
		expect( created ).toMatchObject( { saved: true, status: 'draft', title } );
		await expect( page.getByLabel( 'Form title', { exact: true } ) ).toHaveValue( title );
		if ( recovery ) {
			expect( created.operation ).toMatchObject( { effect: 'write', status: 'confirmed', receipt: expect.any( String ) } );
			const inspected = await page.evaluate( async () => {
				const context = ( window as any ).__formMio;
				const operation = ( window as any ).__lastMioOperation;
				const signal = new AbortController().signal;
				const status = await context.operationStatus( operation, signal );
				const [ action ] = context.responseActions( { messageId: 'test-reply', summary: { ...( window as any ).__mioTurn, status: 'completed', unknownWrites: 0 }, operations: [ operation ] } );
				if ( action.label !== 'Preview' ) throw new Error( 'Missing Preview action' );
				await action.run( { signal, messageId: 'test-reply', turnId: operation.turnId } );
				return status;
			} );
			expect( inspected ).toMatchObject( { effect: 'write', status: 'confirmed', receipt: created.operation.receipt } );
			await expect( page.getByRole( 'dialog', { name: `Preview: ${ title }`, exact: true } ) ).toBeVisible();
		}
		await beginTurn();
		const editing = await call( page, 'begin_form_edit', { mode: 'update' } );
		const updatedYaml = editing.yaml.replace( /^title:.*$/m, `title: ${ title } updated` );
		const checked = await call( page, 'validate_form_yaml', { editId: editing.editId, yaml: updatedYaml } );
		const updated = await call( page, 'apply_form_edit', { editId: editing.editId, receipt: checked.receipt } );
		expect( updated ).toMatchObject( { saved: true, formId: createdId, status: 'draft' } );
		await expect( page.getByLabel( 'Form title', { exact: true } ) ).toHaveValue( `${ title } updated` );
		const duplicate = await call( page, 'apply_form_edit', { editId: editing.editId, receipt: checked.receipt } );
		expect( duplicate ).toMatchObject( { saved: false, retryable: false } );
		await page.screenshot( { path: 'test-results/mio-form-editor.png' } );
		const previewUrl = await page.evaluate( async ( id ) => {
			const config = ( window as any ).allTerrainForms;
			const response = await fetch( `${ config.restUrl }/forms/${ id }`, { headers: { 'X-WP-Nonce': config.nonce } } );
			return ( await response.json() ).previewUrl;
		}, createdId );
		const preview = await page.context().newPage();
		try {
			await preview.goto( previewUrl );
			const heard = preview.locator( 'select[name="atf[heard]"]' );
			const details = preview.locator( 'textarea[name="atf[details]"]' );
			await expect( details ).toBeHidden();
			await heard.selectOption( 'other' );
			await expect( details ).toBeVisible();
			await expect( details ).toHaveAttribute( 'required', '' );
			await heard.selectOption( 'friend' );
			await expect( details ).toBeHidden();
		} finally { await preview.close(); }
	} finally {
		if ( createdId ) await page.evaluate( async ( id ) => {
			const config = ( window as any ).allTerrainForms;
			const response = await fetch( `${ config.restUrl }/forms/${ id }`, { method: 'DELETE', headers: { 'X-WP-Nonce': config.nonce } } );
			if ( ! response.ok ) throw new Error( await response.text() );
		}, createdId );
	}
} );

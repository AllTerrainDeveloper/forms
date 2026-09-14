import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

test( 'latest MIO chat repairs YAML and offers a working Preview button without another write', async ( { page } ) => {
	const title = `MIO chat E2E ${ Date.now() }`;
	const yaml = readFileSync( 'docs/examples/mio-conditional-contact.yaml', 'utf8' ).replace( /^title:.*$/m, `title: ${ title }` );
	let rounds = 0;
	let saves = 0;
	let createdId = 0;
	// Replace only the provider response. The real chat, session, Forms adapter,
	// validation, save and preview all run against Docker WordPress on port 8889.
	await page.route( /\/mio\/turn(?:\?|$)/, async ( route ) => {
		const history = JSON.parse( route.request().postDataJSON().transcript );
		const editId = history.outcomes.find( ( item: any ) => item.name === 'begin_form_edit' )?.result.editId;
		const answer = ( name: string, args: unknown ) => ( { message: '', calls: [ { name, arguments: JSON.stringify( args ) } ] } );
		let response;
		switch ( rounds++ ) {
			case 0: response = answer( 'begin_form_edit', { mode: 'create' } ); break;
			case 1: response = answer( 'validate_form_yaml', { editId, yaml: yaml.replace( 'required: true', 'required: "true"' ) } ); break;
			case 2:
				expect( history.outcomes.at( -1 ).result.errors[ 0 ].path ).toBe( '/schema/fields/0/required' );
				response = answer( 'validate_form_yaml', { editId, yaml: yaml.replace( 'field: heard', 'field: missing' ) } ); break;
			case 3:
				expect( history.outcomes.at( -1 ).result.errors[ 0 ].message ).toContain( 'missing logic field' );
				response = answer( 'validate_form_yaml', { editId, yaml } ); break;
			case 4: response = answer( 'apply_form_edit', { editId, receipt: history.outcomes.at( -1 ).result.receipt } ); break;
			default:
				createdId = history.outcomes.at( -1 ).result.data.formId;
				response = { message: 'Saved your contact form as a draft.', calls: [] };
		}
		await route.fulfill( { json: response } );
	} );
	page.on( 'request', ( request ) => {
		if ( request.method() === 'POST' && request.url().includes( '/assistant/apply' ) ) saves++;
	} );
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( process.env.ATF_E2E_USER || 'admin' );
	await page.getByLabel( 'Password', { exact: true } ).fill( process.env.ATF_E2E_PASSWORD || 'password' );
	await page.getByRole( 'button', { name: 'Log In', exact: true } ).click();
	await page.waitForURL( '**/wp-admin/**' );
	await page.goto( '/wp-admin/admin.php?page=allterrain-forms' );
	try {
		const editor = page.locator( '#wp-window-allterrain-forms' );
		await editor.getByRole( 'button', { name: 'Ask MIO', exact: true } ).click();
		const chat = page.getByRole( 'dialog', { name: 'Chat with MIO · AllTerrain Forms', exact: true } );
		const input = chat.getByRole( 'textbox', { name: 'Message MIO', exact: true } );
		await input.fill( 'Create a contact form with name, surname and how you heard about us.' );
		await input.press( 'Shift+Enter' );
		expect( rounds ).toBe( 0 );
		await input.press( 'End' );
		await input.press( 'Enter' );
		const previewButton = chat.getByRole( 'button', { name: 'Preview the saved form', exact: true } );
		await expect( previewButton ).toBeVisible( { timeout: 20000 } );
		await expect( input ).toHaveValue( '' );
		expect( rounds ).toBe( 6 );
		expect( saves ).toBe( 1 );
		await page.screenshot( { path: 'test-results/mio-chat-preview-action.png' } );
		await previewButton.click();
		const preview = page.getByRole( 'dialog', { name: `Preview: ${ title }`, exact: true } );
		await expect( preview ).toBeVisible();
		const rendered = preview.frameLocator( 'iframe' );
		await expect( rendered.locator( 'textarea[name="atf[details]"]' ) ).toBeHidden();
		await rendered.locator( 'select[name="atf[heard]"]' ).selectOption( 'other' );
		await expect( rendered.locator( 'textarea[name="atf[details]"]' ) ).toBeVisible();
		expect( rounds ).toBe( 6 );
		expect( saves ).toBe( 1 );
		// Navigation closes chat; reopening retains the action and Escape restores focus.
		await preview.getByRole( 'button', { name: 'Close', exact: true } ).click();
		const launcher = editor.getByRole( 'button', { name: 'Ask MIO', exact: true } );
		await launcher.click();
		await expect( previewButton ).toBeVisible();
		await input.press( 'Escape' );
		await expect( chat ).not.toBeVisible();
		await expect( launcher ).toBeFocused();
	} finally {
		if ( createdId ) await page.evaluate( async ( id ) => {
			const config = ( window as any ).allTerrainForms;
			const response = await fetch( `${ config.restUrl }/forms/${ id }`, { method: 'DELETE', headers: { 'X-WP-Nonce': config.nonce } } );
			if ( ! response.ok ) throw new Error( await response.text() );
		}, createdId );
	}
} );

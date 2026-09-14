import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { parsePackage, packageValidator } from '../../src/shared/form-package.mjs';
const contract = JSON.parse( readFileSync( new URL( '../../schemas/form-package-v1.schema.json', import.meta.url ), 'utf8' ) );

/** Real WordPress REST calls through the same cookie/nonce as the builder. */
async function api( page: Page, path: string, method = 'GET', body?: unknown ) {
	return page.evaluate( async ( { path, method, body } ) => {
		const config = ( window as any ).allTerrainForms;
		const response = await fetch( `${ config.restUrl }${ path }`, {
			method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: body === undefined ? undefined : JSON.stringify( body ),
		} );
		if ( ! response.ok ) throw new Error( await response.text() );
		return response.json();
	}, { path, method, body } );
}

/** The native file chooser belongs to a detached input, so use the browser event. */
async function choose( page: Page, label: string, file: { name: string; mimeType: string; buffer: Buffer } ) {
	const chooser = page.waitForEvent( 'filechooser' );
	await page.getByRole( 'button', { name: label, exact: true } ).click();
	await ( await chooser ).setFiles( file );
}

test( 'export YAML, validate without writes, import a styled draft, reject invalid input', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( process.env.ATF_E2E_USER || 'admin' );
	await page.getByLabel( 'Password', { exact: true } ).fill( process.env.ATF_E2E_PASSWORD || 'password' );
	await page.getByRole( 'button', { name: 'Log In', exact: true } ).click();
	await page.waitForURL( '**/wp-admin/**' );
	await page.goto( '/wp-admin/admin.php?page=allterrain-forms' );
	await page.waitForFunction( () => Boolean( ( window as any ).allTerrainForms?.nonce ) );
	const welcome = page.getByRole( 'button', { name: 'Got it', exact: true } );
	if ( await welcome.isVisible() ) await welcome.click();
	const title = `Package E2E ${ Date.now() }`;
	const created = await api( page, '/forms', 'POST', {
		title,
		schema: {
			fields: [ { id: 'name', type: 'text', label: 'Your name', required: true } ],
			settings: { theme: 'clean', themeOverrides: { accent: '#123456', 'radius-field': '9px' } },
		},
	} );
	const importedIds: number[] = [];
	const themeIds: number[] = [];
	try {
		await page.reload();
		await expect( page.getByLabel( 'Form title', { exact: true } ) ).toHaveValue( title );
		const downloadPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Export', exact: true } ).click();
		const download = await downloadPromise;
		expect( download.suggestedFilename() ).toMatch( /\.yaml$/ );
		const buffer = readFileSync( ( await download.path() )! );
		const document = parsePackage( buffer.toString() ) as any;
		packageValidator( contract )( document );
		expect( document.theme.tokens ).toEqual( {} );
		expect( document.theme.base ).toBe( 'clean' );
		expect( document.form.schema.settings.themeOverrides ).toEqual( { accent: '#123456', 'radius-field': '9px' } );
		const file = { name: 'form.yaml', mimeType: 'application/yaml', buffer };
		const before = await api( page, '/forms' );
		const validated = page.waitForResponse( ( response ) => response.url().includes( '/form-packages/validate' ) && response.status() === 200 );
		await choose( page, 'Validate YAML', file );
		await validated;
		await expect( page.getByRole( 'dialog', { name: 'Valid form package' } ) ).toBeVisible();
		await page.getByRole( 'dialog', { name: 'Valid form package', exact: true } ).getByRole( 'button', { name: 'Close', exact: true } ).click();
		expect( await api( page, '/forms' ) ).toHaveLength( before.length );
		const imported = page.waitForResponse( ( response ) => response.url().includes( '/form-packages/import' ) && response.request().method() === 'POST' );
		await choose( page, 'Import', file );
		const response = await imported;
		const result = await response.json();
		expect( response.status(), JSON.stringify( result ) ).toBe( 201 );
		importedIds.push( result.id );
		expect( result.status ).toBe( 'draft' );
		expect( result.id ).not.toBe( created.id );
		expect( result.schema.settings.themeOverrides ).toEqual( document.form.schema.settings.themeOverrides );
		const themes = await api( page, '/themes' );
		themeIds.push( themes.find( ( theme: any ) => theme.slug === result.schema.settings.theme ).id );
		await expect.poll( () => api( page, '/forms' ) ).toHaveLength( before.length + 1 );
		await expect( page.getByLabel( 'Form title', { exact: true } ) ).toHaveValue( title );
		await expect( page.getByRole( 'dialog', { name: 'Form imported as a draft' } ) ).toBeVisible();
		await page.getByRole( 'dialog', { name: 'Form imported as a draft', exact: true } ).getByRole( 'button', { name: 'Close', exact: true } ).click();
		await choose( page, 'Import', { ...file, buffer: Buffer.from( 'format: broken\nformat: duplicate' ) } );
		await expect( page.getByText( 'Could not import that file', { exact: true } ) ).toBeVisible();
		expect( await api( page, '/forms' ) ).toHaveLength( before.length + 1 );
	} finally {
		for ( const id of [ ...importedIds, created.id ] ) await api( page, `/forms/${ id }`, 'DELETE' );
		for ( const id of themeIds ) await api( page, `/themes/${ id }`, 'DELETE' );
	}
} );

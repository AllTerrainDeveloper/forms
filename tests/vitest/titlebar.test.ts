/**
 * The title-bar provider stays small (#57).
 *
 * OpenStation runs every title-bar provider script at boot, whether or not a
 * Forms window is ever opened. Registering the builder there made every station
 * load pay for the whole builder. These pin the provider to the small bundle and
 * prove the button still previews the form the builder has open.
 */

import { readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { providePreviewSource, registerPreviewButton } from '../../src/preview-button';

const root = resolve( __dirname, '../..' );

describe( 'the title-bar provider', () => {
	it( 'is the titlebar bundle, not the builder', () => {
		const php = readFileSync( resolve( root, 'includes/openstation.php' ), 'utf8' );
		const handles = [ ...php.matchAll( /'register_titlebar_button_script',\s*'([^']+)'/g ) ].map( ( m ) => m[ 1 ] );

		expect( handles ).toEqual( [ 'allterrain-forms-titlebar' ] );
	} );

	it( 'ships a bundle that does not carry the builder', () => {
		const bundle = resolve( root, 'assets/js/titlebar.min.js' );

		expect( statSync( bundle ).size ).toBeLessThan( 10 * 1024 );
		expect( readFileSync( bundle, 'utf8' ) ).not.toContain( 'atfb-palette' );
	} );
} );

describe( 'the preview button', () => {
	afterEach( () => {
		delete ( window as unknown as { wp?: unknown } ).wp;
		delete ( window as unknown as { allTerrainFormsPreviewSources?: unknown } ).allTerrainFormsPreviewSources;
	} );

	function stubShell() {
		let def: { onClick: () => void } | null = null;
		const open = vi.fn();

		( window as unknown as { wp: unknown } ).wp = {
			os: {
				registerTitleBarButton: ( d: { onClick: () => void } ) => {
					def = d;
				},
				windowManager: { open },
			},
		};

		return { click: () => def!.onClick(), open };
	}

	it( 'previews the form the most recent builder has open', async () => {
		const shell = stubShell();

		registerPreviewButton();
		shell.click();
		expect( shell.open ).not.toHaveBeenCalled();

		const withdraw = providePreviewSource( {
			current: () => ( { id: 7, title: 'Contact', previewUrl: 'https://example.test/?p=7' } ),
			isDirty: () => false,
			save: async () => {},
		} );

		shell.click();
		await Promise.resolve();
		expect( shell.open ).toHaveBeenCalledWith( expect.objectContaining( { id: 'allterrain-forms-preview-7' } ) );

		withdraw();
		shell.open.mockClear();
		shell.click();
		await Promise.resolve();
		expect( shell.open ).not.toHaveBeenCalled();
	} );

	it( 'shares the preview source with the separately built titlebar bundle', async () => {
		const shell = stubShell();
		const bundle = readFileSync( resolve( root, 'assets/js/titlebar.min.js' ), 'utf8' );

		new Function( 'window', bundle )( window );

		const withdraw = providePreviewSource( {
			current: () => ( { id: 9, title: 'Quote', previewUrl: 'https://example.test/?p=9' } ),
			isDirty: () => false,
			save: async () => {},
		} );

		shell.click();
		await Promise.resolve();
		expect( shell.open ).toHaveBeenCalledWith( expect.objectContaining( { id: 'allterrain-forms-preview-9' } ) );

		withdraw();
	} );
} );

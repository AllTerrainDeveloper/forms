/**
 * The dock tile's menu, and what is allowed into it.
 *
 * One row of this menu writes several hundred entries into whatever database the
 * site happens to be. It is meant to appear only when OpenStation's developer
 * mode is on, and "meant to" is not a thing a comment can enforce — the check is
 * three tokens long, it lives next to two rows that are gated on capabilities
 * instead, and inverting it or dropping half of it would look entirely normal in
 * a diff.
 *
 * So the menu is asserted directly, from both sides: the row is there when it
 * should be, and — the assertion that actually matters — absent in every case
 * where it should not be.
 *
 * This is a check on what is *shown*. It is not the security boundary: every
 * route these rows reach re-checks developer mode and a capability on the server,
 * and `tests/phpunit/tests/demoData.php` is where that is proven. A menu row is a
 * UI decision, and a UI decision is never a permission.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

/** What the tile registration looks like once the shell has received it. */
interface Row {
	title: string;
	windowId?: string;
}

/**
 * Loads the dock bundle against a given config and returns the menu it built.
 *
 * The module registers its tile as a side effect of being imported, and reads the
 * config once at module scope — so each case needs a fresh module registry rather
 * than a second call into an already-loaded one.
 *
 * @param config What `window.allTerrainForms` says.
 * @return The submenu rows, in order.
 */
async function menuFor( config: Record< string, unknown > ): Promise< Row[] > {
	vi.resetModules();

	const rows: Row[] = [];

	( window as unknown as { allTerrainForms: unknown } ).allTerrainForms = config;
	( window as unknown as { wp: unknown } ).wp = {
		os: {
			registerSystemTile: ( tile: { submenu?: Row[] } ) => {
				rows.push( ...( tile.submenu ?? [] ) );
			},
		},
	};

	await import( '../../src/dock' );

	return rows;
}

describe( 'the demo-data row', () => {
	beforeEach( () => {
		vi.resetModules();
	} );

	it( 'is offered when developer mode is on', async () => {
		const rows = await menuFor( { canEdit: true, canRead: true, devMode: true } );

		expect( rows.map( ( row ) => row.title ) ).toContain( 'Demo data' );
	} );

	it( 'is absent when developer mode is off', async () => {
		const rows = await menuFor( { canEdit: true, canRead: true, devMode: false } );

		expect( rows.map( ( row ) => row.title ) ).not.toContain( 'Demo data' );
	} );

	it( 'is absent when the flag is missing entirely', async () => {
		// Which is what an older shell, or no shell at all, produces. The row must
		// fail closed rather than treating "unknown" as "yes".
		const rows = await menuFor( { canEdit: true, canRead: true } );

		expect( rows.map( ( row ) => row.title ) ).not.toContain( 'Demo data' );
	} );

	it( 'is absent for somebody who cannot edit forms, developer mode or not', async () => {
		// Developer mode is a preference, stored in the user's own meta. Somebody
		// who may read entries but not build forms has no business seeding them,
		// and turning a preference on must not be the thing that decides it.
		const rows = await menuFor( { canEdit: false, canRead: true, devMode: true } );

		expect( rows.map( ( row ) => row.title ) ).not.toContain( 'Demo data' );
	} );

	it( 'comes last, after the ordinary destinations', async () => {
		// It writes to the database. It should not sit one hover away from
		// "Themes" on a site collecting real enquiries.
		const rows = await menuFor( { canEdit: true, canRead: true, devMode: true } );

		expect( rows[ rows.length - 1 ].title ).toBe( 'Demo data' );
	} );
} );

describe( 'the rest of the menu', () => {
	it( 'opens the builder from the first row', async () => {
		// A system tile has no landing page, so the shell runs the first row when
		// the tile's head is clicked. With anything else first, clicking
		// "AllTerrain Forms" opens something other than the forms.
		const rows = await menuFor( { canEdit: true, canRead: true, devMode: true } );

		expect( rows[ 0 ].windowId ).toBe( 'allterrain-forms' );
	} );

	it( 'offers analytics to somebody who may only read entries', async () => {
		const rows = await menuFor( { canEdit: false, canRead: true } );

		expect( rows.map( ( row ) => row.title ) ).toEqual( [ 'Form entries', 'Analytics' ] );
	} );

	it( 'offers nothing to somebody with neither capability', async () => {
		expect( await menuFor( {} ) ).toEqual( [] );
	} );
} );

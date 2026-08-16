/**
 * The dock tile's menu, and what is allowed into it.
 *
 * One row of this menu writes several hundred entries into whatever database the
 * site happens to be. It is meant to appear only when OpenStation's developer
 * mode is on, and "meant to" is not something a comment can enforce — the check
 * is three tokens long, it sits next to two rows gated on capabilities instead,
 * and inverting it or dropping half of it would look entirely normal in a diff.
 *
 * So the menu is asserted from both sides: the row is there when it should be,
 * and — the assertion that actually matters — absent in every case where it
 * should not be.
 *
 * This is a check on what is *shown*. It is not the security boundary: every
 * route these rows reach re-checks developer mode and a capability on the server,
 * and `tests/phpunit/tests/demoData.php` is where that is proven. A menu row is a
 * UI decision, and a UI decision is never a permission.
 */

import { describe, expect, it } from 'vitest';
import { submenuFor } from '../../src/dock';
import type { RuntimeConfig } from '../../src/types';

/**
 * A config with only the fields the menu reads.
 *
 * Cast rather than filled in: the tile reads three flags, and writing out a
 * whole `RuntimeConfig` for each case would bury which of the three a given test
 * is actually about.
 */
function config( flags: Partial< RuntimeConfig > ): RuntimeConfig {
	return flags as RuntimeConfig;
}

/** Just the titles, which is what the menu is. */
function titles( flags: Partial< RuntimeConfig > ): string[] {
	return submenuFor( config( flags ) ).map( ( row ) => row.title );
}

describe( 'the demo-data row', () => {
	it( 'is offered when developer mode is on', () => {
		expect( titles( { canEdit: true, canRead: true, devMode: true } ) ).toContain( 'Demo data' );
	} );

	it( 'is absent when developer mode is off', () => {
		expect( titles( { canEdit: true, canRead: true, devMode: false } ) ).not.toContain( 'Demo data' );
	} );

	it( 'is absent when the flag is missing entirely', () => {
		// Which is what an older shell, or no shell at all, produces. It has to
		// fail closed rather than treating "unknown" as "yes".
		expect( titles( { canEdit: true, canRead: true } ) ).not.toContain( 'Demo data' );
	} );

	it( 'is absent for somebody who cannot edit forms, developer mode or not', () => {
		// Developer mode is a preference, stored in the user's own meta. Somebody
		// who may read entries but not build forms has no business seeding them,
		// and turning a preference on must not be the thing that decides it.
		expect( titles( { canEdit: false, canRead: true, devMode: true } ) ).not.toContain( 'Demo data' );
	} );

	it( 'is absent when there is no config at all', () => {
		expect( submenuFor( undefined ) ).toEqual( [] );
	} );

	it( 'comes last, after the ordinary destinations', () => {
		// It writes to the database. It should not sit one hover away from
		// "Themes" on a site collecting real enquiries.
		const rows = titles( { canEdit: true, canRead: true, devMode: true } );

		expect( rows[ rows.length - 1 ] ).toBe( 'Demo data' );
	} );

	it( 'opens the analytics window, where the panel it wants lives', () => {
		const rows = submenuFor( config( { canEdit: true, canRead: true, devMode: true } ) );

		expect( rows[ rows.length - 1 ].windowId ).toBe( 'allterrain-forms-analytics' );
	} );
} );

describe( 'the rest of the menu', () => {
	it( 'opens the builder from the first row', () => {
		// A system tile has no landing page, so the shell runs the first row when
		// the tile's head is clicked. With anything else first, clicking
		// "AllTerrain Forms" opens something other than the forms.
		const rows = submenuFor( config( { canEdit: true, canRead: true, devMode: true } ) );

		expect( rows[ 0 ].windowId ).toBe( 'allterrain-forms' );
	} );

	it( 'offers analytics to somebody who may only read entries', () => {
		// Reading a report is reading entries. Gating it on editing forms would
		// deny it to exactly the people most likely to want it.
		expect( titles( { canEdit: false, canRead: true } ) ).toEqual( [ 'Form entries', 'Analytics' ] );
	} );

	it( 'offers nothing to somebody with neither capability', () => {
		expect( submenuFor( config( {} ) ) ).toEqual( [] );
	} );
} );

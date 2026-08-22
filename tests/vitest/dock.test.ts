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

import { afterEach, describe, expect, it, vi } from 'vitest';
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
		expect( titles( { canEdit: false, canRead: true } ) ).toEqual( [ 'Entries', 'Analytics' ] );
	} );

	it( 'offers nothing to somebody with neither capability', () => {
		expect( submenuFor( config( {} ) ) ).toEqual( [] );
	} );
} );

describe( 'the MailPoet row', () => {
	it( 'is offered to somebody who may edit forms', () => {
		// Present whether or not MailPoet is installed: the window it opens
		// either configures the integration or makes the case for it, and the
		// row is how anyone discovers either.
		expect( titles( { canEdit: true, canRead: true } ) ).toContain( 'MailPoet' );
	} );

	it( 'is absent for somebody who may only read entries', () => {
		// Connecting a form to a list is editing what the form does on submit.
		expect( titles( { canEdit: false, canRead: true } ) ).not.toContain( 'MailPoet' );
	} );

	it( 'opens the MailPoet window', () => {
		const row = submenuFor( config( { canEdit: true, canRead: true } ) ).find(
			( candidate ) => candidate.title === 'MailPoet'
		);

		expect( row?.windowId ).toBe( 'allterrain-forms-mailpoet' );
	} );
} );

describe( 'the import row', () => {
	const admin = { canEdit: true, canRead: true, adminUrl: 'http://example.com/wp-admin/' };
	const location = window.location;

	// The two tests below reach into globals the rest of the file leaves alone.
	afterEach( () => {
		( window as unknown as { wp?: unknown } ).wp = undefined;
		Object.defineProperty( window, 'location', { configurable: true, value: location } );
	} );

	it( 'is offered to somebody who may build forms', () => {
		expect( titles( admin ) ).toContain( 'Import forms' );
	} );

	it( 'points at the import page', () => {
		const row = submenuFor( config( admin ) ).find( ( candidate ) => candidate.title === 'Import forms' );

		expect( row?.url ).toBe( 'http://example.com/wp-admin/admin.php?page=allterrain-forms-import' );
		expect( row?.windowId ).toBe( 'allterrain-forms-import' );
	} );

	it( 'opens the page as a window in the shell', () => {
		// The bug this replaces: a row carrying only a URL is, to a *system*
		// tile's constellation, a link out — it has no menu behind it to route
		// a URL through, so its last resort is `window.open( url, '_blank' )`
		// and the import page opened in a browser tab, outside the desktop.
		const open = vi.fn();

		( window as unknown as { wp: unknown } ).wp = { os: { windowManager: { open } } };

		const row = submenuFor( config( admin ) ).find( ( candidate ) => candidate.title === 'Import forms' );

		expect( row?.onSelect ).toBeTypeOf( 'function' );

		row?.onSelect?.();

		expect( open ).toHaveBeenCalledWith(
			expect.objectContaining( {
				id: 'allterrain-forms-import',
				url: 'http://example.com/wp-admin/admin.php?page=allterrain-forms-import',
				title: 'AllTerrain Forms — Import',
			} )
		);
	} );

	it( 'navigates rather than dying when there is no shell to open a window', () => {
		// A pop-up the browser may block is not a fallback; the page is.
		const assign = vi.fn();

		( window as unknown as { wp?: unknown } ).wp = undefined;
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { assign },
		} );

		submenuFor( config( admin ) )
			.find( ( candidate ) => candidate.title === 'Import forms' )
			?.onSelect?.();

		expect( assign ).toHaveBeenCalledWith( 'http://example.com/wp-admin/admin.php?page=allterrain-forms-import' );
	} );

	it( 'is absent for somebody who may only read entries', () => {
		expect( titles( { canRead: true, adminUrl: 'http://example.com/wp-admin/' } ) ).not.toContain( 'Import forms' );
	} );

	it( 'is absent when there is no admin URL to point at', () => {
		// Rather than a row that opens `undefinedadmin.php`.
		expect( titles( { canEdit: true, canRead: true } ) ).not.toContain( 'Import forms' );
	} );
} );

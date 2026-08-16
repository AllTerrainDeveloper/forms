/**
 * Joining REST paths onto the two base URLs the config can carry.
 *
 * The bases come from `rest_url()` on the server, and their shape depends on
 * the site's permalink setting: pretty permalinks give a clean
 * `…/wp-json/allterrain-forms/v1`, plain permalinks give
 * `…/index.php?rest_route=/allterrain-forms/v1`. Several routes here carry
 * their own query string — the entries list, the export, analytics with a
 * dimension, the Core pages picker — and naive concatenation onto a plain
 * base produces a second `?`, which Core reads as part of the route and
 * answers with `rest_no_route`. Working on a plain-permalink site is not
 * optional: it is the setting WordPress installs with.
 */

import { describe, expect, it } from 'vitest';
import { joinPath } from '../../src/api';

const PRETTY = 'https://example.test/wp-json/allterrain-forms/v1';
const PLAIN = 'https://example.test/index.php?rest_route=/allterrain-forms/v1';

describe( 'on a pretty-permalink base', () => {
	it( 'passes a bare path through', () => {
		expect( joinPath( PRETTY, '/forms/3' ) ).toBe( `${ PRETTY }/forms/3` );
	} );

	it( 'passes a query-carrying path through', () => {
		expect( joinPath( PRETTY, '/entries?form_id=2&page=3' ) ).toBe( `${ PRETTY }/entries?form_id=2&page=3` );
	} );
} );

describe( 'on a plain-permalink base', () => {
	it( 'passes a bare path through', () => {
		expect( joinPath( PLAIN, '/forms/3' ) ).toBe( `${ PLAIN }/forms/3` );
	} );

	it( 'folds the path query into the base query', () => {
		expect( joinPath( PLAIN, '/entries?form_id=2&page=3' ) ).toBe( `${ PLAIN }/entries&form_id=2&page=3` );
	} );

	it( 'only converts the first ?, which is the only one a path may carry', () => {
		expect( joinPath( PLAIN, '/forms/1/analytics?dimension=how%3F' ) ).toBe(
			`${ PLAIN }/forms/1/analytics&dimension=how%3F`
		);
	} );

	it( 'handles the Core routes joined without a leading slash', () => {
		const base = 'https://example.test/index.php?rest_route=/';

		expect( joinPath( base, 'wp/v2/pages?per_page=100' ) ).toBe(
			'https://example.test/index.php?rest_route=/wp/v2/pages&per_page=100'
		);
	} );
} );

/**
 * The Array that ate the theme override.
 *
 * PHP serialises an empty `themeOverrides` map as `[]`, so the schema arrives
 * holding a real Array. Writing a token onto an Array is a stray expando that
 * `JSON.stringify` silently drops — the preview showed the override, the save
 * lost it, and the control snapped back when the saved schema was adopted.
 * These pin the repair at the API door.
 */

import { describe, expect, it } from 'vitest';
import { withObjectOverrides } from '../../src/api';
import type { Form } from '../../src/types';

const form = ( overrides: unknown ): Form =>
	( { id: 1, schema: { settings: { themeOverrides: overrides } } } ) as unknown as Form;

describe( 'withObjectOverrides', () => {
	it( 'turns the PHP empty array into a plain object', () => {
		const fixed = withObjectOverrides( form( [] ) );

		expect( Array.isArray( fixed.schema.settings.themeOverrides ) ).toBe( false );
		expect( fixed.schema.settings.themeOverrides ).toEqual( {} );
	} );

	it( 'a token written after the repair survives JSON.stringify', () => {
		const fixed = withObjectOverrides( form( [] ) );

		fixed.schema.settings.themeOverrides[ 'label-position' ] = 'floating';

		const wire = JSON.parse( JSON.stringify( fixed.schema.settings ) );

		expect( wire.themeOverrides[ 'label-position' ] ).toBe( 'floating' );
	} );

	it( 'a populated object passes through untouched', () => {
		const fixed = withObjectOverrides( form( { accent: '#ff0000' } ) );

		expect( fixed.schema.settings.themeOverrides ).toEqual( { accent: '#ff0000' } );
	} );

	it( 'a missing map becomes an empty object rather than staying undefined', () => {
		const fixed = withObjectOverrides( form( undefined ) );

		expect( fixed.schema.settings.themeOverrides ).toEqual( {} );
	} );
} );

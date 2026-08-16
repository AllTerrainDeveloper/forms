/**
 * Where the Theme tab puts its picker and its buttons.
 *
 * A layout assertion, which is unusual and deliberate. The picker strip scrolls
 * horizontally, so anything sharing its line silently takes width away from it
 * — and the symptom is not a broken build or a failing render, it is the last
 * theme card cut down the middle in a screenshot somebody has to notice. This
 * pins the one structural fact that prevents it: the actions live on the
 * heading's row, and the strip has the whole width of its own.
 */

import { describe, expect, it } from 'vitest';
import { mountThemeControls, type ThemeControlsOptions } from '../../src/theme-studio';
import type { Theme, ThemeToken } from '../../src/types';

/** The two tokens the quick dials need to render at all. */
const TOKENS: ThemeToken[] = [
	{ key: 'color-accent', label: 'Accent', group: 'Colour', type: 'color', default: '#2271b1' },
	{ key: 'radius-field', label: 'Field radius', group: 'Shape', type: 'text', default: '6px' },
] as unknown as ThemeToken[];

const THEMES: Theme[] = [ 'clean', 'midnight', 'glass' ].map( ( slug, index ) => ( {
	slug,
	label: slug,
	description: '',
	custom: false,
	dark: false,
	id: index + 1,
	tokens: { 'color-accent': '#2271b1' },
	// The chip is painted from these, so a theme without them cannot render.
	resolved: {
		surface: '#fff',
		border: '#ccc',
		'radius-field': '6px',
		'color-accent': '#2271b1',
	},
} ) );

/** Mounts a studio with every callback stubbed. */
function mount( extra: Partial< ThemeControlsOptions > = {} ): HTMLElement {
	return mountThemeControls( {
		themes: THEMES,
		tokens: TOKENS,
		activeSlug: 'clean',
		overrides: {},
		onTheme: () => undefined,
		onOverride: () => undefined,
		previewFor: () => Promise.resolve( '<form></form>' ),
		onThemesChanged: () => undefined,
		...extra,
	} );
}

describe( 'the Theme tab’s top region', () => {
	it( 'puts the actions on the heading’s row', () => {
		const topbar = mount().querySelector( '.atf-studio__topbar' );

		expect( topbar ).not.toBeNull();
		expect( topbar?.querySelector( '.atf-studio__heading' ) ).not.toBeNull();
		expect( topbar?.querySelector( '.atfs__actions' ) ).not.toBeNull();
	} );

	it( 'gives the picker strip a row to itself', () => {
		// Not "the strip exists" — the strip must not share a parent with the
		// buttons, because that parent is a flex row and sharing it is exactly
		// what clipped the last chip.
		const strip = mount().querySelector( '.atfs-themes' );

		expect( strip?.parentElement?.className ).toBe( 'atf-studio__top' );
		expect( strip?.parentElement?.querySelector( ':scope > .atfs__actions' ) ).toBeNull();
	} );

	it( 'keeps every theme in the strip rather than dropping the overflow', () => {
		expect( mount().querySelectorAll( '.atfs-theme' ) ).toHaveLength( THEMES.length );
	} );
} );

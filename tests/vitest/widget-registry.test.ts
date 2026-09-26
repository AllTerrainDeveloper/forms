/**
 * OpenStation resolves widget callbacks by ID after loading the bundle. A
 * working standalone export does not help if that global registration is absent.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

type Registry = Record< string, ( host: HTMLElement ) => unknown >;
type RegistryWindow = Window & {
	openStationWidgets?: Registry;
	desktopModeWidgets?: Registry;
};

const host = window as RegistryWindow;

afterEach( () => {
	delete host.openStationWidgets;
	delete host.desktopModeWidgets;
	vi.resetModules();
} );

describe( 'Recent submissions widget registration', () => {
	it( 'adds its mount callback without removing another OpenStation widget', async () => {
		const other = vi.fn();
		const registry: Registry = { 'other/widget': other };
		host.openStationWidgets = registry;

		const widget = await import( '../../src/widget' );

		expect( host.openStationWidgets ).toBe( registry );
		expect( host.openStationWidgets[ 'other/widget' ] ).toBe( other );
		expect( host.openStationWidgets[ 'allterrain-forms/recent' ] ).toBe( widget.renderWidget );
		expect( widget.render ).toBe( widget.renderWidget );
		expect( host.desktopModeWidgets ).toBe( registry );
	} );

	it( 'reuses the legacy registry when OpenStation has not created one', async () => {
		const registry: Registry = { 'legacy/widget': vi.fn() };
		host.desktopModeWidgets = registry;

		const widget = await import( '../../src/widget' );

		expect( host.openStationWidgets ).toBe( registry );
		expect( host.desktopModeWidgets[ 'legacy/widget' ] ).toBeDefined();
		expect( host.desktopModeWidgets[ 'allterrain-forms/recent' ] ).toBe( widget.renderWidget );
	} );
} );

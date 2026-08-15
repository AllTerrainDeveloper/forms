/**
 * The dock tile.
 *
 * One tile, three destinations. OpenStation fans a system tile's `submenu` out
 * of the rail on hover — the shell calls it the constellation — so the plugin
 * occupies a single place in the dock rather than three tiles competing for the
 * same corner of the user's attention.
 *
 * Its own bundle, and a deliberately tiny one. The tile has to be registered at
 * boot for every user who can reach it, and loading the builder (43KB) to draw a
 * dock icon would make everyone pay for a window most of them will not open in a
 * given session. This file is a couple of hundred bytes of registration and
 * nothing else; the windows load their own bundles when they open.
 */

interface SubmenuRow {
	title: string;
	url: string;
	onSelect?: () => void;
	windowId?: string;
}

interface SystemTile {
	id: string;
	title: string;
	icon: string;
	order?: number;
	onOpen: () => void;
	isOpen?: () => boolean;
	submenu?: SubmenuRow[];
}

interface ShellDock {
	ready?: ( cb: () => void ) => void;
	whenReady?: ( cb: () => void ) => void;
	registerSystemTile?: ( item: SystemTile ) => void;
	openWindow?: ( id: string, opts?: { source?: string } ) => boolean;
	windowManager?: { getById?: ( id: string ) => unknown };
}

interface RuntimeConfig {
	canEdit?: boolean;
	canRead?: boolean;
	devMode?: boolean;
}

const config: RuntimeConfig | undefined = ( window as unknown as { allTerrainForms?: RuntimeConfig } ).allTerrainForms;

/** The windows this tile can reach. */
const BUILDER = 'allterrain-forms';
const ENTRIES = 'allterrain-forms-entries';
const THEMES = 'allterrain-forms-themes';
const ANALYTICS = 'allterrain-forms-analytics';

/** Opens a window through the shell. */
function open( id: string ): void {
	shell()?.openWindow?.( id, { source: 'dock' } );
}

/** The shell, if there is one on this page. */
function shell(): ShellDock | null {
	return ( window as unknown as { wp?: { os?: ShellDock } } ).wp?.os ?? null;
}

/** Registers the tile. */
function registerTile(): void {
	const os = shell();

	if ( ! os?.registerSystemTile ) {
		return;
	}

	// Rows are gated the same way the windows are, so somebody who may read
	// entries but not edit forms gets a menu with only the row they can use —
	// rather than one that opens a window refusing them.
	const submenu: SubmenuRow[] = [];

	// The builder goes first, and that ordering is load-bearing.
	//
	// A system tile has no landing page, so the shell runs the **first submenu
	// row** when its head is clicked. With entries first, clicking "AllTerrain
	// Forms" opened Form entries — the tile did not do what its own name said.
	// Putting the builder at the top makes the head and the first row agree,
	// which is the pattern the shell is built around.
	if ( config?.canEdit ) {
		submenu.push( {
			title: 'Forms',
			url: '',
			onSelect: () => open( BUILDER ),
			windowId: BUILDER,
		} );
	}

	if ( config?.canRead ) {
		submenu.push( {
			title: 'Form entries',
			url: '',
			onSelect: () => open( ENTRIES ),
			// Declaring the window lets the flyout list this row under
			// "Open windows" when it already is, instead of offering to open a
			// second copy.
			windowId: ENTRIES,
		} );
	}

	if ( config?.canRead ) {
		submenu.push( {
			title: 'Analytics',
			url: '',
			onSelect: () => open( ANALYTICS ),
			windowId: ANALYTICS,
		} );
	}

	if ( config?.canEdit ) {
		submenu.push( {
			title: 'Themes',
			url: '',
			onSelect: () => open( THEMES ),
			windowId: THEMES,
		} );
	}

	// Developer mode only, and last — it writes several hundred entries into the
	// database, which is not something to leave one hover away from "Themes" on a
	// site that is collecting real enquiries.
	//
	// Gated on the config flag rather than by asking the shell, because the row
	// has to be decided at registration time and the answer is already in the
	// blob every bundle gets. The server checks the same preference again, and a
	// capability besides, on every route this row can reach: a submenu that is
	// merely absent is a UI decision, not a security one.
	if ( config?.canEdit && config?.devMode ) {
		submenu.push( {
			title: 'Demo data',
			url: '',
			onSelect: () => {
				open( ANALYTICS );
				// The analytics window listens for this and scrolls its developer
				// panel into view, so the row lands somewhere that answers it
				// rather than at the top of a report.
				document.dispatchEvent( new CustomEvent( 'atf-open-demo-panel' ) );
			},
			windowId: ANALYTICS,
		} );
	}


	try {
		os.registerSystemTile( {
			id: 'allterrain-forms',
			title: 'AllTerrain Forms',
			icon: 'dashicons-feedback',
			// Ahead of the shell's own trailing cluster, which starts at 10.
			order: 5,
			// The flyout is a hover gesture and never fans out for keyboard or
			// touch, so the tile's own activation has to go somewhere useful:
			// the builder, which is what the tile is named after.
			onOpen: () => open( config?.canEdit ? BUILDER : ENTRIES ),
			isOpen: () =>
				Boolean(
					os.windowManager?.getById?.( BUILDER ) ||
						os.windowManager?.getById?.( ENTRIES ) ||
						os.windowManager?.getById?.( THEMES )
				),
			submenu,
		} );
	} catch {
		// `registerSystemTile` throws a RegistrationError on a shell whose
		// validation differs from the one this was written against. A missing
		// tile costs a shortcut; the windows are still reachable from the
		// command palette and the admin menu.
	}
}

/**
 * Registers as soon as there is a shell to register with.
 *
 * Script order is not guaranteed: this bundle can load before the shell has
 * defined `wp.os`, and reading it once at module load then giving up is how the
 * tile silently never appears — which is exactly what happened. `os-init` is the
 * shell's own "I am ready" event, so the fallback is a real signal rather than a
 * timeout.
 *
 * @return True when registration was arranged.
 */
function boot(): boolean {
	const os = shell();

	if ( os?.ready ) {
		os.ready( registerTile );

		return true;
	}

	if ( os?.whenReady ) {
		os.whenReady( registerTile );

		return true;
	}

	if ( os?.registerSystemTile ) {
		registerTile();

		return true;
	}

	return false;
}

if ( ! boot() ) {
	document.addEventListener( 'os-init', () => void boot(), { once: true } );
}

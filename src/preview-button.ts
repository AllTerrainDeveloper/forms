/**
 * The eye in the title bar.
 *
 * OpenStation has a convention for this: a window that has something to show on
 * the front end carries a **Preview** button — an eye, on the right of the title
 * bar, just before Related — and pressing it opens the front end *as its own
 * window*, paired with the editor rather than replacing it. The shell does this
 * for post and page edit screens. A form is exactly the same shape of thing, so
 * it wears the same affordance rather than inventing a different one.
 *
 * Pairing beats a modal for a reason that only shows up once you use it: the
 * builder stays open and usable beside the preview. You can drag a field, watch
 * the preview refresh, and drag another — where a modal makes you close it,
 * change one thing, and open it again.
 *
 * All of it degrades. Without a shell there is no title bar to put a button in,
 * so `register()` returns a teardown that does nothing and the builder's own
 * Preview button opens the same URL in a tab.
 */

import type { ShellApi } from './types';

/** The shell, if there is one. */
function shell(): ShellApi | null {
	return ( window as unknown as { wp?: { os?: ShellApi } } ).wp?.os ?? null;
}

/** The shell's title-bar registry, which is Experimental and may be absent. */
interface TitleBarApi {
	registerTitleBarButton?: ( def: Record< string, unknown > ) => void;
	unregisterTitleBarButton?: ( id: string ) => void;
	ready?: ( cb: () => void ) => void;
	windowManager?: {
		open?: ( config: { id: string; baseId?: string; url: string; title: string; icon?: string } ) => unknown;
	};
}

/** The id the button and its paired window are registered under. */
const BUTTON_ID = 'allterrain-forms/preview';
const PREVIEW_WINDOW_ID = 'allterrain-forms-preview';

/** What the builder tells this module about the form currently open. */
export interface PreviewSource {
	/** The form being edited, or null when none is. */
	current(): { id: number; title: string; previewUrl: string } | null;
	/** True when there are unsaved changes, so the button can offer to save first. */
	isDirty(): boolean;
	/** Saves, so the preview shows what is on screen rather than what was stored. */
	save(): Promise< void >;
}

/**
 * Adds the eye to the builder window's title bar.
 *
 * Returns a teardown. Safe to call with no shell present — it registers nothing
 * and the teardown is a no-op.
 */
export function registerPreviewButton( source: PreviewSource ): () => void {
	const os = shell() as ( ShellApi & TitleBarApi ) | null;

	if ( ! os?.registerTitleBarButton ) {
		return () => {};
	}

	const register = () => {
		try {
			os.registerTitleBarButton!( {
				id: BUTTON_ID,
				label: 'Preview this form',
				icon: 'dashicons-visibility',
				placement: 'right',
				// Just before the shell's own Related button, so the builder's
				// eye lands where every other window's eye is.
				order: 90,
				// Only the builder window. The predicate is called against a live
				// `Window`, and a throw counts as "does not match" — so a shell
				// whose `Window` shape differs simply does not show the button
				// rather than erroring on every repaint.
				match: ( window: { id?: string; config?: { id?: string } } ) => {
					const id = window?.id ?? window?.config?.id ?? '';

					return id === 'allterrain-forms' || id.startsWith( 'allterrain-forms#' );
				},
				onClick: () => void openPreview( source ),
				owner: 'allterrain-forms-builder',
			} );
		} catch {
			// `registerTitleBarButton` throws a RegistrationError on a shell
			// whose validation differs from the one this was written against.
			// A missing button is a missing convenience, not a broken builder.
		}
	};

	if ( os.ready ) {
		os.ready( register );
	} else {
		register();
	}

	return () => {
		try {
			os.unregisterTitleBarButton?.( BUTTON_ID );
		} catch {
			// Unregistering is documented as idempotent; a shell that disagrees
			// is not worth taking the teardown down over.
		}
	};
}

/**
 * Opens — or refreshes — the paired preview window.
 *
 * Unsaved work is saved first. The preview is a real front-end render of the
 * *stored* form, so previewing without saving would quietly show the last saved
 * version and look like the builder had lost the edit.
 */
export async function openPreview( source: PreviewSource ): Promise< void > {
	if ( source.isDirty() ) {
		await source.save();
	}

	const form = source.current();

	if ( ! form ) {
		return;
	}

	openPreviewWindow( form.id, form.title, form.previewUrl );
}

/**
 * Opens the preview URL as a desktop window, or a browser tab without a shell.
 *
 * The window id is per form, so previewing two forms gives two windows rather
 * than one that keeps changing under you — and previewing the *same* form twice
 * reuses its window, which is what makes the refresh-on-save behaviour possible.
 */
export function openPreviewWindow( formId: number, title: string, url: string ): void {
	const os = shell() as ( ShellApi & TitleBarApi ) | null;

	if ( ! os?.windowManager?.open ) {
		window.open( url, '_blank', 'noopener' );

		return;
	}

	os.windowManager.open( {
		id: `${ PREVIEW_WINDOW_ID }-${ formId }`,
		baseId: PREVIEW_WINDOW_ID,
		url,
		title: `Preview: ${ title }`,
		icon: 'dashicons-visibility',
	} );
}

/**
 * Reloads an open preview window, if one is open for this form.
 *
 * Called after a save. Re-opening the same window id is how the shell's own
 * editor-preview pairing refreshes: the window manager reuses the instance and
 * navigates it, rather than stacking a second copy on top.
 *
 * A cache-busting parameter is added because the preview is a normal front-end
 * URL and a browser that has just loaded it will happily serve it again from
 * memory — which would make Save look as though it had done nothing.
 */
export function refreshPreview( formId: number, title: string, url: string ): void {
	const os = shell() as ( ShellApi & TitleBarApi ) | null;

	if ( ! os?.windowManager?.open ) {
		return;
	}

	const open = document.querySelector( `[data-window-id^="${ PREVIEW_WINDOW_ID }-${ formId }"]` );

	if ( ! open ) {
		return;
	}

	const separator = url.includes( '?' ) ? '&' : '?';

	openPreviewWindow( formId, title, `${ url }${ separator }atf_r=${ Date.now() }` );
}

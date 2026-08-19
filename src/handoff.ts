/**
 * The admin-page → native-window handoff.
 *
 * With the shell up, all three of this plugin's surfaces exist as native
 * windows, and the matching admin URLs must not become a second copy of the same
 * tool. The admin page therefore renders a pointer instead of the tool, and this
 * takes the pointer the rest of the way.
 *
 * It lives in its own module because it has to run on **every** page that can
 * emit a pointer, and those pages do not all load the same bundle. It used to
 * live in `builder.ts`, so the entries admin page — which loads the entries
 * bundle — shipped a pointer with nothing to act on it. Anything that reached
 * that URL, including the title bar's Related menu, got a window containing a
 * paragraph and a button. That is worse than either alternative: it is not the
 * tool, and it is not obviously a step on the way to it.
 *
 * Reaching the URL from a Related item is the case worth keeping in mind. The
 * shell can only express a related item as a URL, and it opens a URL as a
 * chromeless iframe window — there is no way for a native window to claim one.
 * So the iframe window opens, this runs inside it, the native window comes up,
 * and the iframe window closes itself. The visible result is the right window
 * appearing, which is what was asked for.
 */

/** The slice of the shell this needs. Everything optional — there may be none. */
interface ShellHost {
	wp?: {
		os?: {
			openWindow?: ( id: string, opts?: { source?: string; params?: Record< string, string | number | boolean > } ) => boolean;
			windowManager?: {
				getAll?: () => Array< {
					id: string;
					element?: HTMLElement;
					iframe?: HTMLIFrameElement;
					close?: () => void;
				} >;
				remove?: ( id: string ) => void;
			};
		};
	};
}

/**
 * Every document that might be hosting the shell, nearest first.
 *
 * On the desktop page itself that is this window; inside a chromeless iframe
 * window it is the parent. Both are checked rather than guessed at, because the
 * same admin URL is reached both ways.
 */
function hosts(): ShellHost[] {
	const found: ShellHost[] = [ window as unknown as ShellHost ];

	try {
		if ( window.parent && window.parent !== window ) {
			found.push( window.parent as unknown as ShellHost );
		}
	} catch {
		// A cross-origin parent cannot be reached, and is not our shell.
	}

	return found;
}

/**
 * The id of the shell window this document is the iframe of.
 *
 * Found by identity — the window whose iframe's `contentWindow` is this one —
 * rather than by matching URLs. The shell rewrites a window's src as it
 * navigates (chromeless flags, cache-busters), so a string comparison against
 * `location.href` misses exactly when it matters.
 */
function ownWindow( host: ShellHost ): { id: string; close?: () => void } | null {
	const all = host.wp?.os?.windowManager?.getAll?.() ?? [];

	for ( const win of all ) {
		const frame = win.iframe ?? win.element?.querySelector?.( 'iframe' );

		if ( frame && ( frame as HTMLIFrameElement ).contentWindow === window ) {
			return win;
		}
	}

	return null;
}

/** How long to keep looking for the window this document is inside. */
const ATTACH_TIMEOUT_MS = 4000;

/** How often to look, while waiting. */
const ATTACH_POLL_MS = 100;

/**
 * Closes the window this document is the iframe of, once it can be found.
 *
 * Polled rather than done once, because the shell finishes wiring a window's
 * iframe to its `Window` object *after* the iframe's own scripts have run. A
 * single attempt at handoff time therefore finds nothing, reports nothing, and
 * leaves the redundant window on screen next to the one it just opened — which
 * is the original complaint with an extra step.
 *
 * @param host     The document hosting the shell.
 * @param openedId The window that was opened instead, so this cannot close it.
 */
function closeOwnWindow( host: ShellHost, openedId: string ): void {
	const deadline = Date.now() + ATTACH_TIMEOUT_MS;

	const attempt = () => {
		const own = ownWindow( host );

		if ( own && own.id !== openedId ) {
			// `close()` when the window has one, so the shell runs its own
			// teardown — transition, session bookkeeping, dock indicator — rather
			// than having the element pulled out from under it.
			if ( typeof own.close === 'function' ) {
				own.close();
			} else {
				host.wp?.os?.windowManager?.remove?.( own.id );
			}

			return;
		}

		if ( ! own && Date.now() < deadline ) {
			window.setTimeout( attempt, ATTACH_POLL_MS );
		}
	};

	attempt();
}

/**
 * Opens the native window this page stands in for, and gets out of the way.
 *
 * Safe to call with no shell: there is then no parent to ask, the pointer's
 * button stays as the only route, and that is the correct behaviour rather than
 * a fallback.
 */
export function handOffToWindow(): void {
	const pointer = document.querySelector< HTMLElement >( '[data-atf-handoff]' );

	if ( ! pointer ) {
		return;
	}

	const id = pointer.getAttribute( 'data-atf-handoff' ) ?? '';

	if ( ! id ) {
		return;
	}

	// A form id in the URL is the whole point of a Related item: "entries **for
	// this form**". Passed as an open-time param, which the shell persists with
	// the session — so the window comes back on the same form after a reload
	// rather than silently reverting to its default.
	const form = Number( new URLSearchParams( window.location.search ).get( 'form' ) ) || 0;
	const params = form > 0 ? { form } : undefined;

	if ( form > 0 ) {
		rememberRequestedForm( form );
	}

	for ( const host of hosts() ) {
		if ( ! host.wp?.os?.openWindow?.( id, { source: 'handoff', params } ) ) {
			continue;
		}

		// Close the window this page is in, if it is in one. Deferred: the shell is
		// mid-open on the native window, and tearing down the iframe that asked for
		// it inside the same task removes the caller's own frame underneath it.
		window.setTimeout( () => closeOwnWindow( host, id ), 0 );

		return;
	}
}

/**
 * The pointer's "Open the window" button.
 *
 * Still here, and still the only route when the automatic handoff cannot run —
 * no shell, or a shell whose `openWindow` refused. Delegated from the document
 * so it works whichever page emitted the pointer.
 */
export function watchHandoffButton(): void {
	document.addEventListener( 'click', ( event ) => {
		const button = ( event.target as HTMLElement )?.closest?.< HTMLElement >( '[data-atf-open-window]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const id = button.getAttribute( 'data-atf-open-window' ) ?? '';

		for ( const host of hosts() ) {
			if ( host.wp?.os?.openWindow?.( id, { source: 'handoff' } ) ) {
				return;
			}
		}
	} );
}

/**
 * Where a handoff leaves the form it was asked about.
 *
 * The native window opens asynchronously and has no way to read the URL that
 * caused it — a native window's script is handed a body, not a request. Session
 * storage is the smallest thing that bridges the two: both documents are this
 * site, it survives the window opening a moment later, and it does not survive
 * the browser session, which is right for "the thing you just clicked".
 */
const REQUESTED_FORM_KEY = 'allterrain-forms/requested-form';

/** Remembers which form a handoff was about, for the window about to open. */
export function rememberRequestedForm( formId: number ): void {
	try {
		window.sessionStorage.setItem( REQUESTED_FORM_KEY, String( formId ) );
	} catch {
		// Private mode, or storage disabled. The window opens on its default,
		// which is a worse answer but not a broken one.
	}
}

/**
 * The form a handoff asked for, if one did. Reading it consumes it.
 *
 * Consumed rather than left in place so that opening the window again later —
 * from the dock, from the command palette — does not silently reopen it on a
 * form somebody chose ten minutes ago.
 */
/**
 * The per-surface spelling of the same store, for deep links that know which
 * window they are aimed at. WP Explorer's action row opens three different
 * windows off one selection; a single shared key would let the entries window
 * consume a form that was remembered for the report.
 *
 * @param surface One of `builder`, `entries`, `analytics`.
 */
function requestedFormKeyFor( surface: string ): string {
	return `allterrain-forms/requested-form-${ surface }`;
}

/** Remembers which form a deep link was about, for one specific window. */
export function rememberFormFor( surface: string, formId: number ): void {
	try {
		window.sessionStorage.setItem( requestedFormKeyFor( surface ), String( formId ) );
	} catch {
		// Storage unavailable; the warm-window event path still works.
	}
}

/** The form a deep link asked one window for. Reading it consumes it. */
export function takeFormFor( surface: string ): number {
	try {
		const value = window.sessionStorage.getItem( requestedFormKeyFor( surface ) );

		window.sessionStorage.removeItem( requestedFormKeyFor( surface ) );

		return Number( value ) || 0;
	} catch {
		return 0;
	}
}

/**
 * Opens one of this plugin's windows on a specific form.
 *
 * Both halves of the race are covered: the remembered form is consumed by the
 * window's own start() when it mounts cold, and the event reaches a window
 * that is already alive. Neither path waits on a timer, because a deep link
 * that lands on the wrong form for a split second reads as a bug even after
 * it corrects itself.
 *
 * @param windowId The native window id.
 * @param surface  Its store surface: `builder`, `entries`, `analytics`.
 * @param event    The CustomEvent name its module listens on.
 * @param formId   The form.
 */
export function openWindowOnForm( windowId: string, surface: string, event: string, formId: number ): void {
	rememberFormFor( surface, formId );

	( window as unknown as { wp?: { os?: { openWindow?: ( id: string, opts?: object ) => boolean } } } ).wp?.os?.openWindow?.(
		windowId,
		{ source: 'wp-explorer' }
	);

	document.dispatchEvent( new CustomEvent( event, { detail: { formId } } ) );
}

export function takeRequestedForm(): number {
	try {
		const value = window.sessionStorage.getItem( REQUESTED_FORM_KEY );

		window.sessionStorage.removeItem( REQUESTED_FORM_KEY );

		return Number( value ) || 0;
	} catch {
		return 0;
	}
}

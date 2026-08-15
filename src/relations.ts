/**
 * Where each window sits in the graph.
 *
 * OpenStation keeps a per-window *content identity* — what this window is
 * showing, and what that thing belongs to. From those it derives groups and
 * draws visible ties between the windows on the desktop, and it fills the
 * title bar's **Related** menu.
 *
 * The shape here is the one the data already has:
 *
 *     form  ──────────────── root
 *       └── entry ────────── child of the form it was submitted to
 *             ├── user ───── who submitted it, as a reference
 *             └── media ──── what they attached, as a reference
 *
 * A form is a root because it is the thing that outlives everything else: an
 * entry only means something in the context of the form that asked the
 * questions. Making the *entry* the root instead would put every submission in
 * a group of its own and the desktop would draw no ties at all.
 *
 * The user is a `links` reference rather than a second root, deliberately. A
 * person is not "part of" a form — they submitted to it — and a root is a
 * containment claim. Reference edges are exactly the weaker statement: "this
 * points at that".
 *
 * Everything is optional-chained and every call is wrapped. `relations` is
 * Experimental in the shell, and a plugin that hard-depends on an experimental
 * API is a plugin that breaks on somebody else's release day.
 */

/** The identity record the shell stores per window. */
export interface ContentRef {
	type: string;
	id: number | string;
	root?: { type: string; id: number | string };
	links?: Array< { type: string; id: number | string; rel?: 'references' | 'child' } >;
	label?: string;
	/**
	 * Rows for the title bar's Related menu.
	 *
	 * `id` is required and must be a **string**, unique within the list — the
	 * shell rejects the whole identity otherwise, with
	 * `WindowContentRef registration rejected — fields: related`. There is no
	 * `type` field here; that belongs to `links`, which is a different thing.
	 */
	related?: Array< {
		id: string;
		label: string;
		url: string;
		group?: string;
		groupLabel?: string;
		icon?: string;
		count?: number;
	} >;
}

interface RelationsApi {
	set?: ( windowId: string, ref: ContentRef | null ) => void;
	get?: ( windowId: string ) => ContentRef | undefined;
	related?: ( windowId: string ) => string[];
	subscribe?: ( cb: () => void ) => () => void;
}

interface ShellRelations {
	relations?: RelationsApi;
	windowManager?: unknown;
}

/**
 * The object types this plugin puts into the graph.
 *
 * Namespaced, because the type space is shared with every other plugin and
 * `form` is a word several of them will want. The shell requires
 * `/^[a-z0-9_/-]+$/`.
 */
export const FORM_TYPE = 'allterrain-forms/form';
export const ENTRY_TYPE = 'allterrain-forms/entry';
export const THEME_TYPE = 'allterrain-forms/theme';

/** The shell's relations API, when the running shell has one. */
function relations(): RelationsApi | null {
	const os = ( window as unknown as { wp?: { os?: ShellRelations } } ).wp?.os;

	return os?.relations ?? null;
}

/**
 * The id of the window a given element is inside.
 *
 * Read from the DOM rather than threaded through every constructor, because a
 * native window's script is handed its body and never told which window that
 * body belongs to. Returns null on a plain admin page, where there is no window
 * and nothing to relate.
 */
export function windowIdOf( element: HTMLElement ): string | null {
	const host = element.closest< HTMLElement >( '[data-window-id], .os-window' );

	if ( ! host ) {
		return null;
	}

	// Some shells stamp the id as an attribute; the current one carries it only
	// as the element's `id`, prefixed — `wp-window-allterrain-forms` for the
	// window `relations` knows as `allterrain-forms`. Reading the attribute
	// alone silently found nothing, and an identity that is never set draws no
	// ties and reports no error, which is the quietest possible failure.
	const attribute = host.getAttribute( 'data-window-id' );

	if ( attribute ) {
		return attribute;
	}

	const id = host.id ?? '';

	return id ? id.replace( /^wp-window-/, '' ) : null;
}

/**
 * How many frames to keep looking for the window an element belongs to.
 *
 * The shell clones a native window's template and runs its script while the
 * window is still `os-window--opening` — the body is not yet in the document, so
 * `closest( '.os-window' )` finds nothing and the very first announcement lands
 * nowhere. It is not an error and nothing reports it: the window simply never
 * gets an identity and never draws a tie.
 *
 * Twenty frames is a third of a second at 60Hz, comfortably past the attach and
 * short enough that a genuinely window-less mount (the plain admin page) stops
 * asking almost immediately.
 */
const ATTACH_TIMEOUT_MS = 6000;

/** How often to look, while waiting for the window to attach. */
const ATTACH_POLL_MS = 120;

/**
 * Sets a window's identity, or clears it. Safe with no shell.
 *
 * Retries while the window is still opening, because the first call routinely
 * arrives before the body is attached. The latest ref wins: a pending retry is
 * abandoned if another identity is set in the meantime, so a window that opens
 * and immediately changes what it shows does not end up announcing the older of
 * the two.
 */
export function setIdentity( element: HTMLElement, ref: ContentRef | null ): void {
	const api = relations();

	// Remembered whether or not it can be applied right now, so the lifecycle
	// listener below can assert it once the window exists.
	wanted.set( element, ref );

	if ( ! api?.set ) {
		return;
	}

	const attempt = ( deadline: number ) => {
		// Superseded by a later call — stop, rather than overwriting the newer
		// identity with this stale one.
		if ( pending.get( element ) !== token ) {
			return;
		}

		const windowId = windowIdOf( element );

		if ( ! windowId ) {
			// Still detached. A native window's script runs before its body is
			// in the document, and how long that takes varies with whether the
			// bundle is warm — a fixed number of frames is a guess that is too
			// short exactly when the machine is busy, which is when it matters.
			if ( Date.now() < deadline ) {
				window.setTimeout( () => attempt( deadline ), ATTACH_POLL_MS );
			}

			return;
		}

		try {
			api.set!( windowId, ref );
		} catch ( error ) {
			// A window with no identity just draws no ties, so this must not be
			// fatal — but it must not be *silent* either. Swallowing the
			// RegistrationError is precisely how a malformed `related` array
			// went unnoticed: the call ran, the shell refused it, and nothing
			// anywhere said so. Reported once per page so a broken identity is
			// visible without a console full of repeats from the retry loop.
			if ( ! warned ) {
				warned = true;

				// eslint-disable-next-line no-console
				console.error( '[AllTerrain Forms] The shell refused a window identity.', error, ref );
			}

			pending.delete( element );

			return;
		}

		// Setting it is not the same as it sticking.
		//
		// The shell seeds a window's identity from its config as part of
		// opening, and that seeding lands *after* a native window's script has
		// run — so the first announcement is accepted and then cleared, and
		// `get()` a moment later returns nothing. Reading it back is the only
		// way to tell the difference between "set" and "set and still there".
		const stuck = ! ref || api.get?.( windowId )?.id === ref.id;

		if ( stuck || Date.now() >= deadline ) {
			pending.delete( element );

			return;
		}

		window.setTimeout( () => attempt( deadline ), ATTACH_POLL_MS );
	};

	const token = Symbol( 'atf-identity' );

	pending.set( element, token );
	attempt( Date.now() + ATTACH_TIMEOUT_MS );
}

/** Whether a rejected identity has already been reported this page. */
let warned = false;

/** The most recent identity request per element, so retries cannot go stale. */
const pending = new WeakMap< HTMLElement, symbol >();

/**
 * The identity each mounted element wants, kept so it can be re-applied.
 *
 * Racing frames is not a reliable way to catch a window attaching: a fresh open
 * takes longer than a reopen (the DOM is cold, the bundle is parsing), and any
 * fixed number of frames is a guess that is too short on a slow machine and
 * wasteful on a fast one. The shell announces the moment content is in place, so
 * the identities are simply re-applied then.
 *
 * A `Map` rather than a `WeakMap`: this one is iterated, and it is pruned by
 * dropping entries whose element has left the document, so a closed window's
 * identity does not linger.
 */
const wanted = new Map< HTMLElement, ContentRef | null >();

/** Re-applies every stored identity whose element is still on screen. */
function reapply(): void {
	for ( const [ element, ref ] of wanted ) {
		if ( ! element.isConnected ) {
			wanted.delete( element );

			continue;
		}

		setIdentity( element, ref );
	}
}

if ( typeof document !== 'undefined' ) {
	// `content-loaded` is the shell saying a window's body is in place, which is
	// exactly the moment an identity set during script execution needs
	// re-asserting. `opened` covers a window restored from a session, where the
	// script may not run again at all.
	for ( const event of [ 'os-window-content-loaded', 'os-window-opened' ] ) {
		document.addEventListener( event, () => reapply() );
	}
}

/**
 * The identity of a builder window showing one form.
 *
 * The form is a root — it has no `root` of its own — so every entry window that
 * names it gathers around this one.
 */
export function formIdentity( form: { id: number; title: string; previewUrl?: string }, adminUrl: string ): ContentRef {
	return {
		type: FORM_TYPE,
		id: form.id,
		label: form.title || 'Untitled form',
		related: [
			{
				id: `allterrain-forms/entries-${ form.id }`,
				label: 'Entries for this form',
				url: `${ adminUrl }admin.php?page=allterrain-forms-entries&form=${ form.id }`,
				group: 'allterrain-forms',
				groupLabel: 'Forms',
				icon: 'dashicons-list-view',
			},
		],
	};
}

/**
 * The identity of an entries window showing one submission.
 *
 * Rooted at the form, so the entry window ties itself to an open builder
 * window for that form. The submitter and any uploaded files are `links`:
 * outbound references, not containment.
 */
export function entryIdentity(
	entry: {
		id: number;
		formId: number;
		formTitle: string;
		title: string;
		userId: number;
		fields: Array< { type: string; value: unknown } >;
	},
	adminUrl: string
): ContentRef {
	const links: NonNullable< ContentRef[ 'links' ] > = [];

	if ( entry.userId ) {
		links.push( { type: 'user', id: entry.userId, rel: 'references' } );
	}

	// Uploaded files belong to the submission, so they are `child` references —
	// the shell renders those as a containment tie rather than a pointer.
	for ( const field of entry.fields ) {
		if ( field.type !== 'file' || ! Array.isArray( field.value ) ) {
			continue;
		}

		for ( const id of field.value ) {
			const attachment = Number( id );

			if ( attachment > 0 ) {
				links.push( { type: 'media', id: attachment, rel: 'child' } );
			}
		}
	}

	// The shell caps `links` at 32; trimming here keeps the excess out of the
	// payload rather than relying on it to discard the tail.
	const related: NonNullable< ContentRef[ 'related' ] > = [
		{
			id: `allterrain-forms/form-${ entry.formId }`,
			label: entry.formTitle || 'The form',
			url: `${ adminUrl }admin.php?page=allterrain-forms&form=${ entry.formId }`,
			group: 'allterrain-forms',
			groupLabel: 'Forms',
			icon: 'dashicons-feedback',
		},
	];

	if ( entry.userId ) {
		related.push( {
			id: `allterrain-forms/user-${ entry.userId }`,
			label: 'Who submitted it',
			url: `${ adminUrl }user-edit.php?user_id=${ entry.userId }`,
			group: 'users',
			groupLabel: 'People',
			icon: 'dashicons-admin-users',
		} );
	}

	return {
		type: ENTRY_TYPE,
		id: entry.id,
		root: { type: FORM_TYPE, id: entry.formId },
		label: entry.title || `Entry #${ entry.id }`,
		links: links.slice( 0, 32 ),
		related,
	};
}

/** The identity of the Theme Studio while it is editing one theme. */
export function themeIdentity( slug: string, label: string ): ContentRef {
	return {
		type: THEME_TYPE,
		id: slug,
		label,
	};
}

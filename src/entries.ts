/**
 * The entries window.
 *
 * A list on the left, one submission on the right. Filters across the top,
 * export at the end.
 *
 * The part that only works because this is a native window: **every row is
 * draggable**, carrying the payload type `allterrain-forms/entry`. Drop one on
 * an AllTerrain Work column and it becomes a task; drop it on anything else that
 * registered a drop target for that type and that plugin decides what it means.
 * The payload carries the whole entry, not just an id, so the receiving plugin
 * can render something useful the instant it arrives rather than making a REST
 * call mid-drag.
 */

import { handOffToWindow, takeRequestedForm, watchHandoffButton } from './handoff';
import { api, runtime } from './api';
import { buildPayload, getDragManager, watchShellDragVisuals } from './dnd';
import {
	button,
	checkbox,
	clear,
	confirmAction,
	debounce,
	el,
	icon,
	notify,
	select,
	textInput,
	whenComponents,
	pinWindowBodyScroll,
} from './ui';
import { entryIdentity, setIdentity } from './relations';
import type { Entry, FormSummary } from './types';
import { ENTRY_PAYLOAD_TYPE } from './types';

/** The entries browser, mounted into one root element. */
class EntriesWindow {
	private readonly root: HTMLElement;
	private readonly bar: HTMLElement;
	private readonly list: HTMLElement;
	private readonly detail: HTMLElement;

	private forms: FormSummary[] = [];
	private entries: Entry[] = [];
	private selected: Entry | null = null;

	/**
	 * Which rows are ticked.
	 *
	 * A `Set` of ids rather than a flag on each entry, so a selection survives
	 * the list being refetched — which it is after every bulk action, and after
	 * every new submission arriving over the shell's bus.
	 */
	private selection = new Set< number >();

	/**
	 * Which entry the detail pane is currently *showing*.
	 *
	 * Compared against the selection rather than tracking whether the selection
	 * changed, because the two are not the same thing and the difference is a
	 * real bug: the form picker clears `selected` before calling `load()`, so by
	 * the time the reconciliation runs there is no change left to notice — while
	 * the pane is still displaying the previous form's entry.
	 *
	 * Comparing with what is painted removes the ordering dependency: it does not
	 * matter who cleared the selection or when, only whether the screen agrees
	 * with it.
	 *
	 * `-1` means nothing has been painted, which is distinct from `0` — no
	 * selection, placeholder shown — and is what makes the placeholder appear on
	 * first load instead of leaving the server's empty `<div>`.
	 */
	private paintedSelectionId = -1;

	/**
	 * Serials for the two fetches whose responses paint the window.
	 *
	 * Nothing here cancels a request, so two rapid filter changes race their
	 * fetches — and without a serial the slower, *stale* response can land
	 * second and paint the earlier filter's entries over the current one. Each
	 * fetch takes a ticket on the way out and its response is applied only if
	 * the ticket is still the newest. Separate serials for the list and the
	 * detail pane, because a background list refresh arriving mid-click must
	 * not swallow the entry the click just asked for.
	 */
	private loadSeq = 0;
	private selectSeq = 0;

	private formId = 0;
	private status = 'inbox';
	private search = '';
	private starred = false;
	private page = 1;
	private pages = 1;
	private total = 0;

	private teardowns: Array< () => void > = [];

	public constructor( root: HTMLElement ) {
		this.root = root;
		this.bar = root.querySelector< HTMLElement >( '[data-atfe-bar]' ) ?? el( 'div' );
		this.list = root.querySelector< HTMLElement >( '[data-atfe-list]' ) ?? el( 'div' );
		this.detail = root.querySelector< HTMLElement >( '[data-atfe-detail]' ) ?? el( 'div' );
	}

	/** Loads the form list and the first page of entries. */
	public async start(): Promise< void > {
		this.teardowns.push( watchShellDragVisuals( [ ENTRY_PAYLOAD_TYPE ] ) );

		try {
			this.forms = await api.listForms();
		} catch ( error ) {
			clear( this.bar );
			this.bar.append(
				el( 'p', { class: 'atfb-error', text: error instanceof Error ? error.message : 'Could not load forms.' } )
			);

			return;
		}

		// A form asked for by name wins over the default, because the only way to
		// ask is to have said which one — the title bar's "Entries for this form",
		// or the admin URL's `?form=`. Landing on a different form than the one
		// just requested reads as the link being broken.
		const requested = takeRequestedForm();

		this.formId =
			( requested && this.forms.some( ( form ) => form.id === requested ) ? requested : 0 ) ||
			// Otherwise the form with unread entries, which is almost always what
			// somebody coming to this window wants, and saves a click every visit.
			( this.forms.find( ( form ) => form.unread > 0 ) ?? this.forms[ 0 ] )?.id ||
			0;

		this.renderBar();
		await this.load();

		// New submissions arriving while the window is open. The shell's cross
		// window bus carries the event; without a shell there is simply no
		// broadcast and the list is refreshed by the filters like any other.
		const shell = ( window as unknown as { wp?: { os?: { subscribe?: ( t: string, cb: () => void ) => () => void } } } )
			.wp?.os;

		if ( shell?.subscribe ) {
			this.teardowns.push(
				shell.subscribe( 'os.atf_entry.changed', () => {
					// The shell has no window-closed broadcast, so a closed
					// window is discovered here: its root has left the document,
					// and the only right response is to let go of the
					// subscription rather than keep fetching into a detached
					// DOM for as long as the desktop stays open.
					if ( ! this.root.isConnected ) {
						this.destroy();

						return;
					}

					void this.load();
				} )
			);
		}
	}

	/** Releases every listener. */
	public destroy(): void {
		this.teardowns.forEach( ( teardown ) => teardown() );
		this.teardowns = [];
	}

	/** The filter bar. */
	private renderBar(): void {
		clear( this.bar );

		const searchBox = textInput(
			this.search,
			debounce( ( value: string ) => {
				this.search = value;
				this.page = 1;
				void this.load();
			}, 300 ),
			'Search answers'
		);

		searchBox.type = 'search';
		searchBox.setAttribute( 'aria-label', 'Search entries' );

		this.bar.append(
			select(
				String( this.formId ),
				this.forms.map( ( form ) => ( {
					value: String( form.id ),
					label: form.unread ? `${ form.title } (${ form.unread })` : form.title,
				} ) ),
				( value ) => {
					this.formId = Number( value );
					this.page = 1;
					// A selection belongs to the form it was made in.
					this.selection.clear();
					this.selected = null;
					void this.load();
				}
			),
			select(
				this.status,
				[
					{ value: 'inbox', label: 'All' },
					{ value: 'atf-unread', label: 'Unread' },
					{ value: 'atf-read', label: 'Read' },
					{ value: 'atf-spam', label: 'Spam' },
				],
				( value ) => {
					this.status = value;
					this.page = 1;
					void this.load();
				}
			),
			searchBox,
			checkbox( 'Starred only', this.starred, ( value ) => {
				this.starred = value;
				this.page = 1;
				void this.load();
			} ),
			el( 'span', { class: 'atfe__count', attrs: { role: 'status' }, text: '' } ),
			button( 'Export CSV', () => void this.exportEntries( 'csv' ), 'secondary', 'download' ),
			button( 'JSON', () => void this.exportEntries( 'json' ), 'secondary' )
		);
	}

	/** Fetches and paints the current page. */
	private async load(): Promise< void > {
		if ( ! this.formId ) {
			clear( this.list );
			this.list.append( el( 'p', { class: 'atfb-hint', text: 'No forms yet.' } ) );

			return;
		}

		const seq = ++this.loadSeq;

		try {
			const result = await api.listEntries( {
				form_id: this.formId,
				status: this.status === 'inbox' ? undefined : this.status,
				search: this.search,
				page: this.page,
				starred: this.starred,
			} );

			if ( seq !== this.loadSeq ) {
				return;
			}

			// A bulk delete can empty the page being viewed: the server now
			// reports fewer pages than the one just asked for, and answers it
			// with no rows. Without the clamp the window claims "No entries yet"
			// while every remaining entry sits on an earlier page.
			if ( this.page > 1 && this.page > result.pages ) {
				this.page = Math.max( 1, result.pages );

				return this.load();
			}

			this.entries = result.entries;
			this.total = result.total;
			this.pages = result.pages;

			this.renderList();
			this.reconcileSelection();

			const count = this.bar.querySelector( '.atfe__count' );

			if ( count ) {
				count.textContent = `${ this.total } ${ this.total === 1 ? 'entry' : 'entries' }`;
			}
		} catch ( error ) {
			if ( seq !== this.loadSeq ) {
				return;
			}

			clear( this.list );
			this.list.append(
				el( 'p', { class: 'atfb-error', text: error instanceof Error ? error.message : 'Could not load entries.' } )
			);
		}
	}

	/**
	 * Drops a selection the current view no longer contains, and repaints the
	 * detail pane when that changes what is on screen.
	 *
	 * Every filter — the form picker, the status tabs, search, starred, and the
	 * bulk actions — narrows the list, and any of them can leave the detail pane
	 * showing a submission that is no longer in it. Switching form was the
	 * visible case: the list emptied while the previous form's entry stayed
	 * open beside it, so the window claimed "No entries yet" and displayed one.
	 *
	 * Reconciling here rather than in each callback means a filter added later
	 * cannot reintroduce the bug by forgetting to clear it.
	 *
	 * The pane is only repainted when the selection actually changes, so a
	 * background refresh — the shell broadcasts one on every new submission —
	 * does not collapse the "Where it came from" section under someone who is
	 * reading it.
	 */
	private reconcileSelection(): void {
		if ( this.selected && ! this.entries.some( ( entry ) => entry.id === this.selected?.id ) ) {
			this.selected = null;
		}

		if ( this.paintedSelectionId !== ( this.selected?.id ?? 0 ) ) {
			this.renderDetail();
		}
	}

	/** Paints the list. */
	private renderList(): void {
		clear( this.list );

		if ( ! this.entries.length ) {
			this.list.append(
				el( 'div', {
					class: 'atfb-placeholder',
					children: [
						el( 'p', { text: this.search ? 'Nothing matches that.' : 'No entries yet.' } ),
					],
				} )
			);

			return;
		}

		// Only ids still on screen stay selected. Without this, filtering to
		// "spam" and pressing Delete would also delete things the user selected
		// on a page they can no longer see.
		const onScreen = new Set( this.entries.map( ( entry ) => entry.id ) );

		for ( const id of Array.from( this.selection ) ) {
			if ( ! onScreen.has( id ) ) {
				this.selection.delete( id );
			}
		}

		this.list.append( this.renderBulkBar() );

		const rows = el( 'div', { class: 'atfe__rows', attrs: { role: 'list' } } );

		for ( const entry of this.entries ) {
			rows.append( this.renderRow( entry ) );
		}

		this.list.append( rows );

		if ( this.pages > 1 ) {
			this.list.append(
				el( 'div', {
					class: 'atfe__pager',
					children: [
						button( 'Previous', () => {
							this.page = Math.max( 1, this.page - 1 );
							void this.load();
						} ),
						el( 'span', { text: `Page ${ this.page } of ${ this.pages }` } ),
						button( 'Next', () => {
							this.page = Math.min( this.pages, this.page + 1 );
							void this.load();
						} ),
					],
				} )
			);
		}
	}

	/**
	 * The select-all row, and the actions that appear once something is ticked.
	 *
	 * The actions are rendered only when there is a selection rather than being
	 * disabled, because a row of permanently-greyed buttons is noise on the
	 * ninety-nine percent of visits that are just reading.
	 */
	private renderBulkBar(): HTMLElement {
		const count = this.selection.size;

		const all = el( 'input', {
			type: 'checkbox',
			attrs: { 'aria-label': 'Select every entry on this page' },
			on: {
				change: ( event: Event ) => {
					const checked = ( event.target as HTMLInputElement ).checked;

					this.entries.forEach( ( entry ) => {
						if ( checked ) {
							this.selection.add( entry.id );
						} else {
							this.selection.delete( entry.id );
						}
					} );

					this.renderList();
				},
			},
		} );

		all.checked = count > 0 && count === this.entries.length;
		all.indeterminate = count > 0 && count < this.entries.length;

		return el( 'div', {
			class: 'atfe__bulk',
			children: [
				all,
				el( 'span', {
					class: 'atfe__bulk-count',
					attrs: { role: 'status' },
					text: count ? `${ count } selected` : 'Select',
				} ),
				count ? button( 'Read', () => void this.bulk( 'atf-read' ) ) : null,
				count ? button( 'Unread', () => void this.bulk( 'atf-unread' ) ) : null,
				count ? button( 'Spam', () => void this.bulk( 'atf-spam' ) ) : null,
				count && runtime?.canEdit ? button( 'Delete', () => void this.bulkDelete(), 'danger' ) : null,
			],
		} );
	}

	/**
	 * Applies a status to everything selected.
	 *
	 * Sequential rather than concurrent. A bulk action over two hundred entries
	 * fired as two hundred simultaneous requests is a self-inflicted denial of
	 * service on a shared host, and the wall-clock difference is a second.
	 */
	private async bulk( status: string ): Promise< void > {
		const ids = Array.from( this.selection );

		for ( const id of ids ) {
			try {
				await api.updateEntry( id, { status } );
			} catch {
				// One failure should not abandon the rest — the reload below
				// shows what actually changed.
			}
		}

		this.selection.clear();

		await this.load();
	}

	/** Deletes everything selected, after one confirmation for the lot. */
	private async bulkDelete(): Promise< void > {
		const ids = Array.from( this.selection );

		const confirmed = await confirmAction(
			`Delete ${ ids.length } ${ ids.length === 1 ? 'entry' : 'entries' } and any files uploaded with them? It cannot be undone.`,
			'Delete entries'
		);

		if ( ! confirmed ) {
			return;
		}

		for ( const id of ids ) {
			try {
				await api.deleteEntry( id );
			} catch {
				// As above.
			}
		}

		this.selection.clear();
		this.selected = null;

		await this.load();
		this.renderDetail();
	}

	/** One row, draggable. */
	private renderRow( entry: Entry ): HTMLElement {
		const unread = entry.status === 'atf-unread';

		const row = el( 'div', {
			class: `atfe__row${ unread ? ' is-unread' : '' }${ this.selected?.id === entry.id ? ' is-selected' : '' }`,
			attrs: {
				role: 'listitem',
				tabindex: '0',
				'data-entry': entry.id,
			},
			children: [
				el( 'input', {
					class: 'atfe__select',
					type: 'checkbox',
					attrs: {
						'aria-label': `Select ${ entry.title }`,
						checked: this.selection.has( entry.id ),
					},
					on: {
						click: ( event: Event ) => event.stopPropagation(),
						change: ( event: Event ) => {
							if ( ( event.target as HTMLInputElement ).checked ) {
								this.selection.add( entry.id );
							} else {
								this.selection.delete( entry.id );
							}

							// Only the bulk bar changes, so the rows are left
							// alone — repainting them would drop the focus of
							// whoever is ticking boxes with the keyboard.
							this.list.querySelector( '.atfe__bulk' )?.replaceWith( this.renderBulkBar() );
						},
					},
				} ),
				el( 'button', {
					class: `atfe__star${ entry.starred ? ' is-on' : '' }`,
					type: 'button',
					attrs: { 'aria-label': entry.starred ? 'Unstar this entry' : 'Star this entry' },
					on: {
						click: ( event: Event ) => {
							event.stopPropagation();
							void this.toggleStar( entry );
						},
					},
					children: [ icon( 'star-filled' ) ],
				} ),
				el( 'div', {
					class: 'atfe__row-body',
					children: [
						el( 'strong', { text: entry.title } ),
						el( 'span', { class: 'atfe__row-meta', text: entry.dateHuman } ),
					],
				} ),
				entry.notes ? el( 'span', { class: 'atfb-badge', text: `💬 ${ entry.notes }` } ) : null,
				entry.spam ? el( 'span', { class: 'atfb-badge atfb-badge--spam', text: 'spam' } ) : null,
			],
		} );

		row.addEventListener( 'click', () => {
			if ( getDragManager().recentlyEndedDrag() ) {
				return;
			}

			void this.select( entry );
		} );

		row.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				void this.select( entry );
			}
		} );

		// The cross-window drag. The payload carries the whole entry so a
		// receiving plugin can render it immediately, and the ghost is a small
		// card rather than a clone of the row, which would be the width of the
		// list and unreadable over another window.
		row.addEventListener( 'pointerdown', ( event ) => {
			if ( ( event.target as HTMLElement ).closest( 'button' ) ) {
				return;
			}

			const ghost = el( 'div', {
				class: 'atfe__ghost',
				children: [ icon( 'feedback' ), el( 'span', { text: entry.title } ) ],
			} );

			getDragManager().start( {
				payload: buildPayload(
					ENTRY_PAYLOAD_TYPE,
					row,
					{ entry, formId: entry.formId, formTitle: entry.formTitle },
					event,
					ghost
				),
				origin: event,
			} );
		} );

		return row;
	}

	/** Opens one entry in the detail pane. */
	private async select( entry: Entry ): Promise< void > {
		const seq = ++this.selectSeq;

		try {
			// Fetched rather than reused from the list, because opening an entry
			// is what marks it read and the server does that on the read.
			const fetched = await api.getEntry( entry.id );

			// A second click landed while this one was in flight; the slower
			// response must not paint over the entry chosen after it.
			if ( seq !== this.selectSeq ) {
				return;
			}

			this.selected = fetched;

			const stale = this.entries.find( ( candidate ) => candidate.id === entry.id );

			if ( stale ) {
				stale.status = this.selected.status;
			}

			this.renderList();
			this.renderDetail();
		} catch ( error ) {
			if ( seq !== this.selectSeq ) {
				return;
			}

			notify( 'Could not open that entry', error instanceof Error ? error.message : '', 'error' );
		}
	}

	/** Paints the detail pane. */
	private renderDetail(): void {
		this.paintedSelectionId = this.selected?.id ?? 0;

		// The window's identity follows what it is showing: the open entry,
		// rooted at its form, or nothing at all when the pane is empty. Clearing
		// it matters as much as setting it — a window still claiming to show an
		// entry it has closed would keep drawing a tie to a form nobody is
		// looking at.
		setIdentity(
			this.root,
			this.selected ? entryIdentity( this.selected, runtime?.adminUrl ?? '' ) : null
		);

		clear( this.detail );

		const entry = this.selected;

		if ( ! entry ) {
			this.detail.append(
				el( 'div', { class: 'atfb-placeholder', children: [ el( 'p', { text: 'Pick an entry to read it.' } ) ] } )
			);

			return;
		}

		const answers = el( 'dl', { class: 'atfe__answers' } );

		for ( const field of entry.fields ) {
			if ( ! field.formatted ) {
				continue;
			}

			answers.append(
				el( 'dt', { text: field.label || field.id } ),
				el( 'dd', { text: field.formatted } )
			);
		}

		// Assembled through `el()` rather than appended one by one, because its
		// children list is the only one of the two that tolerates a `null` for
		// a section that does not apply to this entry.
		this.detail.append(
			el( 'div', {
				class: 'atfe__detail',
				children: [
			el( 'div', {
				class: 'atfe__detail-head',
				children: [
					el( 'h2', { text: entry.title } ),
					el( 'p', { class: 'atfb-hint', text: `${ entry.formTitle } — ${ entry.dateHuman }` } ),
				],
			} ),
			answers,
			entry.quiz
				? el( 'p', {
						class: 'atfe__quiz',
						text: `Score: ${ entry.quiz.score } of ${ entry.quiz.total } (${ entry.quiz.percent }%) — ${
							entry.quiz.passed ? 'passed' : 'not passed'
						}`,
				  } )
				: null,
			el( 'details', {
				class: 'atfb-section',
				children: [
					el( 'summary', { text: 'Where it came from' } ),
					el( 'p', { class: 'atfb-hint', text: entry.ip ? `IP: ${ entry.ip }` : 'No IP recorded.' } ),
					entry.referrer ? el( 'p', { class: 'atfb-hint', text: `Referrer: ${ entry.referrer }` } ) : null,
					entry.userAgent ? el( 'p', { class: 'atfb-hint', text: entry.userAgent } ) : null,
				],
			} ),
			el( 'div', {
				class: 'atfe__actions',
				children: [
					button(
						entry.status === 'atf-spam' ? 'Not spam' : 'Mark as spam',
						() => void this.setStatus( entry, entry.status === 'atf-spam' ? 'atf-read' : 'atf-spam' )
					),
					button(
						entry.status === 'atf-unread' ? 'Mark read' : 'Mark unread',
						() => void this.setStatus( entry, entry.status === 'atf-unread' ? 'atf-read' : 'atf-unread' )
					),
					entry.canDelete ? button( 'Delete', () => void this.remove( entry ), 'danger' ) : null,
				],
			} ),
				],
			} )
		);
	}

	/** Stars or unstars. */
	private async toggleStar( entry: Entry ): Promise< void > {
		try {
			const updated = await api.updateEntry( entry.id, { starred: ! entry.starred } );

			entry.starred = updated.starred;
			this.renderList();
		} catch ( error ) {
			notify( 'Could not update that entry', error instanceof Error ? error.message : '', 'error' );
		}
	}

	/** Changes an entry's status. */
	private async setStatus( entry: Entry, status: string ): Promise< void > {
		try {
			const updated = await api.updateEntry( entry.id, { status } );

			this.selected = updated;

			await this.load();
			this.renderDetail();
		} catch ( error ) {
			notify( 'Could not update that entry', error instanceof Error ? error.message : '', 'error' );
		}
	}

	/** Deletes an entry for good. */
	private async remove( entry: Entry ): Promise< void > {
		if ( ! ( await confirmAction( 'Delete this entry and any files uploaded with it? It cannot be undone.', 'Delete entry' ) ) ) {
			return;
		}

		try {
			await api.deleteEntry( entry.id );

			this.selected = null;

			await this.load();
			this.renderDetail();
		} catch ( error ) {
			notify( 'Could not delete that entry', error instanceof Error ? error.message : '', 'error' );
		}
	}

	/**
	 * Downloads the current view as CSV.
	 *
	 * The CSV comes back in a JSON envelope rather than as a file response,
	 * because this is a native window inside a single-page shell: navigating to
	 * a download URL would take the whole desktop with it. A Blob and an
	 * object URL keep the navigation local to a link nobody sees.
	 */
	private async exportEntries( format: 'csv' | 'json' ): Promise< void > {
		try {
			const { filename, csv } = await api.exportEntries( {
				form_id: this.formId,
				search: this.search,
				starred: this.starred,
				format,
			} );

			const blob = new Blob( [ csv ], {
				type: format === 'json' ? 'application/json' : 'text/csv;charset=utf-8',
			} );
			const url = URL.createObjectURL( blob );
			const link = el( 'a', { href: url, attrs: { download: filename } } );

			document.body.append( link );
			link.click();
			link.remove();

			// Revoked on the next tick rather than immediately: some browsers
			// have not finished reading the blob when `click()` returns.
			window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
		} catch ( error ) {
			notify( 'Could not export', error instanceof Error ? error.message : '', 'error' );
		}
	}
}

/** Mounts the entries window. */
let mountedEntries: EntriesWindow | null = null;
let mountedEntriesRoot: HTMLElement | null = null;

export function mountEntries(): void {
	// Same rule as the builder: one live instance, and closing the window
	// releases it. The per-element flag alone would let a reopened window mount
	// a second instance while the first still holds a subscription to the
	// shell's entry-changed broadcast.
	if ( mountedEntriesRoot?.isConnected ) {
		return;
	}

	if ( mountedEntries ) {
		mountedEntries.destroy();
		mountedEntries = null;
		mountedEntriesRoot = null;
	}

	const root = document.querySelector< HTMLElement >( '[data-atfe-root]:not([data-atfe-mounted])' );

	if ( ! root || ! runtime?.canRead ) {
		return;
	}

	// Flagged before the await for the same reason the builder does it: this runs
	// from DOM ready and from the shell's content-loaded event, and a gap between
	// the guard and the flag lets both through.
	root.dataset.atfeMounted = '1';
	mountedEntriesRoot = root;
	pinWindowBodyScroll( root );

	void whenComponents().then( () => {
		if ( ! root.isConnected ) {
			return;
		}

		mountedEntries = new EntriesWindow( root );

		void mountedEntries.start();
	} );
}

/** Mounts the window, or hands off to the native one when the shell has it. */
function bootEntries(): void {
	mountEntries();
	handOffToWindow();
}

watchHandoffButton();

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', bootEntries );
} else {
	bootEntries();
}

document.addEventListener( 'os-window-content-loaded', mountEntries );

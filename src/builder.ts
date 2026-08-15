/**
 * The form builder.
 *
 * Three panes: a palette of field types, a canvas holding the form, and an
 * inspector for whatever is selected. A field is added by dragging it from the
 * palette onto the canvas, and reordered by dragging it within the canvas.
 *
 * Both of those go through `wp.os.dragManager` when OpenStation is present —
 * the shell's own pointer pipeline, shared with every other window on the
 * desktop. That is what makes the cross-window behaviour possible: a field can
 * be dragged between two open builder windows, and an image dragged out of WP
 * Explorer can be dropped onto an image-choice field. On a plain wp-admin page
 * the same code runs against the fallback manager in `dnd.ts`, so there is one
 * drag implementation rather than two.
 *
 * **Dragging is never the only way to do anything.** Every field type in the
 * palette is a real `<button>` that adds a field when activated, and every field
 * on the canvas can be moved with the keyboard. A builder that can only be
 * driven by a pointer excludes the people least able to work around it.
 */

import { api, runtime } from './api';
import { buildPayload, getDragManager, insertionIndex, watchShellDragVisuals } from './dnd';
import {
	button,
	checkbox,
	clear,
	confirmAction,
	debounce,
	el,
	icon,
	notify,
	numberInput,
	raf,
	readSetting,
	whenComponents,
	row,
	select,
	textArea,
	textInput,
	writeSetting,
} from './ui';
import { handOffToWindow, watchHandoffButton } from './handoff';
import { LogicMap, controlCounts, logicEdges, logicTokens, tokensToText } from './logic-map';
import { renderFieldPreview } from './field-preview';
import type { LogicToken } from './logic-map';
import { forgetMergeTags, mergeTags, taggable } from './merge-tags';
import { mountThemeControls } from './theme-studio';
import { openPreview, refreshPreview, registerPreviewButton } from './preview-button';
import { formIdentity, setIdentity } from './relations';

/**
 * Where the logic-overlay toggle is remembered.
 *
 * The key carries a version, and bumping it is how "on by default" becomes true
 * for somebody who had already switched it off while it was a new and unfamiliar
 * thing. A stored preference outlives the reason it was set; this resets that one
 * choice once, and every choice made after this point sticks.
 */
const LOGIC_MAP_SETTING = 'allterrain-forms/logic-map-v2';

/**
 * Marks an inspector control as the twin of something on the canvas.
 *
 * The canvas and the inspector edit the same values, so they have to agree while
 * you are typing — rewriting a label on the card and watching the Label box keep
 * the old text reads as one of them being stale, with no way to tell which.
 *
 * The tag is what lets a canvas edit write through to its counterpart without
 * rebuilding the whole pane on every keystroke. Rebuilding would also work, and
 * is what a structural change does — but it recreates every shell component in
 * the inspector, sixty times a second, to change one string.
 *
 * @param control The inspector control.
 * @param key     What it edits: `label`, `placeholder`, `choice:<n>:label`, …
 * @return The same control.
 */
function bind< T extends HTMLElement >( control: T, key: string ): T {
	control.dataset.atfbBind = key;

	return control;
}

/**
 * The prefill sources, in the order they are offered.
 *
 * `tag` names the merge tag that resolves to the same thing, which is how the
 * preview line gets a real value for *this* site without a second endpoint: the
 * merge-tag catalogue already carries `sample` for every one of these, computed
 * by the same PHP that resolves them.
 *
 * `date:now` and `date:today` are spelled out rather than left as formats,
 * because they are what a date field and a time field respectively want and
 * nobody should have to know `H:i` to pre-fill the time.
 */
const PREFILL_SOURCES: Array< { value: string; label: string; group: string; tag?: string } > = [
	{ value: 'user:email', label: 'Their email address', group: 'About the person filling it in', tag: '{user:email}' },
	{ value: 'user:display_name', label: 'Their name', group: 'About the person filling it in', tag: '{user:display_name}' },
	{ value: 'user:first_name', label: 'Their first name', group: 'About the person filling it in' },
	{ value: 'user:last_name', label: 'Their last name', group: 'About the person filling it in' },
	{ value: 'user:login', label: 'Their username', group: 'About the person filling it in' },
	{ value: 'date:today', label: 'Today’s date', group: 'The date and time', tag: '{date}' },
	{ value: 'date:now', label: 'The time right now', group: 'The date and time', tag: '{time}' },
	{ value: 'site', label: 'This site’s name', group: 'About this site', tag: '{site}' },
	{ value: 'site:url', label: 'This site’s address', group: 'About this site', tag: '{site:url}' },
	{ value: 'site:admin_email', label: 'The site administrator’s email', group: 'About this site', tag: '{admin_email}' },
];

/** The `optgroup` headings, in order. */
const PREFILL_GROUPS = [ 'About the person filling it in', 'The date and time', 'About this site' ];
import type {
	BuilderConfig,
	Choice,
	Confirmation,
	Field,
	FieldType,
	Form,
	FormSchema,
	FormSummary,
	Notification,
	Theme,
} from './types';
import { FIELD_PAYLOAD_TYPE, MEDIA_PAYLOAD_TYPES } from './types';

const i18n = ( key: string, fallback: string ): string => runtime?.i18n?.[ key ] ?? fallback;

/** The builder, mounted into one root element. */
export class Builder {
	private readonly root: HTMLElement;

	private config: BuilderConfig | null = null;
	private themes: Theme[] = [];
	private forms: FormSummary[] = [];

	private form: Form | null = null;
	private schema: FormSchema | null = null;
	private selected: string | null = null;
	private tab: 'build' | 'theme' | 'settings' | 'notify' | 'confirm' = 'build';

	/** The conditional-logic overlay, when the canvas has one. */
	private logicMap: LogicMap | null = null;

	/** The form theme's custom properties, applied to the canvas previews. */
	private readonly canvasTheme = el( 'style' );

	/** Which theme the canvas is currently painted with, so it repaints once. */
	private canvasThemeSignature = '';

	/**
	 * Which disclosure panels are open, by key.
	 *
	 * A `<details>` keeps its open state in the element, and the inspector and the
	 * canvas rebuild their elements on every change — so without this, opening
	 * Conditional logic and then editing anything inside it folds the panel up
	 * around the control you are still using.
	 *
	 * Keyed rather than positional so it survives a field being reordered,
	 * renamed or deleted: the key names the *thing*, not the row it was in.
	 */
	private openSections = new Map< string, boolean >();

	/**
	 * Whether to draw the logic connections.
	 *
	 * On by default, and remembered per browser once somebody says otherwise. A
	 * form with a dozen conditions draws a dozen curves, and somebody laying out
	 * fields may want them out of the way for a minute — a view that cannot be
	 * turned off stops being a view and becomes something to work around.
	 *
	 * The test is against `'off'` rather than for `'on'`, so an unset preference
	 * and an unreadable one both mean on. Storage is unavailable in private mode,
	 * and a feature that quietly disappears there would be the wrong default in
	 * the one session where nothing can be remembered.
	 */
	private logicMapOn: boolean = 'off' !== readSetting( LOGIC_MAP_SETTING );
	private dirty = false;

	private readonly bar: HTMLElement;
	private readonly palette: HTMLElement;
	private readonly canvas: HTMLElement;
	private readonly inspector: HTMLElement;

	private teardowns: Array< () => void > = [];

	/**
	 * Schema snapshots, oldest first, for undo and redo.
	 *
	 * Whole-schema snapshots rather than a command log, because a schema is a
	 * few kilobytes of JSON and a command log would need an inverse for every
	 * operation the builder can ever perform — including the ones added later,
	 * which is exactly where a command log quietly stops being correct.
	 */
	private history: string[] = [];
	private historyAt = -1;

	public constructor( root: HTMLElement ) {
		this.root = root;
		this.bar = root.querySelector< HTMLElement >( '[data-atfb-bar]' ) ?? el( 'div' );
		this.palette = root.querySelector< HTMLElement >( '[data-atfb-palette]' ) ?? el( 'div' );
		this.canvas = root.querySelector< HTMLElement >( '[data-atfb-canvas]' ) ?? el( 'div' );
		this.inspector = root.querySelector< HTMLElement >( '[data-atfb-inspector]' ) ?? el( 'div' );
	}

	/** Loads everything and paints. */
	public async start(): Promise< void > {
		try {
			const [ config, themes, forms ] = await Promise.all( [ api.config(), api.listThemes(), api.listForms() ] );

			this.config = config;
			this.themes = themes;
			this.forms = forms;
		} catch ( error ) {
			this.fail( error );

			return;
		}

		this.teardowns.push( watchShellDragVisuals( [ FIELD_PAYLOAD_TYPE ] ) );

		// The eye in the window's title bar, matching the shell's own
		// editor-preview convention. Registered with a view onto this builder
		// rather than a snapshot, so the button always previews whichever form
		// is open now.
		this.teardowns.push(
			registerPreviewButton( {
				current: () =>
					this.form
						? { id: this.form.id, title: this.form.title, previewUrl: this.form.previewUrl }
						: null,
				isDirty: () => this.dirty,
				save: () => this.save( true ),
			} )
		);

		// Leaving with unsaved work is the one thing a builder must never do
		// quietly. Registered once, and only ever fires while `dirty` is true.
		const beforeUnload = ( event: BeforeUnloadEvent ) => {
			if ( this.dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		};

		window.addEventListener( 'beforeunload', beforeUnload );
		this.teardowns.push( () => window.removeEventListener( 'beforeunload', beforeUnload ) );

		// Undo and redo. Bound on the root rather than the document so two
		// builder windows open at once each answer their own keystrokes, and
		// skipped while focus is in a text control, where the browser's own undo
		// is what the user means.
		const onKey = ( event: KeyboardEvent ) => {
			if ( ! ( event.metaKey || event.ctrlKey ) || event.key.toLowerCase() !== 'z' ) {
				return;
			}

			const target = event.target as HTMLElement | null;

			if ( target?.closest( 'input, textarea, [contenteditable]' ) ) {
				return;
			}

			event.preventDefault();
			this.travel( event.shiftKey ? 1 : -1 );
		};

		this.root.addEventListener( 'keydown', onKey );
		this.teardowns.push( () => this.root.removeEventListener( 'keydown', onKey ) );

		this.renderBar();
		this.renderPalette();

		if ( this.forms.length ) {
			await this.open( this.forms[ 0 ].id );
		} else {
			this.renderFormsList();
		}
	}

	/** Releases every listener this instance registered. */
	public destroy(): void {
		this.teardowns.forEach( ( teardown ) => teardown() );
		this.teardowns = [];
	}

	/** Shows a load failure rather than an empty window. */
	private fail( error: unknown ): void {
		clear( this.bar );

		this.bar.append(
			el( 'p', {
				class: 'atfb-error',
				text:
					error instanceof Error
						? error.message
						: 'Something went wrong loading your forms.',
			} )
		);
	}

	/* ------------------------------------------------------------- Toolbar */

	/** The top bar: form picker, tabs, save. */
	private renderBar(): void {
		clear( this.bar );

		const picker = select(
			String( this.form?.id ?? '' ),
			[
				...this.forms.map( ( form ) => ( { value: String( form.id ), label: form.title || '(untitled)' } ) ),
			],
			( value ) => void this.open( Number( value ) )
		);

		picker.setAttribute( 'aria-label', 'Choose a form' );

		const title = el( 'input', {
			class: 'atfb-title',
			type: 'text',
			value: this.form?.title ?? '',
			placeholder: 'Untitled form',
			attrs: { 'aria-label': 'Form title' },
			on: {
				input: ( event: Event ) => {
					if ( this.form ) {
						this.form.title = ( event.target as HTMLInputElement ).value;
						this.markDirty();
					}
				},
			},
		} );

		const tabs = el( 'div', {
			class: 'atfb-tabs',
			attrs: { role: 'tablist' },
			children: (
				[
					[ 'build', 'Build' ],
					[ 'theme', 'Theme' ],
					[ 'settings', 'Settings' ],
					[ 'notify', 'Notifications' ],
					[ 'confirm', 'Confirmations' ],
				] as const
			).map( ( [ id, label ] ) =>
				el( 'button', {
					class: `atfb-tab${ this.tab === id ? ' is-active' : '' }`,
					type: 'button',
					text: label,
					attrs: { role: 'tab', 'aria-selected': this.tab === id },
					on: {
						click: () => {
							this.tab = id;
							this.renderBar();
							this.renderInspector();
							this.renderCanvas();
						},
					},
				} )
			),
		} );

		this.bar.append(
			el( 'div', {
				class: 'atfb-bar__left',
				children: [ this.forms.length > 1 ? picker : null, title ],
			} ),
			tabs,
			el( 'div', {
				class: 'atfb-bar__right',
				children: [
					// No save-status label here. OpenStation's title bar already
					// carries one — the activity ring that `wp.os.fetch` drives,
					// which every request in `api.ts` goes through — so a second
					// one in the toolbar is the same information twice, in the
					// less prominent place. The Save button below shows whether
					// there is anything to save; the window says what happened
					// to it.
					//
					// Undo and redo are also Cmd/Ctrl+Z and Shift+Cmd/Ctrl+Z; the
					// buttons exist because a builder whose only undo is a
					// shortcut is a builder most people never discover has one.
					this.historyButton( 'undo', 'Undo', -1 ),
					this.historyButton( 'redo', 'Redo', 1 ),
					button( 'New', () => void this.showTemplates(), 'secondary', 'plus-alt2' ),
					button( 'Export', () => void this.exportForm(), 'secondary', 'download' ),
					button( 'Import', () => void this.importForm(), 'secondary', 'upload' ),
					// The same action the title bar's eye performs, for the admin
					// page — where there is no title bar to put an eye in.
					button( 'Preview', () => void this.preview(), 'secondary', 'visibility' ),
					button( 'Entries', () => this.openEntries(), 'secondary', 'list-view' ),
					this.logicMapButton(),
					this.saveButton(),
				],
			} )
		);
	}

	/**
	 * The field with this id in the *current* schema.
	 *
	 * Returns undefined when it has gone — deleted in another window, or dropped
	 * by the server's normalisation — which is a write that should simply not
	 * happen rather than one that should throw.
	 *
	 * @param fieldId The field's id.
	 * @return The live field, if it is still there.
	 */
	private liveField( fieldId: string ): Field | undefined {
		return this.schema?.fields.find( ( candidate ) => candidate.id === fieldId );
	}

	/**
	 * Writes a field's current values into the inspector's matching controls.
	 *
	 * Only when the inspector is actually showing that field — editing a card that
	 * is not selected must not rewrite the pane describing a different one.
	 *
	 * Deliberately one-directional and value-only: the inspector's own handlers
	 * already write to the schema, and firing them from here would put the two
	 * panes in a loop, each telling the other about a change it had just made.
	 *
	 * @param field The field that was edited on the canvas.
	 */
	/**
	 * Writes a field's current values into its card on the canvas.
	 *
	 * The mirror image of `syncInspector()`, for the same reason: the two panes
	 * edit one value and have to agree while it is being typed. The inspector's
	 * `update()` already repaints the canvas wholesale, but the choices editor
	 * mutates in place and only marks the form dirty — which was invisible until
	 * the canvas started drawing the options.
	 *
	 * Writes `textContent` rather than re-rendering, so the caret in the
	 * inspector is untouched.
	 *
	 * @param field The field that was edited in the inspector.
	 */
	private syncCanvas( field: Field ): void {
		const card = this.canvas.querySelector< HTMLElement >(
			`[data-atfb-card="${ CSS.escape( field.id ) }"]`
		);

		if ( ! card ) {
			return;
		}

		const label = card.querySelector< HTMLElement >( '.atf-label.atfb-editable' );

		if ( label && label.textContent !== field.label ) {
			label.textContent = field.label;
		}

		const options = card.querySelectorAll< HTMLElement >( '.atf-choice__label.atfb-editable' );

		( field.choices ?? [] ).forEach( ( choice, index ) => {
			const option = options[ index ];

			if ( option && option.textContent !== choice.label ) {
				option.textContent = choice.label;
			}
		} );
	}

	private syncInspector( field: Field ): void {
		if ( this.selected !== field.id ) {
			return;
		}

		const write = ( key: string, value: string ) => {
			const control = this.inspector.querySelector< HTMLElement & { value?: string } >(
				`[data-atfb-bind="${ CSS.escape( key ) }"]`
			);

			if ( ! control ) {
				return;
			}

			// `value` is a property on a native input and on the shell's field
			// components alike; the attribute is the fallback for anything that
			// only reflects it.
			if ( 'value' in control ) {
				control.value = value;
			} else {
				control.setAttribute( 'value', value );
			}
		};

		write( 'label', field.label ?? '' );
		write( 'placeholder', field.placeholder ?? '' );

		( field.choices ?? [] ).forEach( ( choice, index ) => {
			write( `choice:${ index }:label`, choice.label ?? '' );
			write( `choice:${ index }:value`, choice.value ?? '' );
		} );
	}

	/**
	 * Rebuilds the canvas so its cards point at the current schema objects.
	 *
	 * Deferred while the canvas holds focus. An autosave fires 2.5 seconds after
	 * the last keystroke, which is exactly when somebody has paused mid-sentence
	 * with the caret still in a label — and rebuilding then would take the caret
	 * away for no reason they could see. Waiting for the blur costs nothing: the
	 * card on screen already shows what they typed, and the rebind only has to
	 * happen before the *next* edit.
	 */
	private rebindCanvas(): void {
		const focused = document.activeElement;

		if ( focused instanceof HTMLElement && this.canvas.contains( focused ) ) {
			focused.addEventListener( 'blur', () => this.rebindCanvas(), { once: true } );

			return;
		}

		this.renderCanvas();
	}

	/**
	 * Takes a history snapshot.
	 *
	 * Called before a *structural* change — adding, moving, duplicating or
	 * deleting a field — and not on every keystroke. Snapshotting each character
	 * typed into a label would make undo mean "remove one letter", which is not
	 * what anybody reaches for it to do.
	 */
	private snapshot(): void {
		if ( ! this.schema ) {
			return;
		}

		const json = JSON.stringify( this.schema );

		if ( this.history[ this.historyAt ] === json ) {
			return;
		}

		// Anything ahead of the cursor is a branch the user abandoned by making
		// a new change after undoing, so it is discarded — the same rule every
		// editor follows.
		this.history = this.history.slice( 0, this.historyAt + 1 );
		this.history.push( json );

		// Bounded, because a long session should not grow without limit.
		if ( this.history.length > 60 ) {
			this.history.shift();
		}

		this.historyAt = this.history.length - 1;
	}

	/** Steps backwards or forwards through the history. */
	private travel( delta: number ): void {
		const next = this.historyAt + delta;

		if ( ! this.schema || next < 0 || next >= this.history.length ) {
			return;
		}

		this.historyAt = next;
		this.schema = JSON.parse( this.history[ next ] ) as FormSchema;

		// A field that no longer exists cannot stay selected, or the inspector
		// renders settings for something that is not on the canvas.
		if ( ! this.schema.fields.some( ( field ) => field.id === this.selected ) ) {
			this.selected = null;
		}

		this.dirty = true;
		this.autosave();

		this.renderBar();
		this.renderCanvas();
		this.renderInspector();
	}

	/** An undo or redo button, disabled when there is nowhere to go. */
	private historyButton( iconSlug: string, label: string, delta: number ): HTMLElement & { disabled: boolean } {
		const target = this.historyAt + delta;
		const node = button( label, () => this.travel( delta ), 'secondary', iconSlug );

		node.disabled = target < 0 || target >= this.history.length;

		return node;
	}

	/**
	 * Downloads the current form as JSON.
	 *
	 * The exported document is the schema and the title — the same shape
	 * `/forms` accepts on the way back in, so an export from one site is an
	 * import on another with nothing in between. Entry data is deliberately not
	 * in it: this is the form, not what people said in it.
	 */
	private exportForm(): void {
		if ( ! this.form || ! this.schema ) {
			return;
		}

		const payload = {
			plugin: 'allterrain-forms',
			version: runtime?.version ?? '',
			title: this.form.title,
			schema: this.schema,
		};

		const blob = new Blob( [ JSON.stringify( payload, null, '\t' ) ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const link = el( 'a', {
			href: url,
			attrs: { download: `${ this.form.title.replace( /[^a-z0-9]+/gi, '-' ).toLowerCase() || 'form' }.json` },
		} );

		document.body.append( link );
		link.click();
		link.remove();

		// Revoked on a later tick: some browsers have not finished reading the
		// blob when `click()` returns.
		window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	}

	/**
	 * Creates a form from an exported JSON document.
	 *
	 * A new form rather than an overwrite of the open one. Import is the sort of
	 * action people try to see what happens, and "see what happens" must never
	 * mean "replace the form I spent an afternoon on".
	 *
	 * The schema is normalised server-side on the way in, so a hand-edited or
	 * out-of-date document cannot put anything unusable into the database.
	 */
	private async importForm(): Promise< void > {
		const picker = el( 'input', { type: 'file', attrs: { accept: 'application/json,.json' } } );

		picker.addEventListener( 'change', async () => {
			const file = picker.files?.[ 0 ];

			if ( ! file ) {
				return;
			}

			try {
				const parsed = JSON.parse( await file.text() ) as { title?: string; schema?: unknown };

				if ( ! parsed.schema ) {
					throw new Error( 'That file does not contain a form.' );
				}

				const created = await api.createForm( {
					title: parsed.title ?? file.name.replace( /\.json$/i, '' ),
					schema: parsed.schema,
				} );

				this.forms.unshift( {
					id: created.id,
					title: created.title,
					status: created.status,
					modified: created.modified,
					fields: created.schema.fields.length,
					theme: created.schema.settings.theme,
					entries: 0,
					unread: 0,
					views: 0,
					submissions: 0,
					shortcode: created.shortcode,
				} );

				this.form = created;
				this.schema = created.schema;
				this.selected = null;
				this.dirty = false;
				this.history = [];
				this.historyAt = -1;
				this.snapshot();

				this.renderBar();
				this.renderCanvas();
				this.renderInspector();

				notify( 'Form imported', created.title );
			} catch ( error ) {
				notify( 'Could not import that file', error instanceof Error ? error.message : '', 'error' );
			}
		} );

		picker.click();
	}

	/**
	 * The Save button, which is the only place unsaved state is shown.
	 *
	 * Disabled while there is nothing to save, so the button itself answers "is
	 * my work in?" without a label beside it repeating the answer.
	 */
	private saveButton(): HTMLElement & { disabled: boolean } {
		const node = button( 'Save', () => void this.save(), 'primary' );

		node.disabled = ! this.dirty;
		node.setAttribute( 'data-atfb-save', '' );

		return node;
	}

	/** Marks the form as having unsaved changes and schedules an autosave. */
	private markDirty(): void {
		this.dirty = true;

		// Only the button changes, so the whole toolbar is not rebuilt on every
		// keystroke — which would drop the focus of whoever is typing in it.
		const save = this.bar.querySelector< HTMLElement & { disabled: boolean } >( '[data-atfb-save]' );

		if ( save ) {
			save.disabled = false;
		}

		this.autosave();
	}

	/**
	 * Saves a couple of seconds after the last edit.
	 *
	 * Long enough that typing a label is one save rather than fifteen, short
	 * enough that closing the window straight after an edit does not lose it.
	 */
	private readonly autosave = debounce( () => {
		void this.save( true );
	}, 2500 );

	/** Writes the form back. */
	private async save( silent = false ): Promise< void > {
		if ( ! this.form || ! this.schema ) {
			return;
		}

		try {
			const saved = await api.updateForm( this.form.id, {
				title: this.form.title,
				schema: this.schema,
			} );

			// The server's normalisation is authoritative — it may have issued
			// ids, dropped an unusable field or clamped a setting — so its copy
			// replaces the local one rather than being merged into it.
			//
			// Replacing it orphans every card on the canvas: each one closes over
			// the field object it was rendered from, and those objects are now the
			// *previous* schema's. Typing into a label after an autosave would
			// update an object nothing serialises, and the edit would vanish at the
			// next save with no error anywhere. So the canvas is rebuilt to rebind
			// — but not out from under somebody who is still typing in it.
			this.form = saved;
			this.schema = saved.schema;
			this.dirty = false;

			this.rebindCanvas();

			// The merge-tag picker lists this form's questions by label, and the
			// server builds that list from the *saved* schema. Without this, a
			// question added or renamed a moment ago is missing from the picker for
			// the rest of the session, which reads as the picker being broken
			// rather than as a cache.
			forgetMergeTags( saved.id );

			const summary = this.forms.find( ( candidate ) => candidate.id === saved.id );

			if ( summary ) {
				summary.title = saved.title;
			}

			const save = this.bar.querySelector< HTMLElement & { disabled: boolean } >( '[data-atfb-save]' );

			if ( save ) {
				save.disabled = true;
			}

			// A preview window open for this form is navigated to the fresh
			// render. Doing it here rather than in the preview module means an
			// autosave refreshes it too, so the paired window tracks the builder
			// without anybody pressing anything.
			refreshPreview( saved.id, saved.title, saved.previewUrl );

			if ( ! silent ) {
				notify( 'Form saved', saved.title );
			}
		} catch ( error ) {
			// A failed save is the one save event worth interrupting for, and
			// the shell's own status ring cannot say *why*.
			notify(
				i18n( 'saveFailed', 'Could not save' ),
				error instanceof Error ? error.message : '',
				'error'
			);
		}
	}

	/* ---------------------------------------------------------------- Forms */

	/** Opens a form. */
	private async open( id: number ): Promise< void > {
		if ( this.dirty && ! ( await confirmAction( 'You have unsaved changes. Discard them?' ) ) ) {
			return;
		}

		try {
			this.form = await api.getForm( id );
			this.schema = this.form.schema;
			this.selected = null;
			this.dirty = false;

			// The freshly-loaded schema is the history's first entry, so the first
			// undo returns to how the form was when it was opened rather than to
			// whatever the previously-open form looked like.
			this.history = [];
			this.historyAt = -1;
			this.snapshot();
		} catch ( error ) {
			this.fail( error );

			return;
		}

		this.renderBar();
		this.renderCanvas();
		this.renderInspector();
		this.announceIdentity();
	}

	/**
	 * Tells the shell which form this window is showing.
	 *
	 * That one call is what makes an entries window for the same form draw a tie
	 * to this one, and what fills the title bar's Related menu. Re-announced on
	 * every open, because the identity is the *form*, not the window.
	 */
	private announceIdentity(): void {
		if ( ! this.form ) {
			return;
		}

		setIdentity( this.root, formIdentity( this.form, runtime?.adminUrl ?? '' ) );
	}

	/** The template picker, for a new form. */
	private async showTemplates(): Promise< void > {
		if ( ! this.config ) {
			return;
		}

		const overlay = el( 'div', { class: 'atfb-overlay' } );

		const close = () => overlay.remove();

		const grid = el( 'div', {
			class: 'atfb-templates',
			children: this.config.templates.map( ( template ) =>
				el( 'button', {
					class: 'atfb-template',
					type: 'button',
					on: {
						click: async () => {
							close();

							try {
								const created = await api.createForm( { template: template.slug } );

								this.forms.unshift( {
									id: created.id,
									title: created.title,
									status: created.status,
									modified: created.modified,
									fields: created.schema.fields.length,
									theme: created.schema.settings.theme,
									entries: 0,
									unread: 0,
									views: 0,
									submissions: 0,
									shortcode: created.shortcode,
								} );

								this.form = created;
								this.schema = created.schema;
								this.selected = null;
								this.dirty = false;

								this.renderBar();
								this.renderCanvas();
								this.renderInspector();
								this.announceIdentity();
							} catch ( error ) {
								notify( 'Could not create the form', error instanceof Error ? error.message : '', 'error' );
							}
						},
					},
					children: [
						icon( template.icon ),
						el( 'strong', { text: template.label } ),
						el( 'span', { text: template.description } ),
					],
				} )
			),
		} );

		overlay.append(
			el( 'div', {
				class: 'atfb-modal',
				attrs: { role: 'dialog', 'aria-label': 'Start a new form' },
				children: [
					el( 'h2', { text: 'Start a new form' } ),
					grid,
					el( 'div', { class: 'atfb-modal__actions', children: [ button( 'Cancel', close ) ] } ),
				],
			} )
		);

		overlay.addEventListener( 'click', ( event ) => {
			if ( event.target === overlay ) {
				close();
			}
		} );

		document.addEventListener(
			'keydown',
			( event ) => {
				if ( event.key === 'Escape' ) {
					close();
				}
			},
			{ once: true }
		);

		this.root.append( overlay );
		grid.querySelector< HTMLElement >( 'button' )?.focus();
	}

	/** Shown when the site has no forms at all. */
	private renderFormsList(): void {
		clear( this.canvas );

		this.canvas.append(
			el( 'div', {
				class: 'atfb-empty',
				children: [
					el( 'h2', { text: 'No forms yet' } ),
					el( 'p', { text: 'Start from a template, or build one from nothing.' } ),
					button( 'New form', () => void this.showTemplates(), 'primary', 'plus-alt2' ),
				],
			} )
		);
	}

	/** Opens the entries window, or the entries admin page. */
	private openEntries(): void {
		const shell = ( window as unknown as { wp?: { os?: { openWindow?: ( id: string ) => boolean } } } ).wp?.os;

		if ( shell?.openWindow ) {
			shell.openWindow( 'allterrain-forms-entries' );

			return;
		}

		window.location.assign( `${ runtime?.adminUrl ?? '' }admin.php?page=allterrain-forms-entries` );
	}

	/* -------------------------------------------------------------- Palette */

	/** Draws the field palette, grouped. */
	private renderPalette(): void {
		if ( ! this.config ) {
			return;
		}

		clear( this.palette );

		const grouped = new Map< string, FieldType[] >();

		for ( const type of this.config.fieldTypes ) {
			const list = grouped.get( type.group ) ?? [];

			list.push( type );
			grouped.set( type.group, list );
		}

		const search = el( 'input', {
			class: 'atfb-input atfb-palette__search',
			type: 'search',
			placeholder: 'Search fields',
			attrs: { 'aria-label': 'Search field types' },
			on: {
				input: ( event: Event ) => {
					const term = ( event.target as HTMLInputElement ).value.toLowerCase().trim();

					this.palette.querySelectorAll< HTMLElement >( '.atfb-chip' ).forEach( ( chip ) => {
						const label = ( chip.textContent ?? '' ).toLowerCase();

						chip.hidden = term !== '' && ! label.includes( term );
					} );

					// A group whose every chip is filtered out is hidden too,
					// so the palette does not end up as a list of empty
					// headings.
					this.palette.querySelectorAll< HTMLElement >( '.atfb-group' ).forEach( ( group ) => {
						const visible = Array.from( group.querySelectorAll< HTMLElement >( '.atfb-chip' ) ).some(
							( chip ) => ! chip.hidden
						);

						group.hidden = ! visible;
					} );
				},
			},
		} );

		this.palette.append( search );

		for ( const [ slug, label ] of Object.entries( this.config.groups ) ) {
			const types = grouped.get( slug );

			if ( ! types?.length ) {
				continue;
			}

			this.palette.append(
				el( 'div', {
					class: 'atfb-group',
					children: [
						el( 'h3', { class: 'atfb-group__title', text: label } ),
						el( 'div', {
							class: 'atfb-group__items',
							children: types.map( ( type ) => this.paletteChip( type ) ),
						} ),
					],
				} )
			);
		}
	}

	/**
	 * One palette entry.
	 *
	 * A real `<button>`, so it is reachable by keyboard and activating it adds
	 * the field to the end of the form. The drag is layered on top of that
	 * rather than replacing it — `onClickOnly` is what the drag manager calls
	 * when a press never travelled far enough to become a drag, so one element
	 * serves both interactions without a click firing after a drop.
	 */
	private paletteChip( type: FieldType ): HTMLElement {
		const chip = el( 'button', {
			class: 'atfb-chip',
			type: 'button',
			title: type.description,
			attrs: { 'data-atf-type': type.type },
			children: [ icon( type.icon ), el( 'span', { text: type.label } ) ],
		} );

		chip.addEventListener( 'pointerdown', ( event ) => {
			const ghost = el( 'div', {
				class: 'atfb-chip atfb-chip--ghost',
				children: [ icon( type.icon ), el( 'span', { text: type.label } ) ],
			} );

			getDragManager().start( {
				payload: buildPayload( FIELD_PAYLOAD_TYPE, chip, { fieldType: type.type, isNew: true }, event, ghost ),
				origin: event,
				onClickOnly: () => this.addField( type.type ),
			} );
		} );

		// A press that becomes a drag must not also fire a click. The manager
		// records when a drag ended, and that window is what this checks.
		chip.addEventListener( 'click', ( event ) => {
			if ( getDragManager().recentlyEndedDrag() ) {
				event.preventDefault();
			}
		} );

		return chip;
	}

	/* --------------------------------------------------------------- Canvas */

	/** Draws the canvas for the current tab. */
	private renderCanvas(): void {
		clear( this.canvas );

		if ( ! this.schema || ! this.form ) {
			this.renderFormsList();

			return;
		}

		if ( this.tab !== 'build' ) {
			this.canvas.append( this.renderTabCanvas() );

			return;
		}

		const list = el( 'div', { class: 'atfb-canvas__list', attrs: { 'data-atfb-list': '' } } );

		if ( ! this.schema.fields.length ) {
			list.append(
				el( 'div', {
					class: 'atfb-placeholder',
					text: i18n( 'emptyCanvas', 'Drag a field from the left to begin.' ),
				} )
			);
		}

		this.schema.fields.forEach( ( field, index ) => {
			list.append( this.renderFieldCard( field, index ) );
		} );

		const inner = el( 'div', {
			class: 'atfb-canvas__inner',
			children: [
				el( 'p', {
					class: 'atfb-shortcode',
					text: this.form.shortcode,
					title: 'Paste this anywhere to place the form',
				} ),
				list,
			],
		} );

		this.canvas.append( inner );

		this.registerCanvasTarget( list );
		this.paintLogicMap( inner );

		// Repaints only when the theme or its overrides actually changed, so the
		// canvas does not ask the server for a render on every keystroke.
		void this.paintCanvasTheme();
	}

	/**
	 * Where a field's opening value comes from, asked in plain language.
	 *
	 * This box used to be free text under the hint
	 * `query:utm_source, user:email, user:name, site:name or date:today` — a list
	 * of five examples of a syntax nobody had been taught, two of which
	 * (`user:name`, `site:name`) were not even things the resolver understood. So
	 * the one person who typed exactly what the hint said got an empty field and
	 * no error, because an unrecognised source resolves to nothing.
	 *
	 * The sources are a closed set, so they are offered as a list. The stored
	 * value is still the same string — a form built before this opens in whichever
	 * mode its value already matches, and a plugin adding a source through
	 * `atf_resolve_prefill` still works via Advanced.
	 */
	private prefillControl( field: Field, update: ( key: string, value: unknown ) => void ): HTMLElement {
		// `query:` keeps its parameter name in a separate box, so the only thing
		// anybody types is the name itself.
		const isQuery = field.prefill.startsWith( 'query:' );
		const known = PREFILL_SOURCES.some( ( source ) => source.value === field.prefill );
		const mode = isQuery ? 'query' : ( known && field.prefill ) || ( field.prefill ? 'custom' : '' );

		const detail = el( 'div', { class: 'atfb-prefill__detail' } );
		const preview = el( 'p', { class: 'atfb-row__hint atfb-prefill__preview' } );

		const paint = ( current: string ) => {
			detail.replaceChildren();
			preview.replaceChildren();

			if ( 'query' === current ) {
				const name = field.prefill.startsWith( 'query:' ) ? field.prefill.slice( 6 ) : '';

				detail.append(
					textInput(
						name,
						( value ) => {
							const trimmed = value.trim();

							update( 'prefill', trimmed ? `query:${ trimmed }` : '' );
							paintPreview( `query:${ trimmed }` );
						},
						'utm_source'
					),
					el( 'p', {
						class: 'atfb-row__hint',
						text: 'The name of the parameter in the link people arrive on.',
					} )
				);
			}

			if ( 'custom' === current ) {
				detail.append(
					textInput(
						field.prefill,
						( value ) => {
							update( 'prefill', value );
							paintPreview( value );
						},
						'myplugin:something'
					),
					el( 'p', {
						class: 'atfb-row__hint',
						text: 'For a source another plugin has added through atf_resolve_prefill.',
					} )
				);
			}

			paintPreview( field.prefill );
		};

		// What the visitor will actually see in the box. The site's real values,
		// not invented ones — the whole reason the merge-tag catalogue is served
		// from PHP is that only the server knows them, and this reuses it rather
		// than growing a second endpoint that could disagree.
		const paintPreview = ( source: string ) => {
			preview.replaceChildren();

			if ( ! source ) {
				return;
			}

			if ( source.startsWith( 'query:' ) ) {
				const name = source.slice( 6 );

				if ( name ) {
					preview.textContent = `A visit to …/your-page/?${ name }=abc opens the form with “abc” in it.`;
				}

				return;
			}

			const tag = PREFILL_SOURCES.find( ( candidate ) => candidate.value === source )?.tag;

			if ( ! tag ) {
				return;
			}

			void mergeTags( this.form?.id ?? 0 ).then( ( groups ) => {
				for ( const group of groups ) {
					for ( const item of group.items ) {
						if ( item.tag === tag ) {
							// The caveat only belongs on the sources that have one. A
							// site name is a site name whoever is looking; an account
							// email is nothing at all to a visitor who never signed in,
							// and that is the difference worth stating.
							const personal = source.startsWith( 'user:' );

							if ( ! item.sample ) {
								preview.textContent = 'Empty unless the visitor is signed in.';

								return;
							}

							preview.textContent = personal
								? `Opens with “${ item.sample }” for you — empty for a visitor who is not signed in.`
								: `Opens with “${ item.sample }”.`;

							return;
						}
					}
				}
			} );
		};

		paint( mode );

		const picker = el( 'select', {
			class: 'atfb-input atfb-select',
			on: {
				change: ( event: Event ) => {
					const value = ( event.target as HTMLSelectElement ).value;

					// `query` and `custom` are modes, not sources: what gets stored
					// depends on what is typed into the box they reveal. Clearing
					// first means switching away from `user:email` and typing nothing
					// leaves the field with no prefill rather than the old one.
					update( 'prefill', 'query' === value || 'custom' === value ? '' : value );
					paint( value );
				},
			},
		} );

		picker.append(
			el( 'option', { value: '', text: 'Nothing — leave it empty', attrs: { selected: '' === mode } } )
		);

		for ( const group of PREFILL_GROUPS ) {
			const optgroup = document.createElement( 'optgroup' );

			optgroup.label = group;

			for ( const source of PREFILL_SOURCES.filter( ( candidate ) => candidate.group === group ) ) {
				optgroup.append(
					el( 'option', {
						value: source.value,
						text: source.label,
						attrs: { selected: source.value === mode },
					} )
				);
			}

			picker.append( optgroup );
		}

		const link = document.createElement( 'optgroup' );

		link.label = 'From the link they arrived on';
		link.append(
			el( 'option', { value: 'query', text: 'A parameter in the web address', attrs: { selected: 'query' === mode } } )
		);

		// Ungrouped, at the end. It is the escape hatch rather than a fifth
		// category, and filing it under a heading would imply it belongs to one.
		picker.append(
			link,
			el( 'option', { value: 'custom', text: 'Something else (advanced)', attrs: { selected: 'custom' === mode } } )
		);

		return row(
			'Pre-fill this with',
			el( 'div', { class: 'atfb-prefill', children: [ picker, detail, preview ] } ),
			'What the box already contains when the form opens. They can still change it.'
		);
	}

	/**
	 * A field's condition, drawn as its parts rather than as a sentence.
	 *
	 * "Shown when Can you make it? is Yes, I will be there" is five things in a
	 * row with nothing to separate them, and two of the five are text somebody
	 * typed — so the question ends in a question mark and the answer contains a
	 * comma, and the punctuation the sentence relies on for structure is also in
	 * the content. Reading it means parsing it.
	 *
	 * Drawn as parts, no parsing is needed: the referenced question is a chip,
	 * the answer is a chip, and the verb and comparison are quiet text between
	 * them. The whole row still carries the plain sentence as its `aria-label`,
	 * because a screen reader reading five chips as five unrelated fragments
	 * would be worse off than before.
	 *
	 * The question chip is a button that selects that field — the reference is
	 * the useful kind, the kind you can follow.
	 */
	private renderCondition( tokens: LogicToken[] ): HTMLElement {
		const broken = tokens.some( ( token ) => 'field' === token.kind && token.missing );

		return el( 'span', {
			class: `atfb-cond${ broken ? ' is-broken' : '' }`,
			attrs: { 'aria-label': tokensToText( tokens ) },
			children: [
				icon( 'randomize' ),
				...tokens.map( ( token ) => this.renderConditionToken( token ) ),
			],
		} );
	}

	/** One tagged part of a condition. */
	private renderConditionToken( token: LogicToken ): HTMLElement {
		if ( 'field' === token.kind && ! token.missing ) {
			const chip = el( 'button', {
				class: 'atfb-cond__chip atfb-cond__chip--field',
				type: 'button',
				text: token.text,
				title: 'Go to this question',
				// The row is inside a card that is itself a button; without this the
				// click selects the card the chip is *on* rather than the question it
				// names, which is the opposite of what it offers.
				on: {
					click: ( event: Event ) => {
						event.stopPropagation();
						this.selectField( token.fieldId );

						this.canvas
							.querySelector< HTMLElement >( `[data-atfb-card="${ CSS.escape( token.fieldId ) }"]` )
							?.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
					},
				},
			} );

			return chip;
		}

		const classes: Record< LogicToken[ 'kind' ], string > = {
			verb: 'atfb-cond__verb',
			field: 'atfb-cond__chip atfb-cond__chip--missing',
			operator: 'atfb-cond__op',
			value: 'atfb-cond__chip atfb-cond__chip--value',
			join: 'atfb-cond__join',
		};

		return el( 'span', { class: classes[ token.kind ], text: token.text } );
	}

	/**
	 * A disclosure panel that remembers whether it was open.
	 *
	 * `openByDefault` decides only what happens the *first* time a key is seen —
	 * a field that already has a condition opens showing it, because arriving at
	 * a field and being told nothing about a rule that governs it is worse than a
	 * little extra height. After that the person's own choice wins.
	 *
	 * What this deliberately does not do is derive `open` from the data inside
	 * it. Conditional logic used to: `open: logic.enabled`, so unticking "Only
	 * show this field sometimes" collapsed the panel around the checkbox that had
	 * just been clicked. Whether a panel is open is a question about the
	 * *person's attention*; whether a feature is on is a question about the
	 * *form*. Binding one to the other means neither can be set independently.
	 *
	 * @param key           Stable identity for this panel.
	 * @param summary       The panel's heading.
	 * @param children      What it contains.
	 * @param openByDefault Whether to open it the first time it is rendered.
	 * @return The panel.
	 */
	private section(
		key: string,
		summary: string,
		children: Array< Node | string | null | undefined | false >,
		openByDefault = false
	): HTMLElement {
		const details = el( 'details', {
			class: 'atfb-section',
			attrs: { open: this.openSections.get( key ) ?? openByDefault },
			children: [ el( 'summary', { text: summary } ), ...children ],
		} );

		details.addEventListener( 'toggle', () => this.openSections.set( key, details.open ) );

		return details;
	}

	/**
	 * The toolbar's toggle for the logic overlay.
	 *
	 * Hidden entirely on a form with no conditions. A control for a thing that
	 * is not there teaches nothing and takes up a slot in a toolbar that already
	 * has eight.
	 */
	private logicMapButton(): HTMLElement {
		const has = logicEdges( this.schema?.fields ?? [] ).length > 0;

		const toggle = button(
			this.logicMapOn ? 'Hide logic' : 'Show logic',
			() => {
				this.logicMapOn = ! this.logicMapOn;

				writeSetting( LOGIC_MAP_SETTING, this.logicMapOn ? 'on' : 'off' );

				this.renderBar();
				this.renderCanvas();
			},
			this.logicMapOn ? 'primary' : 'secondary',
			'randomize'
		);

		toggle.title = 'Draw a line from each question to the ones it decides.';
		toggle.hidden = ! has;

		return toggle;
	}

	/**
	 * Draws the conditional-logic connections over the canvas.
	 *
	 * Rebuilt with the canvas rather than kept alive across renders: the layer
	 * measures cards that this render has just replaced, and an instance holding
	 * a `ResizeObserver` on a detached element is a leak that also stops
	 * redrawing. Cheap enough — it is one `<svg>` and a handful of paths.
	 *
	 * @param inner The canvas element the layer covers.
	 */
	private paintLogicMap( inner: HTMLElement ): void {
		this.logicMap?.destroy();
		this.logicMap = null;

		const fields = this.schema?.fields ?? [];
		const edges = logicEdges( fields );

		if ( ! edges.length || ! this.logicMapOn ) {
			return;
		}

		// The strip the curves are drawn in. Reserved by shrinking the cards rather
		// than by letting the layer overflow: the canvas is a scroll box, so
		// anything drawn outside it is clipped — which is exactly what happened to
		// the first version's labels.
		inner.classList.add( 'has-logicmap' );

		const map = new LogicMap( inner );

		map.setEdges( edges );
		map.highlight( this.selected ?? '' );

		this.logicMap = map;

		// Hover lights the curves touching a card and dims the rest, which answers
		// "what does this one affect?" without a click. Delegated from the list so
		// it survives cards being added, removed and reordered.
		inner.addEventListener( 'pointerover', ( event ) => {
			const card = ( event.target as HTMLElement ).closest< HTMLElement >( '[data-atfb-card]' );

			map.highlight( card?.dataset.atfbCard ?? this.selected ?? '' );
		} );

		inner.addEventListener( 'pointerleave', () => map.highlight( this.selected ?? '' ) );
	}

	/** One field on the canvas. */
	private renderFieldCard( field: Field, index: number ): HTMLElement {
		const type = this.config?.fieldTypes.find( ( candidate ) => candidate.type === field.type );
		const selected = this.selected === field.id;

		// What the logic actually does, and how much this field decides for
		// others. A `LOGIC` badge said neither: it announced that a rule existed
		// and left finding out what it said as an exercise.
		const fields = this.schema?.fields ?? [];
		const condition = logicTokens( field, fields );
		const controls = controlCounts( fields ).get( field.id ) ?? 0;

		const card = el( 'div', {
			class: `atfb-card${ selected ? ' is-selected' : '' }`,
			attrs: {
				'data-atfb-card': field.id,
				'data-index': index,
				tabindex: '0',
				role: 'button',
				'aria-pressed': selected,
				'aria-label': `${ field.label || type?.label || field.type }, ${ index + 1 } of ${
					this.schema?.fields.length ?? 0
				}`,
			},
			children: [
				el( 'div', {
					class: 'atfb-card__grip',
					attrs: { 'aria-hidden': 'true' },
					children: [ icon( 'menu' ) ],
				} ),
				el( 'div', {
					class: 'atfb-card__body',
					children: [
						el( 'div', {
							class: 'atfb-card__head',
							children: [
								icon( type?.icon ?? 'dashicons-forms' ),
								el( 'span', { class: 'atfb-card__type', text: type?.label ?? field.type } ),
								this.requiredToggle( field ),
								controls
									? el( 'span', {
											class: 'atfb-badge atfb-badge--controls',
											text: 1 === controls ? 'controls 1 field' : `controls ${ controls } fields`,
											title: 'Other questions appear or disappear based on this answer.',
									  } )
									: null,
							],
						} ),
						// The field itself, drawn with the real front-end classes and the
						// form's own theme, with its text editable where it sits.
						renderFieldPreview( field, type, {
							// The live field is looked up by id on every write. A save
							// replaces `this.schema` with the server's normalised copy,
							// so the object this card was rendered from stops being the
							// one that gets serialised — see `PreviewHandlers`.
							edit: ( apply ) => {
								const live = this.liveField( field.id );

								if ( ! live ) {
									return;
								}

								apply( live );
								this.markDirty();
								this.syncInspector( live );
							},
							restructure: ( apply ) => {
								const live = this.liveField( field.id );

								if ( ! live ) {
									return;
								}

								this.snapshot();
								apply( live );
								this.markDirty();
								this.renderCanvas();
								this.renderInspector();
							},
						} ),
						condition.length ? this.renderCondition( condition ) : null,
					],
				} ),
				el( 'div', {
					class: 'atfb-card__actions',
					children: [
						this.cardAction( 'admin-page', 'Duplicate', () => this.duplicateField( field.id ) ),
						this.cardAction( 'trash', 'Delete', () => void this.deleteField( field.id ) ),
					],
				} ),
			],
		} );

		card.addEventListener( 'click', ( event ) => {
			if ( ( event.target as HTMLElement ).closest( '.atfb-card__actions' ) ) {
				return;
			}

			if ( getDragManager().recentlyEndedDrag() ) {
				return;
			}

			this.selectField( field.id );
		} );

		// Keyboard parity with the drag: Enter selects, and Alt with an arrow
		// moves the field. Without this a keyboard user could inspect a form but
		// never reorder one.
		card.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				this.selectField( field.id );

				return;
			}

			if ( event.altKey && ( event.key === 'ArrowUp' || event.key === 'ArrowDown' ) ) {
				event.preventDefault();
				this.moveField( field.id, event.key === 'ArrowUp' ? index - 1 : index + 1 );

				// The card is rebuilt by the move, so focus has to be restored
				// onto its replacement or it falls back to the document.
				window.requestAnimationFrame( () => {
					this.canvas
						.querySelector< HTMLElement >( `[data-atfb-card="${ CSS.escape( field.id ) }"]` )
						?.focus();
				} );
			}

			if ( event.key === 'Delete' || event.key === 'Backspace' ) {
				event.preventDefault();
				void this.deleteField( field.id );
			}
		} );

		card.addEventListener( 'pointerdown', ( event ) => {
			if ( ( event.target as HTMLElement ).closest( '.atfb-card__actions' ) ) {
				return;
			}

			getDragManager().start( {
				payload: buildPayload( FIELD_PAYLOAD_TYPE, card, { fieldId: field.id, field, isNew: false }, event ),
				origin: event,
			} );
		} );

		return card;
	}

	/**
	 * The required flag, as a toggle on the card rather than a badge.
	 *
	 * It was already displayed here as a read-only badge, and the switch that set
	 * it was in the inspector — so the canvas told you a field was required and
	 * made you go somewhere else to change it. Marking a question required is a
	 * decision you make while writing it, not afterwards.
	 */
	private requiredToggle( field: Field ): HTMLElement {
		const toggle = el( 'button', {
			class: `atfb-req${ field.required ? ' is-on' : '' }`,
			type: 'button',
			text: field.required ? 'Required' : 'Optional',
			title: field.required ? 'This must be answered. Click to make it optional.' : 'Click to make this required.',
			attrs: { 'aria-pressed': field.required ? 'true' : 'false' },
			on: {
				// The card is draggable and clicking it selects the field; neither
				// should happen when the target was this switch.
				pointerdown: ( event: Event ) => event.stopPropagation(),
				click: ( event: Event ) => {
					event.stopPropagation();
					field.required = ! field.required;
					this.markDirty();
					this.renderCanvas();
					this.renderInspector();
				},
			},
		} );

		return toggle;
	}

	/** A small icon button on a field card. */
	private cardAction( iconSlug: string, label: string, onClick: () => void ): HTMLElement {
		return el( 'button', {
			class: 'atfb-card__action',
			type: 'button',
			title: label,
			attrs: { 'aria-label': label },
			on: {
				click: ( event: Event ) => {
					event.stopPropagation();
					onClick();
				},
			},
			children: [ icon( iconSlug ) ],
		} );
	}

	/**
	 * Makes the canvas a drop target.
	 *
	 * Accepts this plugin's own field payload — from the palette, from this
	 * canvas, or from a *second* builder window, which is what the shell's
	 * shared drag manager buys and an iframe could not.
	 */
	private registerCanvasTarget( list: HTMLElement ): void {
		const marker = el( 'div', { class: 'atfb-marker', attrs: { 'aria-hidden': 'true' } } );

		const teardown = getDragManager().registerDropTarget( {
			id: `atfb-canvas-${ this.form?.id ?? 0 }`,
			element: list,
			accept: ( payload ) => payload.type === FIELD_PAYLOAD_TYPE,
			onEnter: () => list.classList.add( 'is-dropping' ),
			onLeave: () => {
				list.classList.remove( 'is-dropping' );
				marker.remove();
			},
			onDrop: ( session, position ) => {
				list.classList.remove( 'is-dropping' );
				marker.remove();

				const data = session.payload.data as {
					fieldType?: string;
					fieldId?: string;
					field?: Field;
					isNew?: boolean;
				};

				const source = data.fieldId
					? this.canvas.querySelector< HTMLElement >( `[data-atfb-card="${ CSS.escape( data.fieldId ) }"]` )
					: null;

				const index = insertionIndex( list, '.atfb-card', position.clientY, source ?? undefined );

				if ( data.isNew && data.fieldType ) {
					this.addField( data.fieldType, index );

					return;
				}

				if ( data.fieldId && this.schema?.fields.some( ( field ) => field.id === data.fieldId ) ) {
					this.moveField( data.fieldId, index );

					return;
				}

				// A field dragged in from another builder window: its id may
				// already be taken here, so it is added as a copy and the
				// schema's normaliser re-issues the id on save.
				if ( data.field ) {
					this.insertField( { ...data.field, id: '' } as Field, index );
				}
			},
		} );

		this.teardowns.push( teardown );

		// The insertion marker follows the pointer while a field is over the
		// canvas. Driven from the shell's own move event so it works with either
		// manager.
		const onMove = ( event: Event ) => {
			const detail = ( event as CustomEvent< { payload?: { type: string }; clientY?: number } > ).detail;

			if ( detail?.payload?.type !== FIELD_PAYLOAD_TYPE || ! list.classList.contains( 'is-dropping' ) ) {
				return;
			}

			const y = detail.clientY ?? 0;
			const index = insertionIndex( list, '.atfb-card', y );
			const cards = list.querySelectorAll< HTMLElement >( '.atfb-card' );

			if ( index >= cards.length ) {
				list.append( marker );
			} else {
				cards[ index ].before( marker );
			}
		};

		document.addEventListener( 'os.drag.move', onMove );
		this.teardowns.push( () => document.removeEventListener( 'os.drag.move', onMove ) );
	}

	/* -------------------------------------------------------- Field editing */

	/** Adds a field of a type, at an index or at the end. */
	private addField( type: string, index?: number ): void {
		const definition = this.config?.fieldTypes.find( ( candidate ) => candidate.type === type );

		if ( ! definition || ! this.schema ) {
			return;
		}

		const field = {
			id: this.nextFieldId(),
			type,
			label: definition.input ? definition.label : '',
			placeholder: '',
			hint: '',
			required: false,
			width: 'full',
			cssClass: '',
			default: '',
			choices: definition.choices
				? [
						{ label: 'First choice', value: 'first' },
						{ label: 'Second choice', value: 'second' },
				  ]
				: [],
			logic: { enabled: false, action: 'show', match: 'all', rules: [] },
			messages: {},
			prefill: '',
			...definition.settings,
		} as unknown as Field;

		this.insertField( field, index );
	}

	/** Puts a field into the schema. */
	private insertField( field: Field, index?: number ): void {
		if ( ! this.schema ) {
			return;
		}

		if ( ! field.id ) {
			field.id = this.nextFieldId();
		}

		this.snapshot();

		const at = index === undefined ? this.schema.fields.length : Math.max( 0, Math.min( index, this.schema.fields.length ) );

		this.schema.fields.splice( at, 0, field );
		this.selected = field.id;

		this.markDirty();
		this.renderCanvas();
		this.renderInspector();

		window.requestAnimationFrame( () => {
			this.canvas.querySelector< HTMLElement >( `[data-atfb-card="${ CSS.escape( field.id ) }"]` )?.focus();
		} );
	}

	/** Moves a field to an index. */
	private moveField( fieldId: string, index: number ): void {
		if ( ! this.schema ) {
			return;
		}

		const from = this.schema.fields.findIndex( ( field ) => field.id === fieldId );

		if ( from < 0 ) {
			return;
		}

		const clamped = Math.max( 0, Math.min( index, this.schema.fields.length - 1 ) );

		if ( from === clamped ) {
			return;
		}

		this.snapshot();

		const [ field ] = this.schema.fields.splice( from, 1 );

		// Removing first shifts everything after it down by one, so an index
		// captured before the removal is one too high when moving downwards.
		this.schema.fields.splice( clamped > from ? clamped - 1 : clamped, 0, field );

		this.markDirty();
		this.renderCanvas();
	}

	/** Copies a field, id and all but the id. */
	private duplicateField( fieldId: string ): void {
		if ( ! this.schema ) {
			return;
		}

		const index = this.schema.fields.findIndex( ( field ) => field.id === fieldId );

		if ( index < 0 ) {
			return;
		}

		const copy = JSON.parse( JSON.stringify( this.schema.fields[ index ] ) ) as Field;

		copy.id = this.nextFieldId();

		// A duplicate keeps its logic rules, which still point at the *original*
		// fields — that is almost always what somebody duplicating a
		// conditionally-shown field wants.
		this.insertField( copy, index + 1 );
	}

	/** Removes a field. */
	private async deleteField( fieldId: string ): Promise< void > {
		if ( ! this.schema ) {
			return;
		}

		const dependents = this.schema.fields.filter( ( field ) =>
			field.logic?.rules?.some( ( rule ) => rule.field === fieldId )
		);

		const message = dependents.length
			? `Delete this field? ${ dependents.length } other field${
					dependents.length === 1 ? '' : 's'
			  } use it in a condition, and those conditions will stop working.`
			: i18n( 'confirmDelete', 'Delete this? It cannot be undone.' );

		if ( ! ( await confirmAction( message, 'Delete field' ) ) ) {
			return;
		}

		this.snapshot();

		this.schema.fields = this.schema.fields.filter( ( field ) => field.id !== fieldId );

		if ( this.selected === fieldId ) {
			this.selected = null;
		}

		this.markDirty();
		this.renderCanvas();
		this.renderInspector();
	}

	/** Selects a field and shows it in the inspector. */
	private selectField( fieldId: string ): void {
		this.selected = fieldId;

		// Selection repaints the *selected state*, not the canvas.
		//
		// It used to call `renderCanvas()`, which rebuilds every card — and since
		// the card now contains the field's own editable label and options, that
		// destroyed the element the click had just put the caret in. Clicking into
		// a question to rewrite it therefore focused it and immediately lost it,
		// which read as the field being un-typeable.
		//
		// Nothing about the canvas's *structure* changes when the selection moves,
		// so nothing needs rebuilding: two class toggles say the same thing, keep
		// the caret, and are faster besides.
		for ( const card of this.canvas.querySelectorAll< HTMLElement >( '[data-atfb-card]' ) ) {
			const isSelected = card.dataset.atfbCard === fieldId;

			card.classList.toggle( 'is-selected', isSelected );
			card.setAttribute( 'aria-pressed', isSelected ? 'true' : 'false' );
		}

		this.logicMap?.highlight( fieldId );
		this.renderInspector();
	}

	/** A field id not already in use. */
	private nextFieldId(): string {
		const used = new Set( ( this.schema?.fields ?? [] ).map( ( field ) => field.id ) );
		let index = used.size + 1;

		while ( used.has( `f${ index }` ) ) {
			index++;
		}

		return `f${ index }`;
	}

	/* ------------------------------------------------------------ Inspector */

	/** Draws the inspector for whatever is selected. */
	private readonly renderInspector = raf( (): void => {
		clear( this.inspector );

		if ( ! this.schema ) {
			return;
		}

		// Only the Build tab has anything to inspect, and only the Build tab has
		// anywhere to drag a field *to*. So on the other tabs both side columns
		// go — a palette you cannot drop from is 240px of furniture, and a
		// permanent "nothing here" column is worse than no column. The width
		// they give back is width the settings and theme panes can use.
		this.root.classList.toggle( 'atfb--build-only-panes', this.tab !== 'build' );

		if ( this.tab !== 'build' ) {
			return;
		}

		const field = this.schema.fields.find( ( candidate ) => candidate.id === this.selected );

		if ( ! field ) {
			this.inspector.append(
				el( 'div', {
					class: 'atfb-placeholder',
					children: [
						el( 'p', { text: 'Select a field to change it.' } ),
						el( 'p', {
							class: 'atfb-hint',
							text: 'Drag a field from the palette, or press one to add it to the end.',
						} ),
					],
				} )
			);

			return;
		}

		const definition = this.config?.fieldTypes.find( ( candidate ) => candidate.type === field.type );
		const supports = definition?.supports ?? [];
		const update = ( key: string, value: unknown ) => {
			( field as unknown as Record< string, unknown > )[ key ] = value;
			this.markDirty();
			this.renderCanvas();
		};

		this.inspector.append(
			el( 'h3', { class: 'atfb-inspector__title', text: definition?.label ?? field.type } ),
			el( 'p', { class: 'atfb-hint', text: `Reference this field as {field:${ field.id }}` } )
		);

		if ( supports.includes( 'label' ) ) {
			this.inspector.append(
				row(
					'Label',
					bind( textInput( field.label, ( value ) => update( 'label', value ) ), 'label' )
				)
			);
		}

		if ( supports.includes( 'placeholder' ) ) {
			this.inspector.append(
				row(
					'Placeholder',
					bind( textInput( field.placeholder, ( value ) => update( 'placeholder', value ) ), 'placeholder' )
				)
			);
		}

		if ( supports.includes( 'hint' ) ) {
			this.inspector.append(
				row(
					'Hint',
					textInput( field.hint, ( value ) => update( 'hint', value ) ),
					'Shown under the field, and read out with it.'
				)
			);
		}

		if ( supports.includes( 'required' ) ) {
			this.inspector.append(
				checkbox( 'Required', field.required, ( value ) => update( 'required', value ) )
			);
		}

		if ( supports.includes( 'width' ) ) {
			this.inspector.append(
				row(
					'Width',
					select(
						field.width,
						[
							{ value: 'full', label: 'Full width' },
							{ value: 'two-thirds', label: 'Two thirds' },
							{ value: 'half', label: 'Half' },
							{ value: 'third', label: 'One third' },
							{ value: 'quarter', label: 'One quarter' },
						],
						( value ) => update( 'width', value )
					)
				)
			);
		}

		if ( definition?.choices ) {
			this.inspector.append( this.renderChoicesEditor( field, update ) );
		}

		if ( field.type === 'total' || supports.includes( 'formula' ) ) {
			this.inspector.append(
				row(
					'Formula',
					textInput( String( field.formula ?? '' ), ( value ) => update( 'formula', value ) ),
					'Reference fields with braces: {f1} * {f2} + 10. Functions: min, max, sum, avg, round, ceil, floor, abs, sqrt, pow.'
				),
				row( 'Currency symbol', textInput( String( field.currency ?? '' ), ( value ) => update( 'currency', value ) ) )
			);
		}

		this.inspector.append( this.renderValidationSection( field, supports, update ) );
		this.inspector.append( this.renderLogicSection( field, update ) );

		if ( supports.includes( 'prefill' ) ) {
			this.inspector.append( this.prefillControl( field, update ) );
		}

		if ( supports.includes( 'css' ) ) {
			this.inspector.append(
				row( 'CSS class', textInput( field.cssClass, ( value ) => update( 'cssClass', value ) ) )
			);
		}
	} );

	/** The choices editor, with drag-in image support. */
	private renderChoicesEditor( field: Field, update: ( key: string, value: unknown ) => void ): HTMLElement {
		const choices = ( field.choices ?? [] ) as Choice[];

		const list = el( 'div', { class: 'atfb-choices' } );

		choices.forEach( ( choice, index ) => {
			const rowEl = el( 'div', {
					class: 'atfb-choice-row',
					children: [
						// The image well only exists on a field that shows
						// pictures. Everywhere else it would be a column of
						// empty boxes for a setting that does nothing.
						field.type === 'image_choice' ? this.choiceImageWell( choice, index, field ) : null,
						bind( textInput( choice.label, ( value ) => {
							// Read *before* the assignment. This compared
							// `choice.value` with `choices[ index ].value` — the
							// same object — so it was always true and the value
							// followed the label unconditionally, which is the
							// one thing the comment says it must not do: an
							// entry stores the value, and rewriting it orphans
							// every submission already recorded under it.
							const mirroring = ! choice.value || choice.value === choice.label;

							choice.label = value;

							if ( mirroring ) {
								choice.value = value;
							}

							this.markDirty();
							this.syncCanvas( field );
						} ), `choice:${ index }:label` ),
						bind(
							textInput(
								choice.value,
								( value ) => {
									choice.value = value;
									this.markDirty();
								},
								'value'
							),
							`choice:${ index }:value`
						),
						field.type === 'quiz' || choice.points !== undefined
							? numberInput( String( choice.points ?? '' ), ( value ) => {
									choice.points = value === '' ? undefined : Number( value );
									this.markDirty();
							  } )
							: numberInput( String( choice.price ?? '' ), ( value ) => {
									choice.price = value === '' ? undefined : Number( value );
									this.markDirty();
							  } ),
						el( 'button', {
							class: 'atfb-card__action',
							type: 'button',
							attrs: { 'aria-label': `Remove ${ choice.label }` },
							on: {
								click: () => {
									choices.splice( index, 1 );
									update( 'choices', choices );
									this.renderInspector();
								},
							},
							children: [ icon( 'trash' ) ],
						} ),
					],
				} );

			list.append( rowEl );
		} );

		return el( 'div', {
			class: 'atfb-section',
			children: [
				el( 'h4', { text: 'Choices' } ),
				el( 'p', {
					class: 'atfb-hint',
					text: field.type === 'quiz' ? 'Label, value, points.' : 'Label, value, and a price for calculations.',
				} ),
				list,
				button(
					'Add choice',
					() => {
						choices.push( { label: '', value: '' } );
						update( 'choices', choices );
						this.renderInspector();
					},
					'ghost',
					'plus-alt2'
				),
				field.type === 'quiz'
					? row(
							'Correct answer',
							select(
								String( field.correct ?? '' ),
								[
									{ value: '', label: '—' },
									...choices.map( ( choice ) => ( { value: choice.value, label: choice.label } ) ),
								],
								( value ) => update( 'correct', value )
							)
					  )
					: null,
			],
		} );
	}

	/**
	 * The image well on one choice of an image-choice field.
	 *
	 * A drop target for media dragged out of WP Explorer. This is the clearest
	 * demonstration of why the builder is a native window: WP Explorer is a
	 * different window entirely, and its file tiles ride the same
	 * `wp.os.dragManager` this target registers with — so a photograph on the
	 * desktop can be dropped straight onto a form's option. Across an iframe
	 * boundary the two would never meet.
	 *
	 * The attachment id is what gets stored; the URL in the payload is used only
	 * to paint the thumbnail immediately, so the well fills the moment the drop
	 * lands rather than after a round trip.
	 */
	private choiceImageWell( choice: Choice, index: number, field: Field ): HTMLElement {
		const well = el( 'div', {
			class: `atfb-well${ choice.image ? ' has-image' : '' }`,
			attrs: {
				'data-choice': index,
				'aria-label': `Image for ${ choice.label || `choice ${ index + 1 }` }`,
			},
			children: [ choice.image ? el( 'span', { class: 'atfb-well__id', text: `#${ choice.image }` } ) : icon( 'format-image' ) ],
		} );

		const teardown = getDragManager().registerDropTarget( {
			id: `atfb-well-${ field.id }-${ index }`,
			element: well,
			// WP Explorer has used more than one payload slug across shell
			// versions, so every spelling this plugin knows about is accepted
			// rather than betting on one.
			accept: ( payload ) => MEDIA_PAYLOAD_TYPES.includes( payload.type ),
			onEnter: () => well.classList.add( 'is-dropping' ),
			onLeave: () => well.classList.remove( 'is-dropping' ),
			onDrop: ( session ) => {
				well.classList.remove( 'is-dropping' );

				const data = session.payload.data as {
					id?: number;
					attachmentId?: number;
					file?: { id?: number; url?: string };
					url?: string;
				};

				const id = Number( data.attachmentId ?? data.id ?? data.file?.id ?? 0 );

				if ( ! id ) {
					notify( 'That is not an image this field can use', '', 'error' );

					return;
				}

				choice.image = id;
				this.markDirty();
				this.renderInspector();
			},
		} );

		this.teardowns.push( teardown );

		// Clicking clears it, because there is otherwise no way to undo a drop
		// and the well is the only place the setting lives.
		well.addEventListener( 'click', () => {
			if ( ! choice.image ) {
				return;
			}

			choice.image = undefined;
			this.markDirty();
			this.renderInspector();
		} );

		return well;
	}

	/** Validation settings for a field. */
	private renderValidationSection(
		field: Field,
		supports: string[],
		update: ( key: string, value: unknown ) => void
	): HTMLElement {
		const rows: HTMLElement[] = [];

		const pairs: Array< [ string, string ] > = [];

		if ( supports.includes( 'minlength' ) ) {
			pairs.push( [ 'minlength', 'Minimum characters' ], [ 'maxlength', 'Maximum characters' ] );
		}

		if ( supports.includes( 'min' ) ) {
			pairs.push( [ 'min', 'Minimum' ], [ 'max', 'Maximum' ] );
		}

		if ( supports.includes( 'mindate' ) ) {
			pairs.push( [ 'minDate', 'Earliest date' ], [ 'maxDate', 'Latest date' ] );
		}

		for ( const [ key, label ] of pairs ) {
			rows.push(
				row(
					label,
					textInput( String( field[ key ] ?? '' ), ( value ) => update( key, value ) )
				)
			);
		}

		if ( supports.includes( 'pattern' ) ) {
			rows.push(
				row(
					'Pattern',
					textInput( String( field.pattern ?? '' ), ( value ) => update( 'pattern', value ) ),
					'A regular expression, without slashes.'
				)
			);
		}

		if ( supports.includes( 'unique' ) ) {
			rows.push(
				checkbox( 'No two people may submit the same value', Boolean( field.unique ), ( value ) =>
					update( 'unique', value )
				)
			);
		}

		const messages = ( field.messages ?? {} ) as Record< string, string >;

		rows.push(
			row(
				'Message when required',
				textInput( messages.required ?? '', ( value ) => {
					messages.required = value;
					update( 'messages', messages );
				} ),
				'Leave empty for the default wording.'
			)
		);

		return this.section( `validation:${ field.id }`, 'Validation', rows );
	}

	/** The conditional-logic editor. */
	private renderLogicSection( field: Field, update: ( key: string, value: unknown ) => void ): HTMLElement {
		const logic = field.logic;
		const others = ( this.schema?.fields ?? [] ).filter(
			( candidate ) => candidate.id !== field.id && candidate.type !== 'page_break'
		);

		const rules = el( 'div', { class: 'atfb-rules' } );

		logic.rules.forEach( ( rule, index ) => {
			rules.append(
				el( 'div', {
					class: 'atfb-rule',
					children: [
						select(
							rule.field,
							others.map( ( candidate ) => ( {
								value: candidate.id,
								label: candidate.label || candidate.id,
							} ) ),
							( value ) => {
								rule.field = value;
								this.markDirty();
							}
						),
						select(
							rule.operator,
							Object.entries( this.config?.operators ?? {} ).map( ( [ value, label ] ) => ( { value, label } ) ),
							( value ) => {
								rule.operator = value as typeof rule.operator;
								this.markDirty();
							}
						),
						textInput( rule.value, ( value ) => {
							rule.value = value;
							this.markDirty();
						} ),
						el( 'button', {
							class: 'atfb-card__action',
							type: 'button',
							attrs: { 'aria-label': 'Remove this rule' },
							on: {
								click: () => {
									logic.rules.splice( index, 1 );
									update( 'logic', logic );
									this.renderInspector();
								},
							},
							children: [ icon( 'trash' ) ],
						} ),
					],
				} )
			);
		} );

		return this.section(
			`logic:${ field.id }`,
			'Conditional logic',
			[
				checkbox( 'Only show this field sometimes', logic.enabled, ( value ) => {
					logic.enabled = value;
					update( 'logic', logic );
					this.renderInspector();
				} ),
				logic.enabled
					? el( 'div', {
							children: [
								el( 'div', {
									class: 'atfb-rule-head',
									children: [
										select(
											logic.action,
											[
												{ value: 'show', label: 'Show' },
												{ value: 'hide', label: 'Hide' },
											],
											( value ) => {
												logic.action = value as 'show' | 'hide';
												update( 'logic', logic );
											}
										),
										el( 'span', { text: 'this field when' } ),
										select(
											logic.match,
											[
												{ value: 'all', label: 'all' },
												{ value: 'any', label: 'any' },
											],
											( value ) => {
												logic.match = value as 'all' | 'any';
												update( 'logic', logic );
											}
										),
										el( 'span', { text: 'of these match:' } ),
									],
								} ),
								rules,
								button(
									'Add rule',
									() => {
										logic.rules.push( {
											field: others[ 0 ]?.id ?? '',
											operator: 'is',
											value: '',
										} );
										update( 'logic', logic );
										this.renderInspector();
									},
									'ghost',
									'plus-alt2'
								),
							],
					  } )
					: null,
			],
			// A field that already has a condition opens showing it: being told a
			// rule governs this field and not what it says is the problem the
			// whole logic display exists to solve.
			logic.enabled
		);
	}

	/* ------------------------------------------------------------ Tab panes */

	/** The canvas contents for the non-Build tabs. */
	private renderTabCanvas(): HTMLElement {
		if ( ! this.schema ) {
			return el( 'div' );
		}

		if ( this.tab === 'theme' ) {
			return mountThemeControls( {
				themes: this.themes,
				tokens: this.config?.tokens ?? [],
				activeSlug: this.schema.settings.theme,
				overrides: this.schema.settings.themeOverrides,
				onTheme: ( slug ) => {
					this.schema!.settings.theme = slug;
					this.markDirty();
				},
				onOverride: ( token, value ) => {
					if ( value === '' ) {
						delete this.schema!.settings.themeOverrides[ token ];
					} else {
						this.schema!.settings.themeOverrides[ token ] = value;
					}

					this.markDirty();
				},
				previewFor: ( slug, overrides ) => this.previewHtml( slug, overrides ),
				onThemesChanged: ( themes ) => {
					this.themes = themes;
				},
			} );
		}

		if ( this.tab === 'settings' ) {
			return this.renderSettingsPane();
		}

		if ( this.tab === 'notify' ) {
			return this.renderNotificationsPane();
		}

		return this.renderConfirmationsPane();
	}

	/**
	 * Puts the form's own theme tokens onto the canvas.
	 *
	 * The previews on the canvas use the real front-end classes, so they are
	 * already styled by `form.css` — but `form.css` reads everything from custom
	 * properties, and without them it falls back to the built-in defaults. The
	 * result would be a canvas that looks like Clean whatever theme the form is
	 * set to, which is the one thing a WYSIWYG canvas must not do.
	 *
	 * The values come from the server's own renderer rather than being resolved
	 * again here. A form's theme is a base theme plus per-form overrides plus
	 * whatever `atf_theme_tokens` filters did to it, and a second resolver in
	 * TypeScript would be a second answer to "what colour is this" — the same
	 * twin-engine problem the logic and calculation code goes to some length to
	 * avoid. One render is asked for, its `<style>` block is lifted, and its
	 * selector is repointed at the canvas.
	 *
	 * Failure is silent on purpose: no tokens means the previews render in the
	 * default theme, which is a worse-looking canvas and a working builder.
	 */
	private async paintCanvasTheme(): Promise< void > {
		if ( ! this.form || ! this.schema ) {
			return;
		}

		const theme = this.schema.settings.theme;
		const signature = JSON.stringify( [ theme, this.schema.settings.themeOverrides ] );

		if ( signature === this.canvasThemeSignature ) {
			return;
		}

		this.canvasThemeSignature = signature;

		try {
			const html = await this.previewHtml( theme, this.schema.settings.themeOverrides ?? {} );
			const block = /<style>([\s\S]*?)<\/style>/.exec( html );

			if ( ! block ) {
				return;
			}

			// The server scopes the block to the instance it rendered
			// (`#atf-12-1 .atf-form`). The canvas has many previews and no
			// instance, so the scope becomes the class they all carry.
			const css = block[ 1 ].replace( /#atf-[\d-]+\s+\.atf-form/g, '.atfb .atfb-preview' );

			this.canvasTheme.textContent = css;

			if ( ! this.canvasTheme.isConnected ) {
				this.root.append( this.canvasTheme );
			}
		} catch {
			// See above: the canvas simply keeps the default look.
		}
	}

	/** Renders the current schema to HTML for a preview. */
	private async previewHtml( theme: string, overrides: Record< string, string > ): Promise< string > {
		if ( ! this.form || ! this.schema ) {
			return '';
		}

		const schema = JSON.parse( JSON.stringify( this.schema ) ) as FormSchema;

		schema.settings.theme = theme;
		schema.settings.themeOverrides = overrides;

		const { html } = await api.preview( this.form.id, { schema, theme } );

		return html;
	}

	/** The form's own settings. */
	private renderSettingsPane(): HTMLElement {
		const settings = this.schema!.settings;

		const set = ( path: string, value: unknown ) => {
			const parts = path.split( '.' );
			let target = settings as unknown as Record< string, unknown >;

			for ( let i = 0; i < parts.length - 1; i++ ) {
				target = target[ parts[ i ] ] as Record< string, unknown >;
			}

			target[ parts[ parts.length - 1 ] ] = value;
			this.markDirty();
		};

		return el( 'div', {
			class: 'atfb-pane',
			children: [
				el( 'h2', { text: 'Settings' } ),

				el( 'section', {
					children: [
						el( 'h3', { text: 'Submitting' } ),
						row( 'Button label', textInput( settings.submitLabel, ( value ) => set( 'submitLabel', value ) ) ),
						checkbox( 'Submit without reloading the page', settings.ajax, ( value ) => set( 'ajax', value ) ),
						row(
							'Progress indicator',
							select(
								settings.progressBar,
								[
									{ value: 'steps', label: 'Numbered steps' },
									{ value: 'bar', label: 'A bar' },
									{ value: 'none', label: 'None' },
								],
								( value ) => set( 'progressBar', value )
							),
							'Only shown on forms with a page break.'
						),
					],
				} ),

				el( 'section', {
					children: [
						el( 'h3', { text: 'Who can fill this in' } ),
						checkbox( 'Only logged-in users', settings.requireLogin, ( value ) => set( 'requireLogin', value ) ),
						row(
							'Message for everyone else',
							textInput( settings.loginMessage, ( value ) => set( 'loginMessage', value ) )
						),
						row(
							'Open from',
							el( 'input', {
								class: 'atfb-input',
								type: 'datetime-local',
								value: settings.schedule.start,
								on: {
									input: ( event: Event ) =>
										set( 'schedule.start', ( event.target as HTMLInputElement ).value ),
								},
							} )
						),
						row(
							'Closes',
							el( 'input', {
								class: 'atfb-input',
								type: 'datetime-local',
								value: settings.schedule.end,
								on: {
									input: ( event: Event ) => set( 'schedule.end', ( event.target as HTMLInputElement ).value ),
								},
							} )
						),
						row(
							'Message when closed',
							textInput( settings.schedule.message, ( value ) => set( 'schedule.message', value ) )
						),
						row(
							'Stop after this many submissions',
							numberInput( String( settings.limit.total || '' ), ( value ) =>
								set( 'limit.total', Number( value ) || 0 )
							),
							'0 means no limit.'
						),
						row(
							'Submissions per logged-in user',
							numberInput( String( settings.limit.perUser || '' ), ( value ) =>
								set( 'limit.perUser', Number( value ) || 0 )
							)
						),
					],
				} ),

				el( 'section', {
					children: [
						el( 'h3', { text: 'Spam' } ),
						el( 'p', {
							class: 'atfb-hint',
							text: 'No captcha. Nothing here asks the visitor to prove anything.',
						} ),
						checkbox( 'Honeypot field', settings.spam.honeypot, ( value ) => set( 'spam.honeypot', value ) ),
						row(
							'Reject submissions faster than (seconds)',
							numberInput( String( settings.spam.timeTrap ), ( value ) =>
								set( 'spam.timeTrap', Number( value ) || 0 )
							),
							'A human cannot fill in a form in under a second. A script can.'
						),
						row(
							'Submissions allowed per hour, per address',
							numberInput( String( settings.spam.rateLimit ), ( value ) =>
								set( 'spam.rateLimit', Number( value ) || 0 )
							)
						),
						row(
							'Blocked words',
							textArea( settings.spam.blocklist, ( value ) => set( 'spam.blocklist', value ), 4 ),
							'One per line.'
						),
						checkbox( 'Use Akismet when it is installed', settings.spam.akismet, ( value ) =>
							set( 'spam.akismet', value )
						),
						checkbox( 'Ask a simple sum before sending', settings.spam.challenge, ( value ) =>
							set( 'spam.challenge', value )
						),
						el( 'p', {
							class: 'atfb-hint',
							text:
								'Only for a form under sustained attack — it is the one check here that asks the '
								+ 'visitor to do something. Still kinder than an image captcha: it is answerable by '
								+ 'a screen reader, and it hands no data to anyone.',
						} ),
					],
				} ),

				el( 'section', {
					children: [
						el( 'h3', { text: 'Storage and privacy' } ),
						checkbox( 'Keep entries', settings.storage.entries, ( value ) => set( 'storage.entries', value ) ),
						checkbox( 'Record IP addresses', settings.storage.ip, ( value ) => set( 'storage.ip', value ) ),
						checkbox( 'Anonymise recorded IP addresses', settings.storage.anonymise, ( value ) =>
							set( 'storage.anonymise', value )
						),
						row(
							'Delete entries after (days)',
							numberInput( String( settings.storage.retention || '' ), ( value ) =>
								set( 'storage.retention', Number( value ) || 0 )
							),
							'0 keeps them forever. Anything else deletes automatically, every day.'
						),
					],
				} ),

				el( 'section', {
					children: [
						el( 'h3', { text: 'Save and continue later' } ),
						checkbox( 'Let people save a half-finished form', settings.resume.enabled, ( value ) =>
							set( 'resume.enabled', value )
						),
						row(
							'Keep a saved form for (days)',
							numberInput( String( settings.resume.days ), ( value ) =>
								set( 'resume.days', Math.max( 1, Number( value ) || 30 ) )
							)
						),
						el( 'p', {
							class: 'atfb-hint',
							text:
								'The link this creates is the only key to those answers — anyone holding it can read them. '
								+ 'For genuinely sensitive questions, require login instead.',
						} ),
					],
				} ),
			],
		} );
	}

	/** A one-line input that understands merge tags. */
	private taggableInput(
		value: string,
		onChange: ( value: string ) => void,
		placeholder = ''
	): HTMLElement {
		return taggable( textInput( value, onChange, placeholder ), { formId: this.form!.id } );
	}

	/** A multi-line input that understands merge tags. */
	private taggableArea( value: string, onChange: ( value: string ) => void, rows = 6 ): HTMLElement {
		return taggable( textArea( value, onChange, rows ), { formId: this.form!.id } );
	}

	/**
	 * Who the notification goes to, asked in plain language.
	 *
	 * Almost every notification is addressed one of three ways, and only one of
	 * them has anything to do with merge tags:
	 *
	 * - to whoever runs the site — `{admin_email}`, and the person should never
	 *   have to learn that;
	 * - to a fixed address they type;
	 * - back to the visitor, at whatever address they gave — which means naming
	 *   one of the form's own email questions, the case where `{field:f2}` used
	 *   to be the entire interface.
	 *
	 * So the choice is offered as a choice, the email questions are listed by
	 * their labels, and the free-text box appears only for the fourth case —
	 * several addresses, or a tag we have not thought of. The stored value is
	 * still a plain string of tags, so nothing about the format changed and a form
	 * built before this existed opens in whichever mode its value already
	 * matches.
	 */
	private recipientControl( notification: Notification ): HTMLElement {
		const emailFields = this.schema!.fields.filter( ( field ) => 'email' === field.type );

		const modeOf = ( value: string ): string => {
			if ( '{admin_email}' === value.trim() ) {
				return 'admin';
			}

			const named = value.trim().match( /^\{field:([a-z0-9_-]+)\}$/i );

			if ( named && emailFields.some( ( field ) => field.id === named[ 1 ] ) ) {
				return `field:${ named[ 1 ] }`;
			}

			// Anything else with a tag in it is beyond the simple choices; a plain
			// address is not.
			return /\{/.test( value ) ? 'custom' : 'address';
		};

		const options = [
			{ value: 'admin', label: 'Whoever runs this site' },
			...emailFields.map( ( field ) => ( {
				value: `field:${ field.id }`,
				label: `The person who filled it in — ${ field.label || 'their email answer' }`,
			} ) ),
			{ value: 'address', label: 'A specific email address' },
			{ value: 'custom', label: 'Something else (advanced)' },
		];

		const mode = modeOf( notification.to );
		const detail = el( 'div', { class: 'atfb-recipient__detail' } );

		const paintDetail = ( current: string ) => {
			detail.replaceChildren();

			if ( 'address' === current ) {
				detail.append(
					textInput(
						/\{/.test( notification.to ) ? '' : notification.to,
						( value ) => {
							notification.to = value;
							this.markDirty();
						},
						'name@example.com'
					)
				);

				return;
			}

			if ( 'custom' === current ) {
				detail.append(
					this.taggableInput(
						notification.to,
						( value ) => {
							notification.to = value;
							this.markDirty();
						},
						'{admin_email}, sales@example.com'
					),
					el( 'p', {
						class: 'atfb-row__hint',
						text: 'Separate several addresses with commas.',
					} )
				);
			}
		};

		paintDetail( mode );

		return row(
			'Send it to',
			el( 'div', {
				class: 'atfb-recipient',
				children: [
					select( mode, options, ( value ) => {
						if ( 'admin' === value ) {
							notification.to = '{admin_email}';
						} else if ( value.startsWith( 'field:' ) ) {
							notification.to = `{${ value }}`;
						} else if ( 'address' === value ) {
							// Cleared rather than carried over: the previous value was a
							// tag, and leaving it in a box labelled "email address" invites
							// somebody to send to a literal `{admin_email}`.
							notification.to = /\{/.test( notification.to ) ? '' : notification.to;
						}

						this.markDirty();
						paintDetail( value );
					} ),
					detail,
				],
			} ),
			emailFields.length
				? undefined
				: 'Add an Email question on the Build tab to reply straight back to the visitor.'
		);
	}

	/** The notification editor. */
	private renderNotificationsPane(): HTMLElement {
		const notifications = this.schema!.notifications;

		const list = el( 'div', { class: 'atfb-list' } );

		if ( ! notifications.length ) {
			list.append(
				el( 'p', {
					class: 'atfb-hint',
					text: 'With none set up, one email goes to the site administrator with every answer in it.',
				} )
			);
		}

		notifications.forEach( ( notification, index ) => {
			list.append(
				this.section(
					`notification:${ notification.id }`,
					notification.name || `Notification ${ index + 1 }`,
					[
						row(
							'Name',
							textInput( notification.name, ( value ) => {
								notification.name = value;
								this.markDirty();
							} )
						),
						this.recipientControl( notification ),
						row(
							'Reply to',
							this.taggableInput(
								notification.replyTo,
								( value ) => {
									notification.replyTo = value;
									this.markDirty();
								},
								'Leave empty to reply to you'
							),
							'Set this to the visitor’s email address and hitting Reply answers them directly.'
						),
						row(
							'Subject',
							this.taggableInput( notification.subject, ( value ) => {
								notification.subject = value;
								this.markDirty();
							} )
						),
						row(
							'Message',
							this.taggableArea(
								notification.message,
								( value ) => {
									notification.message = value;
									this.markDirty();
								},
								8
							)
						),
						checkbox( 'Attach uploaded files', notification.attachFiles, ( value ) => {
							notification.attachFiles = value;
							this.markDirty();
						} ),
						button(
							'Delete this notification',
							() => {
								notifications.splice( index, 1 );
								this.markDirty();
								this.renderCanvas();
							},
							'danger'
						),
					]
				)
			);
		} );

		return el( 'div', {
			class: 'atfb-pane',
			children: [
				el( 'h2', { text: 'Notifications' } ),
				list,
				button(
					'Add a notification',
					() => {
						notifications.push( {
							id: `n${ notifications.length + 1 }`,
							enabled: true,
							name: 'Notification',
							to: '{admin_email}',
							cc: '',
							bcc: '',
							replyTo: '',
							fromName: '',
							fromEmail: '',
							subject: 'New submission',
							message: '{all_fields}',
							attachFiles: false,
							logic: { enabled: false, action: 'show', match: 'all', rules: [] },
						} );

						this.markDirty();
						this.renderCanvas();
					},
					'primary',
					'plus-alt2'
				),
			],
		} );
	}

	/**
	 * The part of a confirmation that depends on what it does.
	 *
	 * "Send them to a page" used to render the same free-text URL box as "Send
	 * them to a URL", which made the two options identical in every visible way
	 * while writing to different fields — so picking the page option and typing an
	 * address stored a URL the confirmation would never read. A page is chosen
	 * from the site's pages, which is the only reading of that option that means
	 * anything.
	 */
	private confirmationDetail( confirmation: Confirmation ): HTMLElement {
		if ( 'message' === confirmation.type ) {
			return row(
				'Message',
				this.taggableArea(
					confirmation.message,
					( value ) => {
						confirmation.message = value;
						this.markDirty();
					},
					5
				),
				'Insert an answer to greet them by name, or show back what they sent.'
			);
		}

		// Both of the "send them somewhere" kinds can carry query parameters, so
		// the box is shared. It was in the schema and honoured by the server from
		// the start with nothing in the builder to set it — a feature that exists
		// and cannot be reached is a feature nobody has.
		const query = row(
			'Extra query parameters',
			this.taggableInput(
				confirmation.query,
				( value ) => {
					confirmation.query = value;
					this.markDirty();
				},
				'ref={entry:id}&name={field:f1}'
			),
			'Added to the address, so the page they land on can read them. Leave empty for none.'
		);

		if ( 'redirect' === confirmation.type ) {
			return el( 'div', {
				children: [
					row(
						'Web address',
						this.taggableInput(
							confirmation.url,
							( value ) => {
								confirmation.url = value;
								this.markDirty();
							},
							'https://example.com/thank-you'
						),
						'A full address, starting with https://.'
					),
					query,
				],
			} );
		}

		// The whole control is rebuilt when the list arrives rather than having its
		// options swapped in place. `select()` may return a native `<select>` or an
		// `<os-select>` depending on what the page has, and those spell their
		// options and their value differently — reaching into one of them from here
		// would mean this function knowing which it got, which is exactly what
		// `select()` exists to hide.
		const holder = el( 'div', { class: 'atfb-pagepicker' } );

		const paint = ( options: Array< { value: string; label: string } > ) => {
			holder.replaceChildren(
				select( String( confirmation.pageId || 0 ), options, ( value ) => {
					confirmation.pageId = Number( value ) || 0;
					this.markDirty();
				} )
			);
		};

		paint( [ { value: '0', label: 'Loading pages…' } ] );

		// Loaded rather than shipped in the config blob: a site with a thousand
		// pages should not pay for the list on every admin page load, and this is
		// the only screen that wants it.
		void api
			.pages()
			.then( ( pages ) => {
				paint( [
					{ value: '0', label: 'Choose a page…' },
					...pages.map( ( page ) => ( { value: String( page.id ), label: page.title } ) ),
				] );
			} )
			.catch( () => {
				paint( [ { value: '0', label: 'Could not load the pages' } ] );
			} );

		return row(
			'Page',
			holder,
			'They are sent to this page after submitting. Its own content is shown, not the form’s message.'
		);
	}

	/** The confirmation editor. */
	private renderConfirmationsPane(): HTMLElement {
		const confirmations = this.schema!.confirmations;

		const list = el( 'div', { class: 'atfb-list' } );

		if ( ! confirmations.length ) {
			list.append(
				el( 'p', { class: 'atfb-hint', text: 'With none set up, the form says thank you and stops.' } )
			);
		}

		confirmations.forEach( ( confirmation, index ) => {
			// Swapped in place when the kind changes, rather than re-rendering the
			// pane.
			//
			// The bug this fixes: changing "What happens" called `renderCanvas()`,
			// which rebuilds every `<details>` in the list — and a rebuilt
			// `<details>` is closed. The whole section collapsed the instant the
			// choice was made, so the control it was supposed to reveal was never
			// seen. From the outside that is indistinguishable from the two other
			// options doing nothing at all, which is exactly how it was reported.
			const detail = el( 'div', { class: 'atfb-confirm__detail' } );

			const paintDetail = () => {
				detail.replaceChildren( this.confirmationDetail( confirmation ) );
			};

			paintDetail();

			list.append(
				this.section(
					`confirmation:${ confirmation.id }`,
					confirmation.name || `Confirmation ${ index + 1 }`,
					[
						row(
							'Name',
							textInput( confirmation.name, ( value ) => {
								confirmation.name = value;
								this.markDirty();
							} )
						),
						row(
							'What happens',
							select(
								confirmation.type,
								[
									{ value: 'message', label: 'Show a message' },
									{ value: 'redirect', label: 'Send them to a URL' },
									{ value: 'page', label: 'Send them to a page' },
								],
								( value ) => {
									confirmation.type = value as typeof confirmation.type;
									this.markDirty();
									paintDetail();
								}
							)
						),
						detail,
						button(
							'Delete this confirmation',
							() => {
								confirmations.splice( index, 1 );
								this.markDirty();
								this.renderCanvas();
							},
							'danger'
						),
					]
				)
			);
		} );

		return el( 'div', {
			class: 'atfb-pane',
			children: [
				el( 'h2', { text: 'Confirmations' } ),
				el( 'p', {
					class: 'atfb-hint',
					text: 'The first one whose conditions match is the one they see.',
				} ),
				list,
				button(
					'Add a confirmation',
					() => {
						confirmations.push( {
							id: `c${ confirmations.length + 1 }`,
							enabled: true,
							name: 'Confirmation',
							type: 'message',
							message: 'Thank you. Your submission has been received.',
							url: '',
							pageId: 0,
							query: '',
							logic: { enabled: false, action: 'show', match: 'all', rules: [] },
						} );

						this.markDirty();
						this.renderCanvas();
					},
					'primary',
					'plus-alt2'
				),
			],
		} );
	}

	/**
	 * Opens the form's real front-end preview.
	 *
	 * The same code path the title bar's eye takes, so the toolbar button and
	 * the eye cannot drift apart. Inside OpenStation it opens a window paired
	 * with this one; on a plain admin page it opens a tab.
	 */
	private async preview(): Promise< void > {
		await openPreview( {
			current: () =>
				this.form ? { id: this.form.id, title: this.form.title, previewUrl: this.form.previewUrl } : null,
			isDirty: () => this.dirty,
			save: () => this.save( true ),
		} );
	}
}

/** Mounts the builder into whatever root is on the page. */
let mounted: Builder | null = null;
let mountedRoot: HTMLElement | null = null;

export function mountBuilder(): void {
	// One *live* builder per document.
	//
	// Two roots can legitimately exist for a moment — the desktop shell renders
	// the admin page's markup and the native window's into the same document —
	// and mounting both gives two instances autosaving the same form, able to
	// overwrite each other's saves.
	//
	// But "one, ever" is too strong, and was its own bug: closing the window
	// takes its DOM with it, and a latch that never releases meant reopening the
	// builder produced a window stuck on "Loading your forms…" forever. The
	// question is not whether one was *ever* mounted, but whether the one that
	// was is still on screen.
	if ( mountedRoot?.isConnected ) {
		return;
	}

	// The previous instance's window is gone; release its listeners rather than
	// leaving a `beforeunload` handler and an autosave timer behind for every
	// open-and-close.
	if ( mounted ) {
		mounted.destroy();
		mounted = null;
		mountedRoot = null;
	}

	const root = document.querySelector< HTMLElement >( '[data-atfb-root]:not([data-atfb-mounted])' );

	if ( ! root ) {
		return;
	}

	// Flagged before the await, not after. `mountBuilder()` is called from two
	// places — DOM ready and the shell's `os-window-content-loaded` — and an
	// await between the guard and the flag is a window big enough for both to
	// pass it and mount two builders on one page.
	root.dataset.atfbMounted = '1';
	mountedRoot = root;

	void whenComponents().then( () => {
		// The window may have been closed while the kit was loading.
		if ( ! root.isConnected ) {
			return;
		}

		mounted = new Builder( root );

		void mounted.start();
	} );
}

function boot(): void {
	mountBuilder();
	handOffToWindow();
}

watchHandoffButton();

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}

// The shell mounts a native window's markup after this bundle has already run,
// so the window's own render callback fires this to mount into it.
document.addEventListener( 'os-window-content-loaded', mountBuilder );

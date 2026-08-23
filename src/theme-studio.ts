/**
 * The Theme Studio.
 *
 * A theme is a flat map of design tokens, so an editor for one is a control per
 * token and a live preview beside it. There is no theme code to write, which is
 * the whole claim: ten themes ship, and an eleventh is made by duplicating one
 * and moving sliders.
 *
 * The control list is **not** hard-coded here. It comes from `/config`, which
 * derives it from `alltfo_theme_token_defaults()` — so a plugin that adds a token
 * through the `alltfo_theme_tokens` filter gets an editor for it without touching
 * this file. That is what makes the "expandable without code" promise true for
 * the editor as well as the renderer.
 *
 * The same module backs two surfaces: the builder's Theme tab, which edits one
 * form's overrides, and the Theme Studio window, which makes reusable themes.
 * They differ only in what they write to.
 */

import { api } from './api';
import { button, clear, confirmAction, debounce, el, notify, pinWindowBodyScroll, row, select, textInput } from './ui';
import { dialOwning, quickDials, type QuickDial, type Tokens } from './theme-quick';
import type { FormSummary, Theme, ThemeToken } from './types';

/** What a mounted studio needs to know. */
export interface ThemeControlsOptions {
	themes: Theme[];
	tokens: ThemeToken[];
	activeSlug: string;
	overrides: Record< string, string >;
	/** The form picked a different theme. */
	onTheme: ( slug: string ) => void;
	/** One token was overridden, or cleared with an empty string. */
	onOverride: ( token: string, value: string ) => void;
	/**
	 * The whole override set was replaced at once — a theme switch, a save or a
	 * delete clears it wholesale rather than token by token. Without this the
	 * host's copy keeps every stale value: the studio previews a clean theme
	 * while the published form still carries the old tuning.
	 */
	onOverridesReplaced?: ( overrides: Record< string, string > ) => void;
	/** Renders the current form with a theme applied, for the preview pane. */
	previewFor: ( slug: string, overrides: Record< string, string > ) => Promise< string >;
	/** The theme list changed — a save or a delete. */
	onThemesChanged: ( themes: Theme[] ) => void;
	/** True in the standalone Studio window, where saving a theme is the point. */
	standalone?: boolean;
}

/** Builds the studio and returns its root element. */
export function mountThemeControls( options: ThemeControlsOptions ): HTMLElement {
	let active = options.activeSlug;
	let overrides = { ...options.overrides };
	let themes = options.themes;

	/**
	 * Swaps the override set wholesale and tells the host.
	 *
	 * `overrides = {}` on its own only changes this closure's copy; the host —
	 * the form's schema, in the builder — has to be told or the two disagree
	 * until the next per-token edit happens to land on every stale key.
	 */
	const replaceOverrides = ( next: Record< string, string > ) => {
		overrides = next;
		options.onOverridesReplaced?.( { ...next } );
	};

	const preview = el( 'div', { class: 'atfs-preview__frame' } );
	const controls = el( 'div', { class: 'atfs-controls__body' } );
	const quick = el( 'div', { class: 'atfs-quick' } );
	const swatches = el( 'div', { class: 'atfs-themes' } );

	/**
	 * Repaints the preview.
	 *
	 * Debounced because dragging a colour picker fires continuously, and every
	 * repaint is a REST round trip. 180ms is under the threshold where a change
	 * stops feeling immediate and well above the rate a drag emits at.
	 */
	const repaint = debounce( async () => {
		try {
			const html = await options.previewFor( active, overrides );

			// Replacing the markup empties the preview pane for a moment, and
			// an emptied scroller clamps to the top -- so somebody comparing
			// two button styles at the bottom of a long form was yanked back
			// to its title on every repaint. Put the scroll back where it was.
			const pane = preview.closest< HTMLElement >( '.atf-studio__preview' );
			const scrolled = pane?.scrollTop ?? 0;

			// Server-rendered from this plugin's own renderer with this
			// plugin's own schema, not from anything a visitor supplied.
			preview.innerHTML = html;

			if ( pane ) {
				pane.scrollTop = scrolled;
			}

			// The front-end bundle enhances anything unenhanced on the page, so
			// the preview gets working logic, totals and steps — which is what
			// makes it a preview rather than a screenshot.
			document.dispatchEvent( new CustomEvent( 'atf-refresh' ) );
		} catch ( error ) {
			clear( preview );
			preview.append(
				el( 'p', { class: 'atfb-error', text: error instanceof Error ? error.message : 'Preview failed.' } )
			);
		}
	}, 180 );

	/**
	 * The two tokens that are a class on the form rather than a custom
	 * property — the renderer turns each into `atf-labels-*` / `atf-fields-*`,
	 * because moving a label or redrawing a field is structure, not paint.
	 */
	const CLASS_TOKENS: Record< string, string > = {
		'label-position': 'atf-labels-',
		'field-style': 'atf-fields-',
	};

	/** Swaps one `prefix-*` class on an element for `prefix + value`. */
	const swapClass = ( target: HTMLElement, prefix: string, value: string ) => {
		const safe = value.replace( /[^a-z0-9_-]/gi, '' );

		for ( const existing of [ ...target.classList ] ) {
			if ( existing.startsWith( prefix ) ) {
				target.classList.remove( existing );
			}
		}

		if ( safe ) {
			target.classList.add( prefix + safe );
		}
	};

	/**
	 * Paints tokens straight onto the previewed form. This IS the preview.
	 *
	 * There used to be a debounced server re-render behind this, "reconciling"
	 * what the inline paint had already shown — which replaced the preview's
	 * markup wholesale, blinked it, reset its scroll and wiped whatever had
	 * been typed or stepped into it, all to arrive at the same pixels. It had
	 * no sense. Every token is either a custom property scoped to the wrapper
	 * or one of the two structural classes above, and both are written here,
	 * synchronously, from the same values the server would resolve. The server
	 * render happens once, at mount, to produce the markup; after that the
	 * preview is repainted, never replaced.
	 */
	const paintNow = ( written: Record< string, string > ) => {
		const wrap = preview.querySelector< HTMLElement >( '.atf-form-wrap' );
		const formEl = wrap?.querySelector< HTMLElement >( '.atf-form' );

		if ( ! wrap ) {
			return;
		}

		for ( const [ token, value ] of Object.entries( written ) ) {
			if ( '' === value ) {
				wrap.style.removeProperty( `--atf-${ token }` );
			} else {
				wrap.style.setProperty( `--atf-${ token }`, value );
			}

			if ( formEl && CLASS_TOKENS[ token ] ) {
				swapClass( formEl, CLASS_TOKENS[ token ], value || ( themes.find( ( t ) => t.slug === active )?.resolved[ token ] ?? '' ) );
			}
		}
	};

	/**
	 * Dresses the preview in a whole theme, classes and all.
	 *
	 * A theme switch changes three things a token write cannot: the
	 * `atf-theme-*` marker, the `atf-is-dark` flag, and every token at once.
	 * All three are known client-side — `resolved` is the theme's full token
	 * map — so the switch is as instant as a dial.
	 */
	const paintTheme = ( theme: Theme ) => {
		paintNow( theme.resolved );

		const formEl = preview.querySelector< HTMLElement >( '.atf-form' );

		if ( formEl ) {
			swapClass( formEl, 'atf-theme-', theme.slug );
			formEl.classList.toggle( 'atf-is-dark', !! theme.dark );
		}
	};

	/** The resolved value of a token right now: override, theme, then default. */
	const resolve = ( token: ThemeToken ): string => {
		if ( overrides[ token.token ] !== undefined ) {
			return overrides[ token.token ];
		}

		const theme = themes.find( ( candidate ) => candidate.slug === active );

		return theme?.resolved?.[ token.token ] ?? token.default;
	};

	/** Paints the theme picker. */
	const renderThemes = () => {
		clear( swatches );

		for ( const theme of themes ) {
			const card = el( 'button', {
				class: `atfs-theme${ theme.slug === active ? ' is-active' : '' }`,
				type: 'button',
				attrs: { 'aria-pressed': theme.slug === active, title: theme.description },
				children: [
					// A miniature of the theme, painted from its own resolved
					// tokens — so the picker previews each theme rather than
					// showing ten identical cards with different names.
					el( 'span', {
						class: 'atfs-theme__chip',
						style: {
							background: theme.resolved[ 'surface' ] ?? '#fff',
							borderColor: theme.resolved[ 'border' ] ?? '#ccc',
							borderRadius: theme.resolved[ 'radius-field' ] ?? '4px',
							boxShadow: theme.resolved[ 'shadow-card' ] ?? 'none',
						},
						children: [
							el( 'span', {
								class: 'atfs-theme__accent',
								style: {
									background: theme.resolved[ 'accent' ] ?? '#2271b1',
									borderRadius: theme.resolved[ 'radius-button' ] ?? '4px',
								},
							} ),
						],
					} ),
					el( 'span', { class: 'atfs-theme__name', text: theme.label } ),
					theme.custom ? el( 'span', { class: 'atfb-badge', text: 'yours' } ) : null,
				],
				on: {
					click: () => {
						active = theme.slug;

						// Switching theme clears the per-form overrides. Keeping
						// them would silently carry one theme's tuning onto
						// another and make the new theme look broken.
						replaceOverrides( {} );

						options.onTheme( active );
						// The switched-to theme's full token set, inline, so the
						// preview wears it this frame rather than after the
						// round trip.
						paintTheme( theme );
						renderThemes();
						syncQuick();
						syncControlsSoon();
						syncDeleteButton();
					},
				},
			} );

			swatches.append( card );
		}
	};

	/** Every token as it currently resolves — what a dial reads to place itself. */
	const currentTokens = (): Tokens => {
		const theme = themes.find( ( candidate ) => candidate.slug === active );
		const resolved: Tokens = { ...( theme?.resolved ?? {} ) };

		for ( const [ name, value ] of Object.entries( overrides ) ) {
			resolved[ name ] = value;
		}

		return resolved;
	};

	/**
	 * Runs a render with the controls pane's scroll where the user left it.
	 *
	 * Rebuilding a pane empties it for a moment, an emptied scroller clamps to
	 * the top, and the rebuilt content arrives with the scroll silently reset —
	 * so every dial click threw the sidebar back to Accent. The scroll position
	 * is ordinary state the same way the values are; a repaint has no business
	 * moving it.
	 */
	const keepScroll = ( render: () => void ) => {
		const scroller = quick.closest< HTMLElement >( '.atf-studio__controls' );
		const scrolled = scroller?.scrollTop ?? 0;

		render();

		if ( scroller ) {
			scroller.scrollTop = scrolled;
		}
	};

	/**
	 * Brings the Advanced token list up to date, off the click's critical path.
	 *
	 * Rebuilding 69 token rows synchronously in the click handler delayed the
	 * frame — the browser could not paint `paintNow()`'s change until the
	 * rebuild finished, which is exactly the lag the inline paint exists to
	 * remove. Debounced past the paint instead: the list is usually folded
	 * behind "Every setting" anyway, and 150ms behind is indistinguishable
	 * from instant for a pane that is not being looked at.
	 */
	const syncControlsSoon = debounce( () => keepScroll( renderControls ), 150 );

	/**
	 * Updates the dials in place instead of rebuilding them.
	 *
	 * A rebuild replaces the very button under the pointer mid-click — hover
	 * state gone, focus gone, and a colour picker mid-drag replaced between two
	 * input events. The dials' structure never changes, only which step is on
	 * and what the colour reads, so that is all that is written. An input the
	 * user is holding is left alone; it already shows what they are typing.
	 */
	const syncQuick = () => {
		const tokens = currentTokens();
		const rows = quick.querySelectorAll( '.atfs-dial' );

		quickDials().forEach( ( dial, index ) => {
			const row = rows[ index ];

			if ( ! row ) {
				return;
			}

			const at = dial.read( tokens );

			if ( dial.kind === 'colour' ) {
				const picker = row.querySelector< HTMLInputElement >( 'input[type="color"]' );
				const text = row.querySelector< HTMLInputElement >( 'input.atfb-input' );

				if ( picker && document.activeElement !== picker ) {
					picker.value = normaliseHex( at );
				}

				if ( text && document.activeElement !== text ) {
					text.value = at;
				}

				return;
			}

			row.querySelectorAll< HTMLButtonElement >( '.atfs-segment' ).forEach( ( segment, step ) => {
				const on = dial.steps?.[ step ]?.value === at;

				segment.classList.toggle( 'is-on', on );
				segment.setAttribute( 'aria-pressed', String( on ) );
			} );
		} );
	};

	/** Applies a dial's whole token family in one go. */
	const applyDial = ( dial: QuickDial, step: string ) => {
		const written = dial.apply( step, currentTokens() );

		for ( const [ token, value ] of Object.entries( written ) ) {
			overrides[ token ] = value;
			options.onOverride( token, value );
		}

		paintNow( written );
		syncQuick();
		syncControlsSoon();
	};

	/**
	 * Paints the Quick layer.
	 *
	 * These are the controls somebody actually reaches for. Each one writes a
	 * whole family of tokens, so "rounder" is one gesture rather than four
	 * numbers that have to agree.
	 */
	const renderQuick = () => {
		clear( quick );

		const tokens = currentTokens();

		for ( const dial of quickDials() ) {
			const at = dial.read( tokens );
			let control: HTMLElement;

			if ( dial.kind === 'colour' ) {
				const picker = el( 'input', {
					class: 'atfs-color',
					type: 'color',
					value: normaliseHex( at ),
					attrs: { 'aria-label': dial.label },
					on: {
						input: ( event: Event ) => applyDial( dial, ( event.target as HTMLInputElement ).value ),
					},
				} );

				control = el( 'div', {
					class: 'atfs-color-row',
					children: [
						picker,
						textInput( at, ( value ) => applyDial( dial, value ) ),
					],
				} );
			} else {
				// A segmented row rather than a `<select>`: there are never more
				// than four options, they are the whole vocabulary of the
				// decision, and seeing them all is most of the help.
				control = el( 'div', {
					class: `atfs-segmented atfs-segmented--${ dial.kind }`,
					attrs: { role: 'group', 'aria-label': dial.label },
					children: ( dial.steps ?? [] ).map( ( step ) =>
						el( 'button', {
							class: `atfs-segment${ step.value === at ? ' is-on' : '' }`,
							type: 'button',
							text: step.label,
							attrs: { 'aria-pressed': step.value === at },
							on: { click: () => applyDial( dial, step.value ) },
						} )
					),
				} );
			}

			quick.append(
				el( 'div', {
					class: 'atfs-dial',
					children: [
						el( 'div', {
							class: 'atfs-dial__head',
							children: [
								el( 'span', { class: 'atfs-dial__label', text: dial.label } ),
								el( 'span', { class: 'atfs-dial__hint', text: dial.hint } ),
							],
						} ),
						control,
					],
				} )
			);
		}
	};

	/** Paints the token controls, grouped. */
	const renderControls = () => {
		clear( controls );

		const grouped = new Map< string, ThemeToken[] >();

		for ( const token of options.tokens ) {
			const list = grouped.get( token.group ) ?? [];

			list.push( token );
			grouped.set( token.group, list );
		}

		const groupLabels: Record< string, string > = {
			colour: 'Colour',
			shape: 'Corners and borders',
			shadow: 'Shadows',
			fields: 'Field style',
			space: 'Spacing',
			type: 'Type',
			labels: 'Labels',
			button: 'Buttons',
			focus: 'Focus ring',
			motion: 'Motion',
		};

		for ( const [ group, tokens ] of grouped ) {
			controls.append(
				el( 'details', {
					class: 'atfs-group',
					attrs: { open: group === 'colour' },
					children: [
						el( 'summary', { text: groupLabels[ group ] ?? group } ),
						...tokens.map( ( token ) => renderTokenControl( token ) ),
					],
				} )
			);
		}
	};

	/** One token's control, chosen by the descriptor the server sent. */
	const renderTokenControl = ( token: ThemeToken ): HTMLElement => {
		const value = resolve( token );

		const change = ( next: string ) => {
			if ( next === '' ) {
				delete overrides[ token.token ];
			} else {
				overrides[ token.token ] = next;
			}

			options.onOverride( token.token, next );
			paintNow( { [ token.token ]: next } );
		};

		let control: HTMLElement;

		if ( token.control === 'color' ) {
			// A colour picker plus a text box, because half the useful values in
			// this token surface are `rgba()` or a gradient, and `<input
			// type="color">` cannot express either.
			const picker = el( 'input', {
				class: 'atfs-color',
				type: 'color',
				value: normaliseHex( value ),
				attrs: { 'aria-label': `${ token.label } colour` },
				on: {
					input: ( event: Event ) => {
						const next = ( event.target as HTMLInputElement ).value;

						text.value = next;
						change( next );
					},
				},
			} );

			const text = textInput( value, ( next ) => {
				picker.value = normaliseHex( next );
				change( next );
			} );

			control = el( 'div', { class: 'atfs-color-row', children: [ picker, text ] } );
		} else if ( token.control === 'select' ) {
			control = select(
				value,
				( token.options ?? [] ).map( ( option ) => ( { value: option, label: option } ) ),
				change
			);
		} else if ( token.control === 'length' ) {
			// A slider and a number together: the slider is for finding a value,
			// the number for saying exactly which one.
			const numeric = parseFloat( value ) || 0;
			const unit = token.unit ?? 'px';
			const max = token.token.includes( 'radius' ) ? 60 : 80;

			const range = el( 'input', {
				class: 'atfs-range',
				type: 'range',
				value: String( numeric ),
				attrs: { min: '0', max: String( max ), step: '1', 'aria-label': token.label },
				on: {
					input: ( event: Event ) => {
						const next = `${ ( event.target as HTMLInputElement ).value }${ unit }`;

						text.value = next;
						change( next );
					},
				},
			} );

			const text = textInput( value, ( next ) => {
				range.value = String( parseFloat( next ) || 0 );
				change( next );
			} );

			control = el( 'div', { class: 'atfs-length-row', children: [ range, text ] } );
		} else {
			control = textInput( value, change );
		}

		const wrapper = row( token.label, control );

		// A token a dial writes is marked, so it is obvious why one slider moved
		// six values — and so editing it by hand is visibly stepping outside the
		// dial rather than silently disagreeing with it.
		const dial = dialOwning( token.token );

		if ( dial ) {
			wrapper.classList.add( 'is-dialled' );
			wrapper.append( el( 'span', { class: 'atfs-owned', text: dial.label } ) );
		}

		// A token that has been overridden is marked, and can be put back — the
		// only way to tell "this theme's value" from "a value I typed" once both
		// are just strings in a box.
		if ( overrides[ token.token ] !== undefined ) {
			wrapper.classList.add( 'is-overridden' );
			wrapper.append(
				el( 'button', {
					class: 'atfs-reset',
					type: 'button',
					text: 'Reset',
					attrs: { 'aria-label': `Reset ${ token.label } to the theme's value` },
					on: {
						click: () => {
							change( '' );
							keepScroll( renderControls );
						},
					},
				} )
			);
		}

		return wrapper;
	};

	/** Saves the current tokens as a reusable theme. */
	const saveAsTheme = async () => {
		const source = themes.find( ( candidate ) => candidate.slug === active );
		const suggested = source ? `${ source.label } (mine)` : 'My theme';

		// eslint-disable-next-line no-alert
		const label = window.prompt( 'Name this theme', suggested );

		if ( ! label ) {
			return;
		}

		try {
			// A theme is saved with the *resolved* tokens, not the overrides
			// alone. A theme that carried only the handful of things somebody
			// changed would shift under them the moment its parent changed, and
			// there would be no way to say "this is what it looks like".
			const resolved: Record< string, string > = {};

			for ( const token of options.tokens ) {
				const value = resolve( token );

				if ( value !== token.default ) {
					resolved[ token.token ] = value;
				}
			}

			const saved = await api.saveTheme( { label, tokens: resolved } );

			themes = [ ...themes.filter( ( candidate ) => candidate.slug !== saved.slug ), saved ];
			options.onThemesChanged( themes );

			active = saved.slug;
			replaceOverrides( {} );
			options.onTheme( active );

			renderThemes();
			syncQuick();
			keepScroll( renderControls );

			notify( 'Theme saved', saved.label );
		} catch ( error ) {
			notify( 'Could not save the theme', error instanceof Error ? error.message : '', 'error' );
		}
	};

	/** Deletes the active theme, when it is one of the user's own. */
	const deleteTheme = async () => {
		const theme = themes.find( ( candidate ) => candidate.slug === active );

		if ( ! theme?.custom ) {
			return;
		}

		if ( ! ( await confirmAction( `Delete “${ theme.label }”? Forms using it fall back to Clean.`, 'Delete theme' ) ) ) {
			return;
		}

		try {
			await api.deleteTheme( theme.id );

			themes = themes.filter( ( candidate ) => candidate.slug !== theme.slug );
			options.onThemesChanged( themes );

			active = 'clean';
			replaceOverrides( {} );
			options.onTheme( active );

			renderThemes();
			syncQuick();
			keepScroll( renderControls );

			const clean = themes.find( ( candidate ) => candidate.slug === active );

			if ( clean ) {
				paintTheme( clean );
			}
		} catch ( error ) {
			notify( 'Could not delete the theme', error instanceof Error ? error.message : '', 'error' );
		}
	};

	/** Copies the current tokens to the clipboard as JSON. */
	const exportTheme = async () => {
		const theme = themes.find( ( candidate ) => candidate.slug === active );
		const payload = {
			label: theme?.label ?? active,
			tokens: { ...( theme?.tokens ?? {} ), ...overrides },
		};

		const json = JSON.stringify( payload, null, '\t' );

		try {
			await navigator.clipboard.writeText( json );
			notify( 'Theme copied', 'Paste it into another site to import it.' );
		} catch {
			// Clipboard access is refused in plenty of legitimate contexts — an
			// insecure origin, a permissions policy. A prompt is graceless but
			// always works.
			// eslint-disable-next-line no-alert
			window.prompt( 'Copy this theme', json );
		}
	};

	/** Reads a pasted theme JSON and applies it as overrides. */
	const importTheme = () => {
		// eslint-disable-next-line no-alert
		const json = window.prompt( 'Paste a theme' );

		if ( ! json ) {
			return;
		}

		try {
			const parsed = JSON.parse( json ) as { tokens?: Record< string, string > };

			if ( ! parsed.tokens || typeof parsed.tokens !== 'object' ) {
				throw new Error( 'That does not look like a theme.' );
			}

			// Applied as overrides rather than saved outright, so an import can
			// be looked at before it is committed. The server sanitises the
			// token names and values again on save.
			for ( const [ name, value ] of Object.entries( parsed.tokens ) ) {
				overrides[ name ] = String( value );
				options.onOverride( name, String( value ) );
			}

			paintNow( { ...overrides } );
			syncQuick();
			keepScroll( renderControls );
		} catch ( error ) {
			notify( 'Could not read that theme', error instanceof Error ? error.message : '', 'error' );
		}
	};

	// Delete is only meaningful for a theme the user made — a built-in cannot be
	// deleted, and a permanently-red button that refuses every second press is
	// worse than no button.
	const deleteButton = button( 'Delete', () => void deleteTheme(), 'danger' );

	const syncDeleteButton = () => {
		const theme = themes.find( ( candidate ) => candidate.slug === active );

		deleteButton.hidden = ! theme?.custom;
	};

	renderThemes();
	renderQuick();
	renderControls();
	repaint();
	syncDeleteButton();

	/*
	 * Three regions, not two.
	 *
	 * The picker used to sit at the top of the left column, above the token
	 * controls. Ten theme chips in a two-up grid plus four action buttons is
	 * roughly 450px — so on any normal window the *actual* work of this tab,
	 * the token controls, began below the fold and every adjustment started
	 * with a scroll.
	 *
	 * Moving the picker to a horizontal strip across the top costs ~110px
	 * instead of ~450px and gives the whole remaining height to the controls,
	 * which is the thing being used. It also puts picking a theme and judging
	 * the result on the same horizontal line as the preview.
	 */
	return el( 'div', {
		class: 'atf-studio',
		children: [
			el( 'div', {
				class: 'atf-studio__top',
				children: [
					// The actions share the heading's line, not the strip's.
					// Beside the strip they took the width the last chip needed
					// and cut it down the middle, which reads as a broken card
					// rather than as "there is more, scroll".
					el( 'div', {
						class: 'atf-studio__topbar',
						children: [
							el( 'h2', { class: 'atf-studio__heading', text: 'Theme' } ),
							el( 'div', {
								class: 'atfs__actions',
								children: [
									button( 'Save as a theme', () => void saveAsTheme(), 'primary' ),
									button( 'Export', () => void exportTheme() ),
									button( 'Import', importTheme ),
									deleteButton,
								],
							} ),
						],
					} ),
					swatches,
				],
			} ),
			el( 'div', {
				class: 'atf-studio__panes',
				children: [
					el( 'aside', {
						class: 'atf-studio__controls',
						children: [
							el( 'p', {
								class: 'atfb-hint',
								text: 'Changes apply to this form only, until you save them as a theme.',
							} ),
							quick,
							// Everything, for the cases the dials above cannot
							// express. Closed by default: a list of 69 controls
							// is a reference, not a starting point — but a
							// control you cannot escape is worse than none, so
							// it is always one click away.
							el( 'details', {
								class: 'atfs-advanced',
								children: [
									el( 'summary', { text: 'Every setting' } ),
									el( 'p', {
										class: 'atfb-hint',
										text: 'The full token list. The dials above write into it.',
									} ),
									controls,
								],
							} ),
						],
					} ),
					el( 'main', {
						class: 'atf-studio__preview',
						children: [ el( 'h2', { class: 'screen-reader-text', text: 'Live preview' } ), preview ],
					} ),
				],
			} ),
		],
	} );
}

/**
 * Mounts the standalone Theme Studio window.
 *
 * The Studio has the same controls as the builder's Theme tab, but nothing to
 * write overrides *to* — so it previews against whichever form the user picks
 * and its only output is a saved theme. That is the difference between "make
 * this form look like this" and "make a theme".
 */
export async function mountThemeStudio(): Promise< void > {
	const root = document.querySelector< HTMLElement >( '[data-atfs-root]' );

	if ( ! root || root.dataset.atfsMounted ) {
		return;
	}

	root.dataset.atfsMounted = '1';
	pinWindowBodyScroll( root );

	const bar = root.querySelector< HTMLElement >( '[data-atfs-bar]' );
	const body = root.querySelector< HTMLElement >( '.atfs__body' ) ?? root;

	try {
		const [ config, themes, forms ] = await Promise.all( [ api.config(), api.listThemes(), api.listForms() ] );

		// The Studio previews against a real form, because a theme has to be
		// judged on real fields. Without one there is nothing to look at, so the
		// user is told to make a form rather than shown an empty box.
		if ( ! forms.length ) {
			clear( body );
			body.append(
				el( 'div', {
					class: 'atfb-empty',
					children: [
						el( 'h2', { text: 'No forms to preview' } ),
						el( 'p', { text: 'Make a form first — a theme needs something to dress.' } ),
					],
				} )
			);

			return;
		}

		let previewForm: FormSummary = forms[ 0 ];
		let overrides: Record< string, string > = {};
		let activeSlug = previewForm.theme;

		const render = () => {
			clear( body );

			body.append(
				mountThemeControls( {
					themes,
					tokens: config.tokens,
					activeSlug,
					overrides,
					standalone: true,
					onTheme: ( slug ) => {
						activeSlug = slug;
					},
					onOverride: ( token, value ) => {
						if ( value === '' ) {
							delete overrides[ token ];
						} else {
							overrides[ token ] = value;
						}
					},
					onOverridesReplaced: ( next ) => {
						overrides = { ...next };
					},
					previewFor: async ( slug, tokens ) => {
						const form = await api.getForm( previewForm.id );

						form.schema.settings.theme = slug;
						form.schema.settings.themeOverrides = tokens;

						const { html } = await api.preview( previewForm.id, { schema: form.schema, theme: slug } );

						return html;
					},
					onThemesChanged: ( next ) => {
						themes.length = 0;
						themes.push( ...next );
					},
				} )
			);
		};

		if ( bar ) {
			clear( bar );
			bar.append(
				el( 'span', { class: 'atfs__label', text: 'Preview against' } ),
				select(
					String( previewForm.id ),
					forms.map( ( form ) => ( { value: String( form.id ), label: form.title || '(untitled)' } ) ),
					( value ) => {
						previewForm = forms.find( ( form ) => String( form.id ) === value ) ?? forms[ 0 ];
						overrides = {};
						render();
					}
				)
			);
		}

		render();
	} catch ( error ) {
		clear( body );
		body.append(
			el( 'p', { class: 'atfb-error', text: error instanceof Error ? error.message : 'Could not load themes.' } )
		);
	}
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => void mountThemeStudio() );
	} else {
		void mountThemeStudio();
	}

	// The shell mounts a native window's markup after this bundle has run.
	document.addEventListener( 'os-window-content-loaded', () => void mountThemeStudio() );
}

/**
 * Coerces any colour value into something `<input type="color">` accepts.
 *
 * That element only understands `#rrggbb`. Handing it `rgba(0,0,0,.5)` or a
 * gradient makes it silently show black, which reads as the theme having lost
 * the value — so anything it cannot represent falls back to a mid grey and the
 * text box beside it keeps the real value.
 */
function normaliseHex( value: string ): string {
	const trimmed = value.trim();

	if ( /^#[0-9a-f]{6}$/i.test( trimmed ) ) {
		return trimmed;
	}

	if ( /^#[0-9a-f]{3}$/i.test( trimmed ) ) {
		return `#${ trimmed[ 1 ] }${ trimmed[ 1 ] }${ trimmed[ 2 ] }${ trimmed[ 2 ] }${ trimmed[ 3 ] }${ trimmed[ 3 ] }`;
	}

	return '#888888';
}

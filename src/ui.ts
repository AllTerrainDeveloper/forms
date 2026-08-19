/**
 * DOM helpers.
 *
 * A tiny `createElement` wrapper rather than a template library, for the same
 * reason the shell's window manager is vanilla: this is imperative, spatial UI —
 * elements are moved, measured, and hit-tested — and a reconciler that owns the
 * DOM fights every one of those operations.
 *
 * `el()` sets text through `textContent`, never `innerHTML`. Anything that
 * genuinely needs markup asks for it explicitly through `html`, which makes the
 * places worth auditing greppable rather than scattered.
 */

interface ElementOptions {
	class?: string;
	text?: string;
	html?: string;
	title?: string;
	type?: string;
	value?: string;
	placeholder?: string;
	href?: string;
	/** Any attribute, including `data-*` and `aria-*`. */
	attrs?: Record< string, string | number | boolean | undefined >;
	/** Inline styles, for the ones that are genuinely dynamic. */
	style?: Partial< CSSStyleDeclaration >;
	/** Event listeners, keyed by event name. */
	on?: Record< string, ( event: never ) => void >;
	children?: Array< Node | string | null | undefined | false >;
}

/** Creates an element. */
export function el< K extends keyof HTMLElementTagNameMap >(
	tag: K,
	options: ElementOptions = {}
): HTMLElementTagNameMap[ K ] {
	const node = document.createElement( tag );

	if ( options.class ) {
		node.className = options.class;
	}

	if ( options.text !== undefined ) {
		node.textContent = options.text;
	}

	if ( options.html !== undefined ) {
		node.innerHTML = options.html;
	}

	if ( options.title ) {
		node.title = options.title;
	}

	if ( options.type && 'type' in node ) {
		( node as unknown as { type: string } ).type = options.type;
	}

	if ( options.value !== undefined && 'value' in node ) {
		( node as unknown as { value: string } ).value = options.value;
	}

	if ( options.placeholder !== undefined && 'placeholder' in node ) {
		( node as unknown as { placeholder: string } ).placeholder = options.placeholder;
	}

	if ( options.href && 'href' in node ) {
		( node as unknown as { href: string } ).href = options.href;
	}

	for ( const [ name, value ] of Object.entries( options.attrs ?? {} ) ) {
		if ( value === undefined || value === false ) {
			continue;
		}

		node.setAttribute( name, value === true ? '' : String( value ) );
	}

	Object.assign( node.style, options.style ?? {} );

	for ( const [ event, handler ] of Object.entries( options.on ?? {} ) ) {
		node.addEventListener( event, handler as EventListener );
	}

	for ( const child of options.children ?? [] ) {
		if ( child === null || child === undefined || child === false ) {
			continue;
		}

		node.append( child );
	}

	return node;
}

/** Empties an element. */
export function clear( node: Element ): void {
	node.replaceChildren();
}

/** A dashicon span. */
export function icon( slug: string ): HTMLElement {
	return el( 'span', {
		class: `dashicons ${ slug.startsWith( 'dashicons-' ) ? slug : `dashicons-${ slug }` }`,
		attrs: { 'aria-hidden': 'true' },
	} );
}

/** A labelled control row for the inspector. */
export function row( label: string, control: HTMLElement, hint?: string ): HTMLElement {
	const id = control.id || `atf-c-${ Math.random().toString( 36 ).slice( 2, 9 ) }`;

	control.id = id;

	return el( 'div', {
		class: 'atfb-row',
		children: [
			el( 'label', { class: 'atfb-row__label', text: label, attrs: { for: id } } ),
			control,
			hint ? el( 'p', { class: 'atfb-row__hint', text: hint } ) : null,
		],
	} );
}

/** A text input. */
export function textInput( value: string, onChange: ( value: string ) => void, placeholder = '' ): HTMLInputElement {
	return el( 'input', {
		class: 'atfb-input',
		type: 'text',
		value,
		placeholder,
		on: {
			input: ( event: Event ) => onChange( ( event.target as HTMLInputElement ).value ),
		},
	} );
}

/** A number input. Empty stays empty rather than becoming zero. */
export function numberInput( value: string, onChange: ( value: string ) => void ): HTMLElement {
	if ( hasComponent( 'os-number-field' ) ) {
		const host = document.createElement( 'os-number-field' );

		host.setAttribute( 'value', value );
		host.classList.add( 'atfb-field' );
		// `os-input-change` fires per keystroke, which is what the raw `input`
		// event did; `os-input-commit` fires on blur and would make the builder
		// feel like it had stopped responding.
		host.addEventListener( 'os-input-change', ( event: Event ) => {
			onChange( String( ( event as CustomEvent< { value?: string } > ).detail?.value ?? '' ) );
		} );

		return host;
	}

	return el( 'input', {
		class: 'atfb-input',
		type: 'number',
		value,
		on: {
			input: ( event: Event ) => onChange( ( event.target as HTMLInputElement ).value ),
		},
	} );
}

/** A textarea. */
export function textArea( value: string, onChange: ( value: string ) => void, rows = 4 ): HTMLTextAreaElement {
	const node = el( 'textarea', {
		class: 'atfb-input atfb-input--area',
		attrs: { rows },
		on: {
			input: ( event: Event ) => onChange( ( event.target as HTMLTextAreaElement ).value ),
		},
	} );

	node.value = value;

	return node;
}

/**
 * A select.
 *
 * `<os-select>` renders its listbox in the top layer, which matters here more
 * than it looks: a native `<select>` inside a desktop window drops its popup
 * using the OS's own widget, so it ignores the desktop theme, and inside a
 * dragged or scaled window it can land in the wrong place entirely.
 *
 * Returns `HTMLElement`, and callers that need to *read* the value read it back
 * from their own state rather than from the control — the two branches spell
 * `value` differently and reading the element was never the point.
 */
export function select(
	value: string,
	options: Array< { value: string; label: string } >,
	onChange: ( value: string ) => void
): HTMLElement {
	if ( hasComponent( 'os-select' ) && hasComponent( 'os-option' ) ) {
		const host = document.createElement( 'os-select' );

		host.setAttribute( 'value', value );
		host.classList.add( 'atfb-field' );

		for ( const option of options ) {
			const item = document.createElement( 'os-option' );

			item.setAttribute( 'value', option.value );
			item.textContent = option.label;
			host.append( item );
		}

		host.addEventListener( 'os-pick', ( event: Event ) => {
			onChange( String( ( event as CustomEvent< { value?: string } > ).detail?.value ?? '' ) );
		} );

		return host;
	}

	return el( 'select', {
		class: 'atfb-input atfb-select',
		on: {
			change: ( event: Event ) => onChange( ( event.target as HTMLSelectElement ).value ),
		},
		children: options.map( ( option ) =>
			el( 'option', {
				value: option.value,
				text: option.label,
				attrs: { selected: option.value === value },
			} )
		),
	} );
}

/**
 * A checkbox with its label.
 *
 * The component earns its place here more than anywhere else in this file. A raw
 * `input[type=checkbox]` inside wp-admin is the control that cost this plugin
 * three separate bug fixes: `forms.css` sets `margin: -0.25rem` on it at (0,1,1)
 * so it floats above its own label, sets `appearance: none` so `accent-color`
 * silently does nothing, and leaves the checked mark to whichever stylesheet
 * claims `:checked::before` — which inside a window is the shell, in the shell's
 * colour rather than the theme's.
 *
 * `<os-checkbox-label>` renders in shadow DOM, where none of that reaches.
 *
 * The *front end* keeps its raw checkboxes deliberately: a form has to render
 * and submit with no JavaScript at all, and a custom element is not a form
 * control. This is builder chrome, where the shell is either present or the
 * fallback below applies.
 */
export function checkbox( label: string, checked: boolean, onChange: ( checked: boolean ) => void ): HTMLElement {
	if ( hasComponent( 'os-checkbox-label' ) ) {
		const host = document.createElement( 'os-checkbox-label' );

		host.setAttribute( 'label', label );
		host.classList.add( 'atfb-check' );

		if ( checked ) {
			host.setAttribute( 'checked', '' );
		}

		host.addEventListener( 'os-checkbox-change', ( event: Event ) => {
			onChange( Boolean( ( event as CustomEvent< { checked?: boolean } > ).detail?.checked ) );
		} );

		return host;
	}

	const input = el( 'input', {
		type: 'checkbox',
		on: {
			change: ( event: Event ) => onChange( ( event.target as HTMLInputElement ).checked ),
		},
	} );

	input.checked = checked;

	return el( 'label', {
		class: 'atfb-check',
		children: [ input, el( 'span', { text: label } ) ],
	} );
}

/**
 * A button, rendered by the shell's own component when the shell has one.
 *
 * `<os-button>` is in the subset OpenStation registers at boot, and it is the
 * right thing to use: its chrome lives in shadow DOM where WordPress's
 * `forms.css` cannot reach it, it resolves the desktop theme's palette directly,
 * and it carries the kit's focus ring and press feedback. Every one of those is
 * something we would otherwise be reimplementing and getting subtly wrong — this
 * plugin has already spent three separate bug fixes on raw controls losing
 * fights with `forms.css`.
 *
 * The fallback is not a nicety. The same builder runs on a plain wp-admin page
 * with no shell on it, where the tag never upgrades and would render as inert
 * markup — a label with no button around it. So the component is used only when
 * it is actually registered, and the variant names are shared deliberately:
 * `primary` / `secondary` / `ghost` / `danger` mean the same thing in both.
 *
 * Returns the element loosely typed, because the two branches are a `<button>`
 * and a custom element and callers only ever touch what both have.
 */
export function button(
	label: string,
	onClick: () => void,
	variant: 'primary' | 'secondary' | 'ghost' | 'danger' = 'secondary',
	iconSlug?: string
): HTMLElement & { disabled: boolean } {
	const children = [ iconSlug ? icon( iconSlug ) : null, el( 'span', { text: label } ) ];

	if ( hasComponent( 'os-button' ) ) {
		const host = document.createElement( 'os-button' );

		host.setAttribute( 'variant', variant );
		host.setAttribute( 'type', 'button' );
		// Kept so the plugin's own layout rules (toolbar gaps, hidden states)
		// address both branches with one selector.
		host.classList.add( 'atfb-button', `atfb-button--${ variant }` );
		host.addEventListener( 'click', onClick );

		for ( const child of children ) {
			if ( child ) {
				host.append( child );
			}
		}

		return host as HTMLElement & { disabled: boolean };
	}

	return el( 'button', {
		class: `atfb-button atfb-button--${ variant }`,
		type: 'button',
		on: { click: onClick },
		children,
	} );
}

/**
 * Whether an `os-*` tag is registered on this page.
 *
 * Emitting an unregistered tag renders inert HTML rather than failing loudly, so
 * asking first is the difference between a component and a dead element. Every
 * component this plugin uses is paired with a plain-HTML fallback, because the
 * same builder also runs on a wp-admin page with no shell on it at all.
 */
export function hasComponent( tag: string ): boolean {
	return typeof customElements !== 'undefined' && Boolean( customElements.get( tag ) );
}

/**
 * The `os-*` tags the admin surfaces render.
 *
 * Named rather than loading the whole kit: `loadComponents()` with no argument
 * fetches all 59, and this plugin uses eight. The list is the honest statement
 * of what we depend on, and it is what a reader greps for when one of them
 * changes.
 */
const COMPONENTS = [
	'os-button',
	'os-checkbox-label',
	'os-number-field',
	'os-select',
	'os-option',
	'os-segmented',
	'os-segment',
	'os-color-field',
	'os-empty-state',
] as const;

/** The in-flight load, so a second caller waits rather than fetching again. */
let componentsPending: Promise< void > | null = null;

/**
 * Makes the component kit available, then resolves.
 *
 * `wp.os.loadComponents()` is the shell's runtime route to the parts of the kit
 * a given page has not already imported. It exists because components register
 * per bundle at import time, and a plugin shipped as a zip has no path to import
 * from at build time — its only alternatives were bundling a second copy of
 * components the page already has, or hand-rolling. This plugin did the second
 * for months, and the raw controls that came out of it lost three separate
 * fights with WordPress's `forms.css`.
 *
 * Awaited once before the first render rather than per control. The call is
 * cheap on repeat — a registry lookup when the tags are already up — but the
 * *render* has to happen after it, because `hasComponent()` decides between a
 * component and its fallback at the moment an element is built.
 *
 * Never rejects outward. A failed fetch means the fallbacks render, which is the
 * same thing that happens with no shell at all: a working builder that looks
 * like wp-admin.
 */
export function whenComponents(): Promise< void > {
	if ( componentsPending ) {
		return componentsPending;
	}

	const shell = ( window as unknown as { wp?: { os?: { loadComponents?: ( tags?: readonly string[] ) => Promise< void > } } } )
		.wp?.os;

	componentsPending = shell?.loadComponents
		? shell.loadComponents( COMPONENTS ).catch( () => undefined )
		: Promise.resolve();

	return componentsPending;
}

/**
 * Asks the user to confirm something destructive.
 *
 * Uses the shell's own confirm dialog when there is one, because `window.confirm`
 * blocks the whole browser event loop — which inside OpenStation freezes every
 * other window on the desktop, not just this one.
 */
export async function confirmAction( message: string, title = '' ): Promise< boolean > {
	const shell = ( window as unknown as { wp?: { os?: { confirm?: ( opts: unknown ) => Promise< boolean > } } } ).wp?.os;

	if ( shell?.confirm ) {
		return shell.confirm( { title, message, danger: true } );
	}

	// eslint-disable-next-line no-alert
	return window.confirm( message );
}

/** Shows a transient message, through the shell when there is one. */
export function notify( title: string, body = '', type = 'info' ): void {
	const shell = ( window as unknown as { wp?: { os?: { notify?: ( opts: unknown ) => unknown } } } ).wp?.os;

	if ( shell?.notify ) {
		shell.notify( { title, body, type } );

		return;
	}

	// Without a shell there is nowhere good to put a toast, and inventing one
	// would mean a second notification system to style and keep accessible. The
	// console is honest about that.
	// eslint-disable-next-line no-console
	console.info( `[AllTerrain Forms] ${ title }${ body ? `: ${ body }` : '' }` );
}

/**
 * Runs a function at most once per animation frame.
 *
 * Every keystroke in the inspector repaints the canvas and the preview; without
 * this, typing a label rebuilds the DOM once per character.
 */
export function raf< T extends unknown[] >( fn: ( ...args: T ) => void ): ( ...args: T ) => void {
	let queued = 0;
	let last: T;

	return ( ...args: T ) => {
		last = args;

		if ( queued ) {
			return;
		}

		queued = window.requestAnimationFrame( () => {
			queued = 0;
			fn( ...last );
		} );
	};
}

/** Runs a function after a quiet period. */
export function debounce< T extends unknown[] >( fn: ( ...args: T ) => void, wait: number ): ( ...args: T ) => void {
	let timer = 0;

	return ( ...args: T ) => {
		window.clearTimeout( timer );
		timer = window.setTimeout( () => fn( ...args ), wait );
	};
}

/**
 * A small, persistent view preference.
 *
 * `localStorage` and not user meta: these are "how I like to look at this on
 * this machine" — a toggled overlay, a collapsed pane — and round-tripping them
 * through the REST API would cost a request to change a checkbox and would
 * follow somebody onto a screen where the setting made no sense. Anything that
 * belongs to the *form* is in the schema instead.
 *
 * Both directions swallow their errors. Storage is unavailable in private mode
 * and can be disabled outright, and a builder that refused to open because it
 * could not remember a toggle would be trading the whole tool for a nicety.
 */
export function readSetting( key: string ): string {
	try {
		return window.localStorage.getItem( key ) ?? '';
	} catch {
		return '';
	}
}

/** Stores a view preference. */
export function writeSetting( key: string, value: string ): void {
	try {
		window.localStorage.setItem( key, value );
	} catch {
		// Storage unavailable; the preference lasts this session only.
	}
}

/**
 * Keeps the shell window body this root lives in from ever scrolling.
 *
 * A native window's body is `overflow: auto` in the shell, and under some
 * sequences of tab switches and window resizes the browser decides it has
 * scrollable overflow even though every pane in here manages its own. One
 * stray wheel tick — or a `scrollIntoView` from focusing a control — then
 * shunts the whole tool upward: the toolbar disappears under the title bar and
 * an equal band of dead space opens at the bottom, which reads as a broken
 * window rather than as a scrolled one. Nothing in these windows ever wants
 * the body scrolled, so any scroll that happens is undone on arrival.
 *
 * A stylesheet rule blocks the wheel; this catches the programmatic scrolls
 * CSS cannot.
 */
export function pinWindowBodyScroll( root: HTMLElement ): void {
	const body = root.closest< HTMLElement >( '.os-window__body' );

	if ( ! body ) {
		return;
	}

	body.addEventListener(
		'scroll',
		() => {
			if ( body.scrollTop ) {
				body.scrollTop = 0;
			}

			if ( body.scrollLeft ) {
				body.scrollLeft = 0;
			}
		},
		{ passive: true }
	);

	// Undo anything that happened before this listener existed.
	body.scrollTop = 0;
	body.scrollLeft = 0;
}

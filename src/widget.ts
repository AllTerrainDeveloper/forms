/**
 * The desktop widget.
 *
 * Recent submissions across every form, with a conversion figure per form. Small
 * on purpose: a widget is glanced at, not read.
 *
 * Loaded by the shell only when somebody has actually put it on their desktop,
 * which is why its handle is registered and never enqueued. It is also the one
 * bundle that may mount long after first paint, so it does its own DOM setup
 * rather than expecting a server-rendered template to be there already.
 */

import { api } from './api';
import { clear, el } from './ui';
import type { Entry, FormSummary } from './types';

/** How often the widget refreshes itself, in milliseconds. */
const REFRESH_MS = 120000;

/** Renders the widget into a host element. */
async function render( host: HTMLElement ): Promise< void > {
	try {
		const forms = await api.listForms();

		if ( ! forms.length ) {
			clear( host );
			host.append( el( 'p', { class: 'atfw__empty', text: 'No forms yet.' } ) );

			return;
		}

		// Newest first across every form, which is what "recent submissions"
		// means — a per-form list would need the reader to know which form was
		// busy, which is the thing they came here to find out.
		const { entries } = await api.listEntries( { per_page: 8 } );

		clear( host );
		host.append( summary( forms ), list( entries ) );
	} catch ( error ) {
		clear( host );
		host.append(
			el( 'p', {
				class: 'atfw__empty',
				text: error instanceof Error ? error.message : 'Could not load submissions.',
			} )
		);
	}
}

/** The headline numbers. */
function summary( forms: FormSummary[] ): HTMLElement {
	const submissions = forms.reduce( ( total, form ) => total + form.submissions, 0 );
	const views = forms.reduce( ( total, form ) => total + form.views, 0 );
	const unread = forms.reduce( ( total, form ) => total + form.unread, 0 );

	// Floored, so 199 submissions from 200 views never reads as the 100% that
	// would suggest nobody ever left without finishing.
	const conversion = views > 0 ? Math.floor( ( submissions / views ) * 100 ) : 0;

	return el( 'div', {
		class: 'atfw__summary',
		children: [
			stat( String( submissions ), 'submissions' ),
			stat( `${ conversion }%`, 'converted' ),
			stat( String( unread ), 'unread' ),
		],
	} );
}

/** One number and its label. */
function stat( value: string, label: string ): HTMLElement {
	return el( 'div', {
		class: 'atfw__stat',
		children: [
			el( 'strong', { class: 'atfw__stat-value', text: value } ),
			el( 'span', { class: 'atfw__stat-label', text: label } ),
		],
	} );
}

/** The recent-submissions list. */
function list( entries: Entry[] ): HTMLElement {
	if ( ! entries.length ) {
		return el( 'p', { class: 'atfw__empty', text: 'Nothing submitted yet.' } );
	}

	return el( 'ul', {
		class: 'atfw__list',
		children: entries.map( ( entry ) =>
			el( 'li', {
				class: `atfw__item${ entry.status === 'atf-unread' ? ' is-unread' : '' }`,
				children: [
					el( 'span', { class: 'atfw__title', text: entry.title } ),
					el( 'span', { class: 'atfw__meta', text: `${ entry.formTitle } · ${ entry.dateHuman }` } ),
				],
			} )
		),
	} );
}

/**
 * The widget's render callback.
 *
 * The shell calls this with the card's own element. Returning a teardown lets it
 * stop the refresh timer when the widget is removed from the desktop — without
 * that, a widget added and removed a few times leaves a timer per instance
 * polling forever.
 */
export function renderWidget( host: HTMLElement ): () => void {
	host.classList.add( 'atfw' );

	void render( host );

	const timer = window.setInterval( () => void render( host ), REFRESH_MS );

	// A new submission arriving is worth showing straight away rather than up to
	// two minutes later.
	const shell = ( window as unknown as { wp?: { os?: { subscribe?: ( t: string, cb: () => void ) => () => void } } } )
		.wp?.os;

	const unsubscribe = shell?.subscribe?.( 'os.atf_entry.changed', () => void render( host ) );

	return () => {
		window.clearInterval( timer );
		unsubscribe?.();
	};
}

/**
 * The name the shell calls.
 *
 * Vite's IIFE build assigns the module's *exports object* to the global named in
 * `vite.config.js` — `window.allTerrainFormsWidget` — as the very last thing it
 * does. Assigning that global by hand inside the module would therefore be
 * overwritten a moment later by the bundle itself, which is a bug that only
 * shows up in the built file and never in a test.
 *
 * So the contract is expressed as an export instead: the shell finds
 * `window.allTerrainFormsWidget.render` because `render` is what this module
 * exports.
 */
export { renderWidget as render };

/**
 * Mounts into a plain container, for the admin page.
 *
 * Without a shell there is no widget layer, so the widget renders anywhere a
 * `[data-atfw-root]` element appears.
 */
function mountStandalone(): void {
	document.querySelectorAll< HTMLElement >( '[data-atfw-root]' ).forEach( ( host ) => {
		if ( host.dataset.atfwMounted ) {
			return;
		}

		host.dataset.atfwMounted = '1';
		renderWidget( host );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountStandalone );
} else {
	mountStandalone();
}

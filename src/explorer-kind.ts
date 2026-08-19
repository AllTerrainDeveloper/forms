/**
 * The Forms section inside WP Explorer, rendered by us.
 *
 * The Explorer's `registerEntityKind()` hands a plugin the whole section body
 * for its routes — list and detail both — while the shell keeps what is the
 * shell's: the window chrome, the route history, and the breadcrumb derived
 * from it. Double-clicking a form navigates to a real detail route, so the
 * crumb reads `Site › Forms › Team pulse survey` with no second trail drawn
 * underneath it.
 *
 * Inside a form the hierarchy deliberately stops: Entries and Report are
 * doors to their own windows — the places those jobs are actually done — and
 * the live preview simply IS the rest of the folder, full size, because
 * looking at a form is what a file manager's quick look is for and the space
 * was sitting there unused.
 *
 * Everything reads through the same REST the Entries and Analytics windows
 * use; nothing here is a second copy of anything.
 */

import { api } from './api';
import { openWindowOnForm } from './handoff';
import { el, clear } from './ui';
import type { FormSummary } from './types';

/** The routes this kind renders, as the shell hands them over. */
interface KindRoute {
	kind: string;
	postId?: number;
	postTitle?: string;
	entityId?: string;
}

/** The slice of the Explorer's render host this kind uses. */
interface RenderHost {
	body: HTMLElement;
	route: KindRoute;
	navigate: ( route: KindRoute ) => void;
	addTeardown: ( fn: () => void ) => void;
	previewActionRow: ( args: { item: Record< string, unknown > } ) => HTMLElement | null;
}

/** A dashicon span, the way the Explorer's own tiles carry theirs. */
function icon( name: string ): HTMLElement {
	return el( 'span', { class: `dashicons ${ name } atfx-icon`, attrs: { 'aria-hidden': 'true' } } );
}

/** One stat card. */
function card( label: string, value: string ): HTMLElement {
	return el( 'div', {
		class: 'atfx-card',
		children: [
			el( 'strong', { class: 'atfx-card__value', text: value } ),
			el( 'span', { class: 'atfx-card__label', text: label } ),
		],
	} );
}

/** An Explorer-style icon tile. */
function tile( args: {
	icon: string;
	label: string;
	vitals?: string;
	selected?: boolean;
	onOpen: () => void;
	onSelect?: () => void;
} ): HTMLElement {
	return el( 'button', {
		class: `atfx-tile${ args.selected ? ' is-selected' : '' }`,
		type: 'button',
		children: [
			icon( args.icon ),
			el( 'strong', { class: 'atfx-tile__label', text: args.label } ),
			args.vitals ? el( 'span', { class: 'atfx-tile__vitals', text: args.vitals } ) : null,
		],
		on: {
			click: () => ( args.onSelect ?? args.onOpen )(),
			dblclick: args.onOpen,
		},
	} );
}

/** The form's stat cards, shared by the list pane and the folder view. */
function statCards( form: FormSummary ): HTMLElement {
	const conversion = form.views > 0 ? `${ Math.floor( ( form.submissions / form.views ) * 100 ) }%` : '—';

	return el( 'div', {
		class: 'atfx-cards',
		children: [
			card( 'Entries', String( form.entries ) ),
			card( 'Unread', String( form.unread ) ),
			card( 'Views', String( form.views ) ),
			card( 'Conversion', conversion ),
		],
	} );
}

/**
 * The live preview of a form's real front end.
 *
 * @param formId The form.
 * @param title  Its title, for the frame's accessible name.
 * @param full   True renders at natural size; false at half, for the pane.
 */
function livePreview( formId: number, title: string, full = false ): HTMLElement {
	const frame = el( 'div', {
		class: `atfx-preview${ full ? ' atfx-preview--full' : '' }`,
		children: [ el( 'p', { class: 'atfx-empty', text: 'Loading the preview…' } ) ],
	} );

	void api
		.getForm( formId )
		.then( ( detail ) => {
			if ( ! detail.previewUrl ) {
				throw new Error( 'no preview' );
			}

			clear( frame );
			frame.append(
				el( 'iframe', {
					class: 'atfx-preview__frame',
					attrs: { src: detail.previewUrl, title: `Preview of ${ title }`, loading: 'lazy', tabindex: '-1' },
				} )
			);
		} )
		.catch( () => {
			clear( frame );
			frame.append( el( 'p', { class: 'atfx-empty', text: 'No preview available.' } ) );
		} );

	return frame;
}

/**
 * Renders the section. Registered as the `atf-form` entity kind.
 *
 * @param host The Explorer's render host.
 * @return void
 */
export function renderFormsKind( host: RenderHost ): void {
	if ( 'detail' === host.route.kind ) {
		renderFolder( host, Number( host.route.postId ?? 0 ), String( host.route.postTitle ?? '' ) );

		return;
	}

	renderList( host );
}

/* ------------------------------------------------------ The forms grid -- */

function renderList( host: RenderHost ): void {
	let forms: FormSummary[] = [];
	let selected = 0;
	let searchTerm = '';

	const grid = el( 'div', { class: 'atfx-grid' } );
	const pane = el( 'div', { class: 'atfx-pane' } );
	const search = el( 'input', {
		class: 'atfb-input atfx-search',
		type: 'search',
		placeholder: 'Search forms…',
		attrs: { 'aria-label': 'Search forms' },
	} ) as HTMLInputElement;

	clear( host.body );
	host.body.append(
		el( 'div', { class: 'atfx', children: [ el( 'div', { class: 'atfx-side', children: [ search, grid ] } ), pane ] } )
	);

	const openFolder = ( form: FormSummary ) =>
		host.navigate( {
			kind: 'detail',
			entityId: 'atf-forms',
			postId: form.id,
			postTitle: form.title || `#${ form.id }`,
		} );

	const paint = () => {
		clear( grid );

		for ( const form of forms ) {
			if ( searchTerm && ! ( form.title || '' ).toLowerCase().includes( searchTerm ) ) {
				continue;
			}

			grid.append(
				tile( {
					icon: 'dashicons-feedback',
					label: form.title || `#${ form.id }`,
					vitals: `${ form.fields } ${ 1 === form.fields ? 'question' : 'questions' } · ${ form.entries } ${
						1 === form.entries ? 'entry' : 'entries'
					}`,
					selected: form.id === selected,
					onSelect: () => {
						selected = form.id;
						paint();
						paintPane();
					},
					onOpen: () => openFolder( form ),
				} )
			);
		}

		if ( ! grid.childElementCount ) {
			grid.append( el( 'p', { class: 'atfx-empty', text: searchTerm ? 'No form matches that.' : 'No forms yet.' } ) );
		}
	};

	const paintPane = () => {
		const form = forms.find( ( candidate ) => candidate.id === selected );

		clear( pane );

		if ( ! form ) {
			pane.append( el( 'p', { class: 'atfx-empty', text: 'Pick a form to see it. Double-click to open it.' } ) );

			return;
		}

		pane.append(
			el( 'h2', { class: 'atfx-title', text: form.title || `#${ form.id }` } ),
			statCards( form ),
			host.previewActionRow( { item: { ...form } } ) ?? el( 'span' ),
			livePreview( form.id, form.title ),
			el( 'p', { class: 'atfx-shortcode', children: [ el( 'code', { text: form.shortcode } ) ] } )
		);
	};

	search.addEventListener( 'input', () => {
		searchTerm = search.value.trim().toLowerCase();
		paint();
	} );

	void api
		.listForms()
		.then( ( loaded ) => {
			forms = loaded;
			selected = forms[ 0 ]?.id ?? 0;
			paint();
			paintPane();
		} )
		.catch( () => {
			clear( grid );
			grid.append( el( 'p', { class: 'atfx-empty', text: 'Could not load the forms.' } ) );
		} );
}

/* -------------------------------------------- Inside one form (detail) -- */

function renderFolder( host: RenderHost, formId: number, title: string ): void {
	const body = el( 'div', { class: 'atfx-full' } );

	clear( host.body );
	host.body.append( el( 'div', { class: 'atfx', children: [ body ] } ) );
	body.append( el( 'p', { class: 'atfx-empty', text: 'Loading…' } ) );

	const paintFolder = ( form: FormSummary ) => {
		clear( body );
		body.append(
			statCards( form ),
			el( 'div', {
				class: 'atfx-grid atfx-grid--folder',
				children: [
					// The form itself first: the folder is named after it, and
					// opening it means building on it.
					tile( {
						icon: 'dashicons-feedback',
						label: form.title || `#${ form.id }`,
						vitals: `${ form.fields } ${ 1 === form.fields ? 'question' : 'questions' } — open in the builder`,
						onOpen: () => openWindowOnForm( 'allterrain-forms', 'builder', 'atf-open-form', form.id ),
					} ),
					// Entries and Report are doors, not depths: the windows are
					// where those jobs are done, so the tiles take you there.
					tile( {
						icon: 'dashicons-list-view',
						label: 'Entries',
						vitals: `${ form.entries } stored`,
						onOpen: () => openWindowOnForm( 'allterrain-forms-entries', 'entries', 'atf-open-entries-form', form.id ),
					} ),
					tile( {
						icon: 'dashicons-chart-bar',
						label: 'Report',
						vitals: 'conversion, NPS, answers',
						onOpen: () => openWindowOnForm( 'allterrain-forms-analytics', 'analytics', 'atf-open-analytics-form', form.id ),
					} ),
				],
			} ),
			// The rest of the folder is the form itself, live and full size.
			livePreview( form.id, form.title, true )
		);
	};

	void api
		.listForms()
		.then( ( forms ) => {
			const form = forms.find( ( candidate ) => candidate.id === formId );

			if ( ! form ) {
				clear( body );
				body.append( el( 'p', { class: 'atfx-empty', text: `“${ title }” is not a form any more.` } ) );

				return;
			}

			paintFolder( form );
		} )
		.catch( () => {
			clear( body );
			body.append( el( 'p', { class: 'atfx-empty', text: 'Could not load the form.' } ) );
		} );
}

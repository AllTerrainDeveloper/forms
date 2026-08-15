/**
 * The REST client.
 *
 * Routed through `wp.os.fetch` when the shell is present, which is not merely
 * cosmetic: the shell's fetch pulses the window's title-bar activity dot and
 * routes 401s into its own re-authentication flow, so a session that expires
 * mid-edit is recovered rather than silently losing the save. Falls back to
 * plain `fetch` everywhere else.
 */

import { getShell } from './dnd';
import type {
	BuilderConfig,
	Entry,
	Form,
	FormSummary,
	MergeTagGroup,
	RuntimeConfig,
	Theme,
	Values,
} from './types';

const config: RuntimeConfig | undefined = ( window as unknown as { allTerrainForms?: RuntimeConfig } ).allTerrainForms;

/** A REST failure carrying the server's own message, so the UI can show it. */
export class ApiError extends Error {
	public readonly status: number;
	public readonly code: string;

	public constructor( message: string, status: number, code = '' ) {
		super( message );
		this.name = 'ApiError';
		this.status = status;
		this.code = code;
	}
}

/** One request. */
async function request< T >( path: string, init: RequestInit = {} ): Promise< T > {
	if ( ! config?.restUrl ) {
		throw new ApiError( 'AllTerrain Forms is not configured on this page.', 0 );
	}

	const url = `${ config.restUrl }${ path }`;

	const headers: Record< string, string > = {
		'Content-Type': 'application/json',
		...( ( init.headers as Record< string, string > ) ?? {} ),
	};

	if ( config.nonce ) {
		headers[ 'X-WP-Nonce' ] = config.nonce;
	}

	const shell = getShell();

	const doFetch = shell?.fetch
		? ( input: string, options: RequestInit ) => shell.fetch!( input, options, { source: 'allterrain-forms' } )
		: ( input: string, options: RequestInit ) => fetch( input, options );

	const response = await doFetch( url, {
		credentials: 'same-origin',
		...init,
		headers,
	} );

	if ( ! response.ok ) {
		let message = `Request failed with status ${ response.status }.`;
		let code = '';

		try {
			const body = ( await response.json() ) as { message?: string; code?: string };

			message = body.message ?? message;
			code = body.code ?? '';
		} catch {
			// A non-JSON error body — a PHP fatal, an HTML error page from a
			// proxy. The status code is all there is to report.
		}

		throw new ApiError( message, response.status, code );
	}

	// A 204 has no body, and `response.json()` on one throws.
	if ( response.status === 204 ) {
		return undefined as T;
	}

	return ( await response.json() ) as T;
}

const get = < T >( path: string ): Promise< T > => request< T >( path );

/**
 * A GET against a route outside this plugin's namespace.
 *
 * `request()` prefixes everything with `restUrl`, which is
 * `…/wp-json/allterrain-forms/v1`. Core's own routes live one level up, so they
 * need the site's REST root instead — same nonce, same shell-routed fetch,
 * different base.
 */
async function wpGet< T >( route: string ): Promise< T > {
	if ( ! config?.wpRestUrl ) {
		throw new ApiError( 'AllTerrain Forms is not configured on this page.', 0 );
	}

	const headers: Record< string, string > = config.nonce ? { 'X-WP-Nonce': config.nonce } : {};
	const shell = getShell();
	const url = `${ config.wpRestUrl }${ route }`;

	const response = shell?.fetch
		? await shell.fetch( url, { credentials: 'same-origin', headers }, { source: 'allterrain-forms' } )
		: await fetch( url, { credentials: 'same-origin', headers } );

	if ( ! response.ok ) {
		throw new ApiError( `Request failed with status ${ response.status }.`, response.status );
	}

	return ( await response.json() ) as T;
}

/**
 * Turns HTML entities back into characters.
 *
 * Core's REST API returns titles as rendered HTML. Everything here puts text on
 * the page through `textContent`, which does not decode — so a page titled
 * "Q&A" would appear as "Q&amp;A". Decoding through the parser rather than a
 * table of replacements covers numeric entities too, and cannot itself execute
 * anything: the string is assigned to a detached textarea's `innerHTML` and read
 * straight back out as text.
 */
function decodeEntities( html: string ): string {
	if ( ! html || ! html.includes( '&' ) ) {
		return html;
	}

	const textarea = document.createElement( 'textarea' );

	textarea.innerHTML = html;

	return textarea.value;
}

const post = < T >( path: string, body: unknown ): Promise< T > =>
	request< T >( path, { method: 'POST', body: JSON.stringify( body ) } );

const del = < T >( path: string ): Promise< T > => request< T >( path, { method: 'DELETE' } );

/** Builds a query string from a map, skipping empties. */
function query( params: Record< string, string | number | boolean | undefined > ): string {
	const search = new URLSearchParams();

	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( value === undefined || value === '' || value === false ) {
			continue;
		}

		search.set( key, String( value ) );
	}

	const string = search.toString();

	return string ? `?${ string }` : '';
}

export const api = {
	config: () => get< BuilderConfig >( '/config' ),

	listForms: () => get< FormSummary[] >( '/forms' ),

	getForm: ( id: number ) => get< Form >( `/forms/${ id }` ),

	createForm: ( body: { template?: string; title?: string; schema?: unknown } ) => post< Form >( '/forms', body ),

	updateForm: ( id: number, body: { title?: string; schema?: unknown } ) => post< Form >( `/forms/${ id }`, body ),

	duplicateForm: ( id: number ) => post< Form >( `/forms/${ id }/duplicate`, {} ),

	deleteForm: ( id: number ) => del< { deleted: boolean } >( `/forms/${ id }` ),

	preview: ( id: number, body: { schema?: unknown; theme?: string } ) =>
		post< { html: string } >( `/forms/${ id }/preview`, body ),

	/**
	 * The site's published pages, for the "send them to a page" confirmation.
	 *
	 * Core's own route rather than one of ours: `wp/v2/pages` already knows about
	 * capabilities, pagination and the page hierarchy, and a plugin re-exposing
	 * the same list is a second thing to keep in step with it. `_fields` keeps the
	 * payload to the two values the picker shows — a full page response carries
	 * rendered content, and a hundred of those is megabytes.
	 */
	pages: () =>
		wpGet< Array< { id: number; title: { rendered: string } } > >(
			'wp/v2/pages?per_page=100&status=publish&orderby=title&order=asc&_fields=id,title'
		).then( ( pages ) =>
			pages.map( ( page ) => ( {
				id: page.id,
				// The REST API returns titles HTML-encoded; `el()` sets text through
				// `textContent`, so without decoding, a page called "Q&A" shows as
				// "Q&amp;A" in the picker.
				title: decodeEntities( page.title?.rendered ?? '' ) || `#${ page.id }`,
			} ) )
		),

	mergeTags: ( id: number ) =>
		get< { groups: MergeTagGroup[] } >( `/forms/${ id }/merge-tags` ).then( ( response ) => response.groups ),

	analytics: ( id: number ) =>
		get< {
			views: number;
			starts: number;
			submissions: number;
			conversion: number;
			completion: number;
			unread: number;
			spam: number;
			fields: Array< {
				id: string;
				label: string;
				type: string;
				answered: number;
				rate: number;
				average?: number | null;
				choices: Array< { label: string; value: string; count: number; percent: number } >;
			} >;
		} >( `/forms/${ id }/analytics` ),

	listEntries: ( params: {
		form_id?: number;
		status?: string;
		search?: string;
		page?: number;
		per_page?: number;
		after?: string;
		before?: string;
		starred?: boolean;
	} ) => get< { entries: Entry[]; total: number; pages: number } >( `/entries${ query( params ) }` ),

	getEntry: ( id: number ) => get< Entry >( `/entries/${ id }` ),

	updateEntry: ( id: number, body: { status?: string; starred?: boolean } ) => post< Entry >( `/entries/${ id }`, body ),

	deleteEntry: ( id: number ) => del< { deleted: boolean } >( `/entries/${ id }` ),

	exportEntries: ( params: {
		form_id: number;
		search?: string;
		after?: string;
		before?: string;
		starred?: boolean;
		format?: 'csv' | 'json';
	} ) => get< { filename: string; format: string; csv: string } >( `/entries/export${ query( params ) }` ),

	listThemes: () => get< Theme[] >( '/themes' ),

	saveTheme: ( body: { id?: number; label: string; slug?: string; description?: string; tokens: Values } ) =>
		post< Theme >( '/themes', body ),

	deleteTheme: ( id: number ) => del< { deleted: boolean } >( `/themes/${ id }` ),
};

export { config as runtime };

/** Linked Markdown ships in the builder, ready for MIO's local retrieval. */
const files = import.meta.glob< string >( '../../docs/mio/**/*.md', { query: '?raw', import: 'default', eager: true } );
export const mioDocuments = Object.entries( files ).map( ( [ path, markdown ] ) => ( {
	id: path.replace( '../../docs/mio/', '' ),
	title: markdown.split( '\n' )[ 0 ].replace( /^# /, '' ),
	version: 'form-schema-1',
	topics: [ 'forms', path.includes( '/components/' ) ? 'components' : path.split( '/' ).pop()!.replace( '.md', '' ) ],
	componentIds: path.includes( '/components/' ) && ! path.endsWith( '/index.md' ) ? [ path.split( '/' ).pop()!.replace( '.md', '' ) ] : [],
	markdown,
} ) );

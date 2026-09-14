/** YAML transport shared by the builder and the offline validator. */
import { parseDocument, stringify, visit, isAlias } from 'yaml';
import Ajv from 'ajv';

export const MAX_PACKAGE_BYTES = 16 * 1024 * 1024;

/** Parse exactly one YAML 1.2 document using only JSON-compatible values. */
export function parsePackage( text ) {
	if ( new TextEncoder().encode( text ).length > MAX_PACKAGE_BYTES ) {
		throw new Error( 'The form package exceeds the 16 MiB limit.' );
	}
	const document = parseDocument( text, { version: '1.2', uniqueKeys: true, stringKeys: true } );
	const problem = [ ...document.errors, ...document.warnings ][ 0 ];
	if ( problem ) {
		throw new Error( problem.message );
	}
	if ( document.directives.yaml.version !== '1.2' ) {
		throw new Error( 'Use YAML 1.2.' );
	}
	visit( document, ( _key, node, path ) => {
		if ( path.length > 64 ) {
			throw new Error( 'The document is nested too deeply.' );
		}
		if ( isAlias( node ) || node?.anchor || node?.tag ) {
			throw new Error( 'Use ordinary values: YAML anchors, aliases and explicit tags are not supported.' );
		}
	} );
	const value = document.toJS( { maxAliasCount: 0 } );
	const inspect = ( item, depth = 0 ) => {
		if ( depth > 32 ) throw new Error( 'The document is nested too deeply.' );
		if ( typeof item === 'number' && ( ! Number.isFinite( item ) || ( Number.isInteger( item ) && ! Number.isSafeInteger( item ) ) ) ) {
			throw new Error( 'Numbers must be finite and integers must be within the safe JSON range.' );
		}
		if ( item && typeof item === 'object' ) {
			for ( const [ key, child ] of Object.entries( item ) ) {
				if ( [ '__proto__', 'prototype', 'constructor', '<<' ].includes( key ) ) {
					throw new Error( `Unsupported mapping key: ${ key }.` );
				}
				inspect( child, depth + 1 );
			}
		}
	};
	inspect( value );
	return value;
}

/** Stable, readable output, with literal blocks for multiline HTML and text. */
export function stringifyPackage( value ) {
	return '# AllTerrain Forms — portable form v1\n# Edit values, keep field IDs stable, then validate before importing.\n' +
		stringify( value, { indent: 2, lineWidth: 0, aliasDuplicateObjects: false, blockQuote: 'literal' } );
}

/** Compile the same versioned contract used by the PHP import boundary. */
export function packageValidator( schema ) {
	const validate = new Ajv( { allErrors: true, strict: false } ).compile( schema );
	return ( value ) => {
		if ( ! validate( value ) ) {
			throw new Error( validate.errors.map( ( error ) => `${ error.instancePath || '/' }: ${ error.message }${ error.params.additionalProperty ? ` (${ error.params.additionalProperty })` : '' }` ).join( '\n' ) );
		}
		const checkFields = ( fields, inherited = [] ) => {
			const ids = fields.map( ( field ) => field.id );
			if ( new Set( ids ).size !== ids.length ) throw new Error( 'Field IDs must be unique within each field list.' );
			for ( const field of fields ) {
				for ( const rule of field.logic?.rules ?? [] ) {
					if ( ! [ ...inherited, ...ids ].includes( rule.field ) ) throw new Error( `Field ${ field.id } references missing field ${ rule.field }.` );
				}
				if ( field.fields ) checkFields( field.fields, [ ...inherited, ...ids ] );
			}
		};
		checkFields( value.form.schema.fields );
	};
}

/** PHP serializes empty maps as []; convert only schema-declared object nodes. */
export function packageObjects( value, schema, root = schema ) {
	const shape = schema.$ref ? root.definitions[ schema.$ref.split( '/' ).pop() ] : schema;
	if ( shape.type === 'object' && Array.isArray( value ) && value.length === 0 ) return {};
	if ( value === null || typeof value !== 'object' ) return value;
	if ( Array.isArray( value ) ) return value.map( ( child ) => packageObjects( child, shape.items ?? {}, root ) );
	return Object.fromEntries( Object.entries( value ).map( ( [ key, child ] ) => [
		key, packageObjects( child, shape.properties?.[ key ] ?? ( typeof shape.additionalProperties === 'object' ? shape.additionalProperties : {} ), root ),
	] ) );
}

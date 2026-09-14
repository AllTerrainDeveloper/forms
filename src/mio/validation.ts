/** Structured, repairable errors; validation is always a read-only operation. */
import Ajv from 'ajv';
import contract from '../../schemas/form-package-v1.schema.json';
import { parsePackage } from '../shared/form-package.mjs';
import type { FormDraft } from './types';

export const MAX_ASSISTANT_BYTES = 16000;
/** Modern MIO compacts superseded documents before the next provider call. */
export const MAX_COMPACT_ASSISTANT_BYTES = 40000;
export interface ValidationIssue { code: string; path: string; message: string; suggestion: string; line?: number; column?: number }
export class DraftValidationError extends Error {
	public constructor( public readonly issues: ValidationIssue[] ) { super( issues.map( ( issue ) => `${ issue.path }: ${ issue.message } ${ issue.suggestion }` ).join( '\n' ) ); }
}
const validate = new Ajv( { allErrors: true, strict: false } ).compile( {
	...contract.properties.form, definitions: contract.definitions,
} );

/** Parse an editor YAML document, reporting all structural issues up to twenty. */
export function parseFormDraft( yaml: string, maxBytes = MAX_ASSISTANT_BYTES ): FormDraft {
	if ( new TextEncoder().encode( yaml ).length > maxBytes ) {
		throw new DraftValidationError( [ { code: 'size_limit', path: '/', message: `The assistant document exceeds ${ maxBytes / 1000 } KB.`, suggestion: 'Use the builder or full YAML import for this form; do not remove fields to fit.' } ] );
	}
	let draft: unknown;
	try { draft = parsePackage( yaml ); } catch ( error ) {
		const message = error instanceof Error ? error.message : String( error );
		const position = /line (\d+), column (\d+)/.exec( message );
		throw new DraftValidationError( [ { code: 'yaml_syntax', path: '/', message, suggestion: 'Correct the indentation, duplicate key or quoting at this location. Return one complete YAML document without Markdown fences.', ...( position ? { line: Number( position[ 1 ] ), column: Number( position[ 2 ] ) } : {} ) } ] );
	}
	if ( ! validate( draft ) ) {
		throw new DraftValidationError( ( validate.errors ?? [] ).slice( 0, 20 ).map( ( issue ) => ( {
			code: issue.keyword,
			path: `${ issue.instancePath || '' }${ issue.params.missingProperty ? `/${ issue.params.missingProperty }` : '' }${ issue.params.additionalProperty ? `/${ issue.params.additionalProperty }` : '' }` || '/',
			message: issue.message ?? 'Invalid value.',
			suggestion: issue.keyword === 'type' ? `Use ${ issue.params.type }; booleans are true/false without quotes.` : issue.keyword === 'enum' ? `Choose one of: ${ JSON.stringify( issue.params.allowedValues ) }.` : 'Check forms.md and the component reference. Fix this property without discarding unrelated settings.',
		} ) ) );
	}
	return draft as unknown as FormDraft;
}

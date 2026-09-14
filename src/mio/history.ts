/** Keep one complete candidate, while retaining errors and write receipts. */
import type { MioHistory } from './types';

type RecordValue = Record< string, unknown >;
const record = ( value: unknown ): value is RecordValue => !! value && typeof value === 'object' && ! Array.isArray( value );

/**
 * MIO nests rejected-tool history in result.data, successful history at the top.
 * Recognize only this adapter's document records; help text is never edited.
 */
export function compactFormHistory( history: MioHistory ): MioHistory {
	const documents: RecordValue[] = [];
	const copy = structuredClone( history );
	for ( const entry of copy.outcomes ) {
		if ( ! record( entry ) ) continue;
		const result = record( entry.result ) ? entry.result : undefined;
		const feedback = result && record( result.data ) ? result.data : undefined;
		for ( const container of [ entry, feedback ] ) {
			if ( container && record( container.document ) && typeof container.document.yaml === 'string' ) documents.push( container.document );
		}
	}
	const latest = documents[ documents.length - 1 ];
	for ( const document of documents.slice( 0, -1 ) ) {
		delete document.yaml;
		document.supersededBy = latest?.id;
	}
	return copy;
}

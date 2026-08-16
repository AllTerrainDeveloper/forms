/**
 * The histogram's bucketing.
 *
 * The spread of a numeric question is visitor-controlled: the report used to
 * draw one DOM column per integer between the smallest and largest answer, so a
 * single submission of ten million bought ten million elements and a frozen
 * admin tab. These tests pin the two properties that prevent that: the column
 * count is capped, and no answer is dropped on the way into a column — not the
 * fractional ones ("3.5" is a legal answer and a legal JSON key), and not the
 * ones outside the min–max envelope.
 */

import { describe, expect, it } from 'vitest';
import { histogramBuckets, MAX_HISTOGRAM_BUCKETS } from '../../src/analytics';
import type { NumberSummary } from '../../src/types';

/** A summary with only the fields the bucketing reads filled in per case. */
function summary( partial: Partial< NumberSummary > ): NumberSummary {
	return {
		count: 0,
		mean: 0,
		median: 0,
		min: 0,
		max: 0,
		distribution: {},
		...partial,
	};
}

/** Every answer, counted — what must survive any amount of bucketing. */
function totalCount( buckets: ReturnType< typeof histogramBuckets > ): number {
	return buckets.reduce( ( sum, bucket ) => sum + bucket.count, 0 );
}

describe( 'a small integer spread', () => {
	const buckets = histogramBuckets(
		summary( {
			min: 1,
			max: 5,
			mean: 2.57,
			distribution: { '1': 2, '3': 4, '5': 1 },
		} )
	);

	it( 'keeps one column per integer', () => {
		expect( buckets.map( ( bucket ) => bucket.label ) ).toEqual( [ '1', '2', '3', '4', '5' ] );
	} );

	it( 'places each count in its own column', () => {
		expect( buckets.map( ( bucket ) => bucket.count ) ).toEqual( [ 2, 0, 4, 0, 1 ] );
	} );

	it( 'marks the column the rounded mean falls in', () => {
		expect( buckets.filter( ( bucket ) => bucket.holdsMean ).map( ( bucket ) => bucket.label ) ).toEqual( [ '3' ] );
	} );
} );

describe( 'fractional answers', () => {
	it( 'rounds them into a column instead of dropping them', () => {
		const buckets = histogramBuckets(
			summary( { min: 1, max: 5, mean: 3, distribution: { '2': 1, '3.5': 2 } } )
		);

		const byLabel = Object.fromEntries( buckets.map( ( bucket ) => [ bucket.label, bucket.count ] ) );

		expect( byLabel[ '2' ] ).toBe( 1 );
		expect( byLabel[ '4' ] ).toBe( 2 );
		expect( totalCount( buckets ) ).toBe( 3 );
	} );
} );

describe( 'a hostile spread', () => {
	const buckets = histogramBuckets(
		summary( {
			min: 1,
			max: 1_000_000,
			mean: 400_001.6,
			distribution: { '1': 3, '1000000': 2 },
		} )
	);

	it( 'never exceeds the cap', () => {
		expect( buckets.length ).toBeLessThanOrEqual( MAX_HISTOGRAM_BUCKETS );
		expect( buckets.length ).toBeGreaterThan( 0 );
	} );

	it( 'labels the columns as ranges', () => {
		expect( buckets[ 0 ].label ).toBe( '1–20000' );
		expect( buckets[ buckets.length - 1 ].label ).toBe( '980001–1000000' );
	} );

	it( 'sums every answer into its range', () => {
		expect( buckets[ 0 ].count ).toBe( 3 );
		expect( buckets[ buckets.length - 1 ].count ).toBe( 2 );
		expect( totalCount( buckets ) ).toBe( 5 );
	} );

	it( 'still marks exactly one column as holding the mean', () => {
		expect( buckets.filter( ( bucket ) => bucket.holdsMean ) ).toHaveLength( 1 );
	} );
} );

describe( 'answers outside the min–max envelope', () => {
	it( 'clamps them into the nearest column rather than losing them', () => {
		const buckets = histogramBuckets(
			summary( { min: 1, max: 10, mean: 5, distribution: { '12': 1, '-3': 1, '5': 1 } } )
		);

		expect( buckets[ buckets.length - 1 ].count ).toBe( 1 );
		expect( buckets[ 0 ].count ).toBe( 1 );
		expect( totalCount( buckets ) ).toBe( 3 );
	} );
} );

describe( 'degenerate summaries', () => {
	it( 'returns nothing for a non-finite envelope', () => {
		expect( histogramBuckets( summary( { min: Number.NaN, max: 5 } ) ) ).toEqual( [] );
	} );

	it( 'draws a single-answer question as one column', () => {
		const buckets = histogramBuckets( summary( { min: 7, max: 7, mean: 7, distribution: { '7': 4 } } ) );

		expect( buckets ).toHaveLength( 1 );
		expect( buckets[ 0 ] ).toEqual( { label: '7', count: 4, holdsMean: true } );
	} );
} );

/**
 * Moving a field means landing where the marker said.
 *
 * Three things reorder the canvas — a drop, the marker that previews the drop,
 * and Alt+Arrow — and all three have to agree on what an index *means*. They
 * did not: the drop was computed with the dragged card excluded, the marker
 * with it included, and the move itself subtracted one on the way down. The
 * visible result was the quiet kind of wrong: a card dragged downward landed
 * one slot above its marker, and Alt+ArrowDown removed a field and put it back
 * exactly where it was, which reads as "moving down is not supported".
 *
 * The convention is now stated once, in `fieldMove()`: an index counts the
 * list *without* the moved field — the same space `insertionIndex()` works in
 * when the dragged card is excluded. These tests hold each caller to it.
 */

import { describe, expect, it } from 'vitest';
import { fieldMove } from '../../src/builder';
import { insertionIndex } from '../../src/dnd';

/** Applies a move the way `moveField()` does, returning the resulting order. */
function apply( ids: string[], fieldId: string, index: number ): string[] {
	const fields = ids.map( ( id ) => ( { id } ) );
	const move = fieldMove( fields, fieldId, index );

	if ( ! move ) {
		return ids;
	}

	const [ moved ] = fields.splice( move.from, 1 );

	fields.splice( move.to, 0, moved );

	return fields.map( ( field ) => field.id );
}

describe( 'fieldMove', () => {
	it( 'moves a field downward to exactly the slot named', () => {
		// The slot counts the list without "a": index 2 of [b, c, d] is
		// between c and d — which is where the marker would have been drawn.
		expect( apply( [ 'a', 'b', 'c', 'd' ], 'a', 2 ) ).toEqual( [ 'b', 'c', 'a', 'd' ] );
	} );

	it( 'moves a field upward to exactly the slot named', () => {
		expect( apply( [ 'a', 'b', 'c', 'd' ], 'd', 1 ) ).toEqual( [ 'a', 'd', 'b', 'c' ] );
	} );

	it( 'moves to the very end when the index points past the last card', () => {
		expect( apply( [ 'a', 'b', 'c', 'd' ], 'a', 3 ) ).toEqual( [ 'b', 'c', 'd', 'a' ] );

		// `insertionIndex()` returns the child count when the pointer is below
		// everything; clamping has to absorb that rather than throw it away.
		expect( apply( [ 'a', 'b', 'c', 'd' ], 'a', 99 ) ).toEqual( [ 'b', 'c', 'd', 'a' ] );
	} );

	it( 'moves down one slot for Alt+ArrowDown', () => {
		// The keystroke passes `index + 1`. Under the old with-the-field
		// indexing this removed at `index` and re-inserted at `index` — a
		// keyboard user could move a field up but never down.
		expect( apply( [ 'a', 'b', 'c', 'd' ], 'b', 1 + 1 ) ).toEqual( [ 'a', 'c', 'b', 'd' ] );
	} );

	it( 'moves up one slot for Alt+ArrowUp', () => {
		expect( apply( [ 'a', 'b', 'c', 'd' ], 'c', 2 - 1 ) ).toEqual( [ 'a', 'c', 'b', 'd' ] );
	} );

	it( 'does nothing at either end', () => {
		expect( fieldMove( [ { id: 'a' }, { id: 'b' } ], 'a', -1 ) ).toBeNull();
		expect( fieldMove( [ { id: 'a' }, { id: 'b' } ], 'b', 2 ) ).toBeNull();
	} );

	it( 'does nothing when the slot is the one the field already holds', () => {
		expect( fieldMove( [ { id: 'a' }, { id: 'b' }, { id: 'c' } ], 'b', 1 ) ).toBeNull();
	} );

	it( 'does nothing for a field that is not in the list', () => {
		// Deleted in another window mid-drag; a write that should simply not
		// happen rather than one that should throw.
		expect( fieldMove( [ { id: 'a' } ], 'ghost', 0 ) ).toBeNull();
	} );
} );

/**
 * The full round trip: pointer position → `insertionIndex()` with the dragged
 * card excluded → `fieldMove()`. This is the pairing the drop handler and the
 * marker both use, so if the two functions ever slip back into different
 * indexing spaces, this is the test that says so.
 */
describe( 'a drop lands where the marker was drawn', () => {
	/** A canvas list of 40px-tall cards, with real geometry stubbed in. */
	function canvasList( ids: string[] ): { container: HTMLElement; card: ( id: string ) => HTMLElement } {
		const container = document.createElement( 'div' );
		const cards = new Map< string, HTMLElement >();

		ids.forEach( ( id, index ) => {
			const card = document.createElement( 'div' );

			card.className = 'atfb-card';
			card.dataset.atfbCard = id;

			// jsdom lays nothing out, so each card states its own box.
			const top = index * 40;

			card.getBoundingClientRect = () =>
				( { top, bottom: top + 40, height: 40, left: 0, right: 100, width: 100, x: 0, y: top, toJSON: () => ( {} ) } ) as DOMRect;

			container.append( card );
			cards.set( id, card );
		} );

		return { container, card: ( id: string ) => cards.get( id ) as HTMLElement };
	}

	it( 'dragging the first card below the third puts it there, not one above', () => {
		const ids = [ 'a', 'b', 'c', 'd' ];
		const { container, card } = canvasList( ids );

		// The pointer sits in the lower half of card c (80–120px), past its
		// midpoint at 100 — the marker flips to the slot between c and d.
		const index = insertionIndex( container, '.atfb-card', 110, card( 'a' ) );

		expect( index ).toBe( 2 );
		expect( apply( ids, 'a', index ) ).toEqual( [ 'b', 'c', 'a', 'd' ] );
	} );

	it( 'dragging the last card above the second puts it there', () => {
		const ids = [ 'a', 'b', 'c', 'd' ];
		const { container, card } = canvasList( ids );

		// Upper half of card b: above its midpoint at 60.
		const index = insertionIndex( container, '.atfb-card', 45, card( 'd' ) );

		expect( index ).toBe( 1 );
		expect( apply( ids, 'd', index ) ).toEqual( [ 'a', 'd', 'b', 'c' ] );
	} );

	it( 'a drop just past the dragged card’s own slot is not a move at all', () => {
		const ids = [ 'a', 'b', 'c', 'd' ];
		const { container, card } = canvasList( ids );

		// Hovering over its own position: with b excluded, the slot between a
		// and c is index 1 — exactly where b already is.
		const index = insertionIndex( container, '.atfb-card', 60, card( 'b' ) );

		expect( index ).toBe( 1 );
		expect( fieldMove( ids.map( ( id ) => ( { id } ) ), 'b', index ) ).toBeNull();
	} );

	it( 'a drop below everything appends', () => {
		const ids = [ 'a', 'b', 'c' ];
		const { container, card } = canvasList( ids );

		const index = insertionIndex( container, '.atfb-card', 500, card( 'a' ) );

		expect( index ).toBe( 2 );
		expect( apply( ids, 'a', index ) ).toEqual( [ 'b', 'c', 'a' ] );
	} );
} );

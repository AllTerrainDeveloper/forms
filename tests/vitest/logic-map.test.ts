/**
 * Conditional logic, described.
 *
 * These functions turn a rule into a sentence a person reads on a card, so the
 * thing under test is *wording*. That sounds like a soft target for tests until
 * you notice what the wrong wording does: a builder that says "Shown when" over
 * a hide rule tells somebody their form does the opposite of what it does, and
 * they will believe the label over the dropdowns.
 *
 * The curve drawing is not tested here. It is geometry over live DOM rects with
 * no branching worth pinning, and a test asserting a Bézier control point would
 * fail on every visual tweak while catching nothing.
 */

import { describe, expect, it } from 'vitest';
import {
	controlCounts,
	describeLogic,
	describeRule,
	describeTrigger,
	logicEdges,
	logicTokens,
	ruleTokens,
	tokensToText,
} from '../../src/logic-map';
import type { Field, Logic, LogicOperator } from '../../src/types';

/** A field with only what these functions read. */
function field( id: string, label: string, extra: Partial< Field > = {} ): Field {
	return {
		id,
		type: 'text',
		label,
		placeholder: '',
		hint: '',
		required: false,
		width: 'full',
		cssClass: '',
		default: '',
		choices: [],
		logic: { enabled: false, action: 'show', match: 'all', rules: [] },
		messages: {},
		prefill: '',
		...extra,
	} as Field;
}

/** A logic block, spelled out. */
function logic( rules: Array< [ string, LogicOperator, string ] >, extra: Partial< Logic > = {} ): Logic {
	return {
		enabled: true,
		action: 'show',
		match: 'all',
		rules: rules.map( ( [ from, operator, value ] ) => ( { field: from, operator, value } ) ),
		...extra,
	};
}

/** The RSVP form the builder screenshots use. */
function rsvp(): Field[] {
	return [
		field( 'f1', 'Your name' ),
		field( 'f3', 'Can you make it?', {
			type: 'radio',
			choices: [
				{ label: 'Yes, I will be there', value: 'yes' },
				{ label: 'Sorry, I cannot', value: 'no' },
			],
		} ),
		field( 'f4', 'How many of you?', { type: 'number', logic: logic( [ [ 'f3', 'is', 'yes' ] ] ) } ),
		field( 'f5', 'Any dietary requirements?', {
			type: 'checkboxes',
			logic: logic( [ [ 'f3', 'is', 'yes' ] ] ),
		} ),
	];
}

describe( 'describeRule', () => {
	it( 'names the question and the choice by their labels', () => {
		const fields = rsvp();

		// Neither `f3` nor `yes` appears: those are storage, and the whole point
		// is that nobody should have to know them.
		expect( describeRule( { field: 'f3', operator: 'is', value: 'yes' }, fields ) ).toBe(
			'Can you make it? is Yes, I will be there'
		);
	} );

	it( 'falls back to the raw value when there are no choices to name', () => {
		const fields = [ field( 'f1', 'Your name' ) ];

		expect( describeRule( { field: 'f1', operator: 'contains', value: 'Ada' }, fields ) ).toBe(
			'Your name contains Ada'
		);
	} );

	it( 'reads operators as English rather than as their enum', () => {
		const fields = [ field( 'f2', 'How many?', { type: 'number' } ) ];

		expect( describeRule( { field: 'f2', operator: 'greater_equal', value: '3' }, fields ) ).toBe(
			'How many? is at least 3'
		);
	} );

	it( 'says nothing about a value for the operators that have none', () => {
		const fields = [ field( 'f1', 'Your name' ) ];

		expect( describeRule( { field: 'f1', operator: 'not_empty', value: '' }, fields ) ).toBe(
			'Your name has any answer'
		);
	} );

	it( 'calls out a rule pointing at a deleted question', () => {
		// This is a broken form: the rule can never match, so the field it guards
		// is stuck. Rendering a blank subject would leave somebody reading
		// "Shown when  is yes" and blaming the builder.
		expect( describeRule( { field: 'gone', operator: 'is', value: 'yes' }, rsvp() ) ).toBe(
			'a question that no longer exists'
		);
	} );

	it( 'still says something for a question with no label', () => {
		const fields = [ field( 'f1', '' ) ];

		expect( describeRule( { field: 'f1', operator: 'is', value: 'x' }, fields ) ).toBe(
			'an untitled question is x'
		);
	} );
} );

describe( 'describeTrigger', () => {
	it( 'drops the question, because the curve already points at it', () => {
		expect( describeTrigger( { field: 'f3', operator: 'is', value: 'yes' }, rsvp() ) ).toBe(
			'Yes, I will be there'
		);
	} );

	it( 'keeps the operator when it is not a plain equality', () => {
		const fields = [ field( 'f2', 'How many?', { type: 'number' } ) ];

		expect( describeTrigger( { field: 'f2', operator: 'greater', value: '3' }, fields ) ).toBe(
			'is more than 3'
		);
	} );
} );

describe( 'describeLogic', () => {
	it( 'is empty when a field has no condition, so callers can test on it', () => {
		expect( describeLogic( field( 'f1', 'Your name' ), [] ) ).toBe( '' );
	} );

	it( 'is empty when logic is switched on but has no rules', () => {
		const target = field( 'f4', 'How many?', { logic: logic( [] ) } );

		// "Shown when" with nothing after it is worse than saying nothing.
		expect( describeLogic( target, [ target ] ) ).toBe( '' );
	} );

	it( 'says shown for a show rule', () => {
		const fields = rsvp();

		expect( describeLogic( fields[ 2 ], fields ) ).toBe(
			'Shown when Can you make it? is Yes, I will be there'
		);
	} );

	it( 'says hidden for a hide rule', () => {
		// The one that matters most: a label reading "Shown when" over a hide rule
		// describes the opposite form, and it is the label people will believe.
		const fields = rsvp();

		fields[ 2 ].logic = logic( [ [ 'f3', 'is', 'no' ] ], { action: 'hide' } );

		expect( describeLogic( fields[ 2 ], fields ) ).toBe(
			'Hidden when Can you make it? is Sorry, I cannot'
		);
	} );

	it( 'joins with and, or or, following the match mode', () => {
		const fields = rsvp();

		fields[ 2 ].logic = logic(
			[
				[ 'f3', 'is', 'yes' ],
				[ 'f1', 'not_empty', '' ],
			],
			{ match: 'any' }
		);

		expect( describeLogic( fields[ 2 ], fields ) ).toBe(
			'Shown when Can you make it? is Yes, I will be there or Your name has any answer'
		);

		fields[ 2 ].logic.match = 'all';

		expect( describeLogic( fields[ 2 ], fields ) ).toContain( ' and ' );
	} );
} );

describe( 'logicEdges', () => {
	it( 'draws one edge per rule, from the controller to the dependent', () => {
		const edges = logicEdges( rsvp() );

		expect( edges ).toHaveLength( 2 );
		expect( edges.map( ( edge ) => `${ edge.from }->${ edge.to }` ) ).toEqual( [ 'f3->f4', 'f3->f5' ] );
		expect( edges[ 0 ].action ).toBe( 'show' );
		expect( edges[ 0 ].broken ).toBe( false );
	} );

	it( 'ignores a field whose logic is switched off', () => {
		const fields = rsvp();

		fields[ 2 ].logic.enabled = false;

		expect( logicEdges( fields ) ).toHaveLength( 1 );
	} );

	it( 'drops a rule that names its own field', () => {
		// A self-reference can never resolve. Drawing a loop from a card back to
		// itself adds a picture of the bug to the bug.
		const target = field( 'f4', 'How many?', { logic: logic( [ [ 'f4', 'is', 'x' ] ] ) } );

		expect( logicEdges( [ target ] ) ).toHaveLength( 0 );
	} );

	it( 'marks an edge broken when the controller is gone', () => {
		const target = field( 'f4', 'How many?', { logic: logic( [ [ 'gone', 'is', 'yes' ] ] ) } );

		expect( logicEdges( [ target ] )[ 0 ].broken ).toBe( true );
	} );
} );

describe( 'controlCounts', () => {
	it( 'counts the fields a question decides', () => {
		expect( controlCounts( rsvp() ).get( 'f3' ) ).toBe( 2 );
	} );

	it( 'counts fields and not rules', () => {
		// Two rules naming the same controller are one relationship. A badge
		// reading "controls 2 fields" when it controls one is a lie in a place
		// nobody would think to check.
		const fields = rsvp();

		fields[ 2 ].logic = logic(
			[
				[ 'f3', 'is', 'yes' ],
				[ 'f3', 'is_not', 'no' ],
			],
			{ match: 'any' }
		);

		expect( controlCounts( fields ).get( 'f3' ) ).toBe( 2 );
	} );

	it( 'does not count a broken edge', () => {
		const target = field( 'f4', 'How many?', { logic: logic( [ [ 'gone', 'is', 'yes' ] ] ) } );

		expect( controlCounts( [ target ] ).size ).toBe( 0 );
	} );
} );

describe( 'ruleTokens', () => {
	it( 'separates the question, the comparison and the answer', () => {
		// The whole reason these are parts and not a sentence: both the question
		// and the answer are text somebody typed, so the question ends in a
		// question mark and the answer contains a comma. Any reader — a person or
		// a stylesheet — that has only the joined string has to guess where the
		// boundaries are, and the punctuation it would guess from is inside the
		// content.
		expect( ruleTokens( { field: 'f3', operator: 'is', value: 'yes' }, rsvp() ) ).toEqual( [
			{ kind: 'field', text: 'Can you make it?', fieldId: 'f3', missing: false },
			{ kind: 'operator', text: 'is' },
			{ kind: 'value', text: 'Yes, I will be there' },
		] );
	} );

	it( 'emits no value token for an operator that has no value', () => {
		const fields = [ field( 'f1', 'Your name' ) ];
		const tokens = ruleTokens( { field: 'f1', operator: 'not_empty', value: '' }, fields );

		expect( tokens.map( ( token ) => token.kind ) ).toEqual( [ 'field', 'operator' ] );
	} );

	it( 'marks a missing question so it can be drawn as the error it is', () => {
		const tokens = ruleTokens( { field: 'gone', operator: 'is', value: 'yes' }, rsvp() );

		expect( tokens ).toHaveLength( 1 );
		expect( tokens[ 0 ] ).toMatchObject( { kind: 'field', missing: true } );
	} );

	it( 'carries the field id, so the chip is a reference you can follow', () => {
		const tokens = ruleTokens( { field: 'f3', operator: 'is', value: 'yes' }, rsvp() );

		expect( tokens[ 0 ] ).toMatchObject( { kind: 'field', fieldId: 'f3' } );
	} );
} );

describe( 'logicTokens', () => {
	it( 'leads with the verb and joins rules with a join token', () => {
		const fields = rsvp();

		fields[ 2 ].logic = logic(
			[
				[ 'f3', 'is', 'yes' ],
				[ 'f1', 'not_empty', '' ],
			],
			{ match: 'any' }
		);

		expect( logicTokens( fields[ 2 ], fields ).map( ( token ) => token.kind ) ).toEqual( [
			'verb',
			'field',
			'operator',
			'value',
			'join',
			'field',
			'operator',
		] );
	} );

	it( 'is empty for a field with no condition', () => {
		expect( logicTokens( field( 'f1', 'Your name' ), [] ) ).toEqual( [] );
	} );
} );

describe( 'tokensToText', () => {
	it( 'is what describeLogic returns, so the two readings cannot drift', () => {
		// The chips are the visual reading and the sentence is the one a screen
		// reader gets. Assembling them separately is how a builder ends up
		// showing one thing and announcing another.
		const fields = rsvp();

		expect( tokensToText( logicTokens( fields[ 2 ], fields ) ) ).toBe( describeLogic( fields[ 2 ], fields ) );
		expect( tokensToText( ruleTokens( fields[ 2 ].logic.rules[ 0 ], fields ) ) ).toBe(
			describeRule( fields[ 2 ].logic.rules[ 0 ], fields )
		);
	} );
} );

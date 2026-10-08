/**
 * The formula editor.
 *
 * A formula was a bare text box and a hint listing function names — which
 * assumes the person writing it already knows their fields' ids and the
 * engine's grammar, the two things nobody knows. The editor turns both into
 * buttons: click a question to reference it, click a function to apply it,
 * and watch the result compute live against sample answers as you type — the
 * same shared engine the front end runs, so a formula that computes here
 * computes there.
 */

import { calculate } from './shared/calc';
import { insertAtCursor, taggable } from './merge-tags';
import { button, el, row } from './ui';
import type { Field, MergeTagGroup, Values } from './types';

/** The engine's functions, in the order a palette should offer them. */
export const FORMULA_FUNCTIONS = [ 'sum', 'min', 'max', 'avg', 'round', 'ceil', 'floor', 'abs', 'sqrt', 'pow' ];

/**
 * What each function does, in words and by example.
 *
 * A row of `ceil()` and `abs()` chips teaches nothing to somebody who did not
 * already know them, and the person building an order form is rarely somebody
 * who did. Shown when a chip is hovered or focused, and as its tooltip.
 */
export const FORMULA_FUNCTION_HELP: Record< string, { usage: string; help: string } > = {
	sum: { usage: 'sum( a, b, … )', help: 'Adds them all up. sum( {attendees.age} ) adds every row of a repeater.' },
	min: { usage: 'min( a, b, … )', help: 'The smallest of them — min( {f1}, 100 ) caps a value at 100.' },
	max: { usage: 'max( a, b, … )', help: 'The largest of them — max( {f1}, 0 ) never lets a value go negative.' },
	avg: { usage: 'avg( a, b, … )', help: 'The average of them.' },
	round: { usage: 'round( x, places )', help: 'Rounds to the nearest whole number, or to that many decimal places: round( {f1} * 1.21, 2 ).' },
	ceil: { usage: 'ceil( x )', help: 'Rounds up — ceil( {guests} / 8 ) is how many tables you need.' },
	floor: { usage: 'floor( x )', help: 'Rounds down to the whole number below.' },
	abs: { usage: 'abs( x )', help: 'Drops the minus sign: the distance between two numbers, whichever is bigger.' },
	sqrt: { usage: 'sqrt( x )', help: 'The square root. A negative number gives 0.' },
	pow: { usage: 'pow( x, y )', help: 'x to the power of y — pow( {side}, 2 ) is an area.' },
};

/**
 * What a reference contributes to a sum, in words.
 *
 * The engine turns every answer into a number, and *how* is the part nobody can
 * guess: a dropdown counts as its option's price, a switch as one or zero. Said
 * next to each question in the `{` picker, so the choice of what to reference is
 * made knowing what it will add.
 *
 * @param field The referenced field.
 * @return One sentence.
 */
export function formulaReferenceHint( field: Field ): string {
	switch ( field.type ) {
		case 'number':
			return 'The number they type in. 0 until they do.';
		case 'range':
		case 'scale':
		case 'rating':
			return 'The number they pick. 0 until they do.';
		case 'total':
			return 'Whatever that total works out to.';
		case 'switch':
			return 'Counts as 1 when it is on, 0 when it is off.';
		case 'quiz':
			return 'The points of the answer they pick.';
		case 'checkboxes':
		case 'multiselect':
			return 'Adds up the price of every option they tick — or its value, when that is a number.';
		default:
			return 'The price of the option they pick — or its value, when that is a number.';
	}
}

/**
 * The field types whose value a formula can sensibly reference.
 *
 * The engine resolves anything — an unanswered text box is a zero — but a
 * palette that offers "Message" beside "Quantity" teaches that referencing a
 * paragraph is a reasonable thing to do. Choices are here because their
 * options can carry prices, which is most of why order forms calculate.
 */
const NUMERIC_FRIENDLY = [
	'number',
	'range',
	'scale',
	'rating',
	'total',
	'select',
	'multiselect',
	'radio',
	'checkboxes',
	'switch',
	'quiz',
];

/**
 * The fields worth offering as formula references.
 *
 * The field being edited is excluded: a total that references itself is a
 * loop, and the engine would only tell the visitor so at run time.
 *
 * @param fields The form's fields.
 * @param except The field id being edited.
 * @return Fields whose values a formula would plausibly use.
 */
export function formulaTargets( fields: Field[], except: string ): Field[] {
	return fields.filter( ( field ) => field.id !== except && NUMERIC_FRIENDLY.includes( field.type ) );
}

/** What one repeater reference chip offers. */
export interface RepeaterReference {
	/** Shown on the chip: "Attendees · Age", or "Attendees (how many)". */
	label: string;
	/** What clicking it types: `{att.age}`, or `{att}`. */
	insert: string;
	/** What it adds up to, in words. */
	hint: string;
}

/**
 * The references a form's repeaters offer a formula.
 *
 * Each repeater contributes its row count — `{attendees}`, which is what
 * "15 per attendee" needs — and every number-shaped sub-field, referenced as
 * `{attendees.age}`, which aggregates across however many rows the visitor
 * adds: `sum( {attendees.age} )` sums every age.
 *
 * @param fields The form's fields.
 * @return Chips worth offering.
 */
export function repeaterReferences( fields: Field[] ): RepeaterReference[] {
	const references: RepeaterReference[] = [];

	for ( const field of fields ) {
		if ( field.type !== 'repeater' ) {
			continue;
		}

		const name = field.label || field.id;

		references.push( {
			label: `${ name } (how many)`,
			insert: `{${ field.id }}`,
			hint: `How many ${ String( field.itemLabel ?? '' ).toLowerCase() || 'row' }s they added — 15 * {${ field.id }} charges 15 for each.`,
		} );

		for ( const sub of ( field.fields ?? [] ) as Field[] ) {
			if ( ! NUMERIC_FRIENDLY.includes( sub.type ) ) {
				continue;
			}

			references.push( {
				label: `${ name } · ${ sub.label || sub.id }`,
				insert: `{${ field.id }.${ sub.id }}`,
				hint: `Every row’s ${ sub.label || sub.id } added together. Inside avg(), min() or max() it compares the rows instead.`,
			} );
		}
	}

	return references;
}

/**
 * Deterministic sample answers, for previewing a formula before anyone submits.
 *
 * Each referenceable field counts up from one, so `{a} + {b}` previews as 3
 * rather than 0 + 0 — a preview where every sample is zero cannot tell a
 * working formula from one that references nothing.
 *
 * @param fields The form's fields.
 * @param except The field id being edited.
 * @return Field id => sample number.
 */
export function formulaSampleValues( fields: Field[], except: string ): Values {
	const values: Values = {};

	formulaTargets( fields, except ).forEach( ( field, index ) => {
		values[ field.id ] = index + 1;
	} );

	// Every repeater gets two sample rows, because one row cannot tell
	// `sum()` apart from a plain reference and zero rows previews everything
	// as 0. Sub-field k holds k+1 in the first row and k+2 in the second.
	for ( const field of fields ) {
		if ( field.type !== 'repeater' || field.id === except ) {
			continue;
		}

		const subs = ( field.fields ?? [] ) as Field[];
		const row = ( bump: number ) =>
			Object.fromEntries( subs.map( ( sub, index ) => [ sub.id, index + 1 + bump ] ) );

		values[ field.id ] = [ row( 0 ), row( 1 ) ] as unknown as Values[ string ];
	}

	return values;
}

/** "Small → 5, Large → 9": what a priced choice contributes, from its own options. */
function pricedSample( field: Field ): string {
	const priced = ( field.choices ?? [] )
		.filter( ( choice ) => typeof choice.price === 'number' || typeof choice.points === 'number' )
		.slice( 0, 3 )
		.map( ( choice ) => `${ choice.label || choice.value } → ${ choice.price ?? choice.points }` );

	return priced.join( ', ' );
}

/** A calculation input with the same brace shortcut as notification values. */
export function formulaInput(
	input: HTMLInputElement | HTMLTextAreaElement,
	fields: Field[],
	except: string
): HTMLElement {
	return taggable( input, {
		preview: false,
		intro: 'Pick a question to use its answer as a number. Join them with + - * / and brackets.',
		button: 'Insert a question',
		groups: () => {
			const repeaters = repeaterReferences( fields.filter( ( field ) => field.id !== except ) );
			const groups: MergeTagGroup[] = [ {
				id: 'references',
				label: 'Your questions',
				items: formulaTargets( fields, except ).map( ( field ) => ( {
					label: field.label || field.id,
					tag: `{${ field.id }}`,
					hint: formulaReferenceHint( field ),
					sample: pricedSample( field ),
				} ) ),
				empty: 'Add a number, scale or priced choice question to reference it here.',
			} ];

			if ( repeaters.length ) {
				groups.push( {
					id: 'repeaters',
					label: 'Repeating sections',
					items: repeaters.map( ( ref ) => ( { label: ref.label, tag: ref.insert, hint: ref.hint, sample: '' } ) ),
				} );
			}

			return groups;
		},
	} );
}

/** What the editor needs from its host. */
export interface FormulaEditorOptions {
	/** Where the overlay mounts — the builder root, so it stays inside the window. */
	root: HTMLElement;
	/** Every field on the form. */
	fields: Field[];
	/** The field whose formula this is. */
	field: Field;
	/** Called with the new formula when Save is pressed. */
	onSave: ( formula: string ) => void;
}

/**
 * Opens the editor as a modal over the builder.
 *
 * @param options What to edit and where to say so.
 * @return void
 */
export function openFormulaEditor( options: FormulaEditorOptions ): void {
	const overlay = el( 'div', { class: 'atfb-overlay' } );

	const close = () => {
		overlay.remove();
		document.removeEventListener( 'keydown', onKeydown );
	};

	const onKeydown = ( event: KeyboardEvent ) => {
		if ( event.key === 'Escape' ) {
			close();
		}
	};

	const input = el( 'textarea', {
		class: 'atfb-input atfb-formula__input',
		attrs: { rows: '3', 'aria-label': 'Formula' },
	} ) as HTMLTextAreaElement;

	input.value = String( options.field.formula ?? '' );

	const result = el( 'p', { class: 'atfb-formula__result', attrs: { 'aria-live': 'polite' } } );
	const samples = formulaSampleValues( options.fields, options.field.id );

	/** Recomputes the sample result. Runs on every keystroke and insertion. */
	const preview = () => {
		const formula = input.value.trim();

		if ( '' === formula ) {
			result.textContent = 'Empty. Reference a question below to start.';
			result.classList.remove( 'is-error' );

			return;
		}

		const computed = calculate( formula, samples, options.fields );

		if ( null === computed ) {
			result.textContent = 'This does not compute yet — check the braces and parentheses.';
			result.classList.add( 'is-error' );

			return;
		}

		const sampled = formulaTargets( options.fields, options.field.id )
			.map( ( field, index ) => `${ field.label || field.id } = ${ index + 1 }` )
			.concat(
				options.fields
					.filter( ( field ) => field.type === 'repeater' && field.id !== options.field.id )
					.map( ( field ) => `${ field.label || field.id } = 2 sample rows` )
			)
			.join( ', ' );

		result.textContent = `With sample answers (${ sampled }): ${ computed }`;
		result.classList.remove( 'is-error' );
	};

	input.addEventListener( 'input', preview );

	const help = el( 'p', {
		class: 'atfb-hint atfb-formula__help',
		attrs: { 'aria-live': 'polite' },
		text: 'Point at a function to see what it does.',
	} );

	const chip = ( label: string, insert: string, caretBack = 0, explain = '' ) =>
		el( 'button', {
			class: 'atfb-formula__chip',
			type: 'button',
			text: label,
			title: explain || undefined,
			on: {
				mouseenter: () => {
					if ( explain && caretBack ) {
						help.textContent = explain;
					}
				},
				focus: () => {
					if ( explain && caretBack ) {
						help.textContent = explain;
					}
				},
				click: () => {
					insertAtCursor( input, insert );

					if ( caretBack > 0 ) {
						const caret = ( input.selectionStart ?? input.value.length ) - caretBack;

						input.setSelectionRange( caret, caret );
					}
				},
			},
		} );

	const targets = formulaTargets( options.fields, options.field.id );
	const repeaters = repeaterReferences( options.fields.filter( ( field ) => field.id !== options.field.id ) );

	const questions = el( 'div', {
		class: 'atfb-formula__chips',
		children:
			targets.length || repeaters.length
				? [
						...targets.map( ( field ) => chip( field.label || field.id, `{${ field.id }}`, 0, formulaReferenceHint( field ) ) ),
						...repeaters.map( ( reference ) => chip( reference.label, reference.insert, 0, reference.hint ) ),
				  ]
				: [ el( 'p', { class: 'atfb-hint', text: 'No number-shaped questions yet — add a number, scale or priced choice field and it appears here.' } ) ],
	} );

	const functions = el( 'div', {
		class: 'atfb-formula__chips',
		children: FORMULA_FUNCTIONS.map( ( name ) => {
			const entry = FORMULA_FUNCTION_HELP[ name ];

			return chip( `${ name }()`, `${ name }()`, 1, entry ? `${ entry.usage } — ${ entry.help }` : '' );
		} ),
	} );

	overlay.append(
		el( 'div', {
			class: 'atfb-modal atfb-formula',
			attrs: { role: 'dialog', 'aria-label': 'Formula editor' },
			children: [
				el( 'h2', { text: 'Formula' } ),
				formulaInput( input, options.fields, options.field.id ),
				result,
				row( 'Your questions', questions, 'Click one to reference its answer — or type { in the formula. Hover one to see what it counts as.' ),
				row( 'Functions', el( 'div', { children: [ functions, help ] } ), 'Join anything with + - * / and brackets: ( {f1} + {f2} ) * 1.21' ),
				el( 'div', {
					class: 'atfb-modal__actions',
					children: [
						button( 'Cancel', close ),
						button(
							'Save formula',
							() => {
								options.onSave( input.value.trim() );
								close();
							},
							'primary'
						),
					],
				} ),
			],
		} )
	);

	overlay.addEventListener( 'click', ( event ) => {
		if ( event.target === overlay ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', onKeydown );
	options.root.append( overlay );
	preview();
	input.focus();
	input.setSelectionRange( input.value.length, input.value.length );
}

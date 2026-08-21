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
import { insertAtCursor } from './merge-tags';
import { button, el, row } from './ui';
import type { Field, Values } from './types';

/** The engine's functions, in the order a palette should offer them. */
export const FORMULA_FUNCTIONS = [ 'sum', 'min', 'max', 'avg', 'round', 'ceil', 'floor', 'abs', 'sqrt', 'pow' ];

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

		references.push( { label: `${ name } (how many)`, insert: `{${ field.id }}` } );

		for ( const sub of ( field.fields ?? [] ) as Field[] ) {
			if ( ! NUMERIC_FRIENDLY.includes( sub.type ) ) {
				continue;
			}

			references.push( {
				label: `${ name } · ${ sub.label || sub.id }`,
				insert: `{${ field.id }.${ sub.id }}`,
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

	const chip = ( label: string, insert: string, caretBack = 0 ) =>
		el( 'button', {
			class: 'atfb-formula__chip',
			type: 'button',
			text: label,
			on: {
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
	const repeaters = repeaterReferences( options.fields );

	const questions = el( 'div', {
		class: 'atfb-formula__chips',
		children:
			targets.length || repeaters.length
				? [
						...targets.map( ( field ) => chip( field.label || field.id, `{${ field.id }}` ) ),
						...repeaters.map( ( reference ) => chip( reference.label, reference.insert ) ),
				  ]
				: [ el( 'p', { class: 'atfb-hint', text: 'No number-shaped questions yet — add a number, scale or priced choice field and it appears here.' } ) ],
	} );

	const functions = el( 'div', {
		class: 'atfb-formula__chips',
		children: FORMULA_FUNCTIONS.map( ( name ) => chip( `${ name }()`, `${ name }()`, 1 ) ),
	} );

	overlay.append(
		el( 'div', {
			class: 'atfb-modal atfb-formula',
			attrs: { role: 'dialog', 'aria-label': 'Formula editor' },
			children: [
				el( 'h2', { text: 'Formula' } ),
				input,
				result,
				row( 'Your questions', questions, 'Click one to reference its answer.' ),
				row( 'Functions', functions ),
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

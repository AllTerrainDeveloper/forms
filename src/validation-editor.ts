/**
 * The custom-rule builder.
 *
 * When no preset fits — a booking code, a staff id, "must mention a ticket
 * number" — the escape hatch used to be a bare box asking for a regular
 * expression, which is asking a person building a contact form to program.
 * This editor asks the questions they can answer instead: what does the
 * answer start with? What must it contain? Which characters are allowed? How
 * long can it be? The blocks compile into one pattern, both engines enforce
 * that pattern, and the person who described the rule never sees it.
 *
 * The playground is the other half of the idea. A validation rule is the one
 * setting whose author cannot tell whether it works by looking at it, so the
 * editor keeps a row of sample answers checked live against the rule as it is
 * edited — a wrong rule announces itself before it ever rejects a real
 * visitor. Experts get a raw-expression mode behind a tab, with the same
 * playground.
 *
 * The recipe — the blocks as filled in — is stored alongside the compiled
 * pattern (`field.validationRecipe`), so reopening the editor restores the
 * form instead of the compiled hieroglyphics.
 */

import { button, el, row } from './ui';
import type { Field } from './types';

/** The blocks, as filled in. Stored as JSON so the editor can be reopened. */
export interface ValidationRecipe {
	/** Which tab wrote the rule: friendly blocks, or a raw expression. */
	mode: 'blocks' | 'regex';
	/** What the answer must start with. */
	starts: string;
	/** What the answer must end with. */
	ends: string;
	/** Something the answer must contain. */
	contains: string;
	/** Something the answer must not contain. */
	notContains: string;
	/** Allowed character groups — keys of `CHAR_GROUPS`. Empty means any. */
	chars: string[];
	/** Minimum length, as typed. Empty means no floor. */
	minLen: string;
	/** Maximum length, as typed. Empty means no ceiling. */
	maxLen: string;
	/** The raw expression, for `regex` mode. */
	regex: string;
	/** The error shown when an answer breaks the rule. */
	message: string;
	/** The playground's sample answers, kept so reopening shows them again. */
	tests: string[];
}

/** The character groups the "only these characters" block offers. */
export const CHAR_GROUPS: Record< string, { label: string; chars: string } > = {
	letters: { label: 'Letters', chars: 'A-Za-zÀ-ÖØ-öø-ÿ' },
	numbers: { label: 'Numbers', chars: '0-9' },
	spaces: { label: 'Spaces', chars: ' ' },
	punctuation: { label: 'Punctuation ( . , ! ? \' " - )', chars: ".,!?'\"()\\-:;" },
	symbols: { label: 'Symbols ( @ # & _ / + )', chars: '@#&_/+*%=' },
};

/** A recipe with nothing in it. */
export function emptyRecipe(): ValidationRecipe {
	return {
		mode: 'blocks',
		starts: '',
		ends: '',
		contains: '',
		notContains: '',
		chars: [],
		minLen: '',
		maxLen: '',
		regex: '',
		message: '',
		tests: [],
	};
}

/**
 * A stored recipe, back as a recipe.
 *
 * Tolerant of anything: the blob travels through saves, imports and older
 * versions, and a recipe that fails to parse must degrade to a blank editor
 * rather than a crashed one.
 *
 * @param json The stored blob.
 * @return The recipe, blank where the blob is unusable.
 */
export function parseRecipe( json: string ): ValidationRecipe {
	const recipe = emptyRecipe();

	let raw: unknown;

	try {
		raw = JSON.parse( json );
	} catch {
		return recipe;
	}

	if ( ! raw || 'object' !== typeof raw ) {
		return recipe;
	}

	const source = raw as Record< string, unknown >;

	recipe.mode = 'regex' === source.mode ? 'regex' : 'blocks';

	for ( const key of [ 'starts', 'ends', 'contains', 'notContains', 'minLen', 'maxLen', 'regex', 'message' ] as const ) {
		if ( 'string' === typeof source[ key ] ) {
			recipe[ key ] = source[ key ] as string;
		}
	}

	if ( Array.isArray( source.chars ) ) {
		recipe.chars = source.chars.filter( ( item ): item is string => 'string' === typeof item && item in CHAR_GROUPS );
	}

	if ( Array.isArray( source.tests ) ) {
		recipe.tests = source.tests.filter( ( item ): item is string => 'string' === typeof item ).slice( 0, 10 );
	}

	return recipe;
}

/** User text, made safe to sit inside a regular expression. */
export function escapeRegex( text: string ): string {
	return text.replace( /[.*+?^${}()|[\]\\/]/g, '\\$&' );
}

/**
 * The blocks, compiled into one anchored pattern.
 *
 * Lookaheads rather than a single linear expression, because the blocks are
 * independent facts — "starts with AT-", "contains @", "at most 20
 * characters" — and lookaheads let each be asserted from the same anchor
 * without knowing about the others. Empty string when no block says anything,
 * which callers treat as "no rule".
 *
 * The output uses no flags-dependent syntax: both engines run it exactly as
 * the plain `pattern` setting is run.
 *
 * @param recipe The blocks as filled in.
 * @return The pattern, or an empty string for an empty recipe.
 */
export function compileRecipe( recipe: ValidationRecipe ): string {
	if ( 'regex' === recipe.mode ) {
		return recipe.regex.trim();
	}

	const parts: string[] = [];

	const min = recipe.minLen.trim();
	const max = recipe.maxLen.trim();

	if ( '' !== min || '' !== max ) {
		parts.push( `(?=.{${ '' === min ? '0' : parseInt( min, 10 ) || 0 },${ '' === max ? '' : parseInt( max, 10 ) || '' }}$)` );
	}

	if ( '' !== recipe.contains ) {
		parts.push( `(?=.*${ escapeRegex( recipe.contains ) })` );
	}

	if ( '' !== recipe.notContains ) {
		parts.push( `(?!.*${ escapeRegex( recipe.notContains ) })` );
	}

	if ( '' !== recipe.starts ) {
		parts.push( `(?=${ escapeRegex( recipe.starts ) })` );
	}

	if ( '' !== recipe.ends ) {
		parts.push( `(?=.*${ escapeRegex( recipe.ends ) }$)` );
	}

	const charClass = recipe.chars.map( ( key ) => CHAR_GROUPS[ key ]?.chars ?? '' ).join( '' );
	const body = charClass ? `[${ charClass }]*$` : '.*$';

	if ( ! parts.length && ! charClass ) {
		return '';
	}

	return `^${ parts.join( '' ) }${ body }`;
}

/**
 * The rule, in plain words.
 *
 * Shown under the picker in the inspector and above the playground in the
 * editor — the sentence is the contract, and the pattern merely implements
 * it.
 *
 * @param recipe The blocks as filled in.
 * @return A sentence, or an empty string for an empty recipe.
 */
export function describeRecipe( recipe: ValidationRecipe ): string {
	if ( 'regex' === recipe.mode ) {
		return recipe.regex.trim() ? `Matches the expression ${ recipe.regex.trim() }` : '';
	}

	const phrases: string[] = [];

	if ( recipe.starts ) {
		phrases.push( `starts with “${ recipe.starts }”` );
	}

	if ( recipe.ends ) {
		phrases.push( `ends with “${ recipe.ends }”` );
	}

	if ( recipe.contains ) {
		phrases.push( `contains “${ recipe.contains }”` );
	}

	if ( recipe.notContains ) {
		phrases.push( `never contains “${ recipe.notContains }”` );
	}

	if ( recipe.chars.length ) {
		const names = recipe.chars.map(
			( key ) => ( CHAR_GROUPS[ key ]?.label ?? key ).split( ' (' )[ 0 ].toLowerCase()
		);

		phrases.push( `uses only ${ names.join( ' and ' ) }` );
	}

	const min = recipe.minLen.trim();
	const max = recipe.maxLen.trim();

	if ( min && max ) {
		phrases.push( `is ${ min }–${ max } characters long` );
	} else if ( min ) {
		phrases.push( `is at least ${ min } characters long` );
	} else if ( max ) {
		phrases.push( `is at most ${ max } characters long` );
	}

	if ( ! phrases.length ) {
		return '';
	}

	// "starts with “AT-”, contains “@”, and is 4–20 characters long."
	const sentence =
		phrases.length > 1
			? `${ phrases.slice( 0, -1 ).join( ', ' ) }, and ${ phrases[ phrases.length - 1 ] }`
			: phrases[ 0 ];

	return `The answer ${ sentence }.`;
}

/**
 * Whether a sample passes the rule as it currently stands.
 *
 * Null when there is no rule yet or the expression does not compile — the
 * playground paints that as "no verdict" rather than as a pass or a failure,
 * because both would be lies.
 *
 * @param recipe The blocks as filled in.
 * @param value  The sample answer.
 * @return True, false, or null when there is nothing to check against.
 */
export function recipePasses( recipe: ValidationRecipe, value: string ): boolean | null {
	const pattern = compileRecipe( recipe );

	if ( '' === pattern ) {
		return null;
	}

	try {
		return new RegExp( pattern ).test( value );
	} catch {
		return null;
	}
}

/** What the editor needs from its host. */
export interface ValidationEditorOptions {
	/** Where the overlay mounts — the builder root, so it stays inside the window. */
	root: HTMLElement;
	/** The field whose rule this is — read for its stored recipe and label. */
	field: Field;
	/** Called with the compiled rule when Save is pressed. */
	onSave: ( result: { pattern: string; recipe: ValidationRecipe; message: string } ) => void;
	/** Called when the editor closes without saving. */
	onCancel?: () => void;
}

/**
 * Opens the custom-rule builder as a child window over the builder.
 *
 * @param options What to edit and where to say so.
 * @return void
 */
export function openValidationEditor( options: ValidationEditorOptions ): void {
	const recipe = parseRecipe( String( options.field.validationRecipe ?? '' ) );

	// A field that has a hand-written pattern but no recipe — authored before
	// this editor existed, or pasted by an expert — opens in the expert tab
	// with that pattern, so editing it does not silently start from zero.
	if ( 'blocks' === recipe.mode && '' === compileRecipe( recipe ) && options.field.pattern ) {
		recipe.mode = 'regex';
		recipe.regex = String( options.field.pattern );
	}

	if ( ! recipe.message ) {
		recipe.message = String( ( options.field.messages as Record< string, string > | undefined )?.invalid ?? '' );
	}

	const overlay = el( 'div', { class: 'atfb-overlay' } );
	let saved = false;

	const close = () => {
		overlay.remove();
		document.removeEventListener( 'keydown', onKeydown );

		if ( ! saved ) {
			options.onCancel?.();
		}
	};

	const onKeydown = ( event: KeyboardEvent ) => {
		if ( 'Escape' === event.key ) {
			close();
		}
	};

	/* ------------------------------------------------------------- Blocks */

	const blockInput = ( key: 'starts' | 'ends' | 'contains' | 'notContains', placeholder: string ) => {
		const input = el( 'input', {
			class: 'atfb-input',
			value: recipe[ key ],
			placeholder,
			attrs: { type: 'text' },
		} ) as HTMLInputElement;

		input.addEventListener( 'input', () => {
			recipe[ key ] = input.value;
			refresh();
		} );

		return input;
	};

	const lengthInput = ( key: 'minLen' | 'maxLen', label: string ) => {
		const input = el( 'input', {
			class: 'atfb-input atfb-valwin__len',
			value: recipe[ key ],
			attrs: { type: 'number', min: '0', 'aria-label': label },
		} ) as HTMLInputElement;

		input.addEventListener( 'input', () => {
			recipe[ key ] = input.value;
			refresh();
		} );

		return input;
	};

	const charBoxes = el( 'div', {
		class: 'atfb-valwin__chars',
		children: Object.entries( CHAR_GROUPS ).map( ( [ key, group ] ) => {
			const box = el( 'input', {
				attrs: { type: 'checkbox', checked: recipe.chars.includes( key ) },
			} ) as HTMLInputElement;

			box.addEventListener( 'change', () => {
				recipe.chars = box.checked
					? [ ...recipe.chars, key ]
					: recipe.chars.filter( ( item ) => item !== key );
				refresh();
			} );

			return el( 'label', { class: 'atfb-valwin__char', children: [ box, el( 'span', { text: group.label } ) ] } );
		} ),
	} );

	const blocksPane = el( 'div', {
		class: 'atfb-valwin__pane',
		children: [
			row( 'Starts with', blockInput( 'starts', 'e.g. AT-' ) ),
			row( 'Ends with', blockInput( 'ends', 'e.g. -2026' ) ),
			row( 'Must contain', blockInput( 'contains', 'e.g. @' ) ),
			row( 'Must not contain', blockInput( 'notContains', 'e.g. spaces? type one' ) ),
			row( 'Only these characters', charBoxes, 'Leave every box unticked to allow anything.' ),
			row(
				'Length',
				el( 'div', {
					class: 'atfb-valwin__lengths',
					children: [
						el( 'span', { text: 'between' } ),
						lengthInput( 'minLen', 'Minimum length' ),
						el( 'span', { text: 'and' } ),
						lengthInput( 'maxLen', 'Maximum length' ),
						el( 'span', { text: 'characters' } ),
					],
				} ),
				'Leave a box empty for no limit.'
			),
		],
	} );

	/* -------------------------------------------------------------- Regex */

	const regexInput = el( 'textarea', {
		class: 'atfb-input atfb-valwin__regex',
		attrs: { rows: '2', 'aria-label': 'Regular expression', placeholder: '^AT-[0-9]{4}$' },
	} ) as HTMLTextAreaElement;

	regexInput.value = recipe.regex;
	regexInput.addEventListener( 'input', () => {
		recipe.regex = regexInput.value;
		refresh();
	} );

	const regexPane = el( 'div', {
		class: 'atfb-valwin__pane',
		children: [
			row(
				'Expression',
				regexInput,
				'A regular expression, without slashes. Checked against the whole answer only if you anchor it with ^ and $.'
			),
		],
	} );

	/* --------------------------------------------------------------- Tabs */

	const tabs = el( 'div', { class: 'atfb-valwin__tabs', attrs: { role: 'tablist' } } );

	const paintTabs = () => {
		tabs.replaceChildren(
			...( [
				[ 'blocks', 'Easy blocks' ],
				[ 'regex', 'Expression (advanced)' ],
			] as const ).map( ( [ mode, label ] ) => {
				const active = recipe.mode === mode;

				return el( 'button', {
					class: `atfb-valwin__tab${ active ? ' is-active' : '' }`,
					type: 'button',
					text: label,
					attrs: { role: 'tab', 'aria-selected': active ? 'true' : 'false' },
					on: {
						click: () => {
							recipe.mode = mode;
							blocksPane.hidden = 'blocks' !== mode;
							regexPane.hidden = 'regex' !== mode;
							paintTabs();
							refresh();
						},
					},
				} );
			} )
		);
	};

	paintTabs();
	blocksPane.hidden = 'blocks' !== recipe.mode;
	regexPane.hidden = 'regex' !== recipe.mode;

	/* ------------------------------------------------------------ Message */

	const messageInput = el( 'input', {
		class: 'atfb-input',
		value: recipe.message,
		placeholder: 'That is not in the expected format.',
		attrs: { type: 'text' },
	} ) as HTMLInputElement;

	messageInput.addEventListener( 'input', () => {
		recipe.message = messageInput.value;
	} );

	/* --------------------------------------------------------- Playground */

	const summary = el( 'p', { class: 'atfb-valwin__summary', attrs: { 'aria-live': 'polite' } } );
	const samples = el( 'div', { class: 'atfb-valwin__samples' } );

	const sampleRow = ( initial: string ) => {
		const verdict = el( 'span', { class: 'atfb-valwin__verdict', attrs: { 'aria-live': 'polite' } } );
		const input = el( 'input', {
			class: 'atfb-input',
			value: initial,
			placeholder: 'Type a sample answer…',
			attrs: { type: 'text' },
		} ) as HTMLInputElement;

		// Lazily, not by value: the first rows are built before `refresh`
		// itself is — a direct reference here is a use before initialisation.
		input.addEventListener( 'input', () => refresh() );

		samples.append( el( 'div', { class: 'atfb-valwin__sample', children: [ input, verdict ] } ) );
	};

	for ( const test of recipe.tests.length ? recipe.tests : [ '', '', '' ] ) {
		sampleRow( test );
	}

	const readSamples = () =>
		Array.from( samples.querySelectorAll< HTMLInputElement >( 'input' ) ).map( ( input ) => input.value );

	/** Re-judges every sample and rewrites the plain-words summary. */
	const refresh = () => {
		const description = describeRecipe( recipe );

		summary.textContent = description || 'Nothing yet — fill in a block above and the rule appears here in plain words.';

		for ( const sample of Array.from( samples.querySelectorAll< HTMLElement >( '.atfb-valwin__sample' ) ) ) {
			const input = sample.querySelector< HTMLInputElement >( 'input' );
			const verdict = sample.querySelector< HTMLElement >( '.atfb-valwin__verdict' );

			if ( ! input || ! verdict ) {
				continue;
			}

			const result = '' === input.value ? null : recipePasses( recipe, input.value );

			verdict.textContent = null === result ? '·' : result ? '✓ passes' : '✗ fails';
			verdict.classList.toggle( 'is-pass', true === result );
			verdict.classList.toggle( 'is-fail', false === result );
		}
	};

	/* -------------------------------------------------------------- Shell */

	overlay.append(
		el( 'div', {
			class: 'atfb-modal atfb-valwin',
			attrs: { role: 'dialog', 'aria-label': 'Custom validation rule' },
			children: [
				el( 'h2', { text: 'Custom rule' } ),
				el( 'p', {
					class: 'atfb-hint',
					text: `Describe what a good answer to “${
						options.field.label || 'this question'
					}” looks like — no code needed.`,
				} ),
				tabs,
				blocksPane,
				regexPane,
				el( 'div', {
					class: 'atfb-valwin__try',
					children: [
						el( 'h3', { text: 'Try it out' } ),
						summary,
						samples,
						button(
							'Add another sample',
							() => {
								sampleRow( '' );
								refresh();
							},
							'ghost',
							'plus-alt2'
						),
					],
				} ),
				row(
					'When it fails, say',
					messageInput,
					'Shown to the visitor when their answer breaks the rule. Leave empty for the default wording.'
				),
				el( 'div', {
					class: 'atfb-modal__actions',
					children: [
						button( 'Cancel', close ),
						button(
							'Save rule',
							() => {
								const pattern = compileRecipe( recipe );
								recipe.tests = readSamples()
									.filter( ( value ) => '' !== value )
									.slice( 0, 10 );

								saved = true;
								options.onSave( { pattern, recipe, message: recipe.message.trim() } );
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
	refresh();

	overlay.querySelector< HTMLInputElement >( '.atfb-valwin__pane:not([hidden]) input, .atfb-valwin__pane:not([hidden]) textarea' )?.focus();
}

/**
 * Calculations, browser side.
 *
 * The twin of `includes/calc.php`. This one shows the visitor a running total as
 * they type; the server recomputes on submit and stores its own answer, so a
 * tampered total is discarded rather than charged.
 *
 * **There is no `eval()` and no `new Function()` here.** A formula is authored
 * by whoever can edit forms and evaluated in every visitor's browser, so either
 * of those would be a script-injection vector with a convenience costume. The
 * formula is tokenised, converted to postfix by the shunting-yard algorithm, and
 * evaluated over a stack. The only things that can come out are numbers.
 */

import type { Choice, Field, FieldValue, Values } from '../types';

type Token =
	| { type: 'number'; value: number }
	| { type: 'operator' | 'unary'; value: string }
	| { type: 'function'; value: string; arity?: number }
	| { type: '(' | ')' | 'comma'; value: string };

/** Function name => argument count, or -1 for variadic. Mirrors `atf_calc_functions()`. */
const FUNCTIONS: Record< string, number > = {
	min: -1,
	max: -1,
	sum: -1,
	avg: -1,
	round: -1,
	ceil: 1,
	floor: 1,
	abs: 1,
	sqrt: 1,
	pow: 2,
};

const PRECEDENCE: Record< string, { precedence: number; right: boolean } > = {
	'+': { precedence: 1, right: false },
	'-': { precedence: 1, right: false },
	'*': { precedence: 2, right: false },
	'/': { precedence: 2, right: false },
	'%': { precedence: 2, right: false },
	'^': { precedence: 4, right: true },
};

/** Evaluates a formula against a set of field values. */
export function calculate( formula: string, values: Values, fields: Field[] = [] ): number | null {
	if ( ! formula || ! formula.trim() ) {
		return null;
	}

	// A runaway formula is a denial of service, and there is no legitimate one
	// this long.
	if ( formula.length > 2000 ) {
		return null;
	}

	const resolved = resolveRefs( formula, values, fields );
	const tokens = tokenize( resolved );

	if ( ! tokens ) {
		return null;
	}

	const postfix = toPostfix( tokens );

	if ( ! postfix ) {
		return null;
	}

	const result = evalPostfix( postfix );

	return result === null || ! Number.isFinite( result ) ? null : result;
}

/**
 * Replaces `{field}` and `{repeater.sub}` references with numeric literals.
 *
 * A field that was not answered, or whose answer is not a number, contributes
 * zero. That is the only reading that lets a running total work: a quantity box
 * nobody has typed in yet must not make the whole total collapse to nothing.
 *
 * A repeater is a *list* of answers, and the grammar reads it three ways:
 *
 * - `{attendees}` is the number of rows — "charge 15 per attendee" is
 *   `{attendees} * 15`.
 * - `sum( {attendees.age} )` spreads into one argument per row, so `sum`,
 *   `avg`, `min` and `max` see every row's answer individually. The spread
 *   happens only when the reference is the sole argument of one of those
 *   four — spreading into `pow( {a.b}, 2 )` would silently push the `2` out
 *   of its parameter slot.
 * - `{attendees.age}` anywhere else is the total across rows, which is what
 *   an aggregate reference standing alone in arithmetic can only mean.
 */
function resolveRefs( formula: string, values: Values, fields: Field[] ): string {
	return formula.replace(
		/\{([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\}/g,
		( match, fieldId: string, subId: string | undefined, offset: number ) =>
			refLiteral( formula, offset, match.length, fieldId, subId ?? '', values, fields )
	);
}

/** The literal one reference resolves to. Mirrors `atf_calc_ref_literal()`. */
function refLiteral(
	formula: string,
	offset: number,
	length: number,
	fieldId: string,
	subId: string,
	values: Values,
	fields: Field[]
): string {
	const field = findField( fields, fieldId );
	const value = Object.prototype.hasOwnProperty.call( values, fieldId ) ? values[ fieldId ] : null;

	if ( subId === '' ) {
		// A repeater referenced whole is its row count; anything else is its
		// numeric value as before.
		const number = field?.type === 'repeater' ? repeaterRows( value ).length : numericValue( value, field );

		return numberLiteral( number );
	}

	const subs = ( field?.fields ?? [] ) as Field[];
	const sub = subs.find( ( candidate ) => candidate.id === subId ) ?? null;

	const numbers = repeaterRows( value ).map( ( row ) =>
		numericValue( ( row[ subId ] ?? '' ) as FieldValue, sub )
	);

	if ( ! numbers.length ) {
		return '0';
	}

	const literals = numbers.map( numberLiteral );

	if ( refSpreads( formula, offset, length ) ) {
		return literals.join( ', ' );
	}

	return literals.length === 1 ? literals[ 0 ] : `( ${ literals.join( ' + ' ) } )`;
}

/**
 * A field found the way the PHP twin finds one: top level first, then each
 * field's repeater sub-fields, in schema order.
 */
function findField( fields: Field[], fieldId: string ): Field | null {
	for ( const field of fields ) {
		if ( field.id === fieldId ) {
			return field;
		}

		for ( const sub of ( field.fields ?? [] ) as Field[] ) {
			if ( sub.id === fieldId ) {
				return sub;
			}
		}
	}

	return null;
}

/**
 * Whether a reference at this position spreads into one argument per row.
 *
 * True only when it is the sole argument of a variadic aggregate —
 * `sum( {a.b} )` — where "one argument per row" is unambiguously what was
 * meant. Everywhere else the reference collapses to its sum, because spreading
 * into a fixed-arity call like `pow( {a.b}, 2 )` would silently shift every
 * later argument out of its slot. Mirrors `atf_calc_ref_spreads()`.
 */
function refSpreads( formula: string, offset: number, length: number ): boolean {
	const after = formula.slice( offset + length ).replace( /^\s+/, '' );

	if ( after === '' || after[ 0 ] !== ')' ) {
		return false;
	}

	const before = formula.slice( 0, offset ).replace( /\s+$/, '' );
	const match = /([a-zA-Z_][a-zA-Z0-9_]*)\s*\($/.exec( before );

	return !! match && [ 'sum', 'avg', 'min', 'max' ].includes( match[ 1 ].toLowerCase() );
}

/**
 * A repeater value's rows that were actually filled in.
 *
 * A row where every answer is empty is a row the visitor added and abandoned;
 * counting it would make `{attendees}` disagree with what the entry stores,
 * because the server's sanitiser drops exactly the same rows. Mirrors
 * `atf_calc_repeater_rows()`.
 */
export function repeaterRows( value: FieldValue ): Array< Record< string, unknown > > {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	const rows: Array< Record< string, unknown > > = [];

	for ( const row of value as unknown[] ) {
		if ( ! row || typeof row !== 'object' || Array.isArray( row ) ) {
			continue;
		}

		const filled = Object.values( row ).some(
			( item ) =>
				item !== '' &&
				item !== null &&
				item !== undefined &&
				item !== false &&
				! ( Array.isArray( item ) && ! item.length )
		);

		if ( filled ) {
			rows.push( row as Record< string, unknown > );
		}
	}

	return rows;
}

/**
 * A number as a formula literal the tokenizer can read back.
 *
 * String() can produce scientific notation ("1e-7"), which the tokenizer reads
 * as an unknown symbol and the whole formula dies. This prints plain decimals
 * at every magnitude, as the PHP twin does.
 */
function numberLiteral( number: number ): string {
	const literal = number
		.toFixed( 10 )
		.replace( /0+$/, '' )
		.replace( /\.$/, '' );

	return literal === '' || literal === '-' ? '0' : literal;
}

/**
 * The number a field's value contributes.
 *
 * A choice field contributes its selected choice's `price`, which is what makes
 * "Ticket type" able to participate in an order total without a hidden field
 * shadowing it. A multi-choice field sums them, which is what "extras" means.
 */
export function numericValue( value: FieldValue, field: Field | null ): number {
	if ( typeof value === 'boolean' ) {
		return value ? 1 : 0;
	}

	if ( Array.isArray( value ) ) {
		return value.reduce< number >( ( total, item ) => total + numericValue( item as FieldValue, field ), 0 );
	}

	if ( value === null || value === undefined || value === '' ) {
		return 0;
	}

	const choices = ( field?.choices ?? [] ) as Choice[];

	for ( const choice of choices ) {
		if ( String( choice.value ) === String( value ) ) {
			if ( typeof choice.price === 'number' ) {
				return choice.price;
			}

			if ( typeof choice.points === 'number' ) {
				return choice.points;
			}

			break;
		}
	}

	const parsed = Number( value );

	return Number.isFinite( parsed ) ? parsed : 0;
}

/**
 * Splits a resolved formula into tokens.
 *
 * Anything the grammar does not recognise aborts the whole evaluation. Skipping
 * unknown characters instead would turn a typo into a silently different sum,
 * and a total that is quietly wrong is worse than one that is visibly missing.
 */
function tokenize( formula: string ): Token[] | null {
	const tokens: Token[] = [];
	let i = 0;

	while ( i < formula.length ) {
		const char = formula[ i ];

		if ( /\s/.test( char ) ) {
			i++;
			continue;
		}

		if ( /[0-9.]/.test( char ) ) {
			let number = '';

			while ( i < formula.length && /[0-9.]/.test( formula[ i ] ) ) {
				number += formula[ i ];
				i++;
			}

			const parsed = Number( number );

			if ( ! Number.isFinite( parsed ) ) {
				return null;
			}

			tokens.push( { type: 'number', value: parsed } );
			continue;
		}

		if ( /[a-zA-Z_]/.test( char ) ) {
			let name = '';

			while ( i < formula.length && /[a-zA-Z0-9_]/.test( formula[ i ] ) ) {
				name += formula[ i ];
				i++;
			}

			name = name.toLowerCase();

			if ( ! Object.prototype.hasOwnProperty.call( FUNCTIONS, name ) ) {
				return null;
			}

			tokens.push( { type: 'function', value: name } );
			continue;
		}

		if ( char === '(' || char === ')' ) {
			tokens.push( { type: char, value: char } );
			i++;
			continue;
		}

		if ( char === ',' ) {
			tokens.push( { type: 'comma', value: ',' } );
			i++;
			continue;
		}

		if ( '+-*/%^'.includes( char ) ) {
			// A `-` in operand position is a sign, not a subtraction: `-5` and
			// `3 * -2` both have to work.
			const previous = tokens[ tokens.length - 1 ];
			const isUnary =
				char === '-' &&
				( ! previous || previous.type === 'operator' || previous.type === 'unary' || previous.type === '(' || previous.type === 'comma' );

			tokens.push( { type: isUnary ? 'unary' : 'operator', value: char } );
			i++;
			continue;
		}

		return null;
	}

	return tokens;
}

/**
 * Precedence and associativity of a stacked operator, unary included.
 *
 * Unary minus sits at 3: **below** exponentiation and **above** multiplication.
 * That is the convention every calculator and language follows, and it is the
 * difference between `-2 ^ 2` meaning `-(2 ^ 2)` — which is -4 — and meaning
 * `(-2) ^ 2`, which is 4. Popping unary operators unconditionally, as the
 * obvious implementation does, silently gives the second answer.
 *
 * Right-associative so `- - 5` nests rather than trying to pop itself.
 */
function precedenceOf( token: Token ): { precedence: number; right: boolean } {
	if ( token.type === 'unary' ) {
		return { precedence: 3, right: true };
	}

	return PRECEDENCE[ token.value as string ] ?? { precedence: 0, right: false };
}

/** Converts infix tokens to postfix, by the shunting-yard algorithm. */
function toPostfix( tokens: Token[] ): Token[] | null {
	const output: Token[] = [];
	const operators: Token[] = [];
	const arity: number[] = [];

	for ( const token of tokens ) {
		switch ( token.type ) {
			case 'number':
				output.push( token );
				break;

			case 'function':
				operators.push( token );
				arity.push( 1 );
				break;

			case 'comma':
				while ( operators.length && operators[ operators.length - 1 ].type !== '(' ) {
					output.push( operators.pop()! );
				}

				if ( ! operators.length ) {
					return null;
				}

				if ( arity.length ) {
					arity[ arity.length - 1 ]++;
				}
				break;

			case 'unary':
				operators.push( token );
				break;

			case 'operator': {
				const info = PRECEDENCE[ token.value ];

				while ( operators.length ) {
					const top = operators[ operators.length - 1 ];

					if ( top.type !== 'operator' && top.type !== 'unary' ) {
						break;
					}

					const topInfo = precedenceOf( top );

					if (
						topInfo.precedence > info.precedence ||
						( topInfo.precedence === info.precedence && ! info.right )
					) {
						output.push( operators.pop()! );
						continue;
					}

					break;
				}

				operators.push( token );
				break;
			}

			case '(':
				operators.push( token );
				break;

			case ')': {
				while ( operators.length && operators[ operators.length - 1 ].type !== '(' ) {
					output.push( operators.pop()! );
				}

				if ( ! operators.length ) {
					return null;
				}

				operators.pop();

				if ( operators.length && operators[ operators.length - 1 ].type === 'function' ) {
					const fn = operators.pop() as Extract< Token, { type: 'function' } >;
					fn.arity = arity.length ? arity.pop() : 1;
					output.push( fn );
				}
				break;
			}
		}
	}

	while ( operators.length ) {
		const top = operators.pop()!;

		if ( top.type === '(' ) {
			return null;
		}

		output.push( top );
	}

	return output;
}

/** Evaluates postfix tokens over a stack. */
function evalPostfix( postfix: Token[] ): number | null {
	const stack: number[] = [];

	for ( const token of postfix ) {
		switch ( token.type ) {
			case 'number':
				stack.push( token.value );
				break;

			case 'unary': {
				if ( ! stack.length ) {
					return null;
				}

				stack.push( -stack.pop()! );
				break;
			}

			case 'operator': {
				if ( stack.length < 2 ) {
					return null;
				}

				const right = stack.pop()!;
				const left = stack.pop()!;

				switch ( token.value ) {
					case '+':
						stack.push( left + right );
						break;

					case '-':
						stack.push( left - right );
						break;

					case '*':
						stack.push( left * right );
						break;

					// Division by zero gives 0 rather than aborting. A visitor
					// half-way through a form routinely has a zero in a
					// denominator, and blanking the running total each time
					// reads as the calculation being broken.
					case '/':
						stack.push( right === 0 ? 0 : left / right );
						break;

					case '%':
						stack.push( right === 0 ? 0 : left % right );
						break;

					case '^':
						stack.push( Math.pow( left, right ) );
						break;

					default:
						return null;
				}
				break;
			}

			case 'function': {
				const count = token.arity ?? 1;

				if ( stack.length < count ) {
					return null;
				}

				const args: number[] = [];

				for ( let i = 0; i < count; i++ ) {
					args.unshift( stack.pop()! );
				}

				const result = applyFunction( token.value, args );

				if ( result === null ) {
					return null;
				}

				stack.push( result );
				break;
			}

			default:
				return null;
		}
	}

	return stack.length === 1 ? stack[ 0 ] : null;
}

/** Applies one whitelisted function. */
function applyFunction( name: string, args: number[] ): number | null {
	if ( ! args.length ) {
		return null;
	}

	switch ( name ) {
		case 'min':
			return Math.min( ...args );

		case 'max':
			return Math.max( ...args );

		case 'sum':
			return args.reduce( ( total, value ) => total + value, 0 );

		case 'avg':
			return args.reduce( ( total, value ) => total + value, 0 ) / args.length;

		case 'round': {
			// A precision beyond float resolution is meaningless and an
			// enormous one is a way to burn CPU.
			const precision = Math.max( -10, Math.min( 10, Math.trunc( args[ 1 ] ?? 0 ) ) );
			const factor = Math.pow( 10, precision );

			return Math.round( args[ 0 ] * factor ) / factor;
		}

		case 'ceil':
			return Math.ceil( args[ 0 ] );

		case 'floor':
			return Math.floor( args[ 0 ] );

		case 'abs':
			return Math.abs( args[ 0 ] );

		case 'sqrt':
			return args[ 0 ] < 0 ? 0 : Math.sqrt( args[ 0 ] );

		case 'pow':
			return Math.pow( args[ 0 ], args[ 1 ] ?? 2 );

		default:
			return null;
	}
}

/**
 * Computes every calculated field.
 *
 * Runs in schema order and feeds each result back into the value set, so one
 * total can be built from another — a subtotal, then VAT on it, then a grand
 * total.
 */
export function applyCalculations( fields: Field[], values: Values ): Values {
	const next: Values = { ...values };

	for ( const field of fields ) {
		const formula = field.formula as string | undefined;

		if ( ! formula ) {
			continue;
		}

		const result = calculate( formula, next, fields );

		if ( result === null ) {
			// A formula that fails must not leave the previous value in place:
			// for a field that carries a formula the engine's answer is the
			// only answer, and "could not compute" is an empty one. The PHP
			// twin does the same with the client-posted value on submit.
			next[ field.id ] = '';
			continue;
		}

		const decimals = typeof field.decimals === 'number' ? field.decimals : 2;
		const factor = Math.pow( 10, decimals );

		next[ field.id ] = Math.round( result * factor ) / factor;
	}

	return next;
}

/**
 * Conditional logic, browser side.
 *
 * **This is the twin of `includes/logic.php` and the two must agree.** The
 * browser hides and shows fields as the visitor types; the server decides which
 * fields were actually required. If they disagree, the visitor is shown a form
 * they cannot submit, with an error about a field they cannot see — the worst
 * bug this plugin can have.
 *
 * `tests/vitest/logic.test.ts` and `tests/phpunit/tests/logic.php` run the same
 * table of cases against both implementations, so a change here that is not
 * mirrored there fails the build.
 *
 * The server remains the authority. Nothing decided here is trusted: the server
 * recomputes visibility from the submitted values and validates only against
 * that.
 */

import type { Field, FieldValue, Logic, LogicOperator, LogicRule, Values } from '../types';

/**
 * Whether a logic block's conditions hold.
 *
 * Returns the *condition* result, not the visibility — `show` versus `hide` is
 * applied by the caller.
 */
export function conditionsMet( logic: Logic | undefined, values: Values ): boolean {
	if ( ! logic?.enabled || ! logic.rules?.length ) {
		return true;
	}

	const match = logic.match === 'any' ? 'any' : 'all';

	for ( const rule of logic.rules ) {
		const value = Object.prototype.hasOwnProperty.call( values, rule.field ) ? values[ rule.field ] : null;
		const passed = rulePasses( rule, value );

		if ( match === 'any' && passed ) {
			return true;
		}

		if ( match === 'all' && ! passed ) {
			return false;
		}
	}

	// Falling out of the loop means every rule agreed with the match mode: all
	// passed under `all`, or none passed under `any`.
	return match === 'all';
}

/** Whether a logic block says its subject should be shown. */
export function logicPasses( logic: Logic | undefined, values: Values ): boolean {
	if ( ! logic?.enabled ) {
		return true;
	}

	const met = conditionsMet( logic, values );

	return logic.action === 'hide' ? ! met : met;
}

/**
 * Evaluates one rule against one value.
 *
 * A multi-value field satisfies a rule when *any* of its values does — except
 * for the negative operators, where "none of them is" is the only reading that
 * does not make picking two options satisfy "is not" for both at once.
 */
export function rulePasses( rule: LogicRule, value: FieldValue ): boolean {
	const operator = rule.operator ?? 'is';
	const expected = rule.value ?? '';

	if ( Array.isArray( value ) ) {
		const filled = value.filter( ( item ) => item !== '' && item !== null && item !== undefined );

		if ( operator === 'empty' ) {
			return filled.length === 0;
		}

		if ( operator === 'not_empty' ) {
			return filled.length > 0;
		}

		if ( operator === 'is_not' || operator === 'not_contains' ) {
			return value.every( ( item ) => compare( operator, item as FieldValue, expected ) );
		}

		return value.some( ( item ) => compare( operator, item as FieldValue, expected ) );
	}

	return compare( operator, value, expected );
}

/**
 * The comparison itself.
 *
 * Comparison is by string except where the operator is inherently numeric, and
 * that split is the source of most conditional-logic bugs in every plugin that
 * has them. `"10" > "9"` is false as strings and true as numbers; a rule saying
 * "quantity is greater than 9" means the number. So the four ordering operators
 * coerce, and everything else compares as text — which is right for a postcode
 * or a phone number, where leading zeroes matter.
 */
export function compare( operator: LogicOperator, actual: FieldValue, expected: string ): boolean {
	// A boolean field's value reads as "1" or "" to a rule, because that is what
	// a checkbox posts and what somebody writing the rule will have typed.
	if ( typeof actual === 'boolean' ) {
		actual = actual ? '1' : '';
	}

	const left = actual === null || actual === undefined ? '' : String( actual );

	switch ( operator ) {
		case 'is':
			return left === expected;

		case 'is_not':
			return left !== expected;

		case 'contains':
			return expected !== '' && left.toLowerCase().includes( expected.toLowerCase() );

		case 'not_contains':
			return expected === '' || ! left.toLowerCase().includes( expected.toLowerCase() );

		case 'starts_with':
			return expected !== '' && left.toLowerCase().startsWith( expected.toLowerCase() );

		case 'ends_with':
			return expected !== '' && left.toLowerCase().endsWith( expected.toLowerCase() );

		case 'empty':
			return left.trim() === '';

		case 'not_empty':
			return left.trim() !== '';

		case 'greater':
		case 'less':
		case 'greater_equal':
		case 'less_equal': {
			// A non-numeric operand makes an ordering question meaningless, and
			// the honest answer is false rather than whatever JavaScript thinks
			// `"apple" > 5` means.
			if ( ! isNumeric( left ) || ! isNumeric( expected ) ) {
				return false;
			}

			const a = parseFloat( left );
			const b = parseFloat( expected );

			if ( operator === 'greater' ) {
				return a > b;
			}

			if ( operator === 'less' ) {
				return a < b;
			}

			return operator === 'greater_equal' ? a >= b : a <= b;
		}

		default:
			return false;
	}
}

/**
 * Which fields are visible for a set of values.
 *
 * Resolved iteratively because logic can chain: field B is shown when A is
 * "yes", and field C is shown when B is "blue". If B is hidden, its value must
 * not count towards C's rule, or C appears for somebody who never answered B.
 * Each pass hides everything whose rules fail against the currently-visible
 * values, and repeats until a pass changes nothing.
 *
 * The iteration is capped. A form can be built with a genuine cycle — A shows B,
 * B hides A — which has no stable answer, and the cap turns an infinite loop
 * into a merely-wrong-looking form.
 */
export function visibleFields( fields: Field[], values: Values ): Record< string, boolean > {
	const visible: Record< string, boolean > = {};

	for ( const field of fields ) {
		visible[ field.id ] = true;
	}

	const MAX_PASSES = 10;

	for ( let pass = 0; pass < MAX_PASSES; pass++ ) {
		let changed = false;

		// Only fields currently visible contribute a value. A hidden field is
		// treated as unanswered even if the DOM still holds something for it,
		// which is also what stops a stale value — typed, then hidden by a
		// change of mind — from driving the rest of the form.
		const effective: Values = {};

		for ( const field of fields ) {
			effective[ field.id ] =
				visible[ field.id ] && Object.prototype.hasOwnProperty.call( values, field.id )
					? values[ field.id ]
					: null;
		}

		for ( const field of fields ) {
			const expected = logicPasses( field.logic, effective );

			if ( expected !== visible[ field.id ] ) {
				visible[ field.id ] = expected;
				changed = true;
			}
		}

		if ( ! changed ) {
			break;
		}
	}

	return visible;
}

/** Whether a string is a number, by the same rule PHP's `is_numeric()` uses. */
function isNumeric( value: string ): boolean {
	return value.trim() !== '' && ! Number.isNaN( Number( value ) );
}

/**
 * Whether a value counts as unanswered.
 *
 * `0` is an answer. A rating of zero, a quantity of zero and a scale answer of
 * zero are all things a visitor deliberately chose, and a falsy check calls
 * every one of them missing — which is how a required NPS field rejects the
 * people who gave it the lowest score.
 */
export function isEmptyValue( value: FieldValue ): boolean {
	if ( typeof value === 'boolean' ) {
		return ! value;
	}

	if ( Array.isArray( value ) ) {
		return value.every( ( item ) => isEmptyValue( item as FieldValue ) );
	}

	if ( value !== null && typeof value === 'object' ) {
		return Object.values( value ).every( ( item ) => isEmptyValue( item as FieldValue ) );
	}

	return value === '' || value === null || value === undefined;
}

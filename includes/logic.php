<?php
/**
 * Conditional logic.
 *
 * One evaluator, used for everything that can be conditional: whether a field is
 * shown, whether a notification is sent, whether a confirmation is chosen,
 * whether a post-submit action runs. They all carry the same logic shape, so
 * they all come through `atf_logic_passes()`.
 *
 * **This file has a twin in `src/shared/logic.ts` and the two must agree.** The
 * browser hides and shows fields as the visitor types; the server decides which
 * fields were actually required. If they disagree, the visitor is shown a form
 * they cannot submit, with an error about a field they cannot see -- the worst
 * bug this plugin can have. `tests/phpunit/tests/logic.php` and
 * `tests/vitest/logic.test.ts` run the same table of cases against both.
 *
 * The server is the authority. The browser's copy is a convenience that must
 * never be trusted: `atf_visible_fields()` recomputes visibility from the
 * submitted values, and validation only ever runs against that.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a logic block's conditions hold for a set of values.
 *
 * Returns the *condition* result, not the visibility -- `show` versus `hide` is
 * applied by the caller, because a notification's "send when" reads naturally as
 * a condition where a field's reads as an action.
 *
 * @since 0.1.0
 *
 * @param array $logic  A normalised logic block.
 * @param array $values Field id => submitted value.
 * @param array $schema The form schema, for choice lookups.
 * @return bool True when the conditions are met. A disabled block is always true.
 */
function atf_logic_conditions_met( $logic, $values, $schema = array() ) {
	if ( empty( $logic['enabled'] ) || empty( $logic['rules'] ) ) {
		return true;
	}

	$match = isset( $logic['match'] ) && 'any' === $logic['match'] ? 'any' : 'all';

	foreach ( $logic['rules'] as $rule ) {
		$field_id = isset( $rule['field'] ) ? $rule['field'] : '';
		$value    = array_key_exists( $field_id, $values ) ? $values[ $field_id ] : null;
		$passed   = atf_logic_rule_passes( $rule, $value, $schema );

		if ( 'any' === $match && $passed ) {
			return true;
		}

		if ( 'all' === $match && ! $passed ) {
			return false;
		}
	}

	// Falling out of the loop means every rule agreed with the match mode: all
	// passed under `all`, or none passed under `any`.
	return 'all' === $match;
}

/**
 * Whether a logic block says its subject should be shown.
 *
 * @since 0.1.0
 *
 * @param array $logic  A normalised logic block.
 * @param array $values Field id => submitted value.
 * @param array $schema The form schema.
 * @return bool
 */
function atf_logic_passes( $logic, $values, $schema = array() ) {
	if ( empty( $logic['enabled'] ) ) {
		return true;
	}

	$met = atf_logic_conditions_met( $logic, $values, $schema );

	return ( isset( $logic['action'] ) && 'hide' === $logic['action'] ) ? ! $met : $met;
}

/**
 * Evaluates one rule against one value.
 *
 * Comparison is by string except where the operator is inherently numeric, and
 * that split is the source of most conditional-logic bugs in every plugin that
 * has them. `"10" > "9"` is false as strings and true as numbers; a rule saying
 * "quantity is greater than 9" means the number. So the four ordering operators
 * coerce, and everything else compares as text -- which is right for a postcode
 * or a phone number, where leading zeroes matter.
 *
 * @since 0.1.0
 *
 * @param array $rule   A normalised rule.
 * @param mixed $value  The value of the field the rule names.
 * @param array $schema The form schema.
 * @return bool
 */
function atf_logic_rule_passes( $rule, $value, $schema = array() ) {
	$operator = isset( $rule['operator'] ) ? $rule['operator'] : 'is';
	$expected = isset( $rule['value'] ) ? (string) $rule['value'] : '';

	// A multi-value field satisfies a rule when *any* of its values does. That
	// is what "Interests is Cycling" means when Interests is a checkbox group,
	// and requiring the whole list to equal one value would make such a rule
	// permanently false.
	if ( is_array( $value ) ) {
		if ( 'empty' === $operator ) {
			return ! array_filter(
				$value,
				static function ( $item ) {
					return '' !== $item && null !== $item;
				}
			);
		}

		if ( 'not_empty' === $operator ) {
			return (bool) array_filter(
				$value,
				static function ( $item ) {
					return '' !== $item && null !== $item;
				}
			);
		}

		// `is_not` over a list means "none of them is", not "at least one is
		// not" -- otherwise picking two options would satisfy "is not" for both
		// of them at once.
		if ( 'is_not' === $operator || 'not_contains' === $operator ) {
			foreach ( $value as $item ) {
				if ( ! atf_logic_compare( $operator, $item, $expected ) ) {
					return false;
				}
			}

			return true;
		}

		foreach ( $value as $item ) {
			if ( atf_logic_compare( $operator, $item, $expected ) ) {
				return true;
			}
		}

		return false;
	}

	return atf_logic_compare( $operator, $value, $expected );
}

/**
 * The comparison itself.
 *
 * @since 0.1.0
 *
 * @param string $operator One of the operators `atf_normalize_operator()` allows.
 * @param mixed  $actual   The submitted value.
 * @param string $expected The rule's value.
 * @return bool
 */
function atf_logic_compare( $operator, $actual, $expected ) {
	// A boolean field's value reads as "1" or "" to a rule, because that is what
	// a checkbox posts and what somebody writing the rule will have typed.
	if ( is_bool( $actual ) ) {
		$actual = $actual ? '1' : '';
	}

	$actual = null === $actual ? '' : (string) $actual;

	switch ( $operator ) {
		case 'is':
			return $actual === $expected;

		case 'is_not':
			return $actual !== $expected;

		case 'contains':
			return '' !== $expected && false !== stripos( $actual, $expected );

		case 'not_contains':
			return '' === $expected || false === stripos( $actual, $expected );

		case 'starts_with':
			return '' !== $expected && 0 === stripos( $actual, $expected );

		case 'ends_with':
			return '' !== $expected && strlen( $actual ) >= strlen( $expected )
				&& 0 === strcasecmp( substr( $actual, -strlen( $expected ) ), $expected );

		case 'empty':
			return '' === trim( $actual );

		case 'not_empty':
			return '' !== trim( $actual );

		case 'greater':
		case 'less':
		case 'greater_equal':
		case 'less_equal':
			// A non-numeric operand makes an ordering question meaningless, and
			// the honest answer is false rather than PHP's idea of what
			// `"apple" > 5` means.
			if ( ! is_numeric( $actual ) || ! is_numeric( $expected ) ) {
				return false;
			}

			$left  = (float) $actual;
			$right = (float) $expected;

			if ( 'greater' === $operator ) {
				return $left > $right;
			}

			if ( 'less' === $operator ) {
				return $left < $right;
			}

			return 'greater_equal' === $operator ? $left >= $right : $left <= $right;
	}

	return false;
}

/**
 * Which fields are actually visible for a set of submitted values.
 *
 * The function validation depends on. A field hidden by logic is not required,
 * is not validated, and does not appear in the entry -- and the only way to know
 * which those are is to evaluate the logic server-side against what was posted.
 *
 * Resolved iteratively because logic can chain: field B is shown when A is "yes",
 * and field C is shown when B is "blue". If B is hidden, its value must not
 * count towards C's rule, or C appears for somebody who never answered B. Each
 * pass hides everything whose rules fail against the currently-visible values,
 * and repeats until a pass changes nothing.
 *
 * The iteration is capped. A form can be built with a genuine cycle -- A shows B,
 * B hides A -- which has no stable answer, and the cap turns an infinite loop
 * into a merely-wrong-looking form.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param array $values Field id => submitted value.
 * @return array<string, bool> Field id => visible.
 */
function atf_visible_fields( $schema, $values ) {
	$fields  = isset( $schema['fields'] ) ? $schema['fields'] : array();
	$visible = array();

	foreach ( $fields as $field ) {
		$visible[ $field['id'] ] = true;
	}

	$max_passes = 10;

	for ( $pass = 0; $pass < $max_passes; $pass++ ) {
		$changed = false;

		// Only fields currently visible contribute a value. A hidden field is
		// treated as unanswered even if the browser posted something for it,
		// which is also what stops a stale value -- typed, then hidden by a
		// change of mind -- from driving the rest of the form.
		$effective = array();

		foreach ( $fields as $field ) {
			$id               = $field['id'];
			$effective[ $id ] = ! empty( $visible[ $id ] ) && array_key_exists( $id, $values ) ? $values[ $id ] : null;
		}

		foreach ( $fields as $field ) {
			$id       = $field['id'];
			$expected = atf_logic_passes( $field['logic'], $effective, $schema );

			if ( $expected !== $visible[ $id ] ) {
				$visible[ $id ] = $expected;
				$changed        = true;
			}
		}

		if ( ! $changed ) {
			break;
		}
	}

	/**
	 * Filters which fields are visible for a submission.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, bool> $visible Field id => visible.
	 * @param array               $schema  The form schema.
	 * @param array               $values  Submitted values.
	 */
	return apply_filters( 'atf_visible_fields', $visible, $schema, $values );
}

/**
 * Whether one field is visible, given a set of values.
 *
 * @since 0.1.0
 *
 * @param array  $schema   The form schema.
 * @param string $field_id The field.
 * @param array  $values   Submitted values.
 * @return bool
 */
function atf_field_is_visible( $schema, $field_id, $values ) {
	$visible = atf_visible_fields( $schema, $values );

	return ! isset( $visible[ $field_id ] ) || $visible[ $field_id ];
}

<?php
/**
 * Calculations.
 *
 * A total field carries a formula like `{f3} * {f5} + 10` and this file works
 * out what it comes to. Order pricing, quote builders, scored quizzes and
 * booking totals are all this one feature, and it is behind a paywall in every
 * major forms plugin.
 *
 * **There is no `eval()` here and there never will be.** A formula is written by
 * whoever can edit forms, stored in the database, and evaluated on every
 * submission -- which makes `eval()` a remote code execution vector wearing a
 * convenience costume, reachable by anyone who can get a string into a form's
 * schema. Instead the formula is tokenised, converted to postfix with the
 * shunting-yard algorithm, and evaluated over a stack. The only things that can
 * come out are numbers.
 *
 * Twinned with `src/shared/calc.ts`, which shows the visitor a live total as
 * they type. The server recomputes on submit and stores its own answer, so a
 * tampered client total is discarded rather than charged.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The functions a formula may call.
 *
 * Deliberately short. Everything here is pure, numeric, and cannot reach outside
 * the calculation -- which is the property that makes the whitelist a security
 * boundary rather than a convenience.
 *
 * @since 0.1.0
 *
 * @return array<string, int> Function name => argument count, or -1 for variadic.
 */
function alltfo_calc_functions() {
	$functions = array(
		'min'   => -1,
		'max'   => -1,
		'sum'   => -1,
		'avg'   => -1,
		'round' => -1,
		'ceil'  => 1,
		'floor' => 1,
		'abs'   => 1,
		'sqrt'  => 1,
		'pow'   => 2,
	);

	/**
	 * Filters the functions available inside a calculation formula.
	 *
	 * Anything added must be pure and numeric. A function with a side effect, or
	 * one that can reach the filesystem or the database, turns the formula field
	 * into an execution surface for anybody who can edit a form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $functions Name => arity.
	 */
	return apply_filters( 'alltfo_calc_functions', $functions );
}

/**
 * Evaluates a formula against a set of field values.
 *
 * @since 0.1.0
 *
 * @param string $formula The formula, e.g. `{f1} * 2 + max( {f2}, 10 )`.
 * @param array  $values  Field id => value.
 * @param array  $schema  The form schema, so choice prices can be looked up.
 * @return float|null The result, or null when the formula cannot be evaluated.
 */
function alltfo_calculate( $formula, $values, $schema = array() ) {
	$formula = (string) $formula;

	if ( '' === trim( $formula ) ) {
		return null;
	}

	// A runaway formula is a denial of service, and there is no legitimate one
	// this long.
	if ( strlen( $formula ) > 2000 ) {
		return null;
	}

	$resolved = alltfo_calc_resolve_refs( $formula, $values, $schema );
	$tokens   = alltfo_calc_tokenize( $resolved );

	if ( null === $tokens ) {
		return null;
	}

	$postfix = alltfo_calc_to_postfix( $tokens );

	if ( null === $postfix ) {
		return null;
	}

	$result = alltfo_calc_eval_postfix( $postfix );

	if ( null === $result || ! is_finite( $result ) ) {
		return null;
	}

	/**
	 * Filters a calculation result.
	 *
	 * @since 0.1.0
	 *
	 * @param float  $result  The computed value.
	 * @param string $formula The formula.
	 * @param array  $values  The values it was computed from.
	 */
	return (float) apply_filters( 'alltfo_calculation_result', $result, $formula, $values );
}

/**
 * Replaces `{field}` and `{repeater.sub}` references with numeric literals.
 *
 * A field that was not answered, or whose answer is not a number, contributes
 * zero. That is the only reading that lets a running total work: a quantity box
 * nobody has typed in yet must not make the whole total collapse to nothing.
 *
 * A choice field contributes its selected choice's `price`, which is what makes
 * "Ticket type" able to participate in an order total without a hidden field
 * shadowing it.
 *
 * A repeater is a *list* of answers, and the grammar reads it three ways:
 *
 * - `{attendees}` is the number of rows -- "charge 15 per attendee" is
 *   `{attendees} * 15`.
 * - `sum( {attendees.age} )` spreads into one argument per row, so `sum`,
 *   `avg`, `min` and `max` see every row's answer individually. The spread
 *   happens only when the reference is the sole argument of one of those
 *   four -- spreading into `pow( {a.b}, 2 )` would silently push the `2`
 *   out of its parameter slot.
 * - `{attendees.age}` anywhere else is the total across rows, which is what
 *   an aggregate reference standing alone in arithmetic can only mean.
 *
 * @since 0.1.0
 *
 * @param string $formula The formula.
 * @param array  $values  Field id => value.
 * @param array  $schema  The form schema.
 * @return string The formula with references replaced by literals.
 */
function alltfo_calc_resolve_refs( $formula, $values, $schema ) {
	// Assembled by offset rather than `preg_replace_callback()`, because a
	// repeater reference resolves differently depending on what surrounds it,
	// and the callback never learns where in the formula it is standing.
	if ( ! preg_match_all( '/\{([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\}/', $formula, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return $formula;
	}

	$out    = '';
	$cursor = 0;

	foreach ( $matches as $match ) {
		$whole  = $match[0][0];
		$offset = (int) $match[0][1];
		$sub_id = isset( $match[2] ) && -1 !== $match[2][1] ? $match[2][0] : '';

		$out .= substr( $formula, $cursor, $offset - $cursor );
		$out .= alltfo_calc_ref_literal( $formula, $offset, strlen( $whole ), $match[1][0], $sub_id, $values, $schema );

		$cursor = $offset + strlen( $whole );
	}

	return $out . substr( $formula, $cursor );
}

/**
 * The literal one reference resolves to.
 *
 * @since 0.1.0
 *
 * @param string $formula  The whole formula, for reading the reference's context.
 * @param int    $offset   Where the reference starts.
 * @param int    $length   How long the reference is.
 * @param string $field_id The field named before the dot, or alone.
 * @param string $sub_id   The repeater sub-field named after the dot, or ''.
 * @param array  $values   Field id => value.
 * @param array  $schema   The form schema.
 * @return string A numeric literal, or a parenthesised/comma-separated list of them.
 */
function alltfo_calc_ref_literal( $formula, $offset, $length, $field_id, $sub_id, $values, $schema ) {
	$field = $schema ? alltfo_find_field( $schema, $field_id ) : null;
	$value = array_key_exists( $field_id, $values ) ? $values[ $field_id ] : null;

	if ( '' === $sub_id ) {
		// A repeater referenced whole is its row count; anything else is its
		// numeric value as before.
		$number = $field && 'repeater' === $field['type']
			? (float) count( alltfo_calc_repeater_rows( $value ) )
			: alltfo_calc_numeric_value( $value, $field );

		return alltfo_calc_number_literal( $number );
	}

	$sub = null;

	if ( $field && ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
		foreach ( $field['fields'] as $candidate ) {
			if ( isset( $candidate['id'] ) && (string) $candidate['id'] === $sub_id ) {
				$sub = $candidate;
				break;
			}
		}
	}

	$numbers = array();

	foreach ( alltfo_calc_repeater_rows( $value ) as $row ) {
		$numbers[] = alltfo_calc_numeric_value( isset( $row[ $sub_id ] ) ? $row[ $sub_id ] : '', $sub );
	}

	if ( ! $numbers ) {
		return '0';
	}

	$literals = array_map( 'alltfo_calc_number_literal', $numbers );

	if ( alltfo_calc_ref_spreads( $formula, $offset, $length ) ) {
		return implode( ', ', $literals );
	}

	return 1 === count( $literals ) ? $literals[0] : '( ' . implode( ' + ', $literals ) . ' )';
}

/**
 * Whether a reference at this position spreads into one argument per row.
 *
 * True only when it is the sole argument of a variadic aggregate --
 * `sum( {a.b} )` -- where "one argument per row" is unambiguously what was
 * meant. Everywhere else the reference collapses to its sum, because spreading
 * into a fixed-arity call like `pow( {a.b}, 2 )` would silently shift every
 * later argument out of its slot.
 *
 * @since 0.1.0
 *
 * @param string $formula The whole formula.
 * @param int    $offset  Where the reference starts.
 * @param int    $length  How long the reference is.
 * @return bool
 */
function alltfo_calc_ref_spreads( $formula, $offset, $length ) {
	$after = ltrim( substr( $formula, $offset + $length ) );

	if ( '' === $after || ')' !== $after[0] ) {
		return false;
	}

	$before = rtrim( substr( $formula, 0, $offset ) );

	if ( ! preg_match( '/([a-zA-Z_][a-zA-Z0-9_]*)\s*\($/', $before, $match ) ) {
		return false;
	}

	return in_array( strtolower( $match[1] ), array( 'sum', 'avg', 'min', 'max' ), true );
}

/**
 * A repeater value's rows that were actually filled in.
 *
 * A row where every answer is empty is a row the visitor added and abandoned;
 * counting it would make `{attendees}` disagree with what the entry stores,
 * because `alltfo_sanitize_repeater_value()` drops exactly the same rows.
 *
 * @since 0.1.0
 *
 * @param mixed $value The repeater's value.
 * @return array[] Rows with at least one non-empty answer.
 */
function alltfo_calc_repeater_rows( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$rows = array();

	foreach ( $value as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		foreach ( $row as $item ) {
			if ( '' !== $item && null !== $item && false !== $item && array() !== $item ) {
				$rows[] = $row;
				break;
			}
		}
	}

	return $rows;
}

/**
 * A number as a formula literal the tokenizer can read back.
 *
 * A bare float cast can produce scientific notation ("1.0E-5"), which the
 * tokenizer reads as an unknown symbol and the whole formula dies. This prints
 * plain decimals at every magnitude.
 *
 * @since 0.1.0
 *
 * @param float $number The number.
 * @return string
 */
function alltfo_calc_number_literal( $number ) {
	$literal = rtrim( rtrim( sprintf( '%.10F', $number ), '0' ), '.' );

	return '' === $literal || '-' === $literal ? '0' : $literal;
}

/**
 * The number a field's value contributes to a calculation.
 *
 * @since 0.1.0
 *
 * @param mixed      $value The submitted value.
 * @param array|null $field The field it belongs to.
 * @return float
 */
function alltfo_calc_numeric_value( $value, $field ) {
	if ( is_bool( $value ) ) {
		return $value ? 1.0 : 0.0;
	}

	// A multi-choice field sums the prices of everything picked, which is what
	// "extras" on an order form means.
	if ( is_array( $value ) ) {
		$total = 0.0;

		foreach ( $value as $item ) {
			$total += alltfo_calc_numeric_value( $item, $field );
		}

		return $total;
	}

	if ( null === $value || '' === $value ) {
		return 0.0;
	}

	if ( $field && ! empty( $field['choices'] ) ) {
		foreach ( $field['choices'] as $choice ) {
			if ( isset( $choice['value'] ) && (string) $choice['value'] === (string) $value ) {
				if ( isset( $choice['price'] ) ) {
					return (float) $choice['price'];
				}

				if ( isset( $choice['points'] ) ) {
					return (float) $choice['points'];
				}

				break;
			}
		}
	}

	return is_numeric( $value ) ? (float) $value : 0.0;
}

/**
 * Splits a resolved formula into tokens.
 *
 * Anything the grammar does not recognise aborts the whole evaluation. Skipping
 * unknown characters instead would turn a typo into a silently different sum,
 * and a total that is quietly wrong is worse than one that is visibly missing.
 *
 * @since 0.1.0
 *
 * @param string $formula A formula with no field references left in it.
 * @return array[]|null Tokens, or null when the formula contains something unrecognised.
 */
function alltfo_calc_tokenize( $formula ) {
	$tokens = array();
	$length = strlen( $formula );
	$i      = 0;

	while ( $i < $length ) {
		$char = $formula[ $i ];

		if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
			++$i;
			continue;
		}

		if ( ctype_digit( $char ) || '.' === $char ) {
			$number = '';

			while ( $i < $length && ( ctype_digit( $formula[ $i ] ) || '.' === $formula[ $i ] ) ) {
				$number .= $formula[ $i ];
				++$i;
			}

			if ( ! is_numeric( $number ) ) {
				return null;
			}

			$tokens[] = array(
				'type'  => 'number',
				'value' => (float) $number,
			);
			continue;
		}

		if ( ctype_alpha( $char ) || '_' === $char ) {
			$name = '';

			while ( $i < $length && ( ctype_alnum( $formula[ $i ] ) || '_' === $formula[ $i ] ) ) {
				$name .= $formula[ $i ];
				++$i;
			}

			$name = strtolower( $name );

			if ( ! isset( alltfo_calc_functions()[ $name ] ) ) {
				return null;
			}

			$tokens[] = array(
				'type'  => 'function',
				'value' => $name,
			);
			continue;
		}

		if ( '(' === $char || ')' === $char ) {
			$tokens[] = array(
				'type'  => $char,
				'value' => $char,
			);
			++$i;
			continue;
		}

		if ( ',' === $char ) {
			$tokens[] = array(
				'type'  => 'comma',
				'value' => ',',
			);
			++$i;
			continue;
		}

		if ( false !== strpos( '+-*/%^', $char ) ) {
			// A `-` in operand position is a sign, not a subtraction: `-5` and
			// `3 * -2` both have to work. Marked as a distinct unary operator
			// here so the postfix stage does not have to look backwards.
			$previous = $tokens ? $tokens[ count( $tokens ) - 1 ] : null;
			$is_unary = '-' === $char && ( null === $previous
				|| in_array( $previous['type'], array( 'operator', 'unary', '(', 'comma' ), true ) );

			$tokens[] = array(
				'type'  => $is_unary ? 'unary' : 'operator',
				'value' => $char,
			);
			++$i;
			continue;
		}

		return null;
	}

	return $tokens;
}

/**
 * Operator precedence and associativity.
 *
 * @since 0.1.0
 *
 * @param string $operator The operator.
 * @return array { precedence: int, right: bool }
 */
function alltfo_calc_operator_info( $operator ) {
	$table = array(
		'+' => array(
			'precedence' => 1,
			'right'      => false,
		),
		'-' => array(
			'precedence' => 1,
			'right'      => false,
		),
		'*' => array(
			'precedence' => 2,
			'right'      => false,
		),
		'/' => array(
			'precedence' => 2,
			'right'      => false,
		),
		'%' => array(
			'precedence' => 2,
			'right'      => false,
		),
		'^' => array(
			'precedence' => 4,
			'right'      => true,
		),
	);

	return isset( $table[ $operator ] ) ? $table[ $operator ] : array(
		'precedence' => 0,
		'right'      => false,
	);
}

/**
 * Precedence and associativity of a stacked operator, unary included.
 *
 * Unary minus sits at 3: **below** exponentiation and **above** multiplication.
 * That is the convention every calculator and language follows, and it is the
 * difference between `-2 ^ 2` meaning `-( 2 ^ 2 )` -- which is -4 -- and meaning
 * `( -2 ) ^ 2`, which is 4. Popping unary operators unconditionally, as the
 * obvious implementation does, silently gives the second answer.
 *
 * Right-associative so `- - 5` nests rather than trying to pop itself.
 *
 * @since 0.1.0
 *
 * @param array $token A token from the operator stack.
 * @return array { precedence: int, right: bool }
 */
function alltfo_calc_stack_precedence( $token ) {
	if ( 'unary' === $token['type'] ) {
		return array(
			'precedence' => 3,
			'right'      => true,
		);
	}

	return alltfo_calc_operator_info( $token['value'] );
}

/**
 * Converts infix tokens to postfix, by the shunting-yard algorithm.
 *
 * Function arity is counted here rather than assumed, so `round( 3.14159, 2 )`
 * and `round( 3.7 )` are both legal and `min()` takes as many arguments as it is
 * given.
 *
 * @since 0.1.0
 *
 * @param array[] $tokens Infix tokens.
 * @return array[]|null Postfix tokens, or null when the parentheses do not balance.
 */
function alltfo_calc_to_postfix( $tokens ) {
	$output    = array();
	$operators = array();
	$arity     = array();

	foreach ( $tokens as $token ) {
		switch ( $token['type'] ) {
			case 'number':
				$output[] = $token;
				break;

			case 'function':
				$operators[] = $token;
				$arity[]     = 1;
				break;

			case 'comma':
				while ( $operators && '(' !== end( $operators )['type'] ) {
					$output[] = array_pop( $operators );
				}

				if ( ! $operators ) {
					return null;
				}

				if ( $arity ) {
					++$arity[ count( $arity ) - 1 ];
				}
				break;

			case 'unary':
				$operators[] = $token;
				break;

			case 'operator':
				$info = alltfo_calc_operator_info( $token['value'] );

				while ( $operators ) {
					$top = end( $operators );

					if ( 'operator' !== $top['type'] && 'unary' !== $top['type'] ) {
						break;
					}

					$top_info = alltfo_calc_stack_precedence( $top );

					if ( $top_info['precedence'] > $info['precedence']
						|| ( $top_info['precedence'] === $info['precedence'] && ! $info['right'] ) ) {
						$output[] = array_pop( $operators );
						continue;
					}

					break;
				}

				$operators[] = $token;
				break;

			case '(':
				$operators[] = $token;
				break;

			case ')':
				while ( $operators && '(' !== end( $operators )['type'] ) {
					$output[] = array_pop( $operators );
				}

				if ( ! $operators ) {
					return null;
				}

				array_pop( $operators );

				if ( $operators && 'function' === end( $operators )['type'] ) {
					$function          = array_pop( $operators );
					$function['arity'] = $arity ? array_pop( $arity ) : 1;
					$output[]          = $function;
				}
				break;
		}
	}

	while ( $operators ) {
		$top = array_pop( $operators );

		if ( '(' === $top['type'] ) {
			return null;
		}

		$output[] = $top;
	}

	return $output;
}

/**
 * Evaluates postfix tokens over a stack.
 *
 * @since 0.1.0
 *
 * @param array[] $postfix Postfix tokens.
 * @return float|null The result, or null when the expression is malformed.
 */
function alltfo_calc_eval_postfix( $postfix ) {
	$stack = array();

	foreach ( $postfix as $token ) {
		switch ( $token['type'] ) {
			case 'number':
				$stack[] = (float) $token['value'];
				break;

			case 'unary':
				if ( ! $stack ) {
					return null;
				}

				$stack[] = -array_pop( $stack );
				break;

			case 'operator':
				if ( count( $stack ) < 2 ) {
					return null;
				}

				$right = array_pop( $stack );
				$left  = array_pop( $stack );

				switch ( $token['value'] ) {
					case '+':
						$stack[] = $left + $right;
						break;

					case '-':
						$stack[] = $left - $right;
						break;

					case '*':
						$stack[] = $left * $right;
						break;

					case '/':
						// Division by zero returns 0 rather than aborting. A
						// visitor half-way through a form routinely has a zero
						// in a denominator, and blanking the running total each
						// time reads as the calculation being broken.
						$stack[] = 0.0 === $right ? 0.0 : $left / $right;
						break;

					case '%':
						$stack[] = 0.0 === $right ? 0.0 : fmod( $left, $right );
						break;

					case '^':
						$stack[] = pow( $left, $right );
						break;

					default:
						return null;
				}
				break;

			case 'function':
				$arity = isset( $token['arity'] ) ? (int) $token['arity'] : 1;

				if ( count( $stack ) < $arity ) {
					return null;
				}

				$args = array();

				for ( $i = 0; $i < $arity; $i++ ) {
					array_unshift( $args, array_pop( $stack ) );
				}

				$result = alltfo_calc_apply_function( $token['value'], $args );

				if ( null === $result ) {
					return null;
				}

				$stack[] = $result;
				break;

			default:
				return null;
		}
	}

	return 1 === count( $stack ) ? (float) $stack[0] : null;
}

/**
 * Applies one whitelisted function.
 *
 * @since 0.1.0
 *
 * @param string  $name Function name, already known to be whitelisted.
 * @param float[] $args Its arguments.
 * @return float|null
 */
function alltfo_calc_apply_function( $name, $args ) {
	if ( ! $args ) {
		return null;
	}

	switch ( $name ) {
		case 'min':
			return (float) min( $args );

		case 'max':
			return (float) max( $args );

		case 'sum':
			return (float) array_sum( $args );

		case 'avg':
			return (float) ( array_sum( $args ) / count( $args ) );

		case 'round':
			$precision = isset( $args[1] ) ? (int) $args[1] : 0;

			// A precision beyond float resolution is meaningless and an
			// enormous one is a way to burn CPU.
			return round( $args[0], max( -10, min( 10, $precision ) ) );

		case 'ceil':
			return (float) ceil( $args[0] );

		case 'floor':
			return (float) floor( $args[0] );

		case 'abs':
			return (float) abs( $args[0] );

		case 'sqrt':
			return $args[0] < 0 ? 0.0 : (float) sqrt( $args[0] );

		case 'pow':
			return (float) pow( $args[0], isset( $args[1] ) ? $args[1] : 2 );
	}

	/**
	 * Applies a calculation function added through `alltfo_calc_functions`.
	 *
	 * @since 0.1.0
	 *
	 * @param float|null $result Null until something computes it.
	 * @param string     $name   Function name.
	 * @param float[]    $args   Arguments.
	 */
	return apply_filters( 'alltfo_calc_apply_function', null, $name, $args );
}

/**
 * Computes every calculated field in a schema.
 *
 * Runs in schema order and feeds each result back into the value set, so one
 * total can be built from another -- a subtotal, then VAT on it, then a grand
 * total. Anything referencing a total defined *later* than itself sees zero,
 * which is the price of not solving a dependency graph here; the builder warns
 * about it rather than the evaluator guessing.
 *
 * @since 0.1.0
 *
 * @param array $schema The form schema.
 * @param array $values Field id => value.
 * @return array The values, with every calculated field filled in.
 */
function alltfo_apply_calculations( $schema, $values ) {
	$fields = isset( $schema['fields'] ) ? $schema['fields'] : array();

	foreach ( $fields as $field ) {
		if ( empty( $field['formula'] ) ) {
			continue;
		}

		$result = alltfo_calculate( $field['formula'], $values, $schema );

		if ( null === $result ) {
			// A formula that fails must not leave the client-posted value in
			// place: for a field that carries a formula the server's answer is
			// the only answer, and "could not compute" is an empty one, never
			// whatever the request claimed the total was.
			$values[ $field['id'] ] = '';
			continue;
		}

		$decimals               = isset( $field['decimals'] ) ? absint( $field['decimals'] ) : 2;
		$values[ $field['id'] ] = round( $result, $decimals );
	}

	return $values;
}

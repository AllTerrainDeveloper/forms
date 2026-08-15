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
function atf_calc_functions() {
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
	return apply_filters( 'atf_calc_functions', $functions );
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
function atf_calculate( $formula, $values, $schema = array() ) {
	$formula = (string) $formula;

	if ( '' === trim( $formula ) ) {
		return null;
	}

	// A runaway formula is a denial of service, and there is no legitimate one
	// this long.
	if ( strlen( $formula ) > 2000 ) {
		return null;
	}

	$resolved = atf_calc_resolve_refs( $formula, $values, $schema );
	$tokens   = atf_calc_tokenize( $resolved );

	if ( null === $tokens ) {
		return null;
	}

	$postfix = atf_calc_to_postfix( $tokens );

	if ( null === $postfix ) {
		return null;
	}

	$result = atf_calc_eval_postfix( $postfix );

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
	return (float) apply_filters( 'atf_calculation_result', $result, $formula, $values );
}

/**
 * Replaces `{field}` references with their numeric values.
 *
 * A field that was not answered, or whose answer is not a number, contributes
 * zero. That is the only reading that lets a running total work: a quantity box
 * nobody has typed in yet must not make the whole total collapse to nothing.
 *
 * A choice field contributes its selected choice's `price`, which is what makes
 * "Ticket type" able to participate in an order total without a hidden field
 * shadowing it.
 *
 * @since 0.1.0
 *
 * @param string $formula The formula.
 * @param array  $values  Field id => value.
 * @param array  $schema  The form schema.
 * @return string The formula with references replaced by literals.
 */
function atf_calc_resolve_refs( $formula, $values, $schema ) {
	return preg_replace_callback(
		'/\{([a-zA-Z0-9_]+)\}/',
		static function ( $matches ) use ( $values, $schema ) {
			$field_id = $matches[1];
			$value    = array_key_exists( $field_id, $values ) ? $values[ $field_id ] : null;
			$field    = $schema ? atf_find_field( $schema, $field_id ) : null;

			return (string) atf_calc_numeric_value( $value, $field );
		},
		$formula
	);
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
function atf_calc_numeric_value( $value, $field ) {
	if ( is_bool( $value ) ) {
		return $value ? 1.0 : 0.0;
	}

	// A multi-choice field sums the prices of everything picked, which is what
	// "extras" on an order form means.
	if ( is_array( $value ) ) {
		$total = 0.0;

		foreach ( $value as $item ) {
			$total += atf_calc_numeric_value( $item, $field );
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
function atf_calc_tokenize( $formula ) {
	$tokens = array();
	$length = strlen( $formula );
	$i      = 0;

	while ( $i < $length ) {
		$char = $formula[ $i ];

		if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
			$i++;
			continue;
		}

		if ( ctype_digit( $char ) || '.' === $char ) {
			$number = '';

			while ( $i < $length && ( ctype_digit( $formula[ $i ] ) || '.' === $formula[ $i ] ) ) {
				$number .= $formula[ $i ];
				$i++;
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
				$i++;
			}

			$name = strtolower( $name );

			if ( ! isset( atf_calc_functions()[ $name ] ) ) {
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
			$i++;
			continue;
		}

		if ( ',' === $char ) {
			$tokens[] = array(
				'type'  => 'comma',
				'value' => ',',
			);
			$i++;
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
			$i++;
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
function atf_calc_operator_info( $operator ) {
	$table = array(
		'+' => array( 'precedence' => 1, 'right' => false ),
		'-' => array( 'precedence' => 1, 'right' => false ),
		'*' => array( 'precedence' => 2, 'right' => false ),
		'/' => array( 'precedence' => 2, 'right' => false ),
		'%' => array( 'precedence' => 2, 'right' => false ),
		'^' => array( 'precedence' => 4, 'right' => true ),
	);

	return isset( $table[ $operator ] ) ? $table[ $operator ] : array( 'precedence' => 0, 'right' => false );
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
function atf_calc_stack_precedence( $token ) {
	if ( 'unary' === $token['type'] ) {
		return array(
			'precedence' => 3,
			'right'      => true,
		);
	}

	return atf_calc_operator_info( $token['value'] );
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
function atf_calc_to_postfix( $tokens ) {
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
					$arity[ count( $arity ) - 1 ]++;
				}
				break;

			case 'unary':
				$operators[] = $token;
				break;

			case 'operator':
				$info = atf_calc_operator_info( $token['value'] );

				while ( $operators ) {
					$top = end( $operators );

					if ( 'operator' !== $top['type'] && 'unary' !== $top['type'] ) {
						break;
					}

					$top_info = atf_calc_stack_precedence( $top );

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
function atf_calc_eval_postfix( $postfix ) {
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

				$result = atf_calc_apply_function( $token['value'], $args );

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
function atf_calc_apply_function( $name, $args ) {
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
	 * Applies a calculation function added through `atf_calc_functions`.
	 *
	 * @since 0.1.0
	 *
	 * @param float|null $result Null until something computes it.
	 * @param string     $name   Function name.
	 * @param float[]    $args   Arguments.
	 */
	return apply_filters( 'atf_calc_apply_function', null, $name, $args );
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
function atf_apply_calculations( $schema, $values ) {
	$fields = isset( $schema['fields'] ) ? $schema['fields'] : array();

	foreach ( $fields as $field ) {
		if ( empty( $field['formula'] ) ) {
			continue;
		}

		$result = atf_calculate( $field['formula'], $values, $schema );

		if ( null === $result ) {
			continue;
		}

		$decimals              = isset( $field['decimals'] ) ? absint( $field['decimals'] ) : 2;
		$values[ $field['id'] ] = round( $result, $decimals );
	}

	return $values;
}

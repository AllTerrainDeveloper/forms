<?php
/**
 * Validation.
 *
 * The server is the authority on whether a submission is acceptable. The browser
 * validates too, and it validates first, but nothing it decides is trusted here:
 * every rule the bundle enforces is re-enforced in this file against the values
 * that actually arrived.
 *
 * The ordering matters and is deliberate. Visibility is resolved first, because
 * a field hidden by conditional logic is not required and must not be validated
 * at all -- checking it first would reject a submission for not answering a
 * question the visitor was never shown. Then required, then type, then bounds.
 * Reporting "this is required" and "this is too long" for the same empty field
 * is noise, so the first failure per field wins.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates a whole submission.
 *
 * @since 0.1.0
 *
 * @param array $schema  The form schema.
 * @param array $values  Sanitised values, field id => value.
 * @param array $context {
 *     Optional. Submission context.
 *
 *     @type int $form_id  The form.
 *     @type int $entry_id An entry being updated, excluded from unique checks.
 * }
 * @return array<string, string> Field id => error message. Empty when valid.
 */
function atf_validate_submission( $schema, $values, $context = array() ) {
	$context = wp_parse_args(
		$context,
		array(
			'form_id'  => 0,
			'entry_id' => 0,
		)
	);

	$errors  = array();
	$visible = atf_visible_fields( $schema, $values );

	foreach ( atf_input_fields( $schema ) as $field ) {
		if ( empty( $visible[ $field['id'] ] ) ) {
			continue;
		}

		$value = array_key_exists( $field['id'], $values ) ? $values[ $field['id'] ] : '';
		$error = atf_validate_field( $field, $value, $schema, $context );

		if ( '' !== $error ) {
			$errors[ $field['id'] ] = $error;
		}
	}

	/**
	 * Filters the validation errors for a submission.
	 *
	 * Where a site adds a cross-field rule -- "the end date must be after the
	 * start date", "at least one contact method" -- that no single field can
	 * express on its own.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $errors  Field id => message.
	 * @param array                 $schema  The form schema.
	 * @param array                 $values  The submitted values.
	 * @param array                 $context Submission context.
	 */
	return apply_filters( 'atf_validation_errors', $errors, $schema, $values, $context );
}

/**
 * Validates one field.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its submitted value.
 * @param array $schema  The form schema.
 * @param array $context Submission context.
 * @return string The error message, or an empty string when the field is fine.
 */
function atf_validate_field( $field, $value, $schema, $context = array() ) {
	$empty = atf_value_is_empty( $value );

	if ( ! empty( $field['required'] ) && $empty ) {
		// The consent field has its own idea of what a missing answer means, so
		// it is allowed to answer first.
		$definition = atf_get_field_type( $field['type'] );

		if ( $definition && is_callable( $definition['validate'] ) ) {
			$result = call_user_func( $definition['validate'], $value, $field, $context );

			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}
		}

		return atf_field_message(
			$field,
			'required',
			$field['label']
				/* translators: %s: the field's label. */
				? sprintf( __( '%s is required.', 'allterrain-forms' ), $field['label'] )
				: __( 'This is required.', 'allterrain-forms' )
		);
	}

	// An empty optional field is valid by definition, and running the type and
	// bounds checks over it produces nonsense like "that is not an email
	// address" for a box nobody filled in.
	if ( $empty ) {
		return '';
	}

	$definition = atf_get_field_type( $field['type'] );

	if ( $definition && is_callable( $definition['validate'] ) ) {
		$result = call_user_func( $definition['validate'], $value, $field, $context );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
	}

	$bounds = atf_validate_bounds( $field, $value );

	if ( '' !== $bounds ) {
		return $bounds;
	}

	if ( ! empty( $field['unique'] ) ) {
		$duplicate = atf_value_already_submitted( $field, $value, $context );

		if ( $duplicate ) {
			return atf_field_message(
				$field,
				'unique',
				__( 'That has already been submitted.', 'allterrain-forms' )
			);
		}
	}

	/**
	 * Filters one field's validation result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $error  The message, or an empty string.
	 * @param array  $field  The field.
	 * @param mixed  $value  Its value.
	 * @param array  $schema The form schema.
	 */
	return (string) apply_filters( 'atf_validate_field', '', $field, $value, $schema );
}

/**
 * Whether a value counts as unanswered.
 *
 * `0` is an answer. A rating of zero, a quantity of zero and a scale answer of
 * zero are all things a visitor deliberately chose, and PHP's `empty()` calls
 * every one of them missing -- which is how a required NPS field rejects the
 * people who gave it the lowest score.
 *
 * @since 0.1.0
 *
 * @param mixed $value The value.
 * @return bool
 */
function atf_value_is_empty( $value ) {
	if ( is_bool( $value ) ) {
		return ! $value;
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( ! atf_value_is_empty( $item ) ) {
				return false;
			}
		}

		return true;
	}

	return '' === $value || null === $value;
}

/**
 * Checks a value against the field's own bounds.
 *
 * @since 0.1.0
 *
 * @param array $field The field.
 * @param mixed $value Its value.
 * @return string The error message, or an empty string.
 */
function atf_validate_bounds( $field, $value ) {
	if ( is_string( $value ) ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

		if ( isset( $field['minlength'] ) && '' !== $field['minlength'] && $length < (int) $field['minlength'] ) {
			return atf_field_message(
				$field,
				'min',
				sprintf(
					/* translators: %d: minimum number of characters. */
					_n( 'Use at least %d character.', 'Use at least %d characters.', (int) $field['minlength'], 'allterrain-forms' ),
					(int) $field['minlength']
				)
			);
		}

		if ( isset( $field['maxlength'] ) && '' !== $field['maxlength'] && $length > (int) $field['maxlength'] ) {
			return atf_field_message(
				$field,
				'max',
				sprintf(
					/* translators: %d: maximum number of characters. */
					_n( 'Use no more than %d character.', 'Use no more than %d characters.', (int) $field['maxlength'], 'allterrain-forms' ),
					(int) $field['maxlength']
				)
			);
		}

		if ( ! empty( $field['validation'] ) && 'custom' !== $field['validation'] ) {
			$preset_error = atf_validate_preset( $field, (string) $field['validation'], $value );

			if ( '' !== $preset_error ) {
				return $preset_error;
			}
		}

		if ( ! empty( $field['pattern'] ) ) {
			// The pattern is authored in the builder as a JavaScript regular
			// expression, so it is delimited here rather than trusted to carry
			// its own delimiters -- and `preg_match()` is given a pattern that
			// cannot be turned into a different one by a stray `/`.
			$pattern = '/' . str_replace( '/', '\\/', (string) $field['pattern'] ) . '/u';

			// A malformed pattern is the form builder's mistake, not the
			// visitor's. Suppressing the warning and passing the field is the
			// only behaviour that does not punish the wrong person.
			$matched = @preg_match( $pattern, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid author-supplied pattern must not fatal a public form.

			if ( 0 === $matched ) {
				return atf_field_message( $field, 'invalid', __( 'That is not in the expected format.', 'allterrain-forms' ) );
			}
		}
	}

	if ( is_numeric( $value ) ) {
		if ( isset( $field['min'] ) && '' !== $field['min'] && (float) $value < (float) $field['min'] ) {
			return atf_field_message(
				$field,
				'min',
				/* translators: %s: the smallest allowed number. */
				sprintf( __( 'Enter %s or more.', 'allterrain-forms' ), $field['min'] )
			);
		}

		if ( isset( $field['max'] ) && '' !== $field['max'] && (float) $value > (float) $field['max'] ) {
			return atf_field_message(
				$field,
				'max',
				/* translators: %s: the largest allowed number. */
				sprintf( __( 'Enter %s or less.', 'allterrain-forms' ), $field['max'] )
			);
		}
	}

	if ( is_array( $value ) && $field['choices'] ) {
		$chosen = count(
			array_filter(
				$value,
				static function ( $item ) {
					return '' !== $item && null !== $item;
				}
			)
		);

		if ( isset( $field['minChoices'] ) && '' !== $field['minChoices'] && $chosen < (int) $field['minChoices'] ) {
			return atf_field_message(
				$field,
				'min',
				sprintf(
					/* translators: %d: minimum number of choices. */
					_n( 'Choose at least %d option.', 'Choose at least %d options.', (int) $field['minChoices'], 'allterrain-forms' ),
					(int) $field['minChoices']
				)
			);
		}

		if ( isset( $field['maxChoices'] ) && '' !== $field['maxChoices'] && $chosen > (int) $field['maxChoices'] ) {
			return atf_field_message(
				$field,
				'max',
				sprintf(
					/* translators: %d: maximum number of choices. */
					_n( 'Choose no more than %d option.', 'Choose no more than %d options.', (int) $field['maxChoices'], 'allterrain-forms' ),
					(int) $field['maxChoices']
				)
			);
		}
	}

	// A choice that is not on the list is not a validation failure the visitor
	// can fix -- it is a forged request, or a form that changed under them.
	// Rejecting it stops a "role" dropdown being posted with `administrator`.
	// With "Other" enabled there is no whitelist to enforce: the visitor may
	// legitimately answer with free text, and by the time validation runs the
	// `__other__` marker has already been replaced by whatever they typed.
	if ( $field['choices'] && empty( $field['other'] ) && in_array( $field['type'], array( 'select', 'radio', 'checkboxes', 'multiselect', 'image_choice', 'quiz' ), true ) ) {
		$allowed = wp_list_pluck( $field['choices'], 'value' );
		$allowed = array_map( 'strval', $allowed );

		foreach ( (array) $value as $item ) {
			if ( '' === $item || null === $item ) {
				continue;
			}

			if ( ! in_array( (string) $item, $allowed, true ) ) {
				return atf_field_message( $field, 'invalid', __( 'That is not one of the available options.', 'allterrain-forms' ) );
			}
		}
	}

	return '';
}

/**
 * Whether a value has already been submitted for a field marked unique.
 *
 * Compares against the stored values of every non-spam entry for the form. A
 * `meta_query` cannot do this, because values live together in one JSON blob
 * rather than a row each -- so the comparison is done in PHP over a bounded
 * number of entries, and the bound is what stops a popular form turning every
 * submission into a full table scan.
 *
 * @since 0.1.0
 *
 * @param array $field   The field.
 * @param mixed $value   Its value.
 * @param array $context Submission context.
 * @return bool
 */
function atf_value_already_submitted( $field, $value, $context ) {
	$form_id = isset( $context['form_id'] ) ? absint( $context['form_id'] ) : 0;

	if ( ! $form_id ) {
		return false;
	}

	/**
	 * Filters how many past entries a uniqueness check scans.
	 *
	 * Raising it makes the check more thorough and every submission slower. A
	 * site that needs true uniqueness at scale should add a `meta_query`-able
	 * mirror of the field through `atf_entry_created` and check that instead.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $limit   How many entries to scan.
	 * @param array $field   The field being checked.
	 * @param int   $form_id The form.
	 */
	$limit = (int) apply_filters( 'atf_unique_scan_limit', 2000, $field, $form_id );

	$query = new WP_Query(
		array(
			'post_type'      => ATF_ENTRY_TYPE,
			'post_status'    => array( ATF_STATUS_UNREAD, ATF_STATUS_READ ),
			'fields'         => 'ids',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
			'post__not_in'   => array( absint( $context['entry_id'] ) ),
			'meta_query'     => array(
				array(
					'key'   => ATF_META_FORM,
					'value' => $form_id,
				),
			),
		)
	);

	$needle = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : wp_json_encode( $value );

	foreach ( $query->posts as $entry_id ) {
		$values = json_decode( (string) get_post_meta( $entry_id, ATF_META_VALUES, true ), true );

		if ( ! is_array( $values ) || ! array_key_exists( $field['id'], $values ) ) {
			continue;
		}

		$stored = $values[ $field['id'] ];
		$stored = is_scalar( $stored ) ? strtolower( trim( (string) $stored ) ) : wp_json_encode( $stored );

		if ( $stored === $needle ) {
			return true;
		}
	}

	return false;
}

/**
 * Every named answer shape a field's `validation` setting can point at.
 *
 * The server half of a twin: `src/shared/validation.ts` carries the same
 * slugs, the same patterns and the same default messages, and
 * `tests/fixtures/validation-cases.json` is read by both suites so the two
 * cannot drift apart silently. The browser check is a courtesy; this is the
 * law.
 *
 * Patterns are anchored, undelimited, and compiled with `/u` -- which is what
 * lets "letters" mean letters in any alphabet rather than A to Z.
 *
 * @since 0.2.0
 *
 * @return array<string, array{pattern: string, message: string, luhn?: bool}> Preset slug => definition.
 */
function atf_validation_presets() {
	$presets = array(
		'email'        => array(
			'pattern' => '^[^\s@]+@[^\s@]+\.[^\s@]+$',
			'message' => __( 'That does not look like an email address.', 'allterrain-forms' ),
		),
		'phone'        => array(
			'pattern' => '^(?=(?:[^0-9]*[0-9]){5,})\+?[0-9 ().-]{5,24}$',
			'message' => __( 'That does not look like a phone number.', 'allterrain-forms' ),
		),
		'handle'       => array(
			'pattern' => '^@?[A-Za-z0-9_]{2,30}$',
			'message' => __( 'That does not look like a username.', 'allterrain-forms' ),
		),
		'digits'       => array(
			'pattern' => '^[0-9]+$',
			'message' => __( 'Numbers only, please.', 'allterrain-forms' ),
		),
		'decimal'      => array(
			'pattern' => '^-?[0-9]+([.,][0-9]+)?$',
			'message' => __( 'That does not look like a number.', 'allterrain-forms' ),
		),
		'price'        => array(
			'pattern' => '^[0-9]+([.,][0-9]{1,2})?$',
			'message' => __( 'That does not look like a price.', 'allterrain-forms' ),
		),
		'zip_us'       => array(
			'pattern' => '^[0-9]{5}(-[0-9]{4})?$',
			'message' => __( 'That does not look like a ZIP code.', 'allterrain-forms' ),
		),
		'postcode_uk'  => array(
			'pattern' => '^[A-Za-z]{1,2}[0-9][A-Za-z0-9]? ?[0-9][A-Za-z]{2}$',
			'message' => __( 'That does not look like a postcode.', 'allterrain-forms' ),
		),
		'iban'         => array(
			'pattern' => '^[A-Za-z]{2}[0-9]{2}(?: ?[A-Za-z0-9]){10,32}$',
			'message' => __( 'That does not look like an IBAN.', 'allterrain-forms' ),
		),
		'credit_card'  => array(
			'pattern' => '^[0-9](?:[0-9 -]{9,21})?[0-9]$',
			'message' => __( 'That does not look like a card number.', 'allterrain-forms' ),
			'luhn'    => true,
		),
		'letters'      => array(
			'pattern' => "^[\p{L}\p{M} .'\x{2019}-]+$",
			'message' => __( 'Letters only, please.', 'allterrain-forms' ),
		),
		'alphanumeric' => array(
			'pattern' => '^[A-Za-z0-9]+$',
			'message' => __( 'Letters and numbers only, please.', 'allterrain-forms' ),
		),
		'no_spaces'    => array(
			'pattern' => '^\S+$',
			'message' => __( 'No spaces allowed.', 'allterrain-forms' ),
		),
		'url'          => array(
			'pattern' => '^(https?://)?([A-Za-z0-9-]+\.)+[A-Za-z]{2,}([/?#]\S*)?$',
			'message' => __( 'That does not look like a web address.', 'allterrain-forms' ),
		),
		'ip'           => array(
			'pattern' => '^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$',
			'message' => __( 'That does not look like an IP address.', 'allterrain-forms' ),
		),
		'slug'         => array(
			'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$',
			'message' => __( 'Lowercase letters, numbers and dashes only.', 'allterrain-forms' ),
		),
		'hex_color'    => array(
			'pattern' => '^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$',
			'message' => __( 'That does not look like a colour code.', 'allterrain-forms' ),
		),
	);

	/**
	 * Filters the validation presets a field's `validation` setting can name.
	 *
	 * Adding an entry here makes it enforceable server side; pair it with the
	 * `atf_builder_config` route if it should be offered in the builder too.
	 * Each entry is an anchored, undelimited pattern compiled with `/u`, a
	 * default message, and an optional `luhn` flag.
	 *
	 * @since 0.2.0
	 *
	 * @param array $presets Preset slug => definition.
	 */
	return apply_filters( 'atf_validation_presets', $presets );
}

/**
 * Checks a value against one named preset.
 *
 * An unknown slug passes: a form saved by a newer version must not start
 * rejecting every answer because this version has never heard of the shape it
 * asks for.
 *
 * @since 0.2.0
 *
 * @param array  $field The field, for its custom messages.
 * @param string $slug  The preset slug.
 * @param string $value The submitted value.
 * @return string The error message, or an empty string when the value passes.
 */
function atf_validate_preset( $field, $slug, $value ) {
	$presets = atf_validation_presets();

	if ( ! isset( $presets[ $slug ] ) ) {
		return '';
	}

	$preset  = $presets[ $slug ];
	$pattern = '/' . str_replace( '/', '\/', (string) $preset['pattern'] ) . '/u';

	// A preset that does not compile is this table's bug, not the visitor's.
	$matched = @preg_match( $pattern, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken preset pattern must not fatal a public form.

	if ( false === $matched ) {
		return '';
	}

	$passes = 1 === $matched && ( empty( $preset['luhn'] ) || atf_luhn_passes( $value ) );

	if ( $passes ) {
		return '';
	}

	return atf_field_message( $field, 'invalid', (string) $preset['message'] );
}

/**
 * Whether the digits in a value survive the Luhn checksum.
 *
 * The check that tells a plausible card number from a typo: doubling every
 * second digit from the right and summing must land on a multiple of ten.
 * Spaces and dashes are ignored, because people type card numbers in groups.
 *
 * @since 0.2.0
 *
 * @param string $value The value as typed.
 * @return bool Whether the checksum holds.
 */
function atf_luhn_passes( $value ) {
	$digits = preg_replace( '/[^0-9]/', '', (string) $value );
	$length = strlen( $digits );

	if ( $length < 12 ) {
		return false;
	}

	$sum    = 0;
	$double = false;

	for ( $index = $length - 1; $index >= 0; $index-- ) {
		$digit = (int) $digits[ $index ];

		if ( $double ) {
			$digit *= 2;

			if ( $digit > 9 ) {
				$digit -= 9;
			}
		}

		$sum   += $digit;
		$double = ! $double;
	}

	return 0 === $sum % 10;
}

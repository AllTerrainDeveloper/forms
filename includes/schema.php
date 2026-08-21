<?php
/**
 * The form schema: one JSON document per form.
 *
 * A schema is the whole form -- its fields, its settings, its notifications, its
 * confirmations and its post-submit actions. It is read and written whole,
 * because a form is only ever edited whole, and a partial write is always a bug.
 *
 * Everything that enters this file is untrusted. The builder posts a schema, an
 * import posts a schema, a template posts a schema; `atf_normalize_schema()` is
 * the single door all three come through, and after it the rest of the plugin
 * can assume every key exists and every value is the type it claims to be. That
 * assumption is what lets the renderer and the validator be short.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The current schema version.
 *
 * Stamped into every saved schema so a future release can migrate an old one
 * forward rather than guessing at its shape.
 *
 * @since 0.1.0
 */
const ATF_SCHEMA_VERSION = 1;

/**
 * An empty, valid schema.
 *
 * Every default here is the answer to "what should this do if nobody touches
 * it", and several are deliberate opinions rather than neutral zeroes. Entries
 * are stored, because a form that silently kept nothing would be discovered the
 * day somebody needed one. The honeypot and the time trap are on, because spam
 * arrives whether or not anyone remembered to switch them on. IP addresses are
 * stored but the retention default is 0 (keep forever) -- a plugin should not
 * quietly delete somebody's data on a schedule they never chose.
 *
 * @since 0.1.0
 *
 * @return array A complete schema with no fields.
 */
function atf_default_schema() {
	$schema = array(
		'version'       => ATF_SCHEMA_VERSION,
		'fields'        => array(),
		'settings'      => array(
			'theme'          => 'clean',
			'themeOverrides' => array(),
			'submitLabel'    => __( 'Send', 'allterrain-forms' ),
			'labelPosition'  => '',
			'ajax'           => true,
			'progressBar'    => 'steps',
			'requireLogin'   => false,
			'roles'          => array(),
			'loginMessage'   => '',
			'schedule'       => array(
				'start'   => '',
				'end'     => '',
				'message' => '',
			),
			'limit'          => array(
				'total'   => 0,
				'perUser' => 0,
				'message' => '',
			),
			'spam'           => array(
				'honeypot'  => true,
				'timeTrap'  => 3,
				'rateLimit' => 10,
				'blocklist' => '',
				'akismet'   => true,
				'challenge' => false,
			),
			'storage'        => array(
				'entries'   => true,
				'ip'        => true,
				'userAgent' => true,
				'retention' => 0,
				'anonymise' => false,
			),
			'resume'         => array(
				'enabled' => false,
				'days'    => 30,
			),
			'quiz'           => array(
				'enabled'   => false,
				// A float on purpose: the default's type is what the setting
				// is coerced to, and a pass mark of 62.5% is legitimate.
				'passMark'  => 0.0,
				'showScore' => true,
			),
		),
		'notifications' => array(),
		'confirmations' => array(),
		'actions'       => array(),
	);

	/**
	 * Filters the schema a brand-new form starts from.
	 *
	 * A site that always wants a particular theme, or always wants IP storage
	 * off, sets it here once rather than on every form.
	 *
	 * @since 0.1.0
	 *
	 * @param array $schema The default schema.
	 */
	return apply_filters( 'atf_default_schema', $schema );
}

/**
 * Normalises anything schema-shaped into a schema.
 *
 * The only entry point. Accepts a JSON string or an array, fills every missing
 * key from the defaults, drops anything unrecognised, and guarantees that every
 * field has a unique id -- which matters more than it looks, because field ids
 * are what conditional logic, calculations and merge tags all reference, and a
 * duplicate id makes every one of them address the wrong field.
 *
 * @since 0.1.0
 *
 * @param mixed $raw A JSON string, an array, or anything else.
 * @return array A complete, valid schema.
 */
function atf_normalize_schema( $raw ) {
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		$raw     = is_array( $decoded ) ? $decoded : array();
	}

	if ( ! is_array( $raw ) ) {
		$raw = array();
	}

	$defaults = atf_default_schema();

	$schema = array(
		'version'       => ATF_SCHEMA_VERSION,
		'fields'        => array(),
		'settings'      => atf_normalize_settings( isset( $raw['settings'] ) ? $raw['settings'] : array(), $defaults['settings'] ),
		'notifications' => atf_normalize_notifications( isset( $raw['notifications'] ) ? $raw['notifications'] : array() ),
		'confirmations' => atf_normalize_confirmations( isset( $raw['confirmations'] ) ? $raw['confirmations'] : array() ),
		'actions'       => atf_normalize_actions( isset( $raw['actions'] ) ? $raw['actions'] : array() ),
	);

	$fields = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array();
	$seen   = array();

	foreach ( $fields as $field ) {
		$normalised = atf_normalize_field( $field, $seen );

		if ( $normalised ) {
			$seen[]             = $normalised['id'];
			$schema['fields'][] = $normalised;
		}
	}

	/**
	 * Filters a schema after normalisation.
	 *
	 * Runs on every read and every write, so anything added here is present
	 * everywhere -- the renderer, the validator, the builder and the export.
	 *
	 * @since 0.1.0
	 *
	 * @param array $schema The normalised schema.
	 * @param mixed $raw    What was passed in.
	 */
	return apply_filters( 'atf_normalize_schema', $schema, $raw );
}

/**
 * Normalises one field.
 *
 * Type-specific settings are merged from the field type's own `settings`
 * default, so a registration that adds a setting gets it on every existing field
 * of that type without a migration.
 *
 * @since 0.1.0
 *
 * @param mixed    $raw  The raw field.
 * @param string[] $seen Ids already used in this schema, so a duplicate can be re-issued.
 * @return array|null The field, or null when it is unusable.
 */
function atf_normalize_field( $raw, $seen = array() ) {
	if ( ! is_array( $raw ) ) {
		return null;
	}

	$type = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : '';

	if ( '' === $type ) {
		return null;
	}

	$definition = atf_get_field_type( $type );

	$id = isset( $raw['id'] ) ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $raw['id'] ) : '';

	// A missing or duplicated id is re-issued rather than rejected. An import
	// from another site, or a hand-written JSON file, routinely has neither --
	// and dropping the field would lose the question rather than fix the id.
	if ( '' === $id || in_array( $id, $seen, true ) ) {
		$id = atf_generate_field_id( $seen );
	}

	$field = array(
		'id'          => $id,
		'type'        => $type,
		'label'       => isset( $raw['label'] ) ? sanitize_text_field( (string) $raw['label'] ) : '',
		'placeholder' => isset( $raw['placeholder'] ) ? sanitize_text_field( (string) $raw['placeholder'] ) : '',
		'hint'        => isset( $raw['hint'] ) ? wp_kses_post( (string) $raw['hint'] ) : '',
		'required'    => ! empty( $raw['required'] ),
		'width'       => atf_normalize_width( isset( $raw['width'] ) ? $raw['width'] : 'full' ),
		'cssClass'    => isset( $raw['cssClass'] ) ? sanitize_html_class( (string) $raw['cssClass'] ) : '',
		'default'     => isset( $raw['default'] ) ? $raw['default'] : '',
		'choices'     => atf_normalize_choices( isset( $raw['choices'] ) ? $raw['choices'] : array() ),
		'logic'       => atf_normalize_logic( isset( $raw['logic'] ) ? $raw['logic'] : array() ),
		'messages'    => atf_normalize_messages( isset( $raw['messages'] ) ? $raw['messages'] : array() ),
		'prefill'     => isset( $raw['prefill'] ) ? sanitize_text_field( (string) $raw['prefill'] ) : '',
	);

	// The default value is sanitised through the field's own type, so a default
	// for a checkbox group is an array and a default for a number is a number.
	$field['default'] = atf_sanitize_field_value( $field['default'], $field );

	$settings = $definition && is_array( $definition['settings'] ) ? $definition['settings'] : array();

	foreach ( $settings as $key => $fallback ) {
		if ( ! array_key_exists( $key, $raw ) ) {
			$field[ $key ] = $fallback;
			continue;
		}

		// `content` and `consentText` may legitimately carry markup, and
		// `wp_kses_post()` below is the authority on what survives in them --
		// flattening them to a single text line here would strip the markup
		// before it ever reached that check. They are only coerced to the
		// string an array can never be.
		if ( 'content' === $key || 'consentText' === $key ) {
			$field[ $key ] = is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
			continue;
		}

		// Everything else is typed against its declared default, so an
		// imported schema cannot put an array where `strtotime()` or a
		// renderer will later assume a scalar.
		$field[ $key ] = atf_coerce_setting( $raw[ $key ], $fallback );
	}

	// Validation bounds are common enough to live on the field rather than in
	// every type's settings, and they are all optional: an empty string means
	// "no bound", which is why they are not cast to int here.
	foreach ( array( 'min', 'max', 'step', 'minlength', 'maxlength', 'pattern', 'minDate', 'maxDate', 'minTime', 'maxTime', 'minChoices', 'maxChoices' ) as $key ) {
		if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
			$field[ $key ] = is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		}
	}

	// A named answer shape -- "an email address", "a ZIP code" -- enforced by
	// `atf_validate_preset()`. The sentinel `custom` means "use `pattern`",
	// which the custom-rule builder writes alongside this flag.
	if ( isset( $raw['validation'] ) && is_scalar( $raw['validation'] ) && '' !== $raw['validation'] ) {
		$field['validation'] = sanitize_key( (string) $raw['validation'] );
	}

	// The custom-rule builder's own notes: the blocks that compiled into
	// `pattern`, kept only so reopening the editor can restore them. Decoded
	// and re-encoded through a whitelist, so an imported schema cannot smuggle
	// arbitrary structure through what is otherwise an opaque blob.
	if ( isset( $raw['validationRecipe'] ) && is_string( $raw['validationRecipe'] ) && '' !== $raw['validationRecipe'] ) {
		$recipe = atf_normalize_validation_recipe( $raw['validationRecipe'] );

		if ( '' !== $recipe ) {
			$field['validationRecipe'] = $recipe;
		}
	}

	foreach ( array( 'unique', 'confirm', 'other', 'inline', 'multiple', 'searchable', 'counter' ) as $flag ) {
		if ( ! empty( $raw[ $flag ] ) ) {
			$field[ $flag ] = true;
		}
	}

	// An HTML block is the one place arbitrary markup is the point. `wp_kses_post()`
	// rather than raw storage, because the person building a form is not
	// necessarily the person who may post unfiltered HTML, and the capability to
	// edit forms must not become the capability to run script on the front end.
	if ( isset( $field['content'] ) ) {
		$field['content'] = wp_kses_post( (string) $field['content'] );
	}

	// Consent text is rendered through `wp_kses_post()` too -- it routinely
	// links a privacy policy -- so it gets the same treatment as `content`.
	if ( isset( $field['consentText'] ) ) {
		$field['consentText'] = wp_kses_post( (string) $field['consentText'] );
	}

	// A repeater's sub-fields are fields, so they recurse through exactly this
	// function -- which is what makes a number inside a repeater behave like a
	// number, rather than like whatever the repeater felt like doing.
	if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
		$sub_seen = array();
		$subs     = array();

		foreach ( $field['fields'] as $sub ) {
			$normalised = atf_normalize_field( $sub, $sub_seen );

			if ( $normalised ) {
				$sub_seen[] = $normalised['id'];
				$subs[]     = $normalised;
			}
		}

		$field['fields'] = $subs;
	}

	$field = atf_seed_field_defaults( $field, $definition );

	/**
	 * Filters one normalised field.
	 *
	 * @since 0.1.0
	 *
	 * @param array $field The normalised field.
	 * @param mixed $raw   What was passed in.
	 */
	return apply_filters( 'atf_normalize_field', $field, $raw );
}

/**
 * Gives a field the sub-list it cannot function without.
 *
 * Some field types are not a control on their own — they are a control *per
 * entry* in a list. A radio group with no choices, a Likert matrix with no
 * statements and a repeater with no sub-fields all render the same thing: a
 * legend, and nothing to answer. The visitor sees a question with no way to
 * respond to it, and if it is marked required the form cannot be submitted at
 * all.
 *
 * The builder never produces one, because it seeds two choices the moment a
 * field is dragged in. Everything else can: Import, the REST API, a schema
 * hand-written for a migration, a template from another site. Those are exactly
 * the paths where nobody is watching the form render, so the failure surfaces on
 * the live site rather than in the editor.
 *
 * Seeding here rather than in the renderer keeps one answer to "what is in this
 * schema" — the stored schema is complete, so the builder opens it showing the
 * same two choices the front end renders, instead of an empty list that somehow
 * displays options.
 *
 * @since 0.1.0
 *
 * @param array      $field      A normalised field.
 * @param array|null $definition Its registered type, or null for an unknown type.
 * @return array The field, with any missing sub-list filled in.
 */
function atf_seed_field_defaults( $field, $definition ) {
	if ( ! $definition ) {
		return $field;
	}

	if ( ! empty( $definition['choices'] ) && empty( $field['choices'] ) ) {
		$field['choices'] = atf_normalize_choices(
			array(
				array(
					'label' => __( 'First choice', 'allterrain-forms' ),
					'value' => 'first',
				),
				array(
					'label' => __( 'Second choice', 'allterrain-forms' ),
					'value' => 'second',
				),
			)
		);
	}

	// A Likert matrix needs both axes: the choices above are the shared answer
	// scale, and these are the statements being rated against it.
	if ( 'likert' === $field['type'] && empty( $field['rows'] ) ) {
		$field['rows'] = array(
			array(
				'key'   => 'r1',
				'label' => __( 'First statement', 'allterrain-forms' ),
			),
			array(
				'key'   => 'r2',
				'label' => __( 'Second statement', 'allterrain-forms' ),
			),
		);
	}

	// One text box, so a repeater built by an import is a repeater of something.
	if ( 'repeater' === $field['type'] && empty( $field['fields'] ) ) {
		$field['fields'] = array(
			atf_normalize_field(
				array(
					'id'    => $field['id'] . '_1',
					'type'  => 'text',
					'label' => __( 'Item', 'allterrain-forms' ),
				),
				array()
			),
		);
	}

	return $field;
}

/**
 * Normalises a field's width to one the grid understands.
 *
 * @since 0.1.0
 *
 * @param mixed $width Requested width.
 * @return string One of full|half|third|two-thirds|quarter.
 */
function atf_normalize_width( $width ) {
	$allowed = array( 'full', 'half', 'third', 'two-thirds', 'quarter' );
	$width   = is_scalar( $width ) ? (string) $width : 'full';

	return in_array( $width, $allowed, true ) ? $width : 'full';
}

/**
 * Normalises a choice list.
 *
 * A choice with a label but no value takes the label as its value, which is what
 * somebody typing a list into the builder means and saves them a column.
 *
 * @since 0.1.0
 *
 * @param mixed $raw Raw choices.
 * @return array[] Normalised choices.
 */
function atf_normalize_choices( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$choices = array();

	foreach ( $raw as $choice ) {
		// A bare string is a perfectly reasonable way to write a choice list,
		// and every import format in the wild uses it somewhere.
		if ( is_scalar( $choice ) ) {
			$choice = array( 'label' => (string) $choice );
		}

		if ( ! is_array( $choice ) ) {
			continue;
		}

		$label = isset( $choice['label'] ) ? sanitize_text_field( (string) $choice['label'] ) : '';
		$value = isset( $choice['value'] ) && '' !== $choice['value'] ? sanitize_text_field( (string) $choice['value'] ) : $label;

		if ( '' === $label && '' === $value ) {
			continue;
		}

		$normalised = array(
			'label'    => $label,
			'value'    => $value,
			'selected' => ! empty( $choice['selected'] ),
		);

		if ( ! empty( $choice['image'] ) ) {
			$normalised['image'] = absint( $choice['image'] );
		}

		if ( isset( $choice['points'] ) && '' !== $choice['points'] ) {
			$normalised['points'] = (float) $choice['points'];
		}

		if ( isset( $choice['price'] ) && '' !== $choice['price'] ) {
			$normalised['price'] = (float) $choice['price'];
		}

		$choices[] = $normalised;
	}

	return $choices;
}

/**
 * Normalises a conditional-logic block.
 *
 * The shape is deliberately the same everywhere logic appears -- on a field, on
 * a notification, on a confirmation -- so `atf_logic_passes()` is one function
 * rather than three.
 *
 * @since 0.1.0
 *
 * @param mixed $raw Raw logic.
 * @return array Normalised logic.
 */
function atf_normalize_logic( $raw ) {
	$logic = array(
		'enabled' => false,
		'action'  => 'show',
		'match'   => 'all',
		'rules'   => array(),
	);

	if ( ! is_array( $raw ) ) {
		return $logic;
	}

	$logic['enabled'] = ! empty( $raw['enabled'] );
	$logic['action']  = isset( $raw['action'] ) && 'hide' === $raw['action'] ? 'hide' : 'show';
	$logic['match']   = isset( $raw['match'] ) && 'any' === $raw['match'] ? 'any' : 'all';

	$rules = isset( $raw['rules'] ) && is_array( $raw['rules'] ) ? $raw['rules'] : array();

	foreach ( $rules as $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
			continue;
		}

		$logic['rules'][] = array(
			'field'    => preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $rule['field'] ),
			'operator' => atf_normalize_operator( isset( $rule['operator'] ) ? $rule['operator'] : 'is' ),
			'value'    => isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '',
		);
	}

	return $logic;
}

/**
 * Constrains a logic operator to one the evaluator implements.
 *
 * An unrecognised operator falls back to `is` rather than being dropped: dropping
 * the rule would silently widen the condition, which is the failure mode that
 * shows a field to everybody when it was meant for one answer.
 *
 * @since 0.1.0
 *
 * @param mixed $operator Requested operator.
 * @return string A supported operator.
 */
function atf_normalize_operator( $operator ) {
	$allowed = array(
		'is',
		'is_not',
		'contains',
		'not_contains',
		'starts_with',
		'ends_with',
		'greater',
		'less',
		'greater_equal',
		'less_equal',
		'empty',
		'not_empty',
	);

	$operator = is_scalar( $operator ) ? (string) $operator : 'is';

	return in_array( $operator, $allowed, true ) ? $operator : 'is';
}

/**
 * Normalises per-field message overrides.
 *
 * @since 0.1.0
 *
 * @param mixed $raw Raw messages.
 * @return array<string, string>
 */
function atf_normalize_messages( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$messages = array();

	foreach ( array( 'required', 'invalid', 'min', 'max', 'unique' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
			$messages[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
		}
	}

	return $messages;
}

/**
 * Coerces one raw setting to the type its declared default has.
 *
 * A schema arrives as JSON somebody may have written by hand, and a setting
 * the code will later hand to `strtotime()` or `wp_kses_post()` has to
 * actually be a string by then -- on PHP 8 both fatal on an array. The
 * default's own type is the declaration: a boolean default makes the setting
 * a boolean, an integer default an integer, a string default a sanitised
 * single line, and an array default keeps arrays, whose contents are
 * normalised by whichever code owns them. A value of the wrong shape falls
 * back to the default rather than being guessed at.
 *
 * @since 0.1.0
 *
 * @param mixed $value    The raw value.
 * @param mixed $fallback The declared default.
 * @return mixed The value, in the default's type.
 */
function atf_coerce_setting( $value, $fallback ) {
	if ( is_array( $fallback ) ) {
		return is_array( $value ) ? $value : $fallback;
	}

	if ( is_bool( $fallback ) ) {
		return (bool) $value;
	}

	if ( is_int( $fallback ) ) {
		return is_scalar( $value ) ? (int) $value : $fallback;
	}

	if ( is_float( $fallback ) ) {
		return is_scalar( $value ) ? (float) $value : $fallback;
	}

	return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
}

/**
 * Normalises the settings block against its defaults.
 *
 * Recursive one level deep, because the settings that group -- spam, storage,
 * schedule -- are maps, and a caller sending only `spam.honeypot` must not lose
 * `spam.timeTrap`.
 *
 * @since 0.1.0
 *
 * @param mixed $raw      Raw settings.
 * @param array $defaults The default settings.
 * @return array
 */
function atf_normalize_settings( $raw, $defaults ) {
	if ( ! is_array( $raw ) ) {
		return $defaults;
	}

	$settings = $defaults;

	foreach ( $defaults as $key => $default ) {
		if ( ! array_key_exists( $key, $raw ) ) {
			continue;
		}

		if ( is_array( $default ) ) {
			if ( ! is_array( $raw[ $key ] ) ) {
				continue;
			}

			// Each value inside a nested map is coerced against its own
			// default, so `schedule.start` cannot arrive as an array and ride
			// through to `strtotime()` untyped. A key the defaults do not
			// declare is kept as it came, for filters that add their own.
			$merged = $default;

			foreach ( $raw[ $key ] as $sub_key => $sub_value ) {
				$merged[ $sub_key ] = array_key_exists( $sub_key, $default )
					? atf_coerce_setting( $sub_value, $default[ $sub_key ] )
					: $sub_value;
			}

			$settings[ $key ] = $merged;
			continue;
		}

		$settings[ $key ] = atf_coerce_setting( $raw[ $key ], $default );
	}

	// The blocklist is newline-separated -- one term per line -- and the text
	// sanitiser above collapses newlines. It is re-read here with its line
	// structure intact; `sanitize_textarea_field()` strips the same badness
	// but keeps the newlines the matcher splits on.
	if ( isset( $raw['spam'] ) && is_array( $raw['spam'] ) && isset( $raw['spam']['blocklist'] ) && is_scalar( $raw['spam']['blocklist'] ) ) {
		$settings['spam']['blocklist'] = sanitize_textarea_field( (string) $raw['spam']['blocklist'] );
	}

	// Types inside the nested maps, after the merge above has placed them.
	$settings['spam']['honeypot']  = ! empty( $settings['spam']['honeypot'] );
	$settings['spam']['akismet']   = ! empty( $settings['spam']['akismet'] );
	$settings['spam']['challenge'] = ! empty( $settings['spam']['challenge'] );
	$settings['spam']['timeTrap']  = max( 0, (int) $settings['spam']['timeTrap'] );
	$settings['spam']['rateLimit'] = max( 0, (int) $settings['spam']['rateLimit'] );

	$settings['storage']['entries']   = ! empty( $settings['storage']['entries'] );
	$settings['storage']['ip']        = ! empty( $settings['storage']['ip'] );
	$settings['storage']['userAgent'] = ! empty( $settings['storage']['userAgent'] );
	$settings['storage']['anonymise'] = ! empty( $settings['storage']['anonymise'] );
	$settings['storage']['retention'] = max( 0, (int) $settings['storage']['retention'] );

	$settings['limit']['total']   = max( 0, (int) $settings['limit']['total'] );
	$settings['limit']['perUser'] = max( 0, (int) $settings['limit']['perUser'] );

	$settings['resume']['enabled'] = ! empty( $settings['resume']['enabled'] );
	$settings['resume']['days']    = max( 1, (int) $settings['resume']['days'] );

	$settings['quiz']['enabled']   = ! empty( $settings['quiz']['enabled'] );
	$settings['quiz']['showScore'] = ! empty( $settings['quiz']['showScore'] );
	$settings['quiz']['passMark']  = max( 0, (float) $settings['quiz']['passMark'] );

	$settings['roles'] = is_array( $settings['roles'] ) ? array_map( 'sanitize_key', $settings['roles'] ) : array();

	return $settings;
}

/**
 * Normalises the notification list.
 *
 * @since 0.1.0
 *
 * @param mixed $raw Raw notifications.
 * @return array[]
 */
function atf_normalize_notifications( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$notifications = array();

	foreach ( $raw as $index => $notification ) {
		if ( ! is_array( $notification ) ) {
			continue;
		}

		$notifications[] = array(
			'id'          => isset( $notification['id'] ) ? sanitize_key( $notification['id'] ) : 'n' . ( $index + 1 ),
			'enabled'     => ! isset( $notification['enabled'] ) || ! empty( $notification['enabled'] ),
			'name'        => isset( $notification['name'] ) ? sanitize_text_field( (string) $notification['name'] ) : __( 'Notification', 'allterrain-forms' ),
			// Addresses keep their merge tags, so `{field:3}` survives to be
			// resolved at send time. Sanitising to an e-mail address here would
			// throw the tag away and leave an empty To line.
			'to'          => isset( $notification['to'] ) ? sanitize_text_field( (string) $notification['to'] ) : '',
			'cc'          => isset( $notification['cc'] ) ? sanitize_text_field( (string) $notification['cc'] ) : '',
			'bcc'         => isset( $notification['bcc'] ) ? sanitize_text_field( (string) $notification['bcc'] ) : '',
			'replyTo'     => isset( $notification['replyTo'] ) ? sanitize_text_field( (string) $notification['replyTo'] ) : '',
			'fromName'    => isset( $notification['fromName'] ) ? sanitize_text_field( (string) $notification['fromName'] ) : '',
			'fromEmail'   => isset( $notification['fromEmail'] ) ? sanitize_text_field( (string) $notification['fromEmail'] ) : '',
			'subject'     => isset( $notification['subject'] ) ? sanitize_text_field( (string) $notification['subject'] ) : '',
			'message'     => isset( $notification['message'] ) ? wp_kses_post( (string) $notification['message'] ) : '',
			'attachFiles' => ! empty( $notification['attachFiles'] ),
			'logic'       => atf_normalize_logic( isset( $notification['logic'] ) ? $notification['logic'] : array() ),
		);
	}

	return $notifications;
}

/**
 * Normalises the confirmation list.
 *
 * @since 0.1.0
 *
 * @param mixed $raw Raw confirmations.
 * @return array[]
 */
function atf_normalize_confirmations( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$confirmations = array();

	foreach ( $raw as $index => $confirmation ) {
		if ( ! is_array( $confirmation ) ) {
			continue;
		}

		$type = isset( $confirmation['type'] ) ? sanitize_key( $confirmation['type'] ) : 'message';

		$confirmations[] = array(
			'id'      => isset( $confirmation['id'] ) ? sanitize_key( $confirmation['id'] ) : 'c' . ( $index + 1 ),
			'enabled' => ! isset( $confirmation['enabled'] ) || ! empty( $confirmation['enabled'] ),
			'name'    => isset( $confirmation['name'] ) ? sanitize_text_field( (string) $confirmation['name'] ) : __( 'Confirmation', 'allterrain-forms' ),
			'type'    => in_array( $type, array( 'message', 'redirect', 'page' ), true ) ? $type : 'message',
			'message' => isset( $confirmation['message'] ) ? wp_kses_post( (string) $confirmation['message'] ) : '',
			'url'     => isset( $confirmation['url'] ) ? sanitize_text_field( (string) $confirmation['url'] ) : '',
			'pageId'  => isset( $confirmation['pageId'] ) ? absint( $confirmation['pageId'] ) : 0,
			'query'   => isset( $confirmation['query'] ) ? sanitize_text_field( (string) $confirmation['query'] ) : '',
			'logic'   => atf_normalize_logic( isset( $confirmation['logic'] ) ? $confirmation['logic'] : array() ),
		);
	}

	return $confirmations;
}

/**
 * Normalises the post-submit action list.
 *
 * @since 0.1.0
 *
 * @param mixed $raw Raw actions.
 * @return array[]
 */
function atf_normalize_actions( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$actions = array();

	foreach ( $raw as $index => $action ) {
		if ( ! is_array( $action ) || empty( $action['type'] ) ) {
			continue;
		}

		$actions[] = array(
			'id'       => isset( $action['id'] ) ? sanitize_key( $action['id'] ) : 'a' . ( $index + 1 ),
			'type'     => sanitize_key( $action['type'] ),
			'enabled'  => ! isset( $action['enabled'] ) || ! empty( $action['enabled'] ),
			'logic'    => atf_normalize_logic( isset( $action['logic'] ) ? $action['logic'] : array() ),
			// Action settings vary per action type, and the action's own handler
			// is what knows their shape. Kept as-is here and sanitised there,
			// which is the only place with enough context to do it properly.
			'settings' => isset( $action['settings'] ) && is_array( $action['settings'] ) ? $action['settings'] : array(),
		);
	}

	return $actions;
}

/**
 * Mints a field id not already in use.
 *
 * Short and readable (`f7`) rather than a UUID, because these ids are typed by
 * hand into merge tags and calculation formulas, and `{field:f7}` is something a
 * person can write where `{field:8f3c-…}` is not.
 *
 * @since 0.1.0
 *
 * @param string[] $seen Ids already in use.
 * @return string A fresh id.
 */
function atf_generate_field_id( $seen = array() ) {
	$index = count( $seen ) + 1;

	while ( in_array( 'f' . $index, $seen, true ) ) {
		++$index;
	}

	return 'f' . $index;
}

/**
 * Reads a form's schema.
 *
 * @since 0.1.0
 *
 * @param int|WP_Post $form The form.
 * @return array The normalised schema. An unknown form gets the default one.
 */
function atf_get_form_schema( $form ) {
	$form_id = is_object( $form ) ? $form->ID : absint( $form );

	if ( ! $form_id ) {
		return atf_normalize_schema( array() );
	}

	return atf_normalize_schema( get_post_meta( $form_id, ATF_META_SCHEMA, true ) );
}

/**
 * Writes a form's schema.
 *
 * Stored as JSON rather than a serialised array so the value stays legible in
 * the database, survives a `wp db export`, and can be diffed between revisions
 * by eye. `wp_slash()` because `update_post_meta()` runs the value through
 * `wp_unslash()` on the way in, and JSON is full of the quotes that would eat.
 *
 * @since 0.1.0
 *
 * @param int   $form_id The form.
 * @param array $schema  A schema, normalised or not.
 * @return array The normalised schema that was stored.
 */
function atf_save_form_schema( $form_id, $schema ) {
	$form_id = absint( $form_id );
	$schema  = atf_normalize_schema( $schema );

	$json = wp_json_encode( $schema );

	update_post_meta( $form_id, ATF_META_SCHEMA, wp_slash( $json ) );

	/**
	 * Fires after a form's schema is saved.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $form_id The form.
	 * @param array $schema  The normalised schema that was stored.
	 */
	do_action( 'atf_schema_saved', $form_id, $schema );

	return $schema;
}

/**
 * Finds one field in a schema by id.
 *
 * Searches inside repeaters too, because a merge tag or a logic rule can name a
 * sub-field and the caller should not have to know where it lives.
 *
 * @since 0.1.0
 *
 * @param array  $schema   The schema.
 * @param string $field_id The id to find.
 * @return array|null The field, or null.
 */
function atf_find_field( $schema, $field_id ) {
	$fields = isset( $schema['fields'] ) ? $schema['fields'] : array();

	foreach ( $fields as $field ) {
		if ( $field['id'] === $field_id ) {
			return $field;
		}

		if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
			foreach ( $field['fields'] as $sub ) {
				if ( $sub['id'] === $field_id ) {
					return $sub;
				}
			}
		}
	}

	return null;
}

/**
 * Every field that contributes a value, top level only.
 *
 * Repeater sub-fields are excluded because their values live nested inside the
 * repeater's own value, and a caller walking "the fields with values" wants the
 * repeater once, not its five sub-fields as though they were separate answers.
 *
 * @since 0.1.0
 *
 * @param array $schema The schema.
 * @return array[] Input fields.
 */
function atf_input_fields( $schema ) {
	$fields = isset( $schema['fields'] ) ? $schema['fields'] : array();

	return array_values( array_filter( $fields, 'atf_field_is_input' ) );
}

/**
 * Splits a schema's fields into pages at each page break.
 *
 * Always returns at least one page, so a caller never has to special-case the
 * single-page form -- which is most of them, and would otherwise be the branch
 * that gets tested least and breaks most.
 *
 * @since 0.1.0
 *
 * @param array $schema The schema.
 * @return array[] { fields: array[], break: array|null } per page.
 */
function atf_schema_pages( $schema ) {
	$fields = isset( $schema['fields'] ) ? $schema['fields'] : array();
	$pages  = array();
	$page   = array(
		'fields' => array(),
		'break'  => null,
	);

	foreach ( $fields as $field ) {
		if ( 'page_break' === $field['type'] ) {
			// The break belongs to the page it closes, because its `nextLabel`
			// is the label on *that* page's forward button.
			$page['break'] = $field;
			$pages[]       = $page;
			$page          = array(
				'fields' => array(),
				'break'  => null,
			);
			continue;
		}

		$page['fields'][] = $field;
	}

	$pages[] = $page;

	return $pages;
}

/**
 * Whether a form is split across more than one page.
 *
 * @since 0.1.0
 *
 * @param array $schema The schema.
 * @return bool
 */
function atf_is_multi_page( $schema ) {
	return count( atf_schema_pages( $schema ) ) > 1;
}

/**
 * Normalises the custom-rule builder's recipe blob.
 *
 * The recipe is builder state, not an enforced rule -- enforcement happens
 * through the `pattern` it compiled into -- but it is still stored input, so
 * it is rebuilt from a whitelist of known keys rather than stored as
 * received.
 *
 * @since 0.2.0
 *
 * @param string $json The raw recipe JSON.
 * @return string The normalised JSON, or an empty string when unusable.
 */
function atf_normalize_validation_recipe( $json ) {
	$raw = json_decode( $json, true );

	if ( ! is_array( $raw ) ) {
		return '';
	}

	$recipe = array(
		'mode' => isset( $raw['mode'] ) && 'regex' === $raw['mode'] ? 'regex' : 'blocks',
	);

	foreach ( array( 'starts', 'ends', 'contains', 'notContains', 'minLen', 'maxLen', 'regex', 'message' ) as $key ) {
		$recipe[ $key ] = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
	}

	$allowed_chars   = array( 'letters', 'numbers', 'spaces', 'punctuation', 'symbols' );
	$recipe['chars'] = array_values( array_intersect( $allowed_chars, array_map( 'strval', isset( $raw['chars'] ) && is_array( $raw['chars'] ) ? $raw['chars'] : array() ) ) );
	$recipe['tests'] = array();

	if ( isset( $raw['tests'] ) && is_array( $raw['tests'] ) ) {
		foreach ( array_slice( array_values( $raw['tests'] ), 0, 10 ) as $test ) {
			$recipe['tests'][] = is_scalar( $test ) ? sanitize_text_field( (string) $test ) : '';
		}
	}

	return (string) wp_json_encode( $recipe );
}

<?php
/**
 * The field-type registry.
 *
 * Every field in every form -- the ones this plugin ships and the ones a third
 * party adds -- is one registration in this table. Nothing downstream special-cases
 * a type by name: the renderer asks the registry how to draw it, the validator
 * asks how to check it, the CSV exporter asks how to flatten it. A new field type
 * is `atf_register_field_type()` and nothing else.
 *
 * That discipline is what makes the palette extensible. A plugin that wants a
 * "Stripe payment" field or a "postcode lookup" field registers one and it
 * appears in the builder's palette, drags like every other field, saves into the
 * same schema, validates through the same pipeline, and exports to the same CSV.
 *
 * @package AllTerrain_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * The registered field types, keyed by type slug.
 *
 * A function-static rather than a global so nothing can reach in and mutate it
 * without going through the registration functions, which normalise.
 *
 * @since 0.1.0
 *
 * @param array<string, array>|null $set Internal. Replaces the whole table.
 * @return array<string, array> The registry.
 */
function &atf_field_type_store( $set = null ) {
	static $types = array();

	if ( null !== $set ) {
		$types = $set;
	}

	return $types;
}

/**
 * Registers a field type.
 *
 * Every argument but `label` has a sensible default, so the smallest useful
 * registration is a label and a group. The heavy callbacks -- `sanitize`,
 * `validate`, `format` -- all fall back to implementations keyed off `value`,
 * which is why a type that stores a string needs none of them.
 *
 * @since 0.1.0
 *
 * @param string $type Type slug, e.g. `email`. Lowercase, `[a-z0-9_-]`.
 * @param array  $args {
 *     Type definition.
 *
 *     @type string   $label       Human label shown in the palette. Required.
 *     @type string   $group       Palette group: text|choice|datetime|advanced|layout|special.
 *     @type string   $icon        Dashicon slug for the palette tile.
 *     @type string   $description One line shown under the label in the palette.
 *     @type bool     $input       Whether it contributes a value to the entry. Layout fields do not.
 *     @type string   $value       Value shape: string|text|number|bool|array|file|files|object.
 *     @type bool     $choices     Whether the field carries a list of choices.
 *     @type string[] $supports    Which common settings the inspector should offer.
 *     @type array    $settings    Default settings merged into every new field of this type.
 *     @type callable $sanitize    ( $raw, $field ) => mixed. Defaults by `value`.
 *     @type callable $validate    ( $value, $field, $context ) => true|WP_Error.
 *     @type callable $render      ( $field, $value, $context ) => string of HTML.
 *     @type callable $format      ( $value, $field, $context ) => string for e-mail/CSV/tables.
 *     @type int      $position    Sort order within its palette group.
 * }
 * @return true|WP_Error True on success, `WP_Error` when the definition is unusable.
 */
function atf_register_field_type( $type, $args = array() ) {
	$type = sanitize_key( $type );

	if ( '' === $type ) {
		return new WP_Error( 'atf_invalid_type', __( 'A field type needs a slug.', 'allterrain-forms' ) );
	}

	if ( empty( $args['label'] ) ) {
		return new WP_Error(
			'atf_missing_label',
			/* translators: %s: field type slug. */
			sprintf( __( 'Field type "%s" needs a label.', 'allterrain-forms' ), $type )
		);
	}

	$definition = wp_parse_args(
		$args,
		array(
			'label'       => '',
			'group'       => 'advanced',
			'icon'        => 'dashicons-forms',
			'description' => '',
			'input'       => true,
			'value'       => 'string',
			'choices'     => false,
			'supports'    => array( 'label', 'hint', 'required', 'width', 'css', 'logic' ),
			'settings'    => array(),
			'sanitize'    => null,
			'validate'    => null,
			'render'      => null,
			'format'      => null,
			'position'    => 50,
		)
	);

	$definition['type'] = $type;

	/**
	 * Filters a field type's definition as it is registered.
	 *
	 * Lets a site add a setting to a built-in type -- a character counter on
	 * every textarea, say -- without unregistering and re-registering it.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $definition The normalised definition.
	 * @param string $type       Type slug.
	 */
	$definition = apply_filters( 'atf_register_field_type', $definition, $type );

	$types          = &atf_field_type_store();
	$types[ $type ] = $definition;

	return true;
}

/**
 * Removes a field type from the palette.
 *
 * Existing fields of the type keep their stored values -- unregistering hides a
 * type from the builder, it does not rewrite forms that already use it. Those
 * fields fall back to a plain text rendering and a warning in the builder, which
 * is a great deal better than silently dropping data somebody submitted.
 *
 * @since 0.1.0
 *
 * @param string $type Type slug.
 * @return bool True when a type was removed.
 */
function atf_unregister_field_type( $type ) {
	$types = &atf_field_type_store();
	$type  = sanitize_key( $type );

	if ( ! isset( $types[ $type ] ) ) {
		return false;
	}

	unset( $types[ $type ] );

	return true;
}

/**
 * Every registered field type.
 *
 * @since 0.1.0
 *
 * @return array<string, array> Type slug => definition, sorted by group then position.
 */
function atf_get_field_types() {
	atf_boot_field_types();

	$types = atf_field_type_store();

	uasort(
		$types,
		static function ( $a, $b ) {
			if ( $a['group'] !== $b['group'] ) {
				return strcmp( $a['group'], $b['group'] );
			}

			return $a['position'] <=> $b['position'];
		}
	);

	/**
	 * Filters the whole field-type table just before it is used.
	 *
	 * The place to remove types wholesale -- a site that never wants file
	 * uploads can drop the type here and it disappears from the palette, the
	 * validator and the renderer at once.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array> $types Type slug => definition.
	 */
	return apply_filters( 'atf_field_types', $types );
}

/**
 * One field type's definition.
 *
 * @since 0.1.0
 *
 * @param string $type Type slug.
 * @return array|null The definition, or null when nothing is registered under that slug.
 */
function atf_get_field_type( $type ) {
	$types = atf_get_field_types();
	$type  = sanitize_key( (string) $type );

	return isset( $types[ $type ] ) ? $types[ $type ] : null;
}

/**
 * Registers the built-in types, once, on first use.
 *
 * Lazily rather than on `init` because the registry is read by the REST routes,
 * the renderer and the CLI alike, and several of those run before or without
 * `init` in tests. A guard flag rather than `did_action()` so it holds in a unit
 * test that never bootstraps the action system.
 *
 * `atf_register_field_types` fires afterwards so a plugin can add to the palette
 * even if it loaded too late for `atf_loaded`.
 *
 * @since 0.1.0
 *
 * @return void
 */
function atf_boot_field_types() {
	static $booted = false;

	if ( $booted ) {
		return;
	}

	$booted = true;

	atf_register_builtin_field_types();

	/**
	 * Fires after the built-in field types are registered.
	 *
	 * The last safe moment to call `atf_register_field_type()`.
	 *
	 * @since 0.1.0
	 */
	do_action( 'atf_register_field_types' );
}

/**
 * The palette groups, in the order the builder shows them.
 *
 * @since 0.1.0
 *
 * @return array<string, string> Group slug => label.
 */
function atf_field_groups() {
	$groups = array(
		'text'     => __( 'Text', 'allterrain-forms' ),
		'choice'   => __( 'Choice', 'allterrain-forms' ),
		'datetime' => __( 'Date & time', 'allterrain-forms' ),
		'advanced' => __( 'Advanced', 'allterrain-forms' ),
		'layout'   => __( 'Layout', 'allterrain-forms' ),
		'special'  => __( 'Special', 'allterrain-forms' ),
	);

	/**
	 * Filters the palette groups.
	 *
	 * A plugin adding several related field types can give them a group of their
	 * own rather than scattering them through `advanced`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $groups Group slug => label.
	 */
	return apply_filters( 'atf_field_groups', $groups );
}

/**
 * Whether a field contributes a value to the entry.
 *
 * Layout fields -- headings, dividers, HTML blocks, page breaks -- do not, and
 * every loop that walks values rather than fields has to know the difference.
 *
 * @since 0.1.0
 *
 * @param array $field One field from a form schema.
 * @return bool True when the field produces a value.
 */
function atf_field_is_input( $field ) {
	$definition = atf_get_field_type( isset( $field['type'] ) ? $field['type'] : '' );

	// An unknown type is treated as an input. A form that used to have a
	// "postcode lookup" field whose plugin has been deactivated still has that
	// answer stored in every entry, and pretending the field was decorative
	// would drop the column from the export.
	if ( ! $definition ) {
		return true;
	}

	return ! empty( $definition['input'] );
}

/**
 * The value shape a field type stores.
 *
 * @since 0.1.0
 *
 * @param array $field One field from a form schema.
 * @return string One of string|text|number|bool|array|file|files|object.
 */
function atf_field_value_type( $field ) {
	$definition = atf_get_field_type( isset( $field['type'] ) ? $field['type'] : '' );

	return $definition ? (string) $definition['value'] : 'string';
}

/**
 * Runs a raw submitted value through its field type's sanitiser.
 *
 * The type's own `sanitize` callback wins; otherwise the shape in `value` picks
 * a default. Every path ends at a core `sanitize_*()` or `wp_kses`, and none of
 * them trusts the input's own type -- an array arriving where a string was
 * expected is coerced, not passed along, because it is exactly the shape a
 * request-forgery probe takes.
 *
 * @since 0.1.0
 *
 * @param mixed $raw   The value straight off the request.
 * @param array $field The field it was submitted for.
 * @return mixed The sanitised value, in the shape the field type declares.
 */
function atf_sanitize_field_value( $raw, $field ) {
	$definition = atf_get_field_type( isset( $field['type'] ) ? $field['type'] : '' );

	if ( $definition && is_callable( $definition['sanitize'] ) ) {
		return call_user_func( $definition['sanitize'], $raw, $field );
	}

	$shape = $definition ? $definition['value'] : 'string';

	switch ( $shape ) {
		case 'number':
			// An empty string is "not answered", which is different from zero.
			// Coercing it to 0 would make an optional number field look
			// answered, and a required one pass validation.
			if ( '' === $raw || null === $raw || is_array( $raw ) ) {
				return '';
			}

			return is_numeric( $raw ) ? 0 + $raw : '';

		case 'bool':
			return (bool) $raw && '0' !== $raw && 'false' !== $raw;

		case 'text':
			// Multi-line, so newlines survive. `sanitize_textarea_field()` strips
			// tags and control characters but keeps the line breaks a message
			// field exists to collect.
			return sanitize_textarea_field( (string) ( is_scalar( $raw ) ? $raw : '' ) );

		case 'array':
			$raw = is_array( $raw ) ? $raw : ( '' === $raw || null === $raw ? array() : array( $raw ) );

			return array_values(
				array_map(
					static function ( $item ) {
						return sanitize_text_field( (string) ( is_scalar( $item ) ? $item : '' ) );
					},
					$raw
				)
			);

		case 'object':
			// A composite field -- an address, a name. Each part sanitised as a
			// single line; nesting deeper than one level is not a shape any
			// built-in produces, and allowing it would make the recursion a
			// denial-of-service surface.
			if ( ! is_array( $raw ) ) {
				return array();
			}

			$clean = array();

			foreach ( $raw as $key => $value ) {
				$clean[ sanitize_key( $key ) ] = sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
			}

			return $clean;

		case 'file':
		case 'files':
			// Files never arrive as values -- they arrive in `$_FILES` and are
			// turned into attachment ids by `atf_handle_uploads()` long before
			// this runs. Anything reaching here claiming to be a file is a
			// forged field, and the only safe reading is a list of ids.
			$raw = is_array( $raw ) ? $raw : array( $raw );

			return array_values( array_filter( array_map( 'absint', $raw ) ) );

		case 'string':
		default:
			return sanitize_text_field( (string) ( is_scalar( $raw ) ? $raw : '' ) );
	}
}

/**
 * Renders one value for a human: an e-mail, a CSV cell, the entries table.
 *
 * Always returns plain text. Escaping for a particular destination is that
 * destination's job -- the HTML e-mail escapes it, the CSV writer quotes it --
 * because a function that returned HTML would be wrong for two of the three.
 *
 * @since 0.1.0
 *
 * @param mixed  $value   The stored value.
 * @param array  $field   The field it belongs to.
 * @param string $context Where it is going: `email`, `csv`, `table`, `detail`.
 * @return string Plain text.
 */
function atf_format_field_value( $value, $field, $context = 'table' ) {
	$definition = atf_get_field_type( isset( $field['type'] ) ? $field['type'] : '' );

	if ( $definition && is_callable( $definition['format'] ) ) {
		return (string) call_user_func( $definition['format'], $value, $field, $context );
	}

	if ( is_bool( $value ) ) {
		return $value ? __( 'Yes', 'allterrain-forms' ) : __( 'No', 'allterrain-forms' );
	}

	if ( is_array( $value ) ) {
		// A composite keeps its labels: "London, SW1A 1AA" tells you more than
		// "London SW1A 1AA", and an address with an empty county should not
		// leave a dangling comma.
		$parts = array_filter(
			array_map(
				static function ( $item ) {
					return is_scalar( $item ) ? trim( (string) $item ) : '';
				},
				$value
			),
			static function ( $item ) {
				return '' !== $item;
			}
		);

		return implode( ', ', $parts );
	}

	return (string) $value;
}

/**
 * Looks up the choice label for a stored choice value.
 *
 * Entries store the choice's *value*, not its label, so that renaming a label
 * later does not rewrite history. Everything human-facing has to map back, and
 * a value with no matching choice returns itself -- which is what happens when
 * a choice is deleted after somebody picked it, and is strictly better than an
 * empty cell that looks like they answered nothing.
 *
 * @since 0.1.0
 *
 * @param string $value The stored value.
 * @param array  $field The field.
 * @return string The label, or the value when no choice matches.
 */
function atf_choice_label( $value, $field ) {
	$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();

	foreach ( $choices as $choice ) {
		if ( isset( $choice['value'] ) && (string) $choice['value'] === (string) $value ) {
			return isset( $choice['label'] ) && '' !== $choice['label'] ? (string) $choice['label'] : (string) $value;
		}
	}

	return (string) $value;
}

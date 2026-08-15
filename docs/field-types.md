# Adding a field type

A field type is one `atf_register_field_type()` call. Nothing downstream
special-cases a type by name: the renderer asks the registry how to draw it, the
validator asks how to check it, the CSV exporter asks how to flatten it. So a new
type appears in the palette, drags like every other field, saves into the same
schema, validates through the same pipeline and exports to the same CSV — without
touching this plugin's code.

Every one of the 37 built-in types uses exactly this API. There is no privileged
path, which is the only way to know the extension API actually works.

---

## The smallest useful one

```php
add_action( 'atf_loaded', function () {
	atf_register_field_type( 'postcode', array(
		'label' => __( 'Postcode', 'my-plugin' ),
		'group' => 'text',
		'icon'  => 'dashicons-location',
	) );
} );
```

That is a working field: it appears in the palette under **Text**, renders as a
single-line input, sanitises as a string, stores, exports and can be referenced by
conditional logic and merge tags.

---

## The full definition

| Key | Type | Default | What it does |
|---|---|---|---|
| `label` | `string` | *required* | The name in the palette. |
| `group` | `string` | `advanced` | `text`, `choice`, `datetime`, `advanced`, `layout`, `special`, or your own via `atf_field_groups`. |
| `icon` | `string` | `dashicons-forms` | Dashicon slug for the palette tile. |
| `description` | `string` | `''` | One line under the label in the palette. |
| `input` | `bool` | `true` | Whether it contributes a value. Layout fields set `false`. |
| `value` | `string` | `string` | Value shape — see below. |
| `choices` | `bool` | `false` | Whether it carries a choice list. |
| `supports` | `string[]` | see below | Which settings the inspector offers. |
| `settings` | `array` | `array()` | Type-specific defaults, merged into every new field. |
| `sanitize` | `callable` | by `value` | `( $raw, $field ) => mixed` |
| `validate` | `callable` | `null` | `( $value, $field, $context ) => true\|WP_Error` |
| `render` | `callable` | `null` | `( $field, $value, $context ) => string` |
| `format` | `callable` | by `value` | `( $value, $field, $context ) => string` |
| `position` | `int` | `50` | Sort order within the group. |

### `value` — the shape it stores

| Shape | Stores | Sanitised with |
|---|---|---|
| `string` | one line | `sanitize_text_field()` |
| `text` | many lines, newlines kept | `sanitize_textarea_field()` |
| `number` | a number, or `''` when unanswered | numeric check |
| `bool` | true/false | cast |
| `array` | a list of strings | each `sanitize_text_field()` |
| `object` | a flat map — an address, a name | keys `sanitize_key()`, values `sanitize_text_field()` |
| `files` | a list of attachment ids | `absint()` |

An unanswered `number` stays `''` rather than becoming `0`. Coercing it would
make an optional number field look answered and a required one pass validation.

### `supports` — what the inspector shows

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`,
`logic`, `choices`, `min`, `max`, `step`, `minlength`, `maxlength`, `pattern`,
`unique`, `confirm`, `other`, `inline`, `multiple`, `minchoices`, `maxchoices`,
`mindate`, `maxdate`, `filetypes`, `maxsize`, `maxfiles`, `formula`, `currency`,
`rows`, `parts`, `correct`, `points`.

`atf_input_supports()` gives you the common set:

```php
'supports' => atf_input_supports( array( 'minlength', 'maxlength', 'unique' ) ),
```

---

## A worked example

A postcode field that validates a UK postcode, normalises its spacing, and offers
a "must be in London" toggle.

```php
add_action( 'atf_loaded', function () {
	atf_register_field_type( 'postcode', array(
		'label'       => __( 'UK postcode', 'my-plugin' ),
		'description' => __( 'Validated and tidied up on the way in.', 'my-plugin' ),
		'group'       => 'text',
		'icon'        => 'dashicons-location',
		'value'       => 'string',
		'supports'    => atf_input_supports( array( 'unique' ) ),
		'settings'    => array( 'londonOnly' => false ),

		// Uppercase, one space before the last three characters. Doing this at
		// sanitise time rather than at display time means every entry, export
		// and notification agrees on the format — and a uniqueness check
		// actually catches "sw1a1aa" against "SW1A 1AA".
		'sanitize'    => function ( $raw ) {
			$raw = strtoupper( preg_replace( '/\s+/', '', (string) $raw ) );

			return strlen( $raw ) > 3
				? substr( $raw, 0, -3 ) . ' ' . substr( $raw, -3 )
				: $raw;
		},

		'validate'    => function ( $value, $field ) {
			if ( ! preg_match( '/^[A-Z]{1,2}\d[A-Z\d]? \d[A-Z]{2}$/', $value ) ) {
				return new WP_Error(
					'not_a_postcode',
					// Honouring the field's own message override is what lets
					// the person building the form write better wording than
					// you can from here.
					atf_field_message( $field, 'invalid', __( 'That is not a UK postcode.', 'my-plugin' ) )
				);
			}

			if ( ! empty( $field['londonOnly'] ) && ! preg_match( '/^(E|EC|N|NW|SE|SW|W|WC)/', $value ) ) {
				return new WP_Error(
					'not_london',
					__( 'This form is only open to London postcodes.', 'my-plugin' )
				);
			}

			return true;
		},
	) );
} );
```

Note what is **not** here: no renderer. The default single-line input is right,
and writing one would mean maintaining the label binding, the `aria-describedby`
wiring and the error attributes yourself.

---

## When you do need a renderer

Only when the default control is genuinely the wrong element. If you write one,
you own the accessibility of what you emit:

```php
'render' => function ( $field, $value, $context ) {
	// `$context` carries: id, instance, schema, values, error, describedby.
	return atf_render_label( $field, $context['id'] )
		. sprintf(
			'<input type="text" class="atf-input"%s value="%s" data-atf-input data-my-plugin-lookup>',
			// Emits id, name, required, aria-invalid, aria-describedby,
			// placeholder and the bounds — all of it, correctly.
			atf_control_attributes( $field, $context ),
			esc_attr( (string) $value )
		);
},
```

Rules the built-ins follow, and yours should:

- Bind the label with `for`, or use a `<fieldset>` and `<legend>` if the control
  is a group. A `<label>` may only point at one control.
- Put `data-atf-input` on anything the front-end bundle should read for
  conditional logic and calculations.
- Never render a `required` control inside a branch that can be hidden — a
  required input in a hidden branch is a form nobody can submit.
- Emit `$context['describedby']` on the control, or the hint and error you were
  given are announced to nobody.

---

## Formatting for humans

`format` turns a stored value into plain text for an e-mail, a CSV cell, the
entries table or the detail view. Always **plain text** — escaping belongs to the
destination, and a function that returned HTML would be wrong for two of the four.

```php
'format' => function ( $value, $field, $context ) {
	// The entries table gets a summary; an export gets everything.
	return 'table' === $context
		? sprintf( _n( '%d item', '%d items', count( $value ), 'my-plugin' ), count( $value ) )
		: implode( ', ', $value );
},
```

Returning `''` from `format` hides the field from `{all_fields}` and from an
export column — which is exactly how the `password` type keeps itself out of
both.

---

## Participating in calculations

Give a choice a `price` and it feeds a total automatically:

```php
'choices' => array(
	array( 'label' => 'Standard', 'value' => 'std', 'price' => 20 ),
	array( 'label' => 'Express',  'value' => 'exp', 'price' => 35 ),
),
```

A multi-choice field sums the prices of everything picked. A `points` value works
the same way and is what quiz scoring reads.

---

## Removing a type

```php
add_action( 'atf_register_field_types', function () {
	atf_unregister_field_type( 'signature' );
} );
```

Existing fields of the type keep their stored values. Unregistering hides a type
from the builder; it does not rewrite forms that already use it, and it does not
drop the column from an export — which is a great deal better than silently
losing data somebody submitted.

---

## The client side

The front-end bundle reads a **reduced** schema, not the full one: field ids,
types, logic, validation bounds, and choice values with their prices. That is
enough for conditional logic and live totals.

If your field type needs something else there, add it through `atf_client_schema`
— and remember that anything you add is readable by every visitor in the page
source.

```php
add_filter( 'atf_client_schema', function ( $payload, $schema ) {
	foreach ( $payload['fields'] as &$field ) {
		if ( 'postcode' === $field['type'] ) {
			$field['londonOnly'] = ! empty( atf_find_field( $schema, $field['id'] )['londonOnly'] );
		}
	}

	return $payload;
}, 10, 2 );
```

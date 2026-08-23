# Adding a field type

A field type is one `alltfo_register_field_type()` call. Nothing downstream
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
add_action( 'alltfo_loaded', function () {
	alltfo_register_field_type( 'postcode', array(
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
| `group` | `string` | `advanced` | `text`, `choice`, `datetime`, `advanced`, `layout`, `special`, or your own via `alltfo_field_groups`. |
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

A flag here makes the inspector draw the control for that setting. Every one of
them is real: a flag the builder has no control for is a setting nobody can
reach, and `tests/vitest/field-settings.test.ts` fails if one appears.

**Common to every input** — `alltfo_input_supports()` adds these:

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`,
`logic`.

**Content and layout**

| Flag | Writes | Control |
|---|---|---|
| `choices` | `choices` | The choices editor, and the option list on the canvas |
| `correct` | `correct` | Marks the right answer in the choices editor |
| `rows` | `rows` | A line count on a textarea; the statement list on a Likert matrix |
| `parts` | `parts` | A tick box per part, listed by the server |
| `level` | `level` | Heading level, 2–6 |
| `content` | `content` | The markup in an HTML block |
| `height` | `height` | A spacer's height in pixels |
| `consenttext` | `consentText` | What a consent tick box is agreeing to |
| `columns` | `columns` | How many pictures sit side by side |
| `multiple` | `multiple` | Whether more than one may be chosen |
| `inline` | `inline` | Lays the options out in a row |
| `other` | `other` | Adds an "Other" option with a box |

**Wording**

| Flag | Writes | Control |
|---|---|---|
| `nextlabel` | `nextLabel` | The page break's forward button, edited on the button itself |
| `prevlabel` | `prevLabel` | The button that comes back from the page after |
| `addlabel` | `addLabel` | The repeater's add button |
| `itemlabel` | `itemLabel` | What one repeater row is called — "Attendee" numbers every card "Attendee 1", "Attendee 2" and names the Remove button |
| `endlabels` | `minLabel`, `maxLabel` | The words on the ends of a scale |

**Bounds and validation**

| Flag | Writes | Control |
|---|---|---|
| `min` / `max` | `min`, `max` | A pair, or `max` alone where a type has no floor |
| `step` | `step` | The interval a number or time moves in |
| `minlength` / `maxlength` | `minlength`, `maxlength` | A pair |
| `mindate` / `maxdate` | `minDate`, `maxDate` | A pair |
| `mintime` / `maxtime` | `minTime`, `maxTime` | A pair |
| `minchoices` / `maxchoices` | `minChoices`, `maxChoices` | How many may be picked |
| `minrows` / `maxrows` | `minRows`, `maxRows` | How many repeater rows |
| `maxsize` / `maxfiles` | `maxsize`, `maxfiles` | Largest file, and how many |
| `filetypes` | `filetypes` | Accepted extensions, typed with commas |
| `pattern` | `validation`, `pattern`, `validationRecipe` | "The answer should be" — a preset shape (email, phone, ZIP code, IBAN, card number, …) or a custom rule built in the rule builder. The preset slug lives in `validation`; a custom rule compiles into `pattern`, with the builder's blocks kept in `validationRecipe` |
| `unique` | `unique` | No two submissions may share the value |
| `formula` / `currency` | `formula`, `currency` | A calculation and its symbol |
| `points` | `points` | What a quiz answer is worth |

The flag is lower-case, by WordPress convention; the property it writes is
camelCase. They differ often enough — `minrows` writes `minRows` — that the two
are worth reading as separate things.

Note that `supports` describes settings that exist. `calc`, `confirm`, `counter`,
`searchable`, `disabledays`, `defaultcountry`, `explanation` and a rating `icon`
were declared before 0.1.0 and read by nothing; they have been removed rather
than given controls that would do nothing.

`alltfo_input_supports()` gives you the common set:

```php
'supports' => alltfo_input_supports( array( 'minlength', 'maxlength', 'unique' ) ),
```

---

## A worked example

A postcode field that validates a UK postcode, normalises its spacing, and offers
a "must be in London" toggle.

```php
add_action( 'alltfo_loaded', function () {
	alltfo_register_field_type( 'postcode', array(
		'label'       => __( 'UK postcode', 'my-plugin' ),
		'description' => __( 'Validated and tidied up on the way in.', 'my-plugin' ),
		'group'       => 'text',
		'icon'        => 'dashicons-location',
		'value'       => 'string',
		'supports'    => alltfo_input_supports( array( 'unique' ) ),
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
					alltfo_field_message( $field, 'invalid', __( 'That is not a UK postcode.', 'my-plugin' ) )
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
	return alltfo_render_label( $field, $context['id'] )
		. sprintf(
			'<input type="text" class="atf-input"%s value="%s" data-atf-input data-my-plugin-lookup>',
			// Emits id, name, required, aria-invalid, aria-describedby,
			// placeholder and the bounds — all of it, correctly.
			alltfo_control_attributes( $field, $context ),
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

### Repeaters in formulas

A repeater is a *list* of answers, and a formula can read it three ways:

```
{attendees}              the number of rows — "15 per attendee" is {attendees} * 15
sum( {attendees.age} )   one argument per row: sum, avg, min and max see every row
{attendees.age} * 2      anywhere else, the reference is the total across rows
```

The per-row spread happens only when `{repeater.sub}` is the **sole argument**
of `sum`, `avg`, `min` or `max` — spreading into a fixed-arity call like
`pow( {a.b}, 2 )` would silently push the `2` out of its parameter slot, so
everywhere else the reference collapses to its sum. A row the visitor added
and never filled in counts nowhere, which keeps `{attendees}` agreeing with
what the entry stores. Priced choices inside a repeater contribute their price
per row, so `sum( {attendees.meal} )` totals an order.

Both engines — `includes/calc.php` on submit, `src/shared/calc.ts` as the
visitor types — resolve these identically, and the shared conformance table in
`tests/fixtures/calc-cases.json` is what holds them to it.

---

## Removing a type

```php
add_action( 'alltfo_register_field_types', function () {
	alltfo_unregister_field_type( 'signature' );
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

If your field type needs something else there, add it through `alltfo_client_schema`
— and remember that anything you add is readable by every visitor in the page
source.

```php
add_filter( 'alltfo_client_schema', function ( $payload, $schema ) {
	foreach ( $payload['fields'] as &$field ) {
		if ( 'postcode' === $field['type'] ) {
			$field['londonOnly'] = ! empty( alltfo_find_field( $schema, $field['id'] )['londonOnly'] );
		}
	}

	return $payload;
}, 10, 2 );
```

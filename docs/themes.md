# Themes

A theme is **one flat map of design tokens** and nothing else. There is no theme
PHP, no theme template, no theme stylesheet. The renderer emits the same markup
for every theme and the tokens decide what it looks like.

That is the whole reason a theme can be made without code. If a theme were a
stylesheet, making one would mean writing CSS, and "expandable without code"
would be a line in a README rather than a fact.

It is also what keeps the accessibility work done once rather than ten times: all
ten built-in themes render byte-identical markup, and the test suite asserts it.

---

## Making one without code

**Theme Studio** — a native OpenStation window, or **Forms → Themes** without the
shell.

1. Pick a theme to start from.
2. Move the controls. The preview repaints as you go, against a real form of
   yours, using the real renderer.
3. **Save as a theme**, and name it.

It becomes selectable anywhere a built-in is, and can be exported as JSON and
imported on another site.

The Studio's controls are **built from the token table**, not hard-coded. A
plugin that adds a token through `atf_theme_tokens` gets an editor for it for
free — which is what makes the promise true for the editor as well as the
renderer.

---

## The token surface

69 tokens across eleven families. Every one is settable by a theme, by a form's
own overrides, and by the Studio.

### Colour

`bg` `surface` `surface-alt` `text` `text-muted` `heading` `accent`
`accent-text` `accent-soft` `border` `border-focus` `error` `error-soft`
`success` `placeholder`

### Radius

`radius-field` `radius-button` `radius-card` `radius-check`

`radius-check` is separate because a theme that squares off its text fields
usually still wants the tick box square and the radio round, and `radius-field`
cannot say both.

### Shadow

`shadow-field` `shadow-field-focus` `shadow-button` `shadow-button-hover`
`shadow-card`

### Border and field shape

`border-width` `border-style` `field-style`

`field-style` is one of `outline`, `filled`, `underline` or `none`. It is
*structural*: the renderer reads it and picks a class, because it changes the
shape of the markup rather than only its paint.

### Space

`gap-fields` `gap-label` `pad-field-x` `pad-field-y` `pad-card` `field-height`

### Typography

`font-family` `font-family-heading` `size-base` `size-label` `size-hint`
`size-heading` `size-button` `weight-label` `weight-heading` `weight-button`
`letter-spacing` `letter-spacing-label` `line-height` `transform-label`

### Labels

`label-position` `label-width`

`label-position` is one of `top`, `inside`, `floating`, `left` or `hidden`. Also
structural. **`hidden` hides the label visually and keeps it in the accessibility
tree** — there is no theme in which throwing away the accessible name is the
right call.

### Button

`button-bg` `button-text` `button-bg-hover` `button-border` `button-pad-x`
`button-pad-y` `button-width` `button-align` `button-transform`

### Focus

`focus-ring-width` `focus-ring-color` `focus-ring-offset`

### Motion

`transition-duration` `transition-easing` `field-lift`

Every transition is suppressed under `prefers-reduced-motion` regardless of what
a theme sets.

### Effects

`backdrop-blur` `field-gradient` `card-gradient` `card-border` `progress-height`

---

## How a theme resolves

Three layers, each beating the one before:

```
atf_theme_token_defaults()      the Clean theme's values
        ↓
the theme's own token map       whatever it changes
        ↓
the form's themeOverrides       this one form's tuning
```

That last layer is what lets one form nudge its accent colour without anybody
having to make a whole new theme for it. It is also what the Studio writes while
you are moving sliders, before you save.

The result is emitted as CSS custom properties **scoped to that form's instance
id**, inline, so two forms wearing two different themes can sit on the same page.

```html
<div class="atf-form-wrap" id="atf-12-1">
	<style>#atf-12-1 .atf-form { --atf-accent: #f252fc; … }</style>
	<form class="atf-form atf-theme-holo atf-labels-top atf-fields-outline" …>
```

---

## Adding a token in code

```php
add_filter( 'atf_theme_tokens', function ( $tokens ) {
	$tokens['field-icon-color'] = 'currentColor';

	return $tokens;
} );
```

You are then responsible for CSS that reads it:

```css
.atf-field--email .atf-input { color: var( --atf-field-icon-color ); }
```

The test suite asserts the token table and the stylesheet agree **in both
directions**: a token no rule reads is a control that does nothing, and a
`var(--atf-…)` no token declares is a value no theme can reach. Add one without
the other and the build fails.

---

## Adding a whole theme in code

For a theme that ships inside another plugin, rather than one a user made:

```php
add_action( 'atf_loaded', function () {
	atf_register_theme( 'sunset', array(
		'label'       => __( 'Sunset', 'my-plugin' ),
		'description' => __( 'Warm gradients, soft corners.', 'my-plugin' ),
		// Marks the theme as dark so the form gets `atf-is-dark`, which the
		// builder and the block preview use to pick a sensible backdrop.
		'dark'        => true,
		'tokens'      => array(
			'surface'      => '#2b1b2e',
			'text'         => '#ffe9d6',
			'accent'       => '#ff8a5b',
			'accent-text'  => '#2b1b2e',
			'radius-field' => '14px',
			'button-bg'    => 'linear-gradient( 100deg, #ff8a5b, #ff5f8a )',
		),
	) );
} );
```

List **only the tokens you change** — everything else inherits, so a token added
to the surface in a later release reaches your theme without an edit.

Tokens are sanitised at registration rather than at use, so a typo in a token
name is dropped once and reported nowhere, instead of being carried around and
silently ignored by every reader.

`atf_unregister_theme( 'sunset' )` removes it again. Forms using it fall back to
Clean the next time they render, which is recoverable where rewriting every form
that referenced it is not.

Themes resolve from three sources, each beating the one before:

```
atf_builtin_themes()      the ten that ship
        ↓
atf_register_theme()      registered in code by a plugin
        ↓
saved themes (posts)      made in the Studio
```

A saved theme winning on a slug collision is what lets a site override a shipped
theme, and get it back by deleting theirs.

---

## The security boundary

Token values land inside a `<style>` block. A value containing a brace could
close the rule and open another, which is how a "theme" becomes a way to restyle
the page around it or load a remote resource.

`atf_sanitize_tokens()` **refuses** rather than escapes:

- any of `{ } ; < >` or a backslash
- `url(`, `image-set(` or `expression(`
- `@import` or `javascript:`
- anything over 400 characters
- any token name not in the table

There is no legitimate token value that needs one of those. The refusal is tested
in both directions — the dangerous values are refused, and the values a real
theme uses (`rgba()`, gradients, shadow stacks, font stacks, `clamp()`) survive.

A sanitiser that refused everything would pass the first half of that test and
make the theme system useless.

---

## Contrast

All ten built-in themes are checked for WCAG AA contrast (4.5:1), **on a white
page and on a dark one**, and the suite fails if one stops meeting it.

Translucent layers are composited the way a browser composites them — page, then
the form's `bg`, then the field `surface` — so a theme cannot opt out of being
measured by making its surface an `rgba()`.

That last part is not theoretical. The first version of this test measured only
solid colours and *skipped* anything translucent, which is exactly the shape
Glass and Holo have. Both shipped painting white text on a translucent surface
with nothing behind it: a measured contrast of **1.00** on an ordinary page — not
"low contrast", invisible. Both now carry their own dark scrim in `bg`, which is
the rule the failure taught: **a theme with light text has to bring its own
ground rather than assume the page has one.**

| Theme | On white | On dark |
|---|---|---|
| Clean | 16.67 | 16.67 |
| Midnight | 13.95 | 13.95 |
| Glass | 6.91 | 12.17 |
| Brutal | 21.00 | 21.00 |
| Paper | 14.12 | 14.12 |
| Neon | 16.69 | 16.69 |
| Terminal | 14.25 | 14.25 |
| Soft | 8.01 | 8.01 |
| Editorial | 18.16 | 18.16 |
| Holo | 9.33 | 15.90 |

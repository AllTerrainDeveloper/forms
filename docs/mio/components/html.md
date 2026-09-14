# HTML block (`html`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Layout markup in `content`. WordPress allowed-post HTML sanitization applies; scripts, event handlers and unsafe markup are rejected by strict assistant validation. Put theme styles in themeOverrides.

Type ID: `html`. Palette group: `layout`. Produces a submitted answer: **no**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`content`, `css`, `logic`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `content` | `""` |

## Minimal field

```yaml
id: html_question
type: html
label: HTML block
content: '<p>Helpful instructions.</p>'
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

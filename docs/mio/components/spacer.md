# Spacer (`spacer`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Layout spacing; `height` defaults to 24 pixels. Prefer theme gap tokens for consistent spacing across the form; use a spacer for a deliberate local break.

Type ID: `spacer`. Palette group: `layout`. Produces a submitted answer: **no**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`height`, `css`, `logic`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `height` | `24` |

## Minimal field

```yaml
id: spacer_question
type: spacer
label: Spacer
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

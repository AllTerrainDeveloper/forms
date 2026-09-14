# Section heading (`heading`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Layout only, not an answer. `level` sets the heading level; `label` is heading text and `hint` supporting content. Do not require a layout field.

Type ID: `heading`. Palette group: `layout`. Produces a submitted answer: **no**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`label`, `hint`, `level`, `css`, `logic`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `level` | `3` |

## Minimal field

```yaml
id: heading_question
type: heading
label: Section heading
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

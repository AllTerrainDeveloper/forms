# Total (`total`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Read-only calculated number. `formula` uses `{id}` references and supported numeric functions. `currency` is a display marker, `decimals` controls precision, `display` is input or output. This is not a payment gateway.

Type ID: `total`. Palette group: `special`. Produces a submitted answer: **yes**. Registered value shape: `number`. Supplies choices: **no**.

## Inspector capabilities

`label`, `hint`, `width`, `css`, `logic`, `formula`, `currency`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `formula` | `""` |
| `currency` | `""` |
| `decimals` | `2` |
| `display` | `"input"` |

## Minimal field

```yaml
id: total_question
type: total
label: Total
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

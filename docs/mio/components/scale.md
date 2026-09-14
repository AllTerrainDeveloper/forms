# Opinion scale (`scale`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Numeric opinion/NPS scale. `minLabel` and `maxLabel` label the endpoints; `min`/`max` are the numeric bounds. Default range 0–10.

Type ID: `scale`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `number`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `min`, `max`, `endlabels`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `min` | `0` |
| `max` | `10` |
| `minLabel` | `""` |
| `maxLabel` | `""` |

## Minimal field

```yaml
id: scale_question
type: scale
label: Opinion scale
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

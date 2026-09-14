# Slider (`range`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Slider with `min`, `max` and `step`. A default should be in range. A slider is not a calculation; use total for a derived result.

Type ID: `range`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `number`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `min`, `max`, `step`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `min` | `0` |
| `max` | `100` |
| `step` | `1` |

## Minimal field

```yaml
id: range_question
type: range
label: Slider
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

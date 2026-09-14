# Likert matrix (`likert`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Each statement is a row and each column is a shared choice. `rows` is a list of choice-shaped records (value and label), while `choices` defines the answer scale. Stores an object keyed by row value.

Type ID: `likert`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `object`. Supplies choices: **yes**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `choices`, `rows`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `rows` | `[]` |

## Minimal field

```yaml
id: likert_question
type: likert
label: Likert matrix
choices:
  - {value: option_a, label: Option A}
  - {value: option_b, label: Option B}
rows:
  - {value: service, label: Service}
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

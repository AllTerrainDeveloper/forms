# Star rating (`rating`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Integer star rating up to `max` (default 5). Use the rating field for stars and scale for labeled numeric endpoints.

Type ID: `rating`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `number`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `max`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `max` | `5` |

## Minimal field

```yaml
id: rating_question
type: rating
label: Star rating
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

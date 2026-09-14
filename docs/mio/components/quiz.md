# Quiz question (`quiz`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Choice question with `correct` equal to an actual choice value and numeric `points`. Enable schema.settings.quiz.enabled for scoring; passMark is a percentage and showScore controls score visibility.

Type ID: `quiz`. Palette group: `special`. Produces a submitted answer: **yes**. Registered value shape: `string`. Supplies choices: **yes**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `choices`, `correct`, `points`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `correct` | `""` |
| `points` | `1` |

## Minimal field

```yaml
id: quiz_question
type: quiz
label: Quiz question
choices:
  - {value: option_a, label: Option A}
  - {value: option_b, label: Option B}
correct: option_a
points: 1
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

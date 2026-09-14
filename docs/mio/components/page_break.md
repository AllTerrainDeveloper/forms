# Page break (`page_break`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Divides the ordered field list into steps. Place between groups of questions. `label` names the step; `nextLabel` and `prevLabel` customize navigation. Form `progressBar` chooses steps/bar/none.

Type ID: `page_break`. Palette group: `layout`. Produces a submitted answer: **no**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`label`, `nextlabel`, `prevlabel`, `logic`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `nextLabel` | `""` |
| `prevLabel` | `""` |

## Minimal field

```yaml
id: page_break_question
type: page_break
label: Page break
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

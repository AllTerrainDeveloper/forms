# Paragraph (`textarea`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Use for paragraphs and the conditional Other explanation. `rows` is the visible height, not a response limit. Set `maxlength` for a response limit. Required validation applies only while visible.

Type ID: `textarea`. Palette group: `text`. Produces a submitted answer: **yes**. Registered value shape: `text`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `minlength`, `maxlength`, `rows`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `rows` | `5` |

## Minimal field

```yaml
id: textarea_question
type: textarea
label: Paragraph
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.

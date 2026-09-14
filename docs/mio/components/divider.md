# Divider (`divider`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Layout separator with no submitted value. It can have conditional visibility and a CSS class. Do not add choices or a required answer.

Type ID: `divider`. Palette group: `layout`. Produces a submitted answer: **no**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`css`, `logic`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

No additional registered defaults. Shared properties and optional supported constraints are described in forms.md.

## Minimal field

```yaml
id: divider_question
type: divider
label: Divider
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
